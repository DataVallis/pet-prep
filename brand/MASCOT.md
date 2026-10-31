# Curio, the PetPrep mascot ("Radovednež")

> Added 2026-10-13. Curio is the logo mark turned into a 3D character for promo videos (TikTok, Instagram Reels).
> Read `brand/README.md` first; this file only adds the character.

## Role (decided by David, 2026-10-13)
- **Curio is always the pet**: the practice pet that needs care. It is fed, walked, waits, gets bored, is happy when cared for.
- Curio is **never the caretaker or a coach**. The child and parent stay off-screen (implied by on-screen text).
- Reason: one role makes the account readable as a story series; the mixed role in early reels ("Feeding time", where Curio fed a bowl) confused viewers. Don't repost that reel.
- **Curio is the brand mascot, not the pet in the app** (David, 2026-10-25): in videos Curio *plays* a pet, but the pet a family cares for in PetPrep is a lifelike AI simulation of a real breed. Ads and captions must never suggest you look after Curio in the app (website: petprep.si/meet-curio).
- **Animated series** (David, 2026-10-31): silent family comedy with drawn human characters, Curio as the family pet — bible and pilot in `brand/mascot/SERIES.md`.

## Character DNA (prompt source of truth)
- Body: soft rounded cube (corner radius ~30 % of width, height ≈ 1.1× width), matte graphite `#121614`, velvety soft-touch designer-vinyl texture.
- Face: two large white oval eyes with graphite pupils (default gaze up-right, like the logo), small raspberry `#FF6B8B` button nose, **no mouth** — emotion only through eyes, eyelids, pupils and body tilt.
- No ears, no fur, no eyebrows, no fingers. Short rounded arms with mitten hands, stubby feet.
- Mint `#7FE0B4` collar with a small round blank raspberry tag.
- Style: stylized 3D render, premium designer vinyl toy, soft diffused light, clean uncluttered sets (fog-grey walls, white, light oak).
- Gender-neutral, referred to as "it". Name: EN "Curio", SL "Radovednež".
- Reference images: portrait (waving) and turnaround/expression sheet generated 2026-10-11 in David's Higgsfield library (Nano Banana). Always attach both as references when generating a new scene.

## Prompting rules learned (Kling 3.0 / Nano Banana)
- Never write "text", "caption" or "space for text" in a positive prompt — the model draws gibberish letters. Describe "a bare empty wall above" instead.
- Kling on Higgsfield has no negative-prompt field. Don't paste a negative list into the prompt (words like "mouth" get drawn). Phrase positively: "completely smooth blank face below the nose", "its face only ever shows emotion through its eyes".
- Avoid "smile"/"smiling" — it adds a mouth. Use "eyes squint into happy upside-down arcs".
- Big expressive motion (bouncing, cheering) still tends to add a mouth; keep motion small or plan to cut those frames.
- Set aspect 9:16 explicitly; "auto" returns 3:4 and Kling may add a solid band. If the clip is not 9:16, the edit fills the top with a blurred copy of the frame — never stretch rows (it distorts doors/walls and leaves a seam).

## Reel format that works
- Story series **"Day 1 vs Day 14"** (the kid's promise vs reality), Curio as the pet. Hook text in the first second, white native-style text boxes, "Day N" pills (Day 1 mint, later days graphite).
- End card: a one-line takeaway (e.g. "Practice the promise first."), the mark, "petprep", petprep.si, and a raspberry **"+ Follow for Day N+1"** button that promises the next episode.
- Posts in English, ≤ 5 hashtags, caption ends with a simple question (e.g. "Day 15: does the kid come back? YES or NO").
- Fonts for overlays: Bricolage Grotesque 800 (headlines, pills) and Instrument Sans 700 (hook boxes, small lines). No emoji inside rendered text (the fonts have none).
