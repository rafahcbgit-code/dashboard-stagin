<?php

use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Here you may register all of the event broadcasting channels that your
| application supports. The given channel authorization callbacks are
| used to check if an authenticated user can listen to the channel.
|
*/

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('chats', function ($user) {
    return true; // Adjust authentication logic if needed
});

/*
|--------------------------------------------------------------------------
| Campaign Progress Channel
|--------------------------------------------------------------------------
|
| Private channel for real-time campaign progress updates.
| User must belong to the organization that owns the campaign.
|
*/
Broadcast::channel('campaign.{uuid}', function ($user, $uuid) {
    $campaign = \App\Models\Campaign::where('uuid', $uuid)->first();
    if (! $campaign) {
        return false;
    }

    // User must belong to organization that owns this campaign
    return $user->teams()
        ->where('organization_id', $campaign->organization_id)
        ->exists();
});
