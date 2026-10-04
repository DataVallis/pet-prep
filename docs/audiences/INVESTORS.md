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
- After 12 weeks: Certificate of Responsibility *(planned)*.

## Business model (decided 2026-10-02)
- **Free forever:** basic mutt.
- **12-week PetPrep Challenge: €49.99 per dog**, 7-day free trial. A dog shared by siblings is one price; a second dog in the family is a second challenge (David, 2026-10-04). Both parents get access.
- Back-end offers *(planned)*: Second Chance reset €19.99; affiliate/partner coupons at certification (pet stores); post-adoption AI assistant subscription (Phase 2).
- Unit economics from planning docs *(assumptions, not yet measured)*: lead CPA €1.50, 10 % lead→sale, CAC €15, AOV €52.99. Missing in that model: app-store fees, VAT, AI media cost — to be added.

## Go-to-market *(planned)*
Lead magnet "Pet Promise Reality Check" (free web tool → signed PDF contract) → challenge offer. Channels: Meta ads to parents of 7–12 y/o, mom-influencers on performance pay, TikTok/Shorts with AI dog videos, waitlist "first 500 families". First market: Slovenia (+ region), then DACH/UK.

## Product & technology (built)
- Laravel API + PostgreSQL + real-time WebSockets (Reverb) on EU hosting (Hetzner); Expo/React Native app (iOS + Android from one codebase).
- Game engine verified by automated whole-day simulations (e.g. mutt hungry after exactly 12 h 30 min, per spec).
- AI dog identity ("Pet DNA") with fal.ai image/video; webhooks cryptographically verified (child-safety by design).
- CI on every change (183 backend + 74 mobile tests as of 2026-10-03), independent AI code review, one-click deploys.
- Built by one founder + an AI engineering team with documented process (see BUILD_LOG).

## Traction
- **Pre-launch.** No users yet. Closed beta (20–50 families) planned after core loop + parent flows (roadmap M1–M5).

## Roadmap (high level)
M1 core game end-to-end → M2 parent onboarding & real dashboard → M3 notifications, step tracking, in-app purchase → M4 AI dog videos per state → M5 production hardening & closed beta. Phase 2: real-world AI pet assistant.

## Risks & mitigations
| Risk | Mitigation |
|---|---|
| Kids drop off in days | That *is* the signal parents pay for; 7-day early win (badge) for the parent. |
| App-store rules for kids apps | No ads, minimal data, PIN login without email, parent-controlled purchases. |
| AI media cost | Pre-generate a fixed set of 6 videos per dog; free tier uses a static set. |
| Simulation ≠ reality | Conditional guarantee; certificate tied to 12 weeks of objective data. |

## Team
David Tacer — founder (Data Vallis), full-stack developer & entrepreneur, Maribor, Slovenia.
