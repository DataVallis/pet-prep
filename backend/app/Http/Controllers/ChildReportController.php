<?php

namespace App\Http\Controllers;

use App\Http\Requests\ChildReportRequest;
use App\Models\User;
use App\Services\ChildProfileService;
use App\Services\FamilyDashboardService;
use App\Services\FamilyService;
use Illuminate\Http\JsonResponse;

/**
 * Parent's detail report of one child (M2-05 / M2-06): Care Score, traffic
 * light, 12-week progress and the routine history of the last 7, 30 or 84
 * family-local days. Family scoped: another family's child is a 404.
 */
class ChildReportController extends Controller
{
    public function __construct(
        private readonly ChildProfileService $profiles,
        private readonly FamilyService $families,
        private readonly FamilyDashboardService $dashboard,
    ) {}

    /**
     * GET /api/parent/children/{child}/report?days=7|30|84
     */
    public function show(ChildReportRequest $request, string $child): JsonResponse
    {
        /** @var User $parent */
        $parent = $request->user();
        if (! $parent->can('manageFamily', User::class)) {
            abort(403);
        }

        $profile = $this->profiles->childOfParentFamily($parent, $child);
        $family = $this->families->familyOf($parent); // GET: lookup only
        if ($profile === null || $family === null || ! $parent->can('manageChild', $profile)) {
            return response()->json(['message' => 'No such child in your family.', 'reason' => 'child_not_found'], 404);
        }

        return response()->json($this->dashboard->childReport($family, $profile, $request->days()), 200);
    }
}
