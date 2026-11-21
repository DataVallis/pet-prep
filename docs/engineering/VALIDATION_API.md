# Validation plan — API contract (VALIDATION_PLAN §4.1, §4.4; H6, H7) — agreed 2026-11-21

> **Status:** backend built (`feat/M5-08-validation-signals`, 2026-11-21); app: mobile PR #1479. Contract additions of 2026-11-21 (coordinator, needed by #1479) are marked *(addition)*; what the implementation decided where the contract was silent is in §4.

David's decisions 2026-11-21:
- (D1) The nightly breed / language runs keep running; the validation freeze does not apply to them.
- (D2) The acquisition source is a question at sign-up: »Kje ste izvedeli za PetPrep?« Use exactly this wording; David corrected the grammar.
- (D3) The fake door price options are monthly: free / 2,99 € / 5,99 €.
- (D4) The parent survey goes by e-mail, not in the app. That is a separate PR, built later; it is not part of this contract.

## 1. Acquisition source (H1–H4 per channel)
- `POST /api/register` and `POST /api/auth/social` take two new optional fields. On social sign-in they are used only when the call creates a new account; for an existing account they are ignored.
  - `acquisition_source`: one of `friend_family`, `instagram`, `tiktok`, `facebook`, `youtube`, `school_kindergarten`, `search`, `website`, `ad`, `other`.
  - `acquisition_detail`: a string of at most 60 characters, trimmed. It is kept only when the source is `other` or `ad` (for example a campaign code); otherwise it is dropped.
- An invalid value never blocks sign-up: the server stores null and logs at info level. Old apps send nothing, which is null.
- New nullable columns on `users` (parents only): `acquisition_source`, `acquisition_detail`, `acquisition_recorded_at`.
- `PATCH /api/parent/settings` accepts the same two fields only while `acquisition_source` is still null. This gives parents who signed up earlier one chance to answer later. Once it is set, the server answers 200 and ignores the fields.
  - *(addition)* `PATCH /api/parent/settings` is a new route with a **partial body**: the acquisition fields may be sent on their own; `timezone` is optional there (required only when no acquisition field is sent; same rules as PUT when present). Answer `200 {message, settings: {timezone, acquisition_source}}`. **`PUT /api/parent/settings` is unchanged** (timezone required, acquisition fields not taken, same response).
- `GET /api/user` (parent) adds `acquisition_source` (string or null), so the app knows whether to offer the question.
- The data is included in the GDPR export (parent section).
- `/admin/funnel` gets a filter or breakdown by source, plus a »neznano« (unknown) row.

## 2. Parent dashboard opens (H6)
- New endpoint `POST /api/parent/dashboard-opened` (ability parent, throttle 30/min).
  - Body: `{ "trigger": "launch" | "foreground" | "push" }`, optional.
  - Answer: 204.
- The server records at most one row per parent per 30 minutes, in table `parent_dashboard_opens`: `user_id`, `family_id`, `opened_at`, `trigger`.
  - Rows older than 400 days are pruned.
  - The rows go with the account on deletion, are included in the export, and hold no child data.
- The app calls it when the parent dashboard screen gains focus after a cold launch, after coming back to the foreground, or after a push tap. The app debounces 30 minutes too, so the dashboard's polling never triggers it.
- In `/admin/funnel`, H6 is the share of families whose parent made ≥ 3 opens in program days 8–14 after the first pet's birth. The number of eligible families is shown next to it.

## 3. Phase-2 fake door »Dobili smo pravo žival« (H7)
- New endpoint `POST /api/parent/phase2-interest/viewed`, answering 204. It records a tap on the entry point, once per parent (the first time is stored; later taps update `last_viewed_at`).
- New endpoint `POST /api/parent/phase2-interest`, answering 200 with `{ "status": "saved" }`, then upserts one row per parent. Body:

  ```
  {
    "price_choice": "free" | "monthly_2_99" | "monthly_5_99",
    "email": "…" | null,
    "notify": true | false
  }
  ```

  - `email` is required when `notify` is true. Without one: 422 `email_required`. The app prefills the account e-mail.
- New table `phase2_interests`: `user_id` (unique), `family_id`, `first_viewed_at`, `last_viewed_at`, `price_choice`, `notify`, `submitted_at`.
- When `notify` is true, the e-mail goes into the unified e-mail store. Reuse `email_contacts` + `email_signups` from M6-S09 / #1418 with source `app_phase2`; do not add a parallel store.
- `GET /api/parent/dashboard` adds a top-level `phase2_interest` field: *(addition)* `{ "submitted": bool, "notify": bool }` (of the requesting parent), so the app shows »Hvala« instead of the form — `notify` false → a plain thank-you (without »Obvestili vas bomo«).
- In `/admin/funnel`, H7 = submitted with notify / viewed. Taps, submits and the price split are shown too.
- **Copy (honest, »v pripravi«):**
  - SL entry point: »Dobili smo pravo žival«
  - Screen title: »Pripravljamo pomočnika za pravega ljubljenčka«
  - Body: »Ta del je še v pripravi. Povejte nam, kaj bi vam pomagalo, in obvestili vas bomo, ko bo na voljo.«
  - Question: »Koliko bi bili pripravljeni plačati?«, with the options »Samo brezplačno«, »2,99 € na mesec«, »5,99 € na mesec«.
  - Checkbox: »Obvestite me, ko bo na voljo« plus the e-mail field.
  - Button: »Pošlji«.
  - After sending: »Hvala! Obvestili vas bomo.«
  - EN: »We got a real pet« / »We're preparing a helper for your real pet« / »This part is still in the works…« / »Free only« / »€2.99 a month« / »€5.99 a month«.
  - The app does not name any feature as available.

## 4. Implementation notes (backend, 2026-11-21)
Where the contract was silent, the backend decided as follows (no product rule changed):
- **Invalid input never blocks:** an unknown / non-string `acquisition_source` → null + one info log line (type and length only, never the value). `acquisition_detail` is trimmed, inner whitespace collapsed and **cut** to 60 characters (not refused); a non-string detail → null.
- **Social sign-in:** the fields are stored only on the call that creates the account (`created: true`).
- **Dedupe:** "at most one row per 30 minutes" = a new row only when the parent's latest row is ≥ 30 min old (checked under the user row lock). An unknown `trigger` → null (204, never a 422).
- **Phase 2:** `notify` true with a bad address → 422 `code: email_invalid` (besides `email_required`). A submit without a recorded tap sets `first_viewed_at` / `last_viewed_at` too. The e-mail goes to `email_contacts` / `email_signups` (source `app_phase2`, purpose `early_access`, consent version `app-p2-2026-11` — not a website version, so it never replaces a website consent) and `phase2_interests.email_contact_id` links the contact (a column the contract did not list; needed so the export shows the address and the deletion can remove it). The same address again adds no signup; a new address moves it; `notify` false removes the `app_phase2` signups (and the contact when nothing else is on it). Account deletion does the same.
- **Throttles:** `dashboard-opened` 30 / min per parent; both Phase-2 endpoints 10 / min per parent.
- **Funnel:** a family's source = the earliest answer of any of its parents; H6 counts parents one by one (one parent with ≥ 3 opens), days 8–14 = [first birth + 7 d, + 14 d) on the wall clock like d1 / d7 (payment-lock time is not subtracted); eligible = first birth ≥ 14 days ago. H7 = parents who sent with notify / parents who tapped, over the parents of the families of the shown sign-up weeks; superadmins never count.
- **Retention / reset:** `dashboard-opens:prune` daily 03:53 UTC (400 days). The game-data reset deletes both new tables; the e-mail store stays (KEEP).
