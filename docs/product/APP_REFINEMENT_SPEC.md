# PetPrep — Dodelava aplikacije po testiranju (M5-T)

> **Status:** spec, 20. 10. 2026. Vir: Davidovi zapiski s testiranja z beta testerji (otroci in starši).
> **David 20. 10. 2026:** »strinjam se z vsem« — vsi predlogi iz pogovora so potrjeni, razen kjer je spodaj navedena drugačna Davidova odločitev.
> **Pravilo kakovosti (David 20. 10. 2026):** aplikacija mora biti **premium, zelo realistična**. Slabih videov ne bo. Optimizacija stroškov AI je dovoljena samo, če primerjava v AI Labu pokaže, da je kakovost enaka (§13).
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
- Barva ure: zelena → oranžna → rdeča; gumb v zadnjih 30 min rahlo utripa **(meja 30 min — potrdil David 21. 10. 2026)**. Oranžna od ≤ 60 min ali od zadnje polovice časa zadrževanja, kar je prej (*predlog Claude*). Z vklopljenim »zmanjšaj gibanje« gumb ne utripa. Zgrajeno v M5-T01.
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
- *Stanje M5-T01 (21. 10. 2026):* zgrajena potrditvena kartica »✓ … Naslednjič čez ~X h.« (pelji ven; hrana → naslednji obrok, voda → kdaj spet, čiščenje brez »naslednjič«) in kratka animacija ponastavitve ure. **Točk ni**, ker jih odgovor strežnika ne vsebuje; animacija »kuža na travi« čaka na medije (M5-T08). Česanje svojo potrditev že kaže v lastni igri.

## 5. Mini navodila

- Kartica **»Kako skrbim za [ime]«** za vsako pasmo in starost: kolikokrat ven, koliko korakov, kdaj hrana in koliko, voda, česanje, spanje, razlika *pelji ven / sprehod*.
- **Besedila se generirajo iz istih nastavitev, ki jih uporablja simulacija** (stage params, pasemski podatki) — navodila se nikoli ne razidejo s pravili igre.
- Prikaz ob prvem zagonu, nato vedno dostopno z gumbom »?« na glavnem zaslonu.
- Gumb za branje na glas **(D)**.

## 6. Dostopnost (otrok ali skrbnik, ki ne more hoditi)

**David 20. 10.:** **potiski vozička namesto korakov.**

- Starš vklopi »Prilagojen način« v profilu otroka/skrbnika.
- Vir: Apple Health (način invalidskega vozička, potiski) in Health Connect (`WheelchairPushes`). Potiski se preračunajo v »korake« z enako težo **(D: razmerje 1 potisk = 1 korak; preveriti z viri)**.
- Cilji, točke in Care Score ostanejo enaki.
- Neodvisno od tega **(D)**: vse kretnje s potegom (krtača, list) dobijo alternativo s pritiskom in držanjem; podpora VoiceOver/TalkBack.

## 7. Mediji po stanjih (ključne slike, zanke, prehodi)

- Vsako stanje dobi **ključno sliko** (stoji, leži, spi, je/pije, se igra, je bolan, je žalosten …).
- Video stanja se **začne in konča v pozi svojega stanja**, zato se lahko neopazno ponavlja (npr. zvečer »spi« že leži, se ne uleže na začetku vsake zanke).
- **Prehod** (stoji → leže, vstane) je ločen kratek klip, ki se predvaja enkrat ob spremembi stanja.
- Mediji se generirajo **ob prvem nakupu pasme + barve** in se delijo z vsemi kasnejšimi kupci (BREED_COLOURS_SHARED_MEDIA.md). Vnaprej se ne generira nič.

## 8. Pravila »kaj je dovoljeno kdaj«

- Ena tabela pravil: katera dejanja so dovoljena v katerem stanju (spi, tihe ure, bolan, je, na sprehodu, zaklep do plačila).
- **Ko ljubljenček spi, sta onemogočeni igra in šola** (David 20. 10.).
- Onemogočen gumb vedno pokaže razlog, npr. »💤 Kuža spi do 7:00«.

## 9. Rojstni dan

**David 20. 10.:** rojstni dan je, ko ljubljenček **doseže 1 leto po formuli starosti simulacije** (ne mesečni, ne tedenski).

- Klobuček, varna »torta«, izobraževalni namig (npr. čokolada je za pse strupena); obvestilo otroku in staršu.
- Za razvoj preveriti: pri kateri pasmi in starosti ob prihodu se 1 leto zgodi znotraj 12 tednov; če pri nobeni, ga v praksi dobi le brezplačni ljubljenček, ki ostane — sporočiti Davidu.

## 10. Igre

- Kratkoročno: če manjka katerikoli klip igre, je igra **onemogočena** (»Igra se še pripravlja«), nikoli napol delujoča.
- Dolgoročno: klip za vsako fazo igre (čaka → teče → prinese; muca: preži → skoči → ulovi); žogo ali list animira aplikacija sama, zato se besedilo vedno ujema s tem, kar se vidi. Most do načrtovanega 2.5D pristopa.

## 11. Krtača in kretnje

- Krtača sledi prstu, iz dlake se kažejo delčki/iskrice, »umazane« lise izginjajo (kot strgalna srečka), vrstica napredka se polni.
- Enako kot list pri muci, za česanje obeh vrst.

## 12. Hitrost besedil

- Vsako sporočilo ostane najmanj 2,5 s + čas glede na dolžino besedila **(60 ms na znak — potrdil David 21. 10. 2026)**. Zgrajeno v M5-T03.
- Sporočila čakajo v vrsti, se ne prekrivajo, zaprejo se s pritiskom.
- Nastavitve »Hitrost besedil« zaenkrat **ni** (potrdil David 21. 10. 2026).

## 13. Priprava ljubljenčka (ko mediji še niso narejeni)

**David 20. 10.:** uporabnik **ne sme videti**, da se generirajo slike ali videi. Nikakršnega »prvi ste izbrali to pasmo«, nobene omembe AI generiranja.

- Po nakupu: zaslon **»Pripravljamo [ime] … to lahko traja nekaj minut«** z animacijo Curia (nastavljanje aplikacije v ozadju).
- Vse, kar še ni pripravljeno, je označeno nevtralno: »Šola se še pripravlja«, »Igra se še pripravlja«. V šoli se do takrat prikaže poza.
- Vrstni red priprave: najprej to, kar otrok vidi v prvih minutah (stoji, spi, je), nato šola in igre.
- Obvestilo, ko je vse pripravljeno: »[ime] je pripravljen!«
- Vsi uporabniki vidijo enako; pri pasmi/barvi z že narejenimi mediji priprava traja nekaj sekund.
- Za razvoj izmeriti: koliko traja priprava celotnega paketa (fal izvaja videe vzporedno).

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

**Optimizacija (David 20. 10.: strinja se, a najprej primerjava v AI Labu):**
1. Krajši klipi za šolo in igre (3 s namesto 5 s, −40 % pri istem modelu).
2. Stanja (ki jih otrok gleda največ) ostanejo na Kling 3.0 Pro; šola in igre na cenejšem modelu **samo, če je kakovost enaka**.
3. Kandidati za primerjavo: Kling 3.0 Pro, Kling 3.0 Standard (če ga fal ponuja), Kling 2.6 Pro, Veo 3.1 Fast / Lite (720p).
4. Primerjava: isti pes, ista vaja iz šole, vsi kandidati v AI Labu; David oceni realističnost. Šele nato odločitev v `config/media.php`.

## 15. Prioritete

1. §2/§4 (pelji ven) in §8 (onemogočanje) — otrok dobi kazen, ne da bi vedel zakaj; poceni.
2. §12, §10 (onemogočanje iger), §5.
3. §13 (priprava) skupaj z §7.
4. §7, §3, §10 (novi mediji) — po primerjavi modelov (§14).
5. §1, §6, §9, §11.
