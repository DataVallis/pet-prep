<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /api/parent/children/{child}/report?days=7|30|84 (M2-05 / M2-06).
 * Authorization (parent of the child's family) happens in the controller:
 * another family's child must look exactly like a missing one (404).
 */
class ChildReportRequest extends FormRequest
{
    public const ALLOWED_DAYS = [7, 30, 84];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'days' => ['sometimes', 'integer', 'in:7,30,84'],
        ];
    }

    public function days(): int
    {
        return (int) $this->query('days', 7);
    }
}
