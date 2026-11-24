<?php

namespace App\Enums;

/**
 * Where an app error report comes from (M5-05). Error-boundary scopes mirror
 * the app's `RenderErrorScope` (`app`, `child-hud`, `locked-overlay`); the
 * global handlers report `global` (ErrorUtils: uncaught JS error) and
 * `unhandled-rejection` (promise without a catch); `pet-media` = a pet
 * video player failed on the device (2026-11-24). Mirrored in the
 * `app_error_fingerprints_scope_check` constraint.
 */
enum AppErrorScope: string
{
    case App = 'app';
    case ChildHud = 'child-hud';
    case LockedOverlay = 'locked-overlay';
    case Global = 'global';
    case UnhandledRejection = 'unhandled-rejection';
    case PetMedia = 'pet-media';
}
