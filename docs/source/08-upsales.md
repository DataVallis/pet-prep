## **1. Virtualna ekonomija in mikro-transakcije (IAP)**

Sistem razdelimo na dva tipa nakupov, ki jih otrok "prosi", starš pa jih odobri in plača (preko RevenueCat):

- **Krizni stroški (Veterinar):** Če otrok zanemari higieno ali gibanje do te mere, da pes zboli, se aplikacija "zaklene" in zahteva zdravljenje. Starš mora plačati simboličen znesek (npr. 1,99 EUR ali 2,99 EUR) za "Antibiotike" ali "Obisk klinike". Starš dobi sporočilo: *"V resničnem življenju bi ta obisk stal 150 EUR. Naj bo to lekcija."*

- **Potrošni material (Treats/Priboljški):** Nakupi manjših paketov virtualne valute (npr. 0,99 EUR za "10x Premium briketov"). S tem lahko otrok psu takoj in umetno dvigne energijo ali pa ga nauči novega trika v aplikaciji.

- **Trajne dobrine (Igrače):** Nakup virtualne žogice, frizbija ali pullerja (npr. 1,49 EUR). Ko ima pes igračo, metrika energy_level (potreba po gibanju) pada za odtenek počasneje, saj se pes "zaposli sam".

## **2. B2B Partnerstva in "Real-World" ROI (Mr. Pet)**

To je ultimativni prodajni argument za aplikacijo. Staršem ne prodajamo več samo "igrice", ampak investicijo, ki se jim na koncu povrne.

- **Sistem nagrajevanja:** Ko otrok uspešno pripelje psa do 1. leta starosti (12 realnih tednov brez kritičnih zanemarjanj), se generira uradni **Certifikat odgovornosti**.

- **Integracija s trgovci:** Na podlagi tega certifikata aplikacija staršu v njegov Dashboard pošlje unikatno, unovčljivo QR kodo.

- **Primer ponudbe:** *"Čestitamo! Vaš otrok je dokazal, da je pripravljen. Kot nagrado vam naš partner Mr. Pet podarja 20 % popusta na 'Starter kit za mladička' (postelja, posode, hrana) v vrednosti do 50 EUR."*

- **Affiliate model za nas:** Poleg tega, da smo staršem prodajali in-app nakupe, nam Mr. Pet za vsak unovčen kupon plača še affiliate provizijo (CPA), ker smo jim pripeljali visoko motiviranega in ogretega kupca, ki gre ravno po pravo žival.

## **3. Tehnični popravek za RevenueCat**

Ker smo dodali igrače in veterinarja, bomo morali v RevenueCat konfiguraciji in Laravel bazi uporabiti dve različni vrsti produktov:

1.  **Non-consumables (Trajni nakupi):** Odklep pasme (Border Collie) in nakup igrač. To se shrani za vedno.

2.  **Consumables (Potrošni nakupi):** Plačilo veterinarja in nakup priboljškov. Te nakupe je mogoče opraviti večkrat, RevenueCat pa ob vsakem plačilu strežniku sporoči, naj v bazi (users_inventory) poveča število kovancev ali pa psu ponastavi zdravje na 100 %.

S tem smo aplikaciji drastično dvignili LTV (Life-Time Value) posameznega uporabnika.
