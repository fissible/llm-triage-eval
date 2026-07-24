<?php

namespace App\Filament\Resources\EvalRuns\Pages;

use App\Filament\Resources\EvalRuns\EvalRunResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditEvalRun extends EditRecord
{
    protected static string $resource = EvalRunResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
