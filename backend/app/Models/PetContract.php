<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The child's signed responsibility contract (PRODUCT_SPEC §3, M1-07).
 * One per pet; the signature is a finger drawing (SVG path or base64 PNG).
 *
 * @property string $signature_format svg_path | png
 * @property string $signature SVG path data, or base64 PNG without a data: prefix
 */
class PetContract extends Model
{
    public const FORMAT_SVG_PATH = 'svg_path';

    public const FORMAT_PNG = 'png';

    public const FORMATS = [self::FORMAT_SVG_PATH, self::FORMAT_PNG];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'pet_id',
        'user_id',
        'signature_format',
        'signature',
        'signed_at',
    ];

    /**
     * The drawing is personal data of a minor: never serialize it by accident.
     *
     * @var list<string>
     */
    protected $hidden = ['signature'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'signed_at' => 'datetime',
        ];
    }

    public function pet(): BelongsTo
    {
        return $this->belongsTo(Pet::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
