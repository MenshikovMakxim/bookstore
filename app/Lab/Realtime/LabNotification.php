<?php

namespace App\Lab\Realtime;

use App\Models\User;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * Experiment 5: a broadcast-only notification to the current user. Goes to the
 * private channel App.Models.User.{id} (User::receivesBroadcastNotificationsOn()
 * default); Echo listens with ->notification().
 */
class LabNotification extends Notification
{
    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        return ['broadcast'];
    }

    public function toBroadcast(User $notifiable): BroadcastMessage
    {
        return new BroadcastMessage([
            'kind' => 'lab',
            'user_id' => $notifiable->id,
            'sentAt' => (int) (microtime(true) * 1000),
        ]);
    }
}
