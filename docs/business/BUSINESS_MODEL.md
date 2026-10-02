# PetPrep — Poslovni model, monetizacija in go-to-market

> Kanonski povzetek poslovnih dokumentov (`docs/source/`: MAIN dokumentacija, Grand Slam Offer, Upsales, Go To Market, Tranzicija). Številke v §5 so **predpostavke iz dokumentov, ne izmerjeni podatki**.

## 1. Pozicioniranje

Ne prodajamo "igrice", ampak **orodje za oceno zrelosti in zavarovalno polico proti slabi odločitvi o živali**. Kategorija: starševski nadzor + ocena pripravljenosti. Sporočilo staršem: "Ne kupujte otroku psa, dokler ne opravi tega preizkusa." Sporočilo investitorjem: "risk-reversal platforma za pet industrijo".

## 2. Ponudba (Grand Slam Offer)

**Cena: 49,99 €** — 12-tedenski PetPrep izziv (dostop za starša in otroka)

| Element | Opis |
|---|---|
| Core | 12-tedenska simulacija z dvojnim profilom |
| Bonus 1 | "Real Cost of a Dog" kalkulator |
| Bonus 2 | "Breed Matchmaker" — 12-tedenske metrike otroka → primerna pasma |
| Bonus 3 | Fizična plastificirana "Licenca za hišnega ljubljenčka" po pošti ob uspehu |
| Garancija | "Real-World Relief": če otrok dobi certifikat, kupite psa, po 6 mesecih pa vse delo opravljate vi → vračilo 49,99 € + 100 € za varuško psa |
| Early win | 7. dan "Puppy Promoter" značka staršu |
| Urgentnost | kohorte ("naslednji virtualni legel se rodi v ponedeljek, 500 družin") |
| Alternativa | 9,99 €/mes z minimalno vezavo 3 mesece |

## 3. Backend monetizacija

- **Second Chance reset** 19,99 € (ob game overu) / **Breed Downgrade** brezplačno.
- **IAP consumables:** veterinar 1,99–2,99 € ("v resnici bi stalo 150 €"), priboljški 0,99 € / 10×.
- **IAP non-consumables:** igrače 1,49 € (počasnejši upad gibanja), odklep pasem.
- **B2B affiliate:** ob certifikatu QR kupon partnerja (Mr. Pet, Zootic) — npr. 20 % na starter kit; PetPrep dobi CPA provizijo.
- **Faza 2:** AI asistent 3,99 €/mes, affiliate trgovina, zavarovanja (lead gen), booking provizije.

## 4. Lijak (funnel)

1. **Oglas** (Meta: "Pet adoption" + starši otrok 7–12; TikTok / Shorts z AI videi) → hook: "Ne kupujte otroku psa, dokler ne podpiše te pogodbe."
2. **Lead magnet "Pet Promise Reality Check"** — brezplačno spletno orodje: otrok izbere pasmo, odgovarja na ostra vprašanja ("Boš vstal ob 6:00, ko sneži?") → "Real World Burden Score" → PDF pogodba ("14 ur dela na teden, 1.200 € na leto. Podpiši.") → email.
3. **Handoff** na zahvalni strani: "Mislite, da bo zdržal dlje kot 2 tedna? Preizkusite ga v 12-tedenskem simulatorju."
4. **Mom-influencerji** (UGC, plačilo po uspešnosti: 1 € / lead, 20 % od prodaje) po skripti Before & After.
5. **Gverilski marketing** v FB skupinah, **#PetPrepChallenge** (deljenje zelenega semaforja), **waitlist** "prvih 500 družin".

## 5. Enotna ekonomika (predpostavke iz dokumentov)

| Postavka | Vrednost |
|---|---|
| CPA lead (lead magnet) | 1,50 € |
| Lead → nakup | 10 % |
| CAC | 15,00 € |
| Cena core | 49,99 € |
| Backend (15 % × 19,99 € + IAP) | +3,00 € |
| AOV | 52,99 € |
| ROAS (front-end) | ~3,5× |

**Kar v izračunu manjka (dopolniti pred pitch deckom):**
- Provizija App Store / Google Play (15 % small business program, sicer 30 %) **ali** spletna prodaja (Stripe ~1,5–3 %).
- DDV (SI 22 %) — 49,99 € s DDV = 40,97 € neto.
- Strošek AI medijev na psa (fal.ai), strežnik, push, RevenueCat (od določenega prometa 1 %).
- Refundacije in garancija (100 € izplačila).
- Realni benchmark: 10 % lead→sale je optimistično za 49,99 € (hladen promet); validirati na waitlisti.

## 6. KPI-ji za MVP

- CPA (cilj < 1,50 € za lead), konverzija na plačilo, **D1 / D7 retencija otrok**, delež dni z vsemi rutinami, delež staršev, ki odprejo dashboard ≥ 3× / teden, game-over rate (to je *feature* — visok osip potrjuje tezo, a starš mora to doživeti kot vrednost).

## 7. Odločitve

### Sprejete (David, 2. 10. 2026)

| # | Odločitev |
|---|---|
| B1 | **Kanonski model: 12-tedenski PetPrep izziv za 49,99 €** s **7-dnevnim brezplačnim preizkusom**. |
| B1a | **Mešanček ostane vedno brezplačen** (free tier za vedno). Plačljiv izziv je nadgradnja, ne edini način uporabe. |
| B3 | Preizkus traja 7 dni in sovpada s "Puppy Promoter" momentom → paywall na 7. dan. |
| L1 | Jezik aplikacije: **angleščina (privzeto) + slovenščina**; ostali jeziki kasneje. |

### Predlog razmejitve brezplačno / plačljivo (potrdi ali popravi)

| | Mešanček (free, za vedno) | 12-tedenski izziv (49,99 €, 7 dni brezplačno) |
|---|---|---|
| Simulacija psa, 4 metrike, akcije, koraki | ✓ | ✓ |
| Eskalacija, bolezen, game over, hard stop, tihe ure | ✓ | ✓ |
| Starševska nadzorna plošča (živo) | ✓ (zgodovina 7 dni) | ✓ (celotna zgodovina, tedenska poročila) |
| AI video psa | statični nabor videov | personaliziran AI pes (Pet DNA) |
| Border Collie (zahtevna pasma) | – | ✓ |
| 12-tedenski program s starostjo in ciljem | – (neomejen "peskovnik") | ✓ |
| Puppy Promoter značka (7. dan), Certifikat odgovornosti (12. teden) | – | ✓ |
| Bonusi (Real Cost kalkulator, Breed Matchmaker, fizična licenca) | kalkulator kot lead magnet na webu | ✓ |
| Breed Downgrade reset | ✓ | ✓ |
| Second Chance reset (19,99 €) | – | po MVP |

Tehnično (RevenueCat): en entitlement `challenge`, produkt non-consumable `petprep_challenge_12w` (49,99 €), 7-dnevni preizkus kot "trial" stanje na strežniku (`trial_started_at`), ker non-consumable IAP nima vgrajenega triala. Alternativa: naročnina 3 × 9,99 € z vgrajenim 7-dnevnim introductory trialom — **(D)** izberi, ko nastavljamo App Store produkte.

### Še odprte

| # | Vprašanje | Priporočilo orkestratorja |
|---|---|---|
| B2 | IAP v aplikaciji vs. spletni checkout | Oboje: web checkout (lead magnet → Stripe → aktivacijska koda) za oglase; IAP v aplikaciji za organske prenose |
| B4 | Prvi trg | Slovenija + Hrvaška za validacijo (nizek CPM), nato DE / AT / UK |
| B5 | Garancija 100 € | Obdržati v copyju, pogoje pravno preveriti |
| B6 | Mr. Pet partnerstvo | Pogovor po prvih 50 certifikatih |
| B7 | Non-consumable + strežniški trial vs. naročnina z IAP trialom | Non-consumable (jasnejša ponudba "enkratno 49,99 €") |

## 8. Prihodnji materiali (orkestrator lahko pripravi)

- Pitch deck (investitorji / pospeševalniki), one-pager.
- Landing page + waitlist, lead magnet (spletno orodje + PDF pogodba).
- Oglasne kreative in copy (Meta, TikTok), skripte za influencerje.
- Finančni model (Sheets) s scenariji.
- Politika zasebnosti in pogoji (osnutek za pravnika).
