<?php

namespace App\Services\Funnel;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Validation funnel (M5-08, VALIDATION_PLAN §4.1, David 2026-11-10): where
 * families drop off between sign-up and the certificate, per weekly sign-up
 * cohort. Read only, from tables the game already writes — no analytics SDK
 * and no new tracking (the child is a minor). Counts families, never people;
 * the result holds numbers only (no names, e-mails or ids).
 *
 * Steps (a family counts once per step):
 *  registered   family with a parent who is not a superadmin
 *  child_added  the family has a child profile
 *  pet_born     a pet of the family was born (first contract signed)
 *  d1 / d7      a child of the family acted ≥ 1 / ≥ 7 days after the first birth
 *  paid         an unrevoked challenge credit bought in the PRODUCTION store
 *  paid_test    an unrevoked credit from any other environment (sandbox), no production one
 *  week4        a child acted ≥ 21 days after the family's first unrevoked purchase
 *  certificate  a child of the family finished with the certificate outcome
 *
 * d1 / d7 / week4 also carry how many families are old enough to be judged
 * (`*_eligible`) — a cohort from this week cannot have a day-7 family yet.
 * Deleted families are gone from the data (deletion is immediate), so past
 * cohorts can shrink.
 */
class FunnelService
{
    public const TIMEZONE = 'Europe/Ljubljana';

    public const MAX_WEEKS = 52;

    public const STEPS = [
        'registered', 'child_added', 'pet_born', 'd1', 'd7', 'paid', 'paid_test', 'paid_voucher', 'week4', 'certificate',
    ];

    private const ELIGIBLE = ['d1_eligible', 'd7_eligible', 'week4_eligible'];

    /**
     * @return array{rows: list<array<string, int|string>>, total: array<string, int>, from: string}
     */
    public function cohorts(int $weeks = 12, ?Carbon $now = null): array
    {
        $weeks = max(1, min(self::MAX_WEEKS, $weeks));
        $now = ($now ?? Carbon::now())->copy()->utc();
        // Monday 00:00 (Ljubljana) of the oldest cohort week shown.
        $from = $now->copy()->timezone(self::TIMEZONE)->startOfWeek(Carbon::MONDAY)->subWeeks($weeks - 1)->utc();

        $rows = DB::select(<<<'SQL'
            WITH fam AS (
                SELECT f.id,
                       (date_trunc('week', f.created_at AT TIME ZONE 'UTC' AT TIME ZONE :tz))::date AS cohort,
                       (SELECT MIN(p.born_at) FROM pets p WHERE p.family_id = f.id) AS first_born,
                       (SELECT MIN(c.purchased_at) FROM challenge_credits c
                         WHERE c.family_id = f.id AND c.revoked_at IS NULL) AS first_paid,
                       EXISTS (SELECT 1 FROM challenge_credits c
                         WHERE c.family_id = f.id AND c.revoked_at IS NULL AND c.environment = 'PRODUCTION') AS paid_production,
                       EXISTS (SELECT 1 FROM challenge_credits c
                         WHERE c.family_id = f.id AND c.revoked_at IS NULL AND c.source = 'web_gift'
                           AND c.environment = 'PRODUCTION') AS paid_voucher,
                       EXISTS (SELECT 1 FROM family_user fu
                         WHERE fu.family_id = f.id AND fu.role = 'child') AS has_child,
                       EXISTS (SELECT 1 FROM challenge_certificates cert
                         WHERE cert.family_id = f.id AND cert.outcome = 'certificate') AS has_certificate
                FROM families f
                WHERE f.created_at >= :from
                  AND EXISTS (SELECT 1 FROM family_user fu JOIN users u ON u.id = fu.user_id
                               WHERE fu.family_id = f.id AND fu.role = 'parent' AND u.is_superadmin IS NOT TRUE)
            ),
            facts AS (
                SELECT fam.*,
                       (SELECT MAX(al.created_at) FROM activities_log al
                          JOIN pets p ON p.id = al.pet_id
                          JOIN users u ON u.id = al.actor_user_id
                         WHERE p.family_id = fam.id AND u.role = 'child') AS last_child_action
                FROM fam
            )
            SELECT cohort,
                   COUNT(*) AS registered,
                   COUNT(*) FILTER (WHERE has_child) AS child_added,
                   COUNT(*) FILTER (WHERE first_born IS NOT NULL) AS pet_born,
                   COUNT(*) FILTER (WHERE first_born <= :now_d1) AS d1_eligible,
                   COUNT(*) FILTER (WHERE first_born <= :now_d1b AND last_child_action >= first_born + interval '1 day') AS d1,
                   COUNT(*) FILTER (WHERE first_born <= :now_d7) AS d7_eligible,
                   COUNT(*) FILTER (WHERE first_born <= :now_d7b AND last_child_action >= first_born + interval '7 days') AS d7,
                   COUNT(*) FILTER (WHERE paid_production) AS paid,
                   COUNT(*) FILTER (WHERE first_paid IS NOT NULL AND NOT paid_production) AS paid_test,
                   COUNT(*) FILTER (WHERE paid_voucher) AS paid_voucher,
                   COUNT(*) FILTER (WHERE first_paid <= :now_w4) AS week4_eligible,
                   COUNT(*) FILTER (WHERE first_paid <= :now_w4b AND last_child_action >= first_paid + interval '21 days') AS week4,
                   COUNT(*) FILTER (WHERE has_certificate) AS certificate
            FROM facts
            GROUP BY cohort
            ORDER BY cohort DESC
            SQL, [
            'tz' => self::TIMEZONE,
            'from' => $from->toDateTimeString(),
            'now_d1' => $now->copy()->subDay()->toDateTimeString(),
            'now_d1b' => $now->copy()->subDay()->toDateTimeString(),
            'now_d7' => $now->copy()->subDays(7)->toDateTimeString(),
            'now_d7b' => $now->copy()->subDays(7)->toDateTimeString(),
            'now_w4' => $now->copy()->subDays(21)->toDateTimeString(),
            'now_w4b' => $now->copy()->subDays(21)->toDateTimeString(),
        ]);

        $keys = [...self::STEPS, ...self::ELIGIBLE];
        $total = array_fill_keys($keys, 0);
        $out = [];
        foreach ($rows as $row) {
            $line = ['cohort' => (string) $row->cohort];
            foreach ($keys as $key) {
                $line[$key] = (int) $row->{$key};
                $total[$key] += $line[$key];
            }
            $out[] = $line;
        }

        return ['rows' => $out, 'total' => $total, 'from' => $from->copy()->timezone(self::TIMEZONE)->toDateString()];
    }

    /** Whole-number share for display, null when there is nothing to divide by. */
    public static function percent(int $part, int $whole): ?int
    {
        return $whole > 0 ? (int) round($part / $whole * 100) : null;
    }
}
