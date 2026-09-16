<?php

namespace App\Enums;

enum ActivityType: string
{
    case FedPet = 'fed_pet';
    case WateredPet = 'watered_pet';
    case WalkedPet = 'walked_pet';
    case CleanedPoop = 'cleaned_poop';
    case IgnoredWarning = 'ignored_warning';
}
