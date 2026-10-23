<?php

namespace App\Http\Requests;

use App\Enums\MediaDisplay;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PATCH /api/parent/children/{child}/settings (M5-T12, David 2026-10-20; M5-T11
 * 2026-10-21). Body: at least one of
 *  - `adapted_mode` (bool) — »Prilagojen način«: the child's walk counts wheelchair
 *    pushes instead of steps;
 *  - `media_display` (`video` | `picture`) — »Prikaz: Video / Slika« in the child app;
 *  - `easy_gestures` (bool, M5-T17 2026-10-23) — »Lažje kretnje«: press-and-hold instead
 *    of swipe in every swipe game of the child app.
 * Parents only (UserPolicy::manageFamily → 403 for a child; the route group also needs
 * the `parent` token ability); whether the child belongs to the parent's family is
 * checked in the controller (another family's child looks exactly like a missing one, 404).
 *
 * Accessibility, not a diagnosis: only the switch is accepted and stored —
 * there is no field for a reason or a condition (minimum data about a minor).
 */
class UpdateChildSettingsRequest extends FormRequest
{
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
            'adapted_mode' => ['required_without_all:media_display,easy_gestures', 'boolean'],
            'media_display' => ['required_without_all:adapted_mode,easy_gestures', 'string', Rule::enum(MediaDisplay::class)],
            'easy_gestures' => ['required_without_all:adapted_mode,media_display', 'boolean'],
        ];
    }

    /** null = not sent (leave unchanged). */
    public function adaptedMode(): ?bool
    {
        return $this->has('adapted_mode') ? (bool) $this->validated('adapted_mode') : null;
    }

    /** null = not sent (leave unchanged). */
    public function mediaDisplay(): ?MediaDisplay
    {
        return $this->has('media_display') ? MediaDisplay::from((string) $this->validated('media_display')) : null;
    }

    /** null = not sent (leave unchanged). */
    public function easyGestures(): ?bool
    {
        return $this->has('easy_gestures') ? (bool) $this->validated('easy_gestures') : null;
    }
}
