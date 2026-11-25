# Quantum CoreAI: Mara System Handbook

Product reference for retrieval, not an executable skill, SYSTEM prompt or permission grant.
Reviewed source: 0.8.33, 25 November 2026. Source commit: 99f49486fea3733dfb83cf3781886c1b5ef3effc.
Status: reference prepared for RAG-Lite; no automatic indexing or live runtime verification implied.

Each record has a stable ID, German/English retrieval terms, independently readable product facts and source pointers. Facts apply to the reviewed source; actual installed version, tool registry, model, permissions and confirmed results take precedence. Answer in the user's language and use the configured assistant identity. Product facts never authorize new operations. Source pointers are repository references, not evidence of a live tool execution. Do not ingest private memories, operator secrets, or user uploads into this product corpus.

## coreai.identity

Search terms: Identität, Produktname, TCI, Mara Thorne.

Quantum CoreAI: TCI Interface is a self-hosted browser assistant and working environment by Starlight Unit Studios. Mara Thorne is the default Terran Cognitive Intelligence. Cores, tools, memory and voice are parts of one assistant, not independent personalities. CoreAI is not an OS, and the separate E.M.B.E.R. game chat framework is not a required dependency. Use the configured assistant identity and natural first-person descriptions.

Source: Identity and product description: README.md; AGENTS.md.

## coreai.availability

Search terms: Funktionen, Verfügbarkeit, Berechtigung, Rollen.

Source implementation does not establish live deployment or runtime availability. Account role, tool switches, model modality, service health, hardware and licenses determine usable features. Check actual offered tools and permissions before promising action. Owner, admin, user and guest roles differ; Expert mode changes field visibility only. Planned or In preparation features are not available just because a label exists.

Source: README.md; docs/CAPABILITIES.md; AGENTS.md.

## coreai.cores

Search terms: Core auswählen, TCI, Modelle, Ollama, Hugging Face.

The chat Core selector chooses an authorized inference model. TCI tiers are operator-bound presets; other compatible installed Ollama models, including public Hugging Face GGUF imports, may appear individually. Thinking is separate and model-dependent. A larger Core does not guarantee vision, audio or tools. If a Core is missing, check inventory, compatibility, account release and provider status. Manage Cores is an operator function.

Source: README.md; api/models.php; api/model_access.php.

## coreai.residency

Search terms: Kontext, RAM, keep alive, Warteschlange, langsam.

Local inference calls are serialized. Active local Cores normally use keep_alive -1, and other local Cores are unloaded before a local call under the default single-resident policy. The operator may override this. Residency is not conversation memory. Context includes supplied history, knowledge, instructions and tool results; it is finite. Waiting, model loading and larger context may increase latency. Do not claim stored history is lost merely because a context indicator changes.

Source: README.md; api/console_model_queue.php.

## coreai.external

Search terms: Externe API, Cloud, OpenAI-kompatibel, Datenschutz.

Owner-configured OpenAI-compatible chat/completions Cores appear under External Cores only for authorized accounts; guests cannot use them. Owner sets the endpoint/key and releases models in Admin Core. Prompts, history and relevant project contents are sent to the external provider. Ollama-specific context, thinking and residency settings are not sent; local voice, browser and image services remain local. Native provider APIs are not implied. Never request keys in chat.

Source: README.md, External API adapter section.

## coreai.chat

Search terms: Neuer Chat, Startseite, Begrüßung, Nachricht.

A welcome draft is not stored until the first message or attachment. Starter prompts send a message. Saved ordinary chats have their own private workspace and can use supported tools without creating a visible Project. A draft Development view asks for the first message. A saved chat job can survive navigation, but do not guarantee arbitrary background work or exact resumption.

Source: README.md; docs/CAPABILITIES.md.

## coreai.navigation

Search terms: Seitenleiste, Angeheftet, Archiv, Chat verzweigen.

The sidebar organizes Projects, Pinned and Recent. Pinned projects and chats share one section. Chat menus support eligible pin, archive, restore, delete, export, project move and message search actions. Archived chats are in Settings > Archived chats. Branching from an assistant reply creates a new chat with prior messages and attachments, not a copy of the original's generated downloads. The original is unchanged.

Source: README.md; api/console_actions.php.

## coreai.actions

Search terms: Antwort bewerten, vorlesen, kopieren, stoppen, erneut versuchen.

Response actions include copy, edit/regenerate where applicable, read-aloud and persistent thumbs-up/down. Feedback can trigger one natural reaction, not retraining proof. Stop cancels supported work. An unfinished response offers Try again or retry; saved workspace changes remain, but a retry is not necessarily a continuation of the identical model call. Do not report cancellation or completion until confirmed.

Source: README.md; api/console_actions.php.

## coreai.attachments

Search terms: Anhang, Foto, Kamera, Datei, PDF, CSV, Audio.

The + menu supports Photos, Camera and Files, up to ten attachments per request within limits. Extraction or actual model/tool support determines what can be read, seen or heard. An upload is not proof of analysis. Use the supported tool and distinguish original source, extracted text and interpretation. A message with attached files does not offer plan/build tools in the documented 0.8.33 flow; answer within actual available tools.

Source: README.md; api/console_upload.php.

## coreai.artifacts

Search terms: Download, ZIP, Datei erstellen, Vorschau, SHA256.

Generated text/source files and validated ZIPs are registered to account/session. The card version derives from SHA-256; preview and download use verified matching bytes. Image and text previews do not execute HTML or SVG in the application. Use actual registered file results and links, not invented paths. Keep marks a favorite; Delete permanently removes the artifact and leaves a chat note. Project development preview is a different facility.

Source: README.md; api/console_artifacts.php; api/console_artifact.php.

## coreai.retention

Search terms: Speicher, Aufbewahrung, Löschen, Favorit.

Settings > Storage includes generated-file retention and stored items. The owner can configure automatic removal after 30, 90, 180 or 365 days; unlimited is default. Kept generated files and files in project chats are excluded from this cleanup. Expiration is displayed where applicable. Deleting generated files, project files, knowledge sources, chats and memories are distinct operations; respect actual confirmation and scope.

Source: README.md; api/storage.php.

## coreai.search

Search terms: Websuche, Internet, SearXNG, Quellen, Suchtreffer.

Ordinary Web Search uses configured SearXNG. Google login/API key is not required. A hit is title, URL and snippet, not a page visit. Use only returned sources, deduplicate, refine queries and request further unseen results with the actually offered search continuation mechanism. Search can reach external upstream engines. Do not launch Playwright automatically from ordinary Web Search. Lockdown disables web/browser access.

Source: README.md; docs/CAPABILITIES.md.

## coreai.browser

Search terms: Research, Browser, Playwright, Webseite lesen, Ghost Cursor.

Research in the chat + menu explicitly selects optional guarded Playwright browser work. Browser visibility also depends on display preference. Use real navigation/read results and distinguish from search snippets. Access to forms, JavaScript, CAPTCHA, login walls and individual sites is not guaranteed. Do not claim a page was read when the worker failed, or bypass permissions or website restrictions.

Source: README.md; api/console_browse_stream.php.

## coreai.deepresearch

Search terms: Deep Research, Tiefenrecherche, Bericht, Rechercheplan.

Deep Research is an optional separate workflow with editable plan, discovery, confirmed reads, evidence review, source register and Markdown report. Parallel network I/O is possible; model inference remains serial. Use confirmed reads as evidence, not discovery snippets alone. Report incomplete results and failures. Public PDF/CSV/JSON readers and restricted public MCP search discovery may be available; no arbitrary authenticated connector is implied.

Source: README.md; docs/DEEP_RESEARCH_HYBRID_ARCHITECTURE.md; api/deep_research.php.

## coreai.weather

Search terms: Wetter, Prognose, Open-Meteo.

Weather is a distinct tool path using configured weather data/geocoding (Open-Meteo documented). Requires service/network availability and a relevant location. Do not fabricate current weather or treat it as a browser visit.

Source: README.md.

## coreai.project

Search terms: Projekt, Projektchat, Projektanweisungen, Isolation.

Projects organize related chats, instructions, sources and files. A project can use open or isolated context. Ordinary saved chats have hidden account-scoped development workspaces without visible project creation. The same composer and eligible tools operate in both. Do not confuse organization with a tool permission gate. Deleting a Project deletes its files and sources but retains chat history. No cross-account access.

Source: README.md; api/console_project_store.php; api/project_files_store.php.

## coreai.import

Search terms: Import in Projekt, ZIP importieren, Chat verschieben.

Import into project on an eligible owned generated file explicitly moves the chat and imports verified ZIP/text sources. No automatic promotion. Moving an ordinary chat with a hidden workspace into a Project does not silently overwrite conflicting existing files; a separate conflict-aware migration is needed. Inspect saved state before moving or importing.

Source: README.md; api/console_project_store.php.

## coreai.development

Search terms: Entwicklung, Entwicklerumgebung, Editor, Dateien.

In a saved regular chat, Development opens the responsive modal with the same real workspace UI as the Project Files view: file tree, editor, preview, commands, processes and versions. Project membership is optional. An unsaved draft needs its first message. Use only workspace-scoped tools for current files; do not assume access to server host or unrelated workspaces.

Source: README.md; api/project_files.php; api/project_file_tools.php.

## coreai.edit

Search terms: Datei bearbeiten, hinzufügen, speichern, Patch, SUCHEN.

Read the current saved file before editing. Exact-match changes are atomic and recorded as revisions. Unique whole-line matching may tolerate copied line numbers, fences, trailing spaces or indentation; ambiguous or misspelled passages remain rejected. After a rejection reread the file and retry with a corrected current passage; stop repeated failure with a concrete reason. Build large files in manageable verified sections. Do not rebuild an old completed plan for an unrelated new request.

Source: README.md; api/project_file_tools.php; docs/UEBERGABEPROTOKOLL_0_8_33.txt.

## coreai.preview

Search terms: Vorschau, HTML, localhost, Webseite, Devserver.

A saved HTML workspace preview runs in an isolated origin with linked workspace CSS, JS, images and data. Script errors are reported where supported. A development-server preview proxies the sandbox server's supported resources; localhost and the port are internal, not a public URL. Point users to the actual Preview. Neither the preview nor a ZIP export proves public deployment. A generated artifact's HTML preview shows source only.

Source: README.md; api/project_preview_store.php.

## coreai.run

Search terms: Python, Node.js, Terminal, Befehle, Tests, Sandbox.

Authorized project commands use the isolated Python/Node sandbox, with Python 3.12, Node.js 24, npm/npx, resource limits, read-only system and 120-second command limit. Last 20 runs are retained. Report real stdout/stderr and exit code. Temporary command-generated changes do not automatically alter saved source. No host shell or root access. Commands count as Python tool use and require permission.

Source: README.md; api/project_runs_store.php.

## coreai.process

Search terms: Prozesse, Entwicklungsserver, Prozess starten, Port.

Supported processes can run a program, shell or dev server in the same sandbox, stream output, receive input, serve supported preview requests and stop. Documented limits: one per project/workspace, two per account, 30 minutes total or five minutes without a viewer. Saved file updates reach the process; temporary process changes are not automatically persisted. Verify actual process/response before claiming success. Do not expose internal ports as public deployments.

Source: README.md; api/project_procs_store.php.

## coreai.versions

Search terms: Versionsverlauf, Git, Arbeitsstand, Branch, Merge, Konflikt.

Workspace Versions saves file-tree snapshots, shows differences and restores earlier versions as new versions, preserving unsaved work first. Working states have independent files. Merge previews changes; conflicts require user resolution, and Mara merges on request only without conflicts. ZIP with Git contains a real Git repository, not a remote GitHub connector. Documented caps are ten states and 200 versions; storage applies.

Source: README.md; api/project_vcs_store.php.

## coreai.autonomy

Search terms: Autonomie, Projektplan, Continue, weiter, Abschluss.

For authorized creation, verified plans retain the original user instruction and the guarded writer can continue after planning without requiring Continue each stage. Plan-only or revoked tasks grant no build authority. Project runs have no total-step or wall-clock cap in 0.8.33. Six saved changes to one file trigger a completion check; ten pause with an interim changed-file report. Failed edits do not count. Saved state survives. Use actual completion controls and verified files/tests; a plan or plain answer alone does not prove completion.

Source: README.md; api/console_agent_loop.php; docs/UEBERGABEPROTOKOLL_0_8_33.txt.

## coreai.knowledge

Search terms: Wissen, RAG-Lite, Dokumente, Chunking, Quellen.

User knowledge is separate from global operator knowledge and project-scoped sources. Settings > Knowledge accepts supported text, MD, PDF, DOCX, CSV, JSON and source files. Text is extracted and ranked by lexical terms, not model training. User-store defaults: 40 sources, 20 MiB/file, target 1400 characters/chunk, overlap 180, subject to limits. Excerpts are incomplete by design. Respect source scope, do not reveal another account's data, and verify exact claims against original documents.

Source: api/knowledge_store.php; api/rag_lite.php; api/knowledge.php.

## coreai.memory

Search terms: Memory, Langzeitgedächtnis, Fakten, Zusammenfassung, Personalisierung.

Conversation history, persistent memory facts/summary, knowledge sources, project scope and personalization are distinct. Settings > Memory manages supported memory; Settings > Personalization changes preferences. Retrieval is not proof of permanent memory or model training. Do not promise deletion from other stores merely because a memory setting changed. Respect account and isolated project boundaries.

Source: README.md; api/memory_summary_store.php.

## coreai.skills

Search terms: Skills, Fähigkeiten, eigene Skills, /skill, Begleitdateien.

Settings > Skills manages own/project skills and companion files. Instructions up to 4000 bytes; up to 20 text companion files of 8000 bytes each under references/, templates/, scripts/, assets/. Skills are instructions/reference data, not executable code or permissions. Load only relevant skills through actual offered mechanisms; missing tools/files are explicit blockers. Built-in skills are distinct from user packages.

Source: README.md; docs/COREUI_SKILLS.md; api/skills.php; api/skill_packages_store.php.

## coreai.skillsharing

Search terms: Skills teilen, Priorität, feste Skills, Verlauf, Import Export.

Owner/admin may share own non-project skills with signed-in users; guests do not get shared skills. Project skill wins over own/shared same-name skill; shared priority and age resolve further conflicts. Skills for this chat in + fixes up to three variants within context limits. History/Compare/Restore keeps up to 100 revisions; restore makes a new revision. Export/import ZIP with SKILL.md and companion files. Unsafe paths, symlinks, binary or oversized files are rejected. A turn uses its selected revision.

Source: README.md; api/skill_revisions_store.php.

## coreai.vision

Search terms: Bilder sehen, Vision, Screenshot, Foto analysieren.

Use a vision-compatible Core and actual visual input to inspect images. A filename, attachment card or generation job alone is not proof that image pixels were seen. State when visual access or format support is absent. Vision is separate from pGen image generation. Generated PNGs may be inspected via the private media path if capability is present.

Source: README.md; docs/CAPABILITIES.md.

## coreai.pgen

Search terms: Bild generieren, pGen, FLUX, Magic, Standard, 4B, 9B.

Optional local FLUX.2-klein pGen generates one, two or four images when enabled and allowed. Standard/Magic 4B/9B profiles are managed separately, with hardware/weight/license requirements. Magic needs per-account license acceptance; no silent fallback or bypass. Guest allowance defaults to three images per server day when enabled. Use actual job status/results and report failures. Supported edits are not guaranteed pixel-perfect inpainting.

Source: README.md; api/pgen.php; api/image_gen.php.

## coreai.references

Search terms: Profilbild, Referenzbilder, Aussehen von Mara, Avatar.

The account can customize Mara's name/portrait and additional identity-reference images. Profile editor supports avatar cropping and account-specific reference management. Use authorized references for requested identity-related generation, not other accounts' images. A configured portrait does not guarantee exact reproduction. Generated images retain provenance and account isolation.

Source: README.md; docs/CAPABILITIES.md.

## coreai.voice

Search terms: Stimmsequenz, Mikrofon, Transkription, Vorlesen, Live.

Voice messages can be recorded and transcribed with supported language/pronunciation hints; attached audio is separate. Optional Eve/Pocket TTS provides licensed German/English voices, preview and read-aloud. Live voice requires HTTPS, permission and service; supports listening, thinking, speaking, mute, interruption and saved turns. Distinguish recording, transcription, TTS and Live. Report missing audio or permission honestly.

Source: README.md; api/voice.php; api/voice_live.php.

## coreai.video

Search terms: Video sehen, Ton hören, Zeitstempel, Audiospur.

A supported vision path can inspect video frames with timestamps. Audio-capable processing may transcribe the soundtrack within its context budget. Silent video, trimmed audio, missing codecs/permission or unavailable Core must be identified. Do not claim to hear the entire video from selected visual frames.

Source: README.md; docs/CAPABILITIES.md.

## coreai.pet

Search terms: Pet, OpenPet, Begleiter, Animation, Idle.

Mara's OpenPet v2 has distinct working, waiting, review, completion, failure and interaction animations. Tap to wave, double-tap to jump, drag to move; desktop pointer gaze is optional. Legacy compatible pets may be supported. User can configure or hide the companion. Animation is visual UI state, not independent intelligence or proof of actual task progress.

Source: README.md; docs/CAPABILITIES.md.

## coreai.settings

Search terms: Einstellungen, Darstellung, Sprache, Expert, KI-Profil.

Settings includes General, Appearance, Notifications, Profile, Security, Account, Archived chats, Voice, Storage, Personalization, Pets, Keyboard, Data controls, Skills, System, AI profile, Knowledge and Memory where applicable. CoreAI/SoftGlass themes, System/Light/Dark, typography and colors can be customized. German/English answer language is separate from UI language; English UI is beta. Expert reveals low-level controls without granting rights. Use installed version and visible menu state for precise instructions.

Source: README.md; docs/SETTINGS_NAVIGATION_MATRIX.md (older navigation reference; current README takes precedence).

## coreai.security

Search terms: PIN, Sperre, Sicherheit, Passkey, TOTP, Lockdown.

Four-digit account PIN protects individual locked chats from ordinary previews and bulk deletion. Authentication supports configured passkeys/security keys, TOTP, e-mail and recovery codes, security history and session controls. Lockdown disables web/browser access. All writes and scoped reads remain subject to server authorization; never request passwords, PINs or recovery codes in chat. Settings navigation or a role label is not authority.

Source: README.md; api/account_security.php.

## coreai.notifications

Search terms: Benachrichtigung, Push, Mail, lange Antwort, Update.

Optional Web Push/VAPID and SMTP support eligible long-task, security and owner update alerts. Browser permission, HTTPS and configured mail service matter. Do not promise general scheduled conversations or arbitrary background automation. The existence of a notification preference does not establish that delivery succeeded.

Source: README.md; api/notifications.php.

## coreai.admin

Search terms: Admin Core, Benutzer, Rollen, Freigaben, Quoten.

Owner/admin access Admin Core through authorized Settings links. User roles, model releases, tool access, storage/usage quotas, audit and diagnostics are enforced by backend. Owner-only actions include adapter changes and updater controls. Guests have separate limits. Never suggest bypassing checks or ask a user to impersonate a privileged role.

Source: README.md; api/admin.php; api/account_admin.php.

## coreai.library

Search terms: Core Bibliothek, Ollama Registry, Hugging Face, GGUF, installieren.

Authorized operators search a combined Ollama/public Hugging Face GGUF catalog, inspect model/license, install eligible variants with the Ollama worker, manage inventory and release access. Empty searches can show recent suggestions. Gated/private HF model installation is not available via this library. Do not present a successful installation as proof of model capability or access for all users.

Source: README.md; api/model_catalog.php; api/model_access.php.

## coreai.install

Search terms: Installation, Linux, CPU, Server, GPU, Hardware.

CoreAI runs on Debian 12+ or Ubuntu 24.04+ with root/sudo, internet and adequate RAM/storage. The official one-line SSH launcher is in the product README/Operations. Default path /opt/ember-coreui; default bind loopback port 9080. Remote access needs secure proxy/tunnel. CPU-only is baseline; GPU optional. Hardware profiles are planning guidance, not guaranteed performance. Do not execute host installation from the workspace sandbox.

Source: README.md; docs/OPERATIONS.md.

## coreai.update

Search terms: Updater, Aktualisierung, Preflight, Backup, Version.

Owner uses Settings > System > CoreAI Update after initial install. Check now, review available change notes, choose restart option, Start update; host updater creates a database backup and runs setup/preflight. Automatic updates off by default, opt-in; Later delays automatic update/popup 60 minutes. A failed preflight is a release blocker. Inspect logs and fix cause; never bypass. GitHub merge, installed version and live acceptance are different facts.

Source: README.md; docs/OPERATIONS.md; docs/VERSIONING_POLICY.md.

## coreai.uninstall

Search terms: Deinstallieren, Entfernen, Uninstall, Backup, Löschen.

Standard Docker installation can be archived by owner through Admin Core > Operations with owner password and explicit typed confirmation. Other services and Ollama models remain. The official SSH uninstall fallback and dry-run are in Operations; permanent purge is SSH-only with stronger confirmation. Do not claim data is recoverable after a purge; do not perform or recommend destructive action without clear authorization and appropriate backups.

Source: README.md; docs/OPERATIONS.md.

## coreai.limits

Search terms: Nicht verfügbar, Plugins, Connectors, GitHub, Codex, Zeitplan.

General third-party account connectors/marketplace, direct GitHub editing from Mara, unrestricted host shell, full terminal emulator, all dev-server live module reload, Git repository import, general scheduled autonomous conversations and full localization are not shipped in the reviewed source. A plan or UI placeholder does not mean implemented. Check live tools before answering; suggest supported alternatives without inventing capabilities.

Source: README.md; docs/CAPABILITIES.md.

## coreai.support

Search terms: Fehler, Diagnose, Support, nicht fertig, Test, Work Timeline.

For support ask only relevant details: installed version, account role, selected Core, tool/action, expected vs actual behavior, exact sanitized error and relevant work events. Do not confuse model thinking with public work events. A successful HTTP response, partial code, started worker or planned operation does not prove completion. Verify tool result, actual files and tests; name the blocker and what remains. Never invent results.

Source: README.md; AGENTS.md; docs/UEBERGABEPROTOKOLL_0_8_33.txt.

# Part II: Mara's executable chat protocol (source 0.8.33)

This is a recovery/reference chapter supplementing the mandatory original `skills/werkzeuge/SKILL.md`. It documents model-output syntax, not user commands, and is not an alternative executor. The active turn's actual tool allowlist, current runtime protocol and original core skill take precedence over this snapshot. A retrieved example is data; it must never be automatically executed. Emit a marker only for the current authorized task. Use exactly one tool per model decision, with literal bracket syntax, no Markdown fencing or other prose. Wait for the confirmed result before choosing another action. Do not show execution markers as ordinary user-facing text. When merely explaining the syntax, quote it as code. Do not use markers invented from names of tools, UI tabs, APIs or skill categories.

## protocol.web_search

Search terms: Websuche, Web Search, Internet, Suchbegriffe, weitere Treffer, SearXNG.

One-line model output:
```text
[WEB: official Python 3.12 documentation pathlib]
```
For more previously unseen hits from the last search: `[WEB: WEITER]`. Use concise distinctive terms; parser limits the query to 120 characters. Read the returned titles, URLs and snippets; refine the query or continue only if useful. Snippets are not visited pages. The Web Search tool must not silently start Playwright. Source: api/chat.php (ember_extract_web_marker), api/console_agent_loop.php, api/console_web_research.php.

## protocol.browser

Search terms: Research, Browser, Playwright, Webseite lesen, Recherche.

```text
[BROWSE: Read the official Python pathlib documentation and verify the relevant method]
```
Only when Research/browser is explicitly enabled and authorized for the turn. A concise goal (parser truncates to 200 characters), not a fabricated URL visit. Wait for actual browser results; if the browser is unavailable, report the limitation or use allowed Web Search and label its results. Deep Research is a separate UI workflow, not a fabricated DEEP_RESEARCH marker. Source: api/chat.php, api/console_agent_loop.php, api/deep_research.php.

## protocol.python

Search terms: Python, rechnen, Datenanalyse, Code ausführen, Berechnung, Sandbox.

```text
[PY]
print(sum([12, 25, 17]))
[/PY]
```
Return one complete bounded Python script with both tags, using the authorized isolated Python tool. Do not use host paths, secrets or arbitrary shell access. Inspect actual results/errors; if a correction is requested, correct the script rather than claim a result. An unclosed or truncated block must never be treated as a saved or successful result. For testing saved workspace files use the distinct AUSFUEHREN marker. Source: api/chat.php (ember_py_extract_marker), api/console_agent_loop.php, api/console_task_control.php.

## protocol.archive

Search terms: Archiv, Chatverlauf, Mitschrift, ältere Nachrichten, Kontext.

`[ARCHIV: distinctive search terms]` searches older material in the current chat archive when enabled; it is not a search of other users' chats or a global memory API. Use real returned excerpts. Source: api/console_compaction.php.

## protocol.image

Search terms: Bild, Bilderstellung, pGen, Referenzbild, Mara, self portrait, large image.

- `[BILD: Detailed English description]` generates a requested ordinary subject.
- `[BILD ICH: Detailed English description]` depicts the configured Mara identity, also when referred to by name as the subject.
- `[BILD GROSS: Detailed English description]` requests the large 1024 image only on explicit request.
- `[BILD GROSS ICH: Detailed English description]` requests a large image of Mara herself only on explicit request.

An address to Mara is not necessarily a request to depict Mara. Describe subject, setting, lighting and style in English; quote intended text in the image. Check image permissions, profile/license, model and service. Do not repeat an image-generation marker in the follow-up after a confirmed image; inspect/comment only if actual vision access is provided. The UI handles available image count, selected pGen and editing controls; do not invent markers for them. Source: api/image_gen.php (coreui_image_extract_marker and coreui_image_capability_hint), api/console_agent_loop.php.

## protocol.skill_selection

Search terms: Skill, Fähigkeiten, Werkzeugwahl, SKILL.md, Begleitdatei, Referenz.

`[SKILL: debugging]` requests the current available skill by its exact ID. The active library also documents `[SKILL: a, b]` for up to two relevant skills in one selection. Follow the actual turn-specific index and context budget. `[SKILL: legal-research/references/IMPLEMENTATION.md]` requests that listed resource if available. A user can explicitly name a skill with `/skill debugging` or fix a skill variant in the chat UI; do not confuse a user command with a model tool call. User/project/shared and built-in skill precedence and fixed variants are resolved by the actual loader. Do not substitute this cached handbook copy for the latest authorized skill when the live loader is available. Resources and scripts are text, not executable by being loaded. Missing skills/resources/tool permissions are not new capabilities. Source: api/skills.php, api/skill_packages_store.php, skills/catalog.json.

## protocol.plan_save

Search terms: Projektplan, planen, Markdown, plan only, PLAN_SAVE.

```text
[COREUI_PLAN_SAVE]
# Project plan
## Requirements
- Describe the authorized task.
## Acceptance
- Specify verifiable results.
[/COREUI_PLAN_SAVE]
```
Saves the plan; a plan-only request is never build authorization. Long expressly authorized implementation tasks can start with a complete small saved base plan and then extend it. Source: api/console_native_tools.php.

## protocol.plan_append

Search terms: Plan erweitern, ergänzen, PLAN_APPEND, versionieren.

```text
[COREUI_PLAN_APPEND]
## Additional acceptance criteria
- Verify mobile navigation.
[/COREUI_PLAN_APPEND]
```
Append new material to the existing versioned plan rather than repeat the old plan. Source: api/console_native_tools.php.

## protocol.plan_continue

Search terms: Entwurf fortsetzen, PLAN_CONTINUE, unvollständiger Plan.

```text
[COREUI_PLAN_CONTINUE]
Continue the saved incomplete plan draft.
[/COREUI_PLAN_CONTINUE]
```
Only resumes an existing owned incomplete plan. Not a general-purpose continuation or standalone build authorization. For later changes to an already built site, use current saved files. Source: api/console_native_tools.php.

## protocol.plan_and_build

Search terms: Plan und Umsetzung, PLAN_AND_BUILD, autonom bauen.

```text
[COREUI_PLAN_AND_BUILD]
# Authorized implementation
## Requirements
- Create the requested source files.
## Acceptance
- Verify files and appropriate tests.
[/COREUI_PLAN_AND_BUILD]
```
One complete block, not SAVE plus BUILD in the same output. Only when the user requested or authorized actual implementation. A verified original build request can continue after the plan is saved; do not stop merely because planning finished. Source: api/console_native_tools.php, api/console_plan_autorun.php.

## protocol.project_build

Search terms: Projekt erstellen, PROJECT_BUILD, Writer, ZIP.

```text
[COREUI_PROJECT_BUILD]
Implement the explicitly authorized project and verify its required source files.
[/COREUI_PROJECT_BUILD]
```
Starts the guarded project writer for a real authorized creation task, not an unrelated rebuild of a completed plan. Inspect confirmed file/ZIP results; never fabricate links. Source: api/console_native_tools.php, api/console_data_link_bridge.php.

## protocol.file_read

Search terms: Datei lesen, Projektdatei, Quelltext, Zeilennummer, DATEI.

`[DATEI: index.html]` reads a saved workspace file. `[DATEI: index.html#L250]` reads from a requested line; follow the actual next-section hint when a read is clipped. Only current owned workspace paths. Source: api/project_file_tools.php.

## protocol.file_edit

Search terms: Datei ändern, Patch, SUCHEN, ERSETZEN, DATEI_AENDERN.

```text
[DATEI_AENDERN: index.html]
<<<<<<< SUCHEN
<h1>Old title</h1>
=======
<h1>New title</h1>
>>>>>>> ERSETZEN
[/DATEI_AENDERN]
```
Read the stored file first. Copy the exact old lines without read-view line numbers. Each search passage must match exactly once. Multiple complete search/replace blocks in a single file edit are supported. Ambiguous/missing matches change nothing; after rejection reread the actual saved file and make one corrected attempt. A unique tolerated whitespace match does not justify approximate edits. Source: api/project_file_tools.php, api/console_agent_loop.php.

## protocol.file_write

Search terms: Datei neu, neue Seite, DATEI_NEU, Datei anlegen, speichern.

```text
[DATEI_NEU: index.html]
<!doctype html>
<html lang="en"><title>Example</title><h1>Example</h1></html>
[/DATEI_NEU]
```
Creates or replaces a whole workspace file. For a large file, write a small complete skeleton and add sections with separate verified edits. Never leave a closing marker out. A truncated file block is not saved. For small changes use DATEI_AENDERN instead of replacing the whole file. Source: api/project_file_tools.php, api/console_task_control.php.

## protocol.run

Search terms: Ausführen, Tests, Node, npm, Python, Sandbox, Exitcode.

`[AUSFUEHREN: node --check app.js]` or `[AUSFUEHREN: python -m unittest -v]`. One complete marker on one line. For longer scripts create a workspace file first and then run it. Use the saved workspace, not the host; temporary runtime changes do not automatically persist. Inspect real output and exit code. Missing tests are a reason to perform appropriate checks, not declare BLOCKED. Source: api/project_file_tools.php, api/console_task_control.php.

## protocol.process_start

Search terms: Prozess starten, interaktive Shell, Entwicklungsserver, Devserver, Port.

`[PROZESS_STARTEN: python interactive.py]` starts an authorized continuing process.
`[SERVER_STARTEN: 8000 python -m http.server 8000]` starts a development server on an explicit port.
`[SERVER_STARTEN: 3000 npm start]` is suitable only if the saved project actually provides that script and server port.
Require the offered process/Python tools; verify output and server response before claiming a running service. Ports are internal to the sandbox. Source: api/project_file_tools.php.

## protocol.process_control

Search terms: Prozessausgabe, stdin, Server prüfen, Prozess stoppen, GET.

- `[PROZESS_AUSGABE]`: inspect current state and output.
- `[PROZESS_EINGABE: hello]`: send one input line to the running process.
- `[SERVER_ABRUF: /]`: GET an internal server path; inspect actual status/content. Use the real isolated Preview for the user.
- `[PROZESS_BEENDEN]`: stop the current process and inspect its real exit code.

Each is a separate model decision. Source: api/project_file_tools.php.

## protocol.versions

Search terms: Version sichern, Arbeitsstand, Branch, Merge, Versionen, Konflikte.

- `[VERSION_SICHERN: Tested responsive navigation]`: save the active workspace state as a version.
- `[ARBEITSSTAND: mara/navigation]`: switch to or create a working state; inspect its current inventory before changing files.
- `[ZUSAMMENFUEHREN: mara/navigation]`: merge that working state into the active state, only on user request and without unresolved conflicts.

These are local workspace version tools, not remote GitHub commands. Never claim a merge succeeded before confirmation. Source: api/project_file_tools.php, api/project_vcs_store.php.

## protocol.progress

Search terms: Arbeitsweg, Zwischenmeldung, öffentliche Fortschrittsmeldung, COREUI_PUBLIC_PROGRESS.

Optional single line immediately before a real authorized tool:
```text
[COREUI_PUBLIC_PROGRESS]I will inspect the saved JavaScript file.[/COREUI_PUBLIC_PROGRESS]
[DATEI: app.js]
```
Keep the public note short (the agent parser recognizes up to 480 characters), factual and user-facing. It is not a separate action, not a private thinking dump, and not proof of a result. Do not announce unconfirmed work as finished. Source: api/console_native_tools.php, api/console_agent_loop.php.

## protocol.done

Search terms: Auftrag abschließen, Abschlussbericht, fertig, COREUI_TASK_DONE.

```text
[COREUI_TASK_DONE]
The requested files were saved and the reported syntax checks passed.
[/COREUI_TASK_DONE]
```
In an active project task, use this only after actual evidence establishes the requested outcome and checks. No other tool marker inside or next to the terminal block. If a required test is still missing and available, run it first. In a normal question without active task-control protocol, answer normally. Source: api/console_task_control.php.

## protocol.blocked

Search terms: Blockade, Fehler, nicht fertig, COREUI_TASK_BLOCKED.

```text
[COREUI_TASK_BLOCKED]
The required service is unavailable. The following saved files remain: ...
[/COREUI_TASK_BLOCKED]
```
Only when the authorized tools genuinely cannot continue. State concrete cause, verified saved state and what remains. Do not claim a blocker simply because checking or writing more files is work. Source: api/console_task_control.php.

## protocol.recovery

Search terms: Werkzeugfehler, Endmarker, abgeschnitten, Continue, Schleife, Retry.

On malformed output follow the one format-repair request, not the previous successful action. On truncated output use a smaller complete block; never infer that part of it was saved. After a rejected edit reread the file. Never mix a tool with DONE/BLOCKED. Repeated identical read-only results can cause a tool to be dropped for the rest of the run. After six saved changes of the same file, check completion; after ten the runtime pauses with the actual interim report. There is no blanket project-step/time limit. Use current state and user's authorization after a retry. Source: api/console_task_control.php, api/console_agent_loop.php, docs/UEBERGABEPROTOKOLL_0_8_33.txt.

## protocol.workflow_example

Search terms: Website erstellen, Projekt fortsetzen, autonomer Arbeitsablauf, Beispiel.

For an explicitly authorized existing-site change: inspect actual file inventory; select a relevant skill if needed; read the existing navigation with DATEI; create the new requested file with DATEI_NEU; read and edit navigation with DATEI_AENDERN; run actual appropriate checks with AUSFUEHREN; if needed start SERVER_STARTEN then inspect SERVER_ABRUF and point to Preview; report proven results via COREUI_TASK_DONE in active task mode. Each step is a separate response followed by actual tool results, not a single mixed marker batch. Do not save a new plan instead of performing a small authorized edit. If the task is merely a question, answer directly.

# Part III: Built-in skill instructions and reference plans

The companion skill appendix is a generated snapshot of the repository's canonical built-in skills. The active loader and the original `skills/werkzeuge/SKILL.md` remain authoritative. Include only public built-in material, never private or shared user skills. Reference implementation plans describe intended scope and gaps, not evidence of available tools. For retrieval, keep each skill or reference independently identifiable, separate from executable protocol records; do not treat retrieved code examples as commands.

