<x-filament-panels::page>
    @php
        $run = $this->record;
        $confusion = $run->confusion ?? [];
        $perCat = $run->per_category ?? [];
        $labels = [];
        foreach ($confusion as $g => $row) {
            $labels[$g] = true;
            foreach ($row as $p => $c) { $labels[$p] = true; }
        }
        $labels = array_keys($labels);
        sort($labels);
        $max = 1;
        foreach ($confusion as $row) { foreach ($row as $c) { $max = max($max, $c); } }
        $tile = 'flex:1;min-width:8rem;border:1px solid rgba(128,128,128,.25);border-radius:.5rem;padding:.75rem 1rem;';
    @endphp

    <div style="display:flex;flex-wrap:wrap;gap:1rem;margin-bottom:1.5rem;">
        <div style="{{ $tile }}">
            <div style="font-size:.7rem;text-transform:uppercase;opacity:.6;">LLM accuracy</div>
            <div style="font-size:1.5rem;font-weight:700;">{{ number_format($run->llm_accuracy * 100, 1) }}%</div>
            <div style="font-size:.75rem;opacity:.7;">{{ $run->model }} · {{ $run->prompt_version }}</div>
        </div>
        <div style="{{ $tile }}">
            <div style="font-size:.7rem;text-transform:uppercase;opacity:.6;">Rule baseline</div>
            <div style="font-size:1.5rem;font-weight:700;">{{ number_format($run->baseline_accuracy * 100, 1) }}%</div>
            <div style="font-size:.75rem;opacity:.7;">{{ $run->fully_reviewed ? 'vs reviewed gold' : 'vs weak labels' }}</div>
        </div>
        <div style="{{ $tile }}">
            <div style="font-size:.7rem;text-transform:uppercase;opacity:.6;">Cases</div>
            <div style="font-size:1.5rem;font-weight:700;">{{ $run->n }}</div>
        </div>
        <div style="{{ $tile }}">
            <div style="font-size:.7rem;text-transform:uppercase;opacity:.6;">Tokens / cost</div>
            <div style="font-size:1.1rem;font-weight:700;">{{ number_format($run->prompt_tokens + $run->completion_tokens) }}</div>
            <div style="font-size:.75rem;opacity:.7;">${{ number_format((float) $run->cost_usd, 4) }}</div>
        </div>
    </div>

    <x-filament::section>
        <x-slot name="heading">Confusion matrix</x-slot>
        <x-slot name="description">Rows = gold label · columns = model prediction. Columns are numbered to match the row labels (same order). Green diagonal = correct, red = confusions.</x-slot>
        <div style="overflow-x:auto;">
            <table style="border-collapse:collapse;font-size:.75rem;font-family:ui-monospace,monospace;">
                <thead>
                    <tr>
                        <th></th>
                        @foreach ($labels as $p)
                            <th style="padding:.25rem;font-weight:700;min-width:1.8rem;" title="{{ $p }}">{{ $loop->iteration }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($labels as $g)
                        <tr>
                            <th style="text-align:right;padding:.25rem .5rem;font-weight:600;white-space:nowrap;"><span style="opacity:.5;">{{ $loop->iteration }}.</span> {{ $g }}</th>
                            @foreach ($labels as $p)
                                @php
                                    $c = $confusion[$g][$p] ?? 0;
                                    $alpha = $c ? round(0.18 + 0.82 * $c / $max, 2) : 0;
                                    $bg = $c ? ($g === $p ? "rgba(34,197,94,$alpha)" : "rgba(239,68,68,$alpha)") : 'transparent';
                                @endphp
                                <td style="width:2.3rem;height:2.3rem;text-align:center;border:1px solid rgba(128,128,128,.15);background:{{ $bg }};" title="gold {{ $g }} → pred {{ $p }}: {{ $c }}">{{ $c ?: '' }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Per-category</x-slot>
        <div style="overflow-x:auto;">
            <table style="border-collapse:collapse;width:100%;font-size:.85rem;">
                <thead>
                    <tr style="text-align:left;border-bottom:1px solid rgba(128,128,128,.3);">
                        <th style="padding:.4rem;">Category</th>
                        <th style="padding:.4rem;">Support</th>
                        <th style="padding:.4rem;">Precision</th>
                        <th style="padding:.4rem;">Recall</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($perCat as $cat => $m)
                        <tr style="border-bottom:1px solid rgba(128,128,128,.15);">
                            <td style="padding:.4rem;font-family:ui-monospace,monospace;">{{ $cat }}</td>
                            <td style="padding:.4rem;">{{ $m['support'] ?? 0 }}</td>
                            <td style="padding:.4rem;">{{ number_format(($m['precision'] ?? 0) * 100, 0) }}%</td>
                            <td style="padding:.4rem;">{{ number_format(($m['recall'] ?? 0) * 100, 0) }}%</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
