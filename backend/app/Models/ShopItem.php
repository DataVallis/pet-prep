<?php

namespace App\Models;

use App\Enums\Species;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * The wishes catalogue (M6-W01, SHOP_SPEC): one row per key, seeded by the
 * migration (never edited in the app or admin). `covers` = the item keys a
 * purchase of this row gives the pet (the welcome bundle = bed + bowls + toy_1).
 *
 * @property string $key
 * @property string $kind durable | consumable | bundle
 * @property int|null $pack_size
 * @property string|null $dog_variant
 * @property string|null $cat_variant
 * @property string $product_id default store product (config `services.revenuecat.wish_products` decides)
 * @property list<string> $covers
 * @property int $sort
 */
class ShopItem extends Model
{
    public const KIND_DURABLE = 'durable';

    public const KIND_CONSUMABLE = 'consumable';

    public const KIND_BUNDLE = 'bundle';

    public const TREATS = 'treats';

    public const BUNDLE = 'welcome_bundle';

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['covers' => 'array', 'pack_size' => 'integer', 'sort' => 'integer'];
    }

    /** @return Collection<string, ShopItem> keyed by `key`, catalogue order */
    public static function catalogue(): Collection
    {
        return static::query()->orderBy('sort')->get()->keyBy('key');
    }

    public function variantFor(Species $species): ?string
    {
        return $species === Species::Cat ? $this->cat_variant : $this->dog_variant;
    }

    public function isDurable(): bool
    {
        return $this->kind === self::KIND_DURABLE;
    }

    public function isBundle(): bool
    {
        return $this->kind === self::KIND_BUNDLE;
    }
}
