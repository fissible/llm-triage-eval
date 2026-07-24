<?php

namespace App\Filament\Pages;

use App\Services\Triage\MuleLogParser;
use App\Services\Triage\Sanitizer;
use App\Services\Triage\TaxonomyClassifier;
use BackedEnum;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class IngestLogs extends Page implements HasForms
{
    use InteractsWithForms;

    protected string $view = 'filament.pages.ingest-logs';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    protected static ?int $navigationSort = 5;

    // Constrain this form page; wide tables (Correlations, Golden Cases) stay full-width.
    protected Width|string|null $maxContentWidth = Width::FourExtraLarge;

    /** @var array<string,mixed> */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                FileUpload::make('logs')
                    ->label('CloudHub "Download Logs" files')
                    ->helperText('Hash-named .log files (the ones with Error type blocks) — NOT *-mule_ee.log. Parsed then discarded; nothing raw is retained.')
                    ->multiple()
                    ->disk('local')
                    ->directory('triage/uploads')
                    ->preserveFilenames()
                    ->maxSize(300 * 1024) // 300 MB (also needs php upload_max_filesize/post_max_size)
                    ->required(),
                Select::make('env')
                    ->label('Environment tag')
                    ->options(['prod' => 'prod', 'uat' => 'uat', 'sit' => 'sit', 'dev' => 'dev'])
                    ->placeholder('Auto (infer from filename)'),
            ])
            ->statePath('data');
    }

    public function ingest(): void
    {
        @set_time_limit(600); // large logs parse for a while

        $state = $this->form->getState();
        $env = $state['env'] ?? null;
        $paths = $state['logs'] ?? [];

        $parser = app(MuleLogParser::class);
        $classifier = app(TaxonomyClassifier::class);
        $sanitizer = app(Sanitizer::class);

        $files = 0;
        $events = 0;
        $byCategory = [];

        foreach ($paths as $relative) {
            $full = Storage::disk('local')->path($relative);
            if (! is_file($full)) {
                continue;
            }
            $files++;
            $batch = [];
            foreach ($parser->parseFile($full, $env) as $case) {
                $category = $classifier->classify($case);
                $case['weak_label'] = $category->value;
                $case = $sanitizer->sanitizeCase($case);
                $byCategory[$category->value] = ($byCategory[$category->value] ?? 0) + 1;

                $batch[] = [
                    'correlation_id' => $case['correlation_id'] ?? null,
                    'app' => $case['app'] ?? null,
                    'env' => $case['env'] ?? null,
                    'error_type' => $case['error_type'] ?? null,
                    'weak_label' => $case['weak_label'],
                    'occurred_at' => isset($case['timestamp']) ? Carbon::parse($case['timestamp']) : null,
                    'message' => $case['message'] ?? null,
                    'root_exception' => $case['root_exception'] ?? null,
                    'resource_url' => $case['resource_url'] ?? null,
                    'http_status' => $case['http_status'] ?? null,
                    'error_detail' => isset($case['error_detail']) ? json_encode($case['error_detail']) : null,
                    'source_file' => $case['source_file'] ?? null,
                ];
                if (count($batch) >= 500) {
                    DB::table('log_events')->insert($batch);
                    $events += count($batch);
                    $batch = [];
                }
            }
            if ($batch !== []) {
                DB::table('log_events')->insert($batch);
                $events += count($batch);
            }

            // Discard the uploaded file — we never retain raw logs.
            Storage::disk('local')->delete($relative);
        }

        $this->form->fill();

        arsort($byCategory);
        $summary = collect($byCategory)->take(5)->map(fn ($n, $c) => "{$c}: {$n}")->implode(' · ');

        Notification::make()
            ->title("Ingested {$events} events from {$files} file(s)")
            ->body($summary !== '' ? $summary : 'No error blocks found — was this a "Download Logs" file (not mule_ee)?')
            ->success()
            ->persistent()
            ->send();
    }
}
