## **Uporabniški vmesnik (UI) in interakcije: Aplikacija otroka**

Ker UI temelji na minimalizmu (Tailwind / NativeWind) in je v središču fotorealistična AI žival (Kling 3.0), mora biti vmesnik zasnovan kot prosojen "hud" (Heads-Up Display) preko celozaslonskega videa ali slike živali.

### **1. Zaslon za onboarding in seznanjanje (Pairing Screen)**

- **Vnos PIN kode:** Preprosto vnosno polje na sredini zaslona, kamor otrok vpiše 6-mestno kodo, ki mu jo je generiral starš v svoji aplikaciji.


- **Slovesna zaobljuba:** Ko je koda sprejeta, se prikaže digitalna "Pogodba o odgovornosti". Otrok se mora s prstom (touch signature) podpisati na zaslon, preden se žival "rodi".


### **2. Glavni zaslon (Core Game Loop)**

- **Vizualno ozadje (AI Video):** Celozaslonska animacija psa. Video se dinamično menja glede na stanje iz baze (če je parameter energy_level nizek, se predvaja video psa, ki spi; če je hunger_level nizek, pes praska po prazni posodi).


- **Zgornja statusna vrstica (Glassmorphism stil):**

- **  
  > **

  - Informacija o virtualni starosti (npr. "Starost: 2 meseca"). To se izračuna iz born_at v bazi podatkov (1 realen teden = 1 virtualni mesec).

  - 

- **Stranski indikatorji (Desni rob):**

- **  
  > **

  - 4 preprosti vertikalni drsniki (Progress bars), ki prikazujejo raven v odstotkih (0-100%): **Lakota, Žeja, Gibanje, Higiena**. Barve se prelivajo od zelene do rdeče glede na kritičnost.

  - 

- **Akcijski gumbi (Spodnji rob):**

- **  
  > **

  - Prosojni okrogli gumbi z ikonami (Briketi, Kaplja vode, Povodec, Metla). Gumbi niso vedno aktivni (npr. hranjenje je možno samo v določenem časovnem oknu, sicer je gumb zasenčen).

  - 

### **3. Aktivni modul: Sprehod**

- Ko otrok klikne na ikono povodca, se odpre prosojen "overlay" zaslon za sledenje aktivnosti.


- **Integracija senzorjev:** Aplikacija preko knjižnice prebere trenutno stanje korakov iz Apple Health / Google Fit.


- **Prikaz cilja:** Velik števec na sredini (npr. "1.250 / 5.000 dnevnih korakov").


- **Mehanika:** Otrok mora aplikacijo pustiti teči v ozadju, telefon pospraviti v žep in dejansko hoditi. Ko se vrne domov in odpre aplikacijo, se koraki sinhronizirajo v bazo (activities_log), metrika energy_level pa se dvigne.


### **4. Krizna opozorila in prekinitve (Push Notifications & Modals)**

- **Push obvestila:** Aplikacija uporablja lokalna obvestila (Expo Notifications). Zjutraj npr. sproži zvok cviljenja in obvestilo: *"Tvoj pes mora lulat!"*


- **Prisilna interakcija (Zaslon za čiščenje):** Če hygiene_level pade na 0, celoten zaslon prekrije umazanija (naključno zgeneriran AI vizual nereda). Otrok ne more početi ničesar drugega (ne more ga hraniti ali peljati na sprehod), dokler s prstom ne podrsa po zaslonu tolikokrat, da "očisti" madeže, kar vrne higieno na 100 %.


### **5. Zaklenjen zaslon (Hard Stop)**

- Če nadzorni profil (starš) v svoji aplikaciji sproži prekinitev (ker se otrok ni držal dogovora), otroka ob odprtju aplikacije pričaka črn zaslon s statičnim sporočilom (npr. *"Simulacija je začasno ustavljena. Pogovori se s starši."*) in gumbom za ponovni vnos PIN kode, ko starš odobri nadaljevanje.


Na tem zaslonu se ob vsakem kliku sproži API klic, ki shrani zapis v PostgreSQL in preko Reverb-a posodobi stanje v milisekundi.
