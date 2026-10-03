<?php

namespace App\Http\Requests;

use App\Services\FalWebhookVerifier;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class FalAiWebhookRequest extends FormRequest
{
    /**
     * Called by fal.ai servers, not by users. Authenticity is the ED25519 signature,
     * checked here so that it runs BEFORE validation: unauthenticated callers get a
     * bare 401 and learn nothing about the payload schema.
     */
    public function authorize(FalWebhookVerifier $verifier): bool
    {
        return $verifier->verify($this);
    }

    protected function failedAuthorization(): void
    {
        throw new HttpResponseException(response()->json(['message' => 'Unauthorized'], 401));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'request_id' => ['required', 'string', 'max:255'],
            'status' => ['required', 'string', 'in:OK,ERROR'],
            'payload' => ['nullable', 'array'],
            'error' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
