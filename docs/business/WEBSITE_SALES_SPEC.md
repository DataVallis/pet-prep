# PetPrep — prodaja prek spletne strani (darilni bon, natisljivi komplet, stran za partnerje)

> **Specifikacija, 11. 11. 2026; prenovljena 17. 11. 2026 — plačila prek Stripe Managed Payments namesto Lemon Squeezy, lastna vrsta e-pošte, admin kot trgovina.** Lemon Squeezy je 17. 11. 2026 zavrnil trgovino PetPrep (ne sprejema več novih trgovin, zapre se 31. 3. 2027). David ima Stripe Managed Payments odobren na računu »David Tacer s.p.« in je odločil: **»vse bova s Stripe delala«**, **e-pošta prek lastnega strežnika `mail.datavallis.com` z vrsto v bazi in ponovnimi poskusi**, **admin mora biti kot trgovina (naročila, izdelki, zgodovina nakupov, dostop do kod)** (David 17. 11. 2026).
> Kar je označeno **(David)**, je njegova odločitev. Kar je označeno *(Claude, David lahko spremeni)*, je moj predlog.
> Naloge v ROADMAP: **M6-S01** (darilni bon), **M6-S02** (natisljivi komplet), **M6-S03** (partnerji, zgrajeno), **M6-S09** (Stripe namesto Lemon Squeezy, vrsta e-pošte, admin trgovina).
> Stanje 17. 11. 2026: M6-S09 združen — strežnik `pet-prep` #1330 (en krog QA plačil, popravki v istem PR), spletna stran `pet-prep-website` #474; na produkcijo pride z naslednjim deployem (03:17). Lemon Squeezy odstranjen; Stripe (testni način) nastavljen — izdelka, ceni, webhook (§3.5). Vse za stikali (privzeto izklopljeno). Naslednje: Davidovi koraki §3.9.

## 1. Cilj in obseg

| # | Izdelek | Cena |
|---|---|---|
| A | **Darilni bon za 12-tedenski izziv** | 49,99 € z DDV |
| B | **Natisljivi komplet »Obljuba o ljubljenčku«** (PDF) | 6,90 € z DDV; priložen vsakemu bonu |
| C | **Stran za partnerje** (zgrajena, brez plačil) | — |

Jeziki: **vseh 15 jezikov spletne strani** (pravilo 16. 11. 2026). Cene z DDV **(David 11. 11. 2026)**.

## 2. Skupna pravila

- **Plačila: Stripe Managed Payments** **(David 17. 11. 2026)** — račun »David Tacer s.p.«. Prodajalec (merchant of record) je Stripe prek Link: DDV v 80+ državah, račun in potrdilo kupcu, spori, podpora za plačila. Kupec na izpisku vidi `LINK.COM* <opis>`.
  - Samo digitalni izdelki. Fizično blago (Curio plišek, puzzle) kasneje na navadnem Stripe.
  - Integracija: **Checkout Session**, ki jo ustvari naš strežnik (`mode=payment`, `managed_payments[enabled]=true`, različica API `2025-03-31.basil`). **Ime in logotip blagajne PetPrep** se nastavita na vsaki seji (`branding_settings`), ker isti račun Stripe uporabljajo tudi druge Davidove prodaje *(Claude)*.
  - E-pošt Link ne moremo spreminjati. **Naše vsebine (kodo, PDF) pošlje PetPrep sam** (§3.6) in jih pokaže na strani »hvala«.
  - Link sme v nekaterih primerih sam vrniti denar v 60 dneh. Webhook vračilo obravnava ne glede na to, kdo ga je sprožil.
- **Aplikacija nikoli ne omenja spletnega nakupa**, nima povezave nanj in nima vnosa kode (pravila App Store 3.1.1, 3.1.3(b), 1.3 in Google Play o plačilih — preverjeno 11. 11. 2026; skladno, dokler aplikacija bona ne omenja in izziv ostane tudi nakup v aplikaciji).
- **Spletna stran kaže samo, kar res obstaja**: stran bona je javna, ko je aplikacija v vsaj eni trgovini in unovčitev deluje na produkciji. Do takrat stikalo.
- **Besedila izvirna**, staršem vikamo, brez starosti otrok, Curio je maskota. CGP »Grafit in meta«, brez majhnih monospace oznak.
- **Otroški podatki:** nikjer.
- **Zunanje povezave** v novem zavihku; izjema: gumb za nakup preusmeri na blagajno v istem zavihku *(Claude)*.

## 3. A — Darilni bon (M6-S01, M6-S09)

### 3.1 Kaj kupec dobi
- En bon = en 12-tedenski izziv za enega ljubljenčka (katerakoli vrsta in pasma v aplikaciji).
- **E-pošta PetPrep** s kodo, natisljivim bonom (PDF priponka) in povezavo na komplet B (PDF) v jeziku nakupa; **stran »hvala«** z isto vsebino. Račun pošlje Link.
- Veljavnost 12 mesecev od nakupa *(Claude)*. Vračilo: neunovčen 14 dni, unovčen ne *(Claude; pravni pregled z M5-06)*; Link lahko izjemoma vrne do 60 dni.

### 3.2 Strani na petprep.si (15 jezikov)
- **Bon** `/gift` · `/sl/darilni-bon`: vsebina kot doslej; gumb pokliče `POST /api/shop/checkout {product: gift_voucher, locale, success_path, cancel_path}` (poti strani v jeziku obiskovalca; strežnik sprejme samo pot na petprep.si) in preusmeri na `url`.
- **»Hvala«** `…/hvala?session_id={CHECKOUT_SESSION_ID}`: `GET /api/shop/orders/{session_id}` → koda (gumb »Kopiraj«), natisljiv bon (tiskalni slog, A4, dva na list, »Za« / »Od«), prenos kompleta B, »Kodo smo poslali tudi na <delno skrit e-naslov>«. Dokler plačilo ni potrjeno: »Plačilo še potrjujemo …« in ponovni poskusi do ~2 min.
- **Unovčitev** `/redeem` · `/sl/unovci`: prijava starša kot v aplikaciji + koda (M6-S01b, nespremenjeno).
- Strani »hvala« in unovčitev: `noindex`, brez zunanjih skript; vse za `NEXT_PUBLIC_FEATURE_GIFT`.

### 3.3 Unovčitev (nespremenjeno)
Prijavljen starš vnese kodo → družina dobi kupljen izziv (neporabljen nakup v aplikaciji), potisno obvestilo brez imen in brez omembe bona. Napake: neveljavna, potekla, vrnjena, unovčena v drugi družini.

### 3.4 Strežnik — trgovina (`pet-prep`)
**Splošni del trgovine** (za bon, komplet in prihodnje digitalne izdelke):
- **Izdelki in cene živijo v Stripe** (vir resnice, tam se računa DDV). `config/shop.php` preslika ključ izdelka (`gift_voucher`, `promise_kit`) na Stripe Price ID (env) in na admin stikalo. Tabela **`shop_products`** je kopija za admin (ime, opis, cena, valuta, aktiven, Stripe ID-ji, `livemode`), osvežena z ukazom `shop:sync-products` in gumbom »Osveži iz Stripe«. Urejanje cen samo v Stripe (povezava iz admina).
- **`POST /api/shop/checkout {product, locale, success_path, cancel_path}`** (javno; 10 / min in 30 / h na IP; stikalo izdelka izklopljeno → 503; pot samo na petprep.si — nikoli odprta preusmeritev): Checkout Session z eno postavko, `managed_payments[enabled]=true`, `success_url` (stran »hvala« + `?session_id=…`), `cancel_url`, `metadata {petprep_product, locale}`, `branding_settings` PetPrep, `locale` blagajne. Vrne `{url}`.
- **Tabela `shop_orders`** (eno naročilo = ena Checkout Session): številka naročila PetPrep (`PP-2026-000123`), izdelek, `stripe_checkout_session_id` (UNIQUE), `stripe_payment_intent_id`, `livemode`, znesek, DDV, vrnjeni znesek, valuta, jezik, **e-naslov in ime kupca** (iz Stripe, potrebno za pošiljanje — politika zasebnosti dobi odstavek), država kupca, stanje (`pending` / `paid` / `refunded` / `partially_refunded` / `disputed` / `failed`), časi.
- **Izpolnitev na enem mestu** `ShopFulfillment::fulfill(sessionId)`: prebere sejo s postavkami iz Stripe, preveri `payment_status = paid`, ceno (natanko nastavljena, količina 1) in izdelek, `livemode` (testne samo z `STRIPE_ACCEPT_TEST_MODE=true`); pod zaklepom ustvari/posodobi naročilo in **natanko enkrat** izvede dostavo (bon → koda; komplet → nič dodatnega) ter postavi e-pošto v vrsto. Kličeta jo webhook in stran »hvala« (kar pride prej).
- **`GET /api/shop/orders/{session_id}`** (javno, 60 / min na IP): po potrebi `fulfill`; plačano → `{status, product, order_number, locale, email_masked, voucher: {code, purchased_at, expires_at, status}|null, downloads: [{locale, url}]}` (podpisane povezave, 24 h); v obdelavi → `202`; neznano → 404. Koda in povezave se vračajo 30 dni po nakupu in samo, dokler naročilo ni vrnjeno *(Claude)*.
- **Webhook `POST /api/webhooks/stripe`**: podpis `Stripe-Signature` (HMAC-SHA256 s `STRIPE_WEBHOOK_SECRET`, toleranca 5 min, brez zunanjega paketa); brez ključa → 503. Vsak dogodek enkrat po `event.id` (tabela `stripe_events` brez osebnih podatkov). Dogodki:
  - `checkout.session.completed` (plačano), `checkout.session.async_payment_succeeded` → `fulfill`;
  - `checkout.session.async_payment_failed` → naročilo `failed`;
  - `charge.refunded`: celotno → naročilo `refunded` + pravila izdelka (bon: neunovčen → `refunded`; unovčen in nedodeljen → kredit preklican; dodeljen in izziv ni končan → obstoječe pravilo vračila; končan → oznaka; komplet: povezave ne delujejo več); delno → `partially_refunded` + Discord;
  - `charge.dispute.created` → `disputed` + Discord; `charge.dispute.closed` dobljen → nazaj `paid`; izgubljen → kot celotno vračilo (bon `disabled`) + Discord.
  - Plačana seja z našo oznako, a s tujo ceno, količino ali načinom → nič se ne dostavi, dogodek `rejected` in **opozorilo v Discord** (npr. cena spremenjena med odprto blagajno); sprejme se samo `payment_status = paid`.
  - Stripe nedosegljiv med izpolnitvijo → 503, Stripe poskusi znova. Deluje ne glede na stikala.
- **Brez paketa `stripe/stripe-php`**: klici z Laravel `Http` (`Shop\StripeClient`), glava `Stripe-Version` fiksna *(Claude; packagist v oblaku ni dosegljiv, odvisnost ni potrebna)*.

**Bon:**
- Koda `PP-XXXX-XXXX-XXXX` (Crockford base32, 60 naključnih bitov); vnos je prizanesljiv (male črke, presledki, brez vezajev, brez »PP«, O namesto 0, I / L namesto 1).
- `gift_vouchers`: `shop_order_id`, zgoščena koda (SHA-256, iskanje), **šifrirana koda** (`Crypt`, za prikaz, ponovno pošiljanje in podporo), zadnji 4 znaki, stanje (`purchased` / `redeemed` / `refunded` / `expired` / `disabled`), nakup, velja do (+12 mesecev), unovčitev, družina.
- `POST /api/gift/redeem {code, locale}` (samo spletni žeton): lokalno preverjanje, unovčitev in kredit v eni transakciji z zaklepom. Kredit: `challenge_credits.source = web_gift`, `store = STRIPE`, `product_id = stripe:<price id>`. Testni (SANDBOX) bon se ne unovči, dokler je `STRIPE_ACCEPT_TEST_MODE` izklopljen.
- **Odstranjeno** vse Lemon Squeezy (storitve, webhook, `lemonsqueezy_events`, env). Migracija `2027_04_16_120000_stripe_shop` preoblikuje `gift_vouchers` (zavrne se, če obstaja kak bon Lemon Squeezy).

**Komplet:** dostava = podpisane povezave do PDF — na strani »hvala« vseh 15 jezikov (jezik nakupa prvi), v e-pošti jezik nakupa in angleščina (krajša e-pošta) *(Claude)*; po vračilu ne delujejo. PDF-ji živijo na zasebnem disku strežnika (`shop-files/<izdelek>/<jezik>.pdf`) — naloži jih superadmin v **Izdelki → Naloži PDF** ali `php artisan shop:import-files <mapa>` (izhod `npm run kit:build`). **Brezplačen vzorec** prek obstoječega `POST /api/summary-leads` (`source: promise_kit_sample`) → odgovor ima `sample_url` (podpisana povezava 24 h; angleščina, če jezika ni; `null`, dokler vzorec ni naložen).

### 3.5 Okolje
`STRIPE_SECRET_KEY` (omejen ključ: Checkout Sessions pisanje; Products, Prices, Payment Intents branje), `STRIPE_WEBHOOK_SECRET`, `STRIPE_PRICE_GIFT_VOUCHER`, `STRIPE_PRICE_PROMISE_KIT`, `STRIPE_ACCEPT_TEST_MODE` (privzeto `false`); pošta: `MAIL_MAILER=smtp`, `MAIL_SCHEME=smtps`, `MAIL_HOST=mail.datavallis.com`, `MAIL_PORT=465`, `MAIL_USERNAME`, `MAIL_PASSWORD`; pošiljatelj v `config/shop.php` (`SHOP_MAIL_FROM_ADDRESS` = `curio@petprep.si`, `SHOP_MAIL_FROM_NAME` = `Curio from PetPrep`, `SHOP_MAIL_REPLY_TO` = `hello@petprep.si`). Spletna stran: `NEXT_PUBLIC_FEATURE_GIFT`, `NEXT_PUBLIC_FEATURE_PROMISE_KIT`, `NEXT_PUBLIC_PETPREP_API_URL`; `NEXT_PUBLIC_LS_GIFT_URL` je odstranjen.

Testni ID-ji (Stripe test, 17. 11. 2026): izdelek bona `prod_Ve7EBi9SnjHz6l` / cena `price_1Ucp0KEShsvVJ1lPgY9IEF3q` (davčna koda `txcd_10103100`); komplet `prod_Ve7EcVKgJAPySN` / `price_1Ucp0KEShsvVJ1lP33ltYxfp` (`txcd_10302000`); webhook `we_1Ucp0iEShsvVJ1lP1AhiSHtP`. Oba izdelka testna blagajna z Managed Payments sprejme. **Testni nakup kompleta in vračilo uspela 17. 11. 2026** (naročili PP-2026-000001/000002). **Živi ID-ji (17. 11. 2026):** bon `prod_VeC0RL4UebALpX` / `price_1UctcmEShsvVJ1lPS8vmaIBG`; komplet `prod_VeC0cTJSEB4Zvu` / `price_1UctcnEShsvVJ1lPY0fK1Bdy`; webhook `we_1UctcnEShsvVJ1lPeQADrDTx` (isti URL, svoj podpisni ključ).

### 3.6 Vrsta e-pošte (`outbound_emails`) **(David 17. 11. 2026)**
- Vsaka e-pošta PetPrepa se najprej **zapiše v bazo s celotnim HTML** (in besedilno različico): prejemnik, zadeva, HTML, besedilo, priponke (samo opis — PDF bona se nariše ob pošiljanju), vrsta (`gift_voucher`, `promise_kit`, …), povezava na naročilo, jezik, stanje (`pending` / `sending` / `sent` / `failed` / `cancelled`), število poskusov, naslednji poskus, zadnja napaka, poslano ob.
- **Pošiljatelj v ozadju**: ukaz `emails:send` vsako minuto vzame do 50 čakajočih (`next_attempt_at <= now`, zaklep `FOR UPDATE SKIP LOCKED`) in jih pošlje prek SMTP `mail.datavallis.com`.
- **Napaka** → ponovni poskus z razmikom *(Claude)*: 5 min, 30 min, 2 h, 6 h, 24 h (skupaj 6 poskusov). Po zadnjem `failed` + eno opozorilo v Discord in rdeča številka ob »E-pošta« v adminu (»E-pošte ni bilo mogoče poslati — verjetno napačen e-naslov«). Strežnik zavrne prejemnika (550 / 551 / 553, 5.1.x, npr. neobstoječ naslov) → takoj `failed`. Vrstica, ki obvisi v `sending` (sesuta izvedba), po 15 min šteje kot neuspel poskus in gre na naslednji razmik.
- Admin: seznam e-pošt z iskanjem, predogled HTML, »Pošlji znova«, »Spremeni e-naslov in pošlji« (na naročilu), »Prekliči«.
- **Koda bona ni nikoli shranjena v vrsti:** HTML in besedilo imata namesto kode žeton, pošiljatelj ga tik pred pošiljanjem zamenja s kodo iz šifriranega bona; predogled v adminu kaže zakrito kodo (QA 17. 11. 2026). E-pošta naročila, ki je medtem vrnjeno, se prekliče, ne pošlje.
- **Poštni strežnik nedosegljiv ali zavrne prijavo** (povezava, 421, 530 / 535) → ta e-pošta gre na naslednji poskus, ostale iz serije ostanejo v vrsti nedotaknjene, eno opozorilo v Discord na uro. Trajno neuspešno takoj samo, ko strežnik zavrne prejemnika (550 / 551 / 553, 5.1.x).
- Pošiljatelj **`curio@petprep.si`**, ime prevedeno v jezik e-pošte (»Curio iz PetPrep«, »Curio from PetPrep«, »Curio von PetPrep« …, David 17. 11. 2026); logotip je vgrajen v e-pošto (prikaže se tudi, ko odjemalec blokira slike z interneta); naročilo kompleta ima PDF kompleta v jeziku nakupa kot priponko.
- Prej: pošiljatelj **`Curio from PetPrep <curio@petprep.si>`**, odgovori na `hello@petprep.si` (David 17. 11. 2026: ne `noreply@`; ime Data Vallis kupec ne vidi). **SPF, DKIM in DMARC za `petprep.si`** morajo dovoliti `mail.datavallis.com` (David).
- Ista vrsta služi kasneje vsem e-poštam PetPrepa (potrditev računa, ponastavitev gesla — M2-10b).
- Besedila v vseh 15 jezikih (`lang/<jezik>/shop.php`).

### 3.7 Admin kot trgovina (samo superadmin) **(David 17. 11. 2026)**
- **Naročila**: seznam (številka, datum, izdelek, znesek, DDV, valuta, kupec, država, stanje, test/živo), filtri in iskanje (e-naslov, številka, ID Stripe); podrobnosti z bonom, e-poštami, dogodki Stripe in povezavo na plačilo v Stripe. Dejanja: »Pokaži kodo«, »Pošlji e-pošto znova«, »Spremeni e-naslov in pošlji«, »Odpri v Stripe«. Vračilo se sproži v Stripe, stanje se posodobi samo.
- **Izdelki**: seznam iz `shop_products` (cena, aktiven, število prodanih, prihodek, kateri jeziki imajo PDF), »Osveži iz Stripe«, »Naloži PDF«, povezava za urejanje v Stripe. Stikali prodaje: /admin → Funkcije (»Darilni boni«, »Komplet Obljuba«).
- **Boni**: koda skrita, **»Pokaži kodo«** (na bonu in na naročilu) in **»Pošlji e-pošto znova«** na naročilu (vsako dejanje zabeleženo v `shop_audit_logs`: kdo, kdaj), stanje, družina, ki je unovčila, ljubljenček.
- **Kupci**: zgodovina nakupov po e-naslovu — klik na e-naslov v naročilu odpre vsa naročila istega kupca.
- **E-pošta**: vrsta iz §3.6.
- **Pregled**: prodaja danes / ta mesec po izdelku na vrhu naročil (iz `shop_orders`, samo živi način — za računovodstvo velja Stripe).
- Lijak: »Plačali z bonom« ostane.

### 3.8 Testi
`GiftVoucherTest` (webhook → naročilo + bon, dvojni dogodek in stran »hvala« hkrati, async plačilo, tuja cena / količina, testni način, podpis, Stripe nedosegljiv, unovčitev, vračila, spori), `ShopTest` (blagajna, odprta preusmeritev, stran »hvala«, prenosi, vzorec, admin, uvoz PDF, 15 jezikov), `OutboundEmailTest` (pošiljanje s priponko, razmiki, zavrnjen prejemnik, napačno geslo SMTP 535, obviseli `sending`, vrnjeno naročilo, urnik), `GiftVoucherRegressionTest` (RevenueCat nespremenjen).

### 3.9 Kaj mora narediti David
1. V `/opt/petprep/.env`: Stripe **testni** ključi najprej (`STRIPE_SECRET_KEY` = omejen testni ključ, `STRIPE_WEBHOOK_SECRET` = podpisni ključ testnega webhooka, cene iz §3.5, `STRIPE_ACCEPT_TEST_MODE=true`) in SMTP podatki.
2. Na `mail.datavallis.com` nabiralnik ali alias `curio@petprep.si`; DNS za `petprep.si`: SPF, DKIM, DMARC za `mail.datavallis.com`.
3. Testni nakup bona in kompleta s kartico `4242 4242 4242 4242`, testno vračilo.
4. Nato Claude ustvari izdelka in webhook v živem načinu; ti zamenjaš ključe v `.env` in nastaviš `STRIPE_ACCEPT_TEST_MODE=false`.
5. E-naslov za podporo v Stripe (Business details), ki ga res bereš (Link eskalira; brez odgovora v 48 h lahko sam vrne denar).
6. Pogoji uporabe in politika zasebnosti na petprep.si: odstavek o bonih in o e-naslovu kupca.
7. PDF-ji kompleta: `npm run kit:build` v `pet-prep-website`, nato **Izdelki → Naloži PDF** (ali `shop:import-files`).
8. Ko je aplikacija v trgovinah: stikalo »Darilni boni« v adminu in `NEXT_PUBLIC_FEATURE_GIFT=1`.

## 4. B — Komplet »Obljuba o ljubljenčku« (M6-S02)
Vsebina kot doslej (8 delov, približno 10 strani, 15 jezikov, `npm run kit:build`, kopija v Google Drive). Prodaja prek trgovine (§3.4), stran `/promise-kit` · `/sl/obljuba-o-ljubljencku`, stran »hvala« s prenosom, e-pošta s povezavami, brezplačen vzorec prek `summary-leads`. Stran vklopljena 17. 11. 2026 (David: »obdržimo, vklopi«; `NEXT_PUBLIC_FEATURE_PROMISE_KIT=1`). Nakup za admin stikalom »Komplet Obljuba« — vklopi se z živimi ključi Stripe in naloženimi PDF-ji (§3.9). Ni povezano z dinamičnim povzetkom »Koliko dela je pes« (T8).

## 5. C — Partnerji (M6-S03)
Zgrajeno 11. 11. 2026 (15 jezikov 17. 11. 2026). Ni plačil.

## 6. Vrstni red
1. M6-S09 strežnik — en PR v `pet-prep` (QA plačila).
2. M6-S09 spletna stran — en PR v `pet-prep-website`.
3. Davidov testni nakup (§3.9), nato živi način.
4. Objava bona ob izidu aplikacije v trgovinah; komplet ob potrditvi besedila.

## 7. Odprto (privzeto velja predlog)
| # | Vprašanje | Predlog |
|---|---|---|
| S1 | Cena kompleta | 6,90 € z DDV |
| S2 | Veljavnost bona | 12 mesecev |
| S5 | Vračilo bona | neunovčen 14 dni, unovčen ne |
| S9 | Razmiki ponovnih poskusov e-pošte | 5 min, 30 min, 2 h, 6 h, 24 h, nato `failed` |
| S10 | Pošiljatelj | `curio@petprep.si` z imenom v jeziku e-pošte (»Curio iz PetPrep«), odgovori `hello@petprep.si` |
