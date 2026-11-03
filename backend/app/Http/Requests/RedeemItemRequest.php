<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/parent/pets/{pet}/items/redeem (M6-W02): `product_id` (a wish store
 * product; unknown → 422 `unknown_product` from WishCreditService), optional
 * `wish_id`. Optional header `Idempotency-Key` (the store transaction id).
 * Parents only; the pet's family is checked in the controller (404).
 */
class RedeemItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewBilling', User::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'product_id' => ['required', 'string', 'max:255'],
            'wish_id' => ['nullable', 'integer', 'min:1'],
            // M6-W03: the design of a purchase without a wish (a wish carries its own); missing →
            // the item's first design; unknown → 422 `invalid_design`.
            'design' => ['nullable', 'string', 'max:64'],
            // M6-W03: the welcome bundle — a design per item (each optional, default = first design).
            // (not `array:bed,…` — Scramble would type every key as required; other keys are ignored)
            'designs' => ['nullable', 'array'],
            'designs.bed' => ['nullable', 'string', 'max:64'],
            'designs.bowls' => ['nullable', 'string', 'max:64'],
            'designs.toy_1' => ['nullable', 'string', 'max:64'],
        ];
    }

    public function design(): ?string
    {
        $design = $this->validated('design');

        return is_string($design) && $design !== '' ? $design : null;
    }

    /** @return array<string, string|null> */
    public function designs(): array
    {
        $designs = $this->validated('designs');

        return is_array($designs) ? array_map(fn ($d) => is_string($d) && $d !== '' ? $d : null, $designs) : [];
    }

    public function productId(): string
    {
        return (string) $this->validated('product_id');
    }

    public function wishId(): ?int
    {
        $id = $this->validated('wish_id');

        return $id === null ? null : (int) $id;
    }

    /** The Idempotency-Key header (trimmed, ≤ 255 characters), or null. */
    public function idempotencyKey(): ?string
    {
        $key = trim((string) $this->header('Idempotency-Key', ''));

        return $key === '' ? null : mb_substr($key, 0, 255);
    }
}
