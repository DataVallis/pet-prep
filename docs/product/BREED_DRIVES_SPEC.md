# PetPrep — Pasemski nagoni (Breed Drives)

> **Status:** ideja / *načrt*, 11. 10. 2026. **Odločil David 11. 10. 2026:** (1) nezadovoljen pasemski nagon sproži **pasemsko značilno pregreho z rokom 2 h, nato normalna lestvica nereda** — enako kot pregrizen copat po zamujenem sprehodu (M5-R02); (2) gradimo **po beti** (beta gre ven s psom in mačko, kot je načrtovano). Vse ostalo v tem dokumentu je **predlog (D)**, ki ga David potrdi, preden se začne gradnja. Nič od tega še ni zgrajeno.

## 1. Zakaj

Ljudje izberejo pasmo po videzu, potem pa so presenečeni nad njenimi nagoni. Tipična primera (David):

- **Jack russell terier** — družina se jezi, ker kopa luknje po vrtu. Terierji so vzrejeni za lov pod zemljo; kopanje je nagon, ne »poredno vedenje«.
- **Border collie** — »pase« otroke in jih ščipa za noge. Ovčarski nagon; brez dela in miselne zaposlitve si ga pes najde sam.

Pes, ki mu zadovoljiš nagon, je priden. To je točno tisto, kar mora družina izkusiti **pred** nakupom: ali zmore vsak dan zadovoljiti nagon te pasme. To je ena najmočnejših obljub PetPrepa in odlična zgodba za marketing (»vaš border vas bo pasel — razen če …«).

## 2. Kako deluje (predlog)

1. **Vsaka pasma ima 0–2 nagona** (polnilnik, ki se prazni podobno kot lakota; *(D)* npr. −X %/h, ob polnoči ne pade na 0). Mešanček in domača mačka: brez posebnega nagona ali blag splošen.
2. **Nagon zadovolji pasemsko značilna igra / aktivnost** (mini-igra, kratka, 30–90 s). Nekateri nagoni se zadovoljijo tudi s pravimi koraki (Apple Zdravje / Health Connect).
3. **Nezadovoljen nagon → pregreha** (David 11. 10.): pes naredi pasemsko značilno »pregreho«; otrok ima **2 h**, da jo razreši (enako kot pregrizen copat), nato velja **normalna lestvica nereda** (vpliva na rutine / Care Score kot drugi neredi).
4. **Ob pregrehi kratek »Ali ste vedeli?«** — zakaj pes to dela, z virom (pravila iz `DID_YOU_KNOW_SPEC.md`: samo resnična dejstva z virom).
5. **Starš** vidi nagon v izbirniku pasem (»Kaj ta pasma potrebuje«) in pregrehe v časovnici.

## 3. Primeri nagonov (predlog, viri še manjkajo)

| Pasma | Nagon | Igra, ki ga zadovolji | Pregreha, če ostane nezadovoljen | Razrešitev |
|---|---|---|---|---|
| Jack russell terier | kopanje / lov | zakopana igrača v peskovniku, lov na »plen« na palici | izkoplje luknjo na vrtu | zasuj luknjo + pokaži dovoljeno mesto za kopanje |
| Border collie | pašnja + miselno delo | zgoni ovce v stajo, učenje novega trika | »pase« otroka in ga ščipa za noge | prekini z ukazom + daj nalogo |
| Beagle | voh / sled | sledi vonju po zaslonu, poišči skrite priboljške | pobegne za vonjem (»zapusti dvorišče«) | pokliči nazaj + igra iskanja |
| Labradorec / retriverji | prinašanje, žvečenje | žoga (že obstaja, M5-R05) | odnese in zgrize stvari | zamenjaj za igračo |
| Sibirski husky | tek / vleka | povezan s pravimi koraki (daljši cilj) | tuli, uide | dolg sprehod |

Seznam pasem in nagonov se bo dopolnjeval skupaj z dodajanjem pasem (runbook `docs/engineering/ADD_BREED_RUNBOOK.md` dobi korak »nagon pasme« z virom). Prvi trije za izvedbo *(D)*: **Jack russell, Border collie, Beagle** — najbolj prepoznavni in najboljši za promo video.

## 4. Razmerje do obstoječih pravil

- **Igra in crkljanje (`PLAY_CUDDLE_SPEC.md`):** igra z žogo in crkljanje ostaneta brez točk (David 7. 10.). Sama pasemska igra prav tako ne daje točk; **posledica je pregreha** (nered z rokom), ne odvzem točk za neigranje — enak model kot »zamujen sprehod → pregrizen copat«.
- **Vedenje (M5-R02):** pregreha uporabi isti mehanizem kot copat (rok 2 h, nato lestvica nereda, prikaz 🆓 risba / 💶 AI video enkrat na življenjsko obdobje).
- **Mačke:** podoben model že obstaja (praskanje po zamujeni igri, CAT_SPEC). Pasemski nagoni mačk *(D)* kasneje.
- **Tihe ure:** nagon se v tihih urah ne prazni, pregreha se ne zgodi *(D)*.
- **Brezplačno / plačljivo:** pasemski nagoni so del plačljivih pasem (mešanček je brezplačen in nima posebnega nagona) *(D)*.

## 5. Splet in marketing

- Register živali na petprep.si: na strani vsake pasme rubrika **»Nagon in kako ga zadovoljiš«** (z viri).
- Promo: kratki videi »border pase otroke«, »jack russell prekoplje vrt« — s sporočilom, da PetPrep to pokaže pred nakupom.

## 6. Odprta vprašanja (za Davida, pred gradnjo)

1. Hitrost praznjenja nagona in kako pogosto se lahko zgodi pregreha (največ 1 na dan na psa?).
2. Ali nagon vpliva tudi na razpoloženje / video, ko je zadovoljen (»vesel« kot po igri)?
3. Ali naj ima mešanček blag splošen nagon (npr. igra) ali nobenega?
4. Kateri viri štejejo za opis nagona (pasemski klubi, RKC / AKC, veterinarski viri).
5. Ali dobijo pasemske nagone tudi mačke in kdaj.
