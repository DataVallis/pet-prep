<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateChildDisplayRequest;
use App\Models\User;
use App\Services\ChildProfileService;
use Illuminate\Http\JsonResponse;

/**
 * The child's own app settings (M5-T11). Today only »Prikaz: Video / Slika».
 */
class ChildSettingsController extends Controller
{
    public function __construct(private readonly ChildProfileService $profiles) {}

    /**
     * The child changes »Prikaz: Video / Slika« (APP_REFINEMENT_SPEC §1): `video` (default)
     * plays the state videos, `picture` shows the key image of the pet's current state and
     * downloads no video. A parent of the family can set the same value
     * (`PATCH /api/parent/children/{child}/settings`). A change broadcasts
     * `caretaker_settings_changed` on the child's active pet. 200 `{media_display}`;
     * parent token → 403.
     *
     * PATCH /api/child/settings
     */
    public function update(UpdateChildDisplayRequest $request): JsonResponse
    {
        /** @var User $child */
        $child = $request->user();

        $display = $request->mediaDisplay();
        $this->profiles->setMediaDisplay($child, $display);

        return response()->json([
            /** @var 'video'|'picture' */
            'media_display' => $display->value,
        ], 200);
    }
}
