<?php

declare(strict_types=1);

namespace App\Filament\Resources\CreditNoteResource\Pages;

use App\Filament\Resources\CreditNoteResource;
use Filament\Resources\Pages\ListRecords;

class ListCreditNotes extends ListRecords
{
    protected static string $resource = CreditNoteResource::class;
}
