# Write-up outline — "The AI app where I measured the AI out of the pipeline"

Audience: developers. A technical, honest build-and-findings piece. Every section
below has the specific numbers/decisions to expand into prose or slides.

> **Provenance.** All numbers here come from a **private production dataset**
> (~4,000 failures, 58 human-reviewed cases). The public repo ships only synthetic
> examples; re-run the pipeline on your own logs to reproduce them.

---

## 0. TL;DR / thesis
- Built an LLM-powered triage tool for integration-layer failures. Set out to have an
  LLM classify failures; built an **eval harness** to prove it worked; the harness
  proved the LLM was *not* worth it for classification (or structured extraction).
- **Net:** deterministic rules/parser do classification + extraction (cheap, fast,
  local, $0); the LLM is used for **one** thing — plain-English incident summaries.
- **Headline numbers:** classification rules **89.7%** vs LLM **87.9%** (58 reviewed
  cases); extraction LLM ↔ details-aware regex agree **90–97%** on structured fields;
  summaries scored **4.62/5 faithfulness** (judge human-validated: within-1 100%, κ 0.57).
  All local (gemma3:12b via Ollama), ~$0.

## 1. Context & problem
- A MuleSoft (CloudHub) integration stack being **sunset**; functionality to be
  re-implemented on another platform. Primary MuleSoft failure mode: **timeouts to a
  legacy PostgreSQL DB**; brittle, expensive, hard to maintain.
- Framing: "failure **taxonomy + triage** for the integration layer, seeded with
  MuleSoft since it's sunsetting." MuleSoft is the **data source, not the subject**.
- The labeled failure set doubles as a **migration-acceptance suite** for the
  re-implementation.
- Built alongside Chip Huyen's *AI Engineering* as a learning vehicle; Laravel chosen
  deliberately for portability into the target stack.

## 2. Stack & architecture
- **Laravel 13, Filament v5, Prism (v0.100), Ollama (gemma3:12b), SQLite, PHPUnit.**
- Provider-agnostic LLM via **Prism** — Ollama default; swap to Anthropic in 2 config
  values (`config/triage.php`: provider, model, prompt_version, timeout, cost_per_mtok).
- Convention: **plain Services + Console Commands** (portable — easy to lift into
  another Laravel app later), not a framework-specific action pattern.
- **DB is source of truth** for the eval; `candidates.jsonl` is a stale seed. Tables:
  `golden_cases`, `eval_runs`, `eval_results`, `log_events`.
- Local-first rationale: work Claude *subscription* ≠ API access; local keeps sensitive
  logs on-device; free iteration; and the harness can *measure* local-vs-hosted later.

## 3. Data pipeline
- **CloudHub log format trap (dev-relevant):** "**Download Logs**" = hash-named files
  with structured `Message / Error type / FlowStack` blocks + `event:<correlationId>`
  → parseable. "**Download Mule Logs**" = `*-mule_ee.log`, raw stacks, **0 error
  blocks**, blank correlation → useless. Cost real time before I noticed.
- **Streaming parser** (`MuleLogParser`): line-by-line via generator → constant memory
  on 100 MB+ files. Header regex + block-field regex; app name pulled from the flow
  reference in `Element` (the header thread is unreliable — Grizzly/idle threads carry
  no app).
- **Correlated-detail enrichment (the key move):** a transaction logs a generic ERROR
  (e.g. `MULE:COMPOSITE_ROUTING`) *and*, at WARN, a JSON blob
  `event:<cid> {code,message,description}` carrying the true root cause. A two-pass scan
  collects all detail blocks keyed by correlation id and attaches them (as a list) to
  the error case. **Impact:** de-aliased `composite_routing` **287→85** and surfaced
  `db_timeout` **0→60** once the real cause was visible.
- **Dedup:** ~4,000 parsed failures → **58 distinct signatures** (by error_type +
  normalized message + element). E.g. 675 DataWeave errors were ~1 bug.
- **Sanitization** calibrated to real data (a scan found ~0 PII in LLM-visible fields):
  redact SSN/email/9-digit IDs, Postgres `Detail: Key (col)=(value)` and
  `Failing row contains (...)`. `person_person_guid_key` is a constraint *name* (schema
  metadata), not PII.
- **env** is provenance (prod/uat/…), inferred from filename or `--env` override; never
  a per-case judgment.

## 4. Taxonomy & labeling doctrine
- **Root-cause-based, not error-code-based.** `FailureCategory` enum (13): db_timeout,
  db_constraint_violation, downstream_db_write_601, downstream_500_cascade,
  downstream_timeout, dataweave_expression_error, business_validation, sf_streaming_auth,
  composite_routing, connectivity, downstream_client_error, malformed_request, unknown.
  Categories derived from `grep 'Error type'` frequency counts on real logs.
- **Doctrine A (the labeling standard):** classify by the **deepest root cause visible
  in the correlated details** over generic wrappers (a 601/500/routing error whose detail
  is a `duplicate key` violation is `db_constraint_violation`). Nuance: a timeout
  *calling* `inventory-sapi` over HTTP is `downstream_timeout`; true `db_timeout` only for
  JDBC-inside.
- **Weak-labeler as baseline:** the rule-based `TaxonomyClassifier` pre-tags every case,
  so building the golden set is a *review* task, not blind labeling — and the weak label
  doubles as the deterministic baseline the LLM must beat.
- **Golden set:** 58 distinct, sanitized, env-tagged, human-reviewed cases (41 prod / 17
  repo). `error_type` is withheld from the LLM's classification input (it would trivialize
  the task, and the target system won't emit Mule error types).

## 5. The eval harness (the core deliverable)
- **Three eval types:**
  1. **Classification** — accuracy, per-category precision/recall, confusion matrix, vs
     the rule baseline. `triage:eval --source=db --only-reviewed --prompt=vN`.
  2. **Extraction** — per-field accuracy (http_method/status, target_entity, error_type,
     operation, constraint) vs a regex parser. `triage:eval-extract`.
  3. **Summary** — LLM writes a one-sentence incident summary; an **LLM-as-judge** scores
     faithfulness + completeness (1–5). `triage:eval-summary`.
- **Discipline:** temperature 0; structured output via Prism schemas; every run writes a
  timestamped JSON report; **prompt versioning (v1/v2/v3)** for regression diffs; the
  golden set is the regression target.
- **judge-the-judge (done, not deferred):** the summary judge shares the model with the
  summarizer (self-eval bias), so I hand-scored 15 summaries **blind** (`triage:judge-review`,
  scores hidden to kill anchoring) and computed agreement — exact %, within-1 %, mean bias,
  and quadratic-weighted Cohen's κ per axis. Result: **faithfulness usable** (within-1 100%,
  κ 0.57, bias −0.07), **completeness not** (κ 0.10). So the judge is trustworthy on the
  objective axis and not the subjective one — and I only cite the validated number.

## 6. Findings (with numbers — the heart)
- **Classification: rules 89.7% ≥ LLM 87.9%** (58 reviewed, Doctrine-A gold, gemma3:12b).
  The LLM adds nothing over regex here.
- **Prompt engineering didn't move the needle:** v1 = v2 = v3 = 89.7%. The **+8.7-point
  jump (81%→89.7%) came from getting the labeling *doctrine* coherent**, not from prompts.
- **The harness caught a bug in *itself*:** `--prompt=v2` only relabeled the report; the
  runner never passed the version to the classifier, so it silently re-ran v1. Tell:
  **identical token counts** across the "different" runs. Fixed by threading the version
  through.
- **Extraction — a fair baseline erased the LLM's apparent edge:** with a *shallow* regex
  the LLM looked far better (it dug into details); with a **details-aware** regex, LLM ↔
  regex agree **http_method 96.5%, http_status 94.8%, constraint 93.1%, operation 89.7%**;
  `error_type` 62% / `target_entity` 83% diverge for *definitional* reasons (which of
  several chain codes is "the" type), not clear LLM wins. **This corrected my own earlier
  over-claim** that "extraction is the LLM's territory."
- **Summary — where the LLM wins:** mean **faithfulness 4.62/5** (58 cases), and that judge
  was **human-validated** (within-1 100%, κ 0.57 — moderate but usable). Produces faithful
  root-cause + cascade narratives regex can't write. (Completeness scored 4.26/5, but the
  judge failed its own validation there — κ 0.10 — so I don't cite it as a real number.)
- **LLM-as-judge is not free ground truth:** validating the judge is itself an eval. It
  passed on faithfulness and *failed* on completeness — the more subjective the axis, the
  less a same-model judge can be trusted. Fixes: refine the completeness rubric, score more
  cases (n=15 + clustered scores depresses κ — the "κ paradox"), or use a *different* model
  as judge to break self-eval bias.
- **Correlation data:** 3,996 events, 1,163 transactions, **614 cross-app chains**.
- **Cost/perf:** $0 (local); ~**8–10 s/case**, ~10 min/58-case run; ~**16.5 GB RAM**
  (gemma3:12b resident).

## 7. The verdict / resulting architecture
- **Classify + structured-extract with deterministic rules/parser** (no AI). **Use the
  LLM only for the plain-English summary/"Explain incident"** — the one place it's better.
- The LLM classifier/extractor are **kept, but only inside the eval harness** as the
  measured contender — so a stronger model (Claude, 27B) can be re-benchmarked with a
  2-value config change. Not in the live path.
- "**Cheap rules to sort, AI to explain.**"

## 8. The app (Filament) — features
- **Ingest Logs:** upload "Download Logs" → parse + sanitize + import → discard raw.
- **Correlations:** every event grouped by correlation id into cross-app chains;
  "cross-app only" + recency filters; bidirectional links to the golden set;
  **"Explain incident"** = on-demand chain-level AI summary.
- **Golden Cases:** the reviewed answer key; review UI (prev/next queue, per-file env
  fix + propagate, guarded delete).
- **Eval Runs:** scored-run history; **confusion-matrix heatmap** + per-category table.
- **Commands:** run evals with **live-streamed output** (SSE).
- **Dashboard:** eval-score stats + correlations summary + recent-failures table.

## 9. Operational gotchas (the "other devs will nod" section)
- **Laravel Herd on PATH:** `php` resolved to Herd's binary; its scan-dir `php.ini`
  overrode `-d`/`-c`/PHPRC, pinning uploads at 2 MB. Fix = edit Herd's `php.ini`
  (`upload_max_filesize`/`post_max_size` 2M→350M).
- **`php artisan serve`** spawns a separate `php -S` that **doesn't inherit `-d`** flags;
  single worker **blocks** a live SSE stream → run with `PHP_CLI_SERVER_WORKERS`.
- **Livewire temp-upload cap** defaults to 12 MB → raised to 300 MB via
  `config('livewire.temporary_file_upload.rules')` in a provider.
- **Filament v5 specifics:** precompiled CSS ignores arbitrary Tailwind classes in
  injected HTML → **inline styles**; entry point is the **facade** `Prism\Prism\Facades\
  Prism`; `DeleteAction` doesn't resolve the record from the form footer → custom action
  with an explicit delete closure; a multi-placeholder read-only form **fragmented under
  Livewire DOM-morph** → collapse to a single HTML block; **form-footer actions aren't
  reachable via `callAction()`** in tests; filter-from-a-link needs a **`#[Url]`** public
  property + `modifyQueryUsing`, since Filament doesn't hydrate `tableSearch` from the URL;
  per-page `maxContentWidth`.
- **A latent cache bug the tests caught:** the golden↔event match used a function-`static`
  cache that leaked across requests (and would go stale under Octane) → moved to a
  request-scoped map.
- **Anypoint retains logs 30 days** → env of older cases is unrecoverable; forensic env
  discovery fails for anything older.
- **File↔DB divergence:** reviews live in the DB; never full-`triage:import` (it clobbers
  reviews) — reports-only import for the UI.

## 10. Lessons (the takeaways)
1. **Build the eval harness first / measure before you AI.** Its job is to decide *where*
   the LLM belongs — and here the answer was "almost nowhere."
2. **Labeling-doctrine coherence beats prompt engineering.** The whole gain was doctrine;
   v1=v2=v3.
3. **A fair baseline is everything.** A weak regex made the LLM look good; a strong one
   erased the edge. Strengthen the baseline before you conclude the LLM wins.
4. **The harness will catch the builder's mistakes** — it caught a silent prompt-wiring
   bug (via token counts) and my premature extraction claim.
5. **Root cause hides behind generic wrappers.** Stitching correlated details
   (de-aliasing) is where classification quality comes from — and it's deterministic.
6. **The LLM's niche is synthesis** (readable summaries), not sorting/extraction.
7. **An LLM judge needs its own eval.** Blind-scoring 15 by hand showed the judge is
   trustworthy for faithfulness (κ 0.57) but not completeness (κ 0.10) — so only the
   validated number gets quoted. A same-model judge is a hypothesis, not a measurement.
8. **Local-first (Ollama + Prism)** = free, private, provider-swappable, honest about cost.
9. **Golden set = migration-acceptance asset**, not throwaway.

## 11. What's next
- **Anypoint connector** — Connected App → Runtime Manager logs API → continuous
  ingestion + complete chains (removes manual downloads; enables live debugging).
- **Promote the summarizer** further / benchmark a stronger model on summaries.
- **Re-benchmark** classification/extraction against Claude or a 27B to confirm the
  rules-win holds (2-value config change).

## 12. Appendix
- **Commands:** `triage:parse`, `triage:golden`, `triage:review`, `triage:import`,
  `triage:import-events`, `triage:enrich-details`, `triage:eval`, `triage:eval-extract`,
  `triage:eval-summary`.
- **Prompts (versioned Blade):** `classify-v{1,2,3}`, `extract-v1`, `summarize-v1`,
  `judge-v1`, `explain-chain-v1`.
- **See also:** `FINDINGS.md` (results), `WALKTHROUGH.md` (non-technical demo script),
  `README.md` (run instructions + LLM modes).
