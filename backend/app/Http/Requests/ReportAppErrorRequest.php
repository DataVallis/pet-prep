<?php

namespace App\Http\Requests;

use App\Enums\AppErrorScope;
use App\Enums\DevicePlatform;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/app-errors (M5-05): one crash / render-error report from the
 * mobile app. With or without a token. Strict lengths and character sets on
 * every field — free text is limited to the error name, message and stack,
 * which the server scrubs again before storing. No props, state, names,
 * e-mails or ids are accepted (unknown keys are ignored).
 */
class ReportAppErrorRequest extends FormRequest
{
    private const VERSION = '/^[0-9A-Za-z.\-+() ]+$/';

    private const TOKEN = '/^[0-9A-Za-z._\-]+$/';

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
            'scope' => ['required', 'string', Rule::enum(AppErrorScope::class)],
            'name' => ['required', 'string', 'max:100'],
            'message' => ['present', 'nullable', 'string', 'max:500'],
            'stack' => ['nullable', 'string', 'max:2048'],
            'fatal' => ['nullable', 'boolean'],
            // e.g. "3.1.3"; same rule as the push device app version.
            'app_version' => ['required', 'string', 'max:32', 'regex:'.self::VERSION],
            // expo-updates runtimeVersion (fingerprint hash) / updateId (uuid) / git sha.
            'runtime_version' => ['nullable', 'string', 'max:64', 'regex:'.self::TOKEN],
            'update_id' => ['nullable', 'string', 'max:64', 'regex:'.self::TOKEN],
            'build' => ['nullable', 'string', 'max:40', 'regex:'.self::TOKEN],
            'platform' => ['required', 'string', Rule::enum(DevicePlatform::class)],
            'os_version' => ['nullable', 'string', 'max:32', 'regex:'.self::VERSION],
            // A screen / route NAME (e.g. "child-hud"), never a param or path.
            'route' => ['nullable', 'string', 'max:64', 'regex:'.self::TOKEN],
            'occurred_at' => ['required', 'date', 'after:-31 days', 'before:+1 day'],
        ];
    }

    /**
     * @return array{scope: string, name: string, message: string, stack?: string|null, app_version: string, runtime_version?: string|null, update_id?: string|null, build?: string|null, platform: string, os_version?: string|null, route?: string|null, occurred_at: string, fatal?: bool|null}
     */
    public function report(): array
    {
        /** @var array{scope: string, name: string, message: string|null, stack?: string|null, app_version: string, runtime_version?: string|null, update_id?: string|null, build?: string|null, platform: string, os_version?: string|null, route?: string|null, occurred_at: string, fatal?: bool|null} $data */
        $data = $this->validated();
        $data['message'] = (string) ($data['message'] ?? '');

        return $data;
    }
}
