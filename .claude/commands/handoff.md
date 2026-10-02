---
description: Update HANDOFF.md with the current state after a work session
---

Update `HANDOFF.md` so the next agent (or David) can continue without losing context.

1. Collect facts: `git log --oneline -15` (root and `mobile/`), `git status`, current branch, test results from this session, ROADMAP.md progress.
2. Update section 1 (status + date + realistic % per milestone), add a dated entry at the top of section 6 "Session log" (what changed, files, decisions), adjust section 3 (known bugs / debt — remove fixed, add new) and section 5 (next 3–5 steps by roadmap ID).
3. Keep it factual: only claim something works if it was tested in this session. Never write "100 %" without passing end-to-end verification.
4. Keep the file under ~300 lines; move older session-log entries to `docs/engineering/handoff-archive.md` when needed.
