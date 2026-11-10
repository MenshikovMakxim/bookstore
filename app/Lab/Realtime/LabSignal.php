<?php

namespace App\Lab\Realtime;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Experiment 1: a plain event (not broadcast) with three listeners, see
 * AppServiceProvider::configureLab(). The timeline shows who ran where and when.
 */
class LabSignal
{
    use Dispatchable;

    /**
     * @param  bool  $halt  the sync listener returns false: listeners after it do not run
     */
    public function __construct(
        public string $variant,
        public bool $halt = false,
    ) {}
}
