<?php

declare(strict_types=1);

namespace App\Filament\Resources\WhatsappThreadResource\Pages;

use App\Filament\Resources\WhatsappThreadResource;
use Filament\Resources\Pages\ListRecords;

class ListWhatsappThreads extends ListRecords
{
    protected static string $resource = WhatsappThreadResource::class;

    protected function getHeaderActions(): array
    {
        return [];  // No create action — threads are created by IngestWhatsappWebhookJob.
    }
}
