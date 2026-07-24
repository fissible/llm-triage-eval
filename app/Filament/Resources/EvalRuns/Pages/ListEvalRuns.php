<?php

namespace App\Filament\Resources\EvalRuns\Pages;

use App\Filament\Resources\EvalRuns\EvalRunResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListEvalRuns extends ListRecords
{
    protected static string $resource = EvalRunResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
