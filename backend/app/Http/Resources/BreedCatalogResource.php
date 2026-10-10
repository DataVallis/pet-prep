<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One breed of the picker catalogue (GET /api/breeds, M5-R06-01). Breed
 * names are not sent as text: the app translates `label_key` (i18n).
 *
 * @property array{breed: string, slug: string, species: string, premium: bool, free_plan_allowed: bool, challenge_allowed: bool, label_key: string, search_keywords: list<string>, sort_order: int, suitability: array{suits: list<string>, consider: list<string>}} $resource
 */
class BreedCatalogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $entry = $this->resource;

        return [
            /**
             * Enum value sent as `breed` to POST /api/parent/generate-pin.
             *
             * @var 'mutt'|'border_collie'|'labrador_retriever'|'golden_retriever'|'french_bulldog'|'german_shepherd'|'cavalier_king_charles_spaniel'|'beagle'|'standard_poodle'|'dachshund'|'australian_shepherd'|'havanese'|'west_highland_white_terrier'|'bernese_mountain_dog'|'domestic_cat'|'maine_coon'
             */
            'breed' => $entry['breed'],
            /**
             * breed_configs slug (admin / analytics key).
             *
             * @var string
             */
            'slug' => $entry['slug'],
            /** @var 'dog'|'cat' */
            'species' => $entry['species'],
            /**
             * Paid breed (12-week challenge); breed_configs.premium_unlock.
             *
             * @var bool
             */
            'premium' => $entry['premium'],
            /**
             * `plan: free` accepts this breed (a free breed).
             *
             * @var bool
             */
            'free_plan_allowed' => $entry['free_plan_allowed'],
            /**
             * `plan: challenge` accepts this breed (a paid breed, M5-F03).
             *
             * @var bool
             */
            'challenge_allowed' => $entry['challenge_allowed'],
            /**
             * i18n key of the breed name, e.g. `breeds.maine_coon`.
             *
             * @var string
             */
            'label_key' => $entry['label_key'],
            /**
             * Search synonyms (lower case; the app also folds diacritics).
             *
             * @var list<string>
             */
            'search_keywords' => $entry['search_keywords'],
            /**
             * Picker order inside free / paid of a species.
             *
             * @var int
             */
            'sort_order' => $entry['sort_order'],
            /**
             * "Za koga je primerna" (M5-R10): sourced tag keys from
             * config/breed_suitability.php — `suits` = the breed fits this
             * family / home, `consider` = what a family must be ready for.
             * The app translates each key (`breedSuitability.<tag>`). Empty
             * lists = no sourced tags yet (mutt, cats). Never "hypoallergenic".
             *
             * @var array{suits: list<'active_family'|'family_pet'|'children'|'small_children'|'first_time_owner'|'apartment'|'large_home'|'other_pets'|'older_owners'|'often_alone'|'low_shedding'>, consider: list<'long_daily_exercise'|'needs_mental_stimulation'|'may_herd_children'|'chews_when_bored'|'sheds'|'food_motivated_weight'|'frequent_grooming'|'brachycephalic_breathing'|'hips_hind_legs'|'heart_and_spine'|'back_spine'|'sensitive_skin'|'shorter_lifespan'>}
             */
            'suitability' => $entry['suitability'],
        ];
    }
}
