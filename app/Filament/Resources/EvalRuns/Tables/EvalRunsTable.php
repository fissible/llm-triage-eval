<?php

namespace App\Filament\Resources\EvalRuns\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class EvalRunsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('ran_at', 'desc')
            ->columns([
                TextColumn::make('ran_at')
                    ->label('Run')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
                TextColumn::make('prompt_version')
                    ->label('Prompt')
                    ->badge(),
                TextColumn::make('model')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('llm_accuracy')
                    ->label('LLM acc')
                    ->formatStateUsing(fn ($state) => number_format($state * 100, 1).'%')
                    ->badge()
                    ->color(fn ($state) => $state >= 0.9 ? 'success' : ($state >= 0.75 ? 'warning' : 'danger'))
                    ->sortable(),
                TextColumn::make('baseline_accuracy')
                    ->label('Baseline')
                    ->formatStateUsing(fn ($state) => number_format($state * 100, 1).'%')
                    ->color('gray'),
                TextColumn::make('n')
                    ->label('Cases'),
                IconColumn::make('fully_reviewed')
                    ->label('Ground truth')
                    ->boolean()
                    ->tooltip('True = evaluated against reviewed labels, not weak labels'),
                TextColumn::make('cost_usd')
                    ->label('Cost')
                    ->formatStateUsing(fn ($state) => '$'.number_format((float) $state, 4)),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
