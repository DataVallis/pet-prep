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

    /** M6-W03: AI content of »Želje« items (catalogue pictures, pet-with-item images / clips) — production budget. */
    case ItemMedia = 'item_media';
}
