<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReportAppErrorRequest;
use App\Jobs\SendAppErrorAlert;
use App\Models\User;
use App\Services\AppErrors\AppErrorReportService;
use App\Services\AppSettingsService;
use Illuminate\Http\Response;

/**
 * Mobile app crash / render-error reports (M5-05).
 */
class AppErrorController extends Controller
{
    public function __construct(
        private readonly AppErrorReportService $errors,
        private readonly AppSettingsService $settings,
    ) {}

    /**
     * Report one app error (error boundary, uncaught JS error or unhandled
     * promise rejection). Works with or without a Bearer token: a valid
     * token only tells the server the role (parent | child); a missing,
     * expired or revoked token is ignored — never a 401 (the app must not
     * sign out because of an error report). Only the role is stored, never
     * the user. Throttled per IP (IPv6 /64; signed out 10 / min + 300 / day)
     * and per signed-in user (30 / min); body ≤ 16 KB (413). While the
     * superadmin switch »App error reports« is off (default) the report is
     * accepted (204) and NOTHING is stored; the app learns the switch from
     * `GET /api/user` (`app_error_reports`) and then sends nothing.
     * 204 on success; 422 for an invalid body; 429 when throttled.
     *
     * POST /api/app-errors
     */
    public function store(ReportAppErrorRequest $request): Response
    {
        if (! $this->settings->appErrorReports()) {
            return response()->noContent();
        }

        $user = $request->user('sanctum');
        $role = $user instanceof User ? $user->role : null;

        $result = $this->errors->record($request->report(), $role);

        if ($result->alert !== null) {
            SendAppErrorAlert::dispatch($result->fingerprint, $result->alert);
        }

        return response()->noContent();
    }
}
