<?php

namespace App\Filament\Widgets;

use App\Models\LogEvent;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class CorrelationStats extends BaseWidget
{
    protected static ?int $sort = 2;

    protected function getStats(): array
    {
        $events = LogEvent::count();
        $apps = LogEvent::query()->distinct()->whereNotNull('app')->count('app');
        $transactions = LogEvent::query()->distinct()->whereNotNull('correlation_id')->count('correlation_id');
        $crossApp = LogEvent::query()
            ->select('correlation_id')
            ->groupBy('correlation_id')
            ->havingRaw('count(distinct app) > 1')
            ->get()
            ->count();
        $topApp = LogEvent::query()
            ->selectRaw('app, count(*) as c')
            ->whereNotNull('app')
            ->groupBy('app')
            ->orderByDesc('c')
            ->first();

        return [
            Stat::make('Failure events', number_format($events))
                ->description("across {$apps} apps")
                ->color('gray'),
            Stat::make('Transactions', number_format($transactions))
                ->description('distinct correlation IDs')
                ->color('gray'),
            Stat::make('Cross-app incidents', number_format($crossApp))
                ->description('one failure spanning multiple apps')
                ->color($crossApp > 0 ? 'warning' : 'gray'),
            Stat::make('Busiest app', $topApp->app ?? '—')
                ->description($topApp ? number_format($topApp->c).' events' : 'no data')
                ->color('gray'),
        ];
    }
}
