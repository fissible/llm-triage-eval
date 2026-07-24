<?php

namespace App\Filament\Resources\LogEvents;

use App\Filament\Resources\LogEvents\Pages\ListLogEvents;
use App\Filament\Resources\LogEvents\Tables\LogEventsTable;
use App\Models\LogEvent;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class LogEventResource extends Resource
{
    protected static ?string $model = LogEvent::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $navigationLabel = 'Correlations';

    protected static ?string $modelLabel = 'event';

    protected static ?int $navigationSort = 10;

    public static function table(Table $table): Table
    {
        return LogEventsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLogEvents::route('/'),
        ];
    }
}
