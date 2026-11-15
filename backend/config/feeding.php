<?php

/*
| Feeding — game rules that are not per breed.
|
| `hunger_floor_percent` (David 2026-11-15 »Izberem št. 1 in mejo 25 %«;
| PRODUCT_SPEC §5 »Spodnja meja lakote do naslednjega obroka«): while the
| pet's meal obligation is met (the last meal window of the child that
| started was fed — by the child, by the parent in quiet hours or by an
| emergency meal — or no window has started since the birth), hunger stops
| decaying at this value until the next meal window of the child opens; from
| that moment it decays normally again. A missed window has no floor (0 %,
| emergency meal, parent alarm as before). The same for every breed and
| species; values are never raised to the floor. 0 switches the rule off.
| Rule: App\Services\CareScheduleService::hungerFloorActive(); applied by the
| decay tick (App\Services\PetDecayService).
*/

return [
    'hunger_floor_percent' => 25,
];
