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
            .'- action_done: the clip really shows the action with natural movement'.($hasEndImage ? ' and ends in the pose of image 3 by moving into it, not by morphing' : '').".\n"
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
