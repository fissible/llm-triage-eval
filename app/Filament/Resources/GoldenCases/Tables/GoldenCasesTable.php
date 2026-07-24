<?php

namespace App\Filament\Resources\GoldenCases\Tables;

use App\Enums\FailureCategory;
use App\Filament\Resources\LogEvents\LogEventResource;
use App\Models\GoldenCase;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

class GoldenCasesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('case_id')
            ->columns([
                TextColumn::make('case_id')
                    ->label('ID')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('env')
                    ->badge()
                    ->color(fn (string $state) => $state === 'prod' ? 'success' : 'gray')
                    ->toggleable(),
                TextColumn::make('app')
                    ->toggleable()
                    ->searchable(),
                TextColumn::make('message')
                    ->limit(60)
                    ->tooltip(fn (GoldenCase $r) => $r->message)
                    ->searchable(),
                TextColumn::make('gold_label')
                    ->label('Label')
                    ->badge()
                    ->searchable(),
                TextColumn::make('weak_label')
                    ->label('Rule')
                    ->badge()
                    ->color('gray')
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('corrected')
                    ->label('Corrected')
                    ->boolean()
                    ->tooltip('Human label differs from the rule-based label'),
                IconColumn::make('reviewed')
                    ->boolean()
                    ->sortable(),
                TextColumn::make('correlation_id')
                    ->label('Corr. ID')
                    ->formatStateUsing(fn (?string $state) => $state ? substr($state, 0, 8).'…' : null)
                    ->copyable()
                    ->copyableState(fn (GoldenCase $r) => $r->correlation_id) // copy the FULL id
                    ->copyMessage('Correlation ID copied — paste into Anypoint')
                    ->tooltip(fn (GoldenCase $r) => $r->correlation_id)
                    ->fontFamily('mono')
                    ->toggleable(),
                TextColumn::make('occurred_at')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('env')
                    ->options(fn () => GoldenCase::query()->distinct()->pluck('env', 'env')->filter()->all()),
                SelectFilter::make('app')
                    ->options(fn () => GoldenCase::query()->distinct()->orderBy('app')->pluck('app', 'app')->filter()->all()),
                SelectFilter::make('gold_label')
                    ->label('Label')
                    ->options(fn () => collect(FailureCategory::cases())->mapWithKeys(fn ($c) => [$c->value => $c->value])->all()),
                TernaryFilter::make('reviewed'),
                Filter::make('corrected')
                    ->label('Corrected (label ≠ rule)')
                    ->query(fn ($query) => $query->whereColumn('gold_label', '!=', 'weak_label')),
            ])
            ->recordActions([
                EditAction::make()->label('Review'),
                Action::make('chain')
                    ->label('Chain')
                    ->icon('heroicon-o-link')
                    ->url(fn (GoldenCase $r) => LogEventResource::getUrl('index', ['correlation' => $r->correlation_id]))
                    ->visible(fn (GoldenCase $r) => filled($r->correlation_id)),
                Action::make('anypoint')
                    ->label('Anypoint')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (GoldenCase $r) => str_replace('{cid}', (string) $r->correlation_id, (string) config('triage.anypoint_search_url')))
                    ->openUrlInNewTab()
                    ->visible(fn (GoldenCase $r) => filled(config('triage.anypoint_search_url')) && filled($r->correlation_id)),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    // env is provenance, not a per-case judgment — correct it in bulk
                    // (e.g. filter to env = unknown, select all, set the real environment).
                    BulkAction::make('setEnv')
                        ->label('Set environment')
                        ->icon('heroicon-o-tag')
                        ->schema([
                            Select::make('env')
                                ->label('Environment')
                                ->options(['prod' => 'prod', 'uat' => 'uat', 'sit' => 'sit', 'dev' => 'dev', 'unknown' => 'unknown'])
                                ->required(),
                        ])
                        ->action(fn (Collection $records, array $data) => $records->each->update(['env' => $data['env']]))
                        ->deselectRecordsAfterCompletion()
                        ->successNotificationTitle('Environment updated'),
                ]),
            ]);
    }
}
