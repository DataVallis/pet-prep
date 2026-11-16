<?php

namespace App\Http\Requests;

use App\Models\BreedQuizStat;
use App\Support\WebsiteLocale;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * POST /api/breed-quiz/events (M6-S06, BREED_QUIZ_SPEC §8). Public, anonymous.
 */
class StoreBreedQuizEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Honeypot: a filled hidden `website` field = bot → 204 and nothing stored,
     * before validation (a bot learns nothing from a 422).
     */
    protected function prepareForValidation(): void
    {
        $honeypot = $this->input('website');
        if (is_string($honeypot) ? trim($honeypot) !== '' : ! empty($honeypot)) {
            throw new HttpResponseException(response()->noContent());
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $completed = $this->input('event') === BreedQuizStat::EVENT_COMPLETED;

        return [
            'event' => ['required', 'string', Rule::in(BreedQuizStat::EVENTS)],
            'locale' => ['required', 'string', Rule::in(WebsiteLocale::ALL)],
            // completed: the result's top 3 in rank order (1–3); clicks: the one card clicked.
            'breeds' => ['required', 'array', $completed ? 'min:1' : 'size:1', $completed ? 'max:3' : 'max:1'],
            // Unknown ids are fine (no DB lookup) but the format is checked.
            'breeds.*' => ['required', 'string', 'regex:/^[a-z0-9_]{1,64}$/', 'distinct'],
            'website' => ['nullable'],
        ];
    }
}
