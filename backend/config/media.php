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
| Production defaults stay on the pre-M4 models until David picks new ones
| (AI_REFERENCE_IMAGE_PROFILE / AI_STATE_VIDEO_PROFILE).
|
| NOTE: the price belongs to the PROFILE, not to the endpoint. Overriding an
| endpoint with FAL_MODEL_* does not change `pricing` — if you point a profile
| at a different model, update its price here too (or add a new profile).
| Negative prices are rejected.
|
*/

return [

    // Profile used for a new pet's reference image (GeneratePetReferenceImage).
    'reference_image_profile' => env('AI_REFERENCE_IMAGE_PROFILE', 'flux_schnell'),

    // Profile used for pet state videos (FalAiService::generatePetVideoState — not wired yet, M4-03).
    'state_video_profile' => env('AI_STATE_VIDEO_PROFILE', 'kling_v16_legacy'),

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
    ],

    'profiles' => [

        'image' => [

            // Pre-M4 default (FalAiService::MODEL_IMAGE). Request body unchanged.
            'flux_schnell' => [
                'label' => 'FLUX.1 [schnell] (current default, fast/cheap)',
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

            // Pre-M4 constant FalAiService::MODEL_VIDEO, kept byte-for-byte so the default does not
            // change. WARNING (2026-10-04): the id is malformed (fal's id is
            // fal-ai/kling-video/v1.6/pro/image-to-video) and fal marks Kling 1.6 as deprecated.
            // generatePetVideoState() is not called anywhere yet; pick a new profile before M4-03.
            'kling_v16_legacy' => [
                'label' => 'Kling 1.6 Pro (legacy id — deprecated on fal)',
                'endpoint' => env('FAL_MODEL_KLING_LEGACY', 'fal-ai/kling-v1.6/pro/image-to-video'),
                'enabled' => true,
                'lab' => false,
                'verified' => false,
                'source' => 'https://fal.ai/models/fal-ai/kling-video/v1.6/pro/image-to-video (2026-10-04: "deprecated", was $0.095/s)',
                'pricing' => ['unit' => 'second', 'usd' => 0.095],
                'duration_seconds' => 5,
                'image_param' => 'image_url',
                'supports_negative_prompt' => false,
                'params' => [
                    'duration' => '5',
                    'aspect_ratio' => '9:16',
                    'cfg_scale' => 0.7,
                ],
            ],

            'kling_v3_pro' => [
                'label' => 'Kling 3.0 Pro image-to-video (audio off)',
                'endpoint' => env('FAL_MODEL_KLING_V3_PRO', 'fal-ai/kling-video/v3/pro/image-to-video'),
                'enabled' => true,
                'verified' => true,
                'source' => 'https://fal.ai/models/fal-ai/kling-video/v3/pro/image-to-video (2026-10-04: $0.112/s audio off, $0.168/s audio on)',
                'pricing' => ['unit' => 'second', 'usd' => 0.112],
                'duration_seconds' => 5,
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
