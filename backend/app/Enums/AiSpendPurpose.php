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
}
