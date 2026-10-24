<?php

namespace App\Http\Controllers;

use App\Http\Requests\ConfirmCareReminderRequest;
use App\Models\User;
use App\Services\CareTasksPayload;
use App\Services\FamilyDashboardService;
use App\Services\FamilyService;
use App\Services\PetActivityService;
use App\Services\Results\ActionResult;
use Illuminate\Http\JsonResponse;

/**
 * M5-R18 / R20 (David 2026-10-24): the parent's reminders of a breed care need
 * that the source gives a frequency for — the professional groomer visit and
 * the bath. The parent confirms "done" in the parent app; nothing is scored.
 */
class CareReminderController extends Controller
{
    public function __construct(
        private readonly FamilyService $families,
        private readonly FamilyDashboardService $dashboard,
        private readonly PetActivityService $activities,
    ) {}

    /**
     * Confirm `task` (groomer_visit | bath) done for the pet's current period.
     * 200 `{status: accepted | unchanged, care_reminders}` (unchanged = already
     * confirmed in this period). 422 `care_task_not_available` (the pet has no
     * such reminder). Another family's pet → 404 `pet_not_found`.
     *
     * POST /api/parent/pets/{pet}/care-reminders
     */
    public function confirm(ConfirmCareReminderRequest $request, string $pet): JsonResponse
    {
        /** @var User $parent */
        $parent = $request->user();
        $family = $this->families->familyOf($parent);
        $target = $family !== null ? $this->dashboard->targetPet($family, $pet) : null;

        if ($target === null || ! $parent->can('manage', $target)) {
            return response()->json(['message' => 'No such pet in your family.', 'reason' => 'pet_not_found'], 404);
        }

        $result = $this->activities->confirmCareReminder($target, $parent, $request->task());
        if ($result->status === ActionResult::REFUSED) {
            return response()->json(['message' => 'This pet has no such reminder.', 'reason' => $result->refusal?->value], 422);
        }
        $target->refresh();

        return response()->json([
            /** @var 'accepted'|'unchanged' */
            'status' => $result->status,
            'pet_id' => $target->id,
            // The pet's reminders after the confirmation (same shape as the dashboard's pet `care_reminders`).
            'care_reminders' => CareTasksPayload::reminders($target),
        ]);
    }
}
