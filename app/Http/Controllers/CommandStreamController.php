<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Process\Process;

/**
 * Streams a whitelisted artisan command's output to the browser as Server-Sent
 * Events (Forge-style live console). Interactive on-demand runs only — unattended
 * / scheduled work belongs on a queue + scheduler (added later with Anypoint).
 *
 * SECURITY: only preset keys are runnable, and argv is built server-side and passed
 * to Symfony Process as an array (no shell), so there is no command injection surface.
 */
class CommandStreamController extends Controller
{
    public function stream(Request $request): StreamedResponse
    {
        $argv = $this->resolve($request);
        abort_if($argv === null, 400, 'Unknown or disallowed command');

        return response()->stream(function () use ($argv) {
            @set_time_limit(0);
            while (ob_get_level() > 0) {
                ob_end_flush();
            }

            $this->sse('$ php artisan '.implode(' ', $argv));

            $process = new Process(
                array_merge([PHP_BINARY, base_path('artisan')], $argv),
                base_path(),
                null,
                null,
                null // no timeout — evals can run for minutes
            );

            $process->run(function (string $type, string $buffer): void {
                foreach (preg_split('/\r\n|\r|\n/', $buffer) as $line) {
                    if ($line !== '') {
                        $this->sse($line);
                    }
                }
            });

            $this->sse('exit code: '.$process->getExitCode(), 'done');
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no', // don't let nginx buffer the stream
            'Connection' => 'keep-alive',
        ]);
    }

    /**
     * Map a preset key + validated params to an argv array, or null if not allowed.
     *
     * @return list<string>|null
     */
    private function resolve(Request $request): ?array
    {
        return match ($request->query('key')) {
            'eval' => array_values(array_filter([
                'triage:eval',
                $request->boolean('reviewed') ? '--only-reviewed' : null,
                ($limit = (int) $request->query('limit', 0)) > 0 ? "--limit={$limit}" : null,
                ($prompt = preg_replace('/[^a-z0-9._-]/i', '', (string) $request->query('prompt', ''))) !== ''
                    ? "--prompt={$prompt}" : null,
            ])),
            'import-events' => [
                'triage:import-events',
                storage_path('app/triage/prod.jsonl'),
                storage_path('app/triage/repo.jsonl'),
                '--fresh',
            ],
            default => null,
        };
    }

    private function sse(string $data, string $event = 'message'): void
    {
        if ($event !== 'message') {
            echo "event: {$event}\n";
        }
        echo 'data: '.$data."\n\n";
        if (ob_get_level() > 0) {
            @ob_flush();
        }
        flush();
    }
}
