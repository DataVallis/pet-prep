<?php

namespace App\Services;

use App\Enums\BreedFactAudience;
use App\Enums\BreedFactCategory;
use App\Enums\BreedType;
use App\Enums\FamilyRole;
use App\Models\BreedFact;
use App\Models\BreedFactRead;
use App\Models\BreedFactShow;
use App\Models\FamilyMember;
use App\Models\Pet;
use App\Models\PetCaretaker;
use App\Models\PetMedia;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;

/**
 * »Ali si vedel?« / breed facts (M5-R09b, DID_YOU_KNOW_SPEC §0, §2, §3.2,
 * §4, §6, §7). Who sees what:
 *
 *  - only APPROVED facts, never for a breed without a pedigree (mutt,
 *    domestic cat — David §0.5; the importer refuses them too);
 *  - a child: `audience = child` facts (never family / health — those are
 *    parent-only by a CHECK), only the breed of a pet the child actively
 *    cares for (active caretaker row on an active pet), no source links (§6);
 *  - a parent: both audiences, only breeds of the family's pets (§0.3), with
 *    the source link, health facts flagged `not_vet_reviewed` (§0.4), and
 *    which children of the family read each fact (»Kar bere otrok«, §4.2).
 *
 * The child's automatic sheet (§3.2, David §0.1 "2-krat na teden"):
 * `fact-of-the-day` = the next unread approved child fact of the child's
 * active pet (sort, then id) + `may_auto_show`, the server half of the rule
 * (see autoShowRefusal()); the calm-moment conditions that depend on live UI
 * state (care just finished, metrics ≥ 50 %, no mess, no overlay, not in the
 * first 10 s) stay in the app (R09c).
 */
class BreedFactService
{
    /** Automatic sheets per program week (David 2026-10-09 §0.1). */
    public const AUTO_SHOWS_PER_WEEK = 2;

    /** Minimum distance between two automatic sheets, in family-local calendar days (§3.2). */
    public const MIN_DAYS_APART = 2;

    public function __construct(
        private readonly FamilyService $families,
        private readonly LifeStageService $lifeStages,
    ) {}

    // ─────────────────────────── visibility ───────────────────────────

    /**
     * The audiences a user may read.
     *
     * @return list<BreedFactAudience>
     */
    public function audiencesFor(User $user): array
    {
        return $user->isChild() ? [BreedFactAudience::Child] : [BreedFactAudience::Child, BreedFactAudience::Parent];
    }

    /**
     * The breeds whose facts a user may read: a child — breeds of the active
     * pets they care for; a parent — breeds of every pet of their family.
     * Breeds without a pedigree are included (they simply have no facts).
     *
     * @return list<BreedType>
     */
    public function breedsFor(User $user): array
    {
        if ($user->isChild()) {
            $pets = Pet::query()
                ->whereIn('id', PetCaretaker::where('user_id', $user->id)->whereNull('ended_at')->select('pet_id'))
                ->where('is_active', true);
        } elseif ($user->isParent()) {
            $family = $this->families->familyOf($user); // read: lookup only
            if ($family === null) {
                return [];
            }
            $pets = Pet::query()->where('family_id', $family->id);
        } else {
            return [];
        }

        $values = $pets->distinct()->pluck('breed_type')
            ->map(fn ($b) => $b instanceof BreedType ? $b : BreedType::tryFrom((string) $b))
            ->filter()
            ->unique(fn (BreedType $b) => $b->value)
            ->values()
            ->all();
        usort($values, fn (BreedType $a, BreedType $b) => strcmp($a->value, $b->value));

        return $values;
    }

    public function mayReadBreed(User $user, BreedType $breed): bool
    {
        return in_array($breed, $this->breedsFor($user), true);
    }

    /** May $user see this fact (approved, audience, breed)? */
    public function mayRead(User $user, BreedFact $fact): bool
    {
        return $fact->isApproved()
            && ! in_array($fact->breed, BreedFactImportService::NON_PEDIGREE, true)
            && in_array($fact->audience, $this->audiencesFor($user), true)
            && $this->mayReadBreed($user, $fact->breed);
    }

    /**
     * Approved facts of a breed the user may see, ordered (sort, id).
     *
     * @return Collection<int, BreedFact>
     */
    public function visibleFacts(User $user, BreedType $breed): Collection
    {
        if (in_array($breed, BreedFactImportService::NON_PEDIGREE, true)) {
            return collect();
        }

        return BreedFact::approved()
            ->where('breed', $breed->value)
            ->whereIn('audience', array_map(fn (BreedFactAudience $a) => $a->value, $this->audiencesFor($user)))
            ->ordered()
            ->get();
    }

    // ─────────────────────────── reads ───────────────────────────

    /**
     * Mark a fact read (idempotent: the first read time is kept).
     */
    public function markRead(User $user, BreedFact $fact, ?CarbonInterface $now = null): BreedFactRead
    {
        BreedFactRead::query()->insertOrIgnore([
            'user_id' => $user->id,
            'breed_fact_id' => $fact->id,
            'read_at' => CarbonImmutable::instance($now ?? now())->utc(),
        ]);

        return BreedFactRead::where('user_id', $user->id)->where('breed_fact_id', $fact->id)->firstOrFail();
    }

    /**
     * read_at per fact id for one user.
     *
     * @param  list<int>  $factIds
     * @return array<int, CarbonImmutable>
     */
    private function readsOf(int $userId, array $factIds): array
    {
        if ($factIds === []) {
            return [];
        }

        return BreedFactRead::where('user_id', $userId)->whereIn('breed_fact_id', $factIds)->get()
            ->mapWithKeys(fn (BreedFactRead $r) => [(int) $r->breed_fact_id => CarbonImmutable::instance($r->read_at)->utc()])
            ->all();
    }

    /**
     * Children of the parent's family who read each fact (»Kar bere otrok«).
     *
     * @param  list<int>  $factIds
     * @return array<int, list<int>> fact id → child ids (ascending)
     */
    private function childReadsFor(User $parent, array $factIds): array
    {
        $family = $this->families->familyOf($parent);
        if ($family === null || $factIds === []) {
            return [];
        }
        $childIds = FamilyMember::where('family_id', $family->id)->where('role', FamilyRole::Child->value)->pluck('user_id');

        $map = [];
        BreedFactRead::whereIn('user_id', $childIds)->whereIn('breed_fact_id', $factIds)
            ->orderBy('user_id')->get(['user_id', 'breed_fact_id'])
            ->each(function (BreedFactRead $r) use (&$map): void {
                $map[(int) $r->breed_fact_id][] = (int) $r->user_id;
            });

        return $map;
    }

    // ─────────────────────────── payloads ───────────────────────────

    /**
     * Facts of a breed as API rows for $user, localized to the request
     * language, plus the caller's progress.
     *
     * @return array{breed: string, facts: list<array<string, mixed>>, read_count: int, total_count: int}
     */
    public function listFor(User $user, BreedType $breed): array
    {
        $facts = $this->visibleFacts($user, $breed);
        $ids = $facts->map(fn (BreedFact $f) => $f->id)->all();
        $reads = $this->readsOf($user->id, $ids);
        $childReads = $user->isChild() ? [] : $this->childReadsFor($user, $ids);

        return [
            'breed' => $breed->value,
            'facts' => $facts->map(fn (BreedFact $f) => $this->row($f, $user, $reads[$f->id] ?? null, $childReads[$f->id] ?? []))->values()->all(),
            'read_count' => count($reads),
            'total_count' => count($ids),
        ];
    }

    /**
     * One fact for $user (title / why in the request language).
     *
     * @param  list<int>  $readByChildren
     * @return array{id: int, key: string, breed: string, category: string, audience: string, title: string, why: string|null, source_name: string, source_url: string|null, game_rule_key: string|null, not_vet_reviewed: bool, read: bool, read_at: string|null, read_by_children: list<int>}
     */
    public function row(BreedFact $fact, User $user, ?CarbonImmutable $readAt, array $readByChildren = []): array
    {
        $locale = App::getLocale() === 'sl' ? 'sl' : 'en';
        $parent = ! $user->isChild();

        return [
            'id' => $fact->id,
            'key' => $fact->key,
            'breed' => $fact->breed->value,
            'category' => $fact->category->value,
            'audience' => $fact->audience->value,
            'title' => $fact->titleIn($locale),
            'why' => $fact->whyIn($locale),
            'source_name' => $fact->source_name,
            // Spec §6: no links for the child — only the source name.
            'source_url' => $parent ? $fact->source_url : null,
            'game_rule_key' => $fact->game_rule_key,
            // David §0.4: health facts are not reviewed by a vet; the app shows the note.
            'not_vet_reviewed' => $fact->category === BreedFactCategory::Health,
            'read' => $readAt !== null,
            'read_at' => $readAt?->toIso8601String(),
            'read_by_children' => $parent ? array_values($readByChildren) : [],
        ];
    }

    // ─────────────────────────── child: fact of the day ───────────────────────────

    /**
     * The child's fact of the day for their pet: next unread approved child
     * fact (sort, id), progress, and whether the app may open the sheet on
     * its own now.
     *
     * @return array{fact: array<string, mixed>|null, may_auto_show: bool, reason: string|null, shown_today: bool, shown_this_week: int, program_week: int|null, read_count: int, total_count: int}
     */
    public function factOfTheDay(User $child, ?Pet $pet, ?CarbonInterface $now = null): array
    {
        $now = CarbonImmutable::instance($now ?? now())->utc();
        $facts = $pet !== null && $this->petIsReadable($child, $pet) ? $this->visibleFacts($child, $pet->breed_type) : collect();
        $ids = $facts->map(fn (BreedFact $f) => $f->id)->all();
        $reads = $this->readsOf($child->id, $ids);
        /** @var BreedFact|null $next */
        $next = $facts->first(fn (BreedFact $f) => ! isset($reads[$f->id]));

        $week = $pet !== null ? $this->programWeek($pet, $now) : null;
        $shownThisWeek = $pet !== null && $week !== null
            ? BreedFactShow::where('user_id', $child->id)->where('pet_id', $pet->id)->where('program_week', $week)->count()
            : 0;
        $shownToday = $pet !== null && BreedFactShow::where('user_id', $child->id)->where('pet_id', $pet->id)
            ->where('local_date', $pet->localDate($now))->exists();
        $refusal = $this->autoShowRefusal($child, $pet, $next, $now);

        return [
            'fact' => $next !== null ? $this->row($next, $child, null) : null,
            'may_auto_show' => $refusal === null,
            'reason' => $refusal,
            'shown_today' => $shownToday,
            'shown_this_week' => $shownThisWeek,
            'program_week' => $week,
            'read_count' => count($reads),
            'total_count' => count($ids),
        ];
    }

    /**
     * Why the sheet may NOT open on its own now (null = it may). Checked in
     * this order:
     *  no_pet › non_pedigree › locked (game over, inactive, hard stop, payment,
     *  contract / unborn, ill — Pet::actionLockReasonFor) › no_image (the
     *  pet's reference image is not stored yet) › no_fact (nothing unread) ›
     *  first_day (the first 24 program hours after birth) › quiet_hours ›
     *  shown_today › week_cap (2 per program week; payment-lock time is not
     *  program time) › too_soon (< 2 family-local days since the last one,
     *  also across a week boundary).
     */
    public function autoShowRefusal(User $child, ?Pet $pet, ?BreedFact $next, CarbonImmutable $now): ?string
    {
        if ($pet === null || ! $this->petIsReadable($child, $pet)) {
            return 'no_pet';
        }
        if (in_array($pet->breed_type, BreedFactImportService::NON_PEDIGREE, true)) {
            return 'non_pedigree';
        }
        if ($pet->actionLockReasonFor($child) !== null) {
            return 'locked';
        }
        if (! $this->hasImage($pet)) {
            return 'no_image';
        }
        if ($next === null) {
            return 'no_fact';
        }
        $day = $this->lifeStages->programPeriodAt($pet, $now, 1);
        if ($day === null || $day['index'] === 0) {
            return 'first_day';
        }
        if ($pet->quietHours()->isQuietNow($now)) {
            return 'quiet_hours';
        }
        $today = $pet->localDate($now);
        $shows = BreedFactShow::where('user_id', $child->id)->where('pet_id', $pet->id);
        if ((clone $shows)->where('local_date', $today)->exists()) {
            return 'shown_today';
        }
        $week = $this->programWeek($pet, $now);
        if ($week === null || (clone $shows)->where('program_week', $week)->count() >= self::AUTO_SHOWS_PER_WEEK) {
            return 'week_cap';
        }
        /** @var BreedFactShow|null $last */
        $last = (clone $shows)->orderByDesc('local_date')->first();
        if ($last !== null) {
            $gap = (int) CarbonImmutable::parse($last->localDate(), 'UTC')->diffInDays(CarbonImmutable::parse($today, 'UTC'), false);
            if ($gap < self::MIN_DAYS_APART) {
                return 'too_soon';
            }
        }

        return null;
    }

    /**
     * Record an automatic sheet (POST /api/child/fact-of-the-day/shown).
     * Idempotent per family-local day: a second call on the same day changes
     * nothing (`already_shown`). Otherwise refused unless may_auto_show.
     * The fact recorded is the current next unread fact.
     *
     * @return array{status: 'recorded'|'already_shown'|'refused', reason: string|null}
     */
    public function recordShown(User $child, Pet $pet, ?CarbonInterface $now = null): array
    {
        $now = CarbonImmutable::instance($now ?? now())->utc();

        return DB::transaction(function () use ($child, $pet, $now): array {
            // Serialises the child's devices (only this child's row is locked).
            User::whereKey($child->id)->lockForUpdate()->first();

            if (BreedFactShow::where('user_id', $child->id)->where('pet_id', $pet->id)->where('local_date', $pet->localDate($now))->exists()) {
                return ['status' => 'already_shown', 'reason' => null];
            }

            $facts = $this->visibleFacts($child, $pet->breed_type);
            $reads = $this->readsOf($child->id, $facts->map(fn (BreedFact $f) => $f->id)->all());
            $next = $facts->first(fn (BreedFact $f) => ! isset($reads[$f->id]));
            $refusal = $this->autoShowRefusal($child, $pet, $next, $now);
            if ($refusal !== null) {
                return ['status' => 'refused', 'reason' => $refusal];
            }

            BreedFactShow::query()->insertOrIgnore([
                'user_id' => $child->id,
                'pet_id' => $pet->id,
                'breed_fact_id' => $next?->id,
                'program_week' => (int) $this->programWeek($pet, $now),
                'local_date' => $pet->localDate($now),
                'shown_at' => $now,
            ]);

            return ['status' => 'recorded', 'reason' => null];
        });
    }

    /** 1 = the program week from birth (payment-lock time excluded); null when unborn. */
    public function programWeek(Pet $pet, CarbonInterface $now): ?int
    {
        $period = $this->lifeStages->programPeriodAt($pet, $now, 7);

        return $period !== null ? $period['index'] + 1 : null;
    }

    /** The pet is one the child actively cares for (active caretaker on an active pet). */
    private function petIsReadable(User $child, Pet $pet): bool
    {
        return $child->isChild()
            && $pet->is_active
            && PetCaretaker::where('pet_id', $pet->id)->where('user_id', $child->id)->whereNull('ended_at')->exists();
    }

    /** The pet's reference image is stored (the sheet shows the child's own pet, spec §3.3). */
    private function hasImage(Pet $pet): bool
    {
        return PetMedia::where('pet_id', $pet->id)->images()->whereNotNull('storage_path')->where('storage_path', '!=', '')->exists();
    }
}
