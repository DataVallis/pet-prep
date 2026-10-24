<?php

namespace App\Services\AppErrors;

use App\Enums\AdminAlertKind;

/** Outcome of AppErrorReportService::record() (M5-05). */
final readonly class AppErrorRecordResult
{
    public function __construct(
        public string $fingerprint,
        public bool $isNew,
        /** The Discord alert to queue (app_error_new / app_error_spike), or null. */
        public ?AdminAlertKind $alert,
    ) {}
}
