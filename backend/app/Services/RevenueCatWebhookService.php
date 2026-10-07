<?php

namespace App\Services;

use App\Enums\EntitlementRevokeReason;
use App\Enums\PurchaseEventOutcome;
use App\Models\Family;
use App\Models\FamilyEntitlement;
use App\Models\PurchaseEvent;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * RevenueCat webhook → purchase ledger → family entitlements (M3-08).
 *
 * Every event is stored once in `purchase_events` (unique RevenueCat
 * `event.id`); a repeated delivery returns null and changes nothing. The
 * insert, the entitlement writes and the outcome are one transaction — a
 * crash leaves nothing behind and RevenueCat retries.
 *
 * Who: RevenueCat `app_user_id` = our parent user id as a string (mobile
 * M3-07 logs in with `appUserID = user.id`). `original_app_user_id` and
 * `aliases` are tried too, in that order; anonymous ids and e-mails never
 * match. The first id that is a parent decides the family (ADR-012:
 * everything a parent buys belongs to the family). No parent → stored with
 * outcome `unknown_user` (HTTP 200 — RevenueCat retries non-2xx forever).
 *
 * What: `entitlement_ids` of the event (filtered to keys we know), else the
 * product map `services.revenuecat.entitlements`.
 *
 * SANDBOX events are stored but change nothing unless
 * `services.revenuecat.accept_sandbox`.
 */
class RevenueCatWebhookService
{
    /** Events that grant or extend the entitlement. */
    private const GRANTING = ['INITIAL_PURCHASE', 'NON_RENEWING_PURCHASE', 'RENEWAL', 'UNCANCELLATION', 'SUBSCRIPTION_EXTENDED'];

    /** Stored for the record only. */
    private const RECORD_ONLY = ['TEST', 'PRODUCT_CHANGE', 'BILLING_ISSUE', 'SUBSCRIBER_ALIAS'];

    /** Refund of a one-time purchase (RevenueCat cancel_reason). */
    private const REFUND_REASON = 'CUSTOMER_SUPPORT';

    public function __construct(
        private readonly EntitlementService $entitlements,
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

                [$outcome, $familyId, $changed] = $this->process($event, $eventId);

                $record->forceFill([
                    'family_id' => $familyId,
                    'outcome' => $outcome,
                    'processed_at' => now(),
                ])->save();

                foreach ($changed as $family) {
                    $this->entitlements->broadcastChanged($family);
                }

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
     * @return array{0: PurchaseEventOutcome, 1: int|null, 2: list<Family>} outcome, mapped family id, families whose active state changed
     */
    private function process(array $event, string $eventId): array
    {
        $type = strtoupper((string) ($event['type'] ?? ''));

        if ($type === 'TRANSFER') {
            return $this->transfer($event, $eventId);
        }

        $family = $this->familyFor($this->candidateIds($event));

        if (in_array($type, self::RECORD_ONLY, true)) {
            return [PurchaseEventOutcome::Recorded, $family?->id, []];
        }
        if (! in_array($type, [...self::GRANTING, 'CANCELLATION', 'EXPIRATION'], true)) {
            return [PurchaseEventOutcome::Ignored, $family?->id, []];
        }
        if ($family === null) {
            Log::warning('RevenueCatWebhook: no parent for event', ['event_id' => $eventId, 'type' => $type]);

            return [PurchaseEventOutcome::UnknownUser, null, []];
        }
        if (! $this->environmentAccepted($event)) {
            return [PurchaseEventOutcome::SandboxIgnored, $family->id, []];
        }

        $keys = $this->entitlementKeys($event);
        if ($keys === []) {
            Log::warning('RevenueCatWebhook: no known entitlement', ['event_id' => $eventId, 'product_id' => $event['product_id'] ?? null]);

            return [PurchaseEventOutcome::NoEntitlement, $family->id, []];
        }

        $this->entitlements->lockFamilies([$family->id]);

        return match (true) {
            in_array($type, self::GRANTING, true) => $this->grant($family, $keys, $event, $eventId),
            $type === 'CANCELLATION' => $this->cancel($family, $keys, $event, $eventId),
            default => $this->expire($family, $keys, $event, $eventId),
        };
    }

    /**
     * @param  list<string>  $keys
     * @param  array<string, mixed>  $event
     * @return array{0: PurchaseEventOutcome, 1: int, 2: list<Family>}
     */
    private function grant(Family $family, array $keys, array $event, string $eventId): array
    {
        $created = false;
        $changed = false;
        foreach ($keys as $key) {
            $result = $this->entitlements->grant(
                $family,
                $key,
                $this->rowAttributes($event),
                $eventId,
                self::msToCarbon($event['expiration_at_ms'] ?? null),
                self::msToCarbon($event['purchased_at_ms'] ?? null) ?? now(),
            );
            $created = $created || $result['created'];
            $changed = $changed || $result['changed'];
        }

        return [$created ? PurchaseEventOutcome::Granted : PurchaseEventOutcome::Extended, $family->id, $changed ? [$family] : []];
    }

    /**
     * A one-time purchase (no expiration) is only ever cancelled by a
     * refund → revoke now (when the row is still that product). A
     * subscription cancellation stops auto-renew: access stays until
     * EXPIRATION.
     *
     * @param  list<string>  $keys
     * @param  array<string, mixed>  $event
     * @return array{0: PurchaseEventOutcome, 1: int, 2: list<Family>}
     */
    private function cancel(Family $family, array $keys, array $event, string $eventId): array
    {
        $oneTime = ($event['expiration_at_ms'] ?? null) === null;
        $reason = strtoupper((string) ($event['cancel_reason'] ?? self::REFUND_REASON));

        if (! $oneTime || $reason !== self::REFUND_REASON) {
            return [PurchaseEventOutcome::Recorded, $family->id, []];
        }

        $productId = $event['product_id'] ?? null;

        return $this->revokeKeys($family, $keys, EntitlementRevokeReason::Refund, $eventId,
            fn (FamilyEntitlement $row): bool => $row->product_id === null || $productId === null || $row->product_id === $productId);
    }

    /**
     * A lifetime row is never ended by a subscription's expiration; a row
     * already renewed past this event's expiration (out-of-order delivery)
     * stays.
     *
     * @param  list<string>  $keys
     * @param  array<string, mixed>  $event
     * @return array{0: PurchaseEventOutcome, 1: int, 2: list<Family>}
     */
    private function expire(Family $family, array $keys, array $event, string $eventId): array
    {
        $expiration = self::msToCarbon($event['expiration_at_ms'] ?? null);

        return $this->revokeKeys($family, $keys, EntitlementRevokeReason::Expired, $eventId,
            fn (FamilyEntitlement $row): bool => $row->expires_at !== null
                && ($expiration === null || $row->expires_at->lte($expiration)));
    }

    /**
     * @param  list<string>  $keys
     * @param  callable(FamilyEntitlement): bool  $applies
     * @return array{0: PurchaseEventOutcome, 1: int, 2: list<Family>}
     */
    private function revokeKeys(Family $family, array $keys, EntitlementRevokeReason $reason, string $eventId, callable $applies): array
    {
        $revoked = false;
        $changed = false;
        foreach ($keys as $key) {
            $result = $this->entitlements->revoke($family, $key, $reason, $eventId, $applies);
            $revoked = $revoked || $result['revoked'] !== null;
            $changed = $changed || $result['changed'];
        }

        return [$revoked ? PurchaseEventOutcome::Revoked : PurchaseEventOutcome::Ignored, $family->id, $changed ? [$family] : []];
    }

    /**
     * TRANSFER (RevenueCat moved the purchases to another app user id, e.g.
     * restore on another account): every unrevoked RevenueCat entitlement
     * of the `transferred_from` families ends (`transferred`) and is granted
     * to the `transferred_to` family with the same product / expiry. An
     * unknown receiver still takes it away from the old family.
     *
     * @param  array<string, mixed>  $event
     * @return array{0: PurchaseEventOutcome, 1: int|null, 2: list<Family>}
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

            return [PurchaseEventOutcome::UnknownUser, null, []];
        }
        if (! $this->environmentAccepted($event)) {
            return [PurchaseEventOutcome::SandboxIgnored, $to?->id, []];
        }

        $this->entitlements->lockFamilies([...array_keys($from), ...($to !== null ? [$to->id] : [])]);

        $changed = [];
        $moved = false;
        foreach ($from as $family) {
            foreach ($this->entitlements->unrevokedKeys($family) as $key) {
                $result = $this->entitlements->revoke($family, $key, EntitlementRevokeReason::Transferred, $eventId);
                $row = $result['revoked'];
                if ($row === null) {
                    continue;
                }
                $moved = true;
                if ($result['changed']) {
                    $changed[$family->id] = $family;
                }
                if ($to !== null) {
                    $granted = $this->entitlements->grant($to, $key, [
                        'product_id' => $row->product_id,
                        'store' => $row->store,
                        'environment' => $row->environment,
                    ], $eventId, $row->expires_at, now());
                    if ($granted['changed']) {
                        $changed[$to->id] = $to;
                    }
                }
            }
        }

        return [$moved ? PurchaseEventOutcome::Transferred : PurchaseEventOutcome::Ignored, $to?->id, array_values($changed)];
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
     * @return list<string>
     */
    private function entitlementKeys(array $event): array
    {
        $known = $this->entitlements->knownKeys();
        $fromEvent = self::stringList($event['entitlement_ids'] ?? (isset($event['entitlement_id']) ? [$event['entitlement_id']] : []));
        $keys = array_values(array_unique(array_intersect($fromEvent, $known)));

        if ($keys === []) {
            $map = (array) config('services.revenuecat.entitlements', []);
            $mapped = $map[(string) ($event['product_id'] ?? '')] ?? null;
            if (is_string($mapped) && in_array($mapped, $known, true)) {
                $keys = [$mapped];
            }
        }

        return $keys;
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function environmentAccepted(array $event): bool
    {
        return self::environment($event) !== 'SANDBOX' || (bool) config('services.revenuecat.accept_sandbox');
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array{product_id: string|null, store: string|null, environment: string|null}
     */
    private function rowAttributes(array $event): array
    {
        return [
            'product_id' => self::stringOrNull($event['product_id'] ?? null),
            'store' => self::stringOrNull($event['store'] ?? null),
            'environment' => self::environment($event),
        ];
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
