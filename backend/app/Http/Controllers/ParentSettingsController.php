<?php

namespace App\Http\Controllers;

use App\Exceptions\TimezoneChangeTooSoonException;
use App\Http\Requests\PatchParentSettingsRequest;
use App\Http\Requests\UpdateParentSettingsRequest;
use App\Services\FamilySettingsService;
use App\Services\Validation\AcquisitionSourceService;
use Illuminate\Http\JsonResponse;

class ParentSettingsController extends Controller
{
    public function __construct(
        private readonly FamilySettingsService $settings,
        private readonly AcquisitionSourceService $acquisition,
    ) {}

    /**
     * Update family settings (currently the family timezone).
     *
     * PUT /api/parent/settings — the app's »Časovni pas družine« row (David
     * 2026-11-08). The same zone again is a no-op. A real change is allowed at
     * most once per 24 h per family → otherwise 422 `timezone_change_too_soon`
     * with `next_allowed_at` (ISO 8601 in the current family zone). On success
     * every family pet gets a `family_timezone_changed` broadcast.
     * (The acquisition answer goes through PATCH, not PUT.)
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

    /**
     * Partial update of the parent settings: the family `timezone` (optional,
     * same rules as PUT) and / or the one-time »Kje ste izvedeli za PetPrep?«
     * answer (VALIDATION_PLAN §4.1, David 2026-11-21).
     *
     * - `acquisition_source` / `acquisition_detail` are stored only while this
     *   parent has no answer yet — one chance for parents who signed up before
     *   the question existed. Once set, they are ignored (still 200); an
     *   unknown value is ignored too (never a 422). `detail` is kept only with
     *   `other` / `ad`, trimmed, at most 60 characters.
     * - `timezone` is required only when the body carries no acquisition field.
     * - Order: the answer is stored FIRST, then the timezone — a 422
     *   `timezone_change_too_soon` still keeps a stored answer.
     *
     * PATCH /api/parent/settings
     */
    public function patch(PatchParentSettingsRequest $request): JsonResponse
    {
        // The answer first: a timezone refused by the 24 h cooldown (422) must not lose it.
        $parent = $this->acquisition->recordOnce($request->user(), $request->acquisitionAnswer());

        if ($request->has('timezone')) {
            try {
                $parent = $this->settings->updateTimezone($parent, $request->validated('timezone'));
            } catch (TimezoneChangeTooSoonException $e) {
                return response()->json($e->body(), 422);
            }
        }

        return response()->json([
            'message' => 'Settings updated successfully.',
            'settings' => [
                'timezone' => $parent->timezone,
                /**
                 * The stored answer (null = not answered yet).
                 *
                 * @var 'friend_family'|'instagram'|'tiktok'|'facebook'|'youtube'|'school_kindergarten'|'search'|'website'|'ad'|'other'|null
                 */
                'acquisition_source' => $parent->acquisition_source,
            ],
        ], 200);
    }
}
