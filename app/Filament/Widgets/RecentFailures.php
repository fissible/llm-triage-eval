<?php

namespace App\Filament\Widgets;

use App\Models\LogEvent;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class RecentFailures extends BaseWidget
{
    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    protected function getTableHeading(): string
    {
        return 'Recent failures (newest first)';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(LogEvent::query()->whereNotNull('occurred_at')->latest('occurred_at'))
            ->columns([
                TextColumn::make('occurred_at')->label('Time')->dateTime('Y-m-d H:i')->sortable(),
                TextColumn::make('app')->badge()->color('gray')->searchable(),
                TextColumn::make('error_type')->limit(32)->searchable(),
                TextColumn::make('weak_label')->label('Class')->badge(),
                TextColumn::make('message')->limit(70)->tooltip(fn (LogEvent $r) => $r->message)->searchable(),
                TextColumn::make('correlation_id')->label('Corr. ID')
                    ->formatStateUsing(fn (?string $s) => $s ? substr($s, 0, 8).'…' : null)
                    ->copyable()->copyableState(fn (LogEvent $r) => $r->correlation_id)
                    ->fontFamily('mono'),
            ]);
    }
}
