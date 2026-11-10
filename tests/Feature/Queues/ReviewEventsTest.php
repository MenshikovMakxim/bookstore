<?php

use App\Enums\QueueName;
use App\Events\ReviewApproved;
use App\Events\ReviewCreated;
use App\Events\ReviewDeleted;
use App\Events\ReviewRejected;
use App\Jobs\RecalculateBookRating;
use App\Listeners\DispatchRatingRecalculation;
use App\Listeners\NotifyModeratorsOfPendingReview;
use App\Models\Book;
use App\Models\Review;
use App\Models\User;
use App\Notifications\PendingReviewNotification;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    app()->setLocale('uk');
    $this->book = Book::factory()->create();
});

function postReview(Book $book): TestResponse
{
    return test()->post(route('books.reviews.store', $book), ['rating' => 5, 'body' => 'Дуже цікава книга, раджу всім прочитати.']);
}

test('events fire on create, approve, reject and delete', function () {
    Event::fake();

    asReader();
    postReview($this->book);
    $review = Review::withoutGlobalScopes()->sole();
    Event::assertDispatched(ReviewCreated::class, fn (ReviewCreated $e) => $e->review->is($review));

    $this->delete(route('reviews.destroy', $review));
    Event::assertDispatched(ReviewDeleted::class, fn (ReviewDeleted $e) => $e->bookId === $this->book->id && ! $e->wasApproved);

    asAdmin();
    $pending = Review::factory()->for($this->book)->create();
    $this->patch(route('admin.reviews.approve', $pending));
    Event::assertDispatched(ReviewApproved::class, fn (ReviewApproved $e) => $e->review->is($pending));

    $other = Review::factory()->for($this->book)->create();
    $this->delete(route('admin.reviews.destroy', $other));
    Event::assertDispatched(ReviewRejected::class, fn (ReviewRejected $e) => $e->reviewId === $other->id);
});

test('listeners are auto-discovered for the right events', function () {
    Event::fake(); // assertListening() lives on the fake; it still sees the real registrations
    Event::assertListening(ReviewCreated::class, NotifyModeratorsOfPendingReview::class);
    Event::assertListening(ReviewApproved::class, DispatchRatingRecalculation::class);
    Event::assertListening(ReviewRejected::class, DispatchRatingRecalculation::class);
    Event::assertListening(ReviewDeleted::class, DispatchRatingRecalculation::class);
});

test('listeners are queued on the queues from Queue::route()', function () {
    Queue::fake();

    asReader();
    postReview($this->book);

    Queue::assertPushedOn(QueueName::Notifications->value, CallQueuedListener::class,
        fn (CallQueuedListener $job) => $job->class === NotifyModeratorsOfPendingReview::class);

    asAdmin();
    $this->patch(route('admin.reviews.approve', Review::withoutGlobalScopes()->sole()));

    Queue::assertPushedOn(QueueName::Ratings->value, CallQueuedListener::class,
        fn (CallQueuedListener $job) => $job->class === DispatchRatingRecalculation::class);
});

test('shouldQueue(): removing a pending review does not touch the rating', function () {
    Queue::fake();

    ReviewDeleted::dispatch(1, $this->book->id, 1, false);
    Queue::assertNotPushed(CallQueuedListener::class);

    ReviewRejected::dispatch(2, $this->book->id, 1, true);
    Queue::assertPushed(CallQueuedListener::class, 1);
});

test('moderators get a mail + database + broadcast notification about a pending review', function () {
    Notification::fake();
    $admins = User::factory()->admin()->count(2)->create();
    $manager = User::factory()->manager()->create();

    asReader();
    postReview($this->book); // sync queue in tests: the queued listener runs right away

    Notification::assertSentTo($admins, PendingReviewNotification::class,
        fn (PendingReviewNotification $n, array $channels) => $channels === ['mail', 'database', 'broadcast'] && $n->bookTitle === $this->book->title);
    Notification::assertNotSentTo($manager, PendingReviewNotification::class);
});

test('the pending-review mail renders in Ukrainian', function () {
    $review = Review::factory()->for($this->book)->create(['rating' => 4]);
    $review->load(['book', 'user']);

    $html = (new PendingReviewNotification($review))->toMail(User::factory()->admin()->create())->render();

    expect((string) $html)->toContain('Новий відгук чекає на модерацію')->toContain($this->book->title);
});

test('ShouldDispatchAfterCommit: an event fired in a rolled-back transaction reaches no listener', function () {
    $heard = 0;
    Event::listen(ReviewCreated::class, function () use (&$heard) {
        $heard++;
    });
    $review = Review::factory()->for($this->book)->create();

    try {
        DB::transaction(function () use ($review) {
            ReviewCreated::dispatch($review);
            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }

    expect($heard)->toBe(0);

    DB::transaction(fn () => ReviewCreated::dispatch($review));
    expect($heard)->toBe(1);
});

test('approving through moderation updates books.rating_avg (approved reviews only)', function () {
    Review::factory()->approved()->for($this->book)->create(['rating' => 5]);
    Review::factory()->for($this->book)->create(['rating' => 1]); // pending: ignored
    $pending = Review::factory()->for($this->book)->create(['rating' => 2]);

    asAdmin();
    $this->patch(route('admin.reviews.approve', $pending));

    expect((float) $this->book->fresh()->rating_avg)->toBe(3.5);
});

test('rating jobs go to the ratings queue', function () {
    Queue::fake();

    RecalculateBookRating::dispatch($this->book->id);

    Queue::assertPushedOn('ratings', RecalculateBookRating::class);
});
