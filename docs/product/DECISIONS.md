# PetPrep — Dnevnik odločitev

> Vsaka produktna, poslovna ali tehnična odločitev v eni vrstici: **datum · kdo · kaj · zakaj · kje**.
> Novejše zgoraj. Produktna pravila se hkrati zapišejo v `PRODUCT_SPEC.md`, poslovna v `BUSINESS_MODEL.md`, večje tehnične kot ADR v `docs/decisions/`.

| Datum | Kdo | Odločitev | Zakaj | Kje |
|---|---|---|---|---|
| 2026-10-03 | Claude (po specifikaciji) | **Števec bolezni (higiena / gibanje 0 % ≥ 6 h) šteje samo čas izven tihih ur**; prej se je med tihimi urami bolezen le ni sprožila, ure pa so se štele (pes je lahko zbolel ob 06:00 po nočni ponastavitvi energije). | PRODUCT_SPEC §7 "izven tihih ur"; backend/CLAUDE.md "illness clock paused". | M1-04, PRODUCT_SPEC §7 |
| 2026-10-03 | Claude | **Sync korakov energije nikoli ne zniža**; pes se rodi s 100 % in jo obdrži do prve lokalne polnoči (`last_step_reset_at` = rojstvo). | Sicer bi prvi sprehod novorojenemu psu energijo *znižal* (npr. 100 → 5 %), ali pa bi bil pes ob rojstvu "bolan". Izven rojstnega dne je pravilo enako formuli. | M1-04, PRODUCT_SPEC §5 |
| 2026-10-03 | Claude | **Anti-cheat koraki: presežek se zavrne, ne celoten sync** (≤ 200 / min od zadnjega sprejetega synca ali lokalne polnoči); čas iz prihodnosti = zdaj; sync z včerajšnjim datumom se ignorira. | Health API-ji pogosto pošljejo korake v zamiku; zavrnjeni del se lahko sprejme kasneje, goljufija pa ne prinese nič. | M1-04, PRODUCT_SPEC §5 |
| 2026-10-03 | Claude | **Higienski dogodki v tabeli `pet_hygiene_events`** (namesto `pets.next_poop_at`): en naključen čas (cela minuta) v vsakem enakem deležu netihega lokalnega dne; RNG determinističen (app key + pes + datum); dogodek se zgodi samo, če pade v interval, ki ga tick obdeluje (`last_decay_at`, zdaj] — med zamrznitvijo / pred rojstvom se preskoči; čiščenje pobere tudi že zapadle dogodke. | BC ima 2 dogodka na dan; tabela je hkrati dnevnik (kdaj se je zgodilo, kdaj očiščeno) za starševsko časovnico. Enakomerni deleži preprečijo dva kakca zapored. | M1-05, ARCHITECTURE §2/§4 |
| 2026-10-03 | Claude (po Davidovi odločitvi) | **Začasno padanje higiene 1,5 %/h odstranjeno** — nadomestijo ga naključni dogodki. | Odločitev 2026-10-03: interim samo do M1-05. | M1-05 |
| 2026-10-03 | Claude | **Vse igralne številke v `breed_configs`** (`thirst_decay_rate`, `poops_per_day`, `feed_windows`, `water_times_per_day`, `water_min_gap_minutes`), CHECK omejitve, urejanje v Filamentu. Anti-cheat meja 200 korakov/min ostane konstanta (ni odvisna od pasme). | Ena resnica za pravila igre. | M1-06 |
| 2026-10-03 | David | **Pred-produkcijska faza:** Claude sam merga zelene PR-je in merge na `main` samodejno deploya na `api.petprep.si`. Ko gremo k pravim uporabnikom → spet ročni deploy. | Hitrost razvoja; produkcija še nima pravih družin. | CLAUDE.md, DEPLOYMENT.md D2 |
| 2026-10-03 | David | **Dokumentacija se piše sproti** za vse publike (tehnična, starši, otroci, investitorji, partnerji), z diagrami; iz nje bodo 2-pagerji, decki, vodiči. | "Za nazaj se ne bomo spomnili vsega." | CLAUDE.md §Living documentation |
| 2026-10-03 | David | **Pragovi opozoril sledijo prikazani (zaokroženi) vrednosti** — kar otrok vidi kot 30 %, sproži opomnik. | Otrok in starš morata videti isto, kar sproži pravilo. | PRODUCT_SPEC §6, PR (M1-01b) |
| 2026-10-03 | David | **Začasno padanje higiene 1,5 %/h ostane** do naključnih "kakec" dogodkov (M1-05). | Brez tega higiena sploh ne bi padala. | HANDOFF §2 |
| 2026-10-03 | Claude (po specifikaciji) | **Hard stop in bolezen zamrznejo vse** — metrike in števce zanemarjanja; ni game overja med starševskim zaklepom. | PRODUCT_SPEC §5 "metrike so zamrznjene"; audit §2.5. | PR #5 |
| 2026-10-03 | Claude | Metrike kot `double precision`, prikaz zaokrožen (half-up); ura razpada `last_decay_at`. | `decimal(5,2)` bi še vedno izgubljal ~2,5 % na minuto. | PR #5, ARCHITECTURE §2 |
| 2026-10-02 | David | **12-tedenski PetPrep izziv 49,99 €** s **7-dnevnim brezplačnim preizkusom**; **mešanček brezplačen za vedno**. | Pozicioniranje kot orodje za oceno zrelosti, ne igrica; brez tveganja za vstop. | BUSINESS_MODEL §7 |
| 2026-10-02 | David | **Otrok se prijavi samo s PIN-om**, brez e-pošte. | Zasebnost otrok (GDPR čl. 8), enostavnost. | PRODUCT_SPEC §3 |
| 2026-10-02 | David | **Monorepo** — `pet-prep-mobile` združen v `pet-prep/mobile`, stari repo arhiviran. | En repo za agente in CI. | HANDOFF |
| 2026-10-02 | David | **Jeziki: angleščina (privzeto) + slovenščina.** | Mednarodna rast, slovenski začetni trg. | PRODUCT_SPEC §2 |
| 2026-10-02 | David | **Paketni manager: yarn 1.** | Že deklariran v root `package.json`. | ROADMAP M0-05 |
| 2026-10-02 | Claude | fal.ai webhooki z ED25519 podpisom (brez skupne skrivnosti), mediji samo s `*.fal.media`. | Uradni mehanizem fal.ai; varnost otrok. | PR #1 |
| 2026-10-02 | Claude | CI na PostgreSQL + mobilni testi; deploy prek GitHub Actions. | Prejšnji workflow je bil neveljaven in ni nikoli tekel. | PR #2 |

## Odprta vprašanja (za Davida)

| Od | Vprašanje | Predlog |
|---|---|---|
| 2026-10-03 | **Zanka bolezni (blokira igro):** po 12 h bolezni števec nadaljuje z ≥ 6 h, zato pes izven tihih ur *takoj* spet zboli; med boleznijo otrok ne more ničesar (niti koraki niti čiščenje se ne sprejmejo). Sled (test, spanje 22–06, šola 8–13, brez korakov): bolan tor 17:00 → ozdravi sre 05:00 (spanje) → spet bolan sre 06:00 → … Brez posega pes ostane bolan za vedno (game over se med boleznijo ne šteje). Velja tudi za higieno (že pred M1-04). | Po koncu bolezni števec bolezni začne znova (otrok dobi novih 6 h izven tihih ur), ali: "veterinar" ob odpustu vrne metriko na npr. 30 %. |
| 2026-10-03 | **Energija 0 % vsako noč → eskalacija:** ob polnoči faza 2 (≤ 10 %) in `pet_state = sick` (video bolnega psa do prvega sprehoda), ob 01:00 faza 3 = alarm staršu in rdeč semafor — vsako noč, med tihimi urami. Game over po 24 h brez sprehoda (če vmes ni bolezni). | Za gibanje: eskalacija (faze 1–3 in 24 h) šteje samo izven tihih ur in se začne šele ob prvi netihi minuti dneva; `sick` samo med dejansko boleznijo, pri nizki energiji `low_energy`. |
| 2026-10-03 | Seeder `breed_configs` teče ob vsakem deployu (`updateOrInsert`) in **prepiše spremembe iz Filamenta**. OK? | Seeder naj vstavi samo manjkajoče vrstice/stolpce; vrednosti naj ureja admin. |
| 2026-10-03 | Vsak sprejet sync korakov = ena vrstica `walked_pet`; tedenski graf šteje vrstice kot "opravljene rutine", zato več syncov napihne številko. | V M2-05 šteti sprehod 1× na dan (dosežen cilj) namesto vrstic. |
| 2026-10-03 | Tihe ure ob prestopu ure: 22:00–06:00 traja 9 h (oktober) oz. 7 h (marec) — po stenski uri. OK? | Da, po stenski uri (kot jo doživi družina). |
| 2026-10-02 | Razmejitev brezplačno / plačljivo (BUSINESS_MODEL §7) in B7: enkratni nakup vs. naročnina. | Enkratni nakup 49,99 € + strežniški 7-dnevni preizkus. |
| 2026-10-02 | B2 spletni checkout, B4 prvi trg, B5 garancija, B6 Mr. Pet. | Glej BUSINESS_MODEL §7. |
