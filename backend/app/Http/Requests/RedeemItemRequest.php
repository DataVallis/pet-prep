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
        ];
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
