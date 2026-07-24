<?php

namespace App\Filament\Widgets;

use App\Models\EvalRun;
use App\Models\GoldenCase;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class TriageStats extends BaseWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $total = GoldenCase::count();
        $reviewed = GoldenCase::where('reviewed', true)->count();
        $latest = EvalRun::latest('ran_at')->first();

        return [
            Stat::make('Golden set reviewed', "{$reviewed} / {$total}")
                ->description($total > 0 ? round($reviewed / $total * 100).'% labeled' : 'empty')
                ->color($reviewed === $total && $total > 0 ? 'success' : 'gray'),

            Stat::make('Latest LLM accuracy', $latest ? round($latest->llm_accuracy * 100, 1).'%' : '—')
                ->description($latest ? "{$latest->model} · {$latest->prompt_version}".($latest->fully_reviewed ? '' : ' (vs weak labels)') : 'no runs yet')
                ->color($latest && $latest->llm_accuracy >= 0.9 ? 'success' : 'warning'),

            Stat::make('Rule-based baseline', $latest ? round($latest->baseline_accuracy * 100, 1).'%' : '—')
                ->description($latest ? "{$latest->n} cases · \${$latest->cost_usd}" : '')
                ->color('gray'),
        ];
    }
}
