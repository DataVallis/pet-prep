## **Uporabniški vmesnik (UI) in interakcije: Nadzorna plošča starša**

Za razliko od otrokove aplikacije, ki je vizualno bogata in "igralna", mora biti aplikacija za starše zasnovana kot čist, analitičen in pregleden nadzorni center (v stilu bančnih ali fitnes aplikacij). Ker uporabljamo NativeWind (Tailwind), bo vmesnik strog, minimalističen in osredotočen na podatke.

### **1. Zaslon za Onboarding in Povezovanje (Pairing)**

- **Ustvarjanje gospodinjstva:** Starš se prijavi z Apple/Google računom in klikne gumb "Dodaj otroka".


- **Generiranje PIN kode:** Na zaslonu se izpiše velika, krepka 6-mestna koda (npr. **734-912**), ki velja 15 minut. Starš to kodo narekuje otroku, ki jo vnese v svojo aplikacijo, s čimer se podatkovni bazi (PostgreSQL) trajno povežeta.


### **2. Glavni zaslon (Real-time Dashboard)**

- **Statusni semafor (Traffic Light System):** Na vrhu zaslona je velik barvni indikator celotnega zdravja simulacije:

- - **Zelena:** Otrok redno izpolnjuje obveznosti.

  - 

  - **Rumena:** Otrok je danes zamudil več kot 2 nalogi (npr. preskočil jutranji sprehod).

  - 

  - **Rdeča:** Kritično stanje, žival trpi in opozorila so bila ignorirana.

  - 

- **Žive metrike (Laravel Reverb):** 4 vizualni merilniki (Lakota, Žeja, Gibanje, Higiena), ki so identični otrokovim. Zaradi WebSocketov se te vrednosti pred očmi starša premaknejo takoj, ko otrok na svojem telefonu nahrani psa, brez potrebe po osveževanju strani.


### **3. Zaslon za analitiko (Activity Log)**

- **Dnevna časovnica (Timeline):** Kronološki seznam vseh akcij, ki jih vleče iz tabele activities_log.

- - *Primer:* "✓ 07:15 - Pes nahranjen."

  - 

  - *Primer:* "✗ 14:00 - Zamujeno čiščenje iztrebkov."

  - 

  - *Primer:* "✓ 18:30 - Prehojenih 5.120 korakov."

  - 

- **Tedenski graf uspešnosti:** Preprost stolpčni graf, ki staršu hitro vizualizira, ob katerih dnevih otrokova motivacija najbolj pade (npr. ob vikendih pogosto pozabi na psa).


### **4. Trgovina in monetizacija (RevenueCat Integracija)**

- **Izbira in plačilo pasme:** Pred začetkom simulacije (ali ob "resetu") starš na tem zaslonu izbere žival.

- - **Osnovna izbira:** "Mešanček (Brezplačno)" – standardne potrebe po gibanju.

  - 

  - **Premium izbira:** Kartica s sliko (Kling 3.0) in napisom "Border Collie". Spodaj je nativen gumb (Apple Pay / Google Pay) s ceno 4,99 €.

  - 

- Ko se plačilo preko RevenueCat uspešno avtorizira, se izbira samodejno odklene v otrokovi aplikaciji.


### **5. Nastavitve in Krizno upravljanje (Intervencija)**

- **Prilagoditev urnika:** Starš lahko določi "tihe ure" (npr. med 8:00 in 13:00, ko je otrok v šoli), v katerih virtualna žival ne pošilja obvestil in njene metrike padajo bistveno počasneje.


- **Gumb "Začasno ustavi simulacijo" (Hard Stop):** Rdeč opozorilni gumb na dnu zaslona. Če ga starš pritisne, se otrokova aplikacija takoj zaklene, kar staršu omogoči, da z otrokom opravi vzgojni pogovor o odgovornosti, preden simulacijo ponovno zažene.


Glede na to, da si popolnoma osredotočen izključno na ta projekt in imava tehnološki stack ter uporabniško izkušnjo (UX/UI) v celoti definirano za oba profila, predlagam, da se sedaj posvetiva "možganom" celotne operacije.
