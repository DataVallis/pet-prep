<?php

namespace App\Filament\Resources\UserResource;

use App\Enums\QuietHoursChangeScope;
use App\Models\Family;
use App\Models\Pet;
use App\Models\QuietHours;
use App\Models\QuietHoursChange;
use App\Models\User;
use App\Services\FamilyService;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;

/**
 * Read-only »Quiet hours« section of a user in /admin (David 2026-11-24): the
 * family's stored quiet hours, the pets with their own hours and the latest
 * rows of the change log (`quiet_hours_changes`). Rendered as escaped HTML in
 * Placeholders — nothing here can be edited.
 */
class QuietHoursPanel
{
    /** Change log rows shown (newest first). */
    public const CHANGES_SHOWN = 20;

    private const DAY_NAMES = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];

    /**
     * Family per record instance, so one render (visible() + both Placeholders)
     * looks it up once instead of once per callback. Weak keys: an entry goes
     * with its User object (next Livewire request = fresh record = fresh lookup).
     *
     * Values are wrapped in a one-element array: WeakMap::offsetExists() is
     * false for a stored null (a user without a family).
     *
     * @var \WeakMap<User, array{0: Family|null}>|null
     */
    private static ?\WeakMap $families = null;

    public static function familyOf(?User $user): ?Family
    {
        if ($user === null || ! $user->exists) {
            return null;
        }

        self::$families ??= new \WeakMap;
        if (! self::$families->offsetExists($user)) {
            self::$families[$user] = [app(FamilyService::class)->familyOf($user)];
        }

        return self::$families[$user][0];
    }

    public static function current(?User $user): HtmlString
    {
        $family = self::familyOf($user);
        if ($family === null) {
            return new HtmlString('—');
        }

        $row = QuietHours::where('family_id', $family->id)->first();
        $lines = [];
        if ($row === null) {
            $lines[] = 'Family: no stored row — defaults apply ('.e(self::schedule(QuietHours::normaliseValues(QuietHours::DEFAULTS))).')';
        } else {
            $lines[] = 'Family: '.e(self::schedule(QuietHours::normaliseValues($row->only(QuietHours::SCHEDULE_KEYS))));
            $lines[] = 'Row created '.e(self::time($row->created_at)).' · updated '.e(self::time($row->updated_at)).' (UTC)';
        }

        $own = Pet::where('family_id', $family->id)->whereNotNull('own_quiet_hours')->orderBy('id')->get(['id', 'family_id', 'own_quiet_hours']);
        foreach ($own as $pet) {
            $values = $pet->ownQuietHoursValues();
            if ($values !== null) {
                $lines[] = 'Pet #'.$pet->id.' (own hours): '.e(self::schedule($values));
            }
        }

        return new HtmlString(implode('<br>', $lines));
    }

    public static function changes(?User $user): HtmlString
    {
        $family = self::familyOf($user);
        if ($family === null) {
            return new HtmlString('—');
        }

        $rows = QuietHoursChange::with('user:id,name')
            ->where('family_id', $family->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::CHANGES_SHOWN)
            ->get();
        if ($rows->isEmpty()) {
            return new HtmlString('No changes recorded (logged since 2026-11-24).');
        }

        $cell = 'style="padding:2px 8px;vertical-align:top;border-bottom:1px solid rgba(127,127,127,.25)"';
        $html = '<table style="font-size:12px;border-collapse:collapse"><thead><tr>';
        foreach (['Time (UTC)', 'By', 'Scope', 'Before', 'After', 'User-Agent / features'] as $head) {
            $html .= '<th '.$cell.' align="left">'.$head.'</th>';
        }
        $html .= '</tr></thead><tbody>';

        foreach ($rows as $change) {
            $pet = $change->scope === QuietHoursChangeScope::Pet;
            $by = $change->user_id === null ? 'deleted parent' : '#'.$change->user_id.' '.($change->user?->name ?? '');
            $html .= '<tr>'
                .'<td '.$cell.'>'.e(self::time($change->created_at)).'</td>'
                .'<td '.$cell.'>'.e($by).'</td>'
                .'<td '.$cell.'>'.e($pet ? 'pet #'.$change->pet_id : 'family').'</td>'
                .'<td '.$cell.'>'.e(self::side($change->before, $pet)).'</td>'
                .'<td '.$cell.'>'.e(self::side($change->after, $pet)).'</td>'
                .'<td '.$cell.'>'.e(($change->user_agent ?? '—').' / '.($change->client_features ?? '—')).'</td>'
                .'</tr>';
        }

        return new HtmlString($html.'</tbody></table>');
    }

    /**
     * @param  array<string, mixed>|null  $values
     */
    private static function side(?array $values, bool $pet): string
    {
        if ($values === null) {
            return $pet ? 'follows family' : '(no row)';
        }

        return self::schedule(QuietHours::normaliseValues($values));
    }

    /**
     * @param  array{school_start: string|null, school_end: string|null, bedtime_start: string|null, bedtime_end: string|null, is_active: bool, school_days: list<int>}  $v
     */
    public static function schedule(array $v): string
    {
        $range = fn (?string $a, ?string $b): string => $a === null && $b === null ? 'none' : ($a ?? '?').'–'.($b ?? '?');
        $days = $v['school_days'] === [] ? 'no days' : implode(',', array_map(fn (int $d): string => self::DAY_NAMES[$d], $v['school_days']));

        return 'school '.$range($v['school_start'], $v['school_end']).' ('.$days.')'
            .' · bed '.$range($v['bedtime_start'], $v['bedtime_end'])
            .' · '.($v['is_active'] ? 'active' : 'OFF');
    }

    private static function time(mixed $at): string
    {
        return $at instanceof \DateTimeInterface ? Carbon::instance($at)->utc()->format('Y-m-d H:i:s') : '—';
    }
}
