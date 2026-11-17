<?php

namespace App\Filament\Resources\ShopOrderResource\Pages;

use App\Filament\Resources\ShopOrderResource;
use App\Filament\Widgets\ShopSalesOverview;
use Filament\Resources\Pages\ListRecords;

class ListShopOrders extends ListRecords
{
    protected static string $resource = ShopOrderResource::class;

    protected function getHeaderWidgets(): array
    {
        return [ShopSalesOverview::class];
    }
}
