<?php

use App\Http\Controllers\Admin;
use App\Http\Controllers\Auth;
use App\Http\Controllers\AuthorController;
use App\Http\Controllers\BookController;
use App\Http\Controllers\GenreController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\Lab\LabCollectionsController;
use App\Http\Controllers\Lab\LabModelsController;
use App\Http\Controllers\Lab\LabNPlusOneController;
use App\Http\Controllers\Lab\LabQueuesController;
use App\Http\Controllers\Lab\LabRealtimeController;
use App\Http\Controllers\Lab\LabRelationsController;
use App\Http\Controllers\Lab\LabXssController;
use App\Http\Controllers\Manage;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\TagController;
use App\Models\Review;
use App\Models\Scopes\ApprovedScope;
use Illuminate\Support\Facades\Route;

// Invokable controller: a single __invoke() action, referenced by class name.
Route::get('/', HomeController::class)->name('home');

// Resource controller limited to two actions; scoped() binds {book} by slug
// instead of id => /books/{book:slug}.
Route::resource('books', BookController::class)
    ->only(['index', 'show'])
    ->scoped(['book' => 'slug']);

// Controller group: actions are referenced by method name only.
Route::controller(AuthorController::class)->prefix('authors')->name('authors.')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::get('/{author:slug}', 'show')->name('show');

    // Scoped binding: {book:slug} is looked up via $author->books(),
    // so /authors/a/books/b is a 404 when author "a" didn't write book "b".
    Route::get('/{author:slug}/books/{book:slug}', 'book')->name('books.show')->scopeBindings();
});

// Plain [Controller::class, 'method'] routes; genres.show only redirects.
Route::get('/genres', [GenreController::class, 'index'])->name('genres.index');
Route::get('/genres/{genre:slug}', [GenreController::class, 'show'])->name('genres.show');
// Tag page reuses the catalog view, pre-filtered by the tag.
Route::get('/tags/{tag:slug}', [TagController::class, 'show'])->name('tags.show');

// View route (no controller); the page uses the legacy @extends layout for comparison.
Route::view('/about', 'about')->name('about');

/*
|--------------------------------------------------------------------------
| Authentication (hand-written controllers, no starter kit)
|--------------------------------------------------------------------------
| Route names login / verification.* are what the "auth" and "verified"
| middleware and the VerifyEmail notification redirect/link to.
*/

Route::middleware('guest')->group(function () {
    Route::get('/register', [Auth\RegisteredUserController::class, 'create'])->name('register');
    Route::post('/register', [Auth\RegisteredUserController::class, 'store']);

    Route::get('/login', [Auth\AuthenticatedSessionController::class, 'create'])->name('login');
    // Failed attempts are throttled inside LoginRequest (5/min per email + IP);
    // stage 7 used route middleware here: ->middleware('throttle:login').
    Route::post('/login', [Auth\AuthenticatedSessionController::class, 'store']);
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [Auth\AuthenticatedSessionController::class, 'destroy'])->name('logout');

    Route::get('/email/verify', [Auth\EmailVerificationController::class, 'notice'])->name('verification.notice');
    // "signed": the URL carries an HMAC signature + expiry; tampering => 403.
    Route::get('/email/verify/{id}/{hash}', [Auth\EmailVerificationController::class, 'verify'])
        ->middleware(['signed', 'throttle:6,1'])->name('verification.verify');
    Route::post('/email/verification-notification', [Auth\EmailVerificationController::class, 'send'])
        ->middleware('throttle:6,1')->name('verification.send');
});

/*
|--------------------------------------------------------------------------
| Reviews (readers)
|--------------------------------------------------------------------------
*/

// Editing and moderation work on pending reviews, which ApprovedScope hides from
// the default route model binding — resolve {review} without it. Who may touch
// which review is up to ReviewPolicy (Form Request authorize() / Gate::authorize()).
Route::bind('review', fn (string $value) => Review::withoutGlobalScope(ApprovedScope::class)->findOrFail($value));

Route::middleware(['auth', 'verified'])->group(function () {
    // GET target of the guest's login prompt: "auth" remembers it as the intended URL.
    Route::get('/books/{book:slug}/reviews/create', [ReviewController::class, 'create'])->name('books.reviews.create');

    // Named limiter "reviews" (AppServiceProvider): 5 writes per minute per user.
    Route::middleware('throttle:reviews')->group(function () {
        Route::post('/books/{book:slug}/reviews', [ReviewController::class, 'store'])->name('books.reviews.store');
        Route::put('/reviews/{review}', [ReviewController::class, 'update'])->name('reviews.update');
        Route::delete('/reviews/{review}', [ReviewController::class, 'destroy'])->name('reviews.destroy');
    });
});

/*
|--------------------------------------------------------------------------
| Staff area: managers and admins
|--------------------------------------------------------------------------
| Middleware run in order: auth (logged in) -> verified (email confirmed)
| -> role (manager or admin). Warehouse routes add warehouse.access.
*/

Route::prefix('manage')->name('manage.')->middleware(['auth', 'verified', 'role:manager,admin'])->group(function () {
    Route::get('/warehouses', [Manage\WarehouseController::class, 'index'])->name('warehouses.index');

    // {warehouse} binds by id; warehouse.access: a manager only gets their own one.
    Route::prefix('/warehouses/{warehouse}')->name('warehouses.')->middleware('warehouse.access')->group(function () {
        Route::get('/stocks', [Manage\StockController::class, 'index'])->name('stocks.index');
        Route::get('/movements', [Manage\MovementController::class, 'index'])->name('movements.index');

        // scopeBindings(): {stock} is looked up via $warehouse->stocks(), so
        // /warehouses/1/stocks/{id of a stock in warehouse 2}/edit is a 404 (IDOR protection);
        // can:update,stock then asks StockPolicy about the row itself.
        Route::scopeBindings()->middleware('can:update,stock')->group(function () {
            Route::get('/stocks/{stock}/edit', [Manage\StockController::class, 'edit'])->name('stocks.edit');
            Route::put('/stocks/{stock}', [Manage\StockController::class, 'update'])->name('stocks.update');
        });
    });
});

// Staff notifications (database channel): bell in the layout, list, mark as read.
Route::prefix('notifications')->name('notifications.')->middleware(['auth', 'verified', 'role:manager,admin'])->group(function () {
    Route::get('/', [NotificationController::class, 'index'])->name('index');
    Route::post('/read-all', [NotificationController::class, 'readAll'])->name('read-all');
    Route::post('/{id}/read', [NotificationController::class, 'read'])->name('read');
});

// Reports: invokable controller, the report name comes from ->defaults().
// A gate (view-reports) rather than role:manager,admin: the rule lives in one place
// and the same check drives the navigation link (@can('view-reports')).
Route::prefix('reports')->name('reports.')->middleware(['auth', 'verified', 'can:view-reports'])->group(function () {
    Route::get('/stock', ReportController::class)->defaults('report', 'stock')->name('stock');
    Route::get('/stock.csv', ReportController::class)->defaults('report', 'stock-csv')->name('stock-csv');
    Route::get('/top-authors', ReportController::class)->defaults('report', 'top-authors')->name('top-authors');
});

/*
|--------------------------------------------------------------------------
| Admin area
|--------------------------------------------------------------------------
*/

Route::prefix('admin')->name('admin.')->middleware(['auth', 'verified', 'role:admin'])->group(function () {
    Route::get('/', Admin\DashboardController::class)->name('dashboard');

    // withTrashed(['show']): soft-deleted books still open in the admin (public routes: 404).
    Route::resource('books', Admin\BookController::class)->withTrashed(['show']);
    Route::patch('/books/{book}/restore', [Admin\BookController::class, 'restore'])->name('books.restore')->withTrashed();
    Route::delete('/books/{book}/force', [Admin\BookController::class, 'forceDelete'])->name('books.force-delete')->withTrashed();

    // CSV import: upload, progress page + JSON for polling, error report.
    Route::get('/imports', [Admin\ImportController::class, 'index'])->name('imports.index');
    Route::post('/imports', [Admin\ImportController::class, 'store'])->name('imports.store');
    Route::get('/imports/{import}', [Admin\ImportController::class, 'show'])->name('imports.show');
    Route::get('/imports/{import}/progress', [Admin\ImportController::class, 'progress'])->name('imports.progress');
    Route::get('/imports/{import}/errors.csv', [Admin\ImportController::class, 'errors'])->name('imports.errors');

    Route::get('/reviews', [Admin\ReviewController::class, 'index'])->name('reviews.index');
    Route::patch('/reviews/{review}/approve', [Admin\ReviewController::class, 'approve'])->name('reviews.approve');
    Route::delete('/reviews/{review}', [Admin\ReviewController::class, 'destroy'])->name('reviews.destroy');
});

// Lab pages (stage demos): local/testing, or admins elsewhere (LabAccess).
Route::prefix('lab')->name('lab.')->middleware('lab')->group(function () {
    Route::get('/relations', LabRelationsController::class)->name('relations');
    Route::get('/n-plus-one', LabNPlusOneController::class)->name('n-plus-one');
    Route::get('/collections', LabCollectionsController::class)->name('collections');
    Route::get('/models', LabModelsController::class)->name('models');
    Route::get('/xss', LabXssController::class)->name('xss');
    Route::get('/queues', [LabQueuesController::class, 'index'])->name('queues');
    Route::post('/queues/failed/{action}', [LabQueuesController::class, 'failed'])->name('queues.failed');
    Route::post('/queues/{variant}', [LabQueuesController::class, 'dispatch'])->name('queues.dispatch');
    Route::get('/realtime', [LabRealtimeController::class, 'index'])->name('realtime');
    Route::post('/realtime/{variant}', [LabRealtimeController::class, 'dispatch'])->name('realtime.dispatch');
});

// Any unmatched URL => our custom 404 page (instead of the framework's default).
Route::fallback(fn () => response()->view('errors.404', [], 404));
