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

## 3. Monetizacija
- **AI naročnina (SaaS):** osnovni tracker podatki brezplačni; "Osebni AI pasji inštruktor" (neomejen chat, analitika obnašanja) npr. **3,99 €/mes**.
- **In-app affiliate trgovina:** priporočila ob prehodu faz (npr. večja ovratnica) s povezavami do partnerjev (npr. Mr. Pet, 10 % popust, provizija).
- **Zavarovanja (lead gen):** ponudbe partnerskih zavarovalnic glede na pasmo (npr. predispozicije Border Collieja za težave s kolki).
- **Booking veterinarjev in pasjih šol:** provizija za posredovanje.

## 4. Tehnična nadgradnja (Laravel + PostgreSQL + React Native)
- **LLM integracija** za pogovornega asistenta (ponudnik in model izberemo ob gradnji).
- **RAG z vektorsko bazo** — prednostno **pgvector v obstoječem PostgreSQL** (brez novega ponudnika), baza znanja iz preverjenih veterinarskih priročnikov in navodil za trening, da AI ne halucinira.

## 5. Opombe za izvedbo (Claude, 4. 10. 2026 — odprto za Davida)
- **Veterinarski triage je medicinski nasvet:** jasne omejitve odgovornosti, vedno pot do pravega veterinarja, nikoli diagnoza; preveriti EU AI Act / predpise za aplikacije, ki dajejo zdravstvene nasvete za živali, in zavarovanje odgovornosti.
- **Viri znanja:** isto načelo kot pri pasmah (M1-19) — samo preverljivi, citirani viri, licence za uporabo priročnikov.
- **Zasebnost:** Faza 2 obdeluje podatke odraslih lastnikov in lokacijo psa (GPS); otrokovi podatki iz izziva se prenesejo samo z izrecnim soglasjem starša; brez otrokove osebnih podatkov pri tretjih ponudnikih (LLM, IoT).
- **Partnerji (affiliate, zavarovalnice):** razkritje provizij uporabniku; brez deljenja podatkov brez soglasja.
- **Najprej validacija:** pred gradnjo meriti, koliko družin po izzivu res kupi psa (ključna metrika za investitorje in za upravičenost Faze 2).
