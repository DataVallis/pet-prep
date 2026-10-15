# <Ime pasme SL> (<Breed name EN>)

<!--
Predloga za en dokument pasme (docs/breeds/<en-slug>.md; <en-slug> = slug.en iz
docs/research/breed-registry.json, npr. hungarian-vizsla.md). Piše ga nenadzorovani zagon pasme
(ADD_BREED_RUNBOOK §4 "Dokument pasme") — to je EDINI dokument, ki ga zagon pasme napiše.
Skupnih dokumentov (DECISIONS, FEATURES, HANDOFF, BUILD_LOG, PRODUCT_SPEC, audiences/*, ROADMAP,
ARCHITECTURE, DIAGRAMS) zagon pasme NE ureja. Po urejanju: node scripts/breeds-index.mjs.
Slovenščina, vikanje staršev; samo dejstva — nič nezgrajenega ne sme brati kot na voljo.
Brez zdravstvenih odstotkov / razmerij obetov; brez osebnih podatkov otrok.
-->

**Stanje:** 🛠 zgrajena v kodi (na telefonu še ni preverjeno) · plačljiva (12-tedenski izziv) · ROADMAP M5-R10-NN · YYYY-MM-DD

| | |
|---|---|
| Ključ / slug | `<enum_value>` / `<breed_configs slug>` |
| Vrsta | pes / mačka |
| Brezplačno / plačljivo | plačljiva (izziv) |
| Vir podatkov | `docs/research/<dog\|cat>-data/data.json` → `<key>` (viri S…) |
| PR-ji | pet-prep #… (pasma), #… (portret) · spletna stran #… |

## Odločitve (z viri in kdo je odločil)

Ena vrstica na odločitev, kot v DECISIONS.md.

| Datum | Kdo | Odločitev | Zakaj / vir | PR |
|---|---|---|---|---|
| YYYY-MM-DD | pravilo runbooka (potrdil David 2026-10-10) | gibanje odraslega N min/dan → N × 100 korakov | S… (citat v data.json) | #… |
| YYYY-MM-DD | pravilo runbooka | starostne meje mladiček / mlad / odrasel / senior: … mesecev | S… | #… |
| YYYY-MM-DD | pravilo runbooka | oznake primernosti: … ; upoštevajte: … | citati S… | #… |
| YYYY-MM-DD | pravilo runbooka (§3) | marketing: sme / ne sme (sploščen obraz → ne, BVA S92) | Breed Watch kategorija …, vir … | #… |
| YYYY-MM-DD | predlog Claude, čaka Davida | … | … | #… |

## Kaj je v aplikaciji (v slogu FEATURES)

| Funkcija | Stanje | Brezplačno / plačljivo | ID | Datum |
|---|---|---|---|---|
| Pasma v izbirniku (iskanje, oznake, starostni namigi) | 🛠 | 💶 izziv | M5-R10-NN | YYYY-MM-DD |
| Pravila po starosti (koraki, obroki, česanje …) | 🛠 | 💶 izziv | M5-R10-NN | YYYY-MM-DD |
| Barve (izbirnik barve) | 🛠 | 💶 izziv | M5-R21 | YYYY-MM-DD |
| AI portret v registru | 🗓 po naslednji produkcijski objavi | — | M5-R11-04 | — |
| Stran na petprep.si | 🗓 »Kmalu«, dokler pasma ni v objavi | — | M5-R11 | — |

## Zgodba (v slogu BUILD_LOG)

**Kaj:** … (prave številke: koraki, obroki, starostne meje, število virov).
**Zakaj je pomembno:** …
**Kako povedati** — starši: … · otroci: … · vlagatelji / partnerji: …

## Odprto za Davida

- … (predlogi `verified=false`, manjkajoči viri, portret, trženjska odločitev pri pasmah s sploščenim obrazom)
