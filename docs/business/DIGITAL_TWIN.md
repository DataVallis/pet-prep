# PetPrep – digitalni dvojček poslovanja

> **Status:** ideja (David, 26. 11. 2026: »naredili bomo digitalnega dvojčka poslovanja … ideja za več točk« na razpisih). Opis in predlog korakov je Claudov; **kdaj in v kakšnem obsegu se gradi, David še ni odločil.** Nič od §4 ni zgrajeno.
> **Namen:** (1) izhodišče za opis v prijavah na razpise (digitalna preobrazba), (2) načrt, da to ne bo samo beseda. Merila posameznega razpisa je treba prebrati v razpisni dokumentaciji – ta dokument jih ne povzema in ne jamči točk.
> **Povezano:** `SUSTAINABILITY.md` (zelena nota), `VALIDATION_PLAN.md` (hipoteze, mejniki prihodka), `DUOLINGO_ROVER_LESSONS.md` (ročne simulacije lijaka).

## 1. Kaj je dvojček in kaj ni

Digitalni dvojček poslovanja je živ model podjetja, ki se sam polni iz sistemov in na katerem odločitev preizkusimo, preden jo sprejmemo.

| Raven | Kaj je | Je to dvojček? |
|---|---|---|
| 1. Zrcalo | Vsi podatki na enem mestu, sproti | Ne – to je nadzorna plošča |
| 2. Model s simulacijo | »Kaj se zgodi, če …« nad živimi podatki (cena, konverzija, strošek AI, število pasem, oglasi) | Da – tu se dvojček začne |
| 3. Povratna zanka | Model predlaga ali sam nastavi parametre (proračun AI, obseg nočnih opravil, poraba za oglase) | Da – polni dvojček |

V prijavah trdimo samo raven, ki dejansko obstaja; ostalo opišemo kot načrtovano aktivnost.

## 2. Kaj že imamo (raven 1, po delih)

| Področje | Kje | Opomba |
|---|---|---|
| Lijak družin po tednu registracije | `/admin/funnel` (`FunnelService`) | registracija → otrok → ljubljenček → aktivnost → nakup → 4. teden → certifikat; viri prihoda, H6, H7 |
| Strošek AI po klicu | `ai_spend_ledger`, stran »AI budget« | dnevni / mesečni proračun, opozorila pri 80 % in 100 % |
| Plačila | `purchase_events`, `challenge_credits`, naročila trgovine (Stripe) | aplikacija prek RevenueCat, splet prek Stripe |
| E-naslovi s spletne strani | `email_contacts`, `email_signups` | vir, namen, pasma, jezik, UTM |
| Zdravje sistema | `/admin/system-health`, napake aplikacije, Discord | |
| Iskanje | mesečno poročilo Search Console (`seo-report.yml`) | |
| Ročne simulacije | `VALIDATION_PLAN.md` §8, `DUOLINGO_ROVER_LESSONS.md` | mejniki 6 / 60 / 150 prodaj na mesec; niso vezane na žive podatke |

Manjka: en skupen model nad temi viri in simulator.

## 3. Kaj bi dvojček modeliral

- **Prihodek:** obiski → registracije → ljubljenčki → nakupi (izziv, nadgradnja mešančka, Želje, boni, komplet) po viru prihoda in državi.
- **Strošek:** AI na videz (pasma + barva + obdobje) in na družino, gostovanje, trgovine (provizije), oglasi.
- **Enota:** prispevek na plačljivo družino in točka preloma (danes ~200 € stroškov na mesec, brez Davidovega plačila).
- **Vzvodi za simulacijo:** cena, konverzije po korakih lijaka, delež skupnih videzov, število pasem in jezikov, proračun za oglase, delež spletne prodaje.

## 4. Predlog korakov (neodločeno)

| # | Korak | Raven | Pogoj |
|---|---|---|---|
| D1 | Popis virov in metrik: en dokument s definicijami (kaj je »plačljiva družina«, »aktivna«, »strošek na videz«) | priprava | brez kode |
| D2 | Stran »Poslovanje« v administraciji: prihodek, strošek AI, prispevek na družino, točka preloma – iz obstoječih tabel, brez novega sledenja | 1 | majhen razvoj (backend) |
| D3 | Simulator »kaj če« nad živimi podatki: drsniki za vzvode iz §3, primerjava scenarijev, izvoz za prijave in vlagatelje | 2 | smiselno šele s podatki vala 1 (pravi plačniki) |
| D4 | Povratna zanka: predlogi za proračun AI in obseg nočnih opravil glede na prihodek | 3 | po D3; samodejne spremembe samo z Davidovo potrditvijo |

## 5. Zadržki

1. **Brez plačnikov dvojček simulira samo predpostavke.** D3 pred valom 1 bi bil model brez podatkov.
2. **Zamrznitev zaradi validacije** (`VALIDATION_PLAN.md` §2): D2–D4 so nov razvoj; kdaj, odloči David.
3. **Zasebnost:** model dela samo z zbirnimi podatki družin, brez podatkov o otrocih in brez novega sledenja v aplikaciji (CLAUDE.md: brez analitičnega SDK).
4. **Ocenjevalci razpisov** ločijo dvojčka od nadzorne plošče – v prijavi navedemo raven in dokaz (posnetek zaslona, opis modela).

## 6. Odprta vprašanja za Davida

1. Za kateri razpis (in ali ga razpis izrecno omenja ali je to dodatna točka pri digitalni preobrazbi)?
2. Ali D1 in D2 naredimo pred izidom ali po valu 1?
3. Ali naj dvojček zajema samo PetPrep ali tudi druge dejavnosti podjetja?
