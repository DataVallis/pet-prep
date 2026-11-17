<?php

namespace App\Console\Commands;

use App\Services\Shop\ShopProductSync;
use Illuminate\Console\Command;

/** M6-S09: copy the Stripe products / prices of config('shop.products') into the admin. */
class ShopSyncProducts extends Command
{
    protected $signature = 'shop:sync-products';

    protected $description = 'Refresh the admin copy of the shop products from Stripe';

    public function handle(ShopProductSync $sync): int
    {
        foreach ($sync->run() as $key => $status) {
            $this->line($key.': '.$status);
        }

        return self::SUCCESS;
    }
}
