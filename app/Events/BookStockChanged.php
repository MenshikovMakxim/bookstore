<?php

namespace App\Events;

use App\Models\Stock;
use App\View\Components\StockBadge;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A book's stock changed (StockObserver): every open catalog/book page updates
 * its badge. Public channel: availability is public information anyway.
 *
 * ShouldBroadcast = queued: the request only inserts a job row; the worker sends
 * the message to Reverb. No worker running => nothing reaches the browser.
 * ShouldDispatchAfterCommit: a rolled-back stock change is never broadcast.
 */
class BookStockChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public int $bookId,
        public bool $fromImport = false,
    ) {}

    /**
     * @return list<Channel>
     */
    public function broadcastOn(): array
    {
        return [new Channel('warehouse')];
    }

    /**
     * Client-side name: Echo listens with a leading dot, `.stock.changed`
     * (without broadcastAs it would be the class name, App\Events\BookStockChanged).
     */
    public function broadcastAs(): string
    {
        return 'stock.changed';
    }

    /**
     * Evaluated at dispatch time, before a job is queued. A CSV import changes
     * thousands of rows: one message per row would flood every open page
     * (the import page has its own channel, see ImportProgressed).
     */
    public function broadcastWhen(): bool
    {
        return ! $this->fromImport;
    }

    /**
     * Runs on the worker when the message is sent, so the totals are current even
     * if several changes were queued. Without broadcastWith every public property
     * is sent as is; here we send what the badge needs and nothing more.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $totals = Stock::where('book_id', $this->bookId)
            ->selectRaw('coalesce(sum(quantity), 0) as quantity, coalesce(sum(reserved), 0) as reserved')
            ->toBase()
            ->first();

        $badge = new StockBadge((int) $totals->quantity, (int) $totals->reserved);

        return [
            'book_id' => $this->bookId,
            'state' => $badge->state(),
            'label' => $badge->label(),
            'available' => $badge->available(),
        ];
    }
}
