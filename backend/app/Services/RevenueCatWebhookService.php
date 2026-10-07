<?php

namespace App\Services;

use App\Enums\PurchaseEventOutcome;
use App\Models\Family;
use App\Models\PurchaseEvent;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * RevenueCat webhook → purchase ledger → challenge credits (M3-08 ledger,
 * M3-11 per-pet consumable, PAYMENTS_SPEC P1).
 *
 * Every event is stored once in `purchase_events` (unique RevenueCat
 * `event.id`); a repeated delivery returns null and changes nothing. The
 * insert, the credit / pet writes and the outcome are one transaction — a
 * crash leaves nothing behind and RevenueCat retries.
 *
 * Who: RevenueCat `app_user_id` = our parent user id as a string (mobile
 * M3-07 logs in with `appUserID = user.id`). `original_app_user_id` and
 * `aliases` are tried too, in that order; anonymous ids and e-mails never
 * match. The first id that is a parent decides the family. No parent →
 * stored with outcome `unknown_user` (HTTP 200 — RevenueCat retries non-2xx
 * forever).
 *
 * What: only the challenge consumable (`services.revenuecat.challenge_products`,
 * default `petprep_challenge_12w`):
 *  - INITIAL_PURCHASE / NON_RENEWING_PURCHASE → one challenge credit for the
 *    family (ChallengeCreditService::create, auto-assigned when the family
 *    has exactly one unpaid challenge pet);
 *  - CANCELLATION of a one-time purchase (= store refund) / REFUND → the
 *    purchase's credit is revoked; its pet goes back to trial or
 *    payment_required (unless its 12 weeks are over);
 *  - TRANSFER → the senders' unassigned credits move to the receiver;
 *    assigned credits stay with their pets (recorded);
 *  - BILLING_ISSUE, PRODUCT_CHANGE, SUBSCRIBER_ALIAS, TEST → recorded; any
 *    other type (RENEWAL, EXPIRATION, …) → ignored.
 *
 * SANDBOX events are stored but change nothing unless
 * `services.revenuecat.accept_sandbox`.
 */
class RevenueCatWebhookService
{
    /** Events that create a credit. */
    private const GRANTING = ['INITIAL_PURCHASE', 'NON_RENEWING_PURCHASE'];

    /** Events that revoke a credit (refund of the consumable). */
    private const REFUNDING = ['CANCELLATION', 'REFUND'];

    /** Stored for the record only. */
    private const RECORD_ONLY = ['TEST', 'PRODUCT_CHANGE', 'BILLING_ISSUE', 'SUBSCRIBER_ALIAS'];

    public function __construct(
        private readonly ChallengeCreditService $credits,
        private readonly FamilyService $families,
    ) {}

    /**
     * @param  array<string, mixed>  $body  the whole webhook body ({api_version, event})
     * @return PurchaseEventOutcome|null null = this event id was already stored (duplicate)
     */
    public function handle(array $body): ?PurchaseEventOutcome
    {
        /** @var array<string, mixed> $event */
        $event = (array) ($body['event'] ?? []);
        $eventId = (string) $event['id'];

        if (PurchaseEvent::where('event_id', $eventId)->exists()) {
            return null;
        }

        try {
            return DB::transaction(function () use ($body, $event, $eventId): PurchaseEventOutcome {
                $record = PurchaseEvent::create($this->columns($body, $event));

                [$outcome, $familyId] = $this->process($record, $event, $eventId);

                $record->forceFill([
                    'family_id' => $familyId,
                    'outcome' => $outcome,
                    'processed_at' => now(),
                ])->save();

                Log::info('RevenueCatWebhook: event processed', [
                    'event_id' => $eventId,
                    'type' => $record->type,
                    'family_id' => $familyId,
                    'outcome' => $outcome->value,
                ]);

                return $outcome;
            });
        } catch (UniqueConstraintViolationException $e) {
            // The same event delivered twice at once: the other request won.
            if (PurchaseEvent::where('event_id', $eventId)->exists()) {
                return null;
            }

            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array{0: PurchaseEventOutcome, 1: int|null} outcome, mapped family id
     */
    private function process(PurchaseEvent $record, array $event, string $eventId): array
    {
        $type = strtoupper((string) ($event['type'] ?? ''));

        if ($type === 'TRANSFER') {
            return $this->transfer($event, $eventId);
        }

        $family = $this->familyFor($this->candidateIds($event));

        if (in_array($type, self::RECORD_ONLY, true)) {
            return [PurchaseEventOutcome::Recorded, $family?->id];
        }
        if (! in_array($type, [...self::GRANTING, ...self::REFUNDING], true)) {
            return [PurchaseEventOutcome::Ignored, $family?->id];
        }
        if ($family === null) {
            Log::warning('RevenueCatWebhook: no parent for event', ['event_id' => $eventId, 'type' => $type]);

            return [PurchaseEventOutcome::UnknownUser, null];
        }
        if (! $this->environmentAccepted($event)) {
            return [PurchaseEventOutcome::SandboxIgnored, $family->id];
        }

        $productId = self::stringOrNull($event['product_id'] ?? null);
        if ($productId === null || ! in_array($productId, $this->challengeProducts(), true)) {
            Log::warning('RevenueCatWebhook: not the challenge product', ['event_id' => $eventId, 'product_id' => $productId]);

            return [PurchaseEventOutcome::UnknownProduct, $family->id];
        }

        DB::table('families')->where('id', $family->id)->lockForUpdate()->first(['id']);

        if (in_array($type, self::GRANTING, true)) {
            $this->credits->create($family, $record, [
                'product_id' => $productId,
                'store' => self::stringOrNull($event['store'] ?? null),
                'environment' => self::environment($event),
                'transaction_id' => self::stringOrNull($event['transaction_id'] ?? null),
            ], self::msToCarbon($event['purchased_at_ms'] ?? null) ?? now());

            return [PurchaseEventOutcome::Granted, $family->id];
        }

        // A consumable never has an expiration: a CANCELLATION is a refund.
        // A subscription-like CANCELLATION (with expiration) is not ours.
        if ($type === 'CANCELLATION' && ($event['expiration_at_ms'] ?? null) !== null) {
            return [PurchaseEventOutcome::Recorded, $family->id];
        }

        $revoked = $this->credits->revokeForRefund(
            $family,
            $productId,
            self::stringOrNull($event['transaction_id'] ?? null),
            $eventId,
            now()->startOfSecond(),
        );

        return [$revoked !== null ? PurchaseEventOutcome::Revoked : PurchaseEventOutcome::Ignored, $family->id];
    }

    /**
     * TRANSFER (RevenueCat moved the purchases to another app user id, e.g.
     * restore on another account): the unassigned credits of the
     * `transferred_from` families move to the `transferred_to` family; a
     * credit already assigned to a pet stays (the pet keeps its challenge).
     * An unknown receiver moves nothing.
     *
     * @param  array<string, mixed>  $event
     * @return array{0: PurchaseEventOutcome, 1: int|null}
     */
    private function transfer(array $event, string $eventId): array
    {
        $to = $this->familyFor(self::stringList($event['transferred_to'] ?? []));
        $from = [];
        foreach (self::stringList($event['transferred_from'] ?? []) as $id) {
            $family = $this->familyFor([$id]);
            if ($family !== null && $family->id !== $to?->id) {
                $from[$family->id] = $family;
            }
        }

        if ($to === null && $from === []) {
            Log::warning('RevenueCatWebhook: TRANSFER between unknown users', ['event_id' => $eventId]);

            return [PurchaseEventOutcome::UnknownUser, null];
        }
        if (! $this->environmentAccepted($event)) {
            return [PurchaseEventOutcome::SandboxIgnored, $to?->id];
        }
        if ($to === null || $from === []) {
            return [PurchaseEventOutcome::Recorded, $to?->id ?? array_key_first($from)];
        }

        $ids = [...array_keys($from), $to->id];
        sort($ids);
        DB::table('families')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get(['id']);

        $moved = 0;
        foreach ($from as $family) {
            $moved += $this->credits->transferUnassigned($family, $to, now());
        }

        return [$moved > 0 ? PurchaseEventOutcome::Transferred : PurchaseEventOutcome::Recorded, $to->id];
    }

    /**
     * @return list<string>
     */
    private function challengeProducts(): array
    {
        return array_values(array_filter((array) config('services.revenuecat.challenge_products', []), 'is_string'));
    }

    /**
     * Family of the first id that is one of our parents. Only plain integer
     * ids are looked up (RevenueCat anonymous ids, e-mails etc. never match).
     *
     * @param  list<string>  $ids
     */
    private function familyFor(array $ids): ?Family
    {
        foreach ($ids as $id) {
            if (preg_match('/^[1-9][0-9]{0,17}$/', $id) !== 1) {
                continue;
            }
            $user = User::find((int) $id);
            if ($user !== null && $user->isParent()) {
                return $this->families->ensureFamilyFor($user);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $event
     * @return list<string>
     */
    private function candidateIds(array $event): array
    {
        return array_values(array_unique([
            ...self::stringList([$event['app_user_id'] ?? null, $event['original_app_user_id'] ?? null]),
            ...self::stringList($event['aliases'] ?? []),
        ]));
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function environmentAccepted(array $event): bool
    {
        return self::environment($event) !== 'SANDBOX' || (bool) config('services.revenuecat.accept_sandbox');
    }

    /**
     * Ledger columns. The stored payload drops `subscriber_attributes`
     * (RevenueCat may put the customer's e-mail / name there).
     *
     * @param  array<string, mixed>  $body
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    private function columns(array $body, array $event): array
    {
        $payload = $body;
        Arr::forget($payload, 'event.subscriber_attributes');

        return [
            'event_id' => (string) $event['id'],
            'type' => strtoupper((string) $event['type']),
            'app_user_id' => self::stringOrNull($event['app_user_id'] ?? null),
            'original_app_user_id' => self::stringOrNull($event['original_app_user_id'] ?? null),
            'aliases' => isset($event['aliases']) ? self::stringList($event['aliases']) : null,
            'product_id' => self::stringOrNull($event['product_id'] ?? null),
            'entitlement_ids' => isset($event['entitlement_ids']) ? self::stringList($event['entitlement_ids']) : null,
            'store' => self::stringOrNull($event['store'] ?? null),
            'environment' => self::environment($event),
            'transaction_id' => self::stringOrNull($event['transaction_id'] ?? null),
            'original_transaction_id' => self::stringOrNull($event['original_transaction_id'] ?? null),
            'purchased_at' => self::msToCarbon($event['purchased_at_ms'] ?? null),
            'expiration_at' => self::msToCarbon($event['expiration_at_ms'] ?? null),
            'event_at' => self::msToCarbon($event['event_timestamp_ms'] ?? null),
            'payload' => $payload,
        ];
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private static function environment(array $event): ?string
    {
        $env = strtoupper((string) ($event['environment'] ?? ''));

        return in_array($env, ['SANDBOX', 'PRODUCTION'], true) ? $env : null;
    }

    private static function msToCarbon(mixed $ms): ?Carbon
    {
        if (! is_int($ms) && ! (is_string($ms) && ctype_digit($ms))) {
            return null;
        }

        return Carbon::createFromTimestampMs((int) $ms, 'UTC');
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : (is_int($value) ? (string) $value : null);
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $values): array
    {
        $out = [];
        foreach ((array) $values as $value) {
            $s = self::stringOrNull($value);
            if ($s !== null) {
                $out[] = $s;
            }
        }

        return $out;
    }
}
