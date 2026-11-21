<?php

namespace App\Services\Funnel;

use App\Enums\AcquisitionSource;
use App\Enums\Phase2PriceChoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Validation funnel (M5-08, VALIDATION_PLAN §4.1, David 2026-11-10): where
 * families drop off between sign-up and the certificate, per weekly sign-up
 * cohort. Read only, from tables the game already writes plus the parent-only
 * validation signals (VALIDATION_PLAN §4.1 / §4.4, David 2026-11-21: acquisition
 * source, dashboard opens, Phase-2 interest) — no analytics SDK and nothing
 * about the child (a minor). Counts families, never people;
 * the result holds numbers only (no names, e-mails or ids).
 *
 * Steps (a family counts once per step):
 *  registered   family with a parent who is not a superadmin
 *  child_added  the family has a child profile
 *  pet_born     a pet of the family was born (first contract signed)
 *  d1 / d7      a child of the family acted ≥ 1 / ≥ 7 days after the first birth
 *  h6           H6 (VALIDATION_PLAN §3): one parent of the family opened the dashboard ≥ 3 times
 *               in days 8–14 after the first birth ([birth + 7 d, birth + 14 d), wall clock like
 *               d1 / d7 — payment-lock time is not subtracted); `parent_dashboard_opens`
 *  paid         an unrevoked challenge credit bought in the PRODUCTION store
 *  paid_test    an unrevoked credit from any other environment (sandbox), no production one
 *  week4        a child acted ≥ 21 days after the family's first unrevoked purchase
 *  certificate  a child of the family finished with the certificate outcome
 *
 * d1 / d7 / h6 / week4 also carry how many families are old enough to be judged
 * (`*_eligible`) — a cohort from this week cannot have a day-7 family yet.
 * Deleted families are gone from the data (deletion is immediate), so past
 * cohorts can shrink.
 */
class FunnelService
{
    public const TIMEZONE = 'Europe/Ljubljana';

    public const MAX_WEEKS = 52;

    public const STEPS = [
        'registered', 'child_added', 'pet_born', 'd1', 'd7', 'h6', 'paid', 'paid_test', 'paid_voucher', 'week4', 'certificate',
    ];

    private const ELIGIBLE = ['d1_eligible', 'd7_eligible', 'h6_eligible', 'week4_eligible'];

    /** `sources()` row of families without an answer (»neznano«). */
    public const SOURCE_UNKNOWN = 'unknown';

    /**
     * @return array{rows: list<array<string, int|string>>, total: array<string, int>, from: string}
     */
    public function cohorts(int $weeks = 12, ?Carbon $now = null): array
    {
        [$now, $from] = $this->window($weeks, $now);
        [$with, $bindings] = $this->familyFacts($now, $from);

        $rows = DB::select($with.<<<'SQL'
            SELECT cohort,
            SQL.$this->stepColumns().<<<'SQL'
            FROM facts
            GROUP BY cohort
            ORDER BY cohort DESC
            SQL, $bindings + $this->stepBindings($now));

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

    /**
     * The same steps per acquisition source (VALIDATION_PLAN §4.1, H1–H4 per channel) over the
     * families of the shown sign-up weeks. A family's source = the first answer any of its parents
     * gave (`users.acquisition_source`, earliest `acquisition_recorded_at`); none = SOURCE_UNKNOWN
     * (»neznano« — old apps, skipped, or before the question existed). Rows in
     * AcquisitionSource order, unknown last; sources without a family are left out.
     *
     * @return list<array<string, int|string>>
     */
    public function sources(int $weeks = 12, ?Carbon $now = null): array
    {
        [$now, $from] = $this->window($weeks, $now);
        [$with, $bindings] = $this->familyFacts($now, $from);

        $rows = DB::select($with.<<<'SQL'
            SELECT COALESCE(source, 'unknown') AS source,
            SQL.$this->stepColumns().<<<'SQL'
            FROM facts
            GROUP BY COALESCE(source, 'unknown')
            SQL, $bindings + $this->stepBindings($now));

        $order = array_flip([...AcquisitionSource::values(), self::SOURCE_UNKNOWN]);
        $keys = [...self::STEPS, ...self::ELIGIBLE];
        $out = [];
        foreach ($rows as $row) {
            $line = ['source' => (string) $row->source];
            foreach ($keys as $key) {
                $line[$key] = (int) $row->{$key};
            }
            $out[] = $line;
        }
        usort($out, fn (array $a, array $b) => ($order[$a['source']] ?? PHP_INT_MAX) <=> ($order[$b['source']] ?? PHP_INT_MAX));

        return $out;
    }

    /**
     * Phase-2 fake door (H7, VALIDATION_PLAN §4.4) over the parents of the families of the shown
     * sign-up weeks: `viewed` parents who tapped »Dobili smo pravo žival«, `submitted` who sent
     * the form, `notify` who sent it with a LIVE »Obvestite me« consent (ticked and the linked
     * signup still exists — Phase2Interest::notifies(); H7 = notify / viewed), and the price split
     * of the submitted answers.
     *
     * @return array{viewed: int, submitted: int, notify: int, prices: array<string, int>}
     */
    public function phase2(int $weeks = 12, ?Carbon $now = null): array
    {
        [$now, $from] = $this->window($weeks, $now);
        [$with, $bindings] = $this->familyFacts($now, $from);

        $row = DB::selectOne($with.<<<'SQL'
            SELECT COUNT(*) FILTER (WHERE pi.first_viewed_at IS NOT NULL) AS viewed,
                   COUNT(*) FILTER (WHERE pi.submitted_at IS NOT NULL) AS submitted,
                   COUNT(*) FILTER (WHERE pi.submitted_at IS NOT NULL AND pi.notify
                                      AND EXISTS (SELECT 1 FROM email_signups es WHERE es.id = pi.email_signup_id)) AS notify,
                   COUNT(*) FILTER (WHERE pi.submitted_at IS NOT NULL AND pi.price_choice = 'free') AS price_free,
                   COUNT(*) FILTER (WHERE pi.submitted_at IS NOT NULL AND pi.price_choice = 'monthly_2_99') AS price_monthly_2_99,
                   COUNT(*) FILTER (WHERE pi.submitted_at IS NOT NULL AND pi.price_choice = 'monthly_5_99') AS price_monthly_5_99
            FROM phase2_interests pi
            JOIN fam ON fam.id = pi.family_id
            JOIN users u ON u.id = pi.user_id
            WHERE u.is_superadmin IS NOT TRUE
            SQL, $bindings);

        $prices = [];
        foreach (Phase2PriceChoice::values() as $price) {
            $prices[$price] = (int) ($row->{'price_'.$price} ?? 0);
        }

        return [
            'viewed' => (int) ($row->viewed ?? 0),
            'submitted' => (int) ($row->submitted ?? 0),
            'notify' => (int) ($row->notify ?? 0),
            'prices' => $prices,
        ];
    }

    /**
     * @return array{0: Carbon, 1: Carbon} now (UTC) and Monday 00:00 (Ljubljana) of the oldest week shown, in UTC
     */
    private function window(int $weeks, ?Carbon $now): array
    {
        $weeks = max(1, min(self::MAX_WEEKS, $weeks));
        $now = ($now ?? Carbon::now())->copy()->utc();
        $from = $now->copy()->timezone(self::TIMEZONE)->startOfWeek(Carbon::MONDAY)->subWeeks($weeks - 1)->utc();

        return [$now, $from];
    }

    /**
     * `WITH fam AS (…), facts AS (…) ` — one row per counted family of the window.
     *
     * @return array{0: string, 1: array<string, string>}
     */
    private function familyFacts(Carbon $now, Carbon $from): array
    {
        $sql = <<<'SQL'
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
                         WHERE cert.family_id = f.id AND cert.outcome = 'certificate') AS has_certificate,
                       (SELECT u.acquisition_source FROM family_user fu JOIN users u ON u.id = fu.user_id
                         WHERE fu.family_id = f.id AND fu.role = 'parent' AND u.acquisition_source IS NOT NULL
                         ORDER BY u.acquisition_recorded_at NULLS LAST, u.id LIMIT 1) AS source
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
                         WHERE p.family_id = fam.id AND u.role = 'child') AS last_child_action,
                       EXISTS (SELECT 1 FROM parent_dashboard_opens o
                         WHERE o.family_id = fam.id
                           AND o.opened_at >= fam.first_born + interval '7 days'
                           AND o.opened_at < fam.first_born + interval '14 days'
                         GROUP BY o.user_id
                         HAVING COUNT(*) >= 3) AS h6_hit
                FROM fam
            )
            SQL;

        return [$sql, ['tz' => self::TIMEZONE, 'from' => $from->toDateTimeString()]];
    }

    /** The step / eligible columns of one grouped SELECT (trailing comma-free). */
    private function stepColumns(): string
    {
        return <<<'SQL'
                   COUNT(*) AS registered,
                   COUNT(*) FILTER (WHERE has_child) AS child_added,
                   COUNT(*) FILTER (WHERE first_born IS NOT NULL) AS pet_born,
                   COUNT(*) FILTER (WHERE first_born <= :now_d1) AS d1_eligible,
                   COUNT(*) FILTER (WHERE first_born <= :now_d1b AND last_child_action >= first_born + interval '1 day') AS d1,
                   COUNT(*) FILTER (WHERE first_born <= :now_d7) AS d7_eligible,
                   COUNT(*) FILTER (WHERE first_born <= :now_d7b AND last_child_action >= first_born + interval '7 days') AS d7,
                   COUNT(*) FILTER (WHERE first_born <= :now_h6) AS h6_eligible,
                   COUNT(*) FILTER (WHERE first_born <= :now_h6b AND h6_hit) AS h6,
                   COUNT(*) FILTER (WHERE paid_production) AS paid,
                   COUNT(*) FILTER (WHERE first_paid IS NOT NULL AND NOT paid_production) AS paid_test,
                   COUNT(*) FILTER (WHERE paid_voucher) AS paid_voucher,
                   COUNT(*) FILTER (WHERE first_paid <= :now_w4) AS week4_eligible,
                   COUNT(*) FILTER (WHERE first_paid <= :now_w4b AND last_child_action >= first_paid + interval '21 days') AS week4,
                   COUNT(*) FILTER (WHERE has_certificate) AS certificate

            SQL;
    }

    /**
     * @return array<string, string>
     */
    private function stepBindings(Carbon $now): array
    {
        return [
            'now_d1' => $now->copy()->subDay()->toDateTimeString(),
            'now_d1b' => $now->copy()->subDay()->toDateTimeString(),
            'now_d7' => $now->copy()->subDays(7)->toDateTimeString(),
            'now_d7b' => $now->copy()->subDays(7)->toDateTimeString(),
            'now_h6' => $now->copy()->subDays(14)->toDateTimeString(),
            'now_h6b' => $now->copy()->subDays(14)->toDateTimeString(),
            'now_w4' => $now->copy()->subDays(21)->toDateTimeString(),
            'now_w4b' => $now->copy()->subDays(21)->toDateTimeString(),
        ];
    }

    /** Whole-number share for display, null when there is nothing to divide by. */
    public static function percent(int $part, int $whole): ?int
    {
        return $whole > 0 ? (int) round($part / $whole * 100) : null;
    }
}
