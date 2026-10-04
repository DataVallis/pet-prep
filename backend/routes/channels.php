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
| Allowed (family model, M2-01): every caretaker child of the pet and every
| parent of the pet's family — PetPolicy::listen. Nobody else: not a child
| of the same family who cares for another pet, not a parent of another
| family, not a guest (the auth route requires a token).
*/
Broadcast::channel('pet.{petId}', function (User $user, $petId): bool {
    if (! ctype_digit((string) $petId)) {
        return false;
    }

    $pet = Pet::find((int) $petId);

    return $pet !== null && $user->can('listen', $pet);
});
