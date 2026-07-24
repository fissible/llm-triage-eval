<?php

namespace App\Filament\Resources\GoldenCases\Pages;

use App\Filament\Resources\GoldenCases\GoldenCaseResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListGoldenCases extends ListRecords
{
    protected static string $resource = GoldenCaseResource::class;

    private bool $queueCaptured = false;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    /**
     * Snapshot the ordered, filtered ID list into the session so the edit screen
     * can offer Previous/Next through exactly the set (and order) last viewed here.
     */
    public function getFilteredSortedTableQuery(): ?Builder
    {
        $query = parent::getFilteredSortedTableQuery();

        if ($query !== null && ! $this->queueCaptured) {
            $this->queueCaptured = true;
            session()->put(
                'golden.review_queue',
                (clone $query)->pluck($query->getModel()->getKeyName())->all()
            );
        }

        return $query;
    }
}
