<?php

namespace App\Filament\Resources\EvalRuns\Pages;

use App\Filament\Resources\EvalRuns\EvalRunResource;
use Filament\Resources\Pages\ViewRecord;

class ViewEvalRun extends ViewRecord
{
    protected static string $resource = EvalRunResource::class;

    protected string $view = 'filament.eval-run';

    protected function getHeaderActions(): array
    {
        return [];
    }
}
