# PetPrep Faza 2 — Real-World AI asistent (po posvojitvi)

> **Status:** *načrt* — David, 4. 10. 2026. Ni v MVP (CLAUDE.md Scope guard: LLM/vision veterinar, GPS). Ta dokument je vir za roadmap Faze 2, investitorske materiale in partnerske pogovore.
> **Poslovni cilj:** LTV uporabnika iz začetnih 3 mesecev (izziv) na 10–15 let (življenjska doba psa).

## 1. Tranzicija: iz igre v resnično življenje
- Starš na nadzorni plošči klikne **"Kupili smo pravo žival"** → UI se spremeni: izginejo virtualni "health bari" in časovni pospešek, aplikacija preide v realni čas.
- **Prenos zgodovine:** asistent pozna družinske navade iz izziva (ritem šole/službe, običajni koraki). Primer pozdrava: *"Čestitke za prihod pravega Maxa! Glede na vaše treninge vem, da ste zjutraj zelo aktivni. Naj vam pomagam pri vzgoji pravega psa."*

## 2. Ključne funkcije
| Funkcija | Opis |
|---|---|
| **IoT integracija** | API povezava s priljubljenimi GPS/activity trackerji (npr. Tractive) ali lastna white-label PetPrep ovratnica. |
| **Opozarjanje na anomalije** | AI bere podatke ovratnice. Primer: *"Max je danes pretekel samo 2 km, 50 % manj od povprečja, hkrati je dobil večji obrok. Priporočam dodaten večerni sprehod."* |
| **AI veterinarski triage (vision + chat)** | Slika izpuščaja, rane, blata → analiza glede na pasmo in starost → priporočilo (opazuj / naroči se pri veterinarju). |
| **Rast in prehrana** | Tedenski vnos teže → graf rasti, predlog prehoda puppy → adult hrana. |

### 2a. AI prvi stik → pravi veterinar (David, 4. 10. 2026)
- Lastnik najprej postavi vprašanje **AI asistentu** (chat, slika). AI odgovori na splošna vprašanja in oceni nujnost.
- Ko je potreben strokovnjak, AI uporabnika **preusmeri k partnerskemu veterinarju** — klic, video ali chat v aplikaciji ali naročilo na pregled. Ob nujnih znakih takoj: "Pokliči veterinarja / dežurno ambulanto zdaj".
- Veterinar ob prevzemu (s soglasjem lastnika) dobi povzetek pogovora, pasmo, starost, težo in podatke ovratnice, da ne sprašuje znova.
- **Za veterinarje:** kanal kvalificiranih potencialnih strank (lead-i) iz njihove okolice; plačan posvet na daljavo.
- **Model** (kot pri zavarovanjih): provizija na posredovan lead / opravljen posvet ali mesečna članarina partnerske ambulante; možnost "priporočena ambulanta" na območju.

## 3. Monetizacija
- **AI naročnina (SaaS):** osnovni tracker podatki brezplačni; "Osebni AI pasji inštruktor" (neomejen chat, analitika obnašanja) npr. **3,99 €/mes**.
- **In-app affiliate trgovina:** priporočila ob prehodu faz (npr. večja ovratnica) s povezavami do partnerjev (npr. Mr. Pet, 10 % popust, provizija).
- **Zavarovanja (lead gen):** ponudbe partnerskih zavarovalnic glede na pasmo (npr. predispozicije Border Collieja za težave s kolki).
- **Veterinarji (lead gen + telemedicina):** AI prvi stik → preusmeritev k partnerskemu veterinarju (klic/chat/naročilo); provizija na lead ali posvet, ali članarina ambulante (glej 2a).
- **Booking veterinarjev in pasjih šol:** provizija za posredovanje.

## 4. Tehnična nadgradnja (Laravel + PostgreSQL + React Native)
- **LLM integracija** za pogovornega asistenta (ponudnik in model izberemo ob gradnji).
- **RAG z vektorsko bazo** — prednostno **pgvector v obstoječem PostgreSQL** (brez novega ponudnika), baza znanja iz preverjenih veterinarskih priročnikov in navodil za trening, da AI ne halucinira.

## 5. Opombe za izvedbo (Claude, 4. 10. 2026 — odprto za Davida)
- **Veterinarski triage je medicinski nasvet:** jasne omejitve odgovornosti, vedno pot do pravega veterinarja, nikoli diagnoza; preveriti EU AI Act / predpise za aplikacije, ki dajejo zdravstvene nasvete za živali, in zavarovanje odgovornosti.
- **Viri znanja:** isto načelo kot pri pasmah (M1-19) — samo preverljivi, citirani viri, licence za uporabo priročnikov.
- **Zasebnost:** Faza 2 obdeluje podatke odraslih lastnikov in lokacijo psa (GPS); otrokovi podatki iz izziva se prenesejo samo z izrecnim soglasjem starša; brez otrokove osebnih podatkov pri tretjih ponudnikih (LLM, IoT).
- **Veterinarji:** AI ni nadomestek veterinarja — jasno označeno; nujni primeri gredo mimo AI direktno k veterinarju; deljenje povzetka samo s soglasjem; preveriti pravila veterinarske zbornice glede telemedicine in plačil za napotitve (provizije za napotitev so lahko omejene).
- **Partnerji (affiliate, zavarovalnice):** razkritje provizij uporabniku; brez deljenja podatkov brez soglasja.
- **Najprej validacija:** pred gradnjo meriti, koliko družin po izzivu res kupi psa (ključna metrika za investitorje in za upravičenost Faze 2).
