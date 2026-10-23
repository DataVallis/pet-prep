# Barve pasem in skupni AI mediji (M5-R21)

> **Odločitev:** David, 14. 10. 2026. **Stanje:** strežnik in izbirnik barve v aplikaciji zgrajena 14. 10. 2026 (`feat/M5-R21-breed-colours-shared-media`); na telefonu še ni preverjeno.
> Del 1 je produktni opis (slovensko), del 2 tehnična zasnova (angleško).

## Del 1 — Kaj in zakaj

**Davidove besede (14. 10. 2026):** nekatere pasme so v več barvah. Ko starš izbere pasmo z barvami, naj izbere tudi barvo in naj se ustvari kuža te barve. A ne ustvarjamo unikatnega kužka za vsak nakup — predrago, preveč slik in videov. Za zdaj (zagon, MVP): ko je pasma + barva enkrat ustvarjena, drugi uporabniki z isto pasmo (+ barvo) dobijo že ustvarjene slike in videe. Unikatnega ustvarjanja ne brišemo — je stikalo, **trenutno izklopljeno**, ki ga superadmin preklopi v administraciji (kot mačke). Mešanček ostane na sedanji logiki.

**Pravila**

1. **Izbira barve** — samo pri **plačljivih pasmah**. Ponudimo samo barve, ki jih dovoljuje standard pasme (iste kot v `config/breed_appearance.php`; nikoli barva, ki jo standard izključuje — npr. francoski buldog nikoli moder ali merle). Pasma z eno samo barvo (npr. westie — bel) izbirnika nima, barva se samo pokaže.
2. **Brez izbire** (starejša aplikacija) — barva se izbere po utežeh pasme, kot doslej.
3. **Skupni mediji (privzeto, stikalo »Unikatni AI mediji za vsakega ljubljenčka« izklopljeno):** vsi novi ljubljenčki iste plačljive pasme in barve si delijo **en videz**: isto sliko (za vsako življenjsko obdobje) in iste videe (miruje, spi, lačen …). Prvi ljubljenček sproži ustvarjanje; vsak naslednji dobi že shranjene datoteke **brez novega stroška**. Dva ljubljenčka iste pasme in barve sta torej videti enako — tudi v isti družini.
4. **Stikalo vklopljeno:** vsak nov ljubljenček dobi svoj unikatni videz (izbrana barva, druge lastnosti naključne) — kot pred M5-R21.
5. **Obstoječi ljubljenčki** obdržijo svoje slike in videe; stikalo velja samo za nove.
6. **Mešanček in domača mačka** (brezplačna) ostaneta na skupnem naboru 20 videzov na pasmo (M4-10, odločitev 9. 10. 2026) — brez izbire barve.
7. **Administracija:** »AI mediji → Skupni videzi« pokaže vse skupne videze (pasma, barva, število ljubljenčkov, pripravljene datoteke) z gumbom **»Ponovno ustvari medije«** (npr. slaba slika): ena nova generacija na datoteko za vse ljubljenčke tega videza; na novo se ustvarijo samo obdobja, v katerih so aktivni ljubljenčki videza (ostala obdržijo slike, rast se nadaljuje iz njih); do shranjevanja novih vsi kažejo stare. Administrator dobi jasno sporočilo, zakaj se ni nič zgodilo (videz se ravno ustvarja · noben aktiven ljubljenček ga ne uporablja · AI izklopljen).
8. **Pasma brez seznama barv** (npr. nova pasma, ki ji je ta korak manjkal) ostane pri skupnem videzu — barva po utežeh videza, brez izbirnika; nikoli nenamerno unikatna (dražja). Korak je v `docs/engineering/ADD_BREED_RUNBOOK.md`.
9. **Izbirnik ima vnaprej izbrano prvo (najpogostejšo) barvo** — *predlog Claude, David lahko spremeni*.

**Od M5-T08 (21. 10. 2026):** skupni videz ima za vsako obdobje tudi poze stanj, zanke in prehoda — poln nabor 5,38 $ za prvega kupca pasme + barve + obdobja, za vsakega naslednjega 0 $ (APP_REFINEMENT_SPEC §7, §14).

**Strošek (za Davida, po cenah v `config/media.php`):** ena slika 0,15 $, en 5-sekundni video 0,56 $ (0,112 $/s). Celoten nabor ene življenjske dobe plačljivega psa = 1 slika + 6 videov ≈ **3,51 $**; v 12 tednih pes zamenja eno do dve obdobji → unikatno **≈ 3,5–7 $ na kupljenega psa**. S skupnimi mediji je to **enkraten** strošek na pasmo + barvo + obdobje; vsak naslednji nakup iste kombinacije stane **0 $**. Zgornja meja, če bi bila kdaj uporabljena prav vsaka kombinacija: 180 barv × do 4 obdobja × 3,51 $ ≈ 2.500 $ — v praksi le priljubljene kombinacije.

**Odprto (za Davida):** ali naj dva otroka iste družine z isto pasmo in barvo dobita različna videza (zdaj: enak videz — za razlikovanje je ime ljubljenčka).

## Part 2 — Technical design

**Colour list.** `config/breed_colours.php`: per paid breed a list `{key, value, en, sl}` — `value` is the exact `coat_color` option of `config/breed_appearance.php` (the DNA trait the prompts use), `key` a stable slug sent by the app, `en`/`sl` short picker labels. `BreedColourSharedMediaTest` keeps the list identical to the appearance options (same values, same order). 40 breeds (34 dogs, 6 cats), 180 colours (the Belgian Malinois added in M5-R10-38). Free breeds have no entry. **Fail safe:** a paid breed without a list still shares — `BreedColourCatalogue::lookColours()` derives keys `auto-<sha256 prefix>` from its appearance `coat_color` options (no picker, `pets.coat_colour` null); `SharedBreedLookService::appliesTo()` does not depend on the list. Service: `App\Services\Media\BreedColourCatalogue`.

**Picker API.** `GET /api/breeds` → every breed has `colours: [{key, label}]` (label in the request language, `Accept-Language` en | sl; `[]` for free breeds; one entry = nothing to choose). `POST /api/parent/generate-pin` accepts optional `colour` (needs `breed`; validation 422 otherwise); a key the breed does not have → 422 `colour_invalid`; stored on the PIN (`pet_profile.colour`, `PetProfileChoice::$colour`). Omitted → `null` (sampled by weight).

**On the pet.** `pets.coat_colour` = the colour key (chosen or sampled) for paid breeds; null for free breeds and pets created before M5-R21. The DNA keeps the full trait (`pet_dna.traits.coat_color`).

**Shared-media key = (breed, colour).** The prompts depend only on breed + DNA traits + life stage + state (no sex, no origin, no name — `PetAppearancePrompt`). A shared look fixes every trait once (`PetDnaService::sample` with `coat_color` fixed, other traits sampled once per look) and its media rows are per life stage + state, so (breed, colour) is the whole key. Storage: `pet_looks` row with `colour_key` (null = a free pool look), unique `(breed_type, colour_key)` (partial) + the existing unique `(breed_type, pool_index)` and `(breed_type, trait_fingerprint)`.

**Switch.** `app_settings.unique_pet_media = {enabled: bool}`, no row = **false** (shared). `AppSettingsService::uniquePetMedia()` (cached 60 s) / `updateUniquePetMedia()` (audited in `app_setting_changes`). Filament: Feature switches → "AI media of paid breeds" toggle (superadmin).

**Creation** (`PairingService::createPet`, inside the pairing transaction, no external HTTP): free breed + profile → pool (M4-10, unchanged); paid breed + profile + switch off → `SharedBreedLookService::assignTo($pet, $colourKey)`; otherwise (switch on, or a legacy-profile pet without arrival age) → unique DNA v2 with the chosen colour fixed (`PetDnaService::forNewPet($pet, null, ['coat_color' => …])`). A colour is used only for the breed it was chosen for (a breed the plan refused falls back to the free breed without colour).

**Generation and reuse.** Paid shared pets use the existing shared-look pipeline of `PetMediaService` (M4-10): the pet's slot links the look's stored row of its stage (0 $), or waits (`awaitLook`) while one worker claims and generates the look row; `StorePetMedia` fans the stored file out to every waiting pet. Paid pets are entitled to all 6 state videos (`MediaEntitlementService`); each look video row is generated once per (state, stage).

**Concurrency.** Look creation: `insertOrIgnore` + re-read by (breed, colour) — two families racing end up with one look; an index taken by another look (reordered list) → next free index (3 attempts); taken trait fingerprints are excluded. Media: row claim (`pending|failed → running`) — one fal call, the others wait and are linked on store; failures fan out to the waiting pets and are retried like pool looks (sweep / daily retry).

**Regenerate.** `PetMediaService::regenerateLook(PetLook): Results\LookRegeneration` (Filament "Shared looks" → Regenerate, superadmin; outcomes `queued` / `busy` / `no_active_pets` / `ai_off`, each its own notification): bumps the generation of the look rows **of the life stages its active pets are at** (other stages stay READY, so a later-stage edit still grows from the older-stage image), resets those pets' image slots (old file stays servable) and re-queues them; busy only when a row of those stages is `running` with a live claim or has a fal `request_id` (a `pending` row without a request or a dead claim does not block); the first pet regenerates the look image, the videos follow from the new image (a look video is only submitted from a READY look image). Same DNA / seed — the look is never rewritten. Old look files stay on disk (debt: cleanup).

**Media per state (M5-T08, 2026-10-21).** A shared look also holds, per life stage, the state KEY IMAGES (`kind keyframe`, Nano Banana Pro Edit of the look's stage image), the state LOOPS (Kling 3.0 Pro from the key image; since 2026-10-23 start frame only, recipe `start_only` — the old `recipe loop` with start = end = key image froze and is remade by `media:regenerate-frozen`, once per look row) and the TRANSITIONS (`kind transition`, idle ↔ sleeping). The first buyer of a breed + colour + stage makes them (5,38 $ for the full set — reference image, 5 key images, 6 loops, 2 transitions; a puppy with behaviour events 6,80 $), every later buyer links them for **0 $** (`keyframeFromLook`, `transitionFromLook`, the same claims / fan-out). A look whose clips were made before M5-T08 (`recipe` null) remakes them once as loops when the first state-media pet needs them; every pet of the look then shows the new file (no extra cost). Existing pets get no key images / transitions automatically (`pets.state_media_version` 1) — only with a new purchase or the admin "Generate missing".

**Unchanged.** Existing pets; free breeds; legacy-profile pets; DNA v1; the AI Lab.
