# PetPrep — Dnevnik odločitev

> Vsaka produktna, poslovna ali tehnična odločitev v eni vrstici: **datum · kdo · kaj · zakaj · kje**.
> Novejše zgoraj. Produktna pravila se hkrati zapišejo v `PRODUCT_SPEC.md`, poslovna v `BUSINESS_MODEL.md`, večje tehnične kot ADR v `docs/decisions/`.

| Datum | Kdo | Odločitev | Zakaj | Kje |
|---|---|---|---|---|
| 2026-10-03 | Claude | **"Dodaj otroka" samo, dokler otrok ni povezan** (nadzorna plošča + Nadzor); ko je, gumba ni. | PRODUCT_SPEC §2/§3: MVP 1 starš → 1 otrok; backend tega pri povezovanju še ne preverja. | `feat/M1-12-session-restore-parent-pin` |
| 2026-10-03 | Claude | **Ob zagonu brez povezave seja ostane** (zaslon "Ni povezave" + "Poskusi znova"); token se izbriše samo, če ga strežnik zavrne (401). | Otrok ne sme biti odjavljen samo zato, ker je telefon brez signala. | `feat/M1-12-session-restore-parent-pin` |
| 2026-10-03 | Claude | **Ob 429 na "Nova koda" ostane prejšnja koda vidna** in gumb je onemogočen za `Retry-After` sekund. | Strežnik kodo zamenja samo ob uspehu — stara še velja. | `feat/M1-12-session-restore-parent-pin` |
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
| 2026-10-03 | Tihe ure ob prestopu ure: 22:00–06:00 traja 9 h (oktober) oz. 7 h (marec) — po stenski uri. OK? | Da, po stenski uri (kot jo doživi družina). |
| 2026-10-02 | Razmejitev brezplačno / plačljivo (BUSINESS_MODEL §7) in B7: enkratni nakup vs. naročnina. | Enkratni nakup 49,99 € + strežniški 7-dnevni preizkus. |
| 2026-10-02 | B2 spletni checkout, B4 prvi trg, B5 garancija, B6 Mr. Pet. | Glej BUSINESS_MODEL §7. |
