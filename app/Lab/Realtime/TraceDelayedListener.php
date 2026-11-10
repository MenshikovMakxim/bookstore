<?php

namespace App\Lab\Realtime;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Attributes\Delay;

/**
 * Queued with a delay: the job row gets available_at = now + 5 s.
 */
#[Delay(5)]
class TraceDelayedListener implements ShouldQueue
{
    // Legacy syntax, equivalent to #[Delay(5)]:
    // public $delay = 5;

    public function handle(LabSignal $event): void
    {
        LabTrace::dispatch($event->variant, 'delayed_listener');
    }
}
