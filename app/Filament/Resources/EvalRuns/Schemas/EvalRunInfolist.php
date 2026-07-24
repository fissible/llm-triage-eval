<?php

namespace App\Filament\Resources\EvalRuns\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class EvalRunInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('report_path'),
                TextEntry::make('prompt_version'),
                TextEntry::make('provider'),
                TextEntry::make('model'),
                TextEntry::make('golden_set')
                    ->placeholder('-'),
                TextEntry::make('n')
                    ->numeric(),
                TextEntry::make('llm_accuracy')
                    ->numeric(),
                TextEntry::make('baseline_accuracy')
                    ->numeric(),
                TextEntry::make('prompt_tokens')
                    ->numeric(),
                TextEntry::make('completion_tokens')
                    ->numeric(),
                TextEntry::make('cost_usd')
                    ->numeric(),
                IconEntry::make('fully_reviewed')
                    ->boolean(),
                TextEntry::make('per_category')
                    ->columnSpanFull(),
                TextEntry::make('confusion')
                    ->columnSpanFull(),
                TextEntry::make('ran_at')
                    ->dateTime(),
                TextEntry::make('created_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('updated_at')
                    ->dateTime()
                    ->placeholder('-'),
            ]);
    }
}
