## **Tehnološki stack za MVP**

**1. Mobilna aplikacija (Frontend)**

- **Tehnologija:** React Native with ExpoGO z Tailwind (NativeWind)

- **Vloga:** Razvoj ene kodne baze, ki se hkrati prevede v nativno iOS in Android aplikacijo.

- **Ključne naloge:** Branje korakov iz pedometra (Apple Health / Google Fit), uporabniški vmesnik za interakcijo z živaljo in prikazovanje lokalnih obvestil (push notifications) glede na urnik živali.

**2. Zaledni sistem (Backend) in Administracija**

- **Tehnologija:** Laravel (PHP)

- **Vloga:** Glavni možgani aplikacije na vašem lastnem strežniku.

- **Ključne naloge:** Avtentikacija, procesiranje igralne logike preko periodičnih nalog (cron jobs, ki vsako minuto psu znižajo energijo) in generiranje nadzorne plošče (Admin panel) s pomočjo orodij, kot je Laravel Filament, za hitro upravljanje uporabnikov in vsebin.

- NanoBanana PRO za generiranje slik in Kling 3.0 za animacijo slik v videje

**3. Podatkovna baza**

- **Tehnologija:** PostgreSQL

- **Vloga:** Relacijska hramba podatkov.

- **Ključne naloge:** Beleženje povezanih računov (Starš -\> Otrok), shranjevanje trenutnega stanja virtualne živali in hramba zgodovine aktivnosti (npr. dnevniki hranjenja in sprehodov) za prikaz analitike na starševi nadzorni plošči.

**4. Sinhronizacija v realnem času (WebSockets)**

- **Tehnologija:** Laravel Reverb

- **Vloga:** Interni proces na vašem strežniku za takojšen prenos podatkov brez osveževanja.

- **Ključne naloge:** Ko otrok na svojem telefonu opravi nalogo (npr. nahrani psa), Reverb v milisekundi pošlje signal aplikaciji na telefonu starša, da se graf metrik posodobi. Stroškovno učinkovita rešitev, saj ne uporablja zunanjih plačljivih servisov.

**5. Plačilni sistem (In-App Purchases)**

- **Tehnologija:** RevenueCat

- **Vloga:** Infrastruktura za upravljanje nakupov znotraj aplikacije.

- **Ključne naloge:** Povezovanje z Apple App Store in Google Play Billing, preverjanje veljavnosti nakupov (npr. plačilo za "Premium pasmo") in varna sinhronizacija plačilnega statusa z vašo bazo podatkov, kar drastično skrajša čas razvoja nakupovalnega modula.
