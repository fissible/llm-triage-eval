<?php

namespace App\Filament\Resources\EvalRuns;

use App\Filament\Resources\EvalRuns\Pages\CreateEvalRun;
use App\Filament\Resources\EvalRuns\Pages\EditEvalRun;
use App\Filament\Resources\EvalRuns\Pages\ListEvalRuns;
use App\Filament\Resources\EvalRuns\Pages\ViewEvalRun;
use App\Filament\Resources\EvalRuns\Schemas\EvalRunForm;
use App\Filament\Resources\EvalRuns\Schemas\EvalRunInfolist;
use App\Filament\Resources\EvalRuns\Tables\EvalRunsTable;
use App\Models\EvalRun;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class EvalRunResource extends Resource
{
    protected static ?string $model = EvalRun::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?int $navigationSort = 20;

    public static function form(Schema $schema): Schema
    {
        return EvalRunForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return EvalRunInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return EvalRunsTable::configure($table);
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
            'index' => ListEvalRuns::route('/'),
            'create' => CreateEvalRun::route('/create'),
            'view' => ViewEvalRun::route('/{record}'),
            'edit' => EditEvalRun::route('/{record}/edit'),
        ];
    }
}
