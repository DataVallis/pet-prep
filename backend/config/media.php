<?php

/*
|--------------------------------------------------------------------------
| AI media (fal.ai) — model profiles, budget caps, AI Lab
|--------------------------------------------------------------------------
|
| M4-02 / M4-07 / M4-08. Every fal.ai call goes through a named profile
| (App\Services\Media\MediaProfiles) and the spend guard
| (App\Services\Media\AiSpendGuard), which checks the daily / monthly cap
| BEFORE the HTTP call and records the estimated cost in ai_spend_ledger.
|
| Prices are fal.ai list prices checked on 2026-10-04 (see `source`). They are
| ESTIMATES used for caps and the AI Lab; the fal.ai invoice is the truth.
| Profiles whose endpoint or price could not be confirmed stay `enabled => false`.
|
| Pricing units:
|   image      — flat price per generated image (`usd`)
|   megapixel  — `usd` for the first megapixel, `usd_additional` for each further
|                started megapixel (fal rounds up)
|   second     — `usd` per second of generated video
|
| Production models (David, 2026-10-05): reference image = Nano Banana Pro,
| state videos = Kling 3.0 Pro (5 s, no audio). Switchable per env
| (AI_REFERENCE_IMAGE_PROFILE / AI_STATE_VIDEO_PROFILE) without a deploy.
|
| NOTE: the price belongs to the PROFILE, not to the endpoint. Overriding an
| endpoint with FAL_MODEL_* does not change `pricing` — if you point a profile
| at a different model, update its price here too (or add a new profile).
| Negative prices are rejected.
|
*/

return [

    // Profile used for a new pet's reference image (GeneratePetReferenceImage). David 2026-10-05.
    'reference_image_profile' => env('AI_REFERENCE_IMAGE_PROFILE', 'nano_banana_pro'),

    // Profile used for the pet state videos (SubmitPetStateVideo, M4-03). David 2026-10-05.
    'state_video_profile' => env('AI_STATE_VIDEO_PROFILE', 'kling_v3_pro'),

    // M5-R01: at a life-stage change the reference image is EDITED from the previous one
    // (image-to-image, same dog grows up). A disabled / unknown profile falls back to
    // text-to-image with the same seed + DNA prompt + stage cue.
    'stage_edit_profile' => env('AI_STAGE_EDIT_PROFILE', 'nano_banana_pro_edit'),

    /*
    | Which state videos a pet gets at birth (M4-03) — MediaEntitlementService.
    | Claude's proposal 2026-10-05, waiting for David: the free mutt gets the
    | reference image + `basic`; a breed with breed_configs.premium_unlock
    | (Border Collie / the paid challenge) gets `full`. Payments (M3) and AI
    | tokens (M4-09) plug into MediaEntitlementService later.
    | Values: PetStateEnum. `idle` must be in every set (fallback video).
    */
    'video_states' => [
        'basic' => ['idle', 'sleeping'],
        // M5-R02 (David 2026-10-06): + behaviour videos `accident` and `chewing` —
        // MediaEntitlementService keeps them only where the event can happen
        // (accident: non-legacy puppy; chewing: non-legacy dog).
        // M5-R06-07: + `scratching` (cats only; dogs never get it, cats never get accident / chewing).
        'full' => ['idle', 'sleeping', 'low_energy', 'hungry', 'sick', 'playing', 'accident', 'chewing', 'scratching'],
    ],

    /*
    | M5-T04 (David 2026-10-20, APP_REFINEMENT_SPEC §10): the clips each mini-game
    | shows (PetStateEnum values). A game starts only when every clip of this list
    | that the pet is entitled to (video_states above) is stored for it — its own
    | file or its shared look's (GameMediaReadiness). `idle` is listed because it
    | is the stage's fallback when the pet's tier has no `playing` clip.
    | play = the play endpoint (the dog's ball game and cuddles, dog + cat — one
    | stage), wand = the cat's feather wand. Long term (§10) every phase gets its
    | own clip — add it here.
    */
    'game_clips' => [
        'play' => ['playing', 'idle'],
        'wand' => ['idle', 'playing'],
    ],

    /*
    | M5-T06 (David 2026-10-20, APP_REFINEMENT_SPEC §13): the ORDER the entitled
    | state videos are queued in (PetPreparationService::order). What the child
    | sees in the first minutes first — standing (idle), sleeping, eating
    | (hungry) — then the games (playing), then the rest. The reference image
    | always comes before every video (videos are made from it). Changes only
    | the order, never which states a pet gets (MediaEntitlementService) or
    | their number; states not listed keep their entitlement order at the end.
    */
    'preparation_order' => ['idle', 'sleeping', 'hungry', 'playing', 'low_energy', 'sick', 'accident', 'chewing', 'scratching'],

    /*
    | M5-T08 (APP_REFINEMENT_SPEC §7, David 2026-10-20 / 21): media per state.
    |  - KEY IMAGE per entitled state except `idle` (the reference image is the
    |    idle pose): an EDIT of the stage's reference image (`keyframe_profile`,
    |    Nano Banana Pro Edit — the same animal, room, light and framing).
    |  - State videos start from their key image and END on it (Kling 3.0 Pro
    |    `end_image_url`, see the profile) — a seamless loop (`recipe = loop`).
    |    A video profile without an end frame → `recipe = pose` (start frame
    |    only; the prompt asks to end in the pose).
    |  - TRANSITIONS: PetTransition values (start = key image A, end = key image
    |    B), played once by the app on a state change.
    | Who gets it: MediaEntitlementService::usesStateMedia() — a pet with the
    | full (purchased) set and `pets.state_media_version = 2` (new profiled
    | pets, a new purchase, admin "Generate missing"). The free basic set is
    | unchanged (open question for David: + sleeping key image 0.15 $ per look
    | stage). Extension points (M5-T09 / M5-T10 / M5-T11): ARCHITECTURE §7.
    */
    'state_media' => [
        'enabled' => filter_var(env('AI_STATE_MEDIA_ENABLED', true), FILTER_VALIDATE_BOOL),
        'keyframe_profile' => env('AI_KEYFRAME_PROFILE', 'nano_banana_pro_edit'),
        // Order = queue order, after every first-minutes clip (preparation_essentials).
        'transitions' => ['idle_to_sleeping', 'sleeping_to_idle'],
    ],

    /*
    | M5-T06: while the image or one of these clips is still on its way the
    | apps show »Pripravljamo [ime] … to lahko traja nekaj minut«; after that
    | only the features still waiting say »… se še pripravlja«.
    */
    'preparation_essentials' => ['idle', 'sleeping', 'hungry'],

    /*
    | Our copy of every generated file (M4-05). fal.media URLs are never given
    | to the apps: a queued job downloads the result to a private disk and the
    | apps get signed, expiring URLs to GET /api/media/{media}.
    */
    'storage' => [
        // Private local disk (config/filesystems.php → storage/app/pet-media, Docker volume app_storage).
        'disk' => env('PET_MEDIA_DISK', 'pet_media'),
        // petprep:reset-game-data (D17) empties the disk only when its root is
        // this directory (what scripts/reset-game-data.sh archives first).
        // null = storage_path('app/pet-media'); tests point it at the fake disk.
        'reset_expected_root' => null,
        'max_image_bytes' => (int) env('PET_MEDIA_MAX_IMAGE_MB', 25) * 1024 * 1024,
        'max_video_bytes' => (int) env('PET_MEDIA_MAX_VIDEO_MB', 60) * 1024 * 1024,
        // Must stay below the queue's retry_after (90 s) — StorePetMedia::$timeout is 85.
        'download_timeout_seconds' => min(80, (int) env('PET_MEDIA_DOWNLOAD_TIMEOUT', 60)),
        'image_mimes' => ['image/jpeg', 'image/png', 'image/webp'],
        // QuickTime (`ftypqt`) is accepted and stored as video/mp4 (PR #24 review m4).
        'video_mimes' => ['video/mp4', 'video/quicktime'],
        // Signed URL lifetime. URLs are bucketed (same URL for half the TTL) so a
        // state poll does not change the URI and restart the player; a URL is
        // valid for between TTL and 1.5 × TTL.
        'url_ttl_minutes' => max(10, (int) env('PET_MEDIA_URL_TTL_MINUTES', 60)),
        // Who sends the bytes of GET /api/media/{media} (M4-05b). `php` streams the
        // file from PHP (local dev, tests). `caddy`: PHP still checks signature +
        // authz, then answers with an empty body and an internal X-Accel-Redirect
        // header; Caddy intercepts it and serves the file from its read-only mount
        // of the storage volume (production default, set in compose.production.yaml).
        // Only applies to a local disk — any other driver always streams via PHP.
        'serve_via' => env('PET_MEDIA_SERVE_VIA', 'php') === 'caddy' ? 'caddy' : 'php',
        // fal fetches our stored reference image as the video start frame.
        'fal_fetch_ttl_minutes' => 360,
    ],

    /*
    | Shared look pool for free pets (M4-10, David 2026-10-09). A new profiled
    | pet of a free breed (premium_unlock = false: mutt, domestic cat) gets one
    | of `size` looks per breed instead of a unique DNA; a look's media are
    | generated once and reused by every pet of the look (0 $ per later pet).
    | The pool fills lazily (a new look per new free pet until it is full).
    | Off → every new pet gets a unique DNA v2 again (existing pool pets keep
    | their look).
    */
    'look_pool' => [
        'enabled' => filter_var(env('AI_LOOK_POOL_ENABLED', true), FILTER_VALIDATE_BOOL),
        'size' => max(1, (int) env('AI_LOOK_POOL_SIZE', 20)),
    ],

    // Pet DNA version for NEW pets: 2 = unique trait sampling (M4-08), 1 = pre-M4 fixed anchors.
    // Existing pets keep the DNA they were born with.
    'pet_dna_version' => (int) env('AI_PET_DNA_VERSION', 2),

    'budget' => [
        // Production caps (reference images, state videos). The AI Lab does NOT count
        // here — it has its own budget below, so lab runs can never starve new pets.
        // Estimated USD; a call that would exceed either cap is refused (fail closed).
        'daily_usd' => (float) env('AI_DAILY_BUDGET_USD', 5),
        'monthly_usd' => (float) env('AI_MONTHLY_BUDGET_USD', 50),
        // Day / month boundaries for the caps (operations clock, not a family clock).
        'timezone' => env('AI_BUDGET_TIMEZONE', 'UTC'),
    ],

    'lab' => [
        'enabled' => (bool) env('AI_LAB_ENABLED', true),
        'max_samples' => 4,
        // Hard ceiling for one lab run, on top of the lab caps.
        'max_run_usd' => (float) env('AI_LAB_MAX_RUN_USD', 3),
        // Separate lab budget (estimated USD), counted only over purpose = lab.
        'daily_usd' => (float) env('AI_LAB_DAILY_USD', 3),
        'monthly_usd' => (float) env('AI_LAB_MONTHLY_USD', 30),
        // Video run "Custom motion prompt" (David 2026-10-20: compare models for the
        // upcoming training ("school") and game clips). Max length of the free text.
        'motion_prompt_max_chars' => 500,
        // Presets that fill the custom motion prompt. Species-specific; the lab
        // prefixes "The same <breed + traits> as in the image," and appends the
        // species' video style (static camera, no people, …). No child data.
        'motion_presets' => [
            'dog' => [
                'sit_hold' => 'sits on command, then holds the sit looking up at the camera',
                'recall' => 'comes running toward the camera and stops',
                'mat_down' => 'walks to a mat and lies down on it',
                'ball_chase' => 'waits alert, then sprints after a thrown ball off-frame',
                'ball_return' => 'trots back holding a ball in its mouth',
            ],
            'cat' => [
                'wand_pounce' => 'crouches, wiggles and pounces at a feather wand',
            ],
        ],
    ],

    /*
    | Breed portraits for the website animal register (M5-R11, David 2026-10-10):
    | `artisan breeds:portraits` — one AI-generated photo per register breed,
    | labelled "AI-generated photo" on the website. Charged to the AI Lab budget
    | (purpose = lab), never the production budget. No child or pet data in any prompt.
    |
    | `traits`: per breed, trait values that replace the automatic pick (the option with
    | the highest weight in config/breed_appearance.php, the first one on a tie). Each
    | value must be one of the breed's options. Labrador: yellow — David 2026-10-10
    | ("Labrador: yellow"); the weights tie black and yellow, black is listed first.
    */
    'breed_portraits' => [
        // Longest side of the stored WebP (never upscaled; the model's square size if smaller).
        'max_size' => 1200,
        'webp_quality' => 88,
        'traits' => [
            'labrador_retriever' => ['coat_color' => 'yellow'],
        ],
    ],

    'profiles' => [

        'image' => [

            // Pre-M4 default (FalAiService::MODEL_IMAGE), kept for the lab and as a cheap fallback.
            'flux_schnell' => [
                'label' => 'FLUX.1 [schnell] (fast/cheap)',
                'endpoint' => env('FAL_MODEL_FLUX_SCHNELL', 'fal-ai/flux/schnell'),
                'enabled' => true,
                'verified' => true,
                'source' => 'https://fal.ai/models/fal-ai/flux/schnell (2026-10-04: $0.003 per megapixel, rounded up)',
                'pricing' => ['unit' => 'megapixel', 'usd' => 0.003],
                'width' => 1024,
                'height' => 1024,
                'supports_negative_prompt' => false,
                'supports_seed' => true,
                'params' => [
                    'image_size' => ['width' => 1024, 'height' => 1024],
                    'num_inference_steps' => 4,
                    'num_images' => 1,
                    'enable_safety_checker' => true,
                ],
            ],

            'flux2_pro' => [
                'label' => 'FLUX.2 [pro] (Black Forest Labs)',
                'endpoint' => env('FAL_MODEL_FLUX2_PRO', 'fal-ai/flux-2-pro'),
                'enabled' => true,
                'verified' => true,
                'source' => 'https://fal.ai/models/fal-ai/flux-2-pro (2026-10-04: $0.03 first MP, $0.015 each additional MP)',
                'pricing' => ['unit' => 'megapixel', 'usd' => 0.03, 'usd_additional' => 0.015],
                // fal preset portrait_16_9 = 576 x 1024 (9:16 for the phone; video models keep the image's ratio).
                'width' => 576,
                'height' => 1024,
                'supports_negative_prompt' => false,
                'supports_seed' => true,
                'params' => [
                    'image_size' => 'portrait_16_9',
                    'safety_tolerance' => '2',
                    'enable_safety_checker' => true,
                    'output_format' => 'jpeg',
                ],
            ],

            // Production default since 2026-10-05 (David). Schema checked on
            // https://fal.ai/models/fal-ai/nano-banana-pro/api (2026-10-05): prompt, num_images,
            // seed, aspect_ratio, output_format, safety_tolerance, resolution (1K|2K|4K);
            // no negative_prompt (the DNA prompt states "no people, no text, …" itself).
            'nano_banana_pro' => [
                'label' => 'Nano Banana Pro (Google Gemini image)',
                'endpoint' => env('FAL_MODEL_NANO_BANANA_PRO', 'fal-ai/nano-banana-pro'),
                'enabled' => true,
                'verified' => true,
                'source' => 'https://fal.ai/models/fal-ai/nano-banana-pro (2026-10-04: $0.15 per image, 4K double)',
                'pricing' => ['unit' => 'image', 'usd' => 0.15],
                'width' => 576,
                'height' => 1024,
                'supports_negative_prompt' => false,
                'supports_seed' => true,
                'params' => [
                    'num_images' => 1,
                    'aspect_ratio' => '9:16',
                    'resolution' => '1K',
                    'output_format' => 'jpeg',
                    'safety_tolerance' => '2',
                ],
            ],

            // M5-R01 stage transitions (image-to-image). Endpoint, schema and price checked on
            // https://fal.ai/models/fal-ai/nano-banana-pro/edit/api (2026-10-05): prompt (required),
            // image_urls (list, required), num_images, seed, aspect_ratio (auto|…|9:16),
            // resolution (1K|2K|4K), output_format, safety_tolerance; output images[].url.
            // $0.15 per image (4K double). Not in the AI Lab (needs an input image).
            'nano_banana_pro_edit' => [
                'label' => 'Nano Banana Pro Edit (image-to-image, stage growth)',
                'endpoint' => env('FAL_MODEL_NANO_BANANA_PRO_EDIT', 'fal-ai/nano-banana-pro/edit'),
                'enabled' => true,
                'verified' => true,
                'lab' => false,
                'source' => 'https://fal.ai/models/fal-ai/nano-banana-pro/edit (2026-10-05: $0.15 per image, 4K double)',
                'pricing' => ['unit' => 'image', 'usd' => 0.15],
                'width' => 576,
                'height' => 1024,
                'image_param' => 'image_urls',
                'supports_negative_prompt' => false,
                'supports_seed' => true,
                'params' => [
                    'num_images' => 1,
                    'aspect_ratio' => '9:16',
                    'resolution' => '1K',
                    'output_format' => 'jpeg',
                    'safety_tolerance' => '2',
                ],
            ],

            'seedream_v5_lite' => [
                'label' => 'Seedream 5.0 Lite (ByteDance)',
                'endpoint' => env('FAL_MODEL_SEEDREAM', 'fal-ai/bytedance/seedream/v5/lite/text-to-image'),
                'enabled' => true,
                'verified' => true,
                'source' => 'https://fal.ai/models/fal-ai/bytedance/seedream/v5/lite/text-to-image/api + https://fal.ai/learn/tools/nano-banana-vs-seedream (2026-10-04: $0.035 per image)',
                'pricing' => ['unit' => 'image', 'usd' => 0.035],
                'width' => 576,
                'height' => 1024,
                'supports_negative_prompt' => false,
                'supports_seed' => true,
                'params' => [
                    'image_size' => 'portrait_16_9',
                    'num_images' => 1,
                    'enable_safety_checker' => true,
                ],
            ],
        ],

        'video' => [

            // Production default since 2026-10-05 (David). Schema checked on
            // https://fal.ai/models/fal-ai/kling-video/v3/pro/image-to-video/api (2026-10-05):
            // start_image_url, prompt, duration (3–15), generate_audio, negative_prompt, cfg_scale.
            // There is NO resolution / aspect_ratio input: the clip follows the start image
            // (9:16 from Nano Banana Pro) at Kling Pro's native resolution — "720p" cannot be requested.
            // M5-T08: END FRAME `end_image_url` (string, optional, "URL of the image to be used for
            // the end of the video"; there is no `tail_image_url`) — checked on
            // https://fal.ai/docs/model-api-reference/video-generation-api/kling-video-v3-pro on 2026-10-21.
            // Used for seamless state loops (end = start key image) and transitions (end = target pose).
            'kling_v3_pro' => [
                'label' => 'Kling 3.0 Pro image-to-video (audio off)',
                'endpoint' => env('FAL_MODEL_KLING_V3_PRO', 'fal-ai/kling-video/v3/pro/image-to-video'),
                'enabled' => true,
                'verified' => true,
                'source' => 'https://fal.ai/models/fal-ai/kling-video/v3/pro/image-to-video (2026-10-04: $0.112/s audio off, $0.168/s audio on)',
                'pricing' => ['unit' => 'second', 'usd' => 0.112],
                'duration_seconds' => 5,
                // fal `duration` enum "3" … "15" (checked 2026-10-20 on the /api page). AI Lab override only.
                'durations' => range(3, 15),
                'image_param' => 'start_image_url',
                'end_image_param' => 'end_image_url',
                'supports_negative_prompt' => true,
                'params' => [
                    'duration' => '5',
                    'generate_audio' => false,
                    'cfg_scale' => 0.5,
                ],
            ],

            // AI Lab comparison candidate (2026-10-20), NOT a production profile.
            // Endpoint, schema and price checked on
            // https://fal.ai/models/fal-ai/kling-video/v3/standard/image-to-video (+ /api) on 2026-10-20:
            // start_image_url (required), prompt, duration "3"–"15" (default "5"), generate_audio
            // (default true → we send false), negative_prompt, cfg_scale (default 0.5); no aspect_ratio
            // (the clip follows the start image). $0.084/s audio off, $0.126/s audio on ($0.154/s voice control).
            'kling_v3_standard' => [
                'label' => 'Kling 3.0 Standard image-to-video (audio off)',
                'endpoint' => env('FAL_MODEL_KLING_V3_STANDARD', 'fal-ai/kling-video/v3/standard/image-to-video'),
                'enabled' => true,
                'verified' => true,
                'source' => 'https://fal.ai/models/fal-ai/kling-video/v3/standard/image-to-video (2026-10-20: $0.084/s audio off, $0.126/s audio on)',
                'pricing' => ['unit' => 'second', 'usd' => 0.084],
                'duration_seconds' => 5,
                'durations' => range(3, 15),
                'image_param' => 'start_image_url',
                'supports_negative_prompt' => true,
                'params' => [
                    'duration' => '5',
                    'generate_audio' => false,
                    'cfg_scale' => 0.5,
                ],
            ],

            'kling_v26_pro' => [
                'label' => 'Kling 2.6 Pro image-to-video (audio off)',
                'endpoint' => env('FAL_MODEL_KLING_V26_PRO', 'fal-ai/kling-video/v2.6/pro/image-to-video'),
                'enabled' => true,
                'verified' => true,
                'source' => 'https://fal.ai/models/fal-ai/kling-video/v2.6/pro/image-to-video (2026-10-04: $0.07/s audio off, $0.14/s audio on)',
                'pricing' => ['unit' => 'second', 'usd' => 0.07],
                'duration_seconds' => 5,
                // fal `duration` enum "5" | "10" (checked 2026-10-20 on the /api page).
                'durations' => [5, 10],
                'image_param' => 'start_image_url',
                'supports_negative_prompt' => true,
                'params' => [
                    'duration' => '5',
                    'generate_audio' => false,
                ],
            ],

            'veo31_fast' => [
                'label' => 'Veo 3.1 Fast image-to-video (720p, audio off)',
                'endpoint' => env('FAL_MODEL_VEO31_FAST', 'fal-ai/veo3.1/fast/image-to-video'),
                'enabled' => true,
                'verified' => true,
                'source' => 'https://fal.ai/models/fal-ai/veo3.1/fast/image-to-video (2026-10-04: $0.10/s without audio at 720p/1080p)',
                'pricing' => ['unit' => 'second', 'usd' => 0.10],
                'duration_seconds' => 4,
                // fal `duration` enum "4s" | "6s" | "8s" (checked 2026-10-20 on the /api page).
                'durations' => [4, 6, 8],
                'image_param' => 'image_url',
                'supports_negative_prompt' => true,
                'params' => [
                    'duration' => '4s',
                    'aspect_ratio' => '9:16',
                    'resolution' => '720p',
                    'generate_audio' => false,
                    'safety_tolerance' => '2',
                ],
            ],

            'veo31_lite' => [
                'label' => 'Veo 3.1 Lite image-to-video (720p, audio off)',
                'endpoint' => env('FAL_MODEL_VEO31_LITE', 'fal-ai/veo3.1/lite/image-to-video'),
                'enabled' => true,
                'verified' => true,
                'source' => 'https://fal.ai/models/fal-ai/veo3.1/lite/image-to-video (2026-10-04: $0.03/s 720p without audio, $0.05/s 1080p without audio)',
                'pricing' => ['unit' => 'second', 'usd' => 0.03],
                'duration_seconds' => 4,
                // fal `duration` enum "4s" | "6s" | "8s" (checked 2026-10-20 on the /api page).
                'durations' => [4, 6, 8],
                'image_param' => 'image_url',
                'supports_negative_prompt' => true,
                'params' => [
                    'duration' => '4s',
                    'aspect_ratio' => '9:16',
                    'resolution' => '720p',
                    'generate_audio' => false,
                    'safety_tolerance' => '2',
                ],
            ],
        ],
    ],
];
