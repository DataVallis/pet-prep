<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateParentSettingsRequest;
use App\Services\FamilySettingsService;
use Illuminate\Http\JsonResponse;

class ParentSettingsController extends Controller
{
    public function __construct(private readonly FamilySettingsService $settings) {}

    /**
     * Update family settings (currently the family timezone).
     *
     * PUT /api/parent/settings
     */
    public function update(UpdateParentSettingsRequest $request): JsonResponse
    {
        $parent = $this->settings->updateTimezone($request->user(), $request->validated('timezone'));

        return response()->json([
            'message' => 'Settings updated successfully.',
            'settings' => [
                'timezone' => $parent->timezone,
            ],
        ], 200);
    }
}
