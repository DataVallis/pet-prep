# PetPrep brand (CGP v2 — "Grafit in meta")

Source of truth for logos, app icons, colours, type and voice. Chosen by David on 2026-10-06; it replaces CGP v1
(caramel/blue, Fredoka/Figtree, dog mark) — do not use v1 anywhere.
Full visual guide: Design System artifact "PetPrep" — https://claude.ai/artifact/X4WPx35McAibqUEPVaTVg4 ·
live reference implementation: https://petprep.si (repo `DataVallis/pet-prep-website`).

## Brand scope
- **All species** (dog first, cat and others later) — copy says "pet"/"žival", never assumes a dog.
- **Two phases:** Phase 1 simulator (12-week PetPrep Challenge), Phase 2 real-world AI assistant (`docs/product/PHASE2_SPEC.md`).
- **Global, localized:** English default, many languages. No text inside images, room for longer strings, locale-aware numbers/dates/currency.
- **Personality:** playful *and* serious, professional, enterprise. UI calm like a banking app; playfulness in one place at a time (the mark, one mint surface, the child's copy).
- **Slogan:** EN "Ready for a pet. There for its whole life." · SL "Pripravljeni na žival. Ob njej vse življenje."

## Files
| Path | What | Use |
|---|---|---|
| `logo/petprep-mark.svg` | Mark "Radovednež" (graphite face, raspberry nose) | small brand spot on light backgrounds |
| `logo/petprep-mark-on-dark.svg` | Mark for dark backgrounds (mint face) | simulator, dark screens |
| `logo/petprep-mark-mono.svg` | One-colour mark | stamps, embossing, notifications |
| `logo/petprep-logo-horizontal.svg` / `-inverse.svg` | Mark + wordmark, light / dark backgrounds | headers, login, store graphics |
| `logo/petprep-logo-stacked.svg` | Mark above wordmark | splash, certificate |
| `logo/petprep-wordmark.svg` | "petprep" (Bricolage Grotesque 800, outlined) | where the mark is already shown |
| `logo/*.png` | Raster exports of the above | docs, decks, email |
| `app-icon/petprep-app-icon.svg` + `-1024.png` | iOS / store icon (graphite face on mint, full bleed, no transparency) | `expo.icon`, App Store, Play listing |
| `app-icon/android-icon-foreground.png` | Adaptive foreground (face inside the 66 % safe zone, transparent) | `android.adaptiveIcon.foregroundImage` |
| `app-icon/android-icon-background.png` | Adaptive background, solid mint `#7FE0B4` | `android.adaptiveIcon.backgroundImage` (+ `backgroundColor: "#7FE0B4"`) |
| `app-icon/android-icon-monochrome.png` | Themed-icon / notification silhouette (eyes cut out) | `android.adaptiveIcon.monochromeImage`, `expo-notifications` `icon` |
| `app-icon/splash-icon.png` | Face on transparent | `expo-splash-screen` image on `#121614` (dark) or `#F3F5F2` (light) |
| `fonts/*.woff2` | Bricolage Grotesque 700/800, Instrument Sans 400–700 (latin + latin-ext) | web only — React Native needs TTF: use `@expo-google-fonts/bricolage-grotesque` and `@expo-google-fonts/instrument-sans` (OFL) |

Mobile: copy the `app-icon/*.png` files over `mobile/assets/` (same file names already referenced in `app.json`),
set `android.adaptiveIcon.backgroundColor` to `#7FE0B4` and the notification `color` to `#1A7A55`.
**Never change `name`, `version`, `ios.bundleIdentifier` or `android.package` in `app.json`.**

## Logo rules
- Mark: rounded graphite square (rx 30/100), two white eyes with pupils looking up-right, raspberry nose. No ears, no species, no other expressions.
- In running text always "PetPrep"; the logo wordmark is lowercase "petprep".
- Clear space = width of one eye. Minimum size: horizontal logo 110 px, mark 20 px.
- Don't recolour, stretch, outline, add shadows or put the light logo on busy photos.

## Colour tokens
| Token | Light | Dark | Role |
|---|---|---|---|
| bg | `#F3F5F2` | `#121614` | background (fog / graphite) |
| surface | `#FFFFFF` | `#1C221F` | cards |
| ink | `#121614` | `#F3F5F2` | text |
| ink-muted | `#5A635D` | `#A3ADA6` | secondary text |
| action / on-action | `#121614` / `#FFFFFF` | `#7FE0B4` / `#121614` | primary button (graphite; mint on dark) |
| mint | `#7FE0B4` | — | brand surfaces, icon, the care button that is due; **never text on light** |
| mint-text | `#1A7A55` | `#7FE0B4` | mint-coloured text / links |
| raspberry | `#FF6B8B` | — | the nose + at most one tiny accent per screen |
| ok / warn / danger | `#1A7A55` / `#8A6500` / `#B93125` | `#3DD68C` / `#FFD15C` / `#FF7A6B` | status only; danger is brick red, not raspberry |
| metric good / mid / low | mint / `#FFD15C` / `#FF7A6B` | | simulator meters |

Proportion: fog/white ~65 %, graphite ~25 %, mint ~8 %, raspberry ≤ 2 %.

## Type
- Headings and numbers: **Bricolage Grotesque** 700/800, tight tracking (−0.01 to −0.02 em).
- Body and UI: **Instrument Sans** 400/500/600/700. Both cover Slovenian diacritics.

## Shape and layout
- Radii: buttons 12, cards 22, sheets 30, care buttons and badges fully round. Icons: Lucide, 2 px stroke.
- Simulator (child): dark, glass panels over the pet video; the care button that is due is solid mint.
- Parent dashboard and Phase 2: white cards on fog.
- Touch targets ≥ 44 pt; contrast WCAG AA.

## Voice
- Child: informal "ti", short, never frightening. Parent: formal "vi" (SL), facts with sources. Owner (Phase 2): observation + reason + one suggestion.
- AI health: never a diagnosis, always a path to the vet, note "The AI assistant is not a substitute for a vet", urgent things first.
- Partner offers are labelled and the commission disclosed. Never invented numbers or reviews.
