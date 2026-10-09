# PetPrep — Investor brief (living source)

> **As of 2026-10-03.** Source material for the 2-pager, pitch deck and data room. Numbers are labelled **assumption** unless measured. Slovenian business details: `docs/business/BUSINESS_MODEL.md`.

## One-liner
PetPrep is a 12-week AI dog simulator that gives parents **objective proof** whether their child is ready for a real pet — before they spend €1,000+ and commit to 10+ years.

## Problem
- Children pester parents for a dog; promises ("I'll walk it every day") are cheap.
- Upfront cost of a dog is commonly €500–2,000 plus ongoing yearly costs; when the child loses interest, the work falls on parents or the dog ends up rehomed. *(assumption; sources to be added)*
- Parents have no neutral way to test readiness.

## Solution
- Dual app: **child** cares for a photoreal AI dog (feed, water, clean, real-world walking via phone steps); **parent** sees a live dashboard and a traffic-light readiness signal.
- **Built for real families** (backend 2026-10-04, app screens *planned*): several parents and several children per family; each child gets their own dog or siblings share one, and every action is attributed to the child who did it — the basis for a fair per-child score and certificate.
- Realistic consequences: escalation, illness, "virtual shelter" on neglect; quiet hours for school and sleep.
- **Objective score — the "Care Score"** (rules decided by the founder 2026-10-04; computed by the backend, app screens *planned*): every day has measurable routines (each feeding window, 3 water refills, every mess cleaned within 2 hours not counting school and sleep, the daily step goal). Care Score = routines done on time ÷ routines expected × 100, −10 per illness, 0–100. Time the parent paused the game or the dog was "at the vet" never counts against the child. With siblings, every routine is split fairly (½ each for two children) and only a child's own actions count — one child doing everything scores 100, the other 0. A daily **traffic light** (red / yellow / green) per child and per dog tells the parent at a glance where to step in. Closed days are frozen, so the history behind a certificate can't be rewritten.
- After 12 weeks: Certificate of Responsibility with the child's Care Score *(planned)*.

## Business model (decided 2026-10-02)
- **Free forever:** basic mutt.
- **12-week PetPrep Challenge: €49.99 per dog** (one-time store purchase, consumable). **No free trial of the challenge since 2026-10-08 (David):** the free mixed breed is the free try-out, and the challenge starts with the purchase — an unpaid challenge dog waits paused from its "birth" (contract) until a parent buys, and the 12 weeks start at the purchase. No subscription, no automatic charge. A dog shared by siblings is one price; a second dog is a second challenge. Free mixed-breed sandbox forever (no 12-week programme, 7-day history). Full AI video set only after purchase (keeps AI cost tied to revenue). *Built 2026-10-07, trial removed 2026-10-08 (server + app); in production for the beta (testers buy in the store sandbox), public once the store products are published.*
- Back-end offers *(planned)*: Second Chance reset €19.99; affiliate/partner coupons at certification (pet stores); post-adoption AI assistant subscription (Phase 2).
- Unit economics from planning docs *(assumptions, not yet measured)*: lead CPA €1.50, 10 % lead→sale, CAC €15, AOV €52.99. Missing in that model: app-store fees, VAT, AI media cost — to be added.

## Go-to-market *(planned)*
Lead magnet "Pet Promise Reality Check" (free web tool → signed PDF contract) → challenge offer. Channels: Meta ads to parents of 7–12 y/o, mom-influencers on performance pay, TikTok/Shorts with AI dog videos, waitlist "first 500 families". First market: Slovenia (+ region), then DACH/UK.

## Product & technology (built)
- Laravel API + PostgreSQL + real-time WebSockets (Reverb) on EU hosting (Hetzner); Expo/React Native app (iOS + Android from one codebase).
- Game engine verified by automated whole-day simulations (e.g. mutt hungry after exactly 12 h 30 min, per spec).
- AI dog identity ("Pet DNA") with fal.ai image/video; webhooks cryptographically verified (child-safety by design).
- **Every dog looks unique** (2026-10-04, backend): appearance traits are drawn within the breed's range (>300,000 combinations for the mutt), reproducible per dog, never duplicated within a family; the image prompt contains no child data. *(Breed trait lists are a draft pending sourced breed standards.)*
- **AI cost is measured and capped before launch** (2026-10-04): every AI call is costed and booked in a ledger; daily / monthly spend caps fail closed (the game keeps working without new media); an internal AI Lab compares models side by side on cost and quality. **Production models picked 2026-10-05:** Nano Banana Pro (photoreal reference image) + Kling 3.0 Pro (5-second state videos, no audio). **Estimated** media cost per dog at birth (fal list prices): **≈ $1.27** for the free mutt (1 image + 2 state videos) and **≈ $3.51** for a paid breed (1 image + 6 state videos) (*estimate*; the split is a proposal awaiting founder sign-off). **Free pets share a pool of looks (built 2026-10-09, server):** each free breed has up to 20 looks whose media are generated once and reused, so a free pet costs **≈ $0** in AI media once its look has the stage's media; the one-off cost of filling a free breed's pool is at most 20 looks × 4 life stages × $1.27 ≈ **$101.60** (*estimate, list prices*). Paid breeds keep a unique, personal look. All media are copied to our own EU storage and served through short-lived signed links (built 2026-10-05). Extra media via token packs (*planned*, M4-09).
- **Sourced realism** (2026-10-05, backend): parents pick origin (bought / adopted) and age at arrival (puppy, young, adult, senior); the dog ages one month per week, moves through life stages and its needs change — puppies 4 → 3 → 2 meals, a daily walk goal of minutes of exercise × 100 steps (adult Border Collie 12,000; adult mutt 6,000). **Every number is stored with its source** (46 sources: AAHA, FCI / AKC / The Royal Kennel Club, ASPCA, Dogs Trust, peer-reviewed studies); unsourced proposals are flagged and never shown to families as fact. At a stage change the dog's photo is regenerated from the previous one (same dog, older) — ≈ $0.15 image + state videos, one transition in a 12-week puppy challenge (*estimate*).
- CI on every change (183 backend + 74 mobile tests as of 2026-10-03; **621 backend + 425 mobile as of 2026-10-04**; **749 backend + 494 mobile as of 2026-10-05**; **965 backend + 788 mobile** after the realism data import), independent AI code review, one-click deploys.
- Scoring engine tested on edge cases that real families hit: daylight-saving days (25-hour day), school and sleep hours, a dog born mid-evening, parent pause, vet time, siblings joining later; the parent dashboard costs the same database work for 7 or 84 days of history.
- Built by one founder + an AI engineering team with documented process (see BUILD_LOG).

## Traction
- **Pre-launch.** No users yet. Closed beta (20–50 families) planned after core loop + parent flows (roadmap M1–M5).

## Roadmap (high level)
M1 core game end-to-end → M2 parent onboarding & real dashboard → M3 notifications, step tracking, in-app purchase → M4 AI dog videos per state → M5 production hardening & closed beta. Phase 2: real-world AI pet assistant.

## Phase 2 — post-adoption *(planned, spec 2026-10-04: `docs/product/PHASE2_SPEC.md`)*
Goal: extend LTV from the 3-month challenge to the dog's 10–15-year life. One tap "We got a real dog" turns the simulator into a real-time AI assistant that already knows the family's routine.
- Features: smart-collar integration (e.g. Tractive) or white-label PetPrep collar; activity anomaly alerts; AI vet triage from photos (guidance only, always routes to a real vet); growth & nutrition tracking.
- Revenue: AI coach subscription (~€3.99/month), affiliate shop (pet stores), pet-insurance lead generation by breed risk, vet partner network (AI first contact → hand-off to a partner vet by call/chat: qualified leads, tele-consult fees or clinic membership), dog-school booking fees.
- Tech: LLM + retrieval over verified veterinary sources (pgvector in our existing PostgreSQL).
- Gate: measure how many challenge families actually adopt a dog before building.

## Risks & mitigations
| Risk | Mitigation |
|---|---|
| Kids drop off in days | That *is* the signal parents pay for; 7-day early win (badge) for the parent. |
| App-store rules for kids apps | No ads, minimal data, PIN login without email, parent-controlled purchases. |
| AI media cost | Pre-generate a fixed set per dog at birth (free mutt: image + 2 videos ≈ $1.27; paid breed: image + 6 videos ≈ $3.51 — *estimates*, built 2026-10-05); free pets share a pool of 20 looks per breed, media generated once and reused (≈ $0 per further free pet, ≤ ≈ $101.60 one-off per free breed — built 2026-10-09); per-call cost ledger with daily / monthly caps (built 2026-10-04); media stored on our own server (no per-view AI cost). |
| Simulation ≠ reality | Conditional guarantee; certificate tied to 12 weeks of objective data. |

## Team
David Tacer — founder (Data Vallis), full-stack developer & entrepreneur, Maribor, Slovenia.
