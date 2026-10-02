## **Poslovna logika in igralna zanka (Game Loop)**

Celotna simulacija temelji na časovnih skriptah (Cron jobs), ki se v Laravel zaledju zaženejo vsako minuto. Te skripte preverjajo časovno okno ("tihe ure", ko je otrok v šoli) in matematično nižajo vrednosti v tabeli pets.

### **1. Časovna asimetrija in življenjska doba**

- **Formula staranja:** 1 realen teden = 1 virtualni mesec.


- **Življenjska doba za MVP:** Po 12 realnih tednih (približno 3 mesecih) pes v aplikaciji doseže starost 1 leta. Takrat aplikacija izda **"Certifikat odgovornosti"** s končno oceno. S tem zaključimo prvi testni cikel in staršem omogočimo oprijemljiv dokaz.


### **2. Hitrost upadanja metrik (Decay Rates)**

Hitrost padanja vrednosti od 100 % do 0 % določa težavnost aplikacije. Z In-App nakupom premium pasme se krivulja zahtevnosti drastično spremeni.

| **Metrika** | **Mešanček (Brezplačno)**          | **Border Collie (Premium)**        | **Dnevna rutina (Kdaj ukrepati)**                          |
|-------------|------------------------------------|------------------------------------|------------------------------------------------------------|
| **Lakota**  | -8 % na uro (0 % doseže v 12 urah) | -12 % na uro (0 % doseže v 8 urah) | Hranjenje 2x dnevno (zjutraj in zvečer).                   |
| **Žeja**    | -10 % na uro                       | -15 % na uro                       | Menjava vode 3x dnevno.                                    |
| **Gibanje** | Cilj: 4.000 korakov/dan            | Cilj: 10.000 korakov/dan           | Koraki se prenesejo iz pedometra. Reset na 0 % ob polnoči. |
| **Higiena** | 1x dnevno naključni upad na 0 %    | 2x dnevno naključni upad na 0 %    | Zahteva takojšnje čiščenje zaslona, ko se zgodi.           |

*Opomba: Upadanje metrik se samodejno pavzira med "Tihimi urami" (npr. od 8:00 do 13:00 in od 22:00 do 6:00), ki jih definira starš, da aplikacija ne moti pouka in spanja.*

### **3. Stopnjevanje obvestil (Escalation Matrix)**

Za vsako metriko velja strog tristopenjski sistem opozarjanja, ki otroke uči odzivnosti.

1.  **Faza 1 - Blag opomnik (pri 30 % vrednosti):**

2.  Otrok prejme standardno Push obvestilo. *Primer: "Tvoj kuža te milo gleda in kaže na posodo s hrano."*


4.  **Faza 2 - Kritično opozorilo (pri 10 % vrednosti):**

5.  Obvestilo vključuje močno vibracijo in pasji zvok (cviljenje). *Primer: "Opozorilo! Žival je kritično lačna. Če je ne nahraniš v 30 minutah, bo zbolela."*


7.  **Faza 3 - Intervencija (pri 0 % vrednosti več kot 1 uro):**

8.  Preko WebSockets (Laravel Reverb) se sproži alarm na telefonu starša. *Primer: "Tvoj otrok danes ni poskrbel za psa. Žival trpi."*


### **4. Kazni in "Game Over" mehanika**

Če metrike padejo na 0 % in tam ostanejo dalj časa, aplikacija simulira resnične posledice malomarnosti.

- **Bolezen in Veterinar:** Če higiena ali gibanje ostaneta na 0 % več kot 6 ur (izven časa spanja/šole), pes "zboli". Ekran se obarva sivo, pes (Kling 3.0 video) leži statično in diha težko. Otrok dobi **12-urni "timeout"**, kjer psa ne more upravljati, saj je ta "na opazovanju pri veterinarju".


- **Odvzem živali (Game Over):** Če katerakoli metrika ostane na 0 % polnih 24 ur (in starš ne stisne gumba za pavzo), virtualna inšpekcija žival "odvzame". Otrokov zaslon se trajno zaklene, dobi obvestilo o neuspehu, starš pa mora aplikacijo ročno resetirati, če želi otroku dati novo priložnost čez določen čas.

