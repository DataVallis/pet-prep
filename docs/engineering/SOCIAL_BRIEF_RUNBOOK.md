# Runbook — post brief to Postaja every 3 days (M6-K, social)

David 2026-10-31: "a schedule that sends me into Postaja, dated, e.g. every 3 days post this, plus prompts for the
images — not every day, so the posts don't repeat". A scheduled task runs **every Monday and Thursday at 18:41 Ljubljana (every 3–4 days)** and adds **one dated
post brief** as a text material to the Postaja brand `petprep-si`. Postaja writes and schedules the posts from the
materials; David picks the brief in Postaja, creates the post and publishes it. The task never posts anything.

## 0. Rotation log = Postaja material names

Every brief is saved as `brief-<post-date>-<topic>-<subject>.md` (e.g. `brief-2026-11-03-food-dog-grapes.md`).
`get_brand("petprep-si")` → `materials[].filename` is the log: read it first, never reuse a `<subject>` from the
last 60 days, never the same `<topic>` twice in a row. A brief with today's post date already present → stop.

## 1. Pick (topic rotation, in this order, skip a topic with nothing new)

1. `food-dog` — one dog food from `docs/research/care-knowledge.json` (verified EN), toxic/dangerous first, then
   surprising "small amounts" ones (corn vs corn cob style pairs are good).
2. `breed-mistakes` — common owner mistakes of **Border Collie, Labrador Retriever, Golden Retriever or the mixed
   breed** only (Postaja CGP: other breeds are not shown in marketing). Items = the breed page's "mistakes" section
   facts (pet-prep-website `ownerMistakes`, i.e. the register facts with their sources).
3. `walkyourpet` — the daily walk goal of one of those breeds (CGP key facts, sources) + `brand/WALKYOURPET.md`.
4. `food-cat` — one cat food (verified EN). Cats in the app are "coming soon": say so if the app is mentioned.
5. `carousel` — "What can dogs / cats eat?" overview (slides from `scripts/care-carousel.mjs`), at most once a month.
6. `emergency` — what to do when a pet ate something (website emergency steps; never doses, never "induce vomiting").

## 2. Brief content (English post, Slovenian notes for David)

```
# Post on <YYYY-MM-DD> (<weekday>) — <title>
Channel: Instagram @petprep.si (carousel | single image | reel)
Pillar: Trust/education | Reach | Sales
## Slides / frames        (each ≤ 12 words on the image)
## Caption (EN)           hook line, 3–6 short lines of sourced facts, kind tone, CTA "Early access → link in bio",
                          link to the matching petprep.si page, max 5 hashtags incl. #PetPrep, #walkyourpet and #momtok
## Facts and sources      every fact with publisher + URL (from the export / register; nothing new)
## Image prompts          one prompt per slide for Higgsfield (photorealistic PetPrep AI animal, CGP colours
                          #F3F5F2 / #121614 / mint #7FE0B4, little text in image) — prompts only, never generate
## Opomba za Davida (SL)  one or two lines: why this post now, which page it links to
```

Rules: only facts already in the verified export or the breed register; never invented numbers, quotes or users;
never a child age range; never "Made in Slovenia", Data Vallis, French Bulldog, German Shepherd or Cavalier King
Charles; no frightening child text; health = "ask your vet". Brief post date = run date + 2 days.

## 3. Save and report

`add_material(brand="petprep-si", filename=<name>, text=<brief>, create_if_missing=false)`. Also save the brief as
`<post-date>_<topic>-<subject>.md` in Drive `CGP/slike/objave/` (id in `MEDIA_DRIVE.md`) when the Drive tool works.
No repo changes. Summary to David in Slovenian, one short message: date, topic, Postaja file name.
