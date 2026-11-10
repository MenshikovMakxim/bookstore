<?php

namespace App\Lab\Realtime;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * One step of a lab experiment ("event dispatched", "sync listener ran", ...),
 * pushed to the /lab/realtime timeline.
 *
 * ShouldBroadcastNow: sent from the process that runs the step (web request or
 * worker), so the timeline does not depend on the broadcasts queue.
 * ShouldRescue: with Reverb down the trace is lost, the experiment still runs.
 */
class LabTrace implements ShouldBroadcastNow, ShouldRescue
{
    use Dispatchable;

    public string $origin;

    public int $pid;

    public int $sentAt;

    /**
     * @param  string  $step  lang key lab.realtime.steps.{step}
     */
    public function __construct(
        public string $variant,
        public string $step,
    ) {
        // Public properties ARE the payload when there is no broadcastWith().
        $this->origin = app()->runningInConsole() ? 'worker' : 'web';
        $this->pid = getmypid() ?: 0;
        $this->sentAt = (int) (microtime(true) * 1000);
    }

    /**
     * Public channel: lab routes are admin-only outside local, the payload is noise.
     *
     * @return list<Channel>
     */
    public function broadcastOn(): array
    {
        return [new Channel('lab.timeline')];
    }

    public function broadcastAs(): string
    {
        return 'lab.trace';
    }
}
