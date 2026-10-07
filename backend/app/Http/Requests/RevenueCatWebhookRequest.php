<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * RevenueCat webhook body (M3-08). Authentication is the
 * VerifyRevenueCatWebhook middleware (runs first, fails closed).
 *
 * Only the fields we read are validated; everything else RevenueCat sends
 * is kept in the stored payload. `app_user_id` is optional: TRANSFER events
 * carry `transferred_from` / `transferred_to` instead.
 */
class RevenueCatWebhookRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'event' => ['required', 'array'],
            'event.id' => ['required', 'string', 'max:128'],
            'event.type' => ['required', 'string', 'max:64'],
            'event.app_user_id' => ['nullable', 'string', 'max:255'],
            'event.original_app_user_id' => ['nullable', 'string', 'max:255'],
            'event.aliases' => ['nullable', 'array', 'max:100'],
            'event.aliases.*' => ['nullable', 'string', 'max:255'],
            'event.product_id' => ['nullable', 'string', 'max:255'],
            'event.new_product_id' => ['nullable', 'string', 'max:255'],
            'event.entitlement_ids' => ['nullable', 'array', 'max:50'],
            'event.entitlement_ids.*' => ['string', 'max:64'],
            'event.entitlement_id' => ['nullable', 'string', 'max:64'],
            'event.store' => ['nullable', 'string', 'max:32'],
            'event.environment' => ['nullable', 'string', 'max:16'],
            'event.transaction_id' => ['nullable', 'string', 'max:255'],
            'event.original_transaction_id' => ['nullable', 'string', 'max:255'],
            'event.purchased_at_ms' => ['nullable', 'integer', 'min:0'],
            'event.expiration_at_ms' => ['nullable', 'integer', 'min:0'],
            'event.event_timestamp_ms' => ['nullable', 'integer', 'min:0'],
            'event.cancel_reason' => ['nullable', 'string', 'max:64'],
            'event.transferred_from' => ['nullable', 'array', 'max:100'],
            'event.transferred_from.*' => ['nullable', 'string', 'max:255'],
            'event.transferred_to' => ['nullable', 'array', 'max:100'],
            'event.transferred_to.*' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * The whole body as sent (stored as the event's raw payload).
     *
     * @return array<string, mixed>
     */
    public function body(): array
    {
        return $this->all();
    }
}
