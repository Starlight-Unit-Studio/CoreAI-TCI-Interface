# Project STΛRLIɢHT: E.M.B.E.R. CoreUI
[![DOI](https://zenodo.org/badge/1349352973.svg)](https://doi.org/10.5281/zenodo.22201310)

**E.M.B.E.R. CoreUI** is an independent local AI WebUI and control environment by **Starlight Unit Studios**, designed for local **Gemma 4 models via Ollama** and the E.M.B.E.R. cognitive architecture.

It combines private conversations and sessions, user-scoped RAG-Lite, local long-term memory, Playwright-based browser research with SearXNG, isolated Python execution, multimodal file handling, deterministic exact integer calculations, and strict separation of internal model analysis from visible answers.

E.M.B.E.R. CoreUI runs on its own containerized Nginx, PHP-FPM, MariaDB, SearXNG, browser, and Python-worker stack and remains isolated from STΛRLIɢHT The Game and other Starlight Unit systems.

Current source version: `0.5.36` (numeric only). The last verified ZIP remains archived under its original historical name.

## Skills API und Chat-Navigation (0.5.36)

The authenticated Skills API is now explicitly exposed by both production Nginx allowlists. An HTTP 404 at `api/user_skills.php` was previously reported as a vague login error. Per-account opt-in, CSRF rules and migration 014 remain unchanged.

When returning to the chat from Settings while a model is still working, CoreUI reads the existing server-owned run and safe work timeline through the authenticated message-history endpoint. The browser restores the waiting UI and polls for the original answer without launching another inference. Short message posts also use `fetch keepalive` to avoid being cancelled by immediate navigation. The serialized model queue remains untouched. See `docs/UEBERGABEPROTOKOLL_0_5_36.txt` for live acceptance.

## Aktives Mara-Modelfile (0.5.35)

The owner-requested change now affects the **active** `config/Modelfile.template`, not just a reference document. Its multi-line Mara SYSTEM content and four sampling parameters, including their order, were transferred from the supplied `ollama show --modelfile` export. Only Ollama export-specific details are normalized: a portable `FROM gemma4:e4b` placeholder compatible with the existing installer (instead of absolute E2B blob paths) and `SYSTEM """..."""` for reliable multiline parsing. The `DRAFT` blob path shown by Ollama is not copied into the portable template; `draft_num_predict 0` remains. **No modifications to the installer or update sequence.**

## Protected owner Modelfile (0.5.34)

The owner-provided E2B `ollama show` export is preserved in [docs/references/MODELFILE_E2B_USER_EXPORT_2026_10_25.txt](docs/references/MODELFILE_E2B_USER_EXPORT_2026_10_25.txt) as an immutable model-specific reference, not a replacement for the active E4B `FROM` directive. The current `config/Modelfile.template` and the complete installation/update flow are unchanged. See [docs/MODELFILE_CHANGE_POLICY.md](docs/MODELFILE_CHANGE_POLICY.md): keep the model prompt compact, use skills and backend tools as the flexible layer, and allow minimal necessary adjustments only within the tool-protocol section.

## Skills (0.5.33)

32 eigene Arbeitsanleitungen mit Quellen, Suchfunktion und eigenen Schaltern unter Einstellungen → MEINE SKILLS. Migration 014 einspielen, Skills auswählen und speichern. Pro Anfrage werden bis zu vier passende aktivierte Pakete geladen; `/skill kennung` priorisiert ein Paket. Siehe [Skills-Dokumentation](docs/COREUI_SKILLS.md) für Grenzen, Quellen und Live-Abnahme. Der universelle Projekt-Agent bleibt separat in Draft PR #69.

## Frontend preflight regression assertion repair (0.5.32)

This is a **test-only stability hotfix**, not a new file-generation route or an optimization. The frontend regression suite still expected `coreui_console_multifile_source_directive($userMsg)`, but 0.5.31 intentionally changed the private local Gemma route to the already validated `$sourceProjectRequest`, retaining `$userMsg` as the original user message. The runtime code was correct; the stale exact-source-string assertion caused the deploy preflight to fail even when its other 14 route conditions passed.

The refreshed assertion checks the actual normalized multi-source call **and** the device-local Gemma guard. Existing authenticated download checks, Cloud routing, ZIP writer, worker, Modelfile and model parameters are unchanged. No database migration, installer behavior change or activation of the separate universal-project pilot. Draft PR #69 remains disabled and unmerged. The new selftest must pass in the normal server preflight before a successful live update can be claimed.

## Homepage ZIP routing for device-local Gemma (0.5.31)

Version 0.5.30 established the exact failure condition `ollama_truncated_py_open` after 3,005 local generation tokens: the user's **unnamed multi-source anime homepage ZIP request** was not recognized as an existing multi-file project and fell into the older huge model-generated [PY] file writer, which was truncated. The screenshot also shows two answer **versions** (`1/2`, `2/2`) of the same user turn, not two different user prompts.

0.5.31 fixes that **routing gap**, without introducing a new file creation architecture. Only for a **device-local Gemma** ZIP homepage request with explicit download/creation intent, no named source files and no single-file instruction, CoreUI internally adds the conservative canonical source set `index.html`, `style.css`, `main.js`. It then uses the already-established local **one-source-per-Ollama-call** path with bounded validations and the CoreUI-owned deterministic ZIP writer. The original request remains otherwise unchanged. Named projects, standalone HTML files, unrelated archives, local Qwen, Ollama Cloud models (`gemma4:31b-cloud` included) and ordinary Python tasks retain their existing routes. All Ollama models remain selected via the locally configured Ollama instance, not a separate API integration.

The original request and negative routing cases have been added to the existing PHP regression suite; a focused PHP 8.4 reproduction verified the three inferred filenames, single-file preservation and unrelated archives. The next server run must confirm the actual three source requests, verified ZIP contents and working homepage in Android Chrome. An excessive or unclosed HTML/CSS/JS output may still fail its existing bounded validation; this release does not bypass those checks or promise full application quality. There is no Gemma Modelfile change, `num_ctx`, `num_predict`, database migration, change to global serial processing, or installer one-liner.

## Ollama file-completion diagnostics (0.5.30)

This is a narrow diagnostic and safety patch over the 0.5.29 rollback, not a new file-generation architecture. Existing model timeline entries now carry a safe Ollama completion label such as `ollama_stop_py_closed` or `ollama_truncated_py_open`, returned content size, `eval_count`, and `prompt_eval_count`. Raw prompts, Python, stderr, and private reasoning are never included.

For device-local Gemma 4 (`gemma4:e4b` and `gemma4:26b`), responses reported as truncated by Ollama or containing an unclosed [PY] marker cannot reach the Python worker. The existing single protocol retry remains; the post-worker recovery reply receives the same guard. `gemma4:31b-cloud` is still selected from local Ollama's model list and routed through that very same local Ollama endpoint. Cloud and Qwen workflows are not rerouted.

This does not guarantee that syntactically incorrect but fully marked Python is fixed. A controlled live retest should compare local Gemma to `gemma4:31b-cloud` and inspect the new completion label in the existing work timeline. There are no Gemma Modelfile changes, no `num_ctx` or `num_predict` overrides, no worker changes, no database migration, and no new installer command. Isolated local PHP validation passed; a full productive server acceptance remains open.

## Stability rollback to the pre-optimization 0.5.25 source (0.5.29)

**0.5.29 is a rollback, not a feature release.** Every operational source file is restored from the 0.5.25 commit `53dab3f6d78e0cd626824aa3ca883f441a7f193f`, the last version before the three unsuccessful post-0.5.25 changes. The rollback removes the HTML/CSS paired local Gemma generation (0.5.26), the single-homepage source-first special handling (0.5.27), and the generic Python syntax-check/repair experiment (0.5.28). The earlier 0.5.25 routing, Python worker, Qwen tool path, approved Gemma Modelfile and other product behavior remain unchanged.

Only the numeric version, six visible page version labels, content-based asset cache fingerprint, release documents and preflight references to the new release documents differ from the original 0.5.25 baseline. This uses a higher version label so the required version check remains monotonic. It does not reintroduce any of the removed optimizations. There are no new migrations, no modifications to the existing update one-liner or DB model and no new Python helpers. The developer updater excludes local `api/config.local.php`, `var/`, `uploads/` and `logs/` to preserve persisted data. Confirm the installed server version after running the existing update procedure; GitHub merging alone is not a live deployment.

## E.M.B.E.R. acronym and official terminal branding (0.5.25)

**E.M.B.E.R.** names the framework, backend and system. Its standalone terminal is **E.M.B.E.R. CoreUI**, and **Mara Thorne** is the TCI/CoreAI of the *Ravager Thorn*. **Ember**, by contrast, is a separate CoreAI of the *Starlight Ember*, linked to the later Sandra-engram storyline. These entities are deliberately not interchangeable.

In releases through `0.5.24`, the product display name `Ember CoreUI` was used incorrectly. From `0.5.25` onward, all current installer messages, user-facing product labels, metadata and branding policy use the correct dotted spelling. The former spelling is retained only as a historical project alias where needed to interpret old releases and license records. Existing technical identifiers such as `ember-coreui:latest`, `/opt/ember-coreui`, environment variables and database IDs are unchanged. No software logic or lore canon outside this naming clarification is altered.

## Mara Thorne CoreAI identity and Qwen compatibility (0.5.24)

The local Gemma Modelfile now uses Mara's more relaxed, spontaneous 2000s gamer-style voice inspired by the native image response style; the separate vision prompt also uses Mara and no longer claims a human biography. Technical file IDs, database tables, endpoints and environment variable names remain untouched to preserve installed data. The user's existing custom assistant display names continue to work, with the old default Ember displayed as Mara.

Gemma's `num_ctx` prohibition is retained: no Modelfile line and no Gemma request override. The accidentally retained `PARAMETER num_predict -1` Modelfile line is deleted as requested; `PARAMETER draft_num_predict 0` remains. Other approved sampling parameters stay as before.

Historical local Qwen coding models such as `qwen3.6:35b-a3e-q4_K_M` are explicitly routed back through the established isolated `[PY]` sandbox file-writing workflow, instead of the strict source-first multi-file ZIP path introduced later. Gemma's special local recovery and both working Cloud file routes remain separate. This restores the earlier *routing approach*, not a proven live performance result. It should be checked against the original Snake ZIP example after update.

## Bounded local JavaScript continuation (0.5.23)

The production test of 0.5.22 narrowed the failure to local `game.js`: HTML and CSS were accepted, but both JavaScript attempts terminated with `model_output_truncated`. This is an Ollama output-limit signal, not a proven source-parser defect. The browser ZIP pipeline and Cloud-provider exports remain unaffected.

When a local Gemma JavaScript source is stopped by the output limit, CoreUI retains the inert generated source **within the same private turn**, strips only an optional outer JavaScript fence, and requests up to **two** short source continuations. Each continuation receives the task and only a bounded exact suffix of existing JavaScript instead of the entire preceding source. Repeated overlaps are removed, full restarts with no progress and unsafe/tool-marker replies are rejected, and the complete concatenation must still satisfy the existing source validator before the ordinary sandbox writer can create any files. Failed or cancelled repairs never fabricate download cards. Non-JS files, Cloud-model exports, model defaults and the owner-approved Modelfile remain unchanged.

The local JavaScript source prompt no longer attaches the full CSS file, retaining the HTML DOM reference while reducing prompt length. Work events now expose only safe `source_chars`, `eval_count` and `prompt_eval_count` counts, never raw prompts, code or private data. The source size budget and two-continuation limit bound work. No migration, model-parameter change, or installer command change. This release needs a live Gemma verification to establish whether it resolves the original Snake request. It does not certify gameplay or JavaScript syntax without browser verification.

## Focused local Gemma ZIP output (0.5.22)

Production screenshots of 0.5.21 showed local Gemma repeatedly returning responses that the strict multi-source Markdown parser could not complete (`source_files_incomplete`). The same Snake ZIP request succeeded on GPT Cloud, proving that the Python sandbox, archive creation and authenticated download cards are operational.

For local `ember-coreui` / `gemma4` models and explicitly requested multi-file ZIPs, the pipeline now requests exactly one source file at a time in a short, independent Ollama message. These calls do NOT append the conversation's general Python tool hint, and the model is asked for raw HTML, CSS, JavaScript or another requested source type, not a `[PY]` writer or Markdown wrapper. Full raw sources, correctly fenced files, and one incomplete enclosing fence with a complete source body can be accepted. Invalid formats and incomplete HTML/CSS/JS are rejected. Each file receives at most one focused retry. The trusted server-owned Python writer then packages the complete source set in the existing isolated sandbox, unchanged.

Cloud models retain their already working 0.5.21 multi-file route. Single-file and other Python tasks also remain unchanged. The original Gemma Modelfile and its owner-approved parameters are not edited. Private data, scripts and raw model outputs are not included in user-facing work events. Source files and reasons are identified by safe metadata to locate future failures without leaking content. No database migration, no change to the original installer command. Production verification of local generation and actual gameplay is pending.

## Source-first browser-game ZIP exports (0.5.21)

For explicit multi-file ZIP projects with named sources (or an unnamed Snake/browser-game ZIP), the model is asked to return complete HTML/CSS/JS source files in inert fenced blocks. Missing sources are requested one at a time, using a bounded sequence. CoreUI then prepares a deterministic Python writer and verifies each emitted file plus the ZIP in the existing sandbox. Model-authored [PY] scripts are not executed on this specific path; generic Python and single-file tasks keep their existing routes. Incomplete source collections fail without a fake download. Actual Ollama output, PHP/DB/worker integration, verified download cards and browser gameplay still need acceptance on the live host.

## File tool routing restored (0.5.19)

Live testing of 0.5.18 still stopped before the worker with `source_files_incomplete`. Multi-file ZIP requests now use the established Python sandbox tool contract again. Both marker-wrapped and fenced Python tool scripts are accepted. Complete named source blocks and bounded two-response source merging remain optional recovery paths. The source-only restriction introduced in 0.5.16 no longer blocks the existing tool protocol. Worker verification, account-scoped artifacts and serial model requests remain required.

The following sections describe historical changes; their source-only routing is superseded by 0.5.19. Production acceptance remains pending.

## Incremental multi-file source recovery (0.5.18)

When the first model response and the single bounded retry each contain only part of an explicitly requested multi-file ZIP project, Ember now combines the safe named source blocks from both responses before invoking the existing deterministic writer. The second response replaces an earlier version of the same requested filename. The bridge still starts no worker unless every requested source is present, and it never adds model-supplied files that the user did not request.

The parser accepts longer outer Markdown fences so Markdown files can safely contain their own triple-backtick examples. It rejects unclosed fences, duplicate files inside one response, unsafe paths, incomplete projects, oversized payloads and jobs that would exceed the ten-artifact limit. Filename matching is case-insensitive while emitted names retain the spelling from the user request. Source bytes remain inert and are written only by the server-owned Python wrapper.

The PHP 8.3 syntax checks and expanded offline file-tool regression pass locally. GitHub Actions were not run because the available Actions quota is exhausted. Production acceptance with local Gemma, Qwen and Cloud GPT, actual authenticated download cards, ZIP inspection and Chrome/Android gameplay remains a required live test after the merge.

## Multi-model file export repair (0.5.17)

Both Ollama Cloud/API models and local Ollama models now use the same verified server-side project source-to-artifact route when the user explicitly requests a ZIP containing two or more named files. Neither provider is trusted to invent server download URLs, ZIP manifests or Python file-writing scripts. The backend assembles actual source blocks, writes files in the existing isolated worker, verifies the ZIP, and only then registers account-scoped download cards.

A confirmed cross-provider failure was the PHP `json_encode()` default slash escaping for base64 payloads: Python received strings containing `\\/` and rejected them with `binascii.Error: Only base64 data is allowed`. The fixed bridge uses `JSON_UNESCAPED_SLASHES` to preserve the exact bytes. The previously stale frontend regression guard now matches the valid missing-file protocol in the backend. An offline PHP-to-Python reproduction created all three Snake source files and a verified `snake-game.zip`; production Ollama, artifact-DB and mobile-browser acceptance is still required.

## Reliable local multi-file ZIP creation (0.5.16)

For an explicit local-model request naming two or more separate source files **and** a ZIP download, Ember now requests **one complete fenced source block per file** instead of demanding that Gemma invent a Python program to generate all the files. The existing vetted project bridge converts the inert source blocks into exact file bytes and runs only its own deterministic file-writing/ZIP-validation script in the isolated Python worker. The user receives only actually confirmed download artifacts. Incomplete responses get at most one focused re-prompt; a failed deterministic worker or artifact registration never triggers another expensive model-generated Python repair attempt. Single-file and unrelated Python tasks retain their existing behavior.

This addresses the `SyntaxError` / exit-code-0-but-`output_missing` failure class seen during a local Snake ZIP request. It does not guarantee that model-generated game logic works: actual HTML/CSS/JS validation and browser gameplay still need a live check. It also does not establish the cause of unrelated Ollama CPU stalls after switching models.

## Owner-approved Gemma 4 Modelfile (0.5.20)

`config/Modelfile.template` now reproduces the owner's supplied Mara Thorne / TSA-Thorn Modelfile verbatim: `FROM gemma4:e4b`, `TEMPLATE {{ .Prompt }}`, `RENDERER gemma4`, `PARSER gemma4`, temperature 1, top_k 64, top_p 0.95, draft_num_predict 0 and num_predict -1. This supersedes the parameter-free template policy introduced in 0.5.15.

Both installers default to gemma4:e4b and rebuild the separate CoreUI model from this file. An explicit COREUI_BASE_MODEL setting still overrides only the FROM line. The installer no longer rejects PARAMETER directives. The existing model name and installer command remain unchanged.

For local `ember-coreui:*` and `gemma4:*`, both streaming and synchronous Ollama API calls omit the `options` object entirely, including on older installations where a preserved `api/config.local.php` still declares `STU_EMBER_NUM_CTX` or experimental tuning. Other models retain their separate, existing settings policy. The top-level `think` switch and the requested model/stream flags are **not** Modelfile `PARAMETER` directives.

The previous eight-second synthetic warmup is disabled for local Gemma: it could time out while loading a CPU model and contend with a real chat request. No automatic Ollama/service restart is introduced.

The base Gemma model can have its own inherited defaults, and `ollama show --modelfile` may display upstream settings not authored by CoreUI. Updating CoreUI does not alter the shared upstream model or prove that occasional Ollama stalls have stopped. The update includes CLI regression checks and separate Compose/Native preflights.

## PHP/MariaDB runtime validation (0.5.14)

The Docker-based installer verifies that the newly built PHP image actually loads `pdo_mysql` before starting services, then force-recreates the dedicated PHP and web containers using the validated image. Both runtime variants now check extensions via PHP's `extension_loaded()` rather than piping `php -m` through `grep -q`, which can report a false negative with `pipefail`. If the PDO driver is truly unavailable, deployment fails visibly, not silently. MariaDB data, accounts, uploads and configuration remain unchanged. Re-run the existing setup one-liner to apply the repaired installer; check the preflight on the server.

## Local file generation with Ollama models

Version 0.5.13 routes explicit file/download requests directly to the isolated Python tool path instead of spending a generic streaming inference first. The model receives a clear, executable `[PY]` file-tool contract and, if it produces no executable instruction, at most one shorter retry. For local `ember-coreui:*` and `gemma4:*` models, pure file-code calls default to `think:false` to avoid expensive analysis-only generations; define `STU_EMBER_LOCAL_FILE_THINK` as `true` in the server-side PHP configuration (for example `define('STU_EMBER_LOCAL_FILE_THINK', true);` in `api/config.local.php`) to opt in to reasoning for these calls. Other models keep their configured thinking behavior.

When a small model emits complete named HTML/CSS/JS code fences rather than a tool marker, the server can convert them into inert source files inside the existing isolated worker and build/verify a ZIP. Filename inference is limited to uniquely named files explicitly requested by the user. A complete project is never claimed when requested filenames or the requested ZIP are missing. Unconfirmed output remains an error, not a fabricated download. Real local-model performance and successful generation must be checked on the target server; this update does not guarantee any fixed latency.

## Persistent response and model timing

Version 0.5.12 restores the duration in the assistant message's information (ⓘ) panel. It displays **GESAMTDAUER** from the persisted server-side work event and, for newly measured model requests, **MODELLZEIT** separately. Both survive chat switches and reloads. Older 0.5.11 answers can show their previously recorded total time; model-only time is never guessed retroactively. When no saved work-event duration exists, a clearly labelled **AGENTENLAUFZEIT** is computed from the scoped, durable run timestamps. No new migration is needed.

## Persistent work timeline after E.M.B.E.R. replies

Version 0.5.11 keeps the public, server-observed operational work timeline attached to the assistant's completed message. The just-finished timeline stays expanded; older answers expose a clearly labelled **Arbeitsweg (N Schritte) anzeigen** control after reload. The browser combines authenticated saved events with a sanitized live SSE snapshot, including reconnect and polling fallbacks. The backend can recover events through the exact account/session/turn/run-key agent-run binding if the separate response-event association was interrupted. This is an operational audit trail, **not** private model chain-of-thought.

## Clickable conversation follow-ups

The Console can show optional tappable replies under an assistant response when E.M.B.E.R. explicitly ends with a closing question and a short bullet list. Buttons send the selected question through the normal authenticated chat workflow and remain available after reload. No HTML, URLs or model-generated code is executed. Mobile choices stack vertically.

## Native CPU image generation (planned)

The [Ember Image Engine plan](docs/COREUI_IMAGE_ENGINE_PLAN.md) adds a native C/C++ diffusion roadmap under P20. This version ships no image generation runtime or model weights. stable-diffusion.cpp, FLUX.1-schnell and SDXL-Turbo are candidates pending license, compatibility and measured CPU benchmarks. Future image jobs must share the global serial model queue.

## Development roadmap

The [implementation roadmap](ROADMAP.md) organizes the work into 29 delivery packages with dependencies, acceptance criteria, and a concrete first implementation sequence for generated file downloads.

The [E.M.B.E.R. CoreUI and E.M.B.E.R. master plan](docs/COREUI_MASTERPLAN.md) records the planned full agent, developer workspace, skill system, and independent implementation of the functionality inventoried from OpenClaw, Open WebUI, CrewAI, LangGraph, and Microsoft AutoGen. The [native image engine plan](docs/COREUI_IMAGE_ENGINE_PLAN.md) specifies a future CPU-only C/C++ diffusion adapter, shared model admission, explicit weight licensing and real benchmarks. No image generation runtime is claimed as shipped yet. The [feature matrix](docs/COREUI_FEATURE_MATRIX.md) tracks 82 stable requirements, reference coverage, existing foundations, open work, and acceptance criteria. The scope includes agent teams, stateful workflows, durable checkpoints, human review, result validation, and a visual workflow playground. These documents describe planned development, not features already shipped; the full reference inventory remains open.

Generated HTML, ZIP, and other output files must become real, persistent chat downloads. This remains an open, early implementation priority in [issue #29](https://github.com/Starlight-Unit-Studio/coreui/issues/29).

## Quick installation

**The same one-liner now installs and updates the latest merged `main` development source**, without a separate updater or GitHub Actions. It downloads the GitHub branch into a unique temporary directory, records the exact commit, and preserves local configuration, database data, accounts, sessions, uploads, logs, chat media, and profile images. The previous pinned, SHA-256-verified release ZIP remains available as an explicit opt-in via `COREUI_SETUP_SOURCE=release`.

```bash
( setup_file="$(mktemp)" && trap 'rm -f -- "$setup_file"' EXIT && curl -fsSL https://raw.githubusercontent.com/Starlight-Unit-Studio/coreui/main/setup.sh -o "$setup_file" && sudo bash "$setup_file" )
```

A fresh interactive installation asks for the administrator email address and password at the real terminal. The one-line SSH command stores the launcher in a protected, uniquely named temporary file and removes it on exit. The setup explicitly connects the account bootstrap to `/dev/tty`, while non-interactive Docker Compose calls run without a TTY requirement. The cloned checkout and its Git metadata stay in a unique temporary directory and are removed automatically; the installed tree excludes `.git`, `.github`, and bundled `releases`. For unattended first installation, provide `COREUI_ADMIN_EMAIL` and `COREUI_ADMIN_PASSWORD` as environment variables.

## Mandatory version increment for every merge

**Each PR merged into `main` MUST already include a numeric `VERSION` increment.** This applies to bugfixes, documentation, tests and any other changes, with no exceptions. Before merging, compare the PR's version against the existing `main` version using `python3 scripts/version-merge-check.py --base-version 0.5.17` (replace the base value with the one freshly read from `main`). The guard checks the increment, UI labels, selftests, and per-version changelog and handover. It requires no GitHub Actions. The complete rule is documented in [VERSIONING_POLICY.md](docs/VERSIONING_POLICY.md).

## Numeric product version and automatic browser cache

The canonical `VERSION` uses exactly `major.minor.patch` with numbers only, for example `0.5.13`. No words or suffixed states belong in the product version. The installer separately records the exact Git commit in `var/dev_source_commit` without changing the product version.

The same original one-liner calls `scripts/cache-bust.py` through the native or container installer. For each referenced CSS/JS file, the installer derives a fixed-length **numeric cache key from that file's actual SHA-256 content** and rewrites the static HTML URL. Unchanged assets keep the same key; changed assets get a different one even without a product version change. All HTML entry points are marked for revalidation at Nginx level, so browsers can discover new asset URLs. Preflight rejects stale or invalid keys.

**Historical releases are not renamed.** The original verified `0.5.3-alpha` archive is retained for legacy rollback only. `0.5.4` was planned but not published as a release. Versions `0.5.5` through `0.5.17` are preceding main-source checkpoints; `0.5.25` is the current development version. A new verified ZIP/SHA pair must be built separately before presenting it as an archived release.

## Independence and mandatory product name

From version `0.5.25`, the correct product name is **E.M.B.E.R. CoreUI**. The full designation is **STΛRLIɢHT: E.M.B.E.R. CoreUI by Starlight Unit Studios**. The standalone designation `CoreUI` is not used as a product name for this project.

E.M.B.E.R. CoreUI is independently developed. The project is not a fork of the separate admin template and UI component project at `coreui.io`, contains no dependency on it, and is neither affiliated with, sponsored by, nor endorsed by it. Technical compatibility names such as `coreui`, `COREUI_*`, `ember-coreui`, `/opt/ember-coreui`, repository components, and domain components remain purely machine identifiers.

## License and free community model

Starting with `0.4.2-alpha`, the project's own code is licensed under the **Starlight Unit Studios E.M.B.E.R. CoreUI Community Source License 1.0**. The legally authoritative German version is located in `LICENSE.de.md`; `LICENSE.md` is the English translation.

- private, community, and internal use remains free
- internal use by companies remains free with visible E.M.B.E.R. CoreUI branding
- no user limit and no license telemetry
- no paid licensing, white-label, donation, advertising, or support program for the official E.M.B.E.R. CoreUI project
- E.M.B.E.R. CoreUI may not be sold by third parties as a paid SaaS or managed service
- branding changes always require prior written case-by-case approval
- modified redistributions must retain source code, license, attribution notices, and branding

Further details are provided in `LICENSE_HISTORY.md`, `TRADEMARKS.md`, `COMMUNITY_POLICY.md`, `NOTICE.md`, and `COPYRIGHT.md`. The license is Source Available and is not OSI-certified. Third-party components retain their own terms.

## Existing file-output foundation and 0.5.13 local-model fix

Version 0.5.13 adds a dedicated file-tool path for local Ollama models, including a bounded missing-marker retry and verified ZIP assembly from named source fences. The existing foundation includes persistent generated file downloads and chat output cards, the new artifact database migration 011, the browser data-link correction, the original one-liner live-update flow, the Nginx permission repair, the scoped SSE selftest update, and the numeric cache/version repair. The 0.5.6 merge also adds compatibility for `gpt-oss:120b` cloud-generated files under the isolated `/mnt/data` output alias, with bounded recovery after failed Python file creation. Real HTML/ZIP downloads still need end-to-end live verification. The full worker-to-mobile download acceptance and new ZIP/SHA packaging remain pending. See [CHANGELOG.md](CHANGELOG.md), [the 0.5.9 entry](docs/changelogs-txt/CHANGELOG_0_5_9.txt) and [handover](docs/UEBERGABEPROTOKOLL_0_5_9.txt).

## 0.5.9: P05 agent runs, FIFO model admission and cooperative cancellation

The private SSE chat now creates a durable, account- and session-scoped agent run for each turn or explicit generation action (migration `013_console_agent_runs`). The run is claimed atomically, transitions through explicit states, and binds only to a real response belonging to the original turn. The authenticated chat history includes the run's safe state metadata. Reconnected requests do not silently repeat an uncertain provider call; such runs are marked interrupted for inspection.

Every Ollama HTTP call, including private streaming, synchronous follow-ups, warmup and reflection, now passes the same MariaDB named model lock. A bounded wait keeps the ordinary paths serial; no alternate file-lock lane can bypass the database lock. Migration 013 also adds a **durable FIFO admission queue** for Ollama calls, including nested requests that share one connection-bound model slot. Queued runs can be cancelled immediately, active private runs receive a cooperative stop request via the CSRF-protected chat action, and browser jobs can be reconciled to saved replies without replaying the model. A cloud provider may continue working after the HTTP transport ends; provider-side reconciliation, a persistent background scheduler, complete workflow resume, and provider capability adapters remain future steps. A live two-session concurrency and restart test is still required. No GitHub Actions were used.

## 0.5.8: Inline work events and file-output recovery

E.M.B.E.R. now displays responses as full-width chat content **without an assistant speech bubble**; only user messages are styled as bubbles. Work steps appear as a single, borderless, collapsible timeline **inside the same assistant message**, not as two separate rows/cards. Live SSE and saved history reuse the same visual component. The already authenticated `console_messages.php` response includes account- and answer-scoped work events, so a second per-answer API fetch is no longer necessary for the chat history. The protected standalone endpoint remains available for dedicated clients.

File generation is still serial and sandboxed. The Python worker now recovers bounded regular output files created under its isolated `/work` directory instead of the designated output directory, and it can recover a complete HTML document printed to stdout when no file exists. It rejects symlinks, scripts used as the execution source, unexpected file types, and oversized files before the existing durable, hash-verified artifact pipeline runs. An absent file despite exit code 0 triggers one bounded corrective model/tool pass with the prior code as context. A download is announced only when real bytes have been registered.

Offline Python output tests, frontend contracts, and mobile layout are added. A real `gpt-oss:120b` HTML/ZIP download on Android is **not yet claimed as verified**. Issue #29 remains open until that live check succeeds. The original install one-liner, account data, and numeric version policy are unchanged.

## P06: Real work activity (first operational slice)

In the private Console, the collapsible **ARBEITSSCHRITTE** view now presents actual, persisted server events for the current answer rather than invented thinking status lines. Real model starts/completions, Python job IDs, exit codes, recognized failure classes (such as `FileNotFoundError`), repair attempts, and registered downloads are displayed as separate steps. This includes cloud-provider use such as `gpt-oss:120b` through the existing Ollama adapter.

Events are stored in migration `012_console_work_events`, scoped to account, session, triggering turn, and the saved reply. New activity is delivered through SSE; a read-only authenticated API reloads it after reconnect or page refresh. Raw chain-of-thought, prompts, script sources, complete stdout/stderr and secrets are never sent to the browser. Technical diagnostics are deliberately restricted to safe metadata.

**Partial implementation only:** Full P05 run control, saved agent plans, multi-tool workflow replay and provider-dependent reasoning controls/display are still open in the roadmap. Issue #29 remains open until a real HTML/ZIP artifact downloads on Android Chrome. The prior one-liner and separate numeric product version rules are unchanged.

## Archived 0.5.3-alpha baseline

`0.5.3-alpha` aligns the production Gemma 4 + Ollama request path with the minimal known-good inference profile established during extended live CoreUI validation.

- Normal generation sends only `temperature=1.0`, `top_p=0.95`, and `top_k=64` inside Ollama `options` by default.
- `num_ctx`, `num_predict`, thread, repeat, seed, and stop controls are no longer injected into normal generation automatically.
- Synchronous and SSE generation share the same option-policy helper so the two paths cannot silently drift.
- Vision and truncated-continuation paths no longer force the old context or predict budgets.
- Legacy backend-specific tuning remains available only behind the explicit experimental `STU_EMBER_UNVERIFIED_TUNING_ENABLED` gate plus explicit configuration.
- There is no new database migration. Existing accounts, sessions, messages, revisions, uploads, RAG data, and local configuration remain unchanged.

The deterministic calculation fixes from `0.5.2-alpha` remain included:

- A prompt containing exactly one signed integer addition, subtraction, or multiplication can now be completed locally without waiting for Ollama or the Python worker.
- The calculation uses decimal string arithmetic instead of PHP integer conversion. Operands of up to 512 digits remain exact on both 32-bit and 64-bit hosts.
- The private SSE path still stores the result as a normal Ember message, keeps the original `reply_to_id`, and sends the regular completion event to the browser.
- The local path does not mark Ember as AFK and cannot leave a Python job or model request blocking the session.
- Exact calculation results do not start memory reflection or reputation analysis after storage, so no hidden follow-up model call can delay completion.
- Attachments, images, decimals, division, powers, chained expressions, multiple numeric constraints, and requests for an explanation remain in the normal model and tool path.
- A sentence-ending period or comma is accepted after the second operand. Decimal values, Unicode digits, hexadecimal literals, labeled ranges, and German or English explanation requests are rejected from the deterministic path, while unambiguous result wording remains local.
- A local calculation owns no Console-AFK marker and therefore cannot clear the state of a concurrent model or tool turn. The synchronous path also checks the deterministic result before requesting the global Ollama lock.
- The reply-pipeline self-test covers the reported `123456789 * 987654321` case, signed arithmetic, large values, sentence punctuation, ambiguous ranges, Unicode digits, hexadecimal literals, AFK ownership, the lock-free synchronous path, strict rejection boundaries, and SSE integration.

All persistence and conversation-flow corrections from `0.5.1-alpha` remain included:

- Ember and user messages now use a local Markdown renderer for headings, emphasis, lists, blockquotes, links, tables, inline code, and fenced code blocks.
- Raw HTML is always rendered as text. Clickable links are restricted to HTTP, HTTPS, and mail addresses. The renderer does not use `innerHTML`, `eval`, a remote CDN, or executable model output.
- Fenced code blocks show a language label, local syntax coloring, horizontal scrolling, and their own copy control.
- Every stored user message receives copy and true edit actions. Editing keeps the same message bubble and message ID, preserves all earlier context, supersedes the active conversation after that turn, and starts Ember again from the revised text.
- Every Ember answer receives copy, positive feedback, negative feedback, alternative generation, continuation, and answer-detail controls.
- Feedback is stored server-side and restricted to the authenticated user, exact private session, and exact Ember message. Migration 009 repairs legacy reaction-table encodings and the missing private `console` channel; CoreUI feedback uses database-safe ASCII tokens while remaining compatible with existing thumb values.
- Alternative answers keep the original response and use the same `reply_to_id`. Variants are grouped under one turn with previous/next navigation and are reconstructed from the database after reloading the page.
- Continuations are stored as separate Ember messages and remain linked to the original user turn.
- Text entered while Ember is answering can be submitted immediately. It appears as a waiting user turn and is sent automatically after the current response without interrupting it.
- Message revisions are stored account- and session-bound. Superseded downstream messages remain recoverable in the private account export but are excluded from the active model context.
- Migration `008_message_actions` stores short-lived, account-bound generation requests. Migration `009_message_editing` adds true in-place revisions and the `edit` generation mode. A fixed message-ID floor lets a reconnect recover only the answer created after that request, without repeating or losing the existing answer.
- Concurrent answer actions in one session are serialized. Permanent session deletion is blocked while an alternative or continuation is still active.
- Live SSE answers and loaded history now use the same Markdown and action rendering path.
- Tool-based answer actions retain their exact private browser-job ID, so reconnects resume the same job and worker failures do not leave the session blocked.
- The private account export includes feedback and generation history without exporting temporary request tokens.

All installer, privacy, upload, profile, navigation, private RAG-Lite, and Python-runtime changes from `0.4.5-alpha` remain included:

- The quick installer has been rebuilt. It no longer points to `0.3.2-alpha`, verifies the exact package root and version, and never unpacks an extra directory level into `/opt/ember-coreui`.
- Private console turns address Ember on the server. Attachment-only messages therefore no longer leave an artificial `@Ember` prefix or a single `@` in the model prompt.
- The authenticated turn ID now restores all ordered message attachments before prompt construction. A real end-to-end preflight verifies Windows-1252 TXT, UTF-8/16 decoding, Python, DOCX, and text-PDF contents from storage mapping through to the final model attachment block.
- Up to ten files per message remain supported and are stored in stable order with the exact user and session.
- Private RAG-Lite now uses the same shared extraction, normalization, chunking, query, and ranking engine as the operator RAG path, while keeping every source and chunk in user-scoped tables.
- Private knowledge uploads additionally accept common source and data formats such as Python, CSV, JSON, XML, YAML, PHP, JavaScript, HTML, CSS, and SQL.
- A dedicated Compose Python queue worker and sandbox self-test close the previously incomplete execution path. Generated code runs only in a disposable restricted container.
- No private Starlight Unit manuscript is bundled or imported. Migration `007_remove_private_studio_lore` removes only the two historic accidental global source IDs from already-updated E.M.B.E.R. CoreUI databases.
- The production address remains `https://core.starlight-unit.de`.

The session scopes, exact turn assignments, lossless legacy history, controlled session deletion, Thinking protection, profiles, user settings, and Admin Core from the preceding releases remain included. Game, homepage, Apache, KeyHelp, host PHP, Repack paths, and the existing STU database remain untouched.

## Real sessions instead of demo pointers

Before `0.3.1-alpha`, a session consisted only of a title and `since_id`. All private messages were still stored in a shared user stream. This caused incorrect excerpts, jumping order, and missing latest messages. The new path fixes this structurally:

- `stu_chat_messages.session_id` permanently separates conversations in the database.
- `reply_to_id` assigns each Ember response to the exact user turn.
- `console_messages.php` returns only messages from a verified session owned by the user.
- The client remembers the active session ID instead of an unstable list index.
- Asynchronous history, poll, SSE, and browser callbacks verify their session ID before changing the visible interface.
- Read markers no longer change `updated_at`; only genuinely new messages move a session to the top.
- The newest messages are loaded directly. Older pages can be loaded without calculation windows or `since_id` estimation.
- Sessions are no longer deleted automatically. Archiving is a reversible metadata operation; the message history remains preserved.
- A dedicated archive view allows archived sessions to be restored. Temporary loading errors no longer displace already confirmed messages from the view.
- Permanent deletion is available only in the archive view and requires two deliberate confirmations.
- The API endpoint accepts deletion only for a session owned by the user that is already archived and verifies a session-specific confirmation again on the server side.
- An SQL transaction removes reactions, browser frames, browser steps, completed browser jobs, messages, orphaned upload records, and finally the session row. An error rolls back the entire database operation.
- Sessions with a browser job in the `queued` or `running` state are not deleted. This prevents an active research task from being removed underneath the worker.
- Physical upload files and the final screenshot of removed browser jobs are deleted only after a successful database commit and only from `var/console_media`, `assets/chat_media`, or `uploads/ember_browse`. Files with additional message references remain preserved.
- Migration `003_console_sessions` collects legacy messages that cannot be separated unambiguously into a recovered history without data loss.
- The dedicated session self-test checks schema, history scope, turn assignment, client races, browser worker propagation, and the complete transactional deletion cascade including rollback.

## Secure thinking status and cleanup of old sessions

E.M.B.E.R. CoreUI strictly separates transient internal model analysis from the visible interface:

- During generation, SSE returns only fixed status stages such as `KONTEXT WIRD ABGEGLICHEN` and `ANTWORT WIRD FORMULIERT`.
- `message.content` and `message.thinking` are collected in full and processed only after the shared safety check.
- English and German prompt, analysis, draft, and self-correction patterns are intercepted before SSE, database, and history.
- New `thinking_content` values contain only the safe final status when Thinking is enabled; when Thinking is disabled, they remain empty.
- The fetch endpoint replaces old raw values before JSON output using fail-closed behavior.
- The browser has an additional whitelist and does not write arbitrary Thinking text into the DOM.
- The response budget is passed to Ollama per user. A detected token limit continues from the existing response instead of restarting the entire generation.
- Private Console messages may contain up to 12000 characters, or up to 20000 characters with attachments. Line breaks and code indentation are preserved.
- The SSE call passes only the authenticated turn ID. The message text is loaded from the E.M.B.E.R. CoreUI database and does not appear in the EventSource URL or in proxy access logs.
- The message column is expanded to `MEDIUMTEXT` by migration `002_coreui_management`.

After the update, existing raw blocks can be replaced once in the isolated E.M.B.E.R. CoreUI database:

```bash
cd /opt/ember-coreui
sudo ./scripts/stack.sh scrub-thinking
```

The command changes neither the Game database nor the Global Chat.

Only Ollama is shared as an already existing local model interface. E.M.B.E.R. CoreUI creates the separate model `ember-coreui:latest` for this purpose. An existing STU model such as `gemma4:26b` is neither replaced nor reconfigured.

## Profiles, AI settings, and private knowledge

`settings.html` connects local display options with a real server-side user and AI profile:

- custom visible username without changing the login email or internal user ID
- private user profile image as a server-side re-rendered PNG file
- custom visible CoreAI name and separate CoreAI profile image per account
- custom system prompt for style and working method within the unchanged E.M.B.E.R. CoreUI safety and identity rules
- Memory on or off and configurable Memory limit
- model Thinking on or off per account; raw thought processes remain private in both settings
- response budget from 256 to 16384 Ollama tokens
- temperature from 0.1 to 1.5
- optional local Ollama model tag that is actually used in the chat path
- create and delete custom visible Memory facts
- upload private RAG-Lite sources directly, use them chunk by chunk, and delete them completely
- account, permission level, health matrix, model, and version
- local reference operation with Gemma 4 and Ollama

Profile images are not served as freely accessible static files. The private media endpoint verifies the session and always serves only the current avatar of the signed-in account. JPEG and PNG uploads are decoded, center-cropped to a square, limited to a maximum of 512 pixels, and saved as a new PNG file without third-party metadata.

Private RAG-Lite accepts `.txt`, `.md`, `.pdf`, `.docx`, `.py`, `.csv`, `.json`, `.xml`, `.yml`, `.yaml`, `.ini`, `.php`, `.js`, `.html`, `.css`, and `.sql`. PDFs require a real text layer for this settings import; scanned PDFs without readable text are explicitly rejected. Extraction, UTF-8 normalization, overlapping chunking, query-term generation, and relevance ranking use the shared RAG-Lite engine. Sources and chunks remain strictly bound to the authenticated user, and both model prompt paths hard-limit private knowledge to the private console channel. By default, limits are 20 MiB per file, 40 sources, and a total of 5,000,000 extracted characters per account. Account-specific MariaDB locks prevent concurrent uploads from jointly bypassing these quotas. Document contents are marked as untrusted data and never as system instructions.

The prepared external provider adapter remains disabled by default. API keys are never stored in the browser. Only a later server-side tested implementation can enable it; there is no compatibility guarantee for arbitrary cloud providers.

## Admin Core

`admin/index.html` is accessible only to permission levels 0 and 1 and operates exclusively against the E.M.B.E.R. CoreUI database. It includes:

- overview for accounts, sessions, messages, Memories, Lore, browse jobs, and logs
- create new user accounts with display name, login email, initial password, and permitted role
- user permissions, time-limited suspensions, and suspension reasons
- Memory CRUD for global, user, and character visibility
- Lore sources and chunk search
- registration, maintenance mode, and maintenance message
- central switches for fast web search, browser research, and isolated Python
- prepared external provider metadata without browser keys
- error log and immutable admin audit

Permission changes and active suspensions are read from the database again on every authenticated request. They therefore also apply to sessions that are already signed in.

The display options are stored under `emberCoreUI.preferences.v1` in the local browser. They change neither the database schema nor accounts, sessions, memories, or model configuration.

## Safe runtime update

The update workflow preserves `var/compose.env`, databases, uploads, and caches. At the same time, the non-secret Nginx and PHP-FPM runtime files are regenerated from the current package templates before every relevant stack start:

```bash
sudo ./scripts/stack.sh refresh-runtime
sudo ./scripts/stack.sh restart web
```

With `sudo ./scripts/stack.sh up -d --build --force-recreate php web browse pyworker`, the runtime refresh and pending additive database migrations are performed automatically. The runtime values for bind address and ports continue to come from the locally protected `var/compose.env`; credentials are neither written into templates nor output.

## Isolation boundaries

| Area | E.M.B.E.R. CoreUI parallel operation |
|---|---|
| Project files | Exclusively under `/opt/ember-coreui` |
| Web server | Dedicated Nginx container on `127.0.0.1:9080` |
| PHP | Dedicated PHP-FPM container on `127.0.0.1:19073` |
| Database | Dedicated MariaDB container on `127.0.0.1:13306` |
| Web search | Dedicated SearXNG container on `127.0.0.1:18889` |
| Browser research | Dedicated Playwright container |
| Python queue | Dedicated trusted worker; generated code runs in a disposable restricted sandbox container |
| Live browser images | Private E.M.B.E.R. CoreUI database, authenticated job SSE, automatic cleanup |
| Model | Dedicated Ollama model `ember-coreui:latest` |
| Login sessions | Dedicated cookie name `EMBERCOREUISESSID` |
| Chat sessions | Dedicated `session_id`, exact history, and turn assignment in the E.M.B.E.R. CoreUI database |
| Profiles | Account-specific names and private media metadata in the E.M.B.E.R. CoreUI database |
| Profile images | Authenticated delivery from `var/profile_media`, no direct web path |
| Private RAG-Lite | User-bound sources and chunks in migration 004, originals under `var/knowledge_uploads` |
| Locks | Dedicated namespace `ember_coreui:*` |
| Runtime data | Local directories `var/`, `logs/`, `uploads/`, and `assets/chat_media/` |
| PDF page images | Dedicated short-lived runtime path `var/pdf_pages` |

On Linux, the containers use the host network so that they can reach Ollama at `127.0.0.1:11434` without rebinding the existing Ollama service to `0.0.0.0`. All E.M.B.E.R. CoreUI services receive dedicated loopback ports that are checked before installation.

Shared Ollama usage still means shared CPU, RAM, and GPU resources. This is not data or configuration coupling, but simultaneous generations can affect the response time of both interfaces.

## Let Game and E.M.B.E.R. CoreUI respond simultaneously

Game and E.M.B.E.R. CoreUI already do not block each other at the application level:

- The Game uses its existing STU database and the lock `ember_global_ollama`.
- E.M.B.E.R. CoreUI uses its own MariaDB instance and, by default, the lock `ember_coreui:global_ollama`.
- Chat histories, sessions, uploads, Memories, and RAG data remain separate.
- Only the local Ollama service at `127.0.0.1:11434` is shared.

For two different model names to be loaded simultaneously, Ollama needs enough RAM or VRAM. First run the read-only report:

```bash
cd /opt/ember-coreui
sudo ./scripts/ollama-parallel-report.sh
```

If both models fit together in the available RAM or VRAM, Ollama can be limited to two loaded models using a systemd drop-in:

```bash
sudo install -d -m 0755 /etc/systemd/system/ollama.service.d
printf '%s\n' \
  '[Service]' \
  'Environment="OLLAMA_MAX_LOADED_MODELS=2"' \
  'Environment="OLLAMA_NUM_PARALLEL=1"' \
  | sudo tee /etc/systemd/system/ollama.service.d/20-stu-parallel.conf >/dev/null
sudo systemctl daemon-reload
sudo systemctl restart ollama
ollama ps
```

Without a custom limit, Ollama already allows at least three loaded models by default according to the official documentation. The drop-in is therefore needed only if the report shows a different existing limit or if the upper limit is intentionally fixed at exactly two. `OLLAMA_NUM_PARALLEL=1` applies per loaded model and avoids additional context copies. If Game and E.M.B.E.R. CoreUI are later intended to use the same model name, `OLLAMA_NUM_PARALLEL=2` may be necessary. However, this multiplies the memory requirement of the context and should be set only after a RAM/VRAM test. If there is not enough memory, Ollama queues requests instead of forcing true concurrency.

The E.M.B.E.R. CoreUI installer deliberately does not write this system-wide Ollama configuration itself. The Ollama service is the only shared resource and remains under the control of the server operator.

Official reference: <https://docs.ollama.com/faq#how-does-ollama-handle-concurrent-requests>

The internal STU Console was already removed from the Game with Alpha `v1.1.1.98` on 20 August 2026. E.M.B.E.R. CoreUI is its independent successor and reference interface; it does not replace the Global Chat. This standalone package deletes no Repack files and changes no Game routes.

## Supported target systems

- Debian 12 or newer
- Ubuntu Server 24.04 or newer
- Root or sudo access via SSH
- Docker or permission to install Docker automatically
- Local Ollama or permission to install Ollama automatically
- Enough RAM and storage for the selected Gemma 4 tag
- Outbound Internet access during the first image build

Ollama's Linux guide: <https://docs.ollama.com/linux>

Gemma 4 model page: <https://ollama.com/library/gemma4>

SearXNG Docker documentation: <https://docs.searxng.org/admin/installation-docker.html>

## Safe parallel installation via SSH

The **archived pinned release**, rather than the current `main` development tree, can still be installed manually through its verified ZIP/SHA-256 pair:

```bash
cd /home/users/game/tmp
BASE='https://raw.githubusercontent.com/Starlight-Unit-Studio/coreui/main/releases/v0.5.3-alpha'

curl -fL "$BASE/EMBER_COREUI_0_5_3_ALPHA.zip" -o EMBER_COREUI_0_5_3_ALPHA.zip
curl -fL "$BASE/EMBER_COREUI_0_5_3_ALPHA.zip.sha256" -o EMBER_COREUI_0_5_3_ALPHA.zip.sha256
sha256sum -c EMBER_COREUI_0_5_3_ALPHA.zip.sha256
unzip -q EMBER_COREUI_0_5_3_ALPHA.zip

sudo mv EMBER_COREUI_0_5_3_ALPHA /opt/ember-coreui
cd /opt/ember-coreui
sudo chmod 0750 setup.sh scripts/*.sh
sudo ./scripts/install.sh
```

The installer asks for the administrator email address, a password of at least 12 characters, and an optional display name through `COREUI_ADMIN_NAME`.

## Manual update using the archived 0.5.3-alpha ZIP

The existing database, accounts, sessions, uploads, and protected credentials are preserved. The package is copied only over static project files and templates.

```bash
cd /home/users/game/tmp
sha256sum -c EMBER_COREUI_0_5_3_ALPHA.zip.sha256
unzip -q -o EMBER_COREUI_0_5_3_ALPHA.zip

sudo apt-get update
sudo apt-get install -y rsync
sudo rsync -a \
  --exclude='api/config.local.php' \
  --exclude='var/' \
  --exclude='logs/' \
  --exclude='uploads/' \
  --exclude='assets/chat_media/' \
  --exclude='assets/profile_photos/' \
  EMBER_COREUI_0_5_3_ALPHA/ /opt/ember-coreui/

cd /opt/ember-coreui
sudo ./scripts/stack.sh up -d --build --force-recreate php web browse pyworker
sudo ./scripts/stack.sh scrub-thinking
sudo ./scripts/preflight.sh
```

`stack.sh up` refreshes the non-secret runtime configuration and applies migrations `007_remove_private_studio_lore`, `008_message_actions`, and `009_message_editing`. Migration 007 removes only the two historical accidental global source IDs. Migration 008 adds account-bound feedback and generation-request metadata. Migration 009 repairs legacy reaction storage, adds message revisions, and enables the edit generation mode. Existing messages are not rewritten during installation. User knowledge, memories, accounts, uploads, and other operator sources remain intact. No private manuscript binary is present in the archive or imported by either installer.

The first Python sandbox image build can take several minutes. Regenerating the Ember model or importing bundled Studio lore is not required. The existing `api/config.local.php` and the public address `https://coreui.starlight-unit.de` remain preserved.

## Testing without affecting existing domains

By default, E.M.B.E.R. CoreUI listens only on `127.0.0.1:9080`. An SSH tunnel is opened from the local computer:

```bash
ssh -L 9080:127.0.0.1:9080 DEIN_SSH_USER@DEIN_SERVER
```

Then open in the local browser:

```text
http://127.0.0.1:9080/
```

This requires no new host Nginx virtual host, no port forwarding, and no change to an existing homepage.

## Public address

The current production address is:

```text
https://coreui.starlight-unit.de
```

The reverse proxy continues to forward this domain internally to `http://127.0.0.1:9080`. In the preserved file `/opt/ember-coreui/api/config.local.php`, exactly one definition must be active for this:

```php
define('STU_PUBLIC_BASE_URL', 'https://coreui.starlight-unit.de');
```

The previous address `webui.starlight-unit.de` is no longer a current E.M.B.E.R. CoreUI base address. Because `config.local.php` is deliberately excluded from updates, the release does not automatically change this local operator configuration.

## What the standard installer does not do

- It does not remove any file from `/etc/nginx/sites-enabled/`.
- It does not write any host Nginx or Apache configuration.
- It does not write any global PHP configuration and does not restart an existing PHP-FPM.
- It does not create tables in the existing STU database.
- It does not change any existing MariaDB user.
- It does not install a systemd service for the browse worker.
- It does not write to `/home/users/game`, `/var/www`, or a Repack path.
- It does not replace any existing STU Ollama model.

If Docker is missing and `COREUI_INSTALL_DOCKER=1` is active, the installer installs and starts Docker. If Ollama is missing and `COREUI_INSTALL_OLLAMA=1` is active, it installs Ollama. Existing reachable services are not reconfigured.

## Optionaler Host-Swap fuer E.M.B.E.R. und CoreUI

CoreUI prueft nach erfolgreichem Docker- oder Native-Installationslauf den Swap-Zustand des **Linux-Hosts**, nicht eines Containers. Ist bereits eine aktive Swap-Datei, Swap-Partition oder zram vorhanden, wird diese unveraendert uebernommen. Dasselbe gilt fuer konfigurierte, aber inaktive Swap-Eintraege in `/etc/fstab` sowie fuer eine bereits vorhandene `/swapfile`.

Nur wenn keine Swap-Konfiguration erkannt wird, erstellt der Installer standardmaessig eine **8-GiB-Notfall-Swapdatei** `/swapfile` auf ext4 oder XFS (mindestens 9 GiB Platz auf dem Dateisystem erforderlich). Die Datei erhaelt Modus `0600`, wird mit `mkswap` und `swapon` aktiviert und in `/etc/fstab` fuer Neustarts eingetragen. Nur bei einer solchen Neuanlage wird `vm.swappiness=10` unter `/etc/sysctl.d/99-stu-coreui-swap.conf` eingerichtet, sofern kein anderer Betreiberwert unter `/etc/sysctl.conf` oder `/etc/sysctl.d/*.conf` hinterlegt ist. Ein bereits eingerichteter Wert bleibt erhalten.

Die Funktion ist idempotent und mit einem Host-Lock gegen zwei gleichzeitige CoreUI-Installerlaeufe abgesichert. Sie ersetzt **niemals** eine vorhandene Swap-Konfiguration des Game-/Repack-Installers. Nicht unterstuetzte Dateisysteme, zu wenig Platz oder Fehler bei der Anlage werden protokolliert, ohne die erfolgreiche CoreUI-Installation rueckgaengig zu machen. Der Swap ist Notfallreserve und kein Ersatz fuer ausreichenden RAM oder eine Begrenzung von LLM-Arbeitslasten.

Fuer die erneute Swap-Pruefung ohne Neuinstallation:

```bash
sudo bash scripts/swap.sh status
sudo bash scripts/swap.sh ensure
```

Optionale Umgebungsvariablen fuer beide Installer und `scripts/swap.sh ensure`:

| Variable | Standard | Bedeutung |
|---|---:|---|
| `COREUI_SWAP_AUTOCREATE` | `1` | Auf `0` setzen, um jede automatische Swap-Anlage zu deaktivieren |
| `COREUI_SWAP_SIZE_GIB` | `8` | Neue Swapdatei, 1 bis 64 GiB |
| `COREUI_SWAP_SWAPPINESS` | `10` | Nur bei neuer Anlage, Bereich 0 bis 200 |

Bei der Verwendung von `sudo` koennen abweichende Einstellungen mit `sudo --preserve-env=COREUI_SWAP_AUTOCREATE,COREUI_SWAP_SIZE_GIB,COREUI_SWAP_SWAPPINESS ...` uebergeben werden. `scripts/swap.sh status` ist nur lesend, `ensure` benoetigt Root-Rechte.

**Release-Hinweis:** Der unveraenderte One-Liner unter „Quick installation“ nutzt standardmaessig den neuesten GitHub-`main`-Stand. Fuer den laufenden Live-Testbetrieb muss nach dem Merge weder ein Release-ZIP neu gebaut noch ein zweiter Updater aufgerufen werden.

## Live-Entwicklerstand mit dem bestehenden One-Liner

Genau denselben One-Liner wie bei der Erstinstallation erneut ausfuehren. `setup.sh` laedt den neuesten Commit aus `main`, fuehrt den regulaeren Installer und die SQL-Migrationen aus und prueft die aktualisierte Installation. Der konfigurierte isolierte Betrieb bleibt bestehen; Benutzer, Sitzungen, Datenbank, Uploads und Geheimnisse bleiben erhalten. GitHub Actions werden nicht benoetigt.

Der tatsaechlich installierte Commit wird am Ende ausgegeben und in `/opt/ember-coreui/var/dev_source_commit` gespeichert. Die aktuelle Produktversion in `VERSION` ist rein numerisch (`0.5.13`), unabhaengig von Commit und Cache-Schluessel. Fuer den exakten live installierten Quellstand ist die gesondert gespeicherte Commit-SHA massgeblich.

Wer stattdessen **explizit das alte gepruefte Release** installieren moechte, kann den Launcher unter `sudo` mit `COREUI_SETUP_SOURCE=release` ausfuehren. Nur dieser Modus benutzt das gepinnte ZIP mit SHA-256-Verifikation. Das bisherige `scripts/update-from-main.sh` ist nicht mehr als separater Update-Schritt erforderlich.

## Installation process

1. Validate the operating system, project path, and inputs.
2. Check the dedicated ports and Compose project name for collisions.
3. Create dedicated runtime directories and credentials.
4. Validate and build the PHP, Python worker, and optional browse images.
5. Detect or download a Gemma 4 base model.
6. Create the separate model `ember-coreui:latest`.
7. Start the dedicated MariaDB and idempotently apply all SQL migrations.
8. Create Ember, the administrator, character, and `stu_kv` records on first installation.
9. Remove only the exact historical accidental manuscript sources and files. No private Studio manuscript is bundled or imported.
10. Start SearXNG, PHP-FPM, Nginx, browse worker, and Python worker.
11. Run health, schema, upload-pipeline, private-RAG, session, Thinking, and Python-sandbox checks.

## Non-interactive installation

The password should not appear as plain text in the shell history:

```bash
cd /opt/ember-coreui
export COREUI_ADMIN_EMAIL='admin@example.org'
read -r -s -p 'E.M.B.E.R. CoreUI Admin-Passwort: ' COREUI_ADMIN_PASSWORD
printf '\n'
export COREUI_ADMIN_PASSWORD
export COREUI_ADMIN_NAME='Operator'
sudo --preserve-env=COREUI_ADMIN_EMAIL,COREUI_ADMIN_PASSWORD,COREUI_ADMIN_NAME ./scripts/install.sh
unset COREUI_ADMIN_PASSWORD
```

## Important options

| Variable | Default | Meaning |
|---|---:|---|
| `COREUI_HTTP_PORT` | `9080` | Dedicated web port |
| `COREUI_PHP_PORT` | `19073` | Dedicated PHP-FPM port |
| `COREUI_DB_PORT` | `13306` | Dedicated MariaDB port |
| `COREUI_SEARXNG_PORT` | `18889` | Dedicated SearXNG port |
| `COREUI_BIND_ADDRESS` | `127.0.0.1` | Secure web bind address |
| `COREUI_BASE_MODEL` | existing `gemma4:*`, otherwise `gemma4:e4b` | Ollama base model |
| `COREUI_MODEL_NAME` | `ember-coreui:latest` | Separate E.M.B.E.R. CoreUI model name |
| `COREUI_LOCK_NAMESPACE` | `ember_coreui` | Dedicated MariaDB lock namespace |
| `COREUI_VIDEO_FRAMES` | `6` | Evenly distributed frames per video, maximum 32 |
| `COREUI_VIDEO_FRAME_PX` | `512` | Maximum width and height of a vision frame |
| `COREUI_PDF_MAX_PAGES` | `4` | Representative pages per scanned PDF, maximum 12 |
| `COREUI_PDF_PAGE_PX` | `1024` | Maximum width and height of a rasterized PDF page |
| `COREUI_INSTALL_DOCKER` | `1` | Installs Docker if required |
| `COREUI_INSTALL_OLLAMA` | `1` | Installs Ollama if required |
| `COREUI_INSTALL_SEARXNG` | `1` | Starts the dedicated SearXNG instance |
| `COREUI_INSTALL_BROWSE` | `1` | Starts the dedicated browse worker |
| `COREUI_SKIP_BOOTSTRAP` | `0` | Skips Admin and Ember bootstrap |
| `COREUI_FORCE_CONFIG` | `0` | Regenerates `api/config.local.php` |

Any port collision causes the process to abort before the E.M.B.E.R. CoreUI containers are started. Existing third-party containers with the Compose project name `ember-coreui` are likewise not adopted.

## PDF detection

The document path operates in two stages:

1. `pdftotext` reads PDFs with a real text layer directly and limits the prompt excerpt like other text attachments.
2. If the text layer remains empty, `pdftoppm` renders up to four evenly distributed pages as JPEG. These pages are sent to Gemma's vision path in the correct order.

With a long scanned PDF, Ember therefore sees a sample, not automatically every page. The response must openly state this scope. For a different limit, the constants `STU_EMBER_PDF_MAX_PAGES` and `STU_EMBER_PDF_PAGE_PX` can be set in `api/config.local.php`. More pages and higher resolution significantly increase vision runtime, context, and memory requirements.

If Poppler is missing, the runtime path is not writable, or no page can be rendered, model generation for the document content is stopped. This prevents an invented PDF summary.

## Browser research and Ollama runtimes

The browse worker clearly separates browser navigation from model decisions. SearXNG search, `goto`, clicks, and page text capture run through Playwright. Only afterwards does Ollama decide on the next step. An Ollama read timeout is therefore not a Playwright error.

In the chat, the current browser viewport replaces the previous text log. The first view shows the locally received SearXNG results as a safe, escaped results page. After navigation, click, input, and scrolling, three real JPEG frames follow by default at intervals of 240 milliseconds. This captures dynamically loaded content and visible page changes without inventing an artificial video stream. The Ghost Cursor retains its last position in every frame. A separate click signal is set only together with the real bounding box of a clicked Playwright target. The images are not served as public files, but are stored job-bound in the dedicated database, read only through the authenticated SSE endpoint, and removed by default after 24 hours.

The following time limits apply by default:

- action decision by Ollama: 420 seconds
- final Ember report: 420 seconds
- live window without a new step event: 480 seconds
- entire browse SSE channel: 900 seconds

On particularly slow CPU systems, the two Ollama time limits can be adjusted in `api/config.local.php`:

```php
define('STU_EMBER_BROWSE_AGENT_TIMEOUT', 600);
define('STU_EMBER_BROWSE_REPORT_TIMEOUT', 600);
define('STU_EMBER_BROWSE_AGENT_RETRIES', 0);
define('STU_EMBER_BROWSE_FRAME_QUALITY', 45);
define('STU_EMBER_BROWSE_FRAME_BURST', 3);
define('STU_EMBER_BROWSE_FRAME_INTERVAL_MS', 240);
define('STU_EMBER_BROWSE_FRAME_RETENTION_HOURS', 24);
```

`STU_EMBER_BROWSE_AGENT_RETRIES` remains `0` by default because a read-side timed-out request may still be processing on the Ollama server. Optional retries apply only to an immediate connection failure, not to a read timeout. Per action, 1 to 5 follow-up frames are allowed, with the interval limited to 120 to 800 milliseconds. JPEG quality is limited to values from 25 to 75, and retention to 1 to 168 hours. After a change, only the worker needs to be restarted:

```bash
cd /opt/ember-coreui
sudo ./scripts/stack.sh restart browse
```

## Use an existing SearXNG instance

```bash
sudo env \
  COREUI_INSTALL_SEARXNG=0 \
  COREUI_SEARXNG_URL='http://127.0.0.1:8888/search' \
  ./scripts/install.sh
```

This deliberately creates a dependency on that existing search instance. For maximum isolation, the dedicated SearXNG instance remains recommended.

Without web search and browse worker:

```bash
sudo env \
  COREUI_INSTALL_SEARXNG=0 \
  COREUI_INSTALL_BROWSE=0 \
  COREUI_SEARXNG_URL='' \
  ./scripts/install.sh
```

## Diagnostics and operation

```bash
cd /opt/ember-coreui
sudo ./scripts/preflight.sh
sudo ./scripts/stack.sh ps
sudo ./scripts/stack.sh logs --tail=100
curl -fsS http://127.0.0.1:9080/api/health.php | python3 -m json.tool
sudo ./scripts/stack.sh exec -T -u 33:33 php php scripts/video-selftest.php
sudo ./scripts/stack.sh exec -T -u 33:33 php php scripts/pdf-selftest.php
sudo ./scripts/stack.sh exec -T -u 33:33 php php scripts/thinking-sanitize-selftest.php
sudo ./scripts/stack.sh exec -T -u 33:33 php php scripts/reply-pipeline-selftest.php
sudo ./scripts/stack.sh exec -T -u 33:33 php php scripts/session-selftest.php
sudo ./scripts/stack.sh exec -T -u 33:33 php php scripts/profile-knowledge-selftest.php
sudo ./scripts/stack.sh exec -T -u 33:33 php php scripts/attachment-pipeline-selftest.php
sudo ./scripts/stack.sh exec -T -u 33:33 php php scripts/python-worker-selftest.php
sudo ./scripts/stack.sh exec -T -u 33:33 php php scripts/logo-alpha-selftest.php
sudo ./scripts/stack.sh exec -T -u 33:33 php php scripts/frontend-regression-selftest.php
sudo ./scripts/stack.sh exec -T -u 33:33 php php scripts/message-actions-selftest.php
sudo ./scripts/ollama-parallel-report.sh
sudo grep -E 'ember_(video|pdf)_|ember_vision_payload' logs/stu_error.log | tail -n 30
```

Watch only the browse worker:

```bash
sudo ./scripts/stack.sh logs -f browse
```

Restart the stack:

```bash
sudo ./scripts/stack.sh restart
```

Stop the stack without deleting data:

```bash
sudo ./scripts/stack.sh stop
```

## Backup

```bash
cd /opt/ember-coreui
sudo install -d -m 0700 /var/backups/ember-coreui
sudo ./scripts/stack.sh exec -T database sh -ec \
  'mariadb-dump --protocol=socket -uroot -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE"' \
  | sudo tee /var/backups/ember-coreui/database.sql >/dev/null

sudo tar -C /opt -czf /var/backups/ember-coreui/files.tgz \
  ember-coreui/api/config.local.php \
  ember-coreui/var/compose.env \
  ember-coreui/assets/chat_media \
  ember-coreui/uploads \
  ember-coreui/var/console_media \
  ember-coreui/var/profile_media \
  ember-coreui/var/knowledge_uploads
```

## Uninstallation

These commands apply to the standard isolated Compose installation. The native installer changes shared host services and therefore requires an operator-controlled rollback instead of this removal procedure.

On an otherwise clean supported server, the standard installer installs Docker and Docker Compose when they are missing, installs Ollama through its official Linux installer when required, pulls `gemma4:e4b` when no selected Gemma 4 base model is available, and creates the dedicated CoreUI model. Nginx, PHP-FPM, MariaDB, SearXNG, and the workers run as containers; the standard installer does not install or reconfigure a host web server. Docker, Ollama, and the base model remain after uninstallation even when CoreUI originally installed or downloaded them, because they may have become shared dependencies. Only the model recorded by CoreUI in `var/model.owner` is treated as installer-owned and removed by the complete procedure below.

To remove the running E.M.B.E.R. CoreUI services while retaining all accounts, database data, uploads, configuration, images, and the dedicated Ollama model for a later reinstall:

```bash
cd /opt/ember-coreui
sudo ./scripts/stack.sh down --remove-orphans
```

The following complete removal is irreversible. Create the backup described above first if any CoreUI data may still be needed. It removes the Compose services, locally built CoreUI images, the installer-owned Ollama model, and the entire `/opt/ember-coreui` directory including its database and uploads:

```bash
(
  set -Eeuo pipefail
  cd /opt/ember-coreui
  COREUI_OWNED_MODEL="$(sudo sed -n '1p' var/model.owner 2>/dev/null || true)"
  sudo ./scripts/stack.sh down --remove-orphans --volumes --rmi local
  if [[ "$COREUI_OWNED_MODEL" =~ ^[A-Za-z0-9][A-Za-z0-9._:/-]*$ ]]; then
    sudo ollama rm "$COREUI_OWNED_MODEL" 2>/dev/null || true
  fi
  sudo docker image rm ember-py:1 2>/dev/null || true
  cd /opt
  sudo rm -rf -- /opt/ember-coreui
)
```

The base Gemma model, Docker, Ollama, host web servers, other applications, and manually created reverse-proxy configuration remain untouched. A reverse-proxy entry that points to E.M.B.E.R. CoreUI can be removed separately by its operator after the service has been uninstalled.

### Additional cleanup for a CoreUI-only server

Use this final cleanup only after the complete removal above and only when the server was prepared exclusively for E.M.B.E.R. CoreUI. It removes all remaining Ollama models and data, Ollama itself, Docker, Docker Compose, and all Docker image and volume data. It must not be used on a server that runs another container or uses Ollama for another application.

The block refuses to continue while any Docker container still exists, displays the remaining Ollama models, and requires the exact confirmation `REMOVE COREUI HOST`:

```bash
(
  set -Eeuo pipefail

  COREUI_REMAINING_CONTAINERS="$(sudo docker ps -aq)" || {
    printf '[ABORT] Docker could not be queried safely.\n' >&2
    exit 1
  }
  if [[ -n "$COREUI_REMAINING_CONTAINERS" ]]; then
    printf '[ABORT] Other Docker containers still exist:\n' >&2
    sudo docker ps -a --format 'table {{.Names}}\t{{.Image}}\t{{.Status}}' >&2
    exit 1
  fi

  OLLAMA_BIN="$(command -v ollama || true)"
  if [[ -n "$OLLAMA_BIN" ]]; then
    printf 'Remaining Ollama models that will be deleted:\n'
    ollama list || {
      printf '[ABORT] Ollama models could not be listed safely.\n' >&2
      exit 1
    }
  else
    printf 'Ollama is not installed; only Docker cleanup remains.\n'
  fi
  read -r -p 'Type REMOVE COREUI HOST to remove Docker, Ollama, and all of their data: ' COREUI_HOST_CONFIRM </dev/tty
  [[ "$COREUI_HOST_CONFIRM" == 'REMOVE COREUI HOST' ]] || {
    printf 'Cleanup cancelled.\n'
    exit 1
  }

  sudo systemctl disable --now ollama.service 2>/dev/null || true
  sudo rm -f -- /etc/systemd/system/ollama.service
  sudo rm -rf -- /etc/systemd/system/ollama.service.d
  case "$OLLAMA_BIN" in
    /usr/local/bin/ollama|/usr/bin/ollama|/bin/ollama)
      sudo rm -f -- "$OLLAMA_BIN"
      ;;
    '') ;;
    *) printf '[WARN] Unknown Ollama binary path remains: %s\n' "$OLLAMA_BIN" >&2 ;;
  esac
  sudo rm -rf -- /usr/local/lib/ollama /usr/lib/ollama /lib/ollama /usr/share/ollama
  sudo userdel ollama 2>/dev/null || true
  sudo groupdel ollama 2>/dev/null || true
  sudo systemctl daemon-reload

  COREUI_DOCKER_PACKAGES=()
  for package in docker.io docker-compose docker-compose-v2 docker-compose-plugin; do
    if dpkg-query -W -f='${db:Status-Abbrev}' "$package" 2>/dev/null | grep -q '^ii '; then
      COREUI_DOCKER_PACKAGES+=("$package")
    fi
  done
  if (( ${#COREUI_DOCKER_PACKAGES[@]} > 0 )); then
    sudo apt-get purge -y "${COREUI_DOCKER_PACKAGES[@]}"
  fi
  sudo apt-get autoremove -y
  sudo rm -rf -- /var/lib/docker /var/lib/containerd
)
```

The system packages used for normal administration, such as `curl`, certificate support, Python, and archive tools, are deliberately retained. Current upstream removal references: [Ollama Linux uninstall](https://docs.ollama.com/linux#uninstall) and [Docker Engine uninstall](https://docs.docker.com/engine/install/debian/#uninstall-docker-engine).

## Native installer for an empty standalone server

The previous host installer remains included as `scripts/install-native.sh`. It installs Nginx, PHP-FPM, and MariaDB directly on the host and is therefore not intended for the parallel STU production server.

It can only be started with explicit confirmation:

```bash
sudo env COREUI_NATIVE_CONFIRM=DEDICATED_SERVER ./scripts/install-native.sh
```

Even then, the native installer does not remove an existing Nginx default site unless `COREUI_DISABLE_NGINX_DEFAULT=1` has been deliberately set.

## Project structure

```text
ember-coreui/
  admin/               isolated Admin Core for levels 0 and 1
  api/                 local REST, SSE, upload, and CRUD endpoints
  assets/              local fonts and chat media
  config/              runtime, Modelfile, and native templates
  css/                 independent Holo HUD design
  database/migrations/ complete E.M.B.E.R. CoreUI schema
  docker/              dedicated PHP, Playwright, and Python-worker images
  docs/                detailed public release changelogs
  images/              local UI images
  js/                  streaming, session, deduplication, and tool logic
  scripts/             parallel installer, native installer, and preflight
  tools/               Lore, browse, and optional Python workers
  uploads/             local browser screenshots
  var/                 database, runtime configuration, and caches
  compose.yaml         isolated standard stack
```

## Separate versioning

E.M.B.E.R. CoreUI versions are not equivalent to Repack versions. The mobile fix from Repack patch `v1.1.1.96` is included, but does not determine the E.M.B.E.R. CoreUI release sequence.

## Copyright and legal finalization

Copyright: `© 2026 Patrick Schildgen, Starlight Unit Studios.`

The studio logo is used byte-for-byte from the approved original PNG supplied by Starlight Unit Studios. E.M.B.E.R. CoreUI does not redraw its contours or add a dark outline. The approved SHA-256 fingerprint is enforced by the release self-test. The licenses of the locally bundled fonts are documented separately under `assets/fonts/LICENSES.md`. The new license does not replace the still-pending legal review of a later final release.

A complete German legal notice and a final privacy policy are deliberately not yet included as finished legal pages. Before the public final release, the actual operator information, serviceable address, representation details, contact channels, hosting data-processing arrangement, and desired privacy texts must be established. The package does not invent such information.

## Alpha status

`0.5.3-alpha` completes exact single-expression integer calculations locally so a model or Python timeout cannot leave the private Core Channel waiting without an answer. Persistent feedback, alternative-answer navigation, true in-place editing, queued follow-up text, safe local Markdown, code blocks, continuations, response details, profile images, TXT decoding, installer verification, attachment reconstruction, private RAG-Lite retrieval, and isolated Python execution remain included. Speech input, native model audio transport, and spoken TTS output remain scheduled for the separate audio stage. The free E.M.B.E.R. CoreUI Community Source License introduced in `0.4.2-alpha` remains unchanged. There is no user counting, phone-home, license key, paywall, or paid E.M.B.E.R. CoreUI offering.
