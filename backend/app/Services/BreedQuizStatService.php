<?php

namespace App\Services;

use App\Models\BreedQuizStat;
use Illuminate\Support\Facades\DB;

/**
 * Anonymous counting of the petprep.si breed quiz (M6-S06). Only counters: the
 * caller passes event, locale and breed ids — never anything about the visitor.
 */
class BreedQuizStatService
{
    /**
     * `completed`: one counter per breed with its rank (1–3, list order);
     * clicks: one counter for the clicked breed with rank NULL. Each is an atomic
     * upsert on the NULLS NOT DISTINCT unique key (day, event, breed, rank, locale).
     *
     * @param  list<string>  $breeds
     */
    public function record(string $event, string $locale, array $breeds): void
    {
        $day = now('UTC')->toDateString();
        $now = now('UTC')->toDateTimeString();

        DB::transaction(function () use ($event, $locale, $breeds, $day, $now): void {
            foreach (array_values($breeds) as $index => $breed) {
                $rank = $event === BreedQuizStat::EVENT_COMPLETED ? $index + 1 : null;

                DB::statement(
                    'INSERT INTO breed_quiz_stats (day, event, breed, rank, locale, count, created_at, updated_at) '
                    .'VALUES (?, ?, ?, ?, ?, 1, ?, ?) '
                    .'ON CONFLICT (day, event, breed, rank, locale) '
                    .'DO UPDATE SET count = breed_quiz_stats.count + 1, updated_at = EXCLUDED.updated_at',
                    [$day, $event, $breed, $rank, $locale, $now, $now],
                );
            }
        });
    }
}
