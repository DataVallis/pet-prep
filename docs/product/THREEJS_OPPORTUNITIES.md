# PetPrep — three.js: kje se splača in kje ne

> **Zapisano 15. 11. 2026 (pogovor David + Claude).** David je vprašal, kje lahko three.js (https://threejs.org/docs/) naredi korak naprej v interakciji in zanimivosti, tudi poslovno. Analiza je Claudova; Davidove odločitve so označene **(David)**.
> **Stanje:** prototip št. 1 je narejen (§4) in je v repozitoriju kot `docs/prototypes/real-cost/`. Ni v aplikaciji, ni na strežniku in ni na petprep.si.
> Povezano: `GAMES_SPEC.md` (smer »pes ostane video«), `docs/business/VALIDATION_PLAN.md` §2 (kaj je zamrznjeno), `docs/business/REVENUE_MODELS.md` (R3 zavarovalnice, R4 spletna stran), `docs/business/BUSINESS_MODEL.md` §2 in §4 (kalkulator »Real Cost of a Dog«, lead magnet »Pet Promise Reality Check«), `docs/business/WEBSITE_SALES_SPEC.md` (Lemon Squeezy, brezplačen vzorec za e-naslov).

## 1. Sklep

Three.js se pri PetPrepu splača **na petprep.si**, ne v aplikaciji in ne za samega ljubljenčka.

- Three.js je dober za prizore, fiziko in predmete. Za fotorealističnega psa z dlako v 81 pasmah ni primeren, zato odločitev »pes ostane video, igra postane interaktiven sloj« (David, 30. 10. 2026) ostane.
- Spletna stran je Next.js, kjer three.js teče brez nove gradnje v trgovinah in brez spremembe prstnega odtisa aplikacije.

## 2. Zakaj ne v aplikaciji zdaj

| Razlog | Podrobnost |
|---|---|
| Sloj za igre že obstaja | Skia + Reanimated (GAMES_SPEC §7) pokrivata žogo, pero in čiščenje pri 60 fps. |
| Nova nativna odvisnost | Three.js v React Native gre prek `@react-three/native` (expo-gl) ali `react-native-wgpu` (React Native ≥ 0.81, samo nova arhitektura). Oboje pomeni novo gradnjo v trgovinah in večjo aplikacijo. |
| Zamrznitev validacije | »Ljubljenček v tvoji sobi« in 2,5D ljubljenček sta na seznamu zamrznjenega (VALIDATION_PLAN §2). |
| Ozko grlo ni interakcija | Aplikacija še ni v trgovinah in plačljivih družin še ni; 3D v aplikaciji ne premakne nakupa. |

## 3. Priložnosti

| # | Kaj | Poslovni učinek | Kdaj |
|---|---|---|---|
| 1 | **»Koliko dela in denarja je pes« v 3D** na petprep.si | Lead magnet, ki je v poslovnem modelu, a ni zgrajen. Vodi v »Obljubo o ljubljenčku«, darilni bon in izziv. Ima sponzorsko mesto za zavarovalnico (fiksno sponzorstvo, R3). | **prototip narejen 15. 11. 2026** (§4) |
| 2 | **Primerjalnik velikosti v registru pasem** (pasma v merilu ob otroku, kavču, vratih) | Višine in teže z viri so že v registru. Edinstvena vsebina za SEO in deljenje na vsaki strani pasme. | predlog |
| 3 | **Demo v brskalniku »2 minuti s kužkom«** (obstoječi AI posnetki + interaktivni sloj) | Aplikacija je »coming soon«; demo zbira čakalni seznam že zdaj (oglasi, Product Hunt). Novih AI stroškov ni. | predlog |
| 4 | **Ljubljenček z globino** (ključna slika + globinska karta, odziv na nagib in dotik) | Davidova ideja 2,5D (ROADMAP M5-R07); pes ostane identičen. Prototip v Skii, brez three.js. | po validaciji |
| 5 | **3D kotiček ljubljenčka** (stvari iz »Želja«, medalje) | Dvigne vrednost »Želja« brez AI stroška na dizajn. Tveganje: nasprotuje pozicioniranju »otrok ni ves dan na telefonu« in meša realističen videz z risanim. | po validaciji, če sploh |

Številke 1–3 niso na zamrznjenem seznamu, a tudi niso na seznamu dovoljenega. Upravičijo se kot kanal pridobivanja za val 2 (plačani oglasi); to je Davidova odločitev.

### Kaj ne
- 3D model ljubljenčka (že zavrnjeno v M5-R07: drago in manj podobno videu).
- »Kuža na obisku« s three.js: načrtovana pot (fotografija sobe → urejanje slike → Kling) ohrani realizem.
- Curio v 3D na domači strani: krepi zmedo med maskoto in realističnim psom v aplikaciji (odločitev 25. 10. 2026).

## 4. Prototip št. 1 — »Koliko dela je labradorec?« (David: »ok daj ja«, 15. 11. 2026)

- **Kje:** izvorna koda `docs/prototypes/real-cost/` (navodila v tamkajšnjem `README.md`); objavljen tudi kot zasebna stran Claude za Davida. Ni na petprep.si.
- **Tehnika:** three.js 0.187.0 in cannon-es 0.20.0 (fizika), brez strežnika. SL + EN, namizje in telefon, gumbi delujejo s tipkovnico, upošteva »zmanjšaj gibanje«. Preverjeno v brskalniku brez zaslona (namizje 1360 px, telefon 390 px), ne na pravem telefonu.
- **Potek:** (1) izbira odrasel pes / mladiček 2 meseca → (2) en dan se odvrti (ura, obroki, sprehod, kakec, vaja; pri mladičku »ven« vsaki 2 uri), vsako opravilo pade kot žeton na kup → (3) kup se pomnoži na teden, leto in 13 let → (4) »Za starše: koliko stane« z drsnikoma, izbiro valute in kupom kovancev + **mesto za partnerja** → (5) »Bi zmogel vsak dan?« s seznamom obljub, gumboma do izziva in »Obljube o ljubljenčku« ter poljem za e-naslov starša (v prototipu ne pošlje ničesar).
- **Figura psa je namenoma igrača** iz osnovnih oblik, z napisom »V aplikaciji PetPrep je ljubljenček realističen.«

### Številke v prototipu in njihov izvor
| Številka | Izvor |
|---|---|
| Odrasel labradorec 90 min gibanja na dan | Guide Dogs UK (S59), PRODUCT_SPEC §5 |
| 2 obroka (odrasel), 4 obroki (mladiček 2 meseca) | S18 / S19, PRODUCT_SPEC §5 |
| Mladiček zdrži 1 uro na mesec starosti → 7× ven med 7:00 in 19:00 | S30 / S31 (število 7 je izpeljano) |
| Mladiček 20 min gibanja (10 min × mesec) | pravilo, ki ga je potrdil David 5. 10. 2026 |
| Vaja 5 min na dan | S36 / S37 (vaje 5–10 min, spodnja meja) |
| Krtačenje 1× na teden | vir labradorca, PRODUCT_SPEC §5 (M5-R13) |
| Življenjska doba 13,1 leta | McMillan 2024 (S54) |
| 100 korakov na minuto, 2 vrečki na dan | **pravili simulacije PetPrep, ne vir** (v prototipu označeno »pravilo PetPrep«) |
| Stroški: najmanj 116 £ na mesec, 560 £ na začetku, velik pes | PDSA, »The cost of owning a dog«, https://www.pdsa.org.uk/pet-help-and-advice/looking-after-your-pet/puppies-dogs/the-cost-of-owning-a-dog (prebrano 15. 11. 2026) |
| Tečaj: 1 EUR = 0,83559 GBP = 1,1052 USD → 1 £ = 1,1968 € = 1,3227 $ | referenčni tečaji ECB, 13. 11. 2026, https://www.ecb.europa.eu/stats/policy_and_exchange_rates/euro_reference_exchange_rates/html/index.en.html |

Izračun za 13 let uporabi številke odraslega psa za vsa leta (poenostavitev, napisana v prototipu).

### Odločitve (David, 15. 11. 2026)
| # | Odločitev |
|---|---|
| T1 | **Valuto izbere uporabnik: €, $ ali £.** Zneski ostanejo v funtih (valuta vira PDSA), druge valute so preračunane po **fiksnem** tečaju ECB z dne 13. 11. 2026 in označene s tildo (»~139 €«). Tečaj se ne osvežuje sam; ob prenosu na splet se zamenja ročno in datum se izpiše. |
| T2 | **Partner je izmišljen:** logotip PetPrep in pod njim manjši napis »partner« (`petprep-partner-placeholder.svg`). To je nadomestek za prototip, ne sredstvo znamke. |
| T3 | Vse se zapiše v repozitorij (ta dokument + izvorna koda prototipa). |

### Odprto za Davida
1. **E-naslov starša — kam gre.** Strežnik danes ne pošilja e-pošte (`MAIL_MAILER=log`, ponudnika ni; ROADMAP M2-10b). Claudov predlog: **brez SMTP**, enako kot brezplačen vzorec »Obljube« (WEBSITE_SALES_SPEC): brezplačen izdelek v Lemon Squeezy »Povzetek za <pasmo>« — Lemon Squeezy pošlje e-sporočilo staršu, shrani naslov s privolitvijo in obvesti Davida. Druga možnost: `POST /api/...` po vzoru povpraševanj partnerjev (tabela + opozorilo v Discord + seznam v administraciji), kjer starš ne dobi ničesar, dokler ni ponudnika e-pošte.
2. **Cene za Slovenijo.** Številke so britanski minimum PDSA, samo preračunan. Ali poiščemo slovenski vir ali ostane PDSA z opombo »cene v vaši državi so lahko drugačne«.
3. **Pravi partner:** kdo in pod kakšnimi pogoji (REVENUE_MODELS §4).
4. **Ali gre to na petprep.si pred koncem validacije** (VALIDATION_PLAN §2) in v katerih jezikih (stran ima 15 jezikov; prototip ima SL + EN).
5. Ali naj se pasma izbira (podatki za vse pasme so v registru) ali ostane ena stran na pasmo.

### Naslednji koraki, če David potrdi
1. Prenos v `pet-prep-website` kot komponenta (three.js naložen šele ob odprtju strani), podatki iz `registry.json` namesto vpisanih v kodo.
2. Stran na pasmo v registru: »Koliko dela je <pasma>?«.
3. Merjenje: delež obiskov, ki pridejo do koraka 5 in pustijo e-naslov (cena stika za H4).
