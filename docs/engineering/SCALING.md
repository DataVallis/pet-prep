# PetPrep — Scaling plan (tisoči uporabnikov, nove države)

> Živ dokument (David, 5. 10. 2026). Kaj moramo narediti, ko preidemo iz zaprte bete (do ~500 družin, en Hetzner CX23) na tisoče družin in več držav. Vsaka točka ima **sprožilec** (kdaj) in **ukrep**. Ko je kaj narejeno, označi in poveži PR. Stanje infrastrukture: `ARCHITECTURE.md`, `DEPLOYMENT.md`.

## 0. Izhodišče (oktober 2026)
- En strežnik Hetzner CX23 (Nemčija): Caddy, Laravel, Reverb, queue, scheduler, PostgreSQL 18, Redis — vse v Docker Compose.
- AI mediji na lokalnem disku (`app_storage`), ocena ~7–49 MB na psa → disk zadošča za ~1.000–2.000 psov.
- Minutni tick (razpad, eskalacija, zapiranje dni) teče za vse pse; varnostna kopija = `pg_dump` na istem strežniku.
- V delu (pred beto): produkcijski PHP runtime (OPcache, FPM/FrankenPHP) + Caddy streže datoteke in videe neposredno (M4-05b).

## 1. Mediji in promet
| Sprožilec | Ukrep |
|---|---|
| Disk > 60 % ali > 1.000 psov | **Hetzner Object Storage** (S3, ~5 €/mes za 1 TB) za `pet_media`; podpisani URL-ji iz shrambe namesto prek API-ja. |
| Uporabniki izven srednje Evrope ali > 5.000 družin | **CDN** pred shrambo (Bunny CDN ali Cloudflare), dolgi cache headerji (datoteke se ne spreminjajo), podpisani CDN URL-ji. |
| Napadi / sumljiv promet | Cloudflare (DDoS, WAF) pred `api.petprep.si`; rate limit na robu. |

## 2. Aplikacijski strežniki
| Sprožilec | Ukrep |
|---|---|
| CPU > 70 % v konicah ali p95 API > 300 ms | Večji strežnik (CX33/CX43) — najcenejši prvi korak. |
| > 3.000 hkratnih družin | Ločiti vloge: 2+ aplikacijska strežnika za Hetzner Load Balancerjem; seje/tokeni so že brezstanjski (Sanctum), cache/queue v Redis. |
| Reverb > ~2.000 hkratnih povezav | Reverb na svoj strežnik, horizontalno skaliranje prek Redis pub/sub (`REVERB_SCALING_ENABLED`). |
| Vrsta (queue) zamuja > 1 min | Več workerjev; ločeni workerji za `broadcasts`, `default`, AI medije; Laravel Horizon za nadzor. |

## 3. Igralna zanka (minutni tick)
| Sprožilec | Ukrep |
|---|---|
| Tick traja > 20 s (zdaj ~3 transakcije na psa) | Predfilter kandidatov v SQL (samo psi blizu praga / z aktivnimi urami), cache tihih ur na družino, paketna obdelava. |
| > 20.000 psov | Tick razdeljen na shard-e (pes `id % N`) prek več queue jobov namesto enega procesa; `withoutOverlapping` per shard. |
| Zapiranje dni ob polnoči (vsi v istem pasu) | Proračun na tick je že (500 psov); po potrebi razpršiti čez prvo uro dneva. |

## 4. Podatkovna baza
| Sprožilec | Ukrep |
|---|---|
| Pred javno beto | **Varnostne kopije izven strežnika** (Hetzner Storage Box ali Object Storage), dnevno + preizkus obnove; WAL arhiv za obnovo na točko v času (PITR). |
| DB > 50 % RAM-a ali počasne poizvedbe | Postgres na svoj strežnik (ali upravljana baza), `pg_stat_statements`, indeksi. |
| Veliko branja (dashboard, poročila) | Bralna replika za poročila; agregati (tedenski / 12-tedenski) materializirani. |
| `activities_log` > 10 M vrstic | Particioniranje po mesecih, arhiviranje starih podatkov po poteku izziva (tudi zaradi GDPR minimizacije). |

## 5. Opazovanje in zanesljivost
- Sledenje napak (npr. Sentry — **brez otrokovih podatkov**, preveriti z Davidom), uptime monitor, opozorila (tick zamuja, queue raste, disk, poraba AI).
- Centralni logi z omejenim hranjenjem; nadzorna plošča metrik (p95, napake, aktivni psi, poraba AI na dan).
- Obremenitveni test (k6) pred vsakim večjim lansiranjem: npr. 5.000 otrok × akcije + tick + WebSocket.
- Staging okolje (kopija produkcije brez osebnih podatkov) — konec samodejnega deploya na `main` brez preverjanja (DEPLOYMENT D2).

## 6. Nove države
| Področje | Ukrep |
|---|---|
| Jezik | i18n (M1-18): EN + SL, nato DE (DACH); prevodi tudi push obvestil, e-pošte, pogojev. |
| Pravo | Pogoji in politika zasebnosti po državi; GDPR čl. 8 (starost za soglasje: SI 15, DE 16, AT 14 …); UK GDPR + Age Appropriate Design Code; DPA z vsemi obdelovalci (fal.ai, RevenueCat, Hetzner, e-pošta). |
| Podatki | Podatki ostanejo v EU (Hetzner DE/FI); za trge izven EU preveriti zahteve (npr. ZDA COPPA — otroci < 13). |
| Plačila | RevenueCat + App Store/Play cene po državah; DDV OSS za EU, UK VAT; cene žetonov po valutah. |
| Časovni pasovi | Že podprto (časovni pas družine, DST); preveriti trge z neobičajnimi pasovi. |
| Trgovine | Lokalizirani opisi, posnetki zaslona, starostne ocene (Kids Category pravila Apple). |
| Pasme | Podatki o pasmah iz virov (M1-19) in priljubljene lokalne pasme po trgu. |
| Partnerji | Lokalni veterinarji, zavarovalnice, trgovine (Faza 2) — po državah. |

## 7. Stroški (spremljati)
- AI mediji na psa (~1,3–3,5 $ po ceniku; primerjati z računi fal.ai) — omejitve porabe so v kodi (dnevne / mesečne).
- Strežniki, shramba, CDN, e-pošta, sledenje napak — mesečni pregled v `BUSINESS_MODEL.md`.

## Dnevnik
| Datum | Kaj | PR |
|---|---|---|
| 2026-10-05 | Dokument ustvarjen; v delu produkcijski PHP runtime + Caddy za medije (M4-05b). | — |
