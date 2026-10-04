<?php

use App\Models\Pet;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Authorized by POST /api/broadcasting/auth (Sanctum bearer token, see
| bootstrap/app.php). Only private channels exist; every event is a
| PrivateChannel (M1-08).
|
*/

Broadcast::channel('App.Models.User.{id}', function (User $user, $id) {
    return (int) $user->id === (int) $id;
});

/*
| pet.{petId} (wire name `private-pet.{petId}`) — PetUpdated for the child
| HUD and the parent dashboard.
|
| Allowed: the child who owns the pet, and that child's parent
| (users.parent_id) — PetPolicy::listen. Nobody else: not another child,
| not another parent, not a guest (the auth route requires a token).
*/
Broadcast::channel('pet.{petId}', function (User $user, $petId): bool {
    if (! ctype_digit((string) $petId)) {
        return false;
    }

    $pet = Pet::with('user:id,parent_id')->find((int) $petId);

    return $pet !== null && $user->can('listen', $pet);
});
