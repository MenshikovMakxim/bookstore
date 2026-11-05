<?php

namespace App\Lab\Realtime;

use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * The same event, sent synchronously inside the request: arrives even with the
 * worker stopped, but the request waits for the HTTP call to Reverb (and fails
 * with a BroadcastException when Reverb is down — the controller shows it).
 */
class LabBroadcastNow extends LabBroadcast implements ShouldBroadcastNow {}
