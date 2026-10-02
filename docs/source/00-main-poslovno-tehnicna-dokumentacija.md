POSLOVNO-TEHNIČNA DOKUMENTACIJA PROJEKTA "PETPREP"

**Celovit pregled arhitekture, igralne zanke, virtualne ekonomije in monetizacijskega lijaka**1. STRATEŠKI PREGLED IN VREDNOSTNA PONUDBA (AT A GLANCE)

Projekt **PetPrep** naslavlja akutno čustveno in finančno bolečino staršev, ki se soočajo z nenehnim pritiski svojih otrok za nakup hišnega ljubljenčka. Gre za platformo, pozicionirano kot **"Tamagotchi za 21. stoletje z resničnimi posledicami"**, ki skozi rigorozno igrifikacijo (gamifikacijo) in natančno senzorsko preverjanje deluje kot objektivno orodje za ocenjevanje otrokove zrelosti.

Vrednostna ponudba (Value Proposition)

- **Za starše (Odločevalci in plačniki):** Finančna polica in zaščita proračuna. Namesto tveganja več kot 1.000 EUR začetnih stroškov, uničenega pohištva in dolgoročne zaveze, starši investirajo v 12-tedenski preizkus, ki neodvisno dokaže, ali bo otrok dejansko prevzel odgovornost.

- **Za otroke (Uporabniki):** Interaktivno dokazovanje zrelosti skozi upravljanje fotorealističnega AI hišnega ljubljenčka, ki se odziva na njihova dejanja v realnem času.

- **Za sekundarni trg:** Odrasli posamezniki, ki želijo pred nakupom preveriti združljivost zahtevne pasme (npr. Border Collie) s svojim trenutnim življenjskim slogom.

2\. STRUKTURA IN ARHITEKTURA MVP (MINIMUM VIABLE PRODUCT)

Za fazo MVP so izključene vse kompleksne, baterijsko potratne in finančno zahtevne komponente (kot so obogatena resničnost - AR, vremenski API), kar omogoča hiter vstop na trg z minimalnimi stroški razvoja. MVP se osredotoča izključno na **psa**, saj je njegovo gibanje najlažje objektivno meriti.Temeljna arhitektura

1.  **Dvojni profil (Parent-Child Pairing):** Starš ustvari krovni račun in generira unikatno 6-mestno kodo PIN (veljavnost 15 minut). Otrok prenese aplikacijo na svojo napravo, vnese kodo in trajno poveže profil v PostgreSQL podatkovni bazi.

2.  **Slovesna zaobljuba:** Preden se virtualna žival "rodi", mora otrok na zaslonu digitalno podpisati "Pogodbo o odgovornosti" s prstnim podpisom.

3.  **Časovna asimetrija:** Življenjski cikel v simulatorju je pospešen v razmerju **1 realen teden = 1 virtualni mesec**. V 12 realnih tednih (približno 3 mesecih) virtualni pes doseže starost 1 leta, s čimer se zaključi testni cikel in generira končni **"Certifikat odgovornosti"**.

3\. IGRALNA ZANKA (CORE GAME LOOP) IN TEHNIČNE METRIKE

Simulacija temelji na časovnih skriptah (Cron jobs) v Laravel zaledju, ki se izvajajo vsako minuto in matematično nižajo življenjske vrednosti živali v tabeli pets.Hitrost upadanja metrik in zahtevnost oskrbe

| **Metrika** | **Mešanček (Brezplačno)**          | **Border Collie (Premium)**        | **Dnevna rutina (Kdaj ukrepati)**                          |
|-------------|------------------------------------|------------------------------------|------------------------------------------------------------|
| **Lakota**  | -8 % na uro (0 % doseže v 12 urah) | -12 % na uro (0 % doseže v 8 urah) | Hranjenje 2x dnevno (zjutraj in zvečer).                   |
| **Žeja**    | -10 % na uro                       | -15 % na uro                       | Menjava vode 3x dnevno.                                    |
| **Gibanje** | Cilj: 4.000 korakov/dan            | Cilj: 10.000 korakov/dan           | Koraki se prenesejo iz pedometra. Reset na 0 % ob polnoči. |
| **Higiena** | 1x dnevno naključni upad na 0 %    | 2x dnevno naključni upad na 0 %    | Zahteva takojšnje čiščenje zaslona, ko se zgodi.           |

\> **Tihe ure (School/Sleep Window):** Upadanje metrik se samodejno zaustavi ali upočasni v časovnih oknih, ki jih definira starš (npr. med poukom od 8:00 do 13:00 ter ponoči od 22:00 do 6:00), s čimer preprečimo motenje šolskih obveznosti in spanca. Stopnjevanje obvestil (Escalation Matrix)

- **Faza 1 - Blag opomnik (pri 30 % vrednosti):** Standardno Push obvestilo na otrokovem telefonu.

- **Faza 2 - Kritično opozorilo (pri 10 % vrednosti):** Obvestilo sproži močno vibriranje naprave in pasje cviljenje.

- **Faza 3 - Intervencija (pri 0 % vrednosti več kot 1 uro):** Preko WebSockets se nemudoma sproži zvočni alarm na telefonu starša.

Kazni in "Game Over" mehanika

- **Bolezen in timeout:** Če higiena ali gibanje ostaneta na 0 % več kot 6 ur izven tihih ur, pes zboli. Celoten zaslon se obarva sivo, predvaja se statičen video težkega dihanja, otrok pa dobi **12-urni timeout** (žival je na opazovanju pri veterinarju), v katerem ne more upravljati aplikacije.

- **Odvzem živali (Game Over):** Če katerakoli metrika ostane na 0 % celih 24 ur, virtualna inšpekcija žival trajno odvzame. Otrokov zaslon se zaklene, starš pa mora aplikacijo ročno ponastaviti.

4\. PREGLED TEHNOLOŠKEGA STACKA

Arhitektura je zasnovana z ozirom na maksimalno stroškovno učinkovitost in zanesljivost delovanja brez uporabe dragih zunanjih servisov.

1.  **Frontend (Mobilna aplikacija):** React Native z ExpoGO in Tailwind (NativeWind). Omogoča razvoj ene kodne baze za iOS in Android ter branje korakov neposredno iz Apple HealthKit in Google Fit API.

2.  **Backend & Administracija:** Laravel (PHP) + Laravel Filament. Služi kot centralni sistem za procesiranje igralne logike in hitro upravljanje baze podatkov.

3.  **Podatkovna baza:** PostgreSQL s štirimi ključnimi tabelami:

    - users: beleženje hierarhije (parent_id), vlog in RevenueCat identifikatorjev.

    - pets: shranjevanje trenutnih stanj metrik, rojstnega žiga (born_at) in statusa simulacije.

    - activities_log: dnevnik vsake akcije (hranjenje, koraki), ki napaja analitične grafe starša.

    - breed_configs: statični podatki o težavnosti pasem in parametrih upadanja metrik.

4.  **Sinhronizacija v realnem času:** Laravel Reverb (WebSockets). Interni proces na lastnem strežniku, ki poskrbi, da se starševa nadzorna plošča posodobi v milisekundi, ko otrok opravi nalogo, brez potrebe po osveževanju.

5.  **Vizualije in video:** NanoBanana PRO za generiranje visokokakovostnih izhodiščnih slik ter Kling 3.0 za generiranje celozaslonskih video odzivov psa glede na stanje v bazi.

6.  **Plačilni sistem:** RevenueCat. Upravlja nakupe znotraj aplikacije (In-App Purchases) in varno sinhronizira plačilni status z App Store ter Google Play.

**5. GRAND SLAM OFFER & VEČNIVOJSKA MONETIZACIJA (UPSALES)**

Ponudba PetPrep prehaja v kategorijo **premijskega orodja za starševski nadzor in oceno zrelosti** s ceno **49,99 EUR**. Višja cena drastično izboljša zavezanost staršev in otrok k uspešnemu dokončanju programa. Celoten ekosistem je podprt z napredno strukturo in-app nakupov (IAP) ter B2B partnerstvi, kar drastično dvigne življenjsko vrednost (LTV) uporabnika.

Sestava ponudbe (The Value Stack)

1.  **Core produkt:** 12-tedenski izobraževalni simulator PetPrep z dualnim dostopom za starša in otroka.

2.  **Bonus 1 - "Real Cost of a Dog" kalkulator:** Orodje za vizualizacijo in izračun realnih finančnih obveznosti glede na izbrano vrsto psa.

3.  **Bonus 2 - "Breed Matchmaker" algoritem:** Napredni sistem, ki na koncu 12 tednov primerja otrokove realne vedenjske metrike z idealno pasmo za njegov življenjski slog.

4.  **Bonus 3 - Fizična licenca:** Plastificirana "Licenca za hišnega ljubljenčka", ki jo otrok prejme po pošti na domači naslov ob uspešno opravljenem simulatorju.

Notranja IAP virtualna ekonomija (RevenueCat konfiguracija)

Sistem ločuje dve vrsti produktov, ki jih otrok predlaga znotraj aplikacije, starš pa jih odobri in plača:

- **Consumables (Potrošni nakupi):**

  - **Krizni stroški (Veterinar):** Če otrok zanemari higieno ali gibanje do te mere, da pes zboli, se aplikacija zaklene. Starš mora plačati simboličen znesek (1,99 EUR ali 2,99 EUR) za "antibiotike" ali "obisk klinike". Starš prejme edukativno sporočilo: *"V resničnem življenju bi ta obisk stal 150 EUR. Naj bo to lekcija."*

  - **Potrošni material (Priboljški / Treats):** Nakup manjših paketov virtualne valute (npr. 0,99 EUR za "10x Premium briketov") za takojšen dvig energije ali učenje novih trikov.

- **Non-consumables (Trajni nakupi):**

  - **Trajne dobrine (Igrače):** Nakup virtualne žogice, frizbija ali pullerja (npr. 1,49 EUR). Ko ima pes igračo, metrika potrebe po gibanju (energy_level) upada počasneje, saj se žival "zaposli sama".

  - **Odklep napredne pasme:** Izbira zahtevnejših pasem (npr. Border Collie).

Backend monetizacija neuspeha (Failure Upsell)

Ko otrok v simulatorju odpove in metrike ostanena ničli 24 ur, se sproži protokol **"Virtual Shelter Intervention"**. Namesto praznega zaklepa zaslona platforma ponudi dve možnosti:

1.  **The "Second Chance" Reset (19.99 EUR):** Simulacija se ponastavi na začetek, vendar mora starš plačati ponovni zagon. S tem otroka naučimo, da ima izguba živali tudi realne finančne posledice.

2.  **The "Breed Downgrade" Reset (Brezplačno):** Ponovni zagon je brezplačen le pod pogojem, da otrok zniža zahtevnost simulacije – prehodi iz visokoenergetskega Border Collieja na manj zahtevno pasmo, mačko ali starejšega psa.

B2B Partnerstva in "Real-World" ROI (Mr. Pet)To je ultimativni prodajni argument za starše, saj se jim investicija v aplikacijo na koncu finančno povrne:

- Ko otrok uspešno pripelje psa do 1. leta starosti brez kritičnih zanemarjanj, aplikacija staršu v nadzorno ploščo pošlje unikatno QR kodo.

- **Primer ponudbe:** *"Čestitamo! Vaš otrok je dokazal, da je pripravljen. Kot nagrado vam naš partner Mr. Pet podarja 20 % popusta na 'Starter kit za mladička' v vrednosti do 50 EUR."*

- **Affiliate model (CPA):** Trgovec (Mr. Pet) plača platformi PetPrep affiliate provizijo za vsak unovčen kupon, saj s tem pridobi visoko motiviranega in kvalificiranega kupca v trenutku nakupa prave živali.

6\. TRŽNI LIJAK (LEAD MAGNET STRATEGIJA)

Direktna prodaja aplikacije za 49,99 EUR preko hladnega prometa je neučinkovita. Zato je vpeljan dvostopenjski prodajni lijak, ki temelječi na reševanju takojšnjega ozkega problema.

Lead Magnet: "Pet Promise Reality Check"

- **Koncept:** Brezplačno, interaktivno spletno orodje, dostopno na mobilnih napravah. Starš in otrok sedeta skupaj, otrok izbere želeno pasmo in odgovarja na serijo ostrih vprašanj ("Ali boš pobiral tople iztrebke z roko v vrečki?", "Ali boš vstal ob 6:00, ko zunaj sneži?").

- **Rezultat:** Orodje na podlagi otrokovih odgovorov (ki so vedno pritrdilni) izračuna **"Real World Burden Score"** in generira prilagojeno PDF pogodbo: *"Čestitke. Strinjali ste se s 14 urami tedenskega ročnega dela in 1.200 EUR letnih stroškov. Podpišite tukaj."*

- **Prehod na Core ponudbo (The Handoff):** Ko otrok samozavestno podpiše pogodbo, se staršu prikaže sporočilo: *"Mislite, da bo zdržal dlje kot dva tedna? Ne tvegajte 1.500 EUR v realnosti. Preizkusite njegovo obljubo v 12-tedenskem simulatorju PetPrep pred nakupom."*

Oglaševalski kanali

1.  **Meta Ads (Surgical Targeting):** Targetiranje interesnih skupin "Pet adoption" + "Parents of 7-12 years old". Kreativa uporablja split-screen z jasnim kavljem (hook): *"Ne kupujte otroku psa, dokler ne podpiše te pogodbe."* Ciljni CPA za prenos pogodbe (Lead) znaša 1,50 EUR.

2.  **Mom-Influencers (UGC engine):** Sodelovanje z vplivnimi starši na podlagi uspešnosti (Performance pricing). Influencerji ne oglašujejo aplikacije, temveč delijo svojo zgodbo preko "Before & After" okvirja (kako jim je brezplačno orodje pomagalo ustaviti nenehno prosjačenje otroka in kako so s pomočjo simulatorja ugotovili realno stanje zrelosti). Kompenzacija: 1,00 EUR na generiran e-mail ali 20 % provizije od prodaje core paketa.

7\. FINANČNA MATEMATIKA IN ENOTNA EKONOMIKA (UNIT ECONOMICS)Projekcija finančnega modela na podlagi konservativnih testnih podatkov v direktnem marketingu:

- **Strošek pridobitve kontakta (CPA Lead Magnet):** 1,50 EUR

- **Stopnja konverzije iz kontakta v nakup (Lead-to-Sale Conversion):** 10 %

- **Skupni strošek pridobitve kupca (CAC za Core ponudbo):** 15,00 EUR

- **Cena Core ponudbe na front-endu:** 49,99 EUR

- **Backend Upsell doprinos (Ponovni reseti + IAP potrošni material & igrače):** Ocenjeno, da ponovni reseti (15 % uporabnikov plača 19,99 EUR) ter mikro-transakcije za veterinarja, priboljške in igrače dodajo povprečno 3,00 EUR k vrednosti naročila.

- **Skupna povprečna vrednost naročila (AOV - Average Order Value):** **52,99 EUR**

**Zaključek:** Pri CAC v vrednosti 15,00 EUR in končnem AOV v vrednosti 52,99 EUR dosega projekt **3,5x donosnost na oglaševalski vložek (ROAS)** že v prvi fazi prodajnega lijaka, pred vključitvijo donosnih B2B affiliate provizij s strani trgovskih partnerjev ob unovčevanju certifikatov.
