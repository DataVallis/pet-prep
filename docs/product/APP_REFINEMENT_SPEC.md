# PetPrep — Dodelava aplikacije po testiranju (M5-T)

> **Status:** spec, 20. 10. 2026. Vir: Davidovi zapiski s testiranja z beta testerji (otroci in starši).
> **David 20. 10. 2026:** »strinjam se z vsem« — vsi predlogi iz pogovora so potrjeni, razen kjer je spodaj navedena drugačna Davidova odločitev.
> **Pravilo kakovosti (David 20. 10. 2026):** aplikacija mora biti **premium, zelo realistična**. Slabih videov ne bo. Optimizacija stroškov AI je dovoljena samo, če primerjava v AI Labu pokaže, da je kakovost enaka (§13). *(21. 10. 2026: primerjava opuščena — en model za vse, krajši klipi šole in iger; §14.)*
> Razvoj se začne šele, ko je ta spec razdelan do potankosti. Kar je označeno **(D)**, je Claudov predlog, ki čaka Davidovo potrditev podrobnosti.

## 0. Temeljni pravili

1. **Stanje → medij, nikoli obratno.** Kaj ljubljenček počne, določa simulacija; slika ali video samo prikaže to stanje. Video nikoli ne sme kazati nečesa drugega, kot piše na zaslonu.
2. **Vsak pritisk ima viden odziv:** kaj se je zgodilo, kaj je zdaj drugače in kdaj bo spet treba nekaj narediti.

## 1. Preklop slika / video

- Nastavitev **»Prikaz: Video / Slika«** v otrokovih nastavitvah; starš jo lahko nastavi za otroka.
- Privzeto: **video**.
- »Slika« prikazuje **ključno sliko trenutnega stanja** (§7), ne ene fiksne fotografije.

## 2. Pelji ven (potreba) — jasnost

**David 20. 10.:** »Pelji ven« ostane **ločen gumb**. Sprehod (koraki) **nikoli** ne reši potrebe — koraki se štejejo samodejno, zato otrok ne bi vedel, da je kužka peljal ven.

- Ura je **rok za ukrep**, ne odštevanje do dogodka. Pod uro besedilo, npr. »Kuža mora ven v 1 h 20 min. Pritisni *Pelji ven*, preden se čas izteče.«
- Barva ure: zelena → oranžna → rdeča; gumb v zadnjih 30 min rahlo utripa **(meja 30 min — potrdil David 21. 10. 2026)**. Oranžna od ≤ 60 min ali od zadnje polovice časa zadrževanja, kar je prej **(potrdil David 21. 10. 2026)**. Z vklopljenim »zmanjšaj gibanje« gumb ne utripa. Zgrajeno v M5-T01.
- **Razlika pelji ven / sprehod** je razložena v mini navodilih (§5) in ob prvem prikazu gumba.

## 3. Šola — žival se ujema z vajo

**David 20. 10.:** **klip za vsak ukaz in vsako fazo** (ne ena poza).

- Ukazi danes: sedi, pridi, prostor, potreba (`TrainingCommand`).
- Faze **(D)**: čaka/pozornost → izvaja → uspeh (drži položaj). Klip faze se predvaja enkrat in se ustavi na zadnji sličici; nikoli ne teče v zanki, ki bi kazala drugo dejanje.
- Šola je onemogočena, ko ljubljenček spi (§8).

## 4. Odziv po »Pelji ven«

- Po pritisku kratek zaslon: animacija (kuža na travi) → »✓ Kuža je opravil potrebo. Naslednjič čez ~X h.« + točke.
- Ura se vidno ponastavi z animacijo, ne »tiho«.
- Isti vzorec potrditve velja za vsa dejanja (hrana, voda, čiščenje, česanje).
- *Stanje M5-T01 (21. 10. 2026):* zgrajena potrditvena kartica »✓ … Naslednjič čez ~X h.« (pelji ven; hrana → naslednji obrok, voda → kdaj spet, čiščenje brez »naslednjič«) in kratka animacija ponastavitve ure. **Točk ni** — David 21. 10. 2026: »ne zdaj«, potrditev po dejanju ne kaže točk, Care Score ostane v poročilu; animacija »kuža na travi« čaka na medije (M5-T08). Česanje svojo potrditev že kaže v lastni igri.

## 5. Mini navodila

- Kartica **»Kako skrbim za [ime]«** za vsako pasmo in starost: kolikokrat ven, koliko korakov, kdaj hrana in koliko, voda, česanje, spanje, razlika *pelji ven / sprehod*.
- **Besedila se generirajo iz istih nastavitev, ki jih uporablja simulacija** (stage params, pasemski podatki) — navodila se nikoli ne razidejo s pravili igre.
- Prikaz ob prvem zagonu, nato vedno dostopno z gumbom »?« na glavnem zaslonu.
- Gumb za branje na glas — **kasneje — David 21. 10. 2026** (ko testerji preverijo kartico).

**Zgrajeno (M5-T05, 21. 10. 2026).** Strežnik pošlje v stanju otroka blok `care_guide` (samo številke in ključi, brez besedil), sestavljen iz istih storitev, ki jih uporablja simulacija: današnja okna hranjenja TEGA ljubljenčka (tudi obroki, ki jih v tihih urah da starš), cilj korakov današnje faze, ure, ki jih mladiček zdrži (»Pelji ven«), pravilo vode (dolij pri ≤ prag %, najmanj N min narazen), česanja na teden, tihe ure družine (= spanje), pri muci igra s palico, pesek in praskanje, rok za čiščenje nereda (2 h) in pri pasmah z GDV čakanje po obroku. Aplikacija iz tega sestavi otroku prijazne stavke (SL + EN; muca »muca« / »kitty« + »it«); naslov »Kako skrbim za: [ime]« oz. »Kako skrbim za kužka / muco«. Razlika »Pelji ven« / sprehod (David 20. 10.: koraki nikoli ne rešijo lulanja) je poudarjen razdelek pri mladičku. Kartica se odpre sama ob prvem prikazu ljubljenčka na napravi (zapomni si jo po ljubljenčku), nato z gumbom »?« v zgornji vrstici HUD. 

**Zgrajeno za starša (M5-T05b, 21. 10. 2026).** Pregled staršev (`GET /api/parent/dashboard`, `family.pets[]`) nosi isti blok `care_guide` kot stanje otroka (isti `CareGuidePayload`, iste številke). V podrobnostih otroka je gumb **»Kako skrbim za: [ime]«** (EN »How to care for [name]«; brez imena »Kako skrbim za kužka / muco«), ki odpre isto kartico z istimi razdelki, a z besedili za starša: vikanje in otrok kot tisti, ki skrbi (npr. »Vaš otrok ga nahrani v teh časih …«, »Obroke med tihimi urami prevzamete vi — aplikacija jih da samodejno …«, »Prej ga mora otrok peljati ven s tipko »Pelji ven««). SL + EN, pes in muca.

## 6. Dostopnost (otrok ali skrbnik, ki ne more hoditi)

**David 20. 10.:** **potiski vozička namesto korakov.**

- Starš vklopi »Prilagojen način« v profilu otroka/skrbnika.
- Vir: Apple Health (način invalidskega vozička, potiski) in Health Connect (`WheelchairPushes`). Potiski se preračunajo v »korake« z enako težo: **1 potisk = 1 korak** (predlog Claude, 21. 10. 2026 — glej spodaj; čaka Davidovo potrditev).
- Cilji, točke in Care Score ostanejo enaki.

**Razmerje — kaj sem našel (Claude, 21. 10. 2026):** avtoritativnega razmerja potisk : korak **ni**. Pregled nosljivih naprav za uporabnike vozičkov (PMC10577091) pove, da raziskave potiske uporabljajo kot nadomestek korakov, pretvornika pa ne da; Rice in sod. 2021 (Am J Phys Med Rehabil 100(3):292–296) za zmerno do intenzivno aktivnost navaja **55 potiskov / min** (SD 26 — zelo raztreseno), pri hoji je prag ~100 korakov / min (Tudor-Locke) — to bi pomenilo ~1,8 koraka na potisk, a gre za intenzivnost, ne za dnevno količino, in Apple / Google ciljev v potiskih ne objavljata (Apple ima »roll« minute namesto potiskov). Ker je razpon velik in ker ni vira za dnevni cilj v potiskih, ostane **1 : 1** (preprosto, razložljivo staršem; cilj v potiskih je enak cilju v korakih). Če David želi drugo razmerje, je to ena konstanta v aplikaciji.

**Zgrajeno (M5-T12, 21. 10. 2026):** starš v seznamu otrok vklopi **»Prilagojen način«** (na otroka; hranimo samo vklop / izklop — nikoli razloga). Otrokov telefon takrat bere **samo potiske** iz Apple Health (`HKQuantityTypeIdentifierPushCount`; zapiše jih Apple Watch, ko je v Zdravju »Invalidski voziček: Da«) ali Health Connect (`WheelchairPushesRecord`) — senzor gibanja in živi števec ne štejeta (štejeta korake). Strežnik dobi število kot `steps_today` + `unit: pushes`; pravila (protigoljufija 200 / min, največja vrednost dneva, cilj, energija, rutina, Care Score) so enaka. Besedila: »potiski« namesto »koraki« v oknu sprehoda, gumbu na HUD-u (številke), navodilih »Kako skrbim za …« (otrok in starš), opomniku za sprehod (samo otroku v prilagojenem načinu) in v poročilu staršu za dneve s potiski. **Potreben nov native build** (novo dovoljenje `READ_WHEELCHAIR_PUSHES`, besedilo dovoljenja za Zdravje EN + SL). Sprememba `app.json` spremeni »prstni odtis«, zato ta del aplikacije (stikalo, besedila, branje potiskov) **ne pride z OTA** na stare gradnje — pride z naslednjo gradnjo v trgovini; strežnik deluje takoj po namestitvi. Če starš vklopi način, otrokov telefon pa ima staro gradnjo, ta še naprej šteje korake (in poročilo pravi »koraki«).
- Neodvisno od tega **(D)**: vse kretnje s potegom (krtača, list) dobijo alternativo s pritiskom in držanjem; podpora VoiceOver/TalkBack.
  - **Zgrajeno za česanje (M5-T14, 21. 10. 2026, predlog Claude):** držanje prsta na ljubljenčku (po 0,45 s) ali gumba »Počeši enkrat« češe samo z nepravilnim ritmom (0,61–0,86 s, strežnik ga šteje); kratek pritisk = ena poteza; VoiceOver/TalkBack: dejanje »Počeši« na ljubljenčku (= ena poteza), vrstica napredka je dostopna z vrednostjo. Ostale kretnje s potegom (palica s peresom, menjava peska, igra) še nimajo držanja.

## 7. Mediji po stanjih (ključne slike, zanke, prehodi)

- Vsako stanje dobi **ključno sliko** (stoji, leži, spi, je/pije, se igra, je bolan, je žalosten …).
- Video stanja se **začne in konča v pozi svojega stanja**, zato se lahko neopazno ponavlja (npr. zvečer »spi« že leži, se ne uleže na začetku vsake zanke).
- **Prehod** (stoji → leže, vstane) je ločen kratek klip, ki se predvaja enkrat ob spremembi stanja.
- Mediji se generirajo **ob prvem nakupu pasme + barve** in se delijo z vsemi kasnejšimi kupci (BREED_COLOURS_SHARED_MEDIA.md). Vnaprej se ne generira nič.

**Zgrajeno (M5-T08, 21. 10. 2026) — strežnik + aplikacija (JS, OTA); na telefonu še ni preverjeno.**
- **Ključne slike:** vsako stanje polnega (kupljenega) nabora razen »stoji« dobi ključno sliko — Nano Banana Pro Edit iz referenčne slike obdobja (isti pes, soba, svetloba in kader, spremeni se samo poza). »Stoji« (`idle`) = sama referenčna slika. Stanja: spi, utrujen, lačen (ob posodi), bolan, igra (poklon), pri mladičku z vedenjskimi dogodki tudi nesreča in grizenje copata, pri muci praskanje.
- **Zanke:** video stanja (Kling 3.0 Pro, 5 s) se začne na ključni sliki in **konča na isti sliki** (`end_image_url`, preverjeno v dokumentaciji fal 21. 10. 2026) — aplikacija ga predvaja z izvorno zanko brez vidnega ponovnega začetka.
- **Prehoda:** stoji → spi (`idle_to_sleeping`) in spi → stoji (`sleeping_to_idle`), Kling 3.0 Pro 5 s, začetek = poza A, konec = poza B. Aplikacija ob spremembi stanja prehod predvaja enkrat, zanka ciljnega stanja je že naložena (ustavljena na prvi sličici) in se takoj nadaljuje. Prehod, ki se ne konča v 8 s, aplikacija opusti.
- **§0 pravilo 1:** dokler klipa stanja ni, aplikacija pokaže ključno sliko tega stanja (ali referenčno sliko) — nikoli klipa drugega stanja. Ljubljenčki s starejšim naborom ohranijo nadomestno verigo (bolan → spi → miruje).
- **Vrstni red (§13):** referenčna slika → zanka »stoji« (iz nje) in ključne slike v vrstnem redu priprave (spi, lačen, igra, ostalo); vsaka zanka sledi svoji ključni sliki; prehoda šele, ko so na poti vsi klipi prvih minut. Zaslon »Pripravljamo …« čaka na iste stvari kot prej (slika, stoji, spi, lačen); prehoda sta postavki priprave (obvestilo »… je pripravljen!« pride, ko sta tudi ta shranjena).
- **Kdo:** samo poln nabor (kupljen izziv). Brezplačni nabor (miruje + spi) ostane, kot je — **potrdil David 21. 10. 2026** (zaenkrat brez ključne slike »spi« za brezplačne). Obstoječi ljubljenčki **nič samodejno** (glej DECISIONS 21. 10. 2026); nov nakup ali admin »Upgrade to state media« (od M5-T08b) jih nadgradi.
- **Skupni videz pasme + barve:** ključne slike, zanke in prehodi so vrstice videza na obdobje — ustvari jih prvi kupec, vsak naslednji jih dobi za 0 $.
- **Administracija:** superadmin → ljubljenček → »AI media« pokaže vse vrstice (vrsta `keyframe` / `video` / `transition`, stolpec »Made as«: loop / from key image / from reference image / pose → pose) in »Regenerate« ključne slike (nato se iz nje znova naredita zanka in prehodi skozi to pozo).
- **Razširitve (M5-T09 / T10 / T11):** ARCHITECTURE §2 (*Media per state*).
- **Stikalo (M5-T08b, 21. 10. 2026):** vklop / izklop v administraciji (»Feature switches« → »Mediji stanj vklopljeni«, privzeto vklopljeno, revidirano). Izklop ustavi tudi že čakajoče (vrsta, dnevni ponovni poskus, čiščenje); ob vklopu se nadaljuje. Pod nalagajočo zanko je ključna slika istega stanja (ne referenčna slika); prehod se predvaja samo, ko lahko takoj preide v zanko ciljnega stanja (pozen klip ga nikoli ne sproži kasneje).

## 8. Pravila »kaj je dovoljeno kdaj«

- Ena tabela pravil: katera dejanja so dovoljena v katerem stanju (spi, tihe ure, bolan, je, na sprehodu, zaklep do plačila).
- **Ko ljubljenček spi, sta onemogočeni igra in šola** (David 20. 10.); **od 21. 10. tudi crkljanje in česanje** (»spečo žival ne motimo«, pes in muca).
- **Šolsko okno ostane spanje** (David 21. 10.): med šolskim oknom tihih ur ljubljenček »spi« kot ponoči (igra / šola / crkljanje / česanje onemogočeni z istim besedilom) — pravilo ostane, kot je.
- Onemogočen gumb vedno pokaže razlog, npr. »💤 Kuža spi do 7:00«.

**Zgrajeno (M5-T02, 21. 10. 2026) — kanonična tabela.** Odloča strežnik (zastavice `can_*` v stanju otroka); aplikacija ima eno tabelo (`mobile/src/modules/childPet/actionRules.ts`), ki zastavice samo prebere in doda razlog spanja (z uro bujenja) ali zaklepa.

**Stanja:**
- **Spi** = tihe ure družine (noč, privzeto 21:00–07:00, in neobvezno šolsko okno), po uri družine. Zbudi se ob koncu tihih ur (šola, ki se nadaljuje v noč ali obratno, šteje kot eno spanje). Ena definicija na strežniku (`PetSleepService`); stanje otroka in `PetUpdated` imata `sleep: {asleep, until}`.
- **Tihe ure** = isti trenutki kot spanje (upad ×0,1, obroke pokrije starš, brez obvestil).
- **Bolan** = obisk pri veterinarju (zaklep do `lock.until`).
- **Na sprehodu** = odprt zaslon sprehoda pokrije HUD (drugega se takrat ne da pritisniti).
- **Zaklep** = konec igre, neaktivno, starš ustavil, **čaka na plačilo**, pogodba ni podpisana, bolan — pokaže ga zaklenjeni zaslon; tabela vrne razlog zaklepa.

| Dejanje | Spi / tihe ure | Bolan / zaklep (tudi plačilo) | Drugo, kar ga ustavi (razlog pod gumbom) |
|---|---|---|---|
| Hrani | dovoljeno, kot reče strežnik (obrok v tihih urah pokrije starš) | ne | nered (»Najprej pospravi«), izven okna (»ob 17:00«) |
| Voda | dovoljeno, kot reče strežnik | ne | nered, posoda še polna (»ob 15:30« / »jutri«) |
| Očisti | dovoljeno | ne | nič za čistiti (»Čisto«), samo prežvečen copat / kavč |
| Pelji ven (mladiček) | ura v tihih urah stoji | ne | — |
| Sprehod | dovoljeno (koraki štejejo vedno) | ne | — (muca nima sprehoda) |
| **Igra** | **ne — »💤 Kuža spi do 7:00«**, strežnik zavrne z `play_pet_sleeping` | ne | nered (»Najprej počisti …«), klipi še niso pripravljeni (»Igra se še pripravlja«, 422 `media_not_ready`, M5-T04) |
| **Crkljanje** (pes in muca) | **ne — »💤 Kuža / Muca spi do 7:00«**, strežnik zavrne z `play_pet_sleeping` (21. 10.) | ne | nered (»Najprej počisti …«), klipi še niso pripravljeni (»Igra se še pripravlja«, M5-T04) |
| **Šola** | **ne — »💤 Kuža spi do 7:00«**, strežnik zavrne z `training_pet_sleeping` | ne | nekdo drug vadi, čas dneva porabljen, dan se izteka |
| **Počeši** (pes in muca) | **ne — »💤 Kuža / Muca spi do 7:00«**, strežnik zavrne z `grooming_quiet_hours` pred vsemi drugimi razlogi (21. 10.); česanje, ki ga spanje onemogoči, se nikoli ne šteje kot zamujeno | ne | že danes / ves teden opravljeno, druga igra teče |
| Igra s palico (muca) | ne (»💤 Muca spi do …«) | ne | 2 h premora, druga igra teče, klipi še niso pripravljeni (»Igra se še pripravlja«, `media_not_ready`, M5-T04) |
| Pesek (muca) | dovoljeno | ne | pesek čist |
| Nova izkušnja (mladiček) | ne (»💤 Kuža spi do …«) | ne | izkušnja tedna opravljena |

- Med spanjem so »Šola«, »Igra« / »Crkljanje« in »Počeši« **vidni, a onemogočeni**; pod njimi ena vrstica »💤 Kuža spi do 7:00« (EN »💤 Your pup is asleep until 7:00«; muca »💤 Muca spi do 7:00«). Ura je ura bujenja po času družine.
- **Zapis ure (David 21. 10.):** vse ure, ki jih vidi uporabnik, so v obliki »7:00« (24-urna, brez vodilne ničle pri uri, minute vedno dvomestne) — namigi na HUD, zavrnitve (»Kuža spi do 7:00«), »najkasneje ob 9:30«, okna hranjenja, obvestila; SL in EN enako (EN je že prej uporabljal 24-urni zapis, odpade samo ničla). Ena funkcija: `clockText` (aplikacija) / `ClockText::at` (strežnik).
- Brez ure (starejši strežnik): »💤 Kuža spi«.

## 9. Rojstni dan

**David 20. 10.:** rojstni dan je, ko ljubljenček **doseže 1 leto po formuli starosti simulacije** (ne mesečni, ne tedenski).

- Klobuček, varna »torta«, izobraževalni namig (npr. čokolada je za pse strupena); obvestilo otroku in staršu.
- Za razvoj preveriti: pri kateri pasmi in starosti ob prihodu se 1 leto zgodi znotraj 12 tednov; če pri nobeni, ga v praksi dobi le brezplačni ljubljenček, ki ostane — sporočiti Davidu.

**Status (M5-T13, 21. 10. 2026): izvedeno** (backend + mobile JS; še ni v trgovini, še ni preverjeno na telefonu).

**Pravilo (izvedba):** starost = starost ob prihodu + 1 mesec na dopolnjen programski teden (`LifeStageService::ageMonthsAt`; čas zaklepa zaradi plačila ne šteje — `Pet::programBirthAt`). Rojstni dan je **trenutek, ko starost doseže 12 mesecev** = obletnica rojstva po (12 − starost ob prihodu) programskih tednih; **datum** je družinski lokalni dan tega trenutka. Velja od 00:00 po času družine in ves ta dan (`birthday.is_today`). **Enkrat na ljubljenčka.** Ljubljenček, ki pride star 12 mesecev ali več, prvega rojstnega dne v simulaciji nima (`birthday: null`); prav tako star (legacy) profil brez starosti.

**Rezultat analize (vse pasme, parametri `breed_stage_params` iz `data.json`, 21. 10. 2026):**

| Vrsta / pasme | Prihod: mladiček | Prihod: mlad | Prihod: odrasel | Prihod: senior |
|---|---|---|---|---|
| Vseh 50 pasem psov (tudi mešanček; vse imajo mladička 2 m, mladega 9 m, odraslega 36 m, seniorja 54–131 m) | 2 m → **1 leto v 10. tednu (dan 70)** ✅ v izzivu | 9 m → **1 leto v 3. tednu (dan 21)** ✅ v izzivu | 36 m → ni (že starejši od 1 leta) | ni |
| Domača mačka (mešanka) | 2 m → **10. teden (dan 70)** ✅ | 12 m → ni (pride stara točno 1 leto) | 84 m → ni | 120 m → ni |
| Maine coon, britanska kratkodlaka, ragdoll, sibirska, norveška gozdna, bengalska (pasemski mucki, 12–13 tednov) | 3 m → **9. teden (dan 63)** ✅ | 12 m → ni | 84 m → ni | 120 m → ni |

**Odgovor Davidu:** rojstni dan **ni le** za brezplačnega ljubljenčka, ki ostane — **dobi ga vsak mladiček / mucek in vsak mlad pes** v 12-tedenskem izzivu (pes ob prihodu pri 9 mesecih že v 3. tednu). Brez njega so odrasli in starejši psi, mlade / odrasle / starejše mačke (mlada mačka pride pri 12 mesecih) — tudi pri brezplačnem ljubljenčku, ki ostane, ker gre samo za *prvi* rojstni dan. Zaklep zaradi plačila datum zamakne za čas zaklepa.

**Izvedba (Claudov predlog, M5-T13):**
- Strežnik: `PetBirthdayService` (datum, `processDue()` v `pets:process-decay`), `pets.birthday_celebrated_at` (enkrat, atomsko pod zaklepom vrstice) + `birthday_next_check_at` (tik ne računa vsako minuto), `birthday {date, is_today, celebrated}` v stanju otroka, pregledu staršev in `PetUpdated` (dodatno), dogodek `pet_birthday`.
- Obvestilo `pet_birthday` otrokom in staršem: naslov = ime ljubljenčka (če ga ima), besedilo brez imena, podatki `{type, pet_id}`, običajna prioriteta; **čez tihe ure se zadrži** (ne zavrže) — dan se začne ob polnoči, ki je skoraj vedno v nočnem oknu. Med zaklepom (trdi stop, bolezen) se rojstni dan počaka; če je zaklenjen ves dan, ga ni. Med čakanjem na plačilo `is_today` = false.
- Otrok: **klobuček** nad ljubljenčkom ves dan (narisan v aplikaciji, brez novih AI medijev; ne čez zaklep), **kartica** »Vse najboljše, Luna!« se odpre sama enkrat na ljubljenčka, nato s pritiskom na klobuček: starost (»Luna ima danes 1 leto.« — z imenom spolno nevtralno kot pri pripravi, David 21. 10.; brez imena »Tvoj kuža je danes star 1 leto.« / »Tvoja muca je danes stara 1 leto.«), **varna torta** (pes: »pasja poslastica brez čokolade in brez sladkarij«; muca: »mačja poslastica brez čokolade in brez mleka«) in **namig z virom**. Ime nikoli sklanjano (nagovor / osebek v imenovalniku). Starš: vrstica »Luna danes praznuje prvi rojstni dan.« v podrobnostih otroka.
- **Namigi in viri** (splošno znana varnostna dejstva — brez opombe »ni preverjeno pri veterinarju«):
  - Pes — »Čokolada je za pse strupena. Vsebuje teobromin – in temnejša kot je čokolada, bolj je nevarna.« Vir: U.S. FDA, *Leave Chocolate Out of Rover's Celebrations* (https://www.fda.gov/animal-veterinary/animal-health-literacy/leave-chocolate-out-rovers-celebrations): »Chocolate is made from cocoa, which naturally contains two compounds that are toxic to dogs, theobromine and caffeine.« / »the darker the chocolate, the higher the cocoa content, and therefore, the more theobromine«.
  - Muca — »Mleko ni poslastica za odraslo muco: odrasle muce ga ne prebavijo dobro in jih lahko boli trebušček. Tudi čokolada je za muce strupena.« Vira: Agriculture Victoria, *Human foods to avoid for cats* (https://agriculture.vic.gov.au/livestock-and-animals/animal-welfare-victoria/cats/health/human-foods-to-avoid-for-cats): »Adult cats do not possess significant amounts of lactase … milk and other dairy based products can cause diarrhoea or other digestive upset.« / »Chocolate contains theobromine … and caffeine which are toxic to cats.«; ASPCA, *People Foods to Avoid Feeding Your Pets* (https://www.aspca.org/pet-care/aspca-poison-control/people-foods-avoid-feeding-your-pets).
- **Prihodnje delo:** rojstnodnevni klip ljubljenčka (AI video s klobučkom) — M5-T08+; zdaj klobuček riše aplikacija čez obstoječe medije.

## 10. Igre

- Kratkoročno: če manjka katerikoli klip igre, je igra **onemogočena** (»Igra se še pripravlja«), nikoli napol delujoča.
  - *Stanje M5-T04 (21. 10. 2026):* zgrajeno za vse igre z mediji — žoga in crkljanje (pes, crkljanje tudi muca) ter palica s peresom (muca). Odloča strežnik: igra potrebuje vse klipe svoje scene, do katerih je ljubljenček upravičen (`config/media.php` `game_clips`: `playing` + `idle`; brezplačni nabor nima `playing`, zato potrebuje samo `idle`), šteje pa ljubljenčkov dejanski nabor (lastni ali skupni videz pasme + barve). Dokler kateri manjka, je gumb viden, a onemogočen z »Igra se še pripravlja« (EN »The game is still getting ready«), začetek pa strežnik zavrne (422 `media_not_ready`). Ko je zadnji klip shranjen, ga `PetUpdated` (`video_ready`) prinese v aplikacijo in igra se omogoči brez ponovnega zagona. Ljubljenček brez AI medijev (narisana scena) igra kot doslej. Besedilo nikoli ne omenja slik, videov ali generiranja (§13).
- Dolgoročno: klip za vsako fazo igre (čaka → teče → prinese; muca: preži → skoči → ulovi); žogo ali list animira aplikacija sama, zato se besedilo vedno ujema s tem, kar se vidi. Most do načrtovanega 2.5D pristopa.

## 11. Krtača in kretnje

- Krtača sledi prstu, iz dlake se kažejo delčki/iskrice, »umazane« lise izginjajo (kot strgalna srečka), vrstica napredka se polni.
- Enako kot list pri muci, za česanje obeh vrst.

**Zgrajeno (M5-T14, 21. 10. 2026, mobilna aplikacija JS → OTA, predlog Claude):** česanje psa in muce (`ChoreOverlay` `grooming`, `BrushArea` + `brushing.ts`): krtača sledi prstu, iskrice (omejene: največ 8, vsakih ≥ 70 ms in ≥ 14 pt), 6 lis (2 na korak) zbledi s potezami, ki jih strežnik šteje, vrstica »Počesano: N %«. Vse lise čiste → igra se konča do 2 s prej (znotraj strežnikove tolerance 3 s), sicer ob koncu časa kot prej; strežnik, potezo in točkovanje so isti. »Zmanjšaj gibanje«: brez iskric, lise brez prelivanja. Ločenega »lista« pri muci ni — muca in pes češeta s krtačo; menjava peska ostane brez lis. Brez novih nativnih knjižnic (PanResponder + Animated; nameščena sta tudi reanimated in svg, a nista bila potrebna).

## 12. Hitrost besedil

- Vsako sporočilo ostane najmanj 2,5 s + čas glede na dolžino besedila **(60 ms na znak — potrdil David 21. 10. 2026)**. Zgrajeno v M5-T03.
- Sporočila čakajo v vrsti, se ne prekrivajo, zaprejo se s pritiskom.
- Nastavitve »Hitrost besedil« zaenkrat **ni** (potrdil David 21. 10. 2026).

## 13. Priprava ljubljenčka (ko mediji še niso narejeni)

**David 20. 10.:** uporabnik **ne sme videti**, da se generirajo slike ali videi. Nikakršnega »prvi ste izbrali to pasmo«, nobene omembe AI generiranja.

- Po nakupu: zaslon **»Pripravljamo [ime] … to lahko traja nekaj minut«** z animacijo Curia (nastavljanje aplikacije v ozadju).
- Vse, kar še ni pripravljeno, je označeno nevtralno: »Šola se še pripravlja«, »Igra se še pripravlja«. V šoli se do takrat prikaže poza.
- Vrstni red priprave: najprej to, kar otrok vidi v prvih minutah (stoji, spi, je), nato šola in igre.
- Obvestilo, ko je vse pripravljeno: »Vse je pripravljeno: [ime]« *(David 21. 10.: spolno nevtralno; prej »[ime] je pripravljen!«)*
- Vsi uporabniki vidijo enako; pri pasmi/barvi z že narejenimi mediji priprava traja nekaj sekund.
- Za razvoj izmeriti: koliko traja priprava celotnega paketa (fal izvaja videe vzporedno).

**Status (M5-T06, 21. 10. 2026):** zgrajeno — strežnik + aplikacija (JS, OTA). Strežnik v stanju otroka, pregledu staršev in vsakem `PetUpdated` pošlje `preparation {ready, pending, preparing, running, ready_at}` (`PetPreparationService`); videi stanj se postavijo v vrsto v vrstnem redu prvih minut (slika → stoji `idle`, spi `sleeping`, je `hungry` → igra `playing` → ostalo; `config/media.php` `preparation_order`) — isti nabor, isto število, brez novega stroška. Priprava se začne ob rojstvu (podpis pogodbe), če takrat ni vse shranjeno (ali ob nakupu, a samo, če je žival čakala za plačilnim zaklepom — nakup živali, ki se že igra, je nikoli ne začne), in nikoli za ljubljenčke, rojene pred 21. 10. 2026; zaslon s Curiom »Pripravljamo: Luna … To lahko traja nekaj minut.« (ime za dvopičjem, nikoli sklanjano; brez imena »Pripravljamo tvojega kužka / tvojo muco«) se pokaže, dokler manjka slika ali klip prvih minut, največ 15 min, nikoli čez zaklep. V šoli se med tekočo pripravo, dokler klipi šole prihajajo, pokaže poza z »Šola se še pripravlja«, igra ostane »Igra se še pripravlja« (M5-T04). Ko je vse shranjeno in je priprava trajala vsaj 10 s ter največ 2 h (`announce`): enkrat na ljubljenčka `PetUpdated pet_ready`, eno potisno obvestilo otrokom **in staršem družine** (naslov = ime, besedilo brez imena in spolno nevtralno — otrok »Vse je pripravljeno. Pridi pogledat!«, starš »Vse je pripravljeno. Ljubljenček vas čaka v aplikaciji.«, podatki `{type, pet_id}`, v tihih urah odpade) in sporočilo v aplikaciji »Vse je pripravljeno: Luna« (brez imena »Vse je pripravljeno za tvojega kužka / tvojo muco!«; vrsta sporočil M5-T03, največ 12 h po koncu). Merjenje: superadmin → ljubljenček → »AI media«: *Preparation* = `preparation_ready_at − preparation_started_at`, *Media pipeline* = zadnji `completed_at` − prvi `created_at` shranjenih medijev. **Odločitve Davida 21. 10.:** omejitve potrjene (zaslon največ 15 min, potisno obvestilo samo, če je priprava končana v 2 h, sporočilo v aplikaciji v 12 h); klipi prvih minut potrjeni (stoji, spi, je); besedilo »pripravljeno« spolno nevtralno; obvestilo dobijo tudi starši.

## 14. Strošek AI medijev in modeli

**Danes:** Kling 3.0 Pro image-to-video, 5 s, brez zvoka, 9:16 po referenčni sliki, izvorna ločljivost (Kling 3.0 Pro na fal **nima parametra za ločljivost** — 720p ni mogoče izbrati; cena je na sekundo, ne glede na ločljivost). Slika: Nano Banana Pro 0,15 $.

**Ocena paketa za eno pasmo + barvo, eno življenjsko obdobje (cenik fal, Kling 3.0 Pro 0,112 $/s):**

| Kaj | Količina | Strošek |
|---|---|---|
| Sedanji paket (slika + 8 stanj) | — | 4,63 $ |
| Ključne slike stanj | 6 × 0,15 $ | 0,90 $ |
| Prehodi | 2 × 0,56 $ | 1,12 $ |
| Šola (4 ukazi × 3 faze) | 12 × 0,56 $ | 6,72 $ |
| Igra z žogo + crkljanje | 4 × 0,56 $ | 2,24 $ |
| Rojstni dan | 1 × 0,56 $ | 0,56 $ |
| **Skupaj** | | **≈ 16,20 $** |

12-tedenski izziv z mladičem ima 2 obdobji → ≈ 32 $ ≈ 28 €. Od 49,99 € ostane po DDV (22 %) in proviziji trgovine (15 %) ≈ 34,80 €; **prvi kupec ≈ 7 € v plusu, vsak naslednji iste pasme in barve ≈ 34,80 €** (ocena po ceniku; resnica je račun fal).

**Odločitev (David 21. 10. 2026) — brez primerjave modelov:**
1. Vsi videi: **Kling 3.0 Pro** (image-to-video); vse slike: **Nano Banana Pro**, ključne slike stanj in življenjskih obdobij **Nano Banana Pro Edit**. Primerjava v AI Labu (M5-T07) je **opuščena**.
2. Novi klipi **šole in iger** (M5-T09 / M5-T10) so dolgi **3 s** namesto 5 s (−40 % pri istem modelu). Klipi stanj (ki jih otrok gleda največ) ostanejo **5 s**.

**Zgrajeno M5-T08 (21. 10. 2026) — dejanski poln nabor stanj na pasmo + barvo + obdobje (cenik fal):** referenčna slika 0,15 $ + 5 ključnih slik × 0,15 $ = 0,75 $ + 6 zank × 0,56 $ = 3,36 $ + 2 prehoda × 0,56 $ = 1,12 $ → **5,38 $** (zanke nadomestijo stare videe stanj; mladiček z vedenjskimi dogodki + 2 ključni sliki + 2 zanki → **6,80 $**). Vsak naslednji kupec iste pasme + barve + obdobja **0 $**. Ključna slika za »stoji« ni potrebna (= referenčna slika), zato 5 namesto 6 ključnih slik. Šola in igre (M5-T09 / T10) spodaj še niso zgrajene.

**Ocena paketa z odločitvijo (cenik fal, Kling 3.0 Pro 0,112 $/s):**

| Kaj | Količina | Strošek |
|---|---|---|
| Sedanji paket (slika + 8 stanj, 5 s) | — | 4,63 $ |
| Ključne slike stanj | 6 × 0,15 $ | 0,90 $ |
| Prehodi (5 s) | 2 × 0,56 $ | 1,12 $ |
| Šola (4 ukazi × 3 faze, 3 s) | 12 × 3 s × 0,112 $ = 12 × 0,336 $ | 4,03 $ |
| Igra z žogo + crkljanje (3 s) | 4 × 3 s × 0,112 $ = 4 × 0,336 $ | 1,34 $ |
| Rojstni dan (5 s) | 1 × 0,56 $ | 0,56 $ |
| **Skupaj** | | **≈ 12,59 $** (prej ≈ 16,20 $) |

12-tedenski izziv z mladičem ima 2 obdobji → ≈ 25,17 $ ≈ 22 €. Od 49,99 € ostane po DDV (22 %) in proviziji trgovine (15 %) ≈ 34,80 €; **prvi kupec ≈ 13 € v plusu, vsak naslednji iste pasme in barve ≈ 34,80 €** (ocena po ceniku; resnica je račun fal). `config/media.php` še nima dolžine klipa po namenu (dolžina je lastnost profila modela, `duration_seconds`); 3 s za šolo in igre se nastavi z M5-T09 / M5-T10, ko ti klipi nastanejo.

## 15. Prioritete

1. §2/§4 (pelji ven) in §8 (onemogočanje) — otrok dobi kazen, ne da bi vedel zakaj; poceni.
2. §12, §10 (onemogočanje iger), §5.
3. §13 (priprava) skupaj z §7.
4. §7, §3, §10 (novi mediji) — po primerjavi modelov (§14).
5. §1, §6, §9, §11.
