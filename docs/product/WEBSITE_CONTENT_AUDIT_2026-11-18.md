# Analiza: petprep.si proti aplikaciji in dokumentaciji (18. 11. 2026)

> **Namen:** preveriti, ali spletna stran pokaže vse, kar je v aplikaciji razvito in pri uporabnikih, in ali kje trdi kaj zastarelega.
> **Viri:** `pet-prep-website` (`main`, 18. 11. 2026, `src/content/*.ts` — vseh 15 jezikov ima enako strukturo), živa stran petprep.si, `docs/product/FEATURES.md`, `docs/engineering/ROADMAP.md`, `HANDOFF.md`, `docs/research/app-release.json` (zadnja izdaja pri uporabnikih: OTA 4.1.4, 17. 11. 2026), odločitve v `DECISIONS.md`.
> **Pravilo, ki velja:** stran kaže samo, kar uporabniki res imajo (David 13. 10. 2026), vse v vseh 15 jezikih (David 16. 11. 2026).

---

## 1. Povzetek

- **Spletna stran dobro pokriva temelje** (12-tedenski izziv, pogodba, koraki, tihe ure, Care Score, semafor, register 111 pasem, skrb/hrana, pobarvanke, Curio, #walkyourpet, kviz pasem, kalkulator »Koliko dela je pes«, partnerji, investitorji, pravne strani).
- **Večina tega, kar je bilo zgrajeno po ~15. 10., na glavnih straneh manjka**: šola, igra z žogo in crkljanje, mačje igre, nega po pasmi (česanje, zobje, kremplji, ušesa, gubice), »Ali si vedel?«, ime ljubljenčka, album rasti, rojstni dan, preklop starš ↔ otrok na enem telefonu, časovni pas in tihe ure na ljubljenčka, opomniki za nego staršu, nadgradnja brezplačnega ljubljenčka (samo v ceniku/FAQ).
- **»Želje« so na strani samo v ceniku, FAQ, pogojih in na strani za investitorje** — ni jih na domači strani, »Kako deluje«, »Za starše« in »Za otroke«. Opomba v ceniku (»prihajajo z naslednjo posodobitvijo«) je zastarela: v aplikaciji so od 27. 10. 2026, ne deluje le nakup (izdelki v trgovinah, M6-W02).
- **5 trditev je napačnih ali v nasprotju z vašimi odločitvami** (razdelek 3) — te bi popravil najprej.

---

## 2. Kaj je razvito (aplikacija + strežnik) — po področjih

Stanje iz `FEATURES.md` / `ROADMAP.md`. »Pri uporabnikih« = v OTA 4.1.4 ali trgovinski gradnji; »native« = potrebuje novo gradnjo v trgovini.

| Področje | Kaj zna | Stanje |
|---|---|---|
| Družina in računi | registracija z e-pošto, **Google in Apple prijava**, več staršev, več otrok, skupni ljubljenček, PIN za otroka, kopiraj kodo, **»Igraj kot …« (en telefon za starša in otroka)**, izvoz, izbris | ✅ |
| Izbira ljubljenčka | vrsta → pasma (pes + mačka, 60+ pasem psov, 7 mačk), **izbira barve dlake s fotografijo barve**, izvor, starost ob prihodu, oznake »Primerno za / Upoštevajte«, **ime ljubljenčka** | ✅ |
| Nega | okna hrane, nujni obrok, **voda »dolij, ko se prazni«** (ni več 3× na dan), čiščenje, sprehod s koraki, **»Pelji ven«** (mladiček), pregrizen copat, **časovni pas in tihe ure za vsakega ljubljenčka**, zapis ure 12/24 h | ✅ |
| Nega po pasmi | **česanje, ščetkanje zob, kremplji, ušesa, gubice**, opozorilo za vročino, premor po obroku, **nova izkušnja (socializacija mladička)**; staršu **opomniki: pasji frizer, kopanje** | ✅ |
| Šola | 4 ukazi, mini-igra »Pohvali ob pravem trenutku«, posnetek za vsak ukaz in fazo, pošten rezultat, starš vidi znanje | ✅ |
| Igra | **žoga, crkljanje**, mačja **palica s peresom**, pesek, menjava peska, praskalnik | ✅ |
| AI ljubljenček | unikaten videz, videi stanj, **album »Moj kuža« in album rasti**, **rojstni dan pri 1 letu**, prikaz video/slika, »Pripravljamo …« | ✅ |
| Znanje | **»Ali si vedel?« + »Moja pasma«** (samo pasme z odobrenimi zanimivostmi: border collie, maine coon), **kartica »Kako skrbim za …«** (otrok in starš) | ✅ |
| Starš | semafor, Care Score, poročilo 7/30/84, časovnica, hard stop, **certifikat**, **seznam želja otroka (Kupi / Ne zdaj)**, opomniki za nego | ✅ |
| Dostopnost | lažje kretnje, bralnik zaslona, manj gibanja, zvoki/vibracija; **prilagojen način (potiski vozička)** — native, še ni pri uporabnikih | ✅ / native |
| Plačila | izziv 49,99 €, **nadgradnja brezplačnega ljubljenčka (z izbiro ocene)**, opomnik staršu 7. dan, **Želje** (nakup čaka izdelke v trgovinah) | ✅ (Želje: nakup 🗓) |
| Splet – prodaja | darilni bon (izklopljen do izida v trgovinah), »Obljuba o ljubljenčku« (stran vklopljena), partnerji, kviz, kalkulator, e-naslovi staršev | ✅ / izklopljeno |

---

## 3. Napačno ali zastarelo na strani (popraviti najprej, vseh 15 jezikov)

| # | Kje | Kaj piše | Zakaj ni prav | Predlog |
|---|---|---|---|---|
| 1 | **FAQ** + predogled FAQ na **domači strani** | »za otroke približno od 7 do 12 let, dobro do 16« | David 15. 10. 2026: **nikoli ne komuniciramo starostnega razpona** | »Za vsakega otroka, ki staršem obljublja, da bo skrbel za ljubljenčka — in za odrasle, ki želijo preizkusiti pasmo.« |
| 2 | **Kako deluje** → korak 1 | »Apple and Google sign-in are coming« | Google in Apple prijava zgrajena 13. 10. 2026 (M2-10c) | »Registrirate se z Googlom, Apple ali e-pošto.« |
| 3 | **Kako deluje** → tabela + **Za starše** → Care Score | voda »3 times a day, at least 3 hours apart«; »each of the three water refills« | Od 12. 10. 2026 velja »dolij, ko se prazni« (M5-F11), brez dnevne omejitve | Voda: »ko se skleda prazni, najmanj 3 h razmika«; rutina: »vsako dolivanje, ko je skleda prazna« |
| 4 | **Kako deluje** → »Failing is a result too« | »You can start again, also with an easier breed« | Ponovni začetek z lažjo pasmo (Breed Downgrade, M2-07) **ni zgrajen**, pravila nov pes po game overu čakajo vas | Odstraniti »with an easier breed« ali preveriti, kaj starš po zavetišču res lahko naredi |
| 5 | **Cenik** → Želje | »Wishes are coming with the next app update« | Želje so v aplikaciji od 27. 10. 2026 (OTA); manjka samo nakup v trgovinah | »Otrok lahko že prosi za stvari; nakup bo mogoč, ko bodo izdelki v App Store in Google Play.« |

Manjše: **»Kako deluje« → certifikat**: »After 12 weeks the pet turns one« velja samo za mladička (odrasel / starejši ljubljenček ne dopolni 1 leta). Predlog: »Po 12 tednih …«.

---

## 4. Razvito, a na strani manjka (po straneh)

### Domača stran (`/`)
- **Želje** — en blok ali kartica v »Realism«/»Za starše«: otrok prosi, starš odloči, samo videz, nikoli »plačaj, da rešiš ljubljenčka«.
- **Šola in igra** (žoga, crkljanje, palica s peresom) — danes stran govori samo o hrani, vodi, sprehodu, čiščenju.
- **Mačke**: tri realism kartice so pasje (koraki, copat). Ena vrstica, kaj počne muca (igra s palico namesto sprehoda, pesek).

### Kako deluje (`/how-it-works`)
- Korak »Twelve weeks of care« naj omeni **šolo, igro, nego po pasmi**.
- Nova kartica **»Ime, album, rojstni dan«** (ljubljenček raste na slikah).
- **Nega po pasmi**: česanje, zobje, kremplji, ušesa, gubice — samo kjer vir navaja pogostost; povezava na register.
- **Ena družina, en telefon**: »Igraj kot …« (danes samo v FAQ za odrasle).
- Posnetki zaslona: danes PIN, pogodba, HUD — ni šole, igre, mačke, želj.

### Za starše (`/for-parents`)
- **Seznam želja otroka** (Kupi / Ne zdaj, presenečenje brez prošnje).
- **Opomniki za nego** (pasji frizer, kopanje — ne štejejo v oceno).
- **Ime ljubljenčka**, **preklop profila na enem telefonu**, **Google/Apple prijava**.
- **Časovni pas in tihe ure za vsakega ljubljenčka** (danes: »quiet hours in your local time«), zapis ure 12/24 h.
- **Nadgradnja brezplačnega ljubljenčka** v izziv z izbiro, ali ocena ostane (danes samo v ceniku/FAQ).
- **Kartica »Kako skrbim za …«** in **znanje, ki ga ima kuža iz šole**.
- Care Score: rutine danes naštete brez šole, nege po pasmi, mačje igre in peska.

### Za otroke (`/for-kids`)
- Danes samo pobarvanke, Curio in #walkyourpet. Manjka **»Kaj počneš v aplikaciji«**: šola, žoga, crkljanje, **Želje** (»prosi starša«), album, rojstni dan, »Ali si vedel?«. Brez cen, brez telefona v roki ves dan (David 18. 10.).

### FAQ
- Nova vprašanja: »Ali se lahko igramo s kužkom?«, »Ali ima lahko ljubljenček ime?«, »Lahko otrok in starš uporabljata en telefon?«, »Kaj, če smo na počitnicah v drugem časovnem pasu?«, »Kaj počne muca namesto sprehoda?«.
- »Which languages? English and Slovenian today« — jasno ločiti: **aplikacija** EN + SL, **spletna stran** 15 jezikov.

### Dostopnost
- Stran je v redu. »Wheelchair walks — coming with the next app update« je še vedno pravilno (native gradnja). Ob izidu preveriti, ali Android sploh bere potiske: `READ_WHEELCHAIR_PUSHES` je od 2. 11. odstranjen iz Android aplikacije (Google zavrnil) — na Androidu ostane samo starševski vpis minut.

---

## 5. Pravilno ni na strani (ni zgrajeno ali ni pri uporabnikih)

Ne dodajati, dokler ni pri uporabnikih: ljubljenček v pravi sobi (napoved v reelsih je OK), žetoni za AI medije, ovratnica (W06), Breed Downgrade / nov pes po game overu, Facebook prijava, lokalni opomniki, pasemski nagoni, DNK razkritje, Faza 2 (že označena »v razvoju«), trgovina z merchom. Darilni bon ostane izklopljen do izida v trgovinah.

---

## 6. Predlog vrstnega reda

1. **Popravki iz razdelka 3** (en PR, 15 jezikov) — hitro, odstrani napačne trditve.
2. **Želje** na domači strani, »Kako deluje«, »Za starše«, »Za otroke« + FAQ (15 jezikov).
3. **»Kako deluje« in »Za starše«** dopolniti s šolo, igro, nego po pasmi, mačko, imenom/albumom, časovnim pasom ljubljenčka, enim telefonom.
4. **»Za otroke«**: blok »Kaj počneš v aplikaciji«.
5. **Posnetki zaslona**: šola, igra, muca, želje (David posname na telefonu → video/slika pipeline).

Pri vsaki točki: besedila naravno v vseh 15 jezikih, brez starostnega razpona, brez otroka, prilepljenega na telefon.
