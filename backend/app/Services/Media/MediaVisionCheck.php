<?php

namespace App\Services\Media;

use App\Enums\AiCallFailure;
use App\Enums\AiSpendPurpose;
use App\Enums\Species;
use App\Models\PetMedia;
use App\Models\PetMediaQualityCheck;
use App\Services\FalAiService;
use Throwable;

/**
 * The AI vision part of the clip quality check (David 2026-10-23): ~6 frames of the clip
 * (one contact sheet), the animal's reference image and — for a clip with an end frame —
 * the end image go to a vision LLM through FalGateway (named profile
 * `media.quality_check.vision_profile`, budget = production, purpose `quality_check`).
 * Frames are of the AI pet only — never a child, a name or any other personal data.
 *
 * Fixed rubric, JSON answer: {anatomy_ok, same_animal, action_done, artifacts, verdict, reason}.
 * Fail = verdict fail OR any of anatomy_ok / same_animal / action_done false OR artifacts true.
 * Budget out / profile off / an unusable answer → status skipped_budget / disabled / error
 * (the technical result decides; the clip is flagged »not AI-checked«).
 *
 * Still images (David 2026-11-04, StillQualityService): judgeStill() — ONE generated picture
 * (key / pose / item key image, catalogue picture) against a fixed rubric: exactly one animal of
 * the species, four legs / paws visible or plausibly hidden, no extra / detached / ghost /
 * semi-transparent / duplicate body parts (leftovers of the reference pose), the scene (item) as
 * described, no text / watermark, no people / hands. Empty-room and object-only rubrics for the
 * empty pose and catalogue pictures.
 *
 * Items (David 2026-11-19, a pasted, floating bed under a Dobermann lying on top of it): the item key
 * image rubric adds grounded / integrated / scale, and judgeItemClip() checks an item clip (contact
 * sheet + its start frame) with the same realism questions.
 */
class MediaVisionCheck
{
    public const SYSTEM_PROMPT = 'You review short AI-generated video clips of a pet for a children\'s app. '
        .'Be strict about anatomy and realism. Answer with ONE JSON object only, no markdown, no other text.';

    public function __construct(
        private readonly FalGateway $gateway,
        private readonly MediaProfiles $profiles,
        private readonly AiSpendGuard $guard,
        private readonly FalAiService $fal,
    ) {}

    /** Estimated cost of one vision call (0 when no profile is enabled). */
    public function costUsd(): float
    {
        return $this->profiles->vision()?->estimatedCostUsd() ?? 0.0;
    }

    /** Would a vision call run now? null = yes, else the ai_status it gets instead. */
    public function unavailableStatus(): ?string
    {
        $profile = $this->profiles->vision();

        if ($profile === null || ! $this->fal->isEnabled()) {
            return PetMediaQualityCheck::AI_DISABLED;
        }

        if (FalGateway::balanceExhaustedAt() !== null || $this->guard->refusalFor($profile->estimatedCostUsd(), AiSpendPurpose::QualityCheck) !== null) {
            return PetMediaQualityCheck::AI_SKIPPED_BUDGET;
        }

        return null;
    }

    /**
     * @param  list<string>  $imageUrls  contact sheet, reference image, (end image) — URLs fal can fetch
     * @return array{status: string, verdict: array<string, mixed>|null, reason: string|null, cost_usd: float, reported_cost_usd: float|null}
     */
    public function judge(PetMedia $clip, Species $species, string $intent, array $imageUrls, bool $hasEndImage): array
    {
        $none = ['verdict' => null, 'cost_usd' => 0.0, 'reported_cost_usd' => null];

        if (($status = $this->unavailableStatus()) !== null) {
            return ['status' => $status, 'reason' => null] + $none;
        }

        $profile = $this->profiles->vision();
        if ($profile === null) {
            return ['status' => PetMediaQualityCheck::AI_DISABLED, 'reason' => null] + $none;
        }

        $input = [
            'prompt' => $this->prompt($species, $intent, $hasEndImage),
            'system_prompt' => self::SYSTEM_PROMPT,
            'image_urls' => array_values($imageUrls),
        ] + $profile->params;

        try {
            $call = $this->gateway->run($profile, $input, AiSpendPurpose::QualityCheck, petId: $clip->pet_id, timeoutSeconds: (int) config('media.quality_check.vision_timeout_seconds', 30), petMediaId: $clip->id);
        } catch (AiCallException $e) {
            $budget = in_array($e->reason, [AiCallFailure::BudgetDaily, AiCallFailure::BudgetMonthly, AiCallFailure::FalBalance], true);

            return [
                'status' => $budget ? PetMediaQualityCheck::AI_SKIPPED_BUDGET : ($e->reason === AiCallFailure::Disabled ? PetMediaQualityCheck::AI_DISABLED : PetMediaQualityCheck::AI_ERROR),
                'reason' => mb_substr($e->getMessage() ?: $e->reason->label(), 0, 500),
                'verdict' => null,
                'cost_usd' => $e->chargedUsd,
                'reported_cost_usd' => null,
            ];
        }

        $body = $call['body'];
        $reported = is_numeric($body['usage']['cost'] ?? null) ? (float) $body['usage']['cost'] : null;
        $verdict = self::parse(is_string($body['output'] ?? null) ? $body['output'] : '');

        if ($verdict === null) {
            return ['status' => PetMediaQualityCheck::AI_ERROR, 'reason' => 'Vision answer was not the rubric JSON', 'verdict' => ['raw' => mb_substr((string) ($body['output'] ?? ''), 0, 500)], 'cost_usd' => $call['cost_usd'], 'reported_cost_usd' => $reported];
        }

        $failed = self::fails($verdict);

        return [
            'status' => $failed ? PetMediaQualityCheck::AI_FAILED : PetMediaQualityCheck::AI_PASSED,
            'reason' => $failed ? 'AI check: '.mb_substr((string) ($verdict['reason'] ?? 'failed the rubric'), 0, 500) : null,
            'verdict' => $verdict,
            'cost_usd' => $call['cost_usd'],
            'reported_cost_usd' => $reported,
        ];
    }

    public const STILL_SYSTEM_PROMPT = 'You review AI-generated still photographs for a children\'s pet app. '
        .'Be strict about anatomy and about leftover or ghost body parts. Answer with ONE JSON object only, no markdown, no other text.';

    /** Rubric of a picture WITH the animal (key / pose / item key image). */
    public const RUBRIC_ANIMAL = 'animal';

    /** Rubric of the empty room (pose `empty`): no animal at all. */
    public const RUBRIC_EMPTY = 'empty';

    /** Rubric of a catalogue picture: the object alone. */
    public const RUBRIC_OBJECT = 'object';

    /**
     * Rubric of an ITEM key image (David 2026-11-19: a pasted, floating, studio-lit bed under a
     * Dobermann lying on top of it passed the animal rubric): the animal questions + the item sits
     * naturally in the room (grounded, same light / focus / perspective) at a plausible scale.
     */
    public const RUBRIC_ITEM = 'item';

    /** Rubric of an ITEM clip (contact sheet + its start frame) — judgeItemClip(). */
    public const RUBRIC_ITEM_CLIP = 'item_clip';

    /** The boolean keys of each still rubric (all must be true to pass). */
    public const STILL_KEYS = [
        self::RUBRIC_ANIMAL => ['one_animal', 'legs_ok', 'no_ghost_parts', 'scene_ok', 'clean'],
        self::RUBRIC_EMPTY => ['no_animal', 'scene_ok', 'clean'],
        self::RUBRIC_OBJECT => ['object_ok', 'no_animal', 'clean'],
        self::RUBRIC_ITEM => ['one_animal', 'legs_ok', 'no_ghost_parts', 'scene_ok', 'clean', 'item_grounded', 'item_integrated', 'scale_ok'],
        self::RUBRIC_ITEM_CLIP => ['anatomy_ok', 'same_animal', 'action_done', 'item_grounded', 'item_integrated', 'scale_ok', 'no_artifacts'],
    ];

    /** The keys of the item rubrics that judge the OBJECT in the scene (a fail of these in a clip = its start frame is wrong). */
    public const ITEM_PLACEMENT_KEYS = ['item_grounded', 'item_integrated', 'scale_ok'];

    /** The item realism questions shared by the key image and the clip rubric (`$animal` = dog | cat). */
    private static function itemQuestions(string $animal): string
    {
        return "- item_grounded: the object rests on the floor like a real object — its whole bottom edge / base touches the floor, with a soft contact shadow under the WHOLE base (darkest where it meets the floor), no gap under it, not floating or hovering; false if the shadow is only at one point or one side, if the object touches the floor at a single point, or if there is a separate, offset or detached shadow blob beside or under it.\n"
            ."- item_integrated: the object looks photographed in this room together with the {$animal}, not pasted in: the same light direction, softness, colour temperature and brightness as the room, the same focus, depth of field, sharpness and grain as the floor around it, the same perspective as the floor; false if the object is noticeably sharper or crisper than the floor it stands on, has a bright outline or halo, cut-out edges, or a studio-lit, razor-sharp product look in a softer room.\n"
            ."- scale_ok: the object's size is plausible next to the {$animal}; a bed is big enough that the {$animal} lies INSIDE it (on its cushion, within its rim / sides) — not on top of a too-small bed with its body overhanging; bowls and toys have a natural size for this {$animal}.\n";
    }

    /**
     * David 2026-11-04: the vision check of ONE still image (fal fetches `$imageUrl`). Same budget,
     * ledger and statuses as judge(); the ledger links the pet_media or item_media row.
     *
     * @return array{status: string, verdict: array<string, mixed>|null, reason: string|null, cost_usd: float, reported_cost_usd: float|null}
     */
    public function judgeStill(string $rubric, Species $species, string $scene, string $imageUrl, ?int $petId = null, ?int $petMediaId = null, ?int $itemMediaId = null): array
    {
        return $this->runRubric($rubric, $this->stillPrompt($rubric, $species, $scene), self::STILL_SYSTEM_PROMPT, [$imageUrl], $petId, $petMediaId, $itemMediaId);
    }

    public const ITEM_CLIP_SYSTEM_PROMPT = 'You review short AI-generated video clips of a pet with an object for a children\'s app. '
        .'Be strict about anatomy and about whether the object looks real in the room. Answer with ONE JSON object only, no markdown, no other text.';

    /**
     * David 2026-11-19: the AI check of an ITEM clip — image 1 = a contact sheet of its frames, image
     * 2 = its start frame (the item key image, full size: compositing shows best there). Rubric
     * RUBRIC_ITEM_CLIP (every key true + verdict pass). Same budget, ledger and statuses as judgeStill().
     *
     * @return array{status: string, verdict: array<string, mixed>|null, reason: string|null, cost_usd: float, reported_cost_usd: float|null}
     */
    public function judgeItemClip(Species $species, string $intent, string $scene, string $sheetUrl, string $startFrameUrl, ?int $petId, int $itemMediaId): array
    {
        return $this->runRubric(self::RUBRIC_ITEM_CLIP, $this->itemClipPrompt($species, $intent, $scene), self::ITEM_CLIP_SYSTEM_PROMPT, [$sheetUrl, $startFrameUrl], $petId, null, $itemMediaId);
    }

    /** The item clip rubric (fixed; only the species, the motion and the scene change). */
    public function itemClipPrompt(Species $species, string $intent, string $scene): string
    {
        $animal = $species === Species::Cat ? 'cat' : 'dog';
        $json = '{'.implode(', ', array_map(fn (string $k) => "\"{$k}\": true|false", self::STILL_KEYS[self::RUBRIC_ITEM_CLIP])).', "verdict": "pass"|"fail", "reason": "<one short sentence>"}';

        return 'Image 1 is a contact sheet: frames of one short clip in time order (left to right, then the next row). '
            ."Image 2 is the clip's first frame at full size. The clip should show the {$animal} {$scene}, {$intent}.\n"
            ."Check (in every frame and in image 2):\n"
            ."- anatomy_ok: the {$animal} has a normal body: four normal legs, one head, one tail, no melting, merging, stretching or morphing body parts, no legs that dissolve or appear.\n"
            ."- same_animal: it is the same {$animal} in every frame (breed, colour, markings).\n"
            ."- action_done: the clip really shows that, with natural movement; when the {$animal} should stay asleep and still, only gentle breathing is right — false if it wakes up, opens its eyes, lifts its head, gets up or wags its tail.\n"
            .self::itemQuestions($animal)
            ."- no_artifacts: no visual glitches (flicker, a second animal, people, hands, text, sudden cuts, objects appearing, changing or changing size).\n"
            .'Be strict: if the object looks pasted in, floats, has a detached shadow, or is too small for the '.$animal.', that key is false. Answer only: '.$json;
    }

    /**
     * One paid vision call with a fixed boolean rubric (STILL_KEYS[$rubric]).
     *
     * @param  list<string>  $imageUrls
     * @return array{status: string, verdict: array<string, mixed>|null, reason: string|null, cost_usd: float, reported_cost_usd: float|null}
     */
    private function runRubric(string $rubric, string $prompt, string $system, array $imageUrls, ?int $petId, ?int $petMediaId, ?int $itemMediaId): array
    {
        $none = ['verdict' => null, 'cost_usd' => 0.0, 'reported_cost_usd' => null];

        if (($status = $this->unavailableStatus()) !== null) {
            return ['status' => $status, 'reason' => null] + $none;
        }

        $profile = $this->profiles->vision();
        if ($profile === null) {
            return ['status' => PetMediaQualityCheck::AI_DISABLED, 'reason' => null] + $none;
        }

        $input = [
            'prompt' => $prompt,
            'system_prompt' => $system,
            'image_urls' => $imageUrls,
        ] + $profile->params;

        try {
            $call = $this->gateway->run($profile, $input, AiSpendPurpose::QualityCheck, petId: $petId, timeoutSeconds: (int) config('media.quality_check.vision_timeout_seconds', 30), petMediaId: $petMediaId, itemMediaId: $itemMediaId);
        } catch (AiCallException $e) {
            $budget = in_array($e->reason, [AiCallFailure::BudgetDaily, AiCallFailure::BudgetMonthly, AiCallFailure::FalBalance], true);

            return [
                'status' => $budget ? PetMediaQualityCheck::AI_SKIPPED_BUDGET : ($e->reason === AiCallFailure::Disabled ? PetMediaQualityCheck::AI_DISABLED : PetMediaQualityCheck::AI_ERROR),
                'reason' => mb_substr($e->getMessage() ?: $e->reason->label(), 0, 500),
                'verdict' => null,
                'cost_usd' => $e->chargedUsd,
                'reported_cost_usd' => null,
            ];
        }

        $body = $call['body'];
        $reported = is_numeric($body['usage']['cost'] ?? null) ? (float) $body['usage']['cost'] : null;
        $verdict = self::parseStill($rubric, is_string($body['output'] ?? null) ? $body['output'] : '');

        if ($verdict === null) {
            return ['status' => PetMediaQualityCheck::AI_ERROR, 'reason' => 'Vision answer was not the rubric JSON', 'verdict' => ['raw' => mb_substr((string) ($body['output'] ?? ''), 0, 500)], 'cost_usd' => $call['cost_usd'], 'reported_cost_usd' => $reported];
        }

        $failed = self::stillFails($rubric, $verdict);

        return [
            'status' => $failed ? PetMediaQualityCheck::AI_FAILED : PetMediaQualityCheck::AI_PASSED,
            'reason' => $failed ? 'AI check: '.mb_substr((string) ($verdict['reason'] ?? 'failed the rubric'), 0, 500) : null,
            'verdict' => $verdict,
            'cost_usd' => $call['cost_usd'],
            'reported_cost_usd' => $reported,
        ];
    }

    /** The still rubric (fixed; only the species and the wanted scene change). */
    public function stillPrompt(string $rubric, Species $species, string $scene): string
    {
        $animal = $species === Species::Cat ? 'cat' : 'dog';
        $json = '{'.implode(', ', array_map(fn (string $k) => "\"{$k}\": true|false", self::STILL_KEYS[$rubric])).', "verdict": "pass"|"fail", "reason": "<one short sentence>"}';

        return match ($rubric) {
            self::RUBRIC_OBJECT => "The image should be a product photograph of this object alone: {$scene}.\n"
                ."Check:\n"
                ."- object_ok: exactly this object is shown, whole and recognisable, matching the description.\n"
                ."- no_animal: there is no animal and no animal body part anywhere.\n"
                ."- clean: no text, letters, price tag, watermark or logo, and no people or hands.\n"
                .'Answer only: '.$json,
            self::RUBRIC_EMPTY => "The image should show an empty room: {$scene}.\n"
                ."Check:\n"
                ."- no_animal: there is no animal, no part of an animal and nothing ghost-like or semi-transparent (no faded paws, legs, tail or body outline) anywhere.\n"
                ."- scene_ok: it is a realistic photograph of an empty room with a clear floor.\n"
                ."- clean: no text, letters, watermark or logo, and no people or hands.\n"
                .'Answer only: '.$json,
            self::RUBRIC_ITEM => "The image is an AI edit of a photograph of a {$animal} in a room. It should show the {$animal} {$scene}.\n"
                ."Check:\n"
                ."- one_animal: exactly one {$animal} is in the picture — no second animal and no duplicate or partial copy of it.\n"
                ."- legs_ok: the {$animal} has exactly four legs and four paws, each either visible or plausibly hidden by its own body or by the object; one head and one tail; no extra, missing, detached or floating legs or paws (count every paw you can see, also under or beside the object).\n"
                ."- no_ghost_parts: nothing semi-transparent, ghost-like, faded or duplicated anywhere: no leftover paws, legs, tail, head or body outline on the floor or elsewhere.\n"
                ."- scene_ok: the picture shows what is described above (the named object is there, and the {$animal} is in, on or with it as described).\n"
                ."- clean: no text, letters, watermark or logo, and no people, children or hands.\n"
                .self::itemQuestions($animal)
                .'Be strict: if the object looks pasted in, floats, has a detached shadow, or is too small for the '.$animal.', that key is false. Answer only: '.$json,
            default => "The image is an AI edit of a photograph of a {$animal}. It should show the {$animal} {$scene}.\n"
                ."Check:\n"
                ."- one_animal: exactly one {$animal} is in the picture — no second animal and no duplicate or partial copy of it.\n"
                ."- legs_ok: the {$animal} has exactly four legs and four paws, each either visible or plausibly hidden by its own body or by an object; one head and one tail; no extra, missing, detached or floating legs or paws (count every paw you can see, also under or beside an object).\n"
                ."- no_ghost_parts: nothing semi-transparent, ghost-like, faded or duplicated anywhere: no leftover paws, legs, tail, head or body outline on the floor or elsewhere (for example remains of the {$animal}'s earlier pose).\n"
                ."- scene_ok: the picture shows what is described above (an object that is named is there, and the {$animal} is on, in or with it as described).\n"
                ."- clean: no text, letters, watermark or logo, and no people, children or hands.\n"
                .'Answer only: '.$json,
        };
    }

    /**
     * PURE: a still rubric's JSON from the model's text (tolerates a ```json fence), or null.
     *
     * @return array<string, mixed>|null
     */
    public static function parseStill(string $rubric, string $output): ?array
    {
        $keys = self::STILL_KEYS[$rubric] ?? null;
        $text = trim($output);
        if ($keys === null) {
            return null;
        }
        if (preg_match('/\{.*\}/s', $text, $m) === 1) {
            $text = $m[0];
        }

        try {
            $json = json_decode($text, true, 8, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        if (! is_array($json) || ! in_array($json['verdict'] ?? null, ['pass', 'fail'], true)) {
            return null;
        }

        $out = [];
        foreach ($keys as $key) {
            if (! is_bool($json[$key] ?? null)) {
                return null;
            }
            $out[$key] = $json[$key];
        }

        return $out + [
            'verdict' => $json['verdict'],
            'reason' => is_string($json['reason'] ?? null) ? mb_substr($json['reason'], 0, 300) : null,
        ];
    }

    /** @param  array<string, mixed>  $verdict */
    public static function stillFails(string $rubric, array $verdict): bool
    {
        if (($verdict['verdict'] ?? 'fail') === 'fail') {
            return true;
        }

        foreach (self::STILL_KEYS[$rubric] ?? [] as $key) {
            if (($verdict[$key] ?? false) !== true) {
                return true;
            }
        }

        return false;
    }

    /** The rubric (fixed; only the species and the clip's intended action change). */
    public function prompt(Species $species, string $intent, bool $hasEndImage): string
    {
        $animal = $species === Species::Cat ? 'cat' : 'dog';

        return 'Image 1 is a contact sheet: frames of one short clip in time order (left to right, then the next row). '
            ."Image 2 is the reference photo of the {$animal}."
            .($hasEndImage ? ' Image 3 is the pose the clip must END in.' : '')
            ." The clip should show the same {$animal} doing this: {$intent}.\n"
            ."Check:\n"
            ."- anatomy_ok: in EVERY frame the {$animal} has a normal body: four normal legs, one head, one tail, no melting, merging, stretching or morphing body parts, no legs that dissolve or appear.\n"
            ."- same_animal: it is the same {$animal} as in the reference photo (breed, colour, markings) in every frame.\n"
            .'- action_done: the clip really shows the action with natural movement'.($hasEndImage ? ' and ends in the pose of image 3 by moving into it, not by morphing' : '')
            ."; when the {$animal} should stay asleep and still, only gentle breathing is right — false if it wakes up, opens its eyes, lifts its head, gets up or wags its tail.\n"
            ."- artifacts: true if there are visual glitches (flicker, a second animal, people, hands, text, sudden cuts, objects appearing).\n"
            .'Answer only: {"anatomy_ok": true|false, "same_animal": true|false, "action_done": true|false, "artifacts": true|false, "verdict": "pass"|"fail", "reason": "<one short sentence>"}';
    }

    /**
     * PURE: the rubric JSON from the model's text (tolerates a ```json fence), or null.
     *
     * @return array<string, mixed>|null
     */
    public static function parse(string $output): ?array
    {
        $text = trim($output);
        if (preg_match('/\{.*\}/s', $text, $m) === 1) {
            $text = $m[0];
        }

        try {
            $json = json_decode($text, true, 8, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        if (! is_array($json) || ! in_array($json['verdict'] ?? null, ['pass', 'fail'], true)) {
            return null;
        }

        foreach (['anatomy_ok', 'same_animal', 'action_done', 'artifacts'] as $key) {
            if (! is_bool($json[$key] ?? null)) {
                return null;
            }
        }

        return [
            'anatomy_ok' => $json['anatomy_ok'],
            'same_animal' => $json['same_animal'],
            'action_done' => $json['action_done'],
            'artifacts' => $json['artifacts'],
            'verdict' => $json['verdict'],
            'reason' => is_string($json['reason'] ?? null) ? mb_substr($json['reason'], 0, 300) : null,
        ];
    }

    /** @param  array<string, mixed>  $verdict */
    public static function fails(array $verdict): bool
    {
        return $verdict['verdict'] === 'fail' || ! $verdict['anatomy_ok'] || ! $verdict['same_animal'] || ! $verdict['action_done'] || $verdict['artifacts'];
    }
}
