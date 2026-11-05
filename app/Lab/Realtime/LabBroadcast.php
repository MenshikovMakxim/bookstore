<?php

namespace App\Lab\Realtime;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Experiment 2: a broadcast event. ShouldBroadcast is queued (the "broadcasts"
 * queue, Queue::route() in AppServiceProvider): no worker => no message.
 * See LabBroadcastNow for the immediate variant.
 */
class LabBroadcast implements ShouldBroadcast
{
    // InteractsWithSockets: ->toOthers() / dontBroadcastToCurrentUser() (experiment 3).
    use Dispatchable, InteractsWithSockets;

    public int $createdAt;

    public function __construct(public string $variant)
    {
        $this->createdAt = (int) (microtime(true) * 1000);
    }

    /**
     * @return list<Channel>
     */
    public function broadcastOn(): array
    {
        return [new Channel('lab.timeline')];
    }

    public function broadcastAs(): string
    {
        return 'lab.broadcast';
    }

    /**
     * Runs where the message is sent: on the worker for ShouldBroadcast, in the
     * request for ShouldBroadcastNow. sent_at - created_at = time spent in the queue.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'variant' => $this->variant,
            'origin' => app()->runningInConsole() ? 'worker' : 'web',
            'pid' => getmypid() ?: 0,
            'createdAt' => $this->createdAt,
            'sentAt' => (int) (microtime(true) * 1000),
        ];
    }
}
