<?php

namespace App\Http\Requests;

use App\Enums\TrainingCommand;
use App\Models\Pet;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/child/pet/training/start (M5-R03): the command to train.
 * Optional `rules` (GAMES_SPEC §7.2): 2 = the v2 school (2 rounds × 3 cues,
 * the mask instead of the release clip, early end after round 1 — a new
 * app); missing / 1 = the v1 schedule, unchanged for old apps.
 * Only children (PetPolicy).
 */
class StartTrainingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('useChildApi', Pet::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            /** sit | come | place | potty */
            'command' => ['required', 'string', Rule::enum(TrainingCommand::class)],
            // GAMES_SPEC §7.2: the school rules the app implements (1 = v1, 2 = rounds + mask).
            'rules' => ['sometimes', 'integer', 'min:1', 'max:2'],
        ];
    }

    /** The rules version asked for (1 without the field). */
    public function rulesVersion(): int
    {
        return (int) ($this->validated('rules') ?? 1);
    }

    public function command(): TrainingCommand
    {
        return TrainingCommand::from((string) $this->validated('command'));
    }
}
