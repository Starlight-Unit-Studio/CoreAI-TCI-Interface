# CoreAI Agent Task Control

## Goal

One runner-owned decision contract for long-running work, independent of the model's language, size, or provider. Natural-language text is user-visible information, not a durable task status.

## State and actions

- **Action:** an existing, allowlist-validated tool invocation. The dispatcher enforces user, session, project scope and tool permissions.
- **Done:** `COREUI_TASK_DONE` around a factual user-facing summary, only after verified tool evidence and cleared edit failures. A model assertion alone does not prove all original requirements.
- **Blocked:** `COREUI_TASK_BLOCKED` when the model cannot proceed, retained as an interrupted run.
- **Invalid decision:** a plain explanation, an incomplete marker or multiple inconsistent decisions. Ask once for a strictly formatted next decision. If unresolved, keep prior files and mark interrupted.
- **Provider failure:** use existing transport/done_reason classification; do not replay file tools or make endless paid requests.
- **Cancelled:** stop before any further tool dispatch and leave verified data intact.

## Invariants

- No tool from prose-only guessing or model self-reported success.
- No project-wide arbitrary step count or wall clock limit while real progress continues.
- No extra inference on honest, verified terminal responses.
- An interruption message can be saved without converting the run to completed.
- Tool evidence is server-authored; keep errors and file versions durable.
- Workflow metadata contains only safe, bounded counters and statuses.

## Scope and next design stage

The 0.8.13 contract is enforced after real tool steps in the project-capable agent loop used by normal CORE CHANNEL and project chat. The plain question/answer path stays lightweight. Existing plan generator, native project builder, browser and deep-research worker have separate controllers.

For true end-to-end autonomy, implement an owner-scoped durable goal manifest and checkpoints for the unified runner. Record required deliverables, expected dependencies, verified artifacts, validation results and outstanding obligations, and derive `completed` from those records rather than model prose. Add safe, idempotent worker reconnection/resumption and adapt the plan, web, browser, sandbox and media workers through the same task interface. Do not add heuristic regexes for each new model response.
