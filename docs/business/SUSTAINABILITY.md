# PetPrep – trajnostni vpliv (zelena nota za razpise)

> **Status:** predlog (Claude, 26. 11. 2026), na Davidovo zahtevo »moramo dodati zeleno noto … zaradi razpisov«. David še **ni odločil**, kaj od §4 se gradi. Nič od spodnjega ni izmerjeno; številk o prihrankih ne navajamo, dokler jih nimamo.
> **Namen:** enotno izhodišče za prijave na razpise (SPS, SPIRIT, EU), kjer se ocenjuje prispevek k zelenemu prehodu, trajnosti ali načelo »da se ne škoduje bistveno« (DNSH). Merila posameznega razpisa je treba pred prijavo prebrati v razpisni dokumentaciji – ta dokument jih ne povzema.
> **Pravilo:** v prijavah in na spletni strani trdimo samo to, kar je v §2. Kar je v §3, ne trdimo.

## 1. Trije stebri

| Steber | Kaj PetPrep prispeva | Stanje |
|---|---|---|
| **Okoljski** | Manj nepremišljenih nakupov živali (žival ima okoljski odtis, predvsem zaradi hrane); ozaveščanje o odtisu ljubljenčka; varčna raba AI; digitalen izdelek brez fizične logistike | jedro izdelka obstaja, učinek ni izmerjen |
| **Družbeni** | Manj zavrženih živali in manjša obremenitev zavetišč; dobrobit živali; otrok se uči odgovornosti; spodbuda k posvojitvi; hoja z ljubljenčkom (#walkyourpet) kot gibanje otrok; prilagojen način (potiski vozička, lažje kretnje) | zgrajeno v aplikaciji |
| **Ekonomski** | Družina pred nakupom vidi resnični strošek in čas (kalkulator »Koliko dela je s psom«); manj stroškov zavetišč in občin z zavrženimi živalmi | kalkulator na petprep.si |

## 2. Kaj lahko pošteno trdimo

1. **PetPrep je orodje za preprečevanje nepremišljenega nakupa živali.** Družina 12 tednov skrbi za realistično simulacijo izbrane pasme, preden se odloči. Vsaka družina, ki se po izzivu odloči »ne še« ali izbere primernejšo pasmo, je ena žival manj, ki bi lahko končala v zavetišču. To je glavni argument – družbeni steber in dobrobit živali, okoljski posredno.
2. **Spodbujamo posvojitev.** Mešanček in domača mačka sta v aplikaciji vedno »posvojena« (nikoli »kupljena pri vzreditelju«), kviz pasem ima kartico posvojitve, register pasem ima razdelek »Pred nakupom: kaj preveriti pri vzreditelju«.
3. **Varčna raba AI.** Slike in videi se ustvarijo enkrat na pasmo + barvo + obdobje in si jih deli veliko družin (skupni videzi, M4-10 / M5-R21); »varčni posnetki« (stikalo `frugal_clips`) dodatno zmanjšajo število generiranj. Uvedeno zaradi stroška, pomeni pa tudi manjšo porabo računske energije na uporabnika.
4. **Digitalen izdelek.** Brez embalaže, prevoza in fizičnega blaga; certifikat in komplet »Obljuba o ljubljenčku« sta PDF, natisne ju družina sama, če želi.
5. **Vsebine z viri.** Vsak podatek o pasmi ima naveden vir (ne »strokovno preverjeno« – glej `docs/research/dog-data/README.md`).

## 3. Česar ne trdimo (dokler ni dokaza)

- **Prihranjenega CO₂ ali števila »rešenih« živali** – nimamo podatka, koliko družin se po izzivu odloči drugače.
- **»Zelenega gostovanja«** – dokler ne preverimo in ne navedemo, kaj Hetzner uradno objavlja za naš podatkovni center.
- **Energijske porabe ponudnika AI (fal.ai)** – ni nam znana.
- **Hoje otrok kot okoljskega učinka** – to je zdravje in gibanje, sodi v družbeni steber.
- **»Eko«, »trajnostna aplikacija«, »ogljično nevtralno«** kot oznake brez merila.

## 4. Predlogi (neodločeno)

| # | Predlog | Vrsta | Opomba |
|---|---|---|---|
| T1 | **Meritev odločitve po izzivu:** eno vprašanje staršu ob koncu 12 tednov – žival dobimo / ne še / drugo pasmo / posvojimo. Brez tega je §2.1 samo zgodba. | razvoj (aplikacija + strežnik) | Sodi k validacijskim signalom (VALIDATION_PLAN §4, hipoteza H7); zahteva vrstico v politiki zasebnosti. Med zamrznitvijo validacije – odloči David. |
| T2 | **Enostranski dokument »Trajnostni vpliv PetPrepa«** za priloge razpisom: trije stebri + izjava DNSH. | dokument | Brez kode. Pripravi Claude, ko je znan razpis. |
| T3 | **Okoljski odtis ljubljenčka kot vsebina:** tretji stolpec v kalkulatorju »Koliko dela je s psom« (hrana, iztrebki, vrečke; pri mačkah vpliv na ptice) + nekaj zanimivosti v »Ali ste vedeli?«. Samo z viri ravni A/B, v vseh 15 jezikih. | razvoj (spletna stran, nato aplikacija) | Naredi zeleno noto vidno. Najprej raziskava virov. |
| T4 | **Partnerstvo z zavetiščem:** pismo o nameri z enim ali dvema slovenskima zavetiščema (povezava do posvojitve v aplikaciji in na strani, zavetišče priporoča PetPrep pred posvojitvijo). | partnerstvo | Brez kode; razpisu da partnerja z imenom. Vpis v »PetPrep – partnerski kontakti«. |
| T5 | **Preveriti navedbe gostovanja** (Hetzner, lokacija strežnika) in jih z virom dodati v §2. | preverba | 15 minut. |

## 5. Odprta vprašanja za Davida

1. Za kateri razpis to potrebujemo najprej (poudarek na okoljskem ali družbenem delu)?
2. Ali T1 in T3 gradimo zdaj ali po validaciji?
3. Ali že obstaja stik z zavetiščem za T4?
