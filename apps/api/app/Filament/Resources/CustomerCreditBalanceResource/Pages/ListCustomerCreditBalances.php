<?php

declare(strict_types=1);

namespace App\Filament\Resources\CustomerCreditBalanceResource\Pages;

use App\Filament\Resources\CustomerCreditBalanceResource;
use Filament\Resources\Pages\ListRecords;

class ListCustomerCreditBalances extends ListRecords
{
    protected static string $resource = CustomerCreditBalanceResource::class;
}
