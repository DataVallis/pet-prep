# PetPrep — Trgovinica za ljubljenčka (osnutek, *načrt*)

> Status: **specifikacija potrjena (David 26. 10. 2026), nič še ni zgrajeno.** Ideja in odločitve: David 26. 10. 2026. Nadgrajuje vrstico »Priboljški, veterinar, igrače (IAP)« (FEATURES ⏸) in BUSINESS_MODEL §3 (IAP).

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

## 3. Potek

1. **Otrok:** na zaslonu ljubljenčka gumb »Želje« odpre katalog s slikami in imeni, brez cen. Otrok tapne »Prosi starša«. Največ **3 odprte prošnje** naenkrat (S9); že kupljene trajne stvari so označene »Že ima«.
2. **Starš:** dobi push »Otrok si želi posteljo za Suki« (brez imena otroka v obvestilu, kot vsa obvestila) in na nadzorni plošči vrstico »Želje (2)«. Seznam pokaže stvar, ceno iz trgovine, kdo je prosil in kdaj.
3. Starš pri vsaki prošnji izbere **Kupi** (nakup v trgovini) ali **Ne zdaj** (otrok vidi prijazno »Mogoče kdaj drugič«, brez razlage o ceni). Kupi lahko tudi brez prošnje, kot presenečenje.
4. **Po nakupu:** strežnik zapiše stvar k ljubljenčku in sproži generiranje vsebine (§5). Dokler ni pripravljena, otrok vidi »Postelja je na poti …«, nato pa obvestilo »Suki ima novo posteljo!«.

## 4. Katalog (predlog za prvo verzijo)

| Stvar | Vrsta | Kje se vidi | Pes / mačka |
|---|---|---|---|
| Postelja | trajna | spanje (ključna slika + video spanja na postelji namesto na tleh) | oba (pri mački košara) |
| Skledi (hrana, voda) | trajna | hranjenje / pitje | oba |
| Igrača (npr. žoga, vrv) | trajna | igra (video igre z igračo) | pes; mačka: miška / palica |
| Priboljški (paket 10) | potrošna | gumb »Priboljšek« → kratek video, kako ga poje; porabi se 1 | oba |

Cene so **odprte** (§8); BUSINESS_MODEL §3 omenja igrače 1,49 € in priboljške 0,99 € za 10.

## 5. Unikatne AI vsebine in strošek

- Vsebina nastane z obstoječo verigo AI medijev: ključna slika (urejanje obstoječega videza psa z dodano stvarjo, Nano Banana Pro, ~0,15 $) + kratek video (Kling 3.0 Pro, 0,112 $/s, 3 s ≈ 0,34 $).
- **Približno 0,50 $ na stvar na videz.** Videz je skupen za pasmo + barvo + obdobje (BREED_COLOURS_SHARED_MEDIA), zato **naslednji kupci iste stvari za isti videz ne stanejo nič**.
- Ko pes preide v novo obdobje (mladiček → mlad pes …), trajne stvari potrebujejo novo vsebino za novo obdobje (spet ~0,50 $, spet skupno).
- **Priboljški:** en video »poje priboljšek« na videz, nato se ponavlja. Paket 10 ne pomeni 10 generacij.
- Strošek gre skozi AiSpendGuard (dnevna meja). Če je meja dosežena, se vsebina ustvari naslednji dan, otrok pa medtem vidi »na poti«.
- Brezplačni plan ima danes samo osnovni nabor videov (miruje, spi). Kupljena stvar doda **samo svoje** vsebine, ne odklene celotnega nabora (S8).

## 6. Tehnično (osnutek)

- **Trgovina:** izdelki v App Store / Google Play prek RevenueCat. Trajne stvari kot *non-consumable* na ljubljenčka? Ker je non-consumable vezan na račun, ne na ljubljenčka, je verjetno bolje **consumable + strežnik vodi lastništvo** (kot `petprep_challenge_12w`, PAYMENTS_SPEC P1). Priboljški so *consumable*.
- **Strežnik:** tabele `shop_items` (katalog), `pet_wishes` (prošnja: pet, child, item, status open/bought/declined), `pet_items` (lastništvo, ostanek priboljškov); webhook RevenueCat ustvari kredit, starš ga dodeli ljubljenčku (enako kot izziv).
- **Mediji:** novi tipi stanja / prekrivni sloji za stvari v obstoječem `state_media` (postelja = varianta stanja spanja).
- **Zasebnost:** prošnja vsebuje samo ID otroka in stvari; brez novih osebnih podatkov.

## 7. Česa NE delamo

Brez cen pri otroku, brez nakupov v otroški seji, brez vpliva na igro (S1), brez veterinarja in zdravil (S2), brez naključnih nagrad (loot box), brez oglasov.

## 8. Odprto

Nič — vse odločitve so v §2 (David 26. 10. 2026). Pred gradnjo: preveriti cene fal.ai in tečaj, izdelke v App Store / Google Play (cenovni razredi 5,99 € in 2,99 €), pravila trgovin za otroške aplikacije (nakup samo za starševsko zaščito — že velja za izziv).
