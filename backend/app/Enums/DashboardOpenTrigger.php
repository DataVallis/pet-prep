<?php

namespace App\Enums;

/**
 * Why the parent dashboard gained focus (H6, VALIDATION_PLAN §4.1): after a cold start, after the
 * app came back to the foreground, or after a push tap. Stored in `parent_dashboard_opens.trigger`
 * (null = not sent), mirrored by the parent_dashboard_opens_trigger_check constraint.
 */
enum DashboardOpenTrigger: string
{
    case Launch = 'launch';
    case Foreground = 'foreground';
    case Push = 'push';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $t) => $t->value, self::cases());
    }
}
