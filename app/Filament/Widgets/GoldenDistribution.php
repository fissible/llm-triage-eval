<?php

namespace App\Filament\Widgets;

use App\Models\GoldenCase;
use Filament\Widgets\ChartWidget;

class GoldenDistribution extends ChartWidget
{
    protected static ?int $sort = 3;

    public function getHeading(): ?string
    {
        return 'Answer key — reviewed cases by failure category';
    }

    public function getDescription(): ?string
    {
        return 'How the '.GoldenCase::count().' hand-reviewed cases break down across the taxonomy.';
    }

    protected function getData(): array
    {
        $counts = GoldenCase::query()
            ->selectRaw('gold_label, count(*) as c')
            ->groupBy('gold_label')
            ->orderByDesc('c')
            ->pluck('c', 'gold_label');

        return [
            'datasets' => [[
                'label' => 'Cases',
                'data' => $counts->values()->all(),
                'backgroundColor' => '#f59e0b',
            ]],
            'labels' => $counts->keys()->all(),
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
