# Certifikat odgovornosti — specifikacija (M2-01d)

> Odločitve: David, 12. 10. 2026 (pogovor s Claudom). Dizajn: claude.ai artefakt »PetPrep certifikat« (`CxwCVD561qmyf78mum1Yf8`), CGP v2 (`brand/README.md`, »Certifikat odgovornosti«).

## 1. Pravila (David, 12. 10. 2026)

- **Kdaj:** izziv **otroka** je končan po **84 dneh programa** od podpisa njegove pogodbe (pri starih psih brez pogodbe od rojstva) — čas zaklepa do plačila se ne šteje (M3-11b, `CareScoreService::progress`). Vsak otrok skupnega psa ima svoj zaključek in svoj certifikat (ADR-012).
- **Samo plačan izziv:** brezplačni plan (mešanček, domača mačka) nima certifikata (M3-11).
- **Prag:** končni **Care Score ≥ 70** (pošten delež, PRODUCT_SPEC §11.2) → **Certifikat odgovornosti**. Pod 70 → **Poročilo o izzivu** (starš vidi oceno in pregled, certifikata ni).
- **Game over** pred koncem izziva → ni certifikata (poročilo z razlogom »game over«).
- **Končna ocena** = Care Score otroka iz rutin od njegovega začetka do zaključka (lokalni dan zaključka vključno); pozneje se ne spremeni (zaključeni dnevi se ne preračunajo). Rutine po zaključku ne štejejo.
- **Ustvarjanje (ne samodejno):** starš v aplikaciji tapne **»Ustvari certifikat«**, izbere **jezik** (EN, SL; kasneje več), lahko **popravi ime otroka** (privzeto vzdevek) in **ime ljubljenčka** (privzeto ime ali pasma), nato **»Ustvari«**. Strežnik shrani podatke in izriše PDF (A4). Starš ga lahko **ponovno ustvari** s popravki — številka, ocena in datumi ostanejo.
- **Prikaz:** starš pri otroku (odpri / deli PDF); **otrok** na svojem zaslonu vidi certifikat (ko ga je starš ustvaril); **push staršu**, ko je izziv otroka končan (enkrat): certifikat je pripravljen za ustvarjanje oz. poročilo je na voljo.
- **Zasebnost:** na certifikatu samo ime, ki ga vpiše starš (privzeto vzdevek), brez priimka in drugih osebnih podatkov. PDF ni javen (podpisan URL, samo starši družine in ta otrok).

## 2. Vsebina certifikata (iz dizajna)

Logotip »Radovednež« + napis, oznaka »12-TEDENSKI PETPREP IZZIV«, naslov »Certifikat odgovornosti«, »Ta certifikat prejme« + ime otroka, stavek o 12 tednih skrbi, tri kartice: **Care Score** (od 100), **ljubljenček** (ime + pasma), **trajanje** (12 tednov · 84 dni skrbi), datum zaključka, prazna črta »Podpis starša«, pečat (enobarvni znak) »POTRJENO«, slogan, `petprep.si · Št. PP-LLLL-NNNNNN`.

## 3. Odprto / kasneje

- Fizična licenca po pošti (⏸).
- Več jezikov (z M1-18 lokalizacijo).
- Javna stran za preverjanje številke certifikata (*predlog*).
