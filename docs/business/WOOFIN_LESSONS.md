# PetPrep: kaj prevzamemo iz Woofina (»Danes s kužkom« in Pot 12 tednov)

> **Status:** *načrt*, samo specifikacija. Nič od tega še ni zgrajeno.
> **Vir:** pregled woofin.com (27. 11. 2026) — aplikacija z dnevnimi aktivnostmi za lastnike pravih psov. Ni partner in ni neposredna konkurenca; gledali smo samo, kaj izboljša našo aplikacijo.
> **Odločil David 27. 11. 2026:** v specifikacijo gresta (1) »Danes s kužkom« in (2) Pot 12 tednov. Koledar skrbi za zdaj ne.
> Vse, kar je označeno **(D)**, je osnutek Claude, ki ga David potrdi pred gradnjo (§6).
> **Kdaj:** VALIDATION_PLAN §2 zamrzne nove funkcije do 8. tedna. Pot 12 tednov samo prikazuje obstoječe podatke (majhno delo); »Danes s kužkom« je nova funkcija za po validaciji.

## 1. Kaj Woofin dela dobro

- Cel produkt stoji na eni resnici o psih: **poleg hrane in sprehoda pes potrebuje miselno zaposlitev**; zdolgočasen pes dela težave.
- Ena preprosta aktivnost na dan, ne seznam nalog.
- Izbira po razmerah: notri / zunaj in vrsta (umirjeno, družabno, miselno, aktivno).
- Aktivnost je kratek vodič po korakih (naprej / nazaj, zaključek).
- Napredek je narisan kot pot s kamni, ne kot številka.
- Naročnina Woofin+ (9,99 $ / mesec) prodaja sveže aktivnosti za pravega psa.

## 2. »Danes s kužkom« (dnevna aktivnost proti dolgčasu)

### 2.1 Zakaj

- Naš ljubljenček ima lakoto, žejo, higieno in energijo. **Miselne zaposlitve nima**, čeprav je to ena glavnih stvari, ki družine pri pravem psu presenetijo.
- Otrok mora imeti kaj početi, ko je ljubljenček preskrbljen (David 7. in 8. 10. 2026).
- Je lažja prva stopnja pasemskih nagonov (BREED_DRIVES_SPEC, »po beti«): ista ideja, brez pregrehe in brez roka.
- Knjižnica aktivnosti je pozneje vsebina za naročnino pri pravem ljubljenčku (REVENUE_MODELS, Faza 2). Enkrat narejeno, dvakrat uporabljeno.

### 2.2 Kako deluje (D)

1. Ko je osnovna skrb danes opravljena, otrok vidi miren čip »Danes s kužkom« (pri mački »Danes z muco«). Brez potisnega obvestila.
2. Otrok izbere **notri** ali **zunaj**. Aplikacija ponudi eno aktivnost, primerno vrsti, pasmi in življenjskemu obdobju.
3. Aktivnost je 3–5 kartic s koraki (risana ilustracija + en stavek), gumba »Naprej« in »Nazaj«, na koncu »Končano«.
4. Zadnja kartica pove, **zakaj to psu koristi**, z virom (pravila iz DID_YOU_KNOW_SPEC: samo resnična dejstva z virom, opomba »ni veterinarsko pregledano«).
5. Največ ena aktivnost na dan na ljubljenčka. Naslednji dan druga; ista se ne ponovi, dokler knjižnica ni izčrpana.

### 2.3 Pravila (D)

- **Ne vpliva** na Care Score, semafor, rutine, bolezen ali certifikat. Vpliva samo na razpoloženje, kot igra in crkljanje (PLAY_CUDDLE_SPEC).
- Brez točk, kovancev, XP, niza ali lestvice (ACHIEVEMENTS_SPEC §2). Izpuščen dan nima posledice in ni sporočila »zamudil si«.
- Brez AI posnetkov: samo risane kartice v slogu znamke. Zato ni stanja »se pripravlja« in ni stroška medijev.
- Ne v tihih urah, ne med boleznijo, hard stopom ali game overjem.
- Vsa besedila v vseh jezikih aplikacije.
- Starš vidi v časovnici, katero aktivnost je otrok naredil (»Maja je s kužkom vadila iskanje priboljška«).

### 2.4 Knjižnica (D)

| Vrsta | Primer pri psu | Primer pri mački |
|---|---|---|
| Miselno | iskanje priboljška, skrivalnice | priboljšek v škatli |
| Umirjeno | vaja miru na odeji, masaža | krtačenje ob predenju |
| Aktivno | vlečenje vrvi, tek za žogo | lov na palico |
| Družabno | srečanje z novim človekom ali zvokom | spoznavanje novega prostora |

- Prvi nabor: 20 aktivnosti za psa in 10 za mačko, vsaka z oznako notri / zunaj, vrsto, primernimi obdobji in (neobvezno) pasemskimi oznakami.
- Katalog je v konfiguraciji (ključ, oznake, kartice, vir), kot katalog nalepk.

## 3. Pot 12 tednov

### 3.1 Zakaj

Otrok danes vidi samo »Teden N od 12«. Številka ne pove, kaj je že za njim in kaj pride. Pot to pokaže z eno sliko in naredi certifikat viden kot cilj.

### 3.2 Kako izgleda (D)

- Risana pot z 12 postajami (ena na teden), odprta s tapom na »Teden N od 12« v HUD-u.
- Opravljene postaje so obarvane, trenutna ima ljubljenčkov portret (iz že narejenih medijev), prihodnje so sive.
- Na poti so **mejniki, ki jih igra že pozna**: prehod življenjskega obdobja, prvi rojstni dan, naučeni ukazi, polovica poti, certifikat na koncu.
- Tap na opravljeno postajo: kratek povzetek tedna (kaj se je ljubljenček naučil, kako je zrasel). Brez ocen in brez »zamujeno«.
- Prihodnje postaje ne razkrijejo vsega: en stavek namiga (»Kuža bo kmalu mlad pes«).

### 3.3 Pravila (D)

- Samo prikaz. Nič ne zaklepa in nič ne odklepa; ne vpliva na igro.
- Programska ura, kot je (`Pet::programInstantAt`): čas zaklepa do plačila ne šteje; pri nadgradnji brezplačnega ljubljenčka se pot začne z nakupom, dnevi prej so »pred izzivom«.
- Vsak otrok vidi pot po **svojem** začetku (`pet.challenge_progress`).
- Brezplačni ljubljenček: pot brez 12 tednov ni; namesto nje (D) kratka pot »prvih 7 dni«, ki se konča pri kartici za nadgradnjo **samo pri staršu** (otrok nikoli ne vidi cen).
- Ko bo album nalepk zgrajen, so nalepke skupine »Pot skozi izziv« postaje na tej poti.
- Starš vidi isto pot v podrobnostih otroka.

## 4. Česa ne prevzamemo

- Kovanci, XP, stopnje, tedenska lestvica, zamrznitve niza, odklepanje z ogledom oglasa. Vse je že izključeno v ACHIEVEMENTS_SPEC §2: Care Score mora meriti pripravljenost, ne zbiranja.
- Koledar skrbi (tačka za vsak dan popolne skrbi): predlagano, David 27. 11. 2026 ni izbral.
- Partnerstvo z Woofinom: ne (David 27. 11. 2026).

## 5. Kako preverimo, da ne škodi

- Care Score in zadrževanje v 4. tednu primerjamo pred funkcijo in po njej (kot ACHIEVEMENTS_SPEC §6).
- »Danes s kužkom«: delež otrok, ki aktivnost odprejo vsaj 2-krat na teden; ali se čas v aplikaciji na dan opazno podaljša (ne sme postati »visenje na telefonu«, David 18. 10. 2026).

## 6. Odprto za Davida

1. »Danes s kužkom«: ena aktivnost na dan ali otrok lahko zahteva drugo, če mu prva ni všeč?
2. Ali naj aktivnosti »zunaj« štejejo samo kot kartice ali naj se navežejo na prave korake?
3. Ali ima brezplačni ljubljenček »Danes s kužkom« v celoti ali samo manjši nabor?
4. Pot 12 tednov: gradnja pred koncem validacije (samo prikaz) ali skupaj z albumom nalepk?
5. Kratka pot »prvih 7 dni« za brezplačnega ljubljenčka: da ali ne?
