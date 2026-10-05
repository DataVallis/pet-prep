<?php

namespace App\Http\Controllers;

use App\Exceptions\AccountDeletionException;
use App\Http\Requests\ConfirmDeletionRequest;
use App\Models\User;
use App\Services\AccountDeletionService;
use App\Services\AccountExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The parent's own account (M2-08): delete it (App Store in-app deletion,
 * GDPR art. 17) and export the family's data (GDPR art. 15 / 20).
 */
class AccountController extends Controller
{
    public function __construct(
        private readonly AccountDeletionService $deletion,
        private readonly AccountExportService $export,
    ) {}

    /**
     * Delete the signed-in parent's account — immediately and irreversibly.
     * The family's LAST parent deletes the whole family: every child profile,
     * pet, AI image / video, contract (signatures), log, device and invite.
     * When another parent remains, only this parent's account goes and the
     * family stays with them. Every token of a deleted user is revoked, so
     * the app signs out afterwards.
     *
     * 200 `{status: deleted, scope: family|parent, family_deleted, parents_deleted,
     * children_deleted, pets_deleted}`; 422 `invalid_password` (or validation:
     * `password` required, `confirm` must be true); 403 `superadmin_protected`;
     * 429 when throttled (5 per 15 min).
     *
     * POST /api/parent/account/delete
     */
    public function destroy(ConfirmDeletionRequest $request): JsonResponse
    {
        /** @var User $parent */
        $parent = $request->user();
        if (! $parent->can('deleteAccount', User::class)) {
            abort(403);
        }

        try {
            $this->deletion->assertPassword($parent, $request->password());
            $result = $this->deletion->deleteParentAccount($parent);
        } catch (AccountDeletionException $e) {
            return $this->refusal($e);
        }

        return response()->json(['status' => 'deleted'] + $result, 200);
    }

    /**
     * Everything PetPrep stores about the family as one JSON document:
     * parents, children (nickname, birth year), quiet hours, pets with their
     * full history, contracts with the child's signature, scores and signed
     * links to the AI images / videos (valid ~1 h). Never: password hashes,
     * tokens, PIN hashes, invite codes. Sent as a download
     * (`Content-Disposition: attachment`).
     *
     * 413 `export_too_large`; 429 when throttled (3 per hour).
     *
     * GET /api/parent/account/export
     */
    public function export(Request $request): JsonResponse
    {
        /** @var User $parent */
        $parent = $request->user();
        if (! $parent->can('exportFamilyData', User::class)) {
            abort(403);
        }

        try {
            $data = $this->export->exportFor($parent);
        } catch (AccountDeletionException $e) {
            return $this->refusal($e);
        }

        $filename = 'petprep-izvoz-'.now()->setTimezone($parent->familyTimezone())->toDateString().'.json';

        return response()
            ->json($data, 200, [
                'Content-Disposition' => 'attachment; filename="'.$filename.'"',
                'Cache-Control' => 'no-store, private',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function refusal(AccountDeletionException $e): JsonResponse
    {
        return response()->json(['message' => $e->getMessage(), 'reason' => $e->reason], $e->status);
    }
}
