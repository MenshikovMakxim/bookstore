<?php

use App\Enums\ImportStatus;
use App\Events\BookStockChanged;
use App\Events\ImportProgressed;
use App\Imports\BookCsv;
use App\Jobs\ImportBooksChunk;
use App\Models\Book;
use App\Models\Genre;
use App\Models\Import;
use App\Models\Stock;
use App\Models\Warehouse;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    app()->setLocale('uk');
    $this->book = Book::factory()->create();
    [$a, $b] = Warehouse::factory()->count(2)->create();
    $this->book->warehouses()->attach($a, ['quantity' => 7, 'reserved' => 2]);
    $this->book->warehouses()->attach($b, ['quantity' => 4, 'reserved' => 0]);
});

test('payload: book totals over all warehouses, rendered like the StockBadge', function () {
    $event = new BookStockChanged($this->book->id);

    expect($event->broadcastOn())->toEqual([new Channel('warehouse')])
        ->and($event->broadcastAs())->toBe('stock.changed')
        ->and($event->broadcastWith())->toBe([
            'book_id' => $this->book->id,
            'state' => 'in',
            'label' => __('ui.stock_badge.in'),
            'available' => 9,
        ]);
});

test('a quantity or reserved change is queued on "broadcasts"; a shelf change is not broadcast', function () {
    Queue::fake();
    $stock = Stock::where('book_id', $this->book->id)->orderBy('id')->first();

    $stock->update(['shelf_code' => 'Z-99-9']);
    Queue::assertNotPushed(BroadcastEvent::class);

    $stock->update(['reserved' => 3]);
    Queue::assertPushedOn('broadcasts', BroadcastEvent::class, fn (BroadcastEvent $job) => $job->event instanceof BookStockChanged
        && $job->event->bookId === $this->book->id);
});

test('ShouldDispatchAfterCommit: a change inside a rolled-back transaction is not broadcast', function () {
    Queue::fake();

    try {
        DB::transaction(function () {
            Stock::where('book_id', $this->book->id)->first()->update(['quantity' => 1]);
            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }

    Queue::assertNotPushed(BroadcastEvent::class);
});

test('import rows: dispatched with fromImport, broadcastWhen() drops them; the import page gets ImportProgressed', function () {
    Event::fake([BookStockChanged::class, ImportProgressed::class]);
    Storage::fake('imports');
    Genre::factory()->create(['slug' => 'proza']);
    Genre::factory()->create(['slug' => 'poeziia']);
    $admin = asAdmin();
    Storage::disk('imports')->put('import-mixed.csv', file_get_contents(base_path('tests/Fixtures/import-mixed.csv')));
    $import = Import::create([
        'user_id' => $admin->id,
        'warehouse_id' => Warehouse::factory()->create()->id,
        'original_name' => 'import-mixed.csv',
        'path' => 'import-mixed.csv',
        'status' => ImportStatus::Processing,
        'total_rows' => BookCsv::countRows(Storage::disk('imports')->path('import-mixed.csv')),
    ]);

    ImportBooksChunk::dispatchSync($import, 0, 2);
    ImportBooksChunk::dispatchSync($import, 2, 500);
    Import::settle($import->id);

    Event::assertDispatched(BookStockChanged::class, fn (BookStockChanged $e) => $e->fromImport && ! $e->broadcastWhen());
    Event::assertNotDispatched(BookStockChanged::class, fn (BookStockChanged $e) => ! $e->fromImport);
    // Two chunks + settle.
    Event::assertDispatchedTimes(ImportProgressed::class, 3);
    Event::assertDispatched(ImportProgressed::class, fn (ImportProgressed $e) => $e->broadcastOn() == [new PrivateChannel("imports.{$import->id}")]);
});
