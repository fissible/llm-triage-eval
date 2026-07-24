# llm-triage-eval

LLM-powered **failure taxonomy and triage for an integration layer**, with an
**eval harness at its core** and a Filament admin UI on top. It ingests
MuleSoft/CloudHub-style logs, classifies each failure into a root-cause taxonomy,
and — crucially — *measures* whether an LLM actually beats a cheap rule-based
baseline at that job before trusting it with anything.

The reusable idea: **build the eval harness first, then let the numbers decide
where (and whether) the LLM belongs.** A labeled "golden set" of real failures
also doubles as a **migration-acceptance suite** if the same functionality later
moves to another platform.

Built on Laravel + [Prism](https://prismphp.com) (provider-agnostic LLM SDK) +
[Filament](https://filamentphp.com). Runs **local-first** on Ollama at ~$0; flip
one config value to point at a remote Ollama or at Claude to *measure* the
quality/cost gap.

> **Note on data.** This public repo ships a small set of **synthetic** example
> failures in `database/golden/candidates.jsonl` so the harness runs out of the
> box. The findings quoted in [`FINDINGS.md`](FINDINGS.md) come from a private
> production dataset and are **not** reproducible from the shipped examples —
> run the pipeline on your own logs to generate real numbers.

---

## Quick start

```bash
composer install
cp .env.example .env && php artisan key:generate   # first time only
php artisan migrate --force
php artisan triage:import        # load the golden set into SQLite
php artisan serve                # → http://127.0.0.1:8000/admin
```

Then start an LLM (below) before running `php artisan triage:eval`.

### Admin UI login

A local dev user is seeded as `admin@triage.test` / `password`
(make your own: `php artisan make:filament-user`). The panel at `/admin` has:

- **Ingest Logs** — drag in CloudHub "Download Logs" files → parsed + imported →
  the raw file is **discarded**. No CLI needed to add data.
- **Correlations** — every event grouped by correlation ID into cross-app chains;
  "Cross-app chains only" filter; golden events are badged and linked back.
- **Golden Cases** — browse/filter by env, app, label, reviewed, or "corrected";
  correlation ID is **click-to-copy**; "Review" edits the label, marks it
  reviewed, adds a note; "Chain" jumps to the transaction's cross-app trace.
- **Eval Runs** — history of scored runs (accuracy, baseline, tokens, cost).
- **Commands** — run `triage:eval` / re-import with **live-streamed output**
  (SSE), plus a reference list of every `triage:*` command.
- **Dashboard** — reviewed-progress, latest LLM accuracy, and the rule baseline.

Optional: set `TRIAGE_ANYPOINT_URL` (with `{cid}` where the correlation id goes)
to get a per-case "Anypoint" deep-link button.

> **Recommended way to run the server.** `php artisan serve` defaults to a ~2 MB
> upload cap and a *single* worker (a live command stream would block the rest of
> the UI). For log uploads and the streaming console, start it with raised limits
> and multiple workers:
> ```
> PHP_CLI_SERVER_WORKERS=4 php -d upload_max_filesize=350M -d post_max_size=350M -d memory_limit=512M artisan serve
> ```

---

## Running the LLM

The harness is provider-agnostic (Prism). Pick one:

### A. Local Ollama — default, recommended for dev (~$0, private, offline)

```bash
brew install ollama
# one terminal tab (flags = faster + smaller KV cache on Apple Silicon):
OLLAMA_FLASH_ATTENTION=1 OLLAMA_KV_CACHE_TYPE=q8_0 ollama serve
# another tab:
ollama pull gemma3:12b
```

No `.env` changes needed — defaults are `TRIAGE_PROVIDER=ollama`,
`TRIAGE_MODEL=gemma3:12b`, `OLLAMA_URL=http://localhost:11434`.

### B. Remote Ollama — a bigger model on a shared host

Point at any remote Ollama instance and pull a larger model there. In `.env`:

```env
OLLAMA_URL=http://your-ollama-host:11434
TRIAGE_MODEL=gemma3:27b
```

`gemma3:27b` beats local `12b` on quality, at the cost of a network dependency —
which is exactly the kind of trade-off the eval harness is there to quantify.

### C. Anthropic — benchmark Claude against local

```env
TRIAGE_PROVIDER=anthropic
TRIAGE_MODEL=claude-sonnet-5
ANTHROPIC_API_KEY=sk-ant-...
# for $ in reports, set the model's price per 1M tokens:
TRIAGE_COST_IN=3
TRIAGE_COST_OUT=15
```

Re-run `triage:eval` and compare the report against the local run — that's the
number that answers "should we pay for a hosted model?"

---

## Pipeline (CLI)

```
raw logs ──▶ triage:parse ──▶ triage:golden ──▶ review ──▶ triage:eval ──▶ report ──▶ triage:import
            parse+weak-label   dedup/sanitize/sample   (UI or TUI)   Prism→LLM   JSON      → DB/UI
```

```bash
# Parse CloudHub "Download Logs" (NOT "Download Mule Logs") into weak-labeled cases
php artisan triage:parse "~/Downloads/prod-logs/*.log" --env=prod --out=storage/app/triage/prod.jsonl

# Dedup + PII-sanitize + stratified-sample a candidate golden set
php artisan triage:golden storage/app/triage/prod.jsonl --size=80

# Review labels — in the UI (/admin) or the terminal:
php artisan triage:review --dump                 # read-only, copy-friendly
php artisan triage:review --category=unknown     # interactive picker, resumable

# Score the LLM classifier (temp 0) → timestamped JSON report
php artisan triage:eval --only-reviewed

# Sync files → database so the UI reflects the latest golden set + reports
php artisan triage:import
```

`storage/eval-reports/eval-<timestamp>-<prompt>.json` carries overall accuracy,
per-category precision/recall, a confusion matrix, LLM-vs-baseline, tokens+cost,
and every misclassified case.

## Iterating on prompts (TDD-style)

Copy `resources/views/prompts/classify-v1*.blade.php` → `-v2`, edit, then
`php artisan triage:eval --only-reviewed --prompt=v2` and diff the two reports.

## Validating the LLM judge (judge-the-judge)

Summaries are scored by an LLM judge — which is itself unproven until you check
it against a human. `php artisan triage:judge-review --n=15` shows you summaries
with the judge's scores **hidden**, collects your blind 1–5 scores, and reports
agreement (exact %, within-1 %, bias, and quadratic-weighted Cohen's κ) so you
only trust the axes where the judge actually tracks a human.

## Taxonomy

Root-cause based (not raw Mule `Error type`): see `app/Enums/FailureCategory.php`.

## Config

`config/triage.php` — provider, model, prompt version, timeout, cost-per-Mtok,
Anypoint URL. Ollama needs no key.

## License

MIT — see [`LICENSE`](LICENSE).
