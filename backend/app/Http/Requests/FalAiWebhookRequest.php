<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FalAiWebhookRequest extends FormRequest
{
    /**
     * Webhook endpoints are called by fal.ai servers, not by authenticated users.
     * Authorization is handled via webhook secret validation in the controller.
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
            'request_id' => ['required', 'string'],
            'status' => ['required', 'string'],
            'pet_id' => ['nullable', 'integer', 'exists:pets,id'],
        ];
    }
}
