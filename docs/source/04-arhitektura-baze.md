## **Arhitektura podatkovne baze (PostgreSQL shema)**

Za MVP potrebujemo štiri ključne tabele, ki bodo podpirale igralno logiko v Laravel zaledju in omogočale hiter prenos podatkov na mobilne naprave.

### **1. Tabela uporabnikov (**users**)**

Služi za avtentikacijo in vzpostavitev hierarhije med nadzornim in izvajalnim profilom.

- id (Primary Key)


- role (Enum: 'parent', 'child')


- parent_id (Foreign Key -\> kaže na users.id, vrednost je NULL za starše, obvezno za otroka)


- pairing_pin (String, začasna koda za prvo povezavo med telefonoma)


- revenuecat_id (String, unikatni identifikator kupca za preverjanje In-App nakupov)


### **2. Tabela virtualnih živali (**pets**)**

Jedro simulacije, na katerem tečejo periodične naloge (cron jobs), ki nižajo življenjske metrike.

- id (Primary Key)


- user_id (Foreign Key -\> users.id od otroka, ki je lastnik)


- breed_type (Enum: 'mutt', 'border_collie')


- hunger_level (Integer 0-100, začne pri 100)


- energy_level (Integer 0-100, polni se z branjem pedometra)


- hygiene_level (Integer 0-100)


- born_at (Timestamp, kritično za matematični izračun 5x pospešene starosti in proženje vizualnih sprememb živali)


- is_active (Boolean, označi konec simulacije ob uspehu ali neuspehu)


### **3. Dnevnik aktivnosti (**activities_log**)**

Tabela, ki shranjuje vsako akcijo. Služi kot vir podatkov za grafe na starševi nadzorni plošči.

- id (Primary Key)


- pet_id (Foreign Key -\> pets.id)


- activity_type (Enum: 'fed_pet', 'walked_pet', 'cleaned_poop', 'ignored_warning')


- value (Integer, npr. natančno število prehojenih korakov, prenešenih iz Apple Health)


- created_at (Timestamp)


### **4. Konfiguracija pasem (**breed_configs**)**

Statična tabela (se ne spreminja pogosto), ki določa matematično zahtevnost posamezne pasme ob nakupu.

- id (Primary Key)


- breed_slug (String, npr. 'border-collie')


- daily_steps_required (Integer, prag za dnevno uspešnost)


- hunger_decay_rate (Float, določa, za koliko odstotkov na uro pade raven lakote)


- premium_unlock (Boolean, ali pasma zahteva RevenueCat validacijo)


Ta struktura neposredno podpira tvoj izbrani stack in omogoča takojšen prenos stanj preko WebSocketov (Laravel Reverb), takoj ko se v tabeli activities_log pojavi nov vnos.
