<?php

namespace App\Models;

use App\Enums\BreedFactAudience;
use App\Enums\BreedFactCategory;
use App\Enums\BreedFactStatus;
use App\Enums\BreedType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One sourced fact about a breed (M5-R09b, DID_YOU_KNOW_SPEC §5 / §7).
 * Content comes from git (docs/research/breed-facts/<breed>.json, imported
 * with `breeds:import-facts`, upsert by `key`); the review state is changed
 * in the admin (BreedFactResource). Only `approved` rows reach the apps.
 *
 * @property int $id
 * @property string $key
 * @property BreedType $breed
 * @property BreedFactCategory $category
 * @property BreedFactAudience $audience
 * @property string $title_en
 * @property string $title_sl
 * @property string|null $why_en
 * @property string|null $why_sl
 * @property string $source_id
 * @property string $source_name
 * @property string $source_url
 * @property string $quote
 * @property string|null $game_rule_key
 * @property int $sort
 * @property BreedFactStatus $status
 * @property string|null $verified_by
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property string $content_hash
 */
class BreedFact extends Model
{
    /**
     * The verifiable content, in hash order (content_hash / verified_hash).
     * `sort` is NOT part of it: reordering never re-opens a review.
     */
    public const CONTENT_FIELDS = [
        'breed', 'category', 'audience', 'title_en', 'title_sl', 'why_en', 'why_sl',
        'source_id', 'source_name', 'source_url', 'quote', 'game_rule_key',
    ];

    public const TITLE_MAX = 120;

    public const WHY_MAX = 140;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'key', 'breed', 'category', 'audience', 'title_en', 'title_sl', 'why_en', 'why_sl',
        'source_id', 'source_name', 'source_url', 'quote', 'game_rule_key', 'sort',
        'status', 'verified_by', 'approved_by', 'approved_at', 'content_hash',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'breed' => BreedType::class,
            'category' => BreedFactCategory::class,
            'audience' => BreedFactAudience::class,
            'status' => BreedFactStatus::class,
            'sort' => 'integer',
            'approved_at' => 'datetime',
        ];
    }

    /**
     * The content hash (docs/research/breed-facts/README.md, »verified_hash«):
     * lower-case hex sha256 of the UTF-8 JSON array of CONTENT_FIELDS in that
     * order (null kept as null), serialised like JavaScript JSON.stringify —
     * no spaces, `/` and non-ASCII not escaped. Same value as
     * `php artisan breeds:import-facts --print-hashes`.
     *
     * @param  array<string, mixed>  $content
     */
    public static function hashOf(array $content): string
    {
        $values = [];
        foreach (self::CONTENT_FIELDS as $field) {
            $value = $content[$field] ?? null;
            $values[] = $value instanceof \BackedEnum ? $value->value : $value;
        }

        return hash('sha256', (string) json_encode($values, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR));
    }

    /** The current content hash of this row (from its attributes). */
    public function computeHash(): string
    {
        $content = [];
        foreach (self::CONTENT_FIELDS as $field) {
            $content[$field] = $this->getAttribute($field);
        }

        return self::hashOf($content);
    }

    public function isApproved(): bool
    {
        return $this->status === BreedFactStatus::Approved;
    }

    /** Source fields required before a fact may become verified (spec §7). */
    public function hasSource(): bool
    {
        return filled($this->source_id) && filled($this->source_name) && filled($this->source_url) && filled($this->quote);
    }

    /** The title in a supported language (en default). */
    public function titleIn(string $locale): string
    {
        return $locale === 'sl' ? $this->title_sl : $this->title_en;
    }

    public function whyIn(string $locale): ?string
    {
        return $locale === 'sl' ? $this->why_sl : $this->why_en;
    }

    /**
     * @param  Builder<BreedFact>  $query
     * @return Builder<BreedFact>
     */
    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', BreedFactStatus::Approved->value);
    }

    /**
     * Stable order of a breed's facts: sort, then id.
     *
     * @param  Builder<BreedFact>  $query
     * @return Builder<BreedFact>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort')->orderBy('id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function reads(): HasMany
    {
        return $this->hasMany(BreedFactRead::class);
    }
}
