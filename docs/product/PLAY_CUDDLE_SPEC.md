# PetPrep — Igra in crkljanje (M5-R05)

> **Status:** osnutek speca, 8. 10. 2026 (Claude). **Odločeno (David 7. 10. 2026, DECISIONS):** občasni dogodki, ko je kuža preskrbljen — da ima otrok kaj početi, ko je vse narejeno; vplivajo **samo na razpoloženje in video** (nikoli na točke, Care Score, rutine, bolezen ali game over); **samo v plačanem izzivu**; oblika: **igra = kratka mini-igra metanja žoge**, **crkljanje = božanje kužka s prstom**.
> Vse drugo v tem dokumentu je **Claudov predlog** in je označeno z **(D)** = čaka Davidovo potrditev. Ko David odgovori, se pravila prenesejo v `PRODUCT_SPEC.md` (§5, §8, §10), odločitve v `DECISIONS.md`, naloga v `ROADMAP.md` M5-R05. Kanonično za kodo šele po potrditvi.
> **Temeljno pravilo:** igra je **nagrada brez kazni**. Če otrok dogodek prezre, se ne zgodi nič slabega — nobenega opomnika, nobene krivde, nobene številke, ki pade.

## 0. Odprta vprašanja za Davida

| # | Vprašanje | Priporočilo (Claude) |
|---|---|---|
| Q1 | »Samo v plačanem izzivu« — velja tudi med **7-dnevnim preizkusom** izziva? | **Da:** izziv v preizkusu in plačan (nakup, grandfathered, odklep admina); **ne** brezplačni mešanček in **ne** med zaklepom do plačila. Preizkus je okus izziva — igra pokaže, kaj družina kupi. |
| Q2 | Ali dobijo igro tudi **obstoječi (legacy) psi** brez profila? | **Ne** — kot vedenje in šolanje (legacy psi ostanejo na starih pravilih za vedno). Preizkus z novim psom na izzivu (Q1) zadošča za test na telefonu. |
| Q3 | **Pogostost in trajanje:** koliko dogodkov na dan in kako dolgo čaka? | **2 na dan na psa — 1 igra + 1 crkljanje**, ob naključni minuti izven tihih ur med 07:00 in 20:00 po času družine, vsaj 3 h narazen; dogodek čaka **2 uri** (prej se konča ob začetku tihih ur). Izven dogodkov otrok igre ne more začeti sam. |
| Q4 | Kdaj je kuža **»preskrbljen«**? | Ni odprtega nereda (kakec, luža, copat), lakota in žeja kažeta **> 30 %**, ni zaklepa (hard stop, veterinar, game over, čaka na starša, pred rojstvom), niso tihe ure. **Energija (sprehod) ni pogoj** — sicer bi kuža vse dopoldne čakal na sprehod. Če pogoj med čakanjem ne velja, se povabilo skrije in se vrne, ko spet velja (do konca 2 ur). |
| Q5 | Kaj naredi **razpoloženje**? | Brez nove številke ali merilnika: po igri / crkljanju je kuža **30 minut »vesel«** — predvaja se video `playing` (če ga ima), sicer običajni video + srčki v aplikaciji, in kratek napis »Kuža je vesel«. Lakota, žeja, nered ali zaklep imajo vedno prednost. |
| Q6 | Ali potrebujemo **nove AI videe**? | **Ne zdaj.** Uporabimo obstoječi `playing` (je že v polnem naboru plačanega izziva). Nov video `cuddle` (crkljanje) bi stal ≈ 0,56 $ na psa na življenjsko obdobje (Kling 3.0 Pro, 5 s × 0,112 $/s) — predlagam za kasneje / žetone (M4-09). |
| Q7 | Kaj vidi **starš** in ali gre **push**? | **Starš:** vrstica v časovnici (»Igra z žogo · {otrok}«, »Crkljanje · {otrok}«) in na kartici psa »Igra in crkljanje danes: 1 / 2«; nič v semaforju, Care Score, rutinah ali poročilu. **Push: nobenega** (ne vabimo otroka v aplikacijo brez potrebe skrbi; tudi nobenega med tihimi urami). |
| Q8 | **Skupni pes** več otrok: koliko dogodkov in kdo ga reši? | Dogodek je **psov**: ostane 2 na dan ne glede na število otrok; vidijo ga vsi otroci psa (ki so podpisali pogodbo), opravi ga **prvi**; ostalim se povabilo tiho zapre (starš v časovnici vidi, kdo ga je opravil). |

## 1. Namen
- Ko je otrok vse naredil (hrana, voda, čiščenje, sprehod), v aplikaciji ni ničesar za početi. Pravi pes si tedaj želi **pozornosti**: igre in bližine. To je del skrbi, ki je ne merimo s točkami.
- Igra ostane **pozitivna**: ni roka, ni zamude, ni opomnika. Uči, da pes ni le seznam opravil.
- Ne sme postati »farma« za čas pred zaslonom: malo dogodkov na dan, kratki (pod pol minute), brez pusha.

## 2. Kdo ima igro in crkljanje
| Pes | Igra in crkljanje |
|---|---|
| 12-tedenski izziv, **plačan** (nakup, grandfathered, admin) | ✅ (odločeno) |
| 12-tedenski izziv v **preizkusu** | ✅ **(D)** Q1 |
| izziv, ki **čaka na plačilo** (zaklep) | ❌ (zaklep ustavi vse, PAYMENTS_SPEC P3) |
| **brezplačni mešanček** | ❌ (odločeno: samo izziv) |
| **legacy** pes (brez profila) | ❌ **(D)** Q2 |

Brez zastavice `features` iz aplikacije: ker igra nima posledic, stara različica aplikacije dogodek preprosto ne pokaže in ta se tiho izteče (**(D)**, glej §9).

## 3. Kdaj se dogodek pojavi
- **2 dogodka na lokalni dan na psa: 1 igra z žogo + 1 crkljanje** **(D)** Q3. Vrstni red naključen.
- Čas: naključna cela minuta **izven tihih ur** (šola, spanje) v **dnevnem pasu 07:00–20:00** po času družine **(D)**; dogodka sta **vsaj 3 h narazen** **(D)**. Če je netihega časa premalo (npr. dolga šola + zgodnje spanje), samo en dogodek ali nobeden.
- Nikoli: med tihimi urami, pred rojstvom (pogodba), med hard stopom, pri veterinarju (bolezen), po game overu, med zaklepom do plačila. Dogodek, ki bi padel v tak čas, odpade (se ne nadoknadi) — kot pri kakcu.
- **Kuža mora biti preskrbljen** **(D)** Q4: ni odprtega nereda, lakota in žeja > 30 %. Energija ni pogoj. Če ob času dogodka pogoj ne velja, dogodek **čaka skrit** in se pokaže, ko pogoj velja — v okviru istih 2 ur.
- Dogodek **čaka 2 uri** od svojega časa **(D)**; če se vmes začnejo tihe ure, se konča ob njihovem začetku.
- Otrok ne more sam začeti igre izven dogodka **(D)** (tako ostane »kuža si želi«, ne gumb za neskončno igro).

## 4. Kaj vidi in naredi otrok
### 4.1 Povabilo
- Nad gumbi (kot »Šola« in »Obroki danes«) se pokaže prijazna kartica s kužkom: igra — »Kuža ti prinaša žogo. Se igrava?« z gumbom **»Igraj se«**; crkljanje — »Kuža se stisne k tebi. Ga pobožaš?« z gumbom **»Pobožaj«**. Brez odštevanja (ni pritiska).
- Kartica se skrije med sprehodom, šolo, čiščenjem in ko pogoj iz §3 ne velja. Gumb »×« jo skrije do konca tega dogodka (prezreti je v redu).

### 4.2 Igra z žogo (mini-igra, ~20 s)
- Celozaslonska plast v slogu »Šole« (temno steklo). Spodaj žoga; otrok jo **povleče s prstom navzgor in spusti** (»vrže«). Kuža steče po žogo in jo prinese nazaj (premium: video `playing`; preizkus / brez videa: obstoječi video + animirana žoga in ilustracija teka).
- **3 meti** = konec. Vsak met šteje, ne glede na moč ali smer — ni zgrešenega meta, ni točk.
- **Dostopnost:** gumb **»Vrzi žogo«** namesto potega (VoiceOver / TalkBack: dvojni dotik); pri vklopljenem »zmanjšaj gibanje« žoga ne leti po zaslonu, samo kratek prelivni prikaz. Ni časovne omejitve med meti.
- Konec: »Hvala za igro! Kuža je ves vesel.«

### 4.3 Crkljanje (božanje, ~10 s)
- Ista plast; otrok s prstom **gladi po kužku** (poteg čez osrednji del zaslona). Ob vsakem potegu se pojavi srček; po **5 potegih** (vsak vsaj ~60 pt, v katerokoli smer) je konec **(D)**.
- **Dostopnost:** gumb **»Drži in pobožaj«** — držanje 3 s nadomesti potege (bralnik zaslona: dejanje »Pobožaj«); »zmanjšaj gibanje« → srčki brez letenja.
- Konec: »Kuža uživa. Hvala za crkljanje!«
- Brez zvoka in brez haptike (`expo-haptics` ni v projektu — nov nativni modul bi zahteval nov build; lahko kasneje).

### 4.4 Če otrok dogodek prezre
Nič. Povabilo po 2 urah tiho izgine. Brez besedila »zamudil si«, brez opomnika, brez vpliva na karkoli. Starš vidi le »Igra in crkljanje danes: 0 / 2« **(D)**.

## 5. Razpoloženje (mood)
- **Danes razpoloženja kot vrednosti ni.** Stanje videa (`pet_state`) izhaja iz merilnikov: `playing`, ko je energija ≥ 80 % in lakota ≥ 60 %, sicer `idle` / `hungry` / `low_energy` / `sleeping` / `sick`.
- **Predlog — minimalno** **(D)** Q5: po končani igri ali crkljanju je kuža **30 minut »vesel«** (`happy_until`). V tem času aplikacija predvaja video `playing`, če ga pes ima, sicer svoj običajni video z mehkimi srčki, in pokaže kratek napis »Kuža je vesel«.
- **Prednost** (od najvišje): zaklep › prizor vedenja (luža / copat) › bolan / spi (tihe ure) / lačen / utrujen › **vesel** › `playing` / `idle`. Vesel kuža nikoli ne skrije potrebe.
- Brez merilnika, brez številke, brez zgodovine razpoloženja. Ne vpliva na točke, Care Score, rutine, upadanje, bolezen ali game over (odločeno).

## 6. Mediji in strošek
- **Brez novih generacij AI** **(D)** Q6. Video `playing` je že v polnem naboru (`config/media.php` → `video_states.full`), ki ga dobi izziv, plačan z nakupom (PAYMENTS_SPEC P6). Pes v preizkusu ali grandfathered ima osnovni nabor (`idle`, `sleeping`) — aplikacija uporabi `idle` + animacijo v aplikaciji (žoga, srčki); obstoječa veriga nadomestkov (`videoChain`: `playing` → `idle`) to že zna.
- **Možnost za kasneje:** novo stanje `cuddle` (pes leži na hrbtu / se stiska, mirno). Strošek: Kling 3.0 Pro 5 s × 0,112 $/s = **≈ 0,56 $ na psa na življenjsko obdobje**; mladiček Border Collie v izzivu gre skozi 2 obdobji → ≈ 1,12 $ na psa (polni nabor bi zrasel s ≈ 4,63 $ na ≈ 5,19 $). Predlagam šele z žetoni (M4-09).

## 7. Kaj vidi starš
- **Časovnica** **(D)** Q7: »♥ 16:20 Igra z žogo · {vzdevek otroka}«, »♥ 18:05 Crkljanje · {vzdevek otroka}« (obstoječi seznam zadnjih 20 dejanj).
- **Kartica psa:** »Igra in crkljanje danes: 1 / 2« (opravljeno / ponujeno do zdaj) **(D)**.
- **Ne:** semafor, Care Score, rutine, tedenski graf, poročilo, certifikat, izvoz za oceno.
- **Brezplačni / legacy pes:** nič (dogodkov nima).

## 8. Obvestila
- **Brez push obvestil** **(D)** Q7 — ne otroku ne staršu. Dogodek vidi le otrok, ki odpre aplikacijo. Razlog: aplikacija otroka kliče samo zaradi skrbi; igra ni obveznost.
- Če bi David vseeno želel en push: največ **en na dan**, samo izven tihih ur, navadni (brez zvoka v ospredju), besedilo »Kuža ima žogo zate« — ni priporočeno.

## 9. Skupni pes, več psov, stare različice
- **Skupni pes** **(D)** Q8: dogodek pripada psu; vidijo ga vsi otroci psa, ki so podpisali pogodbo; opravi ga prvi. Drugim se povabilo zapre (prek `PetUpdated`). Dva dogodka na dan ne glede na število otrok.
- **Več psov v družini:** vsak pes ima svoje dogodke.
- **Stara različica aplikacije:** dodatna polja ignorira; dogodek se izteče brez posledic. **Starševska aplikacija** brez novih oznak bi v časovnici pokazala surovo vrsto (`played_with_pet`) — sprejemljivo, ker aplikacija še ni v trgovinah; nove oznake gredo v isti PR **(D)**.

## 10. Besedila (i18n, otrok — prijazen ton)
| Ključ | SL | EN |
|---|---|---|
| `play.invite` | Kuža ti prinaša žogo. Se igrava? | Your pup brought you a ball. Want to play? |
| `play.start` | Igraj se | Play |
| `play.hint` | Povleci žogo navzgor, da jo vržeš. | Swipe the ball up to throw it. |
| `play.throwButton` | Vrzi žogo | Throw the ball |
| `play.throwA11y` | Vrzi žogo kužku | Throw the ball for your pup |
| `play.progress` | Met {n} od 3 | Throw {n} of 3 |
| `play.done` | Hvala za igro! Kuža je ves vesel. | Thanks for playing! Your pup is so happy. |
| `cuddle.invite` | Kuža se stisne k tebi. Ga pobožaš? | Your pup snuggles up to you. Give them a cuddle? |
| `cuddle.start` | Pobožaj | Cuddle |
| `cuddle.hint` | S prstom nežno pobožaj kužka. | Gently stroke your pup with your finger. |
| `cuddle.holdButton` | Drži in pobožaj | Hold to cuddle |
| `cuddle.done` | Kuža uživa. Hvala za crkljanje! | Your pup loves it. Thanks for the cuddles! |
| `mood.happy` | Kuža je vesel | Your pup is happy |
| `moment.dismiss` | Mogoče kasneje | Maybe later |
| `parent.timeline.play` | Igra z žogo · {child} | Ball game · {child} |
| `parent.timeline.cuddle` | Crkljanje · {child} | Cuddles · {child} |
| `parent.today` | Igra in crkljanje danes: {done} / {offered} | Play and cuddles today: {done} / {offered} |

Brez besedil za zamujeno / prezrto (namenoma). Brez imen otrok v otroški aplikaciji.

## 11. Analitika in zasebnost
- **Brez analitike** in brez SDK-jev; podatek je le vrstica v bazi (kot ostala dejanja) za časovnico staršev. Brez novih osebnih podatkov otroka (zapišemo samo, kateri skrbnik je dogodek opravil — kot pri hranjenju).
- Dogodki so v izvozu podatkov družine in se izbrišejo s psom.

---

## 12. Technical design (English)

> All rules below are **proposals (D)** until David answers §0; the decided parts are: occasional events while the pet is cared for, mood / video only, paid challenge only, play = ball mini-game, cuddle = swipe.

### 12.1 Eligibility
`PlayMomentService::eligible(Pet $pet): bool` =
`plan = challenge` ∧ `challengeStatus() ∈ {trial, paid}` (Q1) ∧ `! awaitsPayment()` ∧ `! isLegacyProfile()` (Q2) ∧ `! isUnborn()` ∧ not game over / inactive.
No `features` flag: the feature has no consequences, so pets of any app build are scheduled; an old build ignores the payload.

### 12.2 Scheduling (server authority)
- In the tick (`pets:process-decay`), after `BehaviourEventService::ensureChewingScheduled`: `PlayMomentService::ensureScheduled($pet, $from, $now, $quiet)` decides every family-local day not yet decided (`pets.moments_scheduled_through`, like `behaviour_scheduled_through`). Same outage rule as chewing: after a scheduler outage (`BehaviourEventService::isOutage`) only today is decided and moments whose time has passed are written as `skipped`.
- Per day: seeded RNG (`hash('sha256', salt.'|moment|'.pet.'|'.date)`, Xoshiro256**, as `randomizerFor`), shuffled kinds `[play, cuddle]`, two whole minutes inside the non-quiet part of the 07:00–20:00 local band with ≥ 3 h between them (rejection sampling with a bounded number of draws; if no valid pair → one moment; if no non-quiet minute → none). Config keys (Filament-editable, not `breed_stage_params` — no source exists): `play.moments_per_day = 2`, `play.day_band = [07:00, 20:00]`, `play.min_gap_minutes = 180`, `play.open_minutes = 120`, `play.happy_minutes = 30`.
- `expires_at = min(scheduled_at + open_minutes, start of the next quiet period)`.
- A moment whose `scheduled_at` falls into a freeze (hard stop, illness, game over, payment lock) or before birth → `skipped` by the tick (as poop / chewing). Quiet hours changed later → re-checked at offer time (never offered inside quiet hours).
- Expiry: the tick marks `pending` moments with `expires_at ≤ now` as `expired`. No other effect.

### 12.3 Offer (what "visible" means)
`PlayMomentService::offerable(Pet $pet, CarbonInterface $now): ?PetMoment` = the oldest `pending` moment with `scheduled_at ≤ now < expires_at`, when `eligible()` ∧ not quiet now ∧ not frozen ∧ no open hygiene event (`displayMetric('hygiene_level') > 0`) ∧ `displayMetric('hunger_level') > 30` ∧ `displayMetric('thirst_level') > 30` (Q4). Evaluated on read (state, broadcast), so the invitation hides / returns as needs change without extra writes. When the condition flips on a tick, the tick dispatches `PetUpdated('moment')` so open apps refresh (one broadcast per flip, not per tick).

### 12.4 Data model
New table **`pet_moments`** (not `pet_hygiene_events`: every hygiene row is a `clean` routine that drops hygiene to 0 and is read by `RoutineLedgerService` — reusing it would leak into routines, escalation and Care Score):

| column | type | notes |
|---|---|---|
| `id` | bigint PK | |
| `pet_id` | FK pets, cascade on delete | |
| `kind` | string, CHECK `play`/`cuddle` | |
| `local_date` | date | family-local day |
| `scheduled_at` | timestamptz (UTC) | |
| `expires_at` | timestamptz (UTC) | |
| `status` | string, CHECK `pending`/`done`/`expired`/`skipped` | |
| `completed_by` | FK users, null on delete | the child who did it |
| `completed_at` | timestamptz null | |
| timestamps | | |

Unique `(pet_id, local_date, kind)`; index `(pet_id, status, scheduled_at)`. `pets.happy_until` (timestamptz null), `pets.moments_scheduled_through` (date null, hidden). Included in `AccountExportService` (pet section, no other users' ids beyond what activities already export); deleted with the pet.

### 12.5 API (additive)
- **`POST /api/child/pet/moments/{moment}/complete`** — FormRequest (`kind` must match the row, optional `method: gesture|button` ignored except logging-free validation). Policy: the child is a caretaker of the moment's pet and signed their contract. In one `DB::transaction` with the pet row lock: re-check `offerable()`; set `status = done`, `completed_by`, `completed_at`; `pets.happy_until = now + happy_minutes`; write activity `played_with_pet` / `cuddled_pet`; dispatch `PetUpdated('moment_done')` after commit. Returns the child state (as other actions).
  - Already `done` by **this** child → 200 (idempotent, no second activity); done by a sibling / `expired` / `skipped` → 409 `moment_closed`; not offerable now (mess appeared, hungry) → 422 `moment_not_available`; locks → existing 423 / `contract_required` answers.
  - No score, no anti-cheat (as training's accepted trust model, David 2026-10-06): the client reports completion; the server only checks that the moment is open.
- **Child state + `PetUpdated` payload:** new typed `PlayPayload` (Scramble-documented):
  ```json
  "play": {
    "moment": { "id": 12, "kind": "play", "expires_at": "2026-10-08T17:20:00+02:00" } | null,
    "mood": { "happy_until": "2026-10-08T16:50:00+02:00" | null, "scene": "playing" | null }
  }
  ```
  `play` is `null` for ineligible pets. `mood.scene = "playing"` while `now < happy_until` and the pet is not locked, not in a behaviour scene and `pet_state ∈ {idle, playing}`. `behaviour.scene` and `pet_state` are unchanged (old builds unaffected).
- **Parent dashboard (per pet):** `play_today: { offered: int, done: int } | null` — offered = moments of today with `scheduled_at ≤ now` that were not `skipped`.
- **Timeline:** activities `played_with_pet`, `cuddled_pet` (new `ActivityType` cases) in the existing pet activity list with the child's nickname.
- Regenerate `mobile/src/api/schema.ts`; ARCHITECTURE §3 + DIAGRAMS (tick sequence).

### 12.6 Isolation from scoring (must hold, tested)
- `RoutineLedgerService`, `CareScoreService`, `EscalationService`, `HardStopService`, semafor, weekly chart, reports and certificate ignore `pet_moments` and the two new activity types (explicit allow-lists, not "all activities").
- `happy_until` never changes metrics, decay, escalation timers, illness or game over.
- Pest: a pet that ignores every moment for 7 days has identical Care Score / routines to a pet without the feature; completing moments changes neither.

### 12.7 Mobile
- `modules/play/` — `play.ts` (payload parsing, strings, `shouldShowInvite(view)`, gesture thresholds), `PlayOverlay.tsx` (ball: RN `PanResponder` — no new native module, `react-native-gesture-handler` is not a dependency — upward release with a distance / velocity threshold; cuddle: pan over the pet area, 5 strokes ≥ 60 pt; button alternatives; `AccessibilityInfo.isReduceMotionEnabled`), `MomentChip.tsx` above the dock (same column as `TrainingChip` / meals row; never under the dock).
- Video: `selectMediaSource` gets `mood.scene` between the behaviour scene and the pet state; chain `playing → idle` already exists; no new `PetState`.
- Invitation hidden while walk / training / cleaning overlays are open; the overlay cannot complete twice (one request, retry on network error, 409 → close quietly).
- Strings through i18n (SL + EN, §10); Jest for `play.ts` + HUD tests (`ChildHudScreen.play.test.tsx`) with fake timers.

### 12.8 Tests (Definition of done)
Pest with `Carbon::setTestNow()`: eligibility matrix (free, trial, paid, payment lock, legacy, unborn); schedule inside the band, outside quiet hours, ≥ 3 h gap, deterministic per seed, one / zero moments on short days; freeze → skipped; expiry; outage; offerable flips with hunger / mess; complete (happy path, idempotent, sibling 409, expired 409, not available 422, lock 423); shared pet broadcast; scoring isolation (§12.6); export / delete cascade.

### 12.9 Cost
Zero AI cost with the recommended option (existing `playing` video). Optional `cuddle` state video: ≈ 0.56 USD per pet per life stage (Kling 3.0 Pro, 5 s × 0.112 USD/s, `config/media.php`), only for purchase-paid pets, counted against the existing AI budget caps.
