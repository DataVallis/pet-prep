Idejo popolnoma razumem. Gre za "Tamagotchi za 21. stoletje z resničnimi posledicami", ki rešuje ogromen problem impulzivnih nakupov hišnih ljubljenčkov. Koncept odlično prepleta gamifikacijo in odgovornost.

Preden preideva na dokumentacijo, imam zate kot Product Owner tri ključna vprašanja, ki bodo vplivala na tehnično arhitekturo in psihologijo aplikacije:

1.  **Kaj se zgodi ob "smrti" ali odvzemu živali?** Ali uvedeva "časovno kazen" (npr. otrok ne more začeti z novo živaljo 14 dni), da preprečiva, da bi to obravnavali zgolj kot videoigro, kjer samo pritisneš *Restart*?


3.  **Kdo je končni sodnik?** Ali aplikacija na koncu enega leta generira uradni "Certifikat odgovornosti", ki ga otrok pokaže staršem (npr. "98% uspešnost skrbi za žival"), s čimer dokaže, da je pripravljen?


5.  **Povezava z resničnim vremenom in lokacijo?** Ali uporabimo API za vreme, tako da mora otrok psa peljati ven tudi, ko dežuje ali sneži (in ga potem v aplikaciji "obrisati", da ne zboli)?


## **Produktna specifikacija (PRD): Projekt "PetPrep" (Delovno ime)**

### **1. Ciljna publika in vrednostna ponudba (Value Proposition)**

- **Primarni uporabniki:** Otroci in najstniki (7–16 let), ki si želijo žival.


- **Kupci (Decision Makers):** Starši, ki želijo preveriti otrokovo odgovornost, preden investirajo denar in svoj čas v pravo žival.


- **Sekundarni uporabniki:** Odrasli, ki želijo preveriti, ali je določena zahtevna pasma (npr. Border Collie, Husky) združljiva z njihovim življenjskim slogom.


### **2. Poslovni model in monetizacija**

Aplikacija mora delovati po modelu, kjer starš prevzame vlogo finančnega skrbnika.

- **Freemium osnova:** Aplikacija je brezplačna in ponuja osnovnega "mešančka", ki nima specifičnih genetskih zahtev.


- **In-App nakupi (Plačilo za pasmo):** Starš mora za virtualnega čistokrvnega psa plačati (npr. 10–20 EUR enakovredno "posvojnini"). Ta pes ima specifične zahteve (zlatemu prinašalcu izpada dlaka in rabi veliko nege, haski potrebuje 10 km teka na dan).


- **Mesečna naročnina (Pro skrbnik):** Za 3–5 EUR/mesec starš dobi dostop do napredne analitike, kje je otrok "zabodel", in opcij pošiljanja in-app žepnine za veterinarske stroške.


- **B2B Partnerstva (Affiliate):** Ko uporabnik zaključi 1-letni cikel in dobi "Certifikat odgovornosti", mu aplikacija ponudi ekskluzivne popuste v pravih trgovinah za male živali (Mr. Pet, Zootic) ali pri zavarovanju za pravo žival.


### **3. Uporabniška izkušnja in tok (User Flow)**

1.  **Registracija (Dualni način):** Ustvarita se dva povezana profila – nadzorni (starš) in izvajalni (otrok).


3.  **Izbira in posvojitev:** Otrok in starš skupaj prebrskata enciklopedijo znotraj aplikacije in izbereta žival. Sistem opozori na predviden dnevni čas, potreben za to vrsto/pasmo.


5.  **Podpis pogodbe:** Otrok v aplikaciji digitalno podpiše "obljubo odgovornosti".


7.  **Onboarding živali:** Žival pride domov kot mladiček (potrebuje učenje na sobno čistočo, ponoči "joka" in pošilja notifikacije).


9.  **Dnevna rutina (Daily Loop):** Opravljanje nalog v realnem času na podlagi življenjskih potreb izbrane živali.


### **4. Ključne funkcionalnosti (Core Features)**

- **Senzorska integracija za gibanje:** Aplikacija uporablja GPS in pedometer (Apple Health / Google Fit). Če pes potrebuje 5 km sprehoda, mora uporabnik fizično s telefonom prehoditi 5 km na dan. "Goljufanje" (vožnja z avtom) se zazna preko hitrosti in se ne upošteva.


- **Časovna asimetrija:** Dnevi potekajo normalno 24 ur, fiziološki razvoj živali pa je 5x hitrejši. V enem letu pes doseže zrelost, se postara in lahko dobi starostne bolezni.


- **Obogatena resničnost (AR):** Uporabnik mora odpreti kamero, da vrže virtualno žogo na svojem dvorišču, in s prstom pospraviti virtualne iztrebke z asfalta.


- **Skrb in higiena:** Hranjenje ob točno določenih urah. Če zamuja, žival zgubi na teži in postane letargična. Mačji WC je potrebno čistiti dnevno; če se ga ne, mačka opravi potrebo "po stanovanju" (kar zahteva kazensko mini-igro čiščenja).


- **Dinamični AI algoritem obnašanja:** Žival se uči iz otrokovega obnašanja. Če je otrok nežen in reden, postane žival ubogljiva. Če je pes veliko sam v virtualnem stanovanju, razvije separacijsko anksioznost (uničuje virtualno pohištvo, kar stane virtualni denar).


- **Veterinar in zdravje:** Nujna cepljenja (koledarski opomniki). Če otrok psa pelje ven na GPS dež in ga ne obriše, žival zboli, kar pomeni "obisk veterinarja" in "kupovanje" antibiotikov z virtualnim ali pravim denarjem.


### **5. Starševska nadzorna plošča (Parent Dashboard)**

- **Heatmap in Analitika:** Prikaz grafov (npr. "Tvoj otrok je ta teden 3x pozabil zamenjati vodo").


- **SOS gumb:** Če gre živali kritično slabo, lahko starš začasno pošlje žival v "varstvo", a to otroku močno zniža oceno.


### **6. Tehnični poudarki**

- **Baterija:** Ker bo aplikacija veliko v ozadju preverjala lokacijo in korake, mora biti GPS sledenje optimizirano in vezano le na aktivacijo modula "Sprehod".


- **Push Notifikacije:** Morajo biti nadležne in realistične (npr. ob 4:30 zjutraj opozorilo "Pes mora nujno ven na potrebo" – pri mladičkih).
