<?php

namespace App\Enums;

/**
 * Queue names, in the order a worker should poll them:
 * queue:work --queue=broadcasts,ratings,imports,notifications,default
 * Job/listener/notification => queue mapping: AppServiceProvider::configureQueues().
 */
enum QueueName: string
{
    // Queued broadcasts (ShouldBroadcast): tiny HTTP calls to Reverb; a live update
    // that waits behind a CSV import is not live any more.
    case Broadcasts = 'broadcasts';
    // Small and frequent: a rating should update within seconds of moderation.
    case Ratings = 'ratings';
    // Long CSV chunks; separate so they do not delay everything else.
    case Imports = 'imports';
    case Notifications = 'notifications';
    case Default = 'default';
}
