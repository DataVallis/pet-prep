# PetPrep — three.js: kje se splača in kje ne

> **Zapisano 15. 11. 2026 (pogovor David + Claude).** David je vprašal, kje lahko three.js (https://threejs.org/docs/) naredi korak naprej v interakciji in zanimivosti, tudi poslovno. Analiza je Claudova; Davidove odločitve so označene **(David)**.
> **Stanje:** prototip št. 1 (§4) je **15. 11. 2026 prenesen na petprep.si** (David: »Prenesi prototip na petprep.si«): `/how-much-work-is-a-labrador` · `/sl/koliko-dela-je-z-labradorcem`, SL + EN, repo `pet-prep-website` PR #406. Izvorna koda strani je od takrat v `pet-prep-website/tools/real-cost/`; `docs/prototypes/real-cost/` tukaj ostane kot zgodovina prototipa. Ni v aplikaciji.
> Povezano: `GAMES_SPEC.md` (smer »pes ostane video«), `docs/business/VALIDATION_PLAN.md` §2 (kaj je zamrznjeno), `docs/business/REVENUE_MODELS.md` (R3 zavarovalnice, R4 spletna stran), `docs/business/BUSINESS_MODEL.md` §2 in §4 (kalkulator »Real Cost of a Dog«, lead magnet »Pet Promise Reality Check«), `docs/business/WEBSITE_SALES_SPEC.md` (Lemon Squeezy, brezplačen vzorec za e-naslov).

## 1. Sklep

Three.js se pri PetPrepu splača **na petprep.si**, ne v aplikaciji in ne za samega ljubljenčka.

- Three.js je dober za prizore, fiziko in predmete. Za fotorealističnega psa z dlako v 81 pasmah ni primeren, zato odločitev »pes ostane video, igra postane interaktiven sloj« (David, 30. 10. 2026) ostane.
- Spletna stran je Next.js, kjer three.js teče brez nove gradnje v trgovinah in brez spremembe prstnega odtisa aplikacije.

## 2. Zakaj ne v aplikaciji zdaj

| Razlog | Podrobnost |
|---|---|
| Sloj za igre že obstaja | Skia + Reanimated (GAMES_SPEC §7) pokrivata žogo, pero in čiščenje pri 60 fps. |
| Nova nativna odvisnost | Three.js v React Native gre prek `@react-three/native` (expo-gl) ali `react-native-wgpu` (React Native ≥ 0.81, samo nova arhitektura). Oboje pomeni novo gradnjo v trgovinah in večjo aplikacijo. |
| Zamrznitev validacije | »Ljubljenček v tvoji sobi« in 2,5D ljubljenček sta na seznamu zamrznjenega (VALIDATION_PLAN §2). |
| Ozko grlo ni interakcija | Aplikacija še ni v trgovinah in plačljivih družin še ni; 3D v aplikaciji ne premakne nakupa. |

## 3. Priložnosti

| # | Kaj | Poslovni učinek | Kdaj |
|---|---|---|---|
| 1 | **»Koliko dela in denarja je pes« v 3D** na petprep.si | Lead magnet, ki je v poslovnem modelu, a ni zgrajen. Vodi v »Obljubo o ljubljenčku«, darilni bon in izziv. Ima sponzorsko mesto za zavarovalnico (fiksno sponzorstvo, R3). | **prototip narejen 15. 11. 2026** (§4) |
| 2 | **Primerjalnik velikosti v registru pasem** (tudi razkritje rezultata kviza, `BREED_QUIZ_SPEC.md` §6) (pasma v merilu ob otroku, kavču, vratih) | Višine in teže z viri so že v registru. Edinstvena vsebina za SEO in deljenje na vsaki strani pasme. | predlog |
| 3 | **Demo v brskalniku »2 minuti s kužkom«** (obstoječi AI posnetki + interaktivni sloj) | Aplikacija je »coming soon«; demo zbira čakalni seznam že zdaj (oglasi, Product Hunt). Novih AI stroškov ni. | predlog |
| 4 | **Ljubljenček z globino** (ključna slika + globinska karta, odziv na nagib in dotik) | Davidova ideja 2,5D (ROADMAP M5-R07); pes ostane identičen. Prototip v Skii, brez three.js. | po validaciji |
| 5 | **3D kotiček ljubljenčka** (stvari iz »Želja«, medalje) | Dvigne vrednost »Želja« brez AI stroška na dizajn. Tveganje: nasprotuje pozicioniranju »otrok ni ves dan na telefonu« in meša realističen videz z risanim. | po validaciji, če sploh |

Številke 1–3 niso na zamrznjenem seznamu, a tudi niso na seznamu dovoljenega. Upravičijo se kot kanal pridobivanja za val 2 (plačani oglasi); to je Davidova odločitev.

### Kaj ne
- 3D model ljubljenčka (že zavrnjeno v M5-R07: drago in manj podobno videu).
- »Kuža na obisku« s three.js: načrtovana pot (fotografija sobe → urejanje slike → Kling) ohrani realizem.
- Curio v 3D na domači strani: krepi zmedo med maskoto in realističnim psom v aplikaciji (odločitev 25. 10. 2026).

## 4. Prototip št. 1 — »Koliko dela je z labradorcem?« (David: »ok daj ja«, 15. 11. 2026)

- **Kje:** na petprep.si `/how-much-work-is-a-labrador` · `/sl/koliko-dela-je-z-labradorcem` (od 15. 11. 2026, `pet-prep-website` PR #406; v zemljevidu strani, ne v glavi ali nogi; povezava s strani labradorca v registru, SL + EN, PR #410 — David 15. 11. 2026: zaenkrat samo labradorec). Interaktivni del je samostojen dokument v okvirju iste domene (three.js in pisave so del strani, brez zahtevkov tretjim), okoli njega besedilo s številkami in viri. Izvorna koda: `pet-prep-website/tools/real-cost/`; prototip `docs/prototypes/real-cost/` ostane kot zgodovina.
- **Tehnika:** three.js 0.187.0 in cannon-es 0.20.0 (fizika), brez strežnika. SL + EN, namizje in telefon, gumbi delujejo s tipkovnico, upošteva »zmanjšaj gibanje«. Preverjeno v brskalniku brez zaslona (namizje 1360 px, telefon 390 px), ne na pravem telefonu.
- **Potek:** (1) izbira odrasel pes / mladiček 2 meseca → (2) en dan se odvrti (ura, obroki, sprehod, kakec, vaja; pri mladičku »ven« vsaki 2 uri), vsako opravilo pade kot žeton na kup → (3) kup se pomnoži na teden, leto in 13 let → (4) »Za starše: koliko stane« z drsnikoma, izbiro valute in kupom kovancev + **mesto za partnerja** (poziv k partnerstvu) → (5) »Bi zmogel vsak dan?« s seznamom obljub, gumboma do izziva in »Obljube o ljubljenčku« ter gumbom »Odpri povzetek za starše«, ki na strani sestavi povzetek za tisk ali shranjevanje v PDF (T8), in neobveznim obrazcem za e-naslov starša s privolitvijo (T9).
- **V prizoru:** oznaka nad posodo pove, kaj je na kupu in koliko pomeni en žeton ali kovanec; pet info točk »i« odpre kratka dejstva z virom.
- **Znana omejitev:** na telefonu je prizor majhen in info točke so tesno skupaj.
- **Figura psa je namenoma igrača** iz osnovnih oblik, z napisom »V aplikaciji PetPrep je ljubljenček realističen.«

### M6-S07 — vse pasme (David 16. 11. 2026, `pet-prep-website` PR #442, združeno 16. 11. 2026)
- David: »Kalkulator razširi na vse pasme, pa da bo dinamično dodajalo tudi vse bodoče pasme« in »brez sklanjanja … koliko-je-dela-s-psom je pravilno«.
- **URL-ji:** `/how-much-work-is-a-dog/<breed>` · `/sl/koliko-je-dela-s-psom/<pasma>` za vseh 111 psov registra; nova pasma v registru dobi stran, vnos v zemljevidu strani in kartico na strani pasme ob naslednji gradnji. Stara labradorčeva URL-ja trajno preusmerita (308).
- **Naslovi brez sklanjanja:** »Labradorec: koliko je dela s tem psom?« · »How much work is a Labrador Retriever?«. Sredi stavka je ime pasme z malo začetnico (»s psom pasme labradorec«).
- **Številke iz registra** (`pet-prep-website/src/lib/realCostProfile.ts`): gibanje — najprej dejstvo `at_least` (parameter igre v aplikaciji), sicer prvo drugo dejstvo v minutah na dan, prikazano s kvalifikatorjem (»več kot 120 minut«, »do 60 minut«); življenjska doba — mediana, sicer sredina razpona, sicer »več kot« / »manj kot« / »približno« (pri »manj kot 10 let« račun vzame 10 let); krtačenje — enkrat na teden = 1, večkrat na teden = 2 (spodnja meja), vsak dan = 7, brez podatka ni vrstice; stroški — minimum PDSA po velikosti: majhen 69 £ + 415 £, srednji 83 £ + 531 £, velik 116 £ + 560 £ (PDSA nima orjaških psov, zato zanje velja velik kot spodnja meja; prebrano 16. 11. 2026). Labradorec ostane 90 min, 13,1 leta, enkrat na teden, velik pes.
- Za vse pasme enako: mladiček (4 obroki, 20 min sprehoda, 7× ven), 2 obroka odraslega psa, 5 min vaje, 100 korakov na minuto, 2 vrečki na dan. Figura psa je ista igrača za vse pasme.
- Interaktivni del dobi pasmo v naslovu okvirja in vsako vrednost preveri (meje števil, stalni seznami, ime brez znakov HTML).

### Številke v prototipu in njihov izvor
| Številka | Izvor |
|---|---|
| Odrasel labradorec 90 min gibanja na dan | Guide Dogs UK (S59), PRODUCT_SPEC §5 |
| 2 obroka (odrasel), 4 obroki (mladiček 2 meseca) | S18 / S19, PRODUCT_SPEC §5 |
| Mladiček zdrži 1 uro na mesec starosti → 7× ven med 7:00 in 19:00 | S30 / S31 (število 7 je izpeljano) |
| Mladiček 20 min gibanja (10 min × mesec) | pravilo, ki ga je potrdil David 5. 10. 2026 |
| Vaja 5 min na dan | S36 / S37 (vaje 5–10 min, spodnja meja) |
| Krtačenje 1× na teden | vir labradorca, PRODUCT_SPEC §5 (M5-R13) |
| Življenjska doba 13,1 leta | McMillan 2024 (S54) |
| 100 korakov na minuto, 2 vrečki na dan | **pravili simulacije PetPrep, ne vir** (v prototipu označeno »pravilo PetPrep«) |
| Stroški: najmanj 116 £ na mesec, 560 £ na začetku, velik pes | PDSA, »The cost of owning a dog«, https://www.pdsa.org.uk/pet-help-and-advice/looking-after-your-pet/puppies-dogs/the-cost-of-owning-a-dog (prebrano 15. 11. 2026) |
| Spanje: mladiček 15–20 ur, odrasel pes 12–14 ur (info točka) | The Royal Kennel Club (S28), PRODUCT_SPEC §5 |
| Tečaj: 1 EUR = 0,83559 GBP = 1,1052 USD → 1 £ = 1,1968 € = 1,3227 $ | referenčni tečaji ECB, 13. 11. 2026, https://www.ecb.europa.eu/stats/policy_and_exchange_rates/euro_reference_exchange_rates/html/index.en.html |

Izračun za 13 let uporabi številke odraslega psa za vsa leta (poenostavitev, napisana v prototipu).

### Odločitve (David, 15. 11. 2026)
| # | Odločitev |
|---|---|
| T1 | **Valuto izbere uporabnik: €, $ ali £.** Zneski ostanejo v funtih (valuta vira PDSA), druge valute so preračunane po **fiksnem** tečaju ECB z dne 13. 11. 2026 in označene s tildo (»~139 €«). Tečaj se ne osvežuje sam; ob prenosu na splet se zamenja ročno in datum se izpiše. |
| T2 | **Partner je izmišljen:** logotip PetPrep in pod njim manjši napis »partner« (`petprep-partner-placeholder.svg`). To je nadomestek za prototip, ne sredstvo znamke. |
| T3 | Vse se zapiše v repozitorij (ta dokument + izvorna koda prototipa). |
| T4 | **Posoda mora biti razumljiva.** David: »kaj je tam v steklenem boxu? ne razumem«, »zakaj so coini napisani desno zgoraj«. Oznaka ni več v kotu zaslona, ampak je pripeta nad posodo: naslov (»Opravila«, do 15. 11. 2026 »Kup opravil« / »Stroški v 13 letih«), merilo (»1 žeton = 500 opravil«, »1 kovanec = 200 €«) in legenda barv žetonov (obrok, sprehod, vrečka za kakec, hitro ven, vaja). |
| T5 | **Info točke z vsebino**, da si obiskovalec vmes kaj prebere: pet gumbov »i« v prizoru (skleda, pot, vrata, ležišče, 13 let), vsak odpre kratko dejstvo z virom. Med predvajanjem dneva so skrite. |
| T7 | **E-naslov starša gre prek Lemon Squeezy, brez SMTP** (David 15. 11. 2026, »ok se strinjam«): enako kot brezplačen vzorec »Obljube« (WEBSITE_SALES_SPEC) — brezplačen izdelek »Povzetek za <pasmo>« v trgovini PetPrep na Lemon Squeezy. Lemon Squeezy pošlje sporočilo staršu, shrani naslov s privolitvijo in obvesti Davida. Strežnik PetPrep ne pošilja e-pošte (`MAIL_MAILER=log` ostane) in ne dobi nove tabele. **PDF »Povzetek za labradorca« je narejen 15. 11. 2026** (SL + EN, 2 strani, `docs/prototypes/real-cost/summary/`). **Izdelek v Lemon Squeezy je objavljen 15. 11. 2026** (»PetPrep Summary: How Much Work Is a Labrador? (free)«, id 1528939, Lead magnet 0 €, datoteki SL + EN) in **korak 5 prototipa ima namesto polja za e-naslov gumb** »Pošlji mi povzetek (PDF, brezplačno)« do `https://petprep.lemonsqueezy.com/checkout/buy/5cb18a19-22d9-4ec7-befe-19a82e687cc5`. **Dve omejitvi (preverjeno 15. 11. 2026):** (1) trgovina je še v **testnem načinu** (preverjanje identitete pri Lemon Squeezy »In Review«), blagajna kaže »Test mode is currently enabled« — po aktivaciji je treba izdelek prenesti v živi način in zamenjati povezavo; (2) blagajna Lemon Squeezy **nima polja za privolitev v obveščanje**, zato zgornje »shrani naslov s privolitvijo« ne drži: naslov se sme uporabiti samo za dostavo povzetka, za novice potrebujemo ločeno izrecno privolitev (odprto). |
| T6 | **Mesto za partnerja je poziv, ne prazen okvir:** »Želite postati naš partner? Tu lahko predstavite svojo storitev in logotip …« s povezavo na stran za partnerje (`/sl/partnerji`, `/partners`), da je stran smiselna tudi, ko se povezava deli naokrog. |
| T8 | **Povzetek je dinamična stran, ne datoteka** (David 15. 11. 2026: »to ne moremo narediti univerzalno, na koncu bomo imeli več kot 300 pasem, ogromno jezikov … to mora biti bolj praktično, da je dinamično«). Stran sama sestavi povzetek iz trenutnih izbir (odrasel pes / mladiček, jezik, valuta, starševi oceni stroškov): tabela skrbi, stroški, obljuba s podpisi, viri. Starš ga natisne ali shrani kot PDF (`window.print()`, slog za tisk A4, ena stran). Brez datotek in brez nalaganja. **T7 je s tem opuščen:** izdelek v Lemon Squeezy (id 1528939) gre nazaj v osnutek, statična PDF-ja in skripta so odstranjeni iz repozitorija (PDF-ja ostaneta na Drivu kot arhiv). Na petprep.si isti graditelj vzame številke pasme iz `registry.json`. |
| T9 | **E-naslove zbira naš API — narejeno 15. 11. 2026 (M6-S05).** David: »moramo zbirati maile, da bo partnerjem, ki bodo imeli logo, nekaj pomenilo … kaj ne moremo na naš API poslat mail pa ga shranit«, nato »za povzetek mail ni obvezen, je pa priporočljiv« in »da ne bo podvojenih mailov«. V koraku 5 je pod gumbom za povzetek **neobvezen** obrazec »Priporočamo: novice PetPrep po e-pošti«: e-naslov + privolitev. Stran pošlje `POST https://api.petprep.si/api/summary-leads`; strežnik shrani e-naslov (male črke), različico in čas privolitve, pasmo, psa / mladička, jezik in vir, **brez IP-ja in imena**, in ne pošlje e-pošte. **Dovoljena vira (`source`): `real_cost` (ta stran) in `breed_quiz` (kviz pasem, M6-S06, od 16. 11. 2026).** **En e-naslov je ena vrstica:** ponovni vpis samo posodobi števec in zadnji obisk; brskalnik si poleg tega zapomni, da je naslov že oddan, in namesto obrazca pokaže zahvalo s povezavo »Vpiši drug e-naslov«. Prave vrstice nastanejo samo, ko stran teče na petprep.si; prototip drugje ne pošlje ničesar. Lastništvo naslova ni preverjeno (strežnik ne pošilja e-pošte); **potrditvenega sporočila ne pošiljamo** (David 15. 11. 2026). Novice pošilja **Klaviyo**, odjava je povezava v nogi sporočila (David 15. 11. 2026). Seznam je v administraciji (superadmin). **Nič se ne briše samodejno** — vrstica se izbriše samo, ko starš to izrecno zahteva (David 15. 11. 2026). Partner dobi **številke** (koliko družin, katere pasme), ne naslovov. **Besedilo privolitve, različica `2026-11`:** SL »Želim prejemati novice in nasvete PetPrep na ta e-naslov. Odjavim se lahko kadar koli.« · EN »I'd like PetPrep news and tips sent to this address. I can unsubscribe at any time.« **Predlog stavka za politiko zasebnosti (še ni na petprep.si):** SL »Če na strani ›Koliko dela je pes‹ vpišete e-naslov in označite privolitev, shranimo vaš e-naslov, čas privolitve, izbrano pasmo, ali ste gledali psa ali mladička, jezik strani ter kolikokrat in kdaj nazadnje ste naslov vpisali. IP-naslova in imena ne shranimo. Naslov uporabljamo samo za novice in nasvete PetPrep, ki jih pošiljamo prek ponudnika Klaviyo (ZDA). Odjavite se s povezavo v nogi vsakega sporočila. Podatke hranimo, dokler ne zahtevate izbrisa.« · EN »If you enter your e-mail on the ›How much work is a dog‹ page and tick the consent box, we store your e-mail, the time of consent, the breed you chose, whether you looked at a dog or a puppy, the page language, and how many times and when you last entered the address. We do not store your IP address or name. We use the address only for PetPrep news and tips, sent through Klaviyo (USA). You can unsubscribe with the link in the footer of every message. We keep the data until you ask us to delete it.« **Povezave s Klaviyem zaenkrat ne gradimo** (David 15. 11. 2026): naslovi se v Klaviyo prenesejo ročno (od 17. 11. 2026 z izvozom CSV v administraciji), odjava v Klaviyu stika pri nas ne izbriše. **Od 17. 11. 2026 (M6-S09) gre vsak e-naslov s spletne strani v ENOTNO bazo** (David: »Vse maile iz povsod zbiramo v naši bazi v adminu, ki pa naj bodo segmentirani …«): novi vmesnik je `POST /api/email-signups` (poleg e-naslova in privolitve pošlje `purpose`, `source`, `page_path`, `section`, `locale` in neobvezno vrsto, pasmo, kviz, UTM, referrer — brez IP-ja in imena); en e-naslov = en stik, vsaka oddaja = vrstica. Tabela `summary_leads` je prenesena v `email_contacts` / `email_signups` in odstranjena; `POST /api/summary-leads` (ta prototip) deluje naprej samo za stare strani. Job za prenos stikov v Klaviyo je v načrtu (ROADMAP M6-S10), še ni zgrajen. |

### Odprto za Davida
1. **Cene za Slovenijo.** Številke so britanski minimum PDSA, samo preračunan. Ali poiščemo slovenski vir ali ostane PDSA z opombo »cene v vaši državi so lahko drugačne«.
2. **Pravi partner:** kdo in pod kakšnimi pogoji (REVENUE_MODELS §4).
3. ~~Ali gre to na petprep.si pred koncem validacije~~ — David 15. 11. 2026: da, stran je prenesena (SL + EN). Ostalih 13 jezikov: David 16. 11. 2026 — pravilo »vse v vseh jezikih« (ROADMAP M6-S08).
4. ~~Ali naj se pasma izbira ali ostane ena stran na pasmo~~ — David 16. 11. 2026: kalkulator za **vse pasme**, dinamično iz registra, vsaka nova pasma samodejno (ROADMAP M6-S07).

### Naslednji koraki, če David potrdi
1. ~~Prenos v `pet-prep-website`~~ — narejeno 15. 11. 2026 (PR #406; three.js se naloži samo na tej strani). Odprto: podatki iz `registry.json` namesto vpisanih v kodo.
2. Stran na pasmo v registru: »Koliko dela je s <pasmo>?« (predlog s / z po pasmi).
3. Povzetek (T8) za vsako pasmo in jezik iz registra — brez datotek. E-naslov: T9 (lasten API, M6-S05) je narejen.
4. Merjenje: delež obiskov, ki pridejo do koraka 5 in pustijo e-naslov (cena stika za H4).
