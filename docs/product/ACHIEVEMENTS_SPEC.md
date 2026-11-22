# PetPrep: Album dosežkov (nalepke)

> **Status:** *načrt*, samo specifikacija. Ideja je Davidova (22. 11. 2026, po zgledu peaksters.pro: trofeje, značke, napredek, zbiranje nalepk). Pravila so osnutek Claude, ki ga potrdi David (§8).
> **Kdaj:** gradnja **po 8. tednu validacije** (VALIDATION_PLAN §2 zamrzne nove funkcije). Druga možnost je vključitev v val 2 kot zavesten test zadrževanja (§7). Nič od tega še ni zgrajeno.

## 1. Zakaj in meja

PetPrep staršu dokazuje, ali bo otrok skrbel za žival **tudi brez nagrade**: pravi pes ne deli nalepk. Če bi otrok hranil kužka zaradi nalepke, bi ocena skrbi (Care Score) in certifikat merila zanimanje za zbirko, ne pripravljenosti. Zato velja eno pravilo:

> **Nalepka praznuje, kaj se je otrok naučil in kako je ljubljenček zrasel. Nikoli ne plača osnovne skrbi.**

Za osnovno skrb (hrana, voda, čiščenje, sprehod, »Pelji ven«) ni nalepk in ni točk. Album ne vpliva na Care Score, semafor, rutine, certifikat ali potrebe (kot S1 v SHOP_SPEC in igra z žogo, PLAY_CUDDLE_SPEC).

## 2. Česa ne delamo

- Nalepk ali točk za posamezno dejanje osnovne skrbi.
- Nizov, ki ob prekinitvi izgorijo, in sporočil v slogu »ne izgubi niza« ali »zamudil si«.
- Nakupljivih ali naključnih nagrad (loot box), kovancev ali valute. Nalepk ni mogoče kupiti v »Željah«.
- Lestvic in primerjav med otroki ali družinami. Pri skupnem psu brat ali sestra nikoli ne izgubi nalepke zaradi drugega.
- Odvzema nalepk. Kar je enkrat v albumu, ostane, tudi po game overu.
- Potisnih obvestil o nalepkah.

## 3. Katere nalepke (osnutek)

Vsaka nalepka izhaja iz podatkov, ki jih igra že zapisuje. Nove sledljivosti ni.

| Skupina | Nalepka | Kdaj | Vir v igri |
|---|---|---|---|
| Pot skozi izziv | Prvi dan, Prvi teden, Prvi mesec, Polovica poti, Konec izziva | dan / teden programa (programska ura, čas zaklepa do plačila ne šteje) | `Pet::programInstantAt`, M3-11b |
| Ljubljenček raste | Mlad pes, Odrasel pes (pri mački po vrsti), Prvi rojstni dan | prehod obdobja, rojstni dan | `LifeStageService`, `PetBirthdayService` |
| Šola | »Sedi ✓«, »Pridi ✓«, »Prostor ✓«, »Lulat zunaj ✓« | ukaz doseže 100 % (naučen ukaz ne pada nikoli) | `TrainingService` |
| Spoznavam pasmo | Prvih 5 / 10 / 20 prebranih zanimivosti | branja »Ali si vedel?« | `breed_fact_reads`, M5-R09 |
| Mladiček spoznava svet | Nova izkušnja: človek, kuža, zvok, mesto | vsaka vrsta prvič | `socialised_pet`, M5-R17 |
| Lep teden | »Teden brez zamujenega sprehoda« | zaključen teden programa brez zamujene rutine sprehoda (pri mački igra), šteje vsak tak teden posebej, ni niza | `RoutineLedgerService` |

Otrok ima za vsako skupino eno stran albuma. Še nedosežene nalepke so sive silhuete z enim stavkom, kako jo dobiš (»Ko bo kuža znal sesti«). Nikoli ne pišemo »zamujeno«.

Pri skupnem psu nalepke skupine »Lep teden« in »Šola« dobijo vsi otroci, ki skrbijo zanj. Nalepke skupine »Spoznavam pasmo« so po otroku.

Brezplačni plan: album je na voljo tudi za mešančka in domačo mačko. Skupina »Spoznavam pasmo« pri njih odpade (nimajo zanimivosti o pasmi).

## 4. Kako izgleda

- **Otrok:** gumb »Album« v zgornji vrstici HUD-a ob knjigi. Ob novi nalepki se enkrat, v mirnem trenutku (enaka pravila kot okno »Ali si vedel?«, ne med mini-igro ali v tihih urah), pokaže kartica »Nova nalepka!« s Curiom. Brez zvoka nagrade, brez ponavljanja.
- **Starš:** v podrobnostih otroka vrstica »Album: 7 nalepk« in seznam z datumi. Kot pogled na napredek, ne kot točke.
- **Certifikat:** ostane velika nagrada na koncu. Album je pot do njega in ga ne nadomesti.
- Nalepke so ilustracije v slogu znamke (BRAND.md). Pri nalepkah z ljubljenčkom lahko kasneje uporabimo njegovo AI sliko (»Odrasel pes« z njegovim portretom), a samo iz že narejenih medijev, brez novih klicev AI.

## 5. Tehnično (osnutek)

- Tabela `child_achievements` (child_user_id, pet_id, key, earned_at), unikatno po (otrok, ljubljenček, ključ). Zapis ob dogodku, ki ga igra že beleži (`activities_log`, prehod obdobja, branje zanimivosti), plus nočno dopolnjevanje za zaključene tedne.
- `achievements` v stanju otroka in v `PetUpdated` (nova nalepka → `achievement_earned`, po commitu). Pri staršu je v poročilu otroka.
- Katalog nalepk je v konfiguraciji (ključ, skupina, pogoj, besedila EN + SL in vseh jezikov aplikacije).
- Ob vklopu se obstoječim ljubljenčkom nalepke za že dosežene mejnike dodelijo za nazaj, brez kartice »Nova nalepka!« za stare.
- Izvoz podatkov in izbris otroka ali družine pokrijeta novo tabelo. Brez osebnih podatkov o otroku, samo ključi in časi.
- Stikalo v administraciji (privzeto izklopljeno), kot pri validacijskih signalih.

## 6. Kako preverimo, da ne škodi

- **H2 (otrok aktiven 7. dan) in zadrževanje v 4. tednu** primerjamo pred albumom in po njem.
- **Care Score ne sme rasti »umetno«:** ker nalepke ne nagrajujejo osnovne skrbi, se ocena ne bi smela spremeniti. Če se, preverimo, zakaj.
- Kratko vprašanje v anketi staršem (VALIDATION_PLAN §4.5): »Ali je album otroka bolj motiviral za skrb ali za zbiranje?«

## 7. Kdaj graditi

- **Priporočilo:** po 8. tednu validacije, ko imamo izmerjen H2 brez albuma.
- **Možnost:** v valu 2 kot zavesten test. Stikalo vklopimo samo za del družin in primerjamo zadrževanje. Pri tako majhnem številu družin je rezultat grob kazalnik (VALIDATION_PLAN §3).

## 8. Odprto za Davida

1. Seznam nalepk v §3: kaj dodati, kaj odstraniti.
2. Ali »Lep teden« sploh (je najbližje nagradi za skrb) ali samo mejniki učenja in rasti.
3. Ali starš vidi album otroka v celoti ali samo število.
4. Ilustracije: ena nalepka na mejnik (cenejše) ali z ljubljenčkovo sliko.
5. Gradnja po validaciji ali test v valu 2.
