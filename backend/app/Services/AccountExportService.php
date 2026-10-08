<?php

namespace App\Services;

use App\Enums\FamilyRole;
use App\Exceptions\AccountDeletionException;
use App\Models\DevicePushToken;
use App\Models\Family;
use App\Models\FamilyMember;
use App\Models\Pet;
use App\Models\PetMedia;
use App\Models\User;
use App\Services\Media\PetGrowthService;
use App\Services\Media\PetMediaService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Data export of a family (M2-08, GDPR art. 15 access / art. 20 portability).
 *
 * Everything the family put into PetPrep or PetPrep derived from it, as one
 * JSON document: parents (name, e-mail, timezone, terms acceptance), children
 * (nickname, birth year), quiet hours, invites, pets (state, appearance traits,
 * caretakers, contracts INCLUDING the child's signature — the family's own
 * drawing, SVG path data or base64 PNG), the full history (activities, steps,
 * walks, routines, pause / illness periods, hygiene events), scores (Care
 * Score + traffic light per child and pet) and signed, expiring URLs of the
 * stored AI images / videos (no binaries).
 *
 * Never exported: password hashes, remember tokens, API tokens (only a device
 * count), Expo push tokens (only platform / app version / dates), PIN hashes / legacy pairing PINs, invite codes, RevenueCat ids, fal
 * request ids / source URLs / prompts and seeds, cost data.
 *
 * Synchronous; above `privacy.export_max_rows` history rows → 413
 * `export_too_large` (asynchronous export is a follow-up). GET only looks up —
 * it never creates a family.
 */
class AccountExportService
{
    public const FORMAT = 'petprep.family-export';

    public const VERSION = 1;

    public function __construct(
        private readonly FamilyService $families,
        private readonly FamilyDashboardService $dashboard,
        private readonly PetMediaService $media,
        private readonly PetGrowthService $growth,
    ) {}

    /**
     * @return array<string, mixed>
     *
     * @throws AccountDeletionException export_too_large (413)
     */
    public function exportFor(User $parent): array
    {
        $family = $this->families->familyOf($parent);
        $generatedAt = now()->utc();

        $base = [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'generated_at' => $generatedAt->toIso8601String(),
            'requested_by' => ['id' => $parent->id],
            // M1-18: in the request language (lang/<locale>/account.php).
            'about' => __('account.export.about'),
        ];

        if ($family === null) {
            return $base + [
                'family' => null,
                'parents' => [$this->parentRow($parent, $this->deviceCounts([$parent->id]), $this->pushDevices([$parent->id]))],
                'children' => [],
                'quiet_hours' => null,
                'invites' => [],
                'pets' => [],
                'scores' => ['children' => [], 'pets' => []],
            ];
        }

        $members = FamilyMember::where('family_id', $family->id)->get(['user_id', 'role']);
        $parentIds = $members->filter(fn (FamilyMember $m) => $m->role === FamilyRole::Parent)->pluck('user_id')->map(fn ($id) => (int) $id)->all();
        $childIds = $members->filter(fn (FamilyMember $m) => $m->role === FamilyRole::Child)->pluck('user_id')->map(fn ($id) => (int) $id)->all();
        $petIds = Pet::where('family_id', $family->id)->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();

        $this->assertSize($petIds);

        $devices = $this->deviceCounts(array_merge($parentIds, $childIds));
        $push = $this->pushDevices(array_merge($parentIds, $childIds));
        $users = User::whereIn('id', array_merge($parentIds, $childIds))->orderBy('id')->get();

        return $base + [
            'family' => [
                'id' => $family->id,
                'timezone' => $family->timezone,
                'created_at' => $family->created_at?->utc()->toIso8601String(),
            ],
            'parents' => $users->whereIn('id', $parentIds)->map(fn (User $u) => $this->parentRow($u, $devices, $push))->values()->all(),
            'children' => $users->whereIn('id', $childIds)->map(fn (User $u) => [
                'id' => $u->id,
                'nickname' => $u->name,
                'birth_year' => $u->birth_year,
                // PIN-only profile (M2-02) vs. a legacy child account with e-mail.
                'login' => $u->password === null ? 'pin' : 'email',
                'email' => $u->email,
                'devices' => (int) ($devices[$u->id] ?? 0),
                'push_devices' => $push[$u->id] ?? [],
                'created_at' => $this->iso($u->created_at),
            ])->values()->all(),
            'quiet_hours' => $this->quietHours($family),
            'invites' => DB::table('family_invites')->where('family_id', $family->id)->orderBy('id')
                ->get(['created_by', 'expires_at', 'used_at', 'used_by', 'created_at'])
                ->map(fn ($r) => [
                    'created_by' => $r->created_by,
                    'created_at' => $this->iso($r->created_at),
                    'expires_at' => $this->iso($r->expires_at),
                    'used_at' => $this->iso($r->used_at),
                    'used_by' => $r->used_by,
                ])->all(),
            'pets' => $this->pets($petIds),
            'scores' => $this->scores($family, $parent),
        ];
    }

    /**
     * @param  list<int>  $petIds
     *
     * @throws AccountDeletionException
     */
    private function assertSize(array $petIds): void
    {
        if ($petIds === []) {
            return;
        }

        $rows = 0;
        foreach (['activities_log', 'pet_daily_steps', 'pet_daily_walks', 'pet_daily_routines', 'pet_status_periods', 'pet_hygiene_events', 'pet_training_sessions'] as $table) {
            $rows += DB::table($table)->whereIn('pet_id', $petIds)->count();
        }
        // M5-R05: free plays are exported as daily counts — only invitations are rows.
        $rows += DB::table('pet_play_events')->whereIn('pet_id', $petIds)->where('source', 'invitation')->count();

        if ($rows > (int) config('privacy.export_max_rows', 50000)) {
            throw new AccountDeletionException('export_too_large', 'The export is too large to build right now. Please contact support.', 413);
        }
    }

    /**
     * @param  array<int, int>  $devices
     * @param  array<int, list<array<string, mixed>>>  $push
     * @return array<string, mixed>
     */
    private function parentRow(User $u, array $devices, array $push = []): array
    {
        return [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'timezone' => $u->timezone,
            'terms_accepted_at' => $this->iso($u->terms_accepted_at),
            'devices' => (int) ($devices[$u->id] ?? 0),
            'push_devices' => $push[$u->id] ?? [],
            'created_at' => $this->iso($u->created_at),
        ];
    }

    /**
     * Push devices per user (M3-02) — without the Expo token itself.
     *
     * @param  list<int>  $userIds
     * @return array<int, list<array<string, mixed>>>
     */
    private function pushDevices(array $userIds): array
    {
        return DevicePushToken::whereIn('user_id', $userIds)->orderBy('id')->get()
            ->groupBy('user_id')
            ->map(fn ($rows) => $rows->map(fn (DevicePushToken $d) => [
                'platform' => $d->platform->value,
                'app_version' => $d->app_version,
                'locale' => $d->locale,
                'enabled' => $d->disabled_at === null,
                'last_seen_at' => $this->iso($d->last_seen_at),
                'created_at' => $this->iso($d->created_at),
            ])->values()->all())
            ->all();
    }

    /**
     * @param  list<int>  $userIds
     * @return array<int, int>
     */
    private function deviceCounts(array $userIds): array
    {
        return PersonalAccessToken::query()
            ->where('tokenable_type', User::class)
            ->whereIn('tokenable_id', $userIds)
            ->selectRaw('tokenable_id, count(*) as n')
            ->groupBy('tokenable_id')
            ->pluck('n', 'tokenable_id')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function quietHours(Family $family): ?array
    {
        $q = DB::table('quiet_hours')->where('family_id', $family->id)->first();

        return $q === null ? null : [
            'school_start' => $q->school_start,
            'school_end' => $q->school_end,
            'bedtime_start' => $q->bedtime_start,
            'bedtime_end' => $q->bedtime_end,
            'is_active' => (bool) $q->is_active,
            'updated_at' => $this->iso($q->updated_at),
        ];
    }

    /**
     * @param  list<int>  $petIds
     * @return list<array<string, mixed>>
     */
    private function pets(array $petIds): array
    {
        if ($petIds === []) {
            return [];
        }

        $pets = Pet::whereIn('id', $petIds)->orderBy('id')->with('media')->get();
        $byPet = fn (string $table, array $columns, string $order = 'id'): Collection => DB::table($table)
            ->whereIn('pet_id', $petIds)->orderBy($order)->orderBy('id')->get(array_merge(['pet_id'], $columns))->groupBy('pet_id');

        $caretakers = $byPet('pet_caretakers', ['user_id', 'requires_contract', 'created_at', 'ended_at']);
        $contracts = $byPet('pet_contracts', ['user_id', 'signature_format', 'signature', 'signed_at'], 'signed_at');
        $activities = $byPet('activities_log', ['actor_user_id', 'activity_type', 'value', 'created_at'], 'created_at');
        $steps = $byPet('pet_daily_steps', ['user_id', 'local_date', 'steps'], 'local_date');
        $walks = $byPet('pet_daily_walks', ['local_date', 'steps', 'goal', 'achieved', 'illness_started_at'], 'local_date');
        $routines = $byPet('pet_daily_routines', ['local_date', 'routine_type', 'slot', 'status', 'opens_at', 'due_at', 'done_at', 'actor_user_id'], 'local_date');
        $periods = $byPet('pet_status_periods', ['kind', 'started_at', 'ended_at'], 'started_at');
        $hygiene = $byPet('pet_hygiene_events', ['local_date', 'scheduled_at', 'status', 'cleaned_at'], 'scheduled_at');
        // M5-R03 training: progress per command and every session (who, when, how well).
        $skills = $byPet('pet_training_skills', ['command', 'progress', 'last_practised_at', 'sessions_completed']);
        $sessions = $byPet('pet_training_sessions', ['user_id', 'command', 'local_date', 'started_at', 'status', 'finished_at', 'taps', 'result', 'progress_gain'], 'started_at');
        // M5-R05: invitations as rows (≤ 2 a day); free plays — unlimited — as one
        // count per day, child and kind, so play can never make the export too large.
        $plays = DB::table('pet_play_events')->whereIn('pet_id', $petIds)->where('source', 'invitation')
            ->orderBy('local_date')->orderBy('id')
            ->get(['pet_id', 'kind', 'source', 'local_date', 'scheduled_at', 'status', 'completed_by', 'completed_at'])->groupBy('pet_id');
        $freePlays = DB::table('pet_play_events')->whereIn('pet_id', $petIds)->where('source', 'free')
            ->selectRaw('pet_id, local_date, completed_by, kind, count(*) as n')
            ->groupBy('pet_id', 'local_date', 'completed_by', 'kind')
            ->orderBy('local_date')->orderBy('kind')
            ->get()->groupBy('pet_id');
        $expires = $this->media->urlExpiry();

        return $pets->map(function (Pet $pet) use ($caretakers, $contracts, $activities, $steps, $walks, $routines, $periods, $hygiene, $skills, $sessions, $plays, $freePlays, $expires): array {
            $dna = is_array($pet->pet_dna) ? $pet->pet_dna : [];
            $rows = fn (Collection $group) => $group->get($pet->id, collect());
            $album = $this->growth->albumFor($pet, $expires)->toArray()['growth'];

            return [
                'id' => $pet->id,
                'breed_type' => $pet->breed_type->value,
                'species' => $pet->speciesValue()->value,
                'created_at' => $this->iso($pet->created_at),
                'born_at' => $this->iso($pet->born_at),
                'is_active' => (bool) $pet->is_active,
                'is_game_over' => (bool) $pet->is_game_over,
                'is_hard_stopped' => (bool) $pet->is_hard_stopped,
                'illness_until' => $this->iso($pet->illness_until),
                'pet_state' => $pet->pet_state instanceof \BackedEnum ? $pet->pet_state->value : $pet->pet_state,
                'escalation_level' => (int) $pet->escalation_level,
                'certificate_eligible' => (bool) $pet->certificate_eligible,
                'metrics' => $pet->displayMetrics(),
                'daily_step_count' => (int) $pet->daily_step_count,
                // Appearance traits only (never the fal prompt / seed / URL).
                'appearance' => $dna['traits'] ?? $dna['visual_traits'] ?? null,
                // child_id null + ended_at = a deleted child's history row (M2-08).
                'caretakers' => $rows($caretakers)->map(fn ($r) => [
                    'child_id' => $r->user_id === null ? null : (int) $r->user_id,
                    'requires_contract' => (bool) $r->requires_contract,
                    'since' => $this->iso($r->created_at),
                    'ended_at' => $this->iso($r->ended_at),
                ])->values()->all(),
                'contracts' => $rows($contracts)->map(fn ($r) => [
                    'child_id' => (int) $r->user_id,
                    'signed_at' => $this->iso($r->signed_at),
                    'signature_format' => $r->signature_format,
                    // The child's own drawing: SVG path data or base64 PNG.
                    'signature' => $r->signature,
                ])->values()->all(),
                'activities' => $rows($activities)->map(fn ($r) => [
                    'type' => $r->activity_type,
                    'value' => $r->value === null ? null : (int) $r->value,
                    // null = system row (escalation) or a deleted child.
                    'child_id' => $r->actor_user_id === null ? null : (int) $r->actor_user_id,
                    'at' => $this->iso($r->created_at),
                ])->values()->all(),
                'daily_steps' => $rows($steps)->map(fn ($r) => [
                    'child_id' => (int) $r->user_id,
                    'local_date' => $this->date($r->local_date),
                    'steps' => (int) $r->steps,
                ])->values()->all(),
                'daily_walks' => $rows($walks)->map(fn ($r) => [
                    'local_date' => $this->date($r->local_date),
                    'steps' => (int) $r->steps,
                    'goal' => (int) $r->goal,
                    'achieved' => (bool) $r->achieved,
                    'illness_started_at' => $this->iso($r->illness_started_at),
                ])->values()->all(),
                'daily_routines' => $rows($routines)->map(fn ($r) => [
                    'local_date' => $this->date($r->local_date),
                    'type' => $r->routine_type,
                    'slot' => (int) $r->slot,
                    'status' => $r->status,
                    'opens_at' => $this->iso($r->opens_at),
                    'due_at' => $this->iso($r->due_at),
                    'done_at' => $this->iso($r->done_at),
                    'child_id' => $r->actor_user_id === null ? null : (int) $r->actor_user_id,
                ])->values()->all(),
                'status_periods' => $rows($periods)->map(fn ($r) => [
                    'kind' => $r->kind,
                    'started_at' => $this->iso($r->started_at),
                    'ended_at' => $this->iso($r->ended_at),
                ])->values()->all(),
                'hygiene_events' => $rows($hygiene)->map(fn ($r) => [
                    'local_date' => $this->date($r->local_date),
                    'scheduled_at' => $this->iso($r->scheduled_at),
                    'status' => $r->status,
                    'cleaned_at' => $this->iso($r->cleaned_at),
                ])->values()->all(),
                'training_skills' => $rows($skills)->map(fn ($r) => [
                    'command' => $r->command,
                    'progress' => Pet::displayValue((float) $r->progress),
                    'last_practised_at' => $this->iso($r->last_practised_at),
                    'sessions_completed' => (int) $r->sessions_completed,
                ])->values()->all(),
                'training_sessions' => $rows($sessions)->map(fn ($r) => [
                    // null = a deleted child.
                    'child_id' => $r->user_id === null ? null : (int) $r->user_id,
                    'command' => $r->command,
                    'local_date' => $this->date($r->local_date),
                    'started_at' => $this->iso($r->started_at),
                    'status' => $r->status,
                    'finished_at' => $this->iso($r->finished_at),
                    'taps' => is_string($r->taps) ? json_decode($r->taps, true) : $r->taps,
                    'result' => is_string($r->result) ? json_decode($r->result, true) : $r->result,
                    'progress_gain' => $r->progress_gain === null ? null : round((float) $r->progress_gain, 2),
                ])->values()->all(),
                // M5-R05: the dog's play invitations (who took them, when) …
                'play_invitations' => $rows($plays)->map(fn ($r) => [
                    'kind' => $r->kind,
                    'local_date' => $this->date($r->local_date),
                    'scheduled_at' => $this->iso($r->scheduled_at),
                    'status' => $r->status,
                    // null = not completed, or a deleted child.
                    'child_id' => $r->completed_by === null ? null : (int) $r->completed_by,
                    'completed_at' => $this->iso($r->completed_at),
                ])->values()->all(),
                // … and the child's own ball games / cuddles, counted per day.
                'free_plays_per_day' => $rows($freePlays)->map(fn ($r) => [
                    'local_date' => $this->date($r->local_date),
                    'kind' => $r->kind,
                    'child_id' => $r->completed_by === null ? null : (int) $r->completed_by,
                    'count' => (int) $r->n,
                ])->values()->all(),
                // Stored AI media as signed, expiring URLs (our copies, never fal's).
                'media' => $pet->media
                    ->filter(fn (PetMedia $slot) => $slot->isServable())
                    ->sortBy(fn (PetMedia $slot) => [$slot->kind, (string) $slot->state])
                    ->map(fn (PetMedia $slot) => [
                        'kind' => $slot->kind,
                        'state' => $slot->state,
                        'mime' => $slot->mime,
                        'bytes' => $slot->bytes,
                        'url' => $this->media->signedUrl($slot, $expires),
                        'expires_at' => $expires->toIso8601String(),
                    ])->values()->all(),
                // M5-R04 growth album: every stored reference image across life
                // stages (archived ones included), oldest first, signed URLs.
                'growth' => $album,
                'growth_expires_at' => $album === [] ? null : $expires->toIso8601String(),
            ];
        })->values()->all();
    }

    /**
     * Care Score + traffic light per child and pet — the same numbers the
     * parent dashboard shows (FamilyDashboardService / CareScoreService).
     *
     * @return array{children: list<array<string, mixed>>, pets: list<array<string, mixed>>}
     */
    private function scores(Family $family, User $viewer): array
    {
        $board = $this->dashboard->family($family, $viewer);

        return [
            'children' => collect($board['children'])->map(fn (array $c) => [
                'child_id' => $c['id'],
                'care_score' => $c['care_score'] ?? null,
                'traffic_light' => $c['traffic_light'] ?? null,
                'progress' => $c['progress'] ?? null,
                'last_7_days' => $c['stats'] ?? null,
            ])->values()->all(),
            'pets' => collect($board['pets'])->map(fn (array $p) => [
                'pet_id' => $p['id'],
                'care_score' => $p['care_score'] ?? null,
                'traffic_light' => $p['traffic_light'] ?? null,
            ])->values()->all(),
        ];
    }

    private function iso(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return ($value instanceof \DateTimeInterface
            ? CarbonImmutable::instance($value)
            : CarbonImmutable::parse((string) $value, 'UTC'))->utc()->toIso8601String();
    }

    private function date(mixed $value): ?string
    {
        return $value === null ? null : substr((string) $value, 0, 10);
    }
}
