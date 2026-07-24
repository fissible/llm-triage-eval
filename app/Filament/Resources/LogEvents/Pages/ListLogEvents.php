<?php

namespace App\Filament\Resources\LogEvents\Pages;

use App\Filament\Resources\LogEvents\LogEventResource;
use Filament\Resources\Pages\ListRecords;
use Livewire\Attributes\Url;

class ListLogEvents extends ListRecords
{
    protected static string $resource = LogEventResource::class;

    /** When set (via the "Chain" link), the table filters to this correlation id. */
    #[Url]
    public ?string $correlation = null;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
