# Wishes (»Želje«) — API contract (M6-W01 / W02 / W03 / W04 / W05)

> Contract agreed before building (orchestrator, 2026-10-27) so backend and app work in parallel.
> Product rules: `docs/product/SHOP_SPEC.md` (S1–S12). David's answers 2026-10-27: **two toys per species**, **treats max 2 per day**, **buying from a wish assigns the item to that wish's pet automatically** (a purchase without a wish: the parent picks the pet first), **no feature switch — live for everyone at once** (buying works only once the store products exist; until then the app says the purchase isn't available yet).
> **M6-W03 (David 2026-11-03, »popolno funkcionalno«): designs + AI content of the items** — every durable item comes in 3 designs per species (the child picks one, the parent buys exactly that one), every design has a catalogue picture, and an owned item gets its own AI picture + clip of the pet with it (§8). `media_ready` is real since then.

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
- **Designs (M6-W03, `backend/config/shop_designs.php`, read only through `App\Services\Shop\ItemDesigns`):** 3 per durable item and species (24 in all), stable keys, EN + SL names, one precise visual description used in every prompt (the catalogue picture and the pet-with-item picture show the same object). The FIRST design is the default. Treats have no designs (one pack). Keys never change or get reused.

| item | dog designs | cat designs |
|---|---|---|
| `bed` | `dog_bed_round_grey` Okrogla siva postelja / Round grey bed · `dog_bed_navy_bolster` Modra blazina / Navy cushion bed · `dog_bed_wicker_checked` Pletena košara / Wicker basket | `cat_basket_wicker_cream` Pletena košara / Wicker basket · `cat_bed_grey_cave` Siva hiška / Grey cat cave · `cat_bed_pink_plush` Rožnata plišasta postelja / Pink plush bed |
| `bowls` | `dog_bowls_steel_stand` Kovinski skledi / Steel bowls · `dog_bowls_blue_ceramic` Modri keramični skledi / Blue ceramic bowls · `dog_bowls_bamboo_stand` Skledi v leseni stojali / Bowls in a wooden stand | `cat_bowls_white_gold` Bela skledi / White bowls · `cat_bowls_steel_wood` Skledi v leseni stojali / Bowls in a wooden stand · `cat_bowls_teal_fish` Turkizni skledi z ribico / Teal fish bowls |
| `toy_1` | `dog_rope_red_white` Rdeče-bela vrv / Red and white rope · `dog_rope_blue_ring` Modri obroč iz vrvi / Blue rope ring · `dog_rope_rainbow` Mavrična vrv / Rainbow rope | `cat_mouse_grey_plush` Siva plišasta miška / Grey plush mouse · `cat_mouse_red_felt` Rdeča miška iz filca / Red felt mouse · `cat_mouse_white_knit` Bela pletena miška / White knitted mouse |
| `toy_2` | `dog_squeaky_duck` Rumena raca / Yellow duck · `dog_squeaky_bone` Plišasta kost / Plush bone · `dog_squeaky_ball` Zelena piskajoča žoga / Green squeaky ball | `cat_wand_rainbow_feathers` Palica z mavričnimi peresi / Rainbow feather wand · `cat_wand_pink_bell` Palica z rožnatimi peresi in zvončkom / Pink feather wand with a bell · `cat_wand_peacock` Palica s pavjim peresom / Peacock feather wand |
- `welcome_bundle` is **never** in the child catalogue (S12). It is offered to the parent only for a pet that owns none of bed / bowls / toy_1.
- Display names, descriptions and pictures live in the app (i18n `wishes.items.<variant>.*`); the server sends keys only.

## 2. Rules (server enforces)

- Who may wish: a child caretaker of an **active** pet (free plan or challenge). Not when the pet is game over, deleted, or the challenge is `payment_required`. Hard-stop / illness do **not** block wishing (it is not a care action).
- **Max 3 open wishes per child** (all their pets together) — S9.
- No second open wish for the same pet + key.
- A durable item the pet already owns cannot be wished (`already_owned`).
- Treats can be wished only while the pet has **≤ 2 treats left** (`treats_still_left`).
- **Designs (M6-W03):** a wish for an item with designs names one of its designs for the pet's species; **no design → the item's first design** (app builds without the picker — QA #655 m2); an unknown design (or any design on a treats wish) → 422 `invalid_design`. A purchase from a wish gets the wish's design; a surprise purchase the parent's `design`; the welcome bundle (S12) one design per item (`designs`; the wished item keeps the wish's design). **A paid purchase is never refused over a design** (QA #655 m1): a missing or unknown design → the item's first design. Items owned before designs existed have the first design of their item (migration backfill — e.g. the bed bought on production at the end of October).
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
      "designs": [
        { "key": "dog_bed_round_grey", "name": "Okrogla siva postelja", "image": "https://api.petprep.si/api/media/items/5?expires=…&v=…&signature=…" },
        { "key": "dog_bed_navy_bolster", "name": "Modra blazina", "image": null },
        { "key": "dog_bed_wicker_checked", "name": "Pletena košara", "image": "https://api.petprep.si/api/media/items/7?…" }
      ],
      "design": null, "status": "available" },
    { "key": "bowls", "kind": "durable", "variant": "dog_bowls", "designs": [ … ],
      "design": "dog_bowls_blue_ceramic",
      "status": "requested", "wish_id": 31, "requested_at": "2026-10-27T08:00:00Z" },
    { "key": "toy_1", "kind": "durable", "variant": "dog_rope", "designs": [ … ], "design": null,
      "status": "declined_recently", "retry_at": "2026-11-03T08:00:00Z" },
    { "key": "toy_2", "kind": "durable", "variant": "dog_squeaky", "designs": [ … ],
      "design": "dog_squeaky_duck", "status": "owned", "media_ready": false },
    { "key": "treats", "kind": "consumable", "variant": "dog_treats", "designs": [], "design": null,
      "status": "available", "treats_left": 0 }
  ]
}
```
`status` ∈ `available` | `requested` | `declined_recently` | `owned` (durable only; treats always carry `treats_left` instead).
**M6-W03:** every row has `designs` (`key`, `name` in the request language — `Accept-Language` en | sl —, `image` = signed URL of the design's catalogue picture or **null while it is not made yet**: show the design without a picture, never block wishing; treats: `[]`) and `design` (the owned design for `owned`, the wished design for `requested`, else null). `media_ready` (owned rows; treats while the pet has a pack) is real: §8. Top level also always has `"can_wish": true | false` and `"blocked_reason": "game_over" | "pet_not_started" | "payment_required" | null` (null while the child can wish; always present since QA #417; still 200, items listed so the child sees what the pet has). `pet_not_started` = the pet is not born yet or this child has not signed their contract. `open_count` here = the **child's** open wishes (all pets) — the number the limit of 3 counts. 404 `no_pet` when the child has no pet.

### `POST /api/child/wishes` `{ "item_key": "bed", "design": "dog_bed_round_grey" }`
- `design` (M6-W03): required for an item with designs (one of its `designs[].key` for the pet's species), absent / null for treats.
- 201 `{ "wish": { "id", "item_key", "design", "status": "open", "created_at" }, "open_count": 2 }` — and a push to the parents (§6).
- 422 `reason` ∈ `wish_limit_reached`, `already_requested`, `already_owned`, `declined_recently` (+ `retry_at`), `treats_still_left`, `unknown_item`, `bundle_not_wishable`, `invalid_design` (M6-W03: a design that does not exist for this item and species, or a design for treats; a missing design is NOT an error — the first design).
- 423 `reason` ∈ `game_over` (also an inactive pet), `pet_not_started` (unborn / this child's contract not signed — QA #417), `payment_required`.
- Throttle: the existing `child-actions` limiter.

### `POST /api/child/pet/treat`
- 200 `{ "status": "accepted", "treats_left": 7, "given_today": 1, "daily_limit": 2, "media": {…}|null, "state": {…} }` — `media` (M6-W03) = the treat content of the pet's current picture: `{ "image", "video", "video_seconds", "loop": false, "video_url", "url" }` (the `media.items.treats` entry of §8 plus `video_url` = `video` and `url` = `video` ?? `image` — what the M6-W04 app reads); null while it is made, for a pet without AI media, and when it was given up without a picture → the app shows the drawing.
- 422 `no_treats`, `treat_limit_today` (+ `next_at` = next local midnight), `pet_sleeping` (same sleep rule as play; `next_at` = wake time); refusals also carry `treats_left`, `given_today`, `daily_limit`, `state`.
- 423 as other child actions (hard-stop, illness, game over, contract, payment) — `{reason, locked_until, state}`.

### Child pet state (`GET /api/child/pet`, `ChildPetStateResource`)
New block:
```json
"wishes": {
  "open_count": 1,
  "items": [ { "key": "bed", "variant": "dog_bed", "design": "dog_bed_round_grey", "media_ready": false } ],
  "treats": { "left": 8, "given_today": 0, "daily_limit": 2 }
}
```
`items` = durable items the pet owns (not revoked), with `design` (M6-W03) and the real `media_ready` (§8). **`open_count` here = open wishes for this pet (all children)** — the same number in HTTP and in `PetUpdated` broadcasts (QA #417 m9); the per-child count for the limit is on `GET /api/child/wishes`. Old apps ignore the block.

## 4. Parent endpoints (token ability `parent`)

### `GET /api/parent/wishes`
```json
{
  "open_count": 2,
  "wishes": [
    { "id": 31, "pet_id": 12, "pet_name": "Suki", "species": "dog",
      "child_id": 7, "child_name": "Ana",
      "item_key": "bowls", "variant": "dog_bowls", "design": "dog_bowls_blue_ceramic", "product_id": "petprep_wish_bowls",
      "status": "open", "created_at": "2026-10-27T08:00:00Z",
      "bundle_product_id": "petprep_wish_bundle_welcome" }
  ],
  "pets": [
    { "pet_id": 12, "pet_name": "Suki", "species": "dog",
      "bundle_eligible": true, "bundle_product_id": "petprep_wish_bundle_welcome",
      "items": [
        { "key": "bed", "variant": "dog_bed", "product_id": "petprep_wish_bed",
          "designs": [ { "key": "dog_bed_round_grey", "name": "Round grey bed", "image": "https://…/api/media/items/5?…" }, … ],
          "design": null, "owned": false, "media_ready": false },
        { "key": "treats", "variant": "dog_treats", "product_id": "petprep_wish_treats_10", "designs": [], "design": null, "treats_left": 0 }
      ] }
  ],
  "unassigned_credits": [ { "product_id": "petprep_wish_bed", "item_key": "bed", "count": 1 } ]
}
```
- `wishes`: open wishes only, newest first. `bundle_product_id` is set when the wish is for bed / bowls / toy_1 and that pet is `bundle_eligible`. `design` (M6-W03) = the design the child picked (null for treats and for wishes from before designs) — the parent buys exactly that one.
- `pets[].items` (also the surprise-purchase list and the redeem answer, M6-W03): every row has `designs` (as in the child catalogue — the parent picks one for a surprise purchase) and `design` (the owned design, else null); owned rows carry the real `media_ready` (§8); a treats row carries `media_ready` only while the pet has a pack.
- `pets`: every active pet of the family that can receive items (not game over / deleted / unborn / `payment_required`), for the surprise purchase. `bundle_product_id` = the bundle's store id while the pet is `bundle_eligible`, else null.
- `unassigned_credits`: purchases (per product, with its `item_key`) the server has but that are not on a pet yet (e.g. the app closed after paying) — the app offers »Komu?« for them.
- `pet_name` may be null (unnamed pet) → app shows species + breed as everywhere else.

### `POST /api/parent/wishes/{wish}/decline`
200 `{ "wish": { "id", "status": "declined" }, "open_count": 1 }`. 404 for another family's wish; 422 `wish_not_open`.

### `POST /api/parent/pets/{pet}/items/redeem` `{ "product_id": "petprep_wish_bed", "wish_id": 31 }`
M6-W03 designs: with `wish_id` the item gets the wish's design (a different `design` is ignored); without a wish optional `"design": "dog_bed_navy_bolster"`; the bundle optional `"designs": { "bed": "…", "bowls": "…", "toy_1": "…" }` (the wished item keeps the wish's design). A missing or unknown design → the item's first design — redeem never answers 422 over a design (QA #655 m1: a paid purchase is never refused over a cosmetic choice). The item's AI content is queued at once (§8).
Call right after a successful store purchase (like `challenge/activate`). Uses the family's unused wish credit of that `product_id` whose store `transaction_id` equals the `Idempotency-Key` when there is one, else the **oldest unused** one.
- 200 `{ "status": "assigned" | "already_assigned", "items": [ … pet's items like in `pets[].items` … ], "open_count": 1 }`. `wish_id` (optional) must be an open wish of this pet whose key the product covers → it becomes `bought`; any other open wish of this pet for a key the purchase covers becomes `cancelled`.
- 409 `no_credit` — the purchase hasn't reached the server yet; retry (app: 3× with backoff, then »Nakup je zabeležen, stvar bo kmalu dodana« and the credit shows in `unassigned_credits`).
- 422 `already_owned` (durable the pet already has — the credit stays unused for another pet), `bundle_not_eligible`, `unknown_product`, `pet_not_active`, `wish_mismatch`.
- 404 `pet_not_found`.
- Idempotency: the app sends `Idempotency-Key: <store transaction id>` when it has one; a repeat with the same key for the **same pet** returns `already_assigned` with that pet's items; the same key for **another pet** → 422 `wish_mismatch` (never the other pet's items).
- `pet_not_active` also for an unborn pet (QA #417).

### Parent dashboard (`GET /api/parent/dashboard`)
New top-level field `"wishes_open_count": 2` (all children, all pets of the family).

## 5. RevenueCat webhook (W02)

- `INITIAL_PURCHASE` / `NON_RENEWING_PURCHASE` of a `wish_products` id → one `wish_credits` row for the family (same ledger `purchase_events`, new outcome stays `granted`). No auto-assignment by the webhook (the app redeems with the wish). The same store `transaction_id` resent under a new event id → no second credit (`recorded`; partial unique index on unrevoked `transaction_id`).
- A product mapped to an item key that has no `shop_items` row (config typo) counts as unknown → `unknown_product` + error log (never a 500, so RevenueCat does not retry forever); redeem → 422 `unknown_product`.
- `CANCELLATION` (no expiration) / `REFUND` → the credit is revoked; if assigned, the pet's item (or the pack's unused treats) is removed; an open wish is not re-opened.
- `TRANSFER` → unassigned wish credits move like challenge credits.
- SANDBOX follows `services.revenuecat.accept_sandbox`.

## 6. Push

- Child wishes → push to every parent of the family who has the app (`PushCopy`, parent register formal): title = pet name when set, else »PetPrep«; body SL »Vaš otrok si želi {item_acc}.«, EN »Your child is wishing for {item}.« (never the child's or pet's name in the body). Data `{ "type": "wish_created", "pet_id": 12, "wish_id": 31 }` → app opens the parent wishes list. Held over quiet hours like other parent pushes. Max one wish push per child per 10 minutes, and while one of the child's wish pushes is still held over quiet hours no second one is created (the end of quiet hours releases at most one); the rest just land on the list. Dropped at delivery when the wish is no longer open.
- **Item ready (M6-W03, `PushType::ItemReady`, type `item_ready`):** to the pet's **caretaker children** once per (pet, item, design) when its content is there (or will not come — the item is then shown without it): title = the pet's name when set, else »PetPrep«; body = one whole sentence per item variant, no names (SL »Nova postelja je tu. Pridi pogledat!«, »Novi skledi sta tu. …«, »Priboljški so tu. …«; EN »The new bed is here. Come and see!«). Data `{ "type": "item_ready", "pet_id": 12 }` only. Held over quiet hours, dropped while the pet is locked; child pushes go to the device that opened this pet last (2026-11-02 rule). A new life stage refreshes the content without a second push. The app (2026-11-03) opens the child's pet screen and refetches the pet state + catalogue; not urgent.

## 7. Data, privacy, admin

- Tables: `shop_items` (key, kind, pack_size, dog_variant, cat_variant, product_id, sort), `pet_wishes` (pet_id, child_user_id, item_key, status, decided_by_user_id, decided_at, timestamps), `wish_credits` (like `challenge_credits`: family, purchase_event, product_id, store, environment, transaction_id, purchased_at, pet_id, assigned_at, assigned_via, revoked_at, revoke_reason, revoke_event_id, transferred_*), `pet_items` (pet_id, item_key, variant, wish_credit_id, quantity_total, quantity_left, acquired_at, revoked_at), `pet_treat_events` (pet_id, child_user_id, pet_item_id, given_at, local_date).
- A wish stores only ids (child, pet, item) — no new personal data. Included in the GDPR family export; deleted with the pet / family (cascade). Wish credits survive a pet deletion with `pet_id` null (used), like challenge credits.
- `/admin`: read-only Wish credits resource + wishes / items on the Pet view. M6-W03: »AI media → Item media« (every catalogue picture and content row, Regenerate) and Pet → »Item media« (the pet's / its look's rows, Regenerate, »Generate missing«) — superadmin, never needed.
- M6-W03: `pet_wishes.design`, `pet_items.design` (in the GDPR export); `item_media` (content rows: catalogue / look / own-pet; own-pet rows + files go with the pet, look rows and catalogue pictures hold no personal data); `item_media_notices` (one row per pet + item + design + stage that was announced).

## 8. Item content (M6-W03, SHOP_SPEC §5)

**What is made** (all through `FalGateway` → `AiSpendGuard`, ledger purpose `item_media`, production budget; `Media\ItemMediaService`, table `item_media`):
- one **catalogue picture** per design (the object alone, square, soft neutral background — Nano Banana Pro text-to-image, ~0,15 $): 24 × 0,15 $ = **3,60 $ once**, made by the scheduled `media:item-media` (every 10 min) right after deploy;
- per owned item and the pet's **current picture** (its reference image of the life stage): a **key image** (Nano Banana Pro Edit of that picture with the animal and the object; the design's catalogue picture is sent as the 2nd reference image so both show the same object, ~0,15 $) and a **clip** (Kling 3.0 Pro, 3 s, start frame = the key image only, ~0,34 $) → **~0,49 $ per item, design and look + stage**. Bed: sleeping on it (loop); bowls: eating (once); toys: playing (loop); treats: eating a treat (once) — **one** generation per look + stage, never per treat.
- **Shared:** a pet of a shared look (breed + colour / free pool) shows the look's content of (design, stage) — made by the first buyer, **0 $** for every later buyer of the same design. A pet with its own media (unique DNA) gets its own. A new life stage (new reference image) → new content for the new stage (shared again); the old one is not shown any more. The pet's state media set is never touched (S8); items never touch game rules (S1).

**`media.items`** (PetMediaPayload — child state `pet.media`, parent dashboard, every `PetUpdated` broadcast): an object keyed by `bed` | `bowls` | `toy_1` | `toy_2` | `treats`, an entry ONLY while that item is owned (treats: an unrevoked pack) AND its content for the current picture is shown:
```json
"items": {
  "bed": {
    "image": "https://api.petprep.si/api/media/items/41?pet=12&expires=1762170000&v=3f2a9c1d0b&signature=…",
    "video": "https://api.petprep.si/api/media/items/42?pet=12&expires=1762170000&v=8e71b04c2a&signature=…",
    "video_seconds": 3,
    "loop": true
  },
  "treats": { "image": "https://…/api/media/items/57?pet=12&…", "video": null, "video_seconds": null, "loop": false }
}
```
`image` = the pet with its item, `video` = the clip (null when only the picture could be made), `loop` = true for bed / toys, false for bowls / treats. Signed URLs of our files (`GET /api/media/items/{id}`, `signed:relative`, expire with `expires_at`) — never fal URLs. Always an object (`{}` when empty).

**`media_ready`** (child catalogue, child state `wishes.items`, parent `pets[].items`, redeem `items`): **true** when the item's content for the current picture is shown, when the pet gets no AI media (AI off, media disabled, its own picture given up — no content ever, the app keeps its drawn stage), or when the automatic recovery gave up (the item is shown without its content); **false** while it is made or waits for the AI budget (»Na poti …«).

**Self-healing** (like the activity clips, PR #547; never an admin step): failures with `http_error` / `invalid_response` / `generation_failed` are retried ≥ 30 min later, at most 2 per failure chain; fal's `no_media_generated` is never sent identically again (the next prompt variant; none left → given up); `timed_out` → given up (a late result is still stored); over the budget / fal balance → waits (not counted) and continues once the budget allows. Given up → the item is shown without that content (`media_ready` true) + one Discord alert per run (counts only). Lost webhooks are polled at fal (free) after 10 min, given up after 2 h. Our own failed download / store (fal URL still valid) is retried from that URL by the hourly sweep up to 3 rounds (free) before the row fails and is regenerated (QA #655 m5); an expired fal URL (4xx) fails at once. A switched-off clip profile → no clip, the key image alone is shown (fallback, `media_ready` true — QA #655 m4); AI / the item image profile off → content already made stays shown, else no content (`no_media`).

**Events:** `PetUpdated` type **`item_media_ready`** once per pet when one of its items gets its content (or falls back) — after commit — and again when an already announced stage changes its outcome (fallback / no media → content after a later Regenerate / Generate missing; QA #655 m3; no second push); `item_acquired` (redeem) as before. Push `item_ready` §6.

**Catalogue picture URLs** carry no `pet`: a product picture with no child data — served on the signature alone (also to a signed-in app). Pet content URLs carry the pet; a request with a token must be allowed to listen to that pet (like every pet media URL).

## 9. M6-W03 app side (2026-11-03)

- **Item media** — every `PetMediaPayload` may carry `items.{bed|bowls|toy_1|toy_2|treats} = {image, video, video_seconds, loop}` (only when owned and ready for the pet's current stage). The app reads it in `modules/petMedia/petMedia.ts#readItemMedia` and shows it per `modules/petMedia/itemMedia.ts`: bed → sleeping scene, toy → playing scene (one toy per play session, alternating), bowls → once after an accepted feed / water, treats → once after »Priboljšek«. Picture mode → the image. Missing → the normal scene.
- **`media_ready`** on catalogue / parent rows drives »Na poti …« → »Že ima« (unchanged); `PetUpdated` `item_media_ready` refetches `GET /api/child/wishes` and `GET /api/parent/wishes` as well as the pet state.
- **Treat answer `media`**: the app reads `items.treats.video` first (older shapes `video_url` / `url` / `current_video_url` / `videos.treat` still work) — but prefers the pet state's own `media.items.treats`.
- **Designs (David 2026-11-03)**: rows gain `designs: [{key, name, image|null}]` (treats `[]`), owned rows and wish rows `design`. Child wish body `{item_key, design}` (design only when the item has designs; a missing design = the first one on the server; 422 `invalid_design` (unknown design) → the app asks to pick again). Redeem body adds **`design`** (one item: the wish's design, or the parent's pick for a surprise / »Komu?«) or, for the welcome bundle, **`designs: {bed, bowls, toy_1}`** (the wish's design fixed for its key) — confirmed by the backend (`RedeemItemRequest`, WishCreditService::redeem). A server without designs sends none → the app behaves as before.
