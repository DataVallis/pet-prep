# Baza znanja o skrbi za živali — načrt (M6-K)

> David, 31. 10. 2026: »Te poučne stvari damo v PetPrep. App in spletno stran. Na spletno stran mora iti čim več vsebine za GEO in SEO … tudi za mačke in ostale vrste, ko jih bomo dodajali. Moramo res dobro bazo vsega zgradit.«
> Povod: objava @golden_retriever_express (21. 10. 2026, ~1.600 všečkov, ~1.900 deljenj) »These Foods Can Kill Your Dog« — strupena / varna hrana in nasveti za nego.
>
> Status: **načrt; odločitve 1–3 sprejete 31. 10. 2026 (§10)**. Nič od tega še ni v aplikaciji ali na spletu.

## 1. Cilj

PetPrep postane **najbolj natančen, virsko podprt vir o vsakdanji skrbi za ljubljenčka** — v jeziku, ki ga razume otrok, in z dovolj globine za starša. Isti vir resnice napaja:

- **spletno stran** (petprep.si) — vsebina za iskalnike (SEO) in za AI odgovore (GEO: ChatGPT, Google AI Overviews / AI Mode, Perplexity, Gemini, Claude),
- **aplikacijo** — otrok in starš (Vodnik, »Ali si vedel?«, kvizi in dogodki v simulaciji),
- **družbena omrežja** — vrtiljaki, Curio reels, odgovori na komentarje.

Prednost pred konkurenco: večina vsebine na spletu (tudi objava zgoraj) meša »ubije« in »slabo prebavljivo«. PetPrep vsako živilo **razvrsti po stopnji nevarnosti z virom in citatom** — natančnost je naš znak kakovosti in hkrati to, kar AI sistemi raje citirajo.

## 2. Kaj že imamo (gradimo na tem, ne od začetka)

| Kaj | Kje | Uporaba za bazo znanja |
|---|---|---|
| Register vrst in pasem (2 vrsti, 83 pasem, 488 virov) | `docs/research/breed-registry.json` → spletna stran `/animals` (urna sinhronizacija) | vzorec za podatke + izvoz + gradnjo strani; povezave pasma ↔ tema |
| »Ali si vedel?« (zanimivosti pasem: citat, neodvisni preverjevalec, `verified_hash`, admin draft → verified → approved) | `docs/research/breed-facts/`, `breed_facts`, `DID_YOU_KNOW_SPEC.md` | isti postopek kakovosti; razširitev na zanimivosti **vrste** (tudi za mešančka in domačo muco, ki zdaj nimata nič) |
| `llms.txt`, `llms-full.txt`, `/llms/<vrsta>.<jezik>.txt`, JSON-LD, `FAQPage` | spletna stran `src/lib/llms.ts`, `jsonld.ts` | dodamo datoteke za bazo znanja |
| Varnostni namigi z virom (FDA čokolada, Agriculture Victoria mleko za muce) | rojstni dan ljubljenčka (`APP_REFINEMENT_SPEC.md` §9) | prva dva vnosa v bazo |
| Vročinsko opozorilo za ploskonose pasme | pravila nege pasem | vir za sezonski vodnik »vročina« |

## 3. Vsebinski stebri

Vsak steber ima **vrsto (species) kot obvezno polje** — nova vrsta (zajec, morski prašiček, skobčevka …) dobi isti nabor tem kot podatke, brez nove postavitve strani.

### A. »Ali lahko pes / muca je …?« — baza živil (najmočnejši SEO/GEO vir)
Ena vrstica = **živilo × vrsta**. Polja: stopnja, kratek odgovor (≤ 50 besed), zakaj (snov / mehanizem), znaki, kaj storiti, količina / oblika (npr. »jabolko brez pešk«), viri s citati, otroška razlaga.

Stopnje (zaprt seznam, ista beseda povsod):
1. **Strupeno — takoj k veterinarju** (čokolada, grozdje/rozine, čebula/česen, alkohol, surovo kvašeno testo; pri psu tudi ksilitol in makadamija; pri muci tudi lilije*). Stopnja velja **za vsako vrsto posebej po virih** — npr. makadamija in ksilitol nista opisana kot strupena za mačke (Merck: mačke niso ogrožene zaradi ksilitola), zato ju pri muci ne uvrstimo med strupena.
2. **Nevarno** (kuhane kosti, koruzni storži, koščice, zelo mastna hrana, sol v velikih količinah, kofein)
3. **Ni priporočljivo** (mleko pri odraslih mačkah, mandlji in drugi oreščki, pekoče, surova jajca)
4. **Varno v majhnih količinah** (jabolko brez pešk, korenje, stročji fižol, kumara, kuhan pust piščanec …)

\* Lilije niso hrana, a so za mačke med najnevarnejšimi stvarmi v domu → steber B, z vzajemno povezavo.

Začetni seznam: ~60 živil × 2 vrsti = **~120 strani na jezik**. Iz objave in vira ASPCA (People Foods to Avoid) + najpogostejša iskanja (»can dogs eat …«): čokolada, grozdje, rozine, čebula, česen, por, drobnjak, ksilitol, kava, čaj, alkohol, kvašeno testo, makadamija, mandlji, orehi, arašidi, arašidovo maslo (ksilitol!), avokado, češnje, breskve/slive (koščice), jabolka, hruške, banane, jagode, borovnice, lubenica, melona, ananas, pomaranče, limone, grozdje, paradižnik, krompir (surov / kuhan), korenje, kumara, stročji fižol, grah, brokoli, cvetača, buča, sladki krompir, koruza in storži, riž, testenine, kruh, sir, mleko, jogurt, jajca (surova / kuhana), piščanec, puran, svinjina, govedina, šunka/slanina, ribe (losos, tuna), kosti (kuhane / surove), sladoled, med, čips/slani prigrizki, pekoče (čili), kokos.

**Objava z Instagrama ima napake — mi jih ne ponovimo:** mandlji »can kill« (vir pravi: bruhanje, driska, lahko pankreatitis — stopnja 3, ne 1); mleko, čili, surova jajca so pod naslovom »Can Kill Your Dog« (stopnja 3); kokos je pri njih »varno za zdravo dlako« (ASPCA svetuje previdnost — stopnja 3/4, odvisno od vira); »češnje — cianid« drži za koščice, peclje in liste, ne za meso → razdelimo na »meso« in »koščica«.

### B. Strupene rastline, zdravila in stvari v domu
Lilije (mačke), poinsettia, bršljan, oleander, monstera/filodendron; zdravila za ljudi (paracetamol — posebej mačke, ibuprofen), permetrin (pasji antiparazitik za mačke), eterična olja, sredstva za čiščenje, antifriz, sol za posipanje, strup za glodavce, baterije, nitke/elastike (mačke). Isti model kot A (»predmet × vrsta«).

### C. Vodniki skrbi (stebrne strani, »pillar«)
Po vrsti in življenjskem obdobju (mladič / odrasel / starejši — iste meje kot v igri): hranjenje (kolikokrat na dan), voda, gibanje in sprehodi (#walkyourpet), spanje, nega dlake, zobje, kremplji, ušesa, kopanje, igra in šolanje, socializacija, toaleta (pes ven, muca pesek), parazitska zaščita in cepljenje (splošno + **državni modul**: npr. slovenska pravila samo na SL straneh — David 11. 10.).

Vsak vodnik ima razdelek **»Kako to vadi PetPrep«** (pravila igre iz PRODUCT_SPEC — številke samo tu, kot v registru).

### D. Pred prihodom in prvi teden
Kontrolni seznami: kaj kupiti, varovanje doma (»kaj mora z mize in tal«), prvi dan, prvi teden, prvi obisk veterinarja, delitev nalog v družini, »Je moj otrok pripravljen na psa / muco?« (vprašalnik → vodi v aplikacijo). To je vsebina z najbolj neposredno potjo do namestitve aplikacije.

### E. Nujni primeri
»Pes je pojedel čokolado — kaj zdaj?«, znaki zastrupitve, vročinski udar, kdaj takoj k veterinarju. Pravila: **nikoli doziranja, nikoli navodil za izzivanje bruhanja doma**, vedno »pokličite veterinarja ali center za zastrupitve, zapišite, kaj in koliko je pojedel«. Kontakti so **državni modul** in se objavijo samo, ko jih preverimo na uradnem viru (ASPCA APCC in Pet Poison Helpline za ZDA, Animal PoisonLine za UK, za Slovenijo dežurne klinike — še ni preverjeno).

### F. Sezonski vodniki (objava 3–4 tedne pred dogodkom)
Novo leto in ognjemet (strah) → **naslednji, objava do ~1. 12. 2026**; božič (čokolada, okraski, poinsettia); velika noč (lilije, čokoladna jajca); poletje (vročina, vroč asfalt, avto); zima (sol, mraz, antifriz); noč čarovnic (sladkarije, ksilitol).

### G. Otroška raven in kvizi
Vsako dejstvo ima **otroško različico** (kratko, brez strašenja, brez lobanj), kot zanimivosti pasem. Kvizi »Ali to sme pojesti?« na spletu (interaktivno, deljivo) in v aplikaciji.

### H. Nove vrste
Ob vsaki novi vrsti v aplikaciji: najprej `sources.md`, nato stebri A–D za to vrsto. Kandidati (vrstni red določi David): zajec, morski prašiček, hrček, skobčevka/papagaj, ribe. Na spletu se vrsta pojavi šele, ko je v aplikaciji (David 13. 10.) — vodniki skrbi za vrsto pa lahko izidejo prej, **brez trditve, da je že v aplikaciji**.

## 4. Kako vsebina pride v aplikacijo

- **Vodnik za vrsto** (otrok + starš) — tudi za mešančka in domačo muco (danes brez knjige). Iskanje, kategorije, »Prebrano X od Y« — isti zaslon kot »Moja pasma«, nov vir podatkov.
- **»Ali si vedel?«** — bazen zanimivosti = pasma + vrsta; brezplačni ljubljenčki dobijo vrstne zanimivosti.
- **Dogodki v simulaciji** (po beti, ločen spec): npr. »Na mizi je ostala čokolada — kaj narediš?« (pravilen odgovor = pospravi / pokliči starša; brez kazni za napačen odgovor v prvi različici, samo razlaga). Povezava z nasvetom »ne puščaj hrane na dosegu«.
- **Starš**: povezave na celotne strani na petprep.si z viri.
- Tehnično: posploši `breed_facts` → `care_facts` s poljem `scope` (`species` | `breed`) ali nova tabela z isto shemo; isti uvoz iz gita in odobritev v `/admin`. Odločitev v tehničnem delu speca ob izvedbi.

## 5. Podatkovni model (vir resnice v gitu)

`docs/research/care/` (EN polja + SL prevodi, kot breed-facts):

```
care/
  sources.md                 # C-viri (Cxx), enako kot dog-data / cat-data
  foods/<food-slug>.json     # en dokument na živilo, vrstice po vrsti
  hazards/<slug>.json        # rastline, zdravila, gospodinjstvo
  guides/<species>/<topic>.json
  checklists/<slug>.json
  regions/<country>.json     # državna pravila in nujni kontakti
```

Vsak zapis: `slug` (EN, stabilen), `species`, `level` (samo A/B), `answer`, `why`, `signs`, `what_to_do`, `child` — vsako kot slovar po jeziku (`{"en": …, "sl": …, …}`), ne kot stolpci `_en` / `_sl`, ker gre naenkrat 10–15 jezikov (David 31. 10.); vsak jezik ima svoj `status` in `verified_hash` (prevod se preveri proti EN besedilu in citatu), `sources: [{id, quote}]`, `game_rule_key?`, `status` draft | verified, `verified_hash`, `updated`, `related` (živila, pasme, vodniki). Izvozna skripta (kot `export-breed-registry.mjs`) zgradi `care-knowledge.json` → spletna stran ga sinhronizira kot register.

Pravila vsebine (enaka kot register + strožja za zdravje):
- vsako dejstvo z virom in dobesednim citatom (citat ostane v podatkih, na strani parafraza);
- vire razvrstimo: A (veterinarske fakultete, AAHA/WSAVA, Merck Veterinary Manual, FDA), B (ASPCA, RSPCA, PDSA, Blue Cross, Cats Protection, International Cat Care, Dogs Trust, VCA), C (ostalo — samo kot dopolnilo);
- neodvisni AI preverjevalec, ki besedila ni napisal; nato Davidova odobritev;
- brez doz, brez diagnoz, brez »hipoalergeno«, brez strašenja v otroški različici;
- ko se viri razlikujejo (npr. kokos), pokažemo oba in izberemo strožjo stopnjo.

## 6. Spletna stran — struktura in URL-ji

| EN | SL | Vsebina |
|---|---|---|
| `/care` | `/skrb` | vozlišče: stebri, iskalnik »Ali lahko … je …?«, najnovejše |
| `/care/dogs/can-dogs-eat` | `/skrb/psi/ali-lahko-pes-je` | seznam vseh živil s filtrom po stopnji |
| `/care/dogs/can-dogs-eat/chocolate` | `/skrb/psi/ali-lahko-pes-je/cokolado` | stran živila |
| `/care/dogs/toxic-foods` | `/skrb/psi/strupena-hrana` | »Top N« seznam, generiran iz baze |
| `/care/cats/dangerous-plants` | `/skrb/mace/nevarne-rastline` | |
| `/care/dogs/guides/feeding` | `/skrb/psi/vodniki/hranjenje` | vodnik |
| `/care/first-week/dog` | `/skrb/prvi-teden/pes` | kontrolni seznam |
| `/care/emergency` | `/skrb/nujni-primeri` | nujno + državni kontakti |

(Končni prevodi URL-jev ob izvedbi; slugi se po objavi ne spreminjajo.)

**Postavitev strani živila** (zaporedje je za GEO pomembno):
1. naslov kot vprašanje (»Ali lahko psi jedo čokolado?«),
2. **odgovor v prvem odstavku, ≤ 50 besed**, s stopnjo kot značko,
3. »Zakaj« · »Znaki« · »Kaj storiti« (kratki seznami),
4. tabela »Pes · Muca« (ista hrana pri obeh vrstah),
5. pogosta vprašanja (vidna + `FAQPage`),
6. »Za otroke« (otroška različica),
7. »Kako to vadi PetPrep« + CTA aplikacije (brez otroka, prilepljenega na telefon — David 18. 10.),
8. viri (oštevilčeni, s povezavami), »Posodobljeno: datum«, opomba o veterinarskem pregledu (§10).

## 7. SEO in GEO pravila

Kaj podatki raziskave kažejo (oktober 2026):
- AI sistemi najpogosteje izberejo stran zaradi **neposrednega odgovora na začetku** in strukture (seznami, tabele, FAQ); gostota dejstev ~1 podatek z virom na 150–200 besed.
- Strani, ki **same citirajo vire**, so citirane pogosteje; v izvirni študiji GEO so citati, statistike in viri dvignili vidnost za ~30–40 %.
- **Svežina**: večina zadetkov AI robotov gre na vsebino, mlajšo od enega leta; strani brez četrtletne posodobitve izgubljajo citate → v bazi imamo `updated` in četrtletni pregled (avtomatiziran, glej §9).
- **Seznami »Top N«** so med najbolj citiranimi oblikami → strani »25 živil, strupenih za pse« generiramo iz baze.
- Velik del AI citatov pride **izven lastne domene** (Reddit, YouTube, mediji) → vsaka tema dobi tudi vrtiljak / reel (kot ta objava, a natančen), Curio odgovarja.
- **ChatGPT išče prek Bing indeksa**, Google AI Overviews prek Googla → zemljevid strani mora biti v obeh. petprep.si je v Bing Webmaster Tools že uvožen iz Google Search Console (David 31. 10.); dodamo IndexNow ob vsaki objavi.

Tehnično na petprep.si:
- JSON-LD: `Article` (z `about` = vrsta / živilo, `citation` = viri, `dateModified` resničen), `FAQPage`, `BreadcrumbList`; povezave `sameAs` na Wikidata za živila in vrste;
- `llms.txt` dobi razdelek »Care knowledge« + `/llms/care-<vrsta>.<jezik>.txt`;
- notranje povezave: živilo ↔ vodnik ↔ pasma (npr. ploskonose pasme ↔ vročina) ↔ register; vsaka stran ≥ 3 povezave v isti steber;
- hreflang za vse jezike strani (`x-default` = EN); slugi in URL-ji po jeziku, gradnja strani brez sprememb, ko dodamo jezik (cilj 10–15 jezikov naenkrat);
- OG slike: generirana kartica (stopnja + živilo + vrsta) v CGP; nič fotografij tujih avtorjev.

## 8. Meritve

- Google Search Console: prikazi in kliki na `/care/**` (tedensko), število indeksiranih strani.
- GEO: mesečni ročni / avtomatski preizkus ~30 vprašanj (»can dogs eat almonds«, »ali lahko muca pije mleko« …) v ChatGPT, Perplexity, Google AI Mode — ali je petprep.si citiran.
- Prehod v aplikacijo: klik na CTA iz `/care/**` → prijava za zgodnji dostop / namestitev.

## 9. Faze

| Faza | Obseg | Rezultat |
|---|---|---|
| **K0** (≈ 1 teden) | `care/sources.md`, shema + izvozna skripta, **30 živil × pes + muca**, strani živil, seznam »strupena hrana« za obe vrsti, `/care/emergency` (brez nepreverjenih kontaktov), llms + JSON-LD | prvih ~60 strani na jezik |
| **K1** | ostalih ~30 živil, steber B (rastline, zdravila, dom), sezonski vodnik »Novo leto in ognjemet« (do 1. 12.) in »Božič« | ~150 strani na jezik |
| **K2** | vodniki skrbi C + prvi teden D za psa in muco, vprašalnik »Je otrok pripravljen?« | stebrne strani |
| **K3** (aplikacija) | Vodnik za vrsto, vrstne zanimivosti za brezplačne ljubljenčke, kviz | baza v aplikaciji |
| **K4** | dogodek »hrana na mizi« v simulaciji (ločen spec), nove vrste, nočna avtomatika: ena tema na zagon po istem pravilniku kot pasme (pisanje → neodvisno preverjanje → PR → spajanje na zeleni CI), četrtletni pregled svežine | rast brez ročnega dela |

Vsaka faza = PR-ji z dokumentacijo; vsebina se objavi na spletu šele, ko je odobrena.

## 10. Odločitve (David)

Sprejeto 31. 10. 2026: (1) **brez veterinarskega pregleda** — strani nosijo opombo »ni veterinarsko preverjeno« (kot zanimivosti pasem), viri in neodvisno preverjanje ostanejo obvezni; (2) **Bing + IndexNow**: petprep.si je v Bing Webmaster Tools že uvožen (enkratno, nič več za narediti), dodamo IndexNow; (3) **najprej splet K0–K1**, aplikacija (K3) sledi. (4) **jeziki**: baza in spletna stran se zgradita za 10–15 jezikov naenkrat (kateri — še odprto). Odprto: izbor jezikov in 5.


1. **Veterinarski pregled** vsebine o hrani in nujnih primerih (npr. pilotni veterinar iz 11. 10. kot recenzent z imenom na strani) — močno poveča zaupanje (E-E-A-T) pri zdravstveni vsebini; ali ostanemo pri »ni veterinarsko preverjeno«?
2. **Bing**: ponovno odpreti Bing Webmaster Tools / IndexNow zaradi ChatGPT?
3. **Vrstni red**: najprej splet (SEO/GEO) in nato aplikacija (predlog), ali oboje hkrati?
4. **Novi jeziki** za bazo znanja (kateri trg je naslednji)?
5. **Naslednja vrsta** po psu in muci (za steber H).

## 11. Viri raziskave (31. 10. 2026)

- ASPCA — People Foods to Avoid Feeding Your Pets: https://www.aspca.org/pet-care/aspca-poison-control/people-foods-avoid-feeding-your-pets
- Embrace Pet Insurance (povzema Merck Veterinary Manual o aliumih pri mačkah): https://www.embracepetinsurance.com/waterbowl/article/what-cats-cannot-eat-toxic-foods
- BSAVA — Toxins, common feline poisonings: https://www.bsavalibrary.com/content/chapter/10.22233/9781910443149.chap4_9
- GEO: Princeton/IIT Delhi (povzetek), https://www.erlin.ai/blog/generative-engine-optimization-best-practices ; https://www.stackmatix.com/blog/generative-engine-optimization-best-practices ; https://rankai.ai/articles/how-to-do-geo ; https://s10500.lovable.app/insights/generative-engine-optimization
- Zdravstvena (YMYL) vsebina in AI Overviews: https://searchenginejournal.com/how-to-create-health-ymyl-content-that-performs-in-ai-search/584431/

Številke iz SEO/GEO člankov so tržne raziskave agencij — usmeritev, ne dejstvo za objavo.

## 12. Jeziki — 13 naenkrat (David, 31. 10. 2026)

Izbor (anketa, priporočena možnost »EU + svet«): **DE, FR, ES, IT, PT, NL, PL, CS, HR, SV, DA, FI, NB** (+ obstoječa EN, SL = 15). Velja za bazo znanja in za celo spletno stran.

Postopek:
1. **L1 — infrastruktura spletne strani:** seznam jezikov v `src/i18n/config.ts` brez trdno vpisanih `en | sl` (proxy, `llms` poti, OG poti, regex-i), slugi strani po jeziku, `hreflang` za vse; jezik je viden šele, ko so vsa njegova besedila (strani + register + skrb) prevedena — gradnja pade, če kaj manjka.
2. **L2 — prevod strani in registra:** en agent na jezik prevede `src/content/<L>.ts`, `src/content/registry/<L>.ts`, `src/content/care/copy.ts` (+ `speciesWords`); drug agent (ne prevajalec) preveri pomen proti EN. Pravila tona iz `brand/README.md`; pravna besedila (pogoji, zasebnost) samo s pravnim pregledom ali z jasno oznako »prevod, velja EN«.
3. **L3 — prevod baze znanja:** vsak jezik je nov ključ v vseh besedilnih slovarjih; prevede ga agent iz **EN in citatov**, preveri neodvisni preverjevalec in za ta jezik zapiše `verified_hash`. Nočna avtomatika: po en jezik × paket živil na zagon (ločeno od zagona za nova živila).
4. **L4 — aplikacija:** ločen načrt (i18n aplikacije, trgovine).

## 13. Merjenje (David, 31. 10. 2026)

Samo Search Console. **Od 31. 10. 2026 brez Supermetricsa (David):** lasten Google servisni račun (uporabnik »Restricted« v Search Console, ključ v GitHub secretu `GSC_SERVICE_ACCOUNT_JSON`). Workflow `.github/workflows/seo-report.yml` (`scripts/seo-report.mjs`) 1. v mesecu ob 5:17 UTC prebere prejšnji mesec (celotna stran, skrb, pobarvanke, najboljše strani in iskanja, »skoraj« strani na položaju 4–15) in ga shrani kot artefakt `seo-report`; mesečno opravilo »PetPrep — mesečno SEO poročilo (skrb)« (1. v mesecu, 8:47) ga prebere in Davidu pošlje povzetek. Brez samodejnega preverjanja citatov v ChatGPT / Perplexity.

## 14. Vsebina zunaj strani — vrtiljaki (31. 10. 2026)

`scripts/care-carousel.mjs <species> <locale> <outDir>` sestavi vrtiljak 1080×1350 (7 slik) **samo iz preverjenega izvoza** — besede so enake kot na petprep.si. Prva dva (pes, mačka, EN) so na Drive `CGP/slike/objave/2026-10-31_what-can-pets-eat/` (id `19S1MIEO9s2aiuPACDsiJZ6uQklV_VrOB`).

Od 31. 10. 2026 opravilo vsake 3 dni pošlje v Postajo datiran brief objave (vrtiljak je ena od tem) — `docs/engineering/SOCIAL_BRIEF_RUNBOOK.md`.
