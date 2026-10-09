# M5-R09 — »Ali si vedel?« in vodnik po pasmi (specifikacija)

> **Stanje:** osnutek Claude (UI/UX), 9. 10. 2026, na Davidovo prošnjo. Vse, kar je označeno **(D)**, čaka Davidovo odločitev (§0).
> Vizualni osnutek zaslonov: Design artefakt »PetPrep – Ali si vedel?« (https://claude.ai/artifact/GUN5K41zxL36VCkTNUmdDJ), CGP v2 »Grafit in meta«.
> Zanimivosti na osnutkih so **resnične**, iz `docs/research/dog-data/sources.md` (S1 FCI, S4 AKC, S5 Royal Kennel Club, S7 PDSA, S15 Dogs Trust).

## 0. Vprašanja za Davida (D)
1. **Pogostost okna pri otroku:** predlog največ **1 na dan**, ob »mirnem trenutku« (§3.2). Možnost: vsak 2. dan.
2. **Zbirka / napredek »8 od 40«:** predlog **da** (otroci radi zbirajo), brez zaklepanja — vse je vedno berljivo.
3. **Starš vidi tudi druge pasme** (ne samo družinske): predlog **da, samo pri staršu** — pomaga odraslim, ki izbirajo pasmo (»je ta pasma zame?«), in pred nakupom izziva. Otrok vidi samo svojo pasmo.
4. **Pregled vsebine o zdravju:** predlog — kategorija »Zdravje« je samo za starše in jo pred objavo pregleda veterinar (ali vsaj David); ostalo preverja neodvisen AI preverjevalec + David.
5. **Mešanček in domača mačka:** po Davidovi odločitvi brez tega. Predlog za kasneje: splošne »pasje / mačje« zanimivosti brez pasme (*načrt*, ne v M5-R09).

## 1. Namen
- Otrok med skrbjo izve resnične stvari o **svoji** pasmi — in razume, **zakaj** se ljubljenček v igri obnaša tako (»Border collie potrebuje 2+ uri gibanja → zato je tvoj cilj 12.000 korakov«). To je jedro: zanimivost razloži pravilo igre.
- Starš dobi zaupanja vreden vodnik po pasmi z viri — tudi kot pomoč pri odločitvi za pravo žival (Faza 2).
- Brez novih stroškov AI: slika je že obstoječa slika ljubljenčka (AI mediji, M4).

## 2. Kdo in kaj
| | Otrok (temni simulator) | Starš (svetli pregled) |
|---|---|---|
| Okno »Ali si vedel? / Ali ste vedeli?« | Spodnji list (bottom sheet) čez glavni zaslon, največ 1 na dan (D1) | **Ni okna.** Mirna kartica na zavihku Pregled; »Naslednja« pokaže drugo |
| Baza znanja | Gumb »knjiga« v zgornji vrstici → zaslon **Moja pasma** | Kartica → **Vodnik po pasmi**; tudi vrstica »O pasmi« v podrobnostih otroka |
| Vsebina | Kratko, otroški ton, brez zdravstvenih tveganj | Vse kategorije + **Zdravje**, vir pri vsaki zanimivosti, preklop »Kar bere otrok« |
| Pasme | Samo pasma njegovega ljubljenčka | Pasme družine; (D3) tudi ostale podprte pasme |

**Samo pasemske živali** (danes border collie, maine coon). Mešanček in domača mačka: ni gumba, ni okna, ni kartice — nič praznega.

## 3. Otrok
### 3.1 Vstopa (osnutek »Otrok · vstopa na glavnem zaslonu«)
1. **Gumb »knjiga«** (52 px, steklo) desno od naslova v zgornji vrstici. Drobna malinasta pika = neprebrana zanimivost (edini malinast poudarek na zaslonu, po CGP).
2. **Čip »Nekaj novega o tvoji pasmi«** nad vrstico gumbov skrbi, ko je za danes nova zanimivost, okno pa se še ni odprlo samo (npr. ker trenutek ni bil miren). Dotik odpre list. Čip izgine po branju ali ob polnoči (družinski čas).

### 3.2 Kdaj se okno odpre samo (»miren trenutek«)
Vsi pogoji hkrati:
- ta dan se še ni pokazalo (D1), ljubljenček je **pasemski** in ima **sliko**;
- otrok je pravkar **uspešno opravil skrb** (nahranil, napojil, počistil, sprehod/igra končana) — zanimivost je nagrada, ne prekinitev;
- vse metrike ≥ 50 %, ni odprtega nereda, ni zaklepa (plačilo, bolezen, hard stop), ni mini-igre / šolanja / prekrivnega okna, niso tihe ure;
- ne prvi dan izziva (otrok se uči osnov) in ne v prvih 10 s po odprtju aplikacije.
Če pogoji niso izpolnjeni do konca dneva, ostane le čip (§3.1) — nič se ne izgubi.

### 3.3 Kartica (osnutek »Otrok · kartica«)
Spodnji list, zaobljen 30 px, zatemnjeno ozadje (video ljubljenčka še vedno slutiš zadaj):
- **okrogla slika lastnega ljubljenčka** z metinim obročem (trenutna življenjska faza),
- oznaka **»ALI SI VEDEL?«** (eyebrow, meta) + »Pasma · kategorija«,
- **dejstvo** kot naslov (Bricolage 28 px, ≤ 120 znakov),
- **»zakaj je to pomembno zate«** (18 px, ≤ 140 znakov) — povezava s pravilom igre, kadar obstaja,
- vir v eni vrstici (»Vir: The Royal Kennel Club«) — tudi otrok vidi, da ni izmišljeno,
- napredek »8 / 40« s tanko metino črto (D2),
- **»Super!«** (meta, glavni) in **»Več o moji pasmi«** (obroba) → zaslon Moja pasma.
Zapre se s potegom navzdol ali »Super!«. Brez zvoka, brez konfetov (miren vmesnik, CGP). Dostopnost: `role=dialog`, naslov kot oznaka, VoiceOver prebere dejstvo in vir.

### 3.4 Moja pasma (osnutek »Otrok · Moja pasma«)
- glava: slika ljubljenčka, eyebrow »Ovčarski pes · Velika Britanija«, ime pasme (h1);
- **3 hitra dejstva** v ploščicah (»2+ h gibanja na dan«, »13 let običajno živi«, »53 cm visok samec«) — vsako z virom ob dotiku;
- **iskanje** (»Išči med zanimivostmi«) + čipi kategorij (Vse, Gibanje, Značaj, Telo, Izvor, Nega …);
- napredek »Prebral si 8 od 40«;
- seznam kartic; neprebrane z oznako **»Novo«**. Dotik odpre isto kartico kot §3.3.
Vse zanimivosti so berljive takoj (brez zaklepanja); »Novo« samo vodi pozornost.

## 4. Starš
### 4.1 Kartica na Pregledu (osnutek »Starš · kartica na pregledu«)
- Bela kartica pod karticami otrok: slika ljubljenčka, **»ALI STE VEDELI?«** (mint-text), pasma · kategorija, dejstvo (16 px), vir, gumba **»Vodnik po pasmi«** (grafit) in **»Naslednja«**.
- Starš je ne more zamuditi, a ga nikoli ne prekine (brez okna, brez potisnih obvestil).
- Več pasemskih ljubljenčkov v družini: kartice se izmenjujejo po dnevih.

### 4.2 Vodnik po pasmi (osnutek »Starš · vodnik po pasmi«)
- glava: slika, »FCI št. 297 · skupina 1«, ime pasme, »Luna (Maja) · 40 preverjenih zanimivosti«;
- preklop **»Za starše« / »Kar bere otrok«** (starš vidi, kaj je otrok že prebral — pogovor za mizo);
- kategorije, tudi **Zdravje** in **Družina** (samo starš);
- vsaka kartica ima **vir** (ime ustanove; dotik odpre povezavo);
- spodaj: »Vsaka zanimivost ima preverjen vir. Ste našli napako? Sporočite nam.« (e-pošta podpori).
- Vstop tudi iz podrobnosti otroka (vrstica »O pasmi«). Nov zavihek ni potreben (spodnja vrstica ostane Pregled / Nadzor).

## 5. Vsebina — kdo napiše in kdo preveri
**Pravilo: nič brez vira. Kar ni preverjeno, se ne prikaže.**
1. **Zbiranje (Claude, raziskovalni agent):** samo iz virov razreda A/B v `docs/research/*/sources.md` (FCI, kinološke zveze, veterinarske ustanove, recenzirane raziskave) — za vsako zanimivost **dobesedni citat**, URL, ID vira, zanesljivost. Nove pasme dobijo najprej svoj `sources.md`.
2. **Pisanje (Claude):** EN + SL, dve ravni (otrok / starš), kategorija, povezava s pravilom igre, kadar obstaja. Brez pretiravanja, brez »najboljši / vedno / nikoli«, številke iz vira.
3. **Neodvisno preverjanje (drugi AI agent, ki besedila ni napisal):** vsako zanimivost primerja s citatom; vse, kar citat ne pokrije, zavrne.
4. **Odobritev (David; Zdravje — D4):** v administraciji (Filament) stanje `osnutek → preverjeno → odobreno`; v aplikaciji samo `odobreno`.
5. **Obseg:** ~40 zanimivosti na pasmo (12 tednov × ~3 na teden), ~20 % s povezavo na pravilo igre. Nove zanimivosti se dodajo v administraciji, **brez nove gradnje aplikacije**.
Kategorije: Izvor, Telo, Značaj, Gibanje, Prehrana, Nega, Šolanje, Družina (starš), Zdravje (starš).

## 6. Zasebnost in otrok
- Nobenih osebnih podatkov v vsebini; stanje »prebrano« je vezano na otrokov profil, brez analitičnega SDK.
- Ime ljubljenčka (M5-R08) samo kot oznaka, nikoli v stavku zanimivosti.
- Brez povezav ven za otroka (vir je besedilo, povezava samo pri staršu).

## 7. Technical design (English, draft)
- **Data:** `breed_facts` (id, breed, category, audience `child|parent`, `title_{en,sl}`, `why_{en,sl}` nullable, `source_id`, `source_name`, `source_url`, `quote`, `game_rule_key` nullable, `sort`, `status` draft|verified|approved, `verified_by`, `approved_by`, timestamps). `breed_fact_reads` (user_id, fact_id, read_at; unique). `breed_fact_shows` (user_id, local_date) for "max one auto-show per family-local day" across devices.
- **API:** `GET /api/breeds/{breed}/facts` (approved only; child gets `audience=child`; parent gets both + sources); `POST /api/breed-facts/{fact}/read`; `GET /api/child/fact-of-the-day` → next unread approved fact (order: `sort`, life-stage relevance, game-rule facts tied to recent actions first) + `may_auto_show` (server-side daily cap in the family timezone). Breeds without approved facts (mutt, domestic cat) → 404 / empty → app hides every entry.
- **Admin:** Filament `BreedFactResource` with the status workflow, source fields required before `verified`, preview of child and parent card.
- **App:** `modules/breedFacts/` (calm-moment rule as a pure function, tested with fake timers), `FactSheet` (child bottom sheet), `BreedGuideScreen` (child dark / parent light), parent `FactCard` on the dashboard; image = the pet's current stage image from `PetMediaView` sources (no new AI calls). i18n EN + SL; facts come localized from the API.
- **Tests:** Pest (approved-only, audience filter, daily cap per family day, mutt/cat empty, read idempotent); Jest (calm-moment conditions incl. lock / mess / quiet hours / overlays, chip vs sheet, no entry for mutts).

## 8. Faze
1. **R09a vsebina:** 40 + 40 zanimivosti (border collie, maine coon), preverjanje, Davidova odobritev.
2. **R09b strežnik + administracija.**
3. **R09c otrok** (okno, čip, Moja pasma).
4. **R09d starš** (kartica, vodnik; D3 druge pasme).
