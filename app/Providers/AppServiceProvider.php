<?php

namespace App\Providers;

use App\Enums\QueueName;
use App\Enums\UserRole;
use App\Http\Middleware\LogSlowRequests;
use App\Jobs\CheckLowStock;
use App\Jobs\ImportBooksChunk;
use App\Jobs\RecalculateBookRating;
use App\Jobs\RecalculateBookRatings;
use App\Jobs\SendImportReport;
use App\Lab\Realtime\LabSignal;
use App\Lab\Realtime\TraceDelayedListener;
use App\Lab\Realtime\TraceQueuedListener;
use App\Lab\Realtime\TraceSyncListener;
use App\Listeners\DispatchRatingRecalculation;
use App\Listeners\NotifyModeratorsOfPendingReview;
use App\Models\Author;
use App\Models\Book;
use App\Models\Review;
use App\Models\User;
use App\View\Composers\GenreTreeComposer;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\LazyLoadingViolationException;
use Illuminate\Http\Request;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Number;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Abilities whose policy checks the state of the data, not the user's privileges
     * (only a trashed book can be restored/force-deleted, only a pending review
     * approved). The admin bypass in Gate::before must not skip these checks.
     */
    private const STATE_RULE_ABILITIES = ['restore', 'forceDelete', 'approve'];

    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Terminable middleware is resolved again for terminate(); a singleton makes
        // that the same instance, so terminate() sees the start time set in handle().
        $this->app->singleton(LogSlowRequests::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Toggle with MODEL_STRICT in .env (see config/app.php and docs/00-setup.md).
        Model::shouldBeStrict((bool) config('app.model_strict'));

        // What a lazy-loading violation does: fail loudly while developing/testing,
        // but only log in production — an N+1 should not take the site down.
        Model::handleLazyLoadingViolationUsing(function (Model $model, string $relation, LazyLoadingViolationException $e) {
            // Same exemption as the framework default: new models have nothing to eager-load.
            if (! $model->exists || $model->wasRecentlyCreated) {
                return;
            }

            if (app()->environment('local', 'testing')) {
                throw $e;
            }

            Log::warning('N+1 query: lazy loading ['.$relation.'] on ['.$model::class.'].', [
                'model' => $model::class,
                'key' => $model->getKey(),
                'relation' => $relation,
                'url' => app()->runningInConsole() ? null : request()->fullUrl(),
            ]);
        });

        // Store short aliases in *_type morph columns instead of class names, and
        // throw if a model without an alias is used in a polymorphic relation.
        // See docs/02-relations.md ("Morph map").
        Relation::enforceMorphMap([
            'book' => Book::class,
            'author' => Author::class,
            // notifications.notifiable_type (database notification channel).
            'user' => User::class,
        ]);

        // Number::format()/fileSize() follow the app locale (uk: "1 234,5"). Carbon picks
        // up the locale from the translator automatically.
        Number::useLocale(app()->getLocale());

        // View composer: runs whenever the catalog sidebar partial is rendered.
        View::composer('books.partials.sidebar', GenreTreeComposer::class);

        $this->configureAuthorization();
        $this->configureRateLimiting();
        $this->configureQueues();
        $this->configureLab();

        // @role('manager', 'admin') ... @endrole in Blade.
        Blade::if('role', fn (string ...$roles) => in_array(auth()->user()?->role?->value, $roles, true));
    }

    private function configureAuthorization(): void
    {
        // Runs before every gate and policy check. Return true to allow, null to let
        // the gate/policy decide — never false: that would deny admins things the
        // policy allows (and hide bugs, because the policy never runs).
        Gate::before(function (User $user, string $ability, array $arguments) {
            if ($user->role !== UserRole::Admin) {
                return null;
            }

            // Data rules are not privileges: "one review per book" must hold for
            // admins too, so this check is left to ReviewPolicy::create(). Same for
            // state rules — without this, an admin could force-delete a book that is
            // not in the trash (caught by BookAdminTest).
            if (in_array($ability, self::STATE_RULE_ABILITIES, true)
                || ($ability === 'create' && ($arguments[0] ?? null) === Review::class)) {
                return null;
            }

            return true;
        });

        // A gate: an ability that is not about one model (policies are per model).
        Gate::define('view-reports', fn (User $user) => in_array($user->role, [UserRole::Manager, UserRole::Admin], true));

        // Policies (App\Policies\{Model}Policy) are found by auto-discovery.
    }

    private function configureRateLimiting(): void
    {
        // Login throttling lives in App\Http\Requests\Auth\LoginRequest since stage 8
        // (failed attempts only). Stage 7 version, for comparison:
        // RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)
        //     ->by(mb_strtolower((string) $request->input('email')).'|'.$request->ip()));

        // Review writes (create/edit/delete): 5 per minute per user; over the limit => 429.
        RateLimiter::for('reviews', fn (Request $request) => Limit::perMinute(5)->by($request->user()?->id ?: $request->ip()));
    }

    /**
     * Job => queue map in one place (Laravel 13 Queue::route()). Used for jobs, queued
     * listeners and queued notifications when they do not name a queue themselves:
     * a #[Queue] attribute or ->onQueue() wins (see ValidateImportFile).
     * A parent class or interface works as a key: Notification::class covers every
     * queued notification.
     *
     * Worker: queue:work --queue=broadcasts,ratings,imports,notifications,default — polls
     * the queues in that order (priority), see compose.yaml.
     *
     * ShouldBroadcast::class covers every queued broadcast (BookStockChanged, the
     * lab's LabBroadcast, BroadcastNotificationCreated of the broadcast notification
     * channel). ShouldBroadcastNow events are not queued at all.
     */
    private function configureQueues(): void
    {
        Queue::route([
            ShouldBroadcast::class => QueueName::Broadcasts,
            RecalculateBookRating::class => QueueName::Ratings,
            RecalculateBookRatings::class => QueueName::Ratings,
            DispatchRatingRecalculation::class => QueueName::Ratings,
            ImportBooksChunk::class => QueueName::Imports,
            NotifyModeratorsOfPendingReview::class => QueueName::Notifications,
            SendImportReport::class => QueueName::Notifications,
            Notification::class => QueueName::Notifications,
            CheckLowStock::class => QueueName::Default,
        ]);
    }

    /**
     * Event discovery only scans app/Listeners, so the lab's listeners are registered
     * explicitly. Order matters: TraceSyncListener can stop propagation (return false).
     */
    private function configureLab(): void
    {
        Event::listen(LabSignal::class, TraceSyncListener::class);
        Event::listen(LabSignal::class, TraceQueuedListener::class);
        Event::listen(LabSignal::class, TraceDelayedListener::class);
    }
}
