# Wishes (»Želje«) — API contract (M6-W01 / W02 / W04 / W05)

> Contract agreed before building (orchestrator, 2026-10-27) so backend and app work in parallel.
> Product rules: `docs/product/SHOP_SPEC.md` (S1–S12). David's answers 2026-10-27: **two toys per species**, **treats max 2 per day**, **buying from a wish assigns the item to that wish's pet automatically** (a purchase without a wish: the parent picks the pet first), **no feature switch — live for everyone at once** (buying works only once the store products exist; until then the app says the purchase isn't available yet).
> Media of bought items (M6-W03) is a separate PR; until it lands every owned item is `media_ready: false` (»na poti«).

## 1. Catalogue

Server-side catalogue `shop_items` (seeded by a migration, not editable in the app). One row per `key`; `variant` depends on the pet's species.

| key | kind | dog variant | cat variant | store product |
|---|---|---|---|---|
| `bed` | durable | `dog_bed` | `cat_basket` | `petprep_wish_bed` (5,99 €) |
| `bowls` | durable | `dog_bowls` | `cat_bowls` | `petprep_wish_bowls` (5,99 €) |
| `toy_1` | durable | `dog_rope` (rope tug toy) | `cat_mouse` (plush mouse) | `petprep_wish_toy_1` (5,99 €) |
| `toy_2` | durable | `dog_squeaky` (squeaky toy) | `cat_feather` (feather wand) | `petprep_wish_toy_2` (5,99 €) |
| `treats` | consumable, 10 per pack | `dog_treats` | `cat_treats` | `petprep_wish_treats_10` (2,99 €) |
| `welcome_bundle` | bundle = bed + bowls + toy_1 | — | — | `petprep_wish_bundle_welcome` (15,99 €) |

- Product ids are species-neutral (one store product per key). Config: `services.revenuecat.wish_products` (product id → key).
- `welcome_bundle` is **never** in the child catalogue (S12). It is offered to the parent only for a pet that owns none of bed / bowls / toy_1.
- Display names, descriptions and pictures live in the app (i18n `wishes.items.<variant>.*`); the server sends keys only.

## 2. Rules (server enforces)

- Who may wish: a child caretaker of an **active** pet (free plan or challenge). Not when the pet is game over, deleted, or the challenge is `payment_required`. Hard-stop / illness do **not** block wishing (it is not a care action).
- **Max 3 open wishes per child** (all their pets together) — S9.
- No second open wish for the same pet + key.
- A durable item the pet already owns cannot be wished (`already_owned`).
- Treats can be wished only while the pet has **≤ 2 treats left** (`treats_still_left`).
- After »Ne zdaj« (`declined`) the same child may wish the same key for the same pet again **7 days** after the decline (`declined_recently`, `retry_at`).
- A wish is `open` → `bought` (parent bought, item assigned to the wish's pet) | `declined` | `cancelled` (pet deleted / game over / the pet got the item another way, e.g. bundle or a surprise purchase).
- Treats: child gives max **2 per day** (family's local day). Treats never change needs, Care Score, certificate (S1); they only play the treat clip.
- **No refunds on game over / pet deletion** (S11): the credit stays used. A store refund (RevenueCat CANCELLATION / REFUND) revokes the credit and removes the item from the pet (treats: the unused treats of that pack).

## 3. Child endpoints (token ability `child`)

### `GET /api/child/wishes`
```json
{
  "pet_id": 12,
  "species": "dog",
  "open_count": 1,
  "open_limit": 3,
  "items": [
    { "key": "bed", "kind": "durable", "variant": "dog_bed",
      "status": "available" },
    { "key": "bowls", "kind": "durable", "variant": "dog_bowls",
      "status": "requested", "wish_id": 31, "requested_at": "2026-10-27T08:00:00Z" },
    { "key": "toy_1", "kind": "durable", "variant": "dog_rope",
      "status": "declined_recently", "retry_at": "2026-11-03T08:00:00Z" },
    { "key": "toy_2", "kind": "durable", "variant": "dog_squeaky",
      "status": "owned", "media_ready": false },
    { "key": "treats", "kind": "consumable", "variant": "dog_treats",
      "status": "available", "treats_left": 0 }
  ]
}
```
`status` ∈ `available` | `requested` | `declined_recently` | `owned` (durable only; treats always carry `treats_left` instead). Top level also always has `"can_wish": true | false` and, when false, `"blocked_reason": "game_over" | "payment_required"` (still 200, items listed so the child sees what the pet has). 404 `no_pet` when the child has no pet.

### `POST /api/child/wishes` `{ "item_key": "bed" }`
- 201 `{ "wish": { "id", "item_key", "status": "open", "created_at" }, "open_count": 2 }` — and a push to the parents (§6).
- 422 `reason` ∈ `wish_limit_reached`, `already_requested`, `already_owned`, `declined_recently` (+ `retry_at`), `treats_still_left`, `unknown_item`, `bundle_not_wishable`.
- 423 `reason` ∈ `game_over`, `payment_required`.
- Throttle: the existing `child-actions` limiter.

### `POST /api/child/pet/treat`
- 200 `{ "treats_left": 7, "given_today": 1, "daily_limit": 2, "media": <PetMedia|null> }` (`media` = treat clip when ready, else null → app shows the picture).
- 422 `no_treats`, `treat_limit_today` (+ `next_at` = next local midnight), `pet_sleeping` (same sleep rule as play).
- 423 as other child actions (hard-stop, illness, game over).

### Child pet state (`GET /api/child/pet`, `ChildPetStateResource`)
New block:
```json
"wishes": {
  "open_count": 1,
  "items": [ { "key": "bed", "variant": "dog_bed", "media_ready": false } ],
  "treats": { "left": 8, "given_today": 0, "daily_limit": 2 }
}
```
`items` = durable items the pet owns (not revoked). Old apps ignore the block.

## 4. Parent endpoints (token ability `parent`)

### `GET /api/parent/wishes`
```json
{
  "open_count": 2,
  "wishes": [
    { "id": 31, "pet_id": 12, "pet_name": "Suki", "species": "dog",
      "child_id": 7, "child_name": "Ana",
      "item_key": "bowls", "variant": "dog_bowls", "product_id": "petprep_wish_bowls",
      "status": "open", "created_at": "2026-10-27T08:00:00Z",
      "bundle_product_id": "petprep_wish_bundle_welcome" }
  ],
  "pets": [
    { "pet_id": 12, "pet_name": "Suki", "species": "dog",
      "bundle_eligible": true,
      "items": [
        { "key": "bed", "variant": "dog_bed", "product_id": "petprep_wish_bed", "owned": false, "media_ready": false },
        { "key": "treats", "variant": "dog_treats", "product_id": "petprep_wish_treats_10", "treats_left": 0 }
      ] }
  ],
  "unassigned_credits": [ { "product_id": "petprep_wish_bed", "count": 1 } ]
}
```
- `wishes`: open wishes only, newest first. `bundle_product_id` is set when the wish is for bed / bowls / toy_1 and that pet is `bundle_eligible`.
- `pets`: every active pet of the family that can receive items (not game over / deleted / `payment_required`), for the surprise purchase.
- `unassigned_credits`: purchases the server has but that are not on a pet yet (e.g. the app closed after paying) — the app offers »Komu?« for them.
- `pet_name` may be null (unnamed pet) → app shows species + breed as everywhere else.

### `POST /api/parent/wishes/{wish}/decline`
200 `{ "wish": { "id", "status": "declined" }, "open_count": 1 }`. 404 for another family's wish; 422 `wish_not_open`.

### `POST /api/parent/pets/{pet}/items/redeem` `{ "product_id": "petprep_wish_bed", "wish_id": 31 }`
Call right after a successful store purchase (like `challenge/activate`). Uses the family's **oldest unused** wish credit with that `product_id` for this pet.
- 200 `{ "status": "assigned" | "already_assigned", "items": [ … pet's items like in `pets[].items` … ], "open_count": 1 }`. `wish_id` (optional) must be an open wish of this pet whose key the product covers → it becomes `bought`; any other open wish of this pet for a key the purchase covers becomes `cancelled`.
- 409 `no_credit` — the purchase hasn't reached the server yet; retry (app: 3× with backoff, then »Nakup je zabeležen, stvar bo kmalu dodana« and the credit shows in `unassigned_credits`).
- 422 `already_owned` (durable the pet already has — the credit stays unused for another pet), `bundle_not_eligible`, `unknown_product`, `pet_not_active`, `wish_mismatch`.
- 404 `pet_not_found`.
- Idempotency: the app sends `Idempotency-Key: <store transaction id>` when it has one; a repeat with the same key returns the first result (`already_assigned`).

### Parent dashboard (`GET /api/parent/dashboard`)
New top-level field `"wishes_open_count": 2` (all children, all pets of the family).

## 5. RevenueCat webhook (W02)

- `INITIAL_PURCHASE` / `NON_RENEWING_PURCHASE` of a `wish_products` id → one `wish_credits` row for the family (same ledger `purchase_events`, new outcome stays `granted`). No auto-assignment by the webhook (the app redeems with the wish).
- `CANCELLATION` (no expiration) / `REFUND` → the credit is revoked; if assigned, the pet's item (or the pack's unused treats) is removed; an open wish is not re-opened.
- `TRANSFER` → unassigned wish credits move like challenge credits.
- SANDBOX follows `services.revenuecat.accept_sandbox`.

## 6. Push

- Child wishes → push to every parent of the family who has the app (`PushCopy`, parent register formal): title = pet name when set, else »PetPrep«; body SL »Vaš otrok si želi {item_acc}.«, EN »Your child is wishing for {item}.« (never the child's or pet's name in the body). Data `{ "type": "wish_created", "wish_id": 31 }` → app opens the parent wishes list. Held over quiet hours like other parent pushes. Max one wish push per child per 10 minutes (the rest just land on the list).
- Item ready (W03): child push »{Ime} ima novo posteljo!« — not in W01.

## 7. Data, privacy, admin

- Tables: `shop_items` (key, kind, pack_size, dog_variant, cat_variant, product_id, sort), `pet_wishes` (pet_id, child_user_id, item_key, status, decided_by_user_id, decided_at, timestamps), `wish_credits` (like `challenge_credits`: family, purchase_event, product_id, store, environment, transaction_id, purchased_at, pet_id, assigned_at, assigned_via, revoked_at, revoke_reason, revoke_event_id, transferred_*), `pet_items` (pet_id, item_key, variant, wish_credit_id, quantity_total, quantity_left, acquired_at, revoked_at), `pet_treat_events` (pet_id, child_user_id, pet_item_id, given_at, local_date).
- A wish stores only ids (child, pet, item) — no new personal data. Included in the GDPR family export; deleted with the pet / family (cascade). Wish credits survive a pet deletion with `pet_id` null (used), like challenge credits.
- `/admin`: read-only Wish credits resource + wishes / items on the Pet view.
