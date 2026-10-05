# PetPrep — Realistična simulacija psa (izvor, starost, rast, vedenje, šolanje)

> **Status:** osnutek — David, 5. 10. 2026 ("čim bolj realna simulacija"). Odprta vprašanja na koncu. Ko so odločitve sprejete, se pravila prenesejo v `PRODUCT_SPEC.md`, naloge v `ROADMAP.md` (epik **M5**).
> **Temeljno pravilo:** vse številke o pasmah, starosti, rasti, prehrani, vedenju in učljivosti pridejo **iz preverljivih virov** (M1-19) — nič si ne izmišljujemo. V igri so označene z virom.

## 1. Kaj starš izbere ob ustvarjanju psa (pred PIN-om otroka)
| Izbira | Možnosti (osnutek) | Vpliv |
|---|---|---|
| Vrsta | pes (mačke niso v MVP) | — |
| Pasma | mešanček, Border Collie, … (seznam iz virov) | velikost, energija, prehrana, učljivost, videz |
| Izvor | **posvojen** (zavetišče) / **kupljen** (vzreditelj) | posvojen: lahko odrasel, neznana preteklost (npr. plašnost, ni vajen hiše); kupljen: praviloma mladiček |
| Starost ob prihodu | **mladiček** (8–12 tednov), **mlad pes** (6–18 mesecev), **odrasel** (2–7 let), *starejši (8+ let)* | potrebe, vedenje, rast, slika |
| (pozneje) spol, velikost | — | — |

## 2. Starost in rast
- Čas: **1 realni teden = 1 mesec** psove starosti (obstoječe pravilo) → 12 tednov = 12 mesecev.
- Mladiček, ki pride pri 2 mesecih, je ob koncu izziva star ~14 mesecev — prehod mladiček → mlad pes → (skoraj) odrasel.
- **Faze** (meje po pasmi/velikosti iz virov): mladiček · mladostnik · odrasel · starejši. Vsaka faza ima svoje potrebe (število obrokov, spanje, gibanje, vedenje).
- **Slika in videi rastejo s psom:** ob prehodu faze (in npr. vsak mesec pri mladičku) se ustvari nova referenčna slika istega psa (image-to-image iz prejšnje → ohranjena identiteta: barva, oznake), nato videi stanj. Strošek: ~0,15 $ slika + videi po fazah — v proračunu (M4-07).

## 3. Vedenje glede na starost in izvor
| Pojav | Kdaj | V igri |
|---|---|---|
| Lulanje / kakanje po stanovanju | mladiček, posvojen pes brez navad | pogostejši "nered" dogodki; manj z vsakim napredkom pri navajanju na čistočo |
| Uničevanje (grizenje pohištva, čevljev) | mladiček (menjava zob), dolgčas pri visokoenergijskih pasmah | dogodek "kuža je nekaj uničil" — otrok mora pospraviti in dati igračo; preprečuje se z gibanjem in šolanjem |
| Več obrokov | mladiček 3–4× na dan → odrasel 2× | okna hranjenja po fazi (iz virov) |
| Več spanja | mladiček, starejši | več spanja v videih, manj gibanja |
| Plašnost / prilagajanje | posvojen pes, prvi tedni | počasnejši napredek pri šolanju, posebni opomniki (mirnost) |
| Potrebe po gibanju | po pasmi in starosti | dnevni cilj korakov po fazi (mladiček krajši sprehodi!) |

## 4. Šolanje (dresura)
- Ukazi (osnutek): **navajanje na čistočo**, sedi, prostor/ostani, pridi (odpoklic), hoja na povodcu, pusti.
- Otrok vsak dan izvede **kratko vajo** (mini-igra, nekaj minut): pravilen časovni trenutek nagrade, ponavljanje. Napredek po ukazu 0–100 %.
- **Učljivost po pasmi** iz virov (npr. raziskava Stanley Coren, *The Intelligence of Dogs* — rangiranje poslušnosti; AKC opisi temperamenta). Border Collie hitro, nekatere pasme počasneje; mešanček povprečno z naključjem.
- Šolanje **zmanjša** nered, uničevanje, pobege; neredno šolanje → napredek počasi upada.
- Šolanje šteje kot rutina v Care Score (predlog) in je vidno staršu ("Kuža zna: sedi ✓, pridi 60 %").

## 5. Kaj to pomeni za aplikacijo in strežnik (pregled)
- Nov korak pri staršu: **"Izberi kužka"** (pasma, izvor, starost) pred PIN-om (nadgradnja M2-04).
- Pravila igre postanejo odvisna od **faze** (ne samo pasme): okna hranjenja, cilj korakov, pogostost nereda, nova vrsta dogodkov (uničevanje), šolanje.
- Novi mediji po fazah (rast), nova stanja videov (npr. "grize copat", "luža").
- Ocena (Care Score) vključi šolanje in odziv na vedenjske dogodke.

## 6. Predpogoj: podatki iz virov (M1-19, razširjeno)
Za vsako pasmo in fazo zbrati z viri: velikost/teža po starosti (rastne krivulje), število obrokov, dnevno gibanje, spanje, starost čistoče (navajanje), obdobje menjave zob, učljivost (rang + vir), tipične vedenjske težave. Viri: FCI/AKC standardi, veterinarska literatura (WSAVA smernice prehrane, rastne krivulje), Coren, kinološke zveze. Vsaka številka v bazi ima `source`.

## 7. Odprta vprašanja za Davida
Glej pogovor 5. 10. 2026 — odgovori se zapišejo v `DECISIONS.md`.
