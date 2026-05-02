<?php

declare(strict_types=1);

namespace App\Filament\Resources\DistributorCommissionPaymentResource\Pages;

use App\Filament\Resources\DistributorCommissionPaymentResource;
use Filament\Resources\Pages\ListRecords;

class ListDistributorCommissionPayments extends ListRecords
{
    protected static string $resource = DistributorCommissionPaymentResource::class;
}
