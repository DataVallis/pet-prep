<?php

namespace App\Enums;

/**
 * Why a challenge ended with a report instead of a certificate (M2-01d).
 * Mirrored by `challenge_certificates_reason_check`.
 */
enum CertificateReason: string
{
    /** 84 program days reached, final Care Score below 70. */
    case LowScore = 'low_score';

    /** The pet went to the virtual shelter before the 84 days were over. */
    case GameOver = 'game_over';
}
