<x-filament-panels::page>
    <form wire:submit="ingest">
        {{ $this->form }}

        <div style="margin-top:1.5rem;">
            <x-filament::button type="submit" icon="heroicon-o-arrow-up-tray">
                Parse &amp; import
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
