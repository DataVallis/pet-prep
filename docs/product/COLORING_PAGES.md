# Pobarvanke za otroke (»Colouring pages«)

> Status: **brezplačne pobarvanke na spletu od 31. 10. 2026** (pet-prep-website PR #141). Prodaja (plačljiva knjižica, merch, Curio plišasta igrača, PetPrep puzzle, darila) je *načrt*. Ideja in odločitve: David, 31. 10. 2026.

## 1. Ideja

Starši natisnejo pobarvanke, otroci jih pobarvajo ali prerišejo. Starši risbo delijo na omrežjih in označijo @petprep.si. Hkrati so pobarvanke prvi izdelek, ki ga lahko kasneje prodajamo brez aplikacije. Za SEO: veliko ljudi išče »dog coloring pages printable«, vsaka stran pa notranje povezuje na bazo znanja o skrbi in strani pasem.

## 2. Odločitve (David 31. 10. 2026)

| # | Odločitev |
|---|---|
| C1 | **Najprej samo brezplačne** pobarvanke na spletu (anketa). Plačljiva knjižica pride kasneje, skupaj z merchem. |
| C2 | **Prodaja digitalnih izdelkov prek Lemon Squeezy** (David že ima račun). Lemon Squeezy je trgovec (merchant of record) in sam obračuna DDV. Še ni zgrajeno. |
| C3 | **Izjema v CGP:** pobarvanke so črtne risbe (sicer so živali na vizualih fotorealistične). Risbe nastanejo iz PetPrepovih AI portretov pasem in iz Curia (Higgsfield, Nano Banana 2.1, 2k, pokončno 3:4, ~2 kredita na stran). Ilustracije ostanejo izjema samo za pobarvanke. |
| C4 | **Deljenje:** risbo delijo **starši**, samo risbo — brez obraza in imena otroka (otroci pod 13 let na Instagramu nimajo računa; zasebnost otrok). Poziv: »tag @petprep.si with #walkyourpet«. |
| C5 | **Pasme v pobarvankah:** samo tiste, ki jih smemo kazati v marketingu (border collie, labradorec, zlati prinašalec, mešanček) + mačke (splošni mucek, brez pasme) + Curio. |
| C6 | **Curio ni skrbnik** (`brand/MASCOT.md`): na pobarvanki psa ne vodi na povodcu, ga ne hrani. Na strani »Curio v parku« psa samo gleda izza drevesa. |
| C7 | **Vsaka stran ima eno zanimivost z virom** in povezavo: živilo iz baze znanja (`care-knowledge.json`, preverjen jezik) ali točka »napake lastnikov« pasme (register). Nikoli novih trditev. |
| C8 | **Licenca:** brezplačno za osebno, družinsko in šolsko uporabo; prosimo, ne prodajajte in ne objavljajte drugje. |
| C10 | **Logotip na vsaki pobarvanki (David 31. 10. 2026):** spodaj desno je logotip PetPrep (znak + napis) kot obris, da ga otrok pobarva. Vir je vektorski `brand/logo/petprep-logo-outline.svg` (prozoren, brez polnila, grafitni obrisi, črke združene); v PDF ostane vektor, v PNG je zglajen. Stran je 1792 × 2620 (spodnji pas 220 px). |
| C9 | **Ideja za trgovino (David 31. 10. 2026):** poleg mercha in Curio plišaste igrače tudi **PetPrep puzzle**; plačljive pobarvanke in darila za otroke. Vse *načrt*, v `ROADMAP.md` (backlog). |

## 3. Kaj je na spletu

- `/coloring-pages` · `/sl/pobarvanke`: mreža 12 pobarvank, knjižica PDF (A4 in US Letter), »Kako«, »Delite risbo«.
- `/coloring-pages/<stran>` · `/sl/pobarvanke/<stran>`: velika slika, gumbi Natisni (natisne samo list), PDF A4, PDF US Letter, PNG; okvir »Ali veš?« z virom in povezavo; »Še več pobarvank«.
- 12 strani: Curio pozdravlja; zlati prinašalec z žogo, na postelji; labradorec ob jezeru, mladiček s skledama; border collie s frizbijem, z igračo-uganko; speči mladiček mešanček; mešanček pije vodo; mucek s klopčičem; muca v škatli; Curio v parku.
- Zemljevid strani, `llms.txt`, JSON-LD (ImageObject, ItemList, BreadcrumbList). Slika za deljenje (WhatsApp, Facebook …) pokaže samo pobarvanko na beli kartici na metini ploskvi (David 31. 10. 2026). Noga: »Izdelek → Pobarvanke«.

## 4. Kako dodati pobarvanko

1. Higgsfield: referenčna slika pasme s petprep.si (`/animals/dogs/<pasma>.webp`) ali Curio (`/curio/curio-waving.webp`) → poziv v slogu obstoječih (»Children's colouring book page … bold, smooth, closed black outlines … no shading, no grey, no text, no frame«). Generiranje samo z Davidovo potrditvijo.
2. Pretvori v 1-bitni PNG (`convert … -threshold 60% -type bilevel`) in shrani risbo brez logotipa v `pet-prep-website/assets/coloring/<id>.png`. Logotip doda skripta (korak 3).
3. Dodaj stran v `src/content/coloring/coloring.ts` (naslov, slug, alt v vseh jezikih, zanimivost) in id v vrstni red v `scripts/coloring-pdf.mjs`; zaženi `node scripts/coloring-pdf.mjs` (naredi `public/coloring/<id>.png` z logotipom ter vse PDF-je).
4. Novi jeziki: `coloringCopy` in besedila strani so `Record<Locale, …>`, zato jih jezikovno opravilo prevede (tsc jih zahteva).
