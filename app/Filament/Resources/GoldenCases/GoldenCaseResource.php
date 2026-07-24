<?php

namespace App\Filament\Resources\GoldenCases;

use App\Filament\Resources\GoldenCases\Pages\CreateGoldenCase;
use App\Filament\Resources\GoldenCases\Pages\EditGoldenCase;
use App\Filament\Resources\GoldenCases\Pages\ListGoldenCases;
use App\Filament\Resources\GoldenCases\Schemas\GoldenCaseForm;
use App\Filament\Resources\GoldenCases\Tables\GoldenCasesTable;
use App\Models\GoldenCase;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class GoldenCaseResource extends Resource
{
    protected static ?string $model = GoldenCase::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCheckBadge;

    // Used for the edit-page title/breadcrumb, e.g. "Edit gs-0003".
    protected static ?string $recordTitleAttribute = 'case_id';

    // Labeling is a setup activity — sits below the day-to-day tracing/eval views.
    protected static ?int $navigationSort = 30;

    public static function form(Schema $schema): Schema
    {
        return GoldenCaseForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return GoldenCasesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListGoldenCases::route('/'),
            'create' => CreateGoldenCase::route('/create'),
            'edit' => EditGoldenCase::route('/{record}/edit'),
        ];
    }
}
