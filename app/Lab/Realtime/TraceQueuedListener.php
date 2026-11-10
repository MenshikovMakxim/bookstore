<?php

namespace App\Lab\Realtime;

use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Queued: the request only stores a CallQueuedListener job; the worker calls handle()
 * later (origin "worker" and another pid on the timeline). Worker stopped => this step
 * never appears, while the sync one does.
 */
class TraceQueuedListener implements ShouldQueue
{
    public function handle(LabSignal $event): void
    {
        LabTrace::dispatch($event->variant, 'queued_listener');
    }
}
