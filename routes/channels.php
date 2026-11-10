<?php

use App\Enums\UserRole;
use App\Models\Import;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
| Authorization of private (and presence) channels. Echo calls POST /broadcasting/auth
| before subscribing; the callback gets the logged-in user (guests are always denied)
| and the channel name's {placeholders}. true => 200 and a signature, false => 403.
| Public channels (new Channel('warehouse'), 'lab.timeline') are not listed here:
| anyone may subscribe to them.
*/

// Database + broadcast notifications ($user->notify()) go to this channel.
Broadcast::channel('App.Models.User.{id}', fn (User $user, int $id) => $user->id === $id);

// Import progress: the same rule as the admin import pages (role:admin).
// {import} is bound like a route parameter: a missing import => denied.
Broadcast::channel('imports.{import}', fn (User $user, Import $import) => $user->role === UserRole::Admin);
