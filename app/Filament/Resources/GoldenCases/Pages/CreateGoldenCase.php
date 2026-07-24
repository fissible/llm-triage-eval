<?php

namespace App\Filament\Resources\GoldenCases\Pages;

use App\Filament\Resources\GoldenCases\GoldenCaseResource;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Enums\Width;

class CreateGoldenCase extends CreateRecord
{
    protected static string $resource = GoldenCaseResource::class;

    protected Width|string|null $maxContentWidth = Width::FourExtraLarge;
}
