<?php

/*
 * Push texts — English (M1-18; reference language, same keys as lang/sl).
 * No child or pet names (Expo / APNs / FCM are third parties, lock screens
 * are public). Rendered per device in `device_push_tokens.locale`
 * (App\Services\Push\PushCopy).
 */

return [
    'title' => 'PetPrep',

    'soft' => [
        'hunger' => 'Your dog is giving you a gentle look and pointing at the food bowl.',
        'thirst' => 'Your dog is giving you a gentle look and pointing at the empty water bowl.',
        'hygiene' => 'Your dog is giving you a gentle look and pointing at a mess that needs cleaning up.',
    ],

    'critical' => [
        'hunger' => 'If you don’t feed your dog within 30 minutes, it will get sick.',
        'thirst' => 'If you don’t give your dog water within 30 minutes, it will get sick.',
        'hygiene' => 'Your dog made a mess! Clean it up as soon as you can, or it will get sick.',
    ],

    'walk_reminder' => 'Your dog hasn’t been for a walk today and is waiting for you with the lead. Shall we go out?',

    'parent_alarm' => 'Your child hasn’t looked after the dog today.',

    'parent_alarm_detail' => [
        'hunger' => 'The dog has had no food for over an hour.',
        'thirst' => 'The dog has had no water for over an hour.',
        'hygiene' => 'A mess has not been cleaned up for over an hour.',
    ],

    'illness' => [
        'child' => [
            'hygiene' => 'Your dog lived in a mess for too long and got sick. It will stay at the vet for 12 hours of observation.',
            'walk' => 'Your dog didn’t go for a walk yesterday and got sick. It will stay at the vet for 12 hours of observation.',
            'other' => 'Your dog got sick. It will stay at the vet for 12 hours of observation.',
        ],
        'parent' => [
            'hygiene' => 'The dog got sick because a mess wasn’t cleaned up. It will stay at the vet for 12 hours of observation.',
            'walk' => 'The dog got sick because it didn’t go for a walk yesterday. It will stay at the vet for 12 hours of observation.',
            'other' => 'The dog got sick. It will stay at the vet for 12 hours of observation.',
        ],
    ],

    'game_over' => [
        'child' => 'Your dog has gone to a shelter because nobody looked after it for too long. Talk to your parents.',
        'parent' => 'The dog has gone to a shelter because it went 24 hours without essential care. Choose how to continue in the app.',
    ],
];
