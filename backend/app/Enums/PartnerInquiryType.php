<?php

namespace App\Enums;

/**
 * Kind of cooperation a partner asks about on petprep.si/partners (M6-S03,
 * WEBSITE_SALES_SPEC §5.2). Mirrored in the CHECK constraint of
 * `partner_inquiries.type`.
 */
enum PartnerInquiryType: string
{
    case Series = 'series';
    case SponsoredContent = 'sponsored_content';
    case Insurer = 'insurer';
    case Directory = 'directory';
    case Shelter = 'shelter';
    case Other = 'other';
}
