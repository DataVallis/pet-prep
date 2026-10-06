<?php

namespace App\Http\Requests;

use App\Enums\TrainingCommand;
use App\Models\Pet;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/child/pet/training/start (M5-R03): the command to train.
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
        ];
    }

    public function command(): TrainingCommand
    {
        return TrainingCommand::from((string) $this->validated('command'));
    }
}
