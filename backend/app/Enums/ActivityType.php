<?php

namespace App\Enums;

enum ActivityType: string
{
    case FedPet = 'fed_pet';
    case WateredPet = 'watered_pet';
    case WalkedPet = 'walked_pet';
    case CleanedPoop = 'cleaned_poop';
    case IgnoredWarning = 'ignored_warning';
    case SignedContract = 'signed_contract';
    // M5-R01: a meal whose feed window lies entirely in quiet hours (school /
    // sleep) is done by the parent — system row, actor null, never a child
    // routine (David 2026-10-05, PRODUCT_SPEC §5).
    case ParentFedPet = 'parent_fed_pet';
}
