# PetPrep — shranjevanje medijev in dokumentov na Google Drive

> Stalno pravilo (David, 29. 10. 2026; dokumenti dodani 15. 11. 2026). Velja za vsakega AI agenta, ki dela na PetPrepu.
> V repozitoriju od 15. 11. 2026 (prej samo v projektu claude.ai kot `petprep/MEDIA_DRIVE.md`).

## Pravilo

**Vsak medij, ki ga ustvarimo za PetPrep, se shrani na Davidov Google Drive v mapo PetPrep**, logično razvrščen. To velja za videe (promo, TikTok/Instagram reels, serija »12 Weeks«, Curio, YC/investitorji, demo posnetki aplikacije), slike (objave, oglasi, CGP, tisk, merch, portreti za kampanje) in končne izvoze. Ne shranjuj samo v pogovor ali lokalno — Drive je arhiv, kjer David najde vse.

- **Tudi dokumenti (David, 15. 11. 2026: »vse pdfje, dokumente, kar kreiraš, mora iti v git in na google drive«):** vsak PDF ali dokument, ki ga agent ustvari za PetPrep (povzetki, kompleti, gradiva za prodajo ali partnerje), gre **v git in na Drive**, ne samo v pogovor. PDF se na Drive naloži z orodjem Google Drive prek sklica na datoteko v delovni mapi repozitorija (`base64Content = sha256:<zgoščena vrednost>:<absolutna pot>`); pot zunaj delovne mape (npr. `/mnt/user-data/outputs`) orodje zavrne.
- Shrani **končne verzije** (in vmesne samo, če jih David želi primerjati). Ime datoteke: `YYYY-MM-DD_kratek-opis_v1.mp4` (npr. `2026-10-29_yc-demo-app_v1.mp4`), angleško ali slovensko, brez šumnikov.
- Ob vsaki objavi/izdelku zraven shrani tudi besedilo objave (caption, hashtagi) kot `.md` z istim imenom.
- Ne briši in ne premikaj Davidovih obstoječih datotek brez vprašanja. Manjkajočo podmapo ustvari sam po spodnji strukturi.
- Če nalaganje ni možno (npr. datoteka prevelika za orodje), Davidu jasno povej, katere datoteke in kam naj jih naloži sam.
- Na koncu dela v odgovoru navedi, kam na Drive si kaj shranil.
- Opomba (30. 10. 2026): orodje Google Drive sprejme samo vsebino, poslano neposredno v klicu, zato slik in videov iz delovnega okolja praktično ni mogoče naložiti. Besedila (.md) naloži agent, medije pa David ročno naloži v pripravljeno mapo. (15. 11. 2026: manjše datoteke v delovni mapi repozitorija gredo prek sklica na datoteko — glej zgoraj.)
- Postopek za videe (30. 10. 2026): agent v Higgsfield sandboxu zloži končne videe s pravimi imeni in v pravih podmapah v en ZIP, ga naloži kot Higgsfield datoteko (media_upload, splošna datoteka) in Davidu da eno povezavo za prenos; David ZIP razširi in podmape povleče na Drive. Povezavo zapiši v .md v ciljni mapi.
- Opomba (31. 10. 2026): nalaganje ZIP-a na Higgsfield (media_upload) lahko zavrne varnostna zaščita seje, če David tega v tisti seji izrecno ne odobri. Takrat agent ZIP (s pravimi podmapami) pošlje neposredno v pogovor, David ga prenese in podmape povleče v CGP/.

## Struktura (Drive → PetPrep)

Obstoječe (15. 11. 2026):

```
PetPrep/                              id 1WyxEZRV_TGWn8ivuqnGyJF8SGGLn_I1S
├── (poslovni Google Docs: MVP, Go To Market, Upsales, …)
├── spletna-prodaja/                  id 1TCvBQlnBjszvM2f46f5gVYeO-0xmDnqZ  (15. 11. 2026; PDF-ji za prodajo in zbiranje e-naslovov na petprep.si: 2026-11-15_povzetek-labradorec-sl_v1.pdf in _v2.pdf, 2026-11-15_summary-labrador-en_v1.pdf — arhiv; povzetek je od 15. 11. 2026 dinamična stran, skripta je v zgodovini gita, PR #1146)
└── CGP/                              id 1eEIo_8HNp_ZUhXep1c9QKPmQaCmigC7y
    ├── BRAND_CGP_za_agente.md
    ├── petprep-logotip-v2/           id 1kill13WF-v79KLDMFx0o-qBG9USC0hBk
    ├── vizitke/                      id 12MUIdwtRs5tgFhD_scCMxdrfmfNcQPmL
    ├── majice/                       id 1nFhJRWN1Il6-6nAT9OpDad_OoOrFClTk
    ├── slike/                        id 1hhlOzpGgk5Cg5M-35CSdox6pPp3F7l-4  (30. 10. 2026)
    │   ├── objave/                   id 1Dd5g3LNzXH4xlIW6zCLhwu_C0cJnunhA
    │   │   ├── 2026-10-30_walkyourpet-launch/   id 1JUjsf-CIeVIIvYfQn8t-YF8zHwjsa8sS  (opisi .md; slike naloži David iz walkyourpet-launch.zip; tudi v gitu brand/marketing/walkyourpet-launch/)
    │   │   ├── 2026-10-31_tamagotchi-serija/    id 1NwlHwHz9ZmMx4Nkv7TQWGBj6JtEDY9q8  (31. 10. 2026; serija 3 objav »Tamagotchi with a goal«: carousel 5 slajdov + statična; opisi v 2026-10-31_tamagotchi-serija_opisi.md; slike naloži David iz petprep_tamagotchi-serija_2026-10-31.zip)
    │   │   └── 2026-10-31_what-can-pets-eat/    id 19S1MIEO9s2aiuPACDsiJZ6uQklV_VrOB  (31. 10. 2026; vrtiljaka »What can dogs / cats eat?« 7 + 7 slik iz baze znanja (scripts/care-carousel.mjs); opisi v 2026-10-31_what-can-pets-eat_opisi.md; slike naloži David iz petprep_what-can-pets-eat_2026-10-31.zip)
    │   └── youtube/                  id 14a81yzBJY0A2Zsu50OV3YpVMrI3a8q0v  (1. 11. 2026; banner 2560×1440 + vodni žig 300×300 za kanal @petprep-si — naloži David)
    └── videji/                       id 1mbQnOCQNz8U-JtaIPiAUp3svSTi61QrC
        ├── YC/                       id 1YLYJKjHTdVCFVigJtUboLqwjsvoGcwpq  (founder video + demo, 29. 10. 2026)
        ├── 12-weeks-serija/          id 1r8kxP5GJw5twG54lrFPLPDaJ4Q9KuX9f  (30. 10. 2026; Ep. 1–10, seznam + opisi v 2026-10-30_12-weeks-serija_epizode-in-opisi.md; videi v ZIP https://d2ol7oe51mr4n9.cloudfront.net/user_38MODY6EnFS0rP9mjrYq6kFy3DX/60a6e141-dea9-4ca8-a0c1-553da4c6d95f.zip)
        ├── promo/                    id 1MtYyanYgEvA_27BoHsnsIh2sX9HU5DDI  (30. 10. 2026; promo 60 s v3 + oglas ZDA v3 — v istem ZIP-u)
        ├── curio/                    id 1oiYYtL1Ri42kbV6bsJoVEsf4DkmF9igS  (31. 10. 2026; 2026-10-31_tamagotchi-reel_v2.mp4 + .md — video naloži David iz petprep_tamagotchi-serija_2026-10-31.zip)
        ├── curio-serija/             id 15HgrnCVCtIsuK-nwkZA9HRsErNvIufHW  (31. 10. 2026; animirana serija Curio — CURIO_SERIES.md = knjiga serije + pilot; git brand/mascot/SERIES.md)
        │   ├── liki/                 id 10mrVdAlo0pudp5AmzBM4wBwyduJsr-z-  (listi likov: Mia, Leo, mama, oči, skupna slika družine)
        │   └── ep-01-pilot-rock-paper-leash/   id 1kOAQK6Z2ItmcWHmHDXZycdz0CZqFIWic  (storyboard sličice, posnetki, končni rez, izrezki)
        └── promo videi (PetPrep_promo_*.mp4)
```

Nove podmape ustvari po potrebi, takole:

```
spletna-prodaja/         PDF-ji in dokumenti za prodajo in zbiranje e-naslovov na petprep.si (povzetki pasem, kompleti)
CGP/videji/
├── YC/                  YC, investitorji, pitch posnetki
├── promo/               promo in oglasni videi
├── 12-weeks-serija/     epizode serije »12 Weeks« (ep-01, ep-02, …)
├── curio/               Curio reels in maskota
├── curio-serija/        animirana serija Curio (liki/, ep-NN-…/ za vsako epizodo)
├── app-demo/            posnetki zaslona in demo videi aplikacije
└── surovo/              surovi posnetki (screen recordings, raw)
CGP/slike/
├── objave/              objave za Instagram/TikTok/Facebook (+ caption .md)
├── oglasi/              Meta/TikTok oglasne kreative
├── pasme/               portreti pasem za marketing
├── youtube/             banner, vodni žig, sličice za YouTube
└── curio/               Curio slike, press kit
CGP/tisk/                tiskovine (vizitke, letaki); obstoječi mapi vizitke/ in majice/ ostaneta, kjer sta
```

Ob ustvarjanju nove mape dopiši njen id v ta dokument.

## YouTube kanal

- Kanal: **@petprep-si** (https://www.youtube.com/@petprep-si), ustvarjen 1. 11. 2026. Ročaja @petprep in @petprep.si sta bila zasedena.
- Nastavitve objav: Not made for kids, Altered content = Yes (AI-generirani ljudje/živali), kategorija Comedy, jezik English. Serija gre na seznam predvajanja **12 Weeks**; sličica = okvir iz videa z žival/otrokom in kratkim napisom.
- Nov kanal še nima klikljivih zunanjih povezav (YouTube jih odklene po nekaj zgodovine; verifikacija ni mogoča), opis z `https://` YouTube zavrne. Zato je v opisih `petprep.si` brez `https://`. Povezave so v Customization → Links na kanalu.
- Privzeti zaključek opisa:
  ```
  🐾 Walk your pet. #walkyourpet
  PetPrep — a realistic AI pet that lets kids prove they're ready for a real one.
  🌐 petprep.si · Instagram & TikTok: @petprep.si
  ```
- Glasbo za YouTube vzemi iz izvirnih datotek ali YouTube Audio Library; ne nalagaj TikTok izvozov z dodano TikTok glasbo (Content ID).

### Naložene epizode »12 Weeks« (1. 11. 2026, Ep. 1–10)

Naslovi: »Week N: … | 12 Weeks«. Vdelava na spletni strani: `https://www.youtube.com/embed/<id>`.

| Ep. | Teden | Povezava |
|---|---|---|
| 1 | Week 1: The Promise | https://youtube.com/shorts/XRjZrBaCEnU |
| 2 | Week 2 | https://youtube.com/shorts/PM4UgH2-2Gs |
| 3 | Week 3 | https://youtube.com/shorts/yJZ3I7JxF9A |
| 4 | Week 4 | https://youtube.com/shorts/zDAXgIi5Gos |
| 5 | Week 5 | https://youtube.com/shorts/yrokI1Z4Mzs |
| 6 | Week 6 | https://youtube.com/shorts/amgDs9QDwkE |
| 7 | Week 7 | https://youtube.com/shorts/-NG1WviHu_g |
| 8 | Week 8 | https://youtube.com/shorts/pJx4E5TQlhw |
| 9 | Week 9 | https://youtube.com/shorts/iiGvrKzbPzA |
| 10 | Week 10 | https://youtube.com/shorts/g2T5ZROUapA |
