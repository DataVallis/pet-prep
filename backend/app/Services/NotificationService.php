<?php

namespace App\Services;

use App\Enums\PushType;
use App\Jobs\SendPushNotification;
use App\Models\DevicePushToken;
use App\Models\Pet;
use App\Models\PushNotification;
use App\Models\PushTicket;
use App\Models\User;
use App\Services\Push\ExpoPushClient;
use App\Services\Push\PushCopy;
use App\Services\Push\PushDeviceService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Escalation push notifications (M3-02, PRODUCT_SPEC §6/§7).
 *
 * escalation() is called by EscalationService inside its per-pet
 * transaction (pet row locked): it decides — recipients, quiet hours,
 * duplicate guard — writes one push_notifications row and queues
 * SendPushNotification after commit. No HTTP here.
 *
 * deliver() runs in the job: builds one message per enabled device of the
 * recipients, sends in chunks of ≤ 100, stores one ticket per device
 * (idempotent per notification + device), disables DeviceNotRegistered tokens.
 * checkReceipts() reads the receipts ≥ 15 min later.
 *
 * Payload data = {type, pet_id} only. Texts come from PushCopy (no names).
 */
class NotificationService
{
    /** Expo makes receipts available ~15 min after the send and keeps them 24 h. */
    public const RECEIPT_DELAY_MINUTES = 15;

    public const RECEIPT_MAX_AGE_HOURS = 24;

    public function __construct(
        private readonly FamilyService $families,
        private readonly PushDeviceService $devices,
    ) {}

    /**
     * Record + queue the push for one escalation step of a pet whose row
     * the caller holds locked. Returns null when push is switched off.
     */
    public function escalation(Pet $pet, PushType $type, ?string $metric = null): ?PushNotification
    {
        if (! config('push.enabled')) {
            return null;
        }

        $recipients = $this->recipientsFor($pet, $type);
        $suppressed = match (true) {
            $recipients === [] => 'no_recipients',
            $pet->quietHours()?->isQuietNow() ?? false => 'quiet_hours',
            $this->isDuplicate($pet, $type) => 'duplicate',
            default => null,
        };

        $notification = PushNotification::create([
            'idempotency_key' => (string) Str::uuid(),
            'pet_id' => $pet->id,
            'type' => $type,
            'metric' => $metric,
            'recipients' => $recipients,
            'status' => $suppressed === null ? PushNotification::STATUS_QUEUED : PushNotification::STATUS_SUPPRESSED,
            'suppressed_reason' => $suppressed,
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

        SendPushNotification::dispatch($notification->id)
            ->onQueue((string) config('push.queue'))
            ->afterCommit();

        return $notification;
    }

    /**
     * Send a queued notification (job). Safe to run twice: devices that
     * already have a ticket for it are skipped; a sent / suppressed / failed
     * notification is not touched.
     */
    public function deliver(int $notificationId, ExpoPushClient $client): void
    {
        $notification = PushNotification::with('pet.family')->find($notificationId);
        if ($notification === null || $notification->status !== PushNotification::STATUS_QUEUED) {
            return; // pet deleted (cascade) or already handled
        }

        $notification->increment('attempts');
        $pet = $notification->pet;

        // A retry that slipped into quiet hours stays silent (spec §6).
        if ($pet->quietHours()?->isQuietNow() ?? false) {
            $this->markSuppressed($notification, 'quiet_hours');

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
            $chunk = $chunk->values();
            $messages = $chunk->map(fn (DevicePushToken $d): array => $this->message(
                $notification, $d, $audience[$d->user_id] ?? PushNotification::AUDIENCE_CHILD,
            ))->all();

            // Throws on transport / Expo errors → the job retries; tickets
            // stored for earlier chunks keep those devices from a repeat.
            $tickets = $client->send($messages);

            $this->storeTickets($notification, $chunk, $tickets);
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
     * Phase 1 / 2 → caretakers; phase 3 → all parents; illness / game over →
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
            PushType::SoftWarning, PushType::CriticalAlert => $children(),
            PushType::ParentAlarm => $parents(),
            PushType::Illness, PushType::GameOver => $parents()->concat($children()),
        };

        return $list->unique('user_id')->values()->all();
    }

    private function isDuplicate(Pet $pet, PushType $type): bool
    {
        return PushNotification::where('pet_id', $pet->id)
            ->where('type', $type->value)
            ->whereIn('status', [PushNotification::STATUS_QUEUED, PushNotification::STATUS_SENT])
            ->where('created_at', '>', now()->subMinutes((int) config('push.dedupe_minutes', 30)))
            ->exists();
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
