# PetPrep — Produktna specifikacija (kanonska)

> Ena resnica za produkt. Združuje 13 izvornih dokumentov (`docs/source/`) v en dokument; kjer si nasprotujejo, je tukaj zapisana veljavna različica, odprta vprašanja pa so označena z **(D)**.
> Spremembe pravil igre se vpišejo **najprej sem**, nato v kodo (`breed_configs`, servisi) in teste.

## 1. Kaj je PetPrep

"Tamagotchi za 21. stoletje z resničnimi posledicami." Mobilna aplikacija, v kateri otrok 12 tednov skrbi za fotorealističnega AI psa — hrani ga, mu menja vodo, ga čisti in z njim **fizično hodi** (koraki s telefona). Starš v realnem času spremlja, ali otrok obveznosti res opravlja.

- **Kupec / odločevalec:** starš (otrok ga prosi za psa; boji se stroškov 1.000+ € in tega, da bo delo ostalo njemu).
- **Uporabnik:** otrok 7–12 (širše 7–16).
- **Sekundarni trg:** odrasli, ki preverjajo, ali zahtevna pasma (npr. Border Collie) ustreza njihovemu življenjskemu slogu.
- **Obljuba:** objektivni dokaz (ali ovržba), da je otrok pripravljen na pravo žival — preden družina zapravi denar in tvega živalsko dobrobit.

## 2. Obseg MVP

**Monetizacija (odločeno 2. 10. 2026):** mešanček je **vedno brezplačen**; **12-tedenski PetPrep izziv** (49,99 €) s **7-dnevnim brezplačnim preizkusom** odklene program s certifikatom, Border Collie in AI psa. Razmejitev: `docs/business/BUSINESS_MODEL.md` §7.

**Jezik:** angleščina (privzeto) + slovenščina; vsi teksti prek i18n od prvega dne.

**Vključeno:** dvojni profil starš–otrok, PIN pairing, samo pes (mešanček brezplačno + Border Collie premium), 4 metrike, koraki iz zdravstvenih API-jev, push obvestila in eskalacija, starševska nadzorna plošča s semaforjem, tihe ure, hard stop, bolezen in game over, AI video psa, IAP prek RevenueCat.

**Izključeno (ne gradimo v MVP):** AR, GPS sledenje in zemljevidi, vremenski API, LLM / vision veterinar, B2B QR kuponi, mačke, naročnina Pro skrbnik.

## 3. Profili in onboarding

1. **Starš** se registrira (Apple / Google, email kot rezerva) → ustvari otroški profil → izbere pasmo (premium = plačilo) → generira **6-mestni PIN (velja 15 min)**.
   - **Izbira kužka (David, 5. 10. 2026; strežnik M5-R01, aplikacija M5-R04 — zaslon "Izberi kužka" po izbiri "Nov pes"; pri "Pridruži se psu" ga ni; izvor in starost brez privzete izbire):** ob PIN-u za novega psa starš izbere **pasmo**, **izvor** (*kupljen* pri vzreditelju / *posvojen* iz zavetišča) in **starost ob prihodu** (*mladiček*, *mlad pes*, *odrasel*, *starejši*). Mešanček je brezplačen v vseh kombinacijah; plačljive pasme se še vedno odklenejo samo z nakupom (izbira plačljive pasme ob PIN-u → "pasma je del plačljivega izziva"). Brez izbire (starejša različica aplikacije): pes brez profila = **legacy** pes, za vedno na pravilih pred M5 (§4).
2. **Otrok** na svoji napravi vnese PIN → odpre se **Pogodba o odgovornosti** → podpis s prstom → pes se "rodi".
   - **Registracija z e-pošto (izvedeno 5. 10. 2026, M2-10a; Claude, čaka Davida):** ime (do 60 znakov), e-pošta (neobčutljiva na velike/male črke), geslo vsaj 10 znakov z velikimi in malimi črkami ter številko (dvakrat), obvezno strinjanje s pogoji uporabe in politiko zasebnosti (čas strinjanja se shrani). Ob registraciji nastane starševa **družina v časovnem pasu telefona** (privzeto Europe/Ljubljana). Starš je takoj prijavljen; potrditev e-pošte in ponastavitev gesla pozneje (M2-10b), Apple / Google (M2-10c). Že uporabljena e-pošta → "Ta e-poštni naslov je že registriran."
   - **Pogodba pred rojstvom (David, 4. 10. 2026):** po vnosu PIN-a pes že obstaja (izgled, referenčna slika se lahko ustvari takoj), a je **še nerojen**: nič ne upada, ni kakcev, dan se ne zaključuje (sprehod), ni opomnikov, bolezni ne odvzema. Otrok lahko naredi samo eno stvar — podpiše pogodbo; vse druge akcije strežnik zavrne z razlogom "najprej podpiši pogodbo" (`contract_required`). **Trenutek podpisa (čas strežnika) je rojstvo:** vse metrike 100 %, starost, ura upadanja, urnik higiene in "rojstni dan" (energija 100 % do prve polnoči, brez bolezni zaradi sprehoda) začnejo teči takrat. Če otrok podpis odloži, ne izgubi nič. Starš do podpisa vidi psa kot "čaka na pogodbo".
   - Med hard stopom (ali če je seja neaktivna) tudi podpis ni mogoč — starševski premor ima prednost.
   - Psi, ustvarjeni pred to spremembo, veljajo za rojene (brez zaklepa, tudi brez podpisane pogodbe).
3. **Otrok nima lastnega emaila; PIN je njegova prijava** (token vezan na otroški profil, ki ga ustvari starš). *Odločeno 2. 10. 2026.*
   - **Otroški profil (izvedeno 4. 10. 2026, M2-02):** starš vpiše samo **vzdevek** (do 30 znakov, priimek ni potreben) in po želji **letnico rojstva**. Brez e-pošte, gesla, priimka ali datuma rojstva.
   - **PIN za otroka:** starš izbere otroka (in po želji psa, h kateremu se pridruži) → 6-mestni PIN, 15 minut, enkraten; nov PIN za istega otroka razveljavi prejšnjega. Prvi PIN otroka poveže (nov nerojen pes ali skupni pes), vsak naslednji samo **prijavi novo napravo** istega otroka (isti pes). *(Claude, čaka Davida)*
   - Otrok je lahko prijavljen na **največ 3 napravah**; starš ga lahko odjavi z vseh naprav. *(Claude, čaka Davida)*
   - Napačna/potekla/porabljena koda: otrok vidi samo "Koda ni veljavna, prosi starša za novo". Po preveč napačnih poskusih počaka 15 minut.
4. **Družina** (David, 4. 10. 2026; ADR-012): več staršev (npr. mama in oče) in več otrok. Vsak otrok ima svojega psa **ali** več otrok skupaj skrbi za enega psa (skupno skrbništvo). Vsi starši vidijo vse otroke in pse v družini. Pri skupnem psu se vsako dejanje zapiše pod otroka, ki ga je naredil — **vsak otrok ima svojo oceno, semafor in certifikat**. Cena: 12-tedenski izziv se plača **na psa** (skupni pes = ena cena); mešanček ostane brezplačen.
   - **Pridružitev drugega starša:** starš ustvari kodo za povabilo (8 znakov, velja 24 ur, enkratna); drugi starš jo vnese v svoj račun in postane starš iste družine. Račun, ki že ima otroke ali pse, se ne more pridružiti (družin ne združujemo). *(David, 4. 10. 2026)*
   - **Otrok k obstoječemu psu:** starš pri ustvarjanju PIN-a izbere "nov pes" ali "pridruži se psu X". Otrok, ki se pridruži, podpiše **svojo** pogodbo, preden lahko skrbi za psa; pes se ne rodi znova (rodi se ob prvi pogodbi). *(David, 4. 10. 2026)*
   - Otrok skrbi za **največ enega aktivnega psa** hkrati. *(David, 4. 10. 2026)*
   - Dnevni sprehod skupnega psa je dosežen s **seštevkom korakov vseh otrok**, ki skrbijo zanj, vsak otrok vidi svoje korake. *(David, 4. 10. 2026)* Hranjenje in voda sta pravili psa (enkrat na okno ne glede na to, kdo nahrani). *(Claude, čaka Davida)*
   - Ocena in semafor posameznega otroka: §9 in §11 (David, 4. 10. 2026 — "pošten delež"). Formula certifikata še ni določena.
5. **Izbris in izvoz podatkov (izvedeno 5. 10. 2026, M2-08; Claude, čaka Davida):** starš v »Nadzor → Račun« izbriše svoj račun ali v seznamu otrok posamezen otroški profil — **takoj in nepovratno**, z geslom in vpisom »IZBRIŠI«. **Zadnji starš izbriše celotno družino** (otroke, pse, slike in videe, pogodbe s podpisi, dnevnik, ocene, naprave); če ostane drug starš, se izbriše samo račun tega starša. Ob izbrisu otroka se izbriše pes, za katerega je skrbel sam; **skupni pes ostane** drugim otrokom, otrokova pretekla dejanja pa ostanejo brez imena; **pretekla ocena (Care Score) preostalih otrok se ne spremeni** (izbrisani otrok še vedno šteje v pošten delež rutin, ki so se odprle, ko je skrbel), rutine po izbrisu pa nosijo preostali otroci. »Izvozi moje podatke« da vse podatke družine v datoteki JSON (vključno s podpisi pogodb in povezavami do slik / videov, ki veljajo ~1 uro; brez gesel in kod), največ 3-krat na uro.

## 4. Čas in življenjski cikel

- **1 realen teden = 1 virtualni mesec.** Starost na zaslonu: pes s profilom (izbira kužka, M5-R04) pokaže fazo in starost, npr. "Mladiček · 3 mesece" (od 24 mesecev v letih, "Odrasel · 3 leta"); obstoječi (legacy) psi brez profila ostanejo pri "Starost: N mesecev".
- **Starost psa (M5-R01, David 5. 10. 2026):** starost ob prihodu + 1 mesec za vsak teden od rojstva (podpis pogodbe). Starost ob prihodu: mladiček **2 meseca** (8 tednov — vir S36), mlad pes **9**, odrasel **36**, starejši **108** (mešanček) / **118** (Border Collie) mesecev — za mladega, odraslega in starejšega je to prvi mesec faze (potrdil David 5. 10. 2026), da pes ves izziv ostane v izbrani fazi. Teden se šteje po stenski uri družine (rojstni "dan v tednu" ob isti uri, tudi ob prestopu ure). **Obstoječi psi (ustvarjeni pred M5-R01, iz PIN-a brez izbire profila — tudi iz starejše aplikacije brez izbirnika, do M5-R04 — ali prek opuščenega `/child/pair`) ostanejo na pravilih pred M5 za vedno** — tudi po koncu svojega izziva (David 5. 10. 2026; prej orkestrator, PR #37): okni pasme 06–10 / 17–21, cilj korakov pasme (mešanček 4.000), brez obroka staršev v tihih urah, brez življenjske faze in brez novih slik faze. Nov pes s profilom dobi pravila faze; ta se zamenjajo vedno šele ob naslednji lokalni polnoči (zaprti dnevi se nikoli ne preračunajo).
- **Življenjske faze (iz virov, `docs/research/dog-data`; meje potrdil David 5. 10. 2026):** mladiček do 9 mesecev (< 9; AAHA: "~6–9 mesecev", S11), mlad pes do 3 let (< 36 mesecev, S11), odrasel, starejši od zadnje četrtine pričakovane življenjske dobe (0,75 × doba: mešanček 12,0 let → **108 mesecev**, Border Collie 13,1 → **118 mesecev**; S11 + S15). Viri dajo razpone, točen mesec je odločitev. **Pravila faze začnejo veljati ob lokalni polnoči po tedenskem rojstnem dnevu** (cel dan velja ena pravila). 12-tedenski izziv z mladičkom: mladiček → mlad pes v 8. tednu.
- Izziv (12 tednov, certifikat) se šteje od rojstva ne glede na starost psa.
- Simulacija traja **12 realnih tednov** → pes dopolni 1 leto → **Certifikat odgovornosti** s končno oceno.
- Vsi časi (tihe ure, polnoč, okna hranjenja) so v **lokalnem časovnem pasu družine**.

## 5. Metrike in hitrost upadanja

| Metrika | Mešanček (brezplačno) | Border Collie (premium) | Kako jo otrok dvigne |
|---|---|---|---|
| Lakota | −8 %/h (0 % v 12,5 h) | −12 %/h (0 % v 8,3 h) | gumb Hrani — **samo v oknih** → 100 %; število obrokov po starosti (M5-R01, spodaj): mladiček 4 → 3 → 2, sicer 2× / dan |
| Žeja | −10 %/h | −15 %/h | gumb Voda — 3× / dan → 100 % |
| Gibanje (energija) = **dnevni sprehod** | cilj po fazi (M5-R01): odrasel **6.000**, mladiček 2.000 → 6.000 | cilj po fazi: odrasel **12.000**, mladiček 2.000 → 12.000 | koraki iz HealthKit / Health Connect; energija = današnji koraki / cilj; **reset na 0 % ob lokalni polnoči** (0 % = "danes še ni bilo sprehoda", ne zanemarjanje) |
| Higiena | naključno **1× / dan** pade na 0 % | naključno **2× / dan** pade na 0 % | mini-igra čiščenja (drgnjenje madežev) → 100 % |

- **Tihe ure** (starš nastavi, npr. šola 8:00–13:00, spanje 22:00–6:00): upadanje se upočasni za 90 %, obvestila se ne pošiljajo, higienski dogodki se ne zgodijo.
- **Hard stop / bolezen:** metrike so zamrznjene.
- **Pred podpisom pogodbe** (nerojen pes, §3) se nič ne zgodi: brez upadanja, higienskih dogodkov, zaključka dneva in eskalacije.
- **Anti-cheat koraki:** zavrnemo prirastke > 200 korakov / min.
- **(D)** Spec omenja tudi "5.000 korakov" (MVP.docx) — veljavno je 4.000 / 10.000 iz MAIN dokumenta.
- Vse številke iz tabele (hitrosti, cilji korakov, število dogodkov, okna hranjenja 06:00–10:00 in 17:00–21:00, voda 3× / dan z razmikom ≥ 3 h) so v tabeli `breed_configs` in jih admin spreminja v Filamentu — ne v kodi (M1-06).

**Pravila po starosti (M5-R01 — David 5. 10. 2026; strežnik zgrajen, številke iz `docs/research/dog-data/data.json`, vsaka z virom v tabeli `breed_stage_params`):**
- **Obroki na dan (viri S18, S19, S14):** mladiček 2–3 mesece **4**, 3–6 mesecev **3**, 6–12 mesecev **2**; mlad pes, odrasel in starejši **2** (David: odrasel 2×; starejši 2 — vir pravi 2–3, potrdil David 5. 10. 2026).
- **Ure oken (David 5. 10. 2026 — viri dajo samo število obrokov, ure so odločitev):** 4 obroki **07–09, 11–13, 15–17, 19–21**; 3 obroki **07–09, 13–15, 19–21** (prvo okno ob 7:00, zadnje ob 19:00, enakomerno, **2 uri**; konec okna ni vključen — hranjenje ob 08:30 šteje, ob 09:00 ne). 2 obroka: okni pasme 06–10 in 17–21 (kot prej).
- **Obrok med tihimi urami opravi starš (David 5. 10. 2026):** okno, ki je **v celoti** v tihih urah (šola, spanje), se od otroka ne pričakuje (ni zamujena rutina, ne šteje ne za ne proti otroku); strežnik ob začetku okna kužka nahrani (lakota 100 %, zapis "starš nahranil" brez imena). Okno, ki je le delno v tihih urah, ostane otrokovo. Med hard stopom / pri veterinarju se samodejno ne hrani. Primer: mladiček 2 meseca, šola 8–13 → okno 11–13 nahrani starš, otrok 07, 15, 19 (okno 07–09 je le delno v šoli → otrokovo). Če strežnik zamudi tik (izpad), lakota po obroku pada naprej od ure obroka (enako kot pri sprotnih tikih). *Znana omejitev:* sprememba tihih ur velja tudi za še neobdelan čas in za še nezaprte dni (kateri obrok je starševski, odloča trenutni urnik). Ne velja za obstoječe (legacy) pse — ti ostanejo na pravilih pred M5 za vedno (§4).
- **Cilj korakov = dnevno gibanje psa (minute, iz virov) × 100 korakov / minuto** (David sprejel pretvorbo; vir S45 velja za odrasle ljudi). Border Collie odrasel > 120 min (S5) → **12.000**; mešanček odrasel 60 min (znotraj 30–120 min, S24; potrdil David 5. 10. 2026) → **6.000**; mladiček in mlad pes: 10 min × starost v mesecih (sporno pravilo "5 min × mesec, 2× na dan", S24 / S25; potrdil David) do odraslega cilja (mešanček 2 meseca 2.000, 3 meseci 3.000, od 6 mesecev 6.000; Border Collie 9 mesecev 9.000, od 12 mesecev 12.000); starejši 75 % odraslega (potrdil David; viri samo "pogosti krajši sprehodi"; mešanček 45 min → 4.500, Border Collie 90 min → 9.000). Zgornja meja na pasmo je nastavljiva (`daily_steps_cap`), zdaj brez.
- Voda ostaja po pasmi (3× / dan, razmik 3 h). Spanje po starosti je shranjeno za videe / vedenje (RKC S28: 15–20 h mladiček, 14–16 h 3–6 mes., 12–14 h kasneje).
- Od 5. 10. 2026 so vse uvožene vrednosti potrjene (`verified = true`): neposredno iz vira ali kot odločitev (oznaka »potrdil David 2026-10-05«; vir ostane dokaz, iz katerega je vrednost izpeljana, odločitev se nikoli ne predstavi kot literatura). API `data_verified` je zato `true`. Vrednost, ki jo admin kasneje označi kot *predlog* (NEPODPRTO), je v Filamentu vidno označena in se staršem ne prikazuje kot dejstvo.

**Vedenjski dogodki (M5-R02 — David 6. 10. 2026; strežnik zgrajen, aplikacija še ne):** dve novi vrsti nereda poleg kakca. Samo za nove pse, ustvarjene iz **različice aplikacije, ki vedenje zna prikazati** (starševa aplikacija pri ustvarjanju PIN-a **in** otrokova aplikacija pri prijavi s PIN-om pošljeta `features: ["behaviour_events"]` — orkestrator 6. 10. 2026, PR #42: starejša aplikacija nima gumbov »Pelji ven« / »Pospravi«, zato tak pes teh dogodkov nima; obstoječi psi tudi ne). Nikoli pred rojstvom, med hard stopom, boleznijo ali po game overju (ne nadoknadijo se). Če strežnik zamudi (izpad > 5 min), se luža za ta čas ne nadoknadi (ura mladička začne znova) in uničevanje se odloči samo za tekoči dan.
- **Luža mladička in gumb »Pelji ven«:** mladiček zdrži **1 uro na mesec starosti** (viri S30 / S31; 2 meseca → 2 h, 3 meseci → 3 h; starost na začetku dneva, kot ostala pravila faze). Ura se začne ob zadnjem »Pelji ven«, ob zadnji luži, ob rojstvu, ob koncu hard stopa / bolezni in **ob lokalni polnoči** (noč je od staršev; *Claude, čaka Davida*), in **teče samo izven tihih ur** (med šolo in spanjem stoji). Ko poteče, se zgodi luža (čistoča 0 %) in ura začne znova. Rok, ki bi padel točno na začetek tihih ur, se premakne na njihov konec. Primer — mladiček 2 meseca, šola 8–13: »Pelji ven« ob 6:30 → luža ob 13:30, če ga nihče ne pelje ven prej. Velja samo v fazi **mladiček**. »Pelji ven« je dovoljen vedno, ko pes ni zaklenjen (tudi med neredom in v tihih urah); dva pritiska v 60 s štejeta kot eden; pri psu, ki ni mladiček, strežnik odgovori `take_out_not_needed`. **Lužo počisti običajna igra čiščenja** (*Claude, čaka Davida*).
- **Uničevanje (»uničil copat«):** največ **en** dogodek na lokalni dan, ob naključni minuti izven tihih ur. Zgodi se, (a) **če prejšnji dan rutina sprehoda ni bila opravljena** (zares zamujena — dan rojstva ali dan pretežno v hard stopu ne šteje) — vsak ne-legacy pes; ali (b) pri **mladičku med menjavo zob** (starost 3–6 mesecev, S32 / S33) z verjetnostjo **0,5 na dan** (*nepreverjen predlog — Claude, čaka Davida*; v `breed_stage_params` z `verified = false`). Otrok ga reši z gumbom **»Pospravi in daj igračo«**.
- Oba znižata **čistočo na 0 %** kot kakec: veljajo isti opomniki, faza 3, bolezen po 6 h izven tihih ur, hrana in voda se zavrneta (»najprej počisti«). **Čistoča se vrne na 100 % šele, ko ni odprt noben nered** (čiščenje ne pospravi copata, »Pospravi« ne počisti luže). Brez novih obvestil (besedila »nered« ostanejo, brez imen). Ob koncu bolezni veterinar zapre vse odprte nerede.
- Dogodek »posvojen odrasel pes brez navad« (S39) in plašnost posvojenega psa za zdaj **nista** del igre (odprto vprašanje).

**Šolanje (M5-R03 — David 6. 10. 2026; strežnik in aplikacija zgrajena 6. 10. 2026 — aplikacija še ni v trgovini):** samo za nove pse iz **različice aplikacije, ki šolanje zna prikazati** (starševa aplikacija pri ustvarjanju PIN-a **in** otrokova aplikacija pri prijavi s PIN-om pošljeta `features: ["training"]`, enako kot pri vedenjskih dogodkih); obstoječi psi in **legacy psi šolanja nimajo** (strežnik odgovori `training_not_available`, rutine šolanja ni).
- **Ukazi:** **sedi**, **pridi**, **prostor**, **lulat zunaj** (navajanje na čistočo). Napredek po ukazu **0–100 %**; pri 100 % kuža ukaz »zna«.
- **Mini-igra = pravi trenutek za nagrado** (strežnik odloča): otrok izbere ukaz in začne vajo; strežnik pripravi urnik: po 2 s uvoda **8 ukazov**, vsakih 6 s. Ob ukazu kuža **včasih uboga** — po naključnem zamiku 0,8–2,5 s; otrok mora takrat v **1,5 s** pritisniti **»Pohvali«**. Prvi pritisk pri vsakem ukazu odloči: **pravočasno** (napredek; najprej **150 ms** po tem, ko kuža uboga — hitreje človek ne more reagirati, zato šteje kot prezgodaj), **prezgodaj** ali **prepozno** (brez napredka), ni pohvale; če kuža ni ubogal, je prav, da otrok **počaka** (pohvala takrat ne prinese nič). Bolje kot kuža ukaz zna, pogosteje uboga (50 % → 90 %); v vsaki vaji kuža vsaj enkrat uboga in vsaj enkrat ne. Vaja traja **50 s**. *(Časi so Claudov predlog — čaka Davida.)*
- **Napredek** na pravočasno pohvalo: **1 točka** (potrdil David 6. 10. 2026) × **učljivost pasme** — **Border Collie 2×** (Coren: rang 1, S34 / S35; potrdil David 6. 10. 2026), **mešanček 1×** × **osebni faktor psa ±20 %** (enkrat izžreban ob prvi vaji in shranjen — vsak mešanček se uči malo drugače; S42: pasma pojasni le ~9 % razlik; potrdil David). Mešanček z eno dobro vajo na dan obvlada ukaz v ~16 dneh, Border Collie v ~8; vse 4 ukaze približno v 12-tedenskem izzivu.
- **Dnevni čas:** največ **5 minut vaje na psa na dan** (≈ 6 vaj; S36 / S37: vaje 5–10 min, mladiček ≤ 15 min na dan; potrdil David 6. 10. 2026); vsaka začeta vaja porabi svojih 50 s. Čas se **pošteno razdeli** med otroke psa (glej spodaj). Hkrati teče največ ena vaja na psa (tudi pri bratu / sestri); vajo konča otrok, ki jo je začel, v 60 s po koncu urnika, sicer poteče. Vaje, ki bi trajala (z rokom 60 s) čez lokalno polnoč, ni mogoče začeti (»dan se končuje«) — vaja, njen čas in rutina vedno pripadajo enemu dnevu. Če se med vajo začne hard stop, bolezen ali game over, vaja ne šteje in njen čas se vrne v dnevni čas. Urnik izbere in pritiske oceni strežnik; ker urnik pozna aplikacija, bi spremenjena aplikacija lahko ponaredila pritiske — dnevni čas omeji pridobitev, sumljivo enakomerne odzive strežnik zabeleži (*sprejeto tveganje do odločitve Davida*). Mladiček se ne uči počasneje in nima krajših vaj (viri tega ne dajo).
- **Številke (potrdil David 6. 10. 2026; v kodi od M5-R03b, v `breed_stage_params` označene kot preverjene):** 5 minut vaje na psa na dan; +1 % na pravočasno pohvalo (× pasma × faktor psa); −2 % vsem ukazom za vsak dan brez vaje. Ponarejene pritiske iz prirejene aplikacije sprejmemo (David); varnost sistema ostaja obvezna.
- **Pošten delež dnevnega časa (David 6. 10. 2026):** 5 minut se enakomerno razdeli med otroke, ki psa lahko šolajo — skrbnike psa, ki so **podpisali svojo pogodbo** (otrok, ki se je pridružil in še ni podpisal, ne more vaditi in ne zmanjša deleža drugim — odločitev Davida 7. 10. 2026). En otrok sam = ves čas (300 s = 6 vaj); 2 otroka → 150 s vsak = 3 vaje; 3 → 100 s = 2 vaji; 4 → 75 s = 1 vaja. Ko otrok porabi svoj delež, strežnik naslednjo vajo zavrne (»tvoj del današnjega časa je porabljen«, do lokalne polnoči), brat ali sestra pa lahko še vadi svoj del. Hkrati še vedno teče največ ena vaja na psa. Prekinjena vaja (hard stop / veterinar / game over) se vrne tudi v delež otroka. Če se med dnevom pridruži (podpiše) nov otrok, se deleži takoj izračunajo za vse otroke znova (kdor je že porabil več od novega deleža, ta dan ne more več vaditi — odločitev Davida 7. 10. 2026). Aplikacija dobi število otrok, svoj delež in koliko ga je še ostalo. Delež je **vsaj ena vaja (50 s)**: pri 7 ali več otrocih lahko vsak opravi eno vajo, dokler ne poteče skupnih 5 minut psa (dobijo jih tisti, ki vadijo prvi — *Claude, čaka Davida*).
- **Pes, ki pride odrasel, že nekaj zna (David 6. 10. 2026):** pes, ki ob prihodu **ni mladiček** — **mlad (9 mesecev), odrasel ali starejši**, kupljen ali posvojen — začne s **sedi 50 %, lulat zunaj 70 %, pridi 30 %, prostor 0 %**; **mladiček (tudi kupljen) začne z 0 %**. Velja samo za nove pse s šolanjem (ob nastanku psa, iz faze ob prihodu); obstoječi in legacy psi se ne spremenijo. Začetni napredek ni vaja (ne šteje kot opravljena vaja ali rutina) in upada enako kot ostali napredek (−2 % na dan brez vaje). Vira ni (predlog Claude, potrdil David); vrednosti so v `breed_stage_params` (`training_starting_progress`, po fazi) in jih je mogoče urejati v Filamentu.
- **Brez vaje napredek upada:** za vsak lokalni dan z **zamujeno rutino šolanja** vsak ukaz izgubi **2 točki** (potrdil David 6. 10. 2026; nikoli pod 0). Dan, ko rutina ni pričakovana (rojstni dan, hard stop / veterinar večino dneva), se ne šteje.
- **Učinki (potrdil David 7. 10. 2026; samo za pse s šolanjem in vedenjskimi dogodki):** **lulat zunaj** — ko mladičku poteče čas (1 h na mesec starosti ostane, S30 / S31), **prosi, da gre ven**, namesto da naredi lužo, z verjetnostjo **0,75 × napredek** (VCA S47: navajen mladiček se nauči prositi); ura se takrat začne znova. **prostor** — verjetnost uničevanja med menjavo zob se zmanjša za **0,5 × napredek** (ASPCA S33); uničevanje po zamujenem sprehodu ostane (Davidova odločitev). Viri potrjujejo smer učinka, velikost (0,75 / 0,5) je določil PetPrep.
- Starš vidi »Kuža zna: sedi ✓, pridi 60 %« (napredek po ukazu, ali je bila vaja danes), v časovnici »opravil vajo«. Vse številke so v `breed_stage_params` (Filament); kar ni iz vira ali Davidove odločitve, je označeno *nepreverjeno*.

**Pojasnila implementacije (M1-07 — 4. 10. 2026, otroški API):**
- **Hrana:** samo znotraj okna pasme po lokalnem času družine; okno vključuje začetek in ne konca (06:00 da, 10:00 ne). **Eno hranjenje na okno** (2 okni = 2× / dan). Izven okna ali drugič v istem oknu strežnik zavrne in pove začetek naslednjega okna. Ob prestopu ure okna sledijo stenski uri (06:00 je poleti 04:00 UTC, pozimi 05:00 UTC).
- **Voda:** največ `water_times_per_day` (3) na lokalni dan (meja se ponastavi ob lokalni polnoči), med dvema najmanj `water_min_gap_minutes` (180) **realnih** minut, tudi čez polnoč. Ko je dnevna meja dosežena, je naslednja voda ob lokalni polnoči (oz. kasneje, če razmik še ni potekel). *(Potrdil David, 4. 10. 2026.)*
- **Najprej čiščenje:** dokler higiena kaže 0 %, hrana in voda nista mogoči (§8); koraki in čiščenje vedno. *(Potrdil David, 4. 10. 2026.)*
- **Zaklep:** med hard stopom, boleznijo, po game overju in pred podpisom pogodbe (nerojen pes, §3) strežnik zavrne vsako otroško akcijo z razlogom; pogodbo je mogoče podpisati samo, ko je edini razlog "najprej pogodba". Če velja več razlogov hkrati, se pokaže prvi od: game over › neaktiven › hard stop › pogodba › bolezen.
- **Pogodba:** podpis enkrat na psa (ponoven podpis se zavrne, prvi ostane; nov pes po game overju = nova pogodba); čas podpisa je čas strežnika in hkrati trenutek rojstva psa (§3). *(Potrdil David, 4. 10. 2026.)*

**Pojasnila implementacije (M1-04, M1-05 — 3. 10. 2026):**
- **Gibanje = dnevni sprehod (David, 3. 10. 2026):** energija ni urna metrika zanemarjanja. Za energijo ni ure "0 % > 1 h" (alarm faze 3), ne 6-urne bolezni ne 24-urnega game overja. Energija **ni na lestvici faz** (*Claude, čaka Davida — pregled PR #35*): nizka energija (≤ 30 %) izven tihih ur sproži le ločen dnevni opomnik za sprehod (§6), faze 1–3 sledijo samo hrani, vodi in čistoči. Pravilo dneva — glej §7 "Dnevni sprehod".
- **Gibanje:** telefon pošilja *skupno* število današnjih korakov; šteje največja prejeta vrednost (ponovljen ali manjši sync ne spremeni ničesar). Energija se s časom ne zmanjšuje — samo ob lokalni polnoči pade na 0 % (koraki → 0). Sync korakov energije nikoli ne zniža, zato ima novorojen pes 100 % do prve polnoči.
- **Anti-cheat:** dovoljeno je največ 200 korakov na minuto od zadnjega sprejetega synca (oz. od lokalne polnoči za prvi sync dneva). Presežek se zavrne, ne celoten sync: če telefon po 5 minutah javi +2.000 korakov, sprejmemo 1.000; preostanek se lahko sprejme ob naslednjem syncu, ko mine dovolj časa. Sync s časom v prihodnosti štejemo, kot da je prišel zdaj; sync z včerajšnjim datumom se ignorira. Med hard stopom, boleznijo in game overjem se koraki ne sprejmejo.
- **Higiena:** postopnega padanja ni več (začasno pravilo 1,5 %/h je odstranjeno). Za vsak lokalni dan vnaprej izžrebamo čase "kakca" (mešanček 1×, Border Collie 2×) — samo izven tihih ur, vsak v svojem enakem delu netihega dne (pri 2× en v prvi in en v drugi polovici), zato sta praviloma razmaknjena čez dan. Ko čas mine, higiena pade na 0 %; čiščenje vrne 100 %. Dogodek, ki pade v hard stop, bolezen ali pred rojstvo psa, se ne zgodi (ne nadoknadi se). Če strežnik zamudi, se zamujeni dogodki uveljavijo enkrat, ura zanemarjanja pa teče od dejanskega časa dogodka.

## 6. Eskalacija (za vsako metriko)

| Faza | Sprožilec | Kaj se zgodi |
|---|---|---|
| 1 — Blag opomnik | metrika ≤ 30 % | push otroku: "Tvoj kuža te milo gleda in kaže na posodo s hrano." |
| 2 — Kritično | metrika ≤ 10 % | push z močno vibracijo in cviljenjem: "Če ga ne nahraniš v 30 minutah, bo zbolel." |
| 3 — Intervencija | metrika 0 % > 1 h | alarm na telefonu starša (Reverb + push): "Tvoj otrok danes ni poskrbel za psa." |

Pragovi se primerjajo s prikazano (zaokroženo) vrednostjo.

**Push obvestila (M3-02, 5. 10. 2026 — podrobnosti in besedila v DECISIONS):** faza 1 in 2 gresta vsem otrokom, ki skrbijo za psa; faza 3 vsem staršem družine; bolezen in game over staršem in otrokom. Besedilo sledi metriki, ki je najnižja (hrana, voda, nered). Ista vrsta obvestila za istega psa največ enkrat na 30 minut. **Med tihimi urami ni nobenega obvestila;** opomniki in alarm, preskočeni med tihimi urami, se kasneje ne pošljejo.
- **Sprehod (energija) — *Claude, čaka Davida (PR #35)*:** energija ni na lestvici faz 1–3 (ta sledi samo hrani, vodi in čistoči — nizka energija nikoli ne zadrži alarma za hrano). Ko energija izven tihih ur kaže ≤ 30 %, gre ločeno navadno obvestilo za sprehod (ne alarm, brez zvoka v ospredju), **največ enkrat na lokalni dan**, **ne prej kot 2 h po koncu zadnjih tihih ur tega dne** (spanje do 06:00 → 08:00; šola 08–13 → 15:00); če je otrok medtem šel na sprehod, ga ni. Brez »bo zbolel v 30 minutah«.
- **Bolezen in game over med tihimi urami — *Claude, čaka Davida (PR #35)*:** obvestilo počaka do konca tihega obdobja in se pošlje takrat.
- Opomniki in alarm se ne pošljejo, če je pes medtem ustavljen (hard stop), neaktiven, v zavetišču ali bolan. Naslov je vedno »PetPrep«, v obvestilu ni imen otrok ali psa.
**Gibanje (energija)** ni na tej lestvici (*Claude, čaka Davida — pregled PR #35*; prej: fazi 1 in 2 izven tihih ur): namesto faz je en dnevni opomnik za sprehod (spodaj); faza 3, bolezen po 6 h in game over se za energijo ne štejejo (dnevni sprehod, §7). Stanje psa "bolan" (`sick`) pomeni samo umazanega ali dejansko bolnega psa — pri 0 % energije je pes "utrujen" (`low_energy`).

## 7. Kazni

- **Bolezen:** higiena 0 % > 6 h (izven tihih ur) **ali** včeraj ni bilo sprehoda (glej "Dnevni sprehod") → zaslon sivo, video težkega dihanja, **12 h timeout** ("na opazovanju pri veterinarju"), otrok ne more ničesar.
  - *Pojasnilo (M1-04, 3. 10. 2026):* 6 ur higiene se šteje **samo izven tihih ur** (med tihimi urami števec stoji), čas hard stopa in bolezni pa se ne šteje. Primer — tihe ure spanje 22:00–06:00, kakec ob 09:00, nihče ne počisti → pes zboli ob 15:00.
  - **Ozdravitev = nov začetek (David, 3. 10. 2026):** ko 12 h bolezni mine, se kuža vrne od veterinarja **čist — higiena 100 %**, in **vse ure zanemarjanja začnejo teči znova od trenutka ozdravitve** (alarm faze 3 po 1 h, bolezen po 6 h, game over po 24 h). Lakota in žeja ostaneta, kakršni sta bili (otrok ju zdaj spet lahko napolni); energija ostane vezana na korake; opomniki (faze) začnejo znova od 0. Če je med boleznijo vklopljen hard stop, je kuža ob koncu bolezni ozdravljen, ure pa stojijo, dokler starš hard stopa ne izklopi. Tako pes po ozdravitvi ne zboli takoj spet (prejšnja "neskončna zanka bolezni"); če ga otrok spet zanemari, zboli po običajnih 6 h izven tihih ur.
- **Dnevni sprehod (David, 3. 10. 2026):** ob lokalni polnoči družine se dan zaključi enkrat na psa: zapišemo včerajšnje korake, cilj pasme (od M5-R01: cilj starosti psa tisti dan) in ali je bil cilj dosežen (za starševski pregled).
  - Če je včerajšnja energija ob polnoči kazala **0 %** (sploh ni bilo sprehoda; tudi npr. 10 korakov pri mešančku se prikaže kot 0 %), kuža **zboli ob koncu tihih ur te noči** (npr. ob 06:00 pri spanju 22:00–06:00; če spanju takoj sledi šola, ob koncu šole; brez tihih ur že ob polnoči) — 12 h, kot zgoraj.
  - Nekaj korakov, a manj od cilja → samo zapis "cilj ni dosežen", brez bolezni.
  - Rojstni dan psa nikoli ne povzroči bolezni. Dan, ki se zaključi med hard stopom ali boleznijo, ne povzroči bolezni; če je starš ob predvidenem začetku bolezni vklopil hard stop, bolezen odpade. Če je strežnik zamudil več kot eno polnoč, bolezni ne sprožimo.
  - Koraki po polnoči že štejejo za novi dan in včerajšnjega ne rešijo.
- **Game over / "Virtual Shelter Intervention":** katerakoli metrika 0 % **24 h** → pes odvzet, otrokov zaslon zaklenjen. Staršu se ponudi:
  - **Breed Downgrade reset** (brezplačno, z odobritvijo starša) — lažja pasma;
  - **Second Chance reset** (19,99 €) — po MVP.
- Starš lahko kadarkoli sproži **Hard stop** → otrok vidi "Simulacija je začasno ustavljena. Pogovori se s starši."

## 8. Otroška aplikacija (UI)

- Celozaslonski AI video psa; video se menja glede na stanje (`idle, sleeping, low_energy, hungry, sick, playing`).
- Zgoraj: glassmorphism vrstica (ime / pasma, starost, indikator povezave).
- Desno: 4 vertikalne vrstice (lakota, žeja, gibanje, higiena), barva zelena → rumena → rdeča.
- Spodaj: 4 okrogli gumbi (briketi, kaplja, povodec, metla); izven okna so zasenčeni s pojasnilom.
- **Sprehod:** overlay s števcem "1.250 / 4.000 korakov", sync ob vrnitvi.
- **Čiščenje:** ko higiena pade na 0 %, umazanija prekrije zaslon; dokler je otrok ne zdrgne, druge akcije niso mogoče.
- **Vedenje (M5-R02, *načrt* za aplikacijo — strežnik pripravljen):** pri mladičku gumb **»Pelji ven«** z odštevanjem do naslednje luže; pri uničevanju gumb **»Pospravi in daj igračo«**. Plačljiva pasma pokaže video luže / grizenja, brezplačni mešanček ikono.
- **Zaklenjen zaslon:** hard stop / bolezen / game over z različnimi sporočili.
- **Pred rojstvom:** dokler pogodba ni podpisana, aplikacija pokaže pogodbo (ne HUD-a); strežnik vse druge akcije zavrne z razlogom `contract_required`. Po podpisu se pes rodi s 100 % in HUD se odklene.

## 9. Starševska aplikacija (UI)

- Čist, analitičen slog (kot bančna / fitnes aplikacija), svetla tema.
- **Semafor:** zelena = redno; rumena = danes zamujeni > 2 rutini; rdeča = kritično, opozorila ignorirana.
- 4 žive metrike (Reverb), časovnica aktivnosti ("✓ 07:15 Pes nahranjen", "✗ 14:00 Zamujeno čiščenje"), tedenski stolpčni graf.
- Nastavitve: tihe ure, hard stop (rdeč gumb s potrditvijo), izbira in nakup pasme.

**Semafor po pravilih (David, 4. 10. 2026; strežnik M2-06):** velja za **vsakega otroka in vsakega psa** posebej, za **današnji dan po času družine**.
- **Rdeča**, če velja karkoli od: pes je odvzet (game over); je aktiven alarm faze 3 (metrika kaže 0 % več kot 1 uro); pes je **danes** zbolel.
- Sicer **rumena**, če so bile danes zamujene **več kot 2 rutini** (rutine: §11) — šteje rutina današnjega dne ali rutina, katere **rok je potekel danes** (nered ob 21:50 z rokom 07:50 šteje v naslednji dan); rok točno ob polnoči pripada prejšnjemu dnevu. *(Claude, čaka Davida)*
- Sicer **zelena**.
- Pri skupnem psu (*Claudova razlaga, čaka Davida*): vsaka zamujena rutina šteje **vsem otrokom, ki so takrat skrbeli za psa** — brat ali sestra se ne more skriti za drugega. Rdeči razlogi psa veljajo za vse njegove skrbnike.
- Pes, ki čaka na pogodbo, in otrok brez psa sta zelena.
- Bolezen, ki se je začela včeraj, danes ne obarva rdeče (pravilo "danes zbolel"); pes je seveda še vedno prikazan kot bolan.

**Kaj starš vidi po otroku (strežnik + zasloni v aplikaciji M2-05, 4. 10. 2026):** semafor z razlogi, Care Score (§11), današnje rutine (pričakovane, opravljene, zamujene s tipom in uro, še odprte), zadnjih 7 dni (rutine, koraki tega otroka, cilj sprehoda, dosežen), napredek 12-tedenskega izziva ("teden N od 12", dnevi od podpisa pogodbe). Po psu: semafor, metrike, Care Score psa, današnje rutine, zadnjih 20 dejanj z vzdevkom otroka, ki jih je naredil. Podrobno poročilo otroka za 7, 30 ali 84 dni.

## 10. AI mediji

- **Pet DNA:** seed + prompt anchor + vizualne lastnosti + referenčna slika → vsak pes je vizualno konsistenten.
- Referenčna slika: **Nano Banana Pro** (David, 5. 10. 2026; 9:16); videi stanj: **Kling 3.0 Pro** (image-to-video iz referenčne slike, **5 s, brez zvoka**, statična kamera, subtilno realistično gibanje, isti pes, brez ljudi in besedila). Kling 3.0 Pro nima nastavitve ločljivosti: video je v izvorni ločljivosti modela, razmerje 9:16 sledi sliki. Modela se lahko zamenjata v nastavitvah.
- **Referenčna slika se ustvari ob paritvi, videi stanj šele ob rojstvu** (prva podpisana pogodba; *odločitev Claude, čaka Davida*) — pes, ki se nikoli ne rodi, stane samo sliko. Drugi skrbnik s svojo pogodbo ne sproži novih videov. Pes dobi videe stanj, do katerih je upravičen (*predlog Claude, čaka Davida*): **brezplačni mešanček** = slika + 2 videa (`idle` miruje, `sleeping` spi); **plačljiva pasma** (Border Collie / izziv) = slika + vseh 6 (`idle, sleeping, low_energy, hungry, sick, playing`). Za stanje brez videa aplikacija predvaja `idle`. Aplikacija preklaplja med videi lokalno, brez novega generiranja. Več videov za mešančka = žetoni (M4-09, *načrt*).
- **Kuža raste (M5-R01, strežnik):** ob prehodu v novo življenjsko fazo se ustvari nova referenčna slika **istega psa** (image-to-image iz prejšnje slike — Nano Banana Pro Edit — ohranjene barve, oznake, oči, ušesa), nato videi stanj po upravičenosti. Prejšnje slike ostanejo shranjene (album rasti pozneje). Prompt vsebuje fazo (mladiček: razmerja mladička, puhasta dlaka, velike tace; starejši: siv gobček) in izvor (posvojen: zdrav, miren, nevtralen — brez klišejev "žalostnega psa iz zavetišča"); nikoli imen. Strošek prehoda: 0,15 $ + videi (mešanček 2 × 0,56 $ → ≈ 1,27 $; plačljiva pasma ≈ 3,51 $); 12-tedenski izziv z mladičkom ima en prehod.
- **Videi vedenja (M5-R02, David 6. 10. 2026):** plačljiv nabor dobi še `accident` (luža) in `chewing` (grize copat) — ustvarita se enkrat na življenjsko fazo iz slike faze kot ostali videi (ne ob vsakem dogodku) in ob prehodu faze znova. *Claude, čaka Davida:* `accident` samo za ne-legacy psa v fazi mladiček, `chewing` za vsakega ne-legacy psa; legacy psi teh videov ne dobijo. Brezplačni mešanček ostane pri 2 videih (aplikacija pokaže ikono). `pet_state` ostane šest stanj; aplikacija izbere video vedenja iz `behaviour.scene`.
- **Ocenjen strošek na psa:** ≈ **1,27 $** osnovno (0,15 $ slika + 2 × 0,56 $), ≈ **3,51 $** polno (0,15 $ + 6 × 0,56 $); mladiček plačljive pasme od M5-R02 ≈ **4,63 $** (0,15 $ + 8 × 0,56 $), mlad / odrasel / starejši ≈ 4,07 $ (7 videov).
- **Hramba:** vsaka slika in video se prenese na naš strežnik; aplikacija dobi le naše podpisane povezave z rokom (60–90 min), nikoli povezav zunanje storitve. Dokler mediji niso shranjeni, jih ni (pes je prikazan brez slike / videa, igra teče normalno).
- **Unikaten videz (DNA v2, 4. 10. 2026):** vsak nov pes dobi naključno, a ponovljivo kombinacijo lastnosti znotraj možnosti pasme (velikost, postava, dolžina in barva dlake, vzorec, oznake, ušesa, oči, rep). V isti družini dva psa iste pasme nimata enake kombinacije. Prompt = pasma + opis lastnosti + slog fotografije (fotorealistično, cel pes, dnevna svetloba, preprosto domače ozadje, brez ljudi, otrok, besedila). Nikoli imena ali drugih osebnih podatkov. Psi, rojeni prej, obdržijo svoj videz. *Možnosti videza po pasmah so osnutek in čakajo preverjene vire (M1-19).*
- **Strošek in izpad:** vsak klic AI ima ocenjen strošek; dnevna in mesečna meja porabe (privzeto 5 $ / 50 $). Če je meja dosežena ali je račun pri fal.ai prazen, slika/video ni ustvarjen, **igra pa teče normalno** (pes brez slike). Osnovni mediji ob rojstvu so brezplačni za družino; več prek žetonov (M4-09, *načrt*).
- **Izbira modelov:** David je 5. 10. 2026 izbral Nano Banana Pro + Kling 3.0 Pro; AI Lab (admin) ostaja za primerjavo novih modelov.

## 11. Ocenjevanje in certifikat

- **7. dan:** "Puppy Promoter" značka staršu (prvi dokaz vrednosti, točka aktivacije garancije).
- **12. teden:** Certifikat odgovornosti s "Care Score" (delež pravočasno opravljenih rutin), brez game overa.

### 11.1 Rutine (David, 4. 10. 2026; strežnik M2-06)
Rutina je ena obveznost, ki jo otrok pravočasno opravi ali zamudi. Vse po **lokalnem času družine**; iz podatkov, ki jih že imamo (od otroka ne zahtevamo ničesar novega).

| Rutina | Koliko | Opravljena | Zamujena |
|---|---|---|---|
| **Hrana** | eno okno hranjenja tistega dne = ena rutina (okna po starosti psa, M5-R01: mladiček 4 / 3 / 2, sicer 2) | hranjenje znotraj okna | okno se konča brez hranjenja |
| **Voda** | `water_times_per_day` (3) na lokalni dan | vsako dolivanje tega dne (po vrsti, do pričakovanega števila) | vsako manjkajoče dolivanje ob koncu dneva |
| **Čiščenje** | vsak nered = ena rutina: "kakec", **luža mladička** in **uničevanje** (M5-R02, David 6. 10. 2026; vrsta je zapisana pri rutini) | počiščeno (luža, kakec) oz. pospravljeno z igračo (uničevanje) v **2 urah, šteto samo izven tihih ur** | ni rešeno v tem času |
| **Sprehod** | dnevni cilj korakov (po starosti psa tisti dan) | koraki dneva ≥ cilj | dan se konča pod ciljem |
| **Šolanje** (M5-R03, David 6. 10. 2026) | ena vaja na lokalni dan — samo pes s šolanjem (nov pes iz aplikacije, ki šolanje zna; nikoli legacy) | dokončana vaja (mini-igra) tega dne; šteje otroku, ki je vadil | dan se konča brez dokončane vaje (→ napredek ukazov upade, §5) |

Rutina se **ne pričakuje** (ne šteje ne kot opravljena ne kot zamujena):
- pred rojstvom psa (nerojen pes nima rutin); na **rojstni dan** samo rutine po rojstvu (okno hranjenja, ki se je začelo pred rojstvom, ne šteje; sprehoda na rojstni dan ni — energija 100 %);
- **okno hranjenja v celoti znotraj tihih ur** (npr. šola 6–10) — obrok opravi starš (David 5. 10. 2026, §5); okno, ki je le deloma v tihih urah (zjutraj 6–10 ob šoli 8–13), se pričakuje, ker ostaneta 2 prosti uri;
- med **hard stopom, boleznijo (veterinar) ali po game overju** — rutina, katere čas se s takim obdobjem prekriva, se ne pričakuje, **razen če jo je otrok vseeno opravil** (takrat šteje kot opravljena). Voda se namesto tega preračuna: 3 × (prosti netihi čas dneva, ko je bil pes v igri) / (ves netihi čas dneva), zaokroženo (npr. rojen ob 18:00 pri tihih urah 22–6 in šoli 8–13 → 4 od 11 ur → 1 voda; hard stop 3 ure → 8 od 11 ur → 2 vodi);
- sprehod za pretekli dan, za katerega strežnik nima nobenega zapisa korakov (izpad strežnika — v dvomu v korist otroka).
- **Sprehod (celodnevna rutina)** se zaradi hard stopa / veterinarja / game overja ne pričakuje le, če je pes vsaj **50 % netihega časa dneva** izven igre; kratek ali nočni hard stop sprehoda ne opraviči. *(Claude, čaka Davida)* Enako velja za **šolanje** (M5-R03), ki se tudi na rojstni dan ne pričakuje.
- "Kakec", luža ali uničevanje, ki bi padel v hard stop, bolezen ali po game overju, se ne zgodi in ni rutina (M5-R02).

Ob prestopu ure: dan ima 23 ali 25 ur, okna sledijo stenski uri (06:00 je poleti 04:00 UTC, pozimi 05:00 UTC).
Zaključeni dnevi se zapišejo (strežnik) in se pozneje ne preračunajo: sprememba tihih ur ali oken hranjenja ne spremeni preteklih ocen. Današnji dan se računa sproti; rutina z rokom v prihodnosti je "odprta" in se v oceno še ne šteje. Ledger se začne 4. 10. 2026 (prej vsi podatki niso bili na strežniku).

### 11.2 Care Score (0–100) (David, 4. 10. 2026)
**Care Score = (opravljene rutine / pričakovane rutine) × 100 − 10 za vsako bolezen**, omejeno na 0–100, zaokroženo na celo število. Brez pričakovanih rutin ocene še ni (prazno).

**Po otroku — "pošten delež" (skupni pes; David, 4. 10. 2026):**
- vsaka rutina psa šteje **1 / n** vsakemu od **n otrok, ki so takrat skrbeli za psa** (od podpisa svoje pogodbe; pri starih psih brez pogodbe od rojstva);
- **opravljene** = rutine, ki jih je opravil **ta otrok** (zapisano pod njegovim imenom);
- ocena otroka = min(100, opravljene / pošteni delež × 100) − 10 za vsako bolezen psa, odkar otrok skrbi zanj; omejeno 0–100;
- **sprehod** (*Claudova razlaga "poštenega deleža", čaka Davida*): šteje otroku kot opravljen, če je pes dosegel cilj **in** je ta otrok prehodil vsaj **cilj / n** korakov (npr. 2 otroka, cilj 4.000 → vsak vsaj 2.000);
- otrok sam s psom: enaka formula z n = 1 (= formula psa).
- Primer: dva otroka, eden opravi vse → 100 in 0. Otrok, ki se pridruži kasneje, deli samo rutine, ki se začnejo po njegovi pogodbi.

- **Bolezni** se odštevajo od prvega dne ocenjevanja (4. 10. 2026) naprej, pri otroku od začetka njegovega skrbništva. *(Claude, čaka Davida)*
- **Dan pridružitve:** otrok, ki se pridruži sredi dneva, ta dan ne deli vode in sprehoda (rutini se začneta ob polnoči), deli pa okna hranjenja in nered po podpisu.

**Napredek izziva:** teden N od 12 (dnevi od podpisa pogodbe otroka / 7 + 1), izziv končan po 84 dneh.

## 12. Faza 2 (po MVP) — Real-World AI asistent

Gumb "Kupili smo pravo žival" → aplikacija postane asistent za pravega psa: IoT ovratnice (Tractive), AI veterinarski triage (vision), rast in prehrana, AI inštruktor (3,99 €/mes), affiliate trgovina, zavarovanja, booking veterinarjev in pasjih šol. Tehnično: LLM + RAG (pgvector). Cilj: LTV iz 3 mesecev na 10–15 let. **Podrobna specifikacija: [`PHASE2_SPEC.md`](PHASE2_SPEC.md)** (David, 4. 10. 2026).
