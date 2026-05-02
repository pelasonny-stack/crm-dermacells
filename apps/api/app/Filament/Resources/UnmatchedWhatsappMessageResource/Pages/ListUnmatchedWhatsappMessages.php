<?php

declare(strict_types=1);

namespace App\Filament\Resources\UnmatchedWhatsappMessageResource\Pages;

use App\Filament\Resources\UnmatchedWhatsappMessageResource;
use Filament\Resources\Pages\ListRecords;

class ListUnmatchedWhatsappMessages extends ListRecords
{
    protected static string $resource = UnmatchedWhatsappMessageResource::class;

    protected function getHeaderActions(): array
    {
        return [];  // No create action — rows are created by IngestWhatsappWebhookJob.
    }
}
