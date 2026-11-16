<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Anonymous counter of the petprep.si breed quiz (M6-S06, BREED_QUIZ_SPEC §8):
 * one row per (UTC day, event, breed, rank, locale). Written only by
 * BreedQuizStatService (atomic upsert); read-only in /admin (superadmin).
 * No IP, no cookie, no visitor identity of any kind.
 *
 * @property int $id
 * @property Carbon $day
 * @property string $event
 * @property string $breed
 * @property int|null $rank 1–3 for `completed`, NULL for clicks
 * @property string $locale
 * @property int $count
 */
class BreedQuizStat extends Model
{
    public const EVENT_COMPLETED = 'completed';

    public const EVENT_CALCULATOR_CLICK = 'calculator_click';

    public const EVENT_APP_CLICK = 'app_click';

    /** @var list<string> */
    public const EVENTS = [self::EVENT_COMPLETED, self::EVENT_CALCULATOR_CLICK, self::EVENT_APP_CLICK];

    /**
     * @var list<string>
     */
    protected $fillable = ['day', 'event', 'breed', 'rank', 'locale', 'count'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['day' => 'date', 'rank' => 'integer', 'count' => 'integer'];
    }
}
