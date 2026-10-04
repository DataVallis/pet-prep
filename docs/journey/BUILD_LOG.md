# PetPrep — Dnevnik razvoja (Build Log)

> **Namen:** surovina za vsebine — objave na omrežjih, zgodbo za investitorje, gradiva za partnerje, sporočila za starše (mame) in otroke. Vsak vnos pove *kaj* se je zgodilo, *zakaj* je pomembno in kako to povedati posameznim publikam.
> **Pravila:** samo resnična dejstva (kar je bilo res narejeno ali odločeno) · številke z virom · brez osebnih podatkov otrok · kar še ni zgrajeno, je označeno kot *načrt*.
> **Vzdrževanje:** orkestrator (Claude) doda vnos po vsaki pomembni seji (`/handoff`). Najnovejši vnos je na vrhu.

Legenda publik: 📣 omrežja · 💼 investitorji · 🤝 partnerji (trgovine, zavetišča, veterinarji, šole) · 👩 starši / mame · 🧒 otroci · 🛠 tehnična publika (dev skupnost, LinkedIn)

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
