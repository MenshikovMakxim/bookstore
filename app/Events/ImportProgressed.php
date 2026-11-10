<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An import chunk finished, or the import settled/failed. The import page then
 * fetches the full progress JSON it used to poll for: the socket message is only
 * a signal ("something changed"), the data still comes over authorized HTTP.
 *
 * ShouldBroadcastNow: sent right away by the import worker, not queued behind
 * the other import chunks. ShouldRescue: if Reverb is down, the exception is
 * reported and swallowed — a dead WebSocket server must not fail the import.
 */
class ImportProgressed implements ShouldBroadcastNow, ShouldRescue
{
    use Dispatchable;

    public function __construct(public int $importId) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        // "private-imports.7" on the wire; authorized in routes/channels.php.
        return [new PrivateChannel('imports.'.$this->importId)];
    }

    public function broadcastAs(): string
    {
        return 'import.progressed';
    }
}
