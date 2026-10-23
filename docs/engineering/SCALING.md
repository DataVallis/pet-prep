# PetPrep — Scaling plan (tisoči uporabnikov, nove države)

> Živ dokument (David, 5. 10. 2026). Kaj moramo narediti, ko preidemo iz zaprte bete (do ~500 družin, en Hetzner CX23) na tisoče družin in več držav. Vsaka točka ima **sprožilec** (kdaj) in **ukrep**. Ko je kaj narejeno, označi in poveži PR. Stanje infrastrukture: `ARCHITECTURE.md`, `DEPLOYMENT.md`.

## 0. Izhodišče (oktober 2026)
- En strežnik Hetzner CX23 (Nemčija): Caddy, Laravel, Reverb, queue, scheduler, PostgreSQL 18, Redis — vse v Docker Compose.
- AI mediji na lokalnem disku (`app_storage`), ocena ~7–49 MB na psa → disk zadošča za ~1.000–2.000 psov.
- *2026-10-23 (David, preverjanje kakovosti posnetkov):* produkcijska slika aplikacije ima zdaj **ffmpeg / ffprobe** (Debian, ~+250 MB nestisnjeno; ena kopija slike na strežnik). Preverjanje enega posnetka: nekaj sekund CPU v vrsti `default` (ffmpeg na 128 px sivi kopiji) + en klic modela za slike prek fal (≤ 0,01 $, produkcijski proračun); pri veliko novih pasmah hkrati je to dodatno delo za delavca vrste — ob rasti ločen delavec za `default`. Neuspešni posnetki se enkrat ustvarijo znova (≤ 1 dodaten posnetek na neuspeh).
- Minutni tick (razpad, eskalacija, zapiranje dni) teče za vse pse; varnostna kopija = `pg_dump` na istem strežniku.
- ✅ *Narejeno v kodi 2026-10-05 (M4-05b, veja `feat/M4-05b-prod-runtime`, deploy ob združitvi):* produkcijski PHP runtime — **PHP-FPM 8.3 + OPcache** v lastni sliki (koda + `vendor` brez razvojnih paketov), Caddy sam streže statične datoteke (Filament) in **videe / slike psov** (X-Accel-Redirect, PHP samo preveri podpis in pravice). Lokalno izmerjeno: med 4 hkratnimi prenosi 10 MB videa je `GET /up` trajal 4,4 s na starem `php artisan serve` in 0,01–0,02 s na Caddy + FPM. Odprto: omejitev hkratnih povezav na IP (vtičnik za Caddy ali Cloudflare, §1).

## 1. Mediji in promet
| Sprožilec | Ukrep |
|---|---|
| ✅ Pred zaprto beto (M4-05b) | **Caddy streže medije neposredno** z lokalnega diska (X-Accel-Redirect, Range / ETag), statične datoteke z dolgim predpomnjenjem — prenos videa ne zasede PHP. *Narejeno v kodi 2026-10-05.* |
| ✅ 2026-10-20 (David) | **Telefon hrani kopijo medijev:** aplikacija vsak video / sliko prenese enkrat in ga predvaja s telefona (ime po datoteki, ne po podpisanem URL-ju), zato ponovni podpisi (~30 min) ne pomenijo novega prenosa; največ 300 MB na telefonu, ob odjavi se pobriše. Manj prometa na strežniku in kasneje na CDN-ju. |
| Disk > 60 % ali > 1.000 psov | **Hetzner Object Storage** (S3, ~5 €/mes za 1 TB) za `pet_media`; podpisani URL-ji iz shrambe namesto prek API-ja (`PetMediaController` že zna preklopiti na PHP za ne-lokalni disk; za S3 se doda preusmeritev na podpisan URL). |
| Uporabniki izven srednje Evrope ali > 5.000 družin | **CDN** pred shrambo (Bunny CDN ali Cloudflare), dolgi cache headerji (datoteke se ne spreminjajo), podpisani CDN URL-ji. |
| Napadi / sumljiv promet | Cloudflare (DDoS, WAF) pred `api.petprep.si`; rate limit na robu. Tudi **omejitev hkratnih povezav na IP** za medije (zdaj je le `throttle:media` 240/min v Laravelu). |

## 2. Aplikacijski strežniki
| Sprožilec | Ukrep |
|---|---|
| ✅ Pred beto (M4-05b) | **PHP-FPM + OPcache** namesto `php artisan serve` (razvojni strežnik, 4 delavci, brez OPcache): `pm=dynamic`, do 12 delavcev, predpomnilniki Laravela ob zagonu vsakega kontejnerja. *Narejeno v kodi 2026-10-05.* |
| CPU > 70 % v konicah ali p95 API > 300 ms | Večji strežnik (CX33/CX43) — najcenejši prvi korak; nato `pm.max_children` v `docker/production/php-fpm.conf` dvigniti skupaj z RAM-om. |
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
| Pred javno beto | **Varnostne kopije izven strežnika** — ✅ zgrajeno 14. 10. 2026 (`scripts/backup-offsite.sh`, restic → Hetzner Storage Box: baza + mediji ljubljenčkov vsako noč, šifrirano, hramba ≈ 35 dni, `verify` za preizkus obnove; čaka Davidovo enkratno nastavitev, PRODUCTION_DEPLOYMENT §6b). Še odprto: WAL arhiv za obnovo na točko v času (PITR). |
| Mediji > 100 GB | Mediji ljubljenčkov iz Docker volumna v objektno shrambo (S3 / Hetzner Object Storage) s CDN; varnostna kopija potem z verzioniranjem vedra. |
| Povezave PostgreSQL blizu `max_connections` | Vsak PHP proces (FPM, delavci, scheduler) ima lahko poleg glavne še 1 povezavo `system_health` (odpre se šele ob zabeleženi napaki; System health, 19. 10. 2026). Pred PgBouncerjem / več FPM delavci to upoštevaj ali nastavi `SYSTEM_HEALTH_ISOLATED_CONNECTION=false`. |
| DB > 50 % RAM-a ali počasne poizvedbe | Postgres na svoj strežnik (ali upravljana baza), `pg_stat_statements`, indeksi. |
| Veliko branja (dashboard, poročila) | Bralna replika za poročila; agregati (tedenski / 12-tedenski) materializirani. |
| `activities_log` > 10 M vrstic | Particioniranje po mesecih, arhiviranje starih podatkov po poteku izziva (tudi zaradi GDPR minimizacije). |

## 5. Opazovanje in zanesljivost
- Sledenje napak (npr. Sentry — **brez otrokovih podatkov**, preveriti z Davidom), uptime monitor, opozorila (tick zamuja, queue raste, disk, poraba AI).
- Centralni logi z omejenim hranjenjem; nadzorna plošča metrik (p95, napake, aktivni psi, poraba AI na dan).
- Obremenitveni test (k6) pred vsakim večjim lansiranjem: npr. 5.000 otrok × akcije + tick + WebSocket.
- Staging okolje (kopija produkcije brez osebnih podatkov) — konec samodejnega deploya na `main` brez preverjanja (DEPLOYMENT D2).

## 5a. Prijava otrok s PIN-om (David 14. 10. 2026: »zabeleži za pin«)
Danes: 6 števk (1.000.000 kod), enkratna, velja 15 min, unikatna med odprtimi PIN-i (delni unikatni indeks `child_login_pins_open_pin_unique`) — **dva otroka nikoli nimata iste veljavne kode**, napačna prijava zaradi trka ni mogoča. Zaščita pred ugibanjem: 10 poskusov / min na IP, zaklep po napakah na IP in **globalna zavora 100 napačnih poskusov / 15 min za vse** (`ChildPinLoginService::MAX_GLOBAL_FAILURES`).

| Sprožilec | Ukrep |
|---|---|
| > ~5.000 hkrati odprtih PIN-ov (verjetnost, da naključen poskus zadane *nekoga*, > 0,5 %) ali pred javnim zagonom | Daljša koda: 8 znakov iz abecede brez zamenljivih znakov (brez 0/O, 1/I/L) ≈ 10¹² kombinacij; aplikacija (polje, lepljenje iz odložišča — trenutno pravilo »točno 6 števk se prijavi takoj«) in strežnik hkrati, stare 6-mestne kode veljajo do poteka. |
| Običajne tipkarske napake se približajo 100 / 15 min (≈ nekaj 10.000 prijav na dan) | **Globalna zavora bi zaklenila prijavo vsem otrokom.** Prag sorazmeren s prometom (npr. delež napak glede na uspešne prijave v oknu) namesto fiksnih 100, opozorilo adminu namesto tihe zavore; po potrebi CAPTCHA / zakasnitev samo za sumljive IP-je. |
| Botnet (veliko IP-jev) | Omejitev na ASN / državo, Cloudflare Turnstile pred `pin-login`, krajša veljavnost PIN-a (npr. 10 min). |

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
| 2026-10-05 | M4-05b v kodi: PHP-FPM + OPcache (lastna produkcijska slika), Caddy streže statične datoteke in medije (X-Accel-Redirect); §0, §1, §2 posodobljeni. Odprto: omejitev povezav na IP. | `feat/M4-05b-prod-runtime` |
| 2026-10-14 | Varnostne kopije izven strežnika zgrajene (§4, D5); mediji v objektni shrambi zapisani kot naslednji korak (§4); omejitve PIN-a pri velikem obsegu (§5a). | `feat/D5-offsite-backups` |
