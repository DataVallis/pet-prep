<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/parent/children/{child}/walks (M5-T12, David 2026-10-21): a parent logs
 * walk minutes for a child in adapted mode. Body `{minutes: 1–240, local_date:
 * Y-m-d (must be TODAY in the family's time zone — David 2026-10-21, checked by the service; the
 * day's total of logged minutes per child is capped at 240 by the service too),
 * entry_id: the app's id of this entry (idempotency, 8–64 of [A-Za-z0-9_-])}`.
 * Parents only (UserPolicy::manageFamily → 403 for a child); the child's family and
 * adapted mode are checked in the controller.
 */
class LogParentWalkRequest extends FormRequest
{
    public const MAX_MINUTES = 240;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('manageFamily', User::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'minutes' => ['required', 'integer', 'between:1,'.self::MAX_MINUTES],
            'local_date' => ['required', 'date_format:Y-m-d'],
            'entry_id' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{8,64}$/'],
        ];
    }

    public function minutes(): int
    {
        return (int) $this->validated('minutes');
    }

    public function localDate(): string
    {
        return (string) $this->validated('local_date');
    }

    public function entryId(): string
    {
        return (string) $this->validated('entry_id');
    }
}
