<?php

namespace App\Http\Controllers;

use App\Exceptions\CertificateException;
use App\Http\Requests\GenerateCertificateRequest;
use App\Models\ChallengeCertificate;
use App\Models\User;
use App\Services\ChallengeCertificateService;
use App\Services\FamilyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Responsibility Certificate (M2-01d, CERTIFICATE_SPEC). The tick creates one
 * row per finished child challenge; parents list them, generate the PDF with
 * a language and editable names, and open it through a signed URL.
 */
class CertificateController extends Controller
{
    public function __construct(
        private readonly ChallengeCertificateService $certificates,
        private readonly FamilyService $families,
    ) {}

    /**
     * Every finished challenge of the parent's family (newest first):
     * certificates and reports, with the form defaults and — once generated —
     * a signed `pdf_url`. A parent without a family gets an empty list.
     *
     * GET /api/parent/certificates
     */
    public function index(Request $request): JsonResponse
    {
        /** @var User $parent */
        $parent = $request->user();
        abort_unless($parent->can('manageFamily', User::class), 403);
        $family = $this->families->familyOf($parent);

        $rows = $family === null ? collect() : ChallengeCertificate::where('family_id', $family->id)
            ->with(['child:id,name', 'pet:id,name'])
            ->orderByDesc('completed_at')
            ->orderByDesc('id')
            ->get();
        $tz = $family?->timezone ?? 'UTC';

        return response()->json([
            'certificates' => $rows->map(fn (ChallengeCertificate $c) => $this->certificates->payload($c, $tz)->toArray())->values()->all(),
        ]);
    }

    /**
     * Generate (or regenerate) the PDF: language + the names to print. Only
     * for outcome `certificate` (a report → 409 `not_eligible`). Number, score
     * and dates never change; the old file is replaced. 422 codes: see
     * GenerateCertificateRequest. Another family's row / unknown id → 404
     * `certificate_not_found`; a child token → 403.
     *
     * POST /api/parent/certificates/{certificate}/generate
     */
    public function generate(GenerateCertificateRequest $request, string $certificate): JsonResponse
    {
        /** @var User $parent */
        $parent = $request->user();
        $family = $this->families->ensureFamilyFor($parent);

        $row = ctype_digit($certificate)
            ? ChallengeCertificate::where('family_id', $family->id)->with(['child:id,name', 'pet:id,name'])->find((int) $certificate)
            : null;
        if ($row === null || ! $parent->can('generate', $row)) {
            return response()->json(['message' => 'No such certificate in your family.', 'reason' => 'certificate_not_found'], 404);
        }

        try {
            $generated = $this->certificates->generate($row, $request->certificateLocale(), $request->childName(), $request->petName(), $parent);
        } catch (CertificateException $e) {
            return $e->toResponse();
        }

        $generated->setRelation('child', $row->child)->setRelation('pet', $row->pet);

        return response()->json($this->certificates->payload($generated, $family->timezone)->toArray());
    }

    /**
     * The PDF (`application/pdf`, `PetPrep-Certificate-<number>.pdf`). The URL
     * is a short-lived capability (relative signed route → 403 when expired or
     * tampered), issued only inside authorized responses. A request that DOES
     * carry a Sanctum token must come from a parent of the family or the
     * child of the row, otherwise 403. Not generated yet → 404.
     *
     * GET /api/certificates/{certificate}/pdf?expires=…&v=…&signature=…
     */
    public function pdf(ChallengeCertificate $certificate): Response
    {
        $user = Auth::guard('sanctum')->user();
        if ($user instanceof User && ! $user->can('view', $certificate)) {
            abort(403);
        }

        $disk = $this->certificates->disk();
        abort_unless($certificate->isCertificate() && $certificate->isGenerated() && $disk->exists((string) $certificate->pdf_path), 404);

        $filename = (string) trans('certificate.filename', ['number' => $certificate->number], 'en');

        $file = response()->file($disk->path((string) $certificate->pdf_path), [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
        $file->setPrivate();

        return $file;
    }
}
