/**
 * »Želje« / Wishes (M6-W04 child, M6-W05 parent) — pure rules and readers, unit-tested in
 * `__tests__/wishes.test.ts`. API contract: `docs/engineering/WISHES_API.md`; product rules:
 * `docs/product/SHOP_SPEC.md` (S1–S12).
 *
 * - The server decides what may be wished / bought; the app only maps its answers to what a
 *   row shows. Never a price on the child side (S4/S5) — prices come only from the store
 *   (RevenueCat product `priceString`) and only in the parent app.
 * - Old servers: no `wishes` block in the child state, no `wishes_open_count` on the
 *   dashboard, 404 on the wish routes → the feature is hidden (`null` everywhere here).
 * - Display names and pictures live in the app (`wishes:items.<variant>.name`); the server
 *   sends keys only. Real item art is pending (M6-W03) — `WishIcon` draws placeholders.
 */

import { ApiError, type ChildPetState } from '@/api/client';

// ──────────────────────────────────────────────────────────────
//  Catalogue
// ──────────────────────────────────────────────────────────────

export type WishItemKey = 'bed' | 'bowls' | 'toy_1' | 'toy_2' | 'treats';
export type ShopKey = WishItemKey | 'welcome_bundle';

export const WISH_ITEM_KEYS: readonly WishItemKey[] = ['bed', 'bowls', 'toy_1', 'toy_2', 'treats'];

/** Store product per catalogue key (species-neutral, WISHES_API §1). */
export const WISH_PRODUCT_IDS: Readonly<Record<ShopKey, string>> = {
  bed: 'petprep_wish_bed',
  bowls: 'petprep_wish_bowls',
  toy_1: 'petprep_wish_toy_1',
  toy_2: 'petprep_wish_toy_2',
  treats: 'petprep_wish_treats_10',
  welcome_bundle: 'petprep_wish_bundle_welcome',
};

/** The bundle »Dobrodošlica« = bed + bowls + toy_1 (S12). */
export const BUNDLE_KEYS: readonly WishItemKey[] = ['bed', 'bowls', 'toy_1'];

/** Variants the app has a name / picture for (WISHES_API §1). */
export const KNOWN_VARIANTS = [
  'dog_bed',
  'cat_basket',
  'dog_bowls',
  'cat_bowls',
  'dog_rope',
  'cat_mouse',
  'dog_squeaky',
  'cat_feather',
  'dog_treats',
  'cat_treats',
] as const;
export type WishVariant = (typeof KNOWN_VARIANTS)[number];

const VARIANTS: Readonly<Record<WishItemKey, { dog: WishVariant; cat: WishVariant }>> = {
  bed: { dog: 'dog_bed', cat: 'cat_basket' },
  bowls: { dog: 'dog_bowls', cat: 'cat_bowls' },
  toy_1: { dog: 'dog_rope', cat: 'cat_mouse' },
  toy_2: { dog: 'dog_squeaky', cat: 'cat_feather' },
  treats: { dog: 'dog_treats', cat: 'cat_treats' },
};

export function isWishItemKey(value: unknown): value is WishItemKey {
  return typeof value === 'string' && (WISH_ITEM_KEYS as readonly string[]).includes(value);
}

export function isShopKey(value: unknown): value is ShopKey {
  return value === 'welcome_bundle' || isWishItemKey(value);
}

export function isKnownVariant(value: unknown): value is WishVariant {
  return typeof value === 'string' && (KNOWN_VARIANTS as readonly string[]).includes(value);
}

/** The variant to name / draw: the server's when the app knows it, else the key's for the species. */
export function variantFor(key: WishItemKey, variant: unknown, species: unknown): WishVariant {
  if (isKnownVariant(variant)) return variant;
  return VARIANTS[key][species === 'cat' ? 'cat' : 'dog'];
}

/** Catalogue key of a store product (null for anything else, e.g. the challenge). */
export function keyOfProduct(productId: string): ShopKey | null {
  const entry = (Object.entries(WISH_PRODUCT_IDS) as [ShopKey, string][]).find(([, id]) => id === productId);
  return entry ? entry[0] : null;
}

/** Keys a product covers (the bundle covers three). */
export function keysOfProduct(productId: string): WishItemKey[] {
  const key = keyOfProduct(productId);
  if (key === null) return [];
  return key === 'welcome_bundle' ? [...BUNDLE_KEYS] : [key];
}

// ──────────────────────────────────────────────────────────────
//  Small readers
// ──────────────────────────────────────────────────────────────

type Obj = Record<string, unknown>;
const isObj = (v: unknown): v is Obj => typeof v === 'object' && v !== null && !Array.isArray(v);
const num = (v: unknown): number | null => {
  const n = typeof v === 'number' ? v : typeof v === 'string' && v !== '' ? Number(v) : Number.NaN;
  return Number.isFinite(n) ? n : null;
};
const count = (v: unknown, fallback = 0): number => {
  const n = num(v);
  return n === null ? fallback : Math.max(0, Math.round(n));
};
const str = (v: unknown): string | null => (typeof v === 'string' && v.length > 0 ? v : null);
const ms = (iso: string | null): number => {
  if (iso === null) return Number.NaN;
  return Date.parse(iso);
};

// ──────────────────────────────────────────────────────────────
//  Child pet state `wishes` block
// ──────────────────────────────────────────────────────────────

export interface ChildTreats {
  left: number;
  given_today: number;
  daily_limit: number;
}

export interface ChildWishesState {
  open_count: number;
  items: { key: string; variant: string; media_ready: boolean }[];
  treats: ChildTreats;
}

/** `state.wishes`; missing / not an object → null (older server: no »Želje«, no »Priboljšek«). */
export function readChildWishesState(raw: unknown): ChildWishesState | null {
  if (!isObj(raw)) return null;
  const t = isObj(raw.treats) ? raw.treats : {};
  const items = Array.isArray(raw.items)
    ? raw.items.filter(isObj).flatMap((i) => {
        const key = str(i.key);
        return key === null ? [] : [{ key, variant: str(i.variant) ?? '', media_ready: i.media_ready === true }];
      })
    : [];
  return {
    open_count: count(raw.open_count),
    items,
    treats: { left: count(t.left), given_today: count(t.given_today), daily_limit: count(t.daily_limit, 2) },
  };
}

/** »Priboljšek« is on the pet screen only while the pet has treats. */
export function showTreatButton(wishes: ChildWishesState | null, locked: boolean): boolean {
  return wishes !== null && !locked && wishes.treats.left > 0;
}

/** Today's treats are used up (the server also answers 422 `treat_limit_today`). */
export function treatLimitReached(treats: ChildTreats): boolean {
  return treats.daily_limit > 0 && treats.given_today >= treats.daily_limit;
}

// ──────────────────────────────────────────────────────────────
//  Child catalogue (`GET /api/child/wishes`)
// ──────────────────────────────────────────────────────────────

export type ChildItemStatus = 'available' | 'requested' | 'declined_recently' | 'owned';

export interface ChildWishItem {
  key: WishItemKey;
  kind: 'durable' | 'consumable';
  variant: WishVariant;
  status: ChildItemStatus;
  wish_id: number | null;
  retry_at: string | null;
  media_ready: boolean;
  /** Treats only (null for a durable item). */
  treats_left: number | null;
}

export interface ChildWishes {
  pet_id: number;
  species: 'dog' | 'cat';
  open_count: number;
  open_limit: number;
  can_wish: boolean;
  blocked_reason: 'game_over' | 'payment_required' | 'pet_not_started' | null;
  items: ChildWishItem[];
}

const STATUSES: readonly ChildItemStatus[] = ['available', 'requested', 'declined_recently', 'owned'];

export function readChildWishes(raw: unknown): ChildWishes | null {
  if (!isObj(raw)) return null;
  const species = raw.species === 'cat' ? 'cat' : 'dog';
  const items: ChildWishItem[] = Array.isArray(raw.items)
    ? raw.items.filter(isObj).flatMap((i): ChildWishItem[] => {
        if (!isWishItemKey(i.key)) return []; // a key this build can't show (or the bundle) is left out
        const status = (STATUSES as readonly unknown[]).includes(i.status) ? (i.status as ChildItemStatus) : 'available';
        const treatsLeft = i.key === 'treats' ? count(i.treats_left) : null;
        return [
          {
            key: i.key,
            kind: i.key === 'treats' ? 'consumable' : 'durable',
            variant: variantFor(i.key, i.variant, species),
            status,
            wish_id: num(i.wish_id),
            retry_at: str(i.retry_at),
            media_ready: i.media_ready === true,
            treats_left: treatsLeft,
          },
        ];
      })
    : [];
  const blocked =
    raw.blocked_reason === 'game_over' || raw.blocked_reason === 'payment_required' || raw.blocked_reason === 'pet_not_started'
      ? raw.blocked_reason
      : null;
  return {
    pet_id: count(raw.pet_id),
    species,
    open_count: count(raw.open_count),
    open_limit: count(raw.open_limit, 3),
    // A server that predates `can_wish` → assume yes; a blocked reason always means no.
    can_wish: raw.can_wish !== false && blocked === null,
    blocked_reason: blocked,
    items,
  };
}

/** Treats may be wished only while ≤ 2 are left (WISHES_API §2 `treats_still_left`). */
export const TREATS_WISH_MAX_LEFT = 2;

/**
 * What one catalogue row shows on the child side. The child never sees money: a declined
 * wish is only »Mogoče kdaj drugič«.
 * - `ask` — »Prosi starša« (enabled)
 * - `limit` — the button is off: 3 open wishes already
 * - `requested` — »Prošnja poslana«
 * - `declined` — »Mogoče kdaj drugič« (until `retry_at`; after it the row is `ask` again)
 * - `owned` — »Že ima«; `on_the_way` — owned, media not ready yet (»Na poti …«)
 * - `treats_left` — treats: still more than 2 left (no button, »Še N priboljškov«)
 * - `blocked` — the pet can't wish now (game over / waiting for a purchase): no button
 */
export type ChildRowState = 'ask' | 'limit' | 'requested' | 'declined' | 'owned' | 'on_the_way' | 'treats_left' | 'blocked';

export function childRowState(item: ChildWishItem, wishes: Pick<ChildWishes, 'open_count' | 'open_limit' | 'can_wish'>, nowMs: number): ChildRowState {
  if (item.status === 'owned') return item.media_ready ? 'owned' : 'on_the_way';
  if (item.status === 'requested') return 'requested';
  if (item.status === 'declined_recently') {
    const retry = ms(item.retry_at);
    // No retry time → stays »Mogoče kdaj drugič« until the server says otherwise.
    if (!Number.isFinite(retry) || nowMs < retry) return 'declined';
  }
  if (!wishes.can_wish) return 'blocked';
  if (item.key === 'treats' && (item.treats_left ?? 0) > TREATS_WISH_MAX_LEFT) return 'treats_left';
  if (wishes.open_count >= wishes.open_limit) return 'limit';
  return 'ask';
}

/** The catalogue after a successful wish: the row turns »Prošnja poslana«, the count follows the server. */
export function applyWishCreated(wishes: ChildWishes, key: WishItemKey, wishId: number | null, openCount: number | null): ChildWishes {
  return {
    ...wishes,
    open_count: openCount ?? wishes.open_count + 1,
    items: wishes.items.map((i) => (i.key === key ? { ...i, status: 'requested', wish_id: wishId, retry_at: null } : i)),
  };
}

/** Machine reason of a refused child wish / treat (422 / 423 `reason`), or null. */
export function errorReason(error: unknown): string | null {
  if (!(error instanceof ApiError) || !isObj(error.data)) return null;
  return str(error.data.reason);
}

/**
 * A 404 that means "this server has no wishes" (route missing on an old server). The child
 * catalogue's own 404 `no_pet` and the parent's `pet_not_found` are real answers, not this.
 */
export function isFeatureMissing(error: unknown): boolean {
  if (!(error instanceof ApiError) || error.status !== 404) return false;
  const reason = errorReason(error);
  return reason === null;
}

// ──────────────────────────────────────────────────────────────
//  Treat answer (`POST /api/child/pet/treat`)
// ──────────────────────────────────────────────────────────────

export interface TreatResult {
  treats_left: number;
  given_today: number;
  daily_limit: number;
  /** URL of the treat clip when the server sent one (W03), else null → picture + hearts. */
  clipUrl: string | null;
  /** The answer's child pet state (QA #416 m11), set by `useGiveTreat`; absent = older contract. */
  state?: ChildPetState | null;
}

/**
 * `media` is »PetMedia | null« in the contract; until W03 defines it exactly the app accepts
 * any of `url` / `video_url` / `current_video_url` / `videos.treat` (assumption, see PR).
 */
export function treatClipUrl(media: unknown): string | null {
  if (!isObj(media)) return null;
  const videos = isObj(media.videos) ? media.videos : {};
  return str(media.video_url) ?? str(media.url) ?? str(media.current_video_url) ?? str(videos.treat) ?? null;
}

export function readTreatResult(raw: unknown): TreatResult | null {
  if (!isObj(raw) || num(raw.treats_left) === null) return null;
  return {
    treats_left: count(raw.treats_left),
    given_today: count(raw.given_today),
    daily_limit: count(raw.daily_limit, 2),
    clipUrl: treatClipUrl(raw.media),
  };
}

export type TreatFailure = 'no_treats' | 'treat_limit_today' | 'pet_sleeping';

export function treatFailureOf(error: unknown): TreatFailure | null {
  if (!(error instanceof ApiError) || error.status !== 422) return null;
  const reason = errorReason(error);
  return reason === 'no_treats' || reason === 'treat_limit_today' || reason === 'pet_sleeping' ? reason : null;
}

// ──────────────────────────────────────────────────────────────
//  Parent list (`GET /api/parent/wishes`)
// ──────────────────────────────────────────────────────────────

export interface ParentWish {
  id: number;
  pet_id: number;
  pet_name: string | null;
  species: 'dog' | 'cat';
  child_id: number;
  child_name: string;
  item_key: WishItemKey;
  variant: WishVariant;
  product_id: string;
  created_at: string | null;
  bundle_product_id: string | null;
}

export interface ParentPetItem {
  key: WishItemKey;
  variant: WishVariant;
  product_id: string;
  owned: boolean;
  media_ready: boolean;
  treats_left: number | null;
}

export interface ParentWishPet {
  pet_id: number;
  pet_name: string | null;
  species: 'dog' | 'cat';
  bundle_eligible: boolean;
  /** Store product of the welcome bundle for this pet (server field; fallback = the known id). */
  bundle_product_id: string | null;
  items: ParentPetItem[];
}

export interface ParentWishes {
  open_count: number;
  wishes: ParentWish[];
  pets: ParentWishPet[];
  unassigned_credits: UnassignedCredit[];
}

export interface UnassignedCredit {
  product_id: string;
  count: number;
  /** Catalogue key from the server (`item_key`); fallback = the known product id; null = unknown (generic label). */
  item_key: ShopKey | null;
}

export function readParentPetItems(raw: unknown, species: 'dog' | 'cat'): ParentPetItem[] {
  if (!Array.isArray(raw)) return [];
  return raw.filter(isObj).flatMap((i): ParentPetItem[] => {
    if (!isWishItemKey(i.key)) return [];
    return [
      {
        key: i.key,
        variant: variantFor(i.key, i.variant, species),
        product_id: str(i.product_id) ?? WISH_PRODUCT_IDS[i.key],
        owned: i.owned === true,
        media_ready: i.media_ready === true,
        treats_left: i.key === 'treats' ? count(i.treats_left) : null,
      },
    ];
  });
}

export function readParentWishes(raw: unknown): ParentWishes | null {
  if (!isObj(raw)) return null;
  const wishes: ParentWish[] = Array.isArray(raw.wishes)
    ? raw.wishes.filter(isObj).flatMap((w): ParentWish[] => {
        const id = num(w.id);
        const petId = num(w.pet_id);
        if (id === null || petId === null || !isWishItemKey(w.item_key)) return [];
        const species = w.species === 'cat' ? 'cat' : 'dog';
        return [
          {
            id,
            pet_id: petId,
            pet_name: str(w.pet_name),
            species,
            child_id: count(w.child_id),
            child_name: str(w.child_name) ?? '',
            item_key: w.item_key,
            variant: variantFor(w.item_key, w.variant, species),
            product_id: str(w.product_id) ?? WISH_PRODUCT_IDS[w.item_key],
            created_at: str(w.created_at),
            bundle_product_id: str(w.bundle_product_id),
          },
        ];
      })
    : [];
  const pets: ParentWishPet[] = Array.isArray(raw.pets)
    ? raw.pets.filter(isObj).flatMap((p): ParentWishPet[] => {
        const petId = num(p.pet_id);
        if (petId === null) return [];
        const species = p.species === 'cat' ? 'cat' : 'dog';
        const eligible = p.bundle_eligible === true;
        // QA #416 m13: the server's `bundle_product_id`; an older payload without it → the known id.
        const bundle = 'bundle_product_id' in p ? str(p.bundle_product_id) : eligible ? WISH_PRODUCT_IDS.welcome_bundle : null;
        return [{ pet_id: petId, pet_name: str(p.pet_name), species, bundle_eligible: eligible, bundle_product_id: eligible ? bundle : null, items: readParentPetItems(p.items, species) }];
      })
    : [];
  // QA #416 m13: the server sends `item_key` per credit (it only lists wish credits) — no
  // filtering by hard-coded product ids; an older payload without it falls back to the known ids.
  const credits: UnassignedCredit[] = Array.isArray(raw.unassigned_credits)
    ? raw.unassigned_credits.filter(isObj).flatMap((c) => {
        const productId = str(c.product_id);
        const n = count(c.count);
        if (productId === null || n === 0) return [];
        const itemKey = isShopKey(c.item_key) ? c.item_key : keyOfProduct(productId);
        return [{ product_id: productId, count: n, item_key: itemKey }];
      })
    : [];
  return { open_count: count(raw.open_count, wishes.length), wishes, pets, unassigned_credits: credits };
}

/** Dashboard `wishes_open_count`; missing (older server) → null = no »Želje« row. */
export function readWishesOpenCount(dashboard: unknown): number | null {
  if (!isObj(dashboard)) return null;
  const n = num(dashboard.wishes_open_count);
  return n === null ? null : Math.max(0, Math.round(n));
}

/**
 * »Vse tri za …« next to a wish: only when the server set `bundle_product_id` (the wish is
 * for bed / bowls / toy_1 and the pet owns none of the three) and the store has the bundle.
 */
export function showBundleOffer(wish: Pick<ParentWish, 'bundle_product_id'>, storeHas: (productId: string) => boolean): boolean {
  return wish.bundle_product_id !== null && storeHas(wish.bundle_product_id);
}

/** What the surprise list shows for one item of a pet. */
export type ParentItemState = 'buy' | 'owned' | 'on_the_way';

export function parentItemState(item: ParentPetItem): ParentItemState {
  if (item.key === 'treats' || !item.owned) return 'buy';
  return item.media_ready ? 'owned' : 'on_the_way';
}

/** Store product ids the parent screen needs prices for (deduplicated, stable order). */
export function productIdsOf(data: ParentWishes | null): string[] {
  const ids = new Set<string>();
  for (const w of data?.wishes ?? []) {
    ids.add(w.product_id);
    if (w.bundle_product_id) ids.add(w.bundle_product_id);
  }
  for (const p of data?.pets ?? []) {
    for (const i of p.items) ids.add(i.product_id);
    if (p.bundle_eligible && p.bundle_product_id) ids.add(p.bundle_product_id);
  }
  return [...ids].sort();
}

// ──────────────────────────────────────────────────────────────
//  QA #416 m4: is a purchase still possible on fresh data?
// ──────────────────────────────────────────────────────────────

export interface PurchaseTarget {
  petId: number;
  productId: string;
  wishId: number | null;
}

/**
 * Checked on a fresh `GET /api/parent/wishes` right before the store sheet opens (another
 * parent may have bought / declined meanwhile):
 * - `wish_gone` — the wish is no longer open;
 * - `pet_gone` — the pet can't receive items any more;
 * - `owned` — the pet already owns the (durable) item;
 * - `bundle_not_eligible` — the pet owns one of the bundle's items now.
 */
export type PurchaseCheck = 'ok' | 'wish_gone' | 'pet_gone' | 'owned' | 'bundle_not_eligible';

export function purchaseStillPossible(data: ParentWishes, target: PurchaseTarget): PurchaseCheck {
  if (target.wishId !== null && !data.wishes.some((w) => w.id === target.wishId)) return 'wish_gone';
  const pet = data.pets.find((p) => p.pet_id === target.petId);
  if (!pet) return 'pet_gone';
  const isBundle = pet.bundle_product_id === target.productId || keyOfProduct(target.productId) === 'welcome_bundle';
  if (isBundle) return pet.bundle_eligible ? 'ok' : 'bundle_not_eligible';
  const item = pet.items.find((i) => i.product_id === target.productId);
  if (item && item.key !== 'treats' && item.owned) return 'owned';
  return 'ok';
}
