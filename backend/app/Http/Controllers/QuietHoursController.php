<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateQuietHoursRequest;
use App\Models\QuietHours;
use App\Services\FamilySettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class QuietHoursController extends Controller
{
    public function __construct(private readonly FamilySettingsService $settings) {}

    /**
     * Get the quiet hours configuration for the authenticated parent.
     *
     * GET /api/parent/quiet-hours
     */
    public function show(): JsonResponse
    {
        $parent = request()->user();

        if (! $parent->isParent()) {
            return response()->json(['message' => 'Only parent profiles can manage quiet hours.'], 403);
        }

        $quietHours = $parent->quietHours;

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

            return QuietHours::updateOrCreate(
                ['parent_id' => $parent->id],
                $request->only([
                    'school_start',
                    'school_end',
                    'bedtime_start',
                    'bedtime_end',
                    'is_active',
                ])
            );
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
