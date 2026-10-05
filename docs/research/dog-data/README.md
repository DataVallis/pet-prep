# Podatki o psih iz virov — raziskava za realistično simulacijo (M1-19 / M5)

> **Status:** David je 5. 10. 2026 sprejel odločitve (DECISIONS); življenjske faze, obroki, gibanje, spanje, teža, učljivost in življenjska doba so **uvoženi v `breed_stage_params`** (M5-R01, `BreedStageParamsSeeder`, vsaka vrednost z `source_id` in `verified`; NEPODPRTO = `verified = false`). `breed_configs` (hitrosti lakote / žeje, voda, kakci) ostaja neuvoženo.
> **Datoteke:** `data.json` (strojno berljivo, vsaka vrednost z virom, citatom in zanesljivostjo) · `sources.md` (seznam virov S1–S46 z URL-ji, založnikom, datumom dostopa).
> **Pravilo:** vse, kar ni neposredno podprto z virom, je označeno **NEPODPRTO — predlog** (v JSON: `UNSOURCED — proposal`) in se staršem/otrokom ne sme prikazati kot dejstvo.
> **Opozorilo o citatih:** strani sem bral z orodjem, ki vrne zahtevane odlomke. Pred uvozom naj človek odpre URL in preveri citat (zlasti PDF-je S1, S12, S13, S24, S30).

## 1. Povzetek — kaj imamo in kako zanesljivo

| # | Tema | Najdeno | Zanesljivost | Glavni viri |
|---|---|---|---|---|
| 1 | Življenjske faze | AAHA: mladiček do ~6–9 mes., mlad odrasel do 3–4 let, starejši = zadnjih 25 % pričakovane življenjske dobe. Mladostništvo se začne pri 6–9 mes., puberteta ~8 mes. | **visoka** (AAHA), srednja (mladostništvo) | S11, S12, S43 |
| 2 | Rast | Odrasla teža: majhni 8–12 mes., srednji 12–15 mes., večji do 24 mes. Border Collie: AKC 30–55 lb (13,6–24,9 kg), FCI idealna višina 53 cm (samci). **Teža po mesecih ni nikjer objavljena kot tabela** → izpeljana iz logističnega modela (glej §3) | srednja (končne točke), **nizka** (krivulja) | S1, S3, S4, S8, S9, S10 |
| 3 | Hranjenje | 8–12 tednov 4×, 3–6 mes. 3× (RKC: 2–3×), 6–12 mes. 2×, odrasel ≥2× (ASPCA: 1× — nesoglasje), starejši manjši obroki 2–3× | visoka/srednja | S14, S18–S21 |
| 4 | Voda | 60–80 ml/kg/dan (Guelph) oz. 20–70 ml/kg (FirstVet); vsi viri: **voda vedno na voljo**. Število "dolivanj" na dan **ni podprto** | srednja; samo splošno | S18, S20, S22, S23 |
| 5 | Gibanje | Odrasel pes 30–120 min/dan; Border Collie >2 h/dan. Mladiček "5 min × mesec, 2× na dan" — **sporno pravilo** brez raziskave. Raziskava (Krontveit 2012): do 3 mes. gibanje na mehkem terenu ščiti, stopnice povečajo tveganje (velike pasme). **Korakov za pse ne podaja noben veterinarski vir** | srednja; koraki nizka | S5, S7, S24–S26, S45, S46 |
| 6 | Spanje | RKC: 4–12 tednov 15–20 h, 3–6 mes. 14–16 h, >6 mes. 12–14 h. Merjenje (Dogs Trust kohorta): 16 tednov 11,2 h, 12 mes. 10,8 h → **nesoglasje**. Starejši spijo več | srednja | S28, S29, S14, S13 |
| 7 | Navajanje na čistočo | Mladiček zdrži ~1 h na mesec starosti (±1 h); dosleden pri 12–16 tednih; popolnoma navajen 4–6 mes. (do 1 leta); nezgode pogoste do ~1 leta; ven po hranjenju, spanju, igri. **Pogostost nezgod na dan ni podprta** | srednja | S30, S31 |
| 8 | Zobje / grizenje | Mlečni zobje 5–6 tednov (28), menjava 12–16 tednov, stalni (42) ~6 mes.; intenzivno grizenje se konča ~6 mes. Vzroki uničevanja: dolgčas, ločitvena tesnoba, lakota, stres. Border Collie: dolgčas → grizenje | visoka | S32, S33, S7, S12 |
| 9 | Učljivost | Coren: Border Collie **#1** (190 od 199 sodnikov v top 10). Mešanci **niso rangirani**. Pasma pojasni le ~9 % razlik v vedenju posameznika (Science 2022). Šolanje od ~8 tednov, 5–10 min na vajo, mladiček ≤15 min/dan; prvi ukazi: pridi, hoja na povodcu, sedi, prostor, ostani | visoka (rang), nizka (definicije razredov — Wikipedia) | S34–S37, S42 |
| 10 | Posvojen vs. kupljen | "3-3-3 pravilo" navaja lokalno zavetišče; **izvor neznan**, nobena nacionalna organizacija ga ne definira. Raziskava (n=27): 41 % psov se je prilagodilo v 4–6 mes., 30 % v ≤3 mes., 19 % še ne. Pogoste težave: strah 63 %, nečistoča 26 %, ločitvene težave 26 %. Zavetišča (Queensland, n=11.967): 26 % mladičev, 74 % odraslih | srednja/nizka | S38–S41 |
| 11 | Videz Border Collie | FCI/RKC: dlaka zmerno dolga ali kratka; **katerakoli barva, belina ne sme prevladovati**; ušesa pokončna ali polpokončna (AKC: tudi eno/obe); oči rjave, pri merlih ena/obe/delno modre; rep zmerno dolg, nizko nastavljen, zavihan navzgor | **visoka** | S1, S3, S6 |
| 11b | Videz mešanček | Standarda ni; statistik videza iz zavetišč **nismo našli** | — | — |
| 12 | Starejši psi | Energijske potrebe padajo; manjši obroki 2–3×; pogosti kratki sprehodi namesto enega dolgega; več spanja; dnevno spanje + nočni nemir = znak kognitivne disfunkcije | srednja | S13, S14 |
| — | Življenjska doba | VB: vsi psi 12,5 let, Border Collie 13,1, mešanci 12,0 (McMillan 2024); O'Neill 2013 pa: mešanci živijo 1,2 leti **dlje** → nesoglasje | visoka (BC), srednja (mešanci) | S15–S17 |

## 2. Česa NISMO mogli podpreti z virom (izrecno)
1. **Teža po mesecih** kot objavljena tabela (ne za Border Collie ne za mešance). Salt 2017 ima samo grafe + surove podatke (146 MB, prenos tu ni uspel).
2. **Starost zaprtja rastnih plošč** po velikosti — našli smo samo "rast se konča pri …".
3. **Koraki na dan za psa** — noben veterinarski vir; tudi raziskava aktivnosti psov (S46) meri pospeškomer, ne korakov. Trenutna 4.000 / 10.000 sta nepodprta.
4. **Število dolivanj vode na dan** — viri pravijo "voda vedno na voljo".
5. **Pogostost nezgod (lulanje/kakanje v stanovanju) na dan.**
6. **Število "kakcev" na dan** (`poops_per_day`) — ni bilo v obsegu iskanja, ostaja nepodprto.
7. **Hitrost upadanja lakote/žeje v %/h** — to je igralna abstrakcija; edini fiziološki namig: lakota se pojavi 8–10 h po obroku (S19).
8. **Izvor "3-3-3 pravila"**.
9. **Seznam barv Border Collieja in njihove pogostnosti** — standardi jih ne naštevajo (AKC stran z barvami ni bila dosegljiva). Prav tako **oznake na obrazu** (lisa) in **jantarne oči** niso v standardih.
10. **Videz mešančkov** (razporeditev barv, ušes, dlake).
11. **Dolžina vaje za odraslega psa.**
12. **Številska razpršenost učljivosti mešančka.**
13. **Starost nakupa mladička** (8–12 tednov je iz REALISM_SPEC, ne iz vira).

## 3. Predlagani parametri igre z izpeljavo (vse = PREDLOG)
Čas igre: 1 realni teden = 1 mesec psa (REALISM_SPEC §2).

**a) Okna hranjenja po fazi** (iz S18, S21, S19, S14)
| Starost psa | Teden izziva (mladiček pride pri 2 mes.) | Obroki/dan | Vir |
|---|---|---|---|
| 2–3 mes. | 1. teden | **4** | ASPCA "8–12 weeks … four meals"; RKC "two to three months – four meals" |
| 3–6 mes. | 2.–4. teden | **3** | ASPCA "three to six months … three meals" (RKC dovoli 2–3) |
| 6–12 mes. | 5.–10. teden | **2** | ASPCA, RKC |
| odrasel | — | **2** | VCA, RSPCA AU (ASPCA 1× — zavrnjeno kot manjšina) |
| starejši | — | **2** (manjši obroki; 3 možno) | Dogs Trust "smaller meals two or three times a day" |
Ure oken (npr. 4× = 06–09, 11–13, 15–17, 19–21) so **nepodprte** — in trčijo s tihimi urami šole (8–13). → odprto vprašanje.

**b) Rast (za slike po fazah)** — logistični model (S9), oblika = povprečje 4 srednjih pasem: x0 = 18,3 tedna (50 % odrasle teže), b = 8,5 tedna; preverba: 99 % pri 57 tednih ≈ objavljenih 52–58 tednov.
| Mesec | 2 | 3 | 4 | 5 | 6 | 8 | 10 | 12 | 14 |
|---|---|---|---|---|---|---|---|---|---|
| % odrasle teže | 25 | 35 | 47 | 60 | 72 | 87 | 95 | 98 | 99 |
| Border Collie (13,6–24,9 kg) | 3,3–6,1 | 4,8–8,8 | 6,5–11,8 | 8,2–15,0 | 9,7–17,8 | 11,9–21,8 | 12,9–23,7 | 13,4–24,4 | 13,5–24,7 |
| Mešanček (predpostavka 15–30 kg) | 3,7–7,4 | 5,3–10,5 | 7,1–14,2 | 9,0–18,0 | 10,7–21,4 | 13,1–26,2 | 14,3–28,5 | 14,7–29,5 | 14,9–29,8 |
Nizka zanesljivost: Border Collie in mešanci niso bili v vzorcu S9 (21 psov).

**c) Gibanje → koraki** — pretvorba je **predpostavka**: 1 minuta hoje = 100 otrokovih korakov (S45: "≥100 steps/min" = zmerna intenzivnost, a **samo za odrasle**).
- Odrasel Border Collie: >120 min (S5) → **12.000 korakov** (zdaj 10.000).
- Odrasel mešanček: 30–120 min (S24) → predlog 60 min → **6.000** (zdaj 4.000). Izbira 60 min je naša.
- Mladiček (sporno pravilo S24): 2 mes. 20 min → 2.000; 3 mes. 30 → 3.000; 6 mes. 60 → 6.000; 12 mes. 120 → 12.000 (BC) — mešanček naj se ustavi pri svojem odraslem cilju.
- Do 3 mes.: v igri brez "stopnic", spodbujati kratko igro zunaj (S26).
- Starejši: več krajših sprehodov (S14) → npr. cilj razdeljen na 2–3 sprehode; minut vir ne podaja.

**d) Nered (lulanje) pri mladičku** — zdrži ~1 h na mesec starosti (S30, S31): 2 mes. ≈ 2 h, 4 mes. ≈ 4 h, 6 mes. ≈ 6 h; ven po hranjenju, spanju, igri. Predlog: nezgoda, če otrok ne "pelje ven" v tem oknu po obroku/spanju; po 4–6 mes. (S31) z napredkom šolanja čistoče redke. Pogostost na dan je **naša izbira**.

**e) Uničevanje (grizenje)** — visoko tveganje pri 3–6 mes. (menjava zob, S32/S33); po 6 mes. le ob dolgčasu (premalo gibanja/vaje; S33, S7, S12). Border Collie: večje tveganje ob nedoseženem gibanju (S4, S7).

**f) Šolanje** — mini-igra ~5 min (S36/S37: 5–10 min, mladiček ≤15 min/dan); začetek pri 8 tednih; prvi ukazi: pridi, hoja na povodcu, sedi, prostor, ostani (S36). Hitrost učenja: Border Collie "najbistrejši" razred (<5 ponovitev — S35, preveriti v knjigi); mešanček povprečje + naključje (S42: pasma ~9 %). Pri ~8 mes. kratkotrajen upad poslušnosti (S43) — zanimiv realističen dogodek.

**g) Spanje v videih** — mladiček 3–6 mes. 14–16 h, >6 mes. 12–14 h (RKC); merjenje kaže ~11 h → predlagam RKC razpone za "več spanja" v videih, brez strogih številk.

**h) Posvojen pes** — prilagajanje: prvi teden "plašnost" (3 dni po S38 → v igri ≈ nekaj realnih dni), pospešeno izboljšanje v 3 tednih, polno 3–6 mes. (S39: večina >4 mes.). Pogoste težave za dogodke: strah, nečistoča (~1/4), ločitvene težave (~1/4) — S39, S40.

**i) Faze za mešančka/BC** — starejši od: BC 0,75 × 13,1 = **9,8 let**; mešanček 0,75 × 12,0 = **9,0 let** (AAHA + McMillan). Dogs Trust preprosto: >7 let. "Starejši (8+ let)" iz REALISM_SPEC je med obema.

## 4. Popravki za `backend/config/breed_appearance.php` (Border Collie)
| Lastnost | Osnutek | Vir pravi | Predlog |
|---|---|---|---|
| size | medium-sized | RKC: Medium | ✓ (S5) |
| coat_length | medium-length rough double / smooth short | "Moderately long or Smooth", dvojna dlaka | preimenovati v "moderately long double" / "smooth double" (S1, S3) |
| coat_color | 7 barv z utežmi | katerakoli barva, belina ne prevladuje | barve dovoljene, **uteži nepodprte**; izključiti pretežno bele vzorce (S1) |
| coat_pattern / markings | seznami | standard ne opisuje | ostanejo `verified: false` |
| ear_carriage | semi-erect, erect, one erect + one semi-erect | FCI: erect/semi-erect; AKC: "one or both … erect and/or semi-erect" | ✓ vse tri podprte (S1, S3) |
| eye_color | rjave; jantar ob rdečih; modre samo ob blue merle; različne ob merlih | FCI/RKC: rjave, **pri merlih** ena/obe/delno modre; AKC: katerakoli, modre pri nemerlih niso zaželene | modre/različne/delno modre dovoliti **pri obeh merlih**; jantar odstraniti ali označiti "samo AKC" |
| tail | long, low-set, upward swirl | "Moderately long … set on low … upward swirl towards the end" | ✓ (S1) |
| build | athletic/lean/well-muscled | "sufficient substance … endurance" | delno podprto |
| `source` | null | — | `FCI 297 (S1) + AKC 2015 (S3) + RKC (S6)` za podprte lastnosti |

Mešanček: standarda ni; celoten seznam ostaja nepodprt (glej §2).

## 5. Odprta vprašanja za Davida
1. **Velikost mešančka:** "srednji" = 15–30 kg (Salt kat. IV) ali 11–27 kg (PetMD)? Ali naj starš izbere velikost mešančka (majhen/srednji/velik)?
2. **Odrasel 1× ali 2× hranjenje?** Viri se razhajajo (ASPCA 1×, VCA/RSPCA ≥2×). Predlagam 2×.
3. **Okna hranjenja 4×/3× pri mladičku** trčijo s šolskimi tihimi urami. Lahko hranjenje med tihimi urami "opravi starš" ali se okna raztegnejo?
4. **Koraki:** sprejmeš pretvorbo 100 korakov/min (vir za odrasle)? To pomeni BC 12.000, mešanček ~6.000. Ali je 2 h hoje na dan za otroka realno — ali BC ostane "zahtevna pasma" namenoma?
5. **Pravilo 5 min/mesec za mladiče** je sporno — ga uporabimo kot zgornjo mejo (z opombo) ali ne omejujemo?
6. **"3-3-3"** — brez znanega izvora; ga v igri omenimo staršem ali uporabimo samo raziskavo (večina psov >4 mes.)?
7. **Uteži barv Border Collieja** — brez vira; jih izpustimo (enakomerno žrebanje) ali poiščemo podatke (npr. register pasme)?
8. **Spanje:** RKC razponi ali izmerjene vrednosti (~11 h)?
9. Naj se citati pred uvozom ročno preverijo (priporočam vsaj PDF-je)?
