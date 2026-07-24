<?php

namespace App\Filament\Resources\GoldenCases\Pages;

use App\Filament\Resources\GoldenCases\GoldenCaseResource;
use App\Models\GoldenCase;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\On;

class EditGoldenCase extends EditRecord
{
    protected static string $resource = GoldenCaseResource::class;

    private bool $envChanged = false;

    protected function beforeSave(): void
    {
        // Capture before persist so afterSave knows the env was edited this save.
        $this->envChanged = $this->record->isDirty('env');
    }

    protected function afterSave(): void
    {
        $env = $this->record->env;
        if (! $this->envChanged || $env === 'unknown' || blank($this->record->source_file)) {
            return;
        }

        $siblings = GoldenCase::query()
            ->where('source_file', $this->record->source_file)
            ->where('env', 'unknown')
            ->where('id', '!=', $this->record->getKey())
            ->count();

        if ($siblings === 0) {
            return;
        }

        Notification::make()
            ->title('Apply to the rest of this log file?')
            ->body("{$siblings} other case(s) from this file are still \"unknown\". Set them to \"{$env}\" too?")
            ->actions([
                Action::make('apply')
                    ->label("Set {$siblings} to \"{$env}\"")
                    ->button()
                    ->dispatch('applyEnvToSiblings', ['sourceFile' => $this->record->source_file, 'env' => $env])
                    ->close(),
                Action::make('dismiss')->label('No')->color('gray')->close(),
            ])
            ->persistent()
            ->send();
    }

    #[On('applyEnvToSiblings')]
    public function applyEnvToSiblings(string $sourceFile, string $env): void
    {
        $updated = GoldenCase::query()
            ->where('source_file', $sourceFile)
            ->where('env', 'unknown')
            ->update(['env' => $env]);

        Notification::make()
            ->title("Updated {$updated} case(s) from this file to \"{$env}\"")
            ->success()
            ->send();
    }

    protected function getHeaderActions(): array
    {
        [$position, $count, $prevId, $nextId] = $this->queuePosition();

        return [
            Action::make('previous')
                ->label('Previous')
                ->icon('heroicon-o-chevron-left')
                ->color('gray')
                ->url(fn () => $prevId ? static::getResource()::getUrl('edit', ['record' => $prevId]) : null)
                ->visible($prevId !== null),
            // Save between the nav buttons; primary color keeps it distinct from the
            // gray Prev/Next so the review rhythm (save → next) is quick but misclick-safe.
            $this->getSaveFormAction()->formId('form'),
            Action::make('next')
                ->label('Next')
                ->icon('heroicon-o-chevron-right')
                ->color('gray')
                ->url(fn () => $nextId ? static::getResource()::getUrl('edit', ['record' => $nextId]) : null)
                ->visible($nextId !== null),
        ];
    }

    protected function getFormActions(): array
    {
        // Delete lives at the bottom with Save/Cancel — a destructive action away
        // from the primary nav row.
        return [
            ...parent::getFormActions(),
            $this->buildDeleteAction(),
        ];
    }

    protected function buildDeleteAction(): Action
    {
        return Action::make('delete')
            ->label('Delete case')
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Delete this golden case?')
            ->modalDescription(new HtmlString(
                'Delete <strong>only</strong> when this case does not belong in the golden set: a mis-parsed '
                .'fragment, a duplicate, noise/synthetic (health-check or test), or a genuinely un-labelable case. '
                .'<br><br>Do <strong>not</strong> delete over a missing environment — leave that as "unknown". '
                .'This removes a real, labeled ground-truth example and cannot be undone.'
            ))
            ->modalSubmitActionLabel('Delete case')
            ->action(function () {
                $this->record->delete();

                Notification::make()->title('Golden case deleted')->success()->send();

                $this->redirect(static::getResource()::getUrl('index'));
            });
    }

    public function getSubheading(): ?string
    {
        [$position, $count] = $this->queuePosition();

        return $position !== null
            ? "Case {$position} of {$count} — from your last Golden Cases view (filters & sort preserved)"
            : null;
    }

    /**
     * Locate this record within the session-stored review queue.
     *
     * @return array{0: ?int, 1: int, 2: int|string|null, 3: int|string|null}
     *         [1-based position, total, previous id, next id]
     */
    private function queuePosition(): array
    {
        $queue = session('golden.review_queue', []);
        $index = array_search($this->record->getKey(), $queue, true);

        if ($index === false) {
            return [null, count($queue), null, null];
        }

        return [
            $index + 1,
            count($queue),
            $queue[$index - 1] ?? null,
            $queue[$index + 1] ?? null,
        ];
    }
}
