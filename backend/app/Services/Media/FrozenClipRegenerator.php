<?php

namespace App\Services\Media;

use App\Enums\AiSpendPurpose;
use App\Enums\PetPhaseClip;
use App\Jobs\SubmitPetStateVideo;
use App\Models\AppSettingChange;
use App\Models\Pet;
use App\Models\PetLook;
use App\Models\PetMedia;
use App\Models\User;
use App\Services\FalAiService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Fix 2026-10-23 (David »uredi«): Kling 3.0 Pro given the same image as start AND end
 * frame returned almost frozen clips (production pet #8, mean frame difference
 * ~0.08 / 255). New clips are made from the start frame only (FalAiService::endFrameUrl);
 * this remakes the clips that were already stored the frozen way
 * (PetMedia::isFrozenSameFrameClip, read from `stored_recipe` = how the STORED file was
 * made): state loops with `loop` and same-pose phase clips (PetPhaseClip::sharesFrames)
 * made with an end-frame profile (null). A remake that fails keeps the old file and its
 * stored_recipe, so it is listed again (QA #361 M1).
 *
 * `plan()` only reads (never a fal call). `regenerate()` re-queues exactly the planned
 * rows as a NEW generation through the normal pipeline (resetForNewGeneration bump →
 * SubmitPetStateVideo → FalGateway + AiSpendGuard; the old file stays servable until the
 * new one is stored), audited to app_setting_changes `media_regenerate_frozen`. The total
 * is PRE-CHECKED against the budget; each clip is still checked by the guard at its submit
 * (a refused one fails, keeps its frozen file and is listed again). Cache lock LOCK_KEY
 * against double submits.
 *
 * Shared looks: the look rows are remade once (pets on the look follow through the
 * fan-out); a pool pet's own slots are never touched. Both superadmin switches apply
 * (state media for every row, »School & game clips« for phase rows) and only rows the
 * pet / an active pet of the look still uses are planned.
 */
class FrozenClipRegenerator
{
    /** app_setting_changes key of the audit row of one real run. */
    public const AUDIT_KEY = 'media_regenerate_frozen';

    /** QA #361 m2: one real run at a time (seconds). */
    public const LOCK_KEY = 'media-regenerate-frozen';

    public const LOCK_SECONDS = 30;

    public function __construct(
        private readonly PetMediaService $media,
        private readonly MediaEntitlementService $entitlements,
        private readonly MediaProfiles $profiles,
        private readonly AiSpendGuard $guard,
        private readonly FalAiService $fal,
    ) {}

    /**
     * The frozen clips that would be remade, with their estimated cost (profile price ×
     * clip seconds: loops 5 s, phase clips 3 s).
     *
     * @return array{rows: Collection<int, PetMedia>, loops: int, phases: int, cost_usd: float, pets: list<int>, looks: list<int>, state_media_off: bool, phase_clips_off: bool}
     */
    public function plan(?int $petId = null, ?int $lookId = null): array
    {
        $stateOn = $this->entitlements->stateMediaEnabled(fresh: true);
        $phasesOn = $stateOn && $this->entitlements->phaseClipsEnabled(fresh: true);
        $rows = collect();

        if ($stateOn) {
            $rows = $this->candidates($petId, $lookId)
                ->filter(fn (PetMedia $row): bool => $row->isFrozenSameFrameClip($this->madeWithEndFrame($row)))
                ->filter(fn (PetMedia $row): bool => ! $row->isPhase() || $phasesOn)
                ->filter(fn (PetMedia $row): bool => $this->stillWanted($row))
                ->values();
        }

        $loops = $rows->filter(fn (PetMedia $r) => $r->isVideo())->count();
        $phases = $rows->filter(fn (PetMedia $r) => $r->isPhase())->count();
        $cost = $rows->sum(fn (PetMedia $r): float => $this->costOf($r));

        return [
            'rows' => $rows,
            'loops' => $loops,
            'phases' => $phases,
            'cost_usd' => round((float) $cost, 4),
            'pets' => $rows->pluck('pet_id')->filter()->unique()->sort()->values()->all(),
            'looks' => $rows->pluck('pet_look_id')->filter()->unique()->sort()->values()->all(),
            'state_media_off' => ! $stateOn,
            'phase_clips_off' => $stateOn && ! $phasesOn,
        ];
    }

    /**
     * Re-queue the planned rows (recomputed now). Refused — nothing queued — when fal is
     * off / its balance is flagged, or the estimated total does not fit today's / this
     * month's production AI budget.
     *
     * @return array{outcome: 'queued'|'nothing'|'ai_off'|'budget'|'busy', queued: int, loops: int, phases: int, cost_usd: float}
     */
    public function regenerate(?User $actor, ?int $petId = null, ?int $lookId = null): array
    {
        $lock = Cache::lock(self::LOCK_KEY, self::LOCK_SECONDS);

        if (! $lock->get()) {
            return ['outcome' => 'busy', 'queued' => 0, 'loops' => 0, 'phases' => 0, 'cost_usd' => 0.0];
        }

        try {
            return $this->regenerateLocked($actor, $petId, $lookId);
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array{outcome: 'queued'|'nothing'|'ai_off'|'budget', queued: int, loops: int, phases: int, cost_usd: float}
     */
    private function regenerateLocked(?User $actor, ?int $petId, ?int $lookId): array
    {
        $plan = $this->plan($petId, $lookId);
        $none = ['queued' => 0, 'loops' => 0, 'phases' => 0];

        if ($plan['rows']->isEmpty()) {
            return ['outcome' => 'nothing'] + $none + ['cost_usd' => 0.0];
        }

        if (! $this->fal->isEnabled() || FalGateway::balanceExhaustedAt() !== null) {
            return ['outcome' => 'ai_off'] + $none + ['cost_usd' => $plan['cost_usd']];
        }

        if ($this->guard->refusalFor($plan['cost_usd'], AiSpendPurpose::StateVideo) !== null) {
            return ['outcome' => 'budget'] + $none + ['cost_usd' => $plan['cost_usd']];
        }

        $queued = collect();

        foreach ($plan['rows'] as $row) {
            // Conditional reset: a row another worker claimed meanwhile is left alone.
            if ($this->media->resetForNewGeneration($row, bump: true)) {
                SubmitPetStateVideo::dispatch($row->id);
                $queued->push($row);
            }
        }

        if ($queued->isEmpty()) {
            return ['outcome' => 'nothing'] + $none + ['cost_usd' => 0.0];
        }

        // QA #361 m2: report / audit what was really queued, not the plan.
        $loops = $queued->filter(fn (PetMedia $r) => $r->isVideo())->count();
        $phases = $queued->filter(fn (PetMedia $r) => $r->isPhase())->count();
        $cost = round((float) $queued->sum(fn (PetMedia $r): float => $this->costOf($r)), 4);

        AppSettingChange::create([
            'key' => self::AUDIT_KEY,
            'user_id' => $actor?->id,
            'old' => null,
            'new' => [
                'pet_media_ids' => $queued->pluck('id')->all(),
                'pets' => $queued->pluck('pet_id')->filter()->unique()->sort()->values()->all(),
                'looks' => $queued->pluck('pet_look_id')->filter()->unique()->sort()->values()->all(),
                'loops' => $loops,
                'phases' => $phases,
                // Pre-checked against the budget; each clip is still checked by the guard at its submit.
                'estimated_usd' => $cost,
                'filter' => ['pet' => $petId, 'look' => $lookId],
            ],
        ]);
        Log::info('FrozenClipRegenerator: frozen clips re-queued', ['count' => $queued->count(), 'estimated_usd' => $cost, 'pet' => $petId, 'look' => $lookId]);

        return ['outcome' => 'queued', 'queued' => $queued->count(), 'loops' => $loops, 'phases' => $phases, 'cost_usd' => $cost];
    }

    /**
     * QA #361 m3: was this stored clip made by a profile that takes an end frame? Only
     * then could a same-pose phase clip have been sent with one (and frozen). Unknown
     * profile (removed from config) → assume yes, the production default did.
     */
    private function madeWithEndFrame(PetMedia $row): bool
    {
        if ($row->profile === null) {
            return true;
        }

        try {
            return $this->profiles->video($row->profile)->supportsEndImage();
        } catch (InvalidArgumentException) {
            return true;
        }
    }

    /** Estimated cost of remaking one row (loop: state video profile; phase: its 3 s clip). */
    public function costOf(PetMedia $row): float
    {
        $clip = $row->phaseClip();

        return $clip !== null ? $this->media->phaseClipCostUsd($clip) : $this->profiles->stateVideo()->estimatedCostUsd();
    }

    /**
     * Pet slots of pets with their own media + look rows; `--pet` of a pool pet = its look.
     *
     * @return Collection<int, PetMedia>
     */
    private function candidates(?int $petId, ?int $lookId): Collection
    {
        $sameFramePhases = array_map(fn (PetPhaseClip $c) => $c->value, PetPhaseClip::sameFrameCases());
        // stored_recipe = how the STORED file was made; a failed remake keeps it (QA #361 M1).
        $kinds = fn (Builder $q) => $q->where(fn ($q) => $q->where(fn ($q) => $q->where('kind', PetMedia::KIND_VIDEO)->where('stored_recipe', PetMedia::RECIPE_LOOP))
            ->orWhere(fn ($q) => $q->where('kind', PetMedia::KIND_PHASE)->whereNull('stored_recipe')->whereIn('state', $sameFramePhases)))
            ->whereIn('status', [PetMedia::STATUS_READY, PetMedia::STATUS_FAILED])
            // QA #363 M4: a slot in quality »Needs review« is only touched by Regenerate / Approve.
            ->where(fn ($q) => $q->whereNull('error_reason')->orWhere('error_reason', '!=', 'quality_failed'))
            ->whereNotNull('storage_path');

        $pet = $petId !== null ? Pet::find($petId) : null;

        if ($petId !== null && $pet === null) {
            return collect();
        }

        // A pool pet shows its look's files: remake the look rows (once for every pet of the look).
        $withPets = $lookId === null && ($pet === null || ! $pet->usesLookPool());
        $withLooks = $pet === null || $pet->usesLookPool();
        $lookId = $pet?->usesLookPool() ? (int) $pet->pet_look_id : $lookId;

        $petRows = ! $withPets ? collect() : PetMedia::query()->tap($kinds)
            ->whereNotNull('pet_id')->whereNull('look_media_id')
            ->whereHas('pet', fn ($q) => $q->where('is_active', true)->whereNull('pet_look_id'))
            ->when($pet !== null, fn ($q) => $q->where('pet_id', $pet->id))
            ->with('pet')->orderBy('pet_id')->orderBy('id')->get();

        $lookRows = ! $withLooks ? collect() : PetMedia::query()->tap($kinds)
            ->whereNotNull('pet_look_id')
            ->when($lookId !== null, fn ($q) => $q->where('pet_look_id', $lookId))
            ->with('look')->orderBy('pet_look_id')->orderBy('id')->get();

        return $petRows->concat($lookRows)->values();
    }

    /** Only rows the pet (or an active state-media pet of the look at that stage) still uses. */
    private function stillWanted(PetMedia $row): bool
    {
        if ($row->isLookMedia()) {
            $look = $row->look;

            return $look instanceof PetLook && $row->life_stage !== null
                && $this->media->lookStateMediaWanted($look, (string) $row->life_stage, $row->isPhase() ? $row : null);
        }

        $pet = $row->pet;

        if ($pet === null || ! $pet->is_active) {
            return false;
        }

        return $row->isPhase()
            ? $this->media->wantsPoseMedia($pet, $row)
            : $this->entitlements->usesStateMedia($pet) && in_array($row->petState(), $this->entitlements->videoStatesFor($pet), true);
    }
}
