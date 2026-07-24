<?php

namespace App\Filament\Resources\EvalRuns\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class EvalRunForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('report_path')
                    ->required(),
                TextInput::make('prompt_version')
                    ->required(),
                TextInput::make('provider')
                    ->required(),
                TextInput::make('model')
                    ->required(),
                TextInput::make('golden_set'),
                TextInput::make('n')
                    ->required()
                    ->numeric(),
                TextInput::make('llm_accuracy')
                    ->required()
                    ->numeric(),
                TextInput::make('baseline_accuracy')
                    ->required()
                    ->numeric(),
                TextInput::make('prompt_tokens')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('completion_tokens')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('cost_usd')
                    ->required()
                    ->numeric()
                    ->default(0),
                Toggle::make('fully_reviewed')
                    ->required(),
                Textarea::make('per_category')
                    ->required()
                    ->columnSpanFull(),
                Textarea::make('confusion')
                    ->required()
                    ->columnSpanFull(),
                DateTimePicker::make('ran_at')
                    ->required(),
            ]);
    }
}
