<?php

/*
 * Superadmin panel (/admin) strings — English (default). Slovenian lives in
 * lang/sl/admin.php with the same keys (David 2026-10-11: one language per
 * screen, EN + SL switchable from the top bar / user menu).
 */
return [
    'locale' => [
        'switch_to' => 'Slovenščina',
        'current' => 'English',
        'en' => 'English',
        'sl' => 'Slovenščina',
    ],

    'navigation' => [
        'configuration' => 'Configuration',
        'payments' => 'Payments',
        'management' => 'Management',
        'audit' => 'Audit',
        'ai_media' => 'AI media',
        'users_label' => 'Users',
        'user_model' => 'user',
        'pets_label' => 'Pets',
        'pet_model' => 'pet',
        'breed_configs' => 'Breed configs',
        'breed_config_model' => 'breed config',
        'stage_data' => 'Life-stage data',
        'stage_value_model' => 'life-stage value',
        'credits' => 'Challenge credits',
        'credit_model' => 'challenge credit',
        'purchase_events' => 'Purchase events',
        'purchase_event_model' => 'purchase event',
        'activity_log' => 'Activity log',
        'activity_model' => 'activity',
        'ai_lab' => 'AI Lab',
    ],

    'dashboard' => [
        'title' => 'Dashboard',
        'period' => 'Period',
        'periods' => [
            '7' => 'Last 7 days',
            '30' => 'Last 30 days',
            '90' => 'Last 90 days',
            '365' => 'Last 12 months',
        ],
        'species_filter' => 'Species',
        'all' => 'All',
        'dog' => 'Dogs',
        'cat' => 'Cats',

        'stats' => [
            'families' => 'Families',
            'families_desc' => '+:count new in the period',
            'parents' => 'Parents',
            'parents_desc' => '+:count new in the period',
            'children' => 'Children',
            'children_desc' => '+:count new in the period',
            'pets' => 'Active pets',
            'pets_desc' => ':dogs dogs · :cats cats · :new new in the period',
            'challenges' => 'Paid challenges',
            'challenges_desc' => ':period in the period · :sandbox sandbox / :production live',
            'care' => 'Care actions',
            'care_desc' => 'Logged by children and parents in the period',
        ],

        'attention' => [
            'heading' => 'Needs attention',
            'sick' => 'Sick pets',
            'sick_desc' => 'Pet state is sick right now',
            'locked' => 'Waiting for payment',
            'locked_desc' => 'Challenge paused until a parent buys',
            'game_over' => 'Game over',
            'game_over_desc' => ':hard hard stop(s) · :total in total',
            'cats' => 'Cats switch',
            'cats_desc' => 'Who can create a new cat — click to change',
        ],

        'growth' => [
            'heading' => 'Growth',
            'description' => 'New parents, children and pets per day',
            'parents' => 'New parents',
            'children' => 'New children',
            'pets' => 'New pets',
        ],

        'care_chart' => [
            'heading' => 'Care activity',
            'description' => 'Logged actions per day, grouped',
            'feeding' => 'Food & water',
            'outside' => 'Walks & potty',
            'hygiene' => 'Cleaning & grooming',
            'fun' => 'Play, cuddles & training',
            'incidents' => 'Incidents',
        ],

        'breeds' => [
            'heading' => 'Pets by breed',
            'description' => 'Active pets, all time',
            'pets' => 'Pets',
        ],

        'plans' => [
            'heading' => 'Free vs paid',
            'description' => 'Active pets by plan',
            'free' => 'Free',
            'challenge_paid' => 'Challenge (paid)',
            'challenge_unpaid' => 'Challenge (unpaid)',
        ],

        'latest_pets' => [
            'heading' => 'Latest pets',
            'id' => 'ID',
            'name' => 'Name',
            'breed' => 'Breed',
            'species' => 'Species',
            'plan' => 'Plan',
            'state' => 'State',
            'family' => 'Family',
            'created' => 'Created',
            'unnamed' => '—',
            'species_dog' => 'Dog',
            'species_cat' => 'Cat',
            'states' => [
                'idle' => 'Idle',
                'sleeping' => 'Sleeping',
                'low_energy' => 'Low energy',
                'hungry' => 'Hungry',
                'sick' => 'Sick',
                'playing' => 'Playing',
            ],
        ],

        'latest_purchases' => [
            'heading' => 'Latest purchases',
            'purchased' => 'Purchased',
            'store' => 'Store',
            'environment' => 'Environment',
            'family' => 'Family',
            'pet' => 'Assigned pet',
            'status' => 'Status',
            'active' => 'Active',
            'revoked' => 'Revoked',
            'view_all' => 'View all',
            'unassigned' => 'Not assigned yet',
        ],

        'ai' => [
            'pets_today' => 'Pets AI spend today (est.)',
            'pets_month' => 'This month $:month / $:cap · images $:images · videos $:videos (:tz)',
            'lab_today' => 'AI Lab spend today (est.)',
            'lab_month' => 'This month $:month / $:cap — separate from the pets budget',
            'balance' => 'fal.ai balance',
            'exhausted' => 'EXHAUSTED',
            'ok' => 'OK',
            'exhausted_desc' => 'Since :at — top up at fal.ai/dashboard/billing',
            'errors_desc' => ':count pet(s) without media (budget / balance / errors)',
        ],
    ],

    'features' => [
        'navigation' => 'Feature switches',
        'title' => 'Feature switches',
        'cats_section' => 'Cats',
        'cats_description' => 'Who can create a NEW cat. Existing cats keep working in every mode (switching off only stops new cats).',
        'cats_mode' => 'Cats',
        'modes' => [
            'off' => 'Off',
            'test_families' => 'Test families only',
            'everyone' => 'Everyone',
        ],
        'env_override' => '<strong>The server has PETPREP_CATS_ENABLED=true</strong> — cats are on for everyone regardless of this choice. To use test families, remove the line from /opt/petprep/.env and restart the PHP containers.',
        'test_parents' => 'Test parents (a family is a test family if any of these parents is in it)',
        'test_parents_help' => 'Search a parent account by e-mail or name.',
        'help' => 'What happens after saving: nothing else needs to be switched anywhere. Cats are shown only by app version 3.1.0 or newer (it sends <code>species_cat</code>) — the child\'s phone needs it too, otherwise the child is asked to update. After a change the user should fully close and reopen the app (the breed catalogue is cached in the app for 1 hour); the server applies the new setting within 1 minute.',
        'save' => 'Save',
        'saved' => 'Saved',
        'saved_body' => 'Cats: :mode',
        'not_saved' => 'Not saved',
        'history' => 'Change history',
        'history_desc' => 'Last 20 changes (who, when, before → after).',
        'history_empty' => 'No changes yet (default: off).',
        'system' => 'system',
    ],
];
