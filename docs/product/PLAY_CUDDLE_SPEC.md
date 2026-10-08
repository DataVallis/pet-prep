# PetPrep — Igra in crkljanje (M5-R05)

> **Status:** spec, 8. 10. 2026. **Odločil David 7. 10. 2026:** igra in crkljanje vplivata **samo na razpoloženje in video** (nikoli na točke, Care Score, rutine, bolezen ali game over); **samo v plačanem izzivu**; **igra = kratka mini-igra metanja žoge**, **crkljanje = božanje kužka s prstom**. **David 8. 10. 2026 09:19 (odgovori na vprašanja Q1–Q8):** tudi v preizkusu izziva (Q1; od M3-13, 10:28, preizkusa ni več — velja: plačan izziv ali tekoči stari preizkus / ko je stikalo plačil izklopljeno); **otrok se lahko igra in crklja kadarkoli sam** — vabila so le spodbuda (Q3); vabilo samo, ko je opravljen današnji sprehod (Q4); razpoloženje = 30 min »vesel« (Q5); starš vidi časovnico in dnevno število, brez pusha in brez točk (Q7); pri skupnem psu se vsak otrok igra prosto, vabilo opravi prvi (Q8).
> Kar ni Davidova odločitev, je **Claudov predlog**, označen z **(D)** = čaka Davidovo potrditev. Odločena pravila so tudi v `PRODUCT_SPEC.md` §5 in §8.
> **Temeljno pravilo:** igra je **nagrada brez kazni**. Če otrok vabilo prezre ali se ne igra, se ne zgodi nič slabega — nobenega opomnika, nobene krivde, nobene številke, ki pade.

## 0. Vprašanja za Davida

| # | Vprašanje | Odgovor / priporočilo |
|---|---|---|
| Q1 | Velja »samo v plačanem izzivu« tudi med **7-dnevnim preizkusom**? | ✅ **David 8. 10.: da** — izziv v preizkusu in plačan (nakup, grandfathered, odklep admina); **ne** brezplačni mešanček in **ne** med zaklepom do plačila. *M3-13 (David 8. 10. 2026, 10:28): novi psi preizkusa nimajo več — »v preizkusu« velja le še za pse iz časa pred M3-13 do konca njihovega preizkusa; `challengeStatus() = trial` ostane zato v pogoju.* |
| Q2 | Ali dobijo igro tudi **legacy psi**? *Razlaga: legacy psi so psi, ustvarjeni pred izbiro kužka (M5-R04) — zanje ne vemo starosti ne izvora, zato zanje veljajo stara pravila (brez vedenja in šolanja).* | ✅ **Brezpredmetno — rešeno z resetom baze** (David 8. 10. 2026 13:50, DEPLOYMENT.md D17): po resetu baze legacy psov v produkciji ni več. Pravilo »legacy pes se ne igra« (`! isLegacyProfile()`) ostane v kodi kot varovalka. ~~Priporočilo: **ne** (kot vedenje in šolanje).~~ |
| Q3 | Pogostost | ✅ **David 8. 10.: otrok se lahko igra in crklja kadarkoli sam** (vedno mora biti kaj za početi). Vabila ostanejo kot spodbuda: **2 na dan na psa — 1 igra + 1 crkljanje** **(D)**, čakajo 2 h **(D)**. Prosta igra brez omejitve števila, brez točk. |
| Q4 | Kdaj je kuža »preskrbljen« (za vabilo)? | ✅ **David 8. 10.: tudi današnji sprehod mora biti opravljen.** Uporabimo obstoječi prag rutine »Sprehod« (PRODUCT_SPEC §11.1): **današnji koraki ≥ dnevni cilj** (energija kaže 100 %). Poleg tega **(D)**: ni nereda, lakota in žeja > 30 %, ni zaklepa, niso tihe ure. |
| Q5 | Razpoloženje | ✅ **David 8. 10.: kot predlagano** — 30 min »vesel«, brez številke in merilnika. |
| Q6 | Novi AI videi? | ⏳ **odprto** — David sprašuje o interaktivnem 3D psu (v slogu Talking Tom); odgovor ločeno, glej §6.1. **Ta spec gradi na obstoječem videu `playing` + srčkih v aplikaciji**; novih AI generacij ni. |
| Q7 | Starš in push | ✅ **David 8. 10.: kot predlagano** — vrstica v časovnici, na kartici psa **dnevno število** iger in crkljanj (ne »1 / 2«), **brez pusha**, brez točk. |
| Q8 | Skupni pes | ✅ **David 8. 10.:** vsak otrok se lahko igra in crklja prosto; vabila so **psova**, opravi jih **prvi** otrok. |

**Še odprti predlogi (D):** število vabil (2 / dan) in njihovo trajanje (2 h); dnevni pas 07–20; prosta igra ni dovoljena med tihimi urami (§3.1); združevanje vrstic v časovnici (§7); ponovljen pritisk v 10 s = ena igra (§12.5).

## 1. Namen
- Ko je otrok vse naredil (hrana, voda, čiščenje, sprehod), v aplikaciji ni ničesar za početi. Pravi pes si tedaj želi **pozornosti**: igre in bližine. To je del skrbi, ki je ne merimo s točkami.
- Igra ostane **pozitivna**: ni roka, ni zamude, ni opomnika. Uči, da pes ni le seznam opravil.
- Mini-igre so kratke (pod pol minute), brez pusha — aplikacija otroka ne kliče zaradi igre.

## 2. Kdo ima igro in crkljanje
| Pes | Igra in crkljanje |
|---|---|
| 12-tedenski izziv, **plačan** (nakup, grandfathered, admin) | ✅ (David 7. 10.) |
| 12-tedenski izziv v **preizkusu** (samo psi iz časa pred M3-13) | ✅ (David 8. 10., Q1) |
| izziv, ki **čaka na plačilo** (zaklep) | ❌ (zaklep ustavi vse, PAYMENTS_SPEC P3) |
| **brezplačni mešanček** | ❌ (David 7. 10.: samo izziv) |
| **legacy** pes (ustvarjen pred izbiro kužka) | ❌ (Q2 — brezpredmetno po resetu baze 8. 10. 2026: legacy psov v produkciji ni več; pravilo ostane kot varovalka) |

Brez zastavice `features` iz aplikacije: igra nima posledic, zato stara različica aplikacije vabila preprosto ne pokaže in to se tiho izteče **(D)**, glej §9.

## 3. Kdaj se lahko otrok igra
### 3.1 Prosta igra (kadarkoli — David 8. 10.)
- Otrok lahko **kadarkoli** začne igro z žogo ali crkljanje, brez omejitve števila (David 8. 10., Q3).
- **Ni mogoče** **(D)**: pred rojstvom (pogodba), med hard stopom, pri veterinarju (bolezen), po game overu, med zaklepom do plačila, **med tihimi urami** (kuža spi — tudi med šolo). Med neredom je zaslon pokrit z umazanijo in vse akcije čakajo na čiščenje (obstoječe pravilo PRODUCT_SPEC §8) — to velja tudi za igro.
- Lakota, žeja in sprehod **niso pogoj** za prosto igro (igra ni nagrada za opravljene naloge, ampak čas s kužkom).
- Brez točk, brez rutine, brez pavze med igrami. Če bi se v praksi pokazalo, da otrok igro »vrti«, je možen mehak premor (npr. 1 min po igri) — **zdaj ga ni** **(D)**.

### 3.2 Vabila (spodbuda)
- **2 vabili na lokalni dan na psa: 1 igra z žogo + 1 crkljanje** **(D)**. Vrstni red naključen.
- Čas: naključna cela minuta **izven tihih ur** v **dnevnem pasu 07:00–20:00** po času družine **(D)**; vsaj **3 h narazen** **(D)**. Če je netihega časa premalo, eno vabilo ali nobeno.
- Nikoli med tihimi urami, pred rojstvom, med hard stopom, pri veterinarju, po game overu ali med zaklepom do plačila. Vabilo, ki bi padlo v tak čas, odpade (se ne nadoknadi) — kot kakec.
- **Kuža mora biti preskrbljen** (David 8. 10., Q4): **današnji sprehod je opravljen** — koraki ≥ dnevni cilj (energija kaže 100 %; isti prag kot rutina »Sprehod«, PRODUCT_SPEC §11.1) — in **(D)** ni odprtega nereda, lakota in žeja kažeta > 30 %. Če pogoj ob času vabila ne velja, vabilo **čaka skrito** in se pokaže, ko začne veljati — v okviru istih 2 ur. Na dan, ko otrok cilja sprehoda ne doseže, vabil ni (prosta igra je še vedno mogoča).
- Vabilo čaka **2 uri** **(D)**; če se vmes začnejo tihe ure, se konča ob njihovem začetku.
- Vabilo se opravi s **katerokoli igro iste vrste** (gumb na vabilu ali prosta igra) v času, ko je vabilo vidno.

## 4. Kaj vidi in naredi otrok
### 4.1 Gumb »Igra« in vabilo
- Nad gumbi (v istem stolpcu kot »Šola« in »Obroki danes«) je gumb **»Igra«**, ki odpre izbiro **»Žoga«** / **»Crkljanje«**. Gumb je **vedno viden**; ko prosta igra ni mogoča (§3.1), je **siv z razlago** (npr. »Kuža spi. Igrata se, ko se zbudi.«) — David 8. 10. 2026 13:47.
- **Vabilo:** ko je odprto, se gumb »Igra« spremeni v prijazno kartico: igra — »Kuža ti prinaša žogo. Se igrava?« z gumbom **»Igraj se«**; crkljanje — »Kuža se stisne k tebi. Ga pobožaš?« z gumbom **»Pobožaj«**. Brez odštevanja (ni pritiska). »Mogoče kasneje« vabilo skrije do konca.
- Gumb in vabilo se skrijeta med sprehodom, šolo in čiščenjem.

### 4.2 Igra z žogo (mini-igra, ~20 s)
- Celozaslonska plast v slogu »Šole« (temno steklo). Spodaj žoga; otrok jo **povleče s prstom navzgor in spusti** (»vrže«). Kuža steče po žogo in jo prinese nazaj (pes s polnim naborom: video `playing`; ostali: obstoječi video + animirana žoga).
- **3 meti** = konec. Vsak met šteje, ne glede na moč ali smer — ni zgrešenega meta, ni točk.
- **Dostopnost:** gumb **»Vrzi žogo«** namesto potega (VoiceOver / TalkBack: dvojni dotik); pri vklopljenem »zmanjšaj gibanje« žoga ne leti po zaslonu, samo kratek prelivni prikaz. Ni časovne omejitve med meti.
- Konec: »Hvala za igro! Kuža je ves vesel.« Igro je mogoče kadarkoli zapreti (»×«); nedokončana igra se ne šteje in nima posledic.

### 4.3 Crkljanje (božanje, ~10 s)
- Ista plast; otrok s prstom **gladi po kužku** (poteg čez osrednji del zaslona). Ob vsakem potegu se pojavi srček; po **5 potegih** (vsak vsaj ~60 pt, v katerokoli smer) je konec **(D)**.
- **Dostopnost:** gumb **»Drži in pobožaj«** — držanje 3 s nadomesti potege (bralnik zaslona: dejanje »Pobožaj«); »zmanjšaj gibanje« → srčki brez letenja.
- Konec: »Kuža uživa. Hvala za crkljanje!«
- Brez zvoka in brez haptike (`expo-haptics` ni v projektu — nov nativni modul bi zahteval nov build; lahko kasneje).

### 4.4 Če otrok vabilo prezre ali se ne igra
Nič. Vabilo po 2 urah tiho izgine. Brez besedila »zamudil si«, brez opomnika, brez vpliva na karkoli.

## 5. Razpoloženje (mood) — David 8. 10., Q5
- **Danes razpoloženja kot vrednosti ni.** Stanje videa (`pet_state`) izhaja iz merilnikov: `playing`, ko je energija ≥ 80 % in lakota ≥ 60 %, sicer `idle` / `hungry` / `low_energy` / `sleeping` / `sick`.
- Po vsaki končani igri ali crkljanju (prosti ali iz vabila) je kuža **30 minut »vesel«** (`happy_until` = konec igre + 30 min; nova igra čas podaljša, se ne sešteva). V tem času aplikacija predvaja video `playing`, če ga pes ima, sicer svoj običajni video z mehkimi srčki, in pokaže kratek napis »Kuža je vesel«.
- **Prednost** (od najvišje): zaklep › prizor vedenja (luža / copat) › bolan / spi (tihe ure) / lačen / utrujen › **vesel** › `playing` / `idle`. Vesel kuža nikoli ne skrije potrebe.
- Brez merilnika, brez številke, brez zgodovine razpoloženja. Ne vpliva na točke, Care Score, rutine, upadanje, bolezen ali game over.

## 6. Mediji in strošek
- **Brez novih generacij AI.** Video `playing` je že v polnem naboru (`config/media.php` → `video_states.full`), ki ga dobi izziv, plačan z nakupom (PAYMENTS_SPEC P6). Neplačan pes (tekoči stari preizkus, stikalo izklopljeno) ali grandfathered ima osnovni nabor (`idle`, `sleeping`) — aplikacija uporabi `idle` + animacijo v aplikaciji (žoga, srčki); obstoječa veriga nadomestkov (`videoChain`: `playing` → `idle`) to že zna.
- Možen kasneje: video `cuddle` (≈ 0,56 $ na psa na življenjsko obdobje — Kling 3.0 Pro, 5 s × 0,112 $/s; mladiček Border Collie v izzivu ≈ 1,12 $), šele z žetoni (M4-09).

### 6.1 Faza 2 / raziskava: interaktivni 3D kuža
David (8. 10., Q6) sprašuje o **interaktivnem 3D psu** (v slogu Talking Tom: pes se v živo odziva na dotik, žogo, božanje). To je **ločen raziskovalni spike** (izvedljivost v Expo / RN, 3D model iz AI slike ali pasme, animacije, velikost aplikacije, strošek, enakost z AI videzom psa) in **ni del M5-R05**. M5-R05 se gradi tako, da ga 3D kasneje lahko zamenja: mini-igri sta ločena plast (`PlayOverlay`), strežnik pozna le »igra / crkljanje opravljeno«, zato se pogled lahko zamenja brez spremembe API-ja.

## 7. Kaj vidi starš — David 8. 10., Q7
- **Časovnica:** »♥ 16:20 Igra z žogo · {vzdevek otroka}«, »♥ 18:05 Crkljanje · {vzdevek otroka}«. Ker je igra prosta, **(D)** največ ena vrstica na otroka in vrsto na uro (ponovitve v tej uri se samo preštejejo), da igra ne izrine dejanj skrbi iz seznama zadnjih 20.
- **Kartica psa:** »Danes: 3× igra z žogo, 2× crkljanje« (dnevno število, vsi otroci skupaj; po lokalnem dnevu družine).
- **Ne:** semafor, Care Score, rutine, tedenski graf, poročilo, certifikat.
- **Brezplačni / legacy pes:** nič.

## 8. Obvestila — David 8. 10., Q7
**Brez push obvestil** — ne otroku ne staršu. Vabilo vidi le otrok, ki odpre aplikacijo.

## 9. Skupni pes, več psov, stare različice
- **Skupni pes** (David 8. 10., Q8): vsak otrok psa, ki je podpisal pogodbo, se lahko igra in crklja prosto. Vabila so **psova** (2 na dan ne glede na število otrok); opravi jih **prvi** otrok, ostalim se vabilo tiho zapre.
- **Več psov v družini:** vsak pes ima svoja vabila in svoje štetje.
- **Stara različica aplikacije:** dodatna polja ignorira; vabila se iztečejo brez posledic. Starševska aplikacija brez novih oznak bi v časovnici pokazala surovo vrsto (`played_with_pet`) — sprejemljivo, ker aplikacija še ni v trgovinah; oznake gredo v isti PR.

## 10. Besedila (i18n, otrok — prijazen ton)
| Ključ | SL | EN |
|---|---|---|
| `play.chip` | Igra | Play |
| `play.pickBall` | Žoga | Ball |
| `play.pickCuddle` | Crkljanje | Cuddles |
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
| `parent.today` | Danes: {play}× igra z žogo, {cuddle}× crkljanje | Today: {play}× ball game, {cuddle}× cuddles |

Brez besedil za zamujeno / prezrto (namenoma). Brez imen otrok v otroški aplikaciji.

## 11. Analitika in zasebnost
- **Brez analitike** in brez SDK-jev; podatek je le vrstica v bazi (kot ostala dejanja) za časovnico in štetje staršev. Brez novih osebnih podatkov otroka (zapišemo samo, kateri skrbnik se je igral — kot pri hranjenju).
- Igre in vabila so v izvozu podatkov družine in se izbrišejo s psom.

---

## 12. Technical design (English)

> Decided: mood / video only, paid challenge (Q1; plus a running pre-M3-13 trial / payments kill switch off — M3-13), free play any time (Q3), invitations only after today's walk goal (Q4), 30-minute happy scene (Q5), parent timeline + daily count, no push (Q7), shared pet: free play for all, invitation completed by the first child (Q8). Items marked (D) above are proposals.

### 12.1 Eligibility
`PlayService::eligible(Pet $pet): bool` = `plan = challenge` ∧ `challengeStatus() ∈ {trial, paid}` (`trial` since M3-13 = a running pre-M3-13 trial or the payments kill switch off) ∧ `! awaitsPayment()` ∧ `! isLegacyProfile()` (Q2 — brezpredmetno po resetu baze D17, ostane kot varovalka) ∧ `! isUnborn()` ∧ not game over / inactive.
`PlayService::canPlayNow(Pet $pet, $now)` = `eligible` ∧ not frozen (hard stop, illness) ∧ not quiet now (D) ∧ no open hygiene event (existing "clean first" rule).
No `features` flag: the feature has no consequences, so pets of any app build are scheduled; an old build ignores the payload.

### 12.2 Invitation scheduling (server authority)
- In the tick (`pets:process-decay`), after `BehaviourEventService::ensureChewingScheduled`: `PlayService::ensureInvitationsScheduled($pet, $from, $now, $quiet)` decides every family-local day not yet decided (`pets.play_scheduled_through`, like `behaviour_scheduled_through`). Same outage rule as chewing: after a scheduler outage (`BehaviourEventService::isOutage`) only today is decided and invitations whose time has passed are written as `skipped`.
- Per day: seeded RNG (`hash('sha256', salt.'|play|'.pet.'|'.date)`, Xoshiro256**, as `randomizerFor`), shuffled kinds `[play, cuddle]`, two whole minutes inside the non-quiet part of the 07:00–20:00 local band with ≥ 3 h between them (bounded rejection sampling; no valid pair → one; no non-quiet minute → none). Config (admin-editable, not `breed_stage_params` — no source exists): `play.invitations_per_day = 2`, `play.day_band = [07:00, 20:00]`, `play.min_gap_minutes = 180`, `play.open_minutes = 120`, `play.happy_minutes = 30`, `play.timeline_merge_minutes = 60`.
- `expires_at = min(scheduled_at + open_minutes, start of the next quiet period)`.
- An invitation whose `scheduled_at` falls into a freeze (hard stop, illness, game over, payment lock) or before birth → `skipped` by the tick. Expiry: the tick marks `pending` rows with `expires_at ≤ now` as `expired`. No other effect.

### 12.3 Offer (invitation visible)
`PlayService::offeredInvitation(Pet $pet, $now): ?PetPlayEvent` = the oldest `pending` invitation with `scheduled_at ≤ now < expires_at`, when `canPlayNow()` ∧ **today's walk goal reached** (`steps_today ≥ step goal of the day` — the same check as the walk routine's "done", `RoutineLedgerService` / `DailyWalkService`; displayed energy 100 %) ∧ `displayMetric('hunger_level') > 30` ∧ `displayMetric('thirst_level') > 30`. Evaluated on read (state, broadcast). When the result flips on a tick or on a steps sync, `PetUpdated('play')` is dispatched once per flip.

### 12.4 Data model
New table **`pet_play_events`** (not `pet_hygiene_events`: every hygiene row is a `clean` routine that drops hygiene to 0 and is read by `RoutineLedgerService`). One table for invitations and completed plays:

| column | type | notes |
|---|---|---|
| `id` | bigint PK | |
| `pet_id` | FK pets, cascade on delete | |
| `kind` | string, CHECK `play`/`cuddle` | |
| `source` | string, CHECK `invitation`/`free` | |
| `local_date` | date | family-local day |
| `scheduled_at` | timestamptz null | invitations only |
| `expires_at` | timestamptz null | invitations only |
| `status` | string, CHECK `pending`/`done`/`expired`/`skipped` | free plays are always `done` |
| `completed_by` | FK users, null on delete | the child |
| `completed_at` | timestamptz null | |
| timestamps | | |

CHECKs: `source = free` ⇒ `status = done` ∧ `scheduled_at IS NULL`; `source = invitation` ⇒ `scheduled_at`, `expires_at` NOT NULL. Partial unique `(pet_id, local_date, kind) WHERE source = 'invitation'`; index `(pet_id, local_date, status)`. A free play that completes an open invitation **updates that invitation row** (no extra free row), so the daily count = `done` rows of the day. `pets.happy_until` (timestamptz null), `pets.play_scheduled_through` (date null, hidden). In `AccountExportService` (pet section); deleted with the pet.

### 12.5 API (additive)
- **`POST /api/child/pet/play`** `{ kind: "play" | "cuddle" }` (FormRequest) — called when a mini-game finishes (free or from the invitation). Policy: child is a caretaker of the pet and signed their contract. In one `DB::transaction` with the pet row lock: check `canPlayNow()` (423 / `contract_required` / 422 `play_not_available` as other actions); if an invitation of that kind is offered now → mark it `done` (`completed_by`, `completed_at`), else insert a `free` row; `pets.happy_until = now + happy_minutes`; write activity `played_with_pet` / `cuddled_pet` unless this child already has one of that kind within `timeline_merge_minutes` (D); dispatch `PetUpdated('play')` after commit. Returns the child state.
  - Repeat by the same child and kind within 10 s → treated as the same play (double tap / retry; 200, no new row) (D).
  - No anti-cheat (as training's accepted trust model, David 2026-10-06): the client reports completion; there is nothing to win.
- **Child state + `PetUpdated` payload:** typed `PlayPayload` (Scramble):
  ```json
  "play": {
    "can_play": true,
    "invitation": { "id": 12, "kind": "play", "expires_at": "2026-10-08T17:20:00+02:00" } | null,
    "mood": { "happy_until": "2026-10-08T16:50:00+02:00" | null, "scene": "playing" | null }
  }
  ```
  `play` is `null` for ineligible pets. `mood.scene = "playing"` while `now < happy_until` and the pet is not locked, not in a behaviour scene and `pet_state ∈ {idle, playing}`. `behaviour.scene` and `pet_state` are unchanged (old builds unaffected).
- **Parent dashboard (per pet):** `play_today: { play: int, cuddle: int } | null` — `done` rows of today's local date.
- **Timeline:** `ActivityType::PlayedWithPet`, `ActivityType::CuddledPet` in the existing pet activity list with the child's nickname.
- Regenerate `mobile/src/api/schema.ts`; ARCHITECTURE §3 + DIAGRAMS (tick + play sequence).

### 12.6 Isolation from scoring (must hold, tested)
- `RoutineLedgerService`, `CareScoreService`, `EscalationService`, `HardStopService`, semafor, weekly chart, reports and certificate ignore `pet_play_events` and the two activity types (explicit allow-lists, not "all activities").
- `happy_until` never changes metrics, decay, escalation timers, illness or game over.
- Pest: a pet that never plays for 7 days has identical Care Score / routines to one that plays 20× a day.

### 12.7 Mobile
- `modules/play/` — `play.ts` (payload parsing, strings, `chipState(view)` = hidden / play button / invitation, gesture thresholds), `PlayOverlay.tsx` (ball: RN `PanResponder` — no new native module; `react-native-gesture-handler` is not a dependency — upward release with a distance / velocity threshold; cuddle: pan over the pet area, 5 strokes ≥ 60 pt; button alternatives; `AccessibilityInfo.isReduceMotionEnabled`), `PlayChip.tsx` above the dock (same column as `TrainingChip` / meals row; never under the dock).
- Video: `selectMediaSource` gets `mood.scene` between the behaviour scene and the pet state; chain `playing → idle` already exists; no new `PetState`. The overlay is a separate layer so a future 3D view (§6.1) can replace it without API changes.
- Chip hidden while walk / training / cleaning overlays are open; one request per finished game, retry on network error, 422 / 423 → close quietly with the existing lock screen.
- Strings through i18n (SL + EN, §10); Jest for `play.ts` + `ChildHudScreen.play.test.tsx` with fake timers.

### 12.8 Tests (Definition of done)
Pest with `Carbon::setTestNow()`: eligibility matrix (free, trial, paid, payment lock, legacy, unborn); free play allowed / refused (quiet hours, hard stop, illness, mess, contract); invitations inside the band, outside quiet hours, ≥ 3 h gap, deterministic per seed, one / zero on short days; freeze → skipped; expiry; outage; offered only after the walk goal and with hunger / thirst > 30; free play completes the open invitation of the same kind; sibling sees it closed; daily counts; timeline merge; 10 s repeat; scoring isolation (§12.6); export / delete cascade.

### 12.9 Cost
Zero AI cost (existing `playing` video). A 3D dog (§6.1) is a separate spike.
