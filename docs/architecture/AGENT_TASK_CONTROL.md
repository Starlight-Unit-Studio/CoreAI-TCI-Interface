# CoreAI Agent Task Control

## Goal

One server-owned task contract across models, languages and long-running workflows. A model's public prose is not a task lifecycle state and never grants authorization.

## Protocol (0.8.13)

After each confirmed project-capable tool result the model must supply exactly one allowed tool, an explicit [COREUI_TASK_DONE] block or an explicit [COREUI_TASK_BLOCKED] block. A malformed decision receives at most one model-only format repair, without repeating side effects. Tool allowlists and owner/session/project permissions remain in the existing secure dispatchers.

## Durable journal (0.8.14)

SQL migration 057 creates:

- `stu_console_task_journal`: owned run and source turn, current phase/status, verified step, checkpoint revision, resume fence, explicit file requirements and safe evidence summary.
- `stu_console_task_actions`: unique per-run step claim, tool type, SHA-256 identity (no raw input), state and server-authored result receipt (without raw output).

CoreAI claims a project tool before executing it. An existing claim cannot execute again, even if the first worker vanished before storing its result. After a real tool result, the receipt and checkpoint are committed together. Current file hash/revision is verified by existing account-scoped project stores.

### Resuming a run

A response-less, interrupted, authenticated ordinary/project CORE CHANNEL run may be offered to the existing client reconnection route. A new worker must atomically claim the interrupted run **and** an unused verified checkpoint. A previous claimed/uncertain tool step, a cancellation, a completed run or a used checkpoint prevents automatic resume. The persisted original user request is authoritative; the resumed Core receives safe step receipts and reads fresh project files through its existing tool permission system. A fresh successful step creates the next checkpoint. The same checkpoint can never trigger an infinite chain of paid model retries.

### Completion evidence

Explicitly named user files in the original turn are recorded as structural requirements. DONE requires saved, owner-scoped file hashes for those names as well as confirmed tool steps and no unresolved tool claims. This does not yet prove that a feature works merely because the file exists. A full goal/dependency validator is still required for code, tests, responsive UI, server HTTP checks, exports and other user requirements.

### User interface

Both live response and chat history can include the server-owned task status, latest verified step and checkpoint revision. Existing chat messages and work-event logs stay intact; one stalled worker is never converted into a fake successful answer.

## Required invariants

- No file/command/browse invocation inferred from language alone.
- No duplicate execution after confirmed or uncertain side effects.
- No arbitrary total project step count or wall-clock cap.
- No extra inferences on verified completed work.
- Provider/local Core and per-user API settings, serial model queue and CPU-only defaults remain untouched.
- No raw prompts, code, credentials, stdout, private thinking or untrusted free-form snippets in task metadata.
- Run cancellations remain effective and all saved project revisions remain intact.

## Remaining scope of Issue #374

Unify the native plan/build, browser/Playwright, Deep Research, web research, image/media and other asynchronous worker adapters with the same journal, leases, outcome verification and UI state. Build a first-class server-owned goal/dependency manifest with separate verified obligations. Test live worker crashes at the most dangerous points (during and immediately after writes, during API inference, before SSE acknowledgement). The issue remains open until the entire system meets these contracts.
