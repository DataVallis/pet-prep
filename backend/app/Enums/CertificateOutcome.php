<?php

namespace App\Enums;

/**
 * How a child's 12-week challenge ended (M2-01d, CERTIFICATE_SPEC §1, David
 * 2026-10-12). Mirrored by `challenge_certificates_outcome_check`.
 */
enum CertificateOutcome: string
{
    /** Final Care Score ≥ ChallengeCertificateService::CERTIFICATE_MIN_SCORE and no game over: the PDF may be generated. */
    case Certificate = 'certificate';

    /** Below the threshold or game over before the end: the parent sees a report, no certificate. */
    case Report = 'report';
}
