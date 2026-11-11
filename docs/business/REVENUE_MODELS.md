# PetPrep — modeli prihodka (ideje in smer)

> **Zapisano 11. 11. 2026 (pogovor David + Claude).** Vse spodaj je *načrt* — nič od tega ni zgrajeno ali izmerjeno. Davidove odločitve so označene **(David)**, ostalo so Claudovi predlogi.
> Današnje stanje in cene: `BUSINESS_MODEL.md`. Načrt validacije: `VALIDATION_PLAN.md`.

## 1. Zakaj ta dokument

Danes PetPrep nima ponavljajočega se prihodka. 12-tedenski izziv je enkratni nakup (49,99 € bruto, po DDV in proviziji trgovine približno 35 €); 100 družin prinese približno 5.000 € bruto. Po koncu 12 tednov ljubljenček družini ostane in igra teče naprej brez doplačila (preverjeno v kodi 11. 11. 2026: ob koncu izziva se ljubljenček ne zaklene).

Simulator kupijo samo družine, ki razmišljajo o živali, in to enkrat. Za veliko podjetje so potrebni kupci, ki plačujejo redno več let — teh je dovolj samo med družinami, ki žival že imajo, in med podjetji okoli njih.

## 2. Smer (David, 11. 11. 2026)

| # | Model | Odločitev |
|---|---|---|
| R1 | **Naročnina za pravega ljubljenčka** (družine, ki žival že imajo) | Všeč — glavna smer |
| R2 | Mesečna naročnina za simuliranega ljubljenčka (namesto ali po 12 tednih) | **Ne** — otroci se naveličajo, naročnine ne bi trajale |
| R3 | **Partnerski prihodek od zavarovalnic** | Da — PetPrep police ne more skleniti, pripelje samo stik; zato **plačilo za posredovan stik ali fiksno sponzorstvo**, ne provizija od sklenjene police |
| R4 | **Zaslužek prek spletne strani**, ne samo aplikacije | Da — začeti prodajati čim prej (§5) |
| R5 | Družinski paketi (več ljubljenčkov za en znesek) | Ne zdaj |

## 3. R1 — PetPrep za pravega ljubljenčka

- Družina vnese svojega psa ali mačko (pasma, starost). Aplikacija iz obstoječih pravil po pasmi sestavi dnevne naloge: obroki, sprehod s koraki, nega.
- Otroci naloge opravljajo, starš vidi semafor in kdo je kaj naredil. Rešuje vsakdanji prepir »kdo je danes peljal psa ven«.
- Že obstaja: motor pravil po pasmi in starosti, družinski model, obvestila, pregled za starša, ocena skrbi. Manjka način brez simulacije (ni upadanja, bolezni, AI posnetkov).
- Cena za preizkus: približno 4,99 € na mesec (predlog, ni izmerjeno).
- **Prvi korak ni gradnja, ampak preverba povpraševanja:** pristajalna stran »PetPrep za vašega ljubljenčka« s ceno in prednaročilom ali čakalnim seznamom ter majhen oglasni test na starše, ki žival že imajo. Lažna vrata v aplikaciji (VALIDATION_PLAN §4.4) dosežejo samo uporabnike simulatorja, torej ne te publike.
- Povezava s Fazo 2 (`docs/product/PHASE2_SPEC.md`): to je njen najmanjši prvi korak, brez AI veterinarja, ovratnic in DNK.

## 4. R3 — zavarovalnice in drugi partnerji

- PetPrep ve, katera družina bo kmalu dobila katero pasmo (simulator), in pri R1 katero žival družina že ima. To je trenutek, ko družina izbira zavarovanje, hrano in veterinarja.
- Oblike: plačilo na posredovan stik, fiksno sponzorstvo (npr. serija »12 Weeks«, stran pasme), kasneje mesečna provizija od naročnin na hrano.
- **Preveriti pred dogovorom:** provizija od sklenjenih zavarovanj je praviloma dovoljena samo registriranim zavarovalnim posrednikom; ali je plačilo za stik brez registracije v redu, naj potrdi zavarovalnica ali odvetnik. Posredovanje stika zahteva izrecno privolitev starša (GDPR); podatki otroka se nikoli ne posredujejo.
- Stiki z zavarovalnicami se vodijo v preglednici »PetPrep – partnerski kontakti«.

## 5. R4 — spletna stran petprep.si

Kaj se lahko prodaja brez čakanja na aplikacijo (Claudov vrstni red; plačila prek Lemon Squeezy, kjer David že ima račun):

1. **Darilni bon za 12-tedenski izziv** (»Ne podarite kužka — podarite preizkus«), s natisljivim bonom. Sezonsko najmočnejše pred božičem. Pogoj: aplikacija je v trgovinah do dostave in strežnik zna unovčiti kodo (danes tega ni). Pred objavo preveriti pravila App Store in Google Play za digitalno vsebino, kupljeno zunaj aplikacije.
2. **Natisljivi komplet »Obljuba o ljubljenčku«**: pogodba za otroka, tedenska tabela nalog, kontrolni seznam stroškov. Lahko gre v prodajo takoj.
3. **Vodniki po pasmah** (PDF »Prvi teden z …«) iz podatkov registra z viri. Zdravstveni del pred prodajo pregleda veterinar.
4. **Plačljiva knjižica pobarvank** (brezplačne so že na strani).
5. **Plačani izpostavljeni vpisi v imenike** (veterinarji, pasje šole, frizerji, vzreditelji) — mesečno, ponavljajoče se; smiselno, ko imajo strani obisk. Do takrat ponudba »ustanovni partner«.
6. **Partnerske povezave** na straneh pasem in živil (zavarovanje, hrana, oprema).
7. **Stran za partnerje in sponzorje** z medijskim kompletom.
8. Kasneje: nakup izziva na spletu namesto v trgovini (brez provizije trgovine; preveriti pravila), oglasi samo na spletni strani.

To so majhni zneski. Njihov namen je prvi prihodek in dokaz, da starši za PetPrep plačajo, ne rast podjetja.

## 6. Druge ideje (ni odločeno)

- **Šole:** letna licenca za razred, »razredni ljubljenček« kot projekt odgovornosti.
- **Zavetišča in vzreditelji:** potrdilo PetPrep kot pogoj ali priporočilo pred posvojitvijo — kanal do družin, ne velik prihodek.
- **Lik in vsebina (Curio, serija):** izdelki in licence. Deluje šele pri zelo veliki publiki; dolgoročna stava.
- **Oglasi v aplikaciji:** ne — otroška aplikacija ostane brez oglasov.

## 7. Kaj je naslednje

> **11. 11. 2026 (David):** iz §5 gradimo 1 (darilni bon), 2 (natisljivi komplet) in 5 (stran za partnerje z ustanovnimi partnerji) — specifikacija [`WEBSITE_SALES_SPEC.md`](WEBSITE_SALES_SPEC.md).

1. Pristajalna stran in oglasni test za R1.
2. Natisljivi komplet (§5.2) v prodajo.
3. Odločitev o darilnem bonu glede na datum objave v trgovinah.
4. Vprašanje zavarovalnicam: plačilo za stik ali sponzorstvo, in kaj je pravno dovoljeno.
