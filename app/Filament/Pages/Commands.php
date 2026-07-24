<?php

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Artisan;

class Commands extends Page
{
    protected string $view = 'filament.pages.commands';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCommandLine;

    protected static ?int $navigationSort = 25;

    protected Width|string|null $maxContentWidth = Width::FiveExtraLarge;

    /**
     * All triage:* commands for the reference list.
     *
     * @return list<array{name:string, description:string, synopsis:string}>
     */
    public function getTriageCommands(): array
    {
        return collect(Artisan::all())
            ->filter(fn ($command, string $name) => str_starts_with($name, 'triage:'))
            ->map(fn ($command, string $name) => [
                'name' => $name,
                'description' => $command->getDescription(),
                'synopsis' => $command->getSynopsis(),
            ])
            ->sortBy('name')
            ->values()
            ->all();
    }
}
