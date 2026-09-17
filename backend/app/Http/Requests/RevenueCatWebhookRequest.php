<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RevenueCatWebhookRequest extends FormRequest
{
    /**
     * Webhook endpoints are called by RevenueCat servers, not by authenticated users.
     * Authorization is handled via Authorization header secret validation in the controller.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'event' => ['required', 'array'],
            'event.type' => ['required', 'string'],
            'event.app_user_id' => ['required', 'string'],
            'event.subscriber_id' => ['nullable', 'string'],
            'event.original_app_user_id' => ['nullable', 'string'],
            'event.product_id' => ['nullable', 'string'],
            'event.store' => ['nullable', 'string'],
        ];
    }

    /**
     * Get custom validation messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'event.required' => 'The event payload is required.',
            'event.array' => 'The event must be an object.',
            'event.type.required' => 'The event type is required.',
            'event.app_user_id.required' => 'The app_user_id is required.',
        ];
    }
}
