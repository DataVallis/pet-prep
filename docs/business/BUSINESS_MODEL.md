# PetPrep — Poslovni model, monetizacija in go-to-market

> Kanonski povzetek poslovnih dokumentov (`docs/source/`: MAIN dokumentacija, Grand Slam Offer, Upsales, Go To Market, Tranzicija). Številke v §5 so **predpostavke iz dokumentov, ne izmerjeni podatki**.

## 1. Pozicioniranje

Ne prodajamo "igrice", ampak **orodje za oceno zrelosti in zavarovalno polico proti slabi odločitvi o živali**. Kategorija: starševski nadzor + ocena pripravljenosti. Sporočilo staršem: "Ne kupujte otroku psa, dokler ne opravi tega preizkusa." Sporočilo investitorjem: "risk-reversal platforma za pet industrijo".

## 1a. Segmenti

| Segment | Kdo | Vprašanje, na katerega odgovori PetPrep | Od kdaj |
|---|---|---|---|
| Družine (glavni) | Starši otrok ~7–12 (do 16), ki jih otrok prosi za žival | »Je otrok res pripravljen?« | od začetka |
| Odrasli, ki izbirajo pasmo (dodatni) | Odrasel, ki si želi točno določeno pasmo in jo hoče preizkusiti pred nakupom ali posvojitvijo | »Je ta pasma res zame — za moje delo in življenje?« | David, 9. 10. 2026 |

Odrasli uporabljajo isti izdelek brez spremembe aplikacije: račun starša → doda sebe kot profil skrbnika → prijava s kodo (isti ali drug telefon); kasneje lahko doda partnerja ali otroke. **Ista cena** (mešanček brezplačen, izziv 49,99 € na žival). Danes sta na voljo mešanček in border collie. Velikost segmenta in konverzija **nista izmerjeni**; kanali za ta segment (npr. skupine ljubiteljev pasem, vzreditelji) so *načrt*.

## 2. Ponudba (Grand Slam Offer)

**Cena: 49,99 € na psa** — 12-tedenski PetPrep izziv; dostop za vse starše in vse otroke, ki skrbijo za tega psa (skupni pes = ena cena). Drugi pes v družini = nov izziv. Mešanček brezplačen (David, 4. 10. 2026).

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
- **Žetoni za AI medije (David, 4. 10. 2026, *načrt*):** osnovni nabor slike + videov ob rojstvu je vključen; za dodatne slike/videe starš kupi paket žetonov (npr. x videov, y slik). Cena paketa mora pokriti strošek modela z maržo (orientacijsko na fal.ai: slika ~0,03–0,05 $, video ~0,05–0,40 $ na sekundo glede na model — preveriti ob izbiri modela).
- **Faza 2:** AI asistent 3,99 €/mes, affiliate trgovina, zavarovanja (lead gen), **veterinarji (AI prvi stik → preusmeritev na partnerskega veterinarja: lead-i, telemedicina, provizija ali članarina ambulante)**, booking provizije. Podrobno: `docs/product/PHASE2_SPEC.md`.

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
| B1 | **Kanonski model: 12-tedenski PetPrep izziv za 49,99 €** ~~s 7-dnevnim brezplačnim preizkusom~~ — *preizkus odstranjen 8. 10. 2026 (M3-13, glej P11).* |
| B1a | **Mešanček ostane vedno brezplačen** (free tier za vedno). Plačljiv izziv je nadgradnja, ne edini način uporabe. |
| ~~B3~~ | ~~Preizkus traja 7 dni in sovpada s "Puppy Promoter" momentom → paywall na 7. dan.~~ **Nadomeščeno z P11 (8. 10. 2026):** paywall je pred začetkom izziva; Puppy Promoter značka (7. dan) ostane del plačanega izziva. |
| L1 | Jezik aplikacije: **angleščina (privzeto) + slovenščina**; ostali jeziki kasneje. |

### Sprejete (David, 7. 10. 2026 — podrobno [`PAYMENTS_SPEC.md`](../product/PAYMENTS_SPEC.md))

| # | Odločitev |
|---|---|
| P1 | **Nakup = en 12-tedenski izziv za enega psa**, vsak nov izziv (nov otrok ali nov pes) znova 49,99 €. V trgovinah **consumable** `petprep_challenge_12w` (RevenueCat); strežnik vodi, kateremu psu pripada. **B7 rešen.** |
| ~~P2~~ | ~~7-dnevni brezplačni preizkus se začne **ob rojstvu psa** (podpis pogodbe), samo za pse na izzivu.~~ **Nadomeščeno z P11.** |
| P3 | Brez nakupa se igra **ustavi** (pes čaka zaklenjen kot pri hard stopu, napredek ostane), dokler starš ne kupi — od P11 že ob rojstvu. |
| P4 | **Razmejitev spodaj potrjena**: mešanček brezplačen za vedno (peskovnik brez 12-tedenskega programa); izziv = program, Border Collie, certifikat, celotna zgodovina, personaliziran AI pes. |

### Sprejete (David, 8. 10. 2026, 10:28 — M3-13)

| # | Odločitev |
|---|---|
| P11 | **7-dnevnega brezplačnega preizkusa izziva ni več.** Brezplačni mešanček je brezplačni preizkus PetPrepa; **12-tedenski izziv se začne z nakupom** (49,99 € na psa, enkratno, brez naročnine). Pes na izzivu čaka zaklenjen od rojstva (podpis pogodbe), dokler starš ne kupi; kupi lahko tudi prej. 12 tednov začne teči ob nakupu. V produkciji velja od 8. 10. 2026 (beta, ~20 testerjev plačuje v sandboxu). **Posledica za trženje:** spletna stran, opisi v trgovinah in vsi materiali, ki obljubljajo »7 dni brezplačno«, se morajo popraviti (seznam v `HANDOFF.md`). |

### Razmejitev brezplačno / plačljivo (potrdil David 7. 10. 2026, P4)

| | Mešanček (free, za vedno — brezplačni preizkus) | 12-tedenski izziv (49,99 €, začne se z nakupom) |
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

Tehnično (RevenueCat, **odločeno 7. 10. 2026, P1**): produkt **consumable** `petprep_challenge_12w` (49,99 €) — vsak nakup postane »kredit izziva« družine na strežniku, ki se dodeli enemu psu (`challenge_credits`, M3-11). Brezplačnega preizkusa izziva od 8. 10. 2026 ni (P11; prej strežniški `pets.trial_ends_at` = rojstvo + 7 dni, zdaj = rojstvo). Naročnina ni izbrana.

### Še odprte

| # | Vprašanje | Priporočilo orkestratorja |
|---|---|---|
| B2 | IAP v aplikaciji vs. spletni checkout | Oboje: web checkout (lead magnet → Stripe → aktivacijska koda) za oglase; IAP v aplikaciji za organske prenose |
| B4 | Prvi trg | Slovenija + Hrvaška za validacijo (nizek CPM), nato DE / AT / UK |
| B5 | Garancija 100 € | Obdržati v copyju, pogoje pravno preveriti |
| B6 | Mr. Pet partnerstvo | Pogovor po prvih 50 certifikatih |
| ~~B7~~ | ~~Non-consumable + strežniški trial vs. naročnina z IAP trialom~~ | **Rešeno 7. 10. 2026 (P1):** consumable na psa + strežniški trial (trial odstranjen 8. 10. 2026, P11) |

## 8. Prihodnji materiali (orkestrator lahko pripravi)

- Pitch deck (investitorji / pospeševalniki), one-pager.
- Landing page + waitlist, lead magnet (spletno orodje + PDF pogodba).
- Oglasne kreative in copy (Meta, TikTok), skripte za influencerje.
- Finančni model (Sheets) s scenariji.
- Politika zasebnosti in pogoji (osnutek za pravnika).
