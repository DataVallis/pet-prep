<?php

namespace App\Http\Requests;

use App\Models\Pet;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/child/pet/scratching/finish (M5-R06-05, CAT_SPEC Q10): the
 * session id from start and when the child praised the cat — whole
 * milliseconds since the session start (`praise_ms`; null = no praise).
 * ScratchingService judges it against the landing + the 3 s window.
 */
class FinishScratchingRequest extends FormRequest
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
            'session_id' => ['required', 'string', 'uuid'],
            'praise_ms' => ['present', 'nullable', 'integer', 'min:0', 'max:600000'],
        ];
    }

    public function sessionId(): string
    {
        return strtolower((string) $this->validated('session_id'));
    }

    public function praiseMs(): ?int
    {
        $value = $this->validated('praise_ms');

        return $value === null ? null : (int) $value;
    }
}
