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
                'water_refill_below' => 60,
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
                'water_refill_below' => 60,
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
                'water_refill_below' => 60,
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
                'water_refill_below' => 60,
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
                'water_refill_below' => 60,
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
                'water_refill_below' => 60,
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
                'water_refill_below' => 60,
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
                'water_refill_below' => 60,
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
                'water_refill_below' => 60,
                'premium_unlock' => true,
                'species' => 'dog',
                'sort_order' => 80,
                'label_key' => 'breeds.standard_poodle',
                'search_keywords' => ['poodle (standard)', 'standard poodle', 'poodle', 'veliki koder', 'koder', 'veliki pudelj', 'pudelj', 'standardni pudelj'],
            ],
            // M5-R10-08 Dachshund, standard size (docs/research/dog-data/data.json dachshund, sources
            // S124–S130; runbook rules confirmed by David 2026-10-10: paid). daily_steps_required =
            // the adult step goal (proposed_game_parameters.dachshund.step_goal_adult: 60 min ×
            // 100 steps/min — RKC S125 "Up to 1 hour per day", PDSA S127 "a minimum of an hour") —
            // only the legacy-profile fallback. Hunger / thirst decay, poops and water are the
            // Border Collie's (no per-breed number).
            [
                'breed_slug' => BreedType::Dachshund->slug(),
                'daily_steps_required' => 6000,
                'hunger_decay_rate' => 12.0,
                'thirst_decay_rate' => 15.0,
                'poops_per_day' => 2,
                'feed_windows' => BreedConfig::DEFAULT_FEED_WINDOWS,
                'water_times_per_day' => 3,
                'water_min_gap_minutes' => 180,
                'water_refill_below' => 60,
                'premium_unlock' => true,
                'species' => 'dog',
                'sort_order' => 90,
                'label_key' => 'breeds.dachshund',
                'search_keywords' => ['dachshund', 'sausage dog', 'teckel', 'jazbečar', 'jazbecar'],
            ],
            // M5-R10-09 Australian Shepherd (docs/research/dog-data/data.json australian_shepherd,
            // sources S131–S135; runbook rules confirmed by David 2026-10-10: paid).
            // daily_steps_required = the adult step goal
            // (proposed_game_parameters.australian_shepherd.step_goal_adult: 120 min × 100 steps/min —
            // RKC S133 "More than 2 hours per day", PDSA S134 "a minimum of two hours") — only the
            // legacy-profile fallback. Hunger / thirst decay, poops and water are the Border Collie's
            // (no per-breed number).
            [
                'breed_slug' => BreedType::AustralianShepherd->slug(),
                'daily_steps_required' => 12000,
                'hunger_decay_rate' => 12.0,
                'thirst_decay_rate' => 15.0,
                'poops_per_day' => 2,
                'feed_windows' => BreedConfig::DEFAULT_FEED_WINDOWS,
                'water_times_per_day' => 3,
                'water_min_gap_minutes' => 180,
                'water_refill_below' => 60,
                'premium_unlock' => true,
                'species' => 'dog',
                'sort_order' => 100,
                'label_key' => 'breeds.australian_shepherd',
                'search_keywords' => ['australian shepherd', 'aussie', 'avstralski ovčar', 'avstralski ovcar'],
            ],
            // M5-R10-10 Havanese (docs/research/dog-data/data.json havanese,
            // sources S136–S141; runbook rules confirmed by David 2026-10-10: paid).
            // daily_steps_required = the adult step goal
            // (proposed_game_parameters.havanese.step_goal_adult: 30 min × 100 steps/min —
            // RKC S138 "Up to 30 minutes per day", PDSA S140 "around 30 minutes") — only the
            // legacy-profile fallback. Hunger / thirst decay, poops and water are the Border Collie's
            // (no per-breed number).
            [
                'breed_slug' => BreedType::Havanese->slug(),
                'daily_steps_required' => 3000,
                'hunger_decay_rate' => 12.0,
                'thirst_decay_rate' => 15.0,
                'poops_per_day' => 2,
                'feed_windows' => BreedConfig::DEFAULT_FEED_WINDOWS,
                'water_times_per_day' => 3,
                'water_min_gap_minutes' => 180,
                'water_refill_below' => 60,
                'premium_unlock' => true,
                'species' => 'dog',
                'sort_order' => 110,
                'label_key' => 'breeds.havanese',
                'search_keywords' => ['havanese', 'havanski bišon', 'havanski bison', 'bichon havanais', 'havanez'],
            ],
            // M5-R10-11 West Highland White Terrier (docs/research/dog-data/data.json
            // west_highland_white_terrier, sources S142–S149; runbook rules confirmed by David
            // 2026-10-10: paid). daily_steps_required = the adult step goal
            // (proposed_game_parameters.west_highland_white_terrier.step_goal_adult: 60 min ×
            // 100 steps/min — RKC S144 "Up to 1 hour per day", PDSA S147 "an hour exercise every
            // day") — only the legacy-profile fallback. Hunger / thirst decay, poops and water are
            // the Border Collie's (no per-breed number).
            [
                'breed_slug' => BreedType::WestHighlandWhiteTerrier->slug(),
                'daily_steps_required' => 6000,
                'hunger_decay_rate' => 12.0,
                'thirst_decay_rate' => 15.0,
                'poops_per_day' => 2,
                'feed_windows' => BreedConfig::DEFAULT_FEED_WINDOWS,
                'water_times_per_day' => 3,
                'water_min_gap_minutes' => 180,
                'water_refill_below' => 60,
                'premium_unlock' => true,
                'species' => 'dog',
                'sort_order' => 120,
                'label_key' => 'breeds.west_highland_white_terrier',
                'search_keywords' => ['west highland white terrier', 'westie', 'westy', 'zahodnoškotski beli terier', 'zahodnoskotski beli terier', 'west highland terier', 'vestie'],
            ],
            // M5-R10-12 Bernese Mountain Dog (docs/research/dog-data/data.json
            // bernese_mountain_dog, sources S150–S157; runbook rules confirmed by David
            // 2026-10-10: paid). daily_steps_required = the adult step goal
            // (proposed_game_parameters.bernese_mountain_dog.step_goal_adult: 60 min × 100
            // steps/min — RKC S152 "Up to 1 hour per day", PDSA S154 "at least an hour") — only
            // the legacy-profile fallback. Hunger / thirst decay, poops and water are the
            // Border Collie's (no per-breed number).
            [
                'breed_slug' => BreedType::BerneseMountainDog->slug(),
                'daily_steps_required' => 6000,
                'hunger_decay_rate' => 12.0,
                'thirst_decay_rate' => 15.0,
                'poops_per_day' => 2,
                'feed_windows' => BreedConfig::DEFAULT_FEED_WINDOWS,
                'water_times_per_day' => 3,
                'water_min_gap_minutes' => 180,
                'water_refill_below' => 60,
                'premium_unlock' => true,
                'species' => 'dog',
                'sort_order' => 130,
                'label_key' => 'breeds.bernese_mountain_dog',
                'search_keywords' => ['bernese mountain dog', 'berner', 'berner sennenhund', 'bernese', 'bernski planšarski pes', 'bernski plansarski pes', 'bernski planšar', 'bernski plansar'],
            ],
            // M5-R10-13 Siberian Husky (docs/research/dog-data/data.json siberian_husky,
            // sources S158–S165; runbook rules confirmed by David 2026-10-10: paid).
            // daily_steps_required = the adult step goal
            // (proposed_game_parameters.siberian_husky.step_goal_adult: 120 min × 100
            // steps/min — RKC S160 "More than 2 hours per day", PDSA S162 "a minimum of two
            // hours") — only the legacy-profile fallback. Hunger / thirst decay, poops and
            // water are the Border Collie's (no per-breed number).
            [
                'breed_slug' => BreedType::SiberianHusky->slug(),
                'daily_steps_required' => 12000,
                'hunger_decay_rate' => 12.0,
                'thirst_decay_rate' => 15.0,
                'poops_per_day' => 2,
                'feed_windows' => BreedConfig::DEFAULT_FEED_WINDOWS,
                'water_times_per_day' => 3,
                'water_min_gap_minutes' => 180,
                'water_refill_below' => 60,
                'premium_unlock' => true,
                'species' => 'dog',
                'sort_order' => 140,
                'label_key' => 'breeds.siberian_husky',
                'search_keywords' => ['siberian husky', 'husky', 'huskie', 'sibirski haski', 'haski', 'sibirski husky', 'sibirec'],
            ],
            // M5-R10-19 Rottweiler (docs/research/dog-data/data.json rottweiler,
            // sources S166–S174; runbook rules confirmed by David 2026-10-10: paid).
            // daily_steps_required = the adult step goal
            // (proposed_game_parameters.rottweiler.step_goal_adult: 120 min × 100
            // steps/min — RKC S168 "More than 2 hours per day", PDSA S169 "at least two
            // hours") — only the legacy-profile fallback. Hunger / thirst decay, poops and
            // water are the Border Collie's (no per-breed number).
            [
                'breed_slug' => BreedType::Rottweiler->slug(),
                'daily_steps_required' => 12000,
                'hunger_decay_rate' => 12.0,
                'thirst_decay_rate' => 15.0,
                'poops_per_day' => 2,
                'feed_windows' => BreedConfig::DEFAULT_FEED_WINDOWS,
                'water_times_per_day' => 3,
                'water_min_gap_minutes' => 180,
                'water_refill_below' => 60,
                'premium_unlock' => true,
                'species' => 'dog',
                'sort_order' => 150,
                'label_key' => 'breeds.rottweiler',
                'search_keywords' => ['rottweiler', 'rottie', 'rotty', 'rott', 'rotvajler', 'rotvajlerka', 'rotweiler', 'rotvailer'],
            ],
            // M5-R10-20 Bulldog (docs/research/dog-data/data.json bulldog, sources
            // S175–S183; runbook rules confirmed by David 2026-10-10: paid).
            // daily_steps_required = the adult step goal
            // (proposed_game_parameters.bulldog.step_goal_adult: 60 min × 100 steps/min
            // — RKC S177 "Up to 1 hour per day", PDSA S180 "up to an hour's exercise
            // daily") — only the legacy-profile fallback. Hunger / thirst decay, poops and
            // water are the Border Collie's (no per-breed number).
            [
                'breed_slug' => BreedType::Bulldog->slug(),
                'daily_steps_required' => 6000,
                'hunger_decay_rate' => 12.0,
                'thirst_decay_rate' => 15.0,
                'poops_per_day' => 2,
                'feed_windows' => BreedConfig::DEFAULT_FEED_WINDOWS,
                'water_times_per_day' => 3,
                'water_min_gap_minutes' => 180,
                'water_refill_below' => 60,
                'premium_unlock' => true,
                'species' => 'dog',
                'sort_order' => 160,
                'label_key' => 'breeds.bulldog',
                'search_keywords' => ['bulldog', 'english bulldog', 'british bulldog', 'angleški buldog', 'angleski buldog', 'buldog'],
            ],
            // M5-R10-21 German Shorthaired Pointer (docs/research/dog-data/data.json
            // german_shorthaired_pointer, sources S184–S189; runbook rules confirmed by David
            // 2026-10-10: paid). daily_steps_required = the adult step goal
            // (proposed_game_parameters.german_shorthaired_pointer.step_goal_adult: 120 min ×
            // 100 steps/min — RKC S187 "More than 2 hours per day", PDSA S188 "over 2 hours
            // ideally per day") — only the legacy-profile fallback. Hunger / thirst decay, poops
            // and water are the Border Collie's (no per-breed number).
            [
                'breed_slug' => BreedType::GermanShorthairedPointer->slug(),
                'daily_steps_required' => 12000,
                'hunger_decay_rate' => 12.0,
                'thirst_decay_rate' => 15.0,
                'poops_per_day' => 2,
                'feed_windows' => BreedConfig::DEFAULT_FEED_WINDOWS,
                'water_times_per_day' => 3,
                'water_min_gap_minutes' => 180,
                'water_refill_below' => 60,
                'premium_unlock' => true,
                'species' => 'dog',
                'sort_order' => 170,
                'label_key' => 'breeds.german_shorthaired_pointer',
                'search_keywords' => ['german shorthaired pointer', 'german short-haired pointer', 'german pointer', 'gsp', 'shorthair', 'deutsch kurzhaar', 'kurzhaar', 'nemški kratkodlaki ptičar', 'nemski kratkodlaki pticar', 'kratkodlaki ptičar', 'kratkodlaki pticar', 'ptičar', 'pticar'],
            ],
            // M5-R10-22 Yorkshire Terrier (docs/research/dog-data/data.json yorkshire_terrier,
            // sources S190–S196; runbook rules confirmed by David 2026-10-10: paid).
            // daily_steps_required = the adult step goal
            // (proposed_game_parameters.yorkshire_terrier.step_goal_adult: 30 min × 100 steps/min
            // — RKC S192 "Up to 30 minutes per day", PDSA S194 "30 or 40 minutes") — only the
            // legacy-profile fallback. Hunger / thirst decay, poops and water are the Border
            // Collie's (no per-breed number).
            [
                'breed_slug' => BreedType::YorkshireTerrier->slug(),
                'daily_steps_required' => 3000,
                'hunger_decay_rate' => 12.0,
                'thirst_decay_rate' => 15.0,
                'poops_per_day' => 2,
                'feed_windows' => BreedConfig::DEFAULT_FEED_WINDOWS,
                'water_times_per_day' => 3,
                'water_min_gap_minutes' => 180,
                'water_refill_below' => 60,
                'premium_unlock' => true,
                'species' => 'dog',
                'sort_order' => 180,
                'label_key' => 'breeds.yorkshire_terrier',
                'search_keywords' => ['yorkshire terrier', 'yorkshire', 'yorkie', 'yorki', 'yorkshirski terier', 'jorkširski terier', 'jorksirski terier', 'jorki'],
            ],
            // M5-R10-23 Cane Corso (docs/research/dog-data/data.json cane_corso, sources
            // S197–S201; runbook rules confirmed by David 2026-10-10: paid).
            // daily_steps_required = the adult step goal
            // (proposed_game_parameters.cane_corso.step_goal_adult: 120 min × 100 steps/min —
            // PDSA S199 "around two hours’ exercise every day"; no RKC page) — only the
            // legacy-profile fallback. Hunger / thirst decay, poops and water are the Border
            // Collie's (no per-breed number).
            [
                'breed_slug' => BreedType::CaneCorso->slug(),
                'daily_steps_required' => 12000,
                'hunger_decay_rate' => 12.0,
                'thirst_decay_rate' => 15.0,
                'poops_per_day' => 2,
                'feed_windows' => BreedConfig::DEFAULT_FEED_WINDOWS,
                'water_times_per_day' => 3,
                'water_min_gap_minutes' => 180,
                'water_refill_below' => 60,
                'premium_unlock' => true,
                'species' => 'dog',
                'sort_order' => 190,
                'label_key' => 'breeds.cane_corso',
                'search_keywords' => ['cane corso', 'cane corso italiano', 'italian cane corso', 'italian corso dog', 'corso', 'italijanski cane corso', 'korzo', 'kane korso'],
            ],
            // M5-R10-24 Pembroke Welsh Corgi (docs/research/dog-data/data.json pembroke_welsh_corgi,
            // sources S202–S206; runbook rules confirmed by David 2026-10-10: paid).
            // daily_steps_required = the adult step goal
            // (proposed_game_parameters.pembroke_welsh_corgi.step_goal_adult: 60 min × 100 steps/min
            // — RKC S204 "Up to 1 hour per day", PDSA S206 "around an hour") — only the
            // legacy-profile fallback. Hunger / thirst decay, poops and water are the Border
            // Collie's (no per-breed number).
            [
                'breed_slug' => BreedType::PembrokeWelshCorgi->slug(),
                'daily_steps_required' => 6000,
                'hunger_decay_rate' => 12.0,
                'thirst_decay_rate' => 15.0,
                'poops_per_day' => 2,
                'feed_windows' => BreedConfig::DEFAULT_FEED_WINDOWS,
                'water_times_per_day' => 3,
                'water_min_gap_minutes' => 180,
                'water_refill_below' => 60,
                'premium_unlock' => true,
                'species' => 'dog',
                'sort_order' => 200,
                'label_key' => 'breeds.pembroke_welsh_corgi',
                'search_keywords' => ['pembroke welsh corgi', 'welsh corgi pembroke', 'pembroke', 'corgi', 'welsh corgi', 'korgi', 'valizanski korgi', 'valižanski korgi'],
            ],
            // M5-R10-25 Dobermann (docs/research/dog-data/data.json dobermann,
            // sources S207–S212; runbook rules confirmed by David 2026-10-10: paid).
            // daily_steps_required = the adult step goal
            // (proposed_game_parameters.dobermann.step_goal_adult: 120 min × 100 steps/min
            // — RKC S209 "More than 2 hours per day", PDSA S211 "a minimum of two hours") — only
            // the legacy-profile fallback. Hunger / thirst decay, poops and water are the Border
            // Collie's (no per-breed number).
            [
                'breed_slug' => BreedType::Dobermann->slug(),
                'daily_steps_required' => 12000,
                'hunger_decay_rate' => 12.0,
                'thirst_decay_rate' => 15.0,
                'poops_per_day' => 2,
                'feed_windows' => BreedConfig::DEFAULT_FEED_WINDOWS,
                'water_times_per_day' => 3,
                'water_min_gap_minutes' => 180,
                'water_refill_below' => 60,
                'premium_unlock' => true,
                'species' => 'dog',
                'sort_order' => 210,
                'label_key' => 'breeds.dobermann',
                'search_keywords' => ['dobermann', 'doberman', 'doberman pinscher', 'dobie', 'dobermann pinscher'],
            ],
            // M5-R10-26 Boxer (docs/research/dog-data/data.json boxer, sources S213–S220;
            // runbook rules confirmed by David 2026-10-10: paid). daily_steps_required = the adult
            // step goal (proposed_game_parameters.boxer.step_goal_adult: 120 min × 100 steps/min
            // — RKC S215 "More than 2 hours per day", PDSA S219 "a minimum of two hours") — only
            // the legacy-profile fallback. Hunger / thirst decay, poops and water are the Border
            // Collie's (no per-breed number).
            [
                'breed_slug' => BreedType::Boxer->slug(),
                'daily_steps_required' => 12000,
                'hunger_decay_rate' => 12.0,
                'thirst_decay_rate' => 15.0,
                'poops_per_day' => 2,
                'feed_windows' => BreedConfig::DEFAULT_FEED_WINDOWS,
                'water_times_per_day' => 3,
                'water_min_gap_minutes' => 180,
                'water_refill_below' => 60,
                'premium_unlock' => true,
                'species' => 'dog',
                'sort_order' => 220,
                'label_key' => 'breeds.boxer',
                'search_keywords' => ['boxer', 'boksar', 'deutscher boxer'],
            ],
            // M5-R10-27 Miniature Schnauzer (docs/research/dog-data/data.json miniature_schnauzer,
            // sources S221–S228; runbook rules confirmed by David 2026-10-10: paid).
            // daily_steps_required = the adult step goal (proposed_game_parameters.miniature_schnauzer
            // .step_goal_adult: 60 min × 100 steps/min — RKC S223 "Up to 1 hour per day", PDSA S226
            // "around an hour of exercise daily") — only the legacy-profile fallback. Hunger / thirst
            // decay, poops and water are the Border Collie's (no per-breed number).
            [
                'breed_slug' => BreedType::MiniatureSchnauzer->slug(),
                'daily_steps_required' => 6000,
                'hunger_decay_rate' => 12.0,
                'thirst_decay_rate' => 15.0,
                'poops_per_day' => 2,
                'feed_windows' => BreedConfig::DEFAULT_FEED_WINDOWS,
                'water_times_per_day' => 3,
                'water_min_gap_minutes' => 180,
                'water_refill_below' => 60,
                'premium_unlock' => true,
                'species' => 'dog',
                'sort_order' => 230,
                'label_key' => 'breeds.miniature_schnauzer',
                'search_keywords' => ['miniature schnauzer', 'pritlikavi šnavcer', 'pritlikavi snavcer', 'mali šnavcer', 'zwergschnauzer', 'schnauzer', 'šnavcer', 'mini schnauzer'],
            ],
            // M5-R10-28 Shih Tzu (docs/research/dog-data/data.json shih_tzu, sources S229–S236;
            // runbook rules confirmed by David 2026-10-10: paid).
            // daily_steps_required = the adult step goal (proposed_game_parameters.shih_tzu
            // .step_goal_adult: 60 min × 100 steps/min — RKC S231 "Up to 1 hour per day", PDSA S233
            // "an hour exercise every day") — only the legacy-profile fallback. Hunger / thirst
            // decay, poops and water are the Border Collie's (no per-breed number).
            [
                'breed_slug' => BreedType::ShihTzu->slug(),
                'daily_steps_required' => 6000,
                'hunger_decay_rate' => 12.0,
                'thirst_decay_rate' => 15.0,
                'poops_per_day' => 2,
                'feed_windows' => BreedConfig::DEFAULT_FEED_WINDOWS,
                'water_times_per_day' => 3,
                'water_min_gap_minutes' => 180,
                'water_refill_below' => 60,
                'premium_unlock' => true,
                'species' => 'dog',
                'sort_order' => 240,
                'label_key' => 'breeds.shih_tzu',
                'search_keywords' => ['shih tzu', 'shih-tzu', 'shihtzu', 'ši-cu', 'šicu', 'ši cu', 'sicu'],
            ],
            // M5-R10-29 Great Dane (docs/research/dog-data/data.json great_dane, sources S239–S244;
            // runbook rules confirmed by David 2026-10-10: paid).
            // daily_steps_required = the adult step goal (proposed_game_parameters.great_dane
            // .step_goal_adult: 120 min × 100 steps/min — RKC S241 "More than 2 hours per day", PDSA
            // S243 "a minimum of two hours of exercise every day") — only the legacy-profile fallback.
            // Hunger / thirst decay, poops and water are the Border Collie's (no per-breed number).
            [
                'breed_slug' => BreedType::GreatDane->slug(),
                'daily_steps_required' => 12000,
                'hunger_decay_rate' => 12.0,
                'thirst_decay_rate' => 15.0,
                'poops_per_day' => 2,
                'feed_windows' => BreedConfig::DEFAULT_FEED_WINDOWS,
                'water_times_per_day' => 3,
                'water_min_gap_minutes' => 180,
                'water_refill_below' => 60,
                'premium_unlock' => true,
                'species' => 'dog',
                'sort_order' => 250,
                'label_key' => 'breeds.great_dane',
                'search_keywords' => ['great dane', 'nemška doga', 'nemska doga', 'doga', 'deutsche dogge', 'dogge', 'dane'],
            ],
            // M5-R10-30 Pomeranian (docs/research/dog-data/data.json pomeranian, sources S245–S252;
            // runbook rules confirmed by David 2026-10-10: paid).
            // daily_steps_required = the adult step goal (proposed_game_parameters.pomeranian
            // .step_goal_adult: 30 min × 100 steps/min — RKC S247 "Up to 30 minutes per day", PDSA
            // S250 "around half an hour of exercise per day") — only the legacy-profile fallback.
            // Hunger / thirst decay, poops and water are the Border Collie's (no per-breed number).
            [
                'breed_slug' => BreedType::Pomeranian->slug(),
                'daily_steps_required' => 3000,
                'hunger_decay_rate' => 12.0,
                'thirst_decay_rate' => 15.0,
                'poops_per_day' => 2,
                'feed_windows' => BreedConfig::DEFAULT_FEED_WINDOWS,
                'water_times_per_day' => 3,
                'water_min_gap_minutes' => 180,
                'water_refill_below' => 60,
                'premium_unlock' => true,
                'species' => 'dog',
                'sort_order' => 260,
                'label_key' => 'breeds.pomeranian',
                'search_keywords' => ['pomeranian', 'pomeranec', 'pom', 'nemški špic', 'nemski spic', 'german toy spitz', 'toy spitz'],
            ],
            // M5-R10-31 Boston Terrier (docs/research/dog-data/data.json boston_terrier, sources S253–S263;
            // runbook rules confirmed by David 2026-10-10: paid).
            // daily_steps_required = the adult step goal (proposed_game_parameters.boston_terrier
            // .step_goal_adult: 60 min × 100 steps/min — RKC S255 "Up to 1 hour per day", PDSA
            // S259 "up to an hour’s exercise per day") — only the legacy-profile fallback.
            // Hunger / thirst decay, poops and water are the Border Collie's (no per-breed number).
            [
                'breed_slug' => BreedType::BostonTerrier->slug(),
                'daily_steps_required' => 6000,
                'hunger_decay_rate' => 12.0,
                'thirst_decay_rate' => 15.0,
                'poops_per_day' => 2,
                'feed_windows' => BreedConfig::DEFAULT_FEED_WINDOWS,
                'water_times_per_day' => 3,
                'water_min_gap_minutes' => 180,
                'water_refill_below' => 60,
                'premium_unlock' => true,
                'species' => 'dog',
                'sort_order' => 270,
                'label_key' => 'breeds.boston_terrier',
                'search_keywords' => ['boston terrier', 'bostonski terier', 'boston', 'american gentleman'],
            ],
            // M5-R10-32 Miniature American Shepherd (docs/research/dog-data/data.json miniature_american_shepherd,
            // sources S264–S271; runbook rules confirmed by David 2026-10-10: paid).
            // daily_steps_required = the adult step goal (proposed_game_parameters.miniature_american_shepherd
            // .step_goal_adult: 60 min × 100 steps/min — PetMD S269 "at least one hour") — only the
            // legacy-profile fallback. Hunger / thirst decay, poops and water are the Border Collie's.
            [
                'breed_slug' => BreedType::MiniatureAmericanShepherd->slug(),
                'daily_steps_required' => 6000,
                'hunger_decay_rate' => 12.0,
                'thirst_decay_rate' => 15.0,
                'poops_per_day' => 2,
                'feed_windows' => BreedConfig::DEFAULT_FEED_WINDOWS,
                'water_times_per_day' => 3,
                'water_min_gap_minutes' => 180,
                'water_refill_below' => 60,
                'premium_unlock' => true,
                'species' => 'dog',
                'sort_order' => 280,
                'label_key' => 'breeds.miniature_american_shepherd',
                'search_keywords' => ['miniature american shepherd', 'mali ameriški ovčar', 'mali ameriski ovcar', 'mini american', 'mini aussie', 'miniature australian shepherd'],
            ],
            // M5-R10-33 Chihuahua (Smooth Coat; docs/research/dog-data/data.json chihuahua, sources
            // S272–S280; runbook rules confirmed by David 2026-10-10: paid).
            // daily_steps_required = the adult step goal (proposed_game_parameters.chihuahua
            // .step_goal_adult: 30 min × 100 steps/min — RKC S275 "Up to 30 minutes per day") — only the
            // legacy-profile fallback. Hunger / thirst decay, poops and water are the Border Collie's.
            [
                'breed_slug' => BreedType::Chihuahua->slug(),
                'daily_steps_required' => 3000,
                'hunger_decay_rate' => 12.0,
                'thirst_decay_rate' => 15.0,
                'poops_per_day' => 2,
                'feed_windows' => BreedConfig::DEFAULT_FEED_WINDOWS,
                'water_times_per_day' => 3,
                'water_min_gap_minutes' => 180,
                'water_refill_below' => 60,
                'premium_unlock' => true,
                'species' => 'dog',
                'sort_order' => 290,
                'label_key' => 'breeds.chihuahua',
                'search_keywords' => ['chihuahua', 'čivava', 'civava', 'chi'],
            ],
            // M5-R10-34 English Springer Spaniel (docs/research/dog-data/data.json english_springer_spaniel,
            // sources S281–S288; runbook rules confirmed by David 2026-10-10: paid).
            // daily_steps_required = the adult step goal (proposed_game_parameters.english_springer_spaniel
            // .step_goal_adult: 120 min × 100 steps/min — RKC S284 "More than 2 hours per day") — only the
            // legacy-profile fallback. Hunger / thirst decay, poops and water are the Border Collie's.
            [
                'breed_slug' => BreedType::EnglishSpringerSpaniel->slug(),
                'daily_steps_required' => 12000,
                'hunger_decay_rate' => 12.0,
                'thirst_decay_rate' => 15.0,
                'poops_per_day' => 2,
                'feed_windows' => BreedConfig::DEFAULT_FEED_WINDOWS,
                'water_times_per_day' => 3,
                'water_min_gap_minutes' => 180,
                'water_refill_below' => 60,
                'premium_unlock' => true,
                'species' => 'dog',
                'sort_order' => 300,
                'label_key' => 'breeds.english_springer_spaniel',
                'search_keywords' => ['english springer spaniel', 'angleški springer španjel', 'angleski springer spanjel', 'springer spaniel', 'springer španjel', 'springer'],
            ],
            // M5-R10-35 Shetland Sheepdog (docs/research/dog-data/data.json shetland_sheepdog,
            // sources S289–S295; runbook rules confirmed by David 2026-10-10: paid).
            // daily_steps_required = the adult step goal (proposed_game_parameters.shetland_sheepdog
            // .step_goal_adult: 60 min × 100 steps/min — RKC S291 "Up to 1 hour per day") — only the
            // legacy-profile fallback. Hunger / thirst decay, poops and water are the Border Collie's.
            [
                'breed_slug' => BreedType::ShetlandSheepdog->slug(),
                'daily_steps_required' => 6000,
                'hunger_decay_rate' => 12.0,
                'thirst_decay_rate' => 15.0,
                'poops_per_day' => 2,
                'feed_windows' => BreedConfig::DEFAULT_FEED_WINDOWS,
                'water_times_per_day' => 3,
                'water_min_gap_minutes' => 180,
                'water_refill_below' => 60,
                'premium_unlock' => true,
                'species' => 'dog',
                'sort_order' => 310,
                'label_key' => 'breeds.shetland_sheepdog',
                'search_keywords' => ['shetland sheepdog', 'šetlandski ovčar', 'setlandski ovcar', 'sheltie', 'šeltie', 'shetland'],
            ],
            // M5-R10-36 Brittany (docs/research/dog-data/data.json brittany,
            // sources S296–S302; runbook rules confirmed by David 2026-10-10: paid).
            // daily_steps_required = the adult step goal (proposed_game_parameters.brittany
            // .step_goal_adult: 120 min × 100 steps/min — RKC S298 "More than 2 hours per day") — only the
            // legacy-profile fallback. Hunger / thirst decay, poops and water are the Border Collie's.
            [
                'breed_slug' => BreedType::Brittany->slug(),
                'daily_steps_required' => 12000,
                'hunger_decay_rate' => 12.0,
                'thirst_decay_rate' => 15.0,
                'poops_per_day' => 2,
                'feed_windows' => BreedConfig::DEFAULT_FEED_WINDOWS,
                'water_times_per_day' => 3,
                'water_min_gap_minutes' => 180,
                'water_refill_below' => 60,
                'premium_unlock' => true,
                'species' => 'dog',
                'sort_order' => 320,
                'label_key' => 'breeds.brittany',
                'search_keywords' => ['brittany', 'bretonski ptičar', 'bretonski pticar', 'epagneul breton', 'bretonski španjel', 'brittany spaniel'],
            ],
            // M5-R10-37 American Cocker Spaniel (docs/research/dog-data/data.json american_cocker_spaniel,
            // sources S303–S311; runbook rules confirmed by David 2026-10-10: paid).
            // daily_steps_required = the adult step goal (proposed_game_parameters.american_cocker_spaniel
            // .step_goal_adult: 120 min × 100 steps/min — RKC S305 "More than 2 hours per day") — only the
            // legacy-profile fallback. Hunger / thirst decay, poops and water are the Border Collie's.
            [
                'breed_slug' => BreedType::AmericanCockerSpaniel->slug(),
                'daily_steps_required' => 12000,
                'hunger_decay_rate' => 12.0,
                'thirst_decay_rate' => 15.0,
                'poops_per_day' => 2,
                'feed_windows' => BreedConfig::DEFAULT_FEED_WINDOWS,
                'water_times_per_day' => 3,
                'water_min_gap_minutes' => 180,
                'water_refill_below' => 60,
                'premium_unlock' => true,
                'species' => 'dog',
                'sort_order' => 330,
                'label_key' => 'breeds.american_cocker_spaniel',
                'search_keywords' => ['american cocker spaniel', 'ameriški koker španjel', 'ameriski koker spanjel', 'american cocker', 'ameriški koker', 'ameriski koker'],
            ],
            // M5-R10-38 Belgian Malinois (docs/research/dog-data/data.json belgian_malinois,
            // sources S312–S318; runbook rules confirmed by David 2026-10-10: paid).
            // daily_steps_required = the adult step goal (proposed_game_parameters.belgian_malinois
            // .step_goal_adult: 120 min × 100 steps/min — RKC S314 "More than 2 hours per day") — only the
            // legacy-profile fallback. Hunger / thirst decay, poops and water are the Border Collie's.
            [
                'breed_slug' => BreedType::BelgianMalinois->slug(),
                'daily_steps_required' => 12000,
                'hunger_decay_rate' => 12.0,
                'thirst_decay_rate' => 15.0,
                'poops_per_day' => 2,
                'feed_windows' => BreedConfig::DEFAULT_FEED_WINDOWS,
                'water_times_per_day' => 3,
                'water_min_gap_minutes' => 180,
                'water_refill_below' => 60,
                'premium_unlock' => true,
                'species' => 'dog',
                'sort_order' => 340,
                'label_key' => 'breeds.belgian_malinois',
                'search_keywords' => ['belgian malinois', 'belgijski ovčar malinois', 'belgijski ovcar malinois', 'malinois', 'belgian shepherd malinois', 'belgian shepherd dog malinois'],
            ],
            // M5-R10-39 Basset Hound (docs/research/dog-data/data.json basset_hound,
            // sources S319–S325; runbook rules confirmed by David 2026-10-10: paid).
            // daily_steps_required = the adult step goal (proposed_game_parameters.basset_hound
            // .step_goal_adult: 60 min × 100 steps/min — RKC S321 "Up to 1 hour per day") — only the
            // legacy-profile fallback. Hunger / thirst decay, poops and water are the Border Collie's.
            [
                'breed_slug' => BreedType::BassetHound->slug(),
                'daily_steps_required' => 6000,
                'hunger_decay_rate' => 12.0,
                'thirst_decay_rate' => 15.0,
                'poops_per_day' => 2,
                'feed_windows' => BreedConfig::DEFAULT_FEED_WINDOWS,
                'water_times_per_day' => 3,
                'water_min_gap_minutes' => 180,
                'water_refill_below' => 60,
                'premium_unlock' => true,
                'species' => 'dog',
                'sort_order' => 350,
                'label_key' => 'breeds.basset_hound',
                'search_keywords' => ['basset hound', 'baset', 'basset', 'baset hound', 'baset haund', 'bassett'],
            ],
            // M5-R10-40 Hungarian Vizsla (docs/research/dog-data/data.json hungarian_vizsla,
            // sources S326–S334; runbook rules confirmed by David 2026-10-10: paid).
            // daily_steps_required = the adult step goal (proposed_game_parameters.hungarian_vizsla
            // .step_goal_adult: 120 min × 100 steps/min — RKC S328 "More than 2 hours per day") — only the
            // legacy-profile fallback. Hunger / thirst decay, poops and water are the Border Collie's.
            [
                'breed_slug' => BreedType::HungarianVizsla->slug(),
                'daily_steps_required' => 12000,
                'hunger_decay_rate' => 12.0,
                'thirst_decay_rate' => 15.0,
                'poops_per_day' => 2,
                'feed_windows' => BreedConfig::DEFAULT_FEED_WINDOWS,
                'water_times_per_day' => 3,
                'water_min_gap_minutes' => 180,
                'water_refill_below' => 60,
                'premium_unlock' => true,
                'species' => 'dog',
                'sort_order' => 360,
                'label_key' => 'breeds.hungarian_vizsla',
                'search_keywords' => ['hungarian vizsla', 'vizsla', 'madžarski kratkodlaki ptičar', 'madzarski kratkodlaki pticar', 'vižla', 'vizla', 'madžarska vižla', 'madzarska vizla', 'magyar vizsla', 'hungarian pointer'],
            ],
            // M5-R10-41 English Cocker Spaniel (docs/research/dog-data/data.json english_cocker_spaniel,
            // sources S335–S344; runbook rules confirmed by David 2026-10-10: paid).
            // daily_steps_required = the adult step goal (proposed_game_parameters.english_cocker_spaniel
            // .step_goal_adult: 60 min × 100 steps/min — RKC S337 "Up to 1 hour per day") — only the
            // legacy-profile fallback. Hunger / thirst decay, poops and water are the Border Collie's.
            [
                'breed_slug' => BreedType::EnglishCockerSpaniel->slug(),
                'daily_steps_required' => 6000,
                'hunger_decay_rate' => 12.0,
                'thirst_decay_rate' => 15.0,
                'poops_per_day' => 2,
                'feed_windows' => BreedConfig::DEFAULT_FEED_WINDOWS,
                'water_times_per_day' => 3,
                'water_min_gap_minutes' => 180,
                'water_refill_below' => 60,
                'premium_unlock' => true,
                'species' => 'dog',
                'sort_order' => 370,
                'label_key' => 'breeds.english_cocker_spaniel',
                'search_keywords' => ['english cocker spaniel', 'angleški koker španjel', 'angleski koker spanjel', 'english cocker', 'angleški koker', 'angleski koker', 'cocker spaniel', 'koker španjel', 'koker spanjel', 'merry cocker'],
            ],
            // M5-R10-42 Maltese (docs/research/dog-data/data.json maltese, sources S345–S352;
            // runbook rules confirmed by David 2026-10-10: paid).
            // daily_steps_required = the adult step goal (proposed_game_parameters.maltese
            // .step_goal_adult: 30 min × 100 steps/min — RKC S347 "Up to 30 minutes per day") — only the
            // legacy-profile fallback. Hunger / thirst decay, poops and water are the Border Collie's.
            [
                'breed_slug' => BreedType::Maltese->slug(),
                'daily_steps_required' => 3000,
                'hunger_decay_rate' => 12.0,
                'thirst_decay_rate' => 15.0,
                'poops_per_day' => 2,
                'feed_windows' => BreedConfig::DEFAULT_FEED_WINDOWS,
                'water_times_per_day' => 3,
                'water_min_gap_minutes' => 180,
                'water_refill_below' => 60,
                'premium_unlock' => true,
                'species' => 'dog',
                'sort_order' => 380,
                'label_key' => 'breeds.maltese',
                'search_keywords' => ['maltese', 'maltežan', 'maltezan', 'malteški bišon', 'malteski bison', 'malteški psiček', 'malteski psicek', 'bichon maltais', 'maltese terrier'],
            ],
            // M5-R10-43 Pug (docs/research/dog-data/data.json pug, sources S353–S362;
            // runbook rules confirmed by David 2026-10-10: paid).
            // daily_steps_required = the adult step goal (proposed_game_parameters.pug
            // .step_goal_adult: 60 min × 100 steps/min — RKC S355 "Up to 1 hour per day") — only the
            // legacy-profile fallback. Hunger / thirst decay, poops and water are the Border Collie's.
            [
                'breed_slug' => BreedType::Pug->slug(),
                'daily_steps_required' => 6000,
                'hunger_decay_rate' => 12.0,
                'thirst_decay_rate' => 15.0,
                'poops_per_day' => 2,
                'feed_windows' => BreedConfig::DEFAULT_FEED_WINDOWS,
                'water_times_per_day' => 3,
                'water_min_gap_minutes' => 180,
                'water_refill_below' => 60,
                'premium_unlock' => true,
                'species' => 'dog',
                'sort_order' => 390,
                'label_key' => 'breeds.pug',
                'search_keywords' => ['pug', 'mops', 'mopsek', 'pug dog', 'carlin'],
            ],
            // M5-R10-44 Rough Collie (docs/research/dog-data/data.json rough_collie, sources S363–S369;
            // runbook rules confirmed by David 2026-10-10: paid).
            // daily_steps_required = the adult step goal (proposed_game_parameters.rough_collie
            // .step_goal_adult: 60 min × 100 steps/min — RKC S365 "Up to 1 hour per day") — only the
            // legacy-profile fallback. Hunger / thirst decay, poops and water are the Border Collie's.
            [
                'breed_slug' => BreedType::RoughCollie->slug(),
                'daily_steps_required' => 6000,
                'hunger_decay_rate' => 12.0,
                'thirst_decay_rate' => 15.0,
                'poops_per_day' => 2,
                'feed_windows' => BreedConfig::DEFAULT_FEED_WINDOWS,
                'water_times_per_day' => 3,
                'water_min_gap_minutes' => 180,
                'water_refill_below' => 60,
                'premium_unlock' => true,
                'species' => 'dog',
                'sort_order' => 400,
                'label_key' => 'breeds.rough_collie',
                'search_keywords' => ['rough collie', 'collie', 'dolgodlaki škotski ovčar', 'dolgodlaki skotski ovcar', 'škotski ovčar', 'skotski ovcar', 'long-haired collie', 'collie rough'],
            ],
            // M5-R10-45 Mastiff (docs/research/dog-data/data.json mastiff, sources S370–S376;
            // runbook rules confirmed by David 2026-10-10: paid).
            // daily_steps_required = the adult step goal (proposed_game_parameters.mastiff
            // .step_goal_adult: 60 min × 100 steps/min — PDSA S370 "Minimum exercise (per day) | 1 hour") — only the
            // legacy-profile fallback. Hunger / thirst decay, poops and water are the Border Collie's.
            [
                'breed_slug' => BreedType::Mastiff->slug(),
                'daily_steps_required' => 6000,
                'hunger_decay_rate' => 12.0,
                'thirst_decay_rate' => 15.0,
                'poops_per_day' => 2,
                'feed_windows' => BreedConfig::DEFAULT_FEED_WINDOWS,
                'water_times_per_day' => 3,
                'water_min_gap_minutes' => 180,
                'water_refill_below' => 60,
                'premium_unlock' => true,
                'species' => 'dog',
                'sort_order' => 410,
                'label_key' => 'breeds.mastiff',
                'search_keywords' => ['mastiff', 'english mastiff', 'old english mastiff', 'angleški mastif', 'angleski mastif', 'mastif'],
            ],
            // M5-R10-46 Shiba Inu (docs/research/dog-data/data.json shiba_inu, sources S377–S383;
            // runbook rules confirmed by David 2026-10-10: paid).
            // daily_steps_required = the adult step goal (proposed_game_parameters.shiba_inu
            // .step_goal_adult: 60 min × 100 steps/min — RKC S378 "Exercise: Up to 1 hour per day") — only the
            // legacy-profile fallback. Hunger / thirst decay, poops and water are the Border Collie's.
            [
                'breed_slug' => BreedType::ShibaInu->slug(),
                'daily_steps_required' => 6000,
                'hunger_decay_rate' => 12.0,
                'thirst_decay_rate' => 15.0,
                'poops_per_day' => 2,
                'feed_windows' => BreedConfig::DEFAULT_FEED_WINDOWS,
                'water_times_per_day' => 3,
                'water_min_gap_minutes' => 180,
                'water_refill_below' => 60,
                'premium_unlock' => true,
                'species' => 'dog',
                'sort_order' => 420,
                'label_key' => 'breeds.shiba_inu',
                'search_keywords' => ['shiba inu', 'shiba', 'japanese shiba inu', 'šiba inu', 'šiba', 'siba inu', 'siba', 'japonski šiba inu', 'japonski siba inu'],
            ],
            // M5-R10-47 Rhodesian Ridgeback (docs/research/dog-data/data.json rhodesian_ridgeback, sources
            // S384–S394; runbook rules confirmed by David 2026-10-10: paid).
            // daily_steps_required = the adult step goal (proposed_game_parameters.rhodesian_ridgeback
            // .step_goal_adult: 120 min × 100 steps/min — RKC S387 "Exercise: More than 2 hours per day") — only
            // the legacy-profile fallback. Hunger / thirst decay, poops and water are the Border Collie's.
            [
                'breed_slug' => BreedType::RhodesianRidgeback->slug(),
                'daily_steps_required' => 12000,
                'hunger_decay_rate' => 12.0,
                'thirst_decay_rate' => 15.0,
                'poops_per_day' => 2,
                'feed_windows' => BreedConfig::DEFAULT_FEED_WINDOWS,
                'water_times_per_day' => 3,
                'water_min_gap_minutes' => 180,
                'water_refill_below' => 60,
                'premium_unlock' => true,
                'species' => 'dog',
                'sort_order' => 430,
                'label_key' => 'breeds.rhodesian_ridgeback',
                'search_keywords' => ['rhodesian ridgeback', 'ridgeback', 'african lion dog', 'rodezijski ridgeback', 'rodezijski'],
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
                'water_refill_below' => 60,
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
                'water_refill_below' => 60,
                'premium_unlock' => true,
                'species' => 'cat',
                'sort_order' => 10,
                'label_key' => 'breeds.maine_coon',
                'search_keywords' => ['maine coon', 'mejnkun', 'mainska'],
            ],
            [
                // M5-R10-14 (cat-data british_shorthair.identity.decision, runbook rule): paid, after the Maine Coon.
                'breed_slug' => BreedType::BritishShorthair->slug(),
                'daily_steps_required' => 0,
                'hunger_decay_rate' => 8.0,
                'thirst_decay_rate' => 8.0,
                'poops_per_day' => 0,
                'feed_windows' => self::CAT_FEED_WINDOWS,
                'water_times_per_day' => 2,
                'water_min_gap_minutes' => 240,
                'water_refill_below' => 60,
                'premium_unlock' => true,
                'species' => 'cat',
                'sort_order' => 20,
                'label_key' => 'breeds.british_shorthair',
                'search_keywords' => ['british shorthair', 'britanska kratkodlaka mačka', 'britanska kratkodlaka macka', 'britanska kratkodlaka', 'britanka', 'british blue', 'bsh'],
            ],
            [
                // M5-R10-15 (cat-data ragdoll.identity.decision, runbook rule): paid, after the British Shorthair.
                'breed_slug' => BreedType::Ragdoll->slug(),
                'daily_steps_required' => 0,
                'hunger_decay_rate' => 8.0,
                'thirst_decay_rate' => 8.0,
                'poops_per_day' => 0,
                'feed_windows' => self::CAT_FEED_WINDOWS,
                'water_times_per_day' => 2,
                'water_min_gap_minutes' => 240,
                'water_refill_below' => 60,
                'premium_unlock' => true,
                'species' => 'cat',
                'sort_order' => 30,
                'label_key' => 'breeds.ragdoll',
                'search_keywords' => ['ragdoll', 'ragdoll mačka', 'ragdoll macka', 'ragdol', 'rag doll'],
            ],
            [
                // M5-R10-16 (cat-data siberian_cat.identity.decision, runbook rule): paid, after the Ragdoll.
                'breed_slug' => BreedType::SiberianCat->slug(),
                'daily_steps_required' => 0,
                'hunger_decay_rate' => 8.0,
                'thirst_decay_rate' => 8.0,
                'poops_per_day' => 0,
                'feed_windows' => self::CAT_FEED_WINDOWS,
                'water_times_per_day' => 2,
                'water_min_gap_minutes' => 240,
                'water_refill_below' => 60,
                'premium_unlock' => true,
                'species' => 'cat',
                'sort_order' => 40,
                'label_key' => 'breeds.siberian_cat',
                'search_keywords' => ['siberian', 'siberian cat', 'sibirska mačka', 'sibirska macka', 'sibirka'],
            ],
            [
                // M5-R10-17 (cat-data norwegian_forest_cat.identity.decision, runbook rule): paid, after the Siberian.
                'breed_slug' => BreedType::NorwegianForestCat->slug(),
                'daily_steps_required' => 0,
                'hunger_decay_rate' => 8.0,
                'thirst_decay_rate' => 8.0,
                'poops_per_day' => 0,
                'feed_windows' => self::CAT_FEED_WINDOWS,
                'water_times_per_day' => 2,
                'water_min_gap_minutes' => 240,
                'water_refill_below' => 60,
                'premium_unlock' => true,
                'species' => 'cat',
                'sort_order' => 50,
                'label_key' => 'breeds.norwegian_forest_cat',
                'search_keywords' => ['norwegian forest cat', 'norwegian forest', 'wegie', 'skogkatt', 'norveška gozdna mačka', 'norveska gozdna macka', 'norveška mačka', 'gozdna mačka'],
            ],
            [
                // M5-R10-18 (cat-data bengal.identity.decision, runbook rule): paid, after the Norwegian Forest Cat.
                'breed_slug' => BreedType::Bengal->slug(),
                'daily_steps_required' => 0,
                'hunger_decay_rate' => 8.0,
                'thirst_decay_rate' => 8.0,
                'poops_per_day' => 0,
                'feed_windows' => self::CAT_FEED_WINDOWS,
                'water_times_per_day' => 2,
                'water_min_gap_minutes' => 240,
                'water_refill_below' => 60,
                'premium_unlock' => true,
                'species' => 'cat',
                'sort_order' => 60,
                'label_key' => 'breeds.bengal',
                'search_keywords' => ['bengal', 'bengal cat', 'bengalska mačka', 'bengalska macka', 'bengalka'],
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

        $rows = [];
        foreach (self::configs() as $config) {
            $config['feed_windows'] = json_encode($config['feed_windows']);
            $config['search_keywords'] = json_encode($config['search_keywords'], JSON_UNESCAPED_UNICODE);
            $rows[] = array_merge($config, ['created_at' => $now, 'updated_at' => $now]);
        }

        // One multi-row INSERT … ON CONFLICT DO NOTHING (every config has the same
        // columns) instead of one query per breed — same insert-only semantics.
        DB::table('breed_configs')->insertOrIgnore($rows);

        // Raw inserts fire no model events (M5-R06-01 free / paid catalogue cache).
        BreedCatalogService::forget();
    }
}
