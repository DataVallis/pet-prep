<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateQuietHoursRequest;
use App\Models\QuietHours;
use App\Services\FamilyService;
use App\Services\FamilySettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class QuietHoursController extends Controller
{
    public function __construct(
        private readonly FamilySettingsService $settings,
        private readonly FamilyService $families,
    ) {}

    /**
     * Get the family's quiet hours (one configuration per family; any
     * parent of the family reads and edits it — M2-01).
     *
     * GET /api/parent/quiet-hours
     */
    public function show(): JsonResponse
    {
        $parent = request()->user();

        if (! $parent->isParent()) {
            return response()->json(['message' => 'Only parent profiles can manage quiet hours.'], 403);
        }

        // GET never writes (no family created on read). Shows what the
        // server applies: without a row that is QuietHours::DEFAULTS, marked
        // `saved: false` (fix/quiet-hours-default, QA PR #80 m2).
        $family = $this->families->familyOf($parent);
        $quietHours = ($family !== null ? QuietHours::where('family_id', $family->id)->first() : null)
            ?? QuietHours::defaultFor($family);

        return response()->json([
            'quiet_hours' => $this->formatQuietHours($quietHours),
            'timezone' => $parent->familyTimezone(),
        ], 200);
    }

    /**
     * Create or update the quiet hours configuration for the authenticated parent.
     *
     * PUT /api/parent/quiet-hours
     */
    public function update(UpdateQuietHoursRequest $request): JsonResponse
    {
        $parent = $request->user();

        if (! $parent->isParent()) {
            return response()->json(['message' => 'Only parent profiles can manage quiet hours.'], 403);
        }

        $quietHours = DB::transaction(function () use ($request, $parent): QuietHours {
            if ($request->has('timezone')) {
                $this->settings->updateTimezone($parent, $request->validated('timezone'));
            }

            $family = $this->families->ensureFamilyFor($parent);
            $values = $request->only([
                'school_start',
                'school_end',
                'bedtime_start',
                'bedtime_end',
                'is_active',
            ]);

            $existing = QuietHours::where('family_id', $family->id)->lockForUpdate()->first()
                // A row this parent created without a family (old code during
                // the deploy window) is adopted, not duplicated (parent_id is
                // unique — a second insert would be a 500).
                ?? QuietHours::where('parent_id', $parent->id)->whereNull('family_id')->lockForUpdate()->first();
            if ($existing !== null) {
                $existing->update(array_merge($values, ['family_id' => $family->id]));

                return $existing;
            }

            // parent_id = the parent who created the family's configuration.
            return QuietHours::create(array_merge($values, [
                'family_id' => $family->id,
                'parent_id' => $parent->id,
            ]));
        });

        return response()->json([
            'message' => 'Quiet hours updated successfully.',
            'quiet_hours' => $this->formatQuietHours($quietHours),
            'timezone' => $parent->familyTimezone(),
        ], 200);
    }

    /**
     * Format quiet hours times consistently (HH:MM format).
     *
     * @return array<string, mixed>
     */
    private function formatQuietHours(QuietHours $quietHours): array
    {
        return [
            // null (with saved = false) while the family has no stored row.
            'id' => $quietHours->exists ? $quietHours->id : null,
            'school_start' => $quietHours->school_start ? substr($quietHours->school_start, 0, 5) : null,
            'school_end' => $quietHours->school_end ? substr($quietHours->school_end, 0, 5) : null,
            'bedtime_start' => $quietHours->bedtime_start ? substr($quietHours->bedtime_start, 0, 5) : null,
            'bedtime_end' => $quietHours->bedtime_end ? substr($quietHours->bedtime_end, 0, 5) : null,
            'is_active' => $quietHours->is_active,
            // false = the defaults apply but no parent has saved them yet.
            'saved' => (bool) $quietHours->exists,
        ];
    }
}
