<?php

use App\Events\BookStockChanged;
use App\Http\Controllers\Lab\LabRealtimeController;
use App\Lab\Realtime\LabBroadcast;
use App\Lab\Realtime\LabBroadcastNow;
use App\Lab\Realtime\LabNotification;
use App\Lab\Realtime\LabTrace;
use App\Lab\Realtime\TraceDelayedListener;
use App\Models\Book;
use App\Models\Stock;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Book::factory()->withAuthors(1)->inWarehouses(1)->create();
});

test('the page renders with a constant number of queries', function () {
    expectConstantQueries(
        fn (int $n) => Book::factory()->count($n)->inWarehouses(1)->create(),
        fn () => $this->get(route('lab.realtime')),
    );
});

test('every variant runs (JSON for fetch, redirect without JS)', function (string $variant) {
    asAdmin();

    $this->postJson(route('lab.realtime.dispatch', $variant))->assertOk()->assertJson(['ok' => true]);
    $this->post(route('lab.realtime.dispatch', $variant))->assertRedirect(route('lab.realtime'));
})->with(array_merge(...array_values(LabRealtimeController::GROUPS)));

test('unknown variant => 404', function () {
    $this->postJson(route('lab.realtime.dispatch', 'nope'))->assertNotFound();
});

/**
 * @return list<string> LabTrace steps in dispatch order
 */
function traceSteps(): array
{
    return Event::dispatched(LabTrace::class)->map(fn (array $args) => $args[0]->step)->all();
}

test('signal: sync listener in the request, queued ones on the "worker" (sync queue in tests)', function () {
    Event::fake([LabTrace::class]);

    $this->postJson(route('lab.realtime.dispatch', 'signal'))->assertOk();

    expect(traceSteps())->toBe(['dispatched', 'sync_listener', 'queued_listener', 'delayed_listener', 'request_done']);
});

test('signal: both queued listeners are pushed, not run', function () {
    Event::fake([LabTrace::class]);
    Queue::fake();

    $this->postJson(route('lab.realtime.dispatch', 'signal'))->assertOk();

    expect(traceSteps())->toBe(['dispatched', 'sync_listener', 'request_done']);
    Queue::assertPushed(CallQueuedListener::class, 2);
    Queue::assertPushed(CallQueuedListener::class, fn (CallQueuedListener $job) => $job->class === TraceDelayedListener::class);
});

test('signal_halt: return false stops propagation, nothing is queued', function () {
    Event::fake([LabTrace::class]);
    Queue::fake();

    $this->postJson(route('lab.realtime.dispatch', 'signal_halt'))->assertOk();

    expect(traceSteps())->toBe(['dispatched', 'sync_listener', 'propagation_stopped', 'request_done']);
    Queue::assertNothingPushed();
});

test('ShouldBroadcast is queued on "broadcasts", ShouldBroadcastNow is not queued', function () {
    Queue::fake();

    $this->postJson(route('lab.realtime.dispatch', 'broadcast_queued'))->assertOk();
    Queue::assertPushedOn('broadcasts', BroadcastEvent::class, fn (BroadcastEvent $job) => $job->event instanceof LabBroadcast);

    $this->postJson(route('lab.realtime.dispatch', 'broadcast_now'))->assertOk();
    Queue::assertPushed(BroadcastEvent::class, 1);
});

test('toOthers() takes the socket id from the X-Socket-ID header', function () {
    Event::fake([LabBroadcastNow::class]);

    $this->postJson(route('lab.realtime.dispatch', 'broadcast_now'), [], ['X-Socket-ID' => '1234.5678'])->assertOk();
    $this->postJson(route('lab.realtime.dispatch', 'broadcast_to_others'), [], ['X-Socket-ID' => '1234.5678'])->assertOk();

    expect(Event::dispatched(LabBroadcastNow::class)->map(fn (array $args) => [$args[0]->variant, $args[0]->socket])->all())
        ->toBe([['broadcast_now', null], ['broadcast_to_others', '1234.5678']]);
});

test('stock buttons change the demo stock through Eloquent and broadcast the book', function () {
    Event::fake([BookStockChanged::class]);
    $stock = Stock::orderBy('id')->first();
    $before = $stock->quantity;

    $this->postJson(route('lab.realtime.dispatch', 'stock_plus'))->assertOk();

    expect($stock->fresh()->quantity)->toBe($before + 1);
    Event::assertDispatched(BookStockChanged::class, fn (BookStockChanged $e) => $e->bookId === $stock->book_id && ! $e->fromImport);
});

test('stock_minus never goes below zero', function () {
    $stock = Stock::orderBy('id')->first();
    $stock->update(['quantity' => 0]);

    $this->postJson(route('lab.realtime.dispatch', 'stock_minus'))->assertOk();

    expect($stock->fresh()->quantity)->toBe(0);
});

test('notify_me: a broadcast-only notification to the current user; guests get 403', function () {
    Notification::fake();

    $this->postJson(route('lab.realtime.dispatch', 'notify_me'))->assertForbidden();

    $user = asReader();
    $this->postJson(route('lab.realtime.dispatch', 'notify_me'))->assertOk();

    Notification::assertSentTo($user, LabNotification::class, fn ($n, array $channels) => $channels === ['broadcast']);
});
