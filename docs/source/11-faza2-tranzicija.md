S tem podaljšaš LTV (Life-Time Value) uporabnika z začetnih 3 mesecev na **10 do 15 let** (življenjska doba psa).

Aplikacija se ob nakupu prave živali ne konča, ampak se transformira iz **Simulatorja** v **Real-World AI Asistenta**. Ker AI že "pozna" družino in njihove navade (ve, kdaj hodijo v službo/šolo, koliko korakov običajno naredijo), je personalizacija takojšnja.

Tukaj je specifikacija za **Fazo 2 (Post-Adoption Phase)**:

## **1. Tranzicija: Iz igre v resnično življenje**

Ko starš na Dashboardu klikne gumb *"Kupili smo pravo žival"*, se UI aplikacije spremeni. Izginejo virtualni "health bari" in časovni pospešek, aplikacija pa preide v realni čas.

- **Prenos zgodovine:** AI asistent pozdravi družino: *"Čestitke za prihod pravega Maxa! Glede na vaše treninge vem, da ste zjutraj zelo aktivni. Naj vama pomagam pri vzgoji pravega psa."*

## **2. Ključni "Real-World" Featurji**

- **IoT Integracija (Pametna ovratnica):** Preko API-ja povežemo priljubljene GPS/Activity trackerje (npr. Tractive) ali pa celo ponudimo lastno white-label PetPrep ovratnico.

- **Dinamično opozarjanje na anomalije:** AI bere podatke z ovratnice. *Primer obvestila:* "Max je danes pretekel samo 2 km, kar je 50 % manj od njegovega povprečja, hkrati pa si mu vnesel večji obrok hrane. Priporočam dodaten večerni sprehod, sicer bo ponoči nemiren."

- **AI Veterinarski Triage (Vision + Chat):** Uporabnik lahko v aplikacijo naloži sliko izpuščaja, rane ali blata. AI (npr. GPT-4o / Claude 3.5 z "vision" zmožnostmi) analizira sliko, jo primerja s pasmo in starostjo ter izda priporočilo: *"Videti je kot blaga alergija. Opazuj 24 ur. Če se stanje poslabša, klikni spodaj za naročilo na pregled pri najbližjem veterinarju."*

- **Rast in prehrana:** Uporabnik vsak teden vnese težo mladiča. AI generira graf rasti in predlaga, kdaj je čas za prehod z "Puppy" na "Adult" brikete.

## **3. Monetizacija v Fazi 2 (Neskončni prihodki)**

Tukaj se odprejo vrata za izjemno donosne B2B modele in In-App prodajo:

- **Mesečna AI Naročnina (SaaS):** Osnovni tracker podatki so brezplačni, napredni "Osebni AI pasji inštruktor" (neomejen chat, analitika obnašanja) pa stane npr. 3,99 € / mesec.

- **In-App Affiliate Trgovina:** Ko AI zazna, da gre pes iz faze mladiča v odraslo dobo, pošlje priporočilo: *"Max bo kmalu rabil večjo ovratnico in močnejše igrače."* Spodaj so affiliate povezave do trgovin (Mr. Pet) z 10 % popustom, kjer mi dobimo provizijo od prodaje.

- **Zavarovanja (Lead Gen):** Ko se pes registrira v Fazi 2, aplikacija samodejno ponudi ponudbe partnerskih zavarovalnic za male živali glede na pasmo (Border Collie ima npr. genetske predispozicije za težave s kolki, kar AI izpostavi kot razlog za zavarovanje).

- **Booking Veterinarjev in Pasjih Šol:** Preko aplikacije se lahko uporabnik direktno naroči v lokalni pasji šoli. Aplikacija zaračuna provizijo (fee) za posredovanje.

## **4. Tehnična nadgradnja zaledja (Backend)**

Za podporo tej fazi bomo morali v naš obstoječi stack (React Native + Laravel + PostgreSQL) dodati:

- **LLM Integracijo (OpenAI / Gemini API):** Za pogovornega bota, ki se obnaša kot veterinar/inštruktor.

- **Vector Database (Pinecone ali pgvector v PostgreSQL):** Zgraditi bomo morali lastno bazo znanja (RAG - Retrieval-Augmented Generation), v katero bomo naložili strokovne veterinarske priročnike in nasvete za trening. Tako AI ne bo "haluciniral", ampak bo dajal varna in preverjena navodila, specifična za živali.
