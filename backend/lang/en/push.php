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

    /*
     * M5-R06-06 (plan T8, CAT_SPEC §6 / §9): cat texts. PushCopy picks `cat.<key>` for a cat
     * and falls back to the dog key only for dog-only keys (walk, chewing — a cat never gets
     * them). Keys that exist only here (play / litter reminders, scratcher variants) are the
     * cat's own. Claude's draft, awaiting David's read-through before the cat switch-on
     * (R06-09) — docs/product/CAT_TEXTS_REVIEW.md. One noun for every life stage, like the dog.
     */
    'cat' => [
        'soft' => [
            'hunger' => 'Your cat is giving you a gentle look and sitting by the food bowl.',
            'thirst' => 'Your cat is giving you a gentle look and sitting by the empty water bowl.',
            'hygiene' => 'Your cat is giving you a gentle look — there’s a mess next to the litter tray that needs cleaning up.',
        ],

        'critical' => [
            'hunger' => 'If you don’t feed your cat within 30 minutes, it will get sick.',
            'thirst' => 'If you don’t give your cat water within 30 minutes, it will get sick.',
            'hygiene' => 'Your cat made a mess next to the litter tray! Clean it up as soon as you can, or it will get sick.',
        ],

        // M3-12: when the next meal / water is later today.
        'wait' => [
            'hunger' => 'Your cat is getting hungry. The next meal is at :time — don’t forget it.',
            'thirst' => 'Your cat is thirsty. You can give it water again at :time — don’t forget it.',
        ],

        // M3-12: food / water are refused while a mess next to the tray is open.
        'clean_first' => [
            'hunger' => 'Your cat is hungry, but the mess has to be cleaned up first. Then you can feed it.',
            'thirst' => 'Your cat is thirsty, but the mess has to be cleaned up first. Then you can give it water.',
        ],

        // M3-12 (QA m3, R06-05): only a scratching is open — cleaning does not resolve it, the scratcher does.
        'scratcher_first' => [
            'hunger' => 'Your cat is hungry, but first carry it to the scratching post and praise it. Then you can feed it.',
            'thirst' => 'Your cat is thirsty, but first carry it to the scratching post and praise it. Then you can give it water.',
        ],

        // M3-12: a scratching plus a mess next to the tray.
        'clean_and_scratcher_first' => [
            'hunger' => 'Your cat is hungry, but first clean up the mess and carry it to the scratching post. Then you can feed it.',
            'thirst' => 'Your cat is thirsty, but first clean up the mess and carry it to the scratching post. Then you can give it water.',
        ],

        // M5-R06-05: the cat scratched the sofa — resolved by carrying it to the scratcher and praising it, not by cleaning.
        'scratcher' => [
            'soft' => 'Your cat has scratched the sofa. Carry it to the scratching post and praise it.',
            'critical' => 'Your cat scratched the sofa! Carry it to the scratching post and praise it as soon as you can, or it will get sick.',
        ],

        'clean_and_scratcher' => [
            'soft' => 'Your cat is waiting: clean up the mess, then carry it to the scratching post and praise it.',
            'critical' => 'Clean up the mess and carry your cat to the scratching post as soon as you can, or it will get sick.',
        ],

        // M5-R06-04: the daily play reminder (instead of the walk).
        'play_reminder' => 'Your cat hasn’t played today and is waiting for the feather wand. Shall we play?',

        // M5-R06-05: an open litter use is due within the hour.
        'litter_reminder' => 'Your cat has used the litter tray. Scoop it soon, before it starts to smell.',

        'parent_alarm' => 'Your child hasn’t looked after the cat today.',

        'parent_alarm_detail' => [
            'hunger' => 'The cat has had no food for over an hour.',
            'thirst' => 'The cat has had no water for over an hour.',
            'hygiene' => 'A mess has not been taken care of for over an hour.',
        ],

        // A cat never gets sick from a missed walk (CAT_SPEC Q2) — no `walk` reason.
        'illness' => [
            'child' => [
                'hygiene' => 'Your cat lived in a mess for too long and got sick. It will stay at the vet for 12 hours of observation.',
                'other' => 'Your cat got sick. It will stay at the vet for 12 hours of observation.',
            ],
            'parent' => [
                'hygiene' => 'The cat got sick because a mess wasn’t taken care of. It will stay at the vet for 12 hours of observation.',
                'other' => 'The cat got sick. It will stay at the vet for 12 hours of observation.',
            ],
        ],

        'game_over' => [
            'child' => 'Your cat has gone to a shelter because nobody looked after it for too long. Talk to your parents.',
            'parent' => 'The cat has gone to a shelter because it went 24 hours without essential care. Choose how to continue in the app.',
        ],

        'payment_required' => [
            'child' => 'The game is waiting for your parent. Your cat is safe and resting.',
            'parent' => 'The free trial has ended. The cat is waiting safely until you unlock the 12-week challenge in the app.',
            'parent_no_trial' => 'The cat is waiting safely until you unlock the 12-week challenge in the app.',
        ],
    ],
];
