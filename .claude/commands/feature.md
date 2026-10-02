---
description: Implement a roadmap task end-to-end (plan → build → test → review → docs)
argument-hint: <ROADMAP task ID, e.g. M1-07>
---

Implement roadmap task **$ARGUMENTS** for PetPrep.

1. Read `HANDOFF.md`, then find `$ARGUMENTS` in `docs/engineering/ROADMAP.md`. If it is marked **(D)** (needs David's decision) or the spec is ambiguous, stop and ask before coding.
2. Read the relevant parts of `docs/product/PRODUCT_SPEC.md`, `docs/engineering/ARCHITECTURE.md` and `docs/engineering/AUDIT-2026-10-02.md`.
3. Create a branch `feat/$ARGUMENTS-<short-slug>` (or `fix/…`) from `main`. Never merge or push to `main` yourself — it deploys to production.
4. Write a short plan (files, migrations, endpoints, tests) and mark the task `[~]` in ROADMAP.md.
5. Delegate: backend work → `backend-engineer`, app work → `mobile-engineer`. Backend contract first, then regenerate types, then mobile.
6. Run `/verify`. Then ask `qa-reviewer` for an independent review of the diff; fix blockers/majors.
7. Update docs: tick `[x]` in ROADMAP.md, ARCHITECTURE.md if API/schema changed, and run `/handoff`.
8. Summarise for David in Slovenian: what now works, how to try it, what's left. Do not commit/push unless asked.
