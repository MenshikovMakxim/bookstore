<?php

namespace App\Lab\Realtime;

/**
 * Not queued: runs inside the request, before the response is sent. An exception
 * here would turn the request into a 500.
 */
class TraceSyncListener
{
    public function handle(LabSignal $event): ?bool
    {
        LabTrace::dispatch($event->variant, 'sync_listener');

        // Returning false stops propagation: listeners registered after this one
        // are not called — queued ones are not even pushed to the queue.
        if ($event->halt) {
            LabTrace::dispatch($event->variant, 'propagation_stopped');

            return false;
        }

        return null;
    }
}
