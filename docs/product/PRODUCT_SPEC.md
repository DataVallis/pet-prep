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

**Izključeno (ne gradimo v MVP):** AR, GPS sledenje in zemljevidi, vremenski API, LLM / vision veterinar, B2B QR kuponi, več otrok ali več živali na starša, mačke, naročnina Pro skrbnik.

## 3. Profili in onboarding

1. **Starš** se registrira (Apple / Google, email kot rezerva) → ustvari otroški profil → izbere pasmo (premium = plačilo) → generira **6-mestni PIN (velja 15 min)**.
2. **Otrok** na svoji napravi vnese PIN → odpre se **Pogodba o odgovornosti** → podpis s prstom → pes se "rodi".
3. **Otrok nima lastnega emaila; PIN je njegova prijava** (token vezan na otroški profil, ki ga ustvari starš). *Odločeno 2. 10. 2026.*
4. MVP: 1 starš → 1 otrok → 1 pes.

## 4. Čas in življenjski cikel

- **1 realen teden = 1 virtualni mesec.** Starost na zaslonu: "Starost: N mesecev".
- Simulacija traja **12 realnih tednov** → pes dopolni 1 leto → **Certifikat odgovornosti** s končno oceno.
- Vsi časi (tihe ure, polnoč, okna hranjenja) so v **lokalnem časovnem pasu družine**.

## 5. Metrike in hitrost upadanja

| Metrika | Mešanček (brezplačno) | Border Collie (premium) | Kako jo otrok dvigne |
|---|---|---|---|
| Lakota | −8 %/h (0 % v 12,5 h) | −12 %/h (0 % v 8,3 h) | gumb Hrani — **samo v oknih** zjutraj in zvečer (2× / dan) → 100 % |
| Žeja | −10 %/h | −15 %/h | gumb Voda — 3× / dan → 100 % |
| Gibanje (energija) | cilj **4.000** korakov / dan | cilj **10.000** korakov / dan | koraki iz HealthKit / Health Connect; energija = koraki / cilj; **reset na 0 % ob polnoči** |
| Higiena | naključno **1× / dan** pade na 0 % | naključno **2× / dan** pade na 0 % | mini-igra čiščenja (drgnjenje madežev) → 100 % |

- **Tihe ure** (starš nastavi, npr. šola 8:00–13:00, spanje 22:00–6:00): upadanje se upočasni za 90 %, obvestila se ne pošiljajo, higienski dogodki se ne zgodijo.
- **Hard stop / bolezen:** metrike so zamrznjene.
- **Anti-cheat koraki:** zavrnemo prirastke > 200 korakov / min.
- **(D)** Spec omenja tudi "5.000 korakov" (MVP.docx) — veljavno je 4.000 / 10.000 iz MAIN dokumenta.
- Vse številke iz tabele (hitrosti, cilji korakov, število dogodkov, okna hranjenja 06:00–10:00 in 17:00–21:00, voda 3× / dan z razmikom ≥ 3 h) so v tabeli `breed_configs` in jih admin spreminja v Filamentu — ne v kodi (M1-06).

**Pojasnila implementacije (M1-04, M1-05 — 3. 10. 2026):**
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

## 7. Kazni

- **Bolezen:** higiena **ali** gibanje 0 % > 6 h (izven tihih ur) → zaslon sivo, video težkega dihanja, **12 h timeout** ("na opazovanju pri veterinarju"), otrok ne more ničesar.
  - *Pojasnilo (M1-04, 3. 10. 2026):* 6 ur se šteje **samo izven tihih ur** (med tihimi urami števec stoji), čas hard stopa in bolezni pa se ne šteje. Primer — tihe ure spanje 22:00–06:00 in šola 8:00–13:00, otrok ne hodi: energija pade na 0 % ob polnoči; šteje se 06:00–08:00 (2 h) in 13:00–17:00 (4 h) → **pes zboli ob 17:00**, če otrok do takrat ne sinhronizira korakov (400 korakov ob 15:30 = 10 % → zdrav). Brez tihih ur bi zbolel že ob 06:00.
  - **(D)** Kaj sledi po 12 h bolezni, specifikacija ne pove. Zdaj števec po bolezni nadaljuje tam, kjer je ostal (že ≥ 6 h), zato pes izven tihih ur takoj spet zboli, otrok pa med boleznijo ne more ničesar (tudi korakov ne). Glej odprta vprašanja v `DECISIONS.md`.
  - **(D)** Ker energija vsako noč pade na 0 %, faza 1–3 eskalacije (≤ 30 %, ≤ 10 %, 0 % > 1 h) velja tudi za gibanje: brez sprememb pravil bi starš vsako noč ob 01:00 dobil alarm faze 3 in rdeč semafor do prvega sprehoda. Glej `DECISIONS.md`.
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
