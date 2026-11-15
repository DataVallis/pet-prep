# Prototype — "How much work is a Labrador?" (three.js)

Interactive 3D lead-magnet prototype for petprep.si (David, 2026-11-15). **Not shipped anywhere**: it is not part of
the app, the backend or `pet-prep-website`. Product context, numbers with their sources and open decisions:
`docs/product/THREEJS_OPPORTUNITIES.md` (Slovenian).

## Files
- `index.html` — markup + styles (PetPrep CGP v2 tokens, Bricolage Grotesque / Instrument Sans from Google Fonts).
- `src/main.js` — scene (three.js 0.187.0), token / coin physics (cannon-es 0.20.0), day playback, UI wiring.
- `src/strings.js` — all copy, SL + EN.
- `petprep-partner-placeholder.svg` — made-up "petprep partner" logo for the sponsor slot (PetPrep horizontal logo
  + the word "partner"). A placeholder only, **not** a brand asset — do not copy it into `brand/`.
- `standalone.html` — the built single file (own code inlined, three.js and cannon-es from cdn.jsdelivr.net through an
  import map). This is what was published as a private Claude page for David.

## Lead PDF ("Summary for a Labrador")
- `summary/build_summary.py` builds `summary/petprep-povzetek-labradorec-sl.pdf` and `summary/petprep-summary-labrador-en.pdf`
  (A4, 2 pages: care table, puppy note, costs in GBP with `~` EUR / USD, promise with signature lines, sources) from
  HTML with headless Chrome, fonts and logo from `brand/`. Numbers mirror `src/main.js` — change both.
- Use (David 2026-11-15, T7): both PDFs are the files of the **free Lemon Squeezy product** »PetPrep Summary: How Much
  Work Is a Labrador? (free)« (id 1528939, lead magnet, €0, published 2026-11-15). Step 5 of the page has a button
  (`SUMMARY_URL` in `src/main.js`) that opens its checkout: `https://petprep.lemonsqueezy.com/checkout/buy/5cb18a19-22d9-4ec7-befe-19a82e687cc5`.
  The parent enters an e-mail there and Lemon Squeezy sends the files.
- **Store is still in test mode** (Lemon Squeezy identity verification »In Review« on 2026-11-15): the checkout shows
  »Test mode is currently enabled«. After activation the product must be copied to live mode and `SUMMARY_URL` replaced
  with the live link.
- **No marketing consent:** the Lemon Squeezy checkout has no opt-in checkbox (checked 2026-11-15), so the addresses
  may be used only to deliver the summary, not for news.

## Run
```bash
cd docs/prototypes/real-cost
npm install
npm run serve      # http://localhost:8765/index.html
```

## Numbers
- Care numbers: `PROFILES`, `LIFE_YEARS`, `STEPS_PER_MIN` in `src/main.js` — origin of each in THREEJS_OPPORTUNITIES §4.
- Costs: PDSA minimum for a large dog, kept in GBP (`COST`).
- Currency: `RATES` — ECB euro reference rates of 13 November 2026 (1 EUR = 0.83559 GBP = 1.1052 USD), **fixed on
  purpose** (David: "kar fiksiraj"); converted amounts are shown with `~`.

## In the scene
- Bin label (`#binlabel`) and info points (`SPOTS`, `#info`) are HTML elements placed every frame by projecting a 3D
  position to the screen (`placeOverlays`). Info-point copy: `i_*` keys in `src/strings.js`.

## Not done
The summary button points to a test-mode checkout (see above); the page itself collects nothing. The dog is a toy figure on purpose (the app pet is a realistic AI video).
