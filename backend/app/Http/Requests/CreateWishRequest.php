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
            // M6-W03: the design the child picked (`designs[].key` of the catalogue row) — required
            // for an item with designs, absent for treats (WishService → 422 `invalid_design`).
            'design' => ['nullable', 'string', 'max:64'],
        ];
    }

    public function itemKey(): string
    {
        return (string) $this->validated('item_key');
    }

    public function design(): ?string
    {
        $design = $this->validated('design');

        return is_string($design) && $design !== '' ? $design : null;
    }
}
