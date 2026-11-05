<?php

use App\Enums\ImportStatus;
use App\Models\Import;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Testing\TestResponse;

/*
| phpunit.xml uses the "null" broadcaster, which authorizes nothing. Here we switch to
| the reverb driver (Pusher protocol, no network needed for auth) and register
| routes/channels.php on it again.
*/
beforeEach(function () {
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => 'test-app',
    ]);
    app(BroadcastManager::class)->forgetDrivers();
    require base_path('routes/channels.php');
});

function authChannel(string $channel): TestResponse
{
    return test()->post('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => $channel]);
}

test('own user channel: signed; someone else\'s: 403; guest: 403', function () {
    $other = User::factory()->create();
    $me = asReader();

    authChannel("private-App.Models.User.{$me->id}")->assertOk()->assertJsonStructure(['auth']);
    authChannel("private-App.Models.User.{$other->id}")->assertForbidden();

    auth()->logout();
    authChannel("private-App.Models.User.{$me->id}")->assertForbidden();
});

test('import channel: admins only; a missing import is denied', function () {
    $admin = User::factory()->admin()->create();
    $import = Import::create([
        'user_id' => $admin->id,
        'warehouse_id' => Warehouse::factory()->create()->id,
        'original_name' => 'a.csv',
        'path' => 'a.csv',
        'status' => ImportStatus::Pending,
        'total_rows' => 1,
    ]);

    asManager();
    authChannel("private-imports.{$import->id}")->assertForbidden();

    $this->actingAs($admin);
    authChannel("private-imports.{$import->id}")->assertOk();
    authChannel('private-imports.999')->assertForbidden();
});
