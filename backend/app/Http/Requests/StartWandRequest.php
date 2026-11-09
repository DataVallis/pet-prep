<?php

namespace App\Http\Requests;

use App\Models\Pet;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/child/pet/wand/start (M5-R06-04): start the cat's ~60 s wand
 * game. The server generates the session. Optional `rules` (GAMES_SPEC §7.2):
 * 3 = the simple hunt (let go when the cat crouches — a new app; David
 * 2026-11-08); 2 = an older TestFlight build's strict hunt, which now gets the
 * simple hunt too; missing / 1 = the v1 minute, unchanged for old apps. Only
 * children (PetPolicy).
 */
class StartWandRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('useChildApi', Pet::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // GAMES_SPEC §7.2: the game rules the app implements (1 = v1, 3 = the simple hunt; 2 = legacy → simple).
            'rules' => ['sometimes', 'integer', 'min:1', 'max:3'],
        ];
    }

    /** The rules version asked for (1 without the field). */
    public function rulesVersion(): int
    {
        return (int) ($this->validated('rules') ?? 1);
    }
}
