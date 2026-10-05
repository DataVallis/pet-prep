<?php

namespace App\Enums;

/**
 * Platform of a registered push device (M3-02). Mirrored in the
 * `device_push_tokens_platform_check` constraint.
 */
enum DevicePlatform: string
{
    case Ios = 'ios';
    case Android = 'android';
}
