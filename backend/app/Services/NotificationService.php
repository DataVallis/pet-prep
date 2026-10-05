<?php

namespace App\Services;

use App\Enums\PushType;
use App\Jobs\SendPushNotification;
use App\Models\DevicePushToken;
use App\Models\Pet;
use App\Models\PushNotification;
use App\Models\PushTicket;
use App\Models\User;
use App\Services\Push\ExpoMixedProjectsException;
use App\Services\Push\ExpoPushClient;
use App\Services\Push\PushCopy;
use App\Services\Push\PushDeviceService;
use App\Services\Push\PushTiming;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Escalation push notifications (M3-02, PRODUCT_SPEC §6/§7; PR #35 review).
 *
 * escalation() is called by EscalationService inside its per-pet
 * transaction (pet row locked): it decides — recipients, duplicate guard,
 * timing — writes one push_notifications row and, when it may go out now,
 * queues SendPushNotification after commit. No HTTP here.
 *
 * Timing (family-local clock, `timing()`):
 *  - phase 1 / 2 caused by energy become ONE `walk_reminder` per local day
 *    (normal priority, default channel), not before 2 h after the day's last
 *    quiet stretch ended — held (`scheduled`) until then;
 *  - quiet hours: illness / game over are held until the quiet stretch ends,
 *    every other push is dropped.
 * `push:dispatch-scheduled` (every minute) queues held rows when due.
 *
 * deliver() runs in the job: re-checks the pet (hard stop / inactive / game
 * over / ill → dropped, except illness / game over news; walk done → dropped)
 * and the timing, builds one message per enabled device of the recipients,
 * sends in chunks of ≤ 100 (split per Expo project on
 * PUSH_TOO_MANY_EXPERIENCE_IDS), stores one ticket per device (idempotent per
 * notification + device), disables DeviceNotRegistered tokens.
 * checkReceipts() reads the receipts ≥ 15 min later.
 *
 * Payload data = {type, pet_id} only. Texts come from PushCopy (no names).
 */
class NotificationService
{
    /** Expo makes receipts available ~15 min after the send and keeps them 24 h. */
    public const RECEIPT_DELAY_MINUTES = 15;

    public const RECEIPT_MAX_AGE_HOURS = 24;

    /** Held rows queued per `push:dispatch-scheduled` run. */
    public const DISPATCH_BATCH = 500;

    public function __construct(
        private readonly FamilyService $families,
        private readonly PushDeviceService $devices,
    ) {}

    /**
     * Record + queue (or hold) the push for one escalation step of a pet whose
     * row the caller holds locked. Returns null when push is switched off.
     */
    public function escalation(Pet $pet, PushType $type, ?string $metric = null): ?PushNotification
    {
        if (! config('push.enabled')) {
            return null;
        }

        // Energy is the daily walk: never an alarm, one reminder per day.
        if ($metric === 'energy' && in_array($type, [PushType::SoftWarning, PushType::CriticalAlert], true)) {
            $type = PushType::WalkReminder;
        }

        $now = now();
        $recipients = $this->recipientsFor($pet, $type);
        $status = PushNotification::STATUS_QUEUED;
        $sendAfter = null;
        $suppressed = match (true) {
            $recipients === [] => 'no_recipients',
            $this->isDuplicate($pet, $type, $now) => 'duplicate',
            default => null,
        };

        if ($suppressed === null) {
            [$action, $at, $reason] = $this->timing($pet, $type, $now);
            if ($action === 'drop') {
                $suppressed = $reason;
            } elseif ($action === 'defer') {
                $status = PushNotification::STATUS_SCHEDULED;
                $sendAfter = $at;
            }
        }

        $notification = PushNotification::create([
            'idempotency_key' => (string) Str::uuid(),
            'pet_id' => $pet->id,
            'type' => $type,
            'metric' => $metric,
            'recipients' => $recipients,
            'status' => $suppressed === null ? $status : PushNotification::STATUS_SUPPRESSED,
            'suppressed_reason' => $suppressed,
            'send_after' => $sendAfter,
        ]);

        if ($suppressed !== null) {
            Log::info('Push: suppressed', [
                'push_notification_id' => $notification->id,
                'pet_id' => $pet->id,
                'type' => $type->value,
                'reason' => $suppressed,
            ]);

            return $notification;
        }

        if ($status === PushNotification::STATUS_SCHEDULED) {
            Log::info('Push: held', [
                'push_notification_id' => $notification->id,
                'type' => $type->value,
                'send_after' => $sendAfter?->toIso8601String(),
            ]);

            return $notification;
        }

        $this->dispatch($notification->id, afterCommit: true);

        return $notification;
    }

    /**
     * `push:dispatch-scheduled`: queue held rows whose time has come. The
     * job re-checks everything (quiet hours, pet state, walk).
     */
    public function dispatchScheduled(): int
    {
        $ids = PushNotification::where('status', PushNotification::STATUS_SCHEDULED)
            ->where('send_after', '<=', now())
            ->orderBy('send_after')
            ->limit(self::DISPATCH_BATCH)
            ->pluck('id');

        $queued = 0;
        foreach ($ids as $id) {
            // Claim: a parallel run can't queue the same row twice.
            $claimed = PushNotification::whereKey($id)
                ->where('status', PushNotification::STATUS_SCHEDULED)
                ->update(['status' => PushNotification::STATUS_QUEUED]);
            if ($claimed === 1) {
                $this->dispatch((int) $id, afterCommit: false);
                $queued++;
            }
        }

        return $queued;
    }

    /**
     * Send a queued notification (job). Safe to run twice: devices that
     * already have a ticket for it are skipped; a sent / suppressed / failed
     * / scheduled notification is not touched.
     */
    public function deliver(int $notificationId, ExpoPushClient $client): void
    {
        $notification = PushNotification::with('pet.family')->find($notificationId);
        if ($notification === null || $notification->status !== PushNotification::STATUS_QUEUED) {
            return; // pet deleted (cascade) or already handled
        }

        $notification->increment('attempts');
        $pet = $notification->pet;
        $type = $notification->type;

        // The game moved on since the decision (PR #35 review, item 6).
        if (! $type->isLockNews() && $this->petLocked($pet)) {
            $this->markSuppressed($notification, 'pet_locked');

            return;
        }
        if ($type === PushType::WalkReminder && $pet->displayMetric('energy_level') > EscalationService::SOFT_WARNING_THRESHOLD) {
            $this->markSuppressed($notification, 'walk_done');

            return;
        }

        // A retry / held row that meets quiet hours or comes too early.
        [$action, $at, $reason] = $this->timing($pet, $type, $notification->created_at);
        if ($action === 'drop') {
            $this->markSuppressed($notification, (string) $reason);

            return;
        }
        if ($action === 'defer') {
            $notification->forceFill(['status' => PushNotification::STATUS_SCHEDULED, 'send_after' => $at])->save();

            return;
        }

        $audience = collect($notification->recipients)
            ->mapWithKeys(fn (array $r): array => [(int) $r['user_id'] => (string) $r['audience']]);

        $devices = DevicePushToken::enabled()
            ->whereIn('user_id', $audience->keys()->all())
            ->orderBy('id')
            ->get();

        if ($devices->isEmpty()) {
            $this->markSuppressed($notification, 'no_devices');

            return;
        }

        $done = PushTicket::where('push_notification_id', $notification->id)->pluck('device_push_token_id')->all();
        $pending = $devices->reject(fn (DevicePushToken $d): bool => in_array($d->id, $done, true))->values();

        foreach ($pending->chunk((int) config('push.chunk_size', 100)) as $chunk) {
            // Throws on transport / Expo errors → the job retries; tickets
            // stored for earlier chunks / groups keep those devices from a repeat.
            $this->sendChunk($notification, $chunk->values(), $audience, $client);
        }

        $notification->forceFill([
            'status' => PushNotification::STATUS_SENT,
            'sent_at' => now(),
            'last_error' => null,
        ])->save();
    }

    public function markFailed(int $notificationId, string $error): void
    {
        PushNotification::whereKey($notificationId)
            ->where('status', PushNotification::STATUS_QUEUED)
            ->update(['status' => PushNotification::STATUS_FAILED, 'last_error' => mb_substr($error, 0, 2000)]);
    }

    /**
     * Read receipts of tickets older than 15 min (≤ 24 h) and disable
     * devices Expo reports as DeviceNotRegistered.
     *
     * @return array{checked: int, errors: int}
     */
    public function checkReceipts(ExpoPushClient $client, int $limit = 5000): array
    {
        $checked = 0;
        $errors = 0;

        $tickets = PushTicket::query()
            ->where('status', 'ok')
            ->whereNotNull('ticket_id')
            ->whereNull('receipt_checked_at')
            ->where('created_at', '<=', now()->subMinutes(self::RECEIPT_DELAY_MINUTES))
            ->where('created_at', '>', now()->subHours(self::RECEIPT_MAX_AGE_HOURS))
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($tickets->chunk((int) config('push.receipts_chunk_size', 1000)) as $chunk) {
            $receipts = $client->receipts($chunk->pluck('ticket_id')->all());

            foreach ($chunk as $ticket) {
                $receipt = $receipts[$ticket->ticket_id] ?? null;
                if (! is_array($receipt)) {
                    continue; // not ready yet — next run
                }

                $error = ($receipt['status'] ?? null) === 'ok' ? null : $this->errorCode($receipt);
                $ticket->forceFill([
                    'receipt_status' => $error === null ? 'ok' : 'error',
                    'receipt_error' => $error,
                    'receipt_checked_at' => now(),
                ])->save();
                $checked++;

                if ($error !== null) {
                    $errors++;
                    $this->handleDeviceError($ticket->device_push_token_id, $error, 'receipt');
                }
            }
        }

        // Older than Expo keeps them: stop asking.
        PushTicket::whereNull('receipt_checked_at')
            ->where('created_at', '<=', now()->subHours(self::RECEIPT_MAX_AGE_HOURS))
            ->update(['receipt_checked_at' => now()]);

        return ['checked' => $checked, 'errors' => $errors];
    }

    // ──────────────────────────────────────────────────────────────

    /**
     * Who hears about which step (single recipient resolution: FamilyService).
     * Phase 1 / 2 and the walk reminder → caretakers; phase 3 → all parents; illness / game over →
     * parents + caretakers.
     *
     * @return list<array{user_id: int, audience: string}>
     */
    private function recipientsFor(Pet $pet, PushType $type): array
    {
        $children = fn (): Collection => $this->families->caretakerRecipients($pet)
            ->map(fn (User $u): array => ['user_id' => $u->id, 'audience' => PushNotification::AUDIENCE_CHILD]);
        $parents = fn (): Collection => $this->families->parentRecipients($pet)
            ->map(fn (User $u): array => ['user_id' => $u->id, 'audience' => PushNotification::AUDIENCE_PARENT]);

        $list = match ($type) {
            PushType::SoftWarning, PushType::CriticalAlert, PushType::WalkReminder => $children(),
            PushType::ParentAlarm => $parents(),
            PushType::Illness, PushType::GameOver => $parents()->concat($children()),
        };

        return $list->unique('user_id')->values()->all();
    }

    /**
     * Same pet + type within PUSH_DEDUPE_MINUTES (held, queued or sent); the
     * walk reminder at most once per family-local day.
     */
    private function isDuplicate(Pet $pet, PushType $type, CarbonInterface $now): bool
    {
        $since = $type === PushType::WalkReminder
            ? Carbon::instance($now)->setTimezone($pet->familyTimezone())->startOfDay()->utc()
            : Carbon::instance($now)->subMinutes((int) config('push.dedupe_minutes', 30));

        return PushNotification::where('pet_id', $pet->id)
            ->where('type', $type->value)
            ->whereIn('status', [PushNotification::STATUS_SCHEDULED, PushNotification::STATUS_QUEUED, PushNotification::STATUS_SENT])
            ->where('created_at', $type === PushType::WalkReminder ? '>=' : '>', $since)
            ->exists();
    }

    /**
     * When may this push go out (family-local clock)?
     *  - walk reminder: not before PushTiming::walkReminderEarliest(); dropped
     *    when that is no longer the day it was decided on;
     *  - quiet hours: illness / game over held until the stretch ends, the
     *    rest dropped.
     *
     * @return array{0: 'send'|'defer'|'drop', 1: Carbon|null, 2: string|null}
     */
    private function timing(Pet $pet, PushType $type, CarbonInterface $decidedAt): array
    {
        $now = now();
        $quietHours = $pet->quietHours();
        $timezone = $pet->familyTimezone();

        if ($type === PushType::WalkReminder) {
            $earliest = PushTiming::walkReminderEarliest($quietHours, $now, $timezone);
            $day = Carbon::instance($decidedAt)->setTimezone($timezone)->toDateString();
            if ($earliest->copy()->setTimezone($timezone)->toDateString() !== $day) {
                return ['drop', null, 'walk_day_over'];
            }

            // Stored as UTC (timestamp columns drop the offset).
            return $earliest->greaterThan($now) ? ['defer', $earliest->copy()->utc(), null] : ['send', null, null];
        }

        if ($quietHours?->isQuietNow($now) ?? false) {
            return $type->isLockNews()
                ? ['defer', PushTiming::quietStretchEnd($quietHours, $now)->utc(), null]
                : ['drop', null, 'quiet_hours'];
        }

        return ['send', null, null];
    }

    private function petLocked(Pet $pet): bool
    {
        return (bool) $pet->is_hard_stopped || ! $pet->is_active || (bool) $pet->is_game_over || $pet->isIll();
    }

    private function dispatch(int $notificationId, bool $afterCommit): void
    {
        $pending = SendPushNotification::dispatch($notificationId)->onQueue((string) config('push.queue'));
        if ($afterCommit) {
            $pending->afterCommit();
        }
    }

    /**
     * Send one chunk and store its tickets. On PUSH_TOO_MANY_EXPERIENCE_IDS
     * (tokens of more than one Expo project) the chunk is sent again per
     * project, each group's tickets stored before the next request.
     *
     * @param  Collection<int, DevicePushToken>  $chunk
     * @param  Collection<int, string>  $audience  user id → audience
     */
    private function sendChunk(PushNotification $notification, Collection $chunk, Collection $audience, ExpoPushClient $client): void
    {
        $messages = $chunk->map(fn (DevicePushToken $d): array => $this->message(
            $notification, $d, $audience[$d->user_id] ?? PushNotification::AUDIENCE_CHILD,
        ))->all();

        try {
            $this->storeTickets($notification, $chunk, $client->send($messages));

            return;
        } catch (ExpoMixedProjectsException $e) {
            $projectOf = [];
            foreach ($e->groups as $project => $tokens) {
                foreach ($tokens as $token) {
                    $projectOf[$token] = $project;
                }
            }
            $groups = $chunk->groupBy(fn (DevicePushToken $d): string => $projectOf[$d->expo_push_token] ?? '?');
            if ($groups->count() < 2) {
                throw $e; // nothing to split by — give up (not retryable)
            }

            Log::warning('Push: tokens of several Expo projects — sending per project', [
                'push_notification_id' => $notification->id,
                'projects' => $groups->count(),
            ]);

            foreach ($groups as $group) {
                $group = $group->values();
                $groupMessages = $group->map(fn (DevicePushToken $d): array => $this->message(
                    $notification, $d, $audience[$d->user_id] ?? PushNotification::AUDIENCE_CHILD,
                ))->all();
                $this->storeTickets($notification, $group, $client->send($groupMessages));
            }
        }
    }

    /**
     * One Expo message. data = {type, pet_id} only (third parties).
     *
     * @return array<string, mixed>
     */
    private function message(PushNotification $notification, DevicePushToken $device, string $audience): array
    {
        $type = $notification->type;

        return [
            'to' => $device->expo_push_token,
            'title' => PushCopy::TITLE,
            'body' => PushCopy::body($type, $notification->metric, $audience),
            'data' => [
                'type' => $type->value,
                'pet_id' => $notification->pet_id,
            ],
            'sound' => 'default',
            'priority' => $type->isUrgent() ? 'high' : 'default',
            'channelId' => $type->androidChannel(),
            'ttl' => $type->ttlSeconds(),
        ];
    }

    /**
     * @param  Collection<int, DevicePushToken>  $chunk
     * @param  list<array<string, mixed>>  $tickets
     */
    private function storeTickets(PushNotification $notification, Collection $chunk, array $tickets): void
    {
        foreach ($chunk as $i => $device) {
            $ticket = $tickets[$i] ?? [];
            $ok = ($ticket['status'] ?? null) === 'ok';
            $error = $ok ? null : $this->errorCode($ticket);

            PushTicket::insertOrIgnore([[
                'push_notification_id' => $notification->id,
                'device_push_token_id' => $device->id,
                'ticket_id' => $ok && is_string($ticket['id'] ?? null) ? $ticket['id'] : null,
                'status' => $ok ? 'ok' : 'error',
                'error' => $error,
                'created_at' => now(),
                'updated_at' => now(),
            ]]);

            if ($error !== null) {
                $this->handleDeviceError($device->id, $error, 'ticket');
            }
        }
    }

    private function handleDeviceError(int $deviceId, string $error, string $stage): void
    {
        if ($error === 'DeviceNotRegistered') {
            $this->devices->disable($deviceId, $error);

            return;
        }

        Log::warning('Push: Expo reported an error', [
            'device_push_token_id' => $deviceId,
            'stage' => $stage,
            'error' => $error,
        ]);
    }

    /**
     * @param  array<string, mixed>  $ticketOrReceipt
     */
    private function errorCode(array $ticketOrReceipt): string
    {
        $code = $ticketOrReceipt['details']['error'] ?? null;

        return mb_substr(is_string($code) && $code !== '' ? $code : 'unknown', 0, 64);
    }

    private function markSuppressed(PushNotification $notification, string $reason): void
    {
        $notification->forceFill([
            'status' => PushNotification::STATUS_SUPPRESSED,
            'suppressed_reason' => $reason,
        ])->save();

        Log::info('Push: suppressed at send time', [
            'push_notification_id' => $notification->id,
            'reason' => $reason,
        ]);
    }
}
