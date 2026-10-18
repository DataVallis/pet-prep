<?php

namespace Database\Seeders;

use App\Enums\BreedType;
use App\Enums\LifeStage;
use App\Enums\StageParamKey;
use App\Services\LifeStageService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Sourced life-stage data (M5-R01, David 2026-10-05) → `breed_stage_params`.
 *
 * Every value comes from docs/research/dog-data/data.json (`ref` = JSON path)
 * with the source id of docs/research/dog-data/sources.md. Rules:
 *  - `verified` = true only when the value is directly backed by the cited
 *    source or is a recorded David decision (`decision`); every value the
 *    research marked "UNSOURCED — proposal" without a decision stays
 *    `verified = false` and is shown as such in Filament.
 *  - David confirmed every open M5-R01 choice on 2026-10-05 (`CONFIRMED`,
 *    "potrdil David 2026-10-05"): feed window clock times (now 2 h each),
 *    stage boundaries 9 / 36 / 108–118 months, arrival ages (puppy 2 months,
 *    others the first month of the stage), exercise minutes (mutt adult 60,
 *    puppy / young 10 × age months, senior 75 % of adult) and senior 2 meals.
 *    Those rows are `verified = true` with the decision in `notes`; their
 *    `source_id` stays the underlying evidence (null where literature gives
 *    no number) — the decision is never presented as literature.
 *    Production rows from PR #37 were updated once by the data migration
 *    2026_10_12_120000_apply_david_stage_decisions (the seeder never updates).
 *  - INSERT-ONLY (like BreedConfigsSeeder, DECISIONS 2026-10-03): an
 *    existing (breed, stage, from month, key) row is never touched, so admin
 *    edits in Filament always win. Runs on every production deploy through
 *    BreedConfigsSeeder::run(). A breed missing in breed_configs is skipped.
 *  - NEVER RESURRECTS: a tuple an admin ever touched — it has a row in
 *    `breed_stage_param_changes` (created / updated / deleted; for an update
 *    that re-keyed the row also the ORIGINAL tuple, rebuilt from `old`) — is
 *    skipped, so a deleted or re-keyed value does not come back on the next
 *    deploy.
 *
 * tests/Feature/LifeStageDataTest cross-checks every row against data.json.
 *
 * DOG BREEDS (M5-R10): rows() loops DOG_BREEDS; the shared general_by_size
 * rows are written once in rows(), the breed-specific values (stage
 * boundaries, arrival ages, exercise minutes, weight, growth, Coren rank,
 * learning multiplier, individual variation, lifespan) come from
 * dogProfile(). A new dog breed = an enum case in DOG_BREEDS + a profile;
 * the mutt / Border Collie rows stay byte-identical (LabradorBreedTest /
 * GoldenRetrieverBreedTest / FrenchBulldogBreedTest / GermanShepherdBreedTest /
 * CavalierKingCharlesSpanielBreedTest / BeagleBreedTest / StandardPoodleBreedTest /
 * DachshundBreedTest / AustralianShepherdBreedTest / HavaneseBreedTest /
 * WestHighlandWhiteTerrierBreedTest / BerneseMountainDogBreedTest / SiberianHuskyBreedTest /
 * RottweilerBreedTest / BulldogBreedTest / GermanShorthairedPointerBreedTest / YorkshireTerrierBreedTest / CaneCorsoBreedTest / PembrokeWelshCorgiBreedTest / DobermannBreedTest / BoxerBreedTest / MiniatureSchnauzerBreedTest / ShihTzuBreedTest / GreatDaneBreedTest / PomeranianBreedTest / BostonTerrierBreedTest / MiniatureAmericanShepherdBreedTest / ChihuahuaBreedTest / EnglishSpringerSpanielBreedTest / ShetlandSheepdogBreedTest / BrittanyBreedTest / AmericanCockerSpanielBreedTest / BelgianMalinoisBreedTest / BassetHoundBreedTest / HungarianVizslaBreedTest / EnglishCockerSpanielBreedTest / MalteseBreedTest / PugBreedTest cross-check the Labrador /
 * Golden / French Bulldog / German Shepherd / Cavalier / Beagle / Standard Poodle / Dachshund /
 * Australian Shepherd / Havanese / West Highland White Terrier / Bernese Mountain Dog / Siberian Husky / Rottweiler / Bulldog / German Shorthaired Pointer / Yorkshire Terrier / Cane Corso / Pembroke Welsh Corgi / Dobermann / Boxer / Miniature Schnauzer / Shih Tzu / Great Dane / Pomeranian / Boston Terrier / Miniature American Shepherd / Chihuahua / English Springer Spaniel / Shetland Sheepdog / Brittany / American Cocker Spaniel / Belgian Malinois / Basset Hound / Hungarian Vizsla / English Cocker Spaniel / Maltese / Pug rows
 * against data.json).
 *
 * CATS (M5-R06-03, M5-R06_PLAN T10): a separate rowset, catRows(), from
 * docs/research/cat-data/data.json (`ref` = "cat-data:" + JSON path, source
 * ids C1–C25 of docs/research/cat-data/sources.md). rows() stays the dog
 * rowset exactly as before; run() seeds allRows(). The existing stage values
 * are reused (puppy = kitten, young = young adult, adult = mature adult,
 * senior = senior — CAT_SPEC §2).
 *
 * Feed window times (David 2026-10-05): N meals → the first window at
 * 07:00, the last at 19:00, equal spacing (12 h / (N − 1)), each window
 * 2 hours long: 4 meals = 07–09, 11–13, 15–17, 19–21; 3 meals = 07–09,
 * 13–15, 19–21. Two meals have no row → the breed's existing
 * breed_configs.feed_windows (06–10, 17–21) stay in force.
 */
class BreedStageParamsSeeder extends Seeder
{
    public const DECISION = 'David 2026-10-05';

    /** David's answers to the M5-R01 open questions (2026-10-05). */
    public const CONFIRMED = 'potrdil David 2026-10-05';

    /** David's M5-R02 decisions (behaviour events, 2026-10-06). */
    public const CONFIRMED_R02 = 'potrdil David 2026-10-06';

    /**
     * David's M5-R03 decisions (training, 2026-10-06): Border Collie 2×, mixed
     * breed baseline ±20 %; M5-R03b: 5 min a day, +1 per praise, −2 per missed
     * day (production rows flipped by 2026_10_15_120000_apply_david_training_decisions),
     * starting progress per arrival stage.
     */
    public const CONFIRMED_R03 = 'potrdil David 2026-10-06';

    /**
     * David's M5-R03b answers (2026-10-07): the two training effects (potty
     * 0.75, place 0.5) — production rows flipped by
     * 2026_10_16_120000_apply_david_training_effect_decisions.
     */
    public const CONFIRMED_R03_EFFECTS = 'potrdil David 2026-10-07';

    /** M5-R03b: starting progress of a puppy (also bought) — David 2026-10-06. */
    public const PUPPY_STARTING_PROGRESS = ['sit' => 0, 'come' => 0, 'place' => 0, 'potty' => 0];

    /** M5-R03b: starting progress of a dog arriving young, adult or senior — David 2026-10-06. */
    public const GROWN_STARTING_PROGRESS = ['sit' => 50, 'come' => 30, 'place' => 0, 'potty' => 70];

    /** CAT_SPEC Q1–Q10, David 2026-10-08 13:47 (data.json `decision`). */
    public const CONFIRMED_CAT = 'potrdil David 2026-10-08 13:47';

    /** David's later answers on the M5-R06 plan, 2026-10-08 (data.json `decision`). */
    public const CONFIRMED_CAT_PLAN = 'potrdil David 2026-10-08 (načrt M5-R06)';

    /** David's answers for the cat play rules (M5-R06-04), 2026-10-08 ~20:40 (data.json `decision`). */
    public const CONFIRMED_CAT_PLAY = 'potrdil David 2026-10-08 20:40';

    /**
     * David's M5-R10 decisions for the Labrador Retriever (2026-10-09,
     * data.json proposed_game_parameters.labrador_retriever.*.decision):
     * exercise 90 min, senior 68 min (75 % of 90 rounded up, 6,800 steps),
     * stages 9 / 36 / 118, arrival 2 / 9 / 36 / 118, learning multiplier 1.8;
     * hunger / thirst / poops / water the same as the Border Collie.
     * M5-R10-02 Golden Retriever (same day, ~22:40, data.json
     * proposed_game_parameters.golden_retriever.*.decision): exercise 120 min,
     * senior 90 min, stages 9 / 36 / 119, arrival 2 / 9 / 36 / 119, learning
     * multiplier 1.9; care rates the Border Collie's.
     */
    public const CONFIRMED_R10 = 'potrdil David 2026-10-09';

    /**
     * M5-R10-03 French Bulldog (David 2026-10-10 ~06:20, data.json
     * proposed_game_parameters.french_bulldog.*.decision): exercise 60 min,
     * senior 45 min, puppy 10 min × age capped at 60, stages 9 / 36 / 88,
     * arrival 2 / 9 / 36 / 88, learning multiplier 0.7; care rates the
     * Border Collie's.
     */
    public const CONFIRMED_R10_FRENCH_BULLDOG = 'potrdil David 2026-10-10';

    /**
     * M5-R10-04 German Shepherd Dog (unattended run 2026-10-10, data.json
     * proposed_game_parameters.german_shepherd.*.decision): every value follows
     * a standing rule of docs/engineering/ADD_BREED_RUNBOOK.md §3, which David
     * confirmed on 2026-10-10 — exercise 120 min, senior 90 min, puppy 10 min ×
     * age capped at 120, stages 9 / 36 / 93, arrival 2 / 9 / 36 / 93, learning
     * multiplier 1.9; care rates the Border Collie's.
     */
    public const CONFIRMED_R10_GERMAN_SHEPHERD = 'potrdil David 2026-10-10 (pravilo runbooka)';

    /**
     * M5-R10-05 Cavalier King Charles Spaniel (unattended run 2026-10-10, data.json
     * proposed_game_parameters.cavalier_king_charles_spaniel.*.decision): every value
     * follows a standing rule of docs/engineering/ADD_BREED_RUNBOOK.md §3, which David
     * confirmed on 2026-10-10 — exercise 60 min, senior 45 min, puppy 10 min × age
     * capped at 60, stages 9 / 36 / 90, arrival 2 / 9 / 36 / 90, learning multiplier
     * 1.0; care rates the Border Collie's. The suffix keeps the value distinct from
     * CONFIRMED_R10_GERMAN_SHEPHERD, so each breed's decision rows stay identifiable.
     */
    public const CONFIRMED_R10_CAVALIER = 'potrdil David 2026-10-10 (pravilo runbooka, M5-R10-05)';

    /**
     * M5-R10-06 Beagle (unattended run 2026-10-10, data.json
     * proposed_game_parameters.beagle.*.decision): every value follows a standing rule
     * of docs/engineering/ADD_BREED_RUNBOOK.md §3, which David confirmed on 2026-10-10 —
     * exercise 60 min, senior 45 min, puppy 10 min × age capped at 60, stages
     * 9 / 36 / 102, arrival 2 / 9 / 36 / 102, learning multiplier 0.5; care rates the
     * Border Collie's. The suffix keeps each breed's decision rows identifiable.
     */
    public const CONFIRMED_R10_BEAGLE = 'potrdil David 2026-10-10 (pravilo runbooka, M5-R10-06)';

    /**
     * M5-R10-07 Standard Poodle (unattended run 2026-10-10, data.json
     * proposed_game_parameters.standard_poodle.*.decision): every value follows a standing
     * rule of docs/engineering/ADD_BREED_RUNBOOK.md §3, which David confirmed on 2026-10-10 —
     * exercise 60 min, senior 45 min, puppy 10 min × age capped at 60, stages 9 / 36 / 108,
     * arrival 2 / 9 / 36 / 108, learning multiplier 2.0; care rates the Border Collie's.
     */
    public const CONFIRMED_R10_STANDARD_POODLE = 'potrdil David 2026-10-10 (pravilo runbooka, M5-R10-07)';

    /**
     * M5-R10-08 Dachshund, standard size (unattended run 2026-10-10, data.json
     * proposed_game_parameters.dachshund.*.decision): every value follows a standing rule of
     * docs/engineering/ADD_BREED_RUNBOOK.md §3, which David confirmed on 2026-10-10 —
     * exercise 60 min, senior 45 min, puppy 10 min × age capped at 60, stages 9 / 36 / 108,
     * arrival 2 / 9 / 36 / 108, learning multiplier 1.0; care rates the Border Collie's.
     */
    public const CONFIRMED_R10_DACHSHUND = 'potrdil David 2026-10-10 (pravilo runbooka, M5-R10-08)';

    /**
     * M5-R10-09 Australian Shepherd (unattended run 2026-10-10, data.json
     * proposed_game_parameters.australian_shepherd.*.decision): every value follows a standing rule
     * of docs/engineering/ADD_BREED_RUNBOOK.md §3, which David confirmed on 2026-10-10 —
     * exercise 120 min, senior 90 min, puppy 10 min × age capped at 120, stages 9 / 36 / 90,
     * arrival 2 / 9 / 36 / 90, learning multiplier 1.0; care rates the Border Collie's.
     */
    public const CONFIRMED_R10_AUSTRALIAN_SHEPHERD = 'potrdil David 2026-10-10 (pravilo runbooka, M5-R10-09)';

    /**
     * M5-R10-10 Havanese (unattended run 2026-10-10, data.json
     * proposed_game_parameters.havanese.*.decision): every value follows a standing rule
     * of docs/engineering/ADD_BREED_RUNBOOK.md §3, which David confirmed on 2026-10-10 —
     * exercise 30 min, senior 23 min, puppy 10 min × age capped at 30, stages 9 / 36 / 108,
     * arrival 2 / 9 / 36 / 108, learning multiplier 1.0 (unranked); care rates the Border Collie's.
     */
    public const CONFIRMED_R10_HAVANESE = 'potrdil David 2026-10-10 (pravilo runbooka, M5-R10-10)';

    /**
     * M5-R10-11 West Highland White Terrier (unattended run 2026-10-10, data.json
     * proposed_game_parameters.west_highland_white_terrier.*.decision): every value follows a
     * standing rule of docs/engineering/ADD_BREED_RUNBOOK.md §3, which David confirmed on
     * 2026-10-10 — exercise 60 min, senior 45 min, puppy 10 min × age capped at 60, stages
     * 9 / 36 / 121, arrival 2 / 9 / 36 / 121, learning multiplier 1.0 (Coren 47, Average);
     * care rates the Border Collie's.
     */
    public const CONFIRMED_R10_WEST_HIGHLAND_WHITE_TERRIER = 'potrdil David 2026-10-10 (pravilo runbooka, M5-R10-11)';

    /**
     * M5-R10-12 Bernese Mountain Dog (unattended run 2026-10-10, data.json
     * proposed_game_parameters.bernese_mountain_dog.*.decision): every value follows a
     * standing rule of docs/engineering/ADD_BREED_RUNBOOK.md §3, which David confirmed on
     * 2026-10-10 — exercise 60 min, senior 45 min, puppy 10 min × age capped at 60, stages
     * young 9 / adult 36, arrival 2 / 9 / 36, learning multiplier 1.5 (Coren 22, Excellent);
     * care rates the Border Collie's. The senior boundary uses the closest rule row (a
     * peer-reviewed breed median, S156, in place of a VetCompass median) and is NOT covered
     * by the confirmation: see PROPOSED_R10_BERNESE_SENIOR.
     */
    public const CONFIRMED_R10_BERNESE_MOUNTAIN_DOG = 'potrdil David 2026-10-10 (pravilo runbooka, M5-R10-12)';

    /**
     * M5-R10-12 Bernese Mountain Dog senior boundary / senior arrival (76 months): Claude's
     * suggestion from the closest rule row (0.75 × Swiss breed median 8.4 y, S156) — no
     * runbook rule produced a value, so the rows are verified=false until David decides
     * (data.json proposed_game_parameters.bernese_mountain_dog.senior_boundary_months).
     */
    public const PROPOSED_R10_BERNESE_SENIOR = 'predlog Claude 2026-10-10 (najbližja vrstica pravila runbooka, M5-R10-12) — čaka Davida';

    /**
     * M5-R10-13 Siberian Husky (unattended run 2026-10-10, data.json
     * proposed_game_parameters.siberian_husky.*.decision): every value follows a standing rule
     * of docs/engineering/ADD_BREED_RUNBOOK.md §3, which David confirmed on 2026-10-10 —
     * exercise 120 min, senior 90 min, puppy 10 min × age capped at 120, stages 9 / 36 / 90
     * (senior from the RKC lower bound "Over 10 years"), arrival 2 / 9 / 36 / 90, learning
     * multiplier 1.0 (Coren 45, Average); care rates the Border Collie's.
     */
    public const CONFIRMED_R10_SIBERIAN_HUSKY = 'potrdil David 2026-10-10 (pravilo runbooka, M5-R10-13)';

    /**
     * M5-R10-19 Rottweiler (unattended run 2026-10-11, data.json
     * proposed_game_parameters.rottweiler.*.decision): every value follows a standing rule
     * of docs/engineering/ADD_BREED_RUNBOOK.md §3, which David confirmed on 2026-10-10 —
     * exercise 120 min, senior 90 min, puppy 10 min × age capped at 120, stages 9 / 36 / 81
     * (senior from the VetCompass median 9.0 y, S171), arrival 2 / 9 / 36 / 81, learning
     * multiplier 1.8 (Coren 9, Brightest); care rates the Border Collie's.
     */
    public const CONFIRMED_R10_ROTTWEILER = 'potrdil David 2026-10-10 (pravilo runbooka, M5-R10-19)';

    /**
     * M5-R10-20 Bulldog (unattended run 2026-10-11, data.json
     * proposed_game_parameters.bulldog.*.decision): every value follows a standing rule
     * of docs/engineering/ADD_BREED_RUNBOOK.md §3, which David confirmed on 2026-10-10 —
     * exercise 60 min, senior 45 min, puppy 10 min × age capped at 60, stages 9 / 36 / 88
     * (senior from the McMillan 2024 median 9.8 y, S54), arrival 2 / 9 / 36 / 88, learning
     * multiplier 0.5 (Coren 77, Lowest); care rates the Border Collie's.
     */
    public const CONFIRMED_R10_BULLDOG = 'potrdil David 2026-10-10 (pravilo runbooka, M5-R10-20)';

    /**
     * M5-R10-21 German Shorthaired Pointer (unattended run 2026-10-11, data.json
     * proposed_game_parameters.german_shorthaired_pointer.*.decision): every value follows a
     * standing rule of docs/engineering/ADD_BREED_RUNBOOK.md §3, which David confirmed on
     * 2026-10-10 — exercise 120 min, senior 90 min, puppy 10 min × age capped at 120, stages
     * 9 / 36 / 121 (senior from the McMillan 2024 median 13.4 y via the Dogs Trust table, S123),
     * arrival 2 / 9 / 36 / 121, learning multiplier 1.5 (Coren 17, Excellent); care rates the
     * Border Collie's.
     */
    public const CONFIRMED_R10_GERMAN_SHORTHAIRED_POINTER = 'potrdil David 2026-10-10 (pravilo runbooka, M5-R10-21)';

    /**
     * M5-R10-22 Yorkshire Terrier (unattended run 2026-10-12, data.json
     * proposed_game_parameters.yorkshire_terrier.*.decision): every value follows a standing
     * rule of docs/engineering/ADD_BREED_RUNBOOK.md §3, which David confirmed on 2026-10-10 —
     * exercise 30 min, senior 23 min, puppy 10 min × age capped at 30, stages 9 / 36 / 122
     * (senior from the VetCompass median 13.56 y, S195 — provisional until McMillan 2024 is
     * checked), arrival 2 / 9 / 36 / 122, learning multiplier 1.2 (Coren 27, Above average);
     * care rates the Border Collie's.
     */
    public const CONFIRMED_R10_YORKSHIRE_TERRIER = 'potrdil David 2026-10-10 (pravilo runbooka, M5-R10-22)';

    /**
     * M5-R10-23 Cane Corso (unattended run 2026-10-12, data.json
     * proposed_game_parameters.cane_corso.*.decision): every value follows a standing rule of
     * docs/engineering/ADD_BREED_RUNBOOK.md §3, which David confirmed on 2026-10-10 — exercise
     * 120 min (PDSA; the breed has no RKC page), senior 90 min, puppy 10 min × age capped at
     * 120, stages young 9 / adult 36, arrival 2 / 9 / 36, learning multiplier 1.0 (not ranked
     * by Coren); care rates the Border Collie's. The senior boundary uses the closest rule row
     * (a peer-reviewed breed median, S201) and is NOT covered by the confirmation: see
     * PROPOSED_R10_CANE_CORSO_SENIOR.
     */
    public const CONFIRMED_R10_CANE_CORSO = 'potrdil David 2026-10-10 (pravilo runbooka, M5-R10-23)';

    /**
     * M5-R10-23 Cane Corso senior boundary / senior arrival (84 months): Claude's suggestion
     * from the closest rule row (0.75 × breed median 9.29 y, Korec et al. 2017, S201) — no
     * runbook rule produced a value (no McMillan 2024 value, no VetCompass paper, no RKC page),
     * so the rows are verified=false until David decides (data.json
     * proposed_game_parameters.cane_corso.senior_boundary_months).
     */
    public const PROPOSED_R10_CANE_CORSO_SENIOR = 'predlog Claude 2026-10-12 (najbližja vrstica pravila runbooka, M5-R10-23) — čaka Davida';

    /**
     * M5-R10-24 Pembroke Welsh Corgi (unattended run 2026-10-12, data.json
     * proposed_game_parameters.pembroke_welsh_corgi.*.decision): every value follows a standing
     * rule of docs/engineering/ADD_BREED_RUNBOOK.md §3, which David confirmed on 2026-10-10 —
     * exercise 60 min, senior 45 min, puppy 10 min × age capped at 60, stages 9 / 36 / 108
     * (senior from the RKC lower bound "Over 12 years", S204 — provisional until McMillan 2024
     * is checked), arrival 2 / 9 / 36 / 108, learning multiplier 1.5 (Coren 11, Excellent);
     * care rates the Border Collie's.
     */
    public const CONFIRMED_R10_PEMBROKE_WELSH_CORGI = 'potrdil David 2026-10-10 (pravilo runbooka, M5-R10-24)';

    /**
     * M5-R10-25 Dobermann (unattended run 2026-10-12, data.json
     * proposed_game_parameters.dobermann.*.decision): every value follows a standing rule of
     * docs/engineering/ADD_BREED_RUNBOOK.md §3, which David confirmed on 2026-10-10 —
     * exercise 120 min, senior 90 min, puppy 10 min × age capped at 120, stages 9 / 36 / 83
     * (senior from the VetCompass median 9.2 y, O'Neill et al. 2013, S212 — provisional until
     * McMillan 2024 is checked), arrival 2 / 9 / 36 / 83, learning multiplier 1.9 (Coren 5,
     * Brightest); care rates the Border Collie's.
     */
    public const CONFIRMED_R10_DOBERMANN = 'potrdil David 2026-10-10 (pravilo runbooka, M5-R10-25)';

    /**
     * M5-R10-26 Boxer (unattended run 2026-10-12, data.json
     * proposed_game_parameters.boxer.*.decision): every value follows a standing rule of
     * docs/engineering/ADD_BREED_RUNBOOK.md §3, which David confirmed on 2026-10-10 —
     * exercise 120 min, senior 90 min, puppy 10 min × age capped at 120, stages 9 / 36 / 102
     * (senior from the McMillan 2024 median 11.3 y via the Dogs Trust table, S123), arrival
     * 2 / 9 / 36 / 102, learning multiplier 1.0 (Coren 48, Average); care rates the Border Collie's.
     */
    public const CONFIRMED_R10_BOXER = 'potrdil David 2026-10-10 (pravilo runbooka, M5-R10-26)';

    /**
     * M5-R10-27 Miniature Schnauzer (unattended run 2026-10-13, data.json
     * proposed_game_parameters.miniature_schnauzer.*.decision): every value follows a standing
     * rule of docs/engineering/ADD_BREED_RUNBOOK.md §3, which David confirmed on 2026-10-10 —
     * exercise 60 min, senior 45 min, puppy 10 min × age capped at 60, stages 9 / 36 / 104
     * (senior from the VetCompass median 11.6 y, S227 — provisional until McMillan 2024 is
     * checked), arrival 2 / 9 / 36 / 104, learning multiplier 1.5 (Coren 12, Excellent); care
     * rates the Border Collie's.
     */
    public const CONFIRMED_R10_MINIATURE_SCHNAUZER = 'potrdil David 2026-10-10 (pravilo runbooka, M5-R10-27)';

    /**
     * M5-R10-28 Shih Tzu (unattended run 2026-10-13, data.json
     * proposed_game_parameters.shih_tzu.*.decision): every value follows a standing rule of
     * docs/engineering/ADD_BREED_RUNBOOK.md §3, which David confirmed on 2026-10-10 — exercise
     * 60 min, senior 45 min, puppy 10 min × age capped at 60, stages 9 / 36 / 114 (senior from
     * the VetCompass median 12.7 y, S234 — provisional until McMillan 2024 is checked), arrival
     * 2 / 9 / 36 / 114, learning multiplier 0.5 (Coren 70, Lowest); care rates the Border Collie's.
     */
    public const CONFIRMED_R10_SHIH_TZU = 'potrdil David 2026-10-10 (pravilo runbooka, M5-R10-28)';

    /**
     * M5-R10-29 Great Dane (unattended run 2026-10-13, data.json
     * proposed_game_parameters.great_dane.*.decision): every value follows a standing rule of
     * docs/engineering/ADD_BREED_RUNBOOK.md §3, which David confirmed on 2026-10-10 — exercise
     * 120 min, senior 90 min, puppy 10 min × age capped at 120, stages 9 / 36 / 54 (senior from
     * the VetCompass median 6.0 y, S212 — 23 deaths, provisional until McMillan 2024 is checked),
     * arrival 2 / 9 / 36 / 54, learning multiplier 1.0 (Coren 48, Average); care rates the Border
     * Collie's.
     */
    public const CONFIRMED_R10_GREAT_DANE = 'potrdil David 2026-10-10 (pravilo runbooka, M5-R10-29)';

    /**
     * M5-R10-30 Pomeranian (unattended run 2026-10-13, data.json
     * proposed_game_parameters.pomeranian.*.decision): every value follows a standing rule of
     * docs/engineering/ADD_BREED_RUNBOOK.md §3, which David confirmed on 2026-10-10 — exercise
     * 30 min, senior 23 min, puppy 10 min × age capped at 30, stages 9 / 36 / 108 (senior from
     * the RKC lower bound "Over 12 years", S247 — provisional until McMillan 2024 is checked),
     * arrival 2 / 9 / 36 / 108, learning multiplier 1.5 (Coren 23, Excellent); care rates the
     * Border Collie's.
     */
    public const CONFIRMED_R10_POMERANIAN = 'potrdil David 2026-10-10 (pravilo runbooka, M5-R10-30)';

    /**
     * M5-R10-31 Boston Terrier (unattended run 2026-10-13, data.json
     * proposed_game_parameters.boston_terrier.*.decision): every value follows a standing rule of
     * docs/engineering/ADD_BREED_RUNBOOK.md §3, which David confirmed on 2026-10-10 — exercise
     * 60 min, senior 45 min, puppy 10 min × age capped at 60, stages 9 / 36 / 90 (senior from
     * the RKC lower bound "Over 10 years", S255 — provisional until McMillan 2024 is checked),
     * arrival 2 / 9 / 36 / 90, learning multiplier 1.0 (Coren 54, Average); care rates the
     * Border Collie's.
     */
    public const CONFIRMED_R10_BOSTON_TERRIER = 'potrdil David 2026-10-10 (pravilo runbooka, M5-R10-31)';

    /**
     * M5-R10-32 Miniature American Shepherd (unattended run 2026-10-13, data.json
     * proposed_game_parameters.miniature_american_shepherd.*.decision): every value follows a standing
     * rule of docs/engineering/ADD_BREED_RUNBOOK.md §3, which David confirmed on 2026-10-10 — exercise
     * 60 min (PetMD S269 "at least one hour"; RKC page not reachable), senior 45 min, puppy 10 min × age
     * capped at 60, stages young 9 / adult 36, arrival 2 / 9 / 36, learning multiplier 1.0 (not ranked by
     * Coren); care rates the Border Collie's. The senior boundary uses the closest rule row (PetMD lower
     * bound 12 y, S269) and is NOT covered by the confirmation: see PROPOSED_R10_MINIATURE_AMERICAN_SHEPHERD_SENIOR.
     */
    public const CONFIRMED_R10_MINIATURE_AMERICAN_SHEPHERD = 'potrdil David 2026-10-10 (pravilo runbooka, M5-R10-32)';

    /**
     * M5-R10-32 Miniature American Shepherd senior boundary / senior arrival (108 months): Claude's
     * suggestion from the closest rule row (0.75 × PetMD lower bound 12 y, S269) — no runbook rule
     * produced a value (no McMillan 2024 row, no VetCompass paper, RKC page not reachable), so the rows
     * are verified=false until David decides (data.json
     * proposed_game_parameters.miniature_american_shepherd.senior_boundary_months).
     */
    public const PROPOSED_R10_MINIATURE_AMERICAN_SHEPHERD_SENIOR = 'predlog Claude 2026-10-13 (najbližja vrstica pravila runbooka, M5-R10-32) — čaka Davida';

    /**
     * M5-R10-33 Chihuahua (Smooth Coat; unattended run 2026-10-13, data.json
     * proposed_game_parameters.chihuahua.*.decision): every value follows a standing rule of
     * docs/engineering/ADD_BREED_RUNBOOK.md §3, which David confirmed on 2026-10-10 — exercise 30 min
     * (RKC S275 "Up to 30 minutes per day"), senior 23 min, puppy 10 min × age capped at 30, stages
     * young 9 / adult 36, arrival 2 / 9 / 36, learning multiplier 0.7 (Coren rank 67, Fair); care rates
     * the Border Collie's. The senior boundary is NOT covered by the confirmation: see
     * PROPOSED_R10_CHIHUAHUA_SENIOR.
     */
    public const CONFIRMED_R10_CHIHUAHUA = 'potrdil David 2026-10-10 (pravilo runbooka, M5-R10-33)';

    /**
     * M5-R10-33 Chihuahua senior boundary / senior arrival (108 months): Claude's suggestion — the RKC /
     * PDSA lower bound "Over 12 years" (S275 / S277) instead of the rule's next row, the VetCompass median
     * (S279, → 74), which its authors say is biased downwards by a young population. verified=false
     * until David decides (data.json proposed_game_parameters.chihuahua.senior_boundary_months).
     */
    public const PROPOSED_R10_CHIHUAHUA_SENIOR = 'predlog Claude 2026-10-13 (spodnja meja RKC / PDSA namesto pristranske mediane VetCompass, M5-R10-33) — čaka Davida';

    /**
     * M5-R10-34 English Springer Spaniel (unattended run 2026-10-14, data.json
     * proposed_game_parameters.english_springer_spaniel.*.decision): every value follows a standing rule of
     * docs/engineering/ADD_BREED_RUNBOOK.md §3, which David confirmed on 2026-10-10 — exercise 120 min
     * (RKC S284 "More than 2 hours per day"), senior 90 min, puppy 10 min × age capped at 120, stages
     * young 9 / adult 36 / senior 122 (0.75 × McMillan 2024 median 13.5 y, S123), arrival 2 / 9 / 36 / 122,
     * learning multiplier 1.5 (Coren rank 13, Excellent); care rates the Border Collie's.
     */
    public const CONFIRMED_R10_ENGLISH_SPRINGER_SPANIEL = 'potrdil David 2026-10-10 (pravilo runbooka, M5-R10-34)';

    /**
     * M5-R10-35 Shetland Sheepdog (unattended run 2026-10-14, data.json
     * proposed_game_parameters.shetland_sheepdog.*.decision): every value follows a standing rule of
     * docs/engineering/ADD_BREED_RUNBOOK.md §3, which David confirmed on 2026-10-10 — exercise 60 min
     * (RKC S291 "Up to 1 hour per day"), senior 45 min, puppy 10 min × age capped at 60, stages
     * young 9 / adult 36 / senior 90 (0.75 × RKC lower bound 10 y, S291 — provisional until McMillan
     * 2024 is checked), arrival 2 / 9 / 36 / 90, learning multiplier 1.8 (Coren rank 6, Brightest);
     * care rates the Border Collie's.
     */
    public const CONFIRMED_R10_SHETLAND_SHEEPDOG = 'potrdil David 2026-10-10 (pravilo runbooka, M5-R10-35)';

    /**
     * M5-R10-36 Brittany (unattended run 2026-10-14, data.json
     * proposed_game_parameters.brittany.*.decision): every value follows a standing rule of
     * docs/engineering/ADD_BREED_RUNBOOK.md §3, which David confirmed on 2026-10-10 — exercise 120 min
     * (RKC S298 "More than 2 hours per day"), senior 90 min, puppy 10 min × age capped at 120, stages
     * young 9 / adult 36 / senior 90 (0.75 × RKC lower bound 10 y, S298 — provisional until McMillan
     * 2024 is checked), arrival 2 / 9 / 36 / 90, learning multiplier 1.5 (Coren rank 19, Excellent);
     * care rates the Border Collie's.
     */
    public const CONFIRMED_R10_BRITTANY = 'potrdil David 2026-10-10 (pravilo runbooka, M5-R10-36)';

    /**
     * M5-R10-37 American Cocker Spaniel (unattended run 2026-10-14, data.json
     * proposed_game_parameters.american_cocker_spaniel.*.decision): every value follows a standing rule of
     * docs/engineering/ADD_BREED_RUNBOOK.md §3, which David confirmed on 2026-10-10 — exercise 120 min
     * (RKC S305 "More than 2 hours per day"; AKC / PetMD conflict, RKC preferred), senior 90 min, puppy
     * 10 min × age capped at 120, stages young 9 / adult 36 / senior 90 (0.75 × RKC lower bound 10 y, S305 —
     * provisional until McMillan 2024 is checked), arrival 2 / 9 / 36 / 90, learning multiplier 1.5 (Coren
     * rank 20, Excellent); care rates the Border Collie's.
     */
    public const CONFIRMED_R10_AMERICAN_COCKER_SPANIEL = 'potrdil David 2026-10-10 (pravilo runbooka, M5-R10-37)';

    /**
     * M5-R10-38 Belgian Malinois (unattended run 2026-10-14, data.json
     * proposed_game_parameters.belgian_malinois.*.decision): every value follows a standing rule of
     * docs/engineering/ADD_BREED_RUNBOOK.md §3, which David confirmed on 2026-10-10 — exercise 120 min
     * (RKC S314 "More than 2 hours per day"), senior 90 min, puppy 10 min × age capped at 120, stages
     * young 9 / adult 36 / senior 90 (0.75 × RKC lower bound 10 y, S314 — provisional until McMillan 2024
     * is checked), arrival 2 / 9 / 36 / 90, learning multiplier 1.5 (Coren rank 22, Excellent); care rates
     * the Border Collie's.
     */
    public const CONFIRMED_R10_BELGIAN_MALINOIS = 'potrdil David 2026-10-10 (pravilo runbooka, M5-R10-38)';

    /**
     * M5-R10-39 Basset Hound (unattended run 2026-10-15, data.json
     * proposed_game_parameters.basset_hound.*.decision): every value follows a standing rule of
     * docs/engineering/ADD_BREED_RUNBOOK.md §3, which David confirmed on 2026-10-10 — exercise 60 min
     * (RKC S321 "Up to 1 hour per day"), senior 45 min, puppy 10 min × age capped at 60, stages
     * young 9 / adult 36 / senior 90 (0.75 × RKC lower bound 10 y, S321 — provisional until McMillan 2024
     * is checked), arrival 2 / 9 / 36 / 90, learning multiplier 0.5 (Coren rank 71, Lowest); care rates
     * the Border Collie's.
     */
    public const CONFIRMED_R10_BASSET_HOUND = 'potrdil David 2026-10-10 (pravilo runbooka, M5-R10-39)';

    /**
     * M5-R10-40 Hungarian Vizsla (unattended run 2026-10-15, data.json
     * proposed_game_parameters.hungarian_vizsla.*.decision): every value follows a standing rule of
     * docs/engineering/ADD_BREED_RUNBOOK.md §3, which David confirmed on 2026-10-10 — exercise 120 min
     * (RKC S328 "More than 2 hours per day"), senior 90 min, puppy 10 min × age capped at 120, stages
     * young 9 / adult 36 / senior 108 (0.75 × RKC lower bound 12 y, S328 — provisional until McMillan 2024
     * is checked), arrival 2 / 9 / 36 / 108, learning multiplier 1.5 (Coren rank 25, Excellent); care rates
     * the Border Collie's.
     */
    public const CONFIRMED_R10_HUNGARIAN_VIZSLA = 'potrdil David 2026-10-10 (pravilo runbooka, M5-R10-40)';

    /**
     * M5-R10-41 English Cocker Spaniel (unattended run 2026-10-15, data.json
     * proposed_game_parameters.english_cocker_spaniel.*.decision): every value follows a standing rule of
     * docs/engineering/ADD_BREED_RUNBOOK.md §3, which David confirmed on 2026-10-10 — exercise 60 min
     * (RKC S337 "Up to 1 hour per day"), senior 45 min, puppy 10 min × age capped at 60, stages
     * young 9 / adult 36 / senior 103 (0.75 × VetCompass median 11.44 y, S342), arrival 2 / 9 / 36 / 103,
     * learning multiplier 1.5 (Coren rank 18, Excellent); care rates the Border Collie's.
     */
    public const CONFIRMED_R10_ENGLISH_COCKER_SPANIEL = 'potrdil David 2026-10-10 (pravilo runbooka, M5-R10-41)';

    /**
     * M5-R10-42 Maltese (unattended run 2026-10-18, data.json proposed_game_parameters.maltese.*.decision):
     * every value follows a standing rule of docs/engineering/ADD_BREED_RUNBOOK.md §3, which David confirmed
     * on 2026-10-10 — exercise 30 min (RKC S347 "Up to 30 minutes per day"), senior 23 min, puppy 10 min × age
     * capped at 30, stages young 9 / adult 36 / senior 108 (0.75 × RKC lower bound 12 y, S347), arrival
     * 2 / 9 / 36 / 108, learning multiplier 0.7 (Coren rank 59, Fair); care rates the Border Collie's.
     */
    public const CONFIRMED_R10_MALTESE = 'potrdil David 2026-10-10 (pravilo runbooka, M5-R10-42)';

    /**
     * M5-R10-43 Pug (unattended run 2026-10-18, data.json proposed_game_parameters.pug.*.decision):
     * every value follows a standing rule of docs/engineering/ADD_BREED_RUNBOOK.md §3, which David confirmed
     * on 2026-10-10 — exercise 60 min (RKC S355 "Up to 1 hour per day"), senior 45 min, puppy 10 min × age
     * capped at 60, stages young 9 / adult 36 / senior 104 (0.75 × McMillan 2024 median 11.6 y, S54), arrival
     * 2 / 9 / 36 / 104, learning multiplier 0.7 (Coren rank 57, Fair); care rates the Border Collie's.
     */
    public const CONFIRMED_R10_PUG = 'potrdil David 2026-10-10 (pravilo runbooka, M5-R10-43)';

    /**
     * M5-R10-14 British Shorthair (unattended run 2026-10-11, runbook §3 standing rules
     * confirmed by David 2026-10-10; cat-data british_shorthair.*.decision): the
     * pedigree kitten arrives at 3 months (CAT_SPEC Q4 rule, C13). Everything else is
     * the general cat rule; no grooming row (CAT_SPEC Q8: Maine Coon only).
     */
    public const CONFIRMED_R10_BRITISH_SHORTHAIR = 'potrdil David 2026-10-10 (pravilo runbooka, M5-R10-14)';

    /**
     * M5-R10-15 Ragdoll (unattended run 2026-10-11, runbook §3 standing rules confirmed by
     * David 2026-10-10; cat-data ragdoll.*.decision): the pedigree kitten arrives at
     * 3 months (CAT_SPEC Q4 rule, C13). Everything else is the general cat rule; no
     * grooming row (CAT_SPEC Q8: Maine Coon only).
     */
    public const CONFIRMED_R10_RAGDOLL = 'potrdil David 2026-10-10 (pravilo runbooka, M5-R10-15)';

    /**
     * M5-R10-16 Siberian (unattended run 2026-10-11, runbook §3 standing rules confirmed by
     * David 2026-10-10; cat-data siberian_cat.*.decision): the pedigree kitten arrives at
     * 3 months (CAT_SPEC Q4 rule, C13). Everything else is the general cat rule.
     */
    public const CONFIRMED_R10_SIBERIAN_CAT = 'potrdil David 2026-10-10 (pravilo runbooka, M5-R10-16)';

    /**
     * M5-R10-16 Siberian grooming (runbook §3 Cats, David's grooming rule of 2026-10-11):
     * 1× per week (PetMD C35 "at least once a week"); matted coat at ceil(1 / 2) = 1.
     */
    public const CONFIRMED_R10_SIBERIAN_CAT_GROOMING = 'potrdil David 2026-10-11 (pravilo runbooka, M5-R10-16)';

    /**
     * M5-R10-17 Norwegian Forest Cat (unattended run 2026-10-11, runbook §3 standing rules
     * confirmed by David 2026-10-10; cat-data norwegian_forest_cat.*.decision): the pedigree
     * kitten arrives at 3 months (CAT_SPEC Q4 rule, C13). Everything else is the general cat rule.
     */
    public const CONFIRMED_R10_NORWEGIAN_FOREST_CAT = 'potrdil David 2026-10-10 (pravilo runbooka, M5-R10-17)';

    /**
     * M5-R10-17 Norwegian Forest Cat grooming (runbook §3 Cats, David's grooming rule of
     * 2026-10-11): 1× per week (PetMD C38 "at least once a week", GCCF C37 "Weekly grooming");
     * matted coat at ceil(1 / 2) = 1.
     */
    public const CONFIRMED_R10_NORWEGIAN_FOREST_CAT_GROOMING = 'potrdil David 2026-10-11 (pravilo runbooka, M5-R10-17)';

    /**
     * M5-R10-18 Bengal (unattended run 2026-10-11, runbook §3 standing rules confirmed by
     * David 2026-10-10; cat-data bengal.*.decision): the pedigree kitten arrives at 3 months
     * (CAT_SPEC Q4 rule, C13); lifespan background = the C14 VetCompass row. Everything else
     * is the general cat rule.
     */
    public const CONFIRMED_R10_BENGAL = 'potrdil David 2026-10-10 (pravilo runbooka, M5-R10-18)';

    /**
     * M5-R10-18 Bengal grooming (runbook §3 Cats, David's grooming rule of 2026-10-11):
     * 1× per week (PDSA C44 "a light brushing/combing once a week", PetMD C45 "should be
     * brushed weekly"); matted coat at ceil(1 / 2) = 1.
     */
    public const CONFIRMED_R10_BENGAL_GROOMING = 'potrdil David 2026-10-11 (pravilo runbooka, M5-R10-18)';

    /**
     * David 2026-10-11: every sourced breed-specific need is built in the app (no
     * "proposal, not built"). Grooming: British Shorthair 1× per week (VCA C28 "at
     * least weekly"), Ragdoll 2× per week (upper bound of PetMD C32 "once or twice
     * a week"); matted coat when ≥ ceil(goal / 2) of the week's slots are missed.
     */
    public const CONFIRMED_CAT_GROOMING_2026_10_11 = 'potrdil David 2026-10-11';

    /**
     * M5-R13 dog grooming (David 2026-10-12, "kot pri mački"): a sourced number per
     * runbook rule (data.json proposed_game_parameters.<breed>.grooming.decision).
     */
    public const CONFIRMED_R13_DOG_GROOMING = 'potrdil David 12. 10. 2026 (pravilo runbooka, M5-R13)';

    /**
     * M5-R13: the sources say "more than once a week" / "a few times a week" without a
     * number (German Shepherd, Australian Shepherd, West Highland White Terrier, Bernese
     * Mountain Dog, Siberian Husky) → Claude's proposal 2 per week, until 2026-10-12
     * verified=false. Kept for the data migration 2026_12_01_120000 (the old text).
     */
    public const PROPOSED_R13_DOG_GROOMING = 'predlog Claude 12. 10. 2026 (»večkrat na teden« brez števila → 2, M5-R13) — čaka Davida';

    /**
     * David 2026-10-12: the five "more than once a week" breeds stay at 2 per week —
     * confirmed (verified). The source has no number; 2 is David's decision.
     */
    public const CONFIRMED_DAVID_2026_10_12_TWICE = 'potrdil David 2026-10-12: 2× na teden';

    /**
     * David 2026-10-12: Cavalier King Charles Spaniel 4 per week instead of 7. The source
     * stays PDSA S108 with its quote; 4 is David's choice, not the source's number.
     */
    public const CONFIRMED_DAVID_2026_10_12_CAVALIER = 'potrdil David 2026-10-12: 4× na teden (PDSA S108 \'may find they need to be brushed daily\' je previden zapis)';

    /** David 2026-10-12: German Shorthaired Pointer stays 2 per week (PDSA "once or twice a week" → 2). */
    public const CONFIRMED_DAVID_2026_10_12 = 'potrdil David 2026-10-12';

    /**
     * M5-R10-38 Belgian Malinois: RKC "More than once a week" (S314) without a number and AKC / PetMD
     * "occasional" brushing → Claude's proposal 2 per week, verified=false (a new breed still asks David,
     * runbook §3 "Dog grooming").
     */
    public const PROPOSED_R13_BELGIAN_MALINOIS = 'predlog Claude 2026-10-14 (RKC »More than once a week« brez števila → 2, M5-R13, M5-R10-38) — čaka Davida';

    /**
     * M5-R13: dog breed → [sessions per week, source id, verbatim quote (data.json), confidence,
     * Claude's proposal?, David's own decision (null = the runbook rule)]. The mutt and the
     * Border Collie have no grooming statement → no row.
     *
     * @var array<string, array{0: int, 1: string, 2: string, 3: string, 4: bool, 5?: string}>
     */
    public const DOG_GROOMING = [
        'labrador_retriever' => [1, 'S50', 'Grooming: Once a week', 'high', false],
        'golden_retriever' => [3, 'S68', 'Ideally, they should be brushed or combed three times a week at a minimum.', 'medium', false],
        'french_bulldog' => [1, 'S78', 'Grooming: Once a week', 'high', false],
        'german_shepherd' => [2, 'S97', 'Grooming: More than once a week', 'low', false, self::CONFIRMED_DAVID_2026_10_12_TWICE],
        'cavalier_king_charles_spaniel' => [4, 'S108', 'Cavalier King Charles Spaniels have fairly high maintenance coats and you may find they need to be brushed daily', 'medium', false, self::CONFIRMED_DAVID_2026_10_12_CAVALIER],
        'beagle' => [1, 'S113', 'Grooming: Once a week', 'high', false],
        'standard_poodle' => [7, 'S120', 'Grooming: Every day', 'high', false],
        'dachshund' => [1, 'S125', 'Grooming: Once a week', 'high', false],
        'australian_shepherd' => [2, 'S133', 'Grooming: More than once a week', 'low', false, self::CONFIRMED_DAVID_2026_10_12_TWICE],
        'havanese' => [7, 'S138', 'Grooming: Every day', 'high', false],
        'west_highland_white_terrier' => [2, 'S144', 'Grooming: More than once a week', 'low', false, self::CONFIRMED_DAVID_2026_10_12_TWICE],
        'bernese_mountain_dog' => [2, 'S152', 'Grooming: More than once a week', 'low', false, self::CONFIRMED_DAVID_2026_10_12_TWICE],
        'siberian_husky' => [2, 'S160', 'Grooming: More than once a week', 'low', false, self::CONFIRMED_DAVID_2026_10_12_TWICE],
        'rottweiler' => [1, 'S168', 'Grooming: Once a week', 'high', false],
        'bulldog' => [1, 'S177', 'Grooming: Once a week', 'high', false],
        'german_shorthaired_pointer' => [2, 'S188', "Shorthaired pointers will need grooming once or twice a week, with Wirehaired also needing 'stripping' occasionally.", 'medium', false, self::CONFIRMED_DAVID_2026_10_12],
        'yorkshire_terrier' => [7, 'S192', 'Grooming: Every day', 'high', false],
        'cane_corso' => [1, 'S199', 'A weekly brush should be enough to keep on top of dead hairs.', 'high', false],
        'pembroke_welsh_corgi' => [3, 'S206', 'Coat care is fairly minimal, with grooming two-three times per week usually enough to remove any dead hair.', 'high', false],
        'dobermann' => [1, 'S209', 'Grooming: Once a week', 'high', false],
        'boxer' => [1, 'S215', 'Grooming: Once a week', 'high', false],
        'miniature_schnauzer' => [3, 'S226', 'Grooming is needed 2-3 times a week', 'high', false],
        'shih_tzu' => [7, 'S231', 'Grooming: Every day', 'high', false],
        'great_dane' => [1, 'S241', 'Grooming: Once a week', 'high', false],
        'pomeranian' => [3, 'S250', 'They have a double coat that needs brushing two-three times per week, and more often when shedding.', 'high', false],
        'boston_terrier' => [1, 'S255', 'Grooming: Once a week', 'high', false],
        'miniature_american_shepherd' => [2, 'S269', 'The Miniature American Shepherd needs to be brushed a couple times a week to control shedding and minimize matting.', 'medium', false],
        'chihuahua' => [1, 'S275', 'Grooming: Once a week', 'high', false],
        'english_springer_spaniel' => [2, 'S285', 'They need grooming once or twice a week, plus clipping every 3 months.', 'medium', false],
        'shetland_sheepdog' => [7, 'S291', 'Grooming: Every day', 'high', false],
        'brittany' => [1, 'S301', 'Weekly brushing with a soft brush or hound glove will keep a Brittany looking their best.', 'medium', false],
        'american_cocker_spaniel' => [7, 'S305', 'Grooming: Every day', 'high', false],
        'belgian_malinois' => [2, 'S314', 'Grooming: More than once a week', 'low', true, self::PROPOSED_R13_BELGIAN_MALINOIS],
        'basset_hound' => [1, 'S321', 'Grooming: Once a week', 'high', false],
        'hungarian_vizsla' => [1, 'S328', 'Grooming: Once a week', 'high', false],
        'english_cocker_spaniel' => [7, 'S337', 'Grooming: Every day', 'high', false],
        'maltese' => [7, 'S347', 'Grooming: Every day', 'high', false],
        'pug' => [1, 'S355', 'Grooming: Once a week', 'high', false],
    ];

    /**
     * M5-R16 meal / exercise gap (David 2026-10-12, "1 ura, samo opozorilo"): the 60 minutes
     * are David's decision; the source only says "leave a gap" (no length).
     */
    public const CONFIRMED_R16_MEAL_EXERCISE_GAP = 'potrdil David 2026-10-12: 60 min, samo opozorilo, koraki se štejejo (ROADMAP M5-R16)';

    /** M5-R16: David's length of the digestion warning, in minutes (all breeds with a source). */
    public const MEAL_EXERCISE_GAP_MINUTES = 60;

    /**
     * M5-R16: dog breed → [source id, verbatim quote (data.json
     * proposed_game_parameters.<breed>.meal_exercise_gap)]. Only breeds whose A/B source
     * states the advice verbatim; no row = no warning. The Bernese Mountain Dog's PDSA page
     * (S154) is recorded only as a paraphrase → no row until a verbatim quote is fetched.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const MEAL_EXERCISE_GAP = [
        'cane_corso' => ['S199', 'Remember to leave a gap after eating and before exercising.'],
        'dobermann' => ['S211', 'Remember to leave at a gap after eating and before exercising.'],
        'boxer' => ['S219', 'Remember to leave a gap between eating and exercising.'],
        'shih_tzu' => ['S233', 'Remember to leave a gap after eating and before exercising.'],
        'great_dane' => ['S243', "Remember to leave at a gap after eating and before exercising to help prevent GDV (Gastric dilatation volvulus or 'bloat')."],
        'chihuahua' => ['S277', 'Remember to leave a gap after eating and before exercising.'],
        'basset_hound' => ['S323', 'Remember to leave a gap after eating and before exercising.'],
        'hungarian_vizsla' => ['S329', 'Remember to leave a gap between eating and exercising.'],
        'english_cocker_spaniel' => ['S339', 'Remember to leave at a gap after eating and before exercising.'],
    ];

    /** M5-R17: David's decision of the weekly "new experience" (value 1, puppy stage, never scored). */
    public const CONFIRMED_R17_SOCIALISATION = 'potrdil David 12. 10. 2026: 1 nova izkušnja na teden, dokler je mladiček; brez kazni (M5-R17)';

    /**
     * David 2026-10-12: socialisation for ALL puppies — every dog breed without a sourced
     * quote gets the weekly "new experience" too (no fake quote, no source id).
     */
    public const CONFIRMED_R17_ALL_PUPPIES = 'potrdil David 12. 10. 2026: socializacija za vse mladičke (1 nova izkušnja na teden, dokler je mladiček; brez kazni, M5-R17)';

    /**
     * M5-R17 puppy socialisation (David 2026-10-12): dog breed → [source id, verbatim
     * quote (data.json `proposed_game_parameters.<breed>.socialisation`)]. A breed gets
     * the weekly "new experience" while a puppy when an A/B source in data.json gives
     * socialisation advice verbatim (runbook §3); the number (1 per week) is David's.
     * Since David 2026-10-12 (»socializacija za vse mladičke«) every other dog breed —
     * the mutt, Border Collie, French Bulldog, Cavalier, Dachshund, Westie, Bernese
     * (paraphrase only), GSP, Yorkshire Terrier, Golden Retriever and every future
     * dog breed — gets the same row from David's decision (no source quote, see
     * dogSocialisation()).
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const DOG_SOCIALISATION = [
        'labrador_retriever' => ['S53', 'Labradors make perfect family pets, given the right socialisation, as with all breeds.'],
        'german_shepherd' => ['S100', 'careful training and socialisation will be needed'],
        'beagle' => ['S115', 'Due to their loving and tolerant nature, if socialised correctly from a young age Beagles suit family life really well.'],
        'standard_poodle' => ['S122', 'They generally get on well with other pets and children, given the right socialisation as puppies, as with all breeds.'],
        'australian_shepherd' => ['S134', 'As long as your Australian Shepherd has been socialised with other dogs from a young age they should be fine with other dogs.'],
        'havanese' => ['S140', 'given the right socialisation as puppies.'],
        'siberian_husky' => ['S162', 'Huskies love company so as long as they have been socialised from a young age, they should get along fine with other dogs.'],
        'rottweiler' => ['S169', 'Rottweilers will need socialising from a young age so they grow up to be confident and calm.'],
        'bulldog' => ['S180', 'They are very calm dogs and will get on well with other household pets if given the right socialisation when young.'],
        'cane_corso' => ['S199', 'You’ll need to socialise your Cane Corso with lots of different people and experiences from a young age.'],
        'pembroke_welsh_corgi' => ['S206', 'socialisation with children and training at a young age can help to overcome these possible problems.'],
        'dobermann' => ['S211', 'it’s important to socialise your dog with different experiences, people and dogs from a young age.'],
        'boxer' => ['S219', "Boxers have a strong guarding instinct so it's important to socialise your Boxer with other dogs from a young age."],
        'shih_tzu' => ['S233', 'Shih Tzus can be good-natured family pets if they’ve been given the right socialisation and training'],
        'great_dane' => ['S243', "This is why it's really important to socialise your Great Dane with different experiences, people and dogs from a young age."],
        'pomeranian' => ['S251', 'Like with any breed, early socialization and puppy training classes are highly recommended.'],
        'boston_terrier' => ['S260', 'Like all breeds, early socialization and puppy training courses are strongly recommended for Boston Terriers.'],
        'miniature_american_shepherd' => ['S267', "They can be weary of new faces, but early, consistent socialization will build up a Mini American's confidence in new situations."],
        'chihuahua' => ['S277', "This is why it's really important to socialise your Chihuahua from a young age"],
        'english_springer_spaniel' => ['S286', 'Like with any breed, early socialization and puppy training courses are key'],
        'shetland_sheepdog' => ['S293', 'Training, along with socialisation, is very important at a young age'],
        'brittany' => ['S302', 'Early socialization in Brittany puppies is a must'],
        'american_cocker_spaniel' => ['S309', 'Start socialization and training classes early so your Cocker Spaniel puppy learns good manners.'],
        'belgian_malinois' => ['S316', 'Early, extensive socialization will help limit aggressive tendencies towards new people.'],
        'basset_hound' => ['S323', 'Be sure to socialise your Basset Hound from a young age with other people and dogs once they are fully vaccinated.'],
        'hungarian_vizsla' => ['S329', 'as long as you socialise your Vizsla from a young age with lots of people, dogs and experiences'],
        'english_cocker_spaniel' => ['S339', 'Socialising your Cocker Spaniel from a young age with a variety of people, dogs and experiences will help them develop'],
        'maltese' => ['S348', 'Known to be sweet-natured, they can be reserved with strangers but given the right socialisation when young,'],
        'pug' => ['S359', 'Pug puppies, like all dogs, need to be socialized during their first months of life.'],
    ];

    /** Cat breeds of catRows() (domestic cat = the free cat). */
    public const CAT_BREEDS = [BreedType::DomesticCat, BreedType::MaineCoon, BreedType::BritishShorthair, BreedType::Ragdoll, BreedType::SiberianCat, BreedType::NorwegianForestCat, BreedType::Bengal];

    /** Dog breeds of rows(), in seeding order (M5-R10: one profile per breed, dogProfile()). */
    public const DOG_BREEDS = [BreedType::Mutt, BreedType::BorderCollie, BreedType::LabradorRetriever, BreedType::GoldenRetriever, BreedType::FrenchBulldog, BreedType::GermanShepherd, BreedType::CavalierKingCharlesSpaniel, BreedType::Beagle, BreedType::StandardPoodle, BreedType::Dachshund, BreedType::AustralianShepherd, BreedType::Havanese, BreedType::WestHighlandWhiteTerrier, BreedType::BerneseMountainDog, BreedType::SiberianHusky, BreedType::Rottweiler, BreedType::Bulldog, BreedType::GermanShorthairedPointer, BreedType::YorkshireTerrier, BreedType::CaneCorso, BreedType::PembrokeWelshCorgi, BreedType::Dobermann, BreedType::Boxer, BreedType::MiniatureSchnauzer, BreedType::ShihTzu, BreedType::GreatDane, BreedType::Pomeranian, BreedType::BostonTerrier, BreedType::MiniatureAmericanShepherd, BreedType::Chihuahua, BreedType::EnglishSpringerSpaniel, BreedType::ShetlandSheepdog, BreedType::Brittany, BreedType::AmericanCockerSpaniel, BreedType::BelgianMalinois, BreedType::BassetHound, BreedType::HungarianVizsla, BreedType::EnglishCockerSpaniel, BreedType::Maltese, BreedType::Pug];

    /** Prefix of a cat row's `data_ref` (path into docs/research/cat-data/data.json). */
    public const CAT_REF = 'cat-data:';

    /** @var list<array{0: string, 1: string}> */
    public const PUPPY_4_MEAL_WINDOWS = [['07:00', '09:00'], ['11:00', '13:00'], ['15:00', '17:00'], ['19:00', '21:00']];

    /** @var list<array{0: string, 1: string}> */
    public const PUPPY_3_MEAL_WINDOWS = [['07:00', '09:00'], ['13:00', '15:00'], ['19:00', '21:00']];

    /**
     * @return list<array<string, mixed>>
     */
    public static function rows(): array
    {
        $rows = [];

        foreach (self::DOG_BREEDS as $breed) {
            $p = self::dogProfile($breed);
            $slug = $breed->slug();
            $add = function (string $stage, int $from, StageParamKey $key, mixed $value, array $meta) use (&$rows, $slug): void {
                $rows[] = array_merge([
                    'breed_slug' => $slug,
                    'stage' => $stage,
                    'age_from_months' => $from,
                    'key' => $key->value,
                    'value' => $value,
                    'unit' => null,
                    'source_id' => null,
                    'confidence' => 'low',
                    'verified' => false,
                    'quote' => null,
                    'notes' => null,
                    'ref' => null,
                    'decision' => null,
                ], $meta);
            };

            // ── Stage boundaries (AAHA life stages, S11) ──────────────────
            $add('puppy', 0, StageParamKey::StartsAtMonths, 0, [
                'unit' => 'months', 'source_id' => 'S11', 'confidence' => 'high', 'verified' => true,
                'ref' => 'general_by_size.life_stages.puppy',
                'quote' => 'From birth to cessation of rapid growth (~6–9 months, varying with breed and size)',
            ]);
            foreach (['young', 'adult', 'senior'] as $stage) {
                [$month, $meta] = $p['starts_at'][$stage];
                $add($stage, 0, StageParamKey::StartsAtMonths, $month, $meta);
            }

            // ── Age at arrival (parent's choice → pets.arrival_age_months) ──
            $add('puppy', 0, StageParamKey::ArrivalAgeMonths, 2, [
                'unit' => 'months', 'source_id' => 'S36', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'general_by_size.training.start_age', 'decision' => self::CONFIRMED,
                'quote' => 'Puppies can begin very simple training starting as soon as they come home, usually around 8 weeks old.',
                'notes' => 'Game value backed by S36 ("home at ~8 weeks").',
            ]);
            foreach ($p['arrival'] as $stage => $age) {
                // `arrival_meta_overrides` (optional, M5-R10-12): a stage whose arrival age is not covered
                // by the shared provenance (an unconfirmed senior boundary) carries its own meta.
                $add($stage, 0, StageParamKey::ArrivalAgeMonths, $age, $p['arrival_meta_overrides'][$stage] ?? $p['arrival_meta']);
            }

            // ── Meals per day (counts sourced) ──────────────────────────────
            $add('puppy', 0, StageParamKey::MealsPerDay, 4, [
                'unit' => 'meals/day', 'source_id' => 'S18', 'confidence' => 'high', 'verified' => true,
                'ref' => 'general_by_size.feeding_meals_per_day.8_12_weeks',
                'quote' => 'Puppies eight to 12 weeks old need four meals a day.',
            ]);
            $add('puppy', 3, StageParamKey::MealsPerDay, 3, [
                'unit' => 'meals/day', 'source_id' => 'S18', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'general_by_size.feeding_meals_per_day.3_6_months',
                'quote' => 'Feed puppies three to six months old three meals a day.',
            ]);
            $add('puppy', 6, StageParamKey::MealsPerDay, 2, [
                'unit' => 'meals/day', 'source_id' => 'S18', 'confidence' => 'high', 'verified' => true,
                'ref' => 'general_by_size.feeding_meals_per_day.6_12_months',
                'quote' => 'Feed puppies six months to one year two meals a day.',
            ]);
            $add('young', 0, StageParamKey::MealsPerDay, 2, [
                'unit' => 'meals/day', 'source_id' => 'S18', 'confidence' => 'high', 'verified' => true,
                'ref' => 'general_by_size.feeding_meals_per_day.6_12_months',
                'quote' => 'Feed puppies six months to one year two meals a day.',
                'notes' => 'After 12 months the adult rule applies (S19: at least two meals) — also 2.',
            ]);
            $add('adult', 0, StageParamKey::MealsPerDay, 2, [
                'unit' => 'meals/day', 'source_id' => 'S19', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'general_by_size.feeding_meals_per_day.adult',
                'quote' => 'The most common recommendation is to feed your dog at least two meals per day.',
                'decision' => self::DECISION,
                'notes' => 'ASPCA (S18) says one meal can be enough; David chose 2 (2026-10-05).',
            ]);
            $add('senior', 0, StageParamKey::MealsPerDay, 2, [
                'unit' => 'meals/day', 'source_id' => 'S14', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'general_by_size.feeding_meals_per_day.senior', 'decision' => self::CONFIRMED,
                'quote' => 'feeding your dog smaller meals two or three times a day',
                'notes' => 'Game value inside the sourced 2–3: lower end, same as adult (David 2026-10-05: adults 2 meals).',
            ]);

            // ── Feed window clock times (David decision) ────────────────────
            $windowsMeta = [
                'unit' => 'HH:MM family-local [start, end)', 'verified' => true,
                'ref' => 'proposed_game_parameters.feed_window_times', 'decision' => self::CONFIRMED,
                'notes' => 'Game clock times (no literature number; the meal COUNT is sourced, S18). First window 07:00, last 19:00, equal spacing, 2 h each. A window entirely inside quiet hours is done by the parent.',
            ];
            $add('puppy', 0, StageParamKey::FeedWindows, self::PUPPY_4_MEAL_WINDOWS, $windowsMeta);
            $add('puppy', 3, StageParamKey::FeedWindows, self::PUPPY_3_MEAL_WINDOWS, $windowsMeta);

            // ── Exercise → step goal ────────────────────────────────────────
            $add('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth, 10, [
                'unit' => 'minutes/day per month of age', 'source_id' => 'S24', 'confidence' => 'low', 'verified' => true,
                'ref' => 'general_by_size.exercise.puppy_rule_of_thumb', 'decision' => self::CONFIRMED,
                'quote' => 'five minutes of exercise per month of age, twice a day, until the puppy is full-grown',
                'notes' => 'Game rule; the source rule is CONTESTED (S25 calls it a misconception), so confidence stays low. 5 min × 2 per day; capped at the adult minutes.',
            ]);
            $add('young', 0, StageParamKey::ExerciseMinutesPerAgeMonth, 10, [
                'unit' => 'minutes/day per month of age', 'source_id' => 'S24', 'confidence' => 'low', 'verified' => true,
                'ref' => 'general_by_size.exercise.puppy_rule_of_thumb', 'decision' => self::CONFIRMED,
                'quote' => 'five minutes of exercise per month of age, twice a day, until the puppy is full-grown',
                'notes' => $p['young_per_age_notes'],
            ]);
            foreach (['young', 'adult'] as $stage) {
                $add($stage, 0, StageParamKey::ExerciseMinutesPerDay, ...$p['adult_minutes']);
            }
            $add('senior', 0, StageParamKey::ExerciseMinutesPerDay, ...$p['senior_minutes']);
            $add('all', 0, StageParamKey::StepsPerExerciseMinute, 100, [
                'unit' => 'child steps per walking minute', 'source_id' => 'S45', 'confidence' => 'low', 'verified' => true,
                'ref' => 'general_by_size.exercise.steps_conversion',
                'quote' => '≥100 steps/min is a consistent heuristic … associated with absolutely defined moderate intensity',
                'decision' => self::DECISION,
                'notes' => 'Adult human cadence (S45); David accepted 100 steps per minute of the dog\'s daily exercise (2026-10-05).',
            ]);

            // ── Sleep (stored for videos / behaviour later) ─────────────────
            $sleep = fn (string $ref, string $quote): array => [
                'unit' => 'hours/day', 'source_id' => 'S28', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'general_by_size.sleep.'.$ref, 'quote' => $quote,
            ];
            $add('puppy', 0, StageParamKey::SleepHours, [15, 20], $sleep('4_12_weeks', '15-20 hours of sleep each day'));
            $add('puppy', 3, StageParamKey::SleepHours, [14, 16], $sleep('3_6_months', '14-16 hours of sleep per day'));
            $add('puppy', 6, StageParamKey::SleepHours, [12, 14], $sleep('over_6_months', 'around 12-14 hours each day'));
            $add('young', 0, StageParamKey::SleepHours, [12, 14], $sleep('over_6_months', 'around 12-14 hours each day'));
            $add('adult', 0, StageParamKey::SleepHours, [12, 14], $sleep('over_6_months', 'around 12-14 hours each day'));
            $add('senior', 0, StageParamKey::SleepHours, null, [
                'unit' => 'hours/day', 'source_id' => 'S14', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'general_by_size.sleep.senior',
                'quote' => 'can become less energetic and tend to sleep more',
                'notes' => 'No hours given: "more than an adult".',
            ]);

            // ── Behaviour (M5-R02, David 2026-10-06) ────────────────────────
            $add('puppy', 0, StageParamKey::AccidentHoldHoursPerAgeMonth, 1, [
                'unit' => 'hours of hold per month of age', 'source_id' => 'S30,S31', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'proposed_game_parameters.accident_window_hours', 'decision' => self::CONFIRMED_R02,
                'quote' => 'one hour for each month of age, give or take an hour',
                'notes' => 'S30 (Penn Vet); S31 (WebMD, DVM-reviewed): "1 hour for every month of age until they\'re about a year old". Puppy stage only; the game clock counts only outside quiet hours.',
            ]);
            $add('puppy', 0, StageParamKey::ChewingChancePerDay, 0.5, [
                'unit' => 'probability per family-local day (teething puppy)', 'source_id' => 'S32,S33', 'confidence' => 'low', 'verified' => false,
                'ref' => 'proposed_game_parameters.chewing_teething_chance_per_day',
                'notes' => 'UNSOURCED proposal (Claude, M5-R02, waiting for David): the sources say teething puppies chew a lot (S32, S33) but give no frequency. Applies only inside teething_months.',
            ]);

            // ── Breed level ─────────────────────────────────────────────────
            $add('all', 0, StageParamKey::AdultWeightKg, ...$p['adult_weight']);
            $add('all', 0, StageParamKey::GrowthEndMonths, ...$p['growth_end']);
            $add('all', 0, StageParamKey::HouseTrainedByMonths, [4, 6], [
                'unit' => 'months', 'source_id' => 'S31', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'general_by_size.house_training.fully_trained',
                'quote' => 'It typically takes 4-6 months for a puppy to be fully house trained, but some puppies may take up to a year.',
            ]);
            $add('all', 0, StageParamKey::TeethingMonths, [3, 6], [
                'unit' => 'months', 'source_id' => 'S32', 'confidence' => 'high', 'verified' => true,
                'ref' => 'general_by_size.teething_chewing.baby_teeth_shed',
                'quote' => 'your puppy\'s baby teeth start to shed (Weeks 12-16)',
                'notes' => 'Baby teeth shed from 12–16 weeks; adult teeth in and intense chewing over by ~6 months (S32, S33).',
            ]);
            $add('all', 0, StageParamKey::CorenRank, ...$p['coren_rank']);
            // ── Training (M5-R03, David 2026-10-06) ─────────────────────────
            $add('all', 0, StageParamKey::TrainingLearningMultiplier, ...$p['learning_multiplier']);
            if ($p['individual_variation'] !== null) {
                // No row (Border Collie, Labrador) = no individual variation.
                $add('all', 0, StageParamKey::TrainingIndividualVariation, ...$p['individual_variation']);
            }
            // M5-R03b: David confirmed the three numbers on 2026-10-06 and the two
            // effects on 2026-10-07 (verified, decision in notes).
            $add('all', 0, StageParamKey::TrainingMinutesPerDay, 5, [
                'unit' => 'minutes of mini-game per dog and family-local day', 'source_id' => 'S36,S37', 'confidence' => 'low', 'verified' => true,
                'ref' => 'proposed_game_parameters.training_minigame_minutes', 'decision' => self::CONFIRMED_R03,
                'notes' => 'Game value (no literature number): S36 / S37 say 5–10 min sessions and ≤ 15 min a day for puppies; 5 min = the dog\'s daily budget (≈ 6 sessions of 50 s), same for every stage, split equally between the children who care for the dog (M5-R03b).',
            ]);
            $add('all', 0, StageParamKey::TrainingProgressPerSuccess, 1.0, [
                'unit' => 'percentage points per correctly timed praise (baseline)', 'confidence' => 'low', 'verified' => true,
                'ref' => 'proposed_game_parameters.training_progress_per_success', 'decision' => self::CONFIRMED_R03,
                'notes' => 'Game value (no literature number): game balance — about 16 good sessions per command for a mixed breed.',
            ]);
            $add('all', 0, StageParamKey::TrainingDecayPerMissedDay, 2.0, [
                'unit' => 'percentage points per missed training day, every command', 'confidence' => 'low', 'verified' => true,
                'ref' => 'proposed_game_parameters.training_decay_per_missed_day', 'decision' => self::CONFIRMED_R03,
                'notes' => 'Game value (no literature number): no source gives a forgetting rate.',
            ]);
            $add('all', 0, StageParamKey::PottyTrainingAccidentReduction, 0.75, [
                'unit' => 'share of due puppy accidents avoided at 100 % potty training', 'source_id' => 'S47,S31', 'confidence' => 'low', 'verified' => true,
                'ref' => 'proposed_game_parameters.potty_training_accident_reduction', 'decision' => self::CONFIRMED_R03_EFFECTS,
                'notes' => 'Game value (no literature number for the size): a house-trained puppy learns to ask to go out (S47); the size of the effect is PetPrep\'s. Bladder hold (S30 / S31) unchanged.',
            ]);
            $add('all', 0, StageParamKey::PlaceTrainingChewingReduction, 0.5, [
                'unit' => 'share of the teething chewing chance removed at 100 % place training', 'source_id' => 'S33', 'confidence' => 'low', 'verified' => true,
                'ref' => 'proposed_game_parameters.place_training_chewing_reduction', 'decision' => self::CONFIRMED_R03_EFFECTS,
                'notes' => 'Game value (no literature number for the size): guidance teaches a puppy to chew its toys (S33); the size of the effect is PetPrep\'s. Chewing after a missed walk stays certain.',
            ]);

            // M5-R03b (David 2026-10-06): a dog arriving young, adult or senior
            // (bought or adopted) already knows some commands; a puppy starts at 0.
            foreach (LifeStage::ordered() as $stage) {
                $add($stage->value, 0, StageParamKey::TrainingStartingProgress, $stage === LifeStage::Puppy
                    ? self::PUPPY_STARTING_PROGRESS : self::GROWN_STARTING_PROGRESS, [
                        'unit' => 'progress % per command when the dog arrives in this stage', 'confidence' => 'low', 'verified' => true,
                        'ref' => 'proposed_game_parameters.training_starting_progress', 'decision' => self::CONFIRMED_R03,
                        'notes' => 'No source; Claude proposal confirmed by David. Applied once when a pet with training is created (arrival stage, any origin); not a session.',
                    ]);
            }

            $add('all', 0, StageParamKey::LifespanYears, ...$p['lifespan']);

            // M5-R13 (David 2026-10-12, "kot pri mački"): the breed's weekly brushing
            // routine; no row = no routine (mutt, Border Collie — no grooming statement).
            $grooming = self::dogGrooming($breed);
            if ($grooming !== null) {
                $add('all', 0, StageParamKey::GroomingSessionsPerWeek, ...$grooming);
            }

            // M5-R16 (David 2026-10-12): the digestion warning after a meal; no row = no warning.
            $gap = self::mealExerciseGap($breed);
            if ($gap !== null) {
                $add('all', 0, StageParamKey::MealExerciseGapMinutes, ...$gap);
            }

            // M5-R17 (David 2026-10-12): the puppy's weekly "new experience" — every dog
            // breed (»socializacija za vse mladičke«), sourced quote where data.json has one.
            $add('puppy', 0, StageParamKey::SocialisationTasksPerWeek, ...self::dogSocialisation($breed));
        }

        return $rows;
    }

    /**
     * M5-R13 dog grooming: `grooming_sessions_per_week` per dog breed from
     * docs/research/dog-data/data.json proposed_game_parameters.<breed>.grooming
     * (runbook §3 rule, David 2026-10-11/12: "once a week" → 1, "every day" → 7,
     * a range A–B → B, "more than once a week" → the most specific number of
     * another source; no number at all → Claude's proposal 2, verified=false).
     * Matted coat at ceil(goal / 2) missed (CatChoreService::mattedAfterMissed).
     * Null = no grooming statement in the sources → no row, no routine.
     *
     * @return array{0: int, 1: array<string, mixed>}|null
     */
    public static function dogGrooming(BreedType $breed): ?array
    {
        $row = self::DOG_GROOMING[$breed->value] ?? null;
        if ($row === null) {
            return null;
        }
        [$value, $sourceId, $quote, $confidence, $proposal] = $row;
        $david = $row[5] ?? null;
        $matted = 'Matted coat when ≥ ceil('.$value.' / 2) of the week\'s slots are missed.';

        return [$value, [
            'unit' => 'brushing sessions/week (≥ 1 day apart)', 'source_id' => $sourceId, 'confidence' => $confidence,
            'verified' => ! $proposal,
            'ref' => "proposed_game_parameters.{$breed->value}.grooming",
            'decision' => $david ?? ($proposal ? self::PROPOSED_R13_DOG_GROOMING : self::CONFIRMED_R13_DOG_GROOMING),
            'quote' => $quote,
            'notes' => match (true) {
                $proposal => 'The sources say "more than once a week" without a number → Claude\'s proposal 2 (open for David). Matted coat when ≥ 1 of 2 is missed (ceil(2 / 2)).',
                $david === self::CONFIRMED_DAVID_2026_10_12_TWICE => 'The sources say "more than once a week" without a number; 2 per week is David\'s decision (2026-10-12). '.$matted,
                $david === self::CONFIRMED_DAVID_2026_10_12_CAVALIER => 'The source (hedged "may find they need to be brushed daily") stays the basis; 4 per week is David\'s choice (2026-10-12), not the source\'s number. '.$matted,
                $david !== null => 'Runbook grooming rule (A–B → B: "once or twice a week" → 2), confirmed by David (2026-10-12). '.$matted,
                default => 'Runbook grooming rule (once a week → 1, every day → 7, A–B → B). '.$matted,
            },
        ]];
    }

    /**
     * M5-R16 meal / exercise gap: `meal_exercise_gap_minutes` for a dog breed whose A/B source
     * says to leave a gap after eating and before exercise (GDV / bloat). The value (60) is
     * David's decision (2026-10-12) — the source gives no length; the row cites the source of
     * the advice. Null = no such statement → no row, no warning.
     *
     * @return array{0: int, 1: array<string, mixed>}|null
     */
    public static function mealExerciseGap(BreedType $breed): ?array
    {
        $row = self::MEAL_EXERCISE_GAP[$breed->value] ?? null;
        if ($row === null) {
            return null;
        }
        [$sourceId, $quote] = $row;

        return [self::MEAL_EXERCISE_GAP_MINUTES, [
            'unit' => 'minutes after a meal (digestion warning only)', 'source_id' => $sourceId, 'confidence' => 'medium',
            'verified' => true,
            'ref' => "proposed_game_parameters.{$breed->value}.meal_exercise_gap",
            'decision' => self::CONFIRMED_R16_MEAL_EXERCISE_GAP,
            'quote' => $quote,
            'notes' => 'The source advises a gap after eating and before exercising (GDV) without a length; 60 minutes is David\'s decision. Only a warning in the child app — steps still count, no score.',
        ]];
    }

    /**
     * M5-R17 puppy socialisation: `socialisation_tasks_per_week` = 1 on the puppy
     * stage for the breeds of DOG_SOCIALISATION (verbatim A/B quote in data.json;
     * the number is David's decision 2026-10-12, so verified). Never scored.
     * Every other dog breed (David 2026-10-12, »socializacija za vse mladičke«):
     * the same value from David's decision alone — source id and quote null,
     * one shared data.json entry `proposed_game_parameters.puppy_socialisation`.
     * Cats never get a row (dog breeds only).
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    public static function dogSocialisation(BreedType $breed): array
    {
        $row = self::DOG_SOCIALISATION[$breed->value] ?? null;
        if ($row === null) {
            return [1, [
                'unit' => 'new-experience tasks/week while a puppy (never scored)', 'source_id' => null, 'confidence' => 'low',
                'verified' => true,
                'ref' => 'proposed_game_parameters.puppy_socialisation',
                'decision' => self::CONFIRMED_R17_ALL_PUPPIES,
                'quote' => null,
                'notes' => 'No breed-specific source quote in data.json; David (12 Oct 2026): every puppy gets one "new experience" (a new person, another dog, a new sound, a new place) per program week while a puppy — shown to the parent, no penalty.',
            ]];
        }
        [$sourceId, $quote] = $row;

        return [1, [
            'unit' => 'new-experience tasks/week while a puppy (never scored)', 'source_id' => $sourceId, 'confidence' => 'medium',
            'verified' => true,
            'ref' => "proposed_game_parameters.{$breed->value}.socialisation",
            'decision' => self::CONFIRMED_R17_SOCIALISATION,
            'quote' => $quote,
            'notes' => 'The source advises socialisation from a young age without an amount; David (2026-10-12): one "new experience" (a new person, another dog, a new sound, a new place) per program week while a puppy — shown to the parent, no penalty.',
        ]];
    }

    /**
     * The breed-specific dog values of rows() (M5-R10): everything else in
     * rows() is general_by_size data shared by every dog breed. Each entry is
     * [value, meta] (meta as in rows()'s $add); `starts_at` is per stage
     * young / adult / senior, `arrival` the non-puppy arrival ages (stage →
     * months) with one shared `arrival_meta`; `individual_variation` null =
     * no row (no per-dog random factor).
     *
     * The mutt and Border Collie profiles are the values rows() had inline
     * before M5-R10 — their rows must stay byte-identical (DogRegressionSnapshotTest,
     * LifeStageDataTest).
     *
     * @return array{starts_at: array<string, array{0: int, 1: array<string, mixed>}>, arrival: array<string, int>, arrival_meta: array<string, mixed>, arrival_meta_overrides?: array<string, array<string, mixed>>, young_per_age_notes: string, adult_minutes: array{0: int, 1: array<string, mixed>}, senior_minutes: array{0: int, 1: array<string, mixed>}, adult_weight: array{0: list<float|int>, 1: array<string, mixed>}, growth_end: array{0: list<int>, 1: array<string, mixed>}, coren_rank: array{0: int|null, 1: array<string, mixed>}, learning_multiplier: array{0: float, 1: array<string, mixed>}, individual_variation: array{0: float, 1: array<string, mixed>}|null, lifespan: array{0: float, 1: array<string, mixed>}}
     */
    public static function dogProfile(BreedType $breed): array
    {
        // Shared by the mutt and the Border Collie (M5-R01, David 2026-10-05).
        $young = [9, [
            'unit' => 'months', 'source_id' => 'S11', 'confidence' => 'medium', 'verified' => true,
            'ref' => 'general_by_size.life_stages.young_adult', 'decision' => self::CONFIRMED,
            'quote' => 'From cessation of rapid growth to completion of physical and social maturation',
            'notes' => 'Game boundary inside the sourced range (no exact month in the literature). AAHA: puppy ends ~6–9 months; 9 = upper end for medium dogs (still 72 % of adult weight at 6 months, S9 logistic proposal).',
        ]];
        $adult = [36, [
            'unit' => 'months', 'source_id' => 'S11', 'confidence' => 'medium', 'verified' => true,
            'ref' => 'general_by_size.life_stages.mature_adult', 'decision' => self::CONFIRMED,
            'quote' => 'From completion of physical and social maturation until the last 25% of estimated lifespan',
            'notes' => 'Game boundary inside the sourced range (no exact month in the literature). Maturation completes at 3–4 years (S11); 36 months = lower end.',
        ]];
        $arrivalMeta = [
            'unit' => 'months', 'verified' => true,
            'ref' => 'proposed_game_parameters.arrival_age_months', 'decision' => self::CONFIRMED,
            'notes' => 'Game value (no literature number): representative age = first month of the stage, so the dog stays in this stage for the 12-week challenge.',
        ];
        $youngPerAgeNotes = 'Game rule; applies "until full-grown" (12–15 months, S10); capped at the adult minutes, so from ~12 months it equals the adult value.';
        $seniorMinutesMeta = [
            'unit' => 'minutes/day', 'verified' => true,
            'ref' => 'proposed_game_parameters.senior_exercise_minutes', 'decision' => self::CONFIRMED,
            'notes' => 'Game value (no literature number): 75 % of the adult minutes. Sources only say "frequent short walks instead of one long one" (S14) and that energy needs fall with age (S13).',
        ];
        $mediumGrowth = fn (string $ref): array => [[12, 15], [
            'unit' => 'months', 'source_id' => 'S10', 'confidence' => 'medium', 'verified' => true,
            'ref' => $ref,
            'quote' => 'Medium (24–59 pounds): 12–15 months',
        ]];

        return match ($breed) {
            BreedType::Mutt => [
                'starts_at' => [
                    'young' => $young,
                    'adult' => $adult,
                    'senior' => [108, [
                        'unit' => 'months', 'source_id' => 'S11,S15', 'confidence' => 'medium', 'verified' => true,
                        'ref' => 'medium_mixed_breed.lifespan.senior_from',
                        'decision' => self::CONFIRMED,
                        'notes' => 'Derived game boundary: last 25 % of lifespan (S11) × median 12.0 y for crossbreeds (S15) = 9.0 y = 108 months. Dogs Trust rule of thumb: > 7 y (S14).',
                    ]],
                ],
                'arrival' => ['young' => 9, 'adult' => 36, 'senior' => 108],
                'arrival_meta' => $arrivalMeta,
                'young_per_age_notes' => $youngPerAgeNotes,
                'adult_minutes' => [60, [
                    'unit' => 'minutes/day', 'verified' => true,
                    'ref' => 'medium_mixed_breed.exercise.adult_game_target', 'decision' => self::CONFIRMED,
                    'notes' => 'Game value (no literature number): 60 min inside the sourced 30–120 min adult range (S24) → ≈ 6,000 steps.',
                ]],
                'senior_minutes' => [45, $seniorMinutesMeta],
                'adult_weight' => [[15, 30], [
                    'unit' => 'kg', 'source_id' => 'S8', 'confidence' => 'medium', 'verified' => true,
                    'ref' => 'medium_mixed_breed.assumed_adult_weight', 'decision' => self::DECISION,
                    'quote' => 'Category IV: 15 to <30 kg',
                    'notes' => 'David 2026-10-05: the game\'s mutt is a medium mixed breed = Salt size category IV (S8).',
                ]],
                'growth_end' => $mediumGrowth('medium_mixed_breed.growth.adult_weight_reached'),
                'coren_rank' => [null, [
                    'unit' => 'rank', 'source_id' => 'S35', 'confidence' => 'medium', 'verified' => true,
                    'ref' => 'medium_mixed_breed.trainability.coren',
                    'quote' => 'Non-AKC/CKC recognized breeds like Jack Russell Terriers were excluded from rankings',
                    'notes' => 'Mixed breeds are not ranked; the game models them as average + individual randomness (proposal, M5 training).',
                ]],
                'learning_multiplier' => [1.0, [
                    'unit' => '× baseline learning speed', 'source_id' => 'S35,S42', 'confidence' => 'low', 'verified' => true,
                    'ref' => 'medium_mixed_breed.trainability.learning_multiplier', 'decision' => self::CONFIRMED_R03,
                    'notes' => 'Game baseline: mixed breeds have no Coren rank (S35); breed explains ~9 % of individual behaviour (S42).',
                ]],
                'individual_variation' => [0.2, [
                    'unit' => '± share of the learning speed, drawn once per dog', 'source_id' => 'S42', 'confidence' => 'low', 'verified' => true,
                    'ref' => 'medium_mixed_breed.trainability.individual_variation', 'decision' => self::CONFIRMED_R03,
                    'notes' => 'Game value: uniform factor in [0.8, 1.2], seeded per pet and stored (pets.training_learning_factor). S42: individuals vary much more than breeds.',
                ]],
                'lifespan' => [12.0, [
                    'unit' => 'years', 'source_id' => 'S15', 'confidence' => 'medium', 'verified' => true,
                    'ref' => 'medium_mixed_breed.lifespan.median_uk_crossbreeds',
                    'quote' => 'This was slightly shorter for crossbred dogs at 12.0 years.',
                ]],
            ],

            BreedType::BorderCollie => [
                'starts_at' => [
                    'young' => $young,
                    'adult' => $adult,
                    'senior' => [118, [
                        'unit' => 'months', 'source_id' => 'S11,S15', 'confidence' => 'medium', 'verified' => true,
                        'ref' => 'border_collie.lifespan.senior_from',
                        'decision' => self::CONFIRMED,
                        'notes' => 'Derived game boundary: last 25 % of lifespan (S11) × median 13.1 y (S15) = 9.8 y = 118 months. Dogs Trust rule of thumb: > 7 y (S14).',
                    ]],
                ],
                'arrival' => ['young' => 9, 'adult' => 36, 'senior' => 118],
                'arrival_meta' => $arrivalMeta,
                'young_per_age_notes' => $youngPerAgeNotes,
                'adult_minutes' => [120, [
                    'unit' => 'minutes/day', 'source_id' => 'S5', 'confidence' => 'high', 'verified' => true,
                    'ref' => 'border_collie.exercise.adult',
                    'quote' => 'Exercise: More than 2 hours per day',
                    'notes' => '"More than 2 hours" → 120 minutes (lower bound).',
                ]],
                'senior_minutes' => [90, $seniorMinutesMeta],
                'adult_weight' => [[13.6, 24.9], [
                    'unit' => 'kg', 'source_id' => 'S4', 'confidence' => 'medium', 'verified' => true,
                    'ref' => 'border_collie.adult_weight.akc', 'quote' => 'weigh between 30 and 55 pounds',
                ]],
                'growth_end' => $mediumGrowth('border_collie.growth.adult_weight_reached'),
                'coren_rank' => [1, [
                    'unit' => 'rank', 'source_id' => 'S34', 'confidence' => 'high', 'verified' => true,
                    'ref' => 'border_collie.trainability.coren_rank',
                    'quote' => '190 of the 199 judges ranked the Border Collie in the top 10',
                ]],
                'learning_multiplier' => [2.0, [
                    'unit' => '× mixed-breed learning speed', 'source_id' => 'S34,S35', 'confidence' => 'low', 'verified' => true,
                    'ref' => 'border_collie.trainability.learning_multiplier', 'decision' => self::CONFIRMED_R03,
                    'notes' => 'Game value (no literature factor): Coren rank #1 (S34), "brightest" tier < 5 repetitions vs 25–40 for average dogs (S35, secondary source). Multiplies the progress per correctly timed praise.',
                ]],
                'individual_variation' => null,
                'lifespan' => [13.1, [
                    'unit' => 'years', 'source_id' => 'S15', 'confidence' => 'high', 'verified' => true,
                    'ref' => 'border_collie.lifespan.median_uk',
                    'quote' => 'Border Collie (13.1 years)',
                ]],
            ],

            // M5-R10 (docs/research/dog-data/data.json labrador_retriever, S48–S62;
            // David's decisions 2026-10-09 in proposed_game_parameters.labrador_retriever).
            BreedType::LabradorRetriever => self::labradorProfile(),

            // M5-R10-02 (docs/research/dog-data/data.json golden_retriever, S63–S75;
            // David's decisions 2026-10-09 in proposed_game_parameters.golden_retriever).
            BreedType::GoldenRetriever => self::goldenProfile(),

            // M5-R10-03 (docs/research/dog-data/data.json french_bulldog, S76–S94;
            // David's decisions 2026-10-10 in proposed_game_parameters.french_bulldog).
            BreedType::FrenchBulldog => self::frenchBulldogProfile(),

            // M5-R10-04 (docs/research/dog-data/data.json german_shepherd, S95–S102;
            // runbook rules in proposed_game_parameters.german_shepherd).
            BreedType::GermanShepherd => self::germanShepherdProfile(),

            // M5-R10-05 (docs/research/dog-data/data.json cavalier_king_charles_spaniel,
            // S103–S110; runbook rules in proposed_game_parameters.cavalier_king_charles_spaniel).
            BreedType::CavalierKingCharlesSpaniel => self::cavalierProfile(),

            // M5-R10-06 (docs/research/dog-data/data.json beagle, S111–S117; runbook
            // rules in proposed_game_parameters.beagle).
            BreedType::Beagle => self::beagleProfile(),

            // M5-R10-07 (docs/research/dog-data/data.json standard_poodle, S118–S123; runbook
            // rules in proposed_game_parameters.standard_poodle).
            BreedType::StandardPoodle => self::standardPoodleProfile(),

            // M5-R10-08 (docs/research/dog-data/data.json dachshund, S124–S130; runbook
            // rules in proposed_game_parameters.dachshund).
            BreedType::Dachshund => self::dachshundProfile(),

            // M5-R10-09 (docs/research/dog-data/data.json australian_shepherd, S131–S135; runbook
            // rules in proposed_game_parameters.australian_shepherd).
            BreedType::AustralianShepherd => self::australianShepherdProfile(),

            // M5-R10-10 (docs/research/dog-data/data.json havanese, S136–S141; runbook
            // rules in proposed_game_parameters.havanese).
            BreedType::Havanese => self::havaneseProfile(),

            // M5-R10-11 (docs/research/dog-data/data.json west_highland_white_terrier,
            // S142–S149; runbook rules in proposed_game_parameters.west_highland_white_terrier).
            BreedType::WestHighlandWhiteTerrier => self::westHighlandWhiteTerrierProfile(),

            // M5-R10-12 (docs/research/dog-data/data.json bernese_mountain_dog,
            // S150–S157; runbook rules in proposed_game_parameters.bernese_mountain_dog).
            BreedType::BerneseMountainDog => self::berneseMountainDogProfile(),

            // M5-R10-13 (docs/research/dog-data/data.json siberian_husky,
            // S158–S165; runbook rules in proposed_game_parameters.siberian_husky).
            BreedType::SiberianHusky => self::siberianHuskyProfile(),

            // M5-R10-19 (docs/research/dog-data/data.json rottweiler,
            // S166–S174; runbook rules in proposed_game_parameters.rottweiler).
            BreedType::Rottweiler => self::rottweilerProfile(),

            // M5-R10-20 (docs/research/dog-data/data.json bulldog,
            // S175–S183; runbook rules in proposed_game_parameters.bulldog).
            BreedType::Bulldog => self::bulldogProfile(),

            // M5-R10-21 (docs/research/dog-data/data.json german_shorthaired_pointer,
            // S184–S189; runbook rules in proposed_game_parameters.german_shorthaired_pointer).
            BreedType::GermanShorthairedPointer => self::germanShorthairedPointerProfile(),

            // M5-R10-22 (docs/research/dog-data/data.json yorkshire_terrier,
            // S190–S196; runbook rules in proposed_game_parameters.yorkshire_terrier).
            BreedType::YorkshireTerrier => self::yorkshireTerrierProfile(),
            // M5-R10-23 (docs/research/dog-data/data.json cane_corso,
            // S197–S201; runbook rules in proposed_game_parameters.cane_corso).
            BreedType::CaneCorso => self::caneCorsoProfile(),
            // M5-R10-24 (docs/research/dog-data/data.json pembroke_welsh_corgi,
            // S202–S206; runbook rules in proposed_game_parameters.pembroke_welsh_corgi).
            BreedType::PembrokeWelshCorgi => self::pembrokeWelshCorgiProfile(),
            // M5-R10-25 (docs/research/dog-data/data.json dobermann,
            // S207–S212; runbook rules in proposed_game_parameters.dobermann).
            BreedType::Dobermann => self::dobermannProfile(),
            // M5-R10-26 (docs/research/dog-data/data.json boxer,
            // S213–S220; runbook rules in proposed_game_parameters.boxer).
            BreedType::Boxer => self::boxerProfile(),
            // M5-R10-27 (docs/research/dog-data/data.json miniature_schnauzer,
            // S221–S228; runbook rules in proposed_game_parameters.miniature_schnauzer).
            BreedType::MiniatureSchnauzer => self::miniatureSchnauzerProfile(),
            // M5-R10-28 (docs/research/dog-data/data.json shih_tzu,
            // S229–S236; runbook rules in proposed_game_parameters.shih_tzu).
            BreedType::ShihTzu => self::shihTzuProfile(),
            // M5-R10-29 (docs/research/dog-data/data.json great_dane,
            // S239–S244; runbook rules in proposed_game_parameters.great_dane).
            BreedType::GreatDane => self::greatDaneProfile(),
            // M5-R10-30 (docs/research/dog-data/data.json pomeranian,
            // S245–S252; runbook rules in proposed_game_parameters.pomeranian).
            BreedType::Pomeranian => self::pomeranianProfile(),
            // M5-R10-31 (docs/research/dog-data/data.json boston_terrier,
            // S253–S263; runbook rules in proposed_game_parameters.boston_terrier).
            BreedType::BostonTerrier => self::bostonTerrierProfile(),
            // M5-R10-32 (docs/research/dog-data/data.json miniature_american_shepherd,
            // S264–S271; runbook rules in proposed_game_parameters.miniature_american_shepherd).
            BreedType::MiniatureAmericanShepherd => self::miniatureAmericanShepherdProfile(),
            // M5-R10-33 (docs/research/dog-data/data.json chihuahua,
            // S272–S280; runbook rules in proposed_game_parameters.chihuahua).
            BreedType::Chihuahua => self::chihuahuaProfile(),
            // M5-R10-34 (docs/research/dog-data/data.json english_springer_spaniel,
            // S281–S288; runbook rules in proposed_game_parameters.english_springer_spaniel).
            BreedType::EnglishSpringerSpaniel => self::englishSpringerSpanielProfile(),
            // M5-R10-35 (docs/research/dog-data/data.json shetland_sheepdog,
            // S289–S295; runbook rules in proposed_game_parameters.shetland_sheepdog).
            BreedType::ShetlandSheepdog => self::shetlandSheepdogProfile(),
            // M5-R10-36 (docs/research/dog-data/data.json brittany,
            // S296–S302; runbook rules in proposed_game_parameters.brittany).
            BreedType::Brittany => self::brittanyProfile(),
            // M5-R10-37 (docs/research/dog-data/data.json american_cocker_spaniel,
            // S303–S311; runbook rules in proposed_game_parameters.american_cocker_spaniel).
            BreedType::AmericanCockerSpaniel => self::americanCockerSpanielProfile(),
            // M5-R10-38 (docs/research/dog-data/data.json belgian_malinois,
            // S312–S318; runbook rules in proposed_game_parameters.belgian_malinois).
            BreedType::BelgianMalinois => self::belgianMalinoisProfile(),
            // M5-R10-39 (docs/research/dog-data/data.json basset_hound,
            // S319–S325; runbook rules in proposed_game_parameters.basset_hound).
            BreedType::BassetHound => self::bassetHoundProfile(),
            // M5-R10-40 (docs/research/dog-data/data.json hungarian_vizsla,
            // S326–S334; runbook rules in proposed_game_parameters.hungarian_vizsla).
            BreedType::HungarianVizsla => self::hungarianVizslaProfile(),
            // M5-R10-41 (docs/research/dog-data/data.json english_cocker_spaniel,
            // S335–S344; runbook rules in proposed_game_parameters.english_cocker_spaniel).
            BreedType::EnglishCockerSpaniel => self::englishCockerSpanielProfile(),
            // M5-R10-42 (docs/research/dog-data/data.json maltese,
            // S345–S352; runbook rules in proposed_game_parameters.maltese).
            BreedType::Maltese => self::malteseProfile(),
            // M5-R10-43 (docs/research/dog-data/data.json pug,
            // S353–S362; runbook rules in proposed_game_parameters.pug).
            BreedType::Pug => self::pugProfile(),

            BreedType::DomesticCat, BreedType::MaineCoon, BreedType::BritishShorthair, BreedType::Ragdoll, BreedType::SiberianCat, BreedType::NorwegianForestCat, BreedType::Bengal => throw new InvalidArgumentException("{$breed->value} is not a dog breed (see catRows())."),
        };
    }

    /**
     * Labrador Retriever (M5-R10). Stage boundaries, arrival ages, exercise
     * minutes and the learning multiplier are David's decisions of 2026-10-09
     * (CONFIRMED_R10); weight, growth, Coren rank and lifespan are sourced.
     *
     * @return array<string, mixed>
     */
    private static function labradorProfile(): array
    {
        $boundary = fn (string $note): array => [
            'unit' => 'months', 'source_id' => 'S11,S10,S8,S54', 'confidence' => 'medium', 'verified' => true,
            'ref' => 'proposed_game_parameters.labrador_retriever.stage_boundaries_months', 'decision' => self::CONFIRMED_R10,
            'notes' => $note,
        ];

        return [
            'starts_at' => [
                'young' => [9, $boundary('Game boundary (no exact month in the literature): AAHA\'s young adult starts at the cessation of RAPID growth (~6–9 months, S11), not the end of growth — a large dog still grows until 15–18 months (S10, up to 24 months S8), which ends inside the young stage. Same 9 as the other dogs (alternative 12 not chosen).')],
                'adult' => [36, $boundary('Game boundary (no exact month in the literature): maturation completes at 3–4 years (S11); 36 months = lower end, same as the other dogs.')],
                'senior' => [118, $boundary('Derived game boundary: last 25 % of lifespan (S11) × median 13.1 y (McMillan 2024, S54) = 9.8 y = 118 months. The VetCompass median 12.0 y (S55) would give 108 months (not chosen).')],
            ],
            'arrival' => ['young' => 9, 'adult' => 36, 'senior' => 118],
            'arrival_meta' => [
                'unit' => 'months', 'verified' => true,
                'ref' => 'proposed_game_parameters.labrador_retriever.arrival_age_months', 'decision' => self::CONFIRMED_R10,
                'notes' => 'Game value (no literature number): first month of the stage (rule of 2026-10-05), so the dog stays in this stage for the 12-week challenge.',
            ],
            'young_per_age_notes' => 'Game rule; applies "until full-grown" (15–18 months for a large dog, S10); capped at the adult 90 minutes, which 10 × 9 months already reaches, so the whole young stage walks the adult minutes.',
            'adult_minutes' => [90, [
                'unit' => 'minutes/day', 'source_id' => 'S59', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'proposed_game_parameters.labrador_retriever.exercise_minutes_adult', 'decision' => self::CONFIRMED_R10,
                'quote' => 'Labrador retrievers generally need at least 90 minutes of exercise daily as adults.',
                'notes' => 'Guide Dogs UK lower bound (S59) → 9,000 steps. Sources disagree: RKC "More than 2 hours per day" (S50), Woodgreen 60–90 min (S60).',
            ]],
            // David 2026-10-09: 75 % of 90 = 67.5 min, stored as whole minutes
            // (StageParamKey::validate, StageRules, API exercise_minutes int) →
            // 68 min = 6,800 steps (confirmed by David 2026-10-09 ~21:00).
            'senior_minutes' => [68, [
                'unit' => 'minutes/day', 'verified' => true,
                'ref' => 'proposed_game_parameters.labrador_retriever.exercise_minutes_senior', 'decision' => self::CONFIRMED_R10,
                'notes' => 'Game value (no literature number): 75 % of the adult 90 minutes = 67.5, rounded to whole minutes → 68 min = 6,800 steps (David confirmed 68, 2026-10-09). Sources only say "frequent short walks instead of one long one" (S14).',
            ]],
            'adult_weight' => [[24.9, 36.3], [
                'unit' => 'kg', 'source_id' => 'S52', 'confidence' => 'high', 'verified' => true,
                'ref' => 'labrador_retriever.adult_weight.akc',
                'quote' => 'Approximate weight of dogs and bitches in working condition: dogs 65 to 80 pounds; bitches 55 to 70 pounds.',
                'notes' => 'AKC standard, bitches 24.9–31.8 kg and dogs 29.5–36.3 kg → overall range.',
            ]],
            'growth_end' => [[15, 18], [
                'unit' => 'months', 'source_id' => 'S10', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'labrador_retriever.growth.adult_weight_reached',
                'quote' => 'Large (59–99 pounds): 15–18 months',
                'notes' => 'Size-class value (Large), not breed-specific; lighter bitches straddle Medium (12–15 months).',
            ]],
            'coren_rank' => [7, [
                'unit' => 'rank', 'source_id' => 'S35', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'labrador_retriever.trainability.coren_rank',
                'quote' => '| 7 | Labrador Retriever |',
                'notes' => 'Coren\'s list as reproduced on Wikipedia (S35); "brightest" tier like the Border Collie.',
            ]],
            'learning_multiplier' => [1.8, [
                'unit' => '× mixed-breed learning speed', 'source_id' => 'S35,S42', 'confidence' => 'low', 'verified' => true,
                'ref' => 'proposed_game_parameters.labrador_retriever.learning_multiplier', 'decision' => self::CONFIRMED_R10,
                'notes' => 'Game value (no literature factor): same Coren "brightest" tier as the Border Collie (S35, 2.0); 1.8 keeps the Border Collie visibly fastest. Multiplies the progress per correctly timed praise.',
            ]],
            // Like the Border Collie: no individual variation row (data.json notes
            // S42 would allow ±20 %, but David decided only the multiplier).
            'individual_variation' => null,
            'lifespan' => [13.1, [
                'unit' => 'years', 'source_id' => 'S54', 'confidence' => 'high', 'verified' => true,
                'ref' => 'labrador_retriever.lifespan.median_uk',
                'quote' => 'Labrador Retriever (orange, x̃= 13.1)',
                'notes' => 'McMillan et al. 2024 (S54); VetCompass 2018 gives 12.0 y (S55).',
            ]],
        ];
    }

    /**
     * Golden Retriever (M5-R10-02). Stage boundaries, arrival ages, exercise
     * minutes and the learning multiplier are David's decisions of 2026-10-09
     * (CONFIRMED_R10); weight, growth, Coren rank and lifespan are sourced.
     *
     * @return array<string, mixed>
     */
    private static function goldenProfile(): array
    {
        $boundary = fn (string $note): array => [
            'unit' => 'months', 'source_id' => 'S11,S10,S8,S15', 'confidence' => 'medium', 'verified' => true,
            'ref' => 'proposed_game_parameters.golden_retriever.stage_boundaries_months', 'decision' => self::CONFIRMED_R10,
            'notes' => $note,
        ];

        return [
            'starts_at' => [
                'young' => [9, $boundary('Game boundary (no exact month in the literature): AAHA\'s young adult starts at the cessation of RAPID growth (~6–9 months, S11), not the end of growth — a large dog still grows until 15–18 months (S10, up to 24 months S8), which ends inside the young stage. Same 9 as the other dogs (alternative 12 not chosen).')],
                'adult' => [36, $boundary('Game boundary (no exact month in the literature): maturation completes at 3–4 years (S11); 36 months = lower end, same as the other dogs.')],
                'senior' => [119, $boundary('Derived game boundary: last 25 % of lifespan (S11) × 13.2 y (Dogs Trust summary of McMillan 2024, S15) = 9.9 y = 118.8 → 119 months. Not chosen: 112 months (VetCompass poster median 12.48 y, S71) and 118 (= Labrador / Border Collie).')],
            ],
            'arrival' => ['young' => 9, 'adult' => 36, 'senior' => 119],
            'arrival_meta' => [
                'unit' => 'months', 'verified' => true,
                'ref' => 'proposed_game_parameters.golden_retriever.arrival_age_months', 'decision' => self::CONFIRMED_R10,
                'notes' => 'Game value (no literature number): first month of the stage (rule of 2026-10-05), so the dog stays in this stage for the 12-week challenge.',
            ],
            'young_per_age_notes' => 'Game rule; applies "until full-grown" (15–18 months for a large dog, S10); capped at the adult 120 minutes, which 10 × age reaches at 12 months (9 months → 90 min, from 12 months the adult 120).',
            'adult_minutes' => [120, [
                'unit' => 'minutes/day', 'source_id' => 'S68,S65', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'proposed_game_parameters.golden_retriever.exercise_minutes_adult', 'decision' => self::CONFIRMED_R10,
                'quote' => 'Your Golden Retriever will need a minimum of two hours of good exercise per day.',
                'notes' => 'PDSA "a minimum of two hours" (S68) and RKC "Exercise: More than 2 hours per day" (S65) → lower bound 120 min = 12,000 steps. Woodgreen 60–90 min (S69) not chosen.',
            ]],
            'senior_minutes' => [90, [
                'unit' => 'minutes/day', 'verified' => true,
                'ref' => 'proposed_game_parameters.golden_retriever.exercise_minutes_senior', 'decision' => self::CONFIRMED_R10,
                'notes' => 'Game value (no literature number): 75 % of the adult 120 minutes = 90 min = 9,000 steps. Sources only say "frequent short walks instead of one long one" (S14).',
            ]],
            'adult_weight' => [[24.9, 34.0], [
                'unit' => 'kg', 'source_id' => 'S67', 'confidence' => 'high', 'verified' => true,
                'ref' => 'golden_retriever.adult_weight.akc',
                'quote' => 'Weight for dogs 65 to 75 pounds; bitches 55 to 65 pounds.',
                'notes' => 'AKC standard, bitches 24.9–29.5 kg and dogs 29.5–34.0 kg → overall range (PDSA S68 / Woodgreen S69: 25–34 kg).',
            ]],
            'growth_end' => [[15, 18], [
                'unit' => 'months', 'source_id' => 'S10', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'golden_retriever.growth.adult_weight_reached',
                'quote' => 'Large (59–99 pounds): 15–18 months',
                'notes' => 'Size-class value (Large), not breed-specific; lighter bitches straddle Medium (12–15 months).',
            ]],
            'coren_rank' => [4, [
                'unit' => 'rank', 'source_id' => 'S34', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'golden_retriever.trainability.coren_rank',
                'quote' => 'Golden Retriever',
                'notes' => 'Coren\'s top-10 list (S34, list entry no. 4); Wikipedia (S35) "| 4 | Golden Retriever |". "Brightest" tier like the Border Collie and the Labrador.',
            ]],
            'learning_multiplier' => [1.9, [
                'unit' => '× mixed-breed learning speed', 'source_id' => 'S34,S35', 'confidence' => 'low', 'verified' => true,
                'ref' => 'proposed_game_parameters.golden_retriever.learning_multiplier', 'decision' => self::CONFIRMED_R10,
                'notes' => 'Game value (no literature factor): Coren rank 4 between the Border Collie (rank 1 → 2.0) and the Labrador (rank 7 → 1.8), linear by rank → 1.9. Multiplies the progress per correctly timed praise.',
            ]],
            // Like the Border Collie and the Labrador: no individual variation row.
            'individual_variation' => null,
            'lifespan' => [13.2, [
                'unit' => 'years', 'source_id' => 'S15', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'golden_retriever.lifespan.median_uk',
                'quote' => 'Golden Retriever (13.2 years)',
                'notes' => 'Dogs Trust summary of McMillan et al. 2024 (S15); VetCompass poster 2012 gives a median of 12.48 y (S71).',
            ]],
        ];
    }

    /**
     * French Bulldog (M5-R10-03). Stage boundaries, arrival ages, exercise
     * minutes and the learning multiplier are David's decisions of 2026-10-10
     * (CONFIRMED_R10_FRENCH_BULLDOG); weight, growth, Coren rank and lifespan
     * are sourced.
     *
     * @return array<string, mixed>
     */
    private static function frenchBulldogProfile(): array
    {
        $decided = self::CONFIRMED_R10_FRENCH_BULLDOG;
        $boundary = fn (string $note): array => [
            'unit' => 'months', 'source_id' => 'S11,S10,S54,S15', 'confidence' => 'medium', 'verified' => true,
            'ref' => 'proposed_game_parameters.french_bulldog.stage_boundaries_months', 'decision' => $decided,
            'notes' => $note,
        ];

        return [
            'starts_at' => [
                'young' => [9, $boundary('Game boundary (no exact month in the literature): AAHA\'s young adult starts at the cessation of rapid growth (~6–9 months, S11); a small / medium dog finishes growing at 9–15 months (S10). Same 9 as the other dogs.')],
                'adult' => [36, $boundary('Game boundary (no exact month in the literature): maturation completes at 3–4 years (S11); 36 months = lower end, same as the other dogs.')],
                'senior' => [88, $boundary('Derived game boundary: last 25 % of lifespan (S11) × median 9.8 y (McMillan 2024, S54; Dogs Trust summary S15) = 7.35 y = 88.2 → 88 months. Not chosen: 90 (0.75 × RKC "over 10 years", S78) and 84 (Dogs Trust > 7 y, S14).')],
            ],
            'arrival' => ['young' => 9, 'adult' => 36, 'senior' => 88],
            'arrival_meta' => [
                'unit' => 'months', 'verified' => true,
                'ref' => 'proposed_game_parameters.french_bulldog.arrival_age_months', 'decision' => $decided,
                'notes' => 'Game value (no literature number): first month of the stage (rule of 2026-10-05), so the dog stays in this stage for the 12-week challenge.',
            ],
            'young_per_age_notes' => 'Game rule; applies "until full-grown" (9–15 months for a small / medium dog, S10); capped at the adult 60 minutes, which 10 × age already reaches at 6 months, so the whole young stage walks the adult minutes.',
            'adult_minutes' => [60, [
                'unit' => 'minutes/day', 'source_id' => 'S78,S81', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'proposed_game_parameters.french_bulldog.exercise_minutes_adult', 'decision' => $decided,
                'quote' => 'Exercise: Up to 1 hour per day',
                'notes' => 'RKC "Up to 1 hour per day" (S78) = PDSA "up to an hour" (S81) → 60 min = 6,000 steps (also Woodgreen\'s lower bound of 60–90 min, S82; 90 not chosen given the breathing / heat advice).',
            ]],
            'senior_minutes' => [45, [
                'unit' => 'minutes/day', 'verified' => true,
                'ref' => 'proposed_game_parameters.french_bulldog.exercise_minutes_senior', 'decision' => $decided,
                'notes' => 'Game value (no literature number): 75 % of the adult 60 minutes = 45 min = 4,500 steps. Sources only say "frequent short walks instead of one long one" (S14).',
            ]],
            'adult_weight' => [[8.0, 14.0], [
                'unit' => 'kg', 'source_id' => 'S76', 'confidence' => 'high', 'verified' => true,
                'ref' => 'french_bulldog.adult_weight.fci',
                'quote' => 'Males: 9–14 kg. Females: 8–13 kg.',
                'notes' => 'FCI standard, females 8–13 kg and males 9–14 kg → overall range (Woodgreen S82: 8–14 kg; RKC ideal 11 / 12.5 kg, S79).',
            ]],
            'growth_end' => [[9, 15], [
                'unit' => 'months', 'source_id' => 'S10', 'confidence' => 'low', 'verified' => true,
                'ref' => 'french_bulldog.growth.adult_weight_reached',
                'quote' => 'Medium (24–59 pounds): 12–15 months',
                'notes' => 'Size-class values, not breed-specific: the FCI weights straddle Small (9–12 months) and Medium (12–15 months) of S10.',
            ]],
            'coren_rank' => [58, [
                'unit' => 'rank', 'source_id' => 'S35', 'confidence' => 'low', 'verified' => true,
                'ref' => 'french_bulldog.trainability.coren_rank',
                'quote' => '| 58 | French Bulldog |',
                'notes' => 'Wikipedia table of Coren\'s ranking (S35), tier "Fair" (ranks 55–69). Coren\'s own article (S34) lists only the top and bottom 10.',
            ]],
            'learning_multiplier' => [0.7, [
                'unit' => '× mixed-breed learning speed', 'source_id' => 'S35,S81,S82', 'confidence' => 'low', 'verified' => true,
                'ref' => 'proposed_game_parameters.french_bulldog.learning_multiplier', 'decision' => $decided,
                'notes' => 'Game value (no literature factor): Coren rank 58 (S35) is in the "Fair" tier, one tier below "Average" (mixed breed 1.0); ~0.3 per tier step → 0.7. PDSA (S81) "easy to train … strong-willed" and Woodgreen (S82) "Moderately easy" were the counterweight (alternative 0.8, not chosen). Multiplies the progress per correctly timed praise.',
            ]],
            // Like the other pedigree breeds: no individual variation row.
            'individual_variation' => null,
            'lifespan' => [9.8, [
                'unit' => 'years', 'source_id' => 'S54', 'confidence' => 'high', 'verified' => true,
                'ref' => 'french_bulldog.lifespan.median_uk',
                'quote' => 'French Bulldog (red, x̃= 9.8)',
                'notes' => 'McMillan et al. 2024 (S54); Dogs Trust summary (S15). Background for the senior boundary only — never shown as a statistic in the app.',
            ]],
        ];
    }

    /**
     * German Shepherd Dog (M5-R10-04). Stage boundaries, arrival ages, exercise
     * minutes and the learning multiplier follow the runbook's standing rules
     * (CONFIRMED_R10_GERMAN_SHEPHERD); weight, growth, Coren rank and lifespan
     * are sourced.
     *
     * @return array<string, mixed>
     */
    private static function germanShepherdProfile(): array
    {
        $decided = self::CONFIRMED_R10_GERMAN_SHEPHERD;
        $boundary = fn (string $note): array => [
            'unit' => 'months', 'source_id' => 'S11,S10,S101', 'confidence' => 'medium', 'verified' => true,
            'ref' => 'proposed_game_parameters.german_shepherd.stage_boundaries_months', 'decision' => $decided,
            'notes' => $note,
        ];

        return [
            'starts_at' => [
                'young' => [9, $boundary('Game boundary (no exact month in the literature): AAHA\'s young adult starts at the cessation of rapid growth (~6–9 months, S11); a large dog finishes growing at 15–18 months (S10). Same 9 as the other dogs.')],
                'adult' => [36, $boundary('Game boundary (no exact month in the literature): maturation completes at 3–4 years (S11); 36 months = lower end, same as the other dogs.')],
                'senior' => [93, $boundary('Derived game boundary: last 25 % of lifespan (S11) × VetCompass median 10.3 y (O\'Neill 2017, S101) = 7.725 y = 92.7 → 93 months. Not chosen: 90 (0.75 × RKC "over 10 years", S97) and 84 (Dogs Trust > 7 y, S14).')],
            ],
            'arrival' => ['young' => 9, 'adult' => 36, 'senior' => 93],
            'arrival_meta' => [
                'unit' => 'months', 'verified' => true,
                'ref' => 'proposed_game_parameters.german_shepherd.arrival_age_months', 'decision' => $decided,
                'notes' => 'Game value (no literature number): first month of the stage (rule of 2026-10-05), so the dog stays in this stage for the 12-week challenge.',
            ],
            'young_per_age_notes' => 'Game rule; applies "until full-grown" (15–18 months for a large dog, S10); capped at the adult 120 minutes, which 10 × age reaches at 12 months.',
            'adult_minutes' => [120, [
                'unit' => 'minutes/day', 'source_id' => 'S97,S100', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'proposed_game_parameters.german_shepherd.exercise_minutes_adult', 'decision' => $decided,
                'quote' => 'Exercise: More than 2 hours per day',
                'notes' => 'RKC "More than 2 hours per day" (S97) = PDSA "a minimum of two hours" (S100) → 120 min = 12,000 steps (runbook rule: "more than N" → N).',
            ]],
            'senior_minutes' => [90, [
                'unit' => 'minutes/day', 'verified' => true,
                'ref' => 'proposed_game_parameters.german_shepherd.exercise_minutes_senior', 'decision' => $decided,
                'notes' => 'Game value (no literature number): 75 % of the adult 120 minutes = 90 min = 9,000 steps. Sources only say "frequent short walks instead of one long one" (S14).',
            ]],
            'adult_weight' => [[22.0, 40.0], [
                'unit' => 'kg', 'source_id' => 'S95', 'confidence' => 'high', 'verified' => true,
                'ref' => 'german_shepherd.adult_weight.fci',
                'quote' => 'Males: Weight: 30 kg to 40 kg; Females: Weight: 22 kg to 32 kg',
                'notes' => 'FCI standard, females 22–32 kg and males 30–40 kg → overall range (PDSA average 35–43 kg, S100; VetCompass medians ♂ 40.1 / ♀ 34.8 kg, S101).',
            ]],
            'growth_end' => [[15, 18], [
                'unit' => 'months', 'source_id' => 'S10', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'german_shepherd.growth.adult_weight_reached',
                'quote' => 'Large (59–99 pounds): 15–18 months',
                'notes' => 'Size-class value, not breed-specific (males are Large; females straddle Medium / Large — same as the Labrador).',
            ]],
            'coren_rank' => [3, [
                'unit' => 'rank', 'source_id' => 'S34', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'german_shepherd.trainability.coren_rank',
                'quote' => 'German Shepherd Dog',
                'notes' => 'Coren\'s own top-10 list (S34): 3rd, tier "Brightest" (ranks 1–10, S35).',
            ]],
            'learning_multiplier' => [1.9, [
                'unit' => '× mixed-breed learning speed', 'source_id' => 'S34,S35', 'confidence' => 'low', 'verified' => true,
                'ref' => 'proposed_game_parameters.german_shepherd.learning_multiplier', 'decision' => $decided,
                'notes' => 'Game value (no literature factor): Brightest tier rule max(1.8, round(2.0 − (rank − 1) / 30, 1)) with rank 3 → 1.9 (= Golden Retriever, rank 4). Multiplies the progress per correctly timed praise.',
            ]],
            // Like the other pedigree breeds: no individual variation row.
            'individual_variation' => null,
            'lifespan' => [10.3, [
                'unit' => 'years', 'source_id' => 'S101', 'confidence' => 'high', 'verified' => true,
                'ref' => 'german_shepherd.lifespan.median_uk',
                'quote' => 'The median longevity of GSDs overall was 10.3 years',
                'notes' => 'O\'Neill et al. 2017, VetCompass (S101); RVC news S102. Background for the senior boundary only — never shown as a statistic in the app.',
            ]],
        ];
    }

    /**
     * Cavalier King Charles Spaniel (M5-R10-05). Stage boundaries, arrival ages,
     * exercise minutes and the learning multiplier follow the runbook's standing
     * rules (CONFIRMED_R10_CAVALIER); weight, growth, Coren rank and lifespan are
     * sourced.
     *
     * @return array<string, mixed>
     */
    private static function cavalierProfile(): array
    {
        $decided = self::CONFIRMED_R10_CAVALIER;
        $boundary = fn (string $note): array => [
            'unit' => 'months', 'source_id' => 'S11,S10,S71', 'confidence' => 'medium', 'verified' => true,
            'ref' => 'proposed_game_parameters.cavalier_king_charles_spaniel.stage_boundaries_months', 'decision' => $decided,
            'notes' => $note,
        ];

        return [
            'starts_at' => [
                'young' => [9, $boundary('Game boundary (no exact month in the literature): AAHA\'s young adult starts at the cessation of rapid growth (~6–9 months, S11); a small dog finishes growing at 9–12 months (S10). Same 9 as the other dogs.')],
                'adult' => [36, $boundary('Game boundary (no exact month in the literature): maturation completes at 3–4 years (S11); 36 months = lower end, same as the other dogs.')],
                'senior' => [90, $boundary('Derived game boundary: last 25 % of lifespan (S11) × VetCompass median 9.99 y (O\'Neill et al. SVEPM 2012 poster, S71) = 7.49 y = 89.9 → 90 months. Not chosen: 108 (0.75 × RKC "over 12 years", S105) and 84 (Dogs Trust > 7 y, S14).')],
            ],
            'arrival' => ['young' => 9, 'adult' => 36, 'senior' => 90],
            'arrival_meta' => [
                'unit' => 'months', 'verified' => true,
                'ref' => 'proposed_game_parameters.cavalier_king_charles_spaniel.arrival_age_months', 'decision' => $decided,
                'notes' => 'Game value (no literature number): first month of the stage (rule of 2026-10-05), so the dog stays in this stage for the 12-week challenge.',
            ],
            'young_per_age_notes' => 'Game rule; applies "until full-grown" (9–12 months for a small dog, S10); capped at the adult 60 minutes, which 10 × age already reaches at 6 months, so the whole young stage walks the adult minutes.',
            'adult_minutes' => [60, [
                'unit' => 'minutes/day', 'source_id' => 'S105,S108', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'proposed_game_parameters.cavalier_king_charles_spaniel.exercise_minutes_adult', 'decision' => $decided,
                'quote' => 'Exercise: Up to 1 hour per day',
                'notes' => 'RKC "Up to 1 hour per day" (S105) = PDSA "at least one hour" (S108) → 60 min = 6,000 steps (runbook rule: "up to N" / "at least N" → N).',
            ]],
            'senior_minutes' => [45, [
                'unit' => 'minutes/day', 'verified' => true,
                'ref' => 'proposed_game_parameters.cavalier_king_charles_spaniel.exercise_minutes_senior', 'decision' => $decided,
                'notes' => 'Game value (no literature number): 75 % of the adult 60 minutes = 45 min = 4,500 steps. Sources only say "frequent short walks instead of one long one" (S14).',
            ]],
            'adult_weight' => [[5.4, 8.0], [
                'unit' => 'kg', 'source_id' => 'S103', 'confidence' => 'high', 'verified' => true,
                'ref' => 'cavalier_king_charles_spaniel.adult_weight.fci',
                'quote' => 'WEIGHT: 5,4 - 8 kg.',
                'notes' => 'FCI standard, one range for both sexes (RKC standard 5.4–8.2 kg, S106; PDSA average 5.4–8.2 kg, S108).',
            ]],
            'growth_end' => [[9, 12], [
                'unit' => 'months', 'source_id' => 'S10', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'cavalier_king_charles_spaniel.growth.adult_weight_reached',
                'quote' => 'Small (12–24 pounds): 9–12 months',
                'notes' => 'Size-class value, not breed-specific (5.4–8.2 kg = 12–18 lb, the Small class of S10).',
            ]],
            'coren_rank' => [44, [
                'unit' => 'rank', 'source_id' => 'S35', 'confidence' => 'low', 'verified' => true,
                'ref' => 'cavalier_king_charles_spaniel.trainability.coren_rank',
                'quote' => '44 | Cavalier King Charles Spaniel',
                'notes' => 'Wikipedia table of Coren\'s ranking (S35), tier "Average" (ranks 40–54). Coren\'s own article (S34) lists only the top and bottom 10.',
            ]],
            'learning_multiplier' => [1.0, [
                'unit' => '× mixed-breed learning speed', 'source_id' => 'S35', 'confidence' => 'low', 'verified' => true,
                'ref' => 'proposed_game_parameters.cavalier_king_charles_spaniel.learning_multiplier', 'decision' => $decided,
                'notes' => 'Game value (no literature factor): Coren rank 44 (S35) is in the "Average" tier → 1.0, the same speed as the mixed breed (PDSA S108: "fairly easy to train"). Multiplies the progress per correctly timed praise.',
            ]],
            // Like the other pedigree breeds: no individual variation row.
            'individual_variation' => null,
            'lifespan' => [9.99, [
                'unit' => 'years', 'source_id' => 'S71', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'cavalier_king_charles_spaniel.lifespan.median_uk',
                'quote' => 'Cavalier King Charles Spaniel 121 9.99 8.14-12.39',
                'notes' => 'O\'Neill et al. VetCompass poster (SVEPM 2012, S71): median longevity of 121 deaths. McMillan 2024 (S54) gives no reachable Cavalier value. Background for the senior boundary only — never shown as a statistic in the app.',
            ]],
        ];
    }

    /**
     * Beagle (M5-R10-06). Stage boundaries, arrival ages, exercise minutes and the
     * learning multiplier follow the runbook's standing rules (CONFIRMED_R10_BEAGLE);
     * weight, growth, Coren rank and lifespan are sourced.
     *
     * @return array<string, mixed>
     */
    private static function beagleProfile(): array
    {
        $decided = self::CONFIRMED_R10_BEAGLE;
        $boundary = fn (string $note): array => [
            'unit' => 'months', 'source_id' => 'S11,S10,S116', 'confidence' => 'medium', 'verified' => true,
            'ref' => 'proposed_game_parameters.beagle.stage_boundaries_months', 'decision' => $decided,
            'notes' => $note,
        ];

        return [
            'starts_at' => [
                'young' => [9, $boundary('Game boundary (no exact month in the literature): AAHA\'s young adult starts at the cessation of rapid growth (~6–9 months, S11); a small dog finishes growing at 9–12 months (S10). Same 9 as the other dogs.')],
                'adult' => [36, $boundary('Game boundary (no exact month in the literature): maturation completes at 3–4 years (S11); 36 months = lower end, same as the other dogs.')],
                'senior' => [102, $boundary('Derived game boundary: last 25 % of lifespan (S11) × VetCompass median age at death 11.28 y (O\'Neill et al. 2025, S116) = 8.46 y = 101.5 → 102 months. Not chosen: 105 (0.75 × 11.70 y, the paper\'s Conclusions), 108 (0.75 × RKC "over 12 years", S113) and 84 (Dogs Trust > 7 y, S14).')],
            ],
            'arrival' => ['young' => 9, 'adult' => 36, 'senior' => 102],
            'arrival_meta' => [
                'unit' => 'months', 'verified' => true,
                'ref' => 'proposed_game_parameters.beagle.arrival_age_months', 'decision' => $decided,
                'notes' => 'Game value (no literature number): first month of the stage (rule of 2026-10-05), so the dog stays in this stage for the 12-week challenge.',
            ],
            'young_per_age_notes' => 'Game rule; applies "until full-grown" (9–12 months for a small dog, S10); capped at the adult 60 minutes, which 10 × age already reaches at 6 months, so the whole young stage walks the adult minutes.',
            'adult_minutes' => [60, [
                'unit' => 'minutes/day', 'source_id' => 'S113', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'proposed_game_parameters.beagle.exercise_minutes_adult', 'decision' => $decided,
                'quote' => 'Exercise: Up to 1 hour per day',
                'notes' => 'RKC "Up to 1 hour per day" (S113) → 60 min = 6,000 steps (runbook rule: "up to N" → N; sources conflict → RKC). PDSA says "at least an hour and a half" in its text but "1 hour" in its key facts (S115) — recorded as the alternative.',
            ]],
            'senior_minutes' => [45, [
                'unit' => 'minutes/day', 'verified' => true,
                'ref' => 'proposed_game_parameters.beagle.exercise_minutes_senior', 'decision' => $decided,
                'notes' => 'Game value (no literature number): 75 % of the adult 60 minutes = 45 min = 4,500 steps. Sources only say "frequent short walks instead of one long one" (S14).',
            ]],
            'adult_weight' => [[9.0, 11.0], [
                'unit' => 'kg', 'source_id' => 'S115', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'beagle.adult_weight.pdsa',
                'quote' => 'Average weight: 9-11 kg',
                'notes' => 'PDSA key facts, one range for both sexes. The FCI (S111) and RKC (S114) standards give a height only (33–40 cm). Measured UK pet Beagles are heavier (S116 — research only; obesity is common).',
            ]],
            'growth_end' => [[9, 12], [
                'unit' => 'months', 'source_id' => 'S10', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'beagle.growth.adult_weight_reached',
                'quote' => 'Small (12–24 pounds): 9–12 months',
                'notes' => 'Size-class value, not breed-specific (9–11 kg = 20–24 lb, the Small class of S10).',
            ]],
            'coren_rank' => [72, [
                'unit' => 'rank', 'source_id' => 'S35', 'confidence' => 'low', 'verified' => true,
                'ref' => 'beagle.trainability.coren_rank',
                'quote' => '72 | Mastiff / Beagle',
                'notes' => 'Wikipedia table of Coren\'s ranking (S35), rank 72 tied with the Mastiff, tier "Lowest" (ranks 70–79). Coren\'s own article (S34) was not reachable in this run.',
            ]],
            'learning_multiplier' => [0.5, [
                'unit' => '× mixed-breed learning speed', 'source_id' => 'S35', 'confidence' => 'low', 'verified' => true,
                'ref' => 'proposed_game_parameters.beagle.learning_multiplier', 'decision' => $decided,
                'notes' => 'Game value (no literature factor): Coren rank 72 (S35) is in the "Lowest" tier → 0.5, half the mixed breed\'s speed (PDSA S115: "mischievous characters", start reward-based training early). Multiplies the progress per correctly timed praise.',
            ]],
            // Like the other pedigree breeds: no individual variation row.
            'individual_variation' => null,
            'lifespan' => [11.28, [
                'unit' => 'years', 'source_id' => 'S116', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'beagle.lifespan.median_uk',
                'quote' => 'The median age at death was 11.28 years (IQR 9.32–13.08) for 322 deaths recorded during the study period.',
                'notes' => 'O\'Neill et al. 2025 (S116), VetCompass UK 2019 (the Conclusions say 11.70 y). McMillan 2024 (S54) gives no reachable Beagle value. Background for the senior boundary only — never shown as a statistic in the app.',
            ]],
        ];
    }

    /**
     * Standard Poodle (M5-R10-07). Stage boundaries, arrival ages, exercise minutes and the
     * learning multiplier follow the runbook's standing rules (CONFIRMED_R10_STANDARD_POODLE);
     * weight, growth, Coren rank and lifespan are sourced.
     *
     * @return array<string, mixed>
     */
    private static function standardPoodleProfile(): array
    {
        $decided = self::CONFIRMED_R10_STANDARD_POODLE;
        $boundary = fn (string $note): array => [
            'unit' => 'months', 'source_id' => 'S11,S10,S120', 'confidence' => 'medium', 'verified' => true,
            'ref' => 'proposed_game_parameters.standard_poodle.stage_boundaries_months', 'decision' => $decided,
            'notes' => $note,
        ];

        return [
            'starts_at' => [
                'young' => [9, $boundary('Game boundary (no exact month in the literature): AAHA\'s young adult starts at the cessation of rapid growth (~6–9 months, S11); a large dog finishes growing at 15–18 months (S10). Same 9 as the other dogs.')],
                'adult' => [36, $boundary('Game boundary (no exact month in the literature): maturation completes at 3–4 years (S11); 36 months = lower end, same as the other dogs.')],
                'senior' => [108, $boundary('Derived game boundary: last 25 % of lifespan (S11) × RKC lifespan lower bound "Over 12 years" (S120; PDSA 12–14 y, S122) = 9 y = 108 months. McMillan 2024 has no Standard Poodle value (the Dogs Trust row "Poodle" pools all varieties, S123) and no VetCompass Standard Poodle median was found. Not chosen: 126 (0.75 × 14.0 y pooled, S123) and 84 (Dogs Trust > 7 y, S14).')],
            ],
            'arrival' => ['young' => 9, 'adult' => 36, 'senior' => 108],
            'arrival_meta' => [
                'unit' => 'months', 'verified' => true,
                'ref' => 'proposed_game_parameters.standard_poodle.arrival_age_months', 'decision' => $decided,
                'notes' => 'Game value (no literature number): first month of the stage (rule of 2026-10-05), so the dog stays in this stage for the 12-week challenge.',
            ],
            'young_per_age_notes' => 'Game rule; applies "until full-grown" (15–18 months for a large dog, S10); capped at the adult 60 minutes, which 10 × age already reaches at 6 months, so the whole young stage walks the adult minutes.',
            'adult_minutes' => [60, [
                'unit' => 'minutes/day', 'source_id' => 'S120', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'proposed_game_parameters.standard_poodle.exercise_minutes_adult', 'decision' => $decided,
                'quote' => 'Exercise: Up to 1 hour per day',
                'notes' => 'RKC "Up to 1 hour per day" (S120) → 60 min = 6,000 steps (runbook rule: "up to N" → N). PDSA "around an hour of exercise daily" (S122) agrees.',
            ]],
            'senior_minutes' => [45, [
                'unit' => 'minutes/day', 'verified' => true,
                'ref' => 'proposed_game_parameters.standard_poodle.exercise_minutes_senior', 'decision' => $decided,
                'notes' => 'Game value (no literature number): 75 % of the adult 60 minutes = 45 min = 4,500 steps. Sources only say "frequent short walks instead of one long one" (S14).',
            ]],
            'adult_weight' => [[21.0, 35.0], [
                'unit' => 'kg', 'source_id' => 'S122', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'standard_poodle.adult_weight.pdsa',
                'quote' => 'Male: 30kg-35kg. Female: 21kg-32kg',
                'notes' => 'PDSA key facts (males 30–35 kg, females 21–32 kg; the page is "Poodle", size Large). The FCI (S118) and RKC (S121) standards give a height only (FCI 45–60 cm, RKC over 38 cm).',
            ]],
            'growth_end' => [[15, 18], [
                'unit' => 'months', 'source_id' => 'S10', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'standard_poodle.growth.adult_weight_reached',
                'quote' => 'Large (59–99 pounds): 15–18 months',
                'notes' => 'Size-class value, not breed-specific (21–35 kg = 46–77 lb; males in the Large class of S10, females straddle Medium 12–15 months).',
            ]],
            'coren_rank' => [2, [
                'unit' => 'rank', 'source_id' => 'S35', 'confidence' => 'low', 'verified' => true,
                'ref' => 'standard_poodle.trainability.coren_rank',
                'quote' => '| 2 | Poodle |',
                'notes' => 'Wikipedia table of Coren\'s ranking (S35), rank 2 (the breed "Poodle", varieties not separated), tier "Brightest" (ranks 1–10). Coren\'s own article (S34) was not reachable in this run.',
            ]],
            'learning_multiplier' => [2.0, [
                'unit' => '× mixed-breed learning speed', 'source_id' => 'S35', 'confidence' => 'low', 'verified' => true,
                'ref' => 'proposed_game_parameters.standard_poodle.learning_multiplier', 'decision' => $decided,
                'notes' => 'Game value (no literature factor): Coren rank 2 (S35) in the "Brightest" tier → max(1.8, round(2.0 − 1/30, 1)) = 2.0, twice the mixed breed\'s speed (PDSA S122: "very obedient and respond well to training"). Multiplies the progress per correctly timed praise.',
            ]],
            // Like the other pedigree breeds: no individual variation row.
            'individual_variation' => null,
            'lifespan' => [12.0, [
                'unit' => 'years', 'source_id' => 'S120', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'standard_poodle.lifespan.rkc',
                'quote' => 'Lifespan: Over 12 years',
                'notes' => 'RKC breed page lower bound (S120; PDSA 12–14 y, S122). No variety-specific McMillan 2024 or VetCompass median (the pooled "Poodle" 14.0 y, S123, is not used). Background for the senior boundary only — never shown as a statistic in the app.',
            ]],
        ];
    }

    /**
     * Dachshund, standard size (M5-R10-08). Stage boundaries, arrival ages, exercise minutes
     * and the learning multiplier follow the runbook's standing rules (CONFIRMED_R10_DACHSHUND);
     * weight, growth, Coren rank and lifespan are sourced.
     *
     * @return array<string, mixed>
     */
    private static function dachshundProfile(): array
    {
        $decided = self::CONFIRMED_R10_DACHSHUND;
        $boundary = fn (string $note): array => [
            'unit' => 'months', 'source_id' => 'S11,S10,S125', 'confidence' => 'medium', 'verified' => true,
            'ref' => 'proposed_game_parameters.dachshund.stage_boundaries_months', 'decision' => $decided,
            'notes' => $note,
        ];

        return [
            'starts_at' => [
                'young' => [9, $boundary('Game boundary (no exact month in the literature): AAHA\'s young adult starts at the cessation of rapid growth (~6–9 months, S11); a small dog finishes growing at 9–12 months (S10). Same 9 as the other dogs.')],
                'adult' => [36, $boundary('Game boundary (no exact month in the literature): maturation completes at 3–4 years (S11); 36 months = lower end, same as the other dogs.')],
                'senior' => [108, $boundary('Derived game boundary: last 25 % of lifespan (S11) × RKC lifespan lower bound "Over 12 years" (S125; PDSA "Over 12 years", S127) = 9 y = 108 months. McMillan 2024 names only the Miniature Dachshund (14.0 y, S130) and no standard-size VetCompass median was found. Not chosen: 126 (0.75 × 14.0 y miniature, S130) and 84 (Dogs Trust > 7 y, S14).')],
            ],
            'arrival' => ['young' => 9, 'adult' => 36, 'senior' => 108],
            'arrival_meta' => [
                'unit' => 'months', 'verified' => true,
                'ref' => 'proposed_game_parameters.dachshund.arrival_age_months', 'decision' => $decided,
                'notes' => 'Game value (no literature number): first month of the stage (rule of 2026-10-05), so the dog stays in this stage for the 12-week challenge.',
            ],
            'young_per_age_notes' => 'Game rule; applies "until full-grown" (9–12 months for a small dog, S10); capped at the adult 60 minutes, which 10 × age already reaches at 6 months, so the whole young stage walks the adult minutes. PDSA asks to take exercise easy while a Dachshund is growing (S127).',
            'adult_minutes' => [60, [
                'unit' => 'minutes/day', 'source_id' => 'S125', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'proposed_game_parameters.dachshund.exercise_minutes_adult', 'decision' => $decided,
                'quote' => 'Exercise: Up to 1 hour per day',
                'notes' => 'RKC "Up to 1 hour per day" (S125) → 60 min = 6,000 steps (runbook rule: "up to N" → N). PDSA "a minimum of an hour exercise every day" for the standard size (S127) agrees.',
            ]],
            'senior_minutes' => [45, [
                'unit' => 'minutes/day', 'verified' => true,
                'ref' => 'proposed_game_parameters.dachshund.exercise_minutes_senior', 'decision' => $decided,
                'notes' => 'Game value (no literature number): 75 % of the adult 60 minutes = 45 min = 4,500 steps. Sources only say "frequent short walks instead of one long one" (S14).',
            ]],
            'adult_weight' => [[9.0, 12.0], [
                'unit' => 'kg', 'source_id' => 'S126', 'confidence' => 'high', 'verified' => true,
                'ref' => 'dachshund.adult_weight.rkc',
                'quote' => 'Ideal weight: 9-12 kgs (20-26 lbs).',
                'notes' => 'RKC breed standard, Dachshund (Smooth Haired), one range for both sexes; PDSA "Standard 9-12kg" (S127) agrees. Miniatures (under 5 kg, S125) are not this breed entry.',
            ]],
            'growth_end' => [[9, 12], [
                'unit' => 'months', 'source_id' => 'S10', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'dachshund.growth.adult_weight_reached',
                'quote' => 'Small (12–24 pounds): 9–12 months',
                'notes' => 'Size-class value, not breed-specific (9–12 kg = 19.8–26.5 lb; midpoint ≈ 23 lb in the Small class of S10, the upper end touches Medium 12–15 months).',
            ]],
            'coren_rank' => [49, [
                'unit' => 'rank', 'source_id' => 'S35', 'confidence' => 'low', 'verified' => true,
                'ref' => 'dachshund.trainability.coren_rank',
                'quote' => '| 49 | Dachshund |',
                'notes' => 'Wikipedia table of Coren\'s ranking (S35), rank 49, tier "Average" (ranks 40–54). Coren\'s own article (S34) lists only the top / bottom 10.',
            ]],
            'learning_multiplier' => [1.0, [
                'unit' => '× mixed-breed learning speed', 'source_id' => 'S35', 'confidence' => 'low', 'verified' => true,
                'ref' => 'proposed_game_parameters.dachshund.learning_multiplier', 'decision' => $decided,
                'notes' => 'Game value (no literature factor): Coren rank 49 (S35) in the "Average" tier → 1.0, the mixed breed\'s speed (PDSA S127: "can be wilful when it comes to training"). Multiplies the progress per correctly timed praise.',
            ]],
            // Like the other pedigree breeds: no individual variation row.
            'individual_variation' => null,
            'lifespan' => [12.0, [
                'unit' => 'years', 'source_id' => 'S125', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'dachshund.lifespan.rkc',
                'quote' => 'Lifespan: Over 12 years',
                'notes' => 'RKC breed page lower bound (S125; PDSA "Over 12 years", S127). No standard-size McMillan 2024 or VetCompass median (the Miniature Dachshund 14.0 y, S130, is not used). Background for the senior boundary only — never shown as a statistic in the app.',
            ]],
        ];
    }

    /**
     * Australian Shepherd (M5-R10-09). Stage boundaries, arrival ages, exercise minutes and the
     * learning multiplier follow the runbook's standing rules (CONFIRMED_R10_AUSTRALIAN_SHEPHERD);
     * weight, growth, Coren rank and lifespan are sourced.
     *
     * @return array<string, mixed>
     */
    private static function australianShepherdProfile(): array
    {
        $decided = self::CONFIRMED_R10_AUSTRALIAN_SHEPHERD;
        $boundary = fn (string $note): array => [
            'unit' => 'months', 'source_id' => 'S11,S10,S133', 'confidence' => 'medium', 'verified' => true,
            'ref' => 'proposed_game_parameters.australian_shepherd.stage_boundaries_months', 'decision' => $decided,
            'notes' => $note,
        ];

        return [
            'starts_at' => [
                'young' => [9, $boundary('Game boundary (no exact month in the literature): AAHA\'s young adult starts at the cessation of rapid growth (~6–9 months, S11); a medium dog finishes growing at 12–15 months (S10). Same 9 as the other dogs.')],
                'adult' => [36, $boundary('Game boundary (no exact month in the literature): maturation completes at 3–4 years (S11); 36 months = lower end, same as the other dogs.')],
                'senior' => [90, $boundary('Derived game boundary: last 25 % of lifespan (S11) × RKC lifespan lower bound "Over 10 years" (S133; PDSA "Over 10 years", S134) = 7.5 y = 90 months. McMillan 2024 has no Australian Shepherd value in its text (S135) and no VetCompass median was found. Not chosen: 84 (Dogs Trust > 7 y, S14). Provisional until McMillan 2024 is checked.')],
            ],
            'arrival' => ['young' => 9, 'adult' => 36, 'senior' => 90],
            'arrival_meta' => [
                'unit' => 'months', 'verified' => true,
                'ref' => 'proposed_game_parameters.australian_shepherd.arrival_age_months', 'decision' => $decided,
                'notes' => 'Game value (no literature number): first month of the stage (rule of 2026-10-05), so the dog stays in this stage for the 12-week challenge.',
            ],
            'young_per_age_notes' => 'Game rule; applies "until full-grown" (12–15 months for a medium dog, S10); capped at the adult 120 minutes, which 10 × age reaches at 12 months.',
            'adult_minutes' => [120, [
                'unit' => 'minutes/day', 'source_id' => 'S133', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'proposed_game_parameters.australian_shepherd.exercise_minutes_adult', 'decision' => $decided,
                'quote' => 'Exercise: More than 2 hours per day',
                'notes' => 'RKC "More than 2 hours per day" (S133) → 120 min = 12,000 steps (runbook rule: "more than N" → N). PDSA "a minimum of two hours exercise every day" (S134) agrees.',
            ]],
            'senior_minutes' => [90, [
                'unit' => 'minutes/day', 'verified' => true,
                'ref' => 'proposed_game_parameters.australian_shepherd.exercise_minutes_senior', 'decision' => $decided,
                'notes' => 'Game value (no literature number): 75 % of the adult 120 minutes = 90 min = 9,000 steps. Sources only say "frequent short walks instead of one long one" (S14).',
            ]],
            'adult_weight' => [[18.0, 29.0], [
                'unit' => 'kg', 'source_id' => 'S134', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'australian_shepherd.adult_weight.pdsa',
                'quote' => '18-29 kg',
                'notes' => 'PDSA key facts (S134), one range for both sexes. The FCI standard (S131) gives height only (males 51–58 cm, females 46–53 cm).',
            ]],
            'growth_end' => [[12, 15], [
                'unit' => 'months', 'source_id' => 'S10', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'australian_shepherd.growth.adult_weight_reached',
                'quote' => 'Medium (24–59 pounds): 12–15 months',
                'notes' => 'Size-class value, not breed-specific (18–29 kg = 39.7–63.9 lb, mostly the Medium class of S10; RKC / PDSA size "Medium").',
            ]],
            'coren_rank' => [42, [
                'unit' => 'rank', 'source_id' => 'S35', 'confidence' => 'low', 'verified' => true,
                'ref' => 'australian_shepherd.trainability.coren_rank',
                'quote' => '| 42 | Kuvasz / Australian Shepherd |',
                'notes' => 'Wikipedia table of Coren\'s ranking (S35), rank 42 (tied with the Kuvasz), tier "Average" (ranks 40–54). Coren\'s own article (S34) lists only the top / bottom 10.',
            ]],
            'learning_multiplier' => [1.0, [
                'unit' => '× mixed-breed learning speed', 'source_id' => 'S35', 'confidence' => 'low', 'verified' => true,
                'ref' => 'proposed_game_parameters.australian_shepherd.learning_multiplier', 'decision' => $decided,
                'notes' => 'Game value (no literature factor): Coren rank 42 (S35) in the "Average" tier → 1.0, the mixed breed\'s speed (PDSA S134: "clever dogs who need positive, reward-based training from a young age"). Multiplies the progress per correctly timed praise.',
            ]],
            // Like the other pedigree breeds: no individual variation row.
            'individual_variation' => null,
            'lifespan' => [10.0, [
                'unit' => 'years', 'source_id' => 'S133', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'australian_shepherd.lifespan.rkc',
                'quote' => 'Lifespan: Over 10 years',
                'notes' => 'RKC breed page lower bound (S133; PDSA "Over 10 years", S134). No McMillan 2024 (S135) or VetCompass median found. Background for the senior boundary only — never shown as a statistic in the app.',
            ]],
        ];
    }

    /**
     * Havanese (M5-R10-10). Stage boundaries, arrival ages, exercise minutes and the
     * learning multiplier follow the runbook's standing rules (CONFIRMED_R10_HAVANESE);
     * weight, growth and lifespan are sourced; the breed has no Coren rank.
     *
     * @return array<string, mixed>
     */
    private static function havaneseProfile(): array
    {
        $decided = self::CONFIRMED_R10_HAVANESE;
        $boundary = fn (string $note): array => [
            'unit' => 'months', 'source_id' => 'S11,S10,S138', 'confidence' => 'medium', 'verified' => true,
            'ref' => 'proposed_game_parameters.havanese.stage_boundaries_months', 'decision' => $decided,
            'notes' => $note,
        ];

        return [
            'starts_at' => [
                'young' => [9, $boundary('Game boundary (no exact month in the literature): AAHA\'s young adult starts at the cessation of rapid growth (~6–9 months, S11); a toy dog finishes growing at 8–12 months (S10). Same 9 as the other dogs.')],
                'adult' => [36, $boundary('Game boundary (no exact month in the literature): maturation completes at 3–4 years (S11); 36 months = lower end, same as the other dogs.')],
                'senior' => [108, $boundary('Derived game boundary: last 25 % of lifespan (S11) × RKC lifespan lower bound "Over 12 years" (S138; PDSA "Over 12 years", S140) = 9 y = 108 months. No McMillan 2024 or VetCompass Havanese median was reachable. Not chosen: 84 (Dogs Trust > 7 y, S14). Provisional until McMillan 2024 is checked.')],
            ],
            'arrival' => ['young' => 9, 'adult' => 36, 'senior' => 108],
            'arrival_meta' => [
                'unit' => 'months', 'verified' => true,
                'ref' => 'proposed_game_parameters.havanese.arrival_age_months', 'decision' => $decided,
                'notes' => 'Game value (no literature number): first month of the stage (rule of 2026-10-05), so the dog stays in this stage for the 12-week challenge.',
            ],
            'young_per_age_notes' => 'Game rule; applies "until full-grown" (8–12 months for a toy dog, S10); capped at the adult 30 minutes, which 10 × age reaches at 3 months.',
            'adult_minutes' => [30, [
                'unit' => 'minutes/day', 'source_id' => 'S138', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'proposed_game_parameters.havanese.exercise_minutes_adult', 'decision' => $decided,
                'quote' => 'Exercise: Up to 30 minutes per day',
                'notes' => 'RKC "Up to 30 minutes per day" (S138) → 30 min = 3,000 steps (runbook rule: "up to N" → N). PDSA "around 30 minutes of exercise per day" (S140) agrees.',
            ]],
            'senior_minutes' => [23, [
                'unit' => 'minutes/day', 'verified' => true,
                'ref' => 'proposed_game_parameters.havanese.exercise_minutes_senior', 'decision' => $decided,
                'notes' => 'Game value (no literature number): 75 % of the adult 30 minutes = 22.5, rounded half up = 23 min = 2,300 steps. Sources only say "frequent short walks instead of one long one" (S14).',
            ]],
            'adult_weight' => [[3.0, 6.0], [
                'unit' => 'kg', 'source_id' => 'S140', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'havanese.adult_weight.pdsa',
                'quote' => '3-6 kg',
                'notes' => 'PDSA key facts (S140), one range for both sexes. The FCI (S136), RKC (S139) and AKC (S141) standards give height only (FCI 23–27 cm).',
            ]],
            'growth_end' => [[8, 12], [
                'unit' => 'months', 'source_id' => 'S10', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'havanese.growth.adult_weight_reached',
                'quote' => 'Toy (5–12 pounds): 8–12 months',
                'notes' => 'Size-class value, not breed-specific (3–6 kg = 6.6–13.2 lb, the Toy class of S10; RKC / PDSA size "Small").',
            ]],
            'coren_rank' => [null, [
                'unit' => 'rank', 'source_id' => 'S35', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'havanese.trainability.coren_rank',
                'notes' => 'Not ranked: the Havanese is not in the Wikipedia table of Coren\'s ranking (S35, 79 ranks, checked 2026-10-10).',
            ]],
            'learning_multiplier' => [1.0, [
                'unit' => '× mixed-breed learning speed', 'source_id' => 'S35', 'confidence' => 'low', 'verified' => true,
                'ref' => 'proposed_game_parameters.havanese.learning_multiplier', 'decision' => $decided,
                'notes' => 'Game value (no literature factor): not ranked by Coren (S35) → 1.0, the mixed breed\'s speed (PDSA S140: "They are easy to train"). Multiplies the progress per correctly timed praise.',
            ]],
            // Like the other pedigree breeds: no individual variation row.
            'individual_variation' => null,
            'lifespan' => [12.0, [
                'unit' => 'years', 'source_id' => 'S138', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'havanese.lifespan.rkc',
                'quote' => 'Lifespan: Over 12 years',
                'notes' => 'RKC breed page lower bound (S138; PDSA "Over 12 years", S140). No McMillan 2024 or VetCompass median reachable. Background for the senior boundary only — never shown as a statistic in the app.',
            ]],
        ];
    }

    /**
     * West Highland White Terrier (M5-R10-11). Stage boundaries, arrival ages, exercise
     * minutes and the learning multiplier follow the runbook's standing rules
     * (CONFIRMED_R10_WEST_HIGHLAND_WHITE_TERRIER); weight, growth, Coren rank and lifespan
     * are sourced.
     *
     * @return array<string, mixed>
     */
    private static function westHighlandWhiteTerrierProfile(): array
    {
        $decided = self::CONFIRMED_R10_WEST_HIGHLAND_WHITE_TERRIER;
        $boundary = fn (string $note): array => [
            'unit' => 'months', 'source_id' => 'S11,S10,S148', 'confidence' => 'medium', 'verified' => true,
            'ref' => 'proposed_game_parameters.west_highland_white_terrier.stage_boundaries_months', 'decision' => $decided,
            'notes' => $note,
        ];

        return [
            'starts_at' => [
                'young' => [9, $boundary('Game boundary (no exact month in the literature): AAHA\'s young adult starts at the cessation of rapid growth (~6–9 months, S11); a small dog finishes growing at 9–12 months (S10). Same 9 as the other dogs.')],
                'adult' => [36, $boundary('Game boundary (no exact month in the literature): maturation completes at 3–4 years (S11); 36 months = lower end, same as the other dogs.')],
                'senior' => [121, $boundary('Derived game boundary: last 25 % of lifespan (S11) × VetCompass median longevity 13.4 y (O\'Neill et al. 2019, S148) = 10.05 y = 120.6 → 121 months. No McMillan 2024 value was reachable. Not chosen: 108 (0.75 × RKC "Over 12 years", S144) and 84 (Dogs Trust > 7 y, S14). Provisional until McMillan 2024 is checked.')],
            ],
            'arrival' => ['young' => 9, 'adult' => 36, 'senior' => 121],
            'arrival_meta' => [
                'unit' => 'months', 'verified' => true,
                'ref' => 'proposed_game_parameters.west_highland_white_terrier.arrival_age_months', 'decision' => $decided,
                'notes' => 'Game value (no literature number): first month of the stage (rule of 2026-10-05), so the dog stays in this stage for the 12-week challenge.',
            ],
            'young_per_age_notes' => 'Game rule; applies "until full-grown" (9–12 months for a small dog, S10); capped at the adult 60 minutes, which 10 × age reaches at 6 months.',
            'adult_minutes' => [60, [
                'unit' => 'minutes/day', 'source_id' => 'S144', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'proposed_game_parameters.west_highland_white_terrier.exercise_minutes_adult', 'decision' => $decided,
                'quote' => 'Exercise: Up to 1 hour per day',
                'notes' => 'RKC "Up to 1 hour per day" (S144) → 60 min = 6,000 steps (runbook rule: "up to N" → N). PDSA "Your Westie will need an hour exercise every day." (S147) agrees.',
            ]],
            'senior_minutes' => [45, [
                'unit' => 'minutes/day', 'verified' => true,
                'ref' => 'proposed_game_parameters.west_highland_white_terrier.exercise_minutes_senior', 'decision' => $decided,
                'notes' => 'Game value (no literature number): 75 % of the adult 60 minutes = 45 min = 4,500 steps. Sources only say "frequent short walks instead of one long one" (S14).',
            ]],
            'adult_weight' => [[6.0, 9.0], [
                'unit' => 'kg', 'source_id' => 'S147', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'west_highland_white_terrier.adult_weight.pdsa',
                'quote' => '6-9 kg',
                'notes' => 'PDSA key facts (S147), one range for both sexes. The FCI (S142) and RKC (S145) standards give height only (approximately 28 cm).',
            ]],
            'growth_end' => [[9, 12], [
                'unit' => 'months', 'source_id' => 'S10', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'west_highland_white_terrier.growth.adult_weight_reached',
                'quote' => 'Small (12–24 pounds): 9–12 months',
                'notes' => 'Size-class value, not breed-specific (6–9 kg = 13.2–19.8 lb, the Small class of S10; RKC / PDSA size "Small").',
            ]],
            'coren_rank' => [47, [
                'unit' => 'rank', 'source_id' => 'S35', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'west_highland_white_terrier.trainability.coren_rank',
                'quote' => '| 47 | West Highland White Terrier |',
                'notes' => 'Wikipedia table of Coren\'s ranking (S35), rank 47, tier "Average" (ranks 40–54). Coren\'s own article (S34) lists only the top / bottom 10.',
            ]],
            'learning_multiplier' => [1.0, [
                'unit' => '× mixed-breed learning speed', 'source_id' => 'S35', 'confidence' => 'low', 'verified' => true,
                'ref' => 'proposed_game_parameters.west_highland_white_terrier.learning_multiplier', 'decision' => $decided,
                'notes' => 'Game value (no literature factor): Coren rank 47, "Average" tier (S35) → 1.0, the mixed breed\'s speed (PDSA S147: "super eager to please"). Multiplies the progress per correctly timed praise.',
            ]],
            // Like the other pedigree breeds: no individual variation row.
            'individual_variation' => null,
            'lifespan' => [13.4, [
                'unit' => 'years', 'source_id' => 'S148', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'west_highland_white_terrier.lifespan.median_uk',
                'quote' => 'The median longevity overall was 13.4 years (IQR 11.0–15.0, range 3.2–19.6).',
                'notes' => 'O\'Neill et al. 2019 (S148), VetCompass UK 2016, 164 deaths; RVC summary S149. McMillan 2024 (S54) gives no reachable value. Background for the senior boundary only — never shown as a statistic in the app.',
            ]],
        ];
    }

    /**
     * Bernese Mountain Dog (M5-R10-12). Stage boundaries, arrival ages, exercise minutes
     * and the learning multiplier follow the runbook's standing rules
     * (CONFIRMED_R10_BERNESE_MOUNTAIN_DOG); weight, growth, Coren rank and lifespan are
     * sourced.
     *
     * @return array<string, mixed>
     */
    private static function berneseMountainDogProfile(): array
    {
        $decided = self::CONFIRMED_R10_BERNESE_MOUNTAIN_DOG;
        $boundary = fn (string $note): array => [
            'unit' => 'months', 'source_id' => 'S11,S10,S156', 'confidence' => 'medium', 'verified' => true,
            'ref' => 'proposed_game_parameters.bernese_mountain_dog.stage_boundaries_months', 'decision' => $decided,
            'notes' => $note,
        ];

        return [
            'starts_at' => [
                'young' => [9, $boundary('Game boundary (no exact month in the literature): AAHA\'s young adult starts at the cessation of rapid growth (~6–9 months, S11); a giant dog finishes growing at 18–24 months (S10). Same 9 as the other dogs.')],
                'adult' => [36, $boundary('Game boundary (no exact month in the literature): maturation completes at 3–4 years (S11); 36 months = lower end, same as the other dogs.')],
                'senior' => [76, [
                    'unit' => 'months', 'source_id' => 'S11,S156', 'confidence' => 'low', 'verified' => false,
                    'ref' => 'proposed_game_parameters.bernese_mountain_dog.senior_boundary_months', 'decision' => self::PROPOSED_R10_BERNESE_SENIOR,
                    'notes' => 'UNCONFIRMED suggestion (Claude, waiting for David) — derived game boundary: last 25 % of lifespan (S11) × breed median life expectancy 8.4 y (Klopfenstein et al. 2016, Switzerland, S156) = 6.3 y = 75.6 → 76 months. No McMillan 2024 value and no VetCompass median were reachable; the RKC band "Under 10 years" (S152) has no lower bound. Not chosen: 91 (0.75 × 10.1 y, McMillan 2024 via Wikipedia S157, tier C), 84 (Dogs Trust > 7 y, S14), 63 (Woodgreen lower bound 7 y, S155).',
                ]],
            ],
            'arrival' => ['young' => 9, 'adult' => 36, 'senior' => 76],
            'arrival_meta' => [
                'unit' => 'months', 'verified' => true,
                'ref' => 'proposed_game_parameters.bernese_mountain_dog.arrival_age_months', 'decision' => $decided,
                'notes' => 'Game value (no literature number): first month of the stage (rule of 2026-10-05), so the dog stays in this stage for the 12-week challenge.',
            ],
            'arrival_meta_overrides' => [
                'senior' => [
                    'unit' => 'months', 'verified' => false,
                    'ref' => 'proposed_game_parameters.bernese_mountain_dog.senior_boundary_months', 'decision' => self::PROPOSED_R10_BERNESE_SENIOR,
                    'notes' => 'UNCONFIRMED (waiting for David): first month of the senior stage, which is Claude\'s suggestion of 76 months (0.75 × 8.4 y, S156).',
                ],
            ],
            'young_per_age_notes' => 'Game rule; applies "until full-grown" (18–24 months for a giant dog, S10); capped at the adult 60 minutes, which 10 × age reaches at 6 months.',
            'adult_minutes' => [60, [
                'unit' => 'minutes/day', 'source_id' => 'S152', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'proposed_game_parameters.bernese_mountain_dog.exercise_minutes_adult', 'decision' => $decided,
                'quote' => 'Exercise: Up to 1 hour per day',
                'notes' => 'RKC "Up to 1 hour per day" (S152) → 60 min = 6,000 steps (runbook rule: "up to N" → N). PDSA "Bernese Mountain Dogs need at least an hour of exercise every day." (S154) agrees; Woodgreen "40-60 mins" (S155) recorded as an alternative.',
            ]],
            'senior_minutes' => [45, [
                'unit' => 'minutes/day', 'verified' => true,
                'ref' => 'proposed_game_parameters.bernese_mountain_dog.exercise_minutes_senior', 'decision' => $decided,
                'notes' => 'Game value (no literature number): 75 % of the adult 60 minutes = 45 min = 4,500 steps. Sources only say "frequent short walks instead of one long one" (S14).',
            ]],
            'adult_weight' => [[42.0, 63.0], [
                'unit' => 'kg', 'source_id' => 'S154', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'bernese_mountain_dog.adult_weight.pdsa',
                'quote' => '42-53kg in females and 48-63kg in males',
                'notes' => 'PDSA key facts (S154), females 42–53 kg and males 48–63 kg → overall range. The FCI (S150) and RKC (S153) standards give height only (dogs 64–70 cm, bitches 58–66 cm).',
            ]],
            'growth_end' => [[18, 24], [
                'unit' => 'months', 'source_id' => 'S10', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'bernese_mountain_dog.growth.adult_weight_reached',
                'quote' => 'Giant (over 100 pounds) | 18–24 months',
                'notes' => 'Size-class value, not breed-specific (PDSA males 48–63 kg = 106–139 lb, the Giant class of S10; RKC standard "Slow to mature.", S153).',
            ]],
            'coren_rank' => [22, [
                'unit' => 'rank', 'source_id' => 'S35', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'bernese_mountain_dog.trainability.coren_rank',
                'quote' => '| 22 | Belgian Malinois | … Bernese Mountain Dog',
                'notes' => 'Wikipedia table of Coren\'s ranking (S35), rank 22 (shared), tier "Excellent" (ranks 11–26). Coren\'s own article (S34) lists only the top / bottom 10.',
            ]],
            'learning_multiplier' => [1.5, [
                'unit' => '× mixed-breed learning speed', 'source_id' => 'S35', 'confidence' => 'low', 'verified' => true,
                'ref' => 'proposed_game_parameters.bernese_mountain_dog.learning_multiplier', 'decision' => $decided,
                'notes' => 'Game value (no literature factor): Coren rank 22, "Excellent" tier (S35) → 1.5 (PDSA S154: "very intelligent and eager to please"). Multiplies the progress per correctly timed praise.',
            ]],
            // Like the other pedigree breeds: no individual variation row.
            'individual_variation' => null,
            'lifespan' => [8.4, [
                'unit' => 'years', 'source_id' => 'S156', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'bernese_mountain_dog.lifespan.median_ch',
                'quote' => 'The median life expectancy of all dogs was 8.4 years (IQR, 6.9–9.7).',
                'notes' => 'Klopfenstein et al. 2016 (S156), Swiss population, 389 dogs. McMillan 2024 (S54 / S135) gives no reachable value. Background for the senior boundary only — never shown as a statistic in the app.',
            ]],
        ];
    }

    /**
     * Siberian Husky (M5-R10-13). Stage boundaries, arrival ages, exercise minutes and the
     * learning multiplier follow the runbook's standing rules (CONFIRMED_R10_SIBERIAN_HUSKY);
     * weight, growth, Coren rank and lifespan are sourced.
     *
     * @return array<string, mixed>
     */
    private static function siberianHuskyProfile(): array
    {
        $decided = self::CONFIRMED_R10_SIBERIAN_HUSKY;
        $boundary = fn (string $note): array => [
            'unit' => 'months', 'source_id' => 'S11,S10,S160', 'confidence' => 'medium', 'verified' => true,
            'ref' => 'proposed_game_parameters.siberian_husky.stage_boundaries_months', 'decision' => $decided,
            'notes' => $note,
        ];

        return [
            'starts_at' => [
                'young' => [9, $boundary('Game boundary (no exact month in the literature): AAHA\'s young adult starts at the cessation of rapid growth (~6–9 months, S11); a medium dog finishes growing at 12–15 months (S10). Same 9 as the other dogs.')],
                'adult' => [36, $boundary('Game boundary (no exact month in the literature): maturation completes at 3–4 years (S11); 36 months = lower end, same as the other dogs.')],
                'senior' => [90, $boundary('Derived game boundary: last 25 % of lifespan (S11) × RKC lifespan lower bound "Over 10 years" (S160; PDSA "Over 10 years", S162) = 7.5 y = 90 months. McMillan 2024 has no reachable Siberian Husky value and no VetCompass median was found. Not chosen: 88 (Finnish average 9.75 y, S165), 84 (Dogs Trust > 7 y, S14). Provisional until McMillan 2024 is checked.')],
            ],
            'arrival' => ['young' => 9, 'adult' => 36, 'senior' => 90],
            'arrival_meta' => [
                'unit' => 'months', 'verified' => true,
                'ref' => 'proposed_game_parameters.siberian_husky.arrival_age_months', 'decision' => $decided,
                'notes' => 'Game value (no literature number): first month of the stage (rule of 2026-10-05), so the dog stays in this stage for the 12-week challenge.',
            ],
            'young_per_age_notes' => 'Game rule; applies "until full-grown" (12–15 months for a medium dog, S10); capped at the adult 120 minutes, which 10 × age reaches at 12 months.',
            'adult_minutes' => [120, [
                'unit' => 'minutes/day', 'source_id' => 'S160', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'proposed_game_parameters.siberian_husky.exercise_minutes_adult', 'decision' => $decided,
                'quote' => 'Exercise: More than 2 hours per day',
                'notes' => 'RKC "More than 2 hours per day" (S160) → 120 min = 12,000 steps (runbook rule: "more than N" → N). PDSA "Your Husky will need a minimum of two hours of exercise every day." (S162) agrees.',
            ]],
            'senior_minutes' => [90, [
                'unit' => 'minutes/day', 'verified' => true,
                'ref' => 'proposed_game_parameters.siberian_husky.exercise_minutes_senior', 'decision' => $decided,
                'notes' => 'Game value (no literature number): 75 % of the adult 120 minutes = 90 min = 9,000 steps. Sources only say "frequent short walks instead of one long one" (S14).',
            ]],
            'adult_weight' => [[15.5, 28.0], [
                'unit' => 'kg', 'source_id' => 'S158', 'confidence' => 'high', 'verified' => true,
                'ref' => 'siberian_husky.adult_weight.fci',
                'quote' => 'Dogs: 20,5 - 28 kg.',
                'notes' => 'FCI standard (S158): dogs 20.5–28 kg, "Females: 15,5 - 23 kg." → overall range. RKC standard (S161) dogs 20–27 kg, bitches 16–23 kg; PDSA (S162) 16–27 kg.',
            ]],
            'growth_end' => [[12, 15], [
                'unit' => 'months', 'source_id' => 'S10', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'siberian_husky.growth.adult_weight_reached',
                'quote' => 'Medium (24–59 pounds): 12–15 months',
                'notes' => 'Size-class value, not breed-specific (15.5–28 kg = 34–62 lb, mostly the Medium class of S10; FCI "a medium-sized working dog", S158).',
            ]],
            'coren_rank' => [45, [
                'unit' => 'rank', 'source_id' => 'S35', 'confidence' => 'low', 'verified' => true,
                'ref' => 'siberian_husky.trainability.coren_rank',
                'quote' => '| 45 | Siberian Husky |',
                'notes' => 'Wikipedia table of Coren\'s ranking (S35), rank 45 (shared with the Bichon Frise and King Charles Spaniel), tier "Average" (ranks 40–54). Coren\'s own article (S34) lists only the top / bottom 10.',
            ]],
            'learning_multiplier' => [1.0, [
                'unit' => '× mixed-breed learning speed', 'source_id' => 'S35', 'confidence' => 'low', 'verified' => true,
                'ref' => 'proposed_game_parameters.siberian_husky.learning_multiplier', 'decision' => $decided,
                'notes' => 'Game value (no literature factor): Coren rank 45 (S35) in the "Average" tier → 1.0, the mixed breed\'s speed (PDSA S162: "very intelligent" but "independent thinkers"). Multiplies the progress per correctly timed praise.',
            ]],
            // Like the other pedigree breeds: no individual variation row.
            'individual_variation' => null,
            'lifespan' => [10.0, [
                'unit' => 'years', 'source_id' => 'S160', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'siberian_husky.lifespan.rkc',
                'quote' => 'Lifespan: Over 10 years',
                'notes' => 'RKC breed page lower bound (S160; PDSA "Over 10 years", S162). No reachable McMillan 2024 or VetCompass median. Background for the senior boundary only — never shown as a statistic in the app.',
            ]],
        ];
    }

    /**
     * Rottweiler (M5-R10-19). Stage boundaries, arrival ages, exercise minutes and the
     * learning multiplier follow the runbook's standing rules (CONFIRMED_R10_ROTTWEILER);
     * weight, growth, Coren rank and lifespan are sourced.
     *
     * @return array<string, mixed>
     */
    private static function rottweilerProfile(): array
    {
        $decided = self::CONFIRMED_R10_ROTTWEILER;
        $boundary = fn (string $note): array => [
            'unit' => 'months', 'source_id' => 'S11,S10,S171', 'confidence' => 'medium', 'verified' => true,
            'ref' => 'proposed_game_parameters.rottweiler.stage_boundaries_months', 'decision' => $decided,
            'notes' => $note,
        ];

        return [
            'starts_at' => [
                'young' => [9, $boundary('Game boundary (no exact month in the literature): AAHA\'s young adult starts at the cessation of rapid growth (~6–9 months, S11); a giant / large dog finishes growing at 15–24 months (S10). Same 9 as the other dogs.')],
                'adult' => [36, $boundary('Game boundary (no exact month in the literature): maturation completes at 3–4 years (S11); 36 months = lower end, same as the other dogs.')],
                'senior' => [81, $boundary('Derived game boundary: last 25 % of lifespan (S11) × UK primary-care median longevity 9.0 y (O\'Neill et al. 2017, VetCompass, S171) = 6.75 y = 81 months. McMillan 2024 has no reachable Rottweiler value this run. Not chosen: 84 (Dogs Trust > 7 y, S14), 90 (PDSA "More than 10 years", S169 — conflicts with RKC "Under 10 years", S168, and S171). Provisional until McMillan 2024 is checked.')],
            ],
            'arrival' => ['young' => 9, 'adult' => 36, 'senior' => 81],
            'arrival_meta' => [
                'unit' => 'months', 'verified' => true,
                'ref' => 'proposed_game_parameters.rottweiler.arrival_age_months', 'decision' => $decided,
                'notes' => 'Game value (no literature number): first month of the stage (rule of 2026-10-05), so the dog stays in this stage for the 12-week challenge.',
            ],
            'young_per_age_notes' => 'Game rule; applies "until full-grown" (18–24 months for a giant dog, S10); capped at the adult 120 minutes, which 10 × age reaches at 12 months.',
            'adult_minutes' => [120, [
                'unit' => 'minutes/day', 'source_id' => 'S168', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'proposed_game_parameters.rottweiler.exercise_minutes_adult', 'decision' => $decided,
                'quote' => 'Exercise: More than 2 hours per day',
                'notes' => 'RKC "More than 2 hours per day" (S168) → 120 min = 12,000 steps (runbook rule: "more than N" → N). PDSA "You should give your Rottweiler at least two hours of exercise every day." (S169) agrees.',
            ]],
            'senior_minutes' => [90, [
                'unit' => 'minutes/day', 'verified' => true,
                'ref' => 'proposed_game_parameters.rottweiler.exercise_minutes_senior', 'decision' => $decided,
                'notes' => 'Game value (no literature number): 75 % of the adult 120 minutes = 90 min = 9,000 steps. Sources only say "frequent short walks instead of one long one" (S14).',
            ]],
            'adult_weight' => [[42.0, 50.0], [
                'unit' => 'kg', 'source_id' => 'S166', 'confidence' => 'high', 'verified' => true,
                'ref' => 'rottweiler.adult_weight.fci',
                'quote' => 'Weight: 50 kg.',
                'notes' => 'FCI standard (S166): dogs "Weight: 50 kg.", bitches "Weight: Approximately 42 kg." (one figure per sex) → 42–50 kg. PDSA (S169) 35–60 kg; the AKC standard (S170) gives no weight.',
            ]],
            'growth_end' => [[18, 24], [
                'unit' => 'months', 'source_id' => 'S10', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'rottweiler.growth.adult_weight_reached',
                'quote' => 'Giant (over 100 pounds) | 18–24 months',
                'notes' => 'Size-class value, not breed-specific (FCI dogs 50 kg ≈ 110 lb, the Giant class of S10; bitches ~42 kg ≈ 93 lb are Large, 15–18 months — same split as the Bernese Mountain Dog).',
            ]],
            'coren_rank' => [9, [
                'unit' => 'rank', 'source_id' => 'S35', 'confidence' => 'low', 'verified' => true,
                'ref' => 'rottweiler.trainability.coren_rank',
                'quote' => '| 9 | Rottweiler |',
                'notes' => 'Wikipedia table of Coren\'s ranking (S35), rank 9, tier "Brightest" (ranks 1–10). Coren\'s own article (S34) was not reachable this run.',
            ]],
            'learning_multiplier' => [1.8, [
                'unit' => '× mixed-breed learning speed', 'source_id' => 'S35', 'confidence' => 'low', 'verified' => true,
                'ref' => 'proposed_game_parameters.rottweiler.learning_multiplier', 'decision' => $decided,
                'notes' => 'Game value (no literature factor): Coren rank 9 (S35) in the "Brightest" tier → max(1.8, round(2.0 − 8 / 30, 1)) = 1.8 (FCI S166: "obedient, biddable and eager to work"). Multiplies the progress per correctly timed praise.',
            ]],
            // Like the other pedigree breeds: no individual variation row.
            'individual_variation' => null,
            'lifespan' => [9.0, [
                'unit' => 'years', 'source_id' => 'S171', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'rottweiler.lifespan.median_uk',
                'quote' => 'Median longevity overall was 9.0 years (IQR 7.2–10.5, range 0.0–17.0).',
                'notes' => 'O\'Neill et al. 2017 (S171), VetCompass UK primary care. McMillan 2024 (S54 / S123) not reachable this run. Background for the senior boundary only — never shown as a statistic in the app.',
            ]],
        ];
    }

    /**
     * Bulldog (M5-R10-20). Stage boundaries, arrival ages, exercise minutes and the
     * learning multiplier follow the runbook's standing rules (CONFIRMED_R10_BULLDOG);
     * weight, growth, Coren rank and lifespan are sourced.
     *
     * @return array<string, mixed>
     */
    private static function bulldogProfile(): array
    {
        $decided = self::CONFIRMED_R10_BULLDOG;
        $boundary = fn (string $note): array => [
            'unit' => 'months', 'source_id' => 'S11,S10,S54', 'confidence' => 'medium', 'verified' => true,
            'ref' => 'proposed_game_parameters.bulldog.stage_boundaries_months', 'decision' => $decided,
            'notes' => $note,
        ];

        return [
            'starts_at' => [
                'young' => [9, $boundary('Game boundary (no exact month in the literature): AAHA\'s young adult starts at the cessation of rapid growth (~6–9 months, S11); a medium dog finishes growing at 12–15 months (S10). Same 9 as the other dogs.')],
                'adult' => [36, $boundary('Game boundary (no exact month in the literature): maturation completes at 3–4 years (S11); 36 months = lower end, same as the other dogs.')],
                'senior' => [88, $boundary('Derived game boundary: last 25 % of lifespan (S11) × McMillan 2024 median 9.8 y (corrected Fig. 3 legend, S54) = 7.35 y = 88.2 → 88 months (= French Bulldog). Not chosen: 65 (VetCompass 2013 median 7.2 y, S181), 84 (Dogs Trust > 7 y, S14).')],
            ],
            'arrival' => ['young' => 9, 'adult' => 36, 'senior' => 88],
            'arrival_meta' => [
                'unit' => 'months', 'verified' => true,
                'ref' => 'proposed_game_parameters.bulldog.arrival_age_months', 'decision' => $decided,
                'notes' => 'Game value (no literature number): first month of the stage (rule of 2026-10-05), so the dog stays in this stage for the 12-week challenge.',
            ],
            'young_per_age_notes' => 'Game rule; applies "until full-grown" (12–15 months for a medium dog, S10); capped at the adult 60 minutes, which 10 × age reaches at 6 months.',
            'adult_minutes' => [60, [
                'unit' => 'minutes/day', 'source_id' => 'S177', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'proposed_game_parameters.bulldog.exercise_minutes_adult', 'decision' => $decided,
                'quote' => 'Exercise: Up to 1 hour per day',
                'notes' => 'RKC "Up to 1 hour per day" (S177) → 60 min = 6,000 steps (runbook rule: "up to N" → N). PDSA "Bulldogs need up to an hour\'s exercise daily" (S180) agrees; never in the heat of the day (S180 — a walk-time rule is not built yet, ROADMAP M5-R14).',
            ]],
            'senior_minutes' => [45, [
                'unit' => 'minutes/day', 'verified' => true,
                'ref' => 'proposed_game_parameters.bulldog.exercise_minutes_senior', 'decision' => $decided,
                'notes' => 'Game value (no literature number): 75 % of the adult 60 minutes = 45 min = 4,500 steps. Sources only say "frequent short walks instead of one long one" (S14).',
            ]],
            'adult_weight' => [[23.0, 25.0], [
                'unit' => 'kg', 'source_id' => 'S175', 'confidence' => 'high', 'verified' => true,
                'ref' => 'bulldog.adult_weight.fci',
                'quote' => 'Males: 25 kgs.',
                'notes' => 'FCI standard (S175): "Males: 25 kgs.", "Females: 23 kgs." (one figure per sex) → 23–25 kg; RKC standard (S178) the same. PDSA (S180) 23–25 kg; AKC (S179) about 50 lb / 40 lb.',
            ]],
            'growth_end' => [[12, 15], [
                'unit' => 'months', 'source_id' => 'S10', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'bulldog.growth.adult_weight_reached',
                'quote' => 'Medium (24–59 pounds): 12–15 months',
                'notes' => 'Size-class value, not breed-specific (FCI / RKC 25 kg ≈ 55 lb and 23 kg ≈ 51 lb = the Medium class of S10).',
            ]],
            'coren_rank' => [77, [
                'unit' => 'rank', 'source_id' => 'S35', 'confidence' => 'low', 'verified' => true,
                'ref' => 'bulldog.trainability.coren_rank',
                'quote' => '| 77 | Bulldog |',
                'notes' => 'Wikipedia table of Coren\'s ranking (S35), rank 77, tier "Lowest" (ranks 70–79). Coren\'s own article (S34) was not fetched this run.',
            ]],
            'learning_multiplier' => [0.5, [
                'unit' => '× mixed-breed learning speed', 'source_id' => 'S35', 'confidence' => 'low', 'verified' => true,
                'ref' => 'proposed_game_parameters.bulldog.learning_multiplier', 'decision' => $decided,
                'notes' => 'Game value (no literature factor): Coren rank 77 (S35) in the "Lowest" tier (ranks 70–79) → 0.5 (= Beagle). Multiplies the progress per correctly timed praise.',
            ]],
            // Like the other pedigree breeds: no individual variation row.
            'individual_variation' => null,
            'lifespan' => [9.8, [
                'unit' => 'years', 'source_id' => 'S54', 'confidence' => 'high', 'verified' => true,
                'ref' => 'bulldog.lifespan.median_uk',
                'quote' => 'Bulldog (green, x̃= 9.8)',
                'notes' => 'McMillan et al. 2024, corrected Fig. 3 legend (S54); published text (S135) the same. VetCompass 2013 median 7.2 y (S181) recorded as an alternative. Background for the senior boundary only — never shown as a statistic in the app.',
            ]],
        ];
    }

    /**
     * German Shorthaired Pointer (M5-R10-21). Stage boundaries, arrival ages, exercise minutes
     * and the learning multiplier follow the runbook's standing rules
     * (CONFIRMED_R10_GERMAN_SHORTHAIRED_POINTER); weight, growth, Coren rank and lifespan are sourced.
     *
     * @return array<string, mixed>
     */
    private static function germanShorthairedPointerProfile(): array
    {
        $decided = self::CONFIRMED_R10_GERMAN_SHORTHAIRED_POINTER;
        $boundary = fn (string $note): array => [
            'unit' => 'months', 'source_id' => 'S11,S10,S123', 'confidence' => 'medium', 'verified' => true,
            'ref' => 'proposed_game_parameters.german_shorthaired_pointer.stage_boundaries_months', 'decision' => $decided,
            'notes' => $note,
        ];

        return [
            'starts_at' => [
                'young' => [9, $boundary('Game boundary (no exact month in the literature): AAHA\'s young adult starts at the cessation of rapid growth (~6–9 months, S11); a medium dog finishes growing at 12–15 months (S10). Same 9 as the other dogs.')],
                'adult' => [36, $boundary('Game boundary (no exact month in the literature): maturation completes at 3–4 years (S11); 36 months = lower end, same as the other dogs.')],
                'senior' => [121, $boundary('Derived game boundary: last 25 % of lifespan (S11) × McMillan 2024 median 13.4 y (Dogs Trust breed table, S123) = 10.05 y = 120.6 → 121 months. Not chosen: 84 (Dogs Trust > 7 y, S14).')],
            ],
            'arrival' => ['young' => 9, 'adult' => 36, 'senior' => 121],
            'arrival_meta' => [
                'unit' => 'months', 'verified' => true,
                'ref' => 'proposed_game_parameters.german_shorthaired_pointer.arrival_age_months', 'decision' => $decided,
                'notes' => 'Game value (no literature number): first month of the stage (rule of 2026-10-05), so the dog stays in this stage for the 12-week challenge.',
            ],
            'young_per_age_notes' => 'Game rule; applies "until full-grown" (12–15 months for a medium dog, S10); capped at the adult 120 minutes, which 10 × age reaches at 12 months.',
            'adult_minutes' => [120, [
                'unit' => 'minutes/day', 'source_id' => 'S187', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'proposed_game_parameters.german_shorthaired_pointer.exercise_minutes_adult', 'decision' => $decided,
                'quote' => 'More than 2 hours per day',
                'notes' => 'RKC "More than 2 hours per day" (S187) → 120 min = 12,000 steps (runbook rule: "more than N" → N). PDSA "over 2 hours ideally per day" (S188) agrees.',
            ]],
            'senior_minutes' => [90, [
                'unit' => 'minutes/day', 'verified' => true,
                'ref' => 'proposed_game_parameters.german_shorthaired_pointer.exercise_minutes_senior', 'decision' => $decided,
                'notes' => 'Game value (no literature number): 75 % of the adult 120 minutes = 90 min = 9,000 steps. Sources only say "frequent short walks instead of one long one" (S14).',
            ]],
            'adult_weight' => [[20.4, 31.8], [
                'unit' => 'kg', 'source_id' => 'S186', 'confidence' => 'high', 'verified' => true,
                'ref' => 'german_shorthaired_pointer.adult_weight.akc',
                'quote' => 'Weight of dogs 55 to 70 pounds.',
                'notes' => 'AKC standard via GSPCA (S186): dogs 55–70 lb, "Weight of bitches 45 to 60 pounds." → 20.4–31.8 kg (× 0.4536). The FCI standard (S184) gives no weight; PDSA (S188) 20–34 kg.',
            ]],
            'growth_end' => [[12, 15], [
                'unit' => 'months', 'source_id' => 'S10', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'german_shorthaired_pointer.growth.adult_weight_reached',
                'quote' => 'Medium (24–59 pounds): 12–15 months',
                'notes' => 'Size-class value, not breed-specific (AKC 45–70 lb straddles Medium and Large; the midpoint 57.5 lb is the Medium class of S10).',
            ]],
            'coren_rank' => [17, [
                'unit' => 'rank', 'source_id' => 'S35', 'confidence' => 'low', 'verified' => true,
                'ref' => 'german_shorthaired_pointer.trainability.coren_rank',
                'quote' => '| 17 | German Shorthaired Pointer |',
                'notes' => 'Wikipedia table of Coren\'s ranking (S35), rank 17, tier "Excellent Working Dogs" (ranks 11–26). Coren\'s own article (S34) lists only the top and bottom 10.',
            ]],
            'learning_multiplier' => [1.5, [
                'unit' => '× mixed-breed learning speed', 'source_id' => 'S35', 'confidence' => 'low', 'verified' => true,
                'ref' => 'proposed_game_parameters.german_shorthaired_pointer.learning_multiplier', 'decision' => $decided,
                'notes' => 'Game value (no literature factor): Coren rank 17 (S35) in the "Excellent" tier (ranks 11–26) → 1.5. Multiplies the progress per correctly timed praise.',
            ]],
            // Like the other pedigree breeds: no individual variation row.
            'individual_variation' => null,
            'lifespan' => [13.4, [
                'unit' => 'years', 'source_id' => 'S123', 'confidence' => 'high', 'verified' => true,
                'ref' => 'german_shorthaired_pointer.lifespan.median_uk',
                'quote' => 'German Shorthaired Pointer | 13.4 | 13.3 - 13.6 | 3313 | 947',
                'notes' => 'McMillan et al. 2024 data as tabled by Dogs Trust (S123). PDSA "Over 10 years" (S188) and Dogs Trust "typically 11+ years" (S189) agree. Background for the senior boundary only — never shown as a statistic in the app.',
            ]],
        ];
    }

    /**
     * Yorkshire Terrier (M5-R10-22). Stage boundaries, arrival ages, exercise minutes and the
     * learning multiplier follow the runbook's standing rules (CONFIRMED_R10_YORKSHIRE_TERRIER);
     * weight, growth, Coren rank and lifespan are sourced.
     *
     * @return array<string, mixed>
     */
    private static function yorkshireTerrierProfile(): array
    {
        $decided = self::CONFIRMED_R10_YORKSHIRE_TERRIER;
        $boundary = fn (string $note): array => [
            'unit' => 'months', 'source_id' => 'S11,S10,S195', 'confidence' => 'medium', 'verified' => true,
            'ref' => 'proposed_game_parameters.yorkshire_terrier.stage_boundaries_months', 'decision' => $decided,
            'notes' => $note,
        ];

        return [
            'starts_at' => [
                'young' => [9, $boundary('Game boundary (no exact month in the literature): AAHA\'s young adult starts at the cessation of rapid growth (~6–9 months, S11); a toy dog finishes growing at 8–12 months (S10). Same 9 as the other dogs.')],
                'adult' => [36, $boundary('Game boundary (no exact month in the literature): maturation completes at 3–4 years (S11); 36 months = lower end, same as the other dogs.')],
                'senior' => [122, $boundary('Derived game boundary: last 25 % of lifespan (S11) × VetCompass median 13.56 y (O\'Neill et al. 2025, S195) = 10.17 y = 122.04 → 122 months. No McMillan 2024 value was reachable. Not chosen: 108 (RKC "Over 12 years", S192), 84 (Dogs Trust > 7 y, S14). Provisional until McMillan 2024 is checked.')],
            ],
            'arrival' => ['young' => 9, 'adult' => 36, 'senior' => 122],
            'arrival_meta' => [
                'unit' => 'months', 'verified' => true,
                'ref' => 'proposed_game_parameters.yorkshire_terrier.arrival_age_months', 'decision' => $decided,
                'notes' => 'Game value (no literature number): first month of the stage (rule of 2026-10-05), so the dog stays in this stage for the 12-week challenge.',
            ],
            'young_per_age_notes' => 'Game rule; applies "until full-grown" (8–12 months for a toy dog, S10); capped at the adult 30 minutes, which 10 × age reaches at 3 months.',
            'adult_minutes' => [30, [
                'unit' => 'minutes/day', 'source_id' => 'S192', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'proposed_game_parameters.yorkshire_terrier.exercise_minutes_adult', 'decision' => $decided,
                'quote' => 'Exercise: Up to 30 minutes per day',
                'notes' => 'RKC "Up to 30 minutes per day" (S192) → 30 min = 3,000 steps (runbook rule: "up to N" → N). PDSA "around 30 or 40 minutes exercise per day" (S194) — RKC preferred.',
            ]],
            'senior_minutes' => [23, [
                'unit' => 'minutes/day', 'verified' => true,
                'ref' => 'proposed_game_parameters.yorkshire_terrier.exercise_minutes_senior', 'decision' => $decided,
                'notes' => 'Game value (no literature number): 75 % of the adult 30 minutes = 22.5, rounded half up = 23 min = 2,300 steps. Sources only say "frequent short walks instead of one long one" (S14).',
            ]],
            'adult_weight' => [[3.2, 3.2], [
                'unit' => 'kg', 'source_id' => 'S194', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'yorkshire_terrier.adult_weight.pdsa',
                'quote' => 'Average weight | 3.2 kg',
                'notes' => 'PDSA key facts (S194), one average for both sexes; equal to the FCI / RKC upper limit "Up to 3,2 kgs" (S190, S193), which gives no lower bound. The UK pet population is heavier (S195) — background only.',
            ]],
            'growth_end' => [[8, 12], [
                'unit' => 'months', 'source_id' => 'S10', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'yorkshire_terrier.growth.adult_weight_reached',
                'quote' => 'Toy (5–12 pounds): 8–12 months',
                'notes' => 'Size-class value, not breed-specific (up to 3.2 kg ≈ 7 lb = the Toy class of S10; RKC / PDSA size "Small").',
            ]],
            'coren_rank' => [27, [
                'unit' => 'rank', 'source_id' => 'S35', 'confidence' => 'low', 'verified' => true,
                'ref' => 'yorkshire_terrier.trainability.coren_rank',
                'notes' => 'Wikipedia table of Coren\'s ranking (S35), rank 27 (shared), tier "Above Average Working Dogs" (ranks 27–39). Coren\'s own article (S34) lists only the top and bottom 10.',
            ]],
            'learning_multiplier' => [1.2, [
                'unit' => '× mixed-breed learning speed', 'source_id' => 'S35', 'confidence' => 'low', 'verified' => true,
                'ref' => 'proposed_game_parameters.yorkshire_terrier.learning_multiplier', 'decision' => $decided,
                'notes' => 'Game value (no literature factor): Coren rank 27 (S35) in the "Above average" tier (ranks 27–39) → 1.2. Multiplies the progress per correctly timed praise.',
            ]],
            // Like the other pedigree breeds: no individual variation row.
            'individual_variation' => null,
            'lifespan' => [13.56, [
                'unit' => 'years', 'source_id' => 'S195', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'yorkshire_terrier.lifespan.median_uk',
                'quote' => 'the median age at death was 13.56 years (IQR 11.30–15.15, range 0.06–19.08)',
                'notes' => 'O\'Neill et al. 2025 (S195), VetCompass UK 2016. RKC / PDSA "Over 12 years" (S192, S194) agree. Background for the senior boundary only — never shown as a statistic in the app.',
            ]],
        ];
    }

    /**
     * Cane Corso (M5-R10-23). Young / adult boundaries, arrival ages, exercise minutes and the
     * learning multiplier follow the runbook's standing rules (CONFIRMED_R10_CANE_CORSO); the
     * senior boundary is Claude's suggestion (PROPOSED_R10_CANE_CORSO_SENIOR, verified=false);
     * weight, growth and lifespan are sourced.
     *
     * @return array<string, mixed>
     */
    private static function caneCorsoProfile(): array
    {
        $decided = self::CONFIRMED_R10_CANE_CORSO;
        $boundary = fn (string $note): array => [
            'unit' => 'months', 'source_id' => 'S11,S10,S201', 'confidence' => 'medium', 'verified' => true,
            'ref' => 'proposed_game_parameters.cane_corso.stage_boundaries_months', 'decision' => $decided,
            'notes' => $note,
        ];

        return [
            'starts_at' => [
                'young' => [9, $boundary('Game boundary (no exact month in the literature): AAHA\'s young adult starts at the cessation of rapid growth (~6–9 months, S11); a giant / large dog finishes growing at 18–24 months (S10). Same 9 as the other dogs.')],
                'adult' => [36, $boundary('Game boundary (no exact month in the literature): maturation completes at 3–4 years (S11); 36 months = lower end, same as the other dogs.')],
                'senior' => [84, [
                    'unit' => 'months', 'source_id' => 'S11,S201', 'confidence' => 'low', 'verified' => false,
                    'ref' => 'proposed_game_parameters.cane_corso.senior_boundary_months', 'decision' => self::PROPOSED_R10_CANE_CORSO_SENIOR,
                    'notes' => 'UNCONFIRMED suggestion (Claude, waiting for David) — derived game boundary: last 25 % of lifespan (S11) × breed median lifespan 9.29 y (Korec et al. 2017, 232 dogs from 25 countries, S201) = 6.97 y = 83.61 → 84 months. No McMillan 2024 value and no VetCompass median were reachable, and the breed has no RKC breed page (PDSA "Under 10 years", S199, has no lower bound). Dogs Trust > 7 y (S14) gives the same 84.',
                ]],
            ],
            'arrival' => ['young' => 9, 'adult' => 36, 'senior' => 84],
            'arrival_meta' => [
                'unit' => 'months', 'verified' => true,
                'ref' => 'proposed_game_parameters.cane_corso.arrival_age_months', 'decision' => $decided,
                'notes' => 'Game value (no literature number): first month of the stage (rule of 2026-10-05), so the dog stays in this stage for the 12-week challenge.',
            ],
            'arrival_meta_overrides' => [
                'senior' => [
                    'unit' => 'months', 'verified' => false,
                    'ref' => 'proposed_game_parameters.cane_corso.senior_boundary_months', 'decision' => self::PROPOSED_R10_CANE_CORSO_SENIOR,
                    'notes' => 'UNCONFIRMED (waiting for David): first month of the senior stage, which is Claude\'s suggestion of 84 months (0.75 × 9.29 y, S201).',
                ],
            ],
            'young_per_age_notes' => 'Game rule; applies "until full-grown" (18–24 months for a giant dog, S10); capped at the adult 120 minutes, which 10 × age reaches at 12 months.',
            'adult_minutes' => [120, [
                'unit' => 'minutes/day', 'source_id' => 'S199', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'proposed_game_parameters.cane_corso.exercise_minutes_adult', 'decision' => $decided,
                'quote' => 'Your Cane Corso will need around two hours’ exercise every day.',
                'notes' => 'PDSA (S199), key facts "Minimum exercise (per day): 2 hours" → 120 min = 12,000 steps (runbook rule: one number N → N). The breed has no RKC breed page (not RKC-recognised).',
            ]],
            'senior_minutes' => [90, [
                'unit' => 'minutes/day', 'verified' => true,
                'ref' => 'proposed_game_parameters.cane_corso.exercise_minutes_senior', 'decision' => $decided,
                'notes' => 'Game value (no literature number): 75 % of the adult 120 minutes = 90 min = 9,000 steps. Sources only say "frequent short walks instead of one long one" (S14).',
            ]],
            'adult_weight' => [[40.0, 50.0], [
                'unit' => 'kg', 'source_id' => 'S197', 'confidence' => 'high', 'verified' => true,
                'ref' => 'cane_corso.adult_weight.fci',
                'quote' => 'Males: 45 – 50 kg.',
                'notes' => 'FCI standard (S197): males 45–50 kg, "Females: 40 – 45 kg." → overall range. PDSA key facts "Average weight: 40-50 kg" (S199) agree.',
            ]],
            'growth_end' => [[18, 24], [
                'unit' => 'months', 'source_id' => 'S10', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'cane_corso.growth.adult_weight_reached',
                'quote' => 'Giant (over 100 pounds) | 18–24 months',
                'notes' => 'Size-class value, not breed-specific (FCI males 45–50 kg ≈ 99–110 lb reach the Giant class of S10; females 40–45 kg are Large, 15–18 months).',
            ]],
            'coren_rank' => [null, [
                'unit' => 'rank', 'source_id' => 'S35', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'cane_corso.trainability.coren_rank',
                'notes' => 'Not ranked: the Cane Corso is not in the Wikipedia table of Coren\'s ranking (S35, checked 2026-10-12); the ranking predates the breed\'s FCI recognition (S198).',
            ]],
            'learning_multiplier' => [1.0, [
                'unit' => '× mixed-breed learning speed', 'source_id' => 'S35', 'confidence' => 'low', 'verified' => true,
                'ref' => 'proposed_game_parameters.cane_corso.learning_multiplier', 'decision' => $decided,
                'notes' => 'Game value (no literature factor): not ranked by Coren (S35) → 1.0, the mixed breed\'s speed (PDSA S199: positive, reward-based training from a young age). Multiplies the progress per correctly timed praise.',
            ]],
            // Like the other pedigree breeds: no individual variation row.
            'individual_variation' => null,
            'lifespan' => [9.29, [
                'unit' => 'years', 'source_id' => 'S201', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'cane_corso.lifespan.median_international',
                'quote' => 'The median lifespan of the whole examined group is 9.29 years (IQR 6.98-11.12, IQR = Interquartile Range).',
                'notes' => 'Korec et al. 2017 (S201), 232 dogs from kennels and owners in 25 countries. PDSA "Under 10 years" (S199) agrees. McMillan 2024 (S54 / S135) gives no reachable value. Background for the senior boundary only — never shown as a statistic in the app.',
            ]],
        ];
    }

    /**
     * Pembroke Welsh Corgi (M5-R10-24). Stage boundaries, arrival ages, exercise minutes and the
     * learning multiplier follow the runbook's standing rules (CONFIRMED_R10_PEMBROKE_WELSH_CORGI);
     * weight, growth, Coren rank and lifespan are sourced.
     *
     * @return array<string, mixed>
     */
    private static function pembrokeWelshCorgiProfile(): array
    {
        $decided = self::CONFIRMED_R10_PEMBROKE_WELSH_CORGI;
        $boundary = fn (string $note): array => [
            'unit' => 'months', 'source_id' => 'S11,S10,S204', 'confidence' => 'medium', 'verified' => true,
            'ref' => 'proposed_game_parameters.pembroke_welsh_corgi.stage_boundaries_months', 'decision' => $decided,
            'notes' => $note,
        ];

        return [
            'starts_at' => [
                'young' => [9, $boundary('Game boundary (no exact month in the literature): AAHA\'s young adult starts at the cessation of rapid growth (~6–9 months, S11); a small dog finishes growing at 9–12 months (S10). Same 9 as the other dogs.')],
                'adult' => [36, $boundary('Game boundary (no exact month in the literature): maturation completes at 3–4 years (S11); 36 months = lower end, same as the other dogs.')],
                'senior' => [108, $boundary('Derived game boundary: last 25 % of lifespan (S11) × RKC lower bound "Over 12 years" (S204; PDSA S206 the same) = 9 y = 108 months. No McMillan 2024 / VetCompass median was reachable. Not chosen: 84 (Dogs Trust > 7 y, S14). Provisional until McMillan 2024 is checked.')],
            ],
            'arrival' => ['young' => 9, 'adult' => 36, 'senior' => 108],
            'arrival_meta' => [
                'unit' => 'months', 'verified' => true,
                'ref' => 'proposed_game_parameters.pembroke_welsh_corgi.arrival_age_months', 'decision' => $decided,
                'notes' => 'Game value (no literature number): first month of the stage (rule of 2026-10-05), so the dog stays in this stage for the 12-week challenge.',
            ],
            'young_per_age_notes' => 'Game rule; applies "until full-grown" (9–12 months for a small dog, S10); capped at the adult 60 minutes, which 10 × age reaches at 6 months.',
            'adult_minutes' => [60, [
                'unit' => 'minutes/day', 'source_id' => 'S204', 'confidence' => 'high', 'verified' => true,
                'ref' => 'proposed_game_parameters.pembroke_welsh_corgi.exercise_minutes_adult', 'decision' => $decided,
                'quote' => 'Exercise: Up to 1 hour per day',
                'notes' => 'RKC "Up to 1 hour per day" (S204) → 60 min = 6,000 steps (runbook rule: "up to N" → N). PDSA "around an hour of exercise per day" (S206) agrees.',
            ]],
            'senior_minutes' => [45, [
                'unit' => 'minutes/day', 'verified' => true,
                'ref' => 'proposed_game_parameters.pembroke_welsh_corgi.exercise_minutes_senior', 'decision' => $decided,
                'notes' => 'Game value (no literature number): 75 % of the adult 60 minutes = 45 min = 4,500 steps. Sources only say "frequent short walks instead of one long one" (S14).',
            ]],
            'adult_weight' => [[9.0, 12.0], [
                'unit' => 'kg', 'source_id' => 'S202', 'confidence' => 'high', 'verified' => true,
                'ref' => 'pembroke_welsh_corgi.adult_weight.fci',
                'quote' => '10-12 kg.',
                'notes' => 'FCI (S202): males 10–12 kg, females 9–11 kg → game range 9–12 kg (both sexes). RKC standard (S205) the same; PDSA "9-12 kg" (S206).',
            ]],
            'growth_end' => [[9, 12], [
                'unit' => 'months', 'source_id' => 'S10', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'pembroke_welsh_corgi.growth.adult_weight_reached',
                'quote' => 'Small (12–24 pounds): 9–12 months',
                'notes' => 'Size-class value, not breed-specific (9–12 kg ≈ 20–26 lb, mostly the Small class of S10; RKC size "Small").',
            ]],
            'coren_rank' => [11, [
                'unit' => 'rank', 'source_id' => 'S35', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'pembroke_welsh_corgi.trainability.coren_rank',
                'notes' => 'Wikipedia table of Coren\'s ranking (S35), rank 11, tier "Excellent Working Dogs" (ranks 11–26). Coren\'s own article (S34) lists only the top and bottom 10.',
            ]],
            'learning_multiplier' => [1.5, [
                'unit' => '× mixed-breed learning speed', 'source_id' => 'S35', 'confidence' => 'low', 'verified' => true,
                'ref' => 'proposed_game_parameters.pembroke_welsh_corgi.learning_multiplier', 'decision' => $decided,
                'notes' => 'Game value (no literature factor): Coren rank 11 (S35) in the "Excellent" tier (ranks 11–26) → 1.5. Multiplies the progress per correctly timed praise.',
            ]],
            // Like the other pedigree breeds: no individual variation row.
            'individual_variation' => null,
            'lifespan' => [12.0, [
                'unit' => 'years', 'source_id' => 'S204', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'pembroke_welsh_corgi.lifespan.rkc',
                'quote' => 'Lifespan: Over 12 years',
                'notes' => 'RKC breed page lower bound (S204); PDSA "Over 12 years" (S206) the same. No McMillan 2024 / VetCompass median reachable. Background for the senior boundary only — never shown as a statistic in the app.',
            ]],
        ];
    }

    /**
     * Dobermann (M5-R10-25). Stage boundaries, arrival ages, exercise minutes and the learning
     * multiplier follow the runbook's standing rules (CONFIRMED_R10_DOBERMANN); weight, growth,
     * Coren rank and lifespan are sourced.
     *
     * @return array<string, mixed>
     */
    private static function dobermannProfile(): array
    {
        $decided = self::CONFIRMED_R10_DOBERMANN;
        $boundary = fn (string $note): array => [
            'unit' => 'months', 'source_id' => 'S11,S10,S212', 'confidence' => 'medium', 'verified' => true,
            'ref' => 'proposed_game_parameters.dobermann.stage_boundaries_months', 'decision' => $decided,
            'notes' => $note,
        ];

        return [
            'starts_at' => [
                'young' => [9, $boundary('Game boundary (no exact month in the literature): AAHA\'s young adult starts at the cessation of rapid growth (~6–9 months, S11); a large dog finishes growing at 15–18 months (S10). Same 9 as the other dogs.')],
                'adult' => [36, $boundary('Game boundary (no exact month in the literature): maturation completes at 3–4 years (S11); 36 months = lower end, same as the other dogs.')],
                'senior' => [83, $boundary('Derived game boundary: last 25 % of lifespan (S11) × VetCompass median 9.2 y (O\'Neill et al. 2013, S212 — 37 deaths) = 6.9 y = 82.8 → 83 months. No McMillan 2024 value was reachable. Not chosen: 90 (RKC "Over 10 years", S209), 84 (Dogs Trust > 7 y, S14). Provisional until McMillan 2024 is checked.')],
            ],
            'arrival' => ['young' => 9, 'adult' => 36, 'senior' => 83],
            'arrival_meta' => [
                'unit' => 'months', 'verified' => true,
                'ref' => 'proposed_game_parameters.dobermann.arrival_age_months', 'decision' => $decided,
                'notes' => 'Game value (no literature number): first month of the stage (rule of 2026-10-05), so the dog stays in this stage for the 12-week challenge.',
            ],
            'young_per_age_notes' => 'Game rule; applies "until full-grown" (15–18 months for a large dog, S10); capped at the adult 120 minutes, which 10 × age reaches at 12 months.',
            'adult_minutes' => [120, [
                'unit' => 'minutes/day', 'source_id' => 'S209', 'confidence' => 'high', 'verified' => true,
                'ref' => 'proposed_game_parameters.dobermann.exercise_minutes_adult', 'decision' => $decided,
                'quote' => 'Exercise: More than 2 hours per day',
                'notes' => 'RKC "More than 2 hours per day" (S209) → 120 min = 12,000 steps (runbook rule: "more than N" → N). PDSA "a minimum of two hours of exercise every day" (S211) agrees.',
            ]],
            'senior_minutes' => [90, [
                'unit' => 'minutes/day', 'verified' => true,
                'ref' => 'proposed_game_parameters.dobermann.exercise_minutes_senior', 'decision' => $decided,
                'notes' => 'Game value (no literature number): 75 % of the adult 120 minutes = 90 min = 9,000 steps. Sources only say "frequent short walks instead of one long one" (S14).',
            ]],
            'adult_weight' => [[32.0, 45.0], [
                'unit' => 'kg', 'source_id' => 'S207', 'confidence' => 'high', 'verified' => true,
                'ref' => 'dobermann.adult_weight.fci',
                'quote' => 'Weight: Males: about 40 – 45 kg.',
                'notes' => 'FCI (S207): males about 40–45 kg, females about 32–35 kg → game range 32–45 kg (both sexes). PDSA "30kg-40kg" (S211); the RKC standard gives no weight.',
            ]],
            'growth_end' => [[15, 18], [
                'unit' => 'months', 'source_id' => 'S10', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'dobermann.growth.adult_weight_reached',
                'quote' => 'Large (59–99 pounds): 15–18 months',
                'notes' => 'Size-class value, not breed-specific (32–45 kg ≈ 70–99 lb, the Large class of S10; RKC size "Large").',
            ]],
            'coren_rank' => [5, [
                'unit' => 'rank', 'source_id' => 'S35', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'dobermann.trainability.coren_rank',
                'notes' => 'Wikipedia table of Coren\'s ranking (S35), rank 5 ("Doberman Pinscher"), tier "Brightest Dogs" (ranks 1–10). Coren\'s own article (S34) not fetched this run.',
            ]],
            'learning_multiplier' => [1.9, [
                'unit' => '× mixed-breed learning speed', 'source_id' => 'S35', 'confidence' => 'low', 'verified' => true,
                'ref' => 'proposed_game_parameters.dobermann.learning_multiplier', 'decision' => $decided,
                'notes' => 'Game value (no literature factor): Coren rank 5 (S35) in the "Brightest" tier → max(1.8, round(2.0 − 4/30, 1)) = 1.9 (as the Golden Retriever, rank 4). Multiplies the progress per correctly timed praise.',
            ]],
            // Like the other pedigree breeds: no individual variation row.
            'individual_variation' => null,
            'lifespan' => [9.2, [
                'unit' => 'years', 'source_id' => 'S212', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'dobermann.lifespan.median_uk',
                'quote' => 'Doberman 9.2 6.2-11.0 2.1-13.0 37',
                'notes' => 'VetCompass median (O\'Neill et al. 2013, S212, 37 deaths). RKC / PDSA "Over 10 years" (S209, S211). No McMillan 2024 value reachable. Background for the senior boundary only — never shown as a statistic in the app.',
            ]],
        ];
    }

    /**
     * Boxer (M5-R10-26). Stage boundaries, arrival ages, exercise minutes and the learning
     * multiplier follow the runbook's standing rules (CONFIRMED_R10_BOXER); weight, growth,
     * Coren rank and lifespan are sourced.
     *
     * @return array<string, mixed>
     */
    private static function boxerProfile(): array
    {
        $decided = self::CONFIRMED_R10_BOXER;
        $boundary = fn (string $note): array => [
            'unit' => 'months', 'source_id' => 'S11,S10,S123', 'confidence' => 'medium', 'verified' => true,
            'ref' => 'proposed_game_parameters.boxer.stage_boundaries_months', 'decision' => $decided,
            'notes' => $note,
        ];

        return [
            'starts_at' => [
                'young' => [9, $boundary('Game boundary (no exact month in the literature): AAHA\'s young adult starts at the cessation of rapid growth (~6–9 months, S11); a large dog finishes growing at 15–18 months (S10). Same 9 as the other dogs.')],
                'adult' => [36, $boundary('Game boundary (no exact month in the literature): maturation completes at 3–4 years (S11); 36 months = lower end, same as the other dogs.')],
                'senior' => [102, $boundary('Derived game boundary: last 25 % of lifespan (S11) × McMillan 2024 median 11.3 y (Dogs Trust table, S123) = 8.475 y = 101.7 → 102 months. Not chosen: 94 (VetCompass 2023 median 10.46 y, S220), 84 (Dogs Trust > 7 y, S14).')],
            ],
            'arrival' => ['young' => 9, 'adult' => 36, 'senior' => 102],
            'arrival_meta' => [
                'unit' => 'months', 'verified' => true,
                'ref' => 'proposed_game_parameters.boxer.arrival_age_months', 'decision' => $decided,
                'notes' => 'Game value (no literature number): first month of the stage (rule of 2026-10-05), so the dog stays in this stage for the 12-week challenge.',
            ],
            'young_per_age_notes' => 'Game rule; applies "until full-grown" (15–18 months for a large dog, S10); capped at the adult 120 minutes, which 10 × age reaches at 12 months.',
            'adult_minutes' => [120, [
                'unit' => 'minutes/day', 'source_id' => 'S215', 'confidence' => 'high', 'verified' => true,
                'ref' => 'proposed_game_parameters.boxer.exercise_minutes_adult', 'decision' => $decided,
                'quote' => 'Exercise: More than 2 hours per day',
                'notes' => 'RKC "More than 2 hours per day" (S215) → 120 min = 12,000 steps (runbook rule: "more than N" → N). PDSA "a minimum of two hours exercise every day" (S219) agrees.',
            ]],
            'senior_minutes' => [90, [
                'unit' => 'minutes/day', 'verified' => true,
                'ref' => 'proposed_game_parameters.boxer.exercise_minutes_senior', 'decision' => $decided,
                'notes' => 'Game value (no literature number): 75 % of the adult 120 minutes = 90 min = 9,000 steps. Sources only say "frequent short walks instead of one long one" (S14).',
            ]],
            'adult_weight' => [[25.0, 32.0], [
                'unit' => 'kg', 'source_id' => 'S217', 'confidence' => 'high', 'verified' => true,
                'ref' => 'boxer.adult_weight.rkc',
                'quote' => 'Weight: dogs: approximately 30-32 kgs (66-70 lbs); bitches: approximately 25-27 kgs (55-60 lbs).',
                'notes' => 'RKC standard (S217): dogs about 30–32 kg, bitches about 25–27 kg → game range 25–32 kg (both sexes). PDSA (S219) the same; FCI (S213) males over 30 kg, females about 25 kg.',
            ]],
            'growth_end' => [[15, 18], [
                'unit' => 'months', 'source_id' => 'S10', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'boxer.growth.adult_weight_reached',
                'quote' => 'Large (59–99 pounds): 15–18 months',
                'notes' => 'Size-class value, not breed-specific (25–32 kg ≈ 55–70 lb: males in the Large class of S10, bitches at the Medium / Large edge; RKC size "Large").',
            ]],
            'coren_rank' => [48, [
                'unit' => 'rank', 'source_id' => 'S35', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'boxer.trainability.coren_rank',
                'notes' => 'Wikipedia table of Coren\'s ranking (S35), rank 48, tier "Average Working/Obedience Intelligence" (ranks 40–54). Coren\'s own article (S34) lists only the top and bottom 10.',
            ]],
            'learning_multiplier' => [1.0, [
                'unit' => '× mixed-breed learning speed', 'source_id' => 'S35', 'confidence' => 'low', 'verified' => true,
                'ref' => 'proposed_game_parameters.boxer.learning_multiplier', 'decision' => $decided,
                'notes' => 'Game value (no literature factor): Coren rank 48 (S35) in the "Average" tier (40–54) → 1.0, the mixed-breed speed. Multiplies the progress per correctly timed praise.',
            ]],
            // Like the other pedigree breeds: no individual variation row.
            'individual_variation' => null,
            'lifespan' => [11.3, [
                'unit' => 'years', 'source_id' => 'S123', 'confidence' => 'high', 'verified' => true,
                'ref' => 'boxer.lifespan.median_uk',
                'quote' => 'Boxer | 11.3 | 11.3 - 11.5 | 11294 | 5925',
                'notes' => 'McMillan et al. 2024 median as tabled by Dogs Trust (S123). VetCompass 2023 median 10.46 y (S220); RKC / PDSA "Over 10 years" (S215, S219). Background for the senior boundary only — never shown as a statistic in the app.',
            ]],
        ];
    }

    /**
     * Miniature Schnauzer (M5-R10-27). Stage boundaries, arrival ages, exercise minutes and the
     * learning multiplier follow the runbook's standing rules (CONFIRMED_R10_MINIATURE_SCHNAUZER);
     * weight, growth, Coren rank and lifespan are sourced.
     *
     * @return array<string, mixed>
     */
    private static function miniatureSchnauzerProfile(): array
    {
        $decided = self::CONFIRMED_R10_MINIATURE_SCHNAUZER;
        $boundary = fn (string $note): array => [
            'unit' => 'months', 'source_id' => 'S11,S10,S227', 'confidence' => 'medium', 'verified' => true,
            'ref' => 'proposed_game_parameters.miniature_schnauzer.stage_boundaries_months', 'decision' => $decided,
            'notes' => $note,
        ];

        return [
            'starts_at' => [
                'young' => [9, $boundary('Game boundary (no exact month in the literature): AAHA\'s young adult starts at the cessation of rapid growth (~6–9 months, S11); a small dog finishes growing at 9–12 months (S10). Same 9 as the other dogs.')],
                'adult' => [36, $boundary('Game boundary (no exact month in the literature): maturation completes at 3–4 years (S11); 36 months = lower end, same as the other dogs.')],
                'senior' => [104, $boundary('Derived game boundary: last 25 % of lifespan (S11) × VetCompass median 11.6 y (O\'Neill et al. 2019, S227) = 8.7 y = 104.4 → 104 months. No McMillan 2024 value was reachable. Not chosen: 90 (RKC "Over 10 years", S223), 84 (Dogs Trust > 7 y, S14). Provisional until McMillan 2024 is checked.')],
            ],
            'arrival' => ['young' => 9, 'adult' => 36, 'senior' => 104],
            'arrival_meta' => [
                'unit' => 'months', 'verified' => true,
                'ref' => 'proposed_game_parameters.miniature_schnauzer.arrival_age_months', 'decision' => $decided,
                'notes' => 'Game value (no literature number): first month of the stage (rule of 2026-10-05), so the dog stays in this stage for the 12-week challenge.',
            ],
            'young_per_age_notes' => 'Game rule; applies "until full-grown" (9–12 months for a small dog, S10); capped at the adult 60 minutes, which 10 × age reaches at 6 months.',
            'adult_minutes' => [60, [
                'unit' => 'minutes/day', 'source_id' => 'S223', 'confidence' => 'high', 'verified' => true,
                'ref' => 'proposed_game_parameters.miniature_schnauzer.exercise_minutes_adult', 'decision' => $decided,
                'quote' => 'Exercise: Up to 1 hour per day',
                'notes' => 'RKC "Up to 1 hour per day" (S223) → 60 min = 6,000 steps (runbook rule: "up to N" → N). PDSA "around an hour of exercise daily" (S226) agrees.',
            ]],
            'senior_minutes' => [45, [
                'unit' => 'minutes/day', 'verified' => true,
                'ref' => 'proposed_game_parameters.miniature_schnauzer.exercise_minutes_senior', 'decision' => $decided,
                'notes' => 'Game value (no literature number): 75 % of the adult 60 minutes = 45 min = 4,500 steps. Sources only say "frequent short walks instead of one long one" (S14).',
            ]],
            'adult_weight' => [[4.0, 8.0], [
                'unit' => 'kg', 'source_id' => 'S221', 'confidence' => 'high', 'verified' => true,
                'ref' => 'miniature_schnauzer.adult_weight.fci',
                'quote' => 'Weight Dogs and bitches approximately 4 to 8 kg.',
                'notes' => 'FCI standard N° 183 (S221): one range for both sexes. PDSA average 6–7 kg (S226) lies inside it; the RKC and AKC standards give no weight. The UK pet population is heavier (S227) — background only.',
            ]],
            'growth_end' => [[9, 12], [
                'unit' => 'months', 'source_id' => 'S10', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'miniature_schnauzer.growth.adult_weight_reached',
                'quote' => 'Small (12–24 pounds): 9–12 months',
                'notes' => 'Size-class value, not breed-specific (FCI 4–8 kg ≈ 9–18 lb at the Toy / Small edge of S10; PDSA 6–7 kg ≈ 13–15 lb = Small; RKC / PDSA size "Small").',
            ]],
            'coren_rank' => [12, [
                'unit' => 'rank', 'source_id' => 'S35', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'miniature_schnauzer.trainability.coren_rank',
                'notes' => 'Wikipedia table of Coren\'s ranking (S35), rank 12, tier "Excellent Working Dogs" (ranks 11–26). Coren\'s own article (S34) lists only the top and bottom 10.',
            ]],
            'learning_multiplier' => [1.5, [
                'unit' => '× mixed-breed learning speed', 'source_id' => 'S35', 'confidence' => 'low', 'verified' => true,
                'ref' => 'proposed_game_parameters.miniature_schnauzer.learning_multiplier', 'decision' => $decided,
                'notes' => 'Game value (no literature factor): Coren rank 12 (S35) in the "Excellent" tier (ranks 11–26) → 1.5. Multiplies the progress per correctly timed praise.',
            ]],
            // Like the other pedigree breeds: no individual variation row.
            'individual_variation' => null,
            'lifespan' => [11.6, [
                'unit' => 'years', 'source_id' => 'S227', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'miniature_schnauzer.lifespan.median_uk',
                'quote' => 'The median age at death of Miniature Schnauzers overall was 11.6 years (IQR 9.3–13.1, range 0.5–17.0).',
                'notes' => 'O\'Neill et al. 2019 (S227), VetCompass UK 2013. RKC / PDSA "Over 10 years" (S223, S226) agree. Background for the senior boundary only — never shown as a statistic in the app.',
            ]],
        ];
    }

    /**
     * M5-R10-28 Shih Tzu — docs/research/dog-data/data.json shih_tzu + proposed_game_parameters.shih_tzu.
     *
     * @return array<string, mixed>
     */
    private static function shihTzuProfile(): array
    {
        $decided = self::CONFIRMED_R10_SHIH_TZU;
        $boundary = fn (string $note): array => [
            'unit' => 'months', 'source_id' => 'S11,S10,S234', 'confidence' => 'medium', 'verified' => true,
            'ref' => 'proposed_game_parameters.shih_tzu.stage_boundaries_months', 'decision' => $decided,
            'notes' => $note,
        ];

        return [
            'starts_at' => [
                'young' => [9, $boundary('Game boundary (no exact month in the literature): AAHA\'s young adult starts at the cessation of rapid growth (~6–9 months, S11); a small dog finishes growing at 9–12 months (S10). Same 9 as the other dogs.')],
                'adult' => [36, $boundary('Game boundary (no exact month in the literature): maturation completes at 3–4 years (S11); 36 months = lower end, same as the other dogs.')],
                'senior' => [114, $boundary('Derived game boundary: last 25 % of lifespan (S11) × VetCompass median 12.7 y (Dale et al. 2024, S234) = 9.525 y = 114.3 → 114 months. No McMillan 2024 value was reachable. Not chosen: 90 (RKC "Over 10 years", S231), 84 (Dogs Trust > 7 y, S14). Provisional until McMillan 2024 is checked.')],
            ],
            'arrival' => ['young' => 9, 'adult' => 36, 'senior' => 114],
            'arrival_meta' => [
                'unit' => 'months', 'verified' => true,
                'ref' => 'proposed_game_parameters.shih_tzu.arrival_age_months', 'decision' => $decided,
                'notes' => 'Game value (no literature number): first month of the stage (rule of 2026-10-05), so the dog stays in this stage for the 12-week challenge.',
            ],
            'young_per_age_notes' => 'Game rule; applies "until full-grown" (9–12 months for a small dog, S10); capped at the adult 60 minutes, which 10 × age reaches at 6 months.',
            'adult_minutes' => [60, [
                'unit' => 'minutes/day', 'source_id' => 'S231', 'confidence' => 'high', 'verified' => true,
                'ref' => 'proposed_game_parameters.shih_tzu.exercise_minutes_adult', 'decision' => $decided,
                'quote' => 'Exercise: Up to 1 hour per day',
                'notes' => 'RKC "Up to 1 hour per day" (S231) → 60 min = 6,000 steps (runbook rule: "up to N" → N). PDSA "an hour exercise every day" (S233) agrees.',
            ]],
            'senior_minutes' => [45, [
                'unit' => 'minutes/day', 'verified' => true,
                'ref' => 'proposed_game_parameters.shih_tzu.exercise_minutes_senior', 'decision' => $decided,
                'notes' => 'Game value (no literature number): 75 % of the adult 60 minutes = 45 min = 4,500 steps. Sources only say "frequent short walks instead of one long one" (S14).',
            ]],
            'adult_weight' => [[4.5, 8.0], [
                'unit' => 'kg', 'source_id' => 'S229', 'confidence' => 'high', 'verified' => true,
                'ref' => 'shih_tzu.adult_weight.fci',
                'quote' => 'Weight: 4.5 to 8 kg. Ideal weight 4.5 - 7.5 kg',
                'notes' => 'FCI standard N° 208 (S229): one range for both sexes (ideal 4.5–7.5 kg). RKC standard (S232) the same; PDSA average 4.5–7.5 kg (S233). The UK pet population mean (S234) — background only.',
            ]],
            'growth_end' => [[9, 12], [
                'unit' => 'months', 'source_id' => 'S10', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'shih_tzu.growth.adult_weight_reached',
                'quote' => 'Small (12–24 pounds): 9–12 months',
                'notes' => 'Size-class value, not breed-specific (FCI 4.5–8 kg ≈ 10–17.6 lb at the Toy / Small edge of S10; the UK mean adult weight (S234) falls in Small; RKC / PDSA size "Small").',
            ]],
            'coren_rank' => [70, [
                'unit' => 'rank', 'source_id' => 'S35', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'shih_tzu.trainability.coren_rank',
                'notes' => 'Wikipedia table of Coren\'s ranking (S35), rank 70, tier "Lowest Degree of Working/Obedience Intelligence" (ranks 70–79). Coren\'s own article (S34) not reachable this run.',
            ]],
            'learning_multiplier' => [0.5, [
                'unit' => '× mixed-breed learning speed', 'source_id' => 'S35', 'confidence' => 'low', 'verified' => true,
                'ref' => 'proposed_game_parameters.shih_tzu.learning_multiplier', 'decision' => $decided,
                'notes' => 'Game value (no literature factor): Coren rank 70 (S35) in the "Lowest" tier (ranks 70–79) → 0.5. Multiplies the progress per correctly timed praise.',
            ]],
            // Like the other pedigree breeds: no individual variation row.
            'individual_variation' => null,
            'lifespan' => [12.7, [
                'unit' => 'years', 'source_id' => 'S234', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'shih_tzu.lifespan.median_uk',
                'quote' => 'The median age of death was 12.7 years (IQR 8.7–14.3, range 2.0–19.9)',
                'notes' => 'Dale et al. 2024 (S234), VetCompass UK 2016. RKC / PDSA "Over 10 years" (S231, S233) agree. Background for the senior boundary only — never shown as a statistic in the app.',
            ]],
        ];
    }

    /**
     * M5-R10-29 Great Dane — docs/research/dog-data/data.json great_dane + proposed_game_parameters.great_dane.
     *
     * @return array<string, mixed>
     */
    private static function greatDaneProfile(): array
    {
        $decided = self::CONFIRMED_R10_GREAT_DANE;
        $boundary = fn (string $note): array => [
            'unit' => 'months', 'source_id' => 'S11,S10,S212', 'confidence' => 'medium', 'verified' => true,
            'ref' => 'proposed_game_parameters.great_dane.stage_boundaries_months', 'decision' => $decided,
            'notes' => $note,
        ];

        return [
            'starts_at' => [
                'young' => [9, $boundary('Game boundary (no exact month in the literature): AAHA\'s young adult starts at the cessation of rapid growth (~6–9 months, S11); a giant dog finishes growing at 18–24 months (S10). Same 9 as the other dogs.')],
                'adult' => [36, $boundary('Game boundary (no exact month in the literature): maturation completes at 3–4 years (S11); 36 months = lower end, same as the other dogs.')],
                'senior' => [54, $boundary('Derived game boundary: last 25 % of lifespan (S11) × VetCompass median 6.0 y (O\'Neill et al. 2013, S212 — 23 deaths) = 4.5 y = 54 months. No McMillan 2024 value was reachable; the RKC band "Under 10 years" (S241) has no lower bound. Not chosen: 84 (Dogs Trust > 7 y, S14). Provisional until McMillan 2024 is checked (small sample — open for David).')],
            ],
            'arrival' => ['young' => 9, 'adult' => 36, 'senior' => 54],
            'arrival_meta' => [
                'unit' => 'months', 'verified' => true,
                'ref' => 'proposed_game_parameters.great_dane.arrival_age_months', 'decision' => $decided,
                'notes' => 'Game value (no literature number): first month of the stage (rule of 2026-10-05), so the dog stays in this stage for the 12-week challenge.',
            ],
            'young_per_age_notes' => 'Game rule; applies "until full-grown" (18–24 months for a giant dog, S10); capped at the adult 120 minutes, which 10 × age reaches at 12 months.',
            'adult_minutes' => [120, [
                'unit' => 'minutes/day', 'source_id' => 'S241', 'confidence' => 'high', 'verified' => true,
                'ref' => 'proposed_game_parameters.great_dane.exercise_minutes_adult', 'decision' => $decided,
                'quote' => 'Exercise: More than 2 hours per day',
                'notes' => 'RKC "More than 2 hours per day" (S241) → 120 min = 12,000 steps (runbook rule: "more than N" → N). PDSA "a minimum of two hours of exercise every day" (S243) agrees.',
            ]],
            'senior_minutes' => [90, [
                'unit' => 'minutes/day', 'verified' => true,
                'ref' => 'proposed_game_parameters.great_dane.exercise_minutes_senior', 'decision' => $decided,
                'notes' => 'Game value (no literature number): 75 % of the adult 120 minutes = 90 min = 9,000 steps. Sources only say "frequent short walks instead of one long one" (S14).',
            ]],
            'adult_weight' => [[46.0, 54.0], [
                'unit' => 'kg', 'source_id' => 'S243', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'great_dane.adult_weight.pdsa',
                'quote' => 'Average weight | 46-54 kg',
                'notes' => 'PDSA key facts (S243): one range for both sexes. RKC standard (S242): minimum weight over 18 months dogs 54 kg, bitches 46 kg (the same numbers as minimums). The FCI standard (S239) gives no weight.',
            ]],
            'growth_end' => [[18, 24], [
                'unit' => 'months', 'source_id' => 'S10', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'great_dane.growth.adult_weight_reached',
                'quote' => 'Giant (over 100 pounds) | 18–24 months',
                'notes' => 'Size-class value, not breed-specific (PDSA 46–54 kg ≈ 101–119 lb = Giant in S10; RKC size "Extra large"). Same as the Bernese Mountain Dog.',
            ]],
            'coren_rank' => [48, [
                'unit' => 'rank', 'source_id' => 'S35', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'great_dane.trainability.coren_rank',
                'notes' => 'Wikipedia table of Coren\'s ranking (S35), rank 48 (shared with the Boxer), tier "Average Working/Obedience Intelligence" (ranks 40–54). Coren\'s own article (S34) lists only the top and bottom 10.',
            ]],
            'learning_multiplier' => [1.0, [
                'unit' => '× mixed-breed learning speed', 'source_id' => 'S35', 'confidence' => 'low', 'verified' => true,
                'ref' => 'proposed_game_parameters.great_dane.learning_multiplier', 'decision' => $decided,
                'notes' => 'Game value (no literature factor): Coren rank 48 (S35) in the "Average" tier (40–54) → 1.0, the mixed-breed speed. Multiplies the progress per correctly timed praise.',
            ]],
            // Like the other pedigree breeds: no individual variation row.
            'individual_variation' => null,
            'lifespan' => [6.0, [
                'unit' => 'years', 'source_id' => 'S212', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'great_dane.lifespan.median_uk',
                'quote' => 'Great Dane 6.0 4.0-9.0 0.0-11.0 23',
                'notes' => 'O\'Neill et al. 2013 (S212), VetCompass England, Table 1 — 23 deaths (small sample). RKC / PDSA "Under 10 years" (S241, S243) agree. Background for the senior boundary only — never shown as a statistic in the app.',
            ]],
        ];
    }

    /**
     * M5-R10-30 Pomeranian — docs/research/dog-data/data.json pomeranian + proposed_game_parameters.pomeranian.
     *
     * @return array<string, mixed>
     */
    private static function pomeranianProfile(): array
    {
        $decided = self::CONFIRMED_R10_POMERANIAN;
        $boundary = fn (string $note): array => [
            'unit' => 'months', 'source_id' => 'S11,S10,S247', 'confidence' => 'medium', 'verified' => true,
            'ref' => 'proposed_game_parameters.pomeranian.stage_boundaries_months', 'decision' => $decided,
            'notes' => $note,
        ];

        return [
            'starts_at' => [
                'young' => [9, $boundary('Game boundary (no exact month in the literature): AAHA\'s young adult starts at the cessation of rapid growth (~6–9 months, S11); a toy dog finishes growing at 8–12 months (S10). Same 9 as the other dogs.')],
                'adult' => [36, $boundary('Game boundary (no exact month in the literature): maturation completes at 3–4 years (S11); 36 months = lower end, same as the other dogs.')],
                'senior' => [108, $boundary('Derived game boundary: last 25 % of lifespan (S11) × RKC lower bound "Over 12 years" (S247; PDSA S250 the same) = 9 y = 108 months. No McMillan 2024 / VetCompass median was reachable (12.2 y via Wikipedia S252 is tier C → 110, not used alone). Not chosen: 84 (Dogs Trust > 7 y, S14). Provisional until McMillan 2024 is checked.')],
            ],
            'arrival' => ['young' => 9, 'adult' => 36, 'senior' => 108],
            'arrival_meta' => [
                'unit' => 'months', 'verified' => true,
                'ref' => 'proposed_game_parameters.pomeranian.arrival_age_months', 'decision' => $decided,
                'notes' => 'Game value (no literature number): first month of the stage (rule of 2026-10-05), so the dog stays in this stage for the 12-week challenge.',
            ],
            'young_per_age_notes' => 'Game rule; applies "until full-grown" (8–12 months for a toy dog, S10); capped at the adult 30 minutes, which 10 × age reaches at 3 months.',
            'adult_minutes' => [30, [
                'unit' => 'minutes/day', 'source_id' => 'S247', 'confidence' => 'high', 'verified' => true,
                'ref' => 'proposed_game_parameters.pomeranian.exercise_minutes_adult', 'decision' => $decided,
                'quote' => 'Exercise: Up to 30 minutes per day',
                'notes' => 'RKC "Up to 30 minutes per day" (S247) → 30 min = 3,000 steps (runbook rule: "up to N" → N). PDSA "around half an hour of exercise per day" (S250) agrees.',
            ]],
            'senior_minutes' => [23, [
                'unit' => 'minutes/day', 'verified' => true,
                'ref' => 'proposed_game_parameters.pomeranian.exercise_minutes_senior', 'decision' => $decided,
                'notes' => 'Game value (no literature number): 75 % of the adult 30 minutes = 22.5 → 23 min (half up) = 2,300 steps. Sources only say "frequent short walks instead of one long one" (S14).',
            ]],
            'adult_weight' => [[1.8, 2.5], [
                'unit' => 'kg', 'source_id' => 'S249', 'confidence' => 'high', 'verified' => true,
                'ref' => 'pomeranian.adult_weight.rkc',
                'quote' => 'Males: 1.8kg – 2kg.',
                'notes' => 'RKC breed standard (S249): males 1.8–2 kg, females 2–2.5 kg → game range 1.8–2.5 kg (both sexes). FCI (S246) gives no weight ("a weight corresponding to its size"); PDSA "Average weight | 2-3 kg" (S250).',
            ]],
            'growth_end' => [[8, 12], [
                'unit' => 'months', 'source_id' => 'S10', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'pomeranian.growth.adult_weight_reached',
                'quote' => 'Toy (5–12 pounds): 8–12 months',
                'notes' => 'Size-class value, not breed-specific (1.8–2.5 kg ≈ 4–5.5 lb, just below the Toy class of S10 — the smallest class it gives; RKC / PDSA size "Small").',
            ]],
            'coren_rank' => [23, [
                'unit' => 'rank', 'source_id' => 'S35', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'pomeranian.trainability.coren_rank',
                'notes' => 'Wikipedia table of Coren\'s ranking (S35), rank 23, tier "Excellent Working Dogs" (ranks 11–26). Coren\'s own article (S34) lists only the top and bottom 10.',
            ]],
            'learning_multiplier' => [1.5, [
                'unit' => '× mixed-breed learning speed', 'source_id' => 'S35', 'confidence' => 'low', 'verified' => true,
                'ref' => 'proposed_game_parameters.pomeranian.learning_multiplier', 'decision' => $decided,
                'notes' => 'Game value (no literature factor): Coren rank 23 (S35) in the "Excellent" tier (ranks 11–26) → 1.5. Multiplies the progress per correctly timed praise.',
            ]],
            // Like the other pedigree breeds: no individual variation row.
            'individual_variation' => null,
            'lifespan' => [12.0, [
                'unit' => 'years', 'source_id' => 'S247', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'pomeranian.lifespan.rkc',
                'quote' => 'Lifespan: Over 12 years',
                'notes' => 'RKC breed page lower bound (S247); PDSA "Over 12 years" (S250) the same. No McMillan 2024 / VetCompass median reachable. Background for the senior boundary only — never shown as a statistic in the app.',
            ]],
        ];
    }

    /**
     * M5-R10-31 Boston Terrier — docs/research/dog-data/data.json boston_terrier + proposed_game_parameters.boston_terrier.
     *
     * @return array<string, mixed>
     */
    private static function bostonTerrierProfile(): array
    {
        $decided = self::CONFIRMED_R10_BOSTON_TERRIER;
        $boundary = fn (string $note): array => [
            'unit' => 'months', 'source_id' => 'S11,S10,S255', 'confidence' => 'medium', 'verified' => true,
            'ref' => 'proposed_game_parameters.boston_terrier.stage_boundaries_months', 'decision' => $decided,
            'notes' => $note,
        ];

        return [
            'starts_at' => [
                'young' => [9, $boundary('Game boundary (no exact month in the literature): AAHA\'s young adult starts at the cessation of rapid growth (~6–9 months, S11); a small dog finishes growing at 9–12 months (S10). Same 9 as the other dogs.')],
                'adult' => [36, $boundary('Game boundary (no exact month in the literature): maturation completes at 3–4 years (S11); 36 months = lower end, same as the other dogs.')],
                'senior' => [90, $boundary('Derived game boundary: last 25 % of lifespan (S11) × RKC lower bound "Over 10 years" (S255; PDSA S259 the same) = 7.5 y = 90 months. No McMillan 2024 / VetCompass median was reachable (the McMillan figure quoted by Wikipedia S262 is tier C → 106, not used alone). Not chosen: 84 (Dogs Trust > 7 y, S14). Provisional until McMillan 2024 is checked.')],
            ],
            'arrival' => ['young' => 9, 'adult' => 36, 'senior' => 90],
            'arrival_meta' => [
                'unit' => 'months', 'verified' => true,
                'ref' => 'proposed_game_parameters.boston_terrier.arrival_age_months', 'decision' => $decided,
                'notes' => 'Game value (no literature number): first month of the stage (rule of 2026-10-05), so the dog stays in this stage for the 12-week challenge.',
            ],
            'young_per_age_notes' => 'Game rule; applies "until full-grown" (9–12 months for a small dog, S10); capped at the adult 60 minutes, which 10 × age reaches at 6 months.',
            'adult_minutes' => [60, [
                'unit' => 'minutes/day', 'source_id' => 'S255', 'confidence' => 'high', 'verified' => true,
                'ref' => 'proposed_game_parameters.boston_terrier.exercise_minutes_adult', 'decision' => $decided,
                'quote' => 'Exercise: Up to 1 hour per day',
                'notes' => 'RKC "Up to 1 hour per day" (S255) → 60 min = 6,000 steps (runbook rule: "up to N" → N). PDSA "up to an hour’s exercise per day" (S259) agrees.',
            ]],
            'senior_minutes' => [45, [
                'unit' => 'minutes/day', 'verified' => true,
                'ref' => 'proposed_game_parameters.boston_terrier.exercise_minutes_senior', 'decision' => $decided,
                'notes' => 'Game value (no literature number): 75 % of the adult 60 minutes = 45 min = 4,500 steps. Sources only say "frequent short walks instead of one long one" (S14).',
            ]],
            'adult_weight' => [[7, 11], [
                'unit' => 'kg', 'source_id' => 'S259', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'boston_terrier.adult_weight.pdsa',
                'quote' => '7-11 kg',
                'notes' => 'PDSA average weight (S259), one range for both sexes → game range 7–11 kg, inside the FCI (S254) maximum of 11.35 kg and the RKC (S257) maximum of 11.5 kg; both standards give weight classes, the lightest without a lower bound.',
            ]],
            'growth_end' => [[9, 12], [
                'unit' => 'months', 'source_id' => 'S10', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'boston_terrier.growth.adult_weight_reached',
                'quote' => 'Small (12–24 pounds): 9–12 months',
                'notes' => 'Size-class value, not breed-specific (7–11 kg ≈ 15.4–24.3 lb → the Small class of S10).',
            ]],
            'coren_rank' => [54, [
                'unit' => 'rank', 'source_id' => 'S35', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'boston_terrier.trainability.coren_rank',
                'notes' => 'Wikipedia table of Coren\'s ranking (S35), rank 54 (shared with the Akita), tier "Average Working/Obedience Intelligence" (ranks 40–54). Coren\'s own article (S34) lists only the top and bottom 10.',
            ]],
            'learning_multiplier' => [1.0, [
                'unit' => '× mixed-breed learning speed', 'source_id' => 'S35', 'confidence' => 'low', 'verified' => true,
                'ref' => 'proposed_game_parameters.boston_terrier.learning_multiplier', 'decision' => $decided,
                'notes' => 'Game value (no literature factor): Coren rank 54 (S35) in the "Average" tier (ranks 40–54) → 1.0. Multiplies the progress per correctly timed praise.',
            ]],
            // Like the other pedigree breeds: no individual variation row.
            'individual_variation' => null,
            'lifespan' => [10.0, [
                'unit' => 'years', 'source_id' => 'S255', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'boston_terrier.lifespan.rkc',
                'quote' => 'Lifespan: Over 10 years',
                'notes' => 'RKC breed page lower bound (S255); PDSA "Over 10 years" (S259) the same. No McMillan 2024 / VetCompass median reachable. Background for the senior boundary only — never shown as a statistic in the app.',
            ]],
        ];
    }

    /**
     * M5-R10-32 Miniature American Shepherd — docs/research/dog-data/data.json miniature_american_shepherd +
     * proposed_game_parameters.miniature_american_shepherd. The senior boundary is Claude's suggestion
     * (PROPOSED_R10_MINIATURE_AMERICAN_SHEPHERD_SENIOR, verified=false).
     *
     * @return array<string, mixed>
     */
    private static function miniatureAmericanShepherdProfile(): array
    {
        $decided = self::CONFIRMED_R10_MINIATURE_AMERICAN_SHEPHERD;
        $boundary = fn (string $note): array => [
            'unit' => 'months', 'source_id' => 'S11,S10,S269', 'confidence' => 'medium', 'verified' => true,
            'ref' => 'proposed_game_parameters.miniature_american_shepherd.stage_boundaries_months', 'decision' => $decided,
            'notes' => $note,
        ];

        return [
            'starts_at' => [
                'young' => [9, $boundary('Game boundary (no exact month in the literature): AAHA\'s young adult starts at the cessation of rapid growth (~6–9 months, S11); a medium dog finishes growing at 12–15 months (S10). Same 9 as the other dogs.')],
                'adult' => [36, $boundary('Game boundary (no exact month in the literature): maturation completes at 3–4 years (S11); 36 months = lower end, same as the other dogs.')],
                'senior' => [108, [
                    'unit' => 'months', 'source_id' => 'S11,S269', 'confidence' => 'low', 'verified' => false,
                    'ref' => 'proposed_game_parameters.miniature_american_shepherd.senior_boundary_months', 'decision' => self::PROPOSED_R10_MINIATURE_AMERICAN_SHEPHERD_SENIOR,
                    'notes' => 'UNCONFIRMED suggestion (Claude, waiting for David) — derived game boundary: last 25 % of lifespan (S11) × PetMD lower bound 12 y ("12–13 years", S269) = 9 y = 108 months. No McMillan 2024 row (Dogs Trust table S123), no VetCompass paper, and the RKC breed page could not be fetched. Alternatives: Wikipedia 13–15 y (tier C, S271) → 117; Dogs Trust > 7 y (S14) → 84.',
                ]],
            ],
            'arrival' => ['young' => 9, 'adult' => 36, 'senior' => 108],
            'arrival_meta' => [
                'unit' => 'months', 'verified' => true,
                'ref' => 'proposed_game_parameters.miniature_american_shepherd.arrival_age_months', 'decision' => $decided,
                'notes' => 'Game value (no literature number): first month of the stage (rule of 2026-10-05), so the dog stays in this stage for the 12-week challenge.',
            ],
            'arrival_meta_overrides' => [
                'senior' => [
                    'unit' => 'months', 'verified' => false,
                    'ref' => 'proposed_game_parameters.miniature_american_shepherd.senior_boundary_months', 'decision' => self::PROPOSED_R10_MINIATURE_AMERICAN_SHEPHERD_SENIOR,
                    'notes' => 'UNCONFIRMED (waiting for David): first month of the senior stage, which is Claude\'s suggestion of 108 months (0.75 × 12 y, S269).',
                ],
            ],
            'young_per_age_notes' => 'Game rule; applies "until full-grown" (12–15 months for a medium dog, S10); capped at the adult 60 minutes, which 10 × age reaches at 6 months.',
            'adult_minutes' => [60, [
                'unit' => 'minutes/day', 'source_id' => 'S269', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'proposed_game_parameters.miniature_american_shepherd.exercise_minutes_adult', 'decision' => $decided,
                'quote' => 'This breed has a lot of energy and needs at least one hour (ideally two) of exercise every day.',
                'notes' => 'PetMD (S269) "at least one hour" → 60 min = 6,000 steps (runbook rule: "at least N" → N; "ideally two" hours is an alternative). The RKC breed page could not be fetched; the breed has no PDSA page.',
            ]],
            'senior_minutes' => [45, [
                'unit' => 'minutes/day', 'verified' => true,
                'ref' => 'proposed_game_parameters.miniature_american_shepherd.exercise_minutes_senior', 'decision' => $decided,
                'notes' => 'Game value (no literature number): 75 % of the adult 60 minutes = 45 min = 4,500 steps. Sources only say "frequent short walks instead of one long one" (S14).',
            ]],
            'adult_weight' => [[9.1, 18.1], [
                'unit' => 'kg', 'source_id' => 'S268', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'miniature_american_shepherd.adult_weight.akc',
                'quote' => 'Miniature American Shepherd | 20-40 pounds | 20-40 pounds',
                'notes' => 'AKC breed weight chart (S268): 20–40 lb for males and females = 9.07–18.14 kg → 9.1–18.1 kg. AKC (S267) and PetMD (S269) give the same 20–40 lb; neither standard gives a weight.',
            ]],
            'growth_end' => [[12, 15], [
                'unit' => 'months', 'source_id' => 'S10', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'miniature_american_shepherd.growth.adult_weight_reached',
                'quote' => 'Medium (24–59 pounds): 12–15 months',
                'notes' => 'Size-class value, not breed-specific (20–40 lb → mostly the Medium class of S10).',
            ]],
            'coren_rank' => [null, [
                'unit' => 'rank', 'source_id' => 'S35', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'miniature_american_shepherd.trainability.coren_rank',
                'notes' => 'Not ranked: the Miniature American Shepherd is not in the Wikipedia table of Coren\'s ranking (S35, checked 2026-10-13); the ranking predates the breed\'s AKC recognition (2015).',
            ]],
            'learning_multiplier' => [1.0, [
                'unit' => '× mixed-breed learning speed', 'source_id' => 'S35', 'confidence' => 'low', 'verified' => true,
                'ref' => 'proposed_game_parameters.miniature_american_shepherd.learning_multiplier', 'decision' => $decided,
                'notes' => 'Game value (no literature factor): not ranked by Coren (S35) → 1.0, the mixed breed\'s speed (PetMD S269: intelligent and eager to please). Multiplies the progress per correctly timed praise.',
            ]],
            // Like the other pedigree breeds: no individual variation row.
            'individual_variation' => null,
            'lifespan' => [12.0, [
                'unit' => 'years', 'source_id' => 'S269', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'miniature_american_shepherd.lifespan.petmd',
                'quote' => 'The average Miniature American Shepherd lifespan is 12–13 years, and they\'re considered a generally healthy breed.',
                'notes' => 'PetMD (S269) "12–13 years", lower bound. No McMillan 2024 row (S123), no VetCompass paper, RKC page not reachable. Background for the senior boundary only — never shown as a statistic in the app.',
            ]],
        ];
    }

    /**
     * M5-R10-33 Chihuahua (Smooth Coat) — docs/research/dog-data/data.json chihuahua +
     * proposed_game_parameters.chihuahua. The senior boundary is Claude's suggestion
     * (PROPOSED_R10_CHIHUAHUA_SENIOR, verified=false).
     *
     * @return array<string, mixed>
     */
    private static function chihuahuaProfile(): array
    {
        $decided = self::CONFIRMED_R10_CHIHUAHUA;
        $boundary = fn (string $note): array => [
            'unit' => 'months', 'source_id' => 'S11,S10,S275', 'confidence' => 'medium', 'verified' => true,
            'ref' => 'proposed_game_parameters.chihuahua.stage_boundaries_months', 'decision' => $decided,
            'notes' => $note,
        ];

        return [
            'starts_at' => [
                'young' => [9, $boundary('Game boundary (no exact month in the literature): AAHA\'s young adult starts at the cessation of rapid growth (~6–9 months, S11); a toy dog finishes growing at 8–12 months (S10). Same 9 as the other dogs.')],
                'adult' => [36, $boundary('Game boundary (no exact month in the literature): maturation completes at 3–4 years (S11); 36 months = lower end, same as the other dogs.')],
                'senior' => [108, [
                    'unit' => 'months', 'source_id' => 'S11,S275', 'confidence' => 'low', 'verified' => false,
                    'ref' => 'proposed_game_parameters.chihuahua.senior_boundary_months', 'decision' => self::PROPOSED_R10_CHIHUAHUA_SENIOR,
                    'notes' => 'UNCONFIRMED suggestion (Claude, waiting for David) — derived game boundary: last 25 % of lifespan (S11) × RKC lower bound "Over 12 years" (S275; PDSA S277 the same) = 9 y = 108 months. The rule\'s VetCompass row (O\'Neill et al. 2020, S279 → 74 months) is biased downwards by a young population (its authors) and contradicts both UK bands; no McMillan 2024 value was reachable. Alternatives: 74 (S279), 84 (Dogs Trust > 7 y, S14).',
                ]],
            ],
            'arrival' => ['young' => 9, 'adult' => 36, 'senior' => 108],
            'arrival_meta' => [
                'unit' => 'months', 'verified' => true,
                'ref' => 'proposed_game_parameters.chihuahua.arrival_age_months', 'decision' => $decided,
                'notes' => 'Game value (no literature number): first month of the stage (rule of 2026-10-05), so the dog stays in this stage for the 12-week challenge.',
            ],
            'arrival_meta_overrides' => [
                'senior' => [
                    'unit' => 'months', 'verified' => false,
                    'ref' => 'proposed_game_parameters.chihuahua.senior_boundary_months', 'decision' => self::PROPOSED_R10_CHIHUAHUA_SENIOR,
                    'notes' => 'UNCONFIRMED (waiting for David): first month of the senior stage, which is Claude\'s suggestion of 108 months (0.75 × 12 y, S275).',
                ],
            ],
            'young_per_age_notes' => 'Game rule; applies "until full-grown" (8–12 months for a toy dog, S10); capped at the adult 30 minutes, which 10 × age reaches at 3 months.',
            'adult_minutes' => [30, [
                'unit' => 'minutes/day', 'source_id' => 'S275', 'confidence' => 'high', 'verified' => true,
                'ref' => 'proposed_game_parameters.chihuahua.exercise_minutes_adult', 'decision' => $decided,
                'quote' => 'Exercise: Up to 30 minutes per day',
                'notes' => 'RKC "Up to 30 minutes per day" (S275) → 30 min = 3,000 steps (runbook rule: "up to N" → N). PDSA "a minimum of half an hour exercise" every day (S277) agrees.',
            ]],
            'senior_minutes' => [23, [
                'unit' => 'minutes/day', 'verified' => true,
                'ref' => 'proposed_game_parameters.chihuahua.exercise_minutes_senior', 'decision' => $decided,
                'notes' => 'Game value (no literature number): 75 % of the adult 30 minutes = 22.5 → 23 min (half up) = 2,300 steps. Sources only say "frequent short walks instead of one long one" (S14).',
            ]],
            'adult_weight' => [[1.8, 2.7], [
                'unit' => 'kg', 'source_id' => 'S274', 'confidence' => 'high', 'verified' => true,
                'ref' => 'chihuahua.adult_weight.rkc',
                'quote' => 'Up to 2.7kg, with 1.8kg – 2.7kg preferred.',
                'notes' => 'KC breed standard (S274): preferred 1.8–2.7 kg (both sexes); PDSA "Average weight | 1.8-2.7 kg" (S277) agrees. FCI (S273): ideal 1.5–2.5 kg, accepted 1–3 kg.',
            ]],
            'growth_end' => [[8, 12], [
                'unit' => 'months', 'source_id' => 'S10', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'chihuahua.growth.adult_weight_reached',
                'quote' => 'Toy (5–12 pounds): 8–12 months',
                'notes' => 'Size-class value, not breed-specific (1.8–2.7 kg ≈ 4–6 lb, at the lower edge of the Toy class of S10 — the smallest class it gives; RKC / PDSA size "Small").',
            ]],
            'coren_rank' => [67, [
                'unit' => 'rank', 'source_id' => 'S35', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'chihuahua.trainability.coren_rank',
                'notes' => 'Wikipedia table of Coren\'s ranking (S35), rank 67, tier "Fair Working/Obedience Intelligence" (ranks 55–69). Coren\'s own article (S34) lists only the top and bottom 10.',
            ]],
            'learning_multiplier' => [0.7, [
                'unit' => '× mixed-breed learning speed', 'source_id' => 'S35', 'confidence' => 'low', 'verified' => true,
                'ref' => 'proposed_game_parameters.chihuahua.learning_multiplier', 'decision' => $decided,
                'notes' => 'Game value (no literature factor): Coren rank 67 (S35) in the "Fair" tier (ranks 55–69) → 0.7. Multiplies the progress per correctly timed praise.',
            ]],
            // Like the other pedigree breeds: no individual variation row.
            'individual_variation' => null,
            'lifespan' => [12.0, [
                'unit' => 'years', 'source_id' => 'S275', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'chihuahua.lifespan.rkc',
                'quote' => 'Lifespan: Over 12 years',
                'notes' => 'RKC breed page lower bound (S275); PDSA "Over 12 years" (S277) the same. The VetCompass median of a young population (S279) is not used. Background for the senior boundary only — never shown as a statistic in the app.',
            ]],
        ];
    }

    /**
     * English Springer Spaniel (M5-R10-34). Stage boundaries, arrival ages, exercise minutes and the
     * learning multiplier follow the runbook's standing rules (CONFIRMED_R10_ENGLISH_SPRINGER_SPANIEL);
     * weight, growth, Coren rank and lifespan are sourced.
     *
     * @return array<string, mixed>
     */
    private static function englishSpringerSpanielProfile(): array
    {
        $decided = self::CONFIRMED_R10_ENGLISH_SPRINGER_SPANIEL;
        $boundary = fn (string $note): array => [
            'unit' => 'months', 'source_id' => 'S11,S10,S123', 'confidence' => 'medium', 'verified' => true,
            'ref' => 'proposed_game_parameters.english_springer_spaniel.stage_boundaries_months', 'decision' => $decided,
            'notes' => $note,
        ];

        return [
            'starts_at' => [
                'young' => [9, $boundary('Game boundary (no exact month in the literature): AAHA\'s young adult starts at the cessation of rapid growth (~6–9 months, S11); a medium dog finishes growing at 12–15 months (S10). Same 9 as the other dogs.')],
                'adult' => [36, $boundary('Game boundary (no exact month in the literature): maturation completes at 3–4 years (S11); 36 months = lower end, same as the other dogs.')],
                'senior' => [122, $boundary('Derived game boundary: last 25 % of lifespan (S11) × McMillan 2024 median 13.5 y (Dogs Trust breed table, S123) = 10.125 y = 121.5 → 122 months (half up). Not chosen: 84 (Dogs Trust > 7 y, S14).')],
            ],
            'arrival' => ['young' => 9, 'adult' => 36, 'senior' => 122],
            'arrival_meta' => [
                'unit' => 'months', 'verified' => true,
                'ref' => 'proposed_game_parameters.english_springer_spaniel.arrival_age_months', 'decision' => $decided,
                'notes' => 'Game value (no literature number): first month of the stage (rule of 2026-10-05), so the dog stays in this stage for the 12-week challenge.',
            ],
            'young_per_age_notes' => 'Game rule; applies "until full-grown" (12–15 months for a medium dog, S10); capped at the adult 120 minutes, which 10 × age reaches at 12 months.',
            'adult_minutes' => [120, [
                'unit' => 'minutes/day', 'source_id' => 'S284', 'confidence' => 'high', 'verified' => true,
                'ref' => 'proposed_game_parameters.english_springer_spaniel.exercise_minutes_adult', 'decision' => $decided,
                'quote' => 'Exercise: More than 2 hours per day',
                'notes' => 'RKC "More than 2 hours per day" (S284) → 120 min = 12,000 steps (runbook rule: "more than N" → N). PDSA "over 2 hours of exercise per day" (S285) agrees.',
            ]],
            'senior_minutes' => [90, [
                'unit' => 'minutes/day', 'verified' => true,
                'ref' => 'proposed_game_parameters.english_springer_spaniel.exercise_minutes_senior', 'decision' => $decided,
                'notes' => 'Game value (no literature number): 75 % of the adult 120 minutes = 90 min = 9,000 steps. Sources only say "frequent short walks instead of one long one" (S14).',
            ]],
            'adult_weight' => [[18, 23], [
                'unit' => 'kg', 'source_id' => 'S285', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'english_springer_spaniel.adult_weight.pdsa',
                'quote' => 'Average weight | 18-23 kg',
                'notes' => 'PDSA key facts (S285), both sexes. AKC standard (S283): a 20-inch dog about 50 lb (≈ 22.7 kg), a 19-inch bitch about 40 lb (≈ 18.1 kg) — inside the range. The KC standard (S282) gives no weight.',
            ]],
            'growth_end' => [[12, 15], [
                'unit' => 'months', 'source_id' => 'S10', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'english_springer_spaniel.growth.adult_weight_reached',
                'quote' => 'Medium (24–59 pounds): 12–15 months',
                'notes' => 'Size-class value, not breed-specific (18–23 kg ≈ 40–51 lb, the Medium class of S10; RKC / PDSA size "Medium").',
            ]],
            'coren_rank' => [13, [
                'unit' => 'rank', 'source_id' => 'S35', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'english_springer_spaniel.trainability.coren_rank',
                'quote' => '| 13 | English Springer Spaniel |',
                'notes' => 'Wikipedia table of Coren\'s ranking (S35), rank 13, tier "Excellent Working Dogs" (ranks 11–26). Coren\'s own article (S34) lists only the top and bottom 10.',
            ]],
            'learning_multiplier' => [1.5, [
                'unit' => '× mixed-breed learning speed', 'source_id' => 'S35', 'confidence' => 'low', 'verified' => true,
                'ref' => 'proposed_game_parameters.english_springer_spaniel.learning_multiplier', 'decision' => $decided,
                'notes' => 'Game value (no literature factor): Coren rank 13 (S35) in the "Excellent" tier (ranks 11–26) → 1.5. Multiplies the progress per correctly timed praise.',
            ]],
            // Like the other pedigree breeds: no individual variation row.
            'individual_variation' => null,
            'lifespan' => [13.5, [
                'unit' => 'years', 'source_id' => 'S123', 'confidence' => 'high', 'verified' => true,
                'ref' => 'english_springer_spaniel.lifespan.median_uk',
                'quote' => 'English Springer Spaniel | 13.5 | 13.4 - 13.6 | 17905 | 6221',
                'notes' => 'McMillan et al. 2024 data as tabled by Dogs Trust (S123). RKC "Lifespan: Over 10 years" (S284) and PDSA "Over 10 years" (S285) agree. Background for the senior boundary only — never shown as a statistic in the app.',
            ]],
        ];
    }

    /**
     * Shetland Sheepdog (M5-R10-35). Stage boundaries, arrival ages, exercise minutes and the
     * learning multiplier follow the runbook's standing rules (CONFIRMED_R10_SHETLAND_SHEEPDOG);
     * weight, growth, Coren rank and lifespan are sourced. The senior boundary uses the RKC lower
     * bound (no McMillan 2024 / VetCompass median reachable) — provisional, like the Boston Terrier.
     *
     * @return array<string, mixed>
     */
    private static function shetlandSheepdogProfile(): array
    {
        $decided = self::CONFIRMED_R10_SHETLAND_SHEEPDOG;
        $boundary = fn (string $note): array => [
            'unit' => 'months', 'source_id' => 'S11,S10,S291', 'confidence' => 'medium', 'verified' => true,
            'ref' => 'proposed_game_parameters.shetland_sheepdog.stage_boundaries_months', 'decision' => $decided,
            'notes' => $note,
        ];

        return [
            'starts_at' => [
                'young' => [9, $boundary('Game boundary (no exact month in the literature): AAHA\'s young adult starts at the cessation of rapid growth (~6–9 months, S11); a small dog finishes growing at 9–12 months (S10). Same 9 as the other dogs.')],
                'adult' => [36, $boundary('Game boundary (no exact month in the literature): maturation completes at 3–4 years (S11); 36 months = lower end, same as the other dogs.')],
                'senior' => [90, $boundary('Derived game boundary: last 25 % of lifespan (S11) × RKC lower bound "Over 10 years" (S291; PDSA S293 the same) = 7.5 y = 90 months. No McMillan 2024 / VetCompass median was reachable (the McMillan figure quoted by Wikipedia S295 is tier C → 121, not used alone). Not chosen: 84 (Dogs Trust > 7 y, S14). Provisional until McMillan 2024 is checked.')],
            ],
            'arrival' => ['young' => 9, 'adult' => 36, 'senior' => 90],
            'arrival_meta' => [
                'unit' => 'months', 'verified' => true,
                'ref' => 'proposed_game_parameters.shetland_sheepdog.arrival_age_months', 'decision' => $decided,
                'notes' => 'Game value (no literature number): first month of the stage (rule of 2026-10-05), so the dog stays in this stage for the 12-week challenge.',
            ],
            'young_per_age_notes' => 'Game rule; applies "until full-grown" (9–12 months for a small dog, S10); capped at the adult 60 minutes, which 10 × age reaches at 6 months.',
            'adult_minutes' => [60, [
                'unit' => 'minutes/day', 'source_id' => 'S291', 'confidence' => 'high', 'verified' => true,
                'ref' => 'proposed_game_parameters.shetland_sheepdog.exercise_minutes_adult', 'decision' => $decided,
                'quote' => 'Exercise: Up to 1 hour per day',
                'notes' => 'RKC "Up to 1 hour per day" (S291) → 60 min = 6,000 steps (runbook rule: "up to N" → N). PDSA "around an hour of exercise daily" (S293) agrees.',
            ]],
            'senior_minutes' => [45, [
                'unit' => 'minutes/day', 'verified' => true,
                'ref' => 'proposed_game_parameters.shetland_sheepdog.exercise_minutes_senior', 'decision' => $decided,
                'notes' => 'Game value (no literature number): 75 % of the adult 60 minutes = 45 min = 4,500 steps. Sources only say "frequent short walks instead of one long one" (S14).',
            ]],
            'adult_weight' => [[6.8, 11.3], [
                'unit' => 'kg', 'source_id' => 'S294', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'shetland_sheepdog.adult_weight.akc',
                'quote' => 'averaging between 13 and 16 inches tall and weighing 15 to 25 pounds.',
                'notes' => 'AKC (S294), 15–25 lb ≈ 6.8–11.3 kg, both sexes. PDSA average 9 kg (S293) — inside the range. The FCI standard (S290) gives no weight.',
            ]],
            'growth_end' => [[9, 12], [
                'unit' => 'months', 'source_id' => 'S10', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'shetland_sheepdog.growth.adult_weight_reached',
                'quote' => 'Small (12–24 pounds): 9–12 months',
                'notes' => 'Size-class value, not breed-specific (PDSA 9 kg ≈ 20 lb → the Small class of S10; RKC size "Small", S291).',
            ]],
            'coren_rank' => [6, [
                'unit' => 'rank', 'source_id' => 'S35', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'shetland_sheepdog.trainability.coren_rank',
                'quote' => '| 6 | Shetland Sheepdog |',
                'notes' => 'Wikipedia table of Coren\'s ranking (S35), rank 6, tier "Brightest Dogs" (ranks 1–10); Coren\'s own article (S34) lists the Shetland Sheepdog sixth among the ten brightest — agrees.',
            ]],
            'learning_multiplier' => [1.8, [
                'unit' => '× mixed-breed learning speed', 'source_id' => 'S35', 'confidence' => 'low', 'verified' => true,
                'ref' => 'proposed_game_parameters.shetland_sheepdog.learning_multiplier', 'decision' => $decided,
                'notes' => 'Game value (no literature factor): Coren rank 6 (S35) in the "Brightest" tier → max(1.8, round(2.0 − 5 / 30, 1)) = 1.8 (= Labrador, rank 7). Multiplies the progress per correctly timed praise.',
            ]],
            // Like the other pedigree breeds: no individual variation row.
            'individual_variation' => null,
            'lifespan' => [10.0, [
                'unit' => 'years', 'source_id' => 'S291', 'confidence' => 'high', 'verified' => true,
                'ref' => 'shetland_sheepdog.lifespan.rkc',
                'quote' => 'Lifespan: Over 10 years',
                'notes' => 'RKC breed page lower bound (S291); PDSA "Over 10 years" (S293) the same. No McMillan 2024 / VetCompass median reachable. Background for the senior boundary only — never shown as a statistic in the app.',
            ]],
        ];
    }

    /**
     * Brittany (M5-R10-36). Stage boundaries, arrival ages, exercise minutes and the
     * learning multiplier follow the runbook's standing rules (CONFIRMED_R10_BRITTANY);
     * weight, growth, Coren rank and lifespan are sourced. The senior boundary uses the RKC lower
     * bound (no McMillan 2024 / VetCompass median reachable) — provisional, like the Shetland Sheepdog.
     *
     * @return array<string, mixed>
     */
    private static function brittanyProfile(): array
    {
        $decided = self::CONFIRMED_R10_BRITTANY;
        $boundary = fn (string $note): array => [
            'unit' => 'months', 'source_id' => 'S11,S10,S298', 'confidence' => 'medium', 'verified' => true,
            'ref' => 'proposed_game_parameters.brittany.stage_boundaries_months', 'decision' => $decided,
            'notes' => $note,
        ];

        return [
            'starts_at' => [
                'young' => [9, $boundary('Game boundary (no exact month in the literature): AAHA\'s young adult starts at the cessation of rapid growth (~6–9 months, S11); a medium dog finishes growing at 12–15 months (S10). Same 9 as the other dogs.')],
                'adult' => [36, $boundary('Game boundary (no exact month in the literature): maturation completes at 3–4 years (S11); 36 months = lower end, same as the other dogs.')],
                'senior' => [90, $boundary('Derived game boundary: last 25 % of lifespan (S11) × RKC lower bound "Over 10 years" (S298) = 7.5 y = 90 months. No McMillan 2024 / VetCompass median was reachable (PetMD 12–14 y, S302, is not in the runbook\'s lifespan order → 108, alternative only). Not chosen: 84 (Dogs Trust > 7 y, S14). Provisional until McMillan 2024 is checked.')],
            ],
            'arrival' => ['young' => 9, 'adult' => 36, 'senior' => 90],
            'arrival_meta' => [
                'unit' => 'months', 'verified' => true,
                'ref' => 'proposed_game_parameters.brittany.arrival_age_months', 'decision' => $decided,
                'notes' => 'Game value (no literature number): first month of the stage (rule of 2026-10-05), so the dog stays in this stage for the 12-week challenge.',
            ],
            'young_per_age_notes' => 'Game rule; applies "until full-grown" (12–15 months for a medium dog, S10); capped at the adult 120 minutes, which 10 × age reaches at 12 months.',
            'adult_minutes' => [120, [
                'unit' => 'minutes/day', 'source_id' => 'S298', 'confidence' => 'high', 'verified' => true,
                'ref' => 'proposed_game_parameters.brittany.exercise_minutes_adult', 'decision' => $decided,
                'quote' => 'Exercise: More than 2 hours per day',
                'notes' => 'RKC "More than 2 hours per day" (S298) → 120 min = 12,000 steps (runbook rule: "more than N" → N). AKC (S301) "they require plenty of exercise" gives no number.',
            ]],
            'senior_minutes' => [90, [
                'unit' => 'minutes/day', 'verified' => true,
                'ref' => 'proposed_game_parameters.brittany.exercise_minutes_senior', 'decision' => $decided,
                'notes' => 'Game value (no literature number): 75 % of the adult 120 minutes = 90 min = 9,000 steps. Sources only say "frequent short walks instead of one long one" (S14).',
            ]],
            'adult_weight' => [[13.6, 18.1], [
                'unit' => 'kg', 'source_id' => 'S300', 'confidence' => 'high', 'verified' => true,
                'ref' => 'brittany.adult_weight.akc',
                'quote' => 'Weight - Should weigh between 30 and 40 pounds.',
                'notes' => 'AKC standard (S300), 30–40 lb ≈ 13.6–18.1 kg, both sexes; AKC article (S301) and PetMD (S302) agree. The FCI (S297) and RKC (S299) standards give no weight.',
            ]],
            'growth_end' => [[12, 15], [
                'unit' => 'months', 'source_id' => 'S10', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'brittany.growth.adult_weight_reached',
                'quote' => 'Medium (24–59 pounds): 12–15 months',
                'notes' => 'Size-class value, not breed-specific (30–40 lb → the Medium class of S10; RKC size "Medium", S298).',
            ]],
            'coren_rank' => [19, [
                'unit' => 'rank', 'source_id' => 'S35', 'confidence' => 'low', 'verified' => true,
                'ref' => 'brittany.trainability.coren_rank',
                'quote' => '| 19 | Brittany |',
                'notes' => 'Wikipedia table of Coren\'s ranking (S35), rank 19, tier "Excellent Working Dogs" (ranks 11–26). Coren\'s own article (S34) lists only the top and bottom 10.',
            ]],
            'learning_multiplier' => [1.5, [
                'unit' => '× mixed-breed learning speed', 'source_id' => 'S35', 'confidence' => 'low', 'verified' => true,
                'ref' => 'proposed_game_parameters.brittany.learning_multiplier', 'decision' => $decided,
                'notes' => 'Game value (no literature factor): Coren rank 19 (S35) in the "Excellent" tier (ranks 11–26) → 1.5. Multiplies the progress per correctly timed praise.',
            ]],
            // Like the other pedigree breeds: no individual variation row.
            'individual_variation' => null,
            'lifespan' => [10.0, [
                'unit' => 'years', 'source_id' => 'S298', 'confidence' => 'high', 'verified' => true,
                'ref' => 'brittany.lifespan.rkc',
                'quote' => 'Lifespan: Over 10 years',
                'notes' => 'RKC breed page lower bound (S298). PetMD "12–14 years" (S302) not used (not in the runbook\'s lifespan order). No McMillan 2024 / VetCompass median reachable. Background for the senior boundary only — never shown as a statistic in the app.',
            ]],
        ];
    }

    /**
     * American Cocker Spaniel (M5-R10-37). Stage boundaries, arrival ages, exercise minutes and the
     * learning multiplier follow the runbook's standing rules (CONFIRMED_R10_AMERICAN_COCKER_SPANIEL);
     * weight, growth, Coren rank and lifespan are sourced. The senior boundary uses the RKC lower
     * bound (no McMillan 2024 / VetCompass median reachable) — provisional, like the Brittany.
     *
     * @return array<string, mixed>
     */
    private static function americanCockerSpanielProfile(): array
    {
        $decided = self::CONFIRMED_R10_AMERICAN_COCKER_SPANIEL;
        $boundary = fn (string $note): array => [
            'unit' => 'months', 'source_id' => 'S11,S10,S305', 'confidence' => 'medium', 'verified' => true,
            'ref' => 'proposed_game_parameters.american_cocker_spaniel.stage_boundaries_months', 'decision' => $decided,
            'notes' => $note,
        ];

        return [
            'starts_at' => [
                'young' => [9, $boundary('Game boundary (no exact month in the literature): AAHA\'s young adult starts at the cessation of rapid growth (~6–9 months, S11); a small dog finishes growing at 9–12 months (S10). Same 9 as the other dogs.')],
                'adult' => [36, $boundary('Game boundary (no exact month in the literature): maturation completes at 3–4 years (S11); 36 months = lower end, same as the other dogs.')],
                'senior' => [90, $boundary('Derived game boundary: last 25 % of lifespan (S11) × RKC lower bound "Over 10 years" (S305) = 7.5 y = 90 months (PetMD lower bound 10 y, S309, agrees). No McMillan 2024 / VetCompass median was reachable (McMillan 2024 13.3 y only via Wikipedia, S311, tier C → 120, alternative only; VCA 12 y, S310 → 108). Not chosen: 84 (Dogs Trust > 7 y, S14). Provisional until McMillan 2024 is checked.')],
            ],
            'arrival' => ['young' => 9, 'adult' => 36, 'senior' => 90],
            'arrival_meta' => [
                'unit' => 'months', 'verified' => true,
                'ref' => 'proposed_game_parameters.american_cocker_spaniel.arrival_age_months', 'decision' => $decided,
                'notes' => 'Game value (no literature number): first month of the stage (rule of 2026-10-05), so the dog stays in this stage for the 12-week challenge.',
            ],
            'young_per_age_notes' => 'Game rule; applies "until full-grown" (9–12 months for a small dog, S10); capped at the adult 120 minutes, which 10 × age reaches at 12 months.',
            'adult_minutes' => [120, [
                'unit' => 'minutes/day', 'source_id' => 'S305', 'confidence' => 'high', 'verified' => true,
                'ref' => 'proposed_game_parameters.american_cocker_spaniel.exercise_minutes_adult', 'decision' => $decided,
                'quote' => 'Exercise: More than 2 hours per day',
                'notes' => 'RKC "More than 2 hours per day" (S305) → 120 min = 12,000 steps (runbook rule: "more than N" → N; RKC preferred when sources conflict). AKC (S308) "They don’t require a lot of exercise", PetMD (S309) "don’t require high levels of exercise" and VCA (S310) "daily exercise in the form of a long walk" give no number.',
            ]],
            'senior_minutes' => [90, [
                'unit' => 'minutes/day', 'verified' => true,
                'ref' => 'proposed_game_parameters.american_cocker_spaniel.exercise_minutes_senior', 'decision' => $decided,
                'notes' => 'Game value (no literature number): 75 % of the adult 120 minutes = 90 min = 9,000 steps. Sources only say "frequent short walks instead of one long one" (S14).',
            ]],
            'adult_weight' => [[9.1, 13.6], [
                'unit' => 'kg', 'source_id' => 'S308', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'american_cocker_spaniel.adult_weight.akc',
                'quote' => 'standing between 13.5 and 15.5 inches tall, and weighing about 20 to 30 pounds.',
                'notes' => 'AKC breed article (S308), 20–30 lb ≈ 9.1–13.6 kg, both sexes; PetMD (S309) agrees, VCA (S310) 24–28 lb inside it. The FCI (S304) and AKC (S307) standards give no weight.',
            ]],
            'growth_end' => [[9, 12], [
                'unit' => 'months', 'source_id' => 'S10', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'american_cocker_spaniel.growth.adult_weight_reached',
                'quote' => 'Small (12–24 pounds): 9–12 months',
                'notes' => 'Size-class value, not breed-specific (20–30 lb straddles the Small and Medium classes of S10; RKC size "Small", S305, decides).',
            ]],
            'coren_rank' => [20, [
                'unit' => 'rank', 'source_id' => 'S35', 'confidence' => 'low', 'verified' => true,
                'ref' => 'american_cocker_spaniel.trainability.coren_rank',
                'quote' => '| 20 | Cocker Spaniel |',
                'notes' => 'Wikipedia table of Coren\'s ranking (S35), rank 20 "Cocker Spaniel" (the American breed; the English Cocker Spaniel is listed at 18), tier "Excellent Working Dogs" (ranks 11–26). Coren\'s own article (S34) lists only the top and bottom 10.',
            ]],
            'learning_multiplier' => [1.5, [
                'unit' => '× mixed-breed learning speed', 'source_id' => 'S35', 'confidence' => 'low', 'verified' => true,
                'ref' => 'proposed_game_parameters.american_cocker_spaniel.learning_multiplier', 'decision' => $decided,
                'notes' => 'Game value (no literature factor): Coren rank 20 (S35) in the "Excellent" tier (ranks 11–26) → 1.5. Multiplies the progress per correctly timed praise.',
            ]],
            // Like the other pedigree breeds: no individual variation row.
            'individual_variation' => null,
            'lifespan' => [10.0, [
                'unit' => 'years', 'source_id' => 'S305', 'confidence' => 'high', 'verified' => true,
                'ref' => 'american_cocker_spaniel.lifespan.rkc',
                'quote' => 'Lifespan: Over 10 years',
                'notes' => 'RKC breed page lower bound (S305); PetMD "10–14 years" (S309) agrees. McMillan 2024 13.3 y only via Wikipedia (S311, tier C) — not used. Background for the senior boundary only — never shown as a statistic in the app.',
            ]],
        ];
    }

    /**
     * Belgian Malinois (M5-R10-38). Stage boundaries, arrival ages, exercise minutes and the
     * learning multiplier follow the runbook's standing rules (CONFIRMED_R10_BELGIAN_MALINOIS);
     * weight, growth, Coren rank and lifespan are sourced. The senior boundary uses the RKC lower
     * bound (no McMillan 2024 / VetCompass median reachable) — provisional, like the Brittany.
     *
     * @return array<string, mixed>
     */
    private static function belgianMalinoisProfile(): array
    {
        $decided = self::CONFIRMED_R10_BELGIAN_MALINOIS;
        $boundary = fn (string $note): array => [
            'unit' => 'months', 'source_id' => 'S11,S10,S314', 'confidence' => 'medium', 'verified' => true,
            'ref' => 'proposed_game_parameters.belgian_malinois.stage_boundaries_months', 'decision' => $decided,
            'notes' => $note,
        ];

        return [
            'starts_at' => [
                'young' => [9, $boundary('Game boundary (no exact month in the literature): AAHA\'s young adult starts at the cessation of rapid growth (~6–9 months, S11); a medium dog finishes growing at 12–15 months (S10). Same 9 as the other dogs.')],
                'adult' => [36, $boundary('Game boundary (no exact month in the literature): maturation completes at 3–4 years (S11); 36 months = lower end, same as the other dogs.')],
                'senior' => [90, $boundary('Derived game boundary: last 25 % of lifespan (S11) × RKC lower bound "Over 10 years" (S314) = 7.5 y = 90 months (PetMD lower bound 10 y, S317, agrees). No McMillan 2024 / VetCompass median was reachable (McMillan 2024 12 y only via Wikipedia, S318, tier C → 108, alternative only). Not chosen: 84 (Dogs Trust > 7 y, S14). Provisional until McMillan 2024 is checked.')],
            ],
            'arrival' => ['young' => 9, 'adult' => 36, 'senior' => 90],
            'arrival_meta' => [
                'unit' => 'months', 'verified' => true,
                'ref' => 'proposed_game_parameters.belgian_malinois.arrival_age_months', 'decision' => $decided,
                'notes' => 'Game value (no literature number): first month of the stage (rule of 2026-10-05), so the dog stays in this stage for the 12-week challenge.',
            ],
            'young_per_age_notes' => 'Game rule; applies "until full-grown" (12–15 months for a medium dog, S10); capped at the adult 120 minutes, which 10 × age reaches at 12 months.',
            'adult_minutes' => [120, [
                'unit' => 'minutes/day', 'source_id' => 'S314', 'confidence' => 'high', 'verified' => true,
                'ref' => 'proposed_game_parameters.belgian_malinois.exercise_minutes_adult', 'decision' => $decided,
                'quote' => 'Exercise: More than 2 hours per day',
                'notes' => 'RKC "More than 2 hours per day" (S314) → 120 min = 12,000 steps (runbook rule: "more than N" → N). AKC (S316) "significant exercise on a daily basis" agrees without a number; PetMD (S317) "more than 40 minutes" is a minimum.',
            ]],
            'senior_minutes' => [90, [
                'unit' => 'minutes/day', 'verified' => true,
                'ref' => 'proposed_game_parameters.belgian_malinois.exercise_minutes_senior', 'decision' => $decided,
                'notes' => 'Game value (no literature number): 75 % of the adult 120 minutes = 90 min = 9,000 steps. Sources only say "frequent short walks instead of one long one" (S14).',
            ]],
            'adult_weight' => [[20.0, 30.0], [
                'unit' => 'kg', 'source_id' => 'S313', 'confidence' => 'high', 'verified' => true,
                'ref' => 'belgian_malinois.adult_weight.fci',
                'quote' => 'Males about 25-30 kg.',
                'notes' => 'FCI standard (S313): males about 25–30 kg, females about 20–25 kg → 20–30 kg for the game. AKC (S316) 40–80 lb and PetMD (S317) heavier ranges are alternatives only.',
            ]],
            'growth_end' => [[12, 15], [
                'unit' => 'months', 'source_id' => 'S10', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'belgian_malinois.growth.adult_weight_reached',
                'quote' => 'Medium (24–59 pounds): 12–15 months',
                'notes' => 'Size-class value, not breed-specific (FCI 20–30 kg ≈ 44–66 lb, mostly the Medium class of S10; RKC size "Medium-Large", S314 — Siberian Husky precedent).',
            ]],
            'coren_rank' => [22, [
                'unit' => 'rank', 'source_id' => 'S35', 'confidence' => 'low', 'verified' => true,
                'ref' => 'belgian_malinois.trainability.coren_rank',
                'quote' => '| 22 | Belgian Malinois |',
                'notes' => 'Wikipedia table of Coren\'s ranking (S35), rank 22 "Belgian Malinois", tier "Excellent Working Dogs" (ranks 11–26). Coren\'s own article (S34) lists only the top and bottom 10.',
            ]],
            'learning_multiplier' => [1.5, [
                'unit' => '× mixed-breed learning speed', 'source_id' => 'S35', 'confidence' => 'low', 'verified' => true,
                'ref' => 'proposed_game_parameters.belgian_malinois.learning_multiplier', 'decision' => $decided,
                'notes' => 'Game value (no literature factor): Coren rank 22 (S35) in the "Excellent" tier (ranks 11–26) → 1.5. Multiplies the progress per correctly timed praise.',
            ]],
            // Like the other pedigree breeds: no individual variation row.
            'individual_variation' => null,
            'lifespan' => [10.0, [
                'unit' => 'years', 'source_id' => 'S314', 'confidence' => 'high', 'verified' => true,
                'ref' => 'belgian_malinois.lifespan.rkc',
                'quote' => 'Lifespan: Over 10 years',
                'notes' => 'RKC breed page lower bound (S314); PetMD "10–14 years" (S317) agrees. McMillan 2024 12 y only via Wikipedia (S318, tier C) — not used. Background for the senior boundary only — never shown as a statistic in the app.',
            ]],
        ];
    }

    /**
     * Basset Hound (M5-R10-39). Stage boundaries, arrival ages, exercise minutes and the
     * learning multiplier follow the runbook's standing rules (CONFIRMED_R10_BASSET_HOUND);
     * weight, growth, Coren rank and lifespan are sourced. The senior boundary uses the RKC lower
     * bound (no McMillan 2024 / VetCompass median reachable) — provisional, like the Belgian Malinois.
     *
     * @return array<string, mixed>
     */
    private static function bassetHoundProfile(): array
    {
        $decided = self::CONFIRMED_R10_BASSET_HOUND;
        $boundary = fn (string $note): array => [
            'unit' => 'months', 'source_id' => 'S11,S10,S321', 'confidence' => 'medium', 'verified' => true,
            'ref' => 'proposed_game_parameters.basset_hound.stage_boundaries_months', 'decision' => $decided,
            'notes' => $note,
        ];

        return [
            'starts_at' => [
                'young' => [9, $boundary('Game boundary (no exact month in the literature): AAHA\'s young adult starts at the cessation of rapid growth (~6–9 months, S11); a medium dog finishes growing at 12–15 months (S10). Same 9 as the other dogs.')],
                'adult' => [36, $boundary('Game boundary (no exact month in the literature): maturation completes at 3–4 years (S11); 36 months = lower end, same as the other dogs.')],
                'senior' => [90, $boundary('Derived game boundary: last 25 % of lifespan (S11) × RKC lower bound "Over 10 years" (S321) = 7.5 y = 90 months (PDSA "Over 10 years", S323, agrees). No McMillan 2024 / VetCompass median was reachable (McMillan 2024 12.5 y only via Wikipedia, S325, tier C → 113, alternative only). Not chosen: 84 (Dogs Trust > 7 y, S14). Provisional until McMillan 2024 is checked.')],
            ],
            'arrival' => ['young' => 9, 'adult' => 36, 'senior' => 90],
            'arrival_meta' => [
                'unit' => 'months', 'verified' => true,
                'ref' => 'proposed_game_parameters.basset_hound.arrival_age_months', 'decision' => $decided,
                'notes' => 'Game value (no literature number): first month of the stage (rule of 2026-10-05), so the dog stays in this stage for the 12-week challenge.',
            ],
            'young_per_age_notes' => 'Game rule; applies "until full-grown" (12–15 months for a medium dog, S10); capped at the adult 60 minutes, which 10 × age reaches at 6 months.',
            'adult_minutes' => [60, [
                'unit' => 'minutes/day', 'source_id' => 'S321', 'confidence' => 'high', 'verified' => true,
                'ref' => 'proposed_game_parameters.basset_hound.exercise_minutes_adult', 'decision' => $decided,
                'quote' => 'Exercise: Up to 1 hour per day',
                'notes' => 'RKC "Up to 1 hour per day" (S321) → 60 min = 6,000 steps (runbook rule: "up to N" → N). PDSA (S323) "a minimum of an hour of exercise a day" gives the same number; AKC (S324) "regular physical activity" agrees without a number.',
            ]],
            'senior_minutes' => [45, [
                'unit' => 'minutes/day', 'verified' => true,
                'ref' => 'proposed_game_parameters.basset_hound.exercise_minutes_senior', 'decision' => $decided,
                'notes' => 'Game value (no literature number): 75 % of the adult 60 minutes = 45 min = 4,500 steps. Sources only say "frequent short walks instead of one long one" (S14).',
            ]],
            'adult_weight' => [[18.0, 27.0], [
                'unit' => 'kg', 'source_id' => 'S323', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'basset_hound.adult_weight.pdsa',
                'quote' => '18kg-27kg',
                'notes' => 'PDSA key fact "Average weight" (S323), one range for both sexes. The FCI standard (S320) gives no weight; AKC (S324) 40–65 lb is an alternative only.',
            ]],
            'growth_end' => [[12, 15], [
                'unit' => 'months', 'source_id' => 'S10', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'basset_hound.growth.adult_weight_reached',
                'quote' => 'Medium (24–59 pounds): 12–15 months',
                'notes' => 'Size-class value, not breed-specific (PDSA 18–27 kg ≈ 40–60 lb, the Medium class of S10; RKC size "Medium-Large", S321).',
            ]],
            'coren_rank' => [71, [
                'unit' => 'rank', 'source_id' => 'S35', 'confidence' => 'low', 'verified' => true,
                'ref' => 'basset_hound.trainability.coren_rank',
                'quote' => '| 71 | Basset Hound |',
                'notes' => 'Wikipedia table of Coren\'s ranking (S35), rank 71 "Basset Hound", tier "Lowest Degree of Working/Obedience Intelligence" (ranks 70–79). Coren\'s own article (S34) not re-read.',
            ]],
            'learning_multiplier' => [0.5, [
                'unit' => '× mixed-breed learning speed', 'source_id' => 'S35', 'confidence' => 'low', 'verified' => true,
                'ref' => 'proposed_game_parameters.basset_hound.learning_multiplier', 'decision' => $decided,
                'notes' => 'Game value (no literature factor): Coren rank 71 (S35) in the "Lowest" tier (ranks 70–79) → 0.5. Multiplies the progress per correctly timed praise.',
            ]],
            // Like the other pedigree breeds: no individual variation row.
            'individual_variation' => null,
            'lifespan' => [10.0, [
                'unit' => 'years', 'source_id' => 'S321', 'confidence' => 'high', 'verified' => true,
                'ref' => 'basset_hound.lifespan.rkc',
                'quote' => 'Lifespan: Over 10 years',
                'notes' => 'RKC breed page lower bound (S321); PDSA "Over 10 years" (S323) agrees. McMillan 2024 12.5 y only via Wikipedia (S325, tier C) — not used. Background for the senior boundary only — never shown as a statistic in the app.',
            ]],
        ];
    }

    /**
     * Hungarian Vizsla (M5-R10-40). Stage boundaries, arrival ages, exercise minutes and the
     * learning multiplier follow the runbook's standing rules (CONFIRMED_R10_HUNGARIAN_VIZSLA);
     * weight, growth, Coren rank and lifespan are sourced. The senior boundary uses the RKC lower
     * bound (no McMillan 2024 / VetCompass median reachable) — provisional, like the Basset Hound.
     *
     * @return array<string, mixed>
     */
    private static function hungarianVizslaProfile(): array
    {
        $decided = self::CONFIRMED_R10_HUNGARIAN_VIZSLA;
        $boundary = fn (string $note): array => [
            'unit' => 'months', 'source_id' => 'S11,S10,S328', 'confidence' => 'medium', 'verified' => true,
            'ref' => 'proposed_game_parameters.hungarian_vizsla.stage_boundaries_months', 'decision' => $decided,
            'notes' => $note,
        ];

        return [
            'starts_at' => [
                'young' => [9, $boundary('Game boundary (no exact month in the literature): AAHA\'s young adult starts at the cessation of rapid growth (~6–9 months, S11); a medium dog finishes growing at 12–15 months (S10). Same 9 as the other dogs.')],
                'adult' => [36, $boundary('Game boundary (no exact month in the literature): maturation completes at 3–4 years (S11); 36 months = lower end, same as the other dogs.')],
                'senior' => [108, $boundary('Derived game boundary: last 25 % of lifespan (S11) × RKC lower bound "Over 12 years" (S328) = 9 y = 108 months (PDSA "Over 12 years", S329, agrees). No McMillan 2024 / VetCompass median was reachable (McMillan 2024 13.5 y only via Wikipedia, S334, tier C → 122, alternative only). Not chosen: 90 (PetMD lower bound 10 y, S330), 84 (Dogs Trust > 7 y, S14). Provisional until McMillan 2024 is checked.')],
            ],
            'arrival' => ['young' => 9, 'adult' => 36, 'senior' => 108],
            'arrival_meta' => [
                'unit' => 'months', 'verified' => true,
                'ref' => 'proposed_game_parameters.hungarian_vizsla.arrival_age_months', 'decision' => $decided,
                'notes' => 'Game value (no literature number): first month of the stage (rule of 2026-10-05), so the dog stays in this stage for the 12-week challenge.',
            ],
            'young_per_age_notes' => 'Game rule; applies "until full-grown" (12–15 months for a medium dog, S10); capped at the adult 120 minutes, which 10 × age reaches at 12 months.',
            'adult_minutes' => [120, [
                'unit' => 'minutes/day', 'source_id' => 'S328', 'confidence' => 'high', 'verified' => true,
                'ref' => 'proposed_game_parameters.hungarian_vizsla.exercise_minutes_adult', 'decision' => $decided,
                'quote' => 'Exercise: More than 2 hours per day',
                'notes' => 'RKC "More than 2 hours per day" (S328) → 120 min = 12,000 steps (runbook rule: "more than N" → N). PDSA (S329) "a minimum of two hours exercise every day" gives the same number; PetMD (S330) "at least one hour of exercise per day" is an alternative only.',
            ]],
            'senior_minutes' => [90, [
                'unit' => 'minutes/day', 'verified' => true,
                'ref' => 'proposed_game_parameters.hungarian_vizsla.exercise_minutes_senior', 'decision' => $decided,
                'notes' => 'Game value (no literature number): 75 % of the adult 120 minutes = 90 min = 9,000 steps. Sources only say "frequent short walks instead of one long one" (S14).',
            ]],
            'adult_weight' => [[20.0, 30.0], [
                'unit' => 'kg', 'source_id' => 'S329', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'hungarian_vizsla.adult_weight.pdsa',
                'quote' => '20kg-30kg',
                'notes' => 'PDSA key fact "Average weight" (S329), one range for both sexes. The FCI (S327) and AKC (S331) standards give no weight; PetMD (S330) 40–65 lb is an alternative only.',
            ]],
            'growth_end' => [[12, 15], [
                'unit' => 'months', 'source_id' => 'S10', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'hungarian_vizsla.growth.adult_weight_reached',
                'quote' => 'Medium (24–59 pounds): 12–15 months',
                'notes' => 'Size-class value, not breed-specific (PDSA 20–30 kg ≈ 44–66 lb, mostly the Medium class of S10; RKC size "Large", S328).',
            ]],
            'coren_rank' => [25, [
                'unit' => 'rank', 'source_id' => 'S35', 'confidence' => 'low', 'verified' => true,
                'ref' => 'hungarian_vizsla.trainability.coren_rank',
                'quote' => '| 25 | Vizsla |',
                'notes' => 'Wikipedia table of Coren\'s ranking (S35), rank 25 "Vizsla", tier "Excellent Working Dogs" (ranks 11–26). Not in Coren\'s own top / bottom-10 article (S34).',
            ]],
            'learning_multiplier' => [1.5, [
                'unit' => '× mixed-breed learning speed', 'source_id' => 'S35', 'confidence' => 'low', 'verified' => true,
                'ref' => 'proposed_game_parameters.hungarian_vizsla.learning_multiplier', 'decision' => $decided,
                'notes' => 'Game value (no literature factor): Coren rank 25 (S35) in the "Excellent" tier (ranks 11–26) → 1.5. Multiplies the progress per correctly timed praise.',
            ]],
            // Like the other pedigree breeds: no individual variation row.
            'individual_variation' => null,
            'lifespan' => [12.0, [
                'unit' => 'years', 'source_id' => 'S328', 'confidence' => 'high', 'verified' => true,
                'ref' => 'hungarian_vizsla.lifespan.rkc',
                'quote' => 'Lifespan: Over 12 years',
                'notes' => 'RKC breed page lower bound (S328); PDSA "Over 12 years" (S329) agrees. McMillan 2024 13.5 y only via Wikipedia (S334, tier C) — not used. Background for the senior boundary only — never shown as a statistic in the app.',
            ]],
        ];
    }

    /**
     * English Cocker Spaniel (M5-R10-41). Stage boundaries, arrival ages, exercise minutes and the
     * learning multiplier follow the runbook's standing rules (CONFIRMED_R10_ENGLISH_COCKER_SPANIEL);
     * weight, growth, Coren rank and lifespan are sourced. The senior boundary uses the VetCompass
     * breed median (Engdahl et al. 2023, S342 — McMillan 2024 not reachable), like the Beagle.
     *
     * @return array<string, mixed>
     */
    private static function englishCockerSpanielProfile(): array
    {
        $decided = self::CONFIRMED_R10_ENGLISH_COCKER_SPANIEL;
        $boundary = fn (string $note): array => [
            'unit' => 'months', 'source_id' => 'S11,S10,S342', 'confidence' => 'medium', 'verified' => true,
            'ref' => 'proposed_game_parameters.english_cocker_spaniel.stage_boundaries_months', 'decision' => $decided,
            'notes' => $note,
        ];

        return [
            'starts_at' => [
                'young' => [9, $boundary('Game boundary (no exact month in the literature): AAHA\'s young adult starts at the cessation of rapid growth (~6–9 months, S11); a medium dog finishes growing at 12–15 months (S10). Same 9 as the other dogs.')],
                'adult' => [36, $boundary('Game boundary (no exact month in the literature): maturation completes at 3–4 years (S11); 36 months = lower end, same as the other dogs.')],
                'senior' => [103, $boundary('Derived game boundary: last 25 % of lifespan (S11) × VetCompass median age at death 11.44 y (Engdahl et al. 2023, S342) = 8.58 y = 102.96 → 103 months. McMillan 2024 (S54) gives no reachable English Cocker Spaniel value. Not chosen: 90 (RKC lower bound "Over 10 years", S337), 108 (VCA lower bound 12 y, S341), 84 (Dogs Trust > 7 y, S14).')],
            ],
            'arrival' => ['young' => 9, 'adult' => 36, 'senior' => 103],
            'arrival_meta' => [
                'unit' => 'months', 'verified' => true,
                'ref' => 'proposed_game_parameters.english_cocker_spaniel.arrival_age_months', 'decision' => $decided,
                'notes' => 'Game value (no literature number): first month of the stage (rule of 2026-10-05), so the dog stays in this stage for the 12-week challenge.',
            ],
            'young_per_age_notes' => 'Game rule; applies "until full-grown" (12–15 months for a medium dog, S10); capped at the adult 60 minutes, which 10 × age already reaches at 6 months, so the whole young stage walks the adult minutes.',
            'adult_minutes' => [60, [
                'unit' => 'minutes/day', 'source_id' => 'S337', 'confidence' => 'high', 'verified' => true,
                'ref' => 'proposed_game_parameters.english_cocker_spaniel.exercise_minutes_adult', 'decision' => $decided,
                'quote' => 'Exercise: Up to 1 hour per day',
                'notes' => 'RKC "Up to 1 hour per day" (S337) → 60 min = 6,000 steps (runbook rule: "up to N" → N). PDSA (S339) "a minimum of an hour exercise every day" gives the same number; dogs from working lines may need more (PDSA, background only).',
            ]],
            'senior_minutes' => [45, [
                'unit' => 'minutes/day', 'verified' => true,
                'ref' => 'proposed_game_parameters.english_cocker_spaniel.exercise_minutes_senior', 'decision' => $decided,
                'notes' => 'Game value (no literature number): 75 % of the adult 60 minutes = 45 min = 4,500 steps. Sources only say "frequent short walks instead of one long one" (S14).',
            ]],
            'adult_weight' => [[13.0, 14.5], [
                'unit' => 'kg', 'source_id' => 'S339', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'english_cocker_spaniel.adult_weight.pdsa',
                'quote' => '13-14.5 kg',
                'notes' => 'PDSA key fact "Average weight" (S339), one range for both sexes; the FCI standard (S336) gives about 13–14.5 kg too. AKC (S343) males 28–34 lb, females 26–32 lb is an alternative only.',
            ]],
            'growth_end' => [[12, 15], [
                'unit' => 'months', 'source_id' => 'S10', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'english_cocker_spaniel.growth.adult_weight_reached',
                'quote' => 'Medium (24–59 pounds): 12–15 months',
                'notes' => 'Size-class value, not breed-specific (PDSA 13–14.5 kg ≈ 29–32 lb, the Medium class of S10; RKC size "Small", S337).',
            ]],
            'coren_rank' => [18, [
                'unit' => 'rank', 'source_id' => 'S35', 'confidence' => 'low', 'verified' => true,
                'ref' => 'english_cocker_spaniel.trainability.coren_rank',
                'quote' => 'English Cocker Spaniel',
                'notes' => 'Wikipedia table of Coren\'s ranking (S35), rank 18 shared with the Flat-Coated Retriever and the Standard Schnauzer, tier "Excellent Working Dogs" (ranks 11–26); rank 20 "Cocker Spaniel" is the American breed. Not in Coren\'s own top / bottom-10 article (S34).',
            ]],
            'learning_multiplier' => [1.5, [
                'unit' => '× mixed-breed learning speed', 'source_id' => 'S35', 'confidence' => 'low', 'verified' => true,
                'ref' => 'proposed_game_parameters.english_cocker_spaniel.learning_multiplier', 'decision' => $decided,
                'notes' => 'Game value (no literature factor): Coren rank 18 (S35) in the "Excellent" tier (ranks 11–26) → 1.5. Multiplies the progress per correctly timed praise.',
            ]],
            // Like the other pedigree breeds: no individual variation row.
            'individual_variation' => null,
            'lifespan' => [11.44, [
                'unit' => 'years', 'source_id' => 'S342', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'english_cocker_spaniel.lifespan.median_uk',
                'quote' => 'The median age at death was 11.44 years (IQR 9.46–13.47)',
                'notes' => 'Engdahl et al. 2023 (S342), VetCompass UK. McMillan 2024 (S54) gives no reachable English Cocker Spaniel value. Background for the senior boundary only — never shown as a statistic in the app.',
            ]],
        ];
    }

    /**
     * Maltese (M5-R10-42). Stage boundaries, arrival ages, exercise minutes and the learning multiplier
     * follow the runbook's standing rules (CONFIRMED_R10_MALTESE); weight, growth, Coren rank and lifespan
     * are sourced. The senior boundary uses the RKC lower bound "Over 12 years" (S347) — McMillan 2024
     * 13.1 y only via Wikipedia (S352), like the Hungarian Vizsla.
     *
     * @return array<string, mixed>
     */
    private static function malteseProfile(): array
    {
        $decided = self::CONFIRMED_R10_MALTESE;
        $boundary = fn (string $note): array => [
            'unit' => 'months', 'source_id' => 'S11,S10,S347', 'confidence' => 'medium', 'verified' => true,
            'ref' => 'proposed_game_parameters.maltese.stage_boundaries_months', 'decision' => $decided,
            'notes' => $note,
        ];

        return [
            'starts_at' => [
                'young' => [9, $boundary('Game boundary (no exact month in the literature): AAHA\'s young adult starts at the cessation of rapid growth (~6–9 months, S11); a toy dog finishes growing at 8–12 months (S10). Same 9 as the other dogs.')],
                'adult' => [36, $boundary('Game boundary (no exact month in the literature): maturation completes at 3–4 years (S11); 36 months = lower end, same as the other dogs.')],
                'senior' => [108, $boundary('Derived game boundary: last 25 % of lifespan (S11) × RKC lower bound "Over 12 years" (S347) = 9 y = 108 months (PDSA "Over 12 years", S348, and PetMD 12–15 y, S351, agree). No McMillan 2024 / VetCompass median was reachable (McMillan 2024 13.1 y only via Wikipedia, S352, tier C → 118, alternative only). Not chosen: 84 (Dogs Trust > 7 y, S14). Provisional until McMillan 2024 is checked.')],
            ],
            'arrival' => ['young' => 9, 'adult' => 36, 'senior' => 108],
            'arrival_meta' => [
                'unit' => 'months', 'verified' => true,
                'ref' => 'proposed_game_parameters.maltese.arrival_age_months', 'decision' => $decided,
                'notes' => 'Game value (no literature number): first month of the stage (rule of 2026-10-05), so the dog stays in this stage for the 12-week challenge.',
            ],
            'young_per_age_notes' => 'Game rule; applies "until full-grown" (8–12 months for a toy dog, S10); capped at the adult 30 minutes, which 10 × age already reaches at 3 months, so the whole young stage walks the adult minutes.',
            'adult_minutes' => [30, [
                'unit' => 'minutes/day', 'source_id' => 'S347', 'confidence' => 'high', 'verified' => true,
                'ref' => 'proposed_game_parameters.maltese.exercise_minutes_adult', 'decision' => $decided,
                'quote' => 'Exercise: Up to 30 minutes per day',
                'notes' => 'RKC "Up to 30 minutes per day" (S347) → 30 min = 3,000 steps (runbook rule: "up to N" → N). PDSA (S348) "up to half an hour a day usually sufficient" gives the same number.',
            ]],
            'senior_minutes' => [23, [
                'unit' => 'minutes/day', 'verified' => true,
                'ref' => 'proposed_game_parameters.maltese.exercise_minutes_senior', 'decision' => $decided,
                'notes' => 'Game value (no literature number): 75 % of the adult 30 minutes = 22.5 → 23 min (half up) = 2,300 steps. Sources only say "frequent short walks instead of one long one" (S14).',
            ]],
            'adult_weight' => [[3.0, 4.0], [
                'unit' => 'kg', 'source_id' => 'S346', 'confidence' => 'high', 'verified' => true,
                'ref' => 'maltese.adult_weight.fci',
                'quote' => 'Weight: 3–4 kg.',
                'notes' => 'FCI standard (S346), one range for both sexes; PDSA (S348) "3kg-4kg" agrees. AKC (S349) "under 7 pounds, with from 4 to 6 pounds preferred" (1.8–2.7 kg) is an alternative only.',
            ]],
            'growth_end' => [[8, 12], [
                'unit' => 'months', 'source_id' => 'S10', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'maltese.growth.adult_weight_reached',
                'quote' => 'Toy (5–12 pounds): 8–12 months',
                'notes' => 'Size-class value, not breed-specific (FCI / PDSA 3–4 kg ≈ 6.6–8.8 lb, the Toy class of S10; RKC size "Small", S347).',
            ]],
            'coren_rank' => [59, [
                'unit' => 'rank', 'source_id' => 'S35', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'maltese.trainability.coren_rank',
                'quote' => 'Maltese',
                'notes' => 'Wikipedia table of Coren\'s ranking (S35), rank 59 shared with the Griffon Bruxellois, tier "Fair Working/Obedience Intelligence" (ranks 55–69); Wikipedia "Maltese dog" (S352) agrees. Not in Coren\'s own top / bottom-10 article (S34).',
            ]],
            'learning_multiplier' => [0.7, [
                'unit' => '× mixed-breed learning speed', 'source_id' => 'S35', 'confidence' => 'low', 'verified' => true,
                'ref' => 'proposed_game_parameters.maltese.learning_multiplier', 'decision' => $decided,
                'notes' => 'Game value (no literature factor): Coren rank 59 (S35) in the "Fair" tier (ranks 55–69) → 0.7. Multiplies the progress per correctly timed praise.',
            ]],
            // Like the other pedigree breeds: no individual variation row.
            'individual_variation' => null,
            'lifespan' => [12.0, [
                'unit' => 'years', 'source_id' => 'S347', 'confidence' => 'high', 'verified' => true,
                'ref' => 'maltese.lifespan.rkc',
                'quote' => 'Lifespan: Over 12 years',
                'notes' => 'RKC Breeds A–Z lower bound (S347); PDSA "Over 12 years" (S348) agrees. McMillan 2024 13.1 y only via Wikipedia (S352, tier C) — not used. Background for the senior boundary only — never shown as a statistic in the app.',
            ]],
        ];
    }

    /**
     * Pug (M5-R10-43). Stage boundaries, arrival ages, exercise minutes and the learning multiplier
     * follow the runbook's standing rules (CONFIRMED_R10_PUG); weight, growth, Coren rank and lifespan
     * are sourced. The senior boundary uses the McMillan 2024 median 11.6 y (corrected Fig. 3 legend, S54),
     * like the French Bulldog and the Bulldog.
     *
     * @return array<string, mixed>
     */
    private static function pugProfile(): array
    {
        $decided = self::CONFIRMED_R10_PUG;
        $boundary = fn (string $note): array => [
            'unit' => 'months', 'source_id' => 'S11,S10,S54', 'confidence' => 'medium', 'verified' => true,
            'ref' => 'proposed_game_parameters.pug.stage_boundaries_months', 'decision' => $decided,
            'notes' => $note,
        ];

        return [
            'starts_at' => [
                'young' => [9, $boundary('Game boundary (no exact month in the literature): AAHA\'s young adult starts at the cessation of rapid growth (~6–9 months, S11); a small dog finishes growing at 9–12 months (S10). Same 9 as the other dogs.')],
                'adult' => [36, $boundary('Game boundary (no exact month in the literature): maturation completes at 3–4 years (S11); 36 months = lower end, same as the other dogs.')],
                'senior' => [104, $boundary('Derived game boundary: last 25 % of lifespan (S11) × McMillan 2024 median 11.6 y (corrected Fig. 3 legend, S54) = 8.7 y = 104.4 → 104 months. Not chosen: 90 (0.75 × RKC lower bound "Over 10 years", S355), 84 (Dogs Trust > 7 y, S14).')],
            ],
            'arrival' => ['young' => 9, 'adult' => 36, 'senior' => 104],
            'arrival_meta' => [
                'unit' => 'months', 'verified' => true,
                'ref' => 'proposed_game_parameters.pug.arrival_age_months', 'decision' => $decided,
                'notes' => 'Game value (no literature number): first month of the stage (rule of 2026-10-05), so the dog stays in this stage for the 12-week challenge.',
            ],
            'young_per_age_notes' => 'Game rule; applies "until full-grown" (9–12 months for a small dog, S10); capped at the adult 60 minutes, which 10 × age already reaches at 6 months, so the whole young stage walks the adult minutes.',
            'adult_minutes' => [60, [
                'unit' => 'minutes/day', 'source_id' => 'S355', 'confidence' => 'high', 'verified' => true,
                'ref' => 'proposed_game_parameters.pug.exercise_minutes_adult', 'decision' => $decided,
                'quote' => 'Exercise: Up to 1 hour per day',
                'notes' => 'RKC "Up to 1 hour per day" (S355) → 60 min = 6,000 steps (runbook rule: "up to N" → N). PDSA (S357) "up to an hour of exercise a day" gives the same number; PDSA and PetMD (S359) advise several shorter walks and no exercise in the heat.',
            ]],
            'senior_minutes' => [45, [
                'unit' => 'minutes/day', 'verified' => true,
                'ref' => 'proposed_game_parameters.pug.exercise_minutes_senior', 'decision' => $decided,
                'notes' => 'Game value (no literature number): 75 % of the adult 60 minutes = 45 min = 4,500 steps. Sources only say "frequent short walks instead of one long one" (S14).',
            ]],
            'adult_weight' => [[6.3, 8.1], [
                'unit' => 'kg', 'source_id' => 'S354', 'confidence' => 'high', 'verified' => true,
                'ref' => 'pug.adult_weight.fci',
                'quote' => 'Ideal weight 6,3 – 8,1 kg.',
                'notes' => 'FCI standard (S354), one ideal range for both sexes; PDSA (S357) "6-8 kg" agrees. AKC (S358) 14–18 lb (6.4–8.2 kg) is an alternative only.',
            ]],
            'growth_end' => [[9, 12], [
                'unit' => 'months', 'source_id' => 'S10', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'pug.growth.adult_weight_reached',
                'quote' => 'Small (12–24 pounds): 9–12 months',
                'notes' => 'Size-class value, not breed-specific (FCI 6.3–8.1 kg ≈ 13.9–17.9 lb, the Small class of S10; RKC size "Small", S355).',
            ]],
            'coren_rank' => [57, [
                'unit' => 'rank', 'source_id' => 'S35', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'pug.trainability.coren_rank',
                'quote' => 'Pug',
                'notes' => 'Wikipedia table of Coren\'s ranking (S35), rank 57 (not shared), tier "Fair Working/Obedience Intelligence" (ranks 55–69). Not in Coren\'s own top / bottom-10 article (S34).',
            ]],
            'learning_multiplier' => [0.7, [
                'unit' => '× mixed-breed learning speed', 'source_id' => 'S35', 'confidence' => 'low', 'verified' => true,
                'ref' => 'proposed_game_parameters.pug.learning_multiplier', 'decision' => $decided,
                'notes' => 'Game value (no literature factor): Coren rank 57 (S35) in the "Fair" tier (ranks 55–69) → 0.7. Multiplies the progress per correctly timed praise.',
            ]],
            // Like the other pedigree breeds: no individual variation row.
            'individual_variation' => null,
            'lifespan' => [11.6, [
                'unit' => 'years', 'source_id' => 'S54', 'confidence' => 'high', 'verified' => true,
                'ref' => 'pug.lifespan.median_uk',
                'quote' => 'Pug (brown, x̃=11.6)',
                'notes' => 'McMillan et al. 2024, corrected Fig. 3 legend (S54); RKC / PDSA "Over 10 years" (S355, S357) are consistent. Background for the senior boundary only — never shown as a statistic in the app.',
            ]],
        ];
    }

    /**
     * Dog rows (rows()) followed by the cat rows (catRows()) — what run() seeds.
     *
     * @return list<array<string, mixed>>
     */
    public static function allRows(): array
    {
        return array_merge(self::rows(), self::catRows());
    }

    /**
     * Cat life-stage data (M5-R06-03): every value from
     * docs/research/cat-data/data.json (`ref` = self::CAT_REF + JSON path).
     * `verified` = true when the entry has a source (C-id) or a recorded David
     * decision (CAT_SPEC Q1–Q10 13:47, the M5-R06 plan answers, or the play
     * answers of 20:40 — play_min_gap_minutes, which R06-03 seeded as an
     * unverified proposal; 2026_10_26_130000 flips the existing rows).
     * Only values data.json gives are seeded: no exercise / step keys (cats
     * have no steps), no senior sleep hours (no entry), no grooming row for
     * the domestic cat (no grooming routine, CAT_SPEC Q8). Pedigree cats have
     * their own kitten arrival 3, lifespan and grooming rows (Maine Coon 3×,
     * British Shorthair 1×, Ragdoll 2× per week — David 2026-10-11; Siberian 1× —
     * runbook rule, M5-R10-16; Norwegian Forest Cat 1× — runbook rule, M5-R10-17; Bengal 1× —
     * runbook rule, M5-R10-18).
     *
     * @return list<array<string, mixed>>
     */
    public static function catRows(): array
    {
        $rows = [];

        foreach (self::CAT_BREEDS as $breed) {
            $mc = $breed === BreedType::MaineCoon;
            $bsh = $breed === BreedType::BritishShorthair;
            $rag = $breed === BreedType::Ragdoll;
            $sib = $breed === BreedType::SiberianCat;
            $nfo = $breed === BreedType::NorwegianForestCat;
            $ben = $breed === BreedType::Bengal;
            $slug = $breed->slug();
            $add = function (string $stage, int $from, StageParamKey $key, mixed $value, array $meta) use (&$rows, $slug): void {
                $rows[] = array_merge([
                    'breed_slug' => $slug,
                    'stage' => $stage,
                    'age_from_months' => $from,
                    'key' => $key->value,
                    'value' => $value,
                    'unit' => null,
                    'source_id' => null,
                    'confidence' => 'low',
                    'verified' => false,
                    'quote' => null,
                    'notes' => null,
                    'ref' => null,
                    'decision' => null,
                ], $meta, ['ref' => isset($meta['ref']) ? self::CAT_REF.$meta['ref'] : null]);
            };

            // ── Stage boundaries (AAHA/AAFP 2021 feline life stages, C1) ──
            $add('puppy', 0, StageParamKey::StartsAtMonths, 0, [
                'unit' => 'months', 'source_id' => 'C1', 'confidence' => 'high', 'verified' => true,
                'ref' => 'general.life_stages.kitten',
                'quote' => 'kitten, from birth up to 1 year old',
                'notes' => 'Kitten (CAT_SPEC §2); stored as stage puppy.',
            ]);
            foreach ([
                ['young', 12, 'young_adult', 'young adult, 1-6 years old', 'Young cat; stored as stage young.'],
                ['adult', 84, 'mature_adult', 'mature adult, 7-10 years old', 'Mature cat; stored as stage adult.'],
                ['senior', 120, 'senior', 'senior, 10 years old and up', 'Senior cat. The guideline ranges overlap at 10 years; unlike dogs the boundary is NOT 0.75 × lifespan.'],
            ] as [$stage, $month, $ref, $quote, $note]) {
                $add($stage, 0, StageParamKey::StartsAtMonths, $month, [
                    'unit' => 'months', 'source_id' => 'C1', 'confidence' => 'high', 'verified' => true,
                    'ref' => 'general.life_stages.'.$ref, 'decision' => self::CONFIRMED_CAT,
                    'quote' => $quote, 'notes' => $note,
                ]);
            }

            // ── Age at arrival (CAT_SPEC Q4) ─────────────────────────────────
            $add('puppy', 0, StageParamKey::ArrivalAgeMonths, $mc || $bsh || $rag || $sib || $nfo || $ben ? 3 : 2, $mc ? [
                'unit' => 'months', 'source_id' => 'C13', 'confidence' => 'high', 'verified' => true,
                'ref' => 'maine_coon.arrival_age_kitten', 'decision' => self::CONFIRMED_CAT,
                'quote' => 'Pedigree kittens are usually rehomed over 12-13 weeks old',
            ] : ($bsh ? [
                'unit' => 'months', 'source_id' => 'C13', 'confidence' => 'high', 'verified' => true,
                'ref' => 'british_shorthair.arrival_age_kitten', 'decision' => self::CONFIRMED_R10_BRITISH_SHORTHAIR,
                'quote' => 'Pedigree kittens are usually rehomed over 12-13 weeks old',
                'notes' => 'Same rule as the Maine Coon (CAT_SPEC Q4: pedigree kittens).',
            ] : ($rag ? [
                'unit' => 'months', 'source_id' => 'C13', 'confidence' => 'high', 'verified' => true,
                'ref' => 'ragdoll.arrival_age_kitten', 'decision' => self::CONFIRMED_R10_RAGDOLL,
                'quote' => 'Pedigree kittens are usually rehomed over 12-13 weeks old',
                'notes' => 'Same rule as the Maine Coon and the British Shorthair (CAT_SPEC Q4: pedigree kittens).',
            ] : ($sib ? [
                'unit' => 'months', 'source_id' => 'C13', 'confidence' => 'high', 'verified' => true,
                'ref' => 'siberian_cat.arrival_age_kitten', 'decision' => self::CONFIRMED_R10_SIBERIAN_CAT,
                'quote' => 'Pedigree kittens are usually rehomed over 12-13 weeks old',
                'notes' => 'Same rule as the Maine Coon, British Shorthair and Ragdoll (CAT_SPEC Q4: pedigree kittens).',
            ] : ($nfo ? [
                'unit' => 'months', 'source_id' => 'C13', 'confidence' => 'high', 'verified' => true,
                'ref' => 'norwegian_forest_cat.arrival_age_kitten', 'decision' => self::CONFIRMED_R10_NORWEGIAN_FOREST_CAT,
                'quote' => 'Pedigree kittens are usually rehomed over 12-13 weeks old',
                'notes' => 'Same rule as the Maine Coon, British Shorthair, Ragdoll and Siberian (CAT_SPEC Q4: pedigree kittens).',
            ] : ($ben ? [
                'unit' => 'months', 'source_id' => 'C13', 'confidence' => 'high', 'verified' => true,
                'ref' => 'bengal.arrival_age_kitten', 'decision' => self::CONFIRMED_R10_BENGAL,
                'quote' => 'Pedigree kittens are usually rehomed over 12-13 weeks old',
                'notes' => 'Same rule as the Maine Coon, British Shorthair, Ragdoll, Siberian and Norwegian Forest Cat (CAT_SPEC Q4: pedigree kittens).',
            ] : [
                'unit' => 'months', 'source_id' => 'C13', 'confidence' => 'high', 'verified' => true,
                'ref' => 'general.arrival_age.kitten_min', 'decision' => self::CONFIRMED_CAT,
                'quote' => 'Kittens shouldn\'t be rehomed until they\'re at least 8 weeks old',
            ]))))));
            foreach (['young' => 12, 'adult' => 84, 'senior' => 120] as $stage => $age) {
                $add($stage, 0, StageParamKey::ArrivalAgeMonths, $age, [
                    'unit' => 'months', 'verified' => true,
                    'ref' => 'general.arrival_age.'.$stage, 'decision' => self::CONFIRMED_CAT,
                    'notes' => 'Game value (no literature number): first month of the stage, as for dogs.',
                ]);
            }

            // ── Meals per day (counts sourced, C2 / C3) ──────────────────────
            $add('puppy', 0, StageParamKey::MealsPerDay, 4, [
                'unit' => 'meals/day', 'source_id' => 'C3', 'confidence' => 'high', 'verified' => true,
                'ref' => 'general.meals_per_day.kitten_6_12_weeks',
                'quote' => '6-12 weeks old - 4 meals per day',
            ]);
            $add('puppy', 3, StageParamKey::MealsPerDay, 3, [
                'unit' => 'meals/day', 'source_id' => 'C2,C3', 'confidence' => 'high', 'verified' => true,
                'ref' => 'general.meals_per_day.kitten_3_6_months',
                'quote' => 'Until they are six months old, kittens will usually do best when fed three meals a day / 3-6 months old - 2-3 meals per day',
            ]);
            $add('puppy', 6, StageParamKey::MealsPerDay, 2, [
                'unit' => 'meals/day', 'source_id' => 'C2,C3', 'confidence' => 'high', 'verified' => true,
                'ref' => 'general.meals_per_day.kitten_6_12_months',
                'quote' => 'twice daily feeding is generally best / 6-9 months old - 2 meals per day',
                'notes' => 'Two meals: no feed_windows row — the breed_configs windows 06–10 / 17–21 apply (the data.json windows of this band).',
            ]);
            foreach (['young', 'adult'] as $stage) {
                $add($stage, 0, StageParamKey::MealsPerDay, 2, [
                    'unit' => 'meals/day', 'source_id' => 'C2', 'confidence' => 'medium', 'verified' => true,
                    'ref' => 'general.meals_per_day.adult', 'decision' => self::CONFIRMED_CAT_PLAN,
                    'quote' => 'feeding once or twice a day is appropriate in most cases',
                    'notes' => 'Game simplification: the upper end; guidelines also favour many small meals (C8, C9).',
                ]);
            }
            $add('senior', 0, StageParamKey::MealsPerDay, 2, [
                'unit' => 'meals/day', 'source_id' => 'C2', 'confidence' => 'high', 'verified' => true,
                'ref' => 'general.meals_per_day.senior',
                'quote' => 'should maintain the same feeding regimen',
            ]);

            // ── Feed window clock times (CAT_SPEC §3, dog clock rule) ────────
            // The windows are listed in the notes of the meal entries of data.json.
            $windowsMeta = fn (string $ref): array => [
                'unit' => 'HH:MM family-local [start, end)', 'verified' => true,
                'ref' => 'general.meals_per_day.'.$ref, 'decision' => self::CONFIRMED_CAT,
                'notes' => 'Game clock times (no literature number; the meal COUNT is sourced, C3 / C2). CAT_SPEC §3 (approved by David 2026-10-08 13:47) lists these windows — the dog clock rule of 2026-10-05 (first window 07:00, last 19:00, equal spacing, 2 h each). A window entirely inside quiet hours is done by the parent.',
            ];
            $add('puppy', 0, StageParamKey::FeedWindows, self::PUPPY_4_MEAL_WINDOWS, $windowsMeta('kitten_6_12_weeks'));
            $add('puppy', 3, StageParamKey::FeedWindows, self::PUPPY_3_MEAL_WINDOWS, $windowsMeta('kitten_3_6_months'));

            // ── Sleep (videos / behaviour only, not a rule; C12) ─────────────
            $add('puppy', 0, StageParamKey::SleepHours, null, [
                'unit' => 'hours/day', 'source_id' => 'C12', 'confidence' => 'medium', 'verified' => true,
                'ref' => 'general.sleep.kitten', 'quote' => 'up to 20 hours',
                'notes' => 'Source gives only a maximum ("up to 20 h"), no [min, max] range → null.',
            ]);
            foreach (['young', 'adult'] as $stage) {
                $add($stage, 0, StageParamKey::SleepHours, [12, 16], [
                    'unit' => 'hours/day', 'source_id' => 'C12', 'confidence' => 'medium', 'verified' => true,
                    'ref' => 'general.sleep.adult', 'quote' => 'Cats sleep between 12–16 hours a day',
                ]);
            }

            // ── Play (replaces the walk; rules in M5-R06-04) ─────────────────
            $add('puppy', 0, StageParamKey::PlaySessionsPerDay, 3, [
                'unit' => 'wand play sessions/day', 'source_id' => 'C10', 'confidence' => 'low', 'verified' => true,
                'ref' => 'general.play.game_sessions_kitten', 'decision' => self::CONFIRMED_CAT,
                'notes' => 'C10: 2–3 sessions of 10–15 min a day, kittens more. One ~60 s mini-game counts as one real session.',
            ]);
            foreach (['young', 'adult', 'senior'] as $stage) {
                $add($stage, 0, StageParamKey::PlaySessionsPerDay, 2, [
                    'unit' => 'wand play sessions/day', 'source_id' => 'C10', 'confidence' => 'low', 'verified' => true,
                    'ref' => 'general.play.game_sessions_adult', 'decision' => self::CONFIRMED_CAT,
                    'notes' => 'C10: 2–3 sessions of 10–15 min a day. One ~60 s mini-game counts as one real session.',
                ]);
            }
            $add('all', 0, StageParamKey::PlayMinGapMinutes, 120, [
                'unit' => 'minutes between two play sessions', 'confidence' => 'low', 'verified' => true,
                'ref' => 'general.play.game_min_gap', 'decision' => self::CONFIRMED_CAT_PLAY,
                'notes' => 'Game value (no literature number), CAT_SPEC §5.2: at least 2 h between two successful wand sessions, measured from the last successful one, so the sessions spread over the day (C11: several short sessions through the day).',
            ]);
            $add('all', 0, StageParamKey::ScratchingAfterMissedPlay, true, [
                'unit' => 'bool: a missed play day → "scratched the sofa" the next day (max 1/day)', 'source_id' => 'C8,C22', 'confidence' => 'low', 'verified' => true,
                'ref' => 'general.scratching.game_trigger', 'decision' => self::CONFIRMED_CAT,
                'notes' => 'No frequency source (boredom → unwanted behaviour, C8 / C22). Only after a missed play routine, never random for kittens; no illness from missed play (general.play.missed_play_illness).',
            ]);

            // ── Litter (replaces poop events; rules in M5-R06-05) ────────────
            $add('puppy', 0, StageParamKey::LitterUsesPerDay, 3, [
                'unit' => 'litter uses/day outside quiet hours', 'source_id' => 'C6', 'confidence' => 'low', 'verified' => true,
                'ref' => 'general.litter.game_uses_per_day_kitten', 'decision' => self::CONFIRMED_CAT,
                'notes' => 'C6: kittens poo 1–6 (young) / 1–3 (older) times a day. Each use = one "scoop the litter" routine; a use is not a mess.',
            ]);
            foreach (['young', 'adult', 'senior'] as $stage) {
                $add($stage, 0, StageParamKey::LitterUsesPerDay, 2, [
                    'unit' => 'litter uses/day outside quiet hours', 'source_id' => 'C6', 'confidence' => 'low', 'verified' => true,
                    'ref' => 'general.litter.game_uses_per_day_adult', 'decision' => self::CONFIRMED_CAT,
                    'notes' => 'C6: adults pee 2–4 and poo 1–2 times a day. Each use = one "scoop the litter" routine; a use is not a mess.',
                ]);
            }
            $add('all', 0, StageParamKey::LitterScoopDeadlineHours, 4, [
                'unit' => 'hours outside quiet hours', 'confidence' => 'low', 'verified' => true,
                'ref' => 'general.litter.game_scoop_deadline', 'decision' => self::CONFIRMED_CAT,
                'notes' => 'Game value (no literature number — sources give only the scooping frequency, C5–C7). On expiry: a mess next to the tray → the dog hygiene ladder. (While the weekly change is overdue the deadline is 2 h — general.litter.game_overdue_change_deadline, rule in M5-R06-05.)',
            ]);
            $add('all', 0, StageParamKey::LitterFullChangeDays, 7, [
                'unit' => 'days (one full change per program week)', 'source_id' => 'C5,C6,C7', 'confidence' => 'high', 'verified' => true,
                'ref' => 'general.litter.full_change', 'decision' => self::CONFIRMED_CAT,
                'quote' => 'Dump everything, wash with a mild detergent and refill at least once a week',
            ]);

            // ── Breed level ─────────────────────────────────────────────────
            if ($mc) {
                $add('all', 0, StageParamKey::GroomingSessionsPerWeek, 3, [
                    'unit' => 'combing sessions/week (≥ 1 day apart)', 'source_id' => 'C17', 'confidence' => 'low', 'verified' => true,
                    'ref' => 'maine_coon.grooming.game_sessions_per_week', 'decision' => self::CONFIRMED_CAT,
                    'notes' => 'Sources disagree: TICA daily for thick coats, CFA a couple of times a week (C17), Vetstreet weekly (C18). Matted coat after 2 missed sessions (maine_coon.grooming.game_matted_after_missed, rule in M5-R06-05).',
                ]);
            }
            if ($bsh) {
                $add('all', 0, StageParamKey::GroomingSessionsPerWeek, 1, [
                    'unit' => 'combing sessions/week', 'source_id' => 'C28', 'confidence' => 'medium', 'verified' => true,
                    'ref' => 'british_shorthair.grooming.game_sessions_per_week', 'decision' => self::CONFIRMED_CAT_GROOMING_2026_10_11,
                    'quote' => 'Run a comb through its coat at least weekly to keep it mat-free and looking healthy.',
                    'notes' => '"At least weekly" → 1 slot per program week. Matted coat when the week\'s grooming is missed (ceil(1 / 2) = 1).',
                ]);
            }
            if ($rag) {
                $add('all', 0, StageParamKey::GroomingSessionsPerWeek, 2, [
                    'unit' => 'combing sessions/week (≥ 1 day apart)', 'source_id' => 'C32', 'confidence' => 'medium', 'verified' => true,
                    'ref' => 'ragdoll.grooming.game_sessions_per_week', 'decision' => self::CONFIRMED_CAT_GROOMING_2026_10_11,
                    'quote' => 'Some Ragdolls only need to be combed (more effective than brushing) once or twice a week',
                    'notes' => 'Upper bound of the PetMD range (CFA C30 "on a regular basis", VetCompass C31 "need more grooming care"). Matted coat when ≥ 1 of 2 is missed (ceil(2 / 2)).',
                ]);
            }
            if ($sib) {
                $add('all', 0, StageParamKey::GroomingSessionsPerWeek, 1, [
                    'unit' => 'combing sessions/week', 'source_id' => 'C35', 'confidence' => 'medium', 'verified' => true,
                    'ref' => 'siberian_cat.grooming.game_sessions_per_week', 'decision' => self::CONFIRMED_R10_SIBERIAN_CAT_GROOMING,
                    'quote' => 'The Siberian’s water-resistant triple coat is not maintenance-free, requiring grooming sessions at least once a week.',
                    'notes' => '"At least once a week" → 1 slot per program week (runbook §3 Cats). Extra combing in the spring / autumn moult is not modelled; GCCF C34 "a few times a week" kept as an alternative for David. Matted coat when the week\'s grooming is missed (ceil(1 / 2) = 1).',
                ]);
            }
            if ($nfo) {
                $add('all', 0, StageParamKey::GroomingSessionsPerWeek, 1, [
                    'unit' => 'combing sessions/week', 'source_id' => 'C38', 'confidence' => 'medium', 'verified' => true,
                    'ref' => 'norwegian_forest_cat.grooming.game_sessions_per_week', 'decision' => self::CONFIRMED_R10_NORWEGIAN_FOREST_CAT_GROOMING,
                    'quote' => 'it requires regular combing and brushing—at least once a week',
                    'notes' => '"At least once a week" → 1 slot per program week (runbook §3 Cats); GCCF C37 "Weekly grooming" agrees. Extra combing in the spring / autumn shed is not modelled. Matted coat when the week\'s grooming is missed (ceil(1 / 2) = 1).',
                ]);
            }
            if ($ben) {
                $add('all', 0, StageParamKey::GroomingSessionsPerWeek, 1, [
                    'unit' => 'combing sessions/week', 'source_id' => 'C44', 'confidence' => 'medium', 'verified' => true,
                    'ref' => 'bengal.grooming.game_sessions_per_week', 'decision' => self::CONFIRMED_R10_BENGAL_GROOMING,
                    'quote' => 'Bengals have striking markings on their coat, which requires a light brushing/combing once a week.',
                    'notes' => '"Once a week" → 1 slot per program week (runbook §3 Cats); PetMD C45 "should be brushed weekly" agrees. Matted coat when the week\'s grooming is missed (ceil(1 / 2) = 1).',
                ]);
            }
            if ($bsh) {
                $add('all', 0, StageParamKey::LifespanYears, 9.58, [
                    'unit' => 'years (life expectancy at birth, UK)', 'source_id' => 'C14', 'confidence' => 'medium', 'verified' => true,
                    'ref' => 'british_shorthair.lifespan.expectancy_at_birth',
                    'quote' => '"British" (British Blue + Longhair) 9.58 y',
                    'notes' => 'The paper\'s "British" group (wider than the British Shorthair alone). Background for parents and appearance only; the senior boundary comes from C1, not from the lifespan.',
                ]);
            } elseif ($rag) {
                $add('all', 0, StageParamKey::LifespanYears, 10.31, [
                    'unit' => 'years (life expectancy at birth, UK)', 'source_id' => 'C14', 'confidence' => 'high', 'verified' => true,
                    'ref' => 'ragdoll.lifespan.expectancy_at_birth',
                    'quote' => 'Ragdoll 10.31 y',
                    'notes' => 'Background for parents and appearance only; the senior boundary comes from C1, not from the lifespan.',
                ]);
            } elseif ($sib) {
                $add('all', 0, StageParamKey::LifespanYears, 12.0, [
                    'unit' => 'years (lower bound of the GCCF range 12–15)', 'source_id' => 'C34', 'confidence' => 'medium', 'verified' => true,
                    'ref' => 'siberian_cat.lifespan.gccf_lower_bound', 'decision' => self::CONFIRMED_R10_SIBERIAN_CAT,
                    'quote' => 'The Siberian cat breed is very healthy and can live 12 to 15 years or more.',
                    'notes' => 'No Siberian row in the VetCompass life tables (C14) → the GCCF lower bound (the dog rule for a range). Background for parents and appearance only; the senior boundary comes from C1, not from the lifespan.',
                ]);
            } elseif ($nfo) {
                $add('all', 0, StageParamKey::LifespanYears, 14.0, [
                    'unit' => 'years (lower bound of the GCCF range 14–16)', 'source_id' => 'C37', 'confidence' => 'medium', 'verified' => true,
                    'ref' => 'norwegian_forest_cat.lifespan.gccf_lower_bound', 'decision' => self::CONFIRMED_R10_NORWEGIAN_FOREST_CAT,
                    'quote' => 'Lifespan is usually 14 to 16 years of age.',
                    'notes' => 'No Norwegian Forest Cat row in the VetCompass life tables (C14) → the GCCF lower bound (the dog rule for a range). Background for parents and appearance only; the senior boundary comes from C1, not from the lifespan.',
                ]);
            } elseif ($ben) {
                $add('all', 0, StageParamKey::LifespanYears, 8.51, [
                    'unit' => 'years (life expectancy at birth, UK)', 'source_id' => 'C14', 'confidence' => 'medium', 'verified' => true,
                    'ref' => 'bengal.lifespan.expectancy_at_birth', 'decision' => self::CONFIRMED_R10_BENGAL,
                    'quote' => 'Bengal 8.51 y',
                    'notes' => 'VetCompass life expectancy at age 0 (same measure as the Maine Coon, British Shorthair and Ragdoll rows). Background for parents and appearance only; the senior boundary comes from C1, not from the lifespan.',
                ]);
            } else {
                $add('all', 0, StageParamKey::LifespanYears, $mc ? 9.71 : 11.89, [
                    'unit' => 'years (life expectancy at birth, UK)', 'source_id' => 'C14', 'confidence' => 'high', 'verified' => true,
                    'ref' => ($mc ? 'maine_coon' : 'domestic_cat').'.lifespan.expectancy_at_birth',
                    'quote' => $mc ? 'Maine Coon 9.71 y (8.42–11.00)' : 'crossbred 11.89 y (11.76–12.03)',
                    'notes' => 'Background for parents and appearance only; the senior boundary comes from C1, not from the lifespan.',
                ]);
            }
        }

        return $rows;
    }

    /**
     * Run the database seeds (insert-only).
     */
    public function run(): void
    {
        $now = now();
        $breeds = DB::table('breed_configs')->pluck('breed_slug')->all();
        $touched = self::touchedTuples();

        $insert = [];
        foreach (self::allRows() as $row) {
            if (! in_array($row['breed_slug'], $breeds, true)) {
                continue;
            }
            if (isset($touched[self::tupleKey($row['breed_slug'], $row['stage'], (int) $row['age_from_months'], $row['key'])])) {
                continue; // an admin edited / re-keyed / deleted it: never re-insert
            }

            $key = StageParamKey::from($row['key']);
            if (($error = $key->validate($row['value'])) !== null) {
                throw new InvalidArgumentException("BreedStageParamsSeeder: {$row['breed_slug']}.{$row['stage']}.{$row['key']}: {$error}");
            }

            $insert[] = array_merge([
                'breed_slug' => $row['breed_slug'],
                'stage' => $row['stage'],
                'age_from_months' => $row['age_from_months'],
                'key' => $row['key'],
            ], self::columns($row), [
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // One multi-row INSERT … ON CONFLICT DO NOTHING per chunk instead of one
        // query per row (~2,000 rows): same insert-only semantics, ~40× faster —
        // the test suite seeds this before >1,600 tests (perf 2026-10-15).
        foreach (array_chunk($insert, 500) as $chunk) {
            DB::table('breed_stage_params')->insertOrIgnore($chunk);
        }

        // QA m2: raw inserts bypass the model hooks that forget the rules
        // cache — clear it for every breed so new rows apply right away.
        foreach ($breeds as $slug) {
            LifeStageService::forgetBreed($slug);
        }
    }

    /**
     * The stored columns of one seeder row except the tuple (value as JSON,
     * the decision folded into `notes`). Shared with the data migration that
     * applied David's 2026-10-05 decisions to existing rows.
     *
     * @param  array<string, mixed>  $row
     * @return array{value: string|null, unit: string|null, source_id: string|null, confidence: string, verified: bool, quote: string|null, notes: string|null, data_ref: string|null}
     */
    public static function columns(array $row): array
    {
        $notes = trim(implode(' ', array_filter([
            $row['decision'] !== null ? "Decision: {$row['decision']}." : null,
            $row['notes'],
        ])));

        return [
            'value' => $row['value'] === null ? null : json_encode($row['value']),
            'unit' => $row['unit'],
            'source_id' => $row['source_id'],
            'confidence' => $row['confidence'],
            'verified' => (bool) $row['verified'],
            'quote' => $row['quote'],
            'notes' => $notes !== '' ? $notes : null,
            'data_ref' => $row['ref'],
        ];
    }

    /**
     * Every (breed, stage, from month, key) an admin touched, from the audit
     * table: the tuple of each change row, and for updates also the tuple
     * before the change (`old` holds the original stage / from / key when
     * they were changed).
     *
     * @return array<string, true>
     */
    public static function touchedTuples(): array
    {
        $touched = [];
        foreach (DB::table('breed_stage_param_changes')->get(['breed_slug', 'stage', 'age_from_months', 'key', 'action', 'old']) as $change) {
            $touched[self::tupleKey($change->breed_slug, $change->stage, (int) $change->age_from_months, $change->key)] = true;

            $old = is_string($change->old) ? json_decode($change->old, true) : null;
            if ($change->action === 'updated' && is_array($old)) {
                $touched[self::tupleKey(
                    (string) ($old['breed_slug'] ?? $change->breed_slug),
                    (string) ($old['stage'] ?? $change->stage),
                    (int) ($old['age_from_months'] ?? $change->age_from_months),
                    (string) ($old['key'] ?? $change->key),
                )] = true;
            }
        }

        return $touched;
    }

    private static function tupleKey(string $breed, string $stage, int $from, string $key): string
    {
        return $breed.'|'.$stage.'|'.$from.'|'.$key;
    }
}
