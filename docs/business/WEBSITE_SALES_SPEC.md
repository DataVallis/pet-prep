# PetPrep — prodaja prek spletne strani (darilni bon, natisljivi komplet, stran za partnerje)

> **Specifikacija, 11. 11. 2026.** David je 11. 11. 2026 izbral tri stvari iz `REVENUE_MODELS.md` §5 (»1, 2 in 5 — dajmo naredit«) in potrdil, da bo aplikacija v trgovinah čez približno en teden. Vse je *načrt* — nič še ni zgrajeno.
> Kar je označeno **(David)**, je njegova odločitev. Kar je označeno *(Claude, David lahko spremeni)*, je moj predlog, s katerim agent lahko začne. Tehnični del je napisan iz dokumentov in hitrega pregleda kode, zato ga mora inženir pred gradnjo preveriti v obeh repozitorijih (`pet-prep`, `pet-prep-website`) in v trenutni dokumentaciji Lemon Squeezy.
> Naloge v ROADMAP: **M6-S01** (darilni bon), **M6-S02** (natisljivi komplet), **M6-S03** (stran za partnerje).

## 1. Cilj in obseg

Prvi prihodek prek petprep.si, neodvisno od nakupov v trgovinah:

| # | Izdelek | Kdo kupi | Cena *(Claude)* |
|---|---|---|---|
| A | **Darilni bon za 12-tedenski izziv** | starši, stari starši, tete in strici — pred božičem | 49,99 € (enako kot v aplikaciji) |
| B | **Natisljivi komplet »Obljuba o ljubljenčku«** | starši, ki jih otrok prosi za žival | 6,90 €; priložen brezplačno vsakemu bonu |
| C | **Stran za partnerje in sponzorje** z medijskim kompletom in ponudbo »ustanovni partner« | zavarovalnice, trgovine, veterinarji, pasje šole, vzreditelji | po dogovoru; ustanovni vpis v imenik 190 € na leto |

Ni v obsegu: vodniki po pasmah, plačljive pobarvanke, partnerske povezave, naročnina za pravega ljubljenčka (`REVENUE_MODELS.md`).

Jeziki: **slovenščina in angleščina** *(Claude)*. Ostalih 11 jezikov spletne strani pride kasneje (val 1 validacije je Slovenija; načrt validacije nove jezike zamrzne).

## 2. Skupna pravila

- **Plačila: Lemon Squeezy** (David ima račun). Lemon Squeezy je prodajalec (obračuna DDV, izda račun, pošlje e-pošto s potrdilom in izvede vračilo). PetPrep strežnik danes ne pošilja e-pošte (`MAIL_MAILER=log`), zato kupec vse dobi v e-pošti Lemon Squeezy in na strani po nakupu.
- **Aplikacija nikoli ne omenja spletnega nakupa** in ne vsebuje povezave nanj; v aplikaciji ni polja za vnos kode. Bon se unovči na spletni strani (§3.3). **Pravila trgovin (Claude preveril 11. 11. 2026 v uradnih virih; ni pravno mnenje):**
  - *Apple App Review Guidelines:* 3.1.1 — aplikacija ne sme odklepati vsebine z lastnimi mehanizmi, kot so licenčni ključi ali kode QR, in darilni boni za digitalno vsebino se **v aplikaciji** smejo prodajati samo z nakupom v aplikaciji → zato ni vnosa kode v aplikaciji in bona v aplikaciji ne prodajamo. 3.1.3(b) — aplikacija, ki deluje na več platformah, sme uporabniku dati dostop do vsebine, kupljene drugje (tudi na spletu), **če je ista vsebina na voljo tudi kot nakup v aplikaciji** → 12-tedenski izziv je. 3.1.3 — aplikacija ne sme spodbujati nakupa zunaj nje (izjeme: ZDA in posebna dovoljenja), razvijalec pa sme o drugih možnostih obveščati **zunaj aplikacije**. 1.3 (otroška kategorija) — brez povezav ven in nakupnih priložnosti zunaj starševske zapore → ne dodajamo nobene. <https://developer.apple.com/app-store/review/guidelines/> (zadnjega dela strani orodje ni prebralo).
  - *Google Play, pravilnik o plačilih:* Googlov sistem je obvezen za nakupe **v aplikaciji**; aplikacija uporabnika ne sme voditi k drugemu načinu plačila; zunaj aplikacije (e-pošta, splet) sme razvijalec ponujati druge možnosti; uporabnik sme v aplikaciji dostopati do vsebine, plačane drugje. <https://support.google.com/googleplay/android-developer/answer/10281818>, <https://play.google.com/about/monetization-ads/payments>
  - **Sklep:** prodaja bona na petprep.si in unovčitev na spletni strani sta s temi pravili skladna, dokler aplikacija bona ne omenja, nima povezave nanj in nima vnosa kode, izziv pa ostane na voljo tudi kot nakup v aplikaciji. Ostane tveganje presoje posameznega pregledovalca; opis v trgovinah naj bona ne omenja.
- **Spletna stran kaže samo, kar res obstaja** (pravilo 13. 10. 2026): stran bona je javna šele, ko je aplikacija v vsaj eni trgovini in unovčitev deluje na produkciji (David 11. 11. 2026: prodaja bonov zunaj aplikacije je naša stvar; pravila trgovin zgoraj tega ne prepovedujejo). Do takrat je za stikalom.
- **Besedila so izvirna**, v glasu znamke (`brand/README.md`), brez prepisovanja z drugih strani. Staršem v slovenščini vikamo. Nikoli ne omenjamo starosti otrok in ne kažemo otroka, prilepljenega na telefon. Curio je maskota znamke, ne ljubljenček iz aplikacije.
- **Videz:** obstoječa CGP »Grafit in meta«, obstoječe komponente strani. Brez majhnih monospace oznak. Obstoječi logotipi partnerjev kot logotipi, ne besedilo.
- **Brez izmišljenih številk.** Na strani za partnerje samo izmerjeni podatki z datumom; česar še ni, se ne navaja.
- **Otroški podatki:** v nobenem od treh izdelkov ni osebnih podatkov otroka. Komplet se izpolni na papirju.
- **Vse zunanje povezave** se odprejo v novem zavihku (pravilo 31. 10. 2026).
- **Merjenje:** stran »Lijak« (`/admin/funnel`) dobi ločen stolpec za izzive, plačane z darilnim bonom. Prodaja bonov in kompletov se bere v Lemon Squeezy; PetPrep je ne podvaja.

## 3. A — Darilni bon za 12-tedenski izziv (M6-S01)

### 3.1 Kaj kupec dobi
- En bon = **en 12-tedenski izziv za enega ljubljenčka**, katerokoli vrsto in pasmo, ki je takrat v aplikaciji. Enako kot nakup v aplikaciji: poln nabor posnetkov, celotna zgodovina, potrdilo.
- Kodo bona (v e-pošti Lemon Squeezy in na strani po nakupu), **natisljiv bon** (A4, dve na stran za prepogib; polja »Za« in »Od« se izpolnijo ročno) in **komplet B** kot priloženi PDF.
- Veljavnost: **12 mesecev od nakupa** *(Claude)*. Vračilo: po pogojih Lemon Squeezy in potrošniški zakonodaji; **unovčen bon ni vračljiv**, neunovčen je vračljiv 14 dni *(Claude; pravni pregled z M5-06)*.
- Sporočilo strani: »Ne podarite kužka. Podarite preizkus.« — bon je darilo otroku, ki si želi žival, in odgovor staršem, ali je pripravljen. Brez obljube, da bo otrok žival dobil.

### 3.2 Stran na petprep.si
`/gift` · `/sl/darilni-bon`. Razdelki: naslov in gumb za nakup; kaj je PetPrep v treh stavkih; kako deluje bon (kupi → natisni → starš unovči → otrok začne); kaj je vključeno; za koga je in za koga ni; pogosta vprašanja (veljavnost, vračilo, katere naprave, ali rabim račun, ali velja za mačko); povezava na unovčitev. Gumb odpre nakup Lemon Squeezy. Po nakupu Lemon Squeezy preusmeri na `/gift/thanks` · `/sl/darilni-bon/hvala`, kjer je koda, gumb »Natisni bon« in navodila za unovčitev.

Natisljiv bon je stran s tiskalnim slogom (brez strežnika): logotip, Curio, »12-tedenski PetPrep izziv«, koda, naslov strani za unovčitev, veljavnost. Kode ne shranjujemo v brskalniku.

### 3.3 Unovčitev — na spletni strani, ne v aplikaciji
`/redeem` · `/sl/unovci`. Starš vnese **kodo** in **e-naslov svojega starševskega računa** v aplikaciji.
1. Koda ni veljavna, je potekla, vrnjena ali že unovčena → jasno sporočilo, nič se ne zgodi.
2. Koda je veljavna, računa s tem e-naslovom ni → »Najprej v aplikaciji ustvarite račun starša, nato se vrnite sem.« Koda ostane neporabljena.
3. Koda je veljavna in račun obstaja → družina dobi **en kupljen izziv**; stran pokaže »Izziv je dodan. Odprite aplikacijo in izberite ljubljenčka.« Starš dobi potisno obvestilo (brez imen).

V aplikaciji se tak izziv pokaže kot obstoječi **neporabljen nakup** (»Uporabi kupljen izziv«), starš ga dodeli ljubljenčku — nov ali nadgradnja brezplačnega (`FREE_PET_UPGRADE_SPEC.md`). 12 tednov začne teči ob dodelitvi, ne ob unovčitvi.

Varnost: kdor ima veljavno kodo, lahko izve, ali račun z nekim e-naslovom obstaja. To sprejmemo, ker je za vsak poskus potrebna plačana, še neunovčena koda; dodatno omejitev poskusov na IP in na kodo. Starši, prijavljeni z Googlom ali Apple, vnesejo e-naslov računa, ki ga vidijo v aplikaciji (Nadzor → Račun).

### 3.4 Strežnik (`pet-prep`, predlog — preveriti v kodi)
- **Koda = licenčni ključ Lemon Squeezy** izdelka »Darilni bon« (omejitev: 1 aktivacija). Lemon Squeezy ga ustvari ob nakupu in pošlje kupcu, zato PetPrep ne potrebuje lastnega pošiljanja e-pošte. *Agent mora najprej preveriti v trenutni dokumentaciji Lemon Squeezy, da licenčni ključi, License API (`/v1/licenses/activate`, `/validate`) in dogodki `order_created`, `order_refunded`, `license_key_created` še delujejo, kot je tu opisano; če ne, predlaga drugo pot dostave kode, preden gradi.*
- `POST /api/gift/redeem {code, email}` (javno, strogo omejeno): preveri ključ pri Lemon Squeezy (aktivacija z oznako družine), v eni transakciji zapiše unovčitev in ustvari **kredit izziva** za družino. Klic Lemon Squeezy ni znotraj transakcije baze. Idempotentno: ista koda za isto družino vrne isti izid, za drugo družino zavrnitev.
- **Kredit izziva:** obstoječa tabela `challenge_credits` zahteva dogodek nakupa iz RevenueCat. Potrebna je razširitev, da ima kredit izvor (trgovina / spletni bon) in sklic na naročilo Lemon Squeezy; vse ostalo (dodelitev ljubljenčku, »neporabljen nakup«, polni mediji, vračilo, izbris) naj deluje po obstoječih poteh. Izziv, plačan z bonom, šteje kot plačan z nakupom (`challenge_paid_source = purchase`).
- **Webhook Lemon Squeezy** `POST /api/webhooks/lemonsqueezy`: preveri podpis (`X-Signature`, HMAC-SHA256 s skrivnostjo); brez nastavljene skrivnosti zavrne (zaprto, kot RevenueCat). Vsak dogodek enkrat (po ID-ju). `order_created` zabeleži naročilo; `order_refunded` → če bon še ni unovčen, postane neveljaven; če je unovčen in izziv ni končan, velja obstoječe pravilo vračila (ljubljenček se zaklene oziroma se mešanček vrne na brezplačni načrt).
- **Potek:** koda, starejša od 12 mesecev, se zavrne. Kredit, ki je že pri družini, ne poteče.
- **Administracija:** seznam bonov (naročilo, stanje: kupljen / unovčen / vrnjen / potekel, družina, datum), samo branje, brez e-naslova kupca (ostane v Lemon Squeezy). Stikalo »Darilni boni« (izklop = unovčitev vrne »trenutno ni na voljo«).
- **Lijak:** stolpec »Plačali z bonom«.
- Okolje: `LEMONSQUEEZY_WEBHOOK_SECRET`, `LEMONSQUEEZY_STORE_ID`, ID izdelka bona — v `.env.example` in `docs/PRODUCTION_ENV.md`.
- Testi (najmanj): veljavna koda + račun → en kredit; ponovitev → brez drugega kredita; neznan račun → koda ostane; potekla, vrnjena, tuja koda; vračilo pred in po unovčitvi; podpis webhooka; omejitev poskusov; kredit iz bona se dodeli in obnaša kot kupljen (polni mediji, opozorilo ob izbrisu); obstoječi nakupi prek RevenueCat nespremenjeni.

### 3.5 Kaj mora narediti David
1. Lemon Squeezy: izdelek »PetPrep darilni bon — 12-tedenski izziv« (49,99 €, licenčni ključi vklopljeni, 1 aktivacija, veljavnost 12 mesecev, priložena datoteka kompleta B, preusmeritev po nakupu na stran »hvala«), webhook na `https://api.petprep.si/api/webhooks/lemonsqueezy` z dogodki naročil in ključev; skrivnost v `/opt/petprep/.env`.
2. Dopolniti pogoje uporabe na petprep.si z odstavkom o darilnih bonih (veljavnost, vračilo, unovčitev).
3. Povedati, ko je aplikacija v trgovinah — takrat se stran vklopi.

## 4. B — Natisljivi komplet »Obljuba o ljubljenčku« (M6-S02)

### 4.1 Vsebina (PDF, A4, SL in EN, približno 10 strani)
1. **Naslovnica** s Curiom in kratkim nagovorom staršem: čemu komplet služi in česa ne obljublja.
2. **Družinski pogovor:** 10 vprašanj, o katerih se družina pogovori pred odločitvijo (kdo vstane zjutraj, kaj med počitnicami, kdo plača veterinarja, kaj če se otrok naveliča).
3. **Obljuba o ljubljenčku:** pogodba med otrokom in starši z naštetimi vsakodnevnimi nalogami, prostorom za dogovorjena pravila in podpisi vseh.
4. **Tabela nalog za 4 tedne:** hranjenje, voda, sprehod ali igra, čiščenje; ena stran na teden, otrok obkljuka, starš podpiše teden.
5. **Koliko stane ljubljenček:** delovni list s kategorijami (nakup ali posvojitev, oprema, hrana, veterinar, zavarovanje, varstvo med dopustom) in **praznimi polji**, ki jih družina izpolni sama. Brez PetPrepovih zneskov, razen če imajo vir.
6. **Smo pripravljeni?** kontrolni seznam za starše (čas, prostor, alergije, dopust, dolgoročna obveza) z vodilom, kako brati odgovore — brez »ocene« ali diagnoze.
7. **Priznanje za prvi mesec:** stran, ki jo otrok pobarva in starši podpišejo.
8. **Zadnja stran:** kaj je naslednji korak (12-tedenski izziv v aplikaciji) in naslov petprep.si.

Komplet je splošen (pes ali mačka), brez pravil posamezne pasme in brez zdravstvenih nasvetov, zato ne potrebuje veterinarskega pregleda. Strani za otroka so napisane spolno nevtralno.

### 4.2 Prodaja
- Stran `/promise-kit` · `/sl/obljuba-o-ljubljencku`: kaj je v kompletu (predogled 2–3 strani), za koga je, cena, gumb za nakup Lemon Squeezy, povezava na darilni bon.
- **Brezplačen vzorec:** ena stran (Obljuba, točka 3) kot brezplačen izdelek v Lemon Squeezy v zameno za e-naslov starša (z izrecno privolitvijo za obveščanje). To je »Pet Promise« iz `BUSINESS_MODEL.md` §4.
- Komplet je priložen vsakemu darilnemu bonu.
- Izdelava PDF: iz HTML s tiskalnim slogom, skripta v repozitoriju spletne strani, da se komplet ob spremembi besedila zgradi znova; pisave in barve iz `brand/`. Datoteki `petprep-promise-kit-sl.pdf` in `-en.pdf` se shranita tudi v Google Drive (mapa PetPrep, `MEDIA_DRIVE.md`).

### 4.3 Kaj mora narediti David
Pregledati besedilo kompleta pred objavo; v Lemon Squeezy ustvariti izdelek »Obljuba o ljubljenčku« (6,90 €, datoteki SL in EN) in brezplačen izdelek z vzorcem.

## 5. C — Stran za partnerje in sponzorje (M6-S03)

Stran »partnerji« na petprep.si že obstaja; ta naloga jo razširi. Agent najprej prebere obstoječo stran in `docs/audiences/PARTNERS.md` ter ohrani, kar drži.

### 5.1 Vsebina
`/partners` · `/sl/partnerji`:
1. **Komu je PetPrep namenjen in zakaj je to zanimivo za partnerja:** družine tik pred odločitvijo o živali — z znano vrsto in pasmo.
2. **Oblike sodelovanja:**
   - **Sponzorstvo serije »12 Weeks«** in omemba v epizodi.
   - **Sponzorirane vsebine na petprep.si** (stran pasme, baza znanja), vedno označene kot sponzorirane, brez vpliva na vsebino in vire.
   - **Zavarovalnice:** plačilo za posredovan stik ali fiksno sponzorstvo. Stik se posreduje samo z izrecno privolitvijo starša; podatki otroka nikoli. *Posredovanje stikov ni zgrajeno — na strani je opisano kot »v pripravi«.*
   - **Ustanovni partner v imenikih** (veterinarji, pasje šole, frizerji, vzreditelji): izpostavljen vpis z logotipom, opisom in povezavo; **190 € na leto, cena zaklenjena za ustanovne partnerje** *(Claude)*; omejeno število na državo.
   - **Zavetišča in društva:** brezplačen vpis in možnost, da posvojiteljem priporočijo PetPrep.
3. **Česa ne delamo:** oglasov v otroški aplikaciji, prodaje podatkov, zdravstvenih obljub, promocije pasem, ki jih veterinarska združenja odsvetujejo v oglaševanju.
4. **Številke:** samo izmerjene, z datumom (npr. število pasem v registru, jeziki, ogledi serije). Kjer podatka še ni, ga ni.
5. **Obrazec za povpraševanje:** ime podjetja, kontaktna oseba, e-naslov, vrsta sodelovanja, sporočilo. Brez samodejne e-pošte: strežnik shrani povpraševanje in pošlje opozorilo v obstoječi kanal Discord za administratorje; David odgovori sam. Zaščita pred neželenimi vnosi (omejitev, skrito polje).
6. **Medijski komplet (PDF, SL in EN):** kaj je PetPrep, publika, oblike sodelovanja, primeri vsebin, kontakt `david@petprep.si`. Brez »Data Vallis«; podjetje je PetPrep (v ustanavljanju).

### 5.2 Strežnik
`POST /api/partner-inquiries` (javno, omejeno), tabela povpraševanj, seznam v administraciji (samo superadmin), opozorilo v Discord brez osebnih podatkov v besedilu (samo »novo povpraševanje, vrsta: …« in povezava v administracijo). Povpraševanja se izbrišejo po 12 mesecih *(Claude)*; politika zasebnosti dobi vrstico o obrazcu.

### 5.3 Kaj mora narediti David
Potrditi ceno ustanovnega partnerja in številke na strani; povedati, katere logotipe partnerjev (če sploh) smemo pokazati.

## 6. Vrstni red in ocena

1. **B (komplet)** — brez odvisnosti; lahko je v prodaji prvi.
2. **A (bon)** — strežnik in spletna stran vzporedno; objava, ko je aplikacija v trgovinah in so pravila trgovin preverjena.
3. **C (partnerji)** — vzporedno z A.

Vsak izdelek je svoj PR v vsakem repozitoriju. A je plačilna in varnostna sprememba, zato pred združitvijo en krog `qa-reviewer`.

## 7. Odprto za Davida (privzeto velja predlog)

| # | Vprašanje | Predlog |
|---|---|---|
| S1 | Cena kompleta | 6,90 € |
| S2 | Veljavnost bona | 12 mesecev od nakupa |
| S3 | Cena ustanovnega partnerja v imeniku | 190 € na leto |
| S4 | Jeziki ob objavi | slovenščina in angleščina |
| S5 | Vračilo bona | neunovčen 14 dni, unovčen ne |
| S6 | Pravila trgovin za unovčitev zunaj aplikacije | **Preverjeno 11. 11. 2026 (§2):** skladno, dokler aplikacija bona ne omenja in nima vnosa kode |
