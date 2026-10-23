<?php

namespace App\Enums;

/**
 * What a fal.ai call was for (ai_spend_ledger.purpose, CHECK constraint).
 */
enum AiSpendPurpose: string
{
    case Lab = 'lab';
    case ReferenceImage = 'reference_image';
    case StateVideo = 'state_video';

    /** Automatic clip quality check (vision LLM, David 2026-10-23) — production budget. */
    case QualityCheck = 'quality_check';
}
