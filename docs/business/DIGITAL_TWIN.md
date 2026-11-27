# PetPrep – digitalni dvojček poslovanja

> **Status:** ideja (David, 26. 11. 2026: »naredili bomo digitalnega dvojčka poslovanja … ideja za več točk« na razpisih). Opis in predlog korakov je Claudov; **kdaj in v kakšnem obsegu se gradi, David še ni odločil.** Nič od §5 ni zgrajeno.
> **Dopolnjeno 27. 11. 2026** (David: »mora biti pravilno, profesionalno narejeno«): izrazje in ravni usklajeni s strokovnimi definicijami (§1), dodane prakse (§4), koraki razširjeni s popisom procesov, sinhronizacijo in preverjanjem modela (§5).
> **Namen:** (1) izhodišče za opis v prijavah na razpise (digitalna preobrazba), (2) načrt, da to ne bo samo beseda. Merila posameznega razpisa je treba prebrati v razpisni dokumentaciji – ta dokument jih ne povzema in ne jamči točk.
> **Povezano:** `SUSTAINABILITY.md` (zelena nota), `VALIDATION_PLAN.md` (hipoteze, mejniki prihodka), `DUOLINGO_ROVER_LESSONS.md` (ročne simulacije lijaka).

## 1. Kaj je digitalni dvojček (definicije, na katere se opiramo)

| Vir | Kaj pravi | Kaj to pomeni za nas |
|---|---|---|
| **Digital Twin Consortium** (definicija z dne 3. 12. 2020) | Dvojček je virtualna predstavitev resničnih entitet in procesov, usklajena z njimi z določeno pogostostjo in natančnostjo. Tri sestavine: virtualna predstavitev, resnični sistem, mehanizem usklajevanja. | Za vsak vir podatkov zapišemo pogostost osveževanja in natančnost. Model je lahko tudi baza ali enačbe – 3D ni pogoj. |
| **Kritzinger idr. (2018)** – stopnje integracije | *Digitalni model:* pretok podatkov v obe smeri ročen. *Digitalna senca:* samodejen iz resničnega sistema v digitalnega, nazaj ročen. *Digitalni dvojček:* samodejen v obe smeri. | Poimenujemo doseženo stopnjo, ne ciljne. Dvojček je šele, ko model tudi vpliva nazaj na poslovanje. |
| **ISO/IEC 30173:2023** *Digital twin — Concepts and terminology* | Mednarodni standard za izrazje in koncepte (življenjski cikel, tipi, deležniki). | V prijavah uporabljamo to izrazje. Standard je plačljiv; opiramo se na javni povzetek, celotnega besedila nismo brali. |
| **Gartner – digital twin of an organization (DTO)** | Dinamičen programski model organizacije, ki se opira na operativne in kontekstne podatke, da pokaže, kako organizacija izvaja svoj poslovni model; jedrni elementi so operativni model, podatki, enolično ujemanje z organizacijo in zmožnost spremljanja. | Naš dvojček je DTO: modelira **procese** (pot družine, proizvodnja medijev, dodajanje vsebine), ne samo finančnega lijaka. |

Viri: [digitaltwinconsortium.org – definicija](https://www.digitaltwinconsortium.org/hot-topics/the-definition-of-a-digital-twin/) · Kritzinger W. idr. (2018), *Digital Twin in manufacturing: A categorical literature review and classification*, IFAC-PapersOnLine 51(11) · [iso.org/standard/81442.html](https://www.iso.org/standard/81442.html) · Gartner, *Market Guide for Technologies Supporting a Digital Twin of an Organization* (povzeto po javnih opisih, poročila nismo brali). Dostop 27. 11. 2026.

### Stopnje pri PetPrepu

| Stopnja | Kaj je | Primer pri nas |
|---|---|---|
| **Digitalni model** | Ročno vnesene vrednosti, ročno branje izida | Ročne simulacije lijaka v dokumentih; kalkulator z drsniki brez živih podatkov |
| **Digitalna senca** | Model se sam polni iz sistemov; odločitve sprejemamo ročno | Stran »Poslovanje« iz živih tabel; simulator nad živimi podatki |
| **Digitalni dvojček** | Model se sam polni **in** sam predlaga ali nastavi parametre poslovanja (s potrditvijo) | Predlogi za proračun AI, obseg nočnih opravil, porabo za oglase, ki se po potrditvi uveljavijo |

**V prijavah navedemo doseženo stopnjo in ciljno stopnjo z rokom.** Nadzorna plošča ni dvojček.

## 2. Kaj že imamo (deli digitalne sence)

| Področje | Kje | Sinhronizacija danes |
|---|---|---|
| Lijak družin po tednu registracije | `/admin/funnel` (`FunnelService`) | ob odprtju strani, iz baze |
| Strošek AI po klicu | `ai_spend_ledger`, stran »AI budget« | sproti (vsak klic) |
| Plačila | `purchase_events`, `challenge_credits`, naročila trgovine (Stripe) | sproti (webhook) |
| E-naslovi s spletne strani | `email_contacts`, `email_signups` | sproti |
| Zdravje sistema | `/admin/system-health`, napake aplikacije, Discord | sproti |
| Iskanje | mesečno poročilo Search Console (`seo-report.yml`) | mesečno |
| Ročne simulacije | `VALIDATION_PLAN.md` §8, `DUOLINGO_ROVER_LESSONS.md` | ročno – digitalni model |

Manjka: en skupen model nad temi viri, zapisana sinhronizacija, simulator, preverjanje napovedi in povratna smer.

## 3. Kaj dvojček modelira

**Procesi (operativni model)**
- Pot družine: obisk → registracija → otrok → ljubljenček → dnevna skrb → nakup → 12 tednov → certifikat → odločitev o pravi živali.
- Proizvodnja medijev: videz (pasma + barva + obdobje) → generiranje → preverjanje kakovosti → uporaba pri več družinah.
- Vsebina: nova pasma / jezik / živilo → raziskava z viri → PR → register na spletni strani → iskanje.
- Prodaja: aplikacija (trgovini) in splet (Stripe) → krediti → dodelitev ljubljenčku.

**Ekonomika**
- Prihodek po toku (izziv, nadgradnja mešančka, Želje, boni, komplet), po viru prihoda in državi.
- Strošek: AI na videz in na družino, gostovanje, provizije trgovin, oglasi.
- Enota: prispevek na plačljivo družino in točka preloma.

**Vzvodi za simulacijo:** cena, konverzije po korakih, delež skupnih videzov, število pasem in jezikov, proračun za oglase, delež spletne prodaje.

## 4. Prakse, ki se jih držimo

1. **Namen najprej.** Seznam odločitev, ki jih dvojček podpira; kar ne podpira nobene odločitve, ne gre vanj.
2. **Procesi, ne samo denar** (§3) – sicer je to finančna preglednica.
3. **Zapisana sinhronizacija:** za vsak vir pogostost, zamik in natančnost; stran pokaže čas zadnje osvežitve.
4. **En vir resnice:** vsaka metrika ima eno definicijo, eno poizvedbo in lastnika; definicije so v gitu.
5. **Preverjanje modela:** napoved se shrani in po izteku obdobja primerja z dejanskim izidom; napaka se beleži in prikaže. Model brez preverjanja nima teže.
6. **Povratna smer s človekom v zanki:** dvojček predlaga, David potrdi, sprememba se zabeleži (kdo, kdaj, prej → potem). Brez samodejnih sprememb cen ali proračunov brez potrditve.
7. **Zasebnost:** samo zbirni podatki družin; nič o posameznem otroku; brez novega sledenja v aplikaciji in brez analitičnega SDK (CLAUDE.md).
8. **Pošteno poimenovanje:** navajamo doseženo stopnjo (§1), ne ciljne.
9. **Majhni koraki:** vsak korak je uporaben sam zase; naslednji se začne, ko prejšnji daje podatke.

## 5. Predlog korakov (neodločeno)

| # | Korak | Stopnja po §1 | Pogoj |
|---|---|---|---|
| D1 | **Popis:** odločitve, ki jih dvojček podpira; procesi iz §3; viri s pogostostjo in natančnostjo sinhronizacije; slovar metrik (kaj je »plačljiva družina«, »aktivna«, »strošek na videz«) | priprava | brez kode; uporabno tudi kot priloga prijavi |
| D2 | **Stran »Poslovanje«** v administraciji: prihodek po tokovih, strošek AI, prispevek na družino, točka preloma, čas zadnje osvežitve – iz obstoječih tabel, brez novega sledenja | digitalna senca | majhen razvoj (backend) |
| D3 | **Simulator »kaj če«** nad živimi podatki: vzvodi iz §3, primerjava scenarijev, izvoz za prijave in vlagatelje | senca s simulacijo | smiselno šele s podatki vala 1 (pravi plačniki) |
| D4 | **Preverjanje napovedi:** shranjene napovedi, primerjava z dejanskim izidom, prikaz napake | senca, verodostojna | po D3, ko mine prvo obdobje |
| D5 | **Povratna zanka:** predlogi za proračun AI, obseg nočnih opravil in oglase; po Davidovi potrditvi se uveljavijo in zabeležijo | **digitalni dvojček** | po D4 |

## 6. Zadržki

1. **Brez plačnikov dvojček simulira samo predpostavke.** D3 pred valom 1 bi bil model brez podatkov.
2. **Zamrznitev zaradi validacije** (`VALIDATION_PLAN.md` §2): D2–D5 so nov razvoj; kdaj, odloči David.
3. **Ocenjevalci razpisov** ločijo dvojčka od nadzorne plošče – v prijavi navedemo stopnjo, definicijo iz §1 in dokaz (posnetek zaslona, opis modela, D1).
4. **Meril razpisov nismo preverili;** ali posamezen razpis digitalnega dvojčka točkuje, je treba prebrati v razpisni dokumentaciji.

## 7. Odprta vprašanja za Davida

1. Za kateri razpis (in ali ga razpis izrecno omenja ali je to dodatna točka pri digitalni preobrazbi)?
2. Ali D1 in D2 naredimo pred izidom ali po valu 1?
3. Ali naj dvojček zajema samo PetPrep ali tudi druge dejavnosti podjetja?
