<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One check of one »Želje« item file generation (David 2026-11-04, StillQualityService): the AI
 * vision check of a catalogue picture / item key image, or the free technical check of an item
 * clip. Unique per (item_media_id, generation, trigger): inserting the row is the claim — a second
 * job of the same generation never checks (or pays) again. Same statuses as PetMediaQualityCheck.
 *
 * @property int $id
 * @property int $item_media_id
 * @property int $generation
 * @property string $trigger
 * @property string $status
 * @property array<string, mixed>|null $technical
 * @property bool|null $technical_ok
 * @property string $ai_status
 * @property array<string, mixed>|null $ai_verdict
 * @property float $ai_cost_usd
 * @property float|null $ai_reported_cost_usd
 * @property string|null $verdict
 * @property string|null $reason
 */
class ItemMediaQualityCheck extends Model
{
    protected $table = 'item_media_quality_checks';

    /** @var list<string> */
    protected $fillable = [
        'item_media_id', 'generation', 'trigger', 'status', 'technical', 'technical_ok',
        'ai_status', 'ai_verdict', 'ai_cost_usd', 'ai_reported_cost_usd', 'verdict', 'reason', 'decided_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'generation' => 'integer',
            'technical' => 'array',
            'technical_ok' => 'boolean',
            'ai_verdict' => 'array',
            'ai_cost_usd' => 'float',
            'ai_reported_cost_usd' => 'float',
            'decided_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ItemMedia, $this> */
    public function itemMedia(): BelongsTo
    {
        return $this->belongsTo(ItemMedia::class);
    }
}
