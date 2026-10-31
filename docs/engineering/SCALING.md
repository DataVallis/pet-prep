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
| Disk > 60 % ali > 1.000 psov | **Hetzner Object Storage** (S3, ~5 €/mes za 1 TB) za `pet_media` — ✅ *zgrajeno v kodi 24. 10. 2026 (M4-05), privzeto izklopljeno, preklopi David:* disk `pet_media_s3` (zasebno vedro, `PET_MEDIA_DISK=s3` + `PET_MEDIA_S3_*`); aplikacija še vedno dobi naš podpisani `/api/media/{id}` (pravice nespremenjene), strežnik po preverjanju odgovori s **302 na podpisan URL vedra (privzeto 5 min)** — bajti ne gredo več skozi strežnik ne disk. Prenos obstoječih datotek: `media:move-to-object-storage` (`--dry-run`, `--limit`, `--verify`, `--prune-local`; brez AI stroškov). Postopek: DEPLOYMENT.md D23. |
| Uporabniki izven srednje Evrope ali > 5.000 družin | **CDN** pred shrambo (Bunny CDN ali Cloudflare), dolgi cache headerji (datoteke se ne spreminjajo), podpisani CDN URL-ji. *Možnosti (M4-05, še ne zgrajeno):* (a) Bunny Pull Zone z izvorom = zasebno vedro (Bunny podpiše zahtevke na S3) + Bunny token auth (`token` + `expires` v URL-ju, ključ samo na strežniku) — preusmeritev v `PetMediaController` gre na CDN namesto na vedro; (b) Cloudflare pred vedrom z Workerjem, ki preveri naš HMAC. Obe ohranita naše podpisane `/api/media` URL-je in predpomnilnik v aplikaciji (ključ = pot + `v`); dodati bi bilo treba samo graditelja CDN-URL-ja v `MediaStorage::presignedUrl()`. |
| ✅ 2026-10-28 (David) | **Izpeljani posnetki šole na strežniku (ffmpeg, 0 $):** zanke čakanja / držanja poze in »vstane« — ~3,5–5 MB na ukaz na skupen videz (4 ukazi ≈ 14–20 MB na videz in stopnjo), enkrat na datoteko (vsi psi videza jih delijo). ffmpeg ~1–2,5 s in ~260–370 MB pomnilnika na posnetek v vrsti `default`; ob rasti: ločen delavec za medije ali omejitev pomnilnika vsebnika `queue`. **Varčni posnetki (30. 10. 2026, stikalo, samo psi):** obrnjen prehod in zanke naprej-nazaj doma ostanejo v **polni ločljivosti** (1076 × 1924, CRF 21) — izmerjeno: obrat 5 s **~645 MB RSS, 3,1 s** (potrebuje > 1 GB naslovnega prostora), zanka 3 s ~494 MB, 3,5 s. Vsak ffmpeg teče z `nice -n 19` in `prlimit --as` (privzeto 2048 MB, `MEDIA_DERIVATIVE_MEMORY_MB`), naenkrat samo eno težko ffmpeg delo na strežniku (zaklep `media-ffmpeg-heavy`, skupaj s stiskanjem `media:compress`). |
| Napadi / sumljiv promet | Cloudflare (DDoS, WAF) pred `api.petprep.si`; rate limit na robu. Tudi **omejitev hkratnih povezav na IP** za medije (zdaj je le `throttle:media` 240/min v Laravelu). |

## 2. Aplikacijski strežniki
| Sprožilec | Ukrep |
|---|---|
| ✅ Pred beto (M4-05b) | **PHP-FPM + OPcache** namesto `php artisan serve` (razvojni strežnik, 4 delavci, brez OPcache): `pm=dynamic`, do 12 delavcev, predpomnilniki Laravela ob zagonu vsakega kontejnerja. *Narejeno v kodi 2026-10-05.* |
| CPU > 70 % v konicah ali p95 API > 300 ms | Večji strežnik (CX33/CX43) — najcenejši prvi korak; nato `pm.max_children` v `docker/production/php-fpm.conf` dvigniti skupaj z RAM-om. |
| > 3.000 hkratnih družin | Ločiti vloge: 2+ aplikacijska strežnika za Hetzner Load Balancerjem; seje/tokeni so že brezstanjski (Sanctum), cache/queue v Redis. |
| Reverb > ~2.000 hkratnih povezav | Reverb na svoj strežnik, horizontalno skaliranje prek Redis pub/sub (`REVERB_SCALING_ENABLED`). |
| Vrsta (queue) zamuja > 1 min | Več workerjev; ločeni workerji za `broadcasts`, `default`, AI medije; Laravel Horizon za nadzor. |
| p95 API > 300 ms tudi po večjem strežniku | **Laravel Octane** (FrankenPHP ali Swoole): aplikacija ostane v pomnilniku, več zahtev na strežnik; najprej preveriti, da koda ne hrani stanja med zahtevami (singletoni, statične spremenljivke). |

## 2b. Tehnološki sklad — odločitev (David + Claude, 31. 10. 2026)
**Ostanemo na PHP / Laravel.** David je vprašal, ali bi bil za milijone uporabnikov boljši Node.js. Odgovor: jezik strežnika pri PetPrep ni ozko grlo — večino časa zahteve čakajo na bazo, shrambo in zunanje storitve (fal, RevenueCat), kar Node ne pospeši. Ozka grla pri rasti so:
- **baza** (minutni tick, dnevnik dogodkov) → §3, §4 (indeksi, read replice, particioniranje, shard-i ticka);
- **mediji** (največji promet) → §1 (objektna shramba + CDN, neodvisno od jezika);
- **vrste in AI** (fal, ffmpeg, stiskanje) → §2 (več workerjev, ločene vrste, Horizon);
- **websocketi** → §2 (Reverb prek Redis pub/sub).
Laravel ima za to vse: brezstanjske API strežnike za load balancerjem, Octane, Horizon, Redis. Prepis v drug jezik bi pomenil mesece brez novih funkcij (> 5.000 testov, game loop, plačila, AI pipeline, admin) — smiselno le ob konkretnem problemu, ki ga Laravel ne zmore; takega ni.

**Pogoj:** ogrodje mora biti na podprti verziji z varnostnimi popravki. Laravel 11 jih ne dobiva več (Dependabot 31. 10. 2026: popravki samo za 12.68+ / 13.29+) → **nadgradnja na Laravel 12 (M0-07) pred javnim zagonom**, nato vsako leto ob novi glavni verziji. Enako za Expo SDK v aplikaciji.

**Odprto (Claude pripravi, ko David reče):** konkreten načrt »od 1 strežnika do 1 milijona uporabnikov« po korakih s stroški (strežniki, baza, CDN, Reverb, vrste) — dopolni §1–§4 in §7.

## 3. Igralna zanka (minutni tick)
| Sprožilec | Ukrep |
|---|---|
| Tick traja > 20 s (zdaj ~3 transakcije na psa) | Predfilter kandidatov v SQL (samo psi blizu praga / z aktivnimi urami), cache tihih ur na družino, paketna obdelava. |
| > 20.000 psov | Tick razdeljen na shard-e (pes `id % N`) prek več queue jobov namesto enega procesa; `withoutOverlapping` per shard. |
| Zapiranje dni ob polnoči (vsi v istem pasu) | Proračun na tick je že (500 psov); po potrebi razpršiti čez prvo uro dneva. |

## 4. Podatkovna baza
| Sprožilec | Ukrep |
|---|---|
| Pred javno beto | **Varnostne kopije izven strežnika** — ✅ zgrajeno 14. 10. 2026 (`scripts/backup-offsite.sh`, restic → Hetzner Storage Box: baza + mediji ljubljenčkov vsako noč, šifrirano, hramba ≈ 35 dni, `verify` za preizkus obnove; nastavljeno in preverjeno 30. 10. 2026, PRODUCTION_DEPLOYMENT §6b). Še odprto: WAL arhiv za obnovo na točko v času (PITR). |
| Mediji > 100 GB | Mediji ljubljenčkov iz Docker volumna v objektno shrambo (S3 / Hetzner Object Storage) — ✅ koda 24. 10. 2026 (M4-05, §1). **Varnostna kopija vedra:** `backup-offsite.sh` ob `PET_MEDIA_DISK=s3` vsako noč z rclone zrcali vedro v **drugo vedro na drugi lokaciji** (npr. `nbg1`, `MEDIA_MIRROR_S3_*` v `backup.env`): `current/` = žive datoteke, `deleted/<datum>/` = izbrisane / prepisane, po 35 dneh pobrisane (enako kot restic). Verzioniranje vedra je lahko dodatna zaščita pred pomotnim brisanjem, a ni varnostna kopija (isto vedro, ista lokacija) in izbrisane družine bi ostale v starih verzijah — le s pravilom za iztek starih verzij (≤ 35 dni). |
| Povezave PostgreSQL blizu `max_connections` | Vsak PHP proces (FPM, delavci, scheduler) ima lahko poleg glavne še 1 povezavo `system_health` (odpre se šele ob zabeleženi napaki; System health, 19. 10. 2026). Pred PgBouncerjem / več FPM delavci to upoštevaj ali nastavi `SYSTEM_HEALTH_ISOLATED_CONNECTION=false`. |
| DB > 50 % RAM-a ali počasne poizvedbe | Postgres na svoj strežnik (ali upravljana baza), `pg_stat_statements`, indeksi. |
| Veliko branja (dashboard, poročila) | Bralna replika za poročila; agregati (tedenski / 12-tedenski) materializirani. |
| `activities_log` > 10 M vrstic | Particioniranje po mesecih, arhiviranje starih podatkov po poteku izziva (tudi zaradi GDPR minimizacije). |

## 5. Opazovanje in zanesljivost
- Sledenje napak: **lasten »System health« namesto Sentryja** (David 19. 10. 2026) — napake strežnika od PR #281, napake mobilne aplikacije od M5-05 (`POST /api/app-errors`, tabele `app_error_*`, brez povezave z uporabnikom, stikalo »App error reports« privzeto izklopljeno). Rast je omejena: upsert na napako × različico × platformo, največ 200 novih prstnih odtisov na uro (nad tem `overflow`), Discord največ 10 objav na uro, hramba 30 dni. Še manjka: uptime monitor; opozorila (tick zamuja, queue raste, disk, poraba AI) so delno v System health / Discord.
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
| 2026-10-24 | M4-05 objektna shramba v kodi (privzeto lokalni disk, preklopi David): disk `pet_media_s3`, 302 na podpisan URL vedra, `media:move-to-object-storage`, zrcaljenje vedra v varnostni kopiji, System health »Shramba medijev«; §1, §4 posodobljeni, CDN možnosti zapisane. | `feat/M4-05-object-storage` |
| 2026-10-28 | Izpeljani posnetki šole (ffmpeg na strežniku, 0 $): prostor ~3,5–5 MB na ukaz na videz, pomnilnik ~260–370 MB na posnetek; §1. | `feat/school-smooth-backend` |
| 2026-10-30 | Stiskanje shranjenih AI videov (David, za stikalom, privzeto izklopljeno; QA #450): vsak posnetek enkrat H.264 CRF 21 v **nov ključ** `…-c.mp4`, samo pri ≥ 30 % prihranka, SSIM ≥ 0,98 (vsaka sličica ≥ 0,95); izvirnik se izbriše po 3 h, ko ga nič ne uporablja. Produkcija 30. 10.: ~1,56 GB posnetkov (Kling ~23 Mbps), pričakovano ~85 % manj. **Viri:** ponoči 22.00–6.00, ≤ 10 min na uro, `nice 19`, 2 niti, vsak ffmpeg omejen na 2 GB naslovnega prostora (`prlimit --as`; izmerjeno: 1 GB premalo, 1,5 GB dovolj, vrh RSS ~570 MB) — brez `mem_limit` na vsebniku scheduler. `media:disk-report` (samo poročilo, en seznam z velikostmi tudi na S3). | `feat/media-compress` |
| 2026-10-30 | **Novi posnetki stisnjeni, preden se pokažejo** (David): pri vklopljenem stikalu vsak nov posnetek med preverjanjem kakovosti in prikazom (`CompressPetMediaCandidate`, privzeta vrsta, 1 delavec). Izmerjeno (2 vCPU, sintetični 5 s 1076 × 1924 24 fps 23 Mbps, CRF 21): slow 10,4 s / -93,4 %, medium 7,3 s / -93,5 %, fast 6,6 s / -93,0 %, **veryfast 4,1 s / -94,0 % (SSIM 0,992)** + SSIM 2,2 s → izbran `veryfast` za cevovod (nočni ostane `slow`). Nov 5-s posnetek čaka ~7 s dlje, 3-s ~4 s; poln komplet ~20 posnetkov ~2–3 min dlje skupaj, domači (idle, spanje, lačen) ~20 s. Zrnat posnetek: vsi preseti zavrnjeni (prikaže se izvirnik). | `feat/media-compress-on-store` |
| 2026-10-30 | Varčni posnetki (ffmpeg, 0 $): obrat / zanka doma v polni ločljivosti, ~645 MB RSS na obrat 5 s, `nice` + `prlimit --as` 2048 MB, eno težko ffmpeg delo naenkrat; §1. **Isti dan obrat opuščen** (David): novih obratov ni več — ostane samo zanka naprej-nazaj (~494 MB RSS na 3 s); obstoječe obrate zamenja `media:frugal-unreverse` (fal, ~0,56 $ na videz / ljubljenčka) in jih nato izbriše. | `feat/frugal-clips`, `fix/frugal-no-reverse` |
| 2026-10-31 | **Tehnološki sklad ostane PHP / Laravel** (David vprašal za Node.js; ozka grla so baza, mediji, vrste — ne jezik); pogoj podprta verzija ogrodja → Laravel 12 (M0-07) pred zagonom; Octane v §2; odprto: načrt do 1 milijona uporabnikov s stroški; §2b. | — |
