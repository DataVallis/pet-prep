<?php

namespace App\Http\Controllers;

use App\Exceptions\TimezoneChangeTooSoonException;
use App\Http\Requests\UpdateParentSettingsRequest;
use App\Services\FamilySettingsService;
use Illuminate\Http\JsonResponse;

class ParentSettingsController extends Controller
{
    public function __construct(private readonly FamilySettingsService $settings) {}

    /**
     * Update family settings (currently the family timezone).
     *
     * PUT /api/parent/settings — the app's »Časovni pas družine« row (David
     * 2026-11-08). The same zone again is a no-op. A real change is allowed at
     * most once per 24 h per family → otherwise 422 `timezone_change_too_soon`
     * with `next_allowed_at` (ISO 8601 in the current family zone). On success
     * every family pet gets a `family_timezone_changed` broadcast.
     */
    public function update(UpdateParentSettingsRequest $request): JsonResponse
    {
        try {
            $parent = $this->settings->updateTimezone($request->user(), $request->validated('timezone'));
        } catch (TimezoneChangeTooSoonException $e) {
            return response()->json($e->body(), 422);
        }

        return response()->json([
            'message' => 'Settings updated successfully.',
            'settings' => [
                'timezone' => $parent->timezone,
            ],
        ], 200);
    }
}
