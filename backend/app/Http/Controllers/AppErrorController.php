<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReportAppErrorRequest;
use App\Jobs\SendAppErrorAlert;
use App\Models\User;
use App\Services\AppErrors\AppErrorReportService;
use Illuminate\Http\Response;

/**
 * Mobile app crash / render-error reports (M5-05).
 */
class AppErrorController extends Controller
{
    public function __construct(private readonly AppErrorReportService $errors) {}

    /**
     * Report one app error (error boundary, uncaught JS error or unhandled
     * promise rejection). Works with or without a Bearer token: a valid
     * token only tells the server the role (parent | child); a missing,
     * expired or revoked token is ignored — never a 401 (the app must not
     * sign out because of an error report). Only the role is stored, never
     * the user. Throttled per IP (IPv6 /64) and per signed-in user.
     * 204 on success; 422 for an invalid body; 429 when throttled.
     *
     * POST /api/app-errors
     */
    public function store(ReportAppErrorRequest $request): Response
    {
        $user = $request->user('sanctum');
        $role = $user instanceof User ? $user->role : null;

        $result = $this->errors->record($request->report(), $role);

        if ($result->alert !== null) {
            SendAppErrorAlert::dispatch($result->fingerprint, $result->alert);
        }

        return response()->noContent();
    }
}
