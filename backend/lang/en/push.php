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

    // M3-12 (David 2026-10-07): a reminder never asks for an action the app refuses
    // right now — instead of "feed now" it says when it will be possible, or "clean first".
    'wait' => [
        'hunger' => 'Your dog is getting hungry. The next meal is at :time — don’t forget it.',
        'thirst' => 'Your dog is thirsty. You can give it water again at :time — don’t forget it.',
    ],

    'clean_first' => [
        'hunger' => 'Your dog is hungry, but the mess has to be cleaned up first. Then you can feed it.',
        'thirst' => 'Your dog is thirsty, but the mess has to be cleaned up first. Then you can give it water.',
    ],

    // M3-12: a chewed item is not scrubbed away — it is tidied up and swapped for a toy.
    'tidy' => [
        'soft' => 'Your dog has chewed something. Tidy it up and give it a toy.',
        'critical' => 'Your dog chewed a slipper! Tidy it up and give it a toy as soon as you can, or it will get sick.',
    ],

    'clean_and_tidy' => [
        'soft' => 'Your dog is waiting: clean up the mess, tidy away what it chewed and give it a toy.',
        'critical' => 'Clean up the mess, tidy away what it chewed and give your dog a toy as soon as you can, or it will get sick.',
    ],

    'walk_reminder' => 'Your dog hasn’t been for a walk today and is waiting for you with the lead. Shall we go out?',

    // M5-R06-04 placeholder (draft): the cat's daily play reminder — final cat texts in M5-R06-06.
    'play_reminder' => 'Your cat hasn’t played today and is waiting for the feather wand. Shall we play?',

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

    // M3-11 (PAYMENTS_SPEC): trial day 6 (parents only) and the payment lock.
    'trial_ending' => 'The free trial ends tomorrow. Unlock the 12-week challenge in the app so the game can go on.',

    'payment_required' => [
        'child' => 'The game is waiting for your parent. Your dog is safe and resting.',
        'parent' => 'The free trial has ended. The dog is waiting safely until you unlock the 12-week challenge in the app.',
        'parent_no_trial' => 'The dog is waiting safely until you unlock the 12-week challenge in the app.',
    ],
];
