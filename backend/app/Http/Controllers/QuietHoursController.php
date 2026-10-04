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

        $family = $this->families->ensureFamilyFor($parent);
        $quietHours = QuietHours::where('family_id', $family->id)->first();

        if (! $quietHours) {
            return response()->json([
                'message' => 'No quiet hours configured.',
                'quiet_hours' => null,
            ], 200);
        }

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

            $existing = QuietHours::where('family_id', $family->id)->lockForUpdate()->first();
            if ($existing !== null) {
                $existing->update($values);

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
            'id' => $quietHours->id,
            'school_start' => $quietHours->school_start ? substr($quietHours->school_start, 0, 5) : null,
            'school_end' => $quietHours->school_end ? substr($quietHours->school_end, 0, 5) : null,
            'bedtime_start' => $quietHours->bedtime_start ? substr($quietHours->bedtime_start, 0, 5) : null,
            'bedtime_end' => $quietHours->bedtime_end ? substr($quietHours->bedtime_end, 0, 5) : null,
            'is_active' => $quietHours->is_active,
        ];
    }
}
