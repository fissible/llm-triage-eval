<?php

namespace App\Filament\Resources\LogEvents\Tables;

use App\Filament\Resources\GoldenCases\GoldenCaseResource;
use App\Models\GoldenCase;
use App\Models\LogEvent;
use App\Services\Triage\FailureSummarizer;
use App\Services\Triage\TaxonomyClassifier;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

class LogEventsTable
{
    public static function configure(Table $table): Table
    {
        // Request-scoped map of golden cases keyed by correlation|app|error_type,
        // so the "Golden" badge/link is one query per render (no N+1, no cross-request cache).
        $golden = GoldenCase::query()
            ->get(['id', 'case_id', 'correlation_id', 'app', 'error_type'])
            ->keyBy(fn (GoldenCase $g) => $g->correlation_id.'|'.$g->app.'|'.$g->error_type);
        $match = fn (LogEvent $r) => $golden[$r->correlation_id.'|'.$r->app.'|'.$r->error_type] ?? null;

        return $table
            // Group events into their transaction (correlation id), oldest first —
            // so a group reads top-to-bottom as the failure propagated.
            ->groups([
                Group::make('correlation_id')
                    ->label('Correlation')
                    ->collapsible(),
            ])
            ->defaultGroup('correlation_id')
            ->defaultSort('occurred_at', 'asc')
            // When arrived at via a "Chain" link, scope to that one transaction.
            ->modifyQueryUsing(function (Builder $query, $livewire): Builder {
                $correlation = $livewire->correlation ?? null;

                return filled($correlation)
                    ? $query->where('correlation_id', $correlation)
                    : $query;
            })
            ->columns([
                TextColumn::make('occurred_at')
                    ->label('Time')
                    ->dateTime('H:i:s.v')
                    ->sortable(),
                TextColumn::make('app')
                    ->badge()
                    ->color('gray')
                    ->searchable(),
                TextColumn::make('error_type')
                    ->searchable(),
                TextColumn::make('weak_label')
                    ->label('Class')
                    ->badge(),
                TextColumn::make('golden')
                    ->label('Golden')
                    ->badge()
                    ->color('warning')
                    ->state(fn (LogEvent $r) => ($m = $match($r)) ? $m->case_id : null)
                    ->placeholder('—')
                    ->tooltip('This event is in the golden set'),
                TextColumn::make('message')
                    ->limit(70)
                    ->tooltip(fn (LogEvent $r) => $r->message)
                    ->searchable(),
                TextColumn::make('correlation_id')
                    ->label('Correlation ID')
                    ->copyable()
                    ->fontFamily('mono')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('env')
                    ->options(fn () => LogEvent::query()->distinct()->pluck('env', 'env')->filter()->all()),
                SelectFilter::make('app')
                    ->options(fn () => LogEvent::query()->distinct()->orderBy('app')->pluck('app', 'app')->filter()->all()),
                Filter::make('cross_app')
                    ->label('Cross-app chains only')
                    ->query(fn ($query) => $query->whereIn(
                        'correlation_id',
                        LogEvent::query()->select('correlation_id')
                            ->groupBy('correlation_id')
                            ->havingRaw('count(distinct app) > 1')
                    )),
                Filter::make('recency')
                    ->schema([
                        Select::make('window')
                            ->label('Recency')
                            ->options(['1' => 'Last hour', '24' => 'Last 24 hours', '168' => 'Last 7 days', '720' => 'Last 30 days']),
                    ])
                    ->query(fn (Builder $query, array $data) => filled($data['window'] ?? null)
                        ? $query->where('occurred_at', '>=', now()->subHours((int) $data['window']))
                        : $query),
            ])
            ->recordActions([
                Action::make('explain')
                    ->label('Explain')
                    ->icon('heroicon-o-sparkles')
                    ->color('info')
                    ->modalHeading('Incident explanation (AI)')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->modalContent(function (LogEvent $record) {
                        $events = LogEvent::query()
                            ->where('correlation_id', $record->correlation_id)
                            ->orderBy('occurred_at')
                            ->get()
                            ->map(fn (LogEvent $e) => [
                                'occurred_at' => (string) $e->occurred_at,
                                'app' => $e->app,
                                'error_type' => $e->error_type,
                                'message' => $e->message,
                                'error_detail' => $e->error_detail ?? [],
                            ])->all();

                        try {
                            $text = app(FailureSummarizer::class)
                                ->explainChain((string) $record->correlation_id, $events)['explanation'];
                        } catch (\Throwable $e) {
                            $text = 'Could not generate an explanation ('.$e->getMessage().'). Is the local model (Ollama) running?';
                        }

                        return new HtmlString(
                            '<div style="font-size:.95rem;line-height:1.55;">'.e($text).'</div>'
                            .'<div style="margin-top:.75rem;font-size:.75rem;opacity:.6;">AI-generated from '
                            .count($events).' event(s) in this transaction — verify against the evidence below.</div>'
                        );
                    }),
                Action::make('open_golden')
                    ->label('Golden case')
                    ->icon('heroicon-o-check-badge')
                    ->url(fn (LogEvent $r) => ($m = $match($r)) ? GoldenCaseResource::getUrl('edit', ['record' => $m->id]) : null)
                    ->visible(fn (LogEvent $r) => $match($r) !== null),
                Action::make('add_to_golden')
                    ->label('Add to golden set')
                    ->icon('heroicon-o-plus-circle')
                    ->color('success')
                    ->visible(fn (LogEvent $r) => $match($r) === null)
                    ->requiresConfirmation()
                    ->modalHeading('Add this event to the golden set?')
                    ->modalDescription('Creates a weak-labeled golden case from this event, ready for review. Use this for a root-cause event that belongs in your eval set.')
                    ->action(function (LogEvent $r) {
                        $case = self::promoteToGolden($r);

                        Notification::make()
                            ->title("Added {$case->case_id} to the golden set")
                            ->success()
                            ->actions([
                                Action::make('review')
                                    ->label('Review it')
                                    ->button()
                                    ->url(GoldenCaseResource::getUrl('edit', ['record' => $case->getKey()])),
                            ])
                            ->send();
                    }),
            ])
            ->toolbarActions([]);
    }

    /**
     * Create a weak-labeled golden case from a raw log event (log_events is already
     * sanitized on import, so its fields are safe to copy into the golden set).
     */
    private static function promoteToGolden(LogEvent $event): GoldenCase
    {
        $input = [
            'message' => $event->message,
            'element' => null,
            'root_exception' => $event->root_exception,
            'stack_top' => [],
            'http_method' => preg_match('/HTTP (\w+) on resource/', (string) $event->message, $m) ? $m[1] : null,
            'http_status' => $event->http_status,
            'resource_url' => $event->resource_url,
            'target_entity' => preg_match('#/v1/api/([a-z-]+)#', (string) $event->resource_url, $m2) ? $m2[1] : null,
            'error_detail' => $event->error_detail ?? [],
        ];

        $weak = app(TaxonomyClassifier::class)
            ->classify(array_merge($input, ['error_type' => $event->error_type]))
            ->value;

        // Next sequential gs-#### id.
        $maxNumber = GoldenCase::query()->pluck('case_id')
            ->map(fn ($id) => (int) preg_replace('/\D/', '', (string) $id))
            ->max() ?? 0;
        $caseId = 'gs-'.str_pad((string) ($maxNumber + 1), 4, '0', STR_PAD_LEFT);

        return GoldenCase::create([
            'case_id' => $caseId,
            'weak_label' => $weak,
            'gold_label' => $weak,
            'reviewed' => false,
            'note' => 'Promoted from Correlations (chain event).',
            'app' => $event->app,
            'env' => $event->env,
            'error_type' => $event->error_type,
            'correlation_id' => $event->correlation_id,
            'message' => $event->message,
            'source_file' => $event->source_file,
            'occurred_at' => $event->occurred_at,
            'input' => $input,
        ]);
    }
}
