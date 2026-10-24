<?php

namespace App\Services;

use App\Enums\CareTask;
use App\Enums\PushType;
use App\Models\Pet;
use App\Models\PushNotification;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The parent reminder PUSH (M5-R18 / R20 follow-up, David 2026-10-24, answer (d)):
 * when a groomer-visit / bath period of a dog starts, the family's parents get one
 * push — once per (pet, task, period), metric `<task>:<period index>`.
 *
 *  - Only while the reminder is due: no push when the parent already confirmed
 *    it in this period (checked again at delivery — NotificationService).
 *  - A period that started more than PUSH_WINDOW_HOURS ago gets none (a dog
 *    with an old open period after a deploy / an outage is not pinged late);
 *    the card on the parent dashboard still shows it.
 *  - Quiet hours: held until they end (PushType::holdsOverQuietHours), like the
 *    birthday — a period may start at night. Normal priority; dropped while the
 *    pet is locked (hard stop, vet, inactive, game over, payment).
 *  - Copy: title = the pet's name (when set), body without it, formal "vi";
 *    payload {type, pet_id}; parents only, devices of signed-in parents only
 *    (NotificationService / PushDeviceService rules).
 *  - The first reminder comes with the first FULL period (David 2026-10-24):
 *    `period_days` after the birth, or after `care_tasks_since` for an existing
 *    dog switched on later (CareTaskService::periodAt) — never at either.
 *  - Recipients: the parents whose app declares `care_tasks` (QA PR #377 m2);
 *    all parents when none does (NotificationService::recipientsFor).
 *
 * Which dogs a run reads (QA PR #377 M2): only those whose
 * `care_reminder_check_at` is due (null = now) — after a look, it is set to the
 * end of the current reminder period (when the next one starts), at most
 * CHECK_HORIZON_HOURS ahead (a stage / timezone change can move the grid). The
 * due dogs are read in id chunks until the run's time budget is spent; the rest
 * stay due and come first in the next run — nobody is starved by a fixed cap.
 *
 * Run by `pets:care-tasks` every 5 minutes.
 */
class CareReminderPushService
{
    /** No push for a period that started longer ago than this. */
    public const PUSH_WINDOW_HOURS = 24;

    /** A dog is looked at again at the latest after this many hours. */
    public const CHECK_HORIZON_HOURS = 12;

    /** A dog whose look failed is tried again after this many minutes. */
    public const RETRY_MINUTES = 60;

    /** Dogs read per query. */
    public const CHUNK = 200;

    /** Dogs read per query in this instance (tests set a small one). */
    public int $chunk = self::CHUNK;

    /** Seconds one run may spend (the schedule runs every 5 minutes). */
    public const TIME_BUDGET_SECONDS = 120;

    public function __construct(
        private readonly CareTaskService $careTasks,
        private readonly NotificationService $notifications,
    ) {}

    /** Metric of a reminder period: `<task>:<period index>` (≤ 20 characters). */
    public static function metric(CareTask $task, int $periodIndex): string
    {
        return $task->value.':'.$periodIndex;
    }

    /**
     * Push every reminder whose period started within the window.
     *
     * @return array{pets: int, pushed: int}
     */
    public function processDue(?CarbonInterface $now = null, ?float $budgetSeconds = null): array
    {
        $now = CarbonImmutable::instance($now ?? now())->utc();
        // Only breeds with a groomer / bath row (most care breeds have child tasks only).
        $breeds = CareTaskActivationService::breedsWithKeys(array_map(fn (CareTask $t) => $t->stageParamKey(), CareTask::parentTasks()));
        if ($breeds === []) {
            return ['pets' => 0, 'pushed' => 0];
        }
        $deadline = microtime(true) + ($budgetSeconds ?? self::TIME_BUDGET_SECONDS);
        $looked = 0;
        $pushed = 0;

        $this->due($breeds, $now)->with(['family', 'paymentLockPeriods'])->chunkById($this->chunk, function ($pets) use ($now, $deadline, &$looked, &$pushed): bool {
            foreach ($pets as $pet) {
                if (microtime(true) > $deadline) {
                    return false; // the rest stays due — the next run continues
                }
                $looked++;
                try {
                    foreach ($this->careTasks->remindersFor($pet, $now) as $reminder) {
                        $pushed += $this->maybePush($pet, $reminder, $now) ? 1 : 0;
                    }
                    $next = $this->nextCheck($pet, $now);
                } catch (Throwable $e) {
                    // One dog never stops the run; it is looked at again a little later.
                    Log::error('CareReminderPushService: reminder push failed', ['pet_id' => $pet->id, 'error' => $e->getMessage()]);
                    report($e);
                    $next = $now->addMinutes(self::RETRY_MINUTES);
                }
                Pet::whereKey($pet->id)->update(['care_reminder_check_at' => $next]);
            }

            return true;
        });

        return ['pets' => $looked, 'pushed' => $pushed];
    }

    /**
     * Dogs whose reminder look is due.
     *
     * @param  list<string>  $breeds
     * @return Builder<Pet>
     */
    public function due(array $breeds, CarbonInterface $now): Builder
    {
        return Pet::born()
            ->whereIn('breed_type', $breeds)
            ->where('is_active', true)
            ->where('is_game_over', false)
            ->where('care_tasks_enabled', true)
            ->where('species', 'dog')
            ->whereNotNull('arrival_age_months')
            ->where(fn ($q) => $q->whereNull('care_tasks_since')->orWhere('care_tasks_since', '<=', $now))
            ->where(fn ($q) => $q->whereNull('care_reminder_check_at')->orWhere('care_reminder_check_at', '<=', $now));
    }

    /**
     * When the dog's next reminder period may start: the earliest end of its
     * current reminder periods (period 0 included), capped at CHECK_HORIZON_HOURS.
     * A payment lock only moves a period end later — an early look just
     * recomputes.
     */
    public function nextCheck(Pet $pet, CarbonImmutable $now): CarbonImmutable
    {
        $next = $now->addHours(self::CHECK_HORIZON_HOURS);
        foreach (CareTask::parentTasks() as $task) {
            $grid = $this->careTasks->reminderGridAt($pet, $task, $now);
            if ($grid !== null && $grid['end']->greaterThan($now) && $grid['end']->lessThan($next)) {
                $next = $grid['end'];
            }
        }

        return $next;
    }

    /**
     * @param  array{task: CareTask, period: array{index: int, start: CarbonImmutable, end: CarbonImmutable, times: int, period_days: int}, done_at: CarbonImmutable|null}  $reminder
     */
    private function maybePush(Pet $pet, array $reminder, CarbonImmutable $now): bool
    {
        if ($reminder['done_at'] !== null) {
            return false;
        }
        $start = $reminder['period']['start'];
        if ($start->greaterThan($now) || $start->lessThan($now->subHours(self::PUSH_WINDOW_HOURS))) {
            return false;
        }
        $metric = self::metric($reminder['task'], $reminder['period']['index']);
        if ($this->decided($pet->id, $metric)) {
            return false;
        }

        return DB::transaction(function () use ($pet, $metric): bool {
            $locked = Pet::whereKey($pet->id)->lockForUpdate()->first();
            if ($locked === null || $this->decided($locked->id, $metric)) {
                return false;
            }

            // Any row — sent, held or suppressed (no devices, locked) — is the one decision of the period.
            return $this->notifications->careReminder($locked, $metric) !== null;
        });
    }

    private function decided(int $petId, string $metric): bool
    {
        return PushNotification::where('pet_id', $petId)
            ->where('type', PushType::CareReminder->value)
            ->where('metric', $metric)
            ->exists();
    }

    /** Delivery check: the reminder of that metric is still the open one (not confirmed, same period). */
    public function stillDue(Pet $pet, string $metric, ?CarbonInterface $now = null): bool
    {
        [$task, $index] = array_pad(explode(':', $metric, 2), 2, null);
        $task = CareTask::tryFrom((string) $task);
        if ($task === null || $task->isChildTask()) {
            return false;
        }
        $reminder = $this->careTasks->reminderFor($pet, $task, $now ?? now());

        return $reminder !== null && $reminder['done_at'] === null && (string) $reminder['period']['index'] === (string) $index;
    }
}
