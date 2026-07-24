<?php

namespace App\Filament\Resources\GoldenCases\Schemas;

use App\Enums\FailureCategory;
use App\Models\GoldenCase;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

class GoldenCaseForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Evidence')
                ->description('Read-only — what the model (and you) classify from.')
                ->schema([
                    // One stable node instead of many placeholders — avoids the
                    // Livewire DOM-morph breakage that fragmented the old layout.
                    Placeholder::make('evidence')
                        ->hiddenLabel()
                        ->content(fn (GoldenCase $record) => new HtmlString(self::renderEvidence($record)))
                        ->columnSpanFull(),
                ]),
            Section::make('Label')
                ->columns(2)
                ->schema([
                    TextEntry::make('weak_label')
                        ->label('Rule-based (baseline)')
                        ->badge()
                        ->color('gray'),
                    Select::make('env')
                        ->label('Environment')
                        ->helperText('Provenance. Correct it here if the source was mis-tagged.')
                        ->options(['prod' => 'prod', 'uat' => 'uat', 'sit' => 'sit', 'dev' => 'dev', 'unknown' => 'unknown'])
                        ->required(),
                    Select::make('gold_label')
                        ->label('Ground-truth label')
                        ->options(collect(FailureCategory::cases())->mapWithKeys(fn ($c) => [$c->value => "{$c->value} — {$c->label()}"])->all())
                        ->searchable()
                        ->required()
                        ->columnSpanFull(),
                    Toggle::make('reviewed')
                        ->helperText('Mark true once you have confirmed the label.'),
                    Textarea::make('note')
                        ->rows(3)
                        ->placeholder('Why, or what made it tricky')
                        ->columnSpanFull(),
                ]),
        ]);
    }

    private static function renderEvidence(GoldenCase $record): string
    {
        $in = $record->input ?? [];
        $rows = [];

        // Inline styles (not Tailwind classes): Filament's CSS is precompiled and
        // won't include arbitrary utilities used inside injected HTML. No explicit
        // text color + a translucent rule → works in both light and dark themes.
        $add = function (string $label, $value, bool $mono = true) use (&$rows): void {
            if ($value === null || $value === '' || $value === []) {
                return;
            }
            $value = is_array($value) ? implode("\n", $value) : (string) $value;
            $valStyle = 'margin-top:.15rem;font-size:.85rem;white-space:pre-wrap;word-break:break-word;'
                .($mono ? 'font-family:ui-monospace,SFMono-Regular,Menlo,monospace;' : '');
            $rows[] = '<div style="padding:.55rem 0;border-bottom:1px solid rgba(128,128,128,.25);">'
                .'<div style="font-weight:700;font-size:.8rem;letter-spacing:.02em;">'.e($label).'</div>'
                .'<div style="'.$valStyle.'">'.e($value).'</div>'
                .'</div>';
        };

        // Identifiers first — the correlation id is what you paste into Anypoint.
        $add('Golden case', $record->case_id, false);
        $add('Correlation ID', $record->correlation_id);
        $add('Error type', $record->error_type);
        $add('App', $record->app, false);
        $add('Occurred at', $record->occurred_at ? (string) $record->occurred_at : null, false);
        $add('Log file', $record->source_file);

        // Symptom, then correlated root cause(s).
        $add('Message', $in['message'] ?? null);
        $add('Root exception', $in['root_exception'] ?? null);

        $details = $in['error_detail'] ?? [];
        $multi = count($details) > 1;
        foreach ($details as $i => $detail) {
            if (! is_array($detail)) {
                continue;
            }
            $suffix = $multi ? ' #'.($i + 1) : '';
            $add('Detail'.$suffix.' · code', $detail['code'] ?? null);
            $add('Detail'.$suffix.' · description', $detail['description'] ?? null);
        }

        $add('Flow / element', $in['element'] ?? null);
        $http = trim(($in['http_method'] ?? '').' '.($in['http_status'] ?? '').' '.($in['resource_url'] ?? ''));
        $add('HTTP', $http !== '' ? $http : null);
        $add('Stack (top)', array_slice($in['stack_top'] ?? [], 0, 3));

        return '<div>'.implode('', $rows).'</div>';
    }
}
