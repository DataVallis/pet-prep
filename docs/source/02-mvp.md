Za MVP (Minimum Viable Product) moramo oklestiti vse "nice-to-have" funkcionalnosti (kot so obogatena resničnost, kompleksne bolezni, vremenski API in B2B partnerstva) in se osredotočiti na jedro. Cilj MVP-ja je le eden: z najmanj stroški preveriti, ali otroci zdržijo dnevno rutino in ali so starši za aplikacijo pripravljeni plačati.

## **1. Uporabniški profili in arhitektura**

- **Preprosta avtentikacija:** Prijava z Google ali Apple računom.

- **Dvojni profil (Parent-Child Pairing):** Starš ustvari glavni račun in generira povabilno kodo (PIN). Otrok si naloži aplikacijo, vnese PIN in njegova aplikacija se poveže s starševo bazo.

## **2. Omejena izbira živali (Scope Reduction)**

- V MVP vključimo izključno **psa**, saj je njegova potreba po gibanju najlažje merljiva s senzorji telefona.

- **Brezplačna opcija:** Mešanček (povprečne, nezahtevne potrebe).

- **Premium opcija (Plačljiva - test monetizacije):** Ena zahtevna pasma (npr. Border Collie), ki zahteva 2x več gibanja in interakcije. S tem takoj testiramo pripravljenost staršev za In-App nakupe (npr. 4,99 EUR enkratno za to pasmo).

- **Grafika:** Povezava s Kling 3.0 in NanoBananaPro za kreiranje videjev in slik

## **3. Glavna mehanika (Core Loop) in senzorika**

- **Integracija zdravja (Pedometer):** Namesto kompleksnega in baterijsko potratnega GPS sledenja za MVP uporabimo Apple HealthKit in Google Fit API. Aplikacija bere število prehojenih korakov uporabnika. Pes npr. potrebuje 5000 korakov na dan, ki jih mora otrok fizično prehoditi.

- **Sistem potreb (4 osnovne metrike):**

  - **Lakota:** Gumb za hranjenje, ki je aktiven le ob določenih urah (zjutraj in zvečer).

  - **Žeja:** Menjava vode z dotikom na zaslon (3x dnevno).

  - **Sprehod:** "Health bar" se polni izključno s prenešenimi koraki iz pedometra.

  - **Higiena:** Pospravljanje iztrebkov (preprosta mini-igra ali gumb, ki se prikaže naključno 2x dnevno in prinese opozorilo, če se ga ignorira več kot 2 uri).

- **Časovni pospešek:** Preprosta metrika, kjer 1 realen teden pomeni 1 mesec pasjega življenja. Starost se izpisuje zgoraj na zaslonu.

## **4. Sistem obvestil (Push Notifications)**

- Strogi opomniki na sistemski ravni, ki se prožijo glede na časovnik (npr. "Mešanček je lačen!", "Čas za popoldanski sprehod").

- Če otrok obvestilo ignorira, začnejo padati metrike psa. Ko metrika doseže kritično mejo (npr. pod 10 %), aplikacija pošlje obvestilo staršu.

## **5. Starševska nadzorna plošča**

- Zelo preprost "Dashboard" na telefonu starša.

- Prikazuje trenutne 4 metrike psa v realnem času.

- **Tedenski semafor:** Preprost status z barvami – Zelena (otrok odlično skrbi), Rumena (večkrat zamudil rutino), Rdeča (žival bi v realnosti zbolela ali ušla).

- **Hard Stop gumb:** Starš lahko prekine simulacijo in aplikacijo "zaklene", če vidi, da otrok obveznosti ignorira.
