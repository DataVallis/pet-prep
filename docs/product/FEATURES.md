# PetPrep — Katalog funkcij (kaj izdelek zna danes)

> **Stanje na dan 2026-10-07.**
> **Namen:** en pregled vsega, kar PetPrep zna (in česa še ne), po področjih. Vir za spletno stran (petprep.si), opise v App Store / Google Play, vodiče za starše in otroke, gradiva za investitorje in partnerje.
> **Pravila:** samo dejstva iz `docs/journey/BUILD_LOG.md`, `docs/engineering/ROADMAP.md`, `docs/product/PRODUCT_SPEC.md`, `HANDOFF.md`, `docs/business/BUSINESS_MODEL.md` in kode · številke samo z virom · brez osebnih podatkov otrok · česar ni na telefonu, ne opisujemo, kot da je · kar čaka Davidovo potrditev, je označeno *predlog*.
> **Vzdrževanje:** posodobi **v istem PR kot funkcijo** — dodaj vrstico, spremeni stanje, poveži roadmap ID (in datum). Ko David funkcijo preveri na telefonu, dodaj 📱 z datumom in buildom.

## Legenda

**Stanje**

| Oznaka | Pomen |
|---|---|
| ✅ **V aplikaciji** | zgrajeno, v `main`, na strežniku (`api.petprep.si`) oz. v kodi aplikacije. **Aplikacija še ni v trgovinah**; zadnji TestFlight (1.24.4) je starejši od večine funkcij iz 6. in 7. 10. |
| 📱 **Preverjeno na telefonu** | David je to videl na pravem telefonu (TestFlight / ročni preizkus), kot piše v `HANDOFF.md` ali `BUILD_LOG.md` |
| 🛠 **Samo strežnik** | strežnik zna, aplikacija tega še ne prikaže |
| 🗓 **Načrt** | načrtovano v roadmapu (ID v stolpcu) |
| ⏸ **Po MVP** | backlog, ne gradimo pred lansiranjem |

**Cena**

| Oznaka | Pomen |
|---|---|
| 🆓 | brezplačno — mešanček (za vedno, odločitev 2. 10. 2026) |
| 💶 | plačljivo — 12-tedenski izziv / plačljiva pasma (Border Collie). **Plačila še niso zgrajena (RevenueCat odprt, M3-07 – M3-11) — danes ni mogoče ničesar kupiti.** |

Razmejitev brezplačno / plačljivo v `BUSINESS_MODEL.md` §7 je **predlog**, ki ga David še potrjuje. Potrjeno je samo: mešanček brezplačen, Border Collie plačljiv, brezplačni mešanček nima videa »bolan« (David, 6. 10. 2026).

**Kaj je bilo res na telefonu (do 7. 10. 2026):** otroški glavni zaslon (David, 3. 10.), prvi TestFlight 5. 10. (glavni zaslon z merilniki, okno sprehoda, povezava v živo — vse tri z napakami, popravljene na `main`), TestFlight 1.24.4 6. 10. (AI video kužka, zaklep »pri veterinarju« — z napako zanke obvestil, popravljena na `main`). **Nič iz vedenja (M5-R02), šolanja (M5-R03 / R03b) in nove podobe (CGP v2) še ni bilo na telefonu** — potreben je nov native build (`HANDOFF.md` §1, §5).

---

## 1. Družina in računi

| Funkcija | Kaj naredi | Za koga | Stanje | Roadmap ID | Od kdaj |
|---|---|---|---|---|---|
| Registracija starša | »Sem starš« → »Registracija«: ime, e-pošta, geslo (≥ 10 znakov, velike/male črke, številka), obvezno strinjanje s pogoji. Takoj prijavljen, nastane družina v časovnem pasu telefona. | starš | ✅ | M2-10a | 2026-10-05 |
| Ostaneš prijavljen | Ob ponovnem odprtju aplikacija takoj pokaže kužka oz. nadzorno ploščo. Brez interneta ni odjave, le »Poskusi znova«. | starš, otrok | ✅ | M1-12 | 2026-10-03 |
| Otroški profil brez e-pošte | Starš vpiše samo vzdevek (do 30 znakov) in po želji letnico rojstva. Nič drugega o otroku ne vprašamo. | starš | ✅ | M2-02 | 2026-10-04 |
| Prijava otroka s kodo (PIN) | Otrok tapne »Sem otrok« in vtipka 6-mestno kodo od staršev na veliko tipkovnico. Koda velja 15 min in samo enkrat; napačna, potekla in porabljena koda dobijo enak odgovor. | otrok, starš | ✅ | M2-02 | 2026-10-04 |
| Nova naprava, odjava povsod | »Nova koda za prijavo« prijavi otroka na novem telefonu (isti kuža), največ 3 naprave. »Odjavi vse naprave« pri izgubljenem telefonu. | starš | ✅ | M2-02 | 2026-10-04 |
| Več otrok, svoj ali skupni pes | Vsak otrok ima svojega psa ali več otrok skrbi za enega. Vsako dejanje se zapiše otroku, ki ga je naredil. Otrok ima največ enega aktivnega psa. | starš, otrok | ✅ | M2-01, M2-01a | 2026-10-04 |
| Povabilo drugega starša | Koda (8 znakov, 24 ur, enkratna), deli se prek telefona; drugi starš jo vnese v »Imate kodo družine?«. Oba vidita vse in oba lahko ustavita igro. | starš | ✅ | M2-01a | 2026-10-04 |
| Pogodba o odgovornosti = rojstvo | Otrok se s prstom podpiše pod obljubo; šele takrat se kuža »rodi« (vse 100 %). Do podpisa se nič ne zgodi; starš vidi »čaka na podpis pogodbe«. | otrok | ✅ | M1-07b | 2026-10-04 |
| Pridružitev skupnemu psu | Otrok, ki se pridruži psu brata ali sestre, podpiše svojo pogodbo; pes se ne rodi znova. | otrok | ✅ | M2-01 | 2026-10-04 |
| Izvoz podatkov | »Nadzor → Račun → Izvozi moje podatke«: ena datoteka JSON z vsem o družini (brez gesel in kod), največ 3× na uro. | starš | ✅ | M2-08 | 2026-10-05 |
| Izbris računa / profila otroka | Takoj in nepovratno, z geslom in besedo »IZBRIŠI«. Zadnji starš izbriše celo družino; skupni pes ostane drugim otrokom. | starš | ✅ | M2-08 | 2026-10-05 |
| Sprememba časovnega pasu družine | Npr. na počitnicah v tujini. V aplikaciji se pas nastavi samo ob registraciji. | starš | 🛠 | M1-03 | 2026-10-03 |
| Potrditev e-pošte, pozabljeno geslo | — | starš | 🗓 | M2-10b | — |
| Prijava z Apple / Google | — | starš | 🗓 | M2-10c | — |
| Nov pes po game overu, odhod skrbnika, združevanje družin | Pravila še niso določena. | starš | 🗓 (čaka Davida) | M2-01, M2-07 | — |

## 2. Izbira kužka in profil

| Funkcija | Kaj naredi | Za koga | Stanje | Roadmap ID | Od kdaj |
|---|---|---|---|---|---|
| Zaslon »Izberi kužka« | Pri »Nov pes« starš izbere pasmo, izvor in starost ob prihodu, šele nato dobi kodo. Pred povezavo otroka lahko izbiro spremeni (»Spremeni kužka«). | starš | ✅ | M5-R04 (del 1) | 2026-10-05 |
| Pasma | Mešanček 🆓. Border Collie 💶 je viden, a zaklenjen (zaklep velja tudi na strežniku). | starš | ✅ (nakup 🗓) | M5-R04, M3-07 | 2026-10-05 |
| Izvor: kupljen / posvojen | Pokaže se na profilu psa; posvojen pes je na sliki zdrav in miren, brez »žalostnih« klišejev. Vpliv izvora na vedenje (plašnost, nered) še ni v igri. | starš | ✅ (vedenje 🗓, čaka Davida) | M5-R01 | 2026-10-05 |
| Starost ob prihodu | Mladiček (2 meseca), mlad pes (9 mesecev), odrasel (3 leta), starejši (9 let; Border Collie 9,8). Ob vsaki ena poštena vrstica s številkami (obroki, koraki). Brez privzete izbire. | starš | ✅ | M5-R01, M5-R04 | 2026-10-05 |
| Kuža se stara | 1 teden igre = 1 mesec življenja; obdobja mladiček → mlad pes → odrasel → starejši (meje iz virov, potrdil David). Otrok in starš vidita npr. »Mladiček · 3 mesece« in kdaj pride naslednje obdobje. | otrok, starš | ✅ | M5-R01, M5-R04 | 2026-10-05 |
| Potrebe po starosti | Mladiček 4 → 3 → 2 obroka na dan v 2-urnih oknih; cilj korakov = minute gibanja × 100 (odrasel mešanček 6.000, odrasel Border Collie 12.000, mladiček od 2.000 navzgor, starejši 75 %). | otrok | ✅ | M5-R01, M5-R01b | 2026-10-05 |
| Obrok med šolo / spanjem nahrani starš | Okno, ki je v celoti v tihih urah, strežnik opravi samodejno in otroku ne šteje. Starš vidi današnje obroke z oznako »nahrani starš«; otrok vidi isto oznako v vrstici »Obroki danes«. | starš, otrok | ✅ | M5-R01, M5-R04 (del 2) | 2026-10-05 (otrok 2026-10-07) |
| Psi iz časa pred izbiro kužka | Ostanejo na starih pravilih za vedno; v glavi zaslona kažejo »Teden N od 12«. | otrok | ✅ | — (PR #60) | 2026-10-07 |

## 3. Nega in pravila igre

| Funkcija | Kaj naredi | Za koga | Stanje | Roadmap ID | Od kdaj |
|---|---|---|---|---|---|
| Glavni zaslon s 4 merilniki | Lakota, žeja, energija (sprehod), čistoča; barva zelena → rumena → rdeča. Merilniki se prilagodijo velikosti zaslona. Gumb, ki je na vrsti, sveti v mint barvi (nova podoba). | otrok | ✅ · 📱 (3. 10. in TestFlight 5. 10.; popravek merilnikov iz 5. 10. še ni preverjen) | M1-14, M0-14 | 2026-10-04 |
| Hrana samo v oknih | Enkrat v vsakem oknu (odrasel pes 6–10 in 17–21 po času družine). Izven okna je gumb zasenčen in pove, kdaj bo spet čas. | otrok | ✅ | M1-07, M1-14 | 2026-10-04 |
| Obroki danes | Nad gumbi za nego mirna vrstica današnjih oken po času družine, npr. »7–9 ✓ · 11–13 nahrani starš · 15–17 · 19–21«: ✓ pri vsakem oknu, v katerem je bil danes zabeležen obrok (otrokov ali starševski v tihih urah; strežnik to pošlje za vsako okno), trenutno okno poudarjeno, okna v tihih urah z oznako »nahrani starš«, minula okna brez obroka samo zatemnjena (nikoli »zamujeno«). Takoj po hranjenju se ✓ pokaže že pred osvežitvijo stanja. Samo psi z izbiro kužka (pes brez profila ostane kot prej). | otrok | ✅ | M5-R04 (del 2) | 2026-10-07 |
| Voda | 3× na dan, vsaj 3 ure narazen (tudi čez polnoč); pod gumbom piše, kdaj spet. | otrok | ✅ | M1-07, M1-14 | 2026-10-04 |
| Čiščenje | Kuža naključno naredi nered (mešanček 1×, Border Collie 2× na dan), nikoli v tihih urah. Otrok ga zdrgne s prstom. Dokler ni čisto, hrane in vode ni. | otrok | ✅ | M1-05, M1-14 | 2026-10-04 |
| Sprehod s pravimi koraki | Energija = današnji koraki / cilj, ob polnoči (po času družine) znova 0 %. iPhone pošlje vse današnje korake s senzorja gibanja, Android šteje samo, ko je aplikacija odprta. Brez GPS. | otrok | ✅ · 📱 okno sprehoda videno na TestFlightu 5. 10. (z napako oblike; popravek še ni preverjen) | M1-04, M1-14 | 2026-10-04 |
| Pošteno štetje korakov | Največ 200 korakov na minuto; tresenje telefona ne prinese več kot hoja. | otrok | ✅ | M1-04 | 2026-10-03 |
| Skupni sprehod | Pri skupnem psu se koraki vseh otrok seštejejo; vsak vidi svoj del. | otrok | ✅ | M2-01 | 2026-10-04 |
| Tihe ure | Starš nastavi šolo in spanje; kuža takrat skoraj ne upada, ne dela nereda in ne pošilja obvestil. | starš | ✅ | M1-03 | 2026-10-03 |
| Časovni pas družine | Vsa pravila po uri v kuhinji, tudi ob premiku ure (25. 10. 2026, 28. 3. 2027 preverjeno s testi). | — | ✅ | M1-03 | 2026-10-03 |
| Hard stop | Starš za vsakega psa posebej ustavi igro (s potrditvijo); otrokov zaslon se v živo zaklene, nič se ne poslabša. | starš | ✅ | M1-02, M2-05 | 2026-10-04 |
| Bolezen in veterinar | Umazan 6 ur (izven tihih ur) ali dan brez enega koraka → kuža 12 ur »pri veterinarju«; otrok ga vidi skozi sivo plast in ve, do kdaj. | otrok | ✅ · 📱 zaklep videl David na TestFlightu 1.24.4 (6. 10.; z napako časa — popravljeno na `main`, ne preverjeno) | M1-02, M1-16 | 2026-10-03 |
| Ozdravitev = nov začetek | Kuža se vrne čist, ure zanemarjanja tečejo znova; lakota in žeja ostaneta. | otrok | ✅ | M1-04 | 2026-10-03 |
| Game over (»zavetišče«) | Hrana, voda ali čistoča 24 ur na 0 % → psa odpelje zavetišče, zaslon zaklenjen. | otrok, starš | ✅ | M1-02, M1-16 | 2026-10-04 |
| Ponovni začetek po game overu (Breed Downgrade) | Brezplačen začetek z lažjo pasmo. | starš | 🗓 | M2-07 | — |
| Pravila v nastavitvah, ne v kodi | Hitrosti, okna, cilji, število neredov se urejajo v administraciji brez nove verzije aplikacije. | admin | ✅ | M1-06, M5-R01 | 2026-10-03 |

## 4. Vedenje (kot pri pravem psu)

Velja samo za nove pse, ustvarjene z različico aplikacije, ki vedenje zna prikazati. **Ni še bilo na telefonu.**

| Funkcija | Kaj naredi | Za koga | Stanje | Roadmap ID | Od kdaj |
|---|---|---|---|---|---|
| Mladiček mora ven — »Pelji ven« | Mladiček zdrži ~1 uro na mesec starosti (vir: Ryan Veterinary Hospital, WebMD). Mirno odštevanje nad gumbi; ura teče samo izven tihih ur. Če pozabi, nastane luža, ki jo otrok pobriše v igri čiščenja. | otrok | ✅ | M5-R02 | 2026-10-06 |
| Pregrizen copat — »Pospravi in daj igračo« | Pes, ki včeraj ni dosegel cilja sprehoda, danes nekaj zgrize; mladiček med menjavo zob (3–6 mesecev) občasno tudi sam (pogostost ~vsak drugi dan je *predlog*). | otrok | ✅ | M5-R02 | 2026-10-06 |
| Prikaz luže in copata | 🆓 risba v aplikaciji · 💶 AI video (enkrat na življenjsko obdobje, ne ob vsakem dogodku). | otrok | ✅ | M5-R02 | 2026-10-06 |
| Starš vidi nerede | Do kdaj mora mladiček ven, odprti neredi z rokom, kolikokrat ga je otrok v 7 dneh peljal ven, vrsta zamujenega čiščenja, vnosi v časovnici. | starš | ✅ | M5-R02 | 2026-10-06 |
| Nered in plašnost posvojenega odraslega psa | — | otrok | 🗓 (čaka Davida) | — | — |

## 5. Šolanje (dresura)

Samo za nove pse iz različice aplikacije, ki šolanje zna. **Ni še bilo na telefonu.**

| Funkcija | Kaj naredi | Za koga | Stanje | Roadmap ID | Od kdaj |
|---|---|---|---|---|---|
| »Šola« — mini-igra »Pohvali ob pravem trenutku« | 50-sekundna vaja, 8 ukazov; ko kuža uboga, ima otrok 1,5 s za »Pohvali«. Takojšen prijazen odziv, aplikacija nikoli ne graja. Urnik izbere in pritiske oceni strežnik. | otrok | ✅ | M5-R03 | 2026-10-06 |
| 4 ukazi | Sedi, pridi, prostor, lulat zunaj; napredek 0–100 %, pri 100 % »Kuža zna ukaz«. | otrok | ✅ | M5-R03 | 2026-10-06 |
| Učljivost | Border Collie 💶 se uči 2× hitreje (Coren); vsak mešanček 🆓 ±20 % (enkrat izžrebano). Odločitev PetPrep na podlagi virov. | otrok | ✅ | M5-R03 | 2026-10-06 |
| Dnevni čas in pošten delež | Največ 5 minut vaje na psa na dan; pri več otrocih enako razdeljeno (2 otroka po 3 vaje), vsak vsaj eno vajo. Otrok vidi, koliko vaj mu še ostane. | otrok | ✅ | M5-R03b | 2026-10-07 |
| Starejši pes že nekaj zna | Mlad, odrasel ali starejši pes ob prihodu zna sedi 50 %, lulat zunaj 70 %, pridi 30 %, prostor 0 %; mladiček 0 %. (Vira ni — številke postavil PetPrep, potrdil David.) | otrok, starš | ✅ | M5-R03b | 2026-10-07 |
| Pozabljanje | Dan brez vaje → −2 % pri vsakem ukazu (številka PetPrep, potrdil David). | otrok | ✅ | M5-R03 | 2026-10-06 |
| Učinki šolanja | »Lulat zunaj«: mladiček prosi, da gre ven (pri 100 % ~¾ manj luž, VCA); »prostor«: do pol manj grizenja med menjavo zob (ASPCA). Velikost učinka določil PetPrep (potrjeno 7. 10.). | otrok | ✅ | M5-R03 | 2026-10-07 |
| Šolanje v oceni | Ena vaja na dan je rutina v Care Score. | starš | ✅ | M5-R03 | 2026-10-06 |
| Starš vidi znanje | »Kuža zna: sedi ✓, pridi 60 % …«, ali je bila vaja danes, vnos v časovnici. | starš | ✅ | M5-R03 | 2026-10-06 |
| Sprotno razkrivanje ukazov (proti goljufanju) | — | — | ⏸ | backlog | — |

## 6. AI kuža

| Funkcija | Kaj naredi | Za koga | Stanje | Roadmap ID | Od kdaj |
|---|---|---|---|---|---|
| Unikaten videz (»DNK« psa) | Vsak pes izžreba svojo kombinacijo lastnosti znotraj pasme (velikost, dlaka, lise, ušesa, oči, rep); v isti družini dva psa iste pasme nikoli nista enaka. Opis za AI nikoli ne vsebuje podatkov o otroku. | otrok | ✅ | M4-08 | 2026-10-04 |
| Fotografija kužka | Ob povezavi otroka AI (Nano Banana Pro) naredi fotorealistično sliko 9:16. | otrok, starš | ✅ | M4-01, M4-02 | 2026-10-05 |
| Videi stanj | Ob rojstvu 5-sekundni videi brez zvoka (Kling 3.0 Pro), vedno isti pes. 🆓 mešanček: miruje, spi · 💶 plačljiva pasma: vseh 6 (tudi lačen, utrujen, bolan, igriv). Razdelitev je delno *predlog*. | otrok | ✅ · 📱 video na glavnem zaslonu videl David (TestFlight, 6. 10.) | M4-03 | 2026-10-05 |
| Video sledi stanju | Kuža spi ponoči in v tihih urah, mehak prehod med videi; brez videa za stanje pokaže najbližjega (bolan → spi → miruje), nato sliko — otrok nikoli ne vidi napake. Varčno z baterijo. | otrok | ✅ | M4-03 | 2026-10-05 |
| Album »Moj kuža« | Fotografija in vsi videi kužka čez cel zaslon; manjkajoči »Še ni posnetka«; ne kaže, česa pes nima (nič ne vabi k nakupu). Starš ga odpre iz podrobnosti otroka. | otrok, starš | ✅ | M4-03b | 2026-10-05 |
| Kuža raste na sliki | Ob novem življenjskem obdobju AI iz prejšnje slike naredi novo — isti pes, starejši; stare slike ostanejo shranjene. | otrok | 🛠 | M5-R01 | 2026-10-05 |
| Album rasti | Vse slike psa skozi obdobja, od najstarejše do zdajšnje: obdobje (mladiček / mlad / odrasel / starejši), starost psa ob sliki v mesecih, datum. Vidijo ga otroci-skrbniki in vsi starši družine (podpisane povezave z rokom, kot ostali mediji); stare slike so tudi v izvozu podatkov in se izbrišejo s psom. V aplikaciji je to razdelek »Kako je kuža rasel« na vrhu albuma »Moj kuža« (otrok iz glave glavnega zaslona, starš samo za ogled iz podrobnosti otroka): vodoravni trak slik z oznako obdobja (Mladiček / Mlad pes / Odrasel / Starejši), starostjo (npr. »2 meseca«) in datumom po času družine; zdajšnja slika ima oznako »Zdaj«. Dotik odpre sliko čez cel zaslon, puščici / poteg menjata med slikami rasti. Razdelek se pokaže šele, ko sta vsaj 2 sliki; slike se naložijo šele ob odprtju albuma, ob napaki razdelka preprosto ni. | otrok, starš | ✅ (aplikacija) | M5-R04 (del 2) | 2026-10-07 |
| Mediji shranjeni pri nas (EU) | Strežnik slike in videe prenese z AI storitve, preveri in shrani; aplikacija dobi samo naše povezave z rokom 60–90 min. | — | ✅ | M4-05 | 2026-10-05 |
| Meje stroškov AI | Vsak klic je ocenjen in zapisan; dnevna (5 $) in mesečna (50 $) meja. Ob meji igra teče naprej brez nove slike. | admin | ✅ | M4-07 | 2026-10-04 |
| AI laboratorij | Primerjava modelov (isti pes, več modelov, cena in čas) v administraciji. | admin | ✅ | M4-02 | 2026-10-04 |
| Žetoni za dodatne slike / videe | Starš kupi paket, otrok porabi žeton. | starš, otrok | 🗓 | M4-09 | — |
| Rezervni statični videi na pasmo | — | — | 🗓 | M4-06 | — |

## 7. Ocena in pregled za starše

| Funkcija | Kaj naredi | Za koga | Stanje | Roadmap ID | Od kdaj |
|---|---|---|---|---|---|
| Rutine | Vsak dan: vsako okno hranjenja, 3 vode, vsak nered (rešiti v 2 urah izven tihih ur), sprehod, vaja v šoli. Opravljena ali zamujena — nič vmes. Čas hard stopa, veterinarja in tihih ur se ne šteje proti otroku. | starš | ✅ | M2-06 | 2026-10-04 |
| Care Score (0–100) | Opravljene / pričakovane rutine × 100, −10 za vsako bolezen. Prikaz »31 od 36 rutin«; zaključeni dnevi se ne spreminjajo. | starš | ✅ | M2-06 | 2026-10-04 |
| Pošten delež | Pri skupnem psu vsak otrok nosi svoj del rutin, šteje samo, kar je naredil sam (eden naredi vse → 100 in 0). | starš | ✅ | M2-01c | 2026-10-04 |
| Semafor z razlogi | Za vsakega otroka in psa: rdeča (zbolel danes, alarm, zavetišče), rumena (> 2 zamujeni rutini danes), zelena. Razlog z besedami. | starš | ✅ | M2-06 | 2026-10-04 |
| Nadzorna plošča | Kartica na otroka: semafor, Care Score, »Teden N od 12«, današnje rutine (zamujene z uro), zadnjih 7 dni, stanje in slika psa, obdobje in starost psa. Svetla, mirna tema. | starš | ✅ | M2-05, M5-R04 | 2026-10-04 |
| Poročilo 7 / 30 / 84 dni | Ocena obdobja, hrana / voda / čiščenje / sprehod posebej, koraki proti cilju po dnevih, bolezni, časovnica z »Naloži več«. | starš | ✅ | M2-05 | 2026-10-04 |
| V živo | Vrednosti se posodobijo v trenutku (zasebni kanal na psa); brez povezave osvežitev vsakih 30 s. | starš | ✅ | M2-05, M1-15 | 2026-10-04 |
| Značka »Puppy Promoter« (7. dan) | — | starš | 🗓 | backlog / M3 | — |
| Certifikat odgovornosti (12. teden) | Certifikat po otroku iz njegovih dejanj; PDF in fizična licenca po pošti kasneje. | otrok, starš | 🗓 (PDF, licenca ⏸) | M2-01d | — |

## 8. Obvestila

**Koda je končana, na telefonu dostava še ni preverjena** (potreben nov build iz `main`, APNs ključ in FCM — ROADMAP M3-02). Na TestFlightu 1.24.4 je prijava naprave za obvestila povzročila zanko; popravljeno na `main`.

| Funkcija | Kaj naredi | Za koga | Stanje | Roadmap ID | Od kdaj |
|---|---|---|---|---|---|
| Opomnik otroku (30 %) in nujno (10 %) | Blag opomnik, nato nujen z zvokom; besedilo po tem, kar manjka (hrana, voda, nered). | otrok | ✅ | M3-02 | 2026-10-05 |
| Alarm staršem | Ko pes več kot uro nima hrane, vode ali čistoče: »Tvoj otrok danes ni poskrbel za psa.« Vsem staršem družine. | starš | ✅ | M3-02 | 2026-10-05 |
| Bolezen in zavetišče | Obvestilo staršem in otroku. | starš, otrok | ✅ | M3-02 | 2026-10-05 |
| Opomnik za sprehod | Največ enkrat na dan, najprej 2 uri po koncu tihih ur, brez groženj (*predlog*). | otrok | ✅ | M3-02 | 2026-10-05 |
| Tihe ure in mir | V tihih urah nič; isto obvestilo največ enkrat na 30 min; novica o bolezni počaka do jutra (*predlog*). | starš, otrok | ✅ | M3-02 | 2026-10-05 |
| Dovoljenje ob pravem trenutku | Otroka vpraša po podpisu pogodbe, starša po dodanem otroku, s slovensko razlago; v »Nadzor → Obvestila« stanje. Tap odpre kužka / podrobnosti otroka. | starš, otrok | ✅ | M3-02 | 2026-10-05 |
| Lokalni opomniki (delujejo brez interneta) | — | otrok | 🗓 | M3-03 | — |

## 9. Zasebnost in varnost otrok

| Funkcija | Kaj naredi | Za koga | Stanje | Roadmap ID | Od kdaj |
|---|---|---|---|---|---|
| Minimalni podatki o otroku | Samo vzdevek in neobvezna letnica; brez e-pošte, gesla, priimka. Ime naprave = samo model telefona. | starš | ✅ | M2-02 | 2026-10-04 |
| Varna koda PIN | Na strežniku samo zgoščena vrednost; enak odgovor za vse napake; omejitev poskusov (15 min premora). | — | ✅ | M2-02 | 2026-10-04 |
| Ločeni vlogi | Otroški telefon ne more odpreti starševskih nastavitev (ločen ključ za starša in otroka). | — | ✅ | M2-03 (delno) | 2026-10-04 |
| Zasebni kanali v živo | Stanje psa vidijo samo njegovi skrbniki in starši družine; sporočila brez imen in e-pošte. | — | ✅ | M1-08 | 2026-10-04 |
| Obvestila brez imen | Naslov vedno »PetPrep«, brez imena otroka ali psa; hranimo samo žeton naprave, platformo in različico aplikacije. | — | ✅ | M3-02 | 2026-10-05 |
| Preverjeni AI videi | Podpis ED25519 na sporočilih AI storitve; na zaslon pride samo vsebina, ki jo je naročil PetPrep. | — | ✅ | M4-04 | 2026-10-02 |
| Strežnik in mediji v EU | Hetzner; slike in videi pri nas, povezave s kratkim rokom. | — | ✅ | M5-01, M4-05 | 2026-10-05 |
| Brez oglasov, klepeta in analitike | Brez oglasov in klepeta s tujci; analitika (PostHog EU) samo z Davidovim soglasjem. | — | ✅ (analitika 🗓) | M5-08 | — |
| Pogoji, politika zasebnosti, privolitev staršev, pregled »Kids« | Strani na petprep.si obstajajo, čakajo pregled pravnika. | starš | 🗓 | M5-06 | — |

## 10. Platforma in kakovost (kratko)

| Funkcija | Kaj naredi | Za koga | Stanje | Roadmap ID | Od kdaj |
|---|---|---|---|---|---|
| Produkcija | `api.petprep.si`, Hetzner (EU), Docker + Caddy, PHP-FPM + OPcache, samodejni TLS. | — | ✅ | M5-01, M5-02, M4-05b | 2026-10-05 |
| Testi in samodejni deploy | Vsak predlog spremembe gre skozi teste; po združitvi v `main` se strežnik posodobi sam (samo zeleno). | — | ✅ | M0-08, M5-03 | 2026-10-06 |
| Varnostna kopija baze | Pred vsakim deployem, 7 dni, isti disk. Dnevna kopija drugam in test obnove še manjkata. | — | ✅ (delno) | M5-04 | 2026-10-03 |
| Odpornost aplikacije | Ob preobremenitvi počaka in sama poskusi znova, obdrži zadnje stanje; »Ups, nekaj se je zataknilo« z »Poskusi znova« namesto zrušitve. | otrok | ✅ | — (PR #45, #48) | 2026-10-06 |
| Nova podoba »Grafit in meta« | Nova ikona, zagonski zaslon, logotip, pisave, mirna starševska in temna otroška tema. Potreben nov native build. | otrok, starš | ✅ | — (CGP v2) | 2026-10-06 |
| Jezik aplikacije (EN / SL) | Angleščina privzeto, slovenščina; izbira na začetnem zaslonu (EN / SL) in v »Nadzor → Jezik«, velja za napravo; sicer jezik telefona. Strežnik dobi jezik z vsako zahtevo (`Accept-Language`). Potreben nov native build. | otrok, starš | ✅ (zasloni še v prevodu) | M1-18 | 2026-10-07 |
| Oznaka različice | Npr. `v1.10.2 · 23cd58a` na začetnem zaslonu in v »Nadzor«. | — | ✅ | — | 2026-10-05 |
| Administracija (Filament) | Nastavitve pasem, podatki obdobij z virom in revizijo, AI mediji (ponovno ustvarjanje), poraba AI, »Delete family«. | admin | ✅ | M1-06, M4-03, M4-07, M5-R01, M2-08 | 2026-10-05 |
| Poročanje o napakah, nadzor delovanja | Sentry, uptime. | — | 🗓 (čaka Davida) | M5-05 | — |

## 11. Spletna stran petprep.si

| Funkcija | Kaj naredi | Za koga | Stanje | Roadmap ID | Od kdaj |
|---|---|---|---|---|---|
| Dvojezična stran (EN, SL) | Domača stran, kako deluje, za starše, po posvojitvi, cenik, pogosta vprašanja, partnerji, vlagatelji, kontakt, zasebnost, pogoji, varnost otrok. Ločen repo in deploy. | starši, partnerji, vlagatelji | ✅ | — | 2026-10-06 |
| Pripravljena za iskalnike in AI asistente | Strukturirani podatki, zemljevid strani, `llms.txt`. | — | ✅ | — | 2026-10-06 |
| Pravna besedila | Strani obstajajo; čakajo pregled pravnika. | starš | 🗓 | M5-06 | — |
| Lead magnet »Pet Promise Reality Check«, kalkulator »Real Cost of a Dog«, waitlist | — | starši | 🗓 / ⏸ | BUSINESS_MODEL §4, backlog | — |

## 12. Poslovni model

**Danes ni mogoče ničesar kupiti.** Zavihek »Pasme« (simuliran nakup brez učinka na strežniku) je od 7. 10. 2026 **skrit**, dokler plačila niso zgrajena (David).

| Funkcija | Kaj naredi | Za koga | Stanje | Roadmap ID | Od kdaj |
|---|---|---|---|---|---|
| Mešanček brezplačen za vedno 🆓 | Vsaka družina lahko preizkusi osnovno skrb brez plačila. | starš | ✅ (odločeno 2026-10-02) | B1a | 2026-10-02 |
| 12-tedenski PetPrep izziv 💶 | **49,99 € na psa**, prvih **7 dni brezplačno**; skupni pes = ena cena, dostop za oba starša. Pes je v bazi že »enota plačila«. | starš | 🗓 (plačila niso zgrajena) | M3-09, M3-11 | — |
| Nakup v aplikaciji (RevenueCat), paywall, preizkus na strežniku | En entitlement `challenge`; non-consumable ali naročnina še odprto (B7). | starš | 🗓 | M3-07 – M3-11 | — |
| Plačljiva pasma (Border Collie) 💶 | Vidna in zaklenjena v izbiri kužka; odklep z nakupom. | starš | ✅ zaklep · 🗓 nakup | M5-R04, M3-07 | 2026-10-05 |
| Second Chance reset (19,99 €) | — | starš | ⏸ | backlog | — |
| Priboljški, veterinar, igrače (IAP), garancija »Real-World Relief«, bonusi | — | starš | ⏸ | backlog | — |
| Partnerski kuponi (B2B) | Izključeno iz MVP. | partnerji | ⏸ | backlog | — |

## 13. Načrtovano in po MVP

| Funkcija | Kaj naredi | Za koga | Stanje | Roadmap ID |
|---|---|---|---|---|
| Koraki iz Apple Zdravje / Health Connect | Koraki tudi pri zaprti aplikaciji (Android danes šteje samo, ko je odprta). | otrok | 🗓 | M3-04, M3-05, M3-06 |
| Angleščina v aplikaciji | EN privzeto + SL. Ogrodje, izbira jezika in prijava / registracija / PIN so prevedeni (2026-10-07, M1-18 del 1); ostali zasloni in strežniška besedila (push) v teku. | vsi | ✅ delno · 🗓 ostalo | M1-18 |
| Plačila | Glej §12. | starš | 🗓 | M3-07 – M3-11 |
| Certifikat odgovornosti | Glej §7. | otrok, starš | 🗓 | M2-01d |
| Zaprta beta (TestFlight + Google Play, 20–50 družin) | — | — | 🗓 | M5-07 |
| Breed Matchmaker | Primerna pasma iz 12-tedenskih podatkov otroka. | starš | ⏸ | backlog |
| Faza 2: asistent za pravega psa | Gumb »Kupili smo pravo žival«: ovratnice, AI prvi stik z napotitvijo k veterinarju, rast in prehrana. | lastnik | ⏸ | backlog, `PHASE2_SPEC.md` |
| Mačka in druge živali | Podoba je že pripravljena (znak ni pes). | — | ⏸ | backlog |
| AR, GPS zemljevidi, vremenski API, LLM veterinar | Izključeno iz MVP. | — | ⏸ | scope guard |

---

## Številke, ki jih lahko uporabimo

| Številka | Kaj pomeni | Vir |
|---|---|---|
| **46 virov** (S1–S46) | podlaga za pravila po starosti; vsaka vrednost v bazi z virom ali označeno odločitvijo. Z M5-R03 dodan S47 (VCA) | BUILD_LOG 2026-10-05, 2026-10-06; `docs/research/dog-data/` |
| **1.100** strežniških testov zelenih | stanje 2026-10-07 | HANDOFF §6 (2026-10-07) |
| **1.081** testov aplikacije zelenih | stanje 2026-10-07 | HANDOFF §6 (2026-10-07) |
| **≈ 1,27 $** na brezplačnega psa | AI mediji ob rojstvu: slika 0,15 $ + 2 videa × 0,56 $ (*ocena po ceniku fal*) | PRODUCT_SPEC §10 |
| **≈ 3,51 $** na plačljivo pasmo | slika + 6 videov; mladiček plačljive pasme z videi vedenja ≈ 4,63 $, starejši pes ≈ 4,07 $ (*ocene*) | PRODUCT_SPEC §10 |
| **5 $ / dan, 50 $ / mesec** | meji porabe AI; laboratorij ima ločenih 3 $ na dan | BUILD_LOG 2026-10-04 |
| **> 300.000** kombinacij videza mešančka (Border Collie 4.680) | unikaten pes; seznami lastnosti so še osnutek | BUILD_LOG 2026-10-04 |
| **12 h 30 min / 10 h** | mešanček (stara pravila) lačen / žejen; Border Collie 8 h 20 min / 6 h 40 min — preverjeno s 24-urno simulacijo | BUILD_LOG 2026-10-03 |
| **6.000 / 12.000** korakov | dnevni cilj odraslega mešančka / Border Collieja (minute gibanja × 100) | PRODUCT_SPEC §5 |
| **4 ukazi · 50 s · 1,5 s · 5 min na dan** | šolanje; mešanček z eno vajo na dan obvlada ukaz v ~16 dneh (*predlog*) | BUILD_LOG 2026-10-06 |
| **49,99 € · 7 dni** | cena izziva na psa in brezplačni preizkus (odločeno; nakup še ni zgrajen) | BUSINESS_MODEL §2, §7 |
| **4,4 s → 0,01–0,02 s** | odziv API med prenosi videov, star in nov produkcijski strežnik (lokalni preizkus) | BUILD_LOG 2026-10-05 |
| **≈ 50 s / ≈ 2 min** | sprememba dokumentacije skozi CI / strežniška sprememba od združitve do produkcije | HANDOFF §6 (2026-10-06) |
| **41 poizvedb** | nadzorna plošča za 2 psa, enako za 7 ali 84 dni zgodovine | BUILD_LOG 2026-10-04 |
| **~55 %** | realistična ocena dokončanosti MVP (interno; manjkajo plačila, zdravstveni koraki, i18n, zasebnost / pregled trgovin, beta) | HANDOFF §1 (2026-10-06) |
