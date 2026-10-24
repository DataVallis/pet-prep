<?php

namespace App\Services;

use App\Enums\CareTask;
use App\Enums\PushType;
use App\Models\Pet;
use App\Models\PushNotification;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
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
 *  - An existing dog switched on later: its first period starts at
 *    `care_tasks_since` (CareTaskService::periodAt), so the push comes then.
 *
 * Run by `pets:care-tasks` every 5 minutes.
 */
class CareReminderPushService
{
    /** No push for a period that started longer ago than this. */
    public const PUSH_WINDOW_HOURS = 24;

    /** Dogs looked at per run. */
    public const MAX_PETS_PER_RUN = 1000;

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
    public function processDue(?CarbonInterface $now = null): array
    {
        $now = CarbonImmutable::instance($now ?? now())->utc();
        $pets = Pet::born()
            ->where('is_active', true)
            ->where('is_game_over', false)
            ->where('care_tasks_enabled', true)
            ->where('species', 'dog')
            ->whereNotNull('arrival_age_months')
            ->where(fn ($q) => $q->whereNull('care_tasks_since')->orWhere('care_tasks_since', '<=', $now))
            ->orderBy('id')
            ->limit(self::MAX_PETS_PER_RUN)
            ->with(['family', 'paymentLockPeriods'])
            ->get();

        $pushed = 0;
        foreach ($pets as $pet) {
            try {
                foreach ($this->careTasks->remindersFor($pet, $now) as $reminder) {
                    $pushed += $this->maybePush($pet, $reminder, $now) ? 1 : 0;
                }
            } catch (Throwable $e) {
                // One dog never stops the run; it is looked at again on the next run.
                Log::error('CareReminderPushService: reminder push failed', ['pet_id' => $pet->id, 'error' => $e->getMessage()]);
                report($e);
            }
        }

        return ['pets' => $pets->count(), 'pushed' => $pushed];
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
