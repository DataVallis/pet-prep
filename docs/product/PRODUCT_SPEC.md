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
2. **Otrok** na svoji napravi vnese PIN → odpre se **Pogodba o odgovornosti** → podpis s prstom → pes se "rodi".
   - **Pogodba pred rojstvom (David, 4. 10. 2026):** po vnosu PIN-a pes že obstaja (izgled, referenčna slika se lahko ustvari takoj), a je **še nerojen**: nič ne upada, ni kakcev, dan se ne zaključuje (sprehod), ni opomnikov, bolezni ne odvzema. Otrok lahko naredi samo eno stvar — podpiše pogodbo; vse druge akcije strežnik zavrne z razlogom "najprej podpiši pogodbo" (`contract_required`). **Trenutek podpisa (čas strežnika) je rojstvo:** vse metrike 100 %, starost, ura upadanja, urnik higiene in "rojstni dan" (energija 100 % do prve polnoči, brez bolezni zaradi sprehoda) začnejo teči takrat. Če otrok podpis odloži, ne izgubi nič. Starš do podpisa vidi psa kot "čaka na pogodbo".
   - Med hard stopom (ali če je seja neaktivna) tudi podpis ni mogoč — starševski premor ima prednost.
   - Psi, ustvarjeni pred to spremembo, veljajo za rojene (brez zaklepa, tudi brez podpisane pogodbe).
3. **Otrok nima lastnega emaila; PIN je njegova prijava** (token vezan na otroški profil, ki ga ustvari starš). *Odločeno 2. 10. 2026.*
4. **Družina** (David, 4. 10. 2026; ADR-012): več staršev (npr. mama in oče) in več otrok. Vsak otrok ima svojega psa **ali** več otrok skupaj skrbi za enega psa (skupno skrbništvo). Vsi starši vidijo vse otroke in pse v družini. Pri skupnem psu se vsako dejanje zapiše pod otroka, ki ga je naredil — **vsak otrok ima svojo oceno, semafor in certifikat**. Cena: 12-tedenski izziv se plača **na psa** (skupni pes = ena cena); mešanček ostane brezplačen.
   - **Pridružitev drugega starša:** starš ustvari kodo za povabilo (8 znakov, velja 24 ur, enkratna); drugi starš jo vnese v svoj račun in postane starš iste družine. Račun, ki že ima otroke ali pse, se ne more pridružiti (družin ne združujemo). *(Claude, čaka Davida)*
   - **Otrok k obstoječemu psu:** starš pri ustvarjanju PIN-a izbere "nov pes" ali "pridruži se psu X". Otrok, ki se pridruži, podpiše **svojo** pogodbo, preden lahko skrbi za psa; pes se ne rodi znova (rodi se ob prvi pogodbi). *(Claude, čaka Davida)*
   - Otrok skrbi za **največ enega aktivnega psa** hkrati. *(Claude, čaka Davida)*
   - Pri skupnem psu sta hranjenje in voda pravili psa (enkrat na okno ne glede na to, kdo nahrani); dnevni sprehod je dosežen s **seštevkom korakov vseh otrok**, ki skrbijo zanj, vsak otrok vidi svoje korake. *(Claude, čaka Davida)*
   - Formula ocene / semaforja / certifikata posameznega otroka še ni določena (odprto vprašanje v DECISIONS).

## 4. Čas in življenjski cikel

- **1 realen teden = 1 virtualni mesec.** Starost na zaslonu: "Starost: N mesecev".
- Simulacija traja **12 realnih tednov** → pes dopolni 1 leto → **Certifikat odgovornosti** s končno oceno.
- Vsi časi (tihe ure, polnoč, okna hranjenja) so v **lokalnem časovnem pasu družine**.

## 5. Metrike in hitrost upadanja

| Metrika | Mešanček (brezplačno) | Border Collie (premium) | Kako jo otrok dvigne |
|---|---|---|---|
| Lakota | −8 %/h (0 % v 12,5 h) | −12 %/h (0 % v 8,3 h) | gumb Hrani — **samo v oknih** zjutraj in zvečer (2× / dan) → 100 % |
| Žeja | −10 %/h | −15 %/h | gumb Voda — 3× / dan → 100 % |
| Gibanje (energija) = **dnevni sprehod** | cilj **4.000** korakov / dan | cilj **10.000** korakov / dan | koraki iz HealthKit / Health Connect; energija = današnji koraki / cilj; **reset na 0 % ob lokalni polnoči** (0 % = "danes še ni bilo sprehoda", ne zanemarjanje) |
| Higiena | naključno **1× / dan** pade na 0 % | naključno **2× / dan** pade na 0 % | mini-igra čiščenja (drgnjenje madežev) → 100 % |

- **Tihe ure** (starš nastavi, npr. šola 8:00–13:00, spanje 22:00–6:00): upadanje se upočasni za 90 %, obvestila se ne pošiljajo, higienski dogodki se ne zgodijo.
- **Hard stop / bolezen:** metrike so zamrznjene.
- **Pred podpisom pogodbe** (nerojen pes, §3) se nič ne zgodi: brez upadanja, higienskih dogodkov, zaključka dneva in eskalacije.
- **Anti-cheat koraki:** zavrnemo prirastke > 200 korakov / min.
- **(D)** Spec omenja tudi "5.000 korakov" (MVP.docx) — veljavno je 4.000 / 10.000 iz MAIN dokumenta.
- Vse številke iz tabele (hitrosti, cilji korakov, število dogodkov, okna hranjenja 06:00–10:00 in 17:00–21:00, voda 3× / dan z razmikom ≥ 3 h) so v tabeli `breed_configs` in jih admin spreminja v Filamentu — ne v kodi (M1-06).

**Pojasnila implementacije (M1-07 — 4. 10. 2026, otroški API):**
- **Hrana:** samo znotraj okna pasme po lokalnem času družine; okno vključuje začetek in ne konca (06:00 da, 10:00 ne). **Eno hranjenje na okno** (2 okni = 2× / dan). Izven okna ali drugič v istem oknu strežnik zavrne in pove začetek naslednjega okna. Ob prestopu ure okna sledijo stenski uri (06:00 je poleti 04:00 UTC, pozimi 05:00 UTC).
- **Voda:** največ `water_times_per_day` (3) na lokalni dan (meja se ponastavi ob lokalni polnoči), med dvema najmanj `water_min_gap_minutes` (180) **realnih** minut, tudi čez polnoč. Ko je dnevna meja dosežena, je naslednja voda ob lokalni polnoči (oz. kasneje, če razmik še ni potekel). *(Potrdil David, 4. 10. 2026.)*
- **Najprej čiščenje:** dokler higiena kaže 0 %, hrana in voda nista mogoči (§8); koraki in čiščenje vedno. *(Potrdil David, 4. 10. 2026.)*
- **Zaklep:** med hard stopom, boleznijo, po game overju in pred podpisom pogodbe (nerojen pes, §3) strežnik zavrne vsako otroško akcijo z razlogom; pogodbo je mogoče podpisati samo, ko je edini razlog "najprej pogodba". Če velja več razlogov hkrati, se pokaže prvi od: game over › neaktiven › hard stop › pogodba › bolezen.
- **Pogodba:** podpis enkrat na psa (ponoven podpis se zavrne, prvi ostane; nov pes po game overju = nova pogodba); čas podpisa je čas strežnika in hkrati trenutek rojstva psa (§3). *(Potrdil David, 4. 10. 2026.)*

**Pojasnila implementacije (M1-04, M1-05 — 3. 10. 2026):**
- **Gibanje = dnevni sprehod (David, 3. 10. 2026):** energija ni urna metrika zanemarjanja. Za energijo ni ure "0 % > 1 h" (alarm faze 3), ne 6-urne bolezni ne 24-urnega game overja. Opomnika faze 1 / 2 zaradi nizke energije sta le izven tihih ur. Pravilo dneva — glej §7 "Dnevni sprehod".
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
Za **gibanje (energijo)** veljata samo fazi 1 in 2, in to le izven tihih ur; faza 3, bolezen po 6 h in game over se za energijo ne štejejo (dnevni sprehod, §7). Stanje psa "bolan" (`sick`) pomeni samo umazanega ali dejansko bolnega psa — pri 0 % energije je pes "utrujen" (`low_energy`).

## 7. Kazni

- **Bolezen:** higiena 0 % > 6 h (izven tihih ur) **ali** včeraj ni bilo sprehoda (glej "Dnevni sprehod") → zaslon sivo, video težkega dihanja, **12 h timeout** ("na opazovanju pri veterinarju"), otrok ne more ničesar.
  - *Pojasnilo (M1-04, 3. 10. 2026):* 6 ur higiene se šteje **samo izven tihih ur** (med tihimi urami števec stoji), čas hard stopa in bolezni pa se ne šteje. Primer — tihe ure spanje 22:00–06:00, kakec ob 09:00, nihče ne počisti → pes zboli ob 15:00.
  - **Ozdravitev = nov začetek (David, 3. 10. 2026):** ko 12 h bolezni mine, se kuža vrne od veterinarja **čist — higiena 100 %**, in **vse ure zanemarjanja začnejo teči znova od trenutka ozdravitve** (alarm faze 3 po 1 h, bolezen po 6 h, game over po 24 h). Lakota in žeja ostaneta, kakršni sta bili (otrok ju zdaj spet lahko napolni); energija ostane vezana na korake; opomniki (faze) začnejo znova od 0. Če je med boleznijo vklopljen hard stop, je kuža ob koncu bolezni ozdravljen, ure pa stojijo, dokler starš hard stopa ne izklopi. Tako pes po ozdravitvi ne zboli takoj spet (prejšnja "neskončna zanka bolezni"); če ga otrok spet zanemari, zboli po običajnih 6 h izven tihih ur.
- **Dnevni sprehod (David, 3. 10. 2026):** ob lokalni polnoči družine se dan zaključi enkrat na psa: zapišemo včerajšnje korake, cilj pasme in ali je bil cilj dosežen (za starševski pregled).
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
- **Zaklenjen zaslon:** hard stop / bolezen / game over z različnimi sporočili.
- **Pred rojstvom:** dokler pogodba ni podpisana, aplikacija pokaže pogodbo (ne HUD-a); strežnik vse druge akcije zavrne z razlogom `contract_required`. Po podpisu se pes rodi s 100 % in HUD se odklene.

## 9. Starševska aplikacija (UI)

- Čist, analitičen slog (kot bančna / fitnes aplikacija), svetla tema.
- **Semafor:** zelena = redno; rumena = danes zamujeni > 2 rutini; rdeča = kritično, opozorila ignorirana.
- 4 žive metrike (Reverb), časovnica aktivnosti ("✓ 07:15 Pes nahranjen", "✗ 14:00 Zamujeno čiščenje"), tedenski stolpčni graf.
- Nastavitve: tihe ure, hard stop (rdeč gumb s potrditvijo), izbira in nakup pasme.

## 10. AI mediji

- **Pet DNA:** seed + prompt anchor + vizualne lastnosti + referenčna slika → vsak pes je vizualno konsistenten.
- Referenčna slika: NanoBanana Pro (ali enakovreden model na fal.ai); videi: Kling (image-to-video, 9:16, ~5 s zanka).
- Priporočilo: ob rojstvu predgenerirati vseh 6 stanj (nadzorovan strošek), aplikacija preklaplja lokalno.

## 11. Ocenjevanje in certifikat

- **7. dan:** "Puppy Promoter" značka staršu (prvi dokaz vrednosti, točka aktivacije garancije).
- **12. teden:** Certifikat odgovornosti s "Care Score" (delež pravočasno opravljenih rutin), brez game overa.
- **(D)** Formula za Care Score — predlog: (opravljene rutine / pričakovane rutine) × 100, minus 10 točk za vsako bolezen.

## 12. Faza 2 (po MVP) — Real-World AI asistent

Gumb "Kupili smo pravo žival" → aplikacija postane asistent za pravega psa: IoT ovratnice (Tractive), AI veterinarski triage (vision), rast in prehrana, AI inštruktor (3,99 €/mes), affiliate trgovina, zavarovanja, booking veterinarjev in pasjih šol. Tehnično: LLM + RAG (pgvector). Cilj: LTV iz 3 mesecev na 10–15 let.
