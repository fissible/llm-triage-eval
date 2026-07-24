<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">Run a command</x-slot>
        <x-slot name="description">Output streams live below. Interactive runs only — for large/unattended work use the CLI.</x-slot>

        <div
            x-data="{
                running: false,
                output: '',
                reviewed: true,
                limit: '',
                prompt: '',
                run(key) {
                    this.output = '';
                    this.running = true;
                    const p = new URLSearchParams({ key });
                    if (key === 'eval') {
                        if (this.reviewed) p.set('reviewed', '1');
                        if (this.limit) p.set('limit', this.limit);
                        if (this.prompt) p.set('prompt', this.prompt);
                    }
                    const es = new EventSource(@js(route('commands.stream')) + '?' + p.toString());
                    es.onmessage = (e) => {
                        this.output += e.data + '\n';
                        this.$nextTick(() => { const el = this.$refs.out; if (el) el.scrollTop = el.scrollHeight; });
                    };
                    es.addEventListener('done', (e) => {
                        this.output += '\n' + e.data + '\n';
                        es.close(); this.running = false;
                    });
                    es.onerror = () => { this.output += '\n[stream ended]\n'; es.close(); this.running = false; };
                }
            }"
        >
            <div style="display:flex;flex-wrap:wrap;gap:1.5rem;align-items:stretch;margin-bottom:1rem;">
                <fieldset style="border:1px solid rgba(128,128,128,.3);border-radius:.5rem;padding:.5rem 1rem 1rem;">
                    <legend style="font-size:.75rem;font-weight:700;padding:0 .35rem;font-family:ui-monospace,monospace;">triage:eval</legend>
                    <div style="display:flex;flex-wrap:wrap;gap:1rem;align-items:end;">
                        <label style="display:flex;align-items:center;gap:.4rem;font-size:.875rem;">
                            <input type="checkbox" x-model="reviewed"> --only-reviewed
                        </label>
                        <label style="display:flex;flex-direction:column;font-size:.75rem;gap:.15rem;">
                            --limit
                            <input type="number" x-model="limit" placeholder="0 = all" style="width:6rem;padding:.25rem .4rem;border:1px solid rgba(128,128,128,.4);border-radius:.375rem;background:transparent;">
                        </label>
                        <label style="display:flex;flex-direction:column;font-size:.75rem;gap:.15rem;">
                            --prompt
                            <input type="text" x-model="prompt" placeholder="v1" style="width:6rem;padding:.25rem .4rem;border:1px solid rgba(128,128,128,.4);border-radius:.375rem;background:transparent;">
                        </label>
                        <x-filament::button type="button" icon="heroicon-o-play" x-on:click="run('eval')" x-bind:disabled="running">
                            Run eval
                        </x-filament::button>
                    </div>
                </fieldset>

                <fieldset style="border:1px solid rgba(128,128,128,.3);border-radius:.5rem;padding:.5rem 1rem 1rem;">
                    <legend style="font-size:.75rem;font-weight:700;padding:0 .35rem;font-family:ui-monospace,monospace;">triage:import-events</legend>
                    <div style="display:flex;align-items:end;height:100%;">
                        <x-filament::button type="button" color="gray" icon="heroicon-o-arrow-path" x-on:click="run('import-events')" x-bind:disabled="running">
                            Re-import events
                        </x-filament::button>
                    </div>
                </fieldset>

                <span x-show="running" style="align-self:center;font-size:.8rem;opacity:.7;">running…</span>
            </div>

            <pre
                x-ref="out"
                x-show="output"
                x-text="output"
                style="background:#0b1021;color:#d6e2f0;padding:1rem;border-radius:.5rem;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:.78rem;line-height:1.4;max-height:28rem;overflow:auto;white-space:pre-wrap;word-break:break-word;margin:0;"
            ></pre>
        </div>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">All triage commands</x-slot>
        <x-slot name="description">Run these from a terminal. Only the safe presets above are runnable from the browser.</x-slot>

        <div style="display:flex;flex-direction:column;">
            @foreach ($this->getTriageCommands() as $command)
                <div style="padding:.6rem 0;border-bottom:1px solid rgba(128,128,128,.2);">
                    <div style="font-family:ui-monospace,monospace;font-weight:700;font-size:.85rem;">{{ $command['name'] }}</div>
                    <div style="font-size:.85rem;margin:.15rem 0;">{{ $command['description'] }}</div>
                    <div style="font-family:ui-monospace,monospace;font-size:.75rem;opacity:.7;white-space:pre-wrap;word-break:break-word;">php artisan {{ $command['synopsis'] }}</div>
                </div>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-panels::page>
