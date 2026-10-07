---
description: Update HANDOFF.md with the current state after a work session
---

Update `HANDOFF.md` so the next agent (or David) can continue without losing context.

1. Collect facts: `git log --oneline -15` (root and `mobile/`), `git status`, current branch, test results from this session, ROADMAP.md progress.
2. Update section 1 (status + date + realistic % per milestone), add a dated entry at the top of section 6 "Session log" (what changed, files, decisions), adjust section 3 (known bugs / debt — remove fixed, add new) and section 5 (next 3–5 steps by roadmap ID).
3. Keep it factual: only claim something works if it was tested in this session. Never write "100 %" without passing end-to-end verification.
4. Record every decision of the session in `docs/product/DECISIONS.md`; update `docs/audiences/*` and `docs/engineering/DIAGRAMS.md` if user-visible behaviour or structure changed.
5. If the session produced something worth telling (shipped feature, decision, milestone, interesting finding), add a dated entry at the top of `docs/journey/BUILD_LOG.md` in Slovenian using its format (what happened · why it matters · how to tell it per audience). Facts only, mark unbuilt things as *načrt*, no child personal data.
6. Check `docs/product/FEATURES.md`: every feature touched this session has the right status / roadmap ID / date (add rows for new features; 📱 only when David confirmed it on a device). Sync the claude.ai Project copy `petprep/FEATURES.md` after merge.
7. Keep the file under ~300 lines; move older session-log entries to `docs/engineering/handoff-archive.md` when needed.
