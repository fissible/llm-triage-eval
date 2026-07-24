# Integration failure triage — findings

A **failure taxonomy and triage tool for the integration layer**, with a rigorous
**eval harness** at its core. Seeded with real MuleSoft (CloudHub) production
failures, but the taxonomy is defined at the integration-layer level so it carries
forward if the same functionality later moves to another platform. The labeled
failure set doubles as a **migration-acceptance asset**.

Everything below is **measured**, not asserted — that's the point of the harness.

> **Provenance.** The numbers below come from a **private production dataset**
> (~4,000 failures, 58 human-reviewed cases). They are reported here for the
> record; this public repo ships only a handful of **synthetic** examples, so
> re-run the pipeline on your own logs to reproduce them.

---

## Headline conclusions

1. **Use cheap deterministic rules to *classify* failures — not the LLM.**
   On 58 human-reviewed real failures, a rule-based classifier scored **89.7%** vs
   **87.9%** for a local 12B LLM (`gemma3:12b`). For classification on this taxonomy,
   the LLM adds nothing over regex.

2. **A details-aware regex parser also handles *structured* extraction — the LLM's
   edge there is unproven.** With a shallow parser the LLM looked far better (it dug
   into the correlated details). But once the parser also reads the details, LLM and
   regex agree **90–97%** on http_method/status, constraint, and operation; the two
   lower fields (`error_type` 62%, `target_entity` 83%) diverge mostly for
   *definitional* reasons — which of several codes counts as "the" error type — not
   clear LLM wins.

3. **The LLM's real value is the human-readable *summary*.** It writes faithful
   one-sentence incident descriptions — root cause *and* cascade in plain English,
   which regex fundamentally cannot do. Judge-scored **4.62/5 faithfulness**, and that
   judge was **human-validated** (within-1 100%, κ 0.57). (Completeness scored 4.26/5,
   but the judge proved unreliable there — κ 0.10 — so that figure is unvalidated.)

4. **Correlation IDs turn a pile of logs into traceable incidents.** 3,996 parsed
   error events across 8 apps collapse into **1,163 transactions**, of which **614
   span more than one app** — traceable end-to-end to a root cause.

5. **It runs local at ~$0.** No API keys, sensitive logs never leave the machine
   (`gemma3:12b` on Ollama); a full 58-case eval is ~10 minutes.

**Net recommendation:** use the deterministic pipeline (parse + rules) to **classify
and extract** — the LLM isn't needed there — and reserve the LLM for the one thing it
does better than rules: the **human-readable summary** that turns a raw cascade into a
sentence an engineer can act on. Cheap rules to sort, AI to explain. Keep the labeled
set as the migration regression suite regardless.

---

## What was measured, and how

- **Golden set:** ~4,000 parsed prod + repo failures dedupe to **58 distinct failure
  signatures** — a striking finding in itself (e.g. 675 DataWeave errors are ~1 bug).
  Each is sanitized (PII scrubbed) and human-labeled.
- **Taxonomy is root-cause based, not surface error-code based.** A generic
  `INVENTORY-SAPI:INSERT` whose detail is a PostgreSQL `duplicate key` violation is a
  `db_constraint_violation`. This "Doctrine A" (classify by the deepest root cause
  visible in the correlated details) is the labeling standard.
- **Correlated-detail enrichment** was decisive: stitching the WARN-level JSON detail
  onto the ERROR block de-aliased generic buckets — `composite_routing` dropped
  287→85 and the headline `db_timeout` failures went 0→60 once visible.
- **Two eval types:** classification (accuracy + per-category confusion matrix,
  vs a rule baseline) and extraction (per-field accuracy). Every run writes a
  timestamped JSON report; the golden set is the regression target.

---

## Classification result (v3 prompt, Doctrine-A gold, gemma3:12b)

| | Accuracy (58 reviewed cases) |
|---|---|
| Rule-based classifier | **89.7%** |
| LLM (`gemma3:12b`) | 87.9% |

Prompt iteration (v1→v2→v3) made **no** net difference — the win came entirely from
getting the *labeling doctrine* coherent, not from prompt engineering. Remaining
misses are genuinely ambiguous (e.g. "DB timeout vs. downstream timeout" is often
undecidable from a single log line).

## Extraction result (details-aware gold, per field)

LLM field extraction vs. a **details-aware regex parser**, 58 cases:

| Field | LLM ↔ regex agreement |
|---|---|
| http_method | 96.5% |
| http_status | 94.8% |
| constraint | 93.1% |
| operation | 89.7% |
| target_entity | 82.8% |
| error_type | 62.1% |

High agreement means the cheap regex produces the same field, so the LLM isn't
needed there. The two lower fields diverge mostly for *definitional* reasons
(which of several chain codes is "the" error type; entity granularity), not clear
LLM accuracy wins. **Takeaway: a details-aware parser is sufficient for structured
extraction; the LLM's edge is on interpretive summaries — measured next.**

## Summary synthesis result (LLM writes it, LLM judge scores it)

The LLM generated a one-sentence incident summary for each failure; a separate LLM
judge scored each on **faithfulness** (grounded in the evidence, nothing invented)
and **completeness** (captures operation + entity + root cause), 1–5. Over 58 cases:

| Metric | Score |
|---|---|
| Mean faithfulness | **4.62 / 5** |
| Mean completeness | **4.26 / 5** |

Example (gs-0002): *"orders-papi failed to insert an inventory record via
INVENTORY-SAPI because of a duplicate key on the 'sku' column, surfaced only behind
a generic COMPOSITE_ROUTING wrapper."* — a faithful, readable root-cause + cascade
description that regex fundamentally cannot produce. **This is where the LLM earns
its keep.**

**Judge-the-judge (15 blind human scores vs. the LLM judge):**

| Axis | Within-1 | Exact | Bias (judge−human) | Quadratic-weighted κ | Verdict |
|---|---|---|---|---|---|
| faithfulness | **100%** | 80% | −0.07 | 0.57 | moderate — usable |
| completeness | 80% | 40% | +0.13 | **0.10** | weak — do not trust |

So the LLM judge is **reliable for faithfulness** (the axis that matters most —
"did it invent facts?": never off by more than a point, essentially unbiased), so the
**4.62 faithfulness stands with moderate confidence**. It is **not reliable for
completeness** (κ 0.10) — that's a more subjective axis where the human and judge
disagree, compounded by the κ paradox (n=15 with scores clustered at 4–5 depresses κ).
**Treat the 4.26 completeness as unvalidated.** Takeaway: LLM-as-judge worked for the
objective axis, not the subjective one — which is itself a finding about where to trust
an AI grader.

---

## A concrete operational finding

A large share of production errors concentrated on the **primary write path** (the
busiest create/update flow), dominated by **legacy-database timeouts** and 500
cascades. Separately, one class of "updates silently don't come through" bug **does
not appear in the logs at all** — a *silent* failure (swallowed at an
`on-error-continue`). That's an important boundary: log-based triage catches loud
failures; silent data-integrity drift needs a dead-letter queue or a reconciliation
check.

---

## The tool (what to demo)

A Laravel + [Filament](https://filamentphp.com) app (`/admin`):

- **Ingest Logs** — drop CloudHub "Download Logs" files; parsed, sanitized, imported, discarded.
- **Correlations** — every event grouped into cross-app transaction chains; filter to
  cross-app incidents; jump to/from the golden set.
- **Golden Cases** — the labeled eval set: review labels, prev/next, per-file env fixes.
- **Eval Runs** — scored runs over time (accuracy, baseline, tokens, cost).
- **Commands** — run evals with **live-streamed** output.

---

## Limitations & next steps

- **Anypoint connector** (planned): pull logs directly (Connected App → Runtime
  Manager API) for continuous ingestion + complete chains. Removes manual downloads.
  Note: CloudHub retains logs 30 days, so historical env is sometimes unrecoverable.
- **Summary eval (LLM-as-judge)** — the third eval type, for human-readable incident
  summaries, once extraction is locked.
- **Model choice** — everything is provider-agnostic (Prism); benchmarking Claude or
  a larger model on *extraction* (where the LLM matters) is a two-value config change.
- **Rare categories** (`downstream_client_error`, `malformed_request`) have 1–3
  examples — genuinely rare in the data; per-category metrics for them are low-signal.
