<?php

namespace App\Services;

/**
 * Bodies of the breed-fact endpoints (M5-R09b), typed so Scramble documents
 * them (mobile/src/api/schema.ts). Rows come from BreedFactService::row().
 *
 * A fact (`title` / `why` in the request language — Accept-Language en | sl):
 * - id / key: stable identifiers (key = the content key in git)
 * - category: origin | body | temperament | exercise | diet | grooming |
 *   training | family | health (family / health: parents only)
 * - audience: child | parent
 * - why: »zakaj je to pomembno zate« (link to a game rule), may be null
 * - source_name: always shown (also to the child); source_url: parents only
 *   (null for a child — spec §6, no links out for the child)
 * - game_rule_key: the game rule the fact explains (e.g. daily_steps), or null
 * - not_vet_reviewed: true for health facts — show »Informativno, ni
 *   veterinarsko preverjeno …« (David 2026-10-09)
 * - read / read_at: the caller's own read
 * - read_by_children: parents only — ids of the family's children who read it
 *   (»Kar bere otrok«); always [] for a child
 */
final class BreedFactPayload
{
    /**
     * @param  array{breed: string, facts: list<array<string, mixed>>, read_count: int, total_count: int}  $list
     * @return array{breed: string, facts: list<array{id: int, key: string, breed: string, category: 'origin'|'body'|'temperament'|'exercise'|'diet'|'grooming'|'training'|'family'|'health', audience: 'child'|'parent', title: string, why: string|null, source_name: string, source_url: string|null, game_rule_key: string|null, not_vet_reviewed: bool, read: bool, read_at: string|null, read_by_children: list<int>}>, read_count: int, total_count: int}
     */
    public static function list(array $list): array
    {
        return [
            // BreedType value of the breed (e.g. border_collie).
            'breed' => $list['breed'],
            /**
             * Approved facts the caller may see, in display order. Empty for a breed
             * without approved facts (and always for mutt / domestic cat) — hide every
             * entry point then.
             *
             * @var list<array{id: int, key: string, breed: string, category: 'origin'|'body'|'temperament'|'exercise'|'diet'|'grooming'|'training'|'family'|'health', audience: 'child'|'parent', title: string, why: string|null, source_name: string, source_url: string|null, game_rule_key: string|null, not_vet_reviewed: bool, read: bool, read_at: string|null, read_by_children: list<int>}>
             */
            'facts' => $list['facts'],
            // Facts of this list the caller has read (»8 od 40«).
            'read_count' => $list['read_count'],
            'total_count' => $list['total_count'],
        ];
    }

    /**
     * @param  array{fact: array<string, mixed>|null, may_auto_show: bool, reason: string|null, shown_today: bool, shown_this_week: int, program_week: int|null, read_count: int, total_count: int}  $day
     * @return array{fact: array{id: int, key: string, breed: string, category: 'origin'|'body'|'temperament'|'exercise'|'diet'|'grooming'|'training', audience: 'child', title: string, why: string|null, source_name: string, source_url: null, game_rule_key: string|null, not_vet_reviewed: bool, read: bool, read_at: string|null, read_by_children: list<int>}|null, may_auto_show: bool, reason: 'no_pet'|'non_pedigree'|'locked'|'no_image'|'no_fact'|'first_day'|'quiet_hours'|'shown_today'|'week_cap'|'too_soon'|null, shown_today: bool, shown_this_week: int, program_week: int|null, read_count: int, total_count: int}
     */
    public static function factOfTheDay(array $day): array
    {
        return [
            /**
             * The next unread approved child fact of the child's pet (display order), or
             * null when every fact is read / there are none (mutt, domestic cat).
             *
             * @var array{id: int, key: string, breed: string, category: 'origin'|'body'|'temperament'|'exercise'|'diet'|'grooming'|'training', audience: 'child', title: string, why: string|null, source_name: string, source_url: null, game_rule_key: string|null, not_vet_reviewed: bool, read: bool, read_at: string|null, read_by_children: list<int>}|null
             */
            'fact' => $day['fact'],
            /**
             * The server allows the sheet to open on its own now (max 2 per program week,
             * ≥ 2 family-local days apart, not on day 1, not in quiet hours, not while
             * locked, the pet has its picture, an unread fact exists). The app still
             * checks the calm moment (care just done, metrics ≥ 50 %, no mess / overlay)
             * and shows the chip instead when the moment is not calm.
             */
            'may_auto_show' => $day['may_auto_show'],
            // Why not (null when it may) — see the return type for the values.
            'reason' => $day['reason'],
            // An automatic sheet was already recorded today (family-local day).
            'shown_today' => $day['shown_today'],
            // Automatic sheets recorded in the current program week (0–2).
            'shown_this_week' => $day['shown_this_week'],
            // 1 = the program week from birth (payment-lock time excluded); null when unborn.
            'program_week' => $day['program_week'],
            // The child's progress on their breed (»Prebral si 8 od 40«).
            'read_count' => $day['read_count'],
            'total_count' => $day['total_count'],
        ];
    }
}
