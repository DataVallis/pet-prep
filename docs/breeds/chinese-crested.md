# Chinese Crested (kitajski goli pes)

**Stanje:** 🛠 zgrajena v kodi (na telefonu še ni preverjeno) · plačljiva (12-tedenski izziv) · ROADMAP M5-R10-72 · 2026-10-27

| | |
|---|---|
| Ključ / slug | `chinese_crested` / `chinese-crested` (SL `kitajski-goli-pes`) |
| Vrsta | pes |
| Brezplačno / plačljivo | plačljiva (izziv), `sort_order` 670 — v izbirniku za bulmastifom |
| Vir podatkov | `docs/research/dog-data/data.json` → `chinese_crested` (viri S570–S575; ponovno S8, S10, S11, S14, S24, S35, S45, S92, S123, S174); seznam za vzreditelja: 2 testa »best practice« (RKC S572) |
| Izvor | FCI št. 288 »Chinese Crested Dog«, skupina 9, sekcija 4 (gole pasme), Kitajska (S570) — Kitajska (CN) je nova država izvora v registru |
| Zakaj ta pasma | naslednja najbolj priljubljena po registracijah AKC 2024, ki je še ni bilo v aplikaciji — 68. mesto »Chinese Crested« (S174, prebrano 2026-10-27) |
| Različici | gola (Hairless — čop, »nogavičke«, perjanica na repu) in »powder puff« (vsa pokrita z dolgo mehko dlako); igra in portret prikazujeta golo različico |
| PR-ji | pet-prep #418 (pasma) · portret: (sledi) · spletna stran: (sledi, le s portretom) |

## Odločitve (z viri in kdo je odločil)

| Datum | Kdo | Odločitev | Zakaj / vir | PR |
|---|---|---|---|---|
| 2026-10-27 | pravilo runbooka (potrdil David 2026-10-10) | gibanje odraslega **30 min/dan → 3.000 korakov**; starejši 23 min → 2.300; mladiček 10 min × starost do 30 (pri 3 mesecih) | RKC S572 »Exercise: Up to 30 minutes per day« (»up to N« → N); PetMD S574 »relatively small amount of exercise« (brez števila) | #418 |
| 2026-10-27 | pravilo runbooka | obdobja mladiček / mlad / odrasel / starejši: 0 / 9 / 36 / **121** mesecev; prihod 2 / 9 / 36 / 121 | AAHA S11; starejši = 0,75 × mediana McMillan 2024 **13,4 leta** (tabela Dogs Trust S123, vrstica »Chinese Crested«) = 120,6 → 121. Alternative: RKC »Over 12 years« → 108; Dogs Trust »> 7 let« → 84 | #418 |
| 2026-10-27 | pravilo runbooka | učenje **0,7×** (Coren rang 61, skupina »Fair«) | S35 | #418 |
| 2026-10-27 | pravilo runbooka | teža **3,6–5,4 kg** (oba spola), višina psi 28–33 / psice 23–30 cm, rast do 8–12 mesecev; hrana, voda in čiščenje kot pri border collieju | PetMD S574 8–12 lb (standard FCI teže ne navaja; RKC S573 »should not be over 5.4 kgs« se ujema z zgornjo mejo); FCI S571 višina; PetMD S10 (razred »Toy«) | #418 |
| 2026-10-27 | pravilo runbooka (M5-R13) | česanje **1× na teden** (čop, »nogavičke«, perjanica); skuštrana dlaka po 1 zamujenem | RKC S572 »Grooming: Once a week«; PetMD »at least a few times a week« velja le za »powder puff« | #418 |
| 2026-10-27 | David 2026-10-12 (M5-R17) | socializacija mladička (1 nova izkušnja na teden) s citatom vira | PetMD S574 »socializing your Chinese Crested puppy early and consistently is important for her to be comfortable in new situations.« | #418 |
| 2026-10-27 | pravilo »Breed care tasks« (David 2026-10-24, M5-R19) | striženje krempljev **1× na teden** (mini igra otroka) | PetMD S574 »Check your dog's toenails once a week and trim them back when they start getting a little long.« — tedenski pregled kot pri tedenskem »pregledu ušes« (ameriški koker, papijon, veliki šnavcer); glej Odprto | #418 |
| 2026-10-27 | pravila »Breed care tasks« | **brez** nalog zob, ušes, kopanja, frizerja ali obraza; brez opozorila za vročino in brez premora po obroku | PetMD: zobe »brush … at home«, ušesa »keep clean« — brez števila; noben vir ne omenja premora po obroku ali vročine | #418 |
| 2026-10-27 | pravilo runbooka (vsaka potreba z virom) | zaščita gole kože pred soncem in mrazom: **mehanike ni** → nova naloga v ROADMAP (David); do takrat čipa in besedilo v registru | PetMD S574 »Hairless areas of skin require special protection from the sun and cold temperature.« — precedens italijanski hrt (plašček v mrazu) | #418 |
| 2026-10-27 | pravilo runbooka (nova oznaka) | nova oznaka **`sun_protection`**: »gola koža — potrebuje zaščito pred soncem« / »bare skin — needs sun protection« | PetMD S574; `sensitive_skin` je za alergije / dermatitis (Breed Watch), `heat_sensitive` za gosto dlako | #418 |
| 2026-10-27 | pravilo runbooka | oznake: primeren za »družinsko življenje« (`family_pet`), »družino z otroki« (`children` — z opozorilom: otroke vedno nadzorujte), »življenje v stanovanju« (`apartment`), »dom z drugimi ljubljenčki« (`other_pets`); upoštevajte »gola koža — potrebuje zaščito pred soncem« in »občutljiv na mraz« (`cold_sensitive`) | PetMD S574 »fits in well with most families, including those with kids and other pets«, »all interactions between kids and this breed should be supervised«; RKC S572 »Flat/ Apartment« | #418 |
| 2026-10-27 | predlog Claude (pravilo dopušča) | **brez** oznake `sheds` (in nikoli `low_shedding` / »hypoallergenic«); brez `small_children`, `first_time_owner`, `chases_small_pets` | RKC »Sheds: Yes« velja za obe različici, igra kaže golo (dlaka le na glavi, nogah in repu) — glej Odprto; za druge oznake ni vira | #418 |
| 2026-10-27 | pravilo runbooka (§3) | ni pasma z oznako dobrobiti; marketing: **v aplikaciji da, v marketingu da** (nikoli kot modni dodatek, nikoli z opečeno kožo ali z majhnimi otroki brez nadzora — predlog Claude) | RKC Breed Watch kategorija 2 brez vrnjenih točk; ni plosko-obrazna pasma, BVA S92 ne velja | #418 |
| 2026-10-27 | pravilo runbooka (§3) | barve za AI in izbirnik (3): **rožnata koža s temnimi pegami** (portret), temno skrilasto siva, rožnata — vse z belim / kremnim čopom, »nogavičkami« in perjanico; nikoli merle, nikoli »powder puff«; zdrava, neopečena koža | FCI S571 »Any colour or combination of colours, except merle«. Uteži barv brez vira → `verified: false`; barva portreta je predlog Claude | #418 |
| 2026-10-27 | predlog Claude, David lahko spremeni | EN »Chinese Crested«, SL »Kitajski goli pes«; iskanje »chinese crested«, »kitajski goli pes«, »kitajski čopasti pes«, »hairless dog«, »powder puff« … | slovenskega vira (KZS) za ime ni bilo mogoče preveriti | #418 |
| 2026-10-27 | pravilo runbooka (§2.3) | seznam za vzreditelja: DNA test PLL in DNA test prcd-PRA — oba »best practice«; brez novega ključa | RKC S572 (pod »minimum« le »Prioritising genetic diversity« — ni test) | #418 |
| 2026-10-27 | pravilo runbooka | zdravstveni ključi registra: `bare_skin_sun_cold` (nov), `kneecap_luxation`, `primary_lens_luxation`, `pra_inherited_blindness`; brez odstotkov | PetMD S574, RKC S572 | #418 |

## Kaj je v aplikaciji (v slogu FEATURES)

| Funkcija | Stanje | Brezplačno / plačljivo | ID | Datum |
|---|---|---|---|---|
| Pasma v izbirniku (značka »Izziv«, namig »Del 12-tedenskega izziva · česanje 1× na teden«, iskanje »chinese crested«, »kitajski goli pes« …, oznake, starostni namigi) | 🛠 | 💶 izziv | M5-R10-72 | 2026-10-27 |
| Pravila po starosti (2.000 → 3.000 korakov pri 3 mesecih, odrasel 3.000, starejši 2.300 od 121. meseca; 4 → 3 → 2 obroka) | 🛠 | 💶 izziv | M5-R10-72 | 2026-10-27 |
| Česanje 1× na teden (rutina »Počeši«), »Nova izkušnja« za mladička | 🛠 | 💶 izziv | M5-R13 / M5-R17 | 2026-10-27 |
| Striženje krempljev 1× na teden (otrok) | 🛠 | 💶 izziv | M5-R19 | 2026-10-27 |
| Zaščita kože pred soncem / mrazom | 🗓 le čip in besedilo v registru — mehanike ni | — | nova naloga (David) | — |
| Barva (izbirnik: 3 standardne barve, privzeto rožnata s temnimi pegami) | 🛠 | 💶 izziv | M5-R21 | 2026-10-27 |
| AI portret v registru | 🗓 sledi takoj po združitvi (§8) | — | M5-R11-04 | — |
| Stran na petprep.si (`/animals/dogs/chinese-crested`, `/sl/zivali/psi/kitajski-goli-pes`) | 🗓 le s portretom; »Kmalu«, dokler pasma ni v izdaji aplikacije | — | M5-R11 | — |

## Zgodba (v slogu BUILD_LOG)

**Kaj:** Samodejni zagon je dodal kitajskega golega psa — 68. najbolj priljubljeno pasmo po AKC 2024 in 67. plačljivo pasmo psa. 6 novih virov (S570–S575). Odrasel pes v igri potrebuje 3.000 korakov na dan (RKC: do 30 minut gibanja), starejši 2.300 od 121. meseca (10 let) — po veliki britanski raziskavi (McMillan 2024, 928 psov) je mediana življenjske dobe 13,4 leta. Česanje enkrat na teden, tedenski pregled in striženje krempljev ter socializacija mladička so del izziva od prvega dne. Prvič v registru: pasma s Kitajske in nova oznaka »gola koža — potrebuje zaščito pred soncem«.
**Zakaj je pomembno:** Majhen (3,6–5,4 kg), prijazen pes za stanovanje, ki se razume z otroki in drugimi živalmi — a gola koža potrebuje zaščito pred soncem in mrazom, vsak stik z otroki pa nadzor. Lahko živi dolgo, zato je to zaveza za veliko let. PetPrep pokaže, ali otrok zmore vsakodnevno skrb tudi za majhnega psa, ki ne potrebuje veliko gibanja.
**Kako povedati** — starši: »Kitajski goli pes je majhen, prijazen pes za stanovanje z le pol ure gibanja na dan. Gola koža potrebuje zaščito pred soncem in mrazom, kremplje je treba vsak teden pregledati, otroke pa pri igri z njim vedno nadzorujte. Pogosto živi več kot 12 let. V PetPrepu boste videli, ali vaš otrok zmore 12 tednov skrbi zapored.« · otroci: »Kitajski goli pes skoraj nima dlake — samo čopek na glavi, puhaste nogavičke in rep. Vsak teden mu pregledaš kremplje in ga počešeš, na soncu in mrazu pa mora biti zaščiten.« · vlagatelji / partnerji: »Sedeminšestdeseta plačljiva pasma psa, ki jo je samodejni nočni zagon sam izbral po javni lestvici AKC in dodal — z viri, testi, negovalnimi nalogami in pravili, brez novih vprašanj; prva pasma s Kitajske in prva z oznako za zaščito kože pred soncem.«

## Odprto za Davida

- **Zaščita kože pred soncem in mrazom** (PetMD S574): mehanike ni (krema za sonce / plašček) — **potrebuje novo nalogo v ROADMAP — David** (enako kot plašček italijanskega hrta, M5-R10-60). Do takrat le čipa `sun_protection` + `cold_sensitive` in besedilo v registru.
- **Kremplji 1× na teden:** PetMD navaja tedenski *pregled* in striženje »ko so predolgi« — zgrajeno kot tedenska naloga (precedens tedenskih pregledov ušes); po želji redkeje.
- **Brez čipa »izpada mu dlaka«:** RKC »Sheds: Yes« velja za obe različici; igra kaže golo. Če želite čip, dodajte `sheds`.
- **Čip `cold_sensitive`** ima besedilo »tanka dlaka — občutljiv na mraz« (iz italijanskega hrta) — za golega psa bi bilo natančneje »gola koža«; besedilo je skupno, zato ga run ni spreminjal.
- **Breed Watch kategorija 2:** posameznih točk skrbi RKC stran ni vrnila (PetMD navaja izpah pogačice) — brez oznake dobrobiti.
- **Ime:** »Kitajski goli pes« (tudi »kitajski čopasti pes«) ni preverjeno pri KZS.
- **Barva portreta:** rožnata koža s temnimi pegami je predlog Claude (standard dovoli vse barve razen merle).
