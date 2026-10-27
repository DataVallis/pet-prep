<?php

namespace App\Services;

use App\Enums\PetLockReason;
use App\Enums\PushType;
use App\Enums\Species;
use App\Enums\WishStatus;
use App\Events\PetUpdated;
use App\Exceptions\WishException;
use App\Models\Family;
use App\Models\Pet;
use App\Models\PetItem;
use App\Models\PetTreatEvent;
use App\Models\PetWish;
use App\Models\PushNotification;
use App\Models\ShopItem;
use App\Models\User;
use App\Models\WishCredit;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * »Želje« (M6-W01, SHOP_SPEC, docs/engineering/WISHES_API.md): the child asks
 * for an item for their pet, a parent buys it (WishCreditService::redeem) or
 * declines it. Items are looks only (S1) — they never touch needs, Care Score
 * or the certificate.
 *
 * Rules (server enforced):
 *  - a child caretaker may wish while the pet is in play: not game over /
 *    inactive (`game_over`) and not `payment_required` (S3: free plan and
 *    challenge both); hard stop / illness do not block it;
 *  - max OPEN_LIMIT open wishes per child (all pets together, S9), one open
 *    wish per pet + key, no wish for a durable the pet owns, treats only while
 *    ≤ TREATS_WISH_MAX are left, the same child + pet + key again only
 *    RETRY_DAYS after a decline; the welcome bundle is never wishable (S12);
 *  - treats: max TREATS_PER_DAY per pet and family-local day, not while the
 *    pet sleeps (PetSleepService), 423 like every child action.
 *
 * Lock order: child user row → pet row (create); pet row → treat pack rows
 * (treat); wish row (decline).
 */
class WishService
{
    public const OPEN_LIMIT = 3;

    public const RETRY_DAYS = 7;

    public const TREATS_WISH_MAX = 2;

    public const TREATS_PER_DAY = 2;

    /** Max one wish push per child in this many minutes (the rest only land on the list). */
    public const PUSH_GAP_MINUTES = 10;

    public function __construct(
        private readonly NotificationService $notifications,
        private readonly PetSleepService $sleep,
    ) {}

    // ── catalogue / store products ────────────────────────────────────────

    /**
     * Store product id → item key (config `services.revenuecat.wish_products`).
     *
     * @return array<string, string>
     */
    public static function products(): array
    {
        return array_filter((array) config('services.revenuecat.wish_products', []), fn ($v, $k) => is_string($k) && is_string($v), ARRAY_FILTER_USE_BOTH);
    }

    public static function keyForProduct(?string $productId): ?string
    {
        return $productId === null ? null : (self::products()[$productId] ?? null);
    }

    /** The store product the app should sell for an item key (configured, else the catalogue default). */
    public static function productFor(ShopItem $item): string
    {
        $configured = array_search($item->key, self::products(), true);

        return is_string($configured) ? $configured : $item->product_id;
    }

    /** Why the pet cannot get wishes / items now (null = it can). */
    public function blockedReason(Pet $pet): ?string
    {
        if ($pet->is_game_over || ! $pet->is_active) {
            return 'game_over';
        }

        return $pet->awaitsPayment() ? 'payment_required' : null;
    }

    // ── child ─────────────────────────────────────────────────────────────

    /**
     * GET /api/child/wishes body for $child and their pet.
     *
     * @return array<string, mixed>
     */
    public function childCatalogue(Pet $pet, User $child, ?CarbonInterface $now = null): array
    {
        $now = CarbonImmutable::instance($now ?? now())->utc();
        $tz = $pet->familyTimezone();
        $species = $pet->speciesValue();
        $owned = $this->ownedItems($pet);
        $treatsLeft = $this->treatsLeft($pet);
        $open = PetWish::where('pet_id', $pet->id)->where('status', WishStatus::Open->value)->get()->keyBy('item_key');
        $blocked = $this->blockedReason($pet);
        $iso = fn (?CarbonInterface $at): ?string => $at?->copy()->setTimezone($tz)->toIso8601String();

        $items = [];
        foreach (ShopItem::catalogue() as $item) {
            if ($item->isBundle()) {
                continue;
            }
            $row = ['key' => $item->key, 'kind' => $item->kind, 'variant' => (string) $item->variantFor($species)];
            $wish = $open->get($item->key);
            $retryAt = $this->retryAt($pet, $child, $item->key, $now);
            if ($item->isDurable() && $owned->has($item->key)) {
                $row += ['status' => 'owned', 'media_ready' => false];
            } elseif ($wish !== null) {
                $row += ['status' => 'requested', 'wish_id' => $wish->id, 'requested_at' => $iso($wish->created_at)];
            } elseif ($retryAt !== null) {
                $row += ['status' => 'declined_recently', 'retry_at' => $iso($retryAt)];
            } else {
                $row += ['status' => 'available'];
            }
            if (! $item->isDurable()) {
                $row['treats_left'] = $treatsLeft;
            }
            $items[] = $row;
        }

        return array_merge([
            'pet_id' => $pet->id,
            'species' => $species->value,
            'open_count' => $this->openCountOfChild($child),
            'open_limit' => self::OPEN_LIMIT,
            'can_wish' => $blocked === null,
        ], $blocked !== null ? ['blocked_reason' => $blocked] : [], ['items' => $items]);
    }

    /**
     * POST /api/child/wishes: the child wishes for $itemKey for their pet.
     *
     * @throws WishException
     */
    public function create(Pet $pet, User $child, string $itemKey): PetWish
    {
        $item = ShopItem::find($itemKey);
        if ($item === null) {
            throw new WishException('unknown_item', 'There is no such item.');
        }
        if ($item->isBundle()) {
            throw new WishException('bundle_not_wishable', 'The bundle cannot be wished for.');
        }

        $wish = DB::transaction(function () use ($pet, $child, $item): PetWish {
            User::whereKey($child->id)->lockForUpdate()->first(['id']);
            $locked = Pet::whereKey($pet->id)->lockForUpdate()->firstOrFail();
            $now = now()->startOfSecond();

            if (($blocked = $this->blockedReason($locked)) !== null) {
                throw new WishException($blocked, $blocked === 'game_over' ? 'The pet is no longer in play.' : 'The game is waiting for a parent.', 423);
            }
            if ($item->isDurable() && $this->ownedItems($locked)->has($item->key)) {
                throw new WishException('already_owned', 'The pet already has this item.');
            }
            if (PetWish::where('pet_id', $locked->id)->where('item_key', $item->key)->where('status', WishStatus::Open->value)->exists()) {
                throw new WishException('already_requested', 'This item is already on the wish list.');
            }
            if (! $item->isDurable() && $this->treatsLeft($locked) > self::TREATS_WISH_MAX) {
                throw new WishException('treats_still_left', 'The pet still has treats.');
            }
            if (($retryAt = $this->retryAt($locked, $child, $item->key, $now)) !== null) {
                throw new WishException('declined_recently', 'Ask again later.', 422, [
                    'retry_at' => $retryAt->copy()->setTimezone($locked->familyTimezone())->toIso8601String(),
                ]);
            }
            if ($this->openCountOfChild($child) >= self::OPEN_LIMIT) {
                throw new WishException('wish_limit_reached', 'You already have '.self::OPEN_LIMIT.' open wishes.');
            }

            $wish = PetWish::create([
                'pet_id' => $locked->id,
                'child_user_id' => $child->id,
                'item_key' => $item->key,
                'status' => WishStatus::Open,
            ]);

            if (! $this->pushedRecently($child, $now)) {
                $this->notifications->wishCreated($locked, $wish);
            }
            PetUpdated::afterCommit($locked, 'wish_created');

            return $wish;
        });

        return $wish;
    }

    /**
     * POST /api/child/pet/treat: give one treat.
     *
     * @return array{status: 'given'|'locked'|'refused', reason?: string, next_at?: CarbonInterface|null, lock?: PetLockReason, treats_left: int, given_today: int}
     */
    public function giveTreat(Pet $pet, User $child, ?CarbonInterface $now = null): array
    {
        $now = CarbonImmutable::instance($now ?? now())->utc()->startOfSecond();

        return DB::transaction(function () use ($pet, $child, $now): array {
            $locked = Pet::whereKey($pet->id)->lockForUpdate()->firstOrFail();
            $today = $locked->localDate($now);
            $givenToday = PetTreatEvent::where('pet_id', $locked->id)->where('local_date', $today)->count();
            $left = $this->treatsLeft($locked);
            $base = ['treats_left' => $left, 'given_today' => $givenToday];

            if (($lock = $locked->actionLockReasonFor($child)) !== null) {
                return $base + ['status' => 'locked', 'lock' => $lock];
            }
            if ($left <= 0) {
                return $base + ['status' => 'refused', 'reason' => 'no_treats', 'next_at' => null];
            }
            if ($givenToday >= self::TREATS_PER_DAY) {
                $midnight = $now->setTimezone($locked->familyTimezone())->addDay()->startOfDay()->utc();

                return $base + ['status' => 'refused', 'reason' => 'treat_limit_today', 'next_at' => $midnight];
            }
            if (($wakes = $this->sleep->wakesAt($locked, $now)) !== null) {
                return $base + ['status' => 'refused', 'reason' => 'pet_sleeping', 'next_at' => $wakes];
            }

            $pack = PetItem::where('pet_id', $locked->id)->where('item_key', ShopItem::TREATS)
                ->whereNull('revoked_at')->where('quantity_left', '>', 0)
                ->orderBy('acquired_at')->orderBy('id')->lockForUpdate()->firstOrFail();
            $pack->forceFill(['quantity_left' => $pack->quantity_left - 1])->save();
            PetTreatEvent::create([
                'pet_id' => $locked->id,
                'child_user_id' => $child->id,
                'pet_item_id' => $pack->id,
                'given_at' => $now,
                'local_date' => $today,
            ]);
            PetUpdated::afterCommit($locked, 'treat_given');

            return ['status' => 'given', 'treats_left' => $left - 1, 'given_today' => $givenToday + 1];
        });
    }

    /**
     * The `wishes` block of the child pet state.
     *
     * @return array{open_count: int, items: list<array{key: string, variant: string, media_ready: bool}>, treats: array{left: int, given_today: int, daily_limit: int}}
     */
    public function stateBlock(Pet $pet, ?User $child, ?CarbonInterface $now = null): array
    {
        $now = $now ?? now();
        $owned = $this->ownedItems($pet);

        return [
            'open_count' => $child !== null ? $this->openCountOfChild($child) : $this->openCountOfPet($pet),
            'items' => $owned->filter(fn (PetItem $i) => $i->kind === ShopItem::KIND_DURABLE)
                ->map(fn (PetItem $i): array => ['key' => $i->item_key, 'variant' => $i->variant, 'media_ready' => false])
                ->values()->all(),
            'treats' => [
                'left' => $this->treatsLeft($pet),
                'given_today' => PetTreatEvent::where('pet_id', $pet->id)->where('local_date', $pet->localDate($now))->count(),
                'daily_limit' => self::TREATS_PER_DAY,
            ],
        ];
    }

    // ── parent ────────────────────────────────────────────────────────────

    /**
     * GET /api/parent/wishes body.
     *
     * @return array<string, mixed>
     */
    public function parentList(?Family $family): array
    {
        if ($family === null) {
            return ['open_count' => 0, 'wishes' => [], 'pets' => [], 'unassigned_credits' => []];
        }

        $tz = $family->timezone;
        $catalogue = ShopItem::catalogue();
        $bundle = $catalogue->get(ShopItem::BUNDLE);
        $wishes = PetWish::query()
            ->whereIn('pet_id', Pet::where('family_id', $family->id)->select('id'))
            ->where('status', WishStatus::Open->value)
            ->with(['pet', 'child'])
            ->orderByDesc('created_at')->orderByDesc('id')
            ->get();
        $eligible = [];
        $isEligible = function (Pet $pet) use (&$eligible, $bundle): bool {
            return $eligible[$pet->id] ??= $bundle !== null && $this->bundleEligible($pet, $bundle);
        };

        $pets = Pet::where('family_id', $family->id)->where('is_active', true)->where('is_game_over', false)
            ->orderBy('id')->get()
            ->filter(fn (Pet $p) => $this->blockedReason($p) === null)
            ->values();

        return [
            'open_count' => $wishes->count(),
            'wishes' => $wishes->map(function (PetWish $w) use ($catalogue, $bundle, $isEligible, $tz): array {
                $item = $catalogue->get($w->item_key);
                $species = $w->pet->speciesValue();

                return [
                    'id' => $w->id,
                    'pet_id' => $w->pet_id,
                    'pet_name' => $w->pet->name,
                    'species' => $species->value,
                    'child_id' => $w->child_user_id,
                    'child_name' => $w->child?->name,
                    'item_key' => $w->item_key,
                    'variant' => (string) $item?->variantFor($species),
                    'product_id' => $item !== null ? self::productFor($item) : null,
                    'status' => $w->status->value,
                    'created_at' => $w->created_at->copy()->setTimezone($tz)->toIso8601String(),
                    'bundle_product_id' => $bundle !== null && in_array($w->item_key, $bundle->covers, true) && $isEligible($w->pet)
                        ? self::productFor($bundle) : null,
                ];
            })->values()->all(),
            'pets' => $pets->map(fn (Pet $p): array => [
                'pet_id' => $p->id,
                'pet_name' => $p->name,
                'species' => $p->speciesValue()->value,
                'bundle_eligible' => $isEligible($p),
                'items' => $this->parentItems($p, $catalogue),
            ])->all(),
            'unassigned_credits' => WishCredit::where('family_id', $family->id)->available()
                ->selectRaw('product_id, count(*) as n')->groupBy('product_id')->orderBy('product_id')->get()
                ->map(fn ($r): array => ['product_id' => (string) $r->product_id, 'count' => (int) $r->n])->all(),
        ];
    }

    /**
     * A pet's items as the parent sees them (`pets[].items`, redeem `items`).
     *
     * @param  Collection<string, ShopItem>|null  $catalogue
     * @return list<array<string, mixed>>
     */
    public function parentItems(Pet $pet, ?Collection $catalogue = null): array
    {
        $catalogue ??= ShopItem::catalogue();
        $species = $pet->speciesValue();
        $owned = $this->ownedItems($pet);
        $left = $this->treatsLeft($pet);

        return $catalogue->reject(fn (ShopItem $i) => $i->isBundle())->map(function (ShopItem $i) use ($species, $owned, $left): array {
            $row = ['key' => $i->key, 'variant' => (string) $i->variantFor($species), 'product_id' => self::productFor($i)];

            return $i->isDurable()
                ? $row + ['owned' => $owned->has($i->key), 'media_ready' => false]
                : $row + ['treats_left' => $left];
        })->values()->all();
    }

    /**
     * POST /api/parent/wishes/{wish}/decline.
     *
     * @throws WishException
     */
    public function decline(PetWish $wish, User $parent): PetWish
    {
        return DB::transaction(function () use ($wish, $parent): PetWish {
            $locked = PetWish::whereKey($wish->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== WishStatus::Open) {
                throw new WishException('wish_not_open', 'This wish is no longer open.');
            }
            $locked->forceFill([
                'status' => WishStatus::Declined,
                'decided_by_user_id' => $parent->id,
                'decided_at' => now()->startOfSecond(),
            ])->save();
            if (($pet = Pet::find($locked->pet_id)) !== null) {
                PetUpdated::afterCommit($pet, 'wish_declined');
            }

            return $locked;
        });
    }

    public function openCountOfFamily(?Family $family): int
    {
        if ($family === null) {
            return 0;
        }

        return PetWish::whereIn('pet_id', Pet::where('family_id', $family->id)->select('id'))
            ->where('status', WishStatus::Open->value)->count();
    }

    /** Open wishes end when the pet ends (game over / deactivated) — called by the Pet `updated` hook. */
    public static function cancelOpenOf(Pet $pet): void
    {
        PetWish::where('pet_id', $pet->id)->where('status', WishStatus::Open->value)
            ->update(['status' => WishStatus::Cancelled->value, 'decided_at' => now(), 'updated_at' => now()]);
    }

    // ── helpers ───────────────────────────────────────────────────────────

    /**
     * Unrevoked durable items of the pet, keyed by item key.
     *
     * @return Collection<string, PetItem>
     */
    public function ownedItems(Pet $pet): Collection
    {
        return PetItem::where('pet_id', $pet->id)->whereNull('revoked_at')->where('kind', ShopItem::KIND_DURABLE)
            ->orderBy('acquired_at')->get()->keyBy('item_key');
    }

    public function treatsLeft(Pet $pet): int
    {
        return (int) PetItem::where('pet_id', $pet->id)->where('item_key', ShopItem::TREATS)->whereNull('revoked_at')->sum('quantity_left');
    }

    /** The welcome bundle is offered only while the pet owns none of its items (S12). */
    public function bundleEligible(Pet $pet, ShopItem $bundle): bool
    {
        return $this->ownedItems($pet)->keys()->intersect($bundle->covers)->isEmpty();
    }

    public function openCountOfChild(User $child): int
    {
        return PetWish::where('child_user_id', $child->id)->where('status', WishStatus::Open->value)->count();
    }

    private function openCountOfPet(Pet $pet): int
    {
        return PetWish::where('pet_id', $pet->id)->where('status', WishStatus::Open->value)->count();
    }

    /** When the child may wish $key for $pet again after a decline (null = now). */
    private function retryAt(Pet $pet, User $child, string $key, CarbonInterface $now): ?CarbonImmutable
    {
        $declinedAt = PetWish::where('pet_id', $pet->id)->where('child_user_id', $child->id)->where('item_key', $key)
            ->where('status', WishStatus::Declined->value)->max('decided_at');
        if ($declinedAt === null) {
            return null;
        }
        $retry = CarbonImmutable::parse($declinedAt, 'UTC')->addDays(self::RETRY_DAYS);

        return $retry->greaterThan($now) ? $retry : null;
    }

    /** A wish push for this child went out (or is held) in the last PUSH_GAP_MINUTES. */
    private function pushedRecently(User $child, CarbonInterface $now): bool
    {
        $since = CarbonImmutable::instance($now)->subMinutes(self::PUSH_GAP_MINUTES);
        $recent = PetWish::where('child_user_id', $child->id)->where('created_at', '>', $since)->pluck('id')->map(fn ($id) => (string) $id);
        if ($recent->isEmpty()) {
            return false;
        }

        return PushNotification::where('type', PushType::WishCreated->value)
            ->whereIn('metric', $recent->all())
            ->whereIn('status', [PushNotification::STATUS_SCHEDULED, PushNotification::STATUS_QUEUED, PushNotification::STATUS_SENT])
            ->exists();
    }

    /** The push copy key of an item for a species (push.wish_created.<variant>). */
    public static function pushVariant(string $itemKey, Species $species): ?string
    {
        return ShopItem::find($itemKey)?->variantFor($species);
    }
}
