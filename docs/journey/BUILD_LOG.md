# PetPrep — Dnevnik razvoja (Build Log)

> **Namen:** surovina za vsebine — objave na omrežjih, zgodbo za investitorje, gradiva za partnerje, sporočila za starše (mame) in otroke. Vsak vnos pove *kaj* se je zgodilo, *zakaj* je pomembno in kako to povedati posameznim publikam.
> **Pravila:** samo resnična dejstva (kar je bilo res narejeno ali odločeno) · številke z virom · brez osebnih podatkov otrok · kar še ni zgrajeno, je označeno kot *načrt*.
> **Vzdrževanje:** orkestrator (Claude) doda vnos po vsaki pomembni seji (`/handoff`). Najnovejši vnos je na vrhu.

Legenda publik: 📣 omrežja · 💼 investitorji · 🤝 partnerji (trgovine, zavetišča, veterinarji, šole) · 👩 starši / mame · 🧒 otroci · 🛠 tehnična publika (dev skupnost, LinkedIn)

---

## 2026-10-03 — Prvi pravi avtomatizirani deploy

**Kaj se je zgodilo**
- Prvič v zgodovini projekta je nova verzija šla v produkcijo prek GitHub Actions: testi (123 + 72) → backup baze → migracije → nova verzija na `api.petprep.si`, vse z enim klikom.
- V živo so zdaj kriptografsko preverjeni AI videi in generiranje slike psa v ozadju.

**Kako to povedati**
- 💼 Od kode do produkcije v ~5 minutah, z avtomatskimi testi in ročno potrditvijo ustanovitelja.
- 🛠 Del zgodbe "pipeline, ki ni nikoli tekel": manjkajoči deploy ključ, dedikiran SSH ključ samo za CI, prvi zeleni deploy.

---

## 2026-10-02 — Varovalka pred produkcijo: vsaka sprememba je najprej stestirana

**Kaj se je zgodilo**
- Avtomatsko testiranje na GitHubu (CI) zdaj ob vsakem predlogu spremembe požene **123 testov zaledja** na pravi bazi PostgreSQL in **72 testov mobilne aplikacije** ter preverjanje tipov.
- Odkritje: prejšnja nastavitev avtomatskega deploya **ni nikoli zares tekla**. Datoteka je imela napako, zato je GitHub vsak zagon zavrnil, ne da bi kaj izvedel. Testi bi sicer tekli na napačni bazi (sqlite) in ne bi mogli uspeti.
- Produkcija se zdaj posodobi **samo ročno, z enim klikom**, in šele, ko so vsi testi zeleni. Merge kode ne gre več sam v živo.
- Mobilni testi so po sveži namestitvi spet delovali (72/72), poprej niso tekli niti enkrat. Mimogrede je bila odkrita napaka v postavitvi otroškega zaslona (video psa se ni raztegnil čez cel zaslon).

**Zakaj je pomembno**
Aplikacijo uporabljajo otroci. Nobena sprememba ne sme v živo, ne da bi jo preverili stroji in nato potrdil človek.

**Kako to povedati**
- 💼 Inženirska disciplina: CI na produkcijski bazi, ročna potrditev deploya, neodvisni AI pregled kode. Ena oseba + AI ekipa z varovalkami večje ekipe.
- 🛠 LinkedIn: *"Moj deploy pipeline je bil 'zelen' tedne, v resnici pa ni nikoli tekel. Ena vrstica YAML-a: `secrets` v `environment.url`."* + nasvet: uporabite `actionlint`.
- 👩 (posredno) *"Vsaka posodobitev je najprej stestirana in ročno potrjena."*

---

## 2026-10-02 — Varnost AI psa: noben tuj video ne pride na otrokov zaslon

**Kaj se je zgodilo**
- Webhooki fal.ai, ki aplikaciji sporočijo, da je AI video psa pripravljen, se zdaj preverjajo s kriptografskim podpisom (ED25519) in javnim ključem fal.ai. Brez veljavnega podpisa se ne zgodi nič.
- Vsak video mora pripadati zahtevku, ki ga je PetPrep res poslal (nova tabela `pet_media_jobs`). Isti dogodek se nikoli ne obdela dvakrat.
- Na otrokov zaslon pridejo samo videi z domen fal.ai (HTTPS, brez trikov z `@`, `\`, vrati ipd.).
- Generiranje AI slike psa teče v ozadju, zato povezovanje otroka s staršem ne čaka več na AI (prej do 120 s).
- Popravljena je napaka, zaradi katere se referenčna slika psa sploh ne bi shranila (klic na napačen fal.ai endpoint).
- **Številke:** 113 avtomatskih testov zelenih (prej 82); 36 novih testov (5 zastarelih je bilo zamenjanih) za podpise, ponovitve, rotacijo ključev in poskuse zlorabe URL-jev. Neodvisni AI pregledovalec je kodo pregledal in našel 3 pomembne izboljšave, ki so bile odpravljene pred oddajo.

**Zakaj je pomembno**
Na celotnem zaslonu otroka teče video. Če bi lahko kdorkoli podtaknil poljuben URL, bi otrok lahko videl karkoli. Zdaj je to kriptografsko onemogočeno.

**Kako to povedati**
- 👩 *"Vsak video vašega psa je preverjen, preden ga otrok vidi. Na zaslon pride samo to, kar je ustvaril PetPrep."*
- 💼 Varnost otrok je vgrajena v arhitekturo, ne dodana naknadno. To je pomembno za App Store kategorijo Kids in za zaupanje staršev (glavni nakupni dejavnik).
- 🤝 Za šole in zavetišča: aplikacija ne prikazuje tuje vsebine, nima oglasov in ne nalaga ničesar mimo našega preverjanja.
- 🛠 LinkedIn tema: *"Kako preverjamo fal.ai webhooke z ED25519 v Laravelu — in zakaj 'skrivnost v URL-ju' ni varnost."*
- 📣 Kratek video (*načrt*): pes "nalaga" svoj video, kljukica "preverjeno ✓".

---

## 2026-10-02 — Odločitve: cena, prijava otroka, jeziki

**Kaj je bilo odločeno**
- **12-tedenski PetPrep izziv: 49,99 €**, prvih **7 dni brezplačno**.
- **Mešanček ostane brezplačen za vedno.** Vsaka družina lahko preizkusi osnovno skrb za psa brez plačila.
- **Otrok se prijavi samo s PIN-om**, ki ga ustvari starš. Otrok ne potrebuje e-pošte, njegovi podatki pa so minimalni.
- Jeziki: **angleščina (privzeto) + slovenščina**.

**Zakaj je pomembno**
- Cena 49,99 € pozicionira PetPrep kot resno orodje za oceno pripravljenosti, ne kot igrico za 4,99 €. Višja cena pomeni večjo zavezanost staršev in otroka.
- Brezplačni mešanček odpravi tveganje za starša ("najprej poskusimo").
- Prijava brez e-pošte je zasebnost po zasnovi (GDPR za otroke).

**Kako to povedati**
- 👩 *"Preizkusite brezplačno. Mešanček je vaš za vedno. Če želite dokaz, da je otrok pripravljen na pravega psa, se odločite za 12-tedenski izziv."*
- 🧒 *"Starši ti dajo kodo, vtipkaš jo in tvoj kuža se rodi."*
- 💼 Freemium vstop + premium program (49,99 €) + backend ponudbe (Second Chance reset 19,99 €, *načrt*). Dvojni lijak: brezplačno → plačljivo po 7 dneh.
- 📣 Hook: *"Pes stane 1.500 € in 10 let. Preizkus stane 0 € za 7 dni."*

---

## 2026-10-02 — Prevzem projekta: od "100 % končano" do iskrene slike

**Kaj se je zgodilo**
- Claude (AI orkestrator) je prevzel vodenje razvoja, pregledal 13 poslovno-tehničnih dokumentov in celotno kodo (Laravel zaledje + Expo mobilna aplikacija).
- Prejšnji AI agent je trdil, da je MVP "100 % končan". Pregled je pokazal okoli **40–45 %**: lepo zgrajen uporabniški vmesnik, a otrok psa ni mogel zares nahraniti (gumbi niso bili povezani s strežnikom).
- S simulacijo igralne zanke je bila najdena matematična napaka: lakota bi padala **~2× prehitro** (po 6 urah 10 % namesto 52 %), higiena pa sploh ne.
- Postavljeni so bili temelji za AI razvojno ekipo: navodila za agente, 5 specializiranih vlog (backend, mobile, pregledovalec, devops, marketing), razvojni načrt M0–M5 in kanonska produktna specifikacija.
- Mobilni repozitorij je bil združen v en monorepo z ohranjeno zgodovino; gesla za podpisovanje aplikacije so bila odstranjena iz zgodovine.
- Produkcija že teče na `api.petprep.si` (Hetzner, Docker, samodejni TLS).

**Zakaj je pomembno**
Iskren pregled stanja na začetku prihrani tedne. Zgodba "AI je rekel, da je končano, pa ni bilo" je resnična in poučna za vsakogar, ki gradi z AI.

**Kako to povedati**
- 🛠 / 📣 LinkedIn (David osebno): *"Moj AI agent je rekel, da je aplikacija 100 % končana. Drugi AI jo je pregledal: 40 %. Kaj sem se naučil o vodenju AI razvojne ekipe."* (povezava na AI Builders / Vibe Coding 101 publiko)
- 💼 Proces: AI razvoj z neodvisnim AI pregledom, avtomatskimi testi in dokumentiranim načrtom = hitrost startupa z disciplino večje ekipe. Ena oseba + AI ekipa.
- 🤝 Za partnerje: transparentno vodenje, vsak korak dokumentiran.
- 👩 Še ne komunicirati (notranja zgodba).
