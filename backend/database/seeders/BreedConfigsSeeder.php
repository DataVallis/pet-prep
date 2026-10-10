<?php

namespace Database\Seeders;

use App\Enums\BreedType;
use App\Models\BreedConfig;
use App\Services\BreedCatalogService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Starting breed tunables (PRODUCT_SPEC §5). Production deploys run it every
 * time (scripts/deploy-production.sh), so it is INSERT-ONLY: a breed that
 * already exists is never touched — values edited in Filament always win
 * (DECISIONS 2026-10-03). The numbers come from the original product spec and
 * are pending verification against sourced breed data (ROADMAP M1-19).
 */
class BreedConfigsSeeder extends Seeder
{
    /**
     * Two-meal windows of a cat (M5-R06-03): docs/research/cat-data/data.json
     * general.meals_per_day.kitten_6_12_months notes "Windows 06–10 and
     * 17–21" — the same times as the dog default, now named as cat data.
     * Kittens' 4 / 3 meals have their own breed_stage_params rows.
     *
     * @var list<array{0: string, 1: string}>
     */
    public const CAT_FEED_WINDOWS = [['06:00', '10:00'], ['17:00', '21:00']];

    /**
     * @return list<array<string, mixed>>
     */
    public static function configs(): array
    {
        return [
            [
                'breed_slug' => BreedType::Mutt->slug(),
                'daily_steps_required' => 4000,
                'hunger_decay_rate' => 8.0,
                'thirst_decay_rate' => 10.0,
                'poops_per_day' => 1,
                'feed_windows' => BreedConfig::DEFAULT_FEED_WINDOWS,
                'water_times_per_day' => 3,
                'water_min_gap_minutes' => 180,
                'premium_unlock' => false,
                'species' => 'dog',
                'sort_order' => 0,
                'label_key' => 'breeds.mutt',
                'search_keywords' => ['mešanček', 'mesancek', 'mutt', 'mixed'],
            ],
            [
                'breed_slug' => BreedType::BorderCollie->slug(),
                'daily_steps_required' => 10000,
                'hunger_decay_rate' => 12.0,
                'thirst_decay_rate' => 15.0,
                'poops_per_day' => 2,
                'feed_windows' => BreedConfig::DEFAULT_FEED_WINDOWS,
                'water_times_per_day' => 3,
                'water_min_gap_minutes' => 180,
                'premium_unlock' => true,
                'species' => 'dog',
                'sort_order' => 10,
                'label_key' => 'breeds.border_collie',
                'search_keywords' => ['border collie', 'koli'],
            ],
            // M5-R10 Labrador Retriever (docs/research/dog-data/data.json
            // labrador_retriever, sources S48–S62; David 2026-10-09: paid like the
            // Border Collie). daily_steps_required = the adult step goal
            // (proposed_game_parameters.labrador_retriever.step_goal_adult: 90 min ×
            // 100 steps/min) — only the legacy-profile fallback; profiled pets take
            // the goal from breed_stage_params. Hunger / thirst decay, poops and
            // water are the Border Collie's (confirmed by David 2026-10-09:
            // −12 %/h, −15 %/h, 2 poops/day, water 3× ≥ 180 min apart; data.json
            // proposed_game_parameters.labrador_retriever.care_rates): the research
            // has no per-breed number for them (game balance, PRODUCT_SPEC §5).
            [
                'breed_slug' => BreedType::LabradorRetriever->slug(),
                'daily_steps_required' => 9000,
                'hunger_decay_rate' => 12.0,
                'thirst_decay_rate' => 15.0,
                'poops_per_day' => 2,
                'feed_windows' => BreedConfig::DEFAULT_FEED_WINDOWS,
                'water_times_per_day' => 3,
                'water_min_gap_minutes' => 180,
                'premium_unlock' => true,
                'species' => 'dog',
                'sort_order' => 20,
                'label_key' => 'breeds.labrador_retriever',
                'search_keywords' => ['labrador', 'labrador retriever', 'labradorec', 'labradorski prinašalec', 'labradorski prinasalec', 'lab', 'retriever', 'prinašalec', 'prinasalec'],
            ],
            // M5-R10-02 Golden Retriever (docs/research/dog-data/data.json
            // golden_retriever, sources S63–S75; potrdil David 2026-10-09: paid like
            // the Border Collie). daily_steps_required = the adult step goal
            // (proposed_game_parameters.golden_retriever.step_goal_adult: 120 min ×
            // 100 steps/min — RKC S65 "More than 2 hours per day", PDSA S68 "a
            // minimum of two hours") — only the legacy-profile fallback; profiled
            // pets take the goal from breed_stage_params. Hunger / thirst decay,
            // poops and water are the Border Collie's (David 2026-10-09,
            // proposed_game_parameters.golden_retriever.care_rates): the research
            // has no per-breed number for them (game balance, PRODUCT_SPEC §5).
            [
                'breed_slug' => BreedType::GoldenRetriever->slug(),
                'daily_steps_required' => 12000,
                'hunger_decay_rate' => 12.0,
                'thirst_decay_rate' => 15.0,
                'poops_per_day' => 2,
                'feed_windows' => BreedConfig::DEFAULT_FEED_WINDOWS,
                'water_times_per_day' => 3,
                'water_min_gap_minutes' => 180,
                'premium_unlock' => true,
                'species' => 'dog',
                'sort_order' => 30,
                'label_key' => 'breeds.golden_retriever',
                'search_keywords' => ['golden', 'golden retriever', 'zlati prinašalec', 'zlati prinasalec', 'retriever'],
            ],
            // M5-R10-03 French Bulldog (docs/research/dog-data/data.json
            // french_bulldog, sources S76–S94; potrdil David 2026-10-10: paid like
            // the Border Collie). daily_steps_required = the adult step goal
            // (proposed_game_parameters.french_bulldog.step_goal_adult: 60 min ×
            // 100 steps/min — RKC S78 "Up to 1 hour per day", PDSA S81 "up to an
            // hour") — only the legacy-profile fallback; profiled pets take the goal
            // from breed_stage_params. Hunger / thirst decay, poops and water are the
            // Border Collie's (David 2026-10-10, proposed_game_parameters
            // .french_bulldog.care_rates): no per-breed number in the research.
            [
                'breed_slug' => BreedType::FrenchBulldog->slug(),
                'daily_steps_required' => 6000,
                'hunger_decay_rate' => 12.0,
                'thirst_decay_rate' => 15.0,
                'poops_per_day' => 2,
                'feed_windows' => BreedConfig::DEFAULT_FEED_WINDOWS,
                'water_times_per_day' => 3,
                'water_min_gap_minutes' => 180,
                'premium_unlock' => true,
                'species' => 'dog',
                'sort_order' => 40,
                'label_key' => 'breeds.french_bulldog',
                'search_keywords' => ['french bulldog', 'frenchie', 'french', 'bulldog', 'francoski buldog', 'buldog'],
            ],
            // M5-R10-04 German Shepherd Dog (docs/research/dog-data/data.json
            // german_shepherd, sources S95–S102; runbook rules confirmed by David
            // 2026-10-10: paid). daily_steps_required = the adult step goal
            // (proposed_game_parameters.german_shepherd.step_goal_adult: 120 min ×
            // 100 steps/min — RKC S97 "More than 2 hours per day", PDSA S100 "a
            // minimum of two hours") — only the legacy-profile fallback. Hunger /
            // thirst decay, poops and water are the Border Collie's (no per-breed number).
            [
                'breed_slug' => BreedType::GermanShepherd->slug(),
                'daily_steps_required' => 12000,
                'hunger_decay_rate' => 12.0,
                'thirst_decay_rate' => 15.0,
                'poops_per_day' => 2,
                'feed_windows' => BreedConfig::DEFAULT_FEED_WINDOWS,
                'water_times_per_day' => 3,
                'water_min_gap_minutes' => 180,
                'premium_unlock' => true,
                'species' => 'dog',
                'sort_order' => 50,
                'label_key' => 'breeds.german_shepherd',
                'search_keywords' => ['german shepherd', 'german shepherd dog', 'gsd', 'alsatian', 'nemški ovčar', 'nemski ovcar', 'ovčar', 'ovcar'],
            ],
            // M5-R10-05 Cavalier King Charles Spaniel (docs/research/dog-data/data.json
            // cavalier_king_charles_spaniel, sources S103–S110; runbook rules confirmed by
            // David 2026-10-10: paid). daily_steps_required = the adult step goal
            // (proposed_game_parameters.cavalier_king_charles_spaniel.step_goal_adult: 60 min ×
            // 100 steps/min — RKC S105 "Up to 1 hour per day", PDSA S108 "at least one
            // hour") — only the legacy-profile fallback. Hunger / thirst decay, poops and
            // water are the Border Collie's (no per-breed number).
            [
                'breed_slug' => BreedType::CavalierKingCharlesSpaniel->slug(),
                'daily_steps_required' => 6000,
                'hunger_decay_rate' => 12.0,
                'thirst_decay_rate' => 15.0,
                'poops_per_day' => 2,
                'feed_windows' => BreedConfig::DEFAULT_FEED_WINDOWS,
                'water_times_per_day' => 3,
                'water_min_gap_minutes' => 180,
                'premium_unlock' => true,
                'species' => 'dog',
                'sort_order' => 60,
                'label_key' => 'breeds.cavalier_king_charles_spaniel',
                'search_keywords' => ['cavalier king charles spaniel', 'cavalier', 'king charles', 'ckcs', 'spaniel', 'kavalir king charles španjel', 'kavalir king charles spanjel', 'kavalir', 'španjel', 'spanjel'],
            ],
            // M5-R10-06 Beagle (docs/research/dog-data/data.json beagle, sources S111–S117;
            // runbook rules confirmed by David 2026-10-10: paid). daily_steps_required = the
            // adult step goal (proposed_game_parameters.beagle.step_goal_adult: 60 min ×
            // 100 steps/min — RKC S113 "Up to 1 hour per day"; PDSA S115 "at least an hour
            // and a half" is the recorded alternative) — only the legacy-profile fallback.
            // Hunger / thirst decay, poops and water are the Border Collie's (no per-breed number).
            [
                'breed_slug' => BreedType::Beagle->slug(),
                'daily_steps_required' => 6000,
                'hunger_decay_rate' => 12.0,
                'thirst_decay_rate' => 15.0,
                'poops_per_day' => 2,
                'feed_windows' => BreedConfig::DEFAULT_FEED_WINDOWS,
                'water_times_per_day' => 3,
                'water_min_gap_minutes' => 180,
                'premium_unlock' => true,
                'species' => 'dog',
                'sort_order' => 70,
                'label_key' => 'breeds.beagle',
                'search_keywords' => ['beagle', 'bigl'],
            ],
            // M5-R10-07 Standard Poodle (docs/research/dog-data/data.json standard_poodle, sources
            // S118–S123; runbook rules confirmed by David 2026-10-10: paid). daily_steps_required =
            // the adult step goal (proposed_game_parameters.standard_poodle.step_goal_adult: 60 min ×
            // 100 steps/min — RKC S120 "Up to 1 hour per day", PDSA S122 "around an hour") — only
            // the legacy-profile fallback. Hunger / thirst decay, poops and water are the Border
            // Collie's (no per-breed number).
            [
                'breed_slug' => BreedType::StandardPoodle->slug(),
                'daily_steps_required' => 6000,
                'hunger_decay_rate' => 12.0,
                'thirst_decay_rate' => 15.0,
                'poops_per_day' => 2,
                'feed_windows' => BreedConfig::DEFAULT_FEED_WINDOWS,
                'water_times_per_day' => 3,
                'water_min_gap_minutes' => 180,
                'premium_unlock' => true,
                'species' => 'dog',
                'sort_order' => 80,
                'label_key' => 'breeds.standard_poodle',
                'search_keywords' => ['poodle (standard)', 'standard poodle', 'poodle', 'veliki koder', 'koder', 'veliki pudelj', 'pudelj', 'standardni pudelj'],
            ],
            // M5-R06-01 cats (CAT_SPEC Q5 / Q6 / Q9, docs/research/cat-data/data.json,
            // potrdil David 2026-10-08 13:47). Hidden until PETPREP_CATS_ENABLED.
            // Water 2× per day ≥ 240 min apart (general.water.game_*), hunger and
            // thirst −8 %/h for both breeds (general.game_decay). No poop events
            // (litter uses come in M5-R06-05) and no steps (play replaces the walk in
            // M5-R06-04; 0 = energyForSteps() is always 100 %, so no walk illness).
            // M5-R06-03: every value checked against data.json (LifeStageDataTest);
            // the two-meal windows are the cat data (CAT_FEED_WINDOWS, identical to
            // the R06-01 rows, so production needs no data migration). poops_per_day
            // stays 0 for good: a litter use is not a mess (CAT_SPEC §4) — its count
            // is breed_stage_params litter_uses_per_day.
            [
                'breed_slug' => BreedType::DomesticCat->slug(),
                'daily_steps_required' => 0,
                'hunger_decay_rate' => 8.0,
                'thirst_decay_rate' => 8.0,
                'poops_per_day' => 0,
                'feed_windows' => self::CAT_FEED_WINDOWS,
                'water_times_per_day' => 2,
                'water_min_gap_minutes' => 240,
                'premium_unlock' => false,
                'species' => 'cat',
                'sort_order' => 0,
                'label_key' => 'breeds.domestic_cat',
                'search_keywords' => ['domača mačka', 'domaca macka', 'mešanka', 'mesanka', 'domestic cat', 'moggy'],
            ],
            [
                'breed_slug' => BreedType::MaineCoon->slug(),
                'daily_steps_required' => 0,
                'hunger_decay_rate' => 8.0,
                'thirst_decay_rate' => 8.0,
                'poops_per_day' => 0,
                'feed_windows' => self::CAT_FEED_WINDOWS,
                'water_times_per_day' => 2,
                'water_min_gap_minutes' => 240,
                'premium_unlock' => true,
                'species' => 'cat',
                'sort_order' => 10,
                'label_key' => 'breeds.maine_coon',
                'search_keywords' => ['maine coon', 'mejnkun', 'mainska'],
            ],
        ];
    }

    /**
     * Run the database seeds: breed tunables, then the sourced life-stage
     * data (M5-R01, BreedStageParamsSeeder — also insert-only). The deploy
     * runs only this seeder, so both arrive with every deploy.
     */
    public function run(): void
    {
        $this->seedConfigs();
        (new BreedStageParamsSeeder)->run();
    }

    /**
     * Only the breed_configs rows (insert-only). Tests that are not about
     * life stages use this (tests/Pest.php seedBreedConfigs()): without
     * stage data a pet keeps the pre-M5 rules (breed feed windows,
     * daily_steps_required).
     */
    public function seedConfigs(): void
    {
        $now = now();

        foreach (self::configs() as $config) {
            $config['feed_windows'] = json_encode($config['feed_windows']);
            $config['search_keywords'] = json_encode($config['search_keywords'], JSON_UNESCAPED_UNICODE);

            DB::table('breed_configs')->insertOrIgnore(
                array_merge($config, ['created_at' => $now, 'updated_at' => $now])
            );
        }

        // Raw inserts fire no model events (M5-R06-01 free / paid catalogue cache).
        BreedCatalogService::forget();
    }
}
