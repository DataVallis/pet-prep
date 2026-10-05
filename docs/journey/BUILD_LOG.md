# PetPrep — Dnevnik razvoja (Build Log)

> **Namen:** surovina za vsebine — objave na omrežjih, zgodbo za investitorje, gradiva za partnerje, sporočila za starše (mame) in otroke. Vsak vnos pove *kaj* se je zgodilo, *zakaj* je pomembno in kako to povedati posameznim publikam.
> **Pravila:** samo resnična dejstva (kar je bilo res narejeno ali odločeno) · številke z virom · brez osebnih podatkov otrok · kar še ni zgrajeno, je označeno kot *načrt*.
> **Vzdrževanje:** orkestrator (Claude) doda vnos po vsaki pomembni seji (`/handoff`). Najnovejši vnos je na vrhu.

Legenda publik: 📣 omrežja · 💼 investitorji · 🤝 partnerji (trgovine, zavetišča, veterinarji, šole) · 👩 starši / mame · 🧒 otroci · 🛠 tehnična publika (dev skupnost, LinkedIn)

---

## 2026-10-05 — Starši lahko izbrišejo račun in izvozijo vse podatke (M2-08)

**Kaj se je zgodilo:** V aplikaciji za starše je v zavihku **»Nadzor«** nov razdelek **»Račun«**:
- **»Izvozi moje podatke«** — ena datoteka JSON z vsem, kar PetPrep hrani o družini: starši, vzdevki in letnice otrok, psi z vso zgodovino (hranjenje, voda, čiščenje, koraki, sprehodi, rutine, bolezni, premori), pogodbe z otrokovim podpisom, ocene (Care Score, semafor) in povezave do slik in videov kužka. Brez gesel, kod PIN in kod povabil. Odpre se sistemsko okno za deljenje (pošljete si po e-pošti, shranite v datoteke …).
- **»Izbriši račun«** — aplikacija najprej pove, kaj se bo zgodilo: če ste **edini starš, gre celotna družina** (otroci, psi, slike, videi, pogodbe, dnevnik); če je v družini še drug starš, gre samo vaš račun. Potrdite z geslom in besedo **IZBRIŠI**, nato vas aplikacija odjavi.
- Pri vsakem otroku **»Izbriši profil«** — pes, za katerega je skrbel sam, gre z njim; **skupni pes ostane** bratu ali sestri, otrokova pretekla skrb pa ostane v dnevniku brez imena.
- Izbris je takojšen in nepovraten (brez čakalne dobe — *Claudova izbira, čaka Davida*). Vse se izbriše v enem koraku v bazi; slike in videi se z diska pobrišejo takoj zatem. Strošek AI ostane v knjigovodstvu, a brez povezave na psa. Zapis v dnevnik strežnika ne vsebuje imen ali e-pošte — samo številko družine in koliko je bilo izbrisano.
- Administracija (Filament) ima dejanje **»Delete family«** z istimi pravili; navadno brisanje uporabnikov je odstranjeno, ker bi pustilo datoteke in obšlo pravila.
- **Med delom najden in popravljen hrošč:** brisanje psa, ki je že imel AI sliko s stroškom, je v bazi padlo (dve »nastavi na prazno« pravili v istem koraku). Brez popravka izbris računa ne bi uspel za nobenega psa s sliko.
- **Številke:** 38 novih testov na strežniku (skupaj **852 zelenih**), 26 novih v aplikaciji (skupaj **552 zelenih**). Zavore: 5 poskusov brisanja na 15 min, 3 izvozi na uro.
- *Načrt:* potrditveno e-sporočilo o izbrisu (ko bo ponudnik e-pošte), izvoz kot datoteka namesto besedila, asinhroni izvoz za zelo velike družine.

**Zakaj je pomembno**
Apple aplikacije brez izbrisa računa v aplikaciji ne sprejme v App Store, GDPR pa staršem zagotavlja dostop do podatkov in njihov izbris. Pri aplikaciji za otroke je to temelj zaupanja: starš lahko kadarkoli vzame vse s sabo ali vse pobriše.

**Kako to povedati**
- 👩 *"Vaši podatki so vaši: z enim dotikom jih izvozite, z dvema izbrišete — celotno družino ali samo enega otroka. Brez klicev na podporo."*
- 💼 *"Skladnost z App Store (in-app account deletion) in GDPR čl. 15/17/20 pred beto: izvoz in izbris sta samopostrežna, revizijska sled brez PII."*
- 🛠 *"Laravel: izbris družine v eni transakciji z vrstnim redom zaklepov (starš → otroci → družina → psi), datoteke v queued jobu po commitu, idempotentno; pazili smo na zastarele kaskadne tuje ključe in na PostgreSQL past z dvema ON DELETE SET NULL v isti vrstici."*

## 2026-10-05 — Administracija hitrejša, videi ne zavirajo več aplikacije (produkcijski strežnik PHP)

**Kaj se je zgodilo:** Produkcija je do zdaj tekla na **razvojnem** strežniku PHP (`php artisan serve`, 4 delavci, brez predpomnilnika prevedene kode). Zato je bila administracija (Filament) počasna, vsak prenos videa psa pa je za ves čas prenosa zasedel enega od štirih delavcev. Pripravljen je pravi produkcijski način (velja ob naslednjem deployu, ko se veja združi):
- **PHP-FPM + OPcache** v lastni produkcijski sliki: koda in knjižnice so v sliki, prevedena koda ostane v pomnilniku, do 12 delavcev. Predpomnilniki Laravela (nastavitve, poti, pogledi, Filament) se zgradijo ob vsakem zagonu.
- **Caddy sam streže** statične datoteke administracije (z dolgim predpomnjenjem) in **videe ter slike psov**: PHP samo preveri, ali je povezava podpisana in ali jo sme gledati ta uporabnik, datoteko pa pošlje Caddy (tudi po delih za iPhone).
- **Varnejši deploy:** nova slika se zgradi in preizkusi, **preden** gre aplikacija v vzdrževalni način — če gradnja ne uspe, uporabniki ničesar ne opazijo. Ob napaki pred migracijami se samodejno vrne prejšnja različica (koda in slika).
- Odpravljen star dolg: knjižnice (`vendor`) se zdaj res namestijo ob vsakem deployu (prej je bila na strežniku ročno nameščena kopija).
- **Številke (lokalni preizkus):** med 4 hkratnimi prenosi 10 MB videa je odziv API-ja trajal **4,4 s** na starem strežniku in **0,01–0,02 s** na novem. Strežnik: **814 zelenih testov**, test deploya **189 preverjanj**, 30 preverjanj celotne poti Caddy → PHP-FPM.
- *Načrt:* omejitev hkratnih povezav na napravo; Object Storage + CDN, ko bo uporabnikov več (SCALING.md).

**Zakaj je pomembno**
Pred zaprto beto mora strežnik zdržati, da več otrok hkrati gleda svojega psa, ne da bi se aplikacija za starše ustavila. In administracija mora biti dovolj hitra za vsakodnevno delo.

**Kako to povedati**
- 👩 *"Video vašega kužka se naloži takoj, aplikacija pa ostane odzivna, tudi ko si ga ogleduje več otrok hkrati."*
- 💼 *"Infrastruktura za beto: produkcijski PHP runtime in strežba medijev brez dodatnih stroškov (brez CDN), z jasno potjo do Object Storage + CDN ob rasti."*
- 🛠 *"Laravel na PHP-FPM + OPcache (validate_timestamps=0) v multi-stage Docker sliki, Caddy php_fastcgi + handle_response na X-Accel-Redirect za podpisane videe, gradnja in smoke test slike pred maintenance mode, samodejni rollback slike."*

## 2026-10-05 — Starši se lahko sami registrirajo

**Kaj se je zgodilo:** Do zdaj je račun za starša lahko ustvaril samo razvijalec. Zdaj ga starš ustvari sam v aplikaciji: **"Sem starš" → "Nimate računa? Registracija"** → ime, e-pošta, geslo (dvakrat, s prikazom/skritjem) in kljukica **"Strinjam se s pogoji uporabe in politiko zasebnosti"**. Takoj zatem je prijavljen in vidi prazno nadzorno ploščo z gumbom **"Dodaj otroka"**.
- Ob registraciji nastane **družina v časovnem pasu telefona** — po njem tečejo tihe ure, polnoč in okna hranjenja.
- Geslo: **vsaj 10 znakov, velike in male črke ter številka**; hranimo ga samo zgoščenega. Čas strinjanja s pogoji se shrani.
- Zaščita pred zlorabo: največ **5 registracij na minuto in 20 na uro** z enega naslova IP; e-pošta se primerja ne glede na velike/male črke.
- Registracija ustvari **samo starševski račun** — otroški račun (žeton "otrok") na ta način ni mogoč; otrok se še vedno prijavi samo s PIN-om.
- **Številke:** 19 novih testov na strežniku (skupaj **777 zelenih**), 26 novih v aplikaciji (skupaj **520 zelenih**).
- *Načrt:* potrditev e-pošte in pozabljeno geslo (M2-10b), prijava z Apple / Google (M2-10c). **Pogoji uporabe in politika zasebnosti morata biti napisana pred beto** (povezavi sta zdaj le mesti).

**Zakaj je pomembno**
Brez tega ni bete: vsaka družina mora sama priti do računa. To je prvi korak lijaka "naloži → registriraj → dodaj otroka → pes se rodi".

**Kako to povedati**
- 👩 *"Račun ustvarite v pol minute: ime, e-pošta, geslo. O otroku ne vprašamo ničesar razen vzdevka — in to šele, ko ga dodate."*
- 💼 *"Samopostrežna registracija staršev je v aplikaciji — prvi korak aktivacijskega lijaka; Apple/Google prijava sledi."*
- 🛠 *"Laravel FormRequest + servis v eni transakciji (uporabnik + družina + Sanctum žeton z eno sposobnostjo), Password::defaults brez zunanjih klicev, per-IP throttle (IPv6 /64), e-pošta lower-case, Expo zaslon z validacijo, ki zrcali strežnik."*

## 2026-10-05 — Kuža ob rojstvu dobi fotografijo in svoje videe (shranjene pri nas)

**Kaj se je zgodilo:** David je v AI laboratoriju izbral modela: **Nano Banana Pro** za fotografijo psa in **Kling 3.0 Pro** za videe. Strežnik zdaj ob rojstvu sam naredi celoten paket (v aplikaciji se predvajanje doda v naslednjem koraku):
- **Fotografija** po "DNK" psa (pasma + izžrebane lastnosti), pokončna 9:16.
- Ko otrok podpiše pogodbo (rojstvo), iz te fotografije nastanejo **kratki 5-sekundni videi brez zvoka**, v katerih je vedno **isti pes** — mirna kamera, subtilno, realistično gibanje, brez ljudi in besedila. Brezplačni mešanček dobi **2 videa** (miruje, spi), plačljiva pasma (izziv) **vseh 6** (miruje, spi, utrujen, lačen, bolan, igriv). *(Razdelitev je Claudov predlog in čaka Davidovo potrditev.)*
- **Vse se shrani na naš strežnik v EU**, preden ga otrok vidi: strežnik datoteko prenese samo z naslovov fal.ai, preveri velikost (do 25 MB slika, 60 MB video) in da je res slika / video. Aplikacija dobi samo **naše povezave, ki veljajo 60–90 minut** — nikoli povezav na zunanjo storitev.
- **Strošek na psa (ocena po ceniku fal):** ≈ **1,27 $** za mešančka (0,15 $ slika + 2 × 0,56 $ video), ≈ **3,51 $** za plačljivo pasmo. Še vedno velja dnevna (5 $) in mesečna (50 $) meja — če je dosežena, kuža živi naprej brez novega videa, sistem pa naslednji dan nadaljuje. Psi, rojeni prej, dobijo medije samo, ko jih administrator ročno sproži (z ogledom stroška vnaprej).
- Administrator vidi za vsakega psa sliko, videe, ceno in morebitno napako ter lahko posamezen video ali sliko naredi znova.
- **Številke:** 47 novih avtomatskih testov, strežnik skupaj **749 zelenih**; mobilna aplikacija 494 zelenih.

**Zakaj je pomembno**
Kuža ni več ikona — je *tvoj* pes, ki diha, spi in se igra, vedno isti. Hkrati je strošek predvidljiv: videi se naredijo enkrat ob rojstvu, potem jih aplikacija samo predvaja (brez novega plačila za vsak ogled), in vsi mediji so pod našim nadzorom.

**Kako to povedati**
- 🧒 *"Tvoj kuža ima svojo fotografijo in videe — vidiš ga, kako mirno sedi in kako spi."*
- 👩 *"Slike in videe ustvari umetna inteligenca brez kakršnihkoli podatkov o vašem otroku in jih hranimo na strežniku v EU. Povezave do njih veljajo le kratek čas in jih dobi samo vaša družina."*
- 💼 *"Izbrana najboljša realistična modela; strošek medijev ≈ 1,27 $ na brezplačnega in ≈ 3,51 $ na plačljivega psa, enkratno ob rojstvu, z dnevno/mesečno mejo — brez stroška na ogled."*
- 🛠 *"Pipeline: slika → prenos na lasten disk → videi po stanjih prek fal queue + podpisan webhook → prenos → podpisani, časovno omejeni URL-ji z Range podporo za iOS. Vsak korak idempotenten (atomski 'claim' reže), proračun preverjen pred vsakim klicem."*
- Videi se naredijo šele ob rojstvu (podpis pogodbe), zato pes, ki se nikoli ne rodi, stane samo sliko.
- Opomba: Kling 3.0 Pro nima nastavitve ločljivosti (video sledi sliki 9:16 v izvorni ločljivosti); predvajanje v aplikaciji je *načrt* (naslednja mobilna naloga).

## 2026-10-04 — Vsak kuža je unikaten + AI laboratorij z omejitvijo stroškov

**Kaj se je zgodilo:** pripravili smo temelje za AI slike in videe psov (strežnik in administracija; v aplikaciji se ne spremeni nič, dokler David ne izbere modelov).
- **Unikaten videz.** Do zdaj so bili vsi mešančki skoraj enaki (3 različice). Zdaj vsak nov pes ob rojstvu "izžreba" svojo kombinacijo lastnosti: velikost, postava, dolžina in barva dlake, vzorec, lise, ušesa, oči, rep. Pri mešančku je možnih **več kot 300.000 kombinacij**, pri border collieju 4.680 (pasma ima manj dovoljenih različic; seznam je osnutek). Isti pes je vedno isti (žreb je ponovljiv), v isti družini pa dva psa iste pasme nikoli nista enaka.
- **Opis za AI = pasma + lastnosti + slog fotografije** (fotorealistično, dnevna svetloba, cel pes, preprosto domače ozadje, brez ljudi in besedila). Brez imen otrok ali katerihkoli osebnih podatkov.
- **AI laboratorij** (samo za administratorja): izbereš pasmo, 1–4 pse in več modelov hkrati — isti pes se izriše z vsakim modelom, slike so ena ob drugi s ceno in časom. Iz izbrane slike lahko narediš video stanja (spi, se igra, je lačen …) z več video modeli.
- **Modeli in cene (fal.ai, 4. 10. 2026):** slika od **0,006 $** (FLUX.1 schnell, sedanji) do **0,15 $** (Nano Banana Pro); kratek video (4–5 s) brez zvoka od **0,12 $** (Veo 3.1 Lite, 4 s) do **0,56 $** (Kling 3 Pro). Celoten paket ob rojstvu (slika + 6 videov) bi stal približno **0,73 $ do 3,51 $** na psa, odvisno od izbire (ocena po ceniku).
- **Varovalka stroškov:** vsak klic AI se najprej zapiše z ocenjeno ceno; ko bi presegel dnevno (5 $) ali mesečno (50 $) mejo, se ne izvede. Če je račun pri fal.ai prazen, sistem to prepozna in opozori v administraciji. Igra v obeh primerih teče naprej — kuža je le brez nove slike. Laboratorij ima svoj, ločen proračun (3 $ na dan), zato preizkušanje modelov nikoli ne vzame denarja za slike novih psov; slike, ustavljene zaradi meje, sistem naslednji dan poskusi znova.
- **Številke:** 47 novih avtomatskih testov, skupaj **684 zelenih** na strežniku.

**Zakaj je pomembno**
Pravi psi iste pasme si niso enaki — tudi virtualni ne smejo biti. Otrok mora čutiti, da je *njegov* kuža. Hkrati AI stane: brez meje porabe bi ena napaka lahko čez noč porabila stotine evrov. Zdaj je strošek viden, omejen in izmerjen pred izbiro modela.

**Kako to povedati**
- 🧒 *"Tvoj kuža je edini na svetu, ki je točno tak."*
- 👩 *"Vsak virtualni pes je drugačen, kot v resnici. Slike ustvari umetna inteligenca brez kakršnihkoli podatkov o vašem otroku."*
- 💼 Strošek AI na psa izmerjen pred lansiranjem (≈0,73–3,51 $ za osnovni paket), z vgrajeno dnevno/mesečno mejo; dodatni mediji bodo plačljivi z žetoni (*načrt*, M4-09) — AI strošek se pokrije sam.
- 🛠 *"Preden smo izbrali AI model, smo zgradili laboratorij: isti pes, štirje modeli, ena ob drugi, s ceno na sliko. Odločitev na podlagi podatkov, ne hypa."*
- Opomba: opisi videza pasem so še osnutek in bodo zamenjani s podatki iz uradnih standardov pasem (FCI/AKC, M1-19) — ne predstavljati kot uradne.

## 2026-10-04 — Starš vidi objektivno oceno: nova nadzorna plošča na pravih podatkih

**Kaj se je zgodilo:** ocena, ki jo je strežnik začel računati isti dan, je zdaj na zaslonu staršev — demo podatkov (izmišljena časovnica in tedenski graf) ni več. Nadzorna plošča je nova, **svetla** (kot bančna ali fitnes aplikacija) in ima za **vsakega otroka svojo kartico**: semafor z razlogom z besedami (*"Danes so zamujene že 3 rutine."*, *"Kuža je danes zbolel in je pri veterinarju."*), **Care Score** z velikim številom in *"31 od 36 rutin"* (bolezen pokaže odbitek −10), *"Teden 2 od 12"*, današnje rutine (opravljeno / še odprto / zamujeno) in **katera rutina je bila zamujena in kdaj** — *"Hrana · okno 07:00–09:00"*, *"Čiščenje · rok 14:30"* — po času družine, zadnjih 7 dni kot stolpci in stanje psa (4 vrednosti, "ustavljeno", "pri veterinarju", "čaka na podpis pogodbe"). Dokler ni še nobene rutine, piše pošteno *"Še ni dovolj podatkov"*. Tap na **"Podrobnosti"** odpre poročilo za **7 dni, 30 dni ali celih 12 tednov**: ocena obdobja, hrana / voda / čiščenje / sprehod posebej, vsak dan s koraki proti cilju (*"4.210 / 4.000 korakov"*), zamujene rutine, bolezni in časovnica z vzdevkom otroka, ki je kaj naredil (*"Luka nahranil(a) kužka · 07:15"*). V zavihku **Nadzor**: hard stop **za vsakega psa posebej** s potrditvijo, tihe ure, otroci in naprave ter **"Povabi drugega starša"** — 8-mestna koda (*"K7QM 2XPA, velja do 5. 10. ob 14:30"*) se pošlje prek telefona (SMS, WhatsApp …), drugi starš jo vnese pri sebi. Vse se posodablja **v živo** (vsak pes ima svoj zasebni kanal); če povezava pade, se pregled osveži vsakih 30 sekund. Avtomatski testi aplikacije: s 425 na **473 zelenih** (+48; 16 starih testov, ki so preverjali lastno kopijo starega semaforja, je zamenjal en pravi test), med njimi test, ki pade, če bi se v kodo vrnil kakršen koli demo podatek. **Zakaj je pomembno:** do zdaj je starš videl lepo, a izmišljeno sliko. Zdaj vidi številko, ki jo je zaslužil otrok — in točno, kaj je zamudil. To je obljuba PetPrep v izdelku: *objektiven dokaz*, ne občutek. **Kako povedati:** 👩 *"Odprete aplikacijo in v treh sekundah veste: zelena — vse v redu. Rumena — piše, kaj je bilo zamujeno in kdaj. Ne rabite spraševati 'si nahranil kužka?'."* · 👩 *(dva otroka)* *"Vsak otrok ima svojo kartico in svojo oceno. Takoj vidite, kdo skrbi in kdo se skriva."* · 👩👨 *"Povabite partnerja s kodo — oba vidita isto in oba lahko ustavita igro."* · 🧒 *"Mami in ati vidita, kaj si naredil za kužka — tudi to, kar si naredil dobro."* · 💼 *"Care Score je zdaj viden v izdelku: dnevna, tedenska in 12-tedenska ocena na otroka — merljiv rezultat programa."* · 🛠 *"TanStack Query kot edini vir stanja, kanal Reverb na psa s popravkom predpomnilnika + ponovno nalaganje izračunanih delov, 30 s polling brez povezave, neskončna paginacija časovnice, strogi TS brez `any` čez ohlapno generirano shemo."* (Preizkus na pravih napravah še čaka.)

## 2026-10-04 — Objektivna ocena: Care Score in semafor iz tega, kar je otrok res naredil

**Kaj se je zgodilo:** David je določil pravila ocenjevanja, strežnik jih zdaj računa (zasloni v aplikaciji *kmalu*). Vsak dan po času družine ima kuža **rutine**: vsako okno hranjenja (mešanček 2), 3 dolivanja vode, vsak "kakec" (počistiti v **2 urah, šteto samo izven tihih ur** — nered ob 7:30 pred šolo ima rok do 14:30) in dnevni sprehod. Rutina je opravljena ali zamujena — nič vmes. Kar se zgodi med hard stopom, pri veterinarju ali pred rojstvom, se otroku ne šteje; okno hranjenja, ki je v celoti v tihih urah, tudi ne. **Care Score** = opravljene / pričakovane rutine × 100, minus 10 za vsako bolezen (0–100). **Semafor** za vsakega otroka in psa: rdeča (pes odvzet, alarm "0 % več kot uro" ali danes zbolel), rumena (danes zamujene več kot 2 rutini), sicer zelena. Pri **skupnem psu** "pošten delež": vsak od dveh otrok nosi polovico rutin, šteje pa samo to, kar je naredil sam — če eden naredi vse, ima 100, drugi 0. Starš vidi za vsakega otroka še današnje zamujene rutine z uro, zadnjih 7 dni, "teden N od 12" in podrobno poročilo za 7, 30 ali 84 dni. Zaključeni dnevi se zapišejo in se ne spreminjajo več (sprememba tihih ur ne popravi preteklih ocen). **38 novih avtomatskih testov** (tudi dan prestopa ure 25. 10. s 25 urami), strežnik skupaj **621 zelenih**; nadzorna plošča za družino z 2 psoma porabi enako število poizvedb (41) za 7 ali 84 dni zgodovine. **Zakaj je pomembno:** to je jedro obljube PetPrep — *objektiven dokaz* pripravljenosti. Ocena ne temelji na občutku staršev ali na tem, koliko krat je otrok pritisnil gumb, ampak na tem, ali je bila vsaka obveznost opravljena pravočasno. **Kako povedati:** 👩 *"Ne rabite ugibati. Vsak dan vidite, ali je otrok nahranil kužka v oknu, dolil vodo, počistil v dveh urah in šel na sprehod. Šola in spanje se ne štejeta proti njemu. Na koncu dobite eno številko od 0 do 100."* · 👩 *(dva otroka)* *"Vsak otrok ima svojo oceno. Priden ne nosi lenega — in len se ne more skriti za pridnim."* · 🧒 *"Vsaka skrb, ki jo opraviš pravočasno, ti prinese točke. Če kuža zboli, izgubiš 10."* · 💼 *"Care Score je merljiv, preverljiv rezultat 12-tedenskega programa — osnova za certifikat, garancijo in partnerje (zavetišča, vzreditelji)."* · 🤝 *(zavetišča)* *"Otrok s certifikatom ima zapis 12 tednov dejanske skrbi, ne samo obljubo."* · 🛠 *"Routine ledger izpeljan iz obstoječih dogodkov, materializiran ob zaključku dneva po času družine (idempotentno, DST-safe), danes sproti z isto kodo; pošten delež 1/n po skrbnikih."*

## 2026-10-04 — Gumbi zares delujejo: hrana, voda, čiščenje in koraki gredo na strežnik

**Kaj se je zgodilo:** do danes je gumb "Hrani" v otroški aplikaciji samo na zaslonu prištel 20 % — nič se ni shranilo in pravila (okna hranjenja, voda 3× na dan) so veljala le na strežniku. Zdaj vse štiri skrbi tečejo prek strežnika: **hrana, voda in čiščenje** se pokažejo takoj, nato aplikacija vedno prevzame odgovor strežnika. Če ni čas za hrano, je gumb zasenčen in pod njim piše **"ob 17:00"** (po času družine, tudi ob prestopu ure); če otrok vseeno poskusi, dobi prijazno sporočilo, npr. *"Kuža bo lačen spet ob 17:00."*, *"Posoda je še polna. Novo vodo lahko daš ob 15:30."* ali *"Najprej pospravi za kužkom!"*. **Koraki** se pošiljajo sami — ob odprtju aplikacije, ob vrnitvi vanjo in vsakih 5 minut (iPhone pošlje vse današnje korake, Android jih šteje, ko je aplikacija odprta). **Zaklenjen zaslon** zdaj pride s strežnika: ko starš pritisne hard stop, se otrokov zaslon v živo zaklene ("Starš je ustavil igro") in se odklene, ko ga izklopi; ko je kuža bolan, piše *"Kuža je pri veterinarju do 18:30"*. Če povezava v živo pade, aplikacija stanje osveži vsakih 10 sekund. Avtomatski testi aplikacije: z 276 na **389 zelenih** (+113). **Zakaj je pomembno:** to je prvič, da otrokova skrb v aplikaciji res šteje — strežnik je edini vir resnice, zato ocena, opozorila in certifikat lahko temeljijo na tem, kar je otrok res naredil. **Kako povedati:** 🧒 *"Ko je čas za hrano, gumb zasveti. Če ni, ti kuža pove, kdaj bo spet lačen."* · 👩 *"Pravila niso na otrokovem telefonu, ampak na našem strežniku — zvijačenje z uro ne pomaga. Ko pritisnete hard stop, se otrokov zaslon zaklene v sekundi."* · 💼 *"Celotna igralna zanka (akcije → strežnik → dogodki v živo) zdaj teče od konca do konca."* · 🛠 *"TanStack Query kot edini vir stanja, optimistične mutacije z zamenjavo s stanjem iz odgovora (tudi pri 422/423), urejanje dogodkov po `emitted_at`, polling le brez naročnine na kanal."* (Preizkus na pravih napravah še čaka — iOS potrebuje novo gradnjo zaradi dovoljenja za gibanje.)

## 2026-10-04 — Aplikacija: "Sem otrok" in velika tipkovnica za kodo

**Kaj se je zgodilo:** isti dan kot strežnik je prijava s kodo prišla tudi v aplikacijo. Ob prvem odprtju sta zdaj dve jasni poti: **"Sem otrok"** (koda od staršev na veliki tipkovnici — šesta številka kodo pošlje, brez e-pošte in gesla) in **"Sem starš"** (e-pošta). Starš na nadzorni plošči tapne "Dodaj otroka", vpiše samo vzdevek (letnica rojstva je neobvezna; aplikacija izrecno pove "Ne potrebujemo e-pošte ali priimka"), izbere "Nov pes" ali "Pridruži se psu …" in dobi kodo prav za tega otroka. Na novem seznamu otrok vidi, za katerega psa skrbi vsak otrok in na koliko napravah je prijavljen, ter ima dva gumba: "Nova koda za prijavo" (nov telefon, isti kuža) in "Odjavi vse naprave" (izgubljen telefon, s potrditvijo). Napačna koda otroku pove samo "Ta koda ne deluje. Prosi starša za novo kodo.", po preveč poskusih pa tipkovnica pokaže odštevanje. Za ime naprave aplikacija pošlje samo model telefona, nikoli imena, ki ga je telefonu dal uporabnik (pogosto vsebuje otrokovo ime). Avtomatski testi aplikacije: z 201 na **268 zelenih**. **Zakaj je pomembno:** otroška prijava z e-pošto je iz aplikacije odstranjena — o otroku aplikacija ne vpraša ničesar razen vzdevka. **Kako povedati:** 👩 *"Vpišete vzdevek, otrok vtipka kodo — dve minuti in kuža čaka na podpis."* · 🧒 *"Tapni 'Sem otrok' in vtipkaj kodo od staršev na velike gumbe."* · 💼 *"Celoten tok brez otrokove e-pošte je zdaj v izdelku, ne le na strežniku."* · 🛠 *"PIN prijava kot TanStack mutacija, anonimna zahteva brez Bearer žetona, `awaiting_contract` po otroku iz odgovora prijave, 67 novih Jest testov."* (Preizkus na pravih napravah še čaka.)

## 2026-10-04 — Otrok brez e-pošte: prijava samo s kodo staršev

**Kaj se je zgodilo** (izvedba Davidove odločitve z 2. 10.; strežnik — zasloni v aplikaciji *kmalu*)
- **Otroški profil brez e-pošte in gesla.** Starš vpiše samo **vzdevek** (do 30 znakov, priimek ni potreben) in po želji **letnico rojstva**. To je vse, kar o otroku hranimo.
- **Koda za točno tega otroka.** Starš izbere otroka (in po želji psa, h kateremu se pridruži) in dobi 6-mestno kodo, ki velja 15 minut in samo enkrat. Otrok jo vtipka na svoji napravi — brez računa.
- **Nov telefon? Nova koda.** Ista koda za že povezanega otroka ga samo prijavi na novi napravi; kuža ostane isti. Največ 3 naprave na otroka; starš lahko otroka z enim klikom odjavi z vseh naprav (izgubljen telefon).
- **Varnost kode:** na strežniku je shranjena samo zgoščena vrednost kode (ne koda sama); napačna, potekla in že uporabljena koda dobijo **enak odgovor**, zato ni mogoče ugibati, kateri otroci ali kode obstajajo; 10 napačnih poskusov z istega naslova ali 100 skupaj v 15 minutah → premor.
- **Ločene vloge na ravni žetona:** prijava starša dobi "starševski" ključ, otroka "otroški" — otroški telefon ne more odpreti starševskih nastavitev, tudi če bi kdo poskusil. Obstoječi uporabniki ostanejo prijavljeni.
- **Številke:** 53 novih avtomatskih testov (ustvarjanje profila, koda za nov/skupni pes/novo napravo, vsak način neuspeha, zaklepanje, 3 naprave, odjava, ločitev družin, pogodba in kanal v živo z otroškim žetonom), skupaj **559 zelenih** na strežniku; aplikacija 201 zelenih.

**Zakaj je pomembno**
Otroci 7–12 let nimajo e-pošte in je ne bi smeli dajati aplikacijam. Evropska zakonodaja (GDPR čl. 8) in Applova kategorija "Kids" zahtevata, da o otroku zberemo čim manj. PetPrep zdaj o otroku ve samo vzdevek — in morda letnico rojstva.

**Kako to povedati**
- 👩 *"Vaš otrok ne potrebuje e-pošte ne gesla. Vpišete vzdevek, dobite kodo, otrok jo vtipka — to je vse. Izgubljen telefon? En dotik in otrok je odjavljen povsod."*
- 🧒 *"Ne rabiš e-pošte! Starši ti povejo kodo s 6 številkami in tvoj kuža te čaka."*
- 💼 *"Zasebnost po zasnovi: o mladoletnem uporabniku hranimo vzdevek in neobvezno letnico — nič drugega. To je pogoj za App Store Kids in zaupanje staršev."*
- 🤝 *(šole)* *"Otroci ne potrebujejo nobenega računa; starš upravlja vse."*
- 🛠 *"PIN-only prijava: HMAC kode namesto kode same, enoten odgovor za napačno/potečeno/porabljeno kodo, omejitev po IP in globalno, Sanctum abilities `parent`/`child` na skupinah poti, stari `*` žetoni delujejo naprej."*

---

## 2026-10-04 — Mama, oče, brat in sestra: PetPrep postane družinski

**Kaj se je zgodilo** (Davidova odločitev in prva faza na strežniku, isti dan)
- **Družina namesto para starš–otrok.** V eni družini je lahko več staršev (mama in oče) in več otrok. Vsi starši vidijo vse otroke in vse pse.
- **Svoj pes ali skupni pes.** Vsak otrok ima lahko svojega psa, ali pa brat in sestra skupaj skrbita za enega. Starš pri ustvarjanju PIN-a izbere "nov pes" ali "pridruži se psu …".
- **Pošteno do vsakega otroka.** Vsako dejanje (hrana, voda, čiščenje, koraki) se zapiše pod otroka, ki ga je naredil. Starš že dobi statistiko po otroku za zadnjih 7 dni; ocena, semafor in certifikat po otroku sledijo (*načrt* — formula čaka Davida).
- **Drugi starš se pridruži s kodo** (8 znakov, velja 24 ur, samo enkrat; po 5 napačnih poskusih 15 minut premora).
- **Otrok, ki se pridruži obstoječemu psu, podpiše svojo pogodbo** — pes se ne rodi znova; dokler ne podpiše, lahko skrbi samo drugi otrok.
- **Skupni sprehod:** koraki vseh otrok, ki skrbijo za psa, se seštejejo; vsak vidi svoj prispevek.
- **Cena na psa:** 49,99 € za 12-tedenski izziv na psa, skupni pes = ena cena, mešanček ostane brezplačen (plačila še niso zgrajena; pes je že "enota plačila" v bazi).
- **Obstoječi podatki so preneseni samodejno:** vsak starš dobi družino, njegovi otroci in psi gredo vanjo, pretekla dejanja se pripišejo otroku. Trenutna aplikacija deluje naprej brez posodobitve.
- **Številke:** 34 novih avtomatskih testov (prenos podatkov, dva starša z enako nadzorno ploščo, povabila — potek, ponovna uporaba, preveč poskusov —, skupni pes, ločena pogodba, seštevanje korakov, kdo sme poslušati kanal v živo), skupaj **498 zelenih** na strežniku; aplikacija 201 zelenih.
- Zasloni za družino v aplikaciji: *kmalu* (M2-01a).
- **David je potrdil vse štiri izbire** (en aktiven pes na otroka, seštevanje korakov, lastna pogodba vsakega otroka, pridružitev drugega starša samo s praznim računom). Po neodvisnem pregledu dodana varovala: brisanje družine nikoli ne izbriše psa ali otroka (baza to prepove), sočasno povezovanje in pridružitev se zaklepata v istem vrstnem redu, deploy med migracijo vklopi način vzdrževanja. Skupaj **506 zelenih testov** na strežniku.

**Zakaj je pomembno**
Doma o psu ne odloča en starš z enim otrokom. Ko se dva otroka pulita za kužka, PetPrep zdaj pokaže, kdo je res skrbel — priden ne nosi lenega. In oba starša vidita isto, brez posredovanja.

**Kako to povedati**
- 👩 *"Povabite partnerja s kodo in oba v živo vidita, kako gre otrokom. Pri skupnem psu točno veste, kdo ga je nahranil in kdo je šel na sprehod."*
- 🧒 *"Lahko imaš svojega kužka ali ga deliš z bratom ali sestro — vse, kar narediš ti, se šteje tebi. Na sprehod lahko greste skupaj!"*
- 💼 *"Cena je na psa, ne na otroka: družina z dvema otrokoma in dvema psoma je dva izziva; skupni pes ena cena. Model podatkov je že pripravljen za plačilo na psa."*
- 🛠 *"Družine, skrbniki psov in avtor vsakega dejanja; 'en aktiven pes na otroka' zagotavlja baza (delni unikatni indeks na kopiji `is_active`, ki jo vzdržujeta sprožilca). Stari stolpci ostanejo sinhronizirani, da stare verzije aplikacije delujejo."*

---

## 2026-10-04 — Najprej podpis, potem rojstvo

**Kaj se je zgodilo** (Davidova odločitev, isti dan)
- **Pes se rodi šele, ko otrok podpiše pogodbo.** Po vnosu PIN-a kuža že obstaja (izgled, slika), a čaka. Dokler pogodba ni podpisana, nič ne upada, ni kakcev, opomnikov ne kazni; vse akcije razen podpisa strežnik zavrne z razlogom "najprej podpiši pogodbo".
- **Trenutek podpisa = rojstni trenutek:** vse metrike 100 %, starost, ure in "rojstni dan" (energija polna do prve polnoči) začnejo teči takrat. Če otrok podpis odloži za dva dni, ne izgubi nič.
- Starš na nadzorni plošči vidi, da pes "čaka na pogodbo" (podatek je v API-ju, prikaz *kmalu*).
- Psi, ki so obstajali že prej, ostanejo rojeni in delujejo kot doslej.
- David je potrdil še tri pravila: najprej čiščenje, potem hrana in voda; voda 3× na dan z razmikom tudi čez polnoč; ena pogodba na psa.
- **Številke:** 25 novih avtomatskih testov (simulacija 26 ur "nerojenega" psa, rojstvo, upadanje po rojstvu), skupaj 432 zelenih.
- **Aplikacija:** otrok se zdaj res podpiše s prstom (črta na zaslonu), podpis gre na strežnik in pes se rodi šele, ko ga strežnik sprejme; če aplikacijo zapre pred podpisom, ga ob naslednjem zagonu počaka pogodba (31 novih testov v aplikaciji, skupaj 190).

**Zakaj je pomembno**
Pogodba ni več formalnost pred igro, ampak vrata v igro: brez obljube ni psa. Tako začne vsak otrok enako — s polnim, zdravim kužkom v trenutku, ko se zaveže.

**Kako to povedati**
- 🧒 *"Ko se podpišeš, se tvoj kuža rodi — od takrat naprej skrbiš zanj."*
- 👩 *"Simulacija začne šteti šele, ko otrok podpiše obljubo. Prej se nič ne more pokvariti."*
- 🛠 *"Nerojen pes = `born_at` null: tick in eskalacija ga filtrirata v SQL, podpis pod zaklepom vrstice ga 'rodi' v isti transakciji; obstoječi psi ostanejo rojeni brez migracije podatkov."*

---

## 2026-10-04 — Kužo v živo vidita samo otrok in njegov starš

**Kaj se je zgodilo**
- **Zasebni kanal v živo:** posodobitve psa (lakota, žeja, energija, higiena, opozorila) gredo zdaj po **zasebnem kanalu**. Strežnik ob vsaki prijavi na kanal preveri prijavni žeton aplikacije in pusti zraven **samo otroka, ki ima tega psa, in njegovega starša**. Drug otrok, drug starš ali neprijavljen obiskovalec dobi zavrnitev (preverjeno z avtomatskimi testi).
- **Prej** je bil kanal javen: kdor bi poznal javni ključ iz aplikacije in številko psa, bi lahko poslušal njegovo stanje (najresnejša najdba revizije 2. 10., P0). Luknja je zaprta.
- **Brez osebnih podatkov v sporočilu:** vsebina dogodka nosi samo stanje psa — nobenega imena, e-pošte ali številke uporabnika otroka.
- **En dogodek na spremembo:** ko se psu kaj zgodi, gre na telefon točno eno sporočilo (prej ob opozorilu tudi do tri). Manj baterije in prometa, jasnejša časovnica.
- **Igralna zanka ne more obstati zaradi sporočil:** pošiljanje teče v ozadju po posebni vrsti. Če strežnik za sporočila (Reverb) za hip odpove, se kuža še vedno pravilno postara vsako minuto; telefon dobi stanje ob naslednjem dogodku ali pri osvežitvi.
- **Številke:** 30 novih avtomatskih testov (prijava na kanal × 10 primerov, en dogodek na vsak korak eskalacije, oddaja šele po zapisu v bazo, zanka preživi izpad), skupaj 437 zelenih; v aplikaciji 11 novih testov (170 skupaj).

**Zakaj je pomembno**
Pri aplikaciji za otroke je zasebnost osnova, ne dodatek. Stanje psa razkrije ritem otrokovega dne (kdaj je doma, kdaj nahrani psa) — to sme videti samo družina.

**Kako to povedati**
- 👩 *"Kar se dogaja s kužo, vidite samo vi in vaš otrok. Nihče drug se ne more 'priklopiti' na vaš kanal — strežnik vsakič preveri, kdo sprašuje."*
- 💼 Popravljena kritična varnostna najdba revizije pred prvimi družinami; v sporočilih ni osebnih podatkov otroka (dobra osnova za GDPR / zaščito otrok).
- 🤝 Šole in veterinarji: podatki o otroku ne zapustijo družine.
- 🛠 *"Laravel Reverb: `PrivateChannel('pet.{id}')`, `/api/broadcasting/auth` z Sanctum bearer žetonom, avtorizacija v policyju. `ShouldBroadcast` na vrsti `broadcasts` z ločenim workerjem, ena točka oddaje `afterCommit`, payload je posnetek brez PII. Opazovalci modelov odstranjeni — en dogodek na spremembo stanja."*

---

## 2026-10-04 — Gumbi dobijo pravila: hrana ob pravem času, voda s premislekom

**Kaj se je zgodilo**
- **Otroški API je zgrajen** (strežnik): stanje psa, hranjenje, voda, čiščenje, pošiljanje korakov in podpis pogodbe. Aplikacija se nanj poveže v naslednjem koraku (M1-14) — *kmalu*.
- **Hrana samo ob pravem času:** zjutraj med 6. in 10. uro in zvečer med 17. in 21. uro (po uri družine), **enkrat v vsakem oknu**. Če otrok tapne ob 12:00, strežnik pove "naslednje okno ob 17:00". Pravilo drži tudi ob prestopu ure (25. 10. 2026 in 28. 3. 2027 — preverjeno s testi).
- **Voda 3× na dan, vsaj 3 ure narazen** — tudi čez polnoč (voda ob 23:30 → naslednja ob 2:30).
- **Najprej počisti:** dokler je kuža umazan, hrane in vode ni — kot na zaslonu, tako tudi na strežniku (čaka Davidovo potrditev).
- **Zaklenjeno, ko mora biti:** med starševskim premorom, boleznijo ali po odvzemu psa strežnik vsako akcijo zavrne in pove razlog (aplikacija pokaže pravi zaslon).
- **Pogodba o odgovornosti** se zdaj shrani (podpis s prstom, enkrat na psa, čas podpisa) — brez pošiljanja tretjim, v časovnici staršev se pokaže kot "pogodba podpisana".
- **Varnost podatkov igre:** vsaka akcija in vsako opozorilo delata z zaklenjeno vrstico psa — otrok, ki je ravnokar počistil, ne more dobiti bolezni zaradi "zastarelega branja" (popravek iz pregleda kode).
- **Številke:** 99 novih avtomatskih testov, skupaj 384 zelenih (5.417 preverjanj); 6 novih poti v API-ju.

**Zakaj je pomembno**
To je trenutek, ko igra dobi svoja prava pravila: pravi pes ne je, kadar se otroku zljubi, in ne pije vse vode naenkrat. Rutina (zjutraj, zvečer, čez dan) je bistvo tega, kar starš želi preveriti.

**Kako to povedati**
- 🧒 *"Kuža je lačen zjutraj in zvečer — takrat ga nahrani. Če je umazan, ga najprej počisti!"*
- 👩 *"Aplikacija ne dovoli 'hranjenja na zalogo'. Vidite, ali otrok res poskrbi zjutraj pred šolo in zvečer."*
- 💼 Pravila so v nastavitvah pasme (okna hranjenja, število voda, razmik), ne v kodi — vsako pasmo lahko uglasimo brez nove verzije aplikacije.
- 🛠 *"Vsaka otroška akcija: FormRequest → policy → servis z `SELECT … FOR UPDATE` → en zapis v dnevnik → en WebSocket dogodek po commitu. Okna v časovnem pasu družine, testirano čez oba prestopa ure."*

---

## 2026-10-03 — Od veterinarja čist, sprehod vsak dan

**Kaj se je zgodilo** (odločitvi Davida, isti dan)
- **Ozdravitev = nov začetek:** ko 12 ur "pri veterinarju" mine, se kuža vrne **čist (higiena 100 %)** in vse ure zanemarjanja začnejo teči znova. Lakota in žeja ostaneta — otrok mora takoj poskrbeti zanj. S tem je odpravljena "neskončna zanka bolezni", odkrita zjutraj: prej je pes po vrnitvi takoj spet zbolel.
- **Energija = dnevni sprehod:** 0 % po polnoči pomeni "danes še ni sprehoda", ne zanemarjanje. Ponoči zato ni več alarma staršu ob 1:00 in ne "bolnega" psa do prvega sprehoda. Ob polnoči (po uri družine) zapišemo včerajšnji sprehod: koraki, cilj, dosežen ali ne — podlaga za starševski pregled. Dan **brez enega samega koraka** → kuža zboli naslednje jutro ob koncu tihih ur (npr. ob 6:00). Nekaj korakov pod ciljem → samo zapis "cilj ni dosežen".
- **Varovalke:** rojstni dan psa, dan med hard stopom ali boleznijo in zamuda strežnika čez več polnoči nikoli ne povzročijo bolezni.
- **Pošteno štetje na grafu:** sprehod se v tedenskem grafu šteje enkrat na dan (ko je cilj dosežen), ne ob vsakem pošiljanju korakov.
- **Številke:** 22 novih testov (simulacije po 5 minut čez 1–2 dni, družina v Ljubljani, spanje 22–6), skupaj 278 zelenih (4.901 preverjanj).

**Zakaj je pomembno**
Igra mora biti stroga, a nikoli brezizhodna: kazen (bolezen) se konča in otrok dobi novo priložnost. Sprehod pa je kot pri pravem psu — vsak dan, ne vsako uro.

**Kako to povedati**
- 🧒 *"Ko se kuža vrne od veterinarja, je čist kot nov. Hitro mu daj hrano in vodo — in ne pozabi: sprehod vsak dan!"*
- 👩 *"Ponoči vas aplikacija ne bo budila zaradi sprehoda. Ob polnoči vidite, ali je otrok danes šel ven — in koliko korakov je naredil."*
- 💼 Pravila so zasnovana kot vzgojni cikel: opozorilo → posledica → nov začetek. Brez "game over" spirale, ki bi otroka odvrnila.
- 🛠 *"Dnevno pravilo teče v istem minutnem ticku z zaklepom vrstice, idempotentno (en zapis na psa in dan), začetek bolezni pa izračunamo iz tihih ur družine, tudi ob prestopu ure."*

---

## 2026-10-03 — Kuža zdaj hodi s tabo in včasih naredi nered

**Kaj se je zgodilo**
- **Gibanje:** energija psa je zdaj natanko toliko, kolikor je otrok danes prehodil: mešanček potrebuje 4.000 korakov (1.000 korakov = 25 %), Border Collie 10.000. Ob polnoči (po uri družine) se dan začne znova pri 0 %. Strežniški del je pripravljen; povezava z aplikacijo (sprejem korakov s telefona) pride v naslednjem koraku (M1-07).
- **Pošteno štetje:** največ 200 korakov na minuto. Če telefon po 5 minutah javi 2.000 novih korakov, jih upoštevamo 1.000, ostalo šele, ko je realno mogoče. Ponovljeno pošiljanje istih korakov ne šteje dvakrat.
- **Higiena:** kuža zdaj naključno naredi nered — mešanček 1× na dan, Border Collie 2× na dan, **nikoli med šolo ali spanjem**. Takrat higiena pade na 0 %, čiščenje jo vrne na 100 %. Prej je higiena (začasno) počasi padala 1,5 % na uro.
- **Pravila v nastavitvah, ne v kodi:** vse številke igre (hitrost lakote in žeje, cilj korakov, kolikokrat na dan naredi nered, okna hranjenja 6–10 in 17–21, voda 3× na dan z razmikom 3 ure) so v tabeli pasem in jih lahko spremenimo v administraciji brez nove verzije aplikacije.
- **Pravičnejša bolezen:** 6 ur do bolezni se zdaj šteje samo izven tihih ur. Primer: spanje 22–6, šola 8–13, otrok ne gre na sprehod → pes zboli ob 17:00 (ne že ob 6:00 zjutraj). 400 korakov ob 15:30 je dovolj, da ostane zdrav.
- **Številke:** 64 novih avtomatskih testov, skupaj 259 zelenih (4.806 preverjanj). 24-urna simulacija z vklopljenimi naključnimi dogodki še vedno da natančne čase iz specifikacije (mešanček lačen po 12 h 30 min, žejen po 10 h; BC po 8 h 20 min in 6 h 40 min).
- **Odkrito med delom (čaka na Davida):** ker energija vsako noč pade na 0 %, bi brez dodatnega pravila starš vsako noč ob 1:00 dobil alarm, pes pa bi po 12 urah "pri veterinarju" takoj spet zbolel. Predlogi so v dnevniku odločitev.

**Zakaj je pomembno**
Sprehod je tisto, kar starši pri pravem psu najbolj podcenjujejo — in edino, česar otrok ne more "odklikati". Nered ob naključnem času pa uči, da pes ne čaka na urnik.

**Kako to povedati**
- 👩 *"Energija kužka so dejanski koraki vašega otroka. Več kot 200 korakov na minuto ne štejemo, zato bližnjic ni."*
- 🧒 *"Tvoj kuža enkrat na dan naredi nered — nikoli, ko si v šoli ali spiš. Pobriši ga!"*
- 🛠 LinkedIn: *"Naključno, a ponovljivo: časi nereda so izžrebani z RNG, zasejanim s ključem aplikacije, psom in datumom — testi so deterministični, otrok pa časov ne more uganiti."*
- 💼 Vsa pravila igre so nastavljiva brez nove verzije aplikacije — hitro prilagajanje po prvih testnih družinah.
## 2026-10-03 — Aplikacija si zapomni prijavo, starš dobi zaslon "Dodaj otroka"

**Kaj se je zgodilo**
- **Ostaneš prijavljen:** ko aplikacijo zapreš in znova odpreš, te pričaka kratek zaslon "Nalagam …" in nato takoj pravi zaslon — otrok svojega kužka, starš nadzorno ploščo. Prej se je bilo treba ob vsakem zagonu znova prijaviti.
- **Brez signala ni odjave:** če telefon ob zagonu nima interneta, aplikacija pokaže "Ni povezave" in gumb "Poskusi znova" — seja ostane. Odjavi te samo, če strežnik prijavo res zavrne.
- **"Dodaj otroka":** starš, ki še nima povezanega otroka, na nadzorni plošči vidi izrazito kartico. Tap → velika koda, npr. **734 912**, z odštevanjem 15 minut, gumb "Nova koda" in kratka navodila. Ko otrok kodo vtipka na svojem telefonu, starševski zaslon v nekaj sekundah sam pokaže **"Otrok je povezan!"**.
- Če starš prehitro zahteva nove kode (več kot 5 na minuto), aplikacija pove, koliko sekund naj počaka, in prejšnja koda ostane veljavna.
- Odjava zdaj povsod deluje enako (starš, otrok): prekliče prijavo na strežniku, izbriše shranjeni ključ in počisti podatke v telefonu.
- **Številke:** 64 novih avtomatskih testov, skupaj 138 zelenih (prej 74).
- Še odprto (*načrt*): otrok se pred vnosom kode še vedno prijavi z e-pošto; prijava samo s PIN-om pride z M2-02.

**Zakaj je pomembno**
Družina, ki se mora vsak dan znova prijavljati, aplikacijo opusti v prvem tednu — 12-tedenski izziv pa stoji na vsakodnevni rutini. Zaslon "Dodaj otroka" je prvi korak, ki ga starš sploh naredi: brez njega se izziv ne more začeti.

**Kako to povedati**
- 👩 *"Tapnete 'Dodaj otroka', otroku poveste 6 številk in v minuti je kuža na njegovem telefonu."*
- 🧒 *"Ko naslednjič odpreš aplikacijo, te kuža že čaka."*
- 🛠 LinkedIn: *"Obnova seje v Expo: SecureStore → /api/user → ista pot kot ob prijavi. 401 pomeni odjavo, izpad omrežja pa NE — otroka ne odjaviš zato, ker je v tunelu."*
- 💼 Onboarding starš → otrok je zdaj en zaslon in ena koda — ključna točka v lijaku od prenosa do začetka izziva.

## 2026-10-03 — Kuža zdaj živi po slovenski uri

**Kaj se je zgodilo**
- Vsaka družina ima svoj časovni pas (privzeto Europe/Ljubljana). Tihe ure (šola, spanje), polnoč, ko se koraki postavijo na nič, in dnevi v starševskem tedenskem pregledu se zdaj računajo po lokalni uri družine. Prej je vse teklo po UTC, zato so bile tihe ure v Sloveniji zamaknjene za 1–2 uri.
- Pravilno deluje tudi ob premiku ure: v noči na 25. 10. 2026 trajajo tihe ure 22:00–06:00 9 realnih ur, v noči na 28. 3. 2027 pa 7 ur — tako kot jih doživi družina.
- Starš lahko časovni pas spremeni v nastavitvah (nov API), npr. ko je družina na počitnicah v tujini.
- **Številke:** 29 novih avtomatskih testov, skupaj 183 zelenih.

**Zakaj je pomembno**
Če kuža "zaspi" ob 20:00 namesto ob 22:00 ali se koraki ponastavijo ob 2. uri zjutraj, otrok in starš izgubita zaupanje v igro. Pravila morajo slediti uri na steni v kuhinji, ne strežniku.

**Kako to povedati**
- 👩 *"Ko nastavite spanje od 22:00 do 6:00, kuža spi točno takrat — tudi ko se ura premakne."*
- 🛠 LinkedIn: *"Shranjuj v UTC, računaj v lokalnem času. Ob jesenskem premiku ure 02:30 obstaja dvakrat, spomladi pa sploh ne — oboje imamo pokrito s testi."*
- 💼 Pripravljeno za širitev zunaj Slovenije: časovni pas je nastavitev družine, ne strežnika.

## 2026-10-03 — Pes je bil skoraj 2× prehitro lačen: nov "motor" igre

**Kaj se je zgodilo**
- Srce igre (izračun, kako hitro pes postaja lačen, žejen in umazan) je na novo napisano.
- Stara koda je majhne padce (npr. 0,53 %) zaokrožila navzgor na cel 1 % in čas merila od zadnje kakršnekoli spremembe psa. Posledica: mešanček je bil po 6 urah lačen do 10 % namesto 52 %, higiena pa sploh ni padala.
- Med "hard stopom" starša in med boleznijo psa so metrike zdaj zamrznjene (tudi ure zanemarjanja); ko se igra nadaljuje, pes ne "nadoknadi" zamujenega časa naenkrat.
- Starš na nadzorni plošči ne dobi več obvestila vsako minuto, ampak samo, ko se številka na zaslonu res spremeni (pri mešančku ~213× na dan namesto 1.440×).
- **Številke (24-urna simulacija minuto za minuto, avtomatski test):** mešanček je lačen (0 %) natanko po 12 h 30 min (spec 12,5 h), žejen po 10 h; border collie lačen po 8 h 20 min, žejen po 6 h 40 min — natanko po specifikaciji. Enak rezultat, če strežnik zamudi 3 ure. 154 avtomatskih testov zelenih (prej 123).
- Med hard stopom in boleznijo se ustavi tudi štetje zanemarjanja: če starš ustavi igro za 30 ur, pes ne konča v "zavetišču". Preostali čas se ohrani (pes, ki je bil lačen 20 h, ima po nadaljevanju še 4 h).
- Neodvisni AI pregled je pred oddajo našel 2 večji napaki (prepisovanje sočasnih sprememb, game over med hard stopom) in 3 manjše — vse odpravljene.

**Zakaj je pomembno**
Če pes zahteva hrano 2× pogosteje, kot bi jo pravi pes, otrok dobi opozorila že dopoldne in igra ne meri več pripravljenosti, ampak potrpežljivost. Ritem mora biti realističen, sicer certifikat odgovornosti ne pomeni ničesar.

**Kako to povedati**
- 👩 *"Virtualni kuža je lačen tako pogosto kot pravi pes, ne pogosteje. Ko ga začasno ustavite, počaka na vas."*
- 🛠 LinkedIn: *"Napaka zaokroževanja: 0,53 → 1 vsako minuto. Moj virtualni pes je bil 1,8× prehitro lačen. Rešitev: ločena ura za razpad (`last_decay_at`), metrike v plavajoči vejici, zaokroževanje šele ob prikazu."*
- 💼 Igralna mehanika je preverjena s simulacijo celega dne za obe pasmi — številke iz specifikacije so zdaj avtomatski test.

## 2026-10-03 — Prvi pravi avtomatizirani deploy

**Kaj se je zgodilo**
- Prvič v zgodovini projekta je nova verzija šla v produkcijo prek GitHub Actions: testi (123 + 72) → backup baze → migracije → nova verzija na `api.petprep.si`, vse z enim klikom.
- V živo so zdaj kriptografsko preverjeni AI videi in generiranje slike psa v ozadju.

**Kako to povedati**
- 💼 Od kode do produkcije v ~5 minutah, z avtomatskimi testi in ročno potrditvijo ustanovitelja.
- 🛠 Del zgodbe "pipeline, ki ni nikoli tekel": manjkajoči deploy ključ, dedikiran SSH ključ samo za CI, prvi zeleni deploy.

---

## 2026-10-02 — Varovalka pred produkcijo: vsaka sprememba je najprej stestirana

**Kaj se je zgodilo**
- Avtomatsko testiranje na GitHubu (CI) zdaj ob vsakem predlogu spremembe požene **123 testov zaledja** na pravi bazi PostgreSQL in **72 testov mobilne aplikacije** ter preverjanje tipov.
- Odkritje: prejšnja nastavitev avtomatskega deploya **ni nikoli zares tekla**. Datoteka je imela napako, zato je GitHub vsak zagon zavrnil, ne da bi kaj izvedel. Testi bi sicer tekli na napačni bazi (sqlite) in ne bi mogli uspeti.
- Produkcija se zdaj posodobi **samo ročno, z enim klikom**, in šele, ko so vsi testi zeleni. Merge kode ne gre več sam v živo.
- Mobilni testi so po sveži namestitvi spet delovali (72/72), poprej niso tekli niti enkrat. Mimogrede je bila odkrita napaka v postavitvi otroškega zaslona (video psa se ni raztegnil čez cel zaslon).

**Zakaj je pomembno**
Aplikacijo uporabljajo otroci. Nobena sprememba ne sme v živo, ne da bi jo preverili stroji in nato potrdil človek.

**Kako to povedati**
- 💼 Inženirska disciplina: CI na produkcijski bazi, ročna potrditev deploya, neodvisni AI pregled kode. Ena oseba + AI ekipa z varovalkami večje ekipe.
- 🛠 LinkedIn: *"Moj deploy pipeline je bil 'zelen' tedne, v resnici pa ni nikoli tekel. Ena vrstica YAML-a: `secrets` v `environment.url`."* + nasvet: uporabite `actionlint`.
- 👩 (posredno) *"Vsaka posodobitev je najprej stestirana in ročno potrjena."*

---

## 2026-10-02 — Varnost AI psa: noben tuj video ne pride na otrokov zaslon

**Kaj se je zgodilo**
- Webhooki fal.ai, ki aplikaciji sporočijo, da je AI video psa pripravljen, se zdaj preverjajo s kriptografskim podpisom (ED25519) in javnim ključem fal.ai. Brez veljavnega podpisa se ne zgodi nič.
- Vsak video mora pripadati zahtevku, ki ga je PetPrep res poslal (nova tabela `pet_media_jobs`). Isti dogodek se nikoli ne obdela dvakrat.
- Na otrokov zaslon pridejo samo videi z domen fal.ai (HTTPS, brez trikov z `@`, `\`, vrati ipd.).
- Generiranje AI slike psa teče v ozadju, zato povezovanje otroka s staršem ne čaka več na AI (prej do 120 s).
- Popravljena je napaka, zaradi katere se referenčna slika psa sploh ne bi shranila (klic na napačen fal.ai endpoint).
- **Številke:** 113 avtomatskih testov zelenih (prej 82); 36 novih testov (5 zastarelih je bilo zamenjanih) za podpise, ponovitve, rotacijo ključev in poskuse zlorabe URL-jev. Neodvisni AI pregledovalec je kodo pregledal in našel 3 pomembne izboljšave, ki so bile odpravljene pred oddajo.

**Zakaj je pomembno**
Na celotnem zaslonu otroka teče video. Če bi lahko kdorkoli podtaknil poljuben URL, bi otrok lahko videl karkoli. Zdaj je to kriptografsko onemogočeno.

**Kako to povedati**
- 👩 *"Vsak video vašega psa je preverjen, preden ga otrok vidi. Na zaslon pride samo to, kar je ustvaril PetPrep."*
- 💼 Varnost otrok je vgrajena v arhitekturo, ne dodana naknadno. To je pomembno za App Store kategorijo Kids in za zaupanje staršev (glavni nakupni dejavnik).
- 🤝 Za šole in zavetišča: aplikacija ne prikazuje tuje vsebine, nima oglasov in ne nalaga ničesar mimo našega preverjanja.
- 🛠 LinkedIn tema: *"Kako preverjamo fal.ai webhooke z ED25519 v Laravelu — in zakaj 'skrivnost v URL-ju' ni varnost."*
- 📣 Kratek video (*načrt*): pes "nalaga" svoj video, kljukica "preverjeno ✓".

---

## 2026-10-02 — Odločitve: cena, prijava otroka, jeziki

**Kaj je bilo odločeno**
- **12-tedenski PetPrep izziv: 49,99 €**, prvih **7 dni brezplačno**.
- **Mešanček ostane brezplačen za vedno.** Vsaka družina lahko preizkusi osnovno skrb za psa brez plačila.
- **Otrok se prijavi samo s PIN-om**, ki ga ustvari starš. Otrok ne potrebuje e-pošte, njegovi podatki pa so minimalni.
- Jeziki: **angleščina (privzeto) + slovenščina**.

**Zakaj je pomembno**
- Cena 49,99 € pozicionira PetPrep kot resno orodje za oceno pripravljenosti, ne kot igrico za 4,99 €. Višja cena pomeni večjo zavezanost staršev in otroka.
- Brezplačni mešanček odpravi tveganje za starša ("najprej poskusimo").
- Prijava brez e-pošte je zasebnost po zasnovi (GDPR za otroke).

**Kako to povedati**
- 👩 *"Preizkusite brezplačno. Mešanček je vaš za vedno. Če želite dokaz, da je otrok pripravljen na pravega psa, se odločite za 12-tedenski izziv."*
- 🧒 *"Starši ti dajo kodo, vtipkaš jo in tvoj kuža se rodi."*
- 💼 Freemium vstop + premium program (49,99 €) + backend ponudbe (Second Chance reset 19,99 €, *načrt*). Dvojni lijak: brezplačno → plačljivo po 7 dneh.
- 📣 Hook: *"Pes stane 1.500 € in 10 let. Preizkus stane 0 € za 7 dni."*

---

## 2026-10-02 — Prevzem projekta: od "100 % končano" do iskrene slike

**Kaj se je zgodilo**
- Claude (AI orkestrator) je prevzel vodenje razvoja, pregledal 13 poslovno-tehničnih dokumentov in celotno kodo (Laravel zaledje + Expo mobilna aplikacija).
- Prejšnji AI agent je trdil, da je MVP "100 % končan". Pregled je pokazal okoli **40–45 %**: lepo zgrajen uporabniški vmesnik, a otrok psa ni mogel zares nahraniti (gumbi niso bili povezani s strežnikom).
- S simulacijo igralne zanke je bila najdena matematična napaka: lakota bi padala **~2× prehitro** (po 6 urah 10 % namesto 52 %), higiena pa sploh ne.
- Postavljeni so bili temelji za AI razvojno ekipo: navodila za agente, 5 specializiranih vlog (backend, mobile, pregledovalec, devops, marketing), razvojni načrt M0–M5 in kanonska produktna specifikacija.
- Mobilni repozitorij je bil združen v en monorepo z ohranjeno zgodovino; gesla za podpisovanje aplikacije so bila odstranjena iz zgodovine.
- Produkcija že teče na `api.petprep.si` (Hetzner, Docker, samodejni TLS).

**Zakaj je pomembno**
Iskren pregled stanja na začetku prihrani tedne. Zgodba "AI je rekel, da je končano, pa ni bilo" je resnična in poučna za vsakogar, ki gradi z AI.

**Kako to povedati**
- 🛠 / 📣 LinkedIn (David osebno): *"Moj AI agent je rekel, da je aplikacija 100 % končana. Drugi AI jo je pregledal: 40 %. Kaj sem se naučil o vodenju AI razvojne ekipe."* (povezava na AI Builders / Vibe Coding 101 publiko)
- 💼 Proces: AI razvoj z neodvisnim AI pregledom, avtomatskimi testi in dokumentiranim načrtom = hitrost startupa z disciplino večje ekipe. Ena oseba + AI ekipa.
- 🤝 Za partnerje: transparentno vodenje, vsak korak dokumentiran.
- 👩 Še ne komunicirati (notranja zgodba).
