# PetPrep — 8-tedenski načrt validacije

> **Osnutek Claude, 10. 11. 2026; odločitve §7 potrdil David 10. 11. 2026.** Vsi pragovi so predlogi, ne izmerjeni podatki ali viri.

## 1. Cilj

V 8 tednih dobiti **100 družin, ki so plačale s pravim denarjem**, in za vsako točko lijaka izmerjeno številko. Na koncu ena odločitev: nadaljuj / popravi ponudbo / spremeni model.

Zakaj: danes so CPA 1,50 €, konverzija 10 % in CAC 15 € predpostavke (`BUSINESS_MODEL.md` §5). Vse nadaljnje odločitve (cena, Faza 2, trg, investitorji) stojijo na njih.

## 2. Kaj je v tem času zamrznjeno

Nove pasme in vrste, novi jeziki, risanka Curio, trgovina in plišasta igrača, pobarvanke, DNK, »ljubljenček v tvoji sobi«, pasemski nagoni, 2,5D ljubljenček, imenik veterinarjev.
Dovoljeno: napake, ki jih najdejo prave družine, vse iz §4 in serija »12 Weeks« (to je kanal pridobivanja).

## 3. Hipoteze in pragovi

| # | Hipoteza | Metrika | Nadaljuj | Popravi | Ustavi in premisli |
|---|---|---|---|---|---|
| H1 | Starši hočejo to preizkusiti | namestitev → rojen ljubljenček (podpisana pogodba) | ≥ 40 % | 20–40 % | < 20 % |
| H2 | Otrok zdrži dovolj dolgo, da starš vidi vrednost | otrok aktiven 7. dan (vsaj 1 dejanje) | ≥ 35 % | 20–35 % | < 20 % |
| H3 | Starš plača | družine z rojenim ljubljenčkom → nakup v 14 dneh | ≥ 5 % | 2–5 % | < 2 % |
| H4 | Nakup se splača | CAC na plačljivo družino (plačani kanali) | ≤ 35 € | 35–70 € | > 70 € |
| H5 | Plačani izziv drži | plačane družine aktivne v 4. tednu; zahtevki za vračilo | ≥ 40 %; < 10 % | 25–40 % | < 25 % ali vračila > 20 % |
| H6 | Starš gleda rezultat | starš odpre pregled ≥ 3× v 2. tednu | ≥ 50 % | 30–50 % | < 30 % |
| H7 | Obstaja povpraševanje po Fazi 2 | starši, ki tapnejo »Dobili smo pravo žival« (lažna vrata, §4.4) in pustijo e-pošto | ≥ 20 % | 10–20 % | < 10 % |

Prag H4 = približno neto prihodek enega nakupa (49,99 € − DDV 22 % − provizija trgovine 15 % ≈ 35 €). Nad tem vsak kupec prinese izgubo, dokler ni ponavljajočega se prihodka.

Pri tako majhnem številu družin so odstotki grobi kazalniki, ne statistika — zato se odločamo po pasovih in po pogovorih s starši (teden 7), ne po decimalkah.

## 4. Kaj je treba zgraditi (samo to)

1. **Merjenje lijaka brez analitičnega SDK.** Stran v administraciji »Lijak« (`/admin/funnel`, M5-08): po tednih registracije registracija → dodan otrok → rojen ljubljenček → otrok aktiven 1. / 7. dan → nakup → aktivni 4. teden → certifikat. *Prvi del zgrajen 10. 11. 2026 iz podatkov, ki jih igra že zapisuje.* Manjka: vir prihoda (koda kampanje ob registraciji) in odpiranja pregleda pri staršu (H6).
2. **Objava v obeh trgovinah s pravimi plačili.** Brez tega se H3–H5 ne dajo meriti.
3. **Nadgradnja brezplačnega ljubljenčka v izziv (isti ljubljenček).** *Potrdil David 10. 11. 2026 — zamenja odločitev »kasneje« z 9. 10. 2026.* Specifikacija: `docs/product/FREE_PET_UPGRADE_SPEC.md` (*načrt*).
4. **Lažna vrata za Fazo 2.** Gumb »Dobili smo pravo žival« pri staršu → zaslon »Pripravljamo pomočnika za pravega ljubljenčka« + vprašanje o ceni (3 možnosti) + e-pošta. Pošteno označeno kot »v pripravi«.
5. **Kratka anketa staršu** ob nakupu, v 2. tednu in ob koncu ali opustitvi: zakaj ste kupili / zakaj ne, ali načrtujete pravo žival v 6 mesecih. Nekupce vprašamo tudi, ali bi brezplačen teden izziva spremenil odločitev (namesto A/B testa, §7 V3).

## 5. Potek po tednih

| Teden | Delo | Rezultat |
|---|---|---|
| 1 | Zamrznitev (§2). Stran »Lijak«. Stanje obeh trgovin, prava plačila. Spec za nadgradnjo mešančka. | Vsak korak lijaka ima številko; aplikacija se da kupiti. |
| 2 | Lažna vrata, anketa, koda kampanje. Gradnja nadgradnje mešančka. | Merimo H1–H7. |
| 3–4 | **Val 1: 30 družin v Sloveniji brez oglasov** — testerji, osebna mreža, sledilci serije, čakalni seznam. | Prvi pravi nakupi; popravki ovir, ki jih najdejo. |
| 5–6 | **Val 2: plačani oglasi, 500–1.000 €** — Slovenija + manjši angleški test; 2–3 kreative iz serije (družinski sitcom) proti klasičnemu oglasu. | CAC po trgu, kanalu in kreativi (H4). |
| 7 | 10 pogovorov s starši: 5 kupcev, 5 nekupcev. Pregled anket in lažnih vrat. | Razlogi za nakup in zavrnitev z besedami staršev. |
| 8 | Poročilo po tabeli §3 in odločitev (§6). | En list z rezultati in odločitvijo. |

## 6. Odločitev v 8. tednu

- **H1–H5 večinoma »nadaljuj«:** povečaj oglase na istem trgu, začni graditi najmanjšo Fazo 2 (motor pravil za pravo žival, naročnina), posodobi INVESTORS s pravimi številkami.
- **H3 ali H4 »popravi«:** testiraj ceno in obliko (4 tedni ceneje, družinska naročnina, brezplačen teden — če ga nekupci v anketi pogosto omenijo), ponovi val 2.
- **H2 »ustavi«:** otroci ne zdržijo niti tedna. Glavni kupec postane odrasel, ki preizkuša pasmo, ali kanal B2B2C (zavetišča, vzreditelji, zavarovalnice).
- **H7 »nadaljuj«, H3 šibek:** simulator postane brezplačen vstop, posel je naročnina za pravo žival.

## 7. Odločitve (David, 10. 11. 2026)

| # | Vprašanje | Odločitev |
|---|---|---|
| V1 | Trg za validacijo | Slovenija za val 1; v valu 2 Slovenija + manjši angleški test |
| V2 | Nadgradnja mešančka v izziv z istim ljubljenčkom | Da, zdaj (zamenja »kasneje« z 9. 10. 2026) |
| V3 | A/B test 7-dnevnega preizkusa | **Ne** — premalo uporabnikov za zanesljiv rezultat. Ostane brez preizkusa (odločitev 8. 10. 2026); vprašanje o brezplačnem tednu gre v anketo nekupcem. |
| V4 | Proračun za oglase v valu 2 | 500–1.000 € |

Vzporedno, ne del validacije: PetPrep d.o.o. s prenosom IP in trgovinskih računov, en veterinar kot svetovalec za zdravstvene vsebine.
