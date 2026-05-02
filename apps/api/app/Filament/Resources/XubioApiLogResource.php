<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\UserRole;
use App\Filament\Resources\XubioApiLogResource\Pages;
use App\Models\XubioApiLog;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read-only audit view of every Xubio HTTP call.
 * Director-only.
 */
class XubioApiLogResource extends Resource
{
    protected static ?string $model = XubioApiLog::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationLabel = 'Xubio API log';

    protected static ?string $navigationGroup = 'Facturacion';

    protected static ?int $navigationSort = 99;

    public static function canAccess(): bool
    {
        return auth()->user()?->role === UserRole::Director;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label('Cuando')->dateTime('d/m/Y H:i:s')->sortable(),
                Tables\Columns\TextColumn::make('method')->label('Método')->badge(),
                Tables\Columns\TextColumn::make('endpoint')->label('Endpoint')->limit(40)->tooltip(fn ($record) => $record->endpoint),
                Tables\Columns\TextColumn::make('status_code')->label('Status')->badge()
                    ->color(fn ($state) => match (true) {
                        $state >= 500 => 'danger',
                        $state >= 400 => 'warning',
                        $state >= 200 && $state < 300 => 'success',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('latency_ms')->label('Latencia (ms)')->numeric(),
                Tables\Columns\TextColumn::make('request_id')->label('Request ID')->limit(8)->copyable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('method')->options([
                    'GET' => 'GET', 'POST' => 'POST', 'PATCH' => 'PATCH', 'PUT' => 'PUT', 'DELETE' => 'DELETE',
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListXubioApiLogs::route('/'),
        ];
    }
}
