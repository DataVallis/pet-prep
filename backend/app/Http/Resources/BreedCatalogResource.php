<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One breed of the picker catalogue (GET /api/breeds, M5-R06-01). Breed
 * names are not sent as text: the app translates `label_key` (i18n).
 *
 * @property array{breed: string, slug: string, species: string, premium: bool, free_plan_allowed: bool, challenge_allowed: bool, label_key: string, search_keywords: list<string>, sort_order: int} $resource
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
             * @var 'mutt'|'border_collie'|'domestic_cat'|'maine_coon'
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
        ];
    }
}
