<?php

use App\Models\Pet;
use App\Models\User;
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

/*
| pet.updated.{petId} — Real-time pet metric updates for the parent dashboard.
|
| Only the parent who owns the child (via the pet's user -> parent_id)
| or the child who owns the pet may listen to this channel.
*/
Broadcast::channel('pet.updated.{petId}', function (User $user, int $petId) {
    $pet = Pet::find($petId);

    if (! $pet) {
        return false;
    }

    // The child who owns the pet can listen
    if ($pet->user_id === $user->id) {
        return ['id' => $user->id, 'role' => $user->role->value];
    }

    // The parent of the child who owns the pet can listen
    $petOwner = $pet->user;
    if ($petOwner && $petOwner->parent_id === $user->id) {
        return ['id' => $user->id, 'role' => $user->role->value];
    }

    return false;
});
