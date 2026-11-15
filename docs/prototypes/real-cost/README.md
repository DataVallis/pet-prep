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

## Summary for parents (dynamic, T8)
- Step 5 has the button »Odpri povzetek za starše«. `renderSheet()` in `src/main.js` builds the summary **in the browser**
  from the current choices (adult / puppy, language, currency, the parent's own cost sliders): care table (day / week /
  year / 13 years), costs, promise with signature lines, sources. »Natisni ali shrani kot PDF« calls `window.print()`;
  the print styles (`@media print`, A4) show only the sheet, one page.
- **No files, no e-mail, no upload anywhere** (David 2026-11-15: thousands of PDFs for 300 breeds × many languages are
  not practical). On petprep.si the same builder takes the breed's numbers from `registry.json`.
- History: the static PDFs + `summary/build_summary.py` and the free Lemon Squeezy product (id 1528939, T7) were made the
  same day and dropped; the two PDFs stay on Drive (`PetPrep/spletna-prodaja`) as an archive, the script is in git history
  (PR #1146).
- **Optional e-mail (T9, M6-S05):** under the summary button, the form »Priporočamo: novice PetPrep po e-pošti« (e-mail +
  consent box, hidden honeypot `website`). It posts to `POST {API_URL}/api/summary-leads` (`API_URL`, `CONSENT_VERSION`,
  `BREED` at the top of `src/main.js`). The server keeps one row per e-mail; the page also remembers in `localStorage`
  that the address was left and shows »Hvala. Vaš e-naslov imamo …« with a link »Vpiši drug e-naslov« instead of the
  form. The summary works without it. **Real rows are written only when the page runs on petprep.si**; anywhere else the
  form answers »To je prototip …« and sends nothing, unless `?api=<url>` names a test API. A filled honeypot is not sent.
  Tested against a mocked API (201, 422, 429, network error, return visit, honeypot, no API). The consent sentence is `news_consent` in `src/strings.js`
  — changing its wording needs a new version in `SummaryLead::CONSENT_VERSIONS` and here.

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
The page is a prototype and is not on petprep.si; the privacy policy there does not mention the e-mail form yet. The dog is a toy figure on purpose (the app pet is a realistic AI video).
