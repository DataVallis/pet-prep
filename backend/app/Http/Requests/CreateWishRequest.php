<?php

namespace App\Http\Requests;

use App\Models\Pet;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/child/wishes (M6-W01): `item_key` — a catalogue key (unknown keys
 * → 422 `unknown_item` from WishService, not a validation error). Children only.
 */
class CreateWishRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('useChildApi', Pet::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'item_key' => ['required', 'string', 'max:32'],
        ];
    }

    public function itemKey(): string
    {
        return (string) $this->validated('item_key');
    }
}
