# PetPrep – testni zagon pridobivanja strank (pred izidom)

> **Status:** odločeno 28. 11. 2026 (David): test s plačanimi oglasi **pred izidom**, do prijave na zgodnji dostop; proračun **150 €**, trg **Slovenija**. Načrt izvedbe spodaj je Claudov predlog; oglasov še nihče ni zagnal.
> **Zakaj:** strošek na kupca (15 €) in konverzije v `VALIDATION_PLAN.md` so predpostavke; o plačanem pridobivanju nimamo niti ene izmerjene številke. Organski kanal je do 27. 11. prinesel ~500 klikov iz Googla in nič prijav.
> **Razmerje do načrta validacije:** to **ni val 2** (§5, 500–1.000 €, s pravim nakupom). Je predtest, ki izbere kanal in kreativo za val 2 in napolni seznam za val 1. Od proračuna vala 2 odšteje 150 €.

## 1. Kaj merimo in česa ne

| Meri | Ne meri |
|---|---|
| Ceno klika in delež klikov po kreativi | Ali starš plača 49,99 € (to pokaže val 2) |
| Delež obiskovalcev, ki pustijo e-naslov | Zadržanje otroka (H2) |
| **Strošek na e-naslov zainteresiranega starša** | Strošek na kupca (H4) – samo okvirna ocena |
| Katero sporočilo vleče | |

## 2. Zasnova

| | |
|---|---|
| Kanal | Meta (Instagram + Facebook), en oglasni račun |
| Trg | Slovenija, jezik slovenščina |
| Ciljanje | Odrasli (starši); **nikoli mladoletni**; brez ciljanja po starosti otrok v besedilu |
| Proračun | 150 € skupaj, ~15–20 € na dan |
| Trajanje | 7–10 dni; prve 3 dni brez sprememb (učenje) |
| Kreative | 3, enak proračun (§3) |
| Pristanek | `https://petprep.si/sl` z oznakami UTM (§4); prijava prek pojavnega okna / spodnje vrstice / obrazca zgodnjega dostopa |
| Cilj kampanje | Potencialne stranke na spletni strani (dogodek prijave), ne »doseg« |

## 3. Kreative (besedila izvirna, v slovenščini, starše vikamo)

| Oznaka | Vizual | Naslov | Besedilo |
|---|---|---|---|
| `k1-serija` | 10–15 s izsek iz serije »12 Weeks« (družinski sitcom, vedra scena) | Obljubil je, da bo skrbel zanj | Vsak otrok obljubi. PetPrep mu da 12 tednov, da to pokaže – z resničnim psom ali mačko v simulaciji, preden pride pravi. Prijavite se za zgodnji dostop. |
| `k2-aplikacija` | Pravi posnetek zaslona aplikacije s Suki (ne generiran zaslon) | Najprej 12 tednov. Potem pes. | Hranjenje, sprehodi s pravimi koraki, čiščenje. Vi vidite, ali otrok zdrži. Aplikacija izide kmalu – pustite e-naslov in vas obvestimo. |
| `k3-vprašanje` | Statična slika v CGP (grafit + meta, logotip) z vprašanjem | Vas otrok prosi za žival? | Preizkusite, preden se odločite. PetPrep je simulacija prave pasme: otrok skrbi, vi spremljate. Zgodnji dostop je odprt. |

Pravila, ki veljajo za vse tri: brez starostnega razpona otrok; otrok nikoli »prilepljen« na telefon; ljubljenček v oglasu je realistična simulacija, ne Curio; trgovini samo kot »kmalu«; brez »Data Vallis« in »Made in Slovenia«; brez zdravstvenih ali vzgojnih obljub; brez številk, ki jih nimamo.

## 4. Sledenje

- Povezava: `https://petprep.si/sl?utm_source=meta&utm_medium=paid&utm_campaign=ea-test-2026-12&utm_content=<k1-serija|k2-aplikacija|k3-vprašanje>`
- E-naslovi se shranijo v enotno bazo (`email_contacts` / `email_signups`) z virom, stranjo, jezikom in UTM – pregled v `/admin → E-naslovi (spletna stran)`, izvoz CSV.
- **Pred zagonom preveriti (enkrat):** testna prijava prek zgornje povezave se v administraciji pokaže z `utm_content`. Če se ne, oglasov ne zaženemo, dokler ni popravljeno.
- Brez dodatnih sledilnih orodij; Meta piksel samo, če ga David izrecno odobri (piškotki → soglasje prek Kockice).

## 5. Pragovi (predpostavke, ne izmerjeno)

Izhodišče: kupec sme stati največ ~35 € (H4). Če bi kupil vsak deseti prijavljeni (ugibanje), sme e-naslov stati do ~3,50 €.

| Izid po 150 € | Strošek na e-naslov | Odločitev |
|---|---|---|
| ≥ 43 e-naslovov | ≤ 3,50 € | **Nadaljuj:** zmagovalna kreativa gre v val 2 |
| 20–42 | 3,50–7,50 € | **Popravi:** novo sporočilo ali pristajalna stran, ponovi z ~100 € |
| < 20 | > 7,50 € | **Ustavi kanal:** poskusi drug kanal ali drugo občinstvo (odrasli, ki preizkušajo pasmo) |

Pravilo ustavitve med testom: kreativa, ki po 40 € nima nobene prijave, se izklopi; njen proračun gre drugima.

## 6. Kdo naredi kaj

| Korak | Kdo |
|---|---|
| Oglasni račun, plačilno sredstvo, objava oglasov | David (ali oseba, ki mu vodi oglase na Meti) |
| Besedila, UTM povezave, pregled pravil | Claude (ta dokument) |
| Vizuali: izsek serije, posnetek zaslona Suki, statična slika v CGP | David pošlje surovo gradivo; Claude pripravi montažo / sliko |
| Testna prijava z UTM (§4) | David na telefonu, Claude preveri navodila |
| Dnevni pregled številk, poročilo po koncu | Claude iz izvoza CSV + Davidovih posnetkov zaslona Meta Ads |

## 7. Pred zagonom

1. Starostni razpon v FAQ na petprep.si (»7 do 12«) – popravek čaka v odprtem PR spletne strani; oglasi ne smejo voditi na stran, ki krši pravilo.
2. Besedilo spodnje vrstice po Davidovi želji z 27. 11. (»Is your child asking for a pet?« / slovenska različica).
3. Politika zasebnosti: zgodnji dostop in e-pošta sta že opisana; če se doda Meta piksel, dopolniti.
4. Testna prijava z UTM (§4).

## 8. Po testu

- Rezultati v ta dokument (tabela po kreativi: prikazi, kliki, cena klika, prijave, strošek na e-naslov).
- Odločitev po §5 v `DECISIONS.md`.
- Prijavljene e-naslove ob izidu povabimo v val 1.
- Izmerjene vrednosti zamenjajo predpostavke v `VALIDATION_PLAN.md` §8 in so prvi pravi vhod za digitalnega dvojčka (`DIGITAL_TWIN.md`).
