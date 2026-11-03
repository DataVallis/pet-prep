# PetPrep — Trgovinica za ljubljenčka

> Status: **specifikacija potrjena (David 26. 10. 2026); strežnik zgrajen 27. 10. 2026 (M6-W01 + strežniški del W02, API `docs/engineering/WISHES_API.md`), aplikacija 27. 10. 2026 (M6-W04 + W05); AI vsebine stvari z dizajni (M6-W03) — strežnik 3. 11. 2026 (David: »popolno funkcionalno … mora delat kot je zastavljeno«), aplikacija 3. 11. 2026 (prikaz v prizorih + izbira dizajna, pet-prep #652); nakup deluje, ko bodo izdelki v trgovinah (W02).** Dopolnitve 27. 10.: dve igrači na vrsto, priboljški največ 2 na dan, nakup iz želje gre ljubljenčku želje, brez stikala (DECISIONS). Ideja in odločitve: David 26. 10. 2026. Nadgrajuje vrstico »Priboljški, veterinar, igrače (IAP)« (FEATURES ⏸) in BUSINESS_MODEL §3 (IAP).

## 1. Ideja

Otrok v aplikaciji prosi za stvari za svojega ljubljenčka, na primer posteljo, ker zvečer spi na tleh, igračo ali priboljške. Prošnje se staršu nabirajo na seznamu na nadzorni plošči. Starš pregleda seznam, izbere in kupi. Ljubljenček nato dobi **unikatno AI vsebino**, na primer sliko in video, kako spi na svoji novi postelji.

**Zakaj:** otrok spozna, da ljubljenček stane tudi po nakupu, in da o nakupu odloča starš. Za PetPrep je to dodaten prihodek, ki je na voljo **tudi v brezplačni verziji**.

## 2. Odločitve (David 26. 10. 2026)

| # | Odločitev |
|---|---|
| S1 | **Samo videz.** Kupljena stvar ne vpliva na igro: ne na upadanje potreb, ne na oceno skrbi (Care Score), ne na certifikat. Nikoli »plačaj, da bo lažje«. |
| S2 | **Ponudba na začetku:** trajne stvari (postelja, skledi, ovratnica), igrače, priboljški (potrošni). Veterinar in zdravila **ne** — da nikoli ne bi bilo »plačaj, da rešiš psa«. |
| S3 | **Na voljo tudi v brezplačni verziji** (mešanček, domača mačka) in pri izzivu. |
| S4 | **Otrok prosi iz kataloga brez cen**, z omejitvijo števila odprtih prošenj. Starš dobi obvestilo in seznam na nadzorni plošči. |
| S5 | **Kupuje samo starš**, v starševski aplikaciji (zaščitena z geslom, enako kot izziv — PAYMENTS_SPEC). Otrok cen nikoli ne vidi. |
| S6 | **Cene z maržo vsaj 300 %** (neto po DDV 22 % in proviziji trgovine 15 % ≥ 4× strošek AI vsebine v najslabšem primeru): **postelja, skledi, igrača 5,99 €**, **priboljški 2,99 € za 10**. Polna vsebina: v vsakem obdobju psa slika + video. |
| S7 | **Ovratnica pozneje** (ne v prvi verziji): pomeni nov videz psa za vsa stanja (~12 $ na obdobje). |
| S8 | **Brezplačni plan:** kupljena stvar doda samo svojo vsebino (npr. video igre z novo igračo); ostali videi ostanejo osnovni (miruje, spi). |
| S9 | **Največ 3 odprte prošnje** na otroka; po »Ne zdaj« lahko otrok za isto stvar spet prosi po 7 dneh. |
| S10 | **Ime funkcije: »Želje«** (EN »Wishes«). |
| S12 | **Paket »Dobrodošlica za kužka« 15,99 €** (postelja + skledi + igrača; posamezno 17,97 €). Ni stvar v katalogu za otroka — starš ga vidi na seznamu »Želje« (npr. ko otrok prosi za eno od treh: »ali vse tri v paketu za 15,99 €«) in v ponudbi brez prošnje. Stvari, ki jih ljubljenček že ima, se ne podvojijo: paket je na voljo samo, ko nima nobene od treh (*predlog Claude*). Pri muci isti paket z mačjimi stvarmi (košara, skledi, igrača). |
| S11 | **Nakup je nakup, vračila ni:** ob koncu igre (game over) ali izbrisu ljubljenčka nakup ostane porabljen (kot izziv, PAYMENTS_SPEC P5). |
| S13 | **Dizajni (David 3. 11. 2026):** vsaka trajna stvar ima **3 dizajne na vrsto** (pes: 3 postelje, 3 skledi, 3 vrvi, 3 piskajoče igrače; muca: 3 košare / posteljice, 3 skledi, 3 miške, 3 palice s peresi). Vsak dizajn ima **vnaprej narejeno sliko v katalogu** (sama stvar, brez živali). **Otrok izbere dizajn** ob prošnji, starš kupi **točno ta dizajn**; pri nakupu brez prošnje izbere starš (če ne izbere, prvi dizajn); paket »Dobrodošlica« ima dizajn za vsako stvar (privzeto prvi, stvar iz prošnje obdrži otrokov dizajn). AI vsebina ljubljenčka s stvarjo nastane iz **tega dizajna** (isti opis predmeta in kataloška slika kot druga referenca). Stvari, kupljene pred dizajni, dobijo **prvi dizajn** svoje stvari. Priboljški nimajo dizajnov. |

## 3. Potek

1. **Otrok:** na zaslonu ljubljenčka gumb »Želje« odpre katalog s slikami in imeni, brez cen. Pri stvari izbere enega od treh dizajnov (S13; slika dizajna, dokler še ni narejena, je ni — prošnje to ne ustavi) in tapne »Prosi starša«. Največ **3 odprte prošnje** naenkrat (S9); že kupljene trajne stvari so označene »Že ima«.
2. **Starš:** dobi push »Vaš otrok si želi posteljo.« z imenom ljubljenčka v naslovu (brez imen v besedilu, kot vsa obvestila; največ 1 na otroka na 10 minut) in na nadzorni plošči vrstico »Želje (2)«. Seznam pokaže stvar, ceno iz trgovine, kdo je prosil in kdaj.
3. Starš pri vsaki prošnji izbere **Kupi** (nakup v trgovini) ali **Ne zdaj** (otrok vidi prijazno »Mogoče kdaj drugič«, brez razlage o ceni). Kupi lahko tudi brez prošnje, kot presenečenje.
4. **Po nakupu:** strežnik zapiše stvar (z dizajnom) k ljubljenčku in sproži generiranje vsebine (§5). Dokler ni pripravljena, otrok vidi »Postelja je na poti …«, nato pa obvestilo (naslov = ime ljubljenčka, npr. »Suki«; besedilo »Nova postelja je tu. Pridi pogledat!« — ime nikoli v besedilu, pravilo vseh obvestil).

## 4. Katalog (predlog za prvo verzijo)

| Stvar | Vrsta | Kje se vidi | Pes / mačka |
|---|---|---|---|
| Postelja | trajna | spanje (ključna slika + video spanja na postelji namesto na tleh, v zanki) | oba (pri mački košara); 3 dizajni na vrsto |
| Skledi (hrana, voda) | trajna | hranjenje (ključna slika + video, enkrat) | oba; 3 dizajni na vrsto |
| Igrača 1 / 2 | trajna | igra (ključna slika + video igre z igračo, v zanki) | pes: vrv, piskajoča igrača; mačka: miška, palica s peresi; 3 dizajni vsaka |
| Priboljški (paket 10) | potrošna | gumb »Priboljšek« → kratek video, kako ga poje (enkrat); porabi se 1 | oba; brez dizajnov |

Dizajni (ključi, imena, opisi): `backend/config/shop_designs.php`, seznam v `docs/engineering/WISHES_API.md` §1. Cene: §2 S6 (5,99 € / 2,99 €).

## 5. Unikatne AI vsebine in strošek

**Zgrajeno 3. 11. 2026 (M6-W03, `Media\ItemMediaService`):**
- **Kataloške slike:** ena slika na dizajn (samo stvar, mehko nevtralno ozadje, kvadrat; Nano Banana Pro ~0,15 $) — **24 slik × 0,15 $ = 3,60 $ enkrat**, naredi jih samodejni korak `media:item-media` (vsakih 10 minut) takoj po namestitvi; superadmin lahko eno ponovi v /admin.
- Vsebina ljubljenčka nastane z obstoječo verigo AI medijev: **ključna slika** (urejanje trenutne slike ljubljenčka — slike njegovega obdobja — s stvarjo; Nano Banana Pro Edit ~0,15 $; kataloška slika dizajna gre zraven kot druga referenca, da je predmet enak) + **kratek video** (Kling 3.0 Pro, 3 s ≈ 0,34 $, iz ključne slike). Postelja: spi na njej (zanka); skledi: je (enkrat); igrača: se igra (zanka); priboljšek: ga poje (enkrat).
- **≈ 0,49 $ na stvar (dizajn) na videz in obdobje.** Videz je skupen za pasmo + barvo + obdobje (BREED_COLOURS_SHARED_MEDIA), zato **naslednji kupci istega dizajna za isti videz ne stanejo nič**. Ljubljenček z lastnimi (unikatnimi) mediji dobi svojo vsebino.
- Ko ljubljenček preide v novo obdobje (nova slika), trajne stvari samodejno dobijo novo vsebino za novo obdobje (spet ~0,49 $, spet skupno); stara se ne kaže več. Ob tem ni novega obvestila.
- **Priboljški:** en video »poje priboljšek« na videz in obdobje, nato se ponavlja. Paket 10 ne pomeni 10 generacij.
- Strošek gre skozi AiSpendGuard (proizvodni proračun). Če je meja dosežena, vsebina počaka, da proračun spet dopušča, otrok pa medtem vidi »na poti«.
- **Samopopravljanje (kot pri posnetkih dejavnosti, PR #547):** neuspeh se samodejno ponovi največ 2-krat; ko fal zavrne zahtevo (`no_media_generated`), gre naslednja različica poziva (nikoli enaka zahteva); ko ni več možnosti, se stvar pokaže **brez posebne vsebine** (otrok nikoli ne obtiči na »Na poti«) in skrbnik dobi eno obvestilo v Discord (samo številke). Skrbnik ni nikoli potreben.
- Ljubljenček brez AI medijev (mediji izklopljeni, brez slike) dobi stvar takoj, brez vsebine (aplikacija pokaže narisan prizor).
- Brezplačni plan ima samo osnovni nabor videov (miruje, spi). Kupljena stvar doda **samo svoje** vsebine, ne odklene celotnega nabora (S8). Stvari nikoli ne vplivajo na igro (S1).

## 6. Tehnično (osnutek)

- **Trgovina:** izdelki v App Store / Google Play prek RevenueCat. Trajne stvari kot *non-consumable* na ljubljenčka? Ker je non-consumable vezan na račun, ne na ljubljenčka, je verjetno bolje **consumable + strežnik vodi lastništvo** (kot `petprep_challenge_12w`, PAYMENTS_SPEC P1). Priboljški so *consumable*.
- **Strežnik:** tabele `shop_items` (katalog), `pet_wishes` (prošnja: pet, child, item, status open/bought/declined), `pet_items` (lastništvo, ostanek priboljškov); webhook RevenueCat ustvari kredit, starš ga dodeli ljubljenčku (enako kot izziv).
- **Mediji (zgrajeno 3. 11. 2026):** lastna tabela `item_media` (kataloška slika / vsebina videza / vsebina ljubljenčka), ne vrstice `pet_media`; aplikacija dobi vsebino v `media.items` (podpisani URL-ji), `media_ready` pri stvareh; dogodek `item_media_ready`, obvestilo otrokom `item_ready`. API: `docs/engineering/WISHES_API.md` §8.
- **Zasebnost:** prošnja vsebuje samo ID otroka in stvari; brez novih osebnih podatkov.

## 7. Česa NE delamo

Brez cen pri otroku, brez nakupov v otroški seji, brez vpliva na igro (S1), brez veterinarja in zdravil (S2), brez naključnih nagrad (loot box), brez oglasov.

## 8. Odprto

Nič — vse odločitve so v §2 (David 26. 10. 2026). Pred gradnjo: preveriti cene fal.ai in tečaj, izdelke v App Store / Google Play (cenovni razredi 5,99 € in 2,99 €), pravila trgovin za otroške aplikacije (nakup samo za starševsko zaščito — že velja za izziv).
