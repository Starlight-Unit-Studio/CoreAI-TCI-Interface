# CoreAI: TCI Interface

## 0.7.43 - 13.11.2026

- **Fix: Browse Docker image could not install Playwright Chromium.** On `python:3.12-slim-bookworm`, Playwright's bundled Node executable required `libatomic.so.1`, but the runtime package was absent. This failed the build during `python -m playwright install --with-deps chromium` after Python packages were already installed.
- **Image fix:** `docker/browse/Dockerfile` now installs Debian `libatomic1` via apt before running Playwright. No host package installation and no changes to the local Cores are required.
- **Docker-level regression test:** New GitHub Actions workflow builds the actual Browse image and launches Playwright Node plus headless Chromium as the image's unprivileged user, offline. This catches missing shared libraries that pure Python syntax/selftests cannot detect.
- **Unchanged:** Deep Research hybrid architecture (0.7.42), native Vision, model lock, persistent memory, CAPTCHA behavior, permissions, MariaDB schema and interfaces.

## 0.7.42 - 13.11.2026

- **Hybrid Deep Research:** A single Lead Reasoning trajectory coordinates isolated Map workers, reviews their evidence, pivots to new searches and synthesizes a report. This combines iterative depth-first reasoning with breadth-first worker delegation while retaining the single global Ollama lock for CPU-only use.
- **Parallel reading and context engineering:** Independent SearXNG/document network I/O runs concurrently; Worker prompts stay isolated to their own source pages, and only source-backed summaries reach the Lead. Optional configured Lead, Worker and Citation models use that same serial lock.
- **Durable state:** Migration 041 adds per-run checkpoints and retained source excerpts. A stale run with a checkpoint is returned to the queue for recovery rather than being discarded; all research state is deleted with its owning chat.
- **Iterative quality:** Evidence coverage is tracked per Worker, the Lead can add subtasks or focus follow-up queries, and weakly sourced topics get another bounded search round. Report synthesis uses the existing budget and an additional dedicated Citation Agent with deterministic source-reference checks.
- **Research tools:** Existing Playwright and native Vision remain; public PDF, CSV and JSON are now read through a redirect-guarded, bounded document adapter. The existing isolated Python job service can compute source-validated numeric summaries and Unicode sparklines. A public read-only MCP discovery adapter is available only when an operator explicitly configures and enables it.
- **Security and rollout:** No private chain-of-thought is stored, no proprietary RL training is claimed, no cloud-model requirement is added and no unapproved authenticated connector access is enabled. A backward-compatible legacy research path remains when the hybrid migration is absent or hybrid mode is disabled.
- **Verification:** New hybrid selftest, permanent CI workflow and both preflights cover the hybrid pipeline; production Playwright/Ollama/PDF and long-run DB-resume behavior must still be validated on a real deployment. See [architecture and limitations](docs/DEEP_RESEARCH_HYBRID_ARCHITECTURE.md).

## 0.7.41 - 13.11.2026

- **Fix: Deep Research preflight regression after 0.7.40.** Extra unattended source searches could move the reported progress backwards (e.g. 20% → 13%), causing the existing worker selftest and installation preflight to fail. Progress is now monotonically increasing, even when an additional search revisits an earlier phase.
- **Permanent regression guard:** Added GitHub Actions `Deep Research Worker Preflight` to run Python syntax, the complete existing `scripts/deep-research-worker-selftest.py`, and the release contract with visible failure diagnostics on affected PRs. The worker test passed after the fix in GitHub Actions (run 43393508494).
- **No unrelated changes:** Research search strategy, native Vision, Playwright, DB schema, user settings, Ollama lock and UI behavior remain unchanged.

## 0.7.40 - 13.11.2026

- **Deep Research source discovery fixed:** The previous exact-name filter wrongly rejected useful search summaries before visiting their pages. Candidate search is now broader; source acceptance still depends on the actually fetched document, never a search snippet. Relevant text deep inside long pages is prioritized for the local Core.
- **More search coverage and diagnostics:** Up to ten SearXNG results per query are ranked and evaluated, with bounded retries after blocked HTTP downloads. Each failed research step reports how many pages were checked and why they were rejected instead of silently finishing after a few seconds.
- **Optional visual Playwright fallback:** The existing browse worker can open a JavaScript-rendered public page when plain HTTP fails or provides no relevant text, retaining network restrictions and a browser context per research run.
- **Native Core vision:** A real JPEG browser screenshot is passed to the currently selected Ollama Core via the native `messages[].images` field under the same global serial lock. The Core can choose up to two bounded, safe UI actions per page, including reading, clicking public links and entering queries in public search forms. If image support is unavailable, rendered DOM text remains usable.
- **Unattended research and completion:** Anti-bot challenges are logged as inaccessible sources, not prompts for human input. Mara seeks publicly accessible alternatives, avoiding blocked hosts when possible, and completes the server-side run even if the chat is closed. The worker also posts a new completion message containing the existing report card in the same chat. Challenges are not bypassed or automatically solved.
- **Configuration:** `STU_DEEP_RESEARCH_BROWSER_PAGES_PER_STEP` (default 2, 0 disables browser), `STU_DEEP_RESEARCH_BROWSER_ACTIONS` (default 2), `STU_DEEP_RESEARCH_VISUAL_ENABLED` (default 1) and `STU_DEEP_RESEARCH_RESULTS_PER_QUERY` (default 10). All calls keep CPU-only compatibility and the existing model queue.
- **Tests:** Mocked browser and pure regression cases cover screenshots, constrained search-form actions, challenge detection and the JavaScript-page fallback. Complete worker suite, Playwright runtime, PHP preflight and live research still need verification.

## 0.7.39 - 13.11.2026

- **Deep Research: subject identity and better evidence.** Search ranking now favors exact people, projects and organizations over unrelated similar names, products or places, instead of maximizing domain variety.
- **More targeted queries.** Quoted names survive the query parser; planner drift is corrected by deterministic topic anchors. Operator-configurable defaults increase to four searches and four readable pages per step.
- **Verified citations.** Unreadable/blocked URLs and search snippets no longer become numbered sources. The worker tries additional ranked results to reach its page budget; fabricated or missing step-note citations are removed.
- **Balanced synthesis.** Every step and source register survives prompt truncation on smaller local Cores. The prompts distinguish first-party from independent claims and explicitly prohibit unsupported conclusions that profiles do not exist.
- **Honest gaps.** If no readable evidence is found, the report states the limits instead of filling space with off-topic findings.
- **Tests.** The worker selftest now contains synthetic name-collision cases and checks for prompt/query/source quality. The standalone pure helper was syntax-checked and locally exercised; complete worker integration, preflight and live SearXNG are not yet verified.

## 0.7.38 - 12.11.2026

- **Deep Research (roadmap P15), after the operator's reference screenshots:** Deep Research in the + menu is a real tool now (it needs the web search and is not for guests). Instead of an answer, Mara writes a plan: a short title and four to six steps. The plan card offers Bearbeiten, Abbrechen and Starten; without a click it starts after a 45-second countdown. Bearbeiten puts the plan into the input field as a chip; the next message changes it into a new plan.
- **The research runs in the background** in the existing browse worker: one Core call turns the steps into search queries, then per step the searches (SearXNG), the best new pages (plain HTTP, every redirect checked against local addresses, no JavaScript) and notes with the number of each source, then the report in Markdown. Citations that name no real source are removed. Mara's Core lock is taken per call, so the chat stays usable while she researches. Time and size limits can be set in `api/config.local.php` (`STU_DEEP_RESEARCH_*`).
- **While it runs** the card shows each step (open, active, done), "Ich recherchiere …", the number of searches, a progress bar and Stopp. A run whose worker went silent (an update or restart) ends with an honest message instead of turning for ever.
- **The report:** "Recherche abgeschlossen in … · N Quellenangaben · M Suchen" and a report card with a preview, download (Markdown with the numbered sources) and a full view with clickable citations, a table of contents and a sheet with the sources (by domain) and the activity (searches with their domains, pages read, Mara's short notes).
- **Data:** migration 040 (`stu_deep_research_runs`, `_sources`, `_events`), endpoint `api/deep_research.php` (status, download; start, edit, cancel with CSRF). The runs of a chat are deleted with it; a chat whose research is still running counts as busy.
- **Fixes:** the Data controls page now shows "Die Sitzung ist abgelaufen" for the server's real `csrf_failed` answer (0.7.32 only knew `csrf_invalid`). Version comments in settings.html carry their real versions again. The usage and + menu selftests read the nginx allowlist by pattern; the + menu UI selftest, failing on main before because of three outdated literal checks, passes again.
- **Tests:** `scripts/deep-research-selftest.php` (with and without database), `scripts/deep-research-worker-selftest.py` and `scripts/deep-research-ui-selftest.cjs` (Playwright) in both preflights.


## 0.7.37 - 12.11.2026

- **Mobile Projektmenues sichtbar:** Das ausserhalb der Seitenleiste gerenderte Drei-Punkte-Menue lag auf Ebene 180, waehrend die mobile Seitenleiste Ebene 999 und ihr Hintergrund Ebene 998 verwenden. Deshalb wurde ein funktionierendes Popup hinter beiden Ebenen verdeckt.
- **Direkter CSS-Fix:** Das bestehende Projekt-Popup wird jetzt auf Ebene 1100 angezeigt. Keine zweite Menue-Implementierung und keine Aenderung der Projekt-Aktionen oder API.
- **Regressionstest:** Der Projekt-UI-Selbsttest vergleicht ab sofort die tatsaechlich deklarierten Ebenen von mobilem Drawer, Overlay und Projekt-Popup. Er scheitert, wenn das Menue wieder dahinter geraten kann.
- **Bestehende Designs:** CoreAI Holo und SoftGlass behalten ihre Styles; nur die Menue-Ebene wird korrigiert.
- **Abnahme:** Browser-Rendering auf dem produktiven Smartphone nach Aktualisierung und Preflight pruefen.


## 0.7.36 - 12.11.2026

- **Verbindliche Versionskorrektur:** Das Uebergabeprotokoll `UEBERGABEPROTOKOLL_0_7_33.txt` legte nach Abgleich der tatsaechlichen Main-Merges 0.7.36 als naechsten Versionsstand fest. PR #290 wurde irrtuemlich mit 0.7.34 bezeichnet und gemergt. Dieser historische Merge bleibt unveraendert dokumentiert; die vorgegebene Fortsetzung ist jetzt 0.7.36.
- **Projektmenues:** Die bereits in PR #290 gemergte Korrektur fuer beide Drei-Punkte-Menues bleibt enthalten. Es gibt keinen Rueckbau der Funktion.
- **Keine erfundenen Artefakte:** 0.7.35 wird nicht nachtraeglich als veroeffentlichtes Paket oder ZIP dargestellt. Die Zaehlschritte 0.7.34/0.7.35 werden nur wie im frueheren Uebergabeprotokoll erlaeutert beruecksichtigt.
- **Release-Konsistenz:** Aktive Versionsmarker in sechs HTML-Oberflaechen, JS-Bootstrap, README, Preflights, Versionstests, Changelog und Browser-Cache-Fingerprints synchronisiert. Keine Datenbank- oder Laufzeitaenderungen.


## 0.7.34 - 12.11.2026

- **Projektmenüs:** Die Drei-Punkte-Buttons neben Projektordnern und in der Projektstartseite verwenden dieselbe echte Aktionsliste und dieselbe Floating-Menü-Implementierung.
- **Mobil:** Das Menü wird innerhalb des sichtbaren Viewports positioniert und bei einem Scroll-Ereignis nachgeführt, statt unmittelbar zu verschwinden.
- **Robustheit:** Gewöhnliche Aktualisierungen der Projektliste schließen das Menü der Projektstartseite nicht mehr; ein verlassenes oder außerhalb des Bildschirms liegendes Menü wird weiterhin sicher geschlossen.
- **Regression:** Der Projekt-UI-Test prüft nun beide Drei-Punkte-Auslöser, das Projektstartseiten-Ziel und die mobile Menüsteuerung.
- **Design:** Das vorhandene CoreAI Holo und das optionale SoftGlass UI bleiben unverändert.


## 0.7.33 - 12.11.2026

- **CoreAI theme over every settings page and Admin Core:** The pages built after the reference screenshots get the CoreAI look the rest of the interface already uses: no rounded pills, cut corners on boxes, buttons and icon buttons, the thin blue edge, monospace capitals on buttons, tabs and badges, the active tab underlined in the accent, segmented bars. Light and dark follow the existing colour variables. New file `css/coreui-settings-theme.css`, loaded after `css/coreui-theme.css`. The SoftGlass skin keeps its own rounded look; real circles (colour marks, status dots, photos) stay round.
- **Settings > General without duplicates:** General now holds only what lives nowhere else: language, Higher intelligence and Denkaufwand. The overview card, the four key figures (Core, System, Knowledge, Memory) and the 11 shortcut tiles repeated the navigation and the pages Profile, AI profile, System, Knowledge and Memory and are gone, with their code, styles and translations.
- **Expert settings marked:** Every setting behind the EXPERTENMODUS switch now says "(Expert)" after its title: own system prompt, token budget, temperature and Core path (AI profile), memory context and fact relevance (Memory). Without Expert mode they are hidden and the next setting takes their place, with no gap and no stray separator line; the note about external APIs goes with the Core path. The choice of Expert settings is unchanged: only raw technical tuning; the context slider, telemetry and live-browser display stay ordinary.
- **Settings layout fix:** Two stray closing tags (one after Pets, one after Cloud computer) closed the main column early, so Keyboard, Usage, Data controls and the planned pages appeared below the navigation instead of beside it on wide screens. Every settings page sits in the main column again.
- **Tests:** New `scripts/settings-theme-ui-selftest.cjs` (Playwright, both preflights): load order, every page in the main column and beside the navigation, General without duplicates, CoreAI geometry in light and dark, SoftGlass untouched, circles round, no sideways scrolling on a phone. the Expert marks, hiding without a gap and the separator are checked too, `settings-navigation-foundation-selftest.cjs` counts the tagged Expert settings. `settings-tabs-ui-selftest.cjs` and `appearance-ui-selftest.cjs` wait for the System status instead of the removed overview and check that the figures live on their own pages.


## 0.7.32 - 12.11.2026

- **Settings > Data controls after the reference screenshot:** One card with Shared links and Merge personal data (both marked In preparation: CoreAI shares chats as Markdown files and has no import yet), Export data (the existing JSON export), Archived chats (count and link), Archive all chats and Delete all chats. The rows the operator crossed out (model training, network access and reset of the reference's workspace product) are not part of CoreAI.
- **Archive all chats:** After a confirmation, every active chat goes to the archive and loses its pin; it can be restored under Archived chats.
- **Delete all chats:** After a confirmation and the typed word LÖSCHEN, every active and archived chat is deleted for good with its messages and files, through the same delete as the chat menu. A chat Mara is still working in stays (an active one comes back from the archive), and the result says so.
- **Locked chats** (PIN) are never archived or deleted by these actions and are counted separately.
- **Server:** `api/console_sessions.php` gets `data_summary` (GET), `archive_all` and `delete_all` (POST, CSRF, each with its own confirmation value), implemented in `api/data_controls_store.php`.
- **Tests:** `scripts/data-controls-selftest.php` (with and without database; own accounts, removed afterwards) and `scripts/data-controls-ui-selftest.cjs` (Playwright) in both preflights.


## 0.7.31 - 12.11.2026

- **Preflight fix for 0.7.30:** `scripts/storage-selftest.php` checked the nginx allowlist for the literal neighbours `|pets|files|profile|`. Since 0.7.30 lists `usage` after `files`, that check failed and an update to 0.7.30 stopped in the preflight ("nginx allows the storage endpoint"). The selftest now only requires `files` in the API allowlist, whatever stands next to it, and fails without it.
- **Scope:** Test change only; Settings > Usage and the storage endpoint are unchanged.


## 0.7.30 - 12.11.2026

- **Settings > Usage after the reference screenshots:** The page is no longer planned. Two tabs: Overview and Analytics. Only the account's own figures, numbers only, never chat content.
- **Overview:** The plan (as in Settings > Account) with its limits: the daily message limit as a 24-hour window from the first message, with the time until it resets, messages used and a bar; storage against its limit with a link to Settings > Storage; the plan's features. Below, today's messages by hour or the last 7 days.
- **Analytics (7 or 30 days, earlier ranges step by step):** tokens per day stacked by Core or by thinking level with shares; the chats with the most own messages (link to the chat; locked chats without title or link; more on request); the days with messages, tokens per Core, tools and skills; tokens by Core as lines with a Core menu; tools (web search, browser, Python, images) and skills per day; messages, all or by kind (text, voice messages and live turns, with attachment).
- **Honest scope:** CoreAI has no 5-hour or weekly limits and keeps no history of past daily-limit windows, so none are shown. Tokens are counted since migration 029 and the page says since when.
- **Server:** New read-only endpoint `api/usage.php` (overview, analytics, chats; guests 403) and `api/usage_page_store.php`; reading the daily limit never counts a message. nginx allowlist entry.
- **Tests:** `scripts/usage-page-selftest.php` (with and without database, rolled back) and `scripts/usage-ui-selftest.cjs` (Playwright) in both preflights; literal tests for the settings navigation (6 planned pages), the settings tabs (planned example) and the version pin updated.


## 0.7.29 - 12.11.2026

- **Settings > Keyboard after the reference screenshots:** Three groups (message editor, chat, app) list the shortcuts of the chat. Each one has a switch and its key combination; choosing the combination records the next key press. Esc cancels, Backspace alone removes a shortcut, Restore defaults brings back the defaults.
- **Shortcuts:** focus the chat input (Shift+Esc), send (Enter), choose thinking (Ctrl+Shift+M), dictation on/off (no key by default), attach files (Ctrl+U), delete the chat (Ctrl+Shift+Backspace), search in the chat (Ctrl+Shift+F), new chat (Ctrl+Shift+O), show shortcuts (Ctrl+/), switch chat (Ctrl+K), sidebar on/off (Ctrl+Shift+S); ⌘ on a Mac. Every action uses the existing control, so deleting still asks for confirmation. Sending in the background and sending annotations are shown as In preparation.
- **Checks:** A combination that another shortcut already uses is refused with its name, as are keys that would disturb typing (no Ctrl, Alt or Super), Esc alone, the browser's own keys (for example Ctrl+W) and the text editing keys (for example Ctrl+C).
- **In the chat:** Two new windows: the shortcut list and Switch chat (search by title, pinned chats first, arrow keys and Enter). While another window such as a confirmation is open, shortcuts do nothing. The hint under the chat input follows the send key.
- **Send key:** Replaces the two modes "Enter sends" and "Ctrl/⌘ + Enter sends"; a choice made with the old setting carries over, and the old setting is kept in step. Shortcuts are kept in this browser.
- **Tests:** New `scripts/keyboard-shortcuts-ui-selftest.cjs` (Playwright) in both preflights; literal tests for the send key, the settings navigation, the settings tabs and the version pin updated. README: the chat menu no longer calls locking chats and projects "In preparation", both exist.


## 0.7.28 - 12.11.2026

- **Settings > Storage after the reference screenshots:** The page is no longer planned. The overview shows the used space against the account's limit with a bar (or, without a limit, the server's free disk space), and opens Files and Images with their sizes and counts.
- **Files:** Tabs Suggested, Favorites and Own items; project folders with a breadcrumb back to all files; type filter (documents, audio, video, made by Mara); search; list or grid. Each item opens, downloads, opens its chat, becomes a favorite or is deleted after a confirmation that names the chat which then no longer shows it. Uploading goes through the same upload as the chat.
- **Images:** Mara's creations and uploads in a grid, plus the reference images from Personalization with a link there.
- **Limit for all accounts and per account:** Admin Core > Operations > New accounts sets one storage limit in GB that applies at once to every account except the owner (empty or 0 is no limit). Admin Core > Users > Limits can set a different value for a single account (empty is the common value, 0 is no limit). Uploads and new images are refused with a clear message once the storage is full.
- **Server:** New endpoint `api/files.php` (summary, list, favorite, delete; never for guests, only the account's own items) and migration 039 (`storage_mb` per account, file favorites). Deleting removes the file, its sidecar of generated images and artifacts through the existing delete helpers.
- **Tests:** `scripts/storage-selftest.php` (limits, usage, lists, folders, favorites, deleting; with and without database) and `scripts/storage-ui-selftest.cjs` (Playwright) are part of both preflights.


## 0.7.27 - 12.11.2026

- **Project navigation:** Clicking the project name expands/collapses the folder instead of opening its homepage. The project actions menu explicitly contains Projektstartseite, rename, settings, share, pin and delete. Creating a new project opens its folder, not a second page.
- **Real chat composer:** Project overview now mounts the actual CoreAI composer and all of its existing attachment, tool, voice, model and thinking controls. The previous duplicate text-only mock form is removed. Starting a project chat binds the real project ID before creating the conversation and restores the composer to the ordinary chat when the project page closes.
- **Non-clipped menus:** Project chat and project folder actions use one keyboard-accessible floating menu attached to the document body, avoiding the old clipped/hidden popups.
- **Empty projects:** Project list and folder use explicit empty states; protected chats and isolated project memory remain unchanged.
- **Theme compatibility:** Native Holo and optional SoftGlass skin retain their established appearance.


## 0.7.26 - 12.11.2026

- **Preflight repair:** Updated the session selftest to recognize the 0.7.25 chat delete button for both active and archived chats.
- **Safety checks retained:** Explicit deletion confirmation, safe archive-first transaction and archived-session restrictions remain enforced by existing server code.
- **Scope:** The chat deletion and archive interfaces have not been redesigned or functionally changed. Node and Nginx optional warnings are unaffected.


## 0.7.25 - 12.11.2026

- **SoftGlass UI:** An optional, original rounded translucent design inspired by classic Aero-era Linux/Windows glass themes. It is selectable under Einstellungen > Anzeige > Oberflächenstil and supports light, dark and system color schemes.
- **CoreAI remains default:** The angular Terra-Net design is unchanged when SoftGlass is not selected. User-selected styles are local browser preferences, not AI model parameters.
- **Sidebar chat deletion:** Every active chat now offers a red, full-width Löschen button below Archivieren; the existing server-side archive-then-delete transaction and two-step destructive confirmation are reused. Deleted active sessions return to the welcome view.
- **Compatibility:** Project folders, PIN-protected chats, project context isolation, login and model/agent settings are preserved.
- **Legal distinction:** SoftGlass is our own CoreAI skin. No third-party logos, graphics or branded UI assets are copied.


## 0.7.24 - 12.11.2026

- **Individual project folders:** Project navigation now has independent open/close toggles with matching open/closed folder SVGs, accessible expansion states and locally persisted fold preferences. Project names still open their actual workspaces.
- **Compact history:** Reduced vertical spacing of Recent and Pinned chat rows while keeping separate menu controls.
- **Native project workspace:** Removed obsolete ChatGPT-only drawer overrides, restored original CoreAI status framing, typography, blue/gold accents and original SVG controls. The header displays the project's real memory mode.
- **Native project creation:** Project create/settings dialog uses the established cut-corner CoreAI frame, compact fields and themed buttons in light and dark mode.
- **Compatibility:** Existing project CRUD, sources, PIN-protected chats, model/composer flow and server ownership rules are preserved.


## 0.7.23 - 12.11.2026

- **Native CoreAI interface:** Restored angular, cut-corner geometry and existing blue/gold accent lines in chat menus, chat dialogs, project home, cards and sidebar. No new visual system introduced.
- **Ren-matrix controls:** Existing settings and logout actions align vertically below the fold control, with all three accessible on collapsed desktop rail.
- **Release consistency:** Bump all application version strings and browser asset cache fingerprints so installed clients can see the update.
- **Upgrade note:** Merging a GitHub pull request does not deploy a self-hosted installation. The operator must run the existing CoreAI update workflow and preflight.


## 0.7.22 - 12.11.2026

- **Chat lock:** One four-digit account PIN for locked chats, created twice at first use, verified server-side on unlock. Failed PIN attempts are rate-limited and password-hashed; chats remain stored, not encrypted.
- **Navigation:** A protected chat folder appears under Projekte only while locked chats exist. The normal chat lists and project previews exclude locked sessions.
- **Interface:** CoreAI native cut-corner PIN dialog and account security settings, with server-controlled APIs and migration 038.
- **Compatibility:** Existing project navigation, model settings and local CPU deployment retained.



## 0.7.21 - 12.11.2026

- **Navigation:** Original CoreAI logo and ticker stay intact. Settings and logout become familiar header icons in the free space beside the logo; redundant Protocols sidebar button removed, protocols page retained.
- **Pinned chats:** Separate "Angeheftet" list above Projects and Recent; no duplicate entries. Chat actions can pin or unpin without opening the conversation.
- **Three collapsible areas:** Angeheftet, Projekte, Letzte have separate accessible fold toggles with persisted UI preference (not chat state).
- **Compact phone layout:** Decreased oversized navigation spacing and footer, normal touch targets remain. No artificial Work mode or second chat layout.
- **CoreAI project theme:** Project landing, files, chat cards, actions, and settings use CoreAI palette, typography and theme variables, replacing temporary ChatGPT-like dark styling.
- **Compatibility:** Project files/RAG, chat session APIs, ownership constraints and server behavior unchanged. CSS/UI tests and version gate required before merging.


## 0.7.20 - 12.11.2026

- **Preflight repaired:** The multiline and lossless Markdown-attachment regression check now inspects the real chat textarea `#inputMessage` rather than mistaking the independently limited project instructions field for the chat composer. The actual chat long-paste path and upload checks remain enforced.
- **Version recovery:** The two unversioned merges #272 and #273 are documented with retroactive sequence slots 0.7.18 and 0.7.19. Those historical commits still contained VERSION 0.7.17, and no historical ZIP or deployment is claimed. This new source release uses 0.7.20.
- **Release consistency:** VERSION, all six HTML entry points, JS bootstrap, README, changelog TXT archive, handovers, branding checks and production preflights are synchronized. Production preflight must be rerun after actual deployment.

## 0.7.19 - historical reconstruction of PR #273 (merged 11.11.2026)

- Removed the artificial Chat/Work switch in the project home because the normal CoreAI chat is already the integrated workspace. Claude Code is the reference for agentic execution, while ChatGPT Projects is the reference for project organization.
- **Audit:** PR #273 merged as commit `8eec82a4c4f0c58d79c3ab405592928bb6f44fe3` while VERSION still read 0.7.17. Version 0.7.19 is a retroactive accounting slot, not an originally published artifact.

## 0.7.18 - historical reconstruction of PR #272 (merged 11.11.2026)

- Introduced chat-native projects with open/isolated context, project instructions, project chat grouping, private source uploads and project-scoped RAG-Lite. Project collaboration and a standalone sandbox were not yet implemented.
- **Audit:** PR #272 merged as commit `15448295ffcef9069ae2c59842601427ce01d6c7` while VERSION still read 0.7.17. Version 0.7.18 is a retroactive accounting slot, not an originally published artifact.


## 0.7.17 - 11.11.2026

- **Licensed shared OpenPet library:** The CoreAI owner can review, validate and publish individual `.openpet.zip` sprite atlases as standard pets for all accounts. The nine existing built-ins and personal pets remain unchanged; Mara stays the default.
- **No automatic copyrighted sprites:** Audited 56 nested OpenPet downloads. Many are recognizable third-party characters and none contained a sprite-specific redistribution license. Therefore **zero** supplied sprites were bundled or automatically made public.
- **Private licensed media:** Approved atlases live in `var/console_media/pet_library` with an atomic manifest; authenticated pet API serves them. Owner-only publication requires creator/rights-holder, approved redistribution license, source URL, license proof and rights confirmation.
- **User experience:** All accounts see approved pets with source/attribution in Settings > Pets, choose one as usual and automatically fall back to Mara if an entry is unpublished. No new database migration or Core/renderer changes.
- **Regression:** New real ZIP/import/selection rights checks, UI contract test and GitHub Actions CI, plus a required PHP check in both production preflights.
- **Release:** 0.7.17 follows main 0.7.16; target-server preflight and final human licensing review remain mandatory.

## 0.7.16 - 11.11.2026

- **Updates remove the retired dictation endpoint:** The installer copies new files but deliberately keeps local ones, so `api/voice_dictate.php` from 0.7.14 stayed on servers after the update to 0.7.15 and the preflight reported "Stimme-Selbsttest: no separate dictation endpoint". `setup.sh` (used by Settings > System > CoreAI Update) and the developer update now remove it and its old browser test as retired source files, only when main no longer contains them; operator files and data stay untouched.
- **Tests:** The setup one-liner selftest places both retired files before an update and checks they are gone afterwards.

## 0.7.15 - 11.11.2026

- **One microphone, no second dictation button:** "Diktierfunktion aktivieren" under Settings > Voice is the existing microphone button next to Send. Switched off, the button disappears from the chat input field and with it the function; transcripts under earlier Stimmsequenzen stay. The extra dictation button from 0.7.14 and its endpoint `api/voice_dictate.php` are removed, so nginx allows exactly the endpoints of 0.7.13 again.
- **Texts:** The Voice page now speaks of Stimmsequenzen only ("Sprache", "Zuletzt verwendete Aufnahmen"); the dictation row says that it switches the microphone button.
- **Unchanged from 0.7.14:** voice choice with preview, "Automatisch erkennen", the last 20 recordings on the device (each Stimmsequenz with "gesendet" or "nicht gesendet"), migration 036.
- **Tests:** `voice-recordings-ui-selftest.cjs` replaces the dictation browser test (one microphone, the switch, recordings); the voice input selftest checks again that the microphone never writes into the input field.

## 0.7.14 - 11.11.2026

- **Settings > Voice after the reference screenshots:** "Stimme" with the chosen voice, "Sprache", "Diktieren" with "Diktierfunktion aktivieren" and "Zuletzt verwendete Aufnahmen". Automatic read-aloud and the names and terms for Stimmsequenzen stay on the page below. Every change saves at once (migration 036).
- **Voice choice per language:** A dialog grouped by language (German, English) offers 20 Pocket TTS voices with a free license only (CC BY 4.0 or CC0 per kyutai/tts-voices), 11 female and 9 male; voices with a non-commercial license or without a documented source are left out. Tapping a voice plays a short sentence of Mara in that voice; "Fertig" saves, "Abbrechen" keeps the old choice. Eve stays the default. Read-aloud in the chat and in the live conversation uses the chosen voices.
- **Pinned and checked:** Every voice is registered in `config/voice/mara-voice.json` ("choices") with revision, size and SHA-256; the voice service loads a voice state on first use, checks it against its SHA-256 and that Pocket names the same file, and keeps the few used last in memory (Eve always). With Eve the read-aloud cache stays as before.
- **Language of Stimmsequenzen:** "Automatisch erkennen" or a fixed language (German, English, French, Italian, Portuguese, Spanish) for the transcript the Core writes of Stimmsequenzen, dictations and live turns. Unset, the account's main language applies as before.
- **Dictation:** A second button in the chat input field records, the Core writes what was said and the text lands at the caret to be checked before sending (`api/voice_dictate.php`, nothing is kept on the server). On by default, switched off under Settings > Voice.
- **Recent recordings on the device:** The last 20 Stimmsequenzen and dictations stay in the browser only (IndexedDB, per account), with their outcome ("gesendet", "nicht gesendet", "mitgeschrieben"); they can be played, saved as a file or deleted. A Stimmsequenz that could not be sent is no longer lost.
- **Tests:** New `voice-settings-selftest.php`, `voice-settings-ui-selftest.cjs` and `voice-dictation-ui-selftest.cjs`; the voice service selftest covers the choices. Real speech with the new voices can only be heard on a server with the voice service; tested here with the service's test tone.

## 0.7.13 - 11.11.2026

- **Right-edge user bubbles:** The operator message row uses a mirrored full-width layout, with its avatar at the top right and the text bubble ending directly at the right edge of the chat below it, instead of reserving the avatar column for all message content.
- **Mirrored avatar spacing:** OPERATOR and the user name sit 13px from the profile avatar on desktop and 9px away on phones, matching Mara's TCI CORE heading. Preserve gold operator text, blue underline and custom profile names.
- **Mobile-safe:** User bubbles remain natural-width with a max-width and wrapping, actions and timestamps stay right-aligned, no horizontal overflow. Mara's full-width replies, pets, tools and other message types remain unchanged.
- **Regression:** Stronger DOM and Playwright geometry tests at 340px, 390px and 1024px assert avatar gaps and right-aligned bubble/controls. Real-server preflight still required before deployment.

## 0.7.12 - 11.11.2026

- **Mirrored human message heading:** User messages show OPERATOR: over the configurable profile name, using the same font and size as Mara's TCI CORE: heading with inverted gold and blue underline accents.
- **Right-aligned bubbles unchanged:** The existing human message content column remains right-aligned next to its profile avatar; no bubble width, markdown, edit/copy actions, or chat scroll behavior changed.
- **Safe profile display names:** Both historic messages and new turns use the same DOM text-node builder; markup in user-entered names is not interpreted.
- **Regression:** DOM and browser-layout tests cover the two-line identity, exact fonts and colors, mobile and desktop geometry. Server preflight remains required.

## 0.7.11 - 11.11.2026

- **Node.js-optional feedback checks:** Both Compose and native preflights now always verify feedback icons, persisted votes, authenticated events and Mara's no-thinking follow-up using the PHP-only contract test. The existing Node.js test runs additionally only when the optional Node runtime is installed.
- **No false red preflight:** Missing Node.js on a correctly installed CoreAI host now emits a clear warning, not a failing feedback check. Real PHP and Node contract failures still cause a failing preflight with the error details.
- **No new installer requirement:** Normal chat servers do not need Node.js installed. User accounts, feedback business logic, Ollama, guest quotas and database schemas remain unchanged.
- **Outstanding separate issue:** A PHP JSON `server_error` observed in another selftest must be investigated independently if it persists.

## 0.7.10 - 11.11.2026

- **Correct outdated preflight assumptions:** Restore the expected four mobile insight buttons in active chats, accept fingerprinted assets for the permanent pet dock, and recognize the additional Vision callback behind both NRW weather routing callbacks.
- **Diagnose feedback accurately:** Both Compose and native preflights now report the original feedback UI selftest error instead of silently discarding its output. Existing persisted thumbs, Like/Dislike prompts and one no-thinking follow-up remain unchanged.
- **Test before deployment:** Add a focused GitHub Actions workflow for PHP/Node offline checks of all four cases, including the mandatory numeric version gate.
- **Release:** Version 0.7.10 follows main 0.7.9. Production server preflight and the separate displayed JSON `server_error` require independent runtime verification.

## 0.7.9 - 11.11.2026

- **Guest images:** Guest accounts may generate **3 saved images per server calendar day** by default, configurable between 0 and 100 under Admin Core > Zugang und Schutz. Every completed image in a batch counts individually; failed or cancelled image work releases its reservation.
- **Guest tool switches:** Separate controls for guest web search, browser research, Python and image generation, with existing guest message, network and day caps retained. Global tool restrictions still take precedence.
- **Server-side quotas:** The existing user-scoped, atomic `stu_abuse_counters` records enforce the per-guest daily image budget; new server-date keys avoid browser-side clock manipulation. Missing quota storage fails closed.
- **Composer:** Guests can now select images in the + menu when allowed; available credits are displayed and guest tool availability is refreshed every time the menu is opened.
- **No new tables:** Reuses existing persisted app settings and the abuse counter table. Normal accounts, global model queue, installed models, pets and installer remain unchanged.
- **Regression:** Updated guest access, image generation and composer intent selftests. Full target-server PHP/preflight validation is required before merging.

## 0.7.8 - 11.11.2026

- **Explicit like/dislike event:** Mara now receives the actual UI event, "DAUMEN HOCH / LIKE" or "DAUMEN RUNTER / DISLIKE", including the selected response and preceding user question, instead of generic sentiment.
- **No invented spoken praise:** The feedback prompt explicitly says the user clicked a thumb without writing or speaking anything. Mara is instructed to acknowledge the selected rating directly rather than say "Glad to hear that."
- **Unchanged feedback infrastructure:** Persistent red/green thumbs, database records, idempotent single follow-up, no-thinking model call, existing core selection, and no tools remain unchanged.
- **Regression:** Existing message-action tests now check that both the positive and negative prompts carry the explicit UI-event semantics. Full PHP and preflight verification remain required before merge.
- **Version:** 0.7.8 follows main 0.7.7.

## 0.7.7 - 11.11.2026

- **Persistent feedback thumbs:** Replace the assistant answer's up/down arrows with proper thumb icons in the same small action-button footprint. A selected thumbs-up remains green, a thumbs-down red, including after chat reload. Tapping the same selected thumb no longer clears the reaction; changing from up to down replaces it atomically in the existing `stu_chat_reactions` table.
- **Mara reacts autonomously:** Every changed vote creates a durable feedback event (migration 035). A separate authenticated API request triggers one short, natural model-authored message about the rated response and its original question. This inference uses the current model's existing global serial queue, with **thinking explicitly off**, no skills and no tool dispatch.
- **No feedback loops:** One stored response per claimed event, with duplicate-request protection, user/session isolation and stale-event checks. Mara's feedback reaction is a separate assistant message with NULL thinking and no response-variant linkage.
- **Regression and rollout:** Added database and UI tests, extended both Docker and native preflight manifests. A model error does not erase the vote. Full server preflight remains required before merge.
- **Version:** 0.7.7 follows main 0.7.6.

## 0.7.6 - 11.11.2026

- **Mara sees her generated images:** The image-generation follow-up now receives the actual completed private PNG(s) through CoreAI's existing Ollama vision input, instead of just a textual "image created" summary.
- **Keep the same Core:** The existing serial inference queue, account-selected model, vision transport and normal answer UI remain unchanged. A text-only model that rejects images gets an honest fallback rather than fabricated visual observations.
- **Private media isolation:** Only images verified in the current account's generated media records and real protected image directory are eligible. No public URL or client-provided filesystem path is accepted.
- **Safe bounded follow-up:** Exactly one agent-loop vision review per completed image batch. Cancel still stops review and additional tool/image markers cannot start further generation.
- **Regression:** Added account isolation, invalid-path, verified PNG and image reviewer/cancellation tests to the existing image generation suite.
- **Release:** 0.7.6 follows main 0.7.5. Full test and preflight verification remains required before merge.

## 0.7.5 - 11.11.2026

- **Restore original mobile insight blocks:** Undo the smaller 0.7.4 CSS overrides for Core, Kontext, Werkzeuge and Projekt in active chats.
- **No more squeezed controls:** Keep the original 11px monospaced labels, 13px horizontal padding, natural width, icon spacing and original 8px gaps from `css/console.css`. Allow the existing horizontal scrolling on narrower phone screens rather than shrinking the buttons.
- **Keep all four functions:** Active chats retain all four interactive information panels, the unsaved welcome screen remains clear, and the desktop right-hand information cards are unaffected.
- **Regression:** Update the phone Playwright test to assert original computed sizes and accessible scrolling. Add an offline guard against reintroducing miniature overrides.
- **Release:** One merge means version 0.7.5; full browser/server preflight remains required before merge.

## 0.7.4 - 11.11.2026

- **TCI CORE matches Mara:** The underlined role label now shares Mara's configured-name typography (font family, 12px size, bold weight, line height and letter spacing) and its theme-blue text color.
- **Gold underline:** Only the label's underline uses the theme's amber/gold accent, in both light and dark display modes.
- **Regression:** DOM text checks and the 390px/mobile and desktop Playwright test now assert matching computed fonts/color and gold underline.
- **Core/Kontext/Werkzeuge/Projekt restored in chats:** The mobile insight container was mistakenly absent from app.html, so none of the four session panels appeared on phones. Restore the four interactive chips at the top of active chats, keep the right-hand panel for desktop, and hide both only on the unsaved welcome screen. Session switches now explicitly toggle this visibility.
- **Mobile and regression coverage:** Fit all four chips on a normal 390px screen; extend offline UI checks and add Playwright checks for 390px mobile and 1600px desktop, including opening Kontext/Werkzeuge and returning to the welcome view. Both official preflights include the regression.
- **No change to chat behavior:** Full-width replies, custom name, image follow-up and persistent pets remain as in 0.7.3.
- **Installer audit:** setup.sh already starts the official scripts/preflight.sh after scripts/install.sh. No installer logic changed without a new reproduced failure.
- **Version:** Every merge increments to 0.7.4. Full target server preflight remains required.

## 0.7.3 - 11.11.2026

- **Mara's message identity:** Live and persisted chat messages, including browser progress, show an underlined "TCI CORE:" label above the account's custom assistant name. User messages retain their layout.
- **Full-width chat responses:** Assistant text, code blocks and tool results start below the avatar and fill the available chat column on smartphones and larger screens.
- **Autonomous image follow-up:** After confirmed image generation, Mara performs one bounded model call to write a natural closing response in the same assistant turn. The generated image is rendered before her text. No second image or tool loop is allowed; cancellation and model failure keep the confirmed tool result.
- **Scope:** Model-driven follow-up is event-based within an active request. Unsolicited background conversation initiation is not enabled; it requires a separate server-side, opt-in event scheduler with cooldowns.
- **Regression:** Updated image selftests and added an offline TCI header/layout contract; complete PHP, Playwright and preflight execution remains required before release.
- **Version:** One merge = 0.7.3. The repository's original model settings and existing accounts are untouched.

## 0.7.2 - 11.11.2026

- **Mara Thorne is the built-in default Pet:** Added the original, transparent OpenPet v2 spritesheet in `images/pets/mara.webp` (1536 × 2288 pixels, 11 state rows, eight columns). The asset is byte-identical to the submitted `mara.openpet.zip` and is served as a static file.
- **Preset and compatibility:** New accounts preselect Mara Thorne, the ninth built-in companion; existing saved choices, including Nova and "Pet nicht anzeigen", remain unchanged. Deleting an active user-uploaded Pet now falls back to Mara. The eight existing original CoreAI sprites and the custom Pet upload system remain intact.
- **Real stateful animation:** Mara's built-in uses `sprite_version = 2`, so the existing persistent chat Pet dock can play idle, active work, waiting, review, failure, wave and movement states.
- **Regression coverage:** Extended Pet PHP and Playwright tests for the nine built-ins, the original atlas checksum, default selection, fallback, V2 renderer, and browser image dimensions. The full target-host Preflight remains required before deployment.
- **Version:** One merge = 0.7.2. Required version markers, metadata, documentation and cache keys synchronized.

## 0.7.1 - 11.11.2026

- **Settings > Pets after the reference screenshots:** "Pet auswählen" with "Pet hochladen", "Pet mit Mara erstellen", the pets and "Pet nicht anzeigen". A pet is an animated companion that stands at the start of Mara's work line in the chat (instead of the three dots) while she works on a request. Nova is preselected; the choice saves at once, and "Pet nicht anzeigen" brings the three dots back.
- **Own CoreAI pets:** Eight original pixel-art companions in the Terran space style (Nova, Komet, Orbi, Luna, Quanti, Glimm, Raki, Sputni), six frames of 32 px each, drawn reproducibly by `scripts/build-pet-sprites.py` (`--check` compares the committed sheets). No pet, name or art of the reference product is used.
- **Pet hochladen:** Name, optional description (up to 500 characters) and a sprite sheet: a transparent PNG or WebP with square frames in one row (2 to 24), 16 to 512 px high, up to 5 MB, with a live preview before uploading. The server checks format, transparency and size from the file's own bytes (PNG and WebP headers), so it also works where GD has no WebP. At most 12 own pets per account (migration 034); own pets can be deleted, a chosen one then falls back to Nova. Sprite files are served to their owner only and are removed with the account; the export lists the own pets.
- **Pet mit Mara erstellen:** opens a new chat with a draft that explains the sprite sheet format; nothing is sent until the person sends it.
- **Codex Cloud removed:** The row in Personalisierung, the planned entry under Programmierung and its planning page are gone, because the chat already covers everyday and code work (24 settings pages, 8 of them planned). Primary buttons in settings dialogs now keep their filled style.
- **Tests:** New `scripts/pets-selftest.php` (pure parts, MariaDB, wiring) and `scripts/pets-ui-selftest.cjs` (Playwright, including the pet in the work line); navigation, tabs and personalization tests updated for the real Pets page and the removed row. Both Preflights list and run them; `api/pets.php` is in both nginx allowlists.
- **Version:** One merge = 0.7.1. All six HTML pages, bootstrap, content-based cache keys, README, changelogs, handover, branding and both Preflight headers synchronized.

## 0.7.0 - 11.11.2026

- **Settings > Personalisierung rebuilt after the reference screenshots:** Sections Mara-Erinnerung, Schreibstil, Referenzfotos, the style menus, Benutzerdefinierte Anweisungen and Weitere Mara-Einstellungen. Every switch and menu saves on its own (only the changed key is sent; a refused save switches back); the old save button is gone.
- **Seven base styles like in the template:** Standard, Professionell, Freundlich, Aufrichtig, Skurril, Effizient and Zynisch, each with a short description in the menu. Stored older values move to the closest style (locker to Freundlich, kreativ to Skurril, praezise to Effizient).
- **Benutzerdefinierte Anweisungen für Mara as a sub page:** Zurück, Abbrechen and Speichern, the instructions plus Spitzname, Beruf, Mehr über dich and Rolle. Mara reads these details as context in her answer style; tool, privacy and system rules stay unchanged.
- **Erinnerungszusammenfassung:** Mara writes an overview of her memories about the person with the account's Core (in the normal model queue) and keeps it until the memories change (migration 033). "Tiefer eintauchen" suggestions and the question row open a new chat with the text in the input row (`app.html?draft=`). The "…" menu offers "Info zur Erinnerung" and "Erinnerung löschen und ausschalten" (deletes only the account's own memories and the overview, then switches memory off; global facts stay). A busy or unreachable Core shows the saved memories instead.
- **Referenzfotos:** A portrait and a full-body photo, uploaded like a chat image. When the person asks for an image of themselves ("ein Bild von mir", "zeichne mich als ..."), the image tool receives the portrait as a reference, plus the full-body photo for clothing and accessories. Replaced or removed photos are deleted unless a chat already uses them. Without image creation the rows are disabled.
- **Real switches:** "Frühere Stimmsequenzen berücksichtigen" keeps only the mark of earlier Stimmsequenzen in the chat history when off (the live conversation keeps its own rounds); "Live-Gespräch" hides the live button and the server refuses new rounds; Websuche and Browser-Recherche show when Lockdown-Modus or the account's tool rules switch them off. Konnektorsuche and Codex Cloud are marked as planned.
- **Fix:** The menu entry Archivierte Chats no longer shows GEPLANT (the page has been real since 0.6.87).
- **Tests:** New `scripts/personalization-page-selftest.php` (pure parts, MariaDB, wiring) and `scripts/personalization-ui-selftest.cjs` (Playwright); `scripts/personalization-selftest.php` literals updated. Both Preflights list and run them. New endpoint `api/memory_summary.php` is in both nginx allowlists; the account export contains the overview.
- **Version:** One merge = 0.7.0. All six HTML pages, bootstrap, content-based cache keys, README, changelogs, handover, branding and both Preflight headers synchronized.

## 0.6.99 - 11.11.2026

- **Shorter thinking-effort label:** The composer Denkaufwand control now displays `Normal` instead of the lengthy `Core-Standard`, both in its selection list and on the button after selection. The label is defined in one place in `js/coreui-reasoning-effort.js`.
- **Behavior unchanged:** The underlying empty `thinking_level` value continues to mean the current Core's own default; `Aus`, `Gering`, `Mittel`, `Hoch` and other supported levels retain their meaning. No Core settings, permissions, models, API schema, defaults or persisted account values are modified.
- **Regression coverage:** The Denkaufwand UI selftest now checks that the empty-key radio option is labeled `Normal` and that selecting it persists the same empty string while thinking remains enabled.
- **Version and cache:** One merge = 0.6.99. The changed reasoning-effort script and version bootstrap receive new content-derived SHA-256 cache keys; all six HTML page versions, README, changelogs, handover, branding and Preflight headers are synchronized.

## 0.6.98 - 11.11.2026

- **Fix the CoreAI uninstall Preflight false failure and unintended prompt:** The isolated shell fixture executed `uninstall.sh --purge-data` under a redirected stdin and wrongly assumed it was noninteractive. The uninstall runner explicitly reads `/dev/tty`, so a live SSH terminal remained usable, displayed `COREAI DATEN ENDGUELTIG LOESCHEN` during installation, and could let the mock fixture complete. The test then falsely reported `Runner accepted noninteractive purge`, blocking the installer.
- **Never exercise destructive mode from Preflight:** The fixture no longer executes `--purge-data`. It verifies the unchanged root runner's TTY check, exact purge confirmation phrase and exact-match confirmation guard as a non-destructive source contract. Existing dry-run, rejected fake web requests, root-only archive simulation and foreign-service preservation tests remain active.
- **Production guard remains unchanged:** The actual uninstaller still requires explicit interactive confirmation for `--purge-data`; the installed CoreAI project is never deleted by Preflight. No production data, DB, model or user setting has been modified by this patch.
- **Version rule:** One merge = 0.6.98. Version markers for six HTML pages, bootstrap, SHA-256 asset fingerprint, README, TXT changelog, handover, branding and both Preflight headers synchronized.

## 0.6.97 - 11.11.2026

- **Stop synthetic users in production:** The per-account language selftest formerly wrote to the live MariaDB whenever `api/config.local.php` was present. It inserted `lang-xxxxxxxx@coreui.invalid` accounts and relied on a rollback that did not reliably prevent leftover accounts on some installations. Both Docker and native Preflight now explicitly force `COREUI_SELFTEST_DB=0` and verify that the language test reports the live database untouched.
- **Preserve isolated DB integration testing:** It is available only via opt-in `COREUI_SELFTEST_DB=1` with separate `COREUI_SELFTEST_DB_DSN`, `COREUI_SELFTEST_DB_USER`, and `COREUI_SELFTEST_DB_PASS`. The target must be an explicitly named `*_selftest` MariaDB database distinct from the configured production DB.
- **Manual, safe cleanup:** `scripts/cleanup-language-test-users.php` previews exact unused synthetic names within the selftest ID range, excludes accounts with passwords, other roles or linked account data, and only deletes after operator confirmation, a fresh transactional check and a guarded row-by-row delete. Installers and Preflights never automatically delete accounts.
- **Regression checks:** Added an offline production-DB isolation contract to Docker and native Preflight, with README, handover and version-sync updates.
- **Release:** One merge = version 0.6.97. Six HTML markers, content-fingerprint cache key, bootstrap, branding, README, changelog and both Preflight headers updated.

## 0.6.96 - 10.11.2026

- **Dedicated CoreAI uninstaller:** Added `scripts/uninstall.sh` with a dry-run preview, interactive archive-preserving uninstall and a separate strongly confirmed SSH-only data purge. It targets the official `/opt/ember-coreui` Docker stack exclusively: no global Docker prune, no shared Ollama, model, Apache/Nginx, other apps or host swap changes. Safe archive mode preserves all existing accounts, MariaDB files, uploads and backups under a private `/opt/ember-coreui.uninstalled.*` folder.
- **Owner-only Admin Core flow:** Admin Core → Operations adds a separately authenticated uninstall form. The POST endpoint requires owner role, the existing CSRF token, the current owner password and a typed confirmation; a fixed archive request is atomically queued. An independent root systemd path/service handles it. The web application cannot execute shell commands or request arbitrary file paths/purge mode.
- **Safety guards and tests:** Shell runner enforces fixed installation markers, rejects symlinks/mounts and stale/forged requests, shares the existing updater lock, checks its systemd units and consumes the one-time request before archiving. Added PHP queue selftest and root-only isolated Docker/systemd fixture. Both standard and native preflights check the new source and tests. No actual live uninstall has been executed.
- **SSH fallback documented:** Added the standalone, interactive, officially hosted uninstaller one-liner to README alongside the admin route, preserving the 24 KB documentation size limit.
- **Version rule:** One merge equals one new version (0.6.96), including all six HTML version markers, JavaScript bootstrap, numeric SHA-256 asset fingerprints, changelogs, handover and branding.

## 0.6.95 - 10.11.2026

- **Installed-Cores Preflight fix:** Two PHP source-contract strings in `scripts/installed-cores-selftest.php` interpolated the undefined `$lock` variable instead of looking for the literal source code. Masking `$lock` fixes both exact-match assertions.
- **Security and behavior unchanged:** All existing permission, inventory, uninstall, lock and Core-update/pull-worker checks remain active. No production API, installed Core, database or user setting is changed.
- **Evidence:** Direct CoreAI 0.6.94 PHP selftest reproduced the single failure with identical Git, host and container source files. A PHP CLI reproduction confirmed interpolation caused the mismatch. The complete 0.6.95 server Preflight still needs to run after deployment.
- **Version synchronization:** One merge, one version. Six HTML pages, bootstrap, content-fingerprint, README, changelogs, branding and both Preflight version markers updated.

## 0.6.94 - 10.11.2026

- **Critical host-updater syntax fix:** Corrected the unbalanced quote in the nested command substitution for `attempted_commit` in `scripts/coreai-updater.sh`. The old script failed Bash parsing at the end of `do_run()` and therefore could not check for or install updates.
- **Regression protection:** `tests/update-runner-selftest.sh` now runs `bash -n` against the host updater before any simulated GitHub, backup or installation scenario. Docker and native preflights also require valid Bash syntax and report the actual test failure output instead of suppressing it.
- **Recovery:** CoreAI 0.6.93 installations running the affected host script need a one-time local syntax repair or the official setup fallback before the built-in updater can resume. No model files, account data, database schemas or automatic-update policy were changed.
- Version 0.6.94 follows the one-merge-one-version policy. The fixed command was validated with Bash 5.2 in an isolated reproduction; the full host-updater test still requires a complete CoreAI checkout.

## 0.6.93 - 10.11.2026

- **Preflight retry UI:** Failed update jobs now carry a specific `failure_kind=preflight` marker when the installer emits a real `Preflight fehlgeschlagen` line. Only this error gets a friendly retry explanation; the existing detailed update log stays visible. The action becomes **Wiederholen** with a circular-arrow icon. Even a failed Preflight that has already written the newest commit can be retried by the owner through the same built-in updater.
- **Later snooze:** **Später** persists an owner-controlled 60-minute hold on the server, across page reloads and across incoming GitHub merges. It suppresses the automatic popup and, when enabled, the automatic installation. Manual opening and manual updates remain possible at any time. Pending automatic requests are cancelled on snooze or switch-off; running installations are never interrupted.
- **Opt-in auto-update:** Added a real **Automatische Updates** switch to Settings → System. **Off by default**. The existing host 10-minute GitHub check detects changes; after one additional check interval for owner notice, the systemd request service can install the official `main` release with the existing database backup and restart workflow. No cloud account is required and Ollama is not restarted by auto-updates unless explicitly configured in the future.
- **Safety:** An automatic job rechecks its server-side opt-in and snooze immediately before the backup; manual requests take priority. Each commit is attempted automatically at most once, failed commits do not loop, and a newer merge may be considered after a new grace interval. A cancelled, never-started job does not consume its retry allowance.
- **Regression gates:** Added an isolated auto-update scheduler and Preflight-failure parser test, extended the offline PHP update-state tests and host runner tests, and updated Playwright coverage for the switch, persisted snooze and retry icon. Docker and native preflights include these checks. Full target-host integration tests remain necessary before live use.
- Version 0.6.93 follows the existing one-merge-one-version policy and uses the usual GitHub updater. No new deployment path, staging installation or monetized provider dependency.

## 0.6.92 - 10.11.2026

- **Installed-Core regression test:** Corrected the expected error for a deliberately malformed, synthetic name with two tag separators (`qwen3:8b:latest`). The name validator correctly raises `invalid_model_name`; the previous fixture incorrectly expected `model_not_installed`.
- **Safety unchanged:** The exact-name check, protected standard and base Cores, administrator/CSRF checks, install/update lock and uninstall implementation have not been modified. No Qwen Core is installed or downloaded by this offline test.
- Version 0.6.92 follows the one-merge-one-version policy. Updated required release markers and their numeric asset cache keys. Run the installed-Core self-test and full preflight on the target host after the regular GitHub updater.

## 0.6.91 - 10.11.2026

- **Unified Core management:** The optional Ollama-account section no longer owns the library button. A dedicated **Cores verwalten** entry in admin Core management now opens the shared library with three mobile-friendly tabs: **Ollama**, **Hugging Face** (clearly marked *In preparation*) and **Installed**.
- **Installed Cores:** The third tab lists actual entries reported by the local Ollama service, including Mara's TCI Cores, additional Cores, existing cloud stubs and manually imported namespaced models. Local disk size, parameters and protection status are shown where available.
- **Per-Core updates:** A dedicated Update button is available in the installed-Core list, including protected default and source tags. TCI Cores use the existing tier Modelfile rebuild, while ordinary Ollama Cores use the existing pull worker. Cloud-only stubs cannot be locally updated. Both paths share the existing admin permissions, CSRF checks, exclusivity lock, status polling and Core-menu refresh.
- **Safe per-Core removal:** Admins can remove one selected Core after a two-tap confirmation. The existing serialized install/update lock, unload/delete routine and account fallback are reused. The default Core and its template-source tag remain protected, even if a forged API request tries to remove them; exact installed names are revalidated on the server.
- **Core Channel:** Removed only the redundant Core/Kontext/Werkzeuge/Projekt chip toolbar. The welcome greeting, example prompts and composer are unchanged. Renamed the Denkaufwand chip to **Denken** and removed visible `LLM` labels from the new Core library.
- **Regression coverage:** New PHP inventory/authorization self-test and extended Node UI test check three tabs, protected default/base tags, CSRF, double confirmation, installed-list refresh and updated Core selection. Docker and native preflights include these checks.
- The usual one-merge-one-version policy is preserved. Local and external provider settings, Mara's Modelfile and the existing GitHub updater remain unchanged. Full server PHP and Ollama integration checks are pending before approval.

## 0.6.90 - 10.11.2026

- **Ollama registry filter:** Fixed the HTML text-node boundary handling. Adjacent heading and description text no longer merges into `nameCloud only`, so the search excludes cloud-only results reliably. Cloud-only model variants remain non-installable even when a page lists parameter counts or download sizes.
- **CoreAI interface terminology:** The Ollama library's visible labels and feedback now use `LLM` instead of `Modell`, in line with CoreAI's existing user-facing terminology guard. Stable internal IDs, API fields and the TCI Core implementation are unchanged.
- **Regression coverage:** Expanded `ollama-library-selftest.php` with mixed/local, nested heading, explicit cloud-only with size metadata, foreign-host and installed-variant cases. The existing frontend regression remains strict.
- **Verification:** A local fixture reproduction using libxml-compatible HTML parsing and PHP regular-expression checks passed. The actual PHP DOM integration, full server preflight and real Ollama registry/download workflow must still be verified on a host with the required PHP extensions. No installed Cores, databases or accounts are modified by these code changes.
- Version 0.6.90 follows the one-merge-one-version policy; no alternative deployment or updater mechanism introduced.

## 0.6.89 - 10.11.2026

- Six existing regression test files have been updated to match established code behavior: chat-run recovery state, composer script loading order, local-only Premium Core availability, keyboard shortcut persistence, voice-sample heading and HTTP preflight page markers. The Live Voice database/HTTP investigation remains open.
- Settings > Manage Cores now presents an **optional Ollama account connection** for existing Ollama subscribers, without sign-up recommendations, cloud-model promotion or pricing/plan advertising. A valid connection link still opens Ollama; CoreAI checks the status when users return. Local TCI Cores remain available without an Ollama account, and previously installed cloud Cores are untouched.
- **Local model library:** The Ollama section now opens a separate **Cores verwalten** popup with an official Ollama search view, model detail overlay, local variant/size selection, two-step install confirmation and progress. The existing serialized download worker and single install lock are shared; no TCI Modelfile is created or changed and pre-existing models are preserved. The adjacent Hugging Face tile is explicitly marked **In preparation**.
- Registry browsing is performed server-side from Ollama's fixed HTTPS origin, with strict identifiers and owner/admin-only access; a direct official-search link is available if public HTML parsing fails. Remote installs require a currently listed local variant; cloud-only variants are rejected. An offline mock UI regression passed, while the PHP registry and full host preflights still await execution.
- Release preparation: version metadata, numeric content-based asset cache keys, update documentation and the standard version archive follow the one-version-per-merge policy.
- **Draft / verification pending:** Test sources were checked against current code, but automated Node/PHP/Playwright/HTTP tests could not be executed in this development session. No production deployment or completed test run is claimed. Merge only after running the required regression, version and cache checks.

## 0.6.88 - 10.11.2026

- New rule in `CLAUDE.md` for future work: the settings pages are built after the operator's reference screenshots, referenced and not copied; the CoreAI theme is applied over all settings at the end. PR titles, PR descriptions, commit messages and new changelog entries describe them neutrally ("after the reference") instead of naming the reference product. Older, merged texts stay as they are.
- Documentation only: no change to code, Cores, Modelfile, FIFO, prompts or account rights.

## 0.6.87 - 10.11.2026

- **Settings > Archivierte Chats is a real page now**, after the ChatGPT screenshots: "Keine archivierten Chats" when there are none; otherwise the search "Archivierte Chats durchsuchen" (titles) and the two menus of the reference: **"Alle Chats"** with *Typ* (Alle Chats; **Cloud**: chats in which a cloud Core answered, marked with a cloud) and *Sortieren nach* (**Aktualisiert**, **Erstellt**, **Alphabetisch**, real), and **"Alle Projekte"** (Chats; **Geplante Aufgaben** In Vorbereitung). The group "Kein Projekt" with its count, and each chat with title, date, **Dearchivieren** and **Löschen** (with a confirmation). Projects are planned, so every chat stands under "Kein Projekt" and the page says so.
- **Every chat gets the menu of the reference (⋮ in the header):** the chat's title, then
  - **Teilen** (real): the whole chat as a Markdown file through the device's share sheet, or downloaded. CoreAI makes no public links, the person decides where the file goes.
  - **Anpinnen** (real, new migration 032): a pinned chat stays at the top of the chat list, marked "ANGEPINNT"; "Nicht mehr anpinnen" releases it. Archiving releases the pin.
  - **Chat sperren** and **Zum Projekt hinzufügen**: shown as **In Vorbereitung** (locked chats need the Konto-PIN first; CoreAI has no projects yet). They send nothing.
  - **Hochgeladene Dateien** (real): the files the person uploaded in this chat, newest first, each opening the file (Mara's own images are not listed).
  - **Im Chat suchen** (real): a search bar over every message of the chat on the server (from two letters, hits marked); a hit jumps to its message and older pages are loaded until it is there.
  - **Zum Startbildschirm hinzufügen** (real): the address now leads straight to this chat, with the steps for Android, iPhone and computers and "Link kopieren".
  - **Archivieren** with the dialog of the reference ("Möchtest du diesen Chat wirklich archivieren? Du kannst archivierte Chats in den Einstellungen anzeigen.") and **Löschen** with its own confirmation; deleting an active chat archives it first in the same request and restores it if deleting fails (for example while a browser research is running).
- **Premium (gemma4:31b) is offered locally only.** Ollama moved its cloud to paid plans, so the Core installer no longer offers the cloud variant `gemma4:31b-cloud` ("Cloud hinzufügen" no longer appears). Connecting the server's Ollama account, other cloud models and cloud Cores an admin installs with an Ollama subscription keep working and are still recognised by their tag.
- **Fixed:** a slow first profile load could put the old name back after a new one was saved under Settings > Konto (0.6.86); an answer that started before a save is now ignored. `account-ui-selftest.cjs` forces that order.
- **Fixed:** a link `app.html?session=…` (a notification since 0.6.81, now also the home screen link) opened the empty start instead of the chat. The app now opens the linked chat; without a link it still starts with the unsaved draft.
- New `api/console_chat_tools.php`; `api/console_sessions.php` serves `archived` (with `sort`), `files`, `search` and `export`, saves `pin`, and deletes with `archive_first`. Searches take `%`, `_` and `!` literally. The delete itself still only takes archived chats.
- Tests: new `chat-menu-selftest.php` (LIKE escaping, snippets, file names; MariaDB with migration 032: pin order, the pin released on archive, archive search, type Cloud and the three sort orders, files without Mara's images, search over the chat, Markdown, other accounts never seen; wiring), `chat-menu-ui-selftest.cjs` (Chromium with the real header, menu and stylesheets: items in the order of the reference, keyboard, share as a file, pin, planned items, files, search with jump, home screen link, archive and delete dialogs, a busy chat, phone; the app's jump loading older pages) and `archived-chats-ui-selftest.cjs` (empty, search, both menus with Cloud, Geplante Aufgaben planned and the three sort orders, group and count, Dearchivieren, Löschen with cancel, error and success, phone). `core-install-selftest.php` checks that Premium is no longer offered from the cloud while an installed cloud Core still finds its tier; `settings-navigation-foundation-selftest.cjs` counts 10 planned pages now and checks the archive fields; `welcome-composer-ui-selftest.cjs` checks the start with a linked chat. Both preflights.
- No change to Cores, Modelfile, FIFO, prompts or account rights.

## 0.6.86 - 10.11.2026

- **Settings > Konto is rebuilt after the ChatGPT account page** ("Allgemein"): Name, Benutzername, E-Mail, Plan and Erstellerprofil in one card, in CoreAI style. ABMELDEN stays at the top.
  - **Name** (pencil): a dialog "Namen bearbeiten". An unchanged or empty name cannot be saved; the CoreAI keeps its own name, and Profil and the profile overview follow at once.
  - **Benutzername** (real, new): an @handle like the reference's dialog "Benutzernamen bearbeiten": 3 to 20 lowercase letters, digits, dots, underscores or hyphens, unique on this server (capitals are turned to lower case, a leading "@" is ignored). Names like admin, mara, coreai or inhaber are reserved. Without a name the dialog suggests one from the e-mail that is still free. It shows as @name in the profile overview head. A public profile with its own link stays planned; the note in the dialog says so instead of promising redirects. No guests, at most 10 changes an hour.
  - **E-Mail:** shown like the reference (an Inhaber or Admin changes it in Admin Core, as before).
  - **Plan** (real, new): the reference's "Plan: Business" becomes four templates an Inhaber or Admin picks per account under **Admin Core > Benutzer > BEARBEITEN > Plan**: **Free** (unlimited text chats, web search, context window up to 8K), **Go** (plus image creation, up to 16K), **Plus** (all tools, up to 64K) and **Pro** (all tools, the server's largest window). No prices: CoreAI stays free. A plan writes real rules through the existing tools and limits (no label without effect), fitted to the server's context windows; changing a single rule afterwards keeps the plan, shown as "angepasst". The Konto page opens "Dein Plan" with the rules in words, today's messages against a daily limit, tools that are off for everyone on the server, and the four plans side by side with "DEIN PLAN". Accounts without own rules show "Kein Plan", other own rules "Individuell", the Inhaber "keine Limits".
  - **Erstellerprofil** (the reference's GPT-Erstellerprofil) is shown greyed as **In Vorbereitung**: the name under which members will share skills and agents in a Workspace. The new roadmap package **P30: Workspace und Intranet-Betrieb** describes it (a company using CoreAI internally like an intranet, shared rooms and conference calls with several accounts and Mara).
- New migration 031 (`stu_usernames` with a unique key, `stu_user_policies.plan`; deleted with the account). New `api/account_page_store.php`; `api/profile.php` serves `?view=account` and saves `display_name` and `username` (behind CSRF); `api/admin.php` lists plans and saves them with `user_plan` (the same rights as the tools and limits, audit entry). The account export includes the username and the plan.
- Tests: new `account-page-selftest.php` (rules, SQLite store with taken, reserved and suggestions, the four templates on servers with different windows, words, angepasst, Kein Plan, Individuell, Inhaber, Gastzugang, today's count, MariaDB with migration 031: unique key, plans as real rules seen by the runtime, cascade; wiring) and `account-ui-selftest.cjs` (Chromium: the rows, name dialog, Benutzernamen bearbeiten with suggestion, lower case, invalid, reserved and taken, Escape and backdrop, the plan page with angepasst, today's count and server switches, no prices, migration missing, failed request, phone). `settings-tabs-ui-selftest.cjs` checks the new Plan section in Admin Core (Go fills tools and limits). Both preflights.
- No change to Cores, Modelfile, FIFO, prompts or account rights.

## 0.6.85 - 09.11.2026

- **Settings > Sicherheit is rebuilt as "Sicherheit und Anmeldung" after the ChatGPT security screenshots**, in CoreAI style: an overview with **So meldest du dich an**, **Multi-Faktor-Authentifizierung (MFA)**, **Sitzungen**, **Erweiterte Sicherheit** and **App-Sicherheit**; each row opens its own sub page with a back button. "Anmeldeverknüpfungen" was left out on purpose.
  - **Passkeys und Sicherheitsschlüssel** (real): add a passkey of this device, a phone or a hardware security key, see when it was added and last used, rename or remove it (password confirmation). WebAuthn is verified on the server without an external library: ES256, RS256 and Ed25519, attestation "none", origin and RP ID checked (HTTPS, plain HTTP only on localhost), user presence, signature counter. Up to 20 per account. The sign-in page gets **MIT PASSKEY ANMELDEN**; a passkey counts as both factors and works without typing the e-mail.
  - **Passwort:** the existing password change, now on its own sub page.
  - **Authenticator-App** (real, TOTP after RFC 6238): QR code drawn in the browser (no external service), the key in groups of four to copy, confirmation with a first code. The key is stored sealed (AES-256-GCM, key derived from the database password). One step of clock drift is accepted, a code is never accepted twice.
  - **E-Mail-Code** (real, instead of the reference's SMS): 6-digit codes by mail, valid 10 minutes, 5 tries, a new mail at most once a minute. Needs E-Mail sending (Admin Core); without it the row explains why it is unavailable.
  - **Wiederherstellungscodes:** 10 one-time codes (like abcde-fghjk), created with the first second factor, shown once, copy and .txt download, new set on request. Stored only as hashes.
  - **Second step at sign-in:** after the password, accounts with a second factor choose the authenticator app, a code by mail or a recovery code; the half-finished sign-in lives only in the server session (10 minutes, 5 tries).
  - **Aktive Sitzungen** with the existing device list and a new **ÜBERALL ABMELDEN** (all devices including this one).
  - **Sicherheitsverlauf:** sign-ins with method and device, failed passwords and codes, sign-outs, removed devices, password changes and resets, every change to second factors, recovery codes, passkeys, advanced security and Lockdown; sign-ins from before the update come from the existing session list.
  - **Erweiterte Kontosicherheit** (real, after the reference's advanced account security page): sign-in only with a passkey or security key (a password alone is refused before it is even checked), no password reset by e-mail, recovery only with recovery codes or on the server, sessions last 24 hours instead of 30 days, security notifications cannot be switched off (Push and E-Mail), and turning it on signs out everywhere. Needs at least one passkey; the last passkey cannot be removed while it is on. The card "Vertrauliche Gespräche" says what is already true: CoreAI never trains on chats.
  - **Lockdown-Modus** (real): turns off web search and browser research for the account's chats, so foreign web pages cannot slip instructions to Mara; live weather data, which comes from the web, is off too.
  - **In Vorbereitung** (shown greyed, never active): **Konto-PIN** (needs locked chats first), **Push-Benachrichtigungen** to approve sign-ins and **CSP für benutzerdefinierte Apps** (CoreAI has no custom apps yet).
- Every sensitive change asks for the password again (rate-limited). Guests have no security page actions.
- New migration 030 (`stu_user_security`, `stu_passkeys`, `stu_security_events`, all `ON DELETE CASCADE`); the history keeps the last 300 entries per account.
- **Emergency reset on the server:** `php scripts/security-reset.php owner@example.com [--passkeys]` turns off the second factors, recovery codes and advanced security of one account (optionally removes its passkeys), signs it out everywhere and writes the history. CLI only.
- New files: `api/security_store.php`, `api/totp.php`, `api/webauthn.php`, `api/secret_box.php`, `js/coreui-security-settings.js`, `js/coreui-login-security.js`, `js/coreui-qr.js`.
- Tests: new `sign-in-security-selftest.php` (RFC 4226/6238 vectors, sealing, a software authenticator with ES256, RS256 and Ed25519 plus refused origin, challenge, RP ID, presence, signature and counter, the store on SQLite and MariaDB, wiring) and `security-ui-selftest.cjs` (Chromium with a virtual authenticator: overview, every sub page, QR, codes, passkeys, confirmation dialog, advanced security, sign-in second step and passkey button, phone, font sizes). `settings-tabs-ui-selftest.cjs` opens the password and session sub pages. Both preflights.
- No change to Cores, Modelfile, FIFO, prompts or account rights.

## 0.6.84 - 09.11.2026

- **Settings > Profil now opens with an overview modeled on the two ChatGPT profile screenshots**, in CoreAI style. The existing name and picture editors stay below; the pencil jumps there.
  - **Head:** picture, name, role and "dabei seit". The globe (public profile) and share buttons are shown greyed as planned.
  - **Figures** in German like the reference (2,1 Mrd., 195,4 Mio., 1 Std. 50 Min., 27 Tage): **Tokens insgesamt** and **Token-Spitzenwert** (the highest day), **Längste Aufgabe** (the longest completed answer run), **Längste Serie** and **Aktuelle Serie** (days in a row with an own message; the current streak still counts until the day ends).
  - **Tokens are counted from this version on** (new migration 029, `stu_usage_daily`): after every Ollama call of a chat turn, per account, day, Core and thinking level, numbers only. CoreAI never stored tokens before, so the page says since when it counts. Streaks, the longest task, tools and what Mara made come from the existing history.
  - **Tokennutzung** raster of the last 53 weeks with **Täglich, Wöchentlich, Kumulativ** and month labels: tokens (green) from the counting start, own messages per day (blue) before it, explained in a legend; each dot names its day and value.
  - **Highlights** (featuring a skill on the profile) is shown as planned.
  - **Von Mara erstellt** (the reference's Sites): the latest images and files Mara made, each opening its chat, with totals; an empty state links to the chat. The assistant's own name is used.
  - **Top-Werkzeuge** (the reference's Top-Plugins): Websuche, Recherche im Browser, Python, Bilder, Dateien, Skills by use.
  - **Einblicke:** TCI Core Schnell share of the runs (the reference's Schnellmodus), most used Core, most used Denkaufwand, discovered and used skills, messages, chats, active days.
- New `api/profile_stats.php` (GET, the signed-in account's own data only, no guests) and `api/usage_store.php`; nginx allows the endpoint in both templates. The account export includes the counted numbers.
- The "In Vorbereitung" badge is 11 px now (was 10 px), also on Benachrichtigungen.
- Tests: new `profile-stats-selftest.php` (thinking levels, streaks, counting only with an account, the overview from SQLite with rows of other accounts and Mara that must not count, MariaDB with migration 029, cascade on account deletion, wiring) and `profile-stats-ui-selftest.cjs` (Chromium: head, figures, note, raster in all three modes, made, tools, insights, planned parts, pencil, a saved name, a new account, no migration, a failed request, loading when opened, phone). Both preflights.
- No change to Cores, Modelfile, FIFO, prompts or account rights.

## 0.6.83 - 09.11.2026

- **Settings > Benachrichtigungen now has every category of the two ChatGPT reference screenshots**, in their order. 0.6.81 had left out four of the ten:
  - **Neue Funktionen** (the reference's Marketing) is real: once per installed CoreAI version, every account is told that new tools and features are ready (default Push, like the reference). A fresh install only remembers its version, a rollback is remembered silently, and only the page view that moves the stored version sends (compare and swap), so two page views at once announce it once.
  - **Code-Aufgaben** (Codex), **Personalisierte Tipps** and **Updates zu Anfragen** are shown greyed as **In Vorbereitung** with the reference defaults (Push; Push und E-Mail; E-Mail). CoreAI has no background code tasks, tip engine or workspace yet, so they never send.
  - **Antworten** is now called **Reaktionen** as in the reference. Bibliothek, Projekte, Workspace-Anfragen, Sicherheit and CoreAI-Updates use full descriptions like the reference.
  - Not taken over: the link "Aufgaben verwalten", because CoreAI has no tasks yet.
- Tests: `notifications-selftest.php` (12 categories with defaults, the order on the page, Neue Funktionen: first run, once per version, each account's own choice, no guests, rollback, MariaDB compare and swap) and `notifications-ui-selftest.cjs` (labels, order, defaults, saving Neue Funktionen).
- No database migration (the category column takes the new names), no change to Cores, Modelfile, FIFO or account rights.

## 0.6.82 - 09.11.2026

- **Manual SMTP setup in Admin Core** (owner only): **Admin Core > Betrieb > E-Mail-Versand** holds server, encryption, port, user, password, sender address and sender name, with **SMTP SPEICHERN**, **TESTMAIL SENDEN** (to the owner's own address) and **ENTFERNEN**. Until now E-Mail only worked through `COREUI_SMTP_*`, which the Docker installation does not even pass to PHP, so E-Mail notifications were effectively unavailable.
  - **Encryption:** STARTTLS (587), SSL/TLS from the first byte (465, new; before, port 465 could not work) or none. Unencrypted only to a server on this machine or in the private network (loopback and private addresses, Docker service names, `.local`, `.lan`, `.internal`) and then without any login, so credentials never travel in plain text. Certificates are always verified; a self-signed certificate is refused.
  - **Password:** stored with AES-256-GCM, key derived (HKDF) from the server's database password plus a random salt per save, so a database copy alone does not reveal it. It is never sent back to the page; an empty field keeps it, but only for the same server and the same user (a stored password is never sent to a different server). If the database password changes, Admin Core says the password is unreadable and asks for it again.
  - **The server configuration wins:** SMTP set in `api/config.local.php` or `COREUI_SMTP_*` is shown read-only and cannot be changed or removed in Admin Core. New optional `COREUI_SMTP_SECURITY` (`starttls`, `tls`, `none`); port 465 means SSL/TLS by default.
  - Plain-German errors for the test mail (no connection, TLS failed, login rejected, sender or recipient rejected, no answer). Audit entries for save, remove and test, never with the password.
- **Mailer** (`api/mailer.php`) uses the effective configuration: SMTP without login is possible (a relay that trusts the server), dot-stuffing and CRLF fixed (the old code turned every header line ending into CR CR LF, dropped the CR before a stuffed dot and added an empty line at the end), `Date` and `Message-ID` headers added, the recipient is checked.
- **Settings > Benachrichtigungen:** without SMTP the owner gets a link **IN ADMIN CORE EINRICHTEN** to the new card; other accounts keep the plain hint.
- Tests: new `mail-settings-selftest.php` (input rules, local-only plain mode, sealing and tampering, password never shown and kept only for the same server and user, server configuration precedence in a separate process, SQLite saving/keeping/removing/unreadable, a real SMTP session against a local test server with headers, dot-stuffing, 550 and refused connections, a self-signed certificate refused, wiring) and `admin-mail-ui-selftest.cjs` (Chromium: setup, port per encryption, exact save with CSRF, keeping the password, unsaved changes before the test, test success and SMTP error, input errors, removing, server configuration read-only, unreadable password, hidden for Admins, phone). `notifications-ui-selftest.cjs` checks the owner link. Both preflights.
- No database migration (stored in `stu_app_settings`), no change to Cores, Modelfile, FIFO or account rights. The password-reset mail stays controlled by `COREUI_MAIL_ENABLED`.

## 0.6.81 - 09.11.2026

- **Settings > Benachrichtigungen** is a real page now, modeled on the ChatGPT reference (one choice per category: Aus, Push, E-Mail, Push und E-Mail), in CoreAI style:
  - **Dieses Gerät:** switch Push on or off for this browser, send a test, see and remove the account's devices ("Dieses Gerät" marked). Clear texts for every case: no HTTPS, browser without Push, iPhone/iPad not opened from the Home screen, notifications blocked, server not ready yet.
  - **E-Mail:** shows the account address, or that this server has no SMTP; E-Mail choices are greyed then.
  - **Categories:** **Antworten** (Mara's answer took 45 s or more: research, images, long work; default Push), **Sicherheit** (new sign-in, password changed or reset; default E-Mail), **CoreAI-Updates** (Inhaber only: a new version is ready, once per version; default Push). **Aufgaben, Agenten, Bibliothek, Projekte, Workspace-Anfragen** are shown greyed as "In Vorbereitung" and never send.
- **Real Web Push without a library** (`api/web_push.php`, PHP openssl): RFC 8291 encryption (aes128gcm) verified byte for byte against the example in RFC 8291 Appendix A, RFC 8292 VAPID with an ES256 JWT, a server key pair created once and kept in the database (`stu_app_settings`, never in Git or a file). Only the push services of the major browsers (Google, Mozilla, Apple, Microsoft) are accepted as targets, so a stored endpoint can never make the server call another address. Gone browsers (404/410) and browsers that failed ten times are removed.
- **Service worker `/sw.js`** (Push only, no caching): shows the notification, skips an answer while a CoreAI page is open and in front, opens the chat of the answer on tap (`app.html?session=...`). nginx serves it uncached with `Service-Worker-Allowed: /` and allows `api/notifications.php` (both templates).
- **Browser fallback:** without Push on a browser, a long answer is still announced while a CoreAI tab is open in the background and notifications are allowed.
- Everything is sent after the response (`fastcgi_finish_request`, session written first), so no request waits for a push service or a mail server.
- **Migration 028:** `stu_notification_prefs` and `stu_push_subscriptions`, both deleted with the account. The account export includes the choices and device names, never endpoints or keys.
- Tests: new `notifications-selftest.php` (RFC example, round trip, VAPID verified, allowed push services, key once in the database, the request a push service gets, saving rules, devices, dispatch with removal and failure count, mail only to real addresses, all triggers, wiring) and `notifications-ui-selftest.cjs` (Chromium: every Push state, switching on and off, devices, test, E-Mail greyed, categories saved, Inhaber-only and planned rows, phone, the service worker in a sandbox, the chat fallback). `settings-navigation-foundation-selftest.cjs` (11 planned pages now) and `settings-tabs-ui-selftest.cjs` (Pets as the planned example) updated. Both preflights.
- No change to Cores, Modelfile, the global FIFO, account rights or existing settings.

## 0.6.80 - 09.11.2026

- Finish transferring the three ChatGPT **Darstellung** reference screenshots as ordinary settings (no Expert mode), on top of 0.6.79:
  - **Motif export, copy and import:** download the motif as `coreai-motiv-DATE.json`, copy it to the clipboard (with a fallback for browsers without the clipboard API) or import it from pasted text or a file. A motif holds design, accent, font and the light/dark colors only; imports are size-limited, strictly validated (format, version, known values, six-digit hex colors) and never change font sizes, motion, the send key or account data.
  - **Hex values beside the color pickers:** background and foreground show their value (for example `#F0F4FA`) and accept typed values, also without `#` or in short form `#123`. Invalid values are marked and not saved.
  - **Set light and dark mode separately:** a switch gives Hell and Dunkel their own motif, accent and font. Switching it on starts Dunkel from the current look; an "Einstellen für Hell/Dunkel" segment edits either mode without changing the screen; switching it off keeps Dunkel's choice for later.
  - **Schriftart:** CoreAI (Rajdhani) or the device's system font. All 77 Rajdhani declarations in the stylesheets now read `var(--coreui-font, "Rajdhani")`; the three `@font-face` rules and the monospace labels stay unchanged.
  - **Intelligent punctuation** in the chat input (off by default): while typing, `"` and `'` become German or English quotes by interface language, `...` becomes an ellipsis, `--` an en dash (never at the start of a line, so Markdown `---` stays), `->`, `<-`, `<->`, `=>` arrows and `<=`, `>=`, `!=`, `~=`, `+-` math symbols. Pasted text, IME input, inline code, code blocks and URLs stay untouched; Backspace right after a replacement restores exactly what was typed. No long dash (U+2014) is ever produced.
  - **Segments** instead of dropdowns for reduce motion (System/Ein/Aus) and difference markers (Farbe, +/-), as in the reference; the Erweitert order now follows it.
- The Darstellung page header now says "ändert weder Core noch Konto" instead of "Modell" (visible-text rule; `frontend-regression-selftest.php` failed on 0.6.79).
- Fix the **Höhere Intelligenz** switch under General: its visible track was a `<span>` outside any label, so tapping the switch itself did nothing (only its text label worked). It is now a `<label>`.
- Repair long-failing settings regressions found while testing: `settings-tabs-ui-selftest.cjs` (taps visible switches instead of 1 px checkboxes, opens the phone drawer before choosing a category, waits for the drawer animation and the Admin Core page navigation, checks the newest KI-Profil save, `$$eval` typo), `theme-preferences-selftest.cjs` (expected the owner-credit color rule that 0.6.70 deliberately removed) `branding-license-selftest.py` (version was still 0.6.71) and `terran-terminology-selftest.cjs` (still counted the connection line that the 0.6.68 welcome screen replaced). No assertion was removed.
- New tests: `appearance-ui-selftest.cjs` (Chromium: segments, font, hex, Hell/Dunkel separate with edit target, export/copy/import with file and invalid input, reset keeps the send key, phone fit, chat smart punctuation in German and English, code and paste untouched, Backspace undo) and the 0.6.80 part of `theme-preferences-selftest.cjs` (font variable, separate modes, motif validation, punctuation rules). Both preflights run them.
- No database, API, Ollama/Modelfile, account rights or FIFO change. All appearance values stay in the existing local preference key.

## 0.6.79 - 09.11.2026

- Begin rebuilding **Darstellung** from the screenshot references as ordinary user settings, independent of Expert mode. The existing three working System/Hell/Dunkel modes and all current animation, telemetry, compact-feed, work-path and browser controls remain.
- Add two real CoreAI UI motifs (**Holo** and **Klar**) and five accent palettes. The accent changes shared borders and active accents through validated design tokens.
- Add separate, validated light/dark background and foreground color choices; switching light/dark restores the matching palette, including when system mode follows the device. No unsafe arbitrary CSS injection or image inversion.
- Add normal user controls for UI font size, chat content font size, code font size, text contrast, motion System/Ein/Aus (including live OS reduced-motion changes), desktop full-width content, and color vs +/- difference markers. Local preferences persist without new server tables.
- Replace the duplicate "Bewegung reduzieren" checkbox with the three-way selector while preserving the saved legacy reduceMotion preference. Appearance reset continues preserving the unrelated General send shortcut and UI language.
- Extend the appearance regression test for persistence, validation, OS motion changes, old preferences, independent palettes and real input IDs. Keep other existing settings unchanged.
- **Next incremental appearance work:** intelligent punctuation, separately customizable light/dark typography and theme export/import. No ineffective toggles were added for unimplemented features.
- No change to Ollama/Modelfile, global FIFO, account rights or backend schemas; full browser/preflight still required.

## 0.6.78 - 09.11.2026

- Separate the two real Core Channel controls completely. The right-hand **Core** dropdown now only selects local/cloud Cores and retains install/manage access. Remove the duplicate **DENKEN** switch and Thinking status text from the Core dropdown and Core chip, avoiding two controls for the same saved account setting.
- Move the existing explanatory text, **"Deine CoreAI denkt vor der Antwort nach: gründlicher, aber langsamer. Gilt ab der nächsten Nachricht, für alle deine Chats."**, into the **DENKAUFWAND** popup beside its real model-aware selection. The original on/off backend remains unchanged and is still invoked by the Denkaufwand selector through `CoreUICoreMode.setThinking()`.
- Update Core-only aria labels, the help legend and KI-Profil guidance, give the explanation an accessible `aria-describedby` link, and remove the now-unused Core chip Thinking CSS and separator. Keep Core-loading feedback on its name and respect reduced-motion settings.
- Update the existing offline Thinking and Denkaufwand regression tests: no duplicate Core switch, working original per-account Thinking POST/rollback, original explanation exactly once in Denkaufwand, and accessible Core-only menu. Release metadata, README, TXT changelog, handover and SHA-256 browser cache keys synchronized.
- No database migration, Modelfile/model, account authorization, global FIFO, voice or chat-history changes. Full browser and host preflight still required.

## 0.6.77 - 09.11.2026

- Correct the two Core Channel composer controls on mobile: **Denkaufwand sits immediately to the left of the Core dropdown**, with the **Core dropdown always the rightmost of the pair**. Keep their IDs, menus, accessibility wiring and real server-backed behavior unchanged.
- Replace the rounded generic styling of the new Denkaufwand chip with the same faceted TCI silhouette, Share Tech Mono typography, spacing, border, colors, hover and focus treatment as the established Core selector. The default mobile overrides use the same geometry.
- Strengthen the existing reasoning and Playwright composer-menu regressions to verify markup order, visual CSS parity, mobile geometry and no sideways overflow. The responsive layout and established chat/voice controls remain intact.
- Synchronize merge-bound release 0.6.77, version labels, SHA-256 browser cache fingerprints, Changelog TXT index and handover. No DB/API/AI/Modelfile/FIFO changes.

## 0.6.76 - 09.11.2026

- Clarify a single CoreAI rule: settings modeled after the ChatGPT reference images are normal user-facing controls. Custom CoreAI controls are also normal when they are understandable without editing raw model or code parameters. Expert means low-level fine-tuning, not simply a feature that CoreAI added itself.
- Keep the guided context-window slider, Core-default option and hardware estimates visible in KI-Profil without Expert. Simple telemetry and live-browser frame playback presentation switches remain ordinary. The Denkaufwand control and all existing normal controls are unchanged.
- Expert hides only six existing raw/advanced controls: custom system prompt, numeric token budget, numeric temperature, Core provider path, numeric Memory context count, and numeric fact relevance. No raw numeric `num_ctx` editor exists yet, and one is not being simulated.
- Repair stale preflight expectation (9 Expert fields after 0.6.75 already shipped 6), and strengthen static and browser regression tests to verify smart controls remain visible while technical values remain preserved and hidden. No database, API, model queue, permissions or Modelfile changes.

## 0.6.75 - 09.11.2026

- Make **Denkaufwand** visible in General without the Expert switch. It continues to use the existing per-account authenticated General settings endpoint (migration 027); no duplicated preference or new database schema.
- Add a clearly visible Denkaufwand button immediately under the Core Channel composer, beside the existing Core picker. The accessible popup displays **only Ollama-declared** named effort levels (low, medium, high, max, where available) plus Core-Standard and Denken aus.
- Boolean-only Cores show **only Denken an/aus**, wired into the existing per-account Core Thinking switch. Selecting a named effort explicitly turns thinking on; switching it off keeps the saved effort for later. Never claim unsupported extra reasoning tiers.
- Reload supported choices on Core changes, synchronize the effort label with the existing Thinking toggle, support click-outside and Escape, safe mobile wrapping, and retain the original Core permissions, model queue and operator Modelfile.
- Add offline regression tests for named/boolean effort, saved API calls and visible settings, both preflights, and update browser cache fingerprints. Full Chromium and host preflight are still required before merge.

## 0.6.74 - 09.11.2026

- Repair noisy SQLite-only offline preflights: the application-settings installer now uses SQLite's portable `CREATE TABLE` syntax when the PDO connection is SQLite, and SQLite-native `ON CONFLICT` for its value updates. MariaDB's existing `ON UPDATE`, `ENGINE=InnoDB` and `ON DUPLICATE KEY UPDATE` remain unchanged.
- The abuse-guard selftest intercepts its intentionally missing-table error locally instead of repeatedly appending `abuse_guard_unavailable` to the operational error log. It explicitly verifies that SQLite settings were stored and retrieved and that missing migration 018 is still detected. No real safety checks are disabled.
- Correct the private RAG-Lite selftest to check both complete prompt predicates: private Console channel, the account's `knowledge_search` permission, and the account-scoped `$uid` lookup. The earlier literal adjacency assertion had become obsolete after the personalization guard was added and incorrectly failed.
- Preserve production MariaDB authorization, guest quota and rate limiting, both existing prompt pipelines, model/Modelfile behavior, global FIFO and data. No new database migration or runtime service.
- A cancelled stream with cURL callback abort (42) is distinct from an unexpected completion failure; successful upload/worker events remain informational. Live preflight must still be executed before merging.

## 0.6.73 - 09.11.2026

- Finish the Chat-inspired settings layout: a left-edge swipe opens the mobile categories drawer, a swipe inside closes it, and desktop navigation can collapse to give the active settings page all available space. Escape, backdrop, keyboard access and 25 deep-linked categories remain.
- Move existing real controls to their proper pages without creating replacement APIs: password and login devices to Security & sign-in; account JSON export to Data controls; Enter/Ctrl+Enter to Keyboard; automatic read-aloud and voice recognition term hints to Voice; Memory enable and relevance/context settings to Memory. Existing account and AI endpoints remain authoritative.
- Put one global Expert switch in the settings header, independent of navigation scrolling. Add a header Admin Core shortcut only after owner/admin role verification, keeping the separate Admin Core authorization gate intact.
- Prevent Memory-only saves from silently overwriting unsaved AI-profile changes by building the unchanged fields from the last server-loaded snapshot. Keep Expert field visibility browser-local; never delete or reset stored numeric values.
- Strengthen structural and Playwright regression checks for category ownership, privileged Admin links, desktop collapse, mobile swipe and save persistence. No database schema, Modelfile or global FIFO changes.
- Fix the settings navigation on phones and tablets: the retired <=900px horizontal tab bar was still setting `flex-direction: row` on the new fixed drawer, placing Personal and Teams side-by-side and clipping the left edge. Force a single vertical column for the drawer, category groups and tab contents without modifying categories or their behavior.
- Size the fixed drawer and rows with border-box, keep category labels inside the viewport, and allow only vertical scrolling. Preserve backdrop, Escape, mobile close, role-gated Admin Core and the Expert field switch.
- Extend the Playwright regression to measure actual drawer width, position, flex direction, item geometry and scrolling at 320, 390, 768 and 900 px; strengthen the offline navigation selftest so the old horizontal style cannot silently return.
- Synchronize 0.6.73 version, cache fingerprints, TXT changelog and handover. No database migration, Core change, account setting reset, or change to the global model queue.

## 0.6.72 - 09.11.2026

- Repair eight preflight failures introduced by moving Admin Core to its own protected `admin/index.html` page in 0.6.71. Preserve real guest, captcha, roles, Core-grant, global-memory and image-tool controls; their six source-contract tests now inspect the correct page and assert that admin fields are absent from ordinary settings.
- Replace obsolete frontend regression assumptions about the retired settings hash redirect with checks for the real Admin Core link, protected initial shell, independent navigation, root-based admin API and server-side permission gate. Do not reinstate the old embedded admin UI or a history-modifying redirect.
- Restore the approved Starlight Unit Studios logo on the standalone Admin Core footer. Apply its original SHA-256 image digest as a consistent image cache key across splash, login, settings and admin. Strengthen the branding selftest to verify each exact relative path and fingerprint, not merely the filename.
- Keep all existing account settings, Model/Modelfile behavior, migrations, authorization, Core/AI logic, server services and the global FIFO unchanged. Only the approved visual branding and version/cache metadata change at runtime.
- Reserve the merge-bound 0.6.72 version and archive the matching TXT changelog and handover. Full live preflight on an updated host remains the final acceptance gate.

## 0.6.71 - 09.11.2026

- Complete the first General settings page: the interface language selector now changes and stores German, English (beta, core UI strings), or browser-detected language, separately from Mara's answer language.
- Activate automatic higher-Core routing for eligible private complex text turns only. Use the next actually installed/allowed local Mara Core, preserve the manually chosen Core and the global serial FIFO queue, never auto-switch live/audio/vision or guests.
- Support model-declared reasoning effort from Ollama /api/show. Present only supported low/medium/high/max values; existing thinking on/off always takes precedence. No synthetic six levels, model option overrides, new Modelfile or missing capabilities.
- Add dedicated per-user migration 027, authenticated narrow General GET/POST, non-DB tests, and browser regressions for save, i18n, thinking and routing. Keep the PR unmerged until server/browser tests are run.


- Rebuild the settings information architecture around 25 navigable category pages with a searchable sidebar and a mobile drawer. The sections visible in the supplied reference are included; 16 missing capabilities are explicitly marked as planning placeholders, with no fake saving or account connections.
- Use one browser-local Expert settings switch to reveal numeric and technical controls within their original categories (KI profile, memory, appearance, General). All categories, existing values, System diagnostics and owner updater remain accessible regardless of the switch; it grants no permissions.
- Build the General page in native CoreAI row styling while preserving its existing account/Core/system overview. A new real local send-key setting switches the chat between Enter and Ctrl/Command+Enter (composition-safe), updates the composer hint and survives reloads; Display reset preserves it. UI language (DE/EN beta), eligible auto-Core routing and model-declared reasoning effort were subsequently activated within the same 0.6.71 merge.
- Move all eight working Admin Core sections from the regular settings markup into a dedicated `/admin/index.html` page with an authorization gate. Ordinary settings show exactly one Admin Core entry only after owner/admin authentication; old admin deep links continue to work.
- Preserve admin users, runtime controls, role restrictions, global memories, lore, logs, audit and owner-only voice/image sample tools; anchor their API endpoints to CoreAI's project root instead of relative page paths.
- Keep the existing profile, appearance, personalization, security, memory, knowledge, update and account functions, while adding a documented 25-category implementation matrix. Adjust PHP, Playwright and offline regression tests and both preflights.
- Synchronize the merge-bound 0.6.71 version, documentation, handover and SHA-256 numeric browser cache keys. Migration 027 was added for General server-backed controls; no model prompt or runtime service was replaced.
## 0.6.70 - 09.11.2026

- Make the owner name display with the same font weight and text color as the surrounding copyright attribution in all six CoreAI HTML surfaces (splash, sign-in, chat drawer, settings, protocols and admin redirect).
- Remove owner-specific light- and dark-theme CSS rules rather than merely overriding their visual effect. Preserve the visible copyright year range, name and publisher.
- Delete the incorrect statement about a future Repack integration in the Settings > About panel. CoreAI remains an independent product in the Quantum software family.
- Extend the branding regression to reject emphasized owner markup, the obsolete Repack statement and leftover special-case copyright colors. Update CSS SHA-256 content fingerprints and the numeric CoreAI version without changing product or runtime logic.

## 0.6.69 - 09.11.2026

- Repair the voice-input preflight regression introduced in 0.6.68: the original PHP source-contract test still expected a fixed session ID, although welcome drafts correctly use a provisional `__draft__` identifier.
- Verify the two supported voice-upload paths: sending an attachment with its typed text in an existing chat and creating the first chat from a completed voice upload. The PHP check now covers both paths instead of an obsolete single-line source string.
- Extend the offline welcome/composer test with saved, draft and mismatched-session upload handoff cases, and the browser voice-input test with a first recording from an unsaved welcome chat.
- Leave actual voice processing, upload APIs, session persistence, model prompts, databases and installations unchanged. Keep the mandatory 0.6.69 merge version, TXT archive, handover and both preflight headers aligned.

## 0.6.68 - 09.11.2026

- Open on a native CoreAI welcome screen with a personalized greeting and four clickable example questions that immediately send to Mara.
- Create a persistent chat only upon the first real turn, whether text, attachment, voice recording or the first Live utterance. Prior chats remain in the sidebar. New Connection and login no longer create empty sessions.
- Use one rightmost composer action for Live, Send and a strongly pulsing Stop while Mara works; remove duplicate Stop from the message timeline. Keep early cancellation requests until the server reports its agent-run identifier.
- Support reduced-motion preferences and the existing native day/night CoreAI color palette. Include a native regression test. No database migration, model, memory, FLUX or installer change.

## 0.6.67 - 09.11.2026

- Correct the narrative identity boundary: Ember Caldwell / Sandra Jansen is a separate Starlight Unit character and must not appear as a fixed example of TCI Mara Thorne's personal identity.
- Remove hard-coded "Ember" alias examples from Mara's self-portrait prompt and image-routing hints. Mara and the currently configured account-specific TCI nickname remain eligible to select that account's own portrait; no lore character name is hard-coded as a TCI alias.
- Replace Ember-as-Mara examples in image-routing, composer, profile and reference-consistency regressions with an explicit synthetic test alias or Mara herself. The test of custom account portraits retaining priority remains in place.
- Preserve legacy technical IDs, model tags, table names and paths such as `ember_memories` and `ember_*` for compatibility. No FLUX weight/model, original reference image, account, memory, queue or database migration changes.


## 0.6.66 - 08.11.2026

- Repair Mara self-portrait routing: scene-based requests (e.g. "Mara im Cockpit", "Ember am Strand", portraits with Mara by name, or images depicting "dich") now deterministically select the account's TCI identity reference instead of relying on the model to write the optional ICH marker.
- Keep clearly unrelated requests (such as "Mara, zeichne eine fremde Frau") free of Mara references. The assistant name is an address only unless it is actually the requested picture subject.
- Prevent the image composer from stripping a model-selected ICH marker during short contextual self-portrait follow-ups ("diesmal im roten Kleid", "noch eins"). Reinforce both compact and full image instructions to preserve the TCI's existing face and use the private portrait reference instead of inventing a generic woman.
- Fail closed if the image worker reports fewer resolved references than requested or fails to load the canonical Mara gallery when that gallery is deployed: cancel the job instead of silently generating an unrelated woman or reverting to an older fallback face. A deliberately selected custom account portrait remains authoritative.
- Extend image-gen and composer regression tests for named/natural-language Mara scenes, aliases, contextual continuations, unrelated characters and missing worker references. No change to model weights, original photos, database, memory, permissions or serial queue.


## 0.6.65 - 08.11.2026

- Fix 2-/4-image generation progress: the first finished image now appears immediately as a clickable thumbnail beside the active dot-grid, not only after the second image completes. Preserve completed thumbnails across subsequent generations and replay of work events.
- Repair the actual live-event handoff: the shared work-event normalizer was dropping the generated image UUID and canvas-size metadata before the image-progress card could load the file. Retain strictly validated image-only fields without exposing the private UUID in the displayed work timeline.
- Reduce mobile chat jank and image flicker by limiting the expensive 30x30-dot canvas animation to approximately 20 FPS on coarse-pointer devices (25 FPS otherwise), keeping real progress events immediate and preventing duplicate animation loops.
- Correct the canvas bitmap resize check to use rounded integer backing dimensions. Fractional device pixel ratios previously caused repeated canvas clears/reallocations on every frame; cap backing pixel density for this effect without changing image output quality.
- Isolate progress-frame repaints from surrounding chat and refine accessible thumbnail links and focus states.
- Extend the mobile Playwright regression to verify first-image visibility during the next render, viewer opening, fractional-DPR backing stability, 4-image replay and no horizontal overflow. No image generation engine, model, database, memory or account-permission changes.


## 0.6.64 - 08.11.2026

- Extend the mobile TCI image editor with a functional touch annotation toolbar: select/move existing annotations, freehand brush, text placement, eight shapes (line, arrow, rectangle, ellipse, triangle, diamond, star, heart), preset and custom colors, eraser and undo/redo.
- Add floating brush-width adjustment, compact palettes and a thumbnail preview of the marked reference, all using existing light/dark CoreAI blue-and-gold design tokens.
- Generate the SECOND annotated reference PNG only when marks are present; keep the original unchanged and reuse account-owned uploads and the serial FLUX.2 pipeline. Marks are visual guidance rather than a hard inpainting mask.
- Expand offline browser UI coverage for the drawing tools, color/shape/text workflows and reference handoff. Align version 0.6.64, numeric SHA-256 content cache keys, archive, in-repo handover and preflights.


## 0.6.63 - 08.11.2026

- Give the image editor the native CoreAI TCI Color Kit instead of generic control styling. Header, close button, selection controls, undo/redo, brush slider, hint, input, output selector and primary action now share blue and amber tokens and technical HUD geometry.
- Respect both light and dark appearance modes using the same interface palette as the image viewer. Image pixels and the existing FLUX reference-editing pipeline remain unchanged.
- Synchronize the brush slider's blue track fill with its actual value, and extend mobile UI assertions for color, light/dark theme switching and brush progress.
- Align 0.6.63 version labels, SHA-256 numeric asset fingerprints, mandatory TXT changelog, handover and preflight versions. No changes to API, accounts, images, model or databases.


## 0.6.62 - 08.11.2026

- Add an integrated **image editor** to the 0.6.61 picture viewer. A new Edit action opens the original uploaded or generated image, offers a text instruction, Normal/Large output and an optional touch brush with size, undo, redo and clear controls.
- Send original and, when selected, a second marked reference through the existing image-upload, account authorization, image tool and serial FLUX.2 Klein 4B queue. The original is never overwritten; no new model, image worker, Modelfile, database migration or account-memory entry is introduced by the editor UI.
- Explicit limitation: pink selection marks guide FLUX; there is no hard mask/inpainting operation and edits may extend outside the highlighted region.
- Prevent overwriting an unsent chat draft and support exiting the editor during reference loading. Add a local mobile Playwright image-editor regression, registered conditionally in both deployment preflights.
- Bump version consistently and update content-hashed asset cache keys. 0.6.61 remains the earlier viewer-only release.
- Bring the viewer toolbar into the native Quantum CoreAI TCI visual language: replace the split image/dropdown with one accessible info icon, add light blue/white controls with gold/blue accents, compact technical button shapes, mobile-safe spacing and native dark-theme styling. Keep image pixels and the dark canvas unchanged.


## 0.6.61 - 08.11.2026

- Add a full-screen, mobile-friendly image viewer for received, uploaded and TCI-generated chat images. Close via X, Escape or Android back, zoom in/out, see filename and resolution, move through multi-image replies, and use browser fullscreen when supported.
- Show previews of ready-to-send photo attachments. Download original image bytes without recompression or removing Quantum CoreAI PNG provenance; offer native file sharing on compatible mobile browsers.
- Keep protected per-user media URLs, account permissions, generation pipeline, model prompts, and the existing serial image queue unchanged. Extend image-chat UI checks.


## 0.6.60 - 08.11.2026

- Fix the image-in-chat preflight assertion to match the current self-identity instruction ("kein Foto" instead of "nicht ein Foto"). The stale exact-text expectation incorrectly blocked installation even though the instruction still identifies the active portrait as the TCI herself.
- Preserve all identity assertions for compact and full prompts. No runtime, model, profile, memory or installer behavior changes.

## 0.6.59 - 08.11.2026

- Repair the image-service preflight simulator: its nested Python string literal previously expanded raw binary escape sequences into NUL/non-ASCII bytes inside the generated stand-in `sd-cli`. The fake command could not start, so the preview capability assertion failed even though this said nothing about the real image engine.
- Double-escape PNG byte literals in the generated fixture so `sd-cli --help` and its sampled PNG previews run as valid Python.
- Add a simulator smoke check with useful stderr/health diagnostics before judging image preview support. Force preview-on only inside this isolated selftest, independent of an operator setting `COREAI_IMAGE_PREVIEW=0`.
- Keep the seven already-uploaded Mara reference images, image service/runtime, models, stored photos, queue and user settings unchanged. No schema migration or new dependency.


## 0.6.58 - 08.11.2026

- Add an optional seven-image canonical Mara reference set at `images/mara_references/`: five photographic identity views (primary portrait, full body, cockpit, side profile, city) and two strictly style-only hologram views (seated/walking). Exact file names are registered in `config/image/mara-references.json`.
- For a DEFAULT Mara self-portrait, FLUX prefers the new frontal image and chooses up to two complementary angles from the same person, based on the user's actual scene. Only an explicit hologram/wireframe request adds one hologram style sample. Never send all seven images blindly.
- The model is told every canonical identity photo depicts the SAME TCI: Mara Thorne. Hologram samples change the rendering style, not the face. The original scene remains intact within the 1200-character prompt budget.
- Respect per-account overrides: a custom assistant/Ember portrait never inherits another person's global Mara reference set; unrelated people uploaded in chat stay separate, and uploaded attachments suppress extra canonical angles.
- Validate static reference paths, file formats and sizes server-side. Do not accept arbitrary file paths, download assets or duplicate them into a database. Missing assets are skipped, and the existing `images/mara_winter.jpg` remains the fallback until the new portrait is uploaded.
- Keep image generation on the serial queue, preserve model weights, account memories and UI settings. Expose the actual number of sources used in private job status and downloadable PNG provenance; add offline regression cases and preflight warnings for missing optional gallery files.


## 0.6.57 - 08.11.2026

- Clarify the account's visual self-identity for every local or cloud TCI Core: the person in the active assistant profile photograph **IS TCI: Mara Thorne herself**, never an actress, lookalike, borrowed face or third person. This applies to the default portrait and a chosen custom photo, even when the displayed nickname is Ember.
- Reuse one server-side profile identity rule across both private chat prompt builders. Tell the Core to describe the requested scene, pose and clothing without inventing visual traits it cannot see; the image reference supplies the appearance. Do not store a long-term memory or change the operator's Modelfile.
- Apply the same self-identity contract at image generation time, with full, compact and minimal prompt-budget variants. Generic uploads of unrelated people or style references remain separate identities and cannot be confused with Mara.
- Strengthen the regular and compact image tool hints so all Core sizes treat ICH self-portraits as their own on-account visible identity. Keep costume changes and creative scenes available.
- Extend profile, image-reference and image-generation regression checks for identity, nickname independence, short budgets, mixed sources and privacy. No model-weight, database schema or image service change.


## 0.6.56 - 08.11.2026

- Remove all visible copyright and text overlays from generated PNG images, retaining only the existing transparent Quantum CoreAI watermark logo at bottom-right, reduced slightly from 12% to 11.5% width at its previous 50% opacity.
- Embed extensive public PNG iTXt and XMP metadata: Quantum CoreAI as product and generator; the fixed technical identity **TCI: Mara Thorne** as author/creator; Starlight Unit Studios publisher, current software version, UTC timestamp, copyright, source classification, dimensions, seed, steps, CFG, runtime, model components, revision hashes, licenses and reference count.
- Use human-readable credits "Erstellt mit Quantum CoreAI", never the generic label "Generative AI" as a product name. Keep the standards-defined algorithmic-media source tag for interoperability.
- Keep full prompts, account IDs, reference tokens, local paths and credentials out of exported metadata. The existing account-scoped private JSON sidecar keeps the complete prompt and job details.
- Add regression coverage for logo-only pixel changes, metadata chunks and their CRC-valid PNG layout, XMP creator, trademark, source information and privacy. No image-model, generation queue or profile behavior change.


## 0.6.55 - 08.11.2026

- Reuse the touch-based STU Game player avatar crop workflow in CoreAI Settings > Profile. User photos retain a square selection; assistant references offer free, 2:3, 3:4 and square crops with drag, resize, sliders and two-finger gestures. Cropping is confirmed before upload, and an existing image can be cropped again.
- Save the original uploaded JPEG/PNG and normalized crop coordinates as private, UUID-bound source and metadata files alongside the rendered PNG. The server performs crop and PNG resampling, preserving the existing 1024px image-worker reference contract. Previously stored photos remain editable from their existing PNG when no original was kept.
- Keep every source account-scoped, verify the expected UUID on recrop, reject invalid coordinates and stale edits, and never write profile content into long-term memory.
- Include newly retained source photos and crop coordinates in account export and securely clean them up on avatar removal, replacement and account deletion.
- Extend PHP and offline Node regressions and the native/Compose preflight for square/portrait geometry, source preservation, mobile editor wiring, account isolation, deletion and no-memory side effects. No database migration and no changes to Mara's persona, saved names or image-generator model.


## 0.6.54 - 08.11.2026

- Rename five visible local/cloud Core tier labels from "TCI: Mara ..." to "TCI Core ..." in chat selection, chip, legend, install and management UI. Mara Thorne's underlying technical model persona is unchanged.
- Give newly installed local Gemma 4 tiers canonical Ollama names `ember-core:e2b`, `:e4b`, `:12b`, `:26b`, `:31b`; new setups default to `ember-core:e4b`. Existing `ember-coreui:*` tags remain compatible and are not removed automatically.
- After successfully creating and verifying a local CoreAI model, remove the upstream `gemma4:*` source tag only if that tag was newly downloaded by the same CoreAI job. Never delete a pre-existing source tag, a cloud model or an unverified Core. Ollama reuses model blobs, so two tags do not imply two copies of weights.
- Apply the guarded cleanup to the built-in Core installer, weekly/manual tier updater, Compose and native setup; migrate raw-only and old `ember-coreui:*` tiers to the matching `ember-core:*` suffixes on update. Surface cleanup warnings and extend regression checks.
- Preserve the operator Modelfile, runtime parameters, existing chats and account settings.


## 0.6.53 - 08.11.2026

- Repair the 0.6.52 reference-prompt budget regression: the shortened identity directive still requires the same 1:1 face, hairstyle and physique, and multiple references explicitly forbid swapping or mixing identities. Shorten the account-portrait overlay together with the base instruction so the visual identity rules are never silently displaced by their own length.
- Repair the profile-reference selftest after the new 1:1 wording. Run image geometry and reference-prompt assertions even when PDO SQLite is absent; explicitly mark database, account-isolation and memory checks as skipped, never as passed.
- Both Docker and native preflights distinguish partial profile coverage from a complete test pass and display actual uncaught regression errors.
- No production database migration, memory write, model change or image-service dependency change.


## 0.6.52 - 08.11.2026

- Strengthen the immutable server-side reference policy: preserve the exact same individual from visual references with 1:1 facial geometry, hairstyle, identifying details, body shape and proportions, rather than accepting a merely similar or reinterpreted person.
- Allow the TCI to freely select a new pose, clothing, expression and setting when the user has not specified them, without copying the reference background or changing identity.
- The current account portrait overrides name-based, canonical and stored-memory descriptions. The visual reference and reference rules are not saved into editable long-term memory and cannot be changed through the Admin Core memory interface.
- Extend offline identity and compact-prompt regression tests. Exact pixel-level likeness remains dependent on the image generator.


## 0.6.51 - 08.11.2026

- Honor account display names including Ember instead of silently replacing that alias with Mara. The technical identity remains unchanged.
- Preserve the entire assistant profile image, with its aspect ratio and a longest edge of at most 1024 pixels, for image-generation references. User avatars retain their existing square crop. Previously cropped assistant uploads must be uploaded again to recover lost image areas.
- Correct dark-theme avatar background scaling and show the complete assistant image in the profile preview.
- Make the account portrait authoritative over default appearance descriptions in text and image prompts. This improves reference consistency without promising pixel-exact generated likeness.
- Keep profile preferences in the profile store only. Do not write memory entries, infer visual facts or run a model during upload.

## 0.6.50 - 08.11.2026

- Apply a universal **reference consistency system standard** to every FLUX.2 image job that uses a visual reference. The central image-generation layer adds a source-preservation prompt regardless of whether the reference is Mara's canonical or personal portrait, or one or several user-uploaded pictures. Ordinary text-to-image requests remain unchanged.
- Three modes: **identity** for recognizable people and characters (face geometry, eyes, nose, lips, jaw, hairline, skin, build, natural neck/shoulders/limbs/feet); **subject** for other subjects (silhouette, structure, color, material, markings, plus facial identity if people appear); and **style only** when explicitly requested (palette and aesthetic, not the source's subjects). Multiple reference subjects stay distinct instead of merging faces or features.
- Keep the user's original explicit style-only instruction authoritative even if a Vision/model-generated prompt describes people from the references. The attachment fallback likewise respects style-only requests. No global height or body-shape template is imposed on unrelated subjects.
- Respect the model service's prompt-size limit without silently dropping the end of long scene descriptions: use a compact consistency instruction and, only when needed, retain both beginning and ending of the requested scene.
- Add an offline reference-consistency regression selftest and run it in the Docker and native preflights alongside the existing image tests. No new model, database migration, background process or queue behavior.

## 0.6.49 - 08.11.2026

- Let Mara **generate new images from multiple attached reference pictures** within the same turn. The Vision branch now invokes the existing image tool when the user asks for a new image instead of stopping at a prose promise. If the model gives only prose, an explicit image request still invokes a deterministic fallback prompt.
- Reuse account-verified uploaded images through opaque reference tokens; the local image service validates exact filenames, image signatures, size and safe paths, then passes each picture as its own `sd-cli -r` argument. The original Mara profile identity reference stays separate. Show reference counts without exposing private UUIDs.
- Preserve private-account media isolation, the existing serial 1/2/4 output queue and the purely analytical Vision route when no new picture was requested. Extend image, Vision and service selftests with multiple inputs and permission/security regression cases.

- Fix missing visual-reference selection for natural self-portrait questions. A message such as "Wie siehst du eigentlich aus ... kannst du mir das auf einem Bild zeigen?" did not match the previous image-intent logic: when the Core emitted generic `[BILD: ...]`, the image service generated a new face without Maras canonical/reference portrait.
- Recognize direct appearance questions in German and English, including the user's custom TCI display name. Before running the image tool in ordinary text chats, upgrade a generic image marker to the self-reference marker only for clear requests about the TCI. The same rule applies to genuine recorded Stimmsequenzen, never to voice content in a third-party uploaded audio file.
- When a self-portrait reference is present, explicitly anchor FLUX.2's prompt to the identity shown in the profile image. Reinforce both full and compact model hints: a TCI does not automatically have visible glowing circuits, facial implants, or an android appearance unless requested. Preserve costumes/roleplay and unrelated image generation.
- Record only whether a canonical or account-specific portrait was selected in the work log, without disclosing private image identifiers.
- Add regression tests for the exact failed chat request, display-name aliases, regular image subjects, size and roleplay, composer fallback, reference prompting, voice wiring, and tool permissions.
- No change to the pinned model files, databases, installer, media access control, or existing finite 1/2/4-image queue. Real visual likeness must be checked with FLUX.2 on the operator's server; a reference improves consistency but cannot guarantee an exact face.

## 0.6.48 - 08.11.2026

- Repair the voice-input preflight regression introduced with 0.6.47: the selftest now checks the current voice image instructions instead of expecting the removed generic self-portrait wording.
- Verify both kinds of spoken image requests independently: normal subjects continue to use `[BILD: ...]` / `[BILD GROSS: ...]`, while explicit pictures of the TCI use `[BILD ICH: ...]` / `[BILD GROSS ICH: ...]` and the account's portrait reference.
- Keep the safeguards that disable image markers when the image tool is unavailable and preserve all four markers during voice reply cleanup.
- Correct the stale browser cache keys left by 0.6.47 using the repository's actual SHA-256-based 80-bit decimal fingerprint algorithm, including the protocols-page bootstrap. All other static assets remain unchanged.
- No change to Mara's Modelfile, voice engine, image model, Nginx configuration, account data, installer or the 1/2/4-image queue. The missing Nginx default site remains an informational warning, not the cause of this failure.

## 0.6.47 - 08.11.2026

- Use the canonical `images/mara_winter.jpg` portrait as the visible TCI avatar and image-generation reference whenever an account has no custom TCI portrait. Account-specific uploads override it only for that account; removing the upload restores the original.
- Route explicitly requested TCI self-portraits, including roleplay and costumes, through the pinned FLUX.2-klein reference-image option (`sd-cli -r`). Keep ordinary image requests free of TCI reference data. Restrict the image service to canonical or validated private UUID-based reference tokens, never arbitrary filesystem paths.
- Preserve Mara Thorne as the immutable technical identity. Apply each account's chosen display name to chat status, prompt guidance, image labels and other personal UI, using neutral wording before the account profile is known. Keep technical and persisted event identifiers intact.
- Add regression cases for image marker classification, nickname-aware self-portraits, service reference validation, composer fallback and image UI names. Full PHP lint, integration/browser preflights and a live FLUX.2 likeness test remain required before merge.

## 0.6.46 - 07.11.2026

- Fix false update failures in the Docker preflight: settings and Admin Core HTTP checks now consume the full response instead of closing the pipe on the first match. This prevents curl error 23 under pipefail.
- Keep transfer failures and missing content markers as failures. Add regression tests with real curl transfers, large pages, missing markers and transfer errors, including reproduction of the original bug.

## 0.6.45 - 07.11.2026

- Finish image requests after one finite batch, without sending the result back into the model/tool loop.
- Add 1, 2 and 4 image buttons directly beside the composer image size controls. Default: one image. Preserve the count in sent, queued and edited messages and history chips.
- Generate images serially; stop on failure or cancellation and keep completed images. Validate counts on the server.
- Keep completed image thumbnails in a vertical strip left of the live image. Reset progress and preview for each new image; ignore stale image load callbacks.
- Add regression coverage for repeated model requests, batch limits, partial failure, cancellation and composer round trips.

## 0.6.44 - 07.11.2026

Planänderung des Betreibers nach einem Referenzbild: kleine Copyright-Zeile links unten, das neue Quantum-Wasserzeichen rechts unten. Das alte Studio-Logo erscheint nicht mehr in erzeugten Bildern.

- **Copyright links unten:** `© [Jahr] Quantum CoreAI` statt `Erzeugt von TCI: Mara©` oder einer großen Credit-Zeile. Das Jahr wird beim Erzeugen aus der Serverzeit ermittelt; die kleine Rajdhani-Schrift ist weiß mit dezentem Schatten.
- **Neues Logo rechts unten:** `images/watermark_logo.png` (vom Betreiber in `main` hochgeladen), auf 12 % der Bildbreite verkleinert und bei 50 % Deckkraft in das fertige PNG eingebrannt. Die bereits vorhandene PNG-Transparenz wird beim Skalieren und Einblenden erhalten; eine farbbasierte Entfernung vermeintlich dunkler Hintergrundpixel findet nicht statt. Die andere Studio-Logo-Datei bleibt für die Oberflächen erhalten.
- **Getrennte Ecken:** Copyright und Logo berühren sich nicht. Das restliche Bild sowie die Größe 512 × 512 / 1024 × 1024 bleiben erhalten. Die Bildtests prüfen das dynamische Jahr, beide Bereiche, die transparente Darstellung ohne farbbasierte Entfernung der Leiterbahnen, die Bildgröße und die fehlende Verwendung des alten Studio-Logos.
- Keine Änderungen an Cores, Modelfile, Bildmodell, Datenbankschema, Installer oder Warteschlange. Für den vollständigen Image-Selbsttest und die Preflights ist die dafür eingerichtete Testumgebung erforderlich.

## 0.6.43 - 07.11.2026

Wunsch des Betreibers (Screenshots einer anderen Chat-KI): Der Anhang-Knopf soll ein "+" mit Menue werden, mit Fotos und Kamera, Dateien, Bild erstellen sowie Websuche und Recherche; Konnektoren sollen vorbereitet werden, da sie Teil der Roadmap sind, und Deep Research ist ebenfalls geplant.

- **+ Menue im Eingabefeld:** Die Bueroklammer ist jetzt ein "+". Es oeffnet ein Menue mit drei Bereichen. **Anhaengen:** Fotos (nur Bilder), Kamera (nur auf Geraeten mit Kamera, oeffnet die Rueckkamera) und Dateien (wie bisher bis zu zehn je Nachricht). **Werkzeuge:** Bild erstellen, Websuche, Recherche. **Konnektoren:** vorbereitet. Bedienbar mit Maus, Tastatur (Enter, Pfeiltasten, Escape) und am Handy als Blatt vom unteren Rand.
- **Werkzeug als Chip:** Ein gewaehltes Werkzeug steht als Chip ueber dem Text, bei "Bild erstellen" mit **Normal** (512 × 512) oder **Groß** (1024 × 1024), und laesst sich mit × entfernen. Beim Senden wird es zu einer kurzen Marke vor der Nachricht (`[[bild]]`, `[[bild:gross]]`, `[[websuche]]`, `[[recherche]]`); in der eigenen Blase erscheint sie wieder als Chip, auch nach dem Neuladen, beim Bearbeiten und in vorgemerkten Nachrichten. Mit nur einem Anhang (etwa einer Stimmsequenz) gilt das Werkzeug ebenfalls.
- **Mara folgt der Wahl:** Der Server nimmt die Marke aus dem Text und gibt Mara eine klare Anweisung (malen, suchen, recherchieren). Antwortet sie trotzdem ohne den passenden Marker, startet der Schritt mit den Worten der Person. Ist das Werkzeug fuer das Konto gesperrt, wird nichts erzwungen; Mara sagt es freundlich. Im Verlauf, im Chat-Titel, im .md-Archiv und in der Zusammenfassung steht keine rohe Marke (im Archiv "(Bild erstellen) ...").
- **Ehrlich ausgegraut:** Neuer Endpunkt `api/composer_tools.php` sagt, was das Konto nutzen darf (dieselben Schalter wie Maras Arbeitsschleife). Gesperrte Werkzeuge bleiben sichtbar, ausgegraut, mit Grund ("Für Gäste nicht verfügbar", "Für dein Konto gerade nicht freigegeben"). **Deep Research** und **Konnektoren** stehen als "In Vorbereitung" im Menue und tun noch nichts; Erweiterungen koennen spaeter ihren Konnektor anmelden (`CoreAIComposerMenu.registerConnector`, Roadmap P14). Sind zehn Anhaenge gewaehlt, werden nur Fotos, Kamera und Dateien grau, die Werkzeuge bleiben.
- Legende am Eingabefeld: "+" statt "Bueroklammer". README beschreibt das Menue als aktuellen Stand; ROADMAP nennt Deep Research (P15) und Konnektoren (P14).
- Tests: neu `composer-intent-selftest.php` (Marken nur am Anfang, Anweisung, Absicherung mit den Worten der Person, Antwort fuer Konten und Gaeste, Archiv mit SQLite, Verdrahtung) und `composer-menu-ui-selftest.cjs` (Chromium: Menue per Klick, Enter, Escape, Klick daneben und Pfeiltasten, Dateiauswahl mit den richtigen Typen, gesperrte Werkzeuge mit Grund, "In Vorbereitung", Chip mit Normal/Groß, Marke einmal vorne, Bearbeiten, volle Anhaenge, angemeldeter Konnektor, hell und dunkel, Handy-Blatt; Bildschirmfotos angesehen). `voice-input-ui-selftest.cjs` um den neuen Platzhalter ergaenzt, `image-gen-selftest.php` prueft die erweiterten Zeilen in Chat und Stream wortwoertlich. Beide Preflights.
- Keine Aenderung an Cores, Modelfile, Datenbankschema, Installer, Bilddienst oder Warteschlange. Nach dem Update ist nur ein Neuladen der Seite noetig (nginx-Vorlage um `composer_tools` ergaenzt, wird beim Update neu geschrieben).

## 0.6.42 - 07.11.2026

Wunsch des Betreibers: in der Bildkarte den echten optischen Fortschritt sehen, also wie das Bild Stueck fuer Stueck entsteht; die Punkte und der Effekt sollen dabei ueber dem Bild bleiben, bis es fertig ist.

- **Vorschau je Schritt:** stable-diffusion.cpp auf unserem festen Stand kann nach jedem Rechenschritt eine Vorschau schreiben (`--preview proj`). Dabei werden die Zwischendaten direkt in Farben umgerechnet, ohne zusaetzliches Dekodieren; fuer FLUX.2-klein ist das eingebaut. Bei 512 × 512 entsteht nach jedem der 4 Schritte ein 64 × 64 Pixel grosses Bild des aktuellen Stands, erst grob, dann immer klarer. Der Bilddienst schaltet das ein, wenn sein sd-cli es kann (`--help` nennt `--preview-path`), zaehlt die Vorschauen und liefert die neueste aus (`GET /jobs/ID/preview`); nach dem Auftrag wird sie geloescht. `COREAI_IMAGE_PREVIEW=0` schaltet es ab.
- **Ins Raster:** PHP holt jede neue Vorschau einmal ab, verkleinert sie auf hoechstens 64 Pixel und schickt sie als kleines PNG ueber den Live-Stream nur an diese Anfrage (`image_preview`). In der Karte liegt das weiche Zwischenbild unter den Punkten, jeder Punkt nimmt die Farbe seiner Stelle an, und der naechste Schritt blendet weich ueber. Die Punkte und ihr wandernder Lichtfleck bleiben obenauf, bis das Bild fertig ist; dann blendet das scharfe Bild auf.
- Mit "weniger Bewegung" steht das Raster still und die Prozentanzeige springt nur bei echten Ereignissen.
- Kein Umbau des Bilddienstes noetig: Der Container bleibt gleich, nur `tools/image/image_service.py` ist neu und laedt nach dem Update von selbst neu.
- Tests: `image-service-selftest.py` (Vorschau nach jedem Schritt, waehrend des Auftrags abrufbar, danach weg, abschaltbar), `image-gen-selftest.php` (jede neue Vorschau einmal, keine nach dem Auftrag, Verkleinern auf 64), `image-progress-ui-selftest.cjs` (Punkte nehmen die Farben der Vorschau an, naechster Schritt blendet ueber, nur PNG-Daten, Bildschirmfoto angesehen).
- Keine Aenderung an Cores, Modelfile, Datenbankschema, Installer oder Warteschlange.

## 0.6.41 - 07.11.2026

Wunsch des Betreibers (Screenshots einer anderen Bild-KI): Waehrend ein Bild entsteht, soll man den Vorgang sehen, mit einer Flaeche, einer wechselnden Stufe und einer Prozentanzeige.

- **Bildkarte im Chat:** Sobald Mara malt, steht in ihrer Live-Antwort eine quadratische Karte im CoreAI-Stil: ein Punkteraster in CoreAI-Blau mit einem wandernden Lichtfleck, darueber die Stufe ("Bild wird vorbereitet", "Beschreibung wird gelesen", "Erste Skizze entsteht", "Die Szene nimmt Gestalt an", "Details werden verfeinert", "Letzter Feinschliff", "Fast fertig", "Fertig") und unten rechts die Prozentanzeige. Die Karte ersetzt waehrenddessen die Tipp-Punkte.
- **Echter Fortschritt:** Die Prozente folgen den Ereignissen des Bilddienstes (Laden, Schritt 1 bis 4, Dekodieren). Zwischen zwei Ereignissen laufen sie weich weiter, nach den gemessenen Zeiten (512: etwa 13,5 s je Schritt, 1024: etwa 46 s), aber nie ueber das Ende der erreichten Stufe hinaus und nie zurueck; ein verspaetetes aelteres Ereignis aendert nichts.
- **Das Bild blendet auf:** Ist das Bild fertig, erscheint es direkt in der Karte (ueber `console_media.php`, nur fuer den Besitzer), waehrend Mara ihren Text schreibt; danach zeigt die gespeicherte Antwort es wie gewohnt mit "Herunterladen". Scheitert das Bild, sagt die Karte "Kein Bild entstanden".
- **Barrierearm:** Mit "weniger Bewegung" im System steht das Raster still; die Stufe wird vorgelesen (role status). Auf dem Handy ohne seitliches Scrollen.
- Technisch: Die Fortschrittsereignisse tragen Phase, Schritte und Bildbreite, "Bild erzeugt" die Bild-ID (`coreui_image_progress_meta`, Arbeitsspeicher-Felder `steps`, `image_px`, `image_uuid`). Neues Modul `js/coreui-image-progress.js`.
- Tests: neu `image-progress-ui-selftest.cjs` (Chromium: Ereignisse, Stufentexte, Prozentbereiche, nie zurueck, Karte statt Tipp-Punkte, bewegte und bei weniger Bewegung stehende Punkte, Bild blendet auf, Fehler, wieder aufgebaute Zeile, Handybreite, Verdrahtung), `image-gen-selftest.php` (Fortschrittsdaten). Beide Preflights.
- Keine Aenderung an Cores, Modelfile, Bilddienst, Datenbankschema oder Warteschlange.

## 0.6.40 - 07.11.2026

Rueckmeldung des Betreibers zu 0.6.39: Geschrieben klappt es ("Katze im Schnee" mit Bild und Wasserzeichen). Nach der Stimmsequenz "Und jetzt mal eine Wildkatze im Wald" kam aber kein Bild, sondern nur Text mit "*(Hier erscheint das Bild der Wildkatze im Wald) (Von dir erzeugtes Bild (512 × 512): ...)". Auch unter dem Katzenbild stand "*(Hier erscheint das Bild der Katze im Schnee)".

- **Bilder nach Stimmsequenzen:** Nach einer Stimmsequenz bot Mara nur Websuche, Browser und Archiv an; das Bild-Werkzeug fehlte dort, und der Marker waere beim Aufraeumen der Antwort beschaedigt worden. Jetzt nennt ihr Auftrag nach einer Stimmsequenz auch `[BILD: ...]` (gross nur auf Wunsch), der Marker bleibt ganz, und das Bild entsteht wie nach einer geschriebenen Nachricht.
- **Bildliste im Verlauf:** Die Notiz "(Von dir erzeugtes Bild ...)" stand bisher hinter Maras alter Antwort; sie hielt sie fuer ein Antwortformat und schrieb sie ab, statt zu malen. Die gemalten Bilder stehen jetzt als eigener Block nach dem Gespraech ("Bilder, die du in diesem Chat schon gemalt hast", hoechstens fuenf) mit dem Satz, dass ein neues Bild nur mit dem Marker entsteht.
- **Keine Platzhalter:** Maras Hinweis und ihr Ergebnistext sagen, dass das Bild von selbst an der Antwort haengt und kein "(Hier erscheint das Bild)" dazugehoert. Eine solche Platzhalterzeile in Klammern und eine abgeschriebene Bildnotiz werden aus jeder Antwort entfernt, auch aus Maras alten Zeilen im Verlauf. Ein normaler Satz wie "Hier ist dein Bild!" bleibt.
- Tests: `image-gen-selftest.php` (Block statt Zeile, hoechstens fuenf, Platzhalter und abgeschriebene Notiz aus den Screenshots entfernt, normale Saetze bleiben, Werkzeug nach Stimmsequenzen), `voice-input-selftest.php` (Bild im Auftrag nach einer Stimmsequenz, Marker bleibt ganz, Werkzeugliste wortwoertlich nachgezogen).
- Keine Aenderung an Cores, Modelfile, Datenbankschema, Installer oder Warteschlange.

## 0.6.39 - 07.11.2026

P20.5, Schritte B und C: Mara malt Bilder im Chat. Der Bildtest auf dem Server (Lauf 20261107-121303) brachte 8 von 8 Bildern; beide Kandidaten sahen gut aus, "CoreAI" stand bei beiden lesbar auf der Kreidetafel. FLUX.2-klein 4B brauchte fuer 512 × 512 etwa 63 s (Z-Image-Turbo 128 s) und fuer 1024 × 1024 etwa 211 s (Z-Image-Turbo 606 s), bei 9,4 bzw. 14,4 GB RAM. Entscheidung des Betreibers: FLUX.2-klein 4B, Standard 512 × 512, gross nur auf Wunsch.

- **Bilddienst (Schritt B):** eigener Container `image` (Compose-Profil wie Eve) mit stable-diffusion.cpp auf dem festen Stand des Bildtests, auf dem Server fuer genau diese CPU gebaut, nur 127.0.0.1, uid 33, nur lesbar bis auf seine Gewichte, 20 GB Speichergrenze. Register `config/image/mara-image.json`: FLUX.2-klein 4B Q8_0, Qwen3-4B Q8_0 und der VAE, jede Datei mit Revision und SHA-256 aus dem Bildtest auf dem Server (Lock-Datei des Betreibers, dort gleich dem SHA-256 von Hugging Face); ohne festgelegte Werte startet der Dienst nicht. Fehlende Gewichte laedt er selbst in dieser Revision (mit Fortsetzung), prueft jede Datei und merkt sich die Pruefung; die rund 9 GB aus dem Bildtest uebernimmt der Installer per Hardlink, statt sie neu zu laden. Ein Bild zur Zeit, feste Argumente fuer sd-cli (die Beschreibung ist ein Argument, nie Shell-Text), echter Fortschritt aus der Ausgabe von sd-cli (Laden, Schritt 1 bis 4, Dekodieren), Abbruch, Zeitlimit (300 s bzw. 720 s) und eine Pruefung des freien Arbeitsspeichers vor dem Start. Nach einem Update startet der Dienst mit dem neuen Code neu, sobald kein Bild mehr laeuft.
- **Warteschlange:** Ein Bild belegt Maras serielle Warteschlange wie eine Core-Anfrage, es laeuft also nie ein Core gleichzeitig. Weil ein Bild 1 bis 4 Minuten dauert, warten Anfragen dahinter laenger statt nach 90 Sekunden aufzugeben (hoechstens 14 Minuten insgesamt); die Reihenfolge bleibt erhalten. Hinter einer normalen Anfrage gilt die bisherige Grenze.
- **Werkzeug im Chat (Schritt C):** Moechte jemand ein Bild, antwortet Mara mit `[BILD: Beschreibung auf Englisch]` (512 × 512) oder, nur auf ausdruecklichen Wunsch, `[BILD GROSS: ...]` (1024 × 1024). Das ist ein Schritt ihrer Arbeitsschleife: Der Arbeitsweg zeigt "Ich male: ..." und live den Fortschritt ("Ich male · Schritt 2 von 4"), danach sagt Mara kurz, dass das Bild da ist, ohne Einzelheiten zu erfinden. Scheitert es, sagt sie offen warum (Gewichte laden noch mit Prozent, Dienst nicht bereit, zu wenig Speicher, Zeitlimit, Abbruch).
- **Das Bild im Chat:** Es haengt an Maras Antwort, erscheint sofort und nach dem Neuladen, oeffnet sich beim Antippen gross und hat darunter einen Link "Herunterladen". Jedes Bild traegt das Studio-Logo (das freigegebene Original aus `images/`) und buendig darunter in Weiss "Erzeugt von TCI: Mara©" (Rajdhani Bold, so breit wie das Logo), zusammen klein unten rechts mit 50 % Deckkraft, direkt im Bild und damit auch in der heruntergeladenen Datei (Wunsch des Betreibers). Bei 512 × 512 ist das Zeichen mindestens 120 Pixel breit, damit die Zeile lesbar bleibt. Es liegt privat beim Konto (`var/console_media/images`, nur ueber `console_media.php` fuer den Besitzer), wird mit seinem Chat oder Konto geloescht, und eine kleine Beschreibungsdatei daneben haelt Beschreibung, Groesse, Seed und Zeit fest. Im Verlauf weiss Mara, was sie gemalt hat.
- **Rechte:** Gaeste malen nie. Admin Core > Betrieb hat den Schalter "BILDER ERZEUGEN" fuer alle, Admin Core > Benutzer pro Konto "Bilder" (Migration 026, Spalte `tool_image`).
- **Installation:** `COREUI_INSTALL_IMAGE` (Standard an ab 32 GB RAM). Der erste Bau dauert 10 bis 20 Minuten; das Update-Fenster sagt das an. Ordner `var/image-models`.
- Tests: neu `image-service-selftest.py` (Register, Fortschritt, Gewichte gegen ein nachgebautes Hugging Face mit festem SHA-256, Dienst mit nachgebautem sd-cli: Argumente, Schritte, ein Auftrag zur Zeit, Abbruch, Zeitlimit, Fehler, Speicher; Uebernahme aus dem Bildtest; Compose, Installer), `image-gen-selftest.php` (Marker, Arbeitsschritt, Hinweis, Speichern, Abbruch, Verlauf, Anhaenge, Loeschen, Schalter; mit Testdatenbank: eine Anfrage mit 1 s Grenze wartet hinter einem Bild und laeuft danach), `image-chat-ui-selftest.cjs` (Bild im Browser, Handybreite). Angepasst: `skills-selftest.php`, `user-policy-selftest.php`, `settings-tabs-ui-selftest.cjs` (das neue Werkzeug in den wortwoertlichen Listen). Beide Preflights.
- Keine Aenderung an Cores, Modelfile oder Eve.

## 0.6.38 - 07.11.2026

Rueckmeldung des Betreibers zum ersten Bildtest (Lauf 20261107-101029): Die Karte Bildproben zeigte 0 von 0 Bildern und nur "kein lock". Laut run.log waren die grossen Dateien (Z-Image-Turbo, FLUX.2-klein 4B und beide Qwen3-4B) geladen; beide Gruppen scheiterten erst am kleinen VAE von Comfy-Org mit "Hugging Face meldet 103 Bytes" bzw. "112 Bytes" statt etwa 335 MB.

- **Ursache:** In `tools/image_spike/models.json` standen die VAE-Repositories kleingeschrieben (`Comfy-Org/z_image_turbo`, `Comfy-Org/flux2-klein-4B`). Hugging Face leitet solche Namen auf die eigene Schreibweise weiter. Der Bildtest fragte ohne Umleitung und nahm die Laenge dieser kurzen Weiterleitung fuer die Dateigroesse.
- **Behoben:** Die Repositories stehen jetzt in der Schreibweise von Hugging Face (`Comfy-Org/Z-Image-Turbo`, `Comfy-Org/Flux-2-Klein-4B`; Dateigroessen dort geprueft). Ausserdem folgt der Test einer Weiterleitung innerhalb von Hugging Face (hoechstens 5), bis eine Antwort die Datei selbst nennt; die Groesse kommt bei einer Weiterleitung nur noch aus `X-Linked-Size`, nie aus ihrer eigenen kurzen Antwort.
- **Ladefehler sichtbar:** Scheitert das Laden, steht der Grund in `var/image-spike/models/fetch-error-GRUPPE.json`; Bericht und Admin Core > Betrieb > Bildproben zeigen "Laden fehlgeschlagen: ..." statt nur "kein lock". Ein spaeteres erfolgreiches Laden entfernt den alten Grund.
- Bereits geladene Dateien werden wiederverwendet (gleiche Groesse, SHA-256 geprueft); der Container muss nicht neu gebaut werden.
- Tests: `image-spike-selftest.py` (das nachgebaute Hugging Face leitet einen kleingeschriebenen Namen mit 307 und 103 Bytes um, wie beim Betreiber; eine endlose Weiterleitung bricht ab und ihr Grund erscheint statt "kein lock"; ein spaeteres Laden raeumt ihn weg). Gegenprobe: mit dem run.py von 0.6.37 scheitert der neue Test mit genau der Meldung vom Server.
- Keine Aenderung an Cores, Modelfile, Datenbankschema, Installer, nginx oder Warteschlange.

## 0.6.37 - 07.11.2026

P20.5, Schritt A: der Bildtest. Nach dem Bild-Technikplan kommt vor dem Einbau ein Test auf dem echten Server: kann seine CPU Bilder erzeugen, wie schnell und mit wie viel Arbeitsspeicher. Recherche vom 07.11.2026: Ollamas Bildfunktion lief nur auf macOS und ist laut Bericht von Anfang November wieder entfernt; stable-diffusion.cpp kann inzwischen kleinere Modelle als FLUX.1-schnell. Entscheidung des Betreibers: Z-Image-Turbo und FLUX.2-klein 4B, beide Apache 2.0.

- **`scripts/image-spike.sh`** (wie der Stimmtest): baut stable-diffusion.cpp auf dem festen Release-Stand `master-1046-d308544` in einem Container fuer genau die CPU des Servers (`-march=native`, AVX-512 wenn vorhanden; nur das ggml-Untermodul, keine CUDA-, WebP- oder WebM-Teile), laedt die Gewichte mit Netz und misst ohne Netz, mit Speichergrenze, ohne Rechte im Container und mit nur lesbaren Gewichten. Optionen: `--groups`, `--threads`, `--full`, `--skip-build`, `--refetch`, `--keep`, `--dry-run`.
- **Gewichte (etwa 20 GB):** Z-Image-Turbo Q8_0, FLUX.2-klein 4B Q8_0 (beide GGUF von leejet), Qwen3-4B als Textverstaendnis (Q8_0 von unsloth) und die beiden VAEs (Comfy-Org). Jede Datei wird beim ersten Laden mit Repository, Commit und SHA-256 registriert und mit dem SHA-256 von Hugging Face verglichen (bei Abweichung geloescht); ein abgebrochener Download wird fortgesetzt; vor jedem Lauf wird jede Datei gegen ihren Eintrag geprueft.
- **Messung:** drei Prompts (Foto, Schrift, Illustration) mit festem Seed in 512 × 512, der erste auch in 1024 × 1024 (`--full`: alle). Je Bild: Gesamtzeit, Laden, Textverstaendnis, Rechenschritte, Dekodieren (aus der Ausgabe von stable-diffusion.cpp) und der hoechste Arbeitsspeicher genau dieses Aufrufs. Bericht: `var/image-spike/runs/ZEITSTEMPEL/report.md` und `report.json`.
- **Admin Core > Betrieb > Bildproben** (nur Inhaber): je Modell die Bilder (antippen oeffnet sie gross), Zeiten je Groesse, RAM, Laden und Lizenz; dazu Prompts mit Seeds, die registrierten Dateien mit Revision und SHA-256 und der Server. Ein Bild wird nur ausgeliefert, wenn sein Lauf es als erzeugt fuehrt und es wirklich ein PNG in diesem Lauf ist.
- **Bildgenerierung im Chat gibt es noch nicht;** sie folgt nach der Modellwahl mit den echten Zahlen (Schritte B und C des Bildplans).
- Tests: neu `image-spike-selftest.py` (Register und Lizenzen, Commit wie im Dockerfile, Zeitangaben, PNG-Groesse; Laden gegen ein nachgebautes Hugging Face mit Umleitung, Commit, SHA-256, fortgesetztem Download und falschem SHA-256; Lock-Pruefung mit veraenderter Datei; Lauf mit nachgebautem sd-cli: genau die Argumente, Bildgroesse, Zeiten, Spitzen-RAM, Fehler, falsche Groesse, fehlende Gewichte; Bericht; Trockenlauf des Skripts) und `image-spike-store-selftest.php` (Laeufe, oeffentliche Form, nur gelistete echte PNGs aus ihrem Lauf, kein Weg nach draussen, nur Inhaber, Karte). Beide Preflights. stable-diffusion.cpp auf dem festen Stand hier mit `-march=native` gebaut, alle Aufrufparameter des Tests in seiner Hilfe geprueft. Bildschirmfotos der Karte (Handy Nacht, Desktop Tag) angesehen.
- Keine Aenderung an Cores, Modelfile, Datenbankschema, Installer, nginx oder Warteschlange.

## 0.6.36 - 07.11.2026

Rueckmeldung des Betreibers zu 0.6.35: Mara hat bei zwei Handyvideos Bild und Ton richtig erkannt und den englischen Satz korrekt uebersetzt ("alle angaben von mara sind korrekt"). Auf den Screenshots fehlte aber die angekuendigte Zeile "Mitschrift:" unter den Videos.

- **Ursache:** Die Seite fragt die Mitschrift ab, sobald das Video im Chat erscheint. Zu diesem Zeitpunkt schreibt der Core sie gerade erst. Fuer Videos antwortete der Server in 0.6.35 dann sofort "keine Mitschrift", und die Seite merkte sich das, ohne erneut zu fragen. Erst nach einem Neuladen waere die Zeile erschienen.
- **Behoben:** Ein frisches Video zeigt wie eine Stimmsequenz "Mitschrift folgt …", die Seite fragt alle paar Sekunden erneut, und die Zeile erscheint, sobald die Mitschrift fertig ist. Damit das Nachfragen endet, schreibt der Server fuer jedes Video einen Endstand: die Mitschrift, "still" (die Zeile sagt "Keine gesprochenen Worte."), "keine" bei einem Video ohne Tonspur oder mit einem Core ohne Audioeingabe (keine Zeile) oder "fehlgeschlagen", wenn das Mitschreiben scheitert (keine Zeile; Mara hoert den Ton trotzdem). Eine einmal geschriebene Mitschrift bleibt auch, wenn spaeter ein Core ohne Audio gewaehlt ist.
- Tests: `voice-input-selftest.php` (Endstaende "none", "silent", "failed", vorhandene Mitschrift bleibt; Endpunkt fragt frische Videos erneut), `voice-input-ui-selftest.cjs` (Video erscheint mit "Mitschrift folgt …", danach die Mitschrift).
- Keine Aenderung an Cores, Modelfile, Datenbankschema, Installer, nginx oder Warteschlange.

## 0.6.35 - 07.11.2026

P20.2: Videos hoeren. Bisher sah Mara ein Video nur als Einzelbilder und bekam "Ton hast du nicht" gesagt. Jetzt hoert sie die Tonspur mit, wie im Sprachplan festgelegt: ffmpeg trennt den Ton, teilt ihn in Abschnitte von hoechstens 60 Sekunden, und Bilder und Ton gehen gemeinsam in dieselbe Anfrage, mit Zeitmarken.

- **Ton zum Bild:** Hat ein Video eine Tonspur und hoert der gewaehlte Core Audio (angemeldete Konten, keine Gaeste), wird der Ton wie eine Audiodatei aufbereitet: WAV mit 16 kHz, Mono, 16 Bit, Teile von hoechstens 60 s, innerhalb desselben Audio-Budgets des Kontextfensters (hoechstens 40 Prozent, bis 10 Minuten). Die WAVs liegen im selben Medienfeld direkt nach den Einzelbildern.
- **Mitschrift zuerst:** Wie bei Stimmsequenzen und Audiodateien schreibt der Core vorher mit, was gesprochen wird (mit den Begriffen aus dem KI-Profil). Die Mitschrift steht als Anker in Maras Auftrag ("im Zweifel gilt der Ton"), unter dem Video im Chat und im Verlauf ("Ton des Videos ..."). Der Arbeitsweg zeigt den neuen Schritt "Ich schreibe den Ton des Videos mit".
- **Maras Auftrag:** Sie hoert die Tonspur nach den Einzelbildern, bei mehreren Teilen mit deren Startzeiten, bezieht Sprache, Musik und Geraeusche ein, soweit sie sie wirklich hoert, und erfindet keinen Ton. Ohne gesprochene Worte heisst es, dass vielleicht nur Musik oder Geraeusch zu hoeren ist. Ein gekuerzter Ton wird offen genannt. Wie bei Audio ohne Denken und ohne Skill-Dokumente davor; behauptet der Core, es gebe keinen Ton, wird einmal wiederholt.
- **Ehrlich ohne Ton:** Video ohne Tonspur ("Das Video hat keine Tonspur."), stille Tonspur ("Die Tonspur ist still"), Core ohne Audioeingabe (sagt es, wenn es um den Ton geht) und Gaeste (wie bisher "Ton hast du nicht.") bekommen die Einzelbilder und den passenden Hinweis.
- **Aufraeumen:** Die WAVs der Tonspur werden nach dem Aufruf entfernt, wie Einzelbilder und Audioteile.
- **README:** Live-Gespraech und Video-Ton stehen nicht mehr als "geplant" da (beides ist umgesetzt); geplant bleibt die waehlbare englische Oberflaeche.
- Tests: `voice-input-selftest.php` (Tonspur erkannt oder nicht; Video mit Ton: Einzelbilder, dann eine WAV im Medienfeld, gueltig fuer Gemma, vorher genau diese WAV mitgeschrieben, Arbeitsweg, Mitschrift gespeichert; Auftrag mit Mitschrift, Teilen, Kuerzung, ohne Worte; Video ohne Tonspur, stille Tonspur, Core ohne Audio, Gast; keine WAVs uebrig; Verlauf; Verdrahtung in Chat, Endpunkt und Oberflaeche), `voice-input-ui-selftest.cjs` (Mitschrift-Zeile direkt unter dem Video).
- Keine Aenderung an Cores, Modelfile, Datenbankschema, Installer, nginx oder Warteschlange.

## 0.6.34 - 06.11.2026

Wunsch des Betreibers nach dem gelungenen Live-Test: In der Core-Liste sollen Audio-Cores rechts, links neben dem Haken, ein Lautsprecher-Symbol und ein Symbol fuer das Live-Gespraech tragen; der Live-Knopf soll leicht pulsieren, damit er auffaellt, aber nur als Live-Knopf, nie als Senden-Taste. Die Wahl des Live-Cores bleibt unveraendert (Wunsch des Betreibers).

- **Symbole in der Core-Liste:** Ein Lautsprecher zeigt, dass ein Core Audioeingabe hat (Stimmsequenzen und Audiodateien), die Wellen des Live-Knopfs, dass das Live-Gespraech auf ihm laufen kann (ein lokaler Core mit Audioeingabe). Beide sitzen rechts vor dem Haken, mit Beschriftung fuer Bildschirmleser und als Hinweis beim Darueberfahren. Die Haken-Spalte hat eine feste Breite, damit die Symbole untereinander stehen.
- **Woher CoreAI das weiss:** `api/models.php` fragt Ollama je lokalem Core nach seinen Faehigkeiten (`/api/show`, Feld `capabilities`) und liefert `audio` und `live` mit. Cloud-Cores werden nicht gefragt. Antwortet Ollama fuer einen Core nicht, bleiben die uebrigen unbekannt (kein Symbol), damit die Liste nie auf ein langsames Ollama wartet.
- **Live-Knopf pulsiert:** Das Wellen-Symbol wird sanft heller und etwas groesser mit leichtem Leuchten (2,4 s). Nur solange der Knopf der Live-Knopf ist; die Senden-Taste pulsiert nie. Wer im System reduzierte Bewegung eingestellt hat, sieht kein Pulsieren.
- Tests: `core-tiers-selftest.php` (Audio und Live je Core, Cloud nicht gefragt, Abbruch ohne Warten), `thinking-switch-ui-selftest.cjs` (Symbole und Beschriftung in den Zeilen, keine bei Cloud und Cores ohne Audio, Haken an seinem Platz), `voice-live-ui-selftest.cjs` (Live-Knopf pulsiert, Senden nicht, keine Bewegung bei reduzierter Bewegung). Bildschirmfotos der Core-Liste in Nacht und Tag angesehen.
- Keine Aenderung an Cores, Modelfile, Datenbankschema, Installer, nginx oder Warteschlange.

## 0.6.33 - 06.11.2026

Rueckmeldung des Betreibers zu 0.6.32: "die Geschwindigkeit ist top", aber Mara verstand Unsinn. "Hallo" wurde zu "Hallo Bergisch Gladbach", "ich hab nur hallo gesagt" zu "Ich bin Anfaenger. Ich habe nur hallo gesagt.", "na dann" zu "Schopner danke gesagt." Dazu die Messwerte aus `logs/stu_error.log`.

- **Mara Normal schreibt wieder mit:** Die Messwerte zeigen, dass Mara Schnell keine Zeit spart. Mitschrift 1,5 bis 2,2 s (mit Mara Normal in 0.6.31: 2,0 und 2,2 s), Antwort bis zum ersten Satz danach 0,8 bis 2,2 s (0.6.31: 0,8 und 1,3 s). Die Annahme aus 0.6.32 war falsch: `prompt_eval_count` zaehlt bei Ollama den ganzen Prompt, auch was im Zwischenspeicher lag (ein Vorwaermen mit 1.081 Tokens dauerte 0,3 s). Die 5,9 s der Live-Probe kamen vermutlich von der Aufnahme in Maras eigenem Aufruf, die es seit 0.6.31 nicht mehr gibt. Mara Schnell hoerte kurze Saetze aber deutlich schlechter. Darum schreibt im Live-Gespraech wieder der Live-Core mit; Mara Schnell wird dafuer nicht mehr geladen (das Oeffnen dauerte mit beiden Cores 20 s).
- **Schneller bleibt:** Eve ohne Pausen (Teile vorab), 0,7 s Pause bis zum Rundenende und der Live-Knopf im Senden-Platz bleiben wie in 0.6.32.
- **Keine Emojis vorlesen:** Ein einzelnes "😊" erschien als mein Satz und ging an Eve. Emojis werden aus den gesprochenen und angezeigten Saetzen entfernt, ein Satz ohne Buchstaben oder Ziffern entfaellt. In der Chat-Antwort bleiben sie stehen.
- **Ehrliche Messwerte:** Das Protokoll (`coreui_live_turn`, `coreui_live_warm`) nennt jetzt `prompt_tokens` (der ganze Prompt), `prompt_s` (wie lange Ollama wirklich gelesen hat) und `load_s` (Laden des Cores) statt der irrefuehrenden `new_tokens`.
- Tests: `voice-live-selftest.php` (Emojis, Messwerte; ueber HTTP mit Datenbank: Vorwaermen nur mit Mara Normal, Mitschrift durch Mara Normal, obwohl Mara Schnell installiert ist; ein Emoji am Ende der Antwort wird nicht gesprochen, bleibt aber im Chat), `voice-live-ui-selftest.cjs` (Runde ohne Mitschreib-Core, sonst wie 0.6.32).
- Keine Aenderung an Cores, Modelfile, Datenbankschema, Installer, nginx oder Warteschlange.

## 0.6.32 - 06.11.2026

Rueckmeldung des Betreibers nach dem ersten Live-Gespraech: "die Erkennung von meinem Gesprochenem ist etwas traege" und "Mara macht bei ihren Antworten lange Pausen zwischen den Saetzen oder nach nem Komma". Dazu die Idee: der Senden-Knopf soll der Live-Knopf sein, solange nichts in der Eingabezeile steht. Entscheidung des Betreibers: Mara Schnell schreibt im Live-Gespraech mit.

- **Keine Pausen mehr in Maras Stimme:** Eve teilt einen Satz in Teile (der erste kurz, oft bis zum ersten Komma). Bisher wurde jeder Teil erst berechnet, wenn der vorige zu Ende gespielt war; die Rechenzeit war als Pause zu hoeren, nach dem Komma und zwischen den Saetzen. Jetzt werden alle Teile der Reihe nach vorab geholt, waehrend der vorige spielt (einer nach dem anderen, Eve rechnet ohnehin nur einen zur Zeit). Bei einer Unterbrechung werden die vorab geholten Teile verworfen.
- **Mara Schnell schreibt mit, Mara Normal behaelt ihren Zwischenspeicher:** Ollama haelt je Core nur einen Zwischenspeicher. Schrieb Mara Normal die Runde mit und antwortete danach, musste sie den ganzen Verlauf jedes Mal neu lesen (in der Live-Probe gut 1.100 Tokens, etwa 4 Sekunden). Jetzt schreibt Mara Schnell mit (in der Probe genauso richtig, 1,3 statt 1,7 s), Mara Normal antwortet. Beim Oeffnen laedt CoreAI erst Mara Schnell, dann Mara Normal mit dem Anfang des Gespraechs, und fragt Ollama, ob beide im Arbeitsspeicher sind. Nur dann schreibt Mara Schnell mit; sonst wie bisher Mara Normal, damit nicht in jeder Runde ein Core neu geladen wird. Muss Ollama Mara Normal spaeter entladen oder scheitert Mara Schnell, schreibt ab da wieder Mara Normal (die Runde selbst geht nicht verloren). Mara Schnell muss installiert, fuer das Konto freigegeben und hoerfaehig sein.
- **Kuerzere Pause bis zum Rundenende:** 0,7 statt 0,9 Sekunden Stille beenden die eigene Runde.
- **Live-Knopf im Platz des Senden-Knopfs:** Ist die Eingabezeile leer (kein Text, kein Anhang, keine Bearbeitung), zeigt der grosse Knopf rechts das Wellen-Symbol und startet das Live-Gespraech. Sobald etwas getippt oder angehaengt wird, ist er wieder die Senden-Taste; nach dem Absenden oder Loeschen wieder der Live-Knopf. Das Mikrofon fuer Stimmsequenzen bleibt daneben. Wo das Live-Gespraech nicht geht (Gaeste, Core ohne Audio, ohne HTTPS), bleibt es beim Senden-Knopf.
- **Messbar:** Jede Runde schreibt Mitschreib-Core, Zeit bis zur Mitschrift, Zeit bis zum ersten Satz und neu gelesene Tokens in das Fehlerprotokoll (`logs/stu_error.log`, Typ `coreui_live_turn`), das Vorwaermen ebenso (`coreui_live_warm`).
- Tests: `voice-live-selftest.php` (Mara Schnell als Mitschreiberin: freigegeben, hoerfaehig, nicht der Live-Core; beide im Speicher; ueber HTTP mit Datenbank: Vorwaermen laedt Mara Schnell ohne Prompt und dann Mara Normal, Antwort nennt die Mitschreiberin, ohne Platz fuer beide Mara Normal, Runde mit Mara Schnell und Antwort von Mara Normal, Mara Normal entladen und Mara Schnell gescheitert jeweils mit Hinweis an den Browser), `voice-live-ui-selftest.cjs` (Live-Knopf im Senden-Platz bei leerer Zeile mit der echten Umschaltung aus `console-app.js`, Senden bei Text oder Anhang, nur Leerzeichen zaehlen als leer; zweiter Satz wird geholt, waehrend der erste spielt; Mitschreib-Core geht mit jeder Runde und wechselt auf Hinweis des Servers; Tag und Nacht, Handy).
- Keine Aenderung an Cores, Modelfile, Datenbankschema, Installer, nginx oder Warteschlange.

## 0.6.31 - 06.11.2026

Das Live-Gespraech (P20.4). Grundlage ist die Live-Probe auf dem Server: Nur der zweistufige Weg (erst Mitschrift, dann Antwort) verstand zuverlaessig; Mara Plus hoerte die WAV im eigenen Aufruf gar nicht und brauchte etwa 16 s bis zum ersten Satz, Mara Normal etwa 7 s; der Core las in jeder Runde den ganzen Aufruf neu. Entscheidungen des Betreibers: Mara bekommt die Mitschrift als Text, im Live-Gespraech antwortet immer Mara Normal, der Sprecherwechsel ist freihaendig. Vorbild fuer die Bedienung: die Screenshots des Betreibers (Live-Knopf neben dem Mikrofon, Vollbild).

- **Live-Knopf und Vollbild:** In der Eingabezeile direkt neben dem Mikrofon. Die Vollbild-Ansicht im CoreAI-Stil (Tag und Nacht, Handy) zeigt "Du kannst jetzt sprechen, <Name>", den Core, ein lebendiges Element fuer zuhoeren, nachdenken und sprechen, darunter was ich gehoert habe und meinen aktuellen Satz, unten Stummschalten und Beenden. Der Bildschirm bleibt an. Nur angemeldete Konten, nie Gaeste; mit einem Core ohne Audioeingabe ist der Knopf mit Begruendung gesperrt.
- **Freihaendig:** Eine einfache Sprecherkennung im Browser mit gleitender Rauschschwelle startet die Runde beim Sprechen (mit 0,4 s Vorlauf, damit das erste Wort nicht fehlt) und beendet sie nach 0,9 s Pause. Spricht die Person, waehrend ich rede oder nachdenke, stoppe ich sofort, und das Gesagte wird die naechste Runde.
- **Eine Runde:** Die Aufnahme geht als WAV-Stimmsequenz ueber den normalen Upload an `api/voice_live.php`. Der Core schreibt sie mit (mit den Begriffen aus dem KI-Profil), die Stimmsequenz kommt mit Mitschrift in den Chat, dann antworte ich auf den Text, ohne Denken, ueber die serielle Warteschlange. Die Antwort kommt als Server-Sent Events Satz fuer Satz; Eve liest jeden Satz, sobald er da ist. Meine Antwort wird als normale Chat-Antwort gespeichert, bei einer Unterbrechung das bis dahin Gesagte.
- **Schneller durch den Cache:** Jeder Aufruf beginnt gleich: das Modelfile-SYSTEM des Betreibers bleibt unveraendert (keine eigene Systemnachricht fuer Maras Core), dann eine feste Kontext-Nachricht (Name, Datum, KI-Profil, Gedaechtnis, Regeln fuer gesprochene Antworten), der Verlauf bis zum Start (hoechstens 10 Nachrichten) und danach jede Runde. Beim Oeffnen wird dieser Anfang vorgeladen.
- **Gedaechtnis:** Meine Reflexion ueber die Runden laeuft erst nach dem Gespraech (Beenden), damit keine Runde hinter ihr wartet.
- **Dienste:** nginx gibt `api/voice_live.php` frei (beide Vorlagen), das Tageslimit des Kontos zaehlt jede Runde.
- Tests: neu `voice-live-selftest.php` (Live-Core, Satzschnitt, gesprochene Antwort, fester Anfang, Modelfile-SYSTEM, Verdrahtung; mit `COREUI_SELFTEST_DB=1` zusaetzlich der echte Endpunkt ueber HTTP mit Anmeldung, nachgebautem Core und Datenbank: Status, CSRF, Vorwaermen, zwei Runden als Chat-Nachrichten mit Mitschrift, gleicher Anfang fuer den Cache, Unterbrechen, Eve nicht erreichbar, Gedaechtnis am Ende, alles wieder entfernt) und `voice-live-ui-selftest.cjs` (Chromium mit Fake-Mikrofon: Knopf nach dem Mikrofon, Vollbild, Vorwaermen, Runde als WAV-Stimmsequenz, Saetze in Reihenfolge vorgelesen, Stummschalten, Unterbrechen, Beenden mit Gedaechtnis und Neuladen, Gaeste, Core ohne Audio, kein Chat, Tag und Nacht, Handy). Beide Preflights.
- Keine Aenderung an Cores, Modelfile, Datenbankschema, Installer oder Warteschlange.

## 0.6.30 - 06.11.2026

Zurueck zum Live-Gespraech (P20.4). Vor dem Bau wird auf dem Server gemessen, welcher Weg im Gespraech am schnellsten zu Maras erstem gesprochenem Satz fuehrt und dabei zuverlaessig auf die neue Stimmsequenz antwortet.

- **Live-Probe (`scripts/voice-live-check.php`):** nimmt die letzten drei Stimmsequenzen des Inhaber-Kontos als drei Gespraechsrunden, mit dem Verlauf des neuesten Chats und Maras Persona, waermt den Core vor wie spaeter der Gespraechsmodus und spielt drei Wege durch: A zweistufig wie im Chat (Mitschrift mit Googles Vorlage, dann WAV und Mitschrift an Mara), B ein einziger Aufruf, in dem Mara zuerst eine Zeile "Gehoert: ..." schreibt und dann antwortet, C nur die WAV. Ausgegeben werden je Runde die Sekunden bis zum ersten sprechbaren Satz (Eve braucht danach etwa 0,15 s bis zum ersten Ton), die Tokens, die der Core neu lesen musste (wenige heisst: der Cache greift), was gehoert wurde und Maras Antwort. Mit `--cores alle` fuer jeden installierten Core mit Audio-Eingang, `--uuids` fuer bestimmte Aufnahmen, `--ways` fuer einzelne Wege. Ohne Denken, ueber Maras Warteschlange, speichert nichts, raeumt temporaere Dateien auch bei Fehlern weg.
- Sprachplan P20.4 um die Probe und die gewuenschte Bedienung ergaenzt: Live-Knopf neben dem Mikrofon in der Eingabezeile, Vollbild-Ansicht im CoreAI-Stil mit Begruessung, Zustandsanzeige (zuhoeren, nachdenken, sprechen), Stummschalten und Beenden.
- Tests: `voice-input-selftest.php` (erster sprechbarer Satz nach der "Gehoert:"-Zeile, die Zeile als Mitschrift, Verdrahtung). Die Probe lief hier gegen einen nachgebauten, streamenden Core und MariaDB (drei Runden, drei Wege, zwei Cores, wachsender Verlauf, keine Reste).
- Keine Aenderung an Chat, Cores, Modelfile, Datenbankschema, Installer oder Warteschlange.

## 0.6.29 - 06.11.2026

Screenshot des Betreibers: Die Antwort der Browser-Recherche zur Wetterfrage stand mit 13:33 unter einer Stimmsequenz von 14:29.

- **Ursache:** Die Datenbank speichert Zeiten mit NOW() in ihrer eigenen Zeitzone, im Container UTC. Der Verlauf schickte sie als "2026-11-06 13:33:10" ohne Zeitzone, und der Browser las das als Ortszeit. Live gesendete Nachrichten stempelt der Browser selbst, deshalb fiel es vor allem bei nachgeladenen Antworten auf (Browser-Recherche, Neuladen des Chats: dort waren alle Zeiten eine Stunde zu frueh).
- **Behoben:** Verlauf (`api/console_messages.php`) und Chatliste schicken Zeiten als ISO 8601 in UTC ("2026-11-06T13:33:10+00:00"), berechnet mit UNIX_TIMESTAMP in derselben Datenbank-Zeitzone, in der NOW() geschrieben hat. Damit stimmt der Zeitpunkt im Container (UTC) und bei einer nativen Installation (Ortszeit); der Browser zeigt ihn in der Ortszeit des Geraets. Die Sortierung der Chatliste vergleicht jetzt ebenfalls gleichartige Zeiten.
- Tests: neu `message-time-selftest.php` (Umrechnung, Verdrahtung; mit Datenbank: Nachricht und Chat mit NOW(), Datenbank-Sitzung in UTC und in +01:00, zurueckgerollt), in beiden Preflights.
- Keine Aenderung an gespeicherten Daten, Datenbankschema, Cores, Modelfile, Installer oder Warteschlange.

## 0.6.28 - 06.11.2026

Preflight-Korrektur nach dem Betreiberlauf von 0.6.27: Der Spracheingabe-Selbsttest scheiterte bei einem Core ohne Audiofaehigkeit an der veralteten Erwartung "keinen Audio-Eingang". Der Laufzeitcode gibt seit der TCI-Sprachueberarbeitung korrekt "Der gewaehlte Core unterstuetzt keine Audioeingabe." aus.

- **Sicherheitspruefung unveraendert:** Der bestehende Test fordert weiterhin eine ausdrueckliche Ablehnung der Audioverarbeitung und kontrolliert, dass keine Audioteile vorbereitet und kein Vision-/Audio-Pfad gestartet werden. Der neue Solltext wird an der konkreten Runtime-Meldung gemessen. Bei Fehlschlag erscheint die tatsaechlich erhaltene Nachricht in der Testdiagnose.
- **Regression:** `scripts/tci-language-selftest.cjs` prueft jetzt auch die Uebereinstimmung von Audio-Sperrmeldung und Spracheingabe-Selbsttest, einschliesslich des verbotenen Audio-Pfades. Technische Dateikennungen und alte Testeingaben bleiben erhalten.
- **Folgepruefung:** Der verbleibende Abschnitt von `scripts/voice-input-selftest.php` wurde auf weitere terminologisch veraltete Textvergleiche kontrolliert; das erwartete "nur Stille" ist weiterhin in der Runtime vorhanden.
- **Versionierung:** 0.6.28 in VERSION, allen sechs HTML-Oberflaechen, JavaScript-Bootstrap mit inhaltbasiertem Cache-Schluessel, README, Changelog, TXT-Archiv, Uebergabe, Branding-Test und Preflight-Kopfzeilen.
- **Unveraendert:** Kein Eingriff in die produktive Audio-/Vision-Verarbeitung, API, Ollama, Modelfile, Datenbank oder Installer.
- **Validierung:** Gezielt isolierte PHP-8.4- und Node-22-Pruefungen des Audio-Sperrfalls bestanden; statischer Vergleich mit Runtime-Quelltext und Versionspruefung vorbereitet. Vollstaendiger Voice-Selbsttest und Server-Preflight sind vor Merge erforderlich, da der lokale Runner nicht ueber den vollstaendigen Checkout samt SQLite verfuegt.

## 0.6.27 - 06.11.2026

Preflight-Folgekorrektur nach dem Betreiberlauf von 0.6.26: Die Spracheingabe pruefte in `scripts/voice-input-selftest.php` noch die historische sichtbare Formulierung "Das ist eine Sprachnachricht", obwohl `api/ember_attachments.php` sie seit 0.6.24 korrekt als "Das ist eine Stimmsequenz" ausgibt.

- **Spracheingabe-Test:** Ausschliesslich den veralteten Solltext in der bestehenden Assertion auf "Das ist eine Stimmsequenz (Laenge etwa 02:30, Minuten:Sekunden)" umgestellt. Alle echten Pruefungen fuer Dauer, drei Teile und Zweiminutenlimit bleiben unveraendert.
- **Regressionsschutz:** `scripts/tci-language-selftest.cjs` prueft zusaetzlich die Uebereinstimmung zwischen dem sichtbaren Audio-Anhangstext und der erwarteten Testformulierung. Technische Dateinamen mit `Sprachnachricht-` bleiben absichtlich unveraendert.
- **Audit:** Alle 88 Testdateien auf einschlaegige alte Sprach-/Core-Formulierungen durchsucht. Weitere Treffer beziehen sich auf historische Eingaben oder weiterhin verwendete technische Dateikennungen; keine pauschale Ersetzung.
- **Versionierung:** 0.6.27 mit `VERSION`, sechs HTML-Seiten, JavaScript-Bootstrap und inhaltbasiertem Cache-Schluessel, README, Changelog/Archiv, Uebergabe, Brandingtest und Preflight-Header synchronisiert.
- **Abgrenzung:** Keine Aenderung an Runtime-PHP, Audio-/Vision-Verarbeitung, Datenbank, Modelfile, Ollama oder Installer.
- **Pruefung:** Zieltext wurde gegen die aktuelle Runtime-Quelle abgeglichen und die PHP-String-Assertion isoliert mit PHP 8.4 geprueft. Vollstaendiger PHP-Selbsttest und Server-Preflight stehen bis zur tatsaechlichen Ausfuehrung aus.

## 0.6.26 - 06.11.2026

Preflight-Hotfix nach der Betreiberpruefung von 0.6.25: Der komplette Testlauf meldete genau zwei Fehler, weil die Textumstellung auf "Stimmsequenz" und Maras Ich-Perspektive in zwei aelteren Test-Erwartungen noch nicht nachvollzogen war.

- **Spracheingabe-Selbsttest:** `scripts/voice-input-selftest.php` prueft weiter, dass die automatische Mitschrift als Anker der aktuellen Nachricht dient und fruehere Nachrichten nicht erneut beantwortet werden. Die beiden Erwartungstexte sind nun "im Zweifel gilt der Audioinhalt" und "Fruehere Stimmsequenzen". Die Testlogik bleibt unveraendert.
- **Bild-/Video-Persona-Selbsttest:** `scripts/vision-persona-selftest.php` prueft weiter die unveraenderten internen Ereigniskennungen und den gemeinsamen Mara-Kontext. Seine sichtbaren Aktivitaetstexte entsprechen jetzt "Ich sehe mir den Anhang an" und "Ich hoere die Stimmsequenz an".
- **Kompatibilitaet:** Keine Aenderung an Core, Ollama, Backend-Logik, Audioformaten, Bild-/Video-Pipeline, Datenbank oder Modelfile. Die korrekt gestarteten Funktionschecks bleiben erhalten.
- **Versionspflege:** 0.6.26 in den sechs HTML-Seiten, Bootstrap, SHA-256-Cache-Fingerprint, README, Changelog/Index/TXT-Archiv, Uebergabe, Branding und Preflight-Kopfzeilen synchronisiert.
- **Tests:** Die beiden Fehler sind durch den Betreiber-Preflight fuer 0.6.25 bestaetigt. Ein vollstaendiger neuer Runtime-/Server-Preflight fuer 0.6.26 muss vor dem Merge und Live-Update ausgefuehrt werden; keine ungeprueften Erfolgsbehauptungen.

## 0.6.25 - 06.11.2026

Hotfix fuer die fehlgeschlagene Installation von 0.6.24: Im Update-Protokoll wurde ein PHP-Syntaxfehler in `api/voice_transcribe.php` gemeldet. Bei der Umstellung auf "Stimmsequenz" fehlte in einer zusammengesetzten Zeichenkette das schliessende einfache Anfuehrungszeichen. Dieselbe Ursache bestand in `api/ember_attachments.php`, waere also beim naechsten Syntaxcheck aufgetaucht.

- **PHP-Quote-Fix:** In beiden Dateinamen-Formatierungen den gueltigen Ausdruck `'Die Audiodatei "' . ... . '"'` beziehungsweise `'Audiodatei "' . ... . '"'` wiederhergestellt. Der Stimmsequenz-Begriff bleibt erhalten; Audioformate, Dateinamen und Datenbank bleiben unveraendert.
- **Voice-Selbsttest:** Die beiden Verlaufserwartungen von "Sprachnachricht" auf "Stimmsequenz" korrigiert. Der bestehende Test prueft ausserdem die formatierte gewoehnliche Audiodatei mit Dateinamen.
- **Merge-Schutz:** `AGENTS.md` und `CLAUDE.md` verlangen bei PHP-Aenderungen einen echten `php -l`-Lauf und passende Offline-Tests vor dem Merge. Ohne PHP-Laufzeit darf ein PR nicht ungeprueft gemergt werden.
- **Bootstrap-Cache-Fix:** Der Fingerprint von `js/console-gate.js` war nach dem Versionswechsel 0.6.23 -> 0.6.24 in den HTML-Seiten veraltet. Fuer 0.6.25 neu berechnet und auf allen fuenf Seiten mit Bootstrap-Script aktualisiert.
- **Versionierung:** Neuer Versionsstand 0.6.25 samt Oberflaechen, Bootstrap, Branding-Test, Changelog-Archiv und Uebergabe gemaess Versionsrichtlinie.
- Lokal geprueft: die beiden betroffenen PHP-Ausdruecke als eigenstaendige PHP-8.4-Syntax- und Formatierungsprobe. Gesamter PHP-Checkout, Preflight, Server-Update und echte Audio-End-to-End-Tests muessen separat ausgefuehrt werden; keine erfolgreichen ungetesteten Ergebnisse behauptet.

## 0.6.24 - 06.11.2026

Mara spricht als eine einheitliche Terran Cognitive Intelligence (TCI). Der Core und die Werkzeuge sind Bestandteile ihrer Verarbeitung, keine weiteren Akteure. In sichtbaren Texten heissen gesprochene Chatnachrichten jetzt Stimmsequenzen.

- **TCI-Ich-Perspektive:** Arbeitsmeldungen zu Core, Audio, Antwortgenerierung, Kontextfenster und Fehlern beschreiben Maras eigene Verarbeitung statt einen separaten Core als Person. Technische Core-Auswahl und Diagnosen bleiben erhalten.
- **Stimmsequenz:** Mikrofonstatus, Wartemeldungen, Begriffshilfen im KI-Profil, automatische Mitschriften und Anhangsbezeichnungen verwenden die neue Terminologie. Die bestehende Dateikennung `Sprachnachricht-...` und gespeicherte Ereignistitel bleiben unveraendert, damit bisherige Dateien/Verlaeufe weiterhin erkannt werden.
- **Sprachliche Korrekturen:** fehlende Artikel und Beugungen bei Core/Cores, altes Eve-Vorleselabel und irrefuehrende Trennung zwischen Mara und Core berichtigt.
- **Regressionsschutz:** `scripts/tci-language-selftest.cjs` prueft sichtbare Texte und unveraenderte Protokollkennungen; beide Preflights enthalten den Test. Bestehende UI-/Voice-Selbsttests wurden an die Bezeichnungen angepasst.
- Kein neues Core-Modell, keine Modelfile- oder Ollama-Parameter-Aenderung, keine Datenbankmigration; englische README bleibt sachliche Installationsanleitung.
- Validierung: lokale Runtime-Selbsttests und Live-Abnahme auf dem Betreiber-Server stehen bis zur Ausfuehrung aus. Kein ZIP-Release oder Live-Deployment wird behauptet.

## 0.6.23 - 06.11.2026

Screenshot des Betreibers nach 0.6.22: Auf eine Sprachnachricht beschrieb Mara "[Bildbeschreibung: Das Bild zeigt eine Nahaufnahme von zwei Haenden, die ein Stueck Brot halten ...]", zum zweiten Mal ein Bild, das nie geschickt wurde. Und Core hoerte "joder, alles glatt" statt "Bergisch Gladbach", obwohl die Aufnahme beim Abspielen im Chat normal klingt.

- **Kein erfundenes Bild mehr:** Sprachnachrichten und Audiodateien reisen im selben Medienfeld wie Bilder. Der Grundauftrag haengte bei jedem Medienanhang "[Bild wurde mitgesendet - beschreibe und kommentiere es als Mara.]" an, also auch bei einer reinen Sprachnachricht, und Mara beschrieb ein Bild. Bei reinen Audio-Anhaengen faellt der Satz jetzt weg; der Auftrag zur Aufnahme sagt, was gekommen ist. Bilder, Videos, PDF-Seiten und Bild plus Sprachnachricht behalten ihn.
- **Hoerprobe fuer den Server (`scripts/voice-hear-check.php`):** nimmt die neueste Sprachnachricht des Inhaber-Kontos (oder `--uuid`, `--file`, `--user`) und laesst sie genau so mitschreiben wie CoreAI, in drei Varianten: A wie bisher ohne Begriffe, B mit den Begriffen aus dem KI-Profil (oder `--terms`), C wie B mit vorher angeglichener Lautstaerke (ffmpeg loudnorm). Fuer den Core des Kontos oder mit `--cores alle` fuer jeden installierten Core mit Audio-Eingang, `--repeat` fuer Wiederholungen. Speichert und aendert nichts, jeder Aufruf wartet in Maras Warteschlange, temporaere Dateien werden auch bei Fehlern entfernt. Damit entscheiden wir anhand der echten Aufnahme, was hilft (Begriffe, Lautstaerke, ein anderer Core), statt zu raten.
- Tests: `voice-input-selftest.php` (kein Bild-Satz bei Audio, Bild plus Audio, Video, Bild ohne Beschreibung, beide Prompt-Bauer; Hoerprobe nutzt dieselben WAVs und Aufrufe). Die Hoerprobe lief hier gegen einen nachgebauten Core und MariaDB (drei Varianten, zwei Cores, Cloud-Core uebersprungen, keine Reste).
- Keine Aenderung an Cores, Modelfile, Datenbankschema, Installer oder Warteschlange.

## 0.6.22 - 06.11.2026

Betreiber mit zwei Screenshots: "Es wird alles verstanden, nur Bergisch Gladbach nicht: entweder kommt Berlin oder 'der Tisch ist glatt'. Kriegen wir die Erkennung allgemein nicht genauer hin?" Dazu im Arbeitsweg "Ich fuehre gerade 'Core schreibt die Aufnahme mit (Teil 1 von 1)' aus. Der Abschluss steht noch aus." und als Antwort auf eine gesprochene Wetterfrage nur die Zeile "WEB: Wetter Berchtesgaden heute". Und: "Ich finde das Wetter vom WDR am genauesten."

- **Ursache der Hoerfehler:** Kein schlechter Ton, sondern Eigennamen: Gemma kennt "Bergisch Gladbach" kaum und waehlt ein haeufigeres Wort, das aehnlich klingt. Entscheidung des Betreibers (06.11.2026): eigene Begriffe pro Konto.
- **Namen und Begriffe fuer Sprachnachrichten (KI-Profil):** Ein Feld unter "Antwort und Gedaechtnis", ein Begriff pro Zeile (Orte, Namen, Fachwoerter), hoechstens 40 zu je 60 Zeichen. Gespeichert wird zwei Sekunden nach dem Tippen und beim Verlassen des Feldes; der Server bereinigt die Liste (keine Klammern, Anfuehrungszeichen oder Doppelten). Core bekommt die Liste bei jeder Mitschrift als Schreibhinweis (eine zusaetzliche Zeile in Googles Vorlage, nur wenn Begriffe da sind, mit "nicht einfuegen, wenn nicht gesagt"), Mara in ihrem Auftrag zur Sprachnachricht. Nur angemeldete Konten, nie Gaeste. Neue Spalte `stu_user_voice_settings.vocabulary` (Migration 025, laeuft beim Update automatisch); ohne Migration bleibt das Feld sichtbar, aber gesperrt.
- **Aufnahme:** Der Browser nimmt ohne eigene Rauschunterdrueckung auf (sie kann leise Konsonanten verschlucken, etwa bei "Gladbach" und "glatt"), Echounterdrueckung und Pegelanpassung bleiben, Opus mit festen 64 kbit/s.
- **Arbeitsweg:** Die Mitschrift ist ein Schritt mit Abschluss ("Aufnahme wird mitgeschrieben", dann "Aufnahme mitgeschrieben") statt eines offenen Schritts pro Teil. Aeltere Laeufe lesen sich genauso.
- **Werkzeuge nach Sprachnachrichten:** Mara wollte suchen ("[WEB: ...]"), aber der Audio-Weg fuehrte keine Werkzeuge aus, und die Bereinigung machte aus dem Marker eine sichtbare Zeile. Jetzt kennt Mara bei Sprachnachrichten ihre freigegebenen Werkzeuge (Websuche, Browser-Recherche, Archiv) und ein Marker startet ihre Arbeitsschleife wie bei Text. Die Folgeschritte sind Text-Aufrufe ohne erneutes Audio, die Mitschrift steht fuer die Aufnahme. Ein Marker fuer ein abgeschaltetes Werkzeug steht nie als Antwort da.
- **Live-Wetter:** Eine Wetterfrage im Websuche-Schritt bekommt Live-Daten statt alter Suchtreffer (die Suche lieferte Vorhersagen von Juli und Oktober). Fuer Orte in NRW zuerst die WDR-Wettervorhersage (vom Betreiber bevorzugt), fuer jeden Ort die Live-Daten von Open-Meteo: jetzt, heute, morgen, uebermorgen. Nur wenn keine Quelle antwortet, laeuft die normale Suche. Der WDR deckt nur NRW ab; die freie Open-Meteo-API ist fuer nicht-kommerzielle Nutzung gedacht.
- **WDR-Seite nach der Analyse des Betreibers:** Gelesen wird der Teil von "Prognose fuer NRW" (Tag, elf Regionen mit Max und Min, "Stand:", Vorhersagetext) bis vor "Symbolerklaerungen" und "Weitere Beitraege", ohne Navigation, Suchfeld, Listenansicht und Regenradar; keine CSS-Klassen vorausgesetzt. Eine Vorhersage, deren "Stand:" mehr als 36 Stunden zurueckliegt, gilt nicht als heutiges Wetter (eine Regionsseite trug am 06.11. "Stand: 02.11."). Mara bekommt die naechste der elf WDR-Regionen mit ihren Werten (Bergisch Gladbach: Koeln, etwa 13 km). Die WDR-Seite war aus der Entwicklungsumgebung nicht erreichbar; Pruefbefehl auf dem Server: `php scripts/weather-live-selftest.php --live "Bergisch Gladbach"` im PHP-Container.
- **NRW-Wetter ueber die WDR-Suchmaske (Entscheidung des Betreibers: "fuer NRW-Anfragen am sinnvollsten, fuer alle anderen Orte und Laender wie bisher"):** Eine Wetterfrage zu einem Ort in NRW geht nicht mehr in den schnellen Websuche-Schritt, sondern an die Browser-Recherche: Sie startet auf https://www1.wdr.de/nrw/wetter/wettervorhersage/index.html, gibt den Ort in "PLZ oder Ort suchen" ein und liest die Vorhersage; Mara meldet sich mit dem Ergebnis zurueck (dauert laenger als der schnelle Weg). Das gilt nach getippten und gesprochenen Nachrichten, im ersten und in spaeteren Arbeitsschritten. Alle anderen Orte und Laender bekommen wie bisher sofort die Live-Daten. Ist die Browser-Recherche fuer das Konto nicht freigegeben, bekommt auch NRW den schnellen Weg mit der WDR-Uebersicht. Der Betreiber kann die Umleitung abschalten: `define('STU_EMBER_WEATHER_NRW_BROWSE', false);` in `api/config.local.php` (oder `COREUI_WEATHER_NRW_BROWSE=0`).
- **Browser:** startet an der https-Adresse, die im Auftrag steht (nie an lokalen oder privaten Adressen; der Worker prueft sie zusaetzlich), und gibt sich dann mit dieser einen Seite zufrieden, statt eine zweite Webseite zu verlangen. Mara kann die WDR-Ortssuche auch selbst waehlen, wenn jemand ausdruecklich danach fragt.
- Tests: `voice-input-selftest.php` (Begriffe: Bereinigung, Vorlage, Auftrag, beide Mitschriften mit Begriffen gegen den Stand-in-Core; Werkzeug-Marker bleiben ganz; Arbeitsschleife nach Sprachnachrichten verdrahtet; ein Arbeitsschritt mit Abschluss), `voice-read-selftest.php` (Begriffe auf der Datenbank, zurueckgerollt), neu `voice-terms-ui-selftest.cjs` (KI-Profil, Speichern, Bereinigung, Fehler, Gaeste, ohne Migration, Tag und Nacht, Handy), neu `weather-live-selftest.php` (mit dem Seitenaufbau aus der Analyse: elf Regionen, Stand, Vorhersagetext, nichts danach; alte Vorhersage; naechste Region), `web-search-selftest.php` (Wetter vor der Suche), `work-events-ui-selftest.js` (Mitschrift-Schritt), `agent-loop-selftest.php` (Umleitung im ersten und spaeteren Schritt, Startadresse des Browsers, keine lokalen Adressen), `browse-worker-selftest.py` (eine Quelle bei Startseite).
- Keine Aenderung an Cores, Modelfile, Installer oder Warteschlange. Datenbank: Migration 025.

## 0.6.21 - 06.11.2026

Betreiber: "Mara sagt immer Bergisch Glaeaedbach, als wuerde sie Platt versuchen."

- **Vorlesen:** Eve las "Gladbach" wie das englische "glad", mit langem, gedehntem a. Das Aussprache-Woerterbuch der deutschen Textaufbereitung schreibt es jetzt so, wie es gesprochen wird: "Glattbach", mit Endungen ("Gladbacher" wird "Glattbacher"), dazu "Moenchengladbach". Gespeicherte Vorlese-Audios mit der alten Aussprache werden nicht weiterverwendet, weil die Textaufbereitung Teil ihrer Kennung ist.
- Tests: `tts-spike-selftest.py` mit "Bergisch Gladbach", "Gladbacher Strasse" und "Moenchengladbach".
- Keine Aenderung an Cores, Modelfile, Datenbankschema, Installer oder Warteschlange.

## 0.6.20 - 06.11.2026

Screenshot des Betreibers (erste echte Sprachnachricht): Mara hoert die Aufnahme selbst, die Mitschrift erscheint, das Gedaechtnis speichert. Maras Antwort begann aber mit einer einzelnen eckigen Klammer: "[Hey Rick, vielen Dank fuer die Nachricht! ...", ohne schliessende Klammer.

- **Ursache:** Der Auftrag an Mara nannte jede Aufnahme mit dem Kuerzel "[Angehaengt: Sprachaufnahme, N Sekunden]" (aus dem Medientest P20.0), und Mara ahmte das Format nach. Im Medientest hatte das Kuerzel kaum etwas gebracht (5 von 10 mit, 6 von 10 ohne).
- **Behoben:** Der Auftrag nennt die Aufnahme jetzt in einem normalen Satz ("Eine Sprachnachricht von 31 Sekunden, als Audio."). Zusaetzlich verliert eine Antwort auf eine Aufnahme eine eckige Klammer am Anfang, die nie geschlossen wird, oder eine Klammer um die ganze Antwort. Markdown-Links und geschlossene Notizen am Anfang bleiben.
- Die verhoerten Woerter ("nach Berlin" statt "nen Termin") kommen aus Gemmas eigenem Hoeren: Mitschrift und Maras Antwort verhoerten unabhaengig voneinander dasselbe.

Zweite Runde Screenshots am selben Tag: Auf "Was gibt's denn so bei dir ab?" antwortete Mara wieder zum Termin in Berlin, jede Antwort begann mit "hab die Sprachnachricht gehoert", einmal stand der Kopf der Skill-Anleitungen "[COREAI KERN-SKILLS: ...]" vor der Antwort, einmal ein verwaistes "</blockquote>" am Ende. Die Mitschrift hatte den neuen Satz dagegen richtig gehoert. Entscheidung des Betreibers (06.11.2026): Mara bekommt die WAV und dazu die Mitschrift.

- **Mitschrift vor der Antwort:** Core schreibt die WAVs zuerst mit (Googles Vorlage, ohne Denken, ueber die Warteschlange, eine Wiederholung bei "keine Aufnahme"; im Arbeitsweg "Core schreibt die Aufnahme mit (Teil 1 von 2)"). Mara bekommt dieselben WAVs und die Mitschrift als Anker in ihrem Aufruf, hoert also weiter selbst. Grund: Ihr Aufruf ist lang (Verlauf, Gedaechtnis, Profil), darin ging die kurze neue Aufnahme bei der kleinen Stufe unter; der kurze Mitschrift-Auftrag traf sie. Das deckt sich mit dem Medientest (Aufnahmen direkt in Maras Persona ohne Denken scheiterten bei laengeren Clips, die Vorlage traf 10 von 10). Kosten: etwa 1 bis 3 Sekunden bei kurzen Nachrichten, etwa 18 Sekunden je Minute.
- **Die Mitschrift steht sofort unter der Nachricht** und im Verlauf; die Mitschrift nach der Antwort ueberspringt bereits erledigte Aufnahmen und springt nur ein, wenn die erste scheiterte. Eine Audiodatei, die fuers Kontextfenster gekuerzt wurde, schreibt sie danach vollstaendig (bis 10 Minuten) mit.
- **Auftrag:** Nur die neue Aufnahme zaehlt, fruehere Sprachnachrichten sind beantwortet, keine Ankuendigung "hab die Nachricht gehoert".
- **Kein Skill-Block bei Aufnahmen** (auch nicht in der Fortsetzung langer Antworten); dieser Weg fuehrt keine Werkzeuge aus.
- **Bereinigung der Antwort auf Aufnahmen:** ein reiner Ankuendigungssatz am Anfang ("Hey Rick, ich hab mir die Sprachnachricht angehoert. 😊") und schliessende HTML-Tags am Ende ohne oeffnenden fallen weg. Bleibt sonst nichts, bleibt die Antwort, wie sie ist.
- **Verlauf ohne eckige Klammern:** `Sprachnachricht: "..."` statt `[Sprachnachricht, Mitschrift: ...]`.
- Hinweis: Der falsch verstandene Fakt aus der ersten Sprachnachricht steht in Maras Gedaechtnis und lenkt weiter; er laesst sich unter Einstellungen > Memory mit dem x loeschen.
- Tests: `voice-input-selftest.php` (kein Klammer-Kuerzel im Auftrag, Klammer-Regel mit Gegenbeispielen, Mitschrift vor der Antwort mit Warteschlange und Wiederholung, dieselben WAVs, sofort gespeichert, Wiederverwendung beim Neu-Generieren, gekuerzte Datei nicht gespeichert, Bereinigung, Auftrag, Verdrahtung). Gegen den echten `ember_call_ollama`: bei Aufnahmen kein Skill-Block im Auftrag, bei Text weiterhin.
- Keine Aenderung an Cores, Modelfile, Datenbankschema, Installer oder Warteschlange.

## 0.6.19 - 05.11.2026

Betreiber: "Mara sollte selbst entscheiden koennen, was in ihr LTM gehoert, wie bisher auch." Bis 0.6.18 liefen Sprachnachrichten wie Bilder ohne Gedaechtnis-Reflexion (seit 0.5.71 bewusst fuer Bilder, Videos und PDFs, damit fremde Worte in einem Anhang nicht als Fakten ueber den Nutzer gespeichert werden), und der Beziehungswert sah nur eine leere Nachricht.

- **Nach der Mitschrift entscheidet Mara selbst:** Sobald die Mitschrift einer Sprachnachricht im Hintergrund fertig ist, geht der Austausch durch dieselbe Reflexion wie ein Text: das Gesagte (und was dazu getippt wurde) und Maras Antwort. Mara entscheidet wie gewohnt, ob und was ins Langzeitgedaechtnis kommt. Die bekannten Regeln gelten weiter (keine Passwoerter, Tokens oder privaten Daten, kurze Antworten wie "Ja." oder Fragen nach Uhrzeit und Wetter nicht).
- **Beziehungswert:** wird bei Sprachnachrichten aus dem Gesagten bewertet, ebenfalls nach der Mitschrift, und nicht mehr zusaetzlich mit der leeren Nachricht.
- **Bewusst nicht:** hochgeladene Audiodateien und Nachrichten, in denen eine Sprachnachricht zusammen mit Bildern oder Dateien kommt. Worte in einer Aufnahme von anderen (Podcast, Mitschnitt) sind keine Aussagen des Nutzers, wie Text in einem Bild.
- Tests: `voice-input-selftest.php` prueft, was an die Reflexion geht (Gesagtes und Getipptes, nicht bei Audiodateien, Mischungen, Stille, Fehlantworten oder anderem Konto) und die Verdrahtung im Stream; Gegenproben erkannt.

Dazu die Chatliste (Rueckmeldung mit Screenshot: "Archivieren und so sind echt sehr schwer zu treffen und auch nur halb zu sehen"; Wunsch: "ein Ladekreis im Chat-Button, wenn Mara dort gerade arbeitet"):

- **Knoepfe ganz sichtbar:** Die Liste ist eine Flex-Spalte; bei vielen Chats drueckte der Browser die Eintraege auf ihre Mindesthoehe von 53 Pixeln, und der schraege Rahmen (`clip-path`) schnitt UMBENENNEN und ARCHIVIEREN halb ab. Eintraege werden nicht mehr gestaucht, die Liste scrollt stattdessen.
- **Groessere Knoepfe:** mindestens 30 Pixel hoch und 10 statt 8 Pixel Schrift, auf Touch-Geraeten (Tablet, Handy, DeX mit Finger) 36 Pixel. Drei Knoepfe eines archivierten Chats brechen sauber in zwei Zeilen um.
- **Ladekreis:** Arbeitet Mara in einem Chat, dreht sich vor seinem Namen ein Ring, sofort beim Senden und auch fuer Laeufe aus einem anderen Tab, von einem anderen Geraet oder nach dem Neuladen. Die Chatliste meldet dazu `working` (Laeufe mit Lebenszeichen, dieselben Fristen wie beim Aufraeumen verlorener Laeufe, ein verlorener Lauf dreht also nicht ewig). Solange irgendwo gearbeitet wird, fragt die Seite alle 15 Sekunden nach, sonst jede Minute, nur bei sichtbarem Tab; neu gezeichnet wird die Liste nur, wenn sich etwas geaendert hat, damit kein Klick verloren geht. Bei reduzierter Bewegung steht der Ring still.
- Tests: neu `session-list-ui-selftest.cjs` (echte Seite, echtes Stylesheet, echtes `renderSessionList`, Tag und Nacht, Maus und Touch, Ladekreis; mit dem alten Stylesheet rot; in beiden Preflights), `agent-run-liveness-selftest.php` (welche Chats als arbeitend gelten, gegen MariaDB in einer zurueckgerollten Transaktion).
- Keine Aenderung an Cores, Modelfile, Datenbankschema, Installer oder Warteschlange.

## 0.6.18 - 05.11.2026

Preflight auf dem Server nach dem Update auf 0.6.17: "Spracheingabe-Selbsttest: Voice input FAIL: Mara is told: a voice message, its length, 3 parts, 2 minutes kept". Alle anderen Pruefungen bestanden.

- **Ursache:** Die Laenge einer Aufnahme wurde auf ganze Sekunden abgeschnitten statt gerundet. ffprobe misst dieselbe Datei je nach ffmpeg-Version um einige Millisekunden verschieden: Im php-Container laeuft Debians ffmpeg 5.1, in der Entwicklungsumgebung 6.1. Bei 149,978 statt 150,008 Sekunden hiess es "Laenge etwa 02:29" statt "02:30", und der Test erwartete "02:30". Mara haette im Betrieb nur eine Sekunde zu wenig gehoert, die Aufnahme selbst war nicht betroffen.
- **Behoben:** Laengen werden gerundet (`coreui_voice_length`), in Maras Hinweis zur Sprachnachricht und bei "nur die ersten ... Minuten". Zeitmarken der Teile bleiben abgeschnitten (Beginn eines Abschnitts).
- **Test:** `voice-input-selftest.php` prueft das Runden (149,98 und 150,008 s ergeben "02:30") und meldet bei einem Fehler jetzt, was Mara gesagt wurde und was ffprobe gemessen hat, statt nur einer Sammelmeldung.
- Keine Aenderung an Cores, Modelfile, Datenbankschema, Installer oder Warteschlange.

## 0.6.17 - 05.11.2026

Rueckmeldung des Betreibers zu 0.6.14: "Mara bekommt erst gar keine WAV-Datei, das Gesprochene wird fehlerhaft als Text geschrieben und dann muss ich die Textnachricht abschicken. Nix da Nutzung der nativen Audiofunktion vom Core." Neue Entscheidungen (05.11.2026): sofort senden, Mara hoert die WAV selbst, Mitschrift danach im Hintergrund fuer den Verlauf, ohne Denken bei Sprachnachrichten, hoechstens 60 Sekunden je WAV (Grenze der Gemma-4-Modelle).

- **Mikrofon sendet sofort:** FERTIG (oder ein zweiter Klick auf den Knopf) schickt die Aufnahme als Sprachnachricht in den Chat, mit Abspielknopf und mit dem Text, der schon im Eingabefeld steht. Kein Text muss mehr geprueft werden. Antwortet Mara gerade noch, wartet die Sprachnachricht und geht danach raus ("SPRACHNACHRICHT WARTET", abbrechbar).
- **Mara hoert selbst:** Die Aufnahme wird mit ffmpeg in WAVs mit 16 kHz, Mono und 16 Bit von hoechstens 60 Sekunden geschnitten (an Sprechpausen) und geht ueber denselben Weg wie Bilder und Videobilder (das images-Feld von Ollama) in Maras normalen Aufruf: mit ihrer Persona, ihrem Verlauf und KI-Profil, ohne Denken. Zwei Minuten sind so drei WAVs in einem Aufruf. Behauptet Core trotzdem, es gebe keine Aufnahme, wird einmal wiederholt. Eine fortgesetzte lange Antwort bleibt ohne Denken.
- **Audiodateien** hoert Mara genauso, bis 10 Minuten, soweit das Kontextfenster des Cores reicht (etwa 25 Tokens je Sekunde Audio, hoechstens 40 Prozent des Fensters); was nicht mehr passt, sagt sie offen.
- **Mitschrift danach:** Nach Maras Antwort schreibt Core im Hintergrund mit (Googles Vorlage, ohne Denken, ueber die Warteschlange, in der Hauptsprache des Kontos). Sie steht klein unter der Sprachnachricht ("Mitschrift folgt ..." waehrenddessen) und geht in den Verlauf, damit Mara sich in spaeteren Runden an das Gesagte erinnert. Gespeichert 7 Tage im Zwischenspeicher des Kontos, wie die Vorlese-Audios.
- **Fehler behoben:** Chrome nimmt Sprachnachrichten als WebM auf; eine WebM-Datei nur mit Ton wurde als Video gespeichert, Mara haette Einzelbilder gesucht. Dateien ohne Bildspur (Cover-Bilder zaehlen nicht) sind jetzt Audio.
- `api/voice_input.php` liefert nur noch den Status des Knopfs und den Stand der Mitschrift; der Weg ueber eine Mitschrift ins Eingabefeld (0.6.14) ist entfernt. Der Arbeitsweg zeigt "Hoere mir die Aufnahme an".
- Tests: `voice-input-selftest.php` (WAVs fuer Maras Aufruf, Budget, Gaeste und Cores ohne Audio, WebM als Audio, Mitschrift nach der Antwort mit Warteschlange und Wiederholung, Verlauf), `voice-input-ui-selftest.cjs` (sofort senden, Warten auf Mara, Mitschrift-Zeile, der echte Sendeweg aus `console-app.js`), `vision-persona-selftest.php` angepasst. Zusaetzlich geprueft: Durch den echten `ember_call_ollama` kommen die WAVs Byte fuer Byte und mit `think: false` bei einem nachgebauten Ollama an.
- Offen: Gedaechtnis-Reflexion fuer Sprachnachrichten (sie laufen wie Bilder ohne Reflexion), Abnahme mit Gemma auf dem Server.
- Keine Aenderung an Cores, Modelfile, Datenbankschema, Installer oder Warteschlange.

## 0.6.16 - 05.11.2026

- **Terran UI canon correction:** Replaced the inappropriate Ayal'ren system label in Settings with `TERRANISCHE SYSTEMKONFIGURATION`. The splash screen and visible app matrix signature now refer to Terra-Net rather than Ayal'ren.
- **Terra-Net, not Arch-Net:** Updated the splash connection phase and both chat connection messages to use `TERRA-NET VERBINDUNG`. Also corrected the accessible ticker label and neutralized the matrix artwork description without changing the art.
- **Regression protection:** New offline `scripts/terran-terminology-selftest.cjs` verifies visible CoreAI labels and rejects Arch-Net/Ayal'ren references in the four relevant user-facing source files. Registered in both preflight variants.
- **Developer guidance:** Added the terran-interface terminology rule to `AGENTS.md` and `CLAUDE.md` so future assistant changes do not reintroduce the lore mismatch.
- **Version and cache:** Updated to `0.6.16`, aligned six HTML pages, bootstrap, branding checks, preflight headers, changelog TXT index and handover, with fresh asset fingerprint references.
- **Safe scope:** Only presentation text, tests and release metadata. No changes to communication protocols, PHP APIs, database, installers, models, Modelfile, visual matrix art or historical changelog entries. Live deployment and browser QA remain separate.

## 0.6.15 - 05.11.2026

Rueckmeldung des Betreibers nach dem Update auf 0.6.14: kein Mikrofon-Knopf zu sehen.

- **Ursache 1, Endpunkt gesperrt:** nginx laesst nur PHP-Dateien aus einer festen Liste durch. `voice_input` fehlte darin, der Server antwortete auf `api/voice_input.php` mit 404, und der Knopf blendete sich ohne Status still aus. Jetzt steht `voice_input` in beiden Vorlagen (`config/runtime/nginx.conf.template` fuer Docker, `config/nginx.conf.template` fuer die native Installation).
- **Ursache 2, Mikrofon verboten:** nginx schickte `Permissions-Policy: microphone=()` mit. Damit haette der Browser das Mikrofon auch mit Knopf verweigert. Jetzt `microphone=(self)`: nur CoreAI selbst darf es nutzen, eingebettete fremde Seiten nicht. Kamera und Standort bleiben aus.
- Das Update bringt beides ohne Handgriff auf den Server: Der Installer baut die nginx-Konfiguration bei jedem Update aus der Vorlage neu.
- **Knopf:** Scheitert die Statusabfrage, steht der Grund jetzt in der Browser-Konsole ("CoreAI Spracheingabe: Status nicht lesbar (HTTP 404)"), und die Seite fragt bis zu dreimal im Minutenabstand erneut.
- Tests: `voice-input-selftest.php` prueft beide nginx-Vorlagen (Route und Mikrofon-Regel; mit der alten Vorlage schlaegt er fehl), `voice-input-ui-selftest.cjs` den Fall 404, beide Preflights pruefen die Vorlage.
- Keine Einstellung noetig: Die Spracheingabe ist der Knopf im Eingabefeld; die Sprache der Mitschrift kommt aus der Hauptsprache im KI-Profil.
- Keine Aenderung an Cores, Modelfile, Datenbankschema, Installer oder Warteschlange.

## 0.6.14 - 05.11.2026

P20.1 Spracheingabe, wie vom Betreiber entschieden: Die Mitschrift landet im Eingabefeld und wird dort geprueft und gesendet; die Aufnahme wird nach der Mitschrift sofort geloescht; nur angemeldete Konten (Inhaber, Admins, Nutzer), keine Gaeste; Mikrofon bis 2 Minuten, Audiodateien bis 10 Minuten.

- **Mikrofon-Knopf im Eingabefeld** (zwischen Text und Senden, auch am Handy): ein Klick nimmt auf, die Leiste darueber zeigt Zeit, Pegel, "Abbrechen" und "Fertig". "Fertig" oder ein zweiter Klick beendet die Aufnahme, Core schreibt mit, und der Text steht an der Cursorstelle im Eingabefeld. Nach 2 Minuten endet die Aufnahme von selbst. Reine Stille wird gar nicht erst gesendet (Pegelpruefung im Browser). Ein verweigertes Mikrofon und Fehler des Servers werden verstaendlich gemeldet; eine laufende Mitschrift laesst sich abbrechen (auch mit Esc).
- **Core hoert selbst** ueber Gemmas Audio-Eingang, zweistufig nach Lauf 4 des Medientests: zuerst die Mitschrift mit Googles Vorlage, neutralem SYSTEM-Text, ohne Denken und ohne Parameter (nur das Kontextfenster aus dem KI-Profil geht mit, damit Core nicht neu laedt), dann antwortet Mara auf den Text wie auf jede Nachricht. Die Vorlage folgt der Hauptsprache des Kontos (Deutsch oder Englisch).
- **Server** (`api/voice_transcribe.php`, `api/voice_input.php`): ffmpeg wandelt jede Aufnahme in WAV mit 16 kHz, Mono, 16 Bit, der Header wird vor dem Senden geprueft. Lange Aufnahmen werden an Sprechpausen in Abschnitte von hoechstens 60 Sekunden geschnitten (ohne Pause hart bei 60 s), Abschnitte, die nur Stille sind, gehen nicht an Core. Jeder Abschnitt laeuft einzeln durch Maras Modellwarteschlange; ohne Warteschlange wird nichts gesendet. Behauptet Core, es gebe keine Aufnahme, wird einmal wiederholt. Eine Mitschrift je Konto gleichzeitig; die Aufnahme und alle Zwischendateien sind geloescht, bevor die Antwort den Server verlaesst.
- **Audiodateien als Anhang** (MP3, M4A, WAV, OGG und mehr): Core hoert bis zu 10 Minuten und bekommt die Mitschrift mit Zeitmarken (Minuten:Sekunden), Laenge und dem ehrlichen Hinweis, dass sie Hoerfehler enthalten kann. Laengere Dateien: die ersten 10 Minuten, offen gesagt. Dateien ohne Sprache werden als solche benannt. Der Arbeitsweg zeigt "Core hoert die Audiodatei (Abschnitt N von M)". Die Mitschrift bleibt 7 Tage im Vorlese-Zwischenspeicher des Kontos (gleiche Frist und gleiches Loeschen wie beim Vorlesen), damit eine erneute Antwort die Datei nicht noch einmal anhoert. Die Datei selbst bleibt ein normaler Anhang.
- **Cores ohne Audio** (laut `/api/show`, zum Beispiel Cloud-Cores ohne Audioeingang): Der Knopf bleibt sichtbar, ist aber abgeschaltet und nennt den Grund; ein Wechsel im Core-Menue prueft neu. Audioanhaenge bekommen dann wie bisher den ehrlichen Hinweis, ebenso Gaeste.
- Tests: neu `voice-input-selftest.php` (Schnitt, Auftrag, Verdrahtung; mit ffmpeg echte Umwandlung einer Opus-Aufnahme, 2-Minuten-Grenze, Sprechpausen und der ganze Weg gegen ein nachgebautes Ollama mit gezaehlter Warteschlange: Wiederholung, Stille, Abbruch, volle Warteschlange, Core ohne Audio, Audioanhang mit Zwischenspeicher, Gaeste) und `voice-input-ui-selftest.cjs` (Chromium mit simuliertem Mikrofon); beide in beiden Preflights.
- **Werkzeugleiste ueber dem Eingabefeld entfernt** (Wunsch des Betreibers): Die Zeile "TOOL BUS" mit RAG-LITE, CRUD-LZG, WEB und BROWSE war reine Deko, kein Skript hat ihren Zustand je gesetzt. Die Werkzeuge stehen im Werkzeug-Tab der Infokaesten. Der Eingabebereich wird dadurch etwa 30 Pixel niedriger. Ihre Stile (hell, Handy, Nachtmodus) sind mit entfernt; `theme-preferences-selftest.cjs` prueft, dass sie nicht zurueckkommt.
- Keine Aenderung an Cores, Modelfile, Datenbankschema, Installer oder Warteschlange. Videos hoeren (P20.2) folgt.

## 0.6.13 - 05.11.2026

- **Dark-mode contrast hotfix:** Removed the conspicuous light band beneath the chat header. Its real selectors `.insight-chips`, `.insight-chip` and `.insight-sheet .insight-box` now use dark high-contrast surfaces, readable labels, distinct active and hover states, and a dark sticky fade. Both narrow-screen chips and wide-screen insight panels are covered.
- **Owner attribution visible:** Corrected the hardcoded dark-on-dark colours on `.about-copy .copyright-line` and the `strong` owner name. The Quantum family heading and secondary copyright/footer lines are readable in Night mode.
- **Navigation surfaces:** Fixed old light backgrounds in the expanded sidebar header and footer, ticker, status/action chips and file links. No image inversion and no change to the Light theme.
- **Regression protection:** Extended the offline `scripts/theme-preferences-selftest.cjs` with direct checks for the real CSS selectors and expected dark overrides. Existing preflight scripts already invoke it when Node is available.
- **Cache and version:** Recomputed the SHA-256-derived numeric theme CSS/JS keys and aligned VERSION, six visible HTML pages, JavaScript bootstrap, branding/preflights, changelog and handover to `0.6.13`.
- **Scope:** CSS, a browser regression test and release metadata only. No database, installer, runtime, model or other-repository changes. Browser/server visual verification remains separate.

## 0.6.12 - 05.11.2026

- **Native day/night appearance:** A new **Settings → Display** selector provides **System**, **Light** and **Dark** with swatch previews and accessible radio controls. Changes apply instantly; System follows device appearance changes.
- **Persistence without a migration:** Uses the existing browser-local `CoreUIPreferences` storage key, preserving older display settings. The mode survives reloads and syncs across tabs; invalid values fall back safely. Display reset restores System.
- **Native dark Holo palette:** New `css/coreui-theme.css` covers chat messages, drawer, composer, settings, Admin Core, code, login, protocols and file previews. The original light palette and image pixels are untouched; no inversion filters.
- **Offline regression checks:** New `scripts/theme-preferences-selftest.cjs` tests System changes, explicit overrides, old preference data, storage failure, cross-tab updates and required UI wiring. Registered in both preflight variants when Node is present.
- **Documentation:** README and roadmap describe appearance as available; the full English/German interface localization remains a separate planned feature.
- **Release metadata:** Version displays, numeric content hashes, bootstrap, branding checks, changelog and handover aligned to `0.6.12`. No model, database, server API, Ollama or installer changes.
- **Validation scope:** Full Node and browser preflight must be executed on a checkout and the deployment separately verified.

## 0.6.11 - 05.11.2026

- **User-local date and time:** Mara's private CoreAI chat now receives the browser-configured IANA time zone (such as `Asia/Manila`) on new messages and streamed requests. The server validates identifiers and computes the current local time using PHP's timezone database.
- **No location permission:** There is no GPS request, IP lookup, or database migration. Invalid or missing client zones safely use the configured server timezone.
- **Consistent execution:** The main response, normal and streamed generation, and additional per-file Core calls use the active request's timezone. Other request contexts retain their existing time behavior.
- **Regression tests:** `scripts/date-awareness-selftest.php` now checks Philippines/Berlin midnight, DST transitions, invalid timezone input and the frontend/backend connection. This test is already invoked in both preflight variants.
- **Version synchronization:** `0.6.11` across README, versioned pages, bootstrap, changelogs, branding and preflight markers. No changes to database, installer, models, Modelfile, Ollama or other repositories.
- **Testing limit:** Code and static test coverage added; production device/browser and full server preflight must be verified separately.

## 0.6.10 - 04.11.2026

P20.3b, Schritt 2: die Bruecke zur Sprachauswahl, wie vom Betreiber entschieden (Spracheinstellung pro Konto im KI-Profil fuer Antworten und Vorlesen; Mara bleibt mehrsprachig, Deutsch und Englisch sind die Hauptsprachen).

- **KI-Profil, "Sprache":** Deutsch oder English. Darunter steht ehrlich, dass Knoepfe und Menues vorerst deutsch bleiben; die uebersetzte Oberflaeche ist weiter geplant.
- **Maras Antworten:** Bei Englisch bekommt der Prompt einen kurzen Block (standardmaessig Englisch, schreibt die Person in einer anderen Sprache, antwortet Mara in dieser; Deutsch und Englisch sind ihre Hauptsprachen). Bei Deutsch bleibt der Prompt wie bisher, das Modelfile antwortet ohnehin deutsch und folgt der Sprache der Person.
- **Vorlesen:** Die Kontosprache ist die Vorgabe, wenn die Sprache einer Antwort unklar ist (kurze Antworten wie "OK"). Sie gehoert zum Plan; deutsche Plaene behalten ihre Kennung.
- **Speichern:** Migration 024 (`stu_user_ai_settings.main_language`, Standard `de`). Eine unbekannte Sprache wird abgelehnt (`invalid_language`), ohne Migration 024 wird Englisch nicht still verworfen (`missing_schema_024`), und ein Speichern ohne Sprache (aeltere Seite) behaelt die gespeicherte.
- Tests: neu `account-language-selftest.php` (Regeln, Prompt, Vorlese-Vorgabe, Verdrahtung; mit Datenbank Speichern und Laden in einer zurueckgerollten Transaktion; in beiden Preflights), `voice-read-selftest.php` (Vorgabe beim Sprachdienst), `settings-tabs-ui-selftest.cjs` (Auswahl zeigt den gespeicherten Wert und wird gespeichert).
- Keine Aenderung an Cores, Modelfile, Installer oder Warteschlange.

## 0.6.9 - 04.11.2026

Rueckmeldung des Betreibers: Sobald Mara auf Englisch antworten will, haengt sich die Anfrage auf ("Core denkt nach" ueber 20 Minuten). Ein Abbruch bleibt bei "Abbruch angefordert" stehen, und mehrere Chats im Archiv lassen sich nicht loeschen, weil angeblich noch etwas laeuft.

- **Echte Antworten wurden als Gedankenleck verworfen.** Die Bereinigung hielt Saetze wie "I need to respond in English ...", "Ich muss antworten, dass ..." oder jede Antwort, die einen System-Prompt erklaert, fuer interne Modellanalyse und warf die ganze Antwort weg. Eine leere Antwort startete eine neue Generierung, die wieder verworfen wurde; auf der CPU dauerte das viele Minuten. Gerade ein Gespraech ueber die Antwortsprache loeste das aus. Diese Muster zaehlen jetzt nur noch zusammen mit einem zweiten Signal; eindeutige Lecks ("the user is asking", "der Nutzer fragt", "the prompt says", beschriftete Entwuerfe) bleiben sofort intern. Ein Absatz wird nur noch abgeschnitten, wenn er wie Analyse beginnt ("Maybe I should", "Actually, the prompt"), nicht bei jedem "Actually" oder "Maybe".
- **Verlorene Laeufe enden.** Ein Lauf, dessen PHP-Prozess verschwunden ist (der PHP-Container wird bei jedem Update neu erstellt, oder der Prozess wurde beendet), blieb bisher fuer immer "running": "Core denkt nach" bis zu 45 Minuten, ein Abbruch, der nie fertig wurde, und ein Chat, der sich nicht loeschen liess. Jetzt meldet ein laufender Prozess mindestens alle 15 Sekunden ein Lebenszeichen (bei jeder Abbruchpruefung im Modellstream, in der Warteschlange und beim Warten auf Python). Ohne Lebenszeichen endet der Lauf: nach 45 Sekunden, wenn ein Abbruch angefordert ist (dann "abgebrochen"), sonst nach 3 Minuten ("unterbrochen"). Ein Lauf, der nie gestartet wurde, endet nach 10 Minuten. Das passiert beim Neuladen des Chats, beim Abfragen und Abbrechen eines Laufs und beim Loeschen eines Chats; bereits haengende Chats im Archiv werden dadurch beim naechsten Loeschversuch frei.
- Tests: neu `agent-run-liveness-selftest.php` (Verdrahtung und, mit Datenbank, das Beenden verlorener Laeufe in einer zurueckgerollten Transaktion; in beiden Preflights), `thinking-sanitize-selftest.php` mit sieben echten Antworten, die erhalten bleiben, und einem Leck aus zwei schwachen Signalen.
- Keine Aenderung an Cores, Modelfile, Datenbankschema, Installer oder Warteschlange.

## 0.6.8 - 04.11.2026

Hoertest der englischen Eve (Lauf 20261104-215318): Beide Modelle klingen fuer den Betreiber gleich gut, mit perfektem Englisch. Gewaehlt wurde das Standardmodell (RTF 0,19 und 1066 MB statt 0,47 und 3014 MB).

- **Eve liest englische Antworten auf Englisch.** Der Sprachdienst erkennt die Sprache einer Antwort an ihren Woertern (Codebloecke zaehlen nicht mit, im Zweifel Deutsch) und spricht jeden Teil mit der passenden Eve. Deutsche Antworten mit englischen Begriffen bleiben deutsch.
- **Registriert:** `config/voice/mara-voice.json` fuehrt die englische Eve unter `languages.en` mit Modell (`english`, int8), Stimme, Textaufbereitung `en-1`, den drei Dateien mit Revision, SHA-256, Groesse und Lizenz und den Messwerten. Die deutsche Eve steht unveraendert oben.
- **Nach Bedarf geladen:** Die englische Eve laedt beim ersten englischen Vorlesen (beim allerersten Mal mit Download von etwa 0,23 GB). Der Lautsprecher-Knopf wartet so lange (bis zu 3 Minuten), statt den Teil zu ueberspringen. Beide Stimmen bleiben geladen, solange ihre gemessenen Werte zusammen unter `COREAI_VOICE_MEM_MB` (5600 MB) liegen; sonst wird die am laengsten nicht genutzte entladen, nie mitten in einer Ansage.
- **Englische Textaufbereitung `en-1`** (`tools/voice/textprep_en.py`): E.M.B.E.R. wird "Ember", e.g./i.e./etc., Zeit- und Zahlenbereiche ("12:00 to 20:00"), Zeitzonen, Striche und Pfeile als Pause. Zahlen, Datum und Uhrzeit liest das englische Modell selbst. Englische Hinweise: "Code block skipped.", "Table skipped.", "link".
- Gespeicherte Plaene merken sich ihre Sprache; Plaene von vorher gelten als Deutsch. Die Plan-Kennung folgt auch `textprep_en.py`.
- Tests: `voice-service-selftest.py` (Erkennung, englischer Plan, Laden nach Bedarf, beide geladen, Entladen bei knappem Speicher), `voice-read-selftest.php` (Sprache im Plan und beim Sprechen, alte Plaene), `voice-read-ui-selftest.cjs` (Warten, solange die Stimme laedt), `tts-spike-selftest.py` (Registereintrag).
- Keine Aenderung an Cores, Modelfile, Datenbank, Installer oder Warteschlange. Offen: Spracheinstellung im KI-Profil.

## 0.6.7 - 04.11.2026

Rueckmeldung des Betreibers nach dem Update auf 0.6.5: Mara spricht Denglisch einwandfrei, nur bei "TCI" fehlt das I, es klingt wie "TC".

- **TCI** steht jetzt im Aussprache-Woerterbuch und wird mit englischen Buchstabennamen gesprochen: "Ti Si Ei" (wie "CoreAI" als "Kohr Ey Ei"). Auch "TCIs" und "TCI-Kern".
- **Interface** klingt jetzt "Interfeys" statt "Interfehs" (Wunsch des Betreibers, passend zu "Kohr Ey Ei").
- **Gespeicherte Vorlese-Plaene folgen dem Code:** Die Kennung eines Plans haengt jetzt neben dem Register auch von `tools/tts_spike/textprep_de.py` und `tools/voice/speakable.py` ab. Ein neuer Woerterbuch-Eintrag ist damit sofort zu hoeren, auch bei Antworten, die schon einmal vorgelesen wurden; bisher kam eine solche Antwort bis zu 7 Tage lang aus dem Speicher mit der alten Aussprache. Alte Plaene werden nach 7 Tagen aufgeraeumt.
- Tests: `tts-spike-selftest.py` und `voice-service-selftest.py` mit "Ti Si Ei", `voice-read-selftest.php` prueft die Quellen der Plan-Kennung.
- Keine Aenderung an Cores, Modelfile, Datenbank, Installer oder Warteschlange.

## 0.6.6 - 04.11.2026

Vorbereitung fuer Englisch (P20.3b). Entscheidungen des Betreibers: Sprachmodell nach Bedarf laden (ein Sprachdienst fuer alle Konten), Spracheinstellung pro Konto im KI-Profil fuer Antworten und Vorlesen (Mara bleibt mehrsprachig, Deutsch und Englisch sind die Hauptsprachen), zuerst Hoertest der englischen Eve.

- **Stimmtest mit englischer Eve:** zwei neue Gruppen, nur Eve mit int8: `pocket-en-eve-int8` (englisches Standardmodell) und `pocket-en24-eve-int8` (englisch, 24 Schichten, Stand 2026-09). Beide in Pocket TTS 4.0.1 enthalten, ohne Token. Eve ist dieselbe Sprecherin wie auf Deutsch (VCTK p361).
- **Englische Testsaetze** `e1` bis `e7` in `tools/tts_spike/voices.json` (Begruessung, Zahlen, Datum und Uhrzeit, Frage, Dateinamen, Produktnamen, laengerer Absatz). Englische Gruppen sprechen sie ohne die deutsche Textaufbereitung; so ist hoerbar, ob Englisch eine eigene Aufbereitung braucht.
- `tools/tts_spike/run.py`: Sprache je Gruppe (`english...` ist Englisch), Saetze je Sprache, Ergebnis und Bericht nennen die Sprache; der Bericht fuehrt die englischen Saetze nur, wenn eine englische Gruppe gemessen wurde.
- Admin Core, Stimmproben: englische Hoerproben werden angenommen (`...-e1.wav`), jede Stimme zeigt nur die Saetze ihrer Sprache, englische Saetze sind als Englisch markiert.
- Sprachplan (P20.3b) und Roadmap mit den Entscheidungen und dem Befehl fuer den Hoertest. Uebersetzte Oberflaeche sowie Tag- und Nachtmodus bleiben Planung.
- Tests: `tts-spike-selftest.py` (Register, englische Probegruppe spricht e1 bis e7 unveraendert, Bericht mit Sprache), `tts-spike-store-selftest.php` (englische Saetze und Hoerproben), `voice-samples-ui-selftest.cjs` (Saetze je Sprache).
- Keine Aenderung am Vorlesen, an Cores, Modelfile, Datenbank, Installer oder Warteschlange.

## 0.6.5 - 04.11.2026

Rueckmeldung nach dem ersten Live-Vorlesen auf dem Server: Englische Woerter klangen deutsch gelesen, bei "E.M.B.E.R." brach das Vorlesen ab, "12:00-20:00 CET" wurde Kauderwelsch, und es gab oft lange Pausen.

- **Textaufbereitung `de-2`** (`tools/tts_spike/textprep_de.py`, auch im Register `config/voice/mara-voice.json` und im Stimmtest):
  - Abkuerzungen mit Punkten: "E.M.B.E.R." wird "Ember", andere werden buchstabiert ("U.S.A." wird "U Es A"). Mehr feste Abkuerzungen (i.d.R., s.o., etc., Dr., max., min., mind. und weitere). Der Satzpunkt bleibt erhalten, wenn danach ein neuer Satz beginnt.
  - Uhrzeiten ohne "Uhr" ("18:45"), Zeitbereiche ("12:00-20:00", "9-17 Uhr"), Zeitzonen (CET, MEZ, CEST, MESZ, UTC, GMT mit Versatz), ISO-Datum ("2026-11-04"), Kurzdatum ("am 4.11."), Dauer ("1:02:30", "2:30 min"), Verhaeltnisse und Ergebnisse ("16:9", "2:1"), Zahlenbereiche ("3-5", "2024-2026"), Versionen ("v0.6.3" wird "Version null Punkt sechs Punkt drei"), Domains und E-Mail-Adressen, Grad, "circa".
  - Englische Woerter: ein Aussprache-Woerterbuch mit rund 100 Eintraegen schreibt gaengige Begriffe deutsch lautlich um ("Update" wird "Apdeit", "Browser" wird "Brauser", "Chat" wird "Tschaett", "CoreAI" als "Kohr Ey Ei" mit englischem AI, vom Betreiber per Hoertest unter vier Schreibweisen gewaehlt), mit deutschen Endungen ("updaten", "Chats") und Zusammensetzungen ("Updatefenster"). Deutsche Woerter, die nur aehnlich aussehen (Tablette, Mailand, Hostie), bleiben.
  - Striche und Pfeile zwischen Woertern werden eine kurze Pause statt eines gelesenen Zeichens.
- **Keine langen Pausen mehr:**
  - Aufteilung (`tools/voice/speakable.py`): Jeder Teil ist hoechstens 1,8-mal so lang wie der vorige (erster Teil hoechstens 120 Zeichen, dann bis 320). Eve rechnet etwa doppelt so schnell, wie sie spricht, deshalb ist der naechste Teil fertig, bevor der aktuelle endet. Bisher konnte auf einen kurzen ersten Satz ein Teil mit 320 Zeichen folgen, und die Rechenzeit dafuer war als Stille zu hoeren. Geteilt wird an Satz- und Kommagrenzen; ein Satz, der im naechsten Teil weitergeht, endet mit Komma statt Punkt.
  - Sprachdienst (`tools/voice/voice_service.py`): Stille am Anfang und Ende jedes Teils wird auf 0,15 s gekuerzt, Pausen im Teil auf hoechstens 0,7 s.
  - Wiedergabe (`js/coreui-voice-read.js`): Die Teile werden der Reihe nach geholt, immer nur einer gleichzeitig, bis zu drei im Voraus, und in einem einzigen Audio-Element abgespielt. Das funktioniert auch auf Handys, die das Vorladen ignorieren. Ein Teil, der zweimal scheitert, wird uebersprungen; erst zwei Fehler in Folge beenden das Vorlesen.
- **Neue Regeln kommen nach einem Update an:** Der Sprachdienst prueft alle 20 s, ob sich sein Code oder das Register geaendert hat, beendet dann die laufende Ansage und startet neu (Docker startet ihn wieder). `compose.yaml` schaltet das mit `COREAI_VOICE_WATCH_S` ein; dadurch wird der Container beim naechsten Update einmal neu erstellt. Der Installer bleibt unveraendert.
- Nach dem Codex-Review von PR #156: Ergebnisse und Verhaeltnisse mit zweistelliger Zahl werden am Kontext erkannt ("Ergebnis 2:10" wird "zwei zu zehn", "um 18:45" bleibt Uhrzeit); "3.14" wird eine Kommazahl, nur nach Version, Kapitel oder Softwarenamen ("Python 3.11") und bei drei Teilen ("0.6.3") "Punkt"; "2:30 Min." behaelt den Satzpunkt; mehr Satzanfaenge nach "E.M.B.E.R." erkannt ("Morgen", "Anschliessend"); Namen wie Homer, Corelli, Gamelan, Hostessen bleiben unveraendert; ein sehr kurzer erster Satz ("Ja.") wird aufgefuellt, damit der zweite Teil nicht viel laenger ist; ein Abspielfehler bekommt wie ein Ladefehler einen zweiten Versuch.
- Gespeicherte Plaene und Audiodateien aus `de-1` werden nicht wiederverwendet (anderer Registerstand), sondern nach 7 Tagen aufgeraeumt.
- Tests: `tts-spike-selftest.py` mit den Beispielen aus der Rueckmeldung und Gegenproben, `voice-service-selftest.py` mit wachsenden Teilen, Stillekuerzung und Neustart bei neuem Code, `voice-read-ui-selftest.cjs` mit der neuen Wiedergabe (nie zwei Teile gleichzeitig, hoechstens drei voraus, ueberspringen und abbrechen). Ob die Umschreibungen gut klingen, zeigt erst der Live-Test.
- Keine Aenderung an Cores, Modelfile, Datenbank, Installer, Warteschlange oder Stimme.

## 0.6.4 - 04.11.2026

- **English README restored:** All current product, setup, hardware, feature, operation and licensing guidance is now in English, the default language for public GitHub documentation. Historical German records and the legally controlling German license are preserved.
- **Built-in updater documented:** A dedicated section explains **Settings > System > CoreAI Update** (owner only), automatic GitHub checks every ten minutes where systemd services are configured, manual checks, update notifications, backup before installation, optional Ollama restart, live progress and automatic page reload. All menu and button references in the English README are translated into English without German parenthetical labels. English/German UI language selection with day/night themes remains planned. The SSH one-liner remains the initial-install and fallback method.
- **Repository policy:** `AGENTS.md`, `CLAUDE.md` and `docs/VERSIONING_POLICY.md` now explicitly require an English README and documentation of both update paths. The README remains a current manual, not a changelog.
- **Version guard and tests:** `scripts/version-merge-check.py` expects the English Quick start section and requires the built-in updater section. `tests/test_version_merge_check.py` includes regression tests for the language and updater rules.
- **Version synchronization:** `VERSION`, six HTML pages, JavaScript bootstrap, branding test, preflight version headers, changelog archive and handover aligned to `0.6.4`.
- No changes to the actual updater, installer, database, models, Modelfile, Ollama configuration or runtime logic. Live server checks remain separate.

## 0.6.3 - 04.11.2026

- **Preflight-Fehler "Vorlesen-Selbsttest: service down: not available" behoben.** Der Test stellte "Sprachdienst aus" mit der Adresse `127.0.0.1:1` nach. Die Adresspruefung nimmt aber nur Ports mit zwei bis fuenf Ziffern an und fiel deshalb auf den echten Port 18790 zurueck. Lief dort Eve schon und war bereit (so auf dem Server nach dem Update auf 0.6.1), meldete der Test "Dienst an" und der Preflight schlug fehl. Fehler im Test, nicht im Vorlesen.
- `scripts/voice-read-selftest.php` nutzt fuer "Dienst aus" jetzt einen freien Port, auf dem nichts lauscht, und prueft vorher, dass die Adresse wirklich verwendet wird und nicht auf den echten Dienst zurueckfaellt. Nachgestellt mit einem bereiten Dienst auf 18790: alter Test scheitert mit derselben Meldung, neuer besteht.
- `tests/test_version_merge_check.py`: Die Testvorlage hatte seit 0.6.2 keine gueltige README (One-Liner, CHANGELOG-Link, Schnellstart), drei Tests schlugen deshalb schon auf `main` fehl. Vorlage ergaenzt, dazu zwei Tests fuer die neuen README-Regeln (offizieller One-Liner, keine Versionsabschnitte). Der Preflight fuehrt diesen Entwicklertest nicht aus.
- Keine Aenderung an Vorlesen, Sprachdienst, Cores, Modelfile, Datenbank, Installer oder Warteschlange.

## 0.6.2 - 04.11.2026

- **README repariert:** Die Projekt-README beschreibt wieder Produkt, aktuellen Funktionsumfang, Installation, Betrieb, Lizenz und Diagnose statt einer zweiten Versionschronik.
- **CPU-only-Hardwareprofile:** Richtwerte fuer den Einstieg mit E2B, E4B und groessere Cores, Modell-/Kontext-RAM getrennt vom Serverbedarf. Ein GPU-loser Root-Server ist die Referenzplattform. Optionale GPU-/VRAM-Beschleunigung getrennt beschrieben.
- **Preisorientierte Beispiele:** netcup Root-Server RS 1000 bis RS 8000 G12 als unverbindliche Hardwarebeispiele. Keine Aussage, netcup sei nachweislich immer der guenstigste deutsche Anbieter.
- **Setup-One-Liner:** Direkt beim Schnellstart und unter der kanonischen URL `Starlight-Unit-Studio/CoreAI-TCI-Interface/main/setup.sh`. Eine fruehere GitHub-Weiterleitung vom historischen `coreui`-Pfad konnte weiterhin funktionieren; der eigentliche Installer wurde nicht geaendert.
- **Regeln fuer Coding-Assistenten:** Neue `AGENTS.md` und `CLAUDE.md` sowie Dokumentationsrichtlinie in `docs/VERSIONING_POLICY.md` verbieten Release-Notizen in der README; die Versionshistorie gehoert nach `CHANGELOG.md` und `docs/changelogs-txt/`.
- **Versions-Guard:** `scripts/version-merge-check.py` prueft README-Laenge, kanonischen Installationslink, Changelog-Verweis und unerwuenschte Versionsabschnitte. Aktive Versionsanzeigen, JS-Bootstrap, Branding-Test, Preflights und TXT-Archiv auf 0.6.2 aktualisiert.
- **Kein Eingriff in die Runtime:** Keine Aenderungen an Cores, Modelfile, Datenbank, SQL-Migrationen, Ollama-Konfiguration oder Installer. Hardwareempfehlungen sind nicht als gemessene Netcup-Benchmarks zu verstehen; Live-Abnahme steht aus.

## 0.6.1 - 04.11.2026

- **Mara liest vor (P20.3):** Lautsprecher-Knopf unter jeder Mara-Antwort. Eve (Pocket TTS Deutsch, 24 Schichten, int8) liest Satz fuer Satz: Der erste Teil ist kurz, damit sie schnell anfaengt, der naechste laedt waehrend der Wiedergabe, ein zweiter Klick stoppt. Beim Neuzeichnen des Chats behaelt der Knopf seinen Zustand.
- **Was gelesen wird** (`tools/voice/speakable.py`): Codebloecke und Tabellen werden benannt ("Codeblock ausgelassen."), Links behalten ihren Text, Adressen werden zu "Link", Ueberschriften und Listenpunkte werden eigene Saetze, Markdown-Zeichen, Werkzeug-Marker und Emojis fallen weg. Danach die Textaufbereitung `de-1` (Zahlen, Datum, Uhrzeit, Dateinamen als Woerter).
- **Sprachdienst** (`tools/voice/voice_service.py`, Container `voice`, optional): haelt Eve geladen, nur auf 127.0.0.1, ein Auftrag gleichzeitig (andere warten, dann "beschaeftigt"), 4 Kerne, Zeitlimit je Teil (fuenffache Sprechdauer, mindestens 10 s). Beim Start prueft er, dass Pocket genau die registrierten Dateien laedt, holt sie zu den registrierten Revisionen und vergleicht jeden SHA-256 mit `config/voice/mara-voice.json`; kein Token noetig. Laeuft als uid 33, Dateisystem nur lesbar, Speicher begrenzt. Scheitert Bau oder Start, laeuft CoreAI ohne Vorlesen weiter (`COREUI_INSTALL_VOICE=0` laesst ihn ganz weg).
- **Rechte und Speicher** (`api/voice.php`, `api/voice_store.php`): nur angemeldete Konten, nie Gaeste; vorgelesen wird nur eine eigene Mara-Antwort (gleiche Pruefung wie bei Bewertungen). Plan und Audio pro Konto unter `var/voice_cache/<id>`, 7 Tage, dann geloescht; das Loeschen eines Kontos entfernt sie sofort. nginx gibt `api/voice.php` frei.
- **KI-Profil:** "Antworten automatisch vorlesen" (Standard aus, Migration 023 `stu_user_voice_settings`). Ist es an, liest Eve jede neue Antwort nach dem Fertigwerden vor.
- Tests: neu `voice-service-selftest.py` (vorlesbarer Text, Teile, Dienst mit Testton: Gesundheit, Plan, WAV, ein Auftrag gleichzeitig, Zeitlimit, falsche Eingaben, nur lokal, Image und Verdrahtung), `voice-read-selftest.php` (nachgebauter Dienst: Plan und Audio nur einmal berechnet, beschaeftigt und kaputte Antworten, Aufraeumen nach 7 Tagen, fremde Ordner, Dienst aus; Verdrahtung; mit Datenbank der Schalter mit Rollback) und `voice-read-ui-selftest.cjs` (Chromium: CSRF, Teil fuer Teil mit Vorladen, Stopp, Neuzeichnen, automatisch vorlesen, nichts zu lesen, Laden, ohne Dienst, Gaeste, Schalter). In beiden Preflights; der Container-Preflight meldet den Zustand des Sprachdienstes als Hinweis, nie als Fehler.
- Keine Aenderung an Cores, Modelfile oder Warteschlange.

## 0.6.0 - 04.11.2026

- **Maras Stimme gewaehlt: Eve** (Pocket TTS Deutsch, 24 Schichten, int8). Lauf `20261104-173047`: Echtzeitfaktor mit int8 0,45 / 0,48 / 0,46 bei 4 / 6 / 8 Kernen, ohne int8 1,07 / 1,26 / 1,31; erster Ton mit int8 0,13 s statt 0,46 s; 2,5 GB RAM, Laden 8 s. Mehr Kerne helfen nicht.
- **Registriert:** `config/voice/mara-voice.json` mit Engine (`pocket-tts==4.0.1`), Modell (`german_24l`, int8, freie Gewichte), Stimme (`eve`), Textaufbereitung (`de-1`), den drei Dateien mit Repository, Revision, Pfad, SHA-256, Groesse und Lizenz, der Namensnennung (Kyutai, VCTK p361) und dem vorlaeufigen Kernlimit 4. Kein Token noetig. Nichts davon wird ausgeliefert; die spaetere Sprachausgabe laedt genau diese Dateien und prueft sie.
- Messprotokoll und Festlegung im Sprachplan. Hoereindruck des Betreibers: kein Unterschied zwischen int8 und normal; der Stimmtest (P20.0) ist abgeschlossen.
- Tests: `tts-spike-selftest.py` prueft die Registrierung (gleiche Engine-Version wie im Test, gleiche Textaufbereitung, SHA-256 und Revisionen, Namensnennung, Kernlimit unter Echtzeit), in beiden Preflights gelistet.
- Keine Aenderung an Cores, Modelfile, Datenbank oder Warteschlange.

## 0.5.99 - 04.11.2026

- **Token nie in der Befehlszeile:** Auf dem Server wurde der Token direkt an die Option gehaengt (`--hf-hf_...`). Das Skript kannte die Option nicht und gab sie samt Token in der Fehlermeldung wieder aus. Jetzt wird jedes Argument mit `hf_` abgelehnt, ohne den Token auszugeben, mit dem richtigen Befehl und dem Hinweis, den Token neu anzulegen (er steht dann in der Shell-Historie).
- Tests: `tts-spike-selftest.py` prueft vier Schreibweisen (an der Option, nach `--hf-token`, allein, mit `=`): immer abgelehnt, Token nie in der Ausgabe. Dazu die Eve-Gruppen (Eve allein, int8 nur dort, wo die Gruppe es verlangt).
- **Maras Stimme gewaehlt: Eve (Pocket TTS Deutsch, 24 Schichten).** Die Aussprache ist mit der Textaufbereitung gut. Eve ist mit 4 Kernen aber knapp zu langsam (Echtzeitfaktor 1,23). Zwei neue Gruppen messen Eve allein mit 4, 6 und 8 Kernen, einmal normal und einmal mit Pockets int8-Beschleunigung (`quantize: true` in `voices.json`, nur der Transformer). Eve braucht keinen Token (vordefinierter Stimmzustand, CC BY 4.0).
- Keine Aenderung an Cores, Modelfile, Datenbank oder Warteschlange.

## 0.5.98 - 04.11.2026

- **Stimmtest gemessen:** Lauf `20261104-152135` auf dem Server, alle 18 Stimmen haben gesprochen. Messprotokoll im Sprachplan (`docs/COREUI_VOICE_PLAN.md`, Abschnitt "Messprotokoll Stimmtest"). Echtzeitfaktor mit 4 Kernen: Piper 0,01 bis 0,02 (Thorsten high 0,12), Pocket TTS 6 Schichten 0,32 (mit 2 Kernen 0,38), Pocket TTS 24 Schichten 1,13, Chatterbox 5,83. Erster Ton: Piper unter 0,1 s, Pocket 6 Schichten 0,14 s, Pocket 24 Schichten 0,5 s, Chatterbox 36 s.
- **Hoereindruck des Betreibers:** Fehlerfrei las nur die Chatterbox-Standardstimme, die aber maennlich ist (im Bericht stand irrtuemlich "weiblich", korrigiert) und stark englisch klingt. Die schnellen Stimmen machten Fehler bei Zahlen, Datum, Uhrzeit, Dateinamen und auch bei normalen Woertern.
- **Deutsche Textaufbereitung vor jeder Stimme** (`tools/tts_spike/textprep_de.py`, Stufe `de-1`, nur Standardbibliothek): Zahlen ("32.768" wird "zweiunddreissigtausendsiebenhundertachtundsechzig"), Jahre, Datum mit richtiger Endung ("am vierten", "der vierte"), Uhrzeit ("vierzehn Uhr dreissig", "ein Uhr"), Dezimalzahlen, Prozent, Euro, Dateinamen ("index Punkt Ha Te Em El") und gaengige Abkuerzungen. In den Testsaetzen erreicht keine Ziffer mehr eine Stimme. Bericht und Stimmproben-Karte zeigen "Gesprochen als".
- **Kerstin als Pocket-Klon:** zwei neue Gruppen (6 und 24 Schichten) klonen die deutsche Datensatz-Stimme Kerstin (CC0). Die Klon-Gewichte von Kyutai sind auf Hugging Face gesperrt: `tts-spike.sh --hf-token` nimmt den Lese-Token verdeckt entgegen und legt ihn nur fuer root lesbar ab; er geht nur beim Laden an den Container (per Name, nie in der Befehlszeile), nie beim Messen. Die Klon-Gewichte liegen in einem eigenen Cache, die uebrigen Gruppen laden weiter nur ihre registrierten Dateien. Ohne Token ueberspringt der Test die Klone und sagt, wie man sie freischaltet.
- **Kein Token fuer Nutzer:** Der Klon exportiert einmal Kerstins Stimmdatei (`export_model_state`). Zwei neue Gruppen sprechen damit dieselben Saetze mit den frei ladbaren Pocket-Gewichten, ohne Token und ohne Konto. So wuerde spaeter jede Installation Maras Stimme bekommen. Die Stimmdatei-Gruppen warten auf ihren Klon und sehen den Token nie. Bedingungen von Kyutai gelesen (CC BY 4.0; verboten u. a. Klonen ohne Einwilligung und Taeuschung), im Sprachplan festgehalten.
- Tests: `tts-spike-selftest.py` prueft die Textaufbereitung (Saetze und viele Einzelfaelle), dass die Stimme den aufbereiteten Text bekommt, die Klon- und Stimmdatei-Gruppen, Token nur beim Laden der Klone und nie in der Ausgabe, falsche Tokens abgelehnt; Stimmproben-Tests zeigen den gesprochenen Text.
- Keine Aenderung an Cores, Modelfile, Datenbank oder Warteschlange.

## 0.5.97 - 04.11.2026

- **Pocket nachholen ohne Ordnernamen:** Die Anleitung aus 0.5.95 nannte `--run ZEITSTEMPEL` als Platzhalter; auf dem Server wurde das verstaendlicherweise woertlich eingegeben. Jetzt reicht `--run letzter` (auch `latest`): Das Skript nimmt den neuesten Testlauf und meldet, in welchen es schreibt.
- Ein unbekannter Lauf nennt die vorhandenen (neueste zuerst) und schlaegt `--run letzter` vor; ohne jeden Lauf sagt es, dass zuerst ein voller Test noetig ist. Die Pruefung gilt jetzt auch im Probelauf.
- Tests: `tts-spike-selftest.py` prueft `letzter` (neuester Lauf, fremde Ordner zaehlen nicht), die Meldung bei unbekanntem Lauf und den Fall ohne Lauf; `ZEITSTEMPEL` wird abgelehnt.
- Keine Aenderung an der Messung, an Cores, Modelfile, Datenbank oder Warteschlange.

## 0.5.96 - 04.11.2026

- **Update-Preflight repariert:** Das Update auf 0.5.95 stoppte mit "Stimmtest-Selbsttest fehlgeschlagen". Der Selbsttest liess `tts-spike.sh --dry-run` auf den echten Ordner `var/tts-spike` schauen. Nach dem ersten Stimmtest auf dem Server liegen dort die registrierten Gewichte (`lock-*.json`), das Skript ueberspringt dann zu Recht das Laden, und der Test erwartete trotzdem fest sieben Ladeschritte. Lokal gab es keine Gewichte, deshalb fiel es vor dem Merge nicht auf.
- Der Probelauf bekommt jetzt einen eigenen leeren Ordner (`COREAI_TTS_SPIKE_BASE`, wirkt nur zusammen mit `--dry-run`). Der Test prueft zusaetzlich, dass registrierte Gewichte nicht neu geladen werden und `--refetch` sie doch laedt. Nachgestellt mit Lock-Dateien wie auf dem Server.
- Beide Preflights schreiben den Grund eines Fehlschlags dieses Tests ins Update-Protokoll statt nur "fehlgeschlagen".
- Keine Aenderung am Stimmtest selbst, an Cores, Modelfile, Datenbank oder Warteschlange.

## 0.5.95 - 04.11.2026

- **Pocket TTS spricht wieder:** Beim ersten Lauf auf dem Server brach jede Pocket-Probe mit "Inplace update to inference tensor outside InferenceMode" ab. Ursache war der Test selbst: Er rief Pocket in `torch.inference_mode()` auf, Pocket rechnet die Sprache aber in einem eigenen Thread, den dieser Modus nicht erreicht, und aendert dort den vorbereiteten Zustand. Pocket laeuft jetzt ohne diesen Rahmen; es schaltet die Gradienten selbst ab (`torch.no_grad`). Piper und Chatterbox waren nicht betroffen.
- **Gruppen nachholen:** `--run ZEITSTEMPEL` schreibt in einen vorhandenen Testlauf; der Bericht entsteht danach aus allen Ergebnissen des Laufs neu. So laesst sich Pocket nachmessen, ohne Piper und Chatterbox zu wiederholen. Die Container bleiben dieselben (`--skip-build` reicht).
- Die Meldungen von Chatterbox beim Laden (Cache nicht beschreibbar, veraltete Funktionen, NNPACK) sind harmlos: Die Gewichte sind beim Messen absichtlich nur lesbar.
- Tests: `tts-spike-selftest.py` prueft, dass der Pocket-Weg ohne `inference_mode` laeuft, und `--run` (richtiger Ordner, nur die gewaehlten Gruppen, Bericht neu, falsche Werte abgelehnt).

## 0.5.94 - 04.11.2026

- **Stimmtest fuer Maras eigene Stimme (P20.0 Rest):** `sudo bash scripts/tts-spike.sh` vergleicht auf dem Server Piper 1.8.0 (Kerstin, Ramona, Eva K; Thorsten maennlich als Vergleich), Pocket TTS 4.0.1 Deutsch mit 6 und 24 Schichten (Alba, Anna, Vera, Eve, Caro Davy, Estelle; ohne Stimmklon) und Chatterbox Multilingual 0.1.7 (Klon der freien Datensatz-Stimme Kerstin, CC0, dazu die eingebaute Stimme nur als Vergleich).
- **Messung:** sieben deutsche Saetze (Begruessung, Zahlen, Datum und Uhrzeit, Frage, Dateinamen, Umlaute und ss, laengerer Absatz). Je Probe Rechenzeit, Audiodauer, Echtzeitfaktor und Zeit bis zum ersten Ton; je Engine Ladezeit, Aufwaermen und Spitzen-RAM. Alle Stimmen sprechen mit 4 Kernen, mit 2 Kernen misst je Gruppe die erste Stimme.
- **Abgeschottet und nachvollziehbar:** jede Engine in ihrem eigenen Container (`docker/tts-spike/`, Python 3.11, genaue Versionen in `constraints-*.txt`, torch nur fuer die CPU). Die Gewichte werden einmal mit Netz geladen und mit Herkunft, Revision und SHA-256 in `var/tts-spike/models/lock-GRUPPE.json` registriert; gemessen wird mit `--network none`, festem Kernlimit, Gewichten nur lesbar und erst nach der SHA-256-Pruefung jeder Datei. Nichts davon wird mit CoreAI ausgeliefert.
- **Lizenz je Stimme:** `tools/tts_spike/voices.json` fuehrt Herkunft, Stimm-, Gewichts- und Engine-Lizenz. Frei: Kerstin, Thorsten (CC0), die Pocket-Stimmen (CC BY 4.0 oder CC0), der Kerstin-Klon. Pruefen: Ramona und Eva K (M-AILABS) sowie die undokumentierte Chatterbox-Standardstimme. Stimmen mit nicht-kommerzieller Lizenz (Expresso, EARS) sind ausgeschlossen. Piper-Code steht unter GPL-3.0.
- **Admin Core > Betrieb > Stimmproben (nur Inhaber):** jede Stimme mit FREI/PRUEFEN, Echtzeitfaktor, erstem Ton, RAM, Ladezeit, Lizenzen und einem Abspielknopf je Satz; aeltere Testlaeufe waehlbar, Tabelle der Engines und Kerne. Neue Aktionen `tts_runs` und `tts_sample` in `api/admin.php` (nur GET, nur Inhaber); eine Probe wird nur geliefert, wenn ihr Lauf sie auflistet, sie ein echtes WAV ist und im Laufordner liegt (`api/tts_spike_store.php`). `var/` bleibt in nginx gesperrt.
- Tests: neu `tts-spike-selftest.py` (Pruef-Engine statt Sprache: Registrierung mit SHA-256, geaenderte Gewichte abgelehnt, Proben und Bericht, Lizenzregeln, feste Versionen, Probelauf des Skripts ohne Netz mit Kernlimit), `tts-spike-store-selftest.php` (Laeufe, oeffentliche Form, nur gelistete Proben, kein Weg aus dem Laufordner, auch nicht ueber Links) und `voice-samples-ui-selftest.cjs` (Chromium: Stimmen, Lizenz, deutsche Zahlen, Abspielen und Stoppen je Satz, Laufwahl, leerer Server, Admin sieht nichts, Handy ohne Querscrollen), in beiden Preflights.
- Keine Aenderung an Cores, Modelfile, Datenbank oder Warteschlange.

## 0.5.93 - 04.11.2026

- **Zwei veraltete Oberflaechentests nachgezogen:** Auf main 0.5.92 bestanden 76 Selbsttests, zwei schlugen fehl, beide wegen gewollter Aenderungen, nicht wegen Fehlern im Produkt.
- `settings-tabs-ui-selftest.cjs` erwartete nach KI-Profil mit der Pfeiltaste noch "Wissen"; seit 0.5.91 sitzt dort der Tab Personalisierung. Der Test geht jetzt beide Schritte und prueft auch im Tab Personalisierung, dass keine Schrift unter 11 px liegt.
- `browse-live-ui-selftest.cjs` erwartete, dass Bilder des Playwright-Browserfensters als "Aktuelle Seite" ins Quellenkarussell wandern. Das hat 0.5.88 auf Wunsch des Betreibers getrennt (das Karussell gehoert nur zur Websuche). Der Test prueft jetzt genau das: Ein Browserlauf fuellt das Karussell nie (Gegenprobe mit dem alten Verhalten schlaegt an).
- Die aktuelle Uebergabe nannte 0.5.92 noch "nicht gemergt" und den Stand vor 0.5.87 als naechsten Schritt; beides korrigiert.
- Keine Aenderung an Produktcode, Datenbank, Modelfile oder Konfiguration.

## 0.5.92 - 03.11.2026

- **Personalisierung repariert:** Das neue `api/personalization.php` aus 0.5.91 wurde von der Nginx-PHP-Allowlist nicht freigegeben, weshalb die funktionierende Einstellungsseite `HTTP_404` meldete.
- **Gezielte Freigabe:** `config/nginx.conf.template` (native) und `config/runtime/nginx.conf.template` (Container) lassen nur den neuen, authentifizierten API-Endpunkt zusaetzlich passieren. Alle anderen nicht freigegebenen PHP-Skripte bleiben gesperrt.
- **Regressionstest:** Der bestehende Personalisierungs-Selftest prueft jetzt, dass beide Nginx-Varianten die Route besitzen und der generische PHP-Deny weiterhin existiert; der Test laeuft bereits in beiden Preflights.
- **Installationsweg:** Der bestehende CoreAI-Updater generiert die Runtime-Nginx-Datei aus dem aktualisierten Template neu. Keine Datenbank-, Modell-, Chat- oder Sicherheitspolicy-Aenderungen.
- **Live-Abnahme offen:** Nach Merge und Update im angemeldeten Browser Personalisierung laden, speichern und erneut laden.

## 0.5.91 - 02.11.2026

- **Mein CoreAI / Personalisierung:** Kontogebundene Einstellungen fuer Maras Tonfall, Herzlichkeit, Begeisterung, Emojis, Ueberschriften, Visualisierung, benutzerdefinierte Stilwuensche, Schreibstil-Anpassung und knappe Direktantworten.
- **Echte Wirkung:** Antwort-Prompt in beiden Pfaden aktualisiert, ohne das operatorseitige Modelfile oder die Werkzeugprotokolle zu aendern. WEB, Playwright und private RAG-Lite-Suche koennen pro Konto zusaetzlich eingeschraenkt werden; Betreiber- und Nutzerpolicies bleiben Obergrenzen.
- **Bestehende Funktionen:** Memory-Schalter im neuen Bereich mit KI-Profil synchronisiert, Folgefrage-Buttons ein-/ausblendbar und Links zu Profilfoto, Wissen und Browseranzeige.
- **Server und Datenschutz:** Migration 022, authentifizierter CSRF-API-Endpunkt, pro-Konto-Persistenz mit Feldvalidierung, Account-Export aktualisiert, Loeschkaskade bei Kontoloeschung.
- **Rendering:** Dezente blau-goldene Markdown-Akzente in normalen Mara-Antworten, ohne ungeprueftes HTML.
- **Ehrliche Grenzen:** Referenzbilder fuer ImageGen, Live-Sprachchat und Connector-Suche nicht als wirkungslose Schalter angeboten.
- **QA:** Personalisierungs-Selftest in Container- und Native-Preflight aufgenommen. Echte Server-, Installer- und Mobile-Abnahme nach Merge erforderlich.

## 0.5.90 - 02.11.2026

- **Preflight-Reparatur:** Der Sitzungs-Selftest erwartete noch die alte History-Fallback-Zeile, die im 0.5.89-Hotfix bewusst ersetzt wurde. Diese veraltete Assertion blockierte sowohl CoreAI-Updater als auch den SSH-/Native-Preflight.
- **Schutz weiterhin aktiv:** Die neuen Assertions verlangen das atomare Erzeugen und Anzeigen der Chat-Historie, die sichere Rueckgabe der DOM-Referenz, getrennte Renderfehler und das Unterdruecken automatischer Retries bei einem solchen Anzeigefehler. Die anderen Sitzungs-, Rechte- und DB-Tests bleiben unveraendert.
- **Kein Produkt- oder Datenbankeingriff:** PHP-/Client-Verhalten unveraendert; lediglich Test-/Versions-/Changelog-Metadaten nachgezogen. Keine neuen GitHub Actions noetig.
- **Live-Pruefung offen:** Beide Preflights nach Installation erneut ausfuehren; die Fehlermeldung zum veralteten Test sollte verschwinden. Die Web-UI danach nochmals mit laufender Recherche testen.

## 0.5.89 - 01.11.2026

- **Notfallkorrektur nach Live-Test (02.11.2026):** Ein nicht initialisierter `list._history`-Verweis im WEB-Karussell loeste `Cannot set properties of undefined (setting 'hidden')` aus. Die Browseroberflaeche verwechselte diesen Darstellungsfehler mit einem API-Ausfall, loeschte beim erneuten Rendern die sichtbare Chat-Ansicht und wiederholte den Vorgang automatisch. Der Verweis ist jetzt korrekt initialisiert und die Verstecken-/Anzeigen-Regeln fuer die Quelle greifen.
- **Chat-Stabilitaet:** Verlauf wird in einem inaktiven DOM-Container vorbereitet und erst bei erfolgreichem Rendering ersetzt. Bei Renderfehlern bleiben sichtbare Nachrichten stehen; der Fehler wird als ANZEIGEFEHLER statt Serverfehler benannt, und die automatische History-Ladeschleife stoppt. Der Verlauf in MariaDB bleibt unveraendert.
- **Tests:** 156 UI-Assertions mit DOM-Double bestanden, Syntax von `coreui-work-events.js`, `console-app.js` und `coreui-net.js` geprueft. Echtes Chromium und Netcup-Live-Verhalten sind weiterhin vor Ort abzunehmen.
- WEB-Quellen gelangen sofort bei einem SSE-Treffer ins Favicon- und URL-Karussell; die langsame Erzaehlanimation blockiert sie nicht mehr.
- Das erste echte Websuch-Ereignis zeigt den laufenden Suchstatus ohne "Web wird durchsucht". Trefferlose Recherchen bleiben als solche kenntlich; keine Quellen werden vorgetaeuscht.
- Wiederholte Suchrunden und Schritt-Nummern erscheinen ausschliesslich in den eingeklappten technischen Details. Eine erste, natuerlich formulierte Recherchemeldung bleibt wie Maras normaler Nachrichtentext sichtbar.
- Mehrfache Fortschritts-Zeilen und die redundante Schritte-/Werkzeuge-Gesamtzahl wurden im Nachrichtenfluss reduziert. Browserfenster-Schalter und Codefenster aus 0.5.88 bleiben erhalten.
- Regressionstest mit aktiver Textanimationswarteschlange, sofortigem Live-Treffer, URL-Wechsel, mehrfacher Suche und erfolgloser Websuche.

## 0.5.88 - 01.11.2026

- Normale [WEB]-Suche zeigt gefundene Webseiten als wechselndes Favicon-/URL-Karussell direkt in Maras Fortschritt, statt einer ausgeschriebenen Liste oder der Beschriftung "Web wird durchsucht". Die Detailquellen bleiben ausklappbar. Suchtreffer werden nicht als besucht ausgegeben.
- [BROWSE] nutzt weiterhin das eigenstaendige Playwright-Livefenster. Neue Anzeigeoption fuer das komplette Browserfenster: ausgeschaltet erscheint stattdessen "Web wird durchsucht". Die bisherige Option fuer die Frame-Bewegung bleibt separat.
- Live-Quelltextvorschau und Codefenster in Maras fertiger Nachricht sind in aufklappbare Schritte verschoben. Code wird nicht ueber den Chat verbreitert; Kopieren bleibt vorhanden.
- Keine Aenderung der Modellqueue, des Browser-Workers, der Toolformate, des Game-Repos oder des Modelfiles.
- Nur Quellbranch; kein Deploy und keine Live-Abnahme. Version- und Browserpreflights vor Merge ausfuehren.


CoreAI: TCI Interface gehoert zur Quantum-Softwarefamilie und wird bis zur finalen Aufnahme in den STU-Repack eigenstaendig versioniert. Historische Changelogs dokumentieren die frueheren Produktnamen unveraendert.

## 0.5.87 - 01.11.2026

- Fortschritt erscheint dauerhaft als Teil von Maras Nachricht, ohne Arbeitsweg-Ueberschrift oder aeusseren Klappschalter. Nur Schrittgruppen und technische Infos bleiben klappbar; Fehler tragen ein rotes Warndreieck neben dem Info-Symbol statt einer roten Linie.
- Wichtige Starts ausserhalb des Projektbaus (Python, Websuche, Browser, Anhaenge, Wissenssuche) erscheinen als oeffentliche Fortschrittsnotizen aus echten Ereignissen.
- Websuchtreffer werden mit Titel, Domain und Favicon (Buchstaben-Fallback) angezeigt und als Suchtreffer gekennzeichnet. Der Live-Browser zeigt die tatsaechlichen Frame-URLs als aktuelle bzw. besuchte Seiten, ohne Duplikate pro URL.
- Recherche-Metadaten erlauben nur oeffentliche HTTP(S)-Adressen ohne Zugangsdaten, Query oder Fragment; Titel werden als Text gerendert. Favicon-Abrufe senden keinen Referrer. Die vorhandene Browseransicht bleibt erhalten.
- Python-Worker uebernimmt ausschliesslich Uploads der gebundenen Nachricht nach Pruefung von Nutzer, Session und Revision. Legacy-file_uuid bleibt lesbar; keine beliebigen Modellpfade oder vorherigen Chats.
- Originalbytes werden mit Groessenpruefung und SHA-256 in einen separaten, schreibgeschuetzten Job-Input kopiert. Das Modell erhaelt den Manifest-Vertrag; der private Medienstore bleibt ausserhalb des Containers.
- ZIP-Pruefung vor Ausfuehrung: Traversal, Symlinks/Spezialdateien, doppelte Pfade, Dateipfad-Kollisionen, verschluesselte/defekte Archive und Entpacklimits werden abgewiesen. Kein Host-Extract.
- Fehlende Eingaben starten keinen Container. Python-Exitcodes ungleich null erzeugen den Jobstatus error statt done; vorhandene Teilausgaben bleiben als partiell markiert.
- Neues Offline-Selftest-Skript in beiden Preflights. Bestehende Docker-Testdoubles an den vierten, schreibgeschuetzten Mount angepasst.
- Abgrenzung: Projekt-Writer-Vollstaendigkeit, Datei-Wiederaufnahme, Ollama-Diagnose und Game-/Skill-Bereinigung bleiben eigene Folgeschritte. Keine Aenderung an Modelfile, Modellqueue oder Skill-Loader.

## 0.5.86 - 30.10.2026

- **Maras Erinnerungen ohne Kuerzung:** Laengere Eintraege im Memory waren abgeschnitten. Ursache: Beim automatischen Merken schnitt der Server jeden Fakt hart nach 240 Zeichen ab (mitten im Satz), und beim Einfuegen in Maras Prompt nochmals. Jetzt wird jede Erinnerung vollstaendig gespeichert und vollstaendig verwendet.
- **Ganze Erinnerungen im Prompt:** Statt einzelne Fakten zu kuerzen, bekommt das Gedaechtnis ein Budget je Kontextfenster (12000 Zeichen bei grossem oder unbekanntem Fenster, 3000 bis 8K, 1500 bis 4K). Die passendsten Erinnerungen kommen ganz hinein; passt eine nicht mehr, bleibt sie fuer diese Antwort draussen und die naechste wird versucht. Nie wird ein Fakt halbiert.
- **Auch von Hand laenger:** Der Memory-Tab erlaubte 1000 Zeichen, Admin Core 2000; beide jetzt 8000. Diese Grenze von 8000 Zeichen gilt ueberall nur als Schutz gegen ausufernde Ausgaben: Laengeres wird gar nicht gespeichert statt abgeschnitten. Die alte Einstellung `STU_EMBER_REFLECT_MAX_FACT_LEN` entfaellt.
- Hinweis: Schon gekuerzte Eintraege lassen sich nicht wiederherstellen, der abgeschnittene Teil wurde nie gespeichert. Sie lassen sich unter Admin Core > Memory (global) bearbeiten oder loeschen; Mara merkt sie sich beim naechsten Mal vollstaendig.
- Tests: neu `memory-length-selftest.php` (ganze Fakten, Budget je Fenster, nichts kuerzt mehr), in beiden Preflights.

## 0.5.85 - 29.10.2026

- **Konten bearbeiten (BEARBEITEN):** Unter Admin Core > Benutzer klappt BEARBEITEN pro Konto ein Panel mit Abschnitten auf. Es gelten dieselben Regeln wie beim Loeschen: Der Inhaber bearbeitet Admins, Nutzer und Gaeste, ein Admin nur Nutzer und Gaeste; ein anderes Inhaber-Konto und Maras Systemkonto bleiben unberuehrt.
- **Name und E-Mail:** Anzeigename und Login-E-Mail aendern (bereinigt, gueltig, nur einmal vergeben: `username_taken`). Gaeste behalten ihre automatische Kennung, nur der Name ist aenderbar. Auch am eigenen Konto moeglich.
- **Passwort und Anmeldungen:** Neues Passwort setzen (mindestens 12 Zeichen); dabei werden alle Anmeldungen des Kontos beendet, damit alte Geraete nicht angemeldet bleiben. "Alle Geraete abmelden" beendet die Anmeldungen ohne neues Passwort; das Panel zeigt, wie viele aktiv sind. Gaeste haben kein Passwort. Am eigenen Konto gibt es das hier nicht, dafuer bleibt der Tab Konto (mit altem Passwort).
- **Werkzeuge pro Konto:** Im selben Panel lassen sich Websuche, Browser und Python fuer ein Konto sperren (Haken weg). Der globale Schalter unter Betrieb bleibt die Obergrenze: Was dort aus ist, steht im Panel als "global aus" und bleibt fuer alle aus. Die Sperre greift an der zentralen Stelle, ueber die jedes Werkzeug geht (Prompt, Arbeitsschleife, Datei-Auftraege), ab der naechsten Nachricht. Neue Tabelle `stu_user_policies` (Migration 021), die auch die Limits des naechsten Schritts aufnimmt; ohne eigene Zeile gelten die Standards. Fuer das eigene Konto gibt es keine Sperren (`own_policy`).
- **Limits pro Konto:** Nachrichten pro Tag (leer = ohne Grenze; gezaehlt 24 Stunden ab der ersten Nachricht, beim Senden, neu Generieren und Bearbeiten; das Panel zeigt "Heute gesendet: n von m") und das groesste Kontextfenster. Ist das Tageslimit erreicht, sagt der Chat es und nennt, ab wann es wieder geht (`daily_message_limit`, 429); eine abgelehnte Nachricht zaehlt nicht. Das Fenster-Limit kuerzt nur eine eigene Wahl, die darueber liegt; ohne eigene Wahl entscheidet weiter das Modelfile, nichts wird angehoben. Der Schieberegler im KI-Profil zeigt nur die erlaubten Stufen, Speichern darueber wird abgelehnt (`num_ctx_not_allowed`).
- **Haekchen in Tabellen-Panels:** Eine allgemeine Regel fuer Tabellenfelder (volle Breite, 42 px hoch) machte Haekchen gross, besonders ausgegraute; Haekchen haben dort jetzt eine feste Groesse (auch bei den Core-Freigaben).
- Jede Aenderung steht im Admin-Audit (`user_identity`, `user_password_set`, `user_logout_all`, `user_policy`), das Passwort nie.
- **Datenbank-Tests ohne Rueckstaende:** Ein `CREATE TABLE IF NOT EXISTS` (App-Einstellungen) beendet in MariaDB eine offene Transaktion still; ein Test haette so Testkonten hinterlassen. Die Tabelle wird jetzt vor der Transaktion angelegt, und alle drei Datenbank-Tests pruefen vor dem Rollback, dass die Transaktion noch offen ist.
- Tests: neu `account-admin-selftest.php` (Regeln, Verdrahtung; mit Datenbank Passwort, Abmelden, Name und E-Mail mit Rollback) und `user-policy-selftest.php` (Werkzeuge und Limits pro Konto, global aus bleibt aus; mit Datenbank Speichern, Pruefung der Eingaben, die Sperre beim Start einer Antwort, Tageslimit mit Rueckgabe und gekuerztes Fenster), in beiden Preflights; der Einstellungs-Browser-Test bearbeitet ein Konto (Name und E-Mail, Passwort erst ab 12 Zeichen, alle Geraete abmelden) und prueft eigenes Konto, Gast und Admin-Sicht. Lokal gegen MariaDB 10.11 geprueft.

## 0.5.84 - 29.10.2026

- **Benutzer loeschen:** Unter Admin Core > Benutzer hat jede Zeile, die man loeschen darf, den Knopf LOESCHEN. Er klappt eine Zeile auf, die sagt, was alles geht, und verlangt die E-Mail des Kontos als Bestaetigung (Gross- und Kleinschreibung egal); erst dann wird ENDGUELTIG LOESCHEN aktiv. Danach meldet die Seite, wie viele Chats, Nachrichten und Dateien entfernt wurden.
- **Wer wen loeschen darf (Server prueft):** Der Inhaber loescht Admins, Nutzer und Gaeste; ein Admin nur Nutzer und Gaeste. Niemand loescht sich selbst, ein Inhaber-Konto oder Maras Systemkonto. Laeuft fuer das Konto gerade etwas (Antwort, Browser, Python, Warteschlange), wird nichts geloescht und die Seite bittet, kurz zu warten.
- **Was geloescht wird:** alle Chats mit Nachrichten, Anhaengen, Dateiausgaben, Browser- und Python-Jobs, Arbeitsschritten und Plaenen (ueber die geprueften Chat-Loeschung), hochgeladene Dateien, die nie in einem Chat landeten, Wissen mit Abschnitten und Dateien, Maras Erinnerungen an das Konto und seine Figur, Profil und Avatare, KI-Einstellungen, Core-Freigaben, Skills, Anmeldungen und Komprimier-Archive. Globale Fakten, die das Konto einmal angelegt hat, bleiben fuer alle erhalten, nur ohne Verweis aufs Konto. Im Admin-Audit bleibt ein Eintrag mit E-Mail, Rolle und Zahlen.
- **Alles oder nichts:** Der Datenbankteil laeuft in einer Transaktion; Dateien werden erst nach dem Abschluss entfernt und nur in ihren bekannten Ordnern (keine Pfade, keine Symlinks nach aussen).
- Tests: neu `account-delete-selftest.php` (Rechte, Bestaetigung, Dateien, Verdrahtung; mit Datenbank eine vollstaendige Loeschung eines Testkontos mit Chats, Dateien, Wissen und Erinnerungen, danach Rollback; fremde Konten bleiben unberuehrt), in beiden Preflights. Der Einstellungs-Browser-Test loescht als Inhaber ein Konto mit Bestaetigung und prueft, dass ein Admin das Inhaber-Konto nicht loeschen kann. Lokal gegen MariaDB 10.11 mit allen Migrationen geprueft, auch mit echtem Commit und echten Dateien.

## 0.5.83 - 29.10.2026

- **Eingabefeld springt nach dem Senden zurueck:** Waechst das Feld durch mehrere Zeilen, blieb es nach dem Absenden leer, aber gross. Ursache: Der Text wurde an zehn Stellen per Code geleert oder gesetzt (Senden, Vormerken, Bearbeiten und Abbrechen, `/komprimieren`, langer Text als Anhang); das loest kein Eingabe-Ereignis aus, die Hoehe wurde nie neu berechnet. Jetzt laeuft jede Aenderung ueber eine Funktion, die die Hoehe anpasst. Im echten Browser geprueft: vorher 128 px nach dem Senden, jetzt 32 px.
- **Legende nur mit Maras Cores:** Unter "Core-Stufen" stehen nur noch die TCI-Stufen (Schnell bis Premium) mit ihren Gemma-Cores. Modelle mit eigenem Namen, die nicht zu CoreAI gehoeren (etwa gpt-oss), erscheinen dort nicht mehr; im Core-Menue stehen sie wie bisher unter Maras festen Stufen, solange sie in Ollama installiert sind.
- **Ollama-Liste laedt mit dem Chat:** Das Core-Menue holt die aktuelle Ollama-Liste schon beim Oeffnen des Chats (und beim Aufklappen erneut), Punkte und Eintraege stimmen damit sofort.
- **Modell-Freigaben pro Benutzer:** Maras Cores (die Gemma-4-Stufen, lokal oder Cloud, und Cores aus ihrem Modelfile) stehen allen Konten offen. Andere Modelle in Ollama (eigene Namen, etwa ein privates Cloud-Modell wie gpt-oss) nutzt nur der Inhaber; unter Admin Core > Benutzer gibt er sie in der neuen Spalte CORES pro Konto frei (auch fuer Admins). Der Server prueft das bei der Modellliste, bei der Wahl im Core-Menue und im KI-Profil (`model_not_allowed`) und zu Beginn jeder Antwort: Wird eine Freigabe entzogen, antwortet Mara ab der naechsten Nachricht mit dem Standard-Core. Neue Tabelle `stu_user_model_access` (Migration 020), jede Aenderung im Admin-Audit.
- **Update-Fenster (CoreAI aktualisiert sich selbst):** Landet ein neuer Merge auf `main`, bemerkt das der Server innerhalb von 10 Minuten (systemd-Timer, nur `git ls-remote`, kein Download). Der Inhaber bekommt bei jedem Laden ein leicht transparentes, mattgoldenes Fenster ueber ein Drittel des Bildschirms mit Versionen und den Changelog-Abschnitten seit der installierten Version; "Spaeter" blendet es bis zum naechsten Laden aus, in den Einstellungen unter System steht die Karte "CoreAI-Update" (Stand, "Jetzt pruefen", Fenster oeffnen). "Update starten" (Haken "Ollama danach neu starten", vorbelegt) zeigt allen offenen Seiten "Bitte auf Neustart warten" mit den Schritten Sichern, Laden, Installieren, Neustart; die Seite laedt neu, sobald CoreAI auf dem neuen Stand zurueck ist, statt blind nach einer festen Zeit.
- **Echter Installationsfortschritt statt geschaetzter Zeit:** Der Update-Dienst liest die Ausgabe von Setup, Installer und Preflight mit und macht daraus Prozent und den aktuellen Schritt ("Die Images werden gebaut.", "Die Datenbank-Migrationen laufen.", "Der Preflight prueft alle Funktionen." und so weiter): Sicherung 0 bis 8, Laden bis 10, Installation 10 bis 90 (20 Wegmarken, Docker-Build-Schritte und Preflight-Zeilen fuellen ihre Abschnitte; die Preflight-Laenge merkt er sich fuers naechste Mal), Neustart bis 100. Das Fenster zeigt einen Balken (dunkles Marineblau ins CoreAI-Blau, Komplementaerfarbe zu Gold, mit Lichtschimmer solange der Server arbeitet), den Prozentwert und die Laufzeit. Der Balken geht nie zurueck; waehrend PHP und Web neu starten, bleibt der letzte Stand stehen mit dem Hinweis, dass CoreAI gerade neu startet. Die Ausgabe landet weiterhin vollstaendig im Update-Protokoll; ein Fehler beim Mitlesen bricht die Installation nie ab.
- **So laeuft das Update:** Die Weboberflaeche fuehrt nie einen Befehl aus, sie legt nur eine Anfragedatei in `var/update-request` ab (nur Inhaber, CSRF, Admin-Audit). Ein systemd-Dienst auf dem Host (`scripts/coreai-updater.sh`, von `install.sh` eingerichtet) sichert zuerst die Datenbank nach `var/backups` (die letzten 5), laedt das offizielle `setup.sh` genau dieses Commits von `main` und fuehrt es aus (prueft vorher, dass es in diese Installation installiert), startet Python- und Browse-Worker neu und auf Wunsch `systemctl restart ollama`. Status und Protokoll stehen in `var/update` (nur lesbar fuer die Weboberflaeche); schlaegt etwas fehl, sieht der Inhaber die letzten Protokollzeilen und kann es erneut versuchen. Ein abgebrochenes Update (Serverneustart) wird beim naechsten Pruefen als unterbrochen gemeldet.
- **Angemeldet bleiben nach dem Update:** PHP-Sitzungen liegen jetzt in `var/php_sessions` auf dem Host statt im Container; ein neu erstellter PHP-Container meldet niemanden mehr ab (Fallback auf den Temp-Ordner, falls der Ordner fehlt). Die Commit-Markierung `var/dev_source_commit` ist fuer den Webserver lesbar, damit das Fenster den installierten Stand vergleicht.
- **Einmalig:** Der Update-Dienst kommt mit diesem Stand. Das Update auf 0.5.83 selbst laeuft noch ueber den Setup-Einzeiler, ab dann ueber das Fenster.
- Tests: Frontend-Regressionstest sichert, dass das Eingabefeld nur noch ueber die Hoehen-Funktion geaendert wird; Core-Chip-Test prueft Legende ohne fremde Modelle und das Laden der Liste beim Chat-Start. Neu: `model-access-selftest.php` (beide Preflights); der Einstellungs-Browser-Test gibt als Inhaber ein Modell frei und prueft, dass ein Admin es nicht kann. Neu fuer das Update: `update-selftest.php` (Fenster nur bei neuem Commit, Anfragen, Zustaende, Verdrahtung), `tests/update-runner-selftest.sh` (Host-Updater mit nachgebautem git, curl und systemd: Sicherung zuerst, fremdes Ziel abgelehnt, Symlink-Anfragen verworfen, Fehlerfaelle, Sperre; jede Fortschritts-Wegmarke muss in Setup, Installer oder Preflight vorkommen, Docker-Build- und Preflight-Anteile, Ausgabe unveraendert durchgereicht, auch bei Binaerdaten) und `update-ui-selftest.cjs` (Chromium: Drittel des Bildschirms, Spaeter, Start mit Ollama-Wahl, echter Prozentwert und Schritt vom Server, kein Zurueckfallen, letzter Stand bleibt bei 502, Neuladen erst nach dem Neustart, Fehlerprotokoll, Nutzer, Handy). Der Preflight prueft zusaetzlich, dass der Update-Dienst aktiv ist und die neuen Ordner beschreibbar sind.

## 0.5.82 - 29.10.2026

- **Skill-Referenzen angebunden:** Die Referenzplaene der Skills (`references/*.md`, bei 48 Skills, zum Beispiel Quellen- und Gueltigkeitsregeln der Rechts-Skills oder Zielumfang und Abnahme der Office-Skills) waren bisher nur Repository-Dokumente. Mara laedt sie jetzt auf Anforderung mit `[SKILL: kennung/references/IMPLEMENTATION.md]`, als Schritt ihrer Arbeitsschleife wie einen Skill. Ein geladener Skill nennt seine Referenz am Ende ("Referenz bei Bedarf"), und eine Referenz bringt ihren Skill mit, falls er noch fehlt.
- **Geplante Werkzeuge bleiben geplant:** Eine Referenz steht im vertrauenswuerdigen Skill-Block mit dem Hinweis, dass dort genannte Werkzeuge nur gelten, wenn sie in Maras Werkzeugprotokoll stehen; sonst nennt sie sie als fehlend und behauptet kein Ergebnis. Die Plaene bleiben so die Uebersicht, was noch fehlt.
- **Grenzen:** nur Markdown-Dateien aus dem `references/`-Ordner eines registrierten Skills (Pfad gegen den echten Ordner geprueft, Gross- und Kleinschreibung egal), hoechstens 4000 Byte je Datei, nichts wird abgeschnitten. Je Antwort zwei Referenzen bei grossem Kontextfenster, eine bis 8192 Tokens, keine bei 4096. Skripte werden nie geladen oder ausgefuehrt.
- **Texte angepasst:** Skills und Referenzplaene, die "wird nicht geladen" sagten, nennen jetzt den Marker zum Nachladen; die Skill-Doku und die Werkzeugluecken-Uebersicht beschreiben den Ressourcenlader.
- **Test repariert:** `skills-selftest.php` erwartete nach dem Kuerzen der Titel noch "Python-Programme" und schlug in 0.5.81 fehl (auch im Server-Preflight); er liest den Titel jetzt aus dem Katalog. Neu im Test: alle Referenzen ladbar, Pfadschutz, Budget je Fenster, Referenz im Block, Arbeitsschleife.

## 0.5.81 - 29.10.2026

- **Allround-Skill-Bibliothek auf 106 erweitert:** 73 neue Fach-Skills im Vergleich zu den urspruenglichen 33. Enthalten sind alle 19 Anthropic-Referenznamen, zusaetzliche OpenAI-/Codex-inspirierte Prozesse und **20 eigene Rechts-Skills** fuer Bundes-/Landes-/EU-Recht, Bescheide, Vertrage, Rechtsbehelfe, Fristen, Sozialrecht, Miete, Verbraucher, Arbeitsrecht, Datenschutz, Urheber-/Markenrecht, Steuer, Verfahren und weitere Themen.
- **Unabhaengige AgenticSkills.io-Kurierung ausgewertet:** Sechs weitere eigene Skills zu sicherer Skill-Entdeckung, SEO-Audit, Marketingplanung, Mobile-App-Entwicklung, Property-Based-Testing und Datenbanktuning. Alle mit SKILL.md und vollem Werkzeug-/Abnahmeplan, Quellen- und Sicherheitsmatrix unter `docs/COREAI_AGENTICSKILLS_REFERENCE.md`. Keine externen Installer, MCP-Server oder fremden Skripte automatisch ausgefuehrt.
- **Gesetze nicht aus alten Modelldaten behaupten:** Jede der 20 rechtlichen SKILL.md-Anleitungen verlangt vor konkreten Rechtsaussagen einen Onlineabgleich anhand offizieller Quellen. Massgeblich sind sowohl der Rechtsstand am Ereignistag als auch die heutige Fassung; Aenderungsgesetze, Verkuendung, Inkrafttreten, Uebergangs-/Rueckwirkungsrecht und neue Urteile werden geprueft. Kein Webzugriff = keine Behauptung einer verifizierten aktuellen Rechtslage. Pruefregeln: `docs/COREAI_LEGAL_CURRENTNESS.md`.
- **Vollstaendige Entwicklungsplaene:** Alle 23 neuen Skills haben eigene `references/IMPLEMENTATION.md` mit Funktionsumfang, Toolbedarf und Abnahme. `docs/COREAI_SKILL_CAPABILITY_GAPS.md` und ROADMAP verbinden 20 Rechts-Workflows sowie Finanzanalyse, Wissenschaft und IT-Bedrohungsmodelle mit noch fehlenden Werkzeugen. Die urspruenglichen 19 Anthropic-Skills besitzen weiterhin ebenfalls ihre eigenen Sollplaene.
- **Kontext bleibt im Budget:** 106 registrierte Skills mit kompakten Beschreibungen; die volle Index-Bibliothek unter dem bestehenden Limit von 3600 Byte. Der einzige Pflicht-Skill `werkzeuge` wird nicht geaendert.
- **Keine Laufzeitaenderung:** Automatische Auswahl und `[SKILL:]`-Nachladen, Loader, Modelfile, Berechtigungen, Game-Trennung und serielle Modellwarteschlange bleiben unveraendert. Zusatzreferenzen dienen zunaechst als Entwicklungsplaene; der heutige Loader liest nur die einzelnen `SKILL.md`-Texte.
- **Pruefung:** Statische Katalog-/Referenz-/Groessen-/Integritaetschecks. PHP-Laufzeit-Selbsttest, Versionsgate, Server- und Android-Livetest bleiben bis zu realer Ausfuehrung offen; keine GitHub Actions gestartet.

## 0.5.80 - 28.10.2026

- **Skills als intelligentes System:** Mara hat jetzt immer alle 33 Skills und waehlt selbst, wie man es von Claude oder GPT kennt. Offensichtlich passende Skills laedt der Server weiterhin vorab (spart dem lokalen Core einen Rechenschritt), alle uebrigen stehen als kurze Skill-Bibliothek in ihrem Prompt. Mit `[SKILL: kennung]` (hoechstens zwei auf einmal) laedt sie einen nach, als Schritt ihrer Arbeitsschleife; im Arbeitsweg steht dann "Schritt n: Skill geladen".
- **Anleitung bleibt Anleitung:** Ein nachgeladener Skill kommt in den vertrauenswuerdigen Skill-Block oben im Prompt, nicht zu den Werkzeugergebnissen, deren Anweisungen Mara nicht befolgen darf. Der Schritt meldet nur "Geladen", die Bibliothek fuehrt den Skill danach als schon geladen.
- **Passend zum Kontextfenster:** Die Bibliothek zeigt bei grossem Fenster die Beschreibungen, bis 8192 Tokens die Titel und bis 4096 nur die Kennungen. Datei-Auftraege laufen ohne Arbeitsschleife; dort bleibt es bei der Vorauswahl, die Liste entfaellt. Im Game-Kanal gibt es keine Skills.
- **Keine Skill-Schalter mehr:** Der Tab "Skills" in den Einstellungen, `api/user_skills.php` und `js/skills-settings.js` sind entfernt (die Tabelle aus Migration 014 bleibt unbenutzt liegen). `/skill kennung` und `$kennung` geben einen Skill weiterhin vor. Die Infokaesten zeigen "N weitere waehlt Mara selbst". `[SKILL: ...]` wird ueberall aus sichtbarem Text entfernt (Server, Chat, Browser-Bericht, Komprimieren).
- Tests: `skills-selftest.php` neu (Vorauswahl, Bibliothek je Fenster, Marker auch nicht aus Code, Nachladen in den Block, echter Durchlauf der Arbeitsschleife, Datei-Auftrag ohne Bibliothek, Game-Kanal, keine Schalter); `skills-ui-selftest.cjs` entfaellt mit den Schaltern; Insights- und Einstellungs-Tests angepasst.

## 0.5.79 - 28.10.2026

- **Browserfenster kompakt:** Das Live-Fenster ist im Chat hoechstens 600 Pixel breit (eine spaetere Regel gab Mara-Nachrichten die volle Spaltenbreite, das Fenster war dadurch riesig). Der Knopf ⤢ in der Kopfzeile vergroessert es auf volle Breite und wieder zurueck.
- **Mara prueft mehrere Quellen:** Sie haelt bei jeder Aktion Notizen fest (`notiz`), die der jeweiligen Seite als Quelle zugeordnet werden und in jedem weiteren Schritt mitlaufen; vorher ging alles verloren, sobald sie eine Seite verliess. Sie kann mitten in der Recherche neu suchen (`search`, hoechstens drei Suchen je Auftrag). Meldet sie zu frueh fertig, prueft sie einmal noch eine zweite Webseite (`STU_EMBER_BROWSE_MIN_SOURCES`, Standard 2). Ihr Bericht nennt die Quellen und sagt, wo sie sich widersprechen.
- **Cursor bewegt sich wirklich:** Oeffnet Mara einen Treffer, faehrt der Cursor zum Link und klickt ihn (vorher sprang die Seite einfach um). Waehrend ihr Core rechnet, liest der Cursor die echten Textbloecke der Seite entlang (reine Cursor-Schritte ohne neues Bild); das leichte Wackeln bleibt nur fuer aeltere Worker.
- **Cookie-Banner vor jedem Schritt:** Banner, die erst nach dem Laden erscheinen (Cookiebot, WELT), werden vor jedem Schritt abgelehnt, nicht nur direkt nach dem Seitenwechsel.
- **Nie eine leere Antwort:** Maras Modelfile erlaubt ihr Werkzeugmarker; antwortet sie im Bericht nur mit `[BROWSE: ...]` o. Ae., zeigte der Chat eine leere Blase. Der Bericht sagt jetzt ausdruecklich, dass keine Werkzeuge mehr gehen, Marker und `[PY]`-Bloecke werden entfernt, und bleibt nichts uebrig, steht ein Bericht aus Befund, Notizen und Quellen im Chat.
- **AFK-System entfernt (Reste des Game-Chats):** Keine Meldung "Mara ist gerade nicht an der Tastatur" mehr unter dem Browser, kein AFK-Status beim Browsen oder Antworten, keine selbststaendige AFK-Pause, keine Befehle `/afk` und `/back`; die Aktion `set_afk` antwortet `afk_removed`.
- **CoreAI-Rollen statt Game-Raenge:** Inhaber (0), Admin (1), Nutzer (4) und Gast (eigenes Merkmal). Game- und Chat-Moderator gibt es nicht mehr; Migration 019 macht bestehende Konten mit Stufe 2 oder 3 zu Nutzern (sie hatten in CoreAI keine eigene Funktion). Admin-Bereich, Einstellungen und `auth.php?action=me` (`role`) zeigen die Namen, ein Gast bleibt Gast.
- **Captcha bei Gast-Zugang und Registrierung:** selbst gehostet, ohne Dritte und ohne Tracking (Prinzip ALTCHA): Der Server stellt eine signierte Rechenaufgabe, der Browser rechnet etwa eine Sekunde (Handy zwei bis drei), der Knopf zeigt den Fortschritt. Jede Loesung gilt einmal und 10 Minuten; abgelaufene werden automatisch einmal neu geloest. Ein bekanntes Geraet (Geraetemerkmal) braucht kein Captcha. Schalter im Admin-Bereich (Standard an).
- **Gaeste nutzen den Standard-Core:** Core und Kontextfenster sind zwar je Konto gespeichert, ein Wechsel laedt aber einen anderen Core in den Arbeitsspeicher, den alle teilen. Gaeste koennen beides nicht mehr aendern (Core-Menue, KI-Profil, und zur Laufzeit erzwungen). Installieren, Aktualisieren, Deinstallieren und Cloud bleiben Admins vorbehalten; alle anderen Einstellungen eines Gastes betreffen nur sein eigenes Konto.
- **Status-Punkte im Core-Menue:** Gruen ist nur noch der Core, mit dem Mara dir gerade antwortet; installierte Cores in Bereitschaft sind gelb, fehlende oder nicht erreichbare rot. Das goldene Pulsieren beim Wechsel bleibt. Die Legende am Eingabefeld erklaert die Farben.
- **Keine Ollama-Liste mehr in den Einstellungen:** Das Feld "Lokaler Core" im KI-Profil ist weg, den Core waehlt man im Chat. Der Core-Pfad bleibt. Speichern des KI-Profils laesst den im Chat gewaehlten Core unveraendert (auch einen, den Ollama nicht mehr kennt).
- **Einstellungen neu: eine Seite mit Tabs:** Links (auf dem Handy oben als Leiste) waehlt man die Kategorie, rechts erscheint nur diese, jede mit eigenen Karten: Start, Anzeige, Profil, KI-Profil, Skills, Wissen, Memory, Konto und System. Die Startseite zeigt Begruessung und Rolle, Core und Denken, Systemstatus, Wissen und Memory auf einen Blick, dazu Kacheln fuer alle Kategorien. Die Adresse merkt sich den Tab (`settings.html#ki-profil`), Pfeiltasten wechseln zwischen Tabs.
- **Admin Core neu gestaltet und in die Einstellungen geholt:** Fuer Inhaber und Admins erscheint unter den eigenen Kategorien die Gruppe Admin Core mit Uebersicht, Benutzer, Zugang und Schutz, Betrieb, Memory (global), RAG-Lore, Protokoll und Audit. Schalter wie im Rest von CoreAI, Felder mit Erklaerung, keine Schrift mehr unter 11 Pixel (vorher 7 bis 9 Pixel in Monospace, unlesbar und pixelig). Jede Kategorie laedt ihre Daten erst beim Oeffnen. `admin/index.html` leitet auf den passenden Tab weiter, alte Lesezeichen (`#users` usw.) funktionieren weiter; `css/admin.css` entfaellt.
- Tests: neue `captcha-selftest.php` und `roles-guest-selftest.php` in beiden Preflights; Browser-, Live-Ansicht- und Login-Tests erweitert (echter Chromium-Lauf mit zwei Webseiten, neuer Suche und lesendem Cursor; Login-Seite loest eine echte Aufgabe). Neuer Browser-Test `settings-tabs-ui-selftest.cjs` (Tabs, Rollen, Speichern, Schriftgroessen, Handy-Breite, alte Admin-Adresse; laeuft im Preflight, wenn Playwright fuer Node installiert ist).

## 0.5.78 - 28.10.2026

- **Schutz gegen Mehrfach-Gaeste (Betreiberfrage):** Bisher bekam ein neuer Gast immer 5 frische Nachrichten, und dafuer reichte schon das Schliessen des Browsers (Sitzungscookie), Browserdaten loeschen oder ein privates Fenster. Jetzt:
  - Ein Geraetemerkmal (Cookie, 30 Tage, in der Datenbank nur als Hash) fuehrt zurueck ins alte Gastkonto mit dem, was dort uebrig ist.
  - Alle Gaeste aus demselben Netzwerk (IPv4-Adresse, IPv6 als /64) teilen sich ihre Nachrichten fuer 24 Stunden ("Gaeste pro Netzwerk und Tag", Standard 1, also 5 Nachrichten); wer Daten loescht, bekommt nur den Rest, danach "fuer heute aufgebraucht, wieder ab HH:MM".
  - Ein Tagesdeckel fuer alle Gaeste zusammen ("Gast-Nachrichten pro Tag", Standard 200, 0 = ohne Grenze) begrenzt auch Adresswechsler.
  - Abgelehnte Nachrichten zaehlen nicht, gesperrte Gaeste bleiben gesperrt.
- **Ratenlimits auf dem Server:** `stu_rate_limit` zaehlte nur in der PHP-Sitzung, ohne Cookie also bei null. Jetzt zaehlt der Server zusaetzlich je Netzwerk (Login, Registrieren, Gast, Passwort vergessen), und Login-Fehlversuche werden je Konto gedrosselt: 10 je Konto und Netzwerk, 50 je Konto insgesamt in 15 Minuten, danach `too_many_attempts`.
- **Echte Besucher-Adresse hinter dem Reverse-Proxy:** CoreAI lauscht auf 127.0.0.1, PHP sah fuer alle Besucher dieselbe Adresse. `X-Forwarded-For` wird jetzt von rechts gelesen und nur lokalen Proxys geglaubt (`STU_TRUSTED_PROXIES`, Standard lokale Netze), gefaelschte Eintraege links gewinnen nie. Sendet der Proxy den Kopf nicht, bleiben die Netzwerk-Regeln aus (sonst teilten sich alle Besucher ein Limit); der Admin-Bereich zeigt dann eine Warnung mit der fehlenden Proxy-Zeile.
- Datenschutz: neue Tabelle `stu_abuse_counters` (Migration 018) speichert nur HMAC-Schluessel mit Server-Geheimnis, keine IP- oder E-Mail-Adressen; Zeilen verfallen mit ihrem Zeitfenster.
- Tests: neue `abuse-guard-selftest.php` (SQLite im Speicher; eine Gegenprobe ohne Netzwerk-Regel schlaegt fehl) in beiden Preflights; Login- und Admin-Seite erklaeren die neuen Grenzen. Neue Migration 018, `health.php` prueft die Tabelle.

## 0.5.77 - 28.10.2026

- **Registrieren auf der Login-Seite:** Ist im Admin-Bereich (Runtime, "Oeffentliche Registrierung") die Registrierung eingeschaltet, zeigt die Login-Seite "Noch kein Konto? Registrieren". Das Formular fragt E-Mail, optional einen Anzeigenamen und das Passwort zweimal (mindestens 8 Zeichen) und nutzt die vorhandene Registrierung des Servers; neue Konten sind normale Nutzer. Ist die Registrierung aus, bleibt der Knopf verborgen und der Server lehnt weiter ab. Vorher gab es die Registrierung nur im Server, die Login-Seite bot sie nie an.
- Die Abfrage `auth.php?action=me` meldet ohne Anmeldung, ob Registrierung und Gast-Zugang offen sind; Registrierung und Login-Seite nutzen dieselbe Pruefung (`coreui_registration_enabled`).
- **Als Gast chatten:** Ist im Admin-Bereich (Runtime) "Gast-Zugang" eingeschaltet, legt ein dritter Knopf auf der Login-Seite ein Gastkonto an (ohne E-Mail und Passwort, Anzeigename "Gast") und oeffnet sofort den Chat. Ein Gast darf so viele Nachrichten senden, wie der Admin unter "Nachrichten pro Gast" einstellt (Standard 5, 1 bis 100); jede Antwort zaehlt, auch Neu generieren, Weiter und eine bearbeitete Nachricht. Der Zaehler haengt am Konto, ein geloeschter Chat gibt nichts zurueck. Im Chat steht "GAST · NOCH X VON Y NACHRICHTEN", danach ein klarer Hinweis. Gaeste chatten nur im privaten Chat; hoechstens 30 neue Gastkonten pro Stunde.
- **Cores verwalten:** Jede installierte Stufe hat "Aktualisieren" (nur diese Stufe) und "Deinstallieren" (zweimal tippen; Maras Haupt-Core bleibt immer), Cloud-Cores "Cloud entfernen". Ein entfernter Core wird vorher entladen; wer ihn gewaehlt hatte, chattet danach mit dem Standard-Core.
- **Ein Core im Speicher:** Beim Wechsel im Core-Menue entlaedt CoreAI zuerst die anderen lokalen Cores (Ollama: leere Anfrage mit `keep_alive` 0) und laedt erst dann den neuen, innerhalb von Maras Warteschlange. So teilen sich nie zwei Cores den Arbeitsspeicher.
- **Chat komprimieren:** Der private Chat gibt Mara nur ihre neuesten Nachrichten mit (12, bei Lore-Fragen 6); aelteres fiel bisher still heraus. Jetzt fasst Mara den herausgefallenen Teil nach einer Antwort selbst zusammen ("BISHERIGER VERLAUF" in jedem Prompt, laufend fortgeschrieben), und die Nachrichten landen woertlich im Markdown-Archiv des Chats (`var/console_media/archives/`). Das Archiv wird vor der Zusammenfassung geschrieben, geht also nie verloren, bleibt bis zum Loeschen des Chats und laesst sich herunterladen. Knopf ⇊ neben der Legende ("Jetzt komprimieren", Stand, "Archiv (.md)") oder `/komprimieren` im Eingabefeld. Der sichtbare Chat bleibt vollstaendig.
- **Maras Arbeitsschleife:** Statt genau einem Werkzeugschritt arbeitet Mara im privaten Chat selbststaendig in bis zu 6 Schritten: Nach jedem Ergebnis antwortet sie oder waehlt den naechsten Schritt (genauere Websuche, Webseiten mit `[BROWSE:]` lesen, mit `[PY]` rechnen, mit `[ARCHIV: Stichworte]` in der Mitschrift nachsehen). Sie sucht von sich aus, wenn ihr aktuelles oder genaues Wissen fehlt. Jeder Schritt steht im Arbeitsweg ("Schritt 2 von 6: Websuche" plus was sie sucht), ist abbrechbar und laeuft in Maras Warteschlange; nach dem sechsten Schritt antwortet sie mit dem, was sie hat. Python rechnet in der Schleife nur, Dateien laufen wie bisher. Der globale Kanal bleibt bei einem Schritt.
- **Live-Browser friert nicht mehr ein:** Waehrend Mara auf ihren Core wartet (auf CPU-Servern oft eine Minute je Schritt), kam bisher nichts: man sah die Zielseite mit stehendem Cursor. Jetzt zeigt das Fenster "Mara ueberlegt" mit laufender Zeit, der Cursor liest die Zeilen entlang, und der Worker schickt ein frisches Bild, sobald sich die Seite aendert (Nachladen, Banner). Vor jedem Klick und jeder Eingabe gleitet der Cursor sichtbar zum Ziel und klickt erst dort; Tippen erscheint in drei Bildern. Wartet der Auftrag noch, steht "WARTET AUF MARA" da. Nach dem 15-Minuten-Deckel des Servers oder einem Netzfehler verbindet sich das Fenster an der letzten Stelle neu (vorher endete es mit "ABGESCHLOSSEN", obwohl Mara weiter surfte); gleiche Folgebilder werden nicht mehr gespeichert.
- **Formulare und Textfelder beim Browsen (Roadmap P15, Teil):** Mara tippt in Suchfelder von Webseiten, waehlt in Auswahllisten, setzt Haken und schickt ab, etwa fuer Fahrplaene, Filter oder Rechner. Feste Regeln im Worker, unabhaengig vom Core: keine Passwoerter, Zahlungsdaten, Einmalcodes oder Datei-Uploads; keine Anmelde-, Zahlungs-, Nachrichten- oder Kontaktformulare abschicken; keine Knoepfe zum Kaufen, Bestellen, Bezahlen, Abonnieren, Registrieren, Anmelden, Senden oder Loeschen; E-Mail, Telefon- und Kontonummern nur, wenn sie woertlich im Auftrag stehen; mehrzeilige Felder nie mit Enter. Blockiertes erscheint gelb im Fenster und als Hinweis fuer den naechsten Schritt. Schalter `STU_EMBER_BROWSE_FORMS` (Standard an).
- **Browser gehaertet:** Playwright 1.5x hat den Accessibility-Snapshot entfernt, die Elementliste war daher leer und Mara konnte nichts anklicken. Jetzt nummeriert der Worker sichtbare Links, Knoepfe und Felder selbst (Mara antwortet mit der Nummer), liest den Haupttext der Seite in Fenstern weiter (Scrollen bringt neuen Text), kann zurueckgehen, lehnt Cookie-Banner ab, wo moeglich, und sperrt lokale Adressen (127.0.0.1, 192.168.x.x, .local, auch per Weiterleitung oder DNS), damit eine fremde Seite den Browser nicht auf Ollama, SearXNG oder die Datenbank lenkt (`STU_EMBER_BROWSE_ALLOW_PRIVATE`).
- **Derselbe Core wie im Chat:** Der Browse-Auftrag merkt sich den Core und das Kontextfenster des Nutzers (Spalten `model`, `num_ctx`, ohne Migration); vorher lud der Worker immer den Standard-Core dazu. Der Worker sendet keine Gemma-Parameter mehr (vorher temperature, num_predict und `keep_alive` -1), `keep_alive` kommt wie im Chat aus der Konfiguration. Das Ergebnis meldet Mara in ihrer eigenen Stimme mit Quelle (vorher ein alter "Ember"-Text), die Komprimierung wartet, bis der Browse-Auftrag durch ist.
- **Websuche gehaertet:** Findet eine ganze Frage nichts, sucht Mara mit ihren Stichworten noch einmal ("Wie ist das Wetter morgen in Moenchengladbach?" wird "Wetter morgen Moenchengladbach"); dieselbe Seite mit Tracking-Parametern zaehlt nur einmal; Snippets ohne HTML und Entities; nur http(s)-Links; bekannte Datumsangaben stehen beim Treffer ("vom 2026-10-27"). Gilt fuer `[WEB:]` und den Browser.
- Tests: neue `registration-ui-selftest.cjs`, `guest-access-selftest.php`, `compaction-selftest.php` (mit SQLite im Speicher), `agent-loop-selftest.php` (mit nachgebautem Core), `browse-worker-selftest.py` (Regeln; mit Playwright zusaetzlich ein echter Chromium-Lauf gegen Testseiten), `browse-live-ui-selftest.cjs` und `web-search-selftest.php` in beiden Preflights; Installer- und Fenster-Tests erweitert.

## 0.5.76 - 28.10.2026

- **Werkzeug-Erwaehnungen loesen nichts mehr aus:** Erklaerte Mara ihre Werkzeuge ("dazu nutze ich den `[WEB: ...]`-Befehl", "`[PY]`-Block"), starteten diese Erwaehnungen echte Suchen und der restliche Text lief als Python (im Test vom 28.10.2026: 4 Werkzeuge, 1 Fehler). Marker in Inline-Code sind jetzt Erwaehnungen; echte Aufrufe, auch Python mit Codeblock, funktionieren weiter.
- **Keine falsche Korrekturrunde:** Ein Fortschrittsblock vor normalem Text gilt auch mehrzeilig, lang oder offen als normale Antwort (ohne Denken kostete das eine zusaetzliche Core-Runde, ueber eine Minute).
- **Sicherheitsnetz laesst Code stehen:** Nicht ausgefuehrte Marker im Fliesstext werden weiter entfernt, Erwaehnungen in Code-Markierungen bleiben (vorher blieb "``-Befehl" uebrig).
- **Unterstriche im Chat:** `_` betont nie mehr mitten im Wort (COREUI_PLAN_SAVE, snake_case, my_app/config_file.php bleiben wie geschrieben), `*` nicht mehr zwischen Ziffern (2*3*4). Normale Betonung mit _kursiv_, *kursiv*, **fett** bleibt.
- **Ruhigerer Arbeitsweg:** Einfache Antworten ohne Werkzeug, Notiz, Korrektur, Datei, Wissenstreffer oder Fehler zeigen keinen Arbeitsweg mehr. Die Einstellung heisst jetzt "Arbeitsweg offen halten".
- **Analyse-Filter** erkennt auch zusammengesetzte Namen wie "[Systemanalyse: ...]".
- **Sprachplan, Lauf 4:** Googles Transkriptionsvorlage erkennt 10 von 10 kurzen Aufnahmen in etwa 1 s und 60 s in 18 s, ohne temperature 0. Entscheidung: Sprachnachrichten zweistufig (Mitschrift, dann Antwort auf den Text), Live-Gespraech einstufig mit Mitschrift im Hintergrund. Alle vier lokalen Cores auf dem Server nutzen dieselbe Gewichtsdatei.
- **TCI-Namen fuer die Gemma-Stufen im Chat:** e2b heisst "TCI: Mara Schnell", e4b "TCI: Mara Normal", 12b "TCI: Mara Plus", 26b "TCI: Mara Pro", 31b "TCI: Mara Premium", auch als Cloud-Variante. Maras eigener Core traegt die Stufe seiner Basis aus dem Modelfile. Chip, Core-Menue und CORE-Infokasten zeigen den TCI-Namen; Cores anderer Familien behalten ihren Namen. Das KI-Profil zeigt weiter die technischen Namen.
- **Core-Menue in fester Reihenfolge, ohne Dopplung:** Schnell, Normal, Plus, Pro, Premium, danach Cores mit eigenem Namen, jede Stufe genau einmal. Gibt es eine Stufe auch in der Cloud, hat ihre Zeile die Knoepfe Lokal und Cloud; fehlt die lokale Variante, ist "Lokal" grau und oeffnet den Installer. Ein gruener Punkt zeigt installiert und bereit, ein gelber den Core, der gerade laedt. Eine fehlende lokale Stufe ohne Cloud steht ausgegraut an ihrem Platz und bleibt antippbar.
- **Installer im Chat (Betreiberauftrag, fuer Betreiber ohne SSH):** Eine fehlende Stufe oeffnet ein Fenster mit Erklaerung und kurzem Hardware-Check (Ollama erreichbar, Arbeitsspeicher gesamt und frei gegen den Bedarf, Threads und AVX, freier Speicherplatz gegen den Download, Tempo-Richtwert, Grafikkarte falls Ollama sie nutzt) und einer Empfehlung: installieren, knapp oder lieber nicht. Admins installieren mit einem Knopf: Ollama laedt die Gemma-Basis (`/api/pull`, Fortschritt in GB und Prozent), dann baut CoreAI Maras Core aus `config/Modelfile.template`, nur FROM getauscht (`/api/create`, z. B. `ember-coreui:12b`), und prueft den SYSTEM-Text. Bei "lieber nicht" nur mit zweiter Bestaetigung; ohne Ollama gesperrt. Ein Auftrag zur Zeit, das Fenster darf zu, die Installation laeuft auf dem Server weiter. Danach "Jetzt nutzen". Das Modelfile selbst und die Installationsskripte bleiben unveraendert. Groessen laut ollama.com/library/gemma4 (12b: 7,7 bis 8,0 GB).
- **Cores verwalten (Admins, Knopf im Core-Menue):** Uebersicht aller Stufen mit ihren Cores und Status; Namen, die nur ein zweiter Name fuer dieselben Gewichte sind (z. B. gemma4:26b als Kopie von e4b), werden markiert. "Jetzt aktualisieren" zieht jede lokale Gemma-Basis erneut (nur Geaendertes kommt herunter) und baut Maras Cores darauf neu, wenn sich die Basis geaendert hat oder ihr SYSTEM-Text nicht mehr zum Modelfile passt: die Installer-Cores aus `config/Modelfile.template`, Maras Haupt-Core aus `var/Modelfile.generated` (Betreiberentscheidung). Ergebnis je Stufe (aktuell, neue Fassung, Fehler). Schalter "Auto-Update" (einmal pro Woche, gespeichert in `stu_app_settings`): der erste Seitenaufruf nach Ablauf der Woche startet das Update im Hintergrund; Einschalten startet die Woche neu, es gibt also keinen ueberraschenden Download. Schalter und Knopf sitzen auch im Installationsfenster.
- **Ollama Cloud ohne Terminal:** Der Bereich "Ollama Cloud" erklaert, dass Cloud-Cores ein Ollama-Konto auf dem Server brauchen (kostenlos auf ollama.com, Ollama ab 0.12), und verbindet wie `ollama signin`: CoreAI fragt das lokale Ollama (`POST /api/me`), das ohne Konto einen Anmeldelink auf ollama.com liefert; nach dem Bestaetigen dort zeigt "Verbindung pruefen" das Konto. Kein Token noetig, nur Links auf `https://ollama.com/connect` werden angezeigt. Danach fuegt "Cloud hinzufuegen" gemma4:31b-cloud hinzu (nur ein kleiner Eintrag, kein Download); der Cloud-Core spricht ueber die Persona-Nachricht als Mara.
- **Jeder Core ist Mara:** Rohe Gemma- und Cloud-Cores ohne eigenen SYSTEM-Text bekommen Maras SYSTEM-Text aus `config/Modelfile.template` (ohne `<|think|>`) als System-Nachricht; vorher antwortete dort ein Gemma ohne Persona und ohne Werkzeugregeln. Maras eigener Core nutzt unveraendert sein Modelfile, ein Core mit eigenem SYSTEM-Text behaelt ihn.
- **Legende am Eingabefeld** (Knopf "?" neben dem Core-Chip): Core-Stufen mit den technischen Cores dahinter und ihrem Status, die Formatierung im Chat und die Befehle und Tasten (`/skill kennung`, Enter, Shift+Enter, Anhaenge, langer Text als `.md`).
- Tests: neue `tool-mention-selftest.php`, `core-tiers-selftest.php`, `core-install-selftest.php` und `core-install-ui-selftest.cjs` in beiden Preflights; Markdown-, Arbeitsweg-, Core-Chip- und Datei-Werkzeug-Tests erweitert.

## 0.5.75 - 28.10.2026

- **Denkschalter im Chat:** Ein Core-Chip unter dem Eingabefeld zeigt den aktiven Core und ob Mara denkt (DENKEN oder SCHNELL). Ein Tipp oeffnet ein Menue mit dem Schalter "Denken"; der Wert gilt fuer alle Chats des Kontos ab der naechsten Nachricht. Der Schalter im KI-Profil entfaellt; das KI-Profil ueberschreibt den Wert beim Speichern nicht mehr.
- **Modelfile ohne festes `<|think|>`** (Freigabe des Betreibers): Bisher dachte Mara bei jeder Anfrage, auch mit `think: false`, und der Schalter verbarg das Denken nur (22 bis 28 s bis zum ersten Wort). Ollamas Gemma-4-Renderer setzt `<|think|>` jetzt nur bei `think: true`. Der globale Kanal denkt weiter wie bisher. Das normale Update baut Maras Core neu.
- **Core-Wahl im Chat:** Das Menue listet alle Cores aus Ollama wie das KI-Profil (Serverstandard, Groesse, geladen, Cloud). Eine Auswahl speichert nur den Core; ein lokaler Core wird sofort ueber Maras Warteschlange mit denselben Optionen wie ein Chat-Aufruf vorgeladen, der Chip zeigt LAEDT bis Ollama ihn im Speicher hat. Cloud-Cores werden nicht geladen.
- **Analyse-Block entfernt:** Ohne Denken schrieb Mara ihre Vorueberlegung manchmal sichtbar als "[Analyse: ...]" vor die Antwort; ein solcher vorangestellter Block wird jetzt entfernt (Werkzeugmarker bleiben). Die Bau-Entscheidung START_BUILD/CHAT toleriert ihn ebenfalls.
- **Medien-Test, Lauf 3 ausgewertet:** kurze Aufnahmen scheiterten in Maras Persona in 11 von 20 Faellen; der Test vergleicht jetzt Googles Transkriptionsvorlage mit neutralem SYSTEM-Text, eine Sekunde Stille davor und `temperature 0` (nur in dieser Testvariante freigegeben), dazu Live einstufig und zweistufig mit dem ersten wirklich sprechbaren Satz, Gewichtsdatei je Core und einen kompakten Bericht. Die Erkennung von Fehlantworten ordnet alle echten Faelle richtig ein (gehoert, keine Aufnahme, keine Sprache).
- Tests: neue `thinking-switch-selftest.php` und `thinking-switch-ui-selftest.cjs` in beiden Preflights; Gemma-Modelfile-, Audio- und Preflight-Pruefungen angepasst.

## 0.5.74 - 28.10.2026

- **Messprotokoll P20.0** im Sprachplan: zwei Laeufe auf dem echten Server. Audio kostet 25 Tokens je Sekunde, Einlesen etwa 170 und Ausgabe etwa 22 Tokens je Sekunde; 60 Sekunden laufen stabil und werden durchgehend mitgeschrieben; Ollama 0.40.6 nimmt auch 24 Bit, Float und 44,1 kHz Stereo an, nur rohes PCM nicht; Bild und Ton gemeinsam funktionieren. Verborgenes Denken durch `<|think|>` im Modelfile kostet 14 bis 27 Sekunden je Aufruf; eine 5-Sekunden-Aufnahme wurde einmal uebersehen. Abschnitte daher bis 60 Sekunden, Live-Gespraech ohne Denken mit Vorwaermen.
- **Medien-Test erweitert:** gestreamte Antworten mit Zeit bis zum ersten Wort und ersten Satz, Lade- und Denkzeit pro Fall; Vergleich mit Maras SYSTEM-Text ohne `<|think|>` (nur im Testaufruf, Modelfile unveraendert) fuer Mitschrift und Live-Antwort; kurze Aufnahmen (3 und 5 Sekunden, je fuenfmal) mit und ohne Anhang-Hinweis; Digest jedes Cores; Kurzfassung und vollstaendige Mitschrift im Bericht; `keep_alive` aus der CoreAI-Konfiguration. Neue Optionen `--short` und `--repeat`.
- **Hilfen fuer Audio-Aufrufe** `api/voice_core.php`: Anhang-Hinweis mit Laenge, Erkennung von "keine Aufnahme"-Antworten fuer eine automatische Wiederholung, SYSTEM-Text aus `/api/show`, SYSTEM-Text ohne Denk-Schalter. Fuer P20.1 und P20.4.
- Tests: `audio-wav-selftest.php` prueft die neuen Hilfen (auch mit den echten Antworten des Serverlaufs) und den erweiterten Test; Probelauf gegen einen nachgebauten Ollama mit gestreamten Antworten.

## 0.5.73 - 28.10.2026

- **Medien-Test P20.0:** `scripts/media-spike.sh` (Host) und `scripts/media-spike.php` (php-Container oder nativ) messen Hoeren ueber Gemma 4: Faehigkeiten laut `/api/show`, Testaufnahme 5 bis 60 Sekunden als WAV 16 kHz Mono 16 Bit, falsche Formate als Gegenprobe, Bild und Ton gemeinsam, Antwortzeiten, Tokens und Mitschrift. Optional Stimmen-Kandidaten (Rechenzeit, Echtzeitfaktor, RAM bei 2 und 4 Kernen) und ein Bildbefehl. Jeder Core-Aufruf wartet in Maras Warteschlange, ohne Ollama-Optionen, ohne Modell- oder Datenbankaenderung. Bericht als Markdown und JSON.
- **WAV-Pruefung** `api/audio_wav.php`: liest den RIFF-Header (auch WAVE_FORMAT_EXTENSIBLE, offene Datenlaenge, Zusatz-Chunks) und lehnt alles ausser PCM, Mono, 16 kHz, 16 Bit mit Grund ab. Wird spaeter in P20.1 vor jedem Senden genutzt.
- Tests: neue `scripts/audio-wav-selftest.php` in beiden Preflights; Probelauf gegen einen nachgebauten Ollama.

## 0.5.72 - 28.10.2026

- **Roadmap auf Stand 0.5.71:** Neue Statustabelle je Etappe (M1 praktisch erledigt, M2 grossteils erledigt, M3 offen, M4 bis M6 teilweise) und "Naechste Lieferfolge" nach Betreiberentscheidung: P20.0 Test, P20.1 Spracheingabe, P20.2 Videos hoeren, P20.3 Sprachausgabe, P20.4 Live-Gespraech, P20.5 Bildgenerierung, danach P09 Projekt-Workspace.
- **Neuer Sprachplan** `docs/COREUI_VOICE_PLAN.md`: Hoeren ueber Gemma 4 via Ollama, eigene Mara-Stimme als Server-TTS, Vorlesen satzweise, Live-Gespraech mit Sprechpausen-Erkennung und Unterbrechen, Datenschutz fuer Aufnahmen, gemeinsame Abnahme.
- **Regel zur seriellen Ausfuehrung** in Roadmap, Funktionsmatrix und Masterplan um die einzige Ausnahme ergaenzt: Sprachausgabe mit festem Kernlimit parallel zu genau einer Core-Anfrage.
- **Betreiber-Recherche eingearbeitet:** Audio nur bei `gemma4:e2b`, `e4b` und `12b` (nicht 26b/31b), Format WAV mit RIFF-Header, 16 kHz, Mono, 16 Bit PCM signed (`pcm_s16le`, Header wird vor dem Senden geprueft), hoechstens 30 bis 60 Sekunden je Clip; CoreAI schneidet Abschnitte von hoechstens 30 Sekunden an Sprechpausen und deaktiviert das Mikrofon bei Cores ohne Audio. Bildplan um das konkrete FLUX.1-schnell-Startsetup ergaenzt (T5-XXL Q4_K_M, CLIP-L Q8_0, Transformer Q8_0 oder Q5_K_M, VAE `ae.safetensors`, GGUF von `city96`), Lizenzhinweis zu SDXL-Turbo, Aufruf von stable-diffusion.cpp als isolierter Worker-Prozess.
- Funktionsmatrix: CF-057 und CF-058 auf den Plan verwiesen. Keine Code-, Modell- oder Datenbankaenderung.

## 0.5.71 - 28.10.2026

- **RAG-Lite findet Namen in grossen Dokumenten:** Live-Befund: Nach dem Hochladen der drei Starlight-Unit-Bibeln fand Mara "Ayal'ren" und "Alt Khemet" nicht. Ursachen im Nachbau: Die Suche nahm die laengsten Woerter statt der Namen, das Ranking zaehlte nur Treffer (jeder Abschnitt nennt "Starlight Unit" und gewann), der Rest "ren" aus "Ayal'ren" traf "Waren", "Lernen", "Fahren", und der Rueckfallweg sah nur die neuesten 80 Abschnitte. Neu: Namen behalten ihren Stamm ueber Apostrophe, Fuellwoerter fallen weg, Gewichtung nach Seltenheit im eigenen Wissen (IDF), Treffer nur am Wortanfang, Bonus fuer zusammenstehende Namen ("Alt Khemet"), alle Abschnitte der seltensten Begriffe werden geprueft. Rueckfragen ohne Stichwort nutzen den Gespraechsverlauf. Bei grossem Kontextfenster bis zu 6 Abschnitte mit je 1.400 Zeichen. Arbeitsweg-Schritt "Privates Wissen durchsucht" mit Treffern und Quellen.
- **Eine Mara fuer Text, Bild und Video:** Der Vision-Zweig in `ember_generate_reply()` schickte einen eigenen Charakter ("Gamer-Note, Online-Chat der 2000er") als System-Prompt. Bei Ollama ersetzt jede System-Nachricht das Modelfile-SYSTEM; dazu fehlten Verlauf, KI-Profil, Profil und Gedaechtnis. Danach klang Mara anders und reagierte gereizt. Jetzt: leeres System wie bei Text, derselbe Prompt `$u` plus Anhang-Aufgabe, Regeln gegen das Uebernehmen von Ton und Aussagen aus dem Anhang, konfiguriertes Zeitlimit auch fuer ein Einzelbild. Keine Gedaechtnis-Reflexion aus Bildbeschreibungen in der Konsole (Laufzeitfakten und Beziehungswert bleiben).
- **Keine falsche Fehlermeldung bei Anhaengen:** Bild, Video und PDF-Seiten laufen absichtlich synchron ueber den Bildpfad. Der leere Textstream wurde als "Modellstream unvollstaendig" (sichtbar: "Die Uebertragung von Core war unvollstaendig", 1 Fehler) gezaehlt. Jetzt Schritt "Anhang wird angesehen".
- Tests: neue `scripts/knowledge-search-selftest.php` und `scripts/vision-persona-selftest.php` in beiden Preflights.

## 0.5.70 - 27.10.2026

- **Kontextfenster im KI-Profil (#113):** Schieberegler 4K bis 128K plus "Core-Standard übernehmen" (Voreinstellung). Speicher-Richtwert an jeder Stufe, abhaengig vom gewaehlten Core (`api/ai_context_estimate.php`: Gewichte aus `/api/tags`, KV-Cache aus `/api/show`-Metadaten inklusive Sliding Window, Korrektur ueber `/api/ps`, `MemAvailable`); gerundet als Bandbreite, sonst "nicht zuverlaessig schaetzbar". Warnung bei deutlich zu wenig Speicher, keine Sperre. Strikte Whitelist, Migration `017_ai_num_ctx.sql` (NULL = kein Override), `invalid_num_ctx` bei ungueltigem Wert. `options.num_ctx` nur bei ausdruecklicher Wahl, nur lokal und nur in der privaten Konsole, nie Cloud, keine weiteren Gemma-Parameter; SSE und Sync gleich. Pro Lauf fixiert. `ember_ollama_context_window()` liefert das gesendete Fenster (Quelle "KI-Profil"); der Infokasten zeigt den von Ollama geladenen Wert getrennt. Modelfile unveraendert.
- **"Core" statt "Modell" (#115):** Arbeitsweg, Infokaesten, Einstellungen, Admin und sichtbare Server-Meldungen sprechen von Core. Interne Ereignistitel, API-Felder und Protokollkennungen unveraendert (`CoreUIWorkEvents.displayTitle`). Regressionstest gegen sichtbares "Modell".
- **WebUI-Robustheit (#112, P1/P2):** `js/coreui-net.js` (Timeout, Abbruch, Fehlerarten, Backoff mit Jitter, datensparsame Messwerte). Verlauf: abbrechbar beim Sitzungswechsel, eine Hinweiszeile mit "ERNEUT LADEN" statt neuer Systemzeilen, begrenzte automatische Wiederholung, nichts Angezeigtes wird entfernt, Entwurf bleibt. `online`/`visibilitychange` synchronisieren nur lesend; laufende Auftraege werden ueber die Server-ID angebunden, nie doppelt gesendet. Antwort-Poll zaehlt Offline-Versuche nicht gegen das Zeitbudget. Aeltere Seiten werden inkrementell vorangestellt (Nachbau 1.000 Nachrichten: rund 25 ms je Seite statt bis 1 s), Leseposition bleibt; lange Verlaeufe rendern begrenzt; inaktive Sitzungscaches werden gekuerzt.
- **Datum und Uhrzeit in jedem Core-Aufruf:** Eine von Mara erstellte Homepage trug das Jahr aus den Trainingsdaten (2024). Der Hauptprompt hatte die SERVERZEIT, die getrennten Aufrufe (einzelne Quelldateien, deren Fortsetzungen, Plan- und Werkzeug-Folgeaufrufe, Korrekturen) aber weder Datum noch Uhrzeit. `ember_with_today()` stellt jedem Core-Aufruf eines Konsolenauftrags `[DATUM HEUTE: ...]` voran und setzt `[UHRZEIT JETZT: HH:MM Uhr, Zeitzone]` direkt vor die letzte Anweisungszeile, damit der gemeinsame Auftragstext bei einem Minutenwechsel im Prompt-Cache bleibt. Nie doppelt, Global-Kanal unveraendert. Die SERVERZEIT-Anweisung erlaubt das Datum jetzt ausdruecklich fuer erzeugte Inhalte wie das Copyright-Jahr. Mit einem Fake-Ollama geprueft: der echte Einzeldatei-Request beginnt mit dem Datum, die Uhrzeit steht vor "JETZT NUR DEN INHALT ... AUSGEBEN".
- Tests: neue `scripts/ai-context-selftest.php`, `scripts/context-slider-ui-selftest.cjs`, `scripts/webui-resilience-selftest.cjs`, `scripts/date-awareness-selftest.php` in beiden Preflights; Gemma-, Infokasten-, Arbeitsweg- und Frontend-Tests erweitert.

## 0.5.69 - 27.10.2026

- **Infokaesten auf dem Server:** `console_insights` fehlte in der nginx-Freigabeliste (`config/nginx.conf.template`, `config/runtime/nginx.conf.template`); Werkzeuge und Projekt blieben deshalb im Ladezustand. Beide Preflights und `scripts/insights-selftest.php` pruefen die Route. Ein nicht erreichbarer Endpunkt wird im Kasten benannt.
- **Kein Chat-Flackern nach einer Antwort:** Eine kurz vor dem Laufende gestellte Hintergrund-Abfrage belebte den beendeten Lauf wieder (Neuaufbau, Tipp-Indikator, Download-Karten neu). Die App merkt sich beendete Laeufe, uebernimmt die Lauf-ID in die gespeicherte Antwort und ignoriert den Basislauf eines beantworteten Turns. Echte Neu-Generierungen laufen weiter. Im Browser-Nachbau: vorher Neuaufbau und haengende Zeile "Arbeitsweg · laufend", nachher keiner.
- Tests: `chat-navigation-recovery-selftest.cjs` und `insights-ui-selftest.cjs` erweitert.

## 0.5.68 - 27.10.2026

- **Autoscroll beim Live-Arbeitsweg:** Die Ansicht blieb kurz nach der eigenen Nachricht stehen, weil ein grosser laufender Block an seinem Anfang festgehalten wurde und die Ansicht nur bei Server-Ereignissen folgte. Jetzt folgt sie dem Ende des neuesten Beitrags und jedem Wachstum (`ResizeObserver`), auch zwischen den Ereignissen.
- **Stopp nur durch den Nutzer:** Mausrad, Wischen, Tasten oder Scrollleiste halten die Leseposition an. Layout-Verschiebungen, Scroll-Anchoring und Neuaufbau zaehlen nicht mehr als Nutzer-Scrollen; Gesten gelten zeitlich begrenzt.
- **Arbeitsweg im Nachrichtenfluss:** Erzaehlung als normaler Antworttext, Routine-Schritte pro Abschnitt zu einer aufklappbaren grauen Zeile gefaltet, Aufgabenkarte mit allen geplanten Projektdateien und Status, Live-Statuszeile mit Taetigkeit, Laufzeit und Ausgabe-Tokens. Nur Erzaehlung wird getippt, Schritte erscheinen sofort. Neues Ereignis "Projektdateien geplant" mit sicher gefilterter Dateiliste.
- **Infokaesten Core, Kontext, Werkzeuge, Projekt:** neuer Endpunkt `api/console_insights.php` (nur lesend, Eigentuemer und Sitzung geprueft, keine Modelltexte), `js/coreui-insights.js`. Ab 1400 Pixel rechte Spalte im CoreAI-Kastenstil, darunter aufklappbare Buttons; Kontext-Kasten live aus den Arbeitsereignissen. Quelle des Kontextfensters (geladen, Modelfile, gemessen, Standard) wird angezeigt.
- Tests: `scripts/chat-follow-selftest.cjs` um Live-Wachstum, Blockende, programmatische Verschiebung, veraltete Geste und Scrollleisten-Ziehen erweitert; neue `scripts/insights-selftest.php` und `scripts/insights-ui-selftest.cjs` in beiden Preflights; Arbeitsweg-UI-Test um Falten, Aufgabenkarte und Statuszeile erweitert; Browser-Nachbau bei 412, 600, 1010 und 1584 Pixel Breite.
- Versionsstand 0.5.68 mit HTML-Versionen, SHA256-Frontend-Keys, README, TXT-Changelog und Uebergabe. Keine Modell-, Ollama- oder Modelfile-Aenderung.

## 0.5.67 - 27.10.2026

- **Kontextfenster erkannt:** Die abgeschnittenen lokalen Laeufe endeten exakt bei 4096 Tokens (Prompt + Ausgabe). CoreAI liest das echte Fenster jetzt nur lesend ueber `/api/ps`, merkt sich volle Fenster und nimmt sonst Ollamas CPU-Standard 4096 an. Kein `num_ctx`, kein Modelfile- oder Ollama-Eingriff.
- **Schlanke Einzeldatei-Prompts:** Planauszug, Referenzdateien und Ausgabereserve richten sich nach dem Fenster; bereits erzeugte Seiten erscheinen als DOM-Schnittstelle. Reine Quelltextaufrufe und die kurze Bauentscheidung laden keine SKILL-Dokumente. Cloud-Prompts bleiben unveraendert.
- **Kleine Fenster in der Konsole:** Verlauf, Zeilenlaenge, optionale Skills, Planblock und Web-/Browse-/Python-/Werkzeughinweise werden bei 4096 bzw. 8192 Tokens gekuerzt.
- **Kein abgeschnittenes Python:** Code aus einer Antwort mit Token- oder Fensterende wird nie ausgefuehrt. Mehrseitige Projekte lokaler Modelle gehen an den geprueften Projekt-Writer; der lokale Python-Hinweis widerspricht `[COREUI_PROJECT_BUILD]` nicht mehr.
- **Selbstkorrektur ohne Schlagwoerter:** Ein von Mara gewaehlter Dateijob erhaelt nach einem Fehler (auch SyntaxError) genau einen begrenzten Korrekturversuch, bei kleinem Fenster kompakt. Danach folgt eine sachliche Meldung mit Grund statt "ich melde mich".
- **Neu generieren/Bearbeiten:** Die Bauentscheidung fuer gespeicherte Plaene laeuft auch bei diesen Aktionen.
- **Werkzeugparser:** Leerzeilen, kurzer Nachsatz nach dem Marker, Einleitung vor PROJECT_BUILD und reine Fortschrittszeilen werden sicher verarbeitet. Verneinte Frameworks ("kein React") blockieren kein Projekt mehr.
- **Konsole:** Antworten behalten Absaetze, Listen und Codeeinrueckung.
- **Tests:** neuer `scripts/context-budget-selftest.php` in beiden Preflights; erweiterte Datei-, Werkzeug-, Cloud-Marker-, Projektentscheidungs-, Frontend- und Workevent-Tests. Vier durch 0.5.66-Umbenennungen rote Selbsttests repariert (Testseite; Modelfile unveraendert).
- **Cloud-Werkzeugantworten:** Genau ein geschlossener Block mit kurzem Satz davor oder danach, Inline-Code im Nachsatz, fetten Markerzeilen oder CRLF wird erkannt. Unlesbare Antworten erhalten einen begrenzten Grund (`multiple_tool_blocks`, `tool_marker_in_code_fence`, ...) im Arbeitsweg und genau einen Korrekturaufruf ueber denselben Ausfuehrungsweg, in SSE und Sync. Mehrere Bloecke, Codebloecke und "Beispiel:"-Einleitungen bleiben gesperrt.
- **Chat-Leerraum behoben:** Die unsichtbaren, absolut positionierten Schritt-Titel brachen aus dem scrollenden Arbeitsweg aus und verlaengerten den Chat (Nachbau: rund 1300 Pixel Leerraum). Arbeitsweg, Eintraege und Quelltextvorschau sind jetzt positioniert.
- **num_ctx des Betreibers:** Messung 25,1 / 22,4 / 24,3 / 22,0 Ausgabe-Tokens/s bei 4096 / 16384 / 32768 / 65536. Der Betreiber setzt `PARAMETER num_ctx` (jetzt 131072) selbst im Modelfile-Template; CoreAI liest den Wert bei entladenem Modell ueber `/api/show` und sendet weiterhin kein `num_ctx`.
- **Offen:** Issue #102; Live-Abnahme auf Netcup/Android; Tempo und Speicher bei 131072 sind noch nicht gemessen.

## 0.5.66 - 27.10.2026

- **Lokale Quelltext-Fortsetzung:** Die Screenshot-Diagnose HTTP 200, `done_reason=stop`, `source_continuation_invalid` war bisher nicht abgedeckt, weil der Handler nur bei `length` mit dem Fortsetzen begann. Strukturell offene HTML-/CSS-/JS-Quelltexte werden jetzt auch nach einem Stop gezielt geprueft und ohne Modelfile-/Tokenoptionen fortgesetzt.
- **Sichere Neustartannahme:** Liefert ein Modell statt der angeforderten Fortsetzung eine neue vollstaendige Version derselben Quelle, darf CoreAI sie nur bei erfolgreicher Dateipruefung und uebereinstimmendem bereits erhaltenem Prefix uebernehmen. Fehlerhafte Fragmente erzwingen keinen sofortigen Abbruch aller weiteren Dateiversuche.
- **Cloud-Werkzeugprotokoll:** Ein einzelner angefangener `PLAN_AND_BUILD`-Marker mit echtem Markdown-Plan kann als *unvollstaendiger PLAN_SAVE-Entwurf* gesichert werden. Mischmarker, Beispiele, Mini-Fragmente und unvollstaendige Build-Ausfuehrungen bleiben blockiert.
- **Chat-Leerraum:** `js/coreui-chat-follow.js` richtet Rueckspruenge und Auto-Follow an der zuletzt tatsaechlich gerenderten Chatnachricht aus. Die Layout-Mindesthoehe unterhalb des Verlaufs und ueberfluessige untere Auffuellung entfallen. Der bestehende transparente Pfeil und Arbeitspunkte bleiben erhalten.
- Regressionstests: stop-vs-incomplete CSS/HTML, abgeglichener Source-Neustart, unvollstaendiger Cloud-Plan, Leerraum unter der letzten Chatnachricht und bestehende Sicherheitsfaelle.
- Versionsstand 0.5.66 mit HTML-Versionen, SHA256-Frontend-Keys, README, TXT-Changelog und Uebergabe. **Nicht geloest:** persistente dateiweise Checkpoints, globale sichere Agentenfortsetzungen, >9-Quelldatei-Manifeste aus Issue #102. Keine weiteren Modelllimits eingefuehrt.

## 0.5.65 - 26.10.2026

- **Chat-Scroll repariert:** `scrollToBottom()` respektiert jetzt, ob der Nutzer den neuesten Inhalt verfolgt oder bewusst weiter oben liest. Auch bei Live-Workevents, Quellcode-Chunks, SSE und Polling wird eine entfernte Leseposition nicht mehr ueberfahren. Der neue Nutzer-Turn und explizite Ruecksprung koennen die Nachfuehrung wieder aktivieren.
- **Floating Jump:** vorhandener `#jumpLatest` ist verkabelt. Unterhalb der letzten Nachrichten erscheint der transparente, runde Pfeil; bei laufendem Mara-Auftrag stattdessen drei animierte Arbeitspunkte. Ruecksprung mit Bewegung oder ohne Animation bei reduzierter Bewegung.
- **Echter Work-Renderer-Umbau:** Fortschritte stehen als ungerahmte Chatabschnitte, ohne die wiederholten Titel- und Metakarten. Time/Diagnostics sind weiterhin ueber ein zugaengliches kleines Info-Symbol erreichbar. Live-Quelle behaelt ein eigenstaendiges Scrollfenster und zieht den Nutzer nicht aus einer frueheren Position.
- **Deterministisches ZIP:** bestaetigte, vollstaendige Quelldateien werden nicht mehr bei 512 kB Gesamtumfang abgeblockt. Die safe Writer-Budgetierung richtet sich nach den tatsaechlichen validierten Quellen statt dem Codebudget fuer untrusted Modell-Python. Model-Codeberechtigung, Python-Isolation, ZIP-Manifestaudit, Quelldatei-Validierung und Nutzerkontext bleiben unveraendert.
- Neue JavaScript-Regression `scripts/chat-follow-selftest.cjs`, Erweiterungen des lokalen Dateiwerkzeug-Selbsttests um ein 520-kB-Projekt und Hochentropie-Daten, Einbindung in vorhandene Preflights. Versionsstand 0.5.65 mit Pflichtdokumentation und inhaltsbasierten Cache-Keys synchronisiert.
- **Weiter offen:** echte persistente Dateicheckpoints, groessere Projektmanifeste und endliche Fortsetzungsrunden aus Issue #102. Kein Beleg fuer fehlerfreie 10-Unterseiten-Generierung oder erfolgreichen Netcup-Livetest.

## 0.5.64 - 26.10.2026

- **Netcup-Preflight-Hotfix:** In `scripts/local-file-tool-selftest.php` stand noch die 0.5.61-Assertion `if ($augmentSkills) $userPrompt = coreui_skills_augment($userPrompt);`. Die seit 0.5.62 korrekte Implementierung lautet `$userPrompt = coreui_skills_augment($userPrompt, $augmentSkills);`. Der alte Test schlug damit trotz korrektem CoreAI-Werkzeugvertrag fehl.
- Die Assertion prueft jetzt den aktuellen Pflicht-Skill-Aufruf. Die uebrigen vier Teilpruefungen (fokussierte Datei-Anweisung, genau ein sinnvoller Fallback, sichere HTTP-/Transportdiagnostik) sind anhand des unveraenderten Handlers weiterhin erfuellt.
- Versionsstand 0.5.64 mit allen sechs HTML-Angaben, JavaScript-Bootstrap, numerischem SHA256-Cache-Key, Versions-Selbsttests, Preflight-Kopfzeilen, README, TXT-Changelog, TXT-Index und Uebergabeprotokoll synchronisiert.
- Kein Eingriff in Ollama-Optionen, Modelfile, Game-Ember, Datenbank oder persistente Projektplaene. Modell-Fortsetzungsgrenzen aus Issue #102 bleiben offen. Live-Abnahme mit Netcup-Installer steht aus.

## 0.5.63 - 26.10.2026

- **Installations-Hotfix:** Der bei der Netcup-Installation sichtbare Syntaxfehler in `scripts/source-preview-selftest.php` wurde behoben. Der statische Teststring enthielt eine unmaskierte interpolierte PHP-Variable mit Array-Index und verursachte einen Parse Error.
- Der Teststring ist nun korrekt maskiert. Lokaler `php -l` unter PHP 8.4 bestaetigt syntaktisch gueltige Datei; End-to-End-Validierung unter Netcup/PHP 8.5 bleibt ausstehend.
- Numerische Versionsangaben 0.5.63 und der inhaltsbasierte Cache-Key fuer `js/console-gate.js` sind in allen sechs HTML-Oberflaechen synchronisiert. Kein Eingriff in Ollama, CoreAI-Modelfile, Datenbank, Modell-Queue oder private Projektdateien.
- Issue #102 (mehrteiliger Projektbau) bleibt offen; dieser Hotfix behebt ausschliesslich den Installer-Blocker.

## 0.5.62 - 26.10.2026

- Neuer fest integrierter und unabschaltbarer System-Skill `skills/werkzeuge/SKILL.md`. Mit `required: true` im Katalog serverseitig bei jedem privaten CoreAI-Console-Turn aktiviert, nicht im Game-Ember-Kanal; auch wenn die Skills-Datenbankmigration fehlt oder ein altes Konto den Skill deaktiviert gespeichert hatte.
- Der Skills-Endpunkt verweigert `werkzeuge=false`. Backend-Logik ignoriert die Pflichtanleitung bei der Auswahl der vier optionalen Pakete, laedt sie jedoch selbst bei fokussierten Quellcode-Unteraufrufen. Keine neuen Werkzeugrechte oder unautorisierte Modellparameter.
- Skills-Einstellungen: ein sichtbar eingeschalteter, ausgegrauter Werkzeuge-Schalter mit Systemhinweis. Bulk-Aktionen und gespeicherte Vorlieben betreffen nur optionale Pakete.
- Zehn Fach-Skills fuer Projektplanung, Codebearbeitung, Debugging, ZIP, Jobs, SSE, Diagnose, Ollama, Skill-Autoren und Releases an den tatsaechlichen Stand der CoreAI-Quelle angepasst. Insgesamt 33 Pakete. Beschreibungen machen offene Checkpoint-/Agentenprobleme deutlich, anstatt eine bereits geloeste Funktion vorzugeben.
- Tests fuer Backend-Pflichtregel, Cross-Account-Isolation, API-Manipulation, fokussierte Modellaufrufe und UI-Pflichtschalter aktualisiert. Die vorgeschriebenen numerischen Cache-Keys, Versionstexte, Changelog und Uebergabe wurden vorbereitet.
- Chat-UI aus dem vorherigen Draft #105 in 0.5.62 integriert: natuerliche fortlaufende Arbeitstexte ohne wiederholte Labels/Infokarten, tatsaechlich gestreamtes Source-Preview-Fenster unterhalb des Arbeitswegs, UTF-8-sichere Quelltext-Chunks und neue UI-Regressionen. Fehlerhafte Preview-Initialisierung aus der ersten Draft-Version wurde in die richtige Ollama-Funktion verschoben.
- Abgeschnittene Modellstreams werden nicht mehr irrefuehrend als gruen bestaetigter Erfolg markiert. Die Quelle eines `done_reason=length` und die maximalen 2/4 Fortsetzungen sind weiterhin im Runner zu analysieren (Issue #102).
- Keine Aenderung am Betreiber-Modelfile, Ollama-Optionen, der seriellen Queue, privaten Projektplaenen, der Datenbankstruktur oder dem Python-Worker. Issue #102 (12k-Plan/9-Dateien-Limits und fehlende persistente Datei-Checkpoints) bleibt ungelöst. Netcup-/Android-Liveabnahme und kompletter 10-Seiten-Build noch ausstehend; keine Erfolgsmeldung ueber nicht gelaufene Tests.

## 0.5.61 - 26.10.2026

- Umfassendes Uebergabeprotokoll fuer den Chatwechsel: `docs/UEBERGABEPROTOKOLL_0_5_61.txt`, plus aktualisierter `docs/UEBERGABEPROTOKOLL_AKTUELL.txt`-Zeiger.
- 0.5.60-Source-Implementierung bestaetigt: nacheinander live geschriebenes Worklog und inerte HTML/CSS-Fragmentfortsetzung. Netcup-/Android-Liveabnahme noch offen.
- Hauptproblem Issue #102 und Kontext der gescheiterten grossen Homepage-Builds (Plan-/Quelldateilimits, Ausgabeabbrueche, fehlende persistente File-Checkpoints) fuer naechsten Chat festgehalten. Keinen Erfolg oder ZIP behauptet.
- Nur wegen der zwingenden Repo-Versionierung: sechs HTML-Versionsanzeigen, `VERSION`, JS-Bootstrap inklusive inhaltsbasiertem numerischem Cache-Key, Branding-Check, Preflight-Kommentar, README, Changelog/TXT-Archiv synchronisiert. Kein neuer Agenten-/Datei-Funktionscode, keine Modell- oder Datenbankaenderung.
- GitHub Actions nicht gestartet; vollstaendiger Server-Preflight nicht ausgefuehrt.

## 0.5.60 - 26.10.2026

- Core Channel Arbeitsweg: Neue echte SSE-Arbeitsschritte werden visuell geordnet eingereiht. Jede bestaetigte oeffentliche Ereigniserklaerung wird im eigenen Block sichtbar geschrieben; erst nach ihrem Abschluss erscheint der naechste Block. Eine gleichzeitig angelieferte Vierergruppe wird nicht mehr auf einen Schlag angezeigt.
- Der Ablauf selbst wird nicht verzoegert: Modellantworten, Werkzeugaktionen und die Datenbankspeicherung bleiben unveraendert. Nur die Benutzeransicht wird animiert. Gespeicherte Historie wird auch nach Reload sofort vollstaendig gezeigt.
- Bei manuellem Einklappen, verborgenem Tab oder eingeschalteter Betriebssystemoption fuer reduzierte Animation werden ausstehende Ereignisse unverzueglich vollstaendig eingeblendet; kein Schritt geht verloren und technische Details bleiben erhalten.
- In der nativen Source-Only-Route werden abgeschnittene HTML- und CSS-Dateiausgaben bei Ollama `done_reason=length` ebenso wie JavaScript als inerte Quelltextfragmente fortgesetzt, anstatt die erste Teilantwort wegzuwerfen. Exakte Leerzeichen, schliessende Klammern und wiederholte Suffixe werden beim Zusammensetzen geprueft. Fertige Dateien brauchen weiter die bestehende Validierung, bevor ein ZIP freigegeben wird.
- Der Worklog nennt fuer fortgesetzte Quelldateien nun den `done_reason`, die Dateinamen, Tokenzaehler und den tatsaechlichen Ergebnisstatus. Keine erfundenen Mara-Gedanken, keine rohen Denkspuren.
- Neue Offline-Regressionstests fuer SSE-Reihenfolge, reduzierte Bewegung, History-Reload, Teilfragmente, sicherheitsrelevante Marker, Ueberschneidungen und HTML/CSS-Verifizierung.
- Keine Aenderung am E.M.B.E.R.-Modelfile, Ollama-Optionen, MariaDB-Schema, Anwenderdateien, Projektplan oder der einen seriellen Modellwarteschlange. GitHub Actions nicht gestartet.
- **Noch offen:** 12.000-Byte-Plan-Grenze, Neun-Dateien-Manifestgrenze, per-Request-Fortsetzungsbudget und fehlende dauerhafte Datei-Checkpoints aus Issue #102. Dieser Release ist kein Nachweis fuer eine fertig erzeugte Zehn-Seiten-Homepage oder einen erfolgreichen Netcup-Livetest.

## 0.5.59 - 26.10.2026

- Einstellungen: Veraltetes PROJECT STΛRLIɢHT aus Herausgeber-Info entfernt. QUANTUM SOFTWARE ist eigene Familienzeile oberhalb COREAI: TCI INTERFACE.
- Lesbares Copyright in allen sechs internen Oberflaechen: 2023-2026 Patrick Schildgen und Starlight Unit Studios. Sichtbarkeit auf Mobilgeraeten durch groessere Schrift, mehr Kontrast und Umbruch verbessert.
- Chat-Seitentitel und SVG-Beschriftung von veralteter Projektbenennung bereinigt.
- COPYRIGHT.md, NOTICE.md und Copyright-Kopfzeilen der bestehenden deutsch-/englischsprachigen Lizenz nachgezogen, ohne historische Lizenznamen oder Rechtsbedingungen zu veraendern.
- Branding-Selbsttest prueft die namentliche Copyright-Nennung auf allen sechs Seiten und die getrennte Quantum-/Produktzeile.
- Version, Browser-Bootstrap und numerische Content-Cache-Schluessel fuer CSS/JS aktualisiert. Externe Homepage, Modellkonfiguration, Ollama und gespeicherte Daten unveraendert.
- GitHub Quellstand; Netcup-/Android-Liveabnahme offen. Keine GitHub Actions gestartet.

## 0.5.58 - 26.10.2026

- Netcup-Installation 0.5.57 brach beim Datenbankimport ab: scripts/install.sh:664 meldete scripts/migrate.sh: Keine Berechtigung. Git-Mode der Migration war 100644.
- Git-Dateimodus von scripts/migrate.sh auf 100755 umgestellt; setup.sh und scripts/update-from-main.sh setzen Ausfuehrungsrechte vor der Installation. Der Migrationsaufruf in install.sh erfolgt nun explizit via bash, selbst wenn Altdateien nicht executable sind.
- Offline-Regressionspruefungen fuer 0644-Migrationen und Setup-Neuinstallation/Update ergaenzt sowie in beide Preflights eingebunden.
- Version 0.5.58 in HTML, JavaScript-Bootstrap, Testkonfiguration und Changelog dokumentiert; SHA-256-basierter JS-Cache-Key aktualisiert.
- Keine Modelfile-, Ollama-, SQL-Migrations-, Datenbank-, Privatdatei-, Tool- oder Hosting-Aenderungen. GitHub Actions nicht gestartet. Echte Netcup-Liveabnahme bleibt offen.

## 0.5.57 - 26.10.2026

- Offizieller Produktname ab jetzt **CoreAI: TCI Interface**; **Quantum** ist die uebergeordnete Softwarefamilie und nur als untergeordnete Produktfamilienangabe sichtbar. Die bisherige Produktidentitaet E.M.B.E.R. CoreUI wird historisch dokumentiert.
- Repository umbenannt nach `Starlight-Unit-Studio/CoreAI-TCI-Interface`; der bestehende Setup-Downloader verweist auf den neuen GitHub-Pfad. Die bisherigen Netcup-Domain-, Installations- und Datenpfade bleiben vorlaeufig als technische Kompatibilitaetskennungen erhalten.
- Die sechs Projekt-Oberflaechen, SEO-/OpenGraph-/JSON-LD-Produktmetadaten, Branding, Familienhinweis, About-Dialog, JavaScript-Ausgaben, Servertexte, Installationsmeldungen und die Mail-Absender-Standardbezeichnung wurden nachgezogen. Responsive Produkttitel und SHA-256-basierte numerische Asset-Keys aktualisiert.
- Rechtliche Produkt- und Markenhinweise mit Namensfortfuehrung aktualisiert, ohne historische Lizenz-/Releases zu ueberschreiben. Die urspruengliche Community Source Lizenz 1.0 und ihre inhaltlichen Nutzungsbedingungen bleiben bestehen.
- Bestandsdaten, Mara-Identitaet, E.M.B.E.R.-Framework, Ollama-Modelfile, `coreui`-Routen/Umgebungsvariablen, Docker/DB-Namen, Queue, Agenten- und Dateifunktionen bleiben unveraendert. Keine Datenmigration und keine Umstellung der externen Homepage.
- Full-Netcup-/Compose-Preflight und visuelle Android-Abnahme weiterhin offen. Dieses GitHub-Source-Update ist kein Live-Deploy.

## 0.5.56 - 26.10.2026

- Modellformulierter Fortschritt (optional) als kurze, eigenstaendig gespeicherte `[COREUI_PUBLIC_PROGRESS]`-Zeile vor einem verifizierbaren Native-Tool-Block. Die Markierung wird vor dem eigentlichen Werkzeugparser entfernt, die Autorisierung und Quelle bleiben unveraendert; kein zweiter Modellaufruf.
- Ereignisbasierte Statusnotizen werden klar als automatisch erlaeutert gekennzeichnet, echte model.content-Zwischenmeldungen getrennt als Maras oeffentliche Meldung. Keine Roh-Gedanken oder Prompts, verdachtsfaellige Notizen werden verworfen.
- Korrigierter Aufklapp-Pfeil, eng begrenzte oeffentliche Textfilter, PHP-/UI-Regressionspruefungen. Volle Netcup-/Mobil-Abnahme steht noch aus.

- CoreUI zeigt jetzt oeffentliche, in der Ich-Form formulierte Arbeitsnotizen zu tatsaechlich protokollierten Schritten an. Konkret sichtbar werden gerade laufende Aktionen, unvollstaendige Dateien, Fehlversuche, Folgeaufrufe und der jeweils belegte naechste Arbeitsschritt.
- Die Notizen sind bewusst als nachvollziehbare Ausfuehrungserklaerungen gekennzeichnet, nicht als angebliche rohe interne Modell-Gedankenkette. Unbekannte Events erhalten keine erfundenen Erklaerungen. Texte werden ausschliesslich als textContent dargestellt.
- Ausgefuehrte Modell- und Werkzeugaktionen bleiben inklusive Zeiten, Status, Fehlergrund und einzeln aufklappbaren Diagnosen in der vorhandenen kontogebundenen Work-Timeline erhalten. Weitere harmlose Zaehler (z.B. done_reason, thinking_chars, content_chars, Fortsetzungen, Planversion) werden ueber die bestehende serverseitige Whitelist freigegeben; Roh-Thinking, Prompts, Modellcode und stderr bleiben verborgen.
- Live-Verlauf bleibt waehrend der Ausfuehrung offen; nach Abschluss klappt die Ansicht standardmaessig zu, zeigt aber die letzte relevante Notiz und Anzahl Schritte/Werkzeuge/Fehler. Benutzerseitige Aufklappwahl bleibt bei sitzungsinternem Neurendering erhalten, alte Server-Events bleiben auch nach Reload einsehbar.
- CSS fuer breite und mobile Ansichten angepasst: gut lesbare Notizen, kompakte Statistik und scrollbarer Detailverlauf. UI- und PHP-Whitelist-Regressionstests erweitert.
- Die sechs aktiven Oberflaechen und der Bootstrap auf 0.5.56 aktualisiert. Alle referenzierten JS-/CSS-Cache-Keys aus den echten SHA-256-Dateiinhalten neu abgeglichen, nicht aus Versionsnummern geraten.
- Modell-/Ollama-Parameter, Modelfile, Python-/ZIP-/Tool-Ausfuehrung, private Speicher, MySQL-Schema, Installer und serielle Warteschlange unveraendert. Keine GitHub Actions. Vollstaendige Netcup-Preflight-/Mobil-Abnahme noch offen.

## 0.5.55 - 26.10.2026

- Netcup-Installer 0.5.54 scheiterte beim PHP-Syntaxcheck in `scripts/local-file-tool-selftest.php`.
- Grund: Der doppelt zitierte Test-String interpolierte versehentlich `$fileToolCandidate['kind']`. Das Dollarzeichen wird nun escapet; die gepruefte Guard-Zeichenfolge bleibt identisch.
- Vorher/Nachher mit PHP 8.4 Lint isoliert reproduziert und korrigierte Syntax bestaetigt. Vollstaendiger Server-Preflight und Homepage-ZIP-Abnahme noch offen.
- Versionsmetadaten und Browser-Bootstrap aktualisiert. Modelfile, Ollama-Parameter, Installer, gespeicherte Plaene und Source-First-Build unveraendert. Keine GitHub Actions gestartet.

## 0.5.54 - 26.10.2026

- Netcup-Regression: Beim freigegebenen Homepage-Build endete Python-Job 108 mit `SyntaxError: unterminated triple-quoted string literal`. Ein anschliessender Modell-Folgeaufruf meldete nur die Absicht zur Korrektur, ohne ein Projekt-ZIP bereitzustellen.
- Freigegebene Native-Projekt-Builds erzwingen jetzt den bestehenden sicheren Einzeldatei-Modus: Modell generiert HTML/CSS/JS als inerte Quellen, CoreUI schreibt und prueft das ZIP mit dem deterministischen Base64-Writer. Ein langes modellgeschriebenes Python-`[PY]`-Schreibskript wird fuer diesen Build-Typ nicht mehr ausgefuehrt.
- Die serverseitige Source-Only-Autorisierung wird ausschliesslich nach geprueften Plan-/Tool-Guards aktiviert und in `finally` zurueckgesetzt. Sie funktioniert modellnamenunabhaengig auch mit umbenanntem lokalen Gemma, Qwen und Cloud-Tags. Gewoehnliche Python-Auftraege behalten ihren alten Weg.
- Ein ungueltiges Projektmanifest wird ohne unsicheren Fallback abgelehnt. Worklog-Grund `native_source_only_route` kennzeichnet die sichere Route; separate Events zeigen die Einzeldateien und ihren jeweiligen Status.
- Regressionstests fuer Live-Syntaxfehler, Triple-Quotes als inerte Quelldaten, Modell-Aliasse, Quelltext-Routing, privaten Build-Kontext, Einmal-Dispatch und ZIP-Integritaet in den schon vorhandenen Preflight-Tests ergaenzt.
- 0.5.53-Countdown-Entfernung bleibt erhalten. Modelfile.template, Ollama-Parameter, Datenbankmigrationen, Installer, bestehender Python-Worker und ZIP-Writer unveraendert. Vollstaendige Netcup-Abnahme noch offen.

## 0.5.53 - 26.10.2026

- Entfernt den historischen, fuer E.M.B.E.R. CoreUI irrelevanten 310-Sekunden-Countdown oberhalb des Chat-Eingabebereichs. Die Anzeige zaehlte nur im Browser herunter, stoppte keine laufende Modellantwort und war kein echter 307-/310-Sekunden-Server-Timeout.
- `MAX_WAIT_SEC`, `startCountdown()`, `stopCountdown()`, die UI-State-Felder, die 12 Aufrufstellen und die CSS-Regeln `.ember-countdown` sowie `countdown-pulse` entfernt.
- Die normalen Arbeitsweg-/Thinking-/Typing-Anzeigen, modellbezogenen Antwortzeiten, Statusmeldungen, Sessions, Polling, SSE-Watchdog und tatsaechlichen Backend-Timeouts bleiben unveraendert.
- `scripts/frontend-regression-selftest.php` prueft den Wegfall des Countdowns und die weiter vorhandenen Stream-/Timing-/Polling-Komponenten. Bestehende Preflight-Varianten fuehren diesen Test bereits aus; keine neue CI-Abhaengigkeit.
- Neue Browser-Cache-Schluessel fuer Chat-JavaScript und Haupt-CSS, sechs Versionsanzeigen aktualisiert.
- Modelfile.template, Ollama-Parameter, 0.5.51-Builder, Planungsengine, Installer, SQL, Python-/ZIP-Worker und Datenbank unveraendert. Vollstaendige Netcup-Preflight-Abnahme nach Update bleibt offen.

## 0.5.52 - 26.10.2026

- Hotfix fuer den realen 0.5.51 Compose-Preflight-Abbruch vor den tieferen Tests: `Thinking-Schalter oder Mehrfachanhaenge sind unvollstaendig paketiert`.
- Ursache: Beide Preflight-Skripte verglichen einen historischen Ollama-Payload-Ausdruck wortwoertlich. 0.5.51 verwendet stattdessen die bereits getestete und gueltige Think-Override-Bindung `$thinkOverride ?? ember_thinking_enabled()`. Die alte Zeichenfolge war folgerichtig nicht mehr vorhanden.
- In `scripts/preflight.sh` und `scripts/preflight-native.sh` jetzt toleranterer, aber auf das `think`-Payloadfeld und die kontobezogene Defaultfunktion begrenzter Ausdruckstest.
- Thinking- und Zehn-Anhaenge-Paketierung getrennt geprueft und getrennt diagnostiziert. Das eigentliche Override-Verhalten kontrolliert weiterhin `scripts/reply-pipeline-selftest.php`.
- Fix lokal per Bash-Grep-Reproduktion mit altem, neuem und ungueltigem Payload-Ausdruck plausibilisiert. Vollstaendiger Netcup-Preflight nach Aktualisierung noch ausstehend.
- Modelfile.template, Ollama-Parameter, normale Modellgenerierung, 0.5.51-Projekt-Dispatch, SQL, Installer, Python-/ZIP-Writer und persistierte Nutzerdateien unveraendert. Keine GitHub Actions ausgefuehrt.

## 0.5.51 - 25.10.2026

- Fehlerbehebung fuer "Lass mal den Plan umsetzen": 0.5.50 erzeugte statt Projektdateien nur 33 Zeichen Modelltext und danach `continuation_limit`. Der native Build wurde mangels erkanntem Modellwerkzeug gar nicht aufgerufen.
- Neue kurze, **modellgesteuerte** Werkzeugentscheidung bei vorhandenem vollstaendigem Privatplan vor dem grossen Antwortlauf. Dasselbe konfigurierte Modell entscheidet ohne User-Keyword-Router, ob START_BUILD oder normaler CHAT. Nur eine vollstaendig bestaetigte Modellwahl darf den vorhandenen geschuetzten `PROJECT_BUILD`-Pfad ausfuehren. Thinking ist ausschliesslich fuer diese kurze Entscheidung deaktiviert, die urspruengliche Modellkonfiguration bleibt bestehen.
- Sync- und SSE-Routen identisch angebunden; vorhandener Native-Tool-/Datei-/ZIP-Writer wird erneut verwendet, keine parallelen Modellaufrufe und kein zweiter Projektbau bei Wiederaufrufen desselben Turns.
- Projekt-Build kann nun bis zu neun explizite sichere Quelldateien aus dem gespeicherten Plan uebernehmen. Sechs HTML-Unterseiten plus CSS und JS werden nicht auf ein dreiteiliges Standardpaket reduziert. Der Plan wird bis 12000 Bytes vollstaendig uebergeben und niemals still beschnitten; Fehler wegen zu vieler Dateien/grossem Plan werden konkret benannt.
- Weitere datensparsame Ollama-Arbeitsereignisse: done_reason, eval_count, prompt_eval_count, Thinking-/Textlaenge und Fortsetzungszaehler. Der 33-Zeichen-Abbruch kann so kuenftig ohne private Modelltexte genauer eingegrenzt werden.
- Neuer `scripts/model-project-intent-selftest.php` mit simulierter Modellentscheidung, SQL-Fixures, Kontotrennung, abgebrochenen Antworten und verifiziertem Sechs-Seiten-ZIP-Auftrag. Beide Preflight-Varianten und bisherige SSE-/Laengentests synchronisiert.
- Betreiber-Modelfile, Ollama-Optionspolitik, SQL-Migrationen, Installer, Python-Sandbox und eigentlicher ZIP-Writer unveraendert. Vollstaendige Netcup-Preflight- und Live-Gemma-Abnahme noch offen.

## 0.5.50 - 25.10.2026

- Gespeicherte Markdown-Dateien (`.md`, einschliesslich aller privaten Projektplan-Versionen) werden direkt unter Maras Antwort als scrollbare, sicher gerenderte Vorschau gezeigt. Andere Dateitypen behalten ihre bewaehrten Downloadkarten.
- Drei Aktionen oben rechts: Markdown-Inhalt kopieren, Originaldatei herunterladen, Vorschau gross anzeigen; mobile Bedienung, Tastaturfokus und Statusmeldungen integriert.
- Bestehende authentifizierte Plan-/Artifact-Endpunkte unveraendert wiederverwendet. Dateiabfrage nur gleichurspruenglich, ohne Cache oder Redirect, mit Bytegroessen-, MIME- und optionaler SHA256-Pruefung. Kein aktives HTML und kein externer Viewer.
- Der interne Entwurfsmarker wird nur in der Ansicht ausgeblendet und als unvollstaendiger Entwurf angezeigt. Originaldownload bleibt byteidentisch; bei Kopieren wird nutzbarer, sichtbarer Markdown-Text verwendet.
- Vorschauen werden bedarfsweise fuer sichtbare Chatnachrichten geladen. Bei fehlender oder zu grosser Datei bleibt der vorherige sichere Downloadweg erhalten; neu laden innerhalb der Vorschau moeglich.
- Eigener Frontend-Vertragstest fuer sicheren Viewer und unveraenderte API-Downloads in beiden Preflight-Varianten. Browser-Interaktionsprobe und JavaScript-Syntaxpruefung isoliert durchgefuehrt. Netcup-/Android-Livetest steht aus.
- Betreiber-Modelfile, Ollama-Optionen, Modell-FIFO, Plan-Speicherung, SQL-Migrationen, Installer, Python-Worker und ZIP-Erzeugung unveraendert.

## 0.5.49 - 25.10.2026

- Behebt eine Parserluecke bei der Wiederaufnahme schon gespeicherter Markdown-Planentwuerfe: ein einzelner, vom Modell gewaehlter PLAN_CONTINUE-Marker wird jetzt auch mit kurzer Einleitung, Slash-Schreibweise, fehlendem Abschluss oder zusaetzlichem Modelltext erkannt.
- Im Recovery-Fall wird ausschliesslich die neueste private, authentifizierte Planversion aus der Datenbank als Ausgangspunkt verwendet. Unbestaetigte Modelltexte werden nicht ungeprueft angehaengt, Python/Projekt-Build-Werkzeuge werden nicht gestartet.
- SSE- und Sync-Antwortweg umgehen die allgemeine lange Antwortfortsetzung fuer diese Marker und erlauben ein sicheres PLAN_CONTINUE selbst nach abgebrochener Ollama-Generation. Sicherheitsregeln fuer PROJECT_BUILD, PLAN_AND_BUILD und gemischte Marker bleiben streng.
- Technische Work-Events weisen eine tolerante Wiederherstellung oder einen unlesbaren Fortsetzungsmarker mit Groessenangabe aus, ohne Planinhalt zu loggen.
- Neue Regressionstests fuer offene und geschlossene Modellmarker, zusaetzliche Einleitungen, lange Markdown-Bloecke, Mehrfachmarker, eingebettete Code-Beispiele, gemischte Werkzeuge und das sichere Wiederaufnehmen bereits gespeicherter Plaene.
- Vollstaendiger Netcup-/Gemma-Livetest steht aus; der konkrete Rohtext des fehlgeschlagenen Turns ist nicht verfuegbar. Betreiber-Modelfile, Ollama-Sampler und die bestehenden Datei-/ZIP-Werkzeuge sind unveraendert.

## 0.5.48 - 25.10.2026

- Autonome, serielle Projektplanung: Bei einem Modell-gewaehlten offenen PLAN_SAVE-Block wird der sofort gespeicherte Markdown-Entwurf innerhalb desselben Turns in bis zu vier fokussierten Modellaufrufen weitergeschrieben. Nach jedem verwertbaren Abschnitt wird eine neue private, unveraenderliche .md-Version gesichert.
- Planungs-Fortsetzungen erhalten nur das aktuelle Arbeitsziel, bereits vorhandene Ueberschriften und ein kurzes Endfenster. Modell-Neustarts, wiederholte Abschnitte und unerwuenschte gemischte Werkzeugaktionen werden verworfen.
- Erst eine bestaetigte Modellantwort mit explizitem Abschlussmarker und grundlegender Markdown-Struktur macht den Plan fertig; andernfalls bleibt der bisherige Text als abrufbarer Entwurf erhalten.
- Neues modellwaehlbares PLAN_CONTINUE erlaubt es Mara, auch bereits gespeicherte Entwuerfe eines frueheren Chat-Turns in weiteren Versionen zu vervollstaendigen. Kein automatischer Build und kein Umgehen der kontogebundenen Downloads.
- Gleiche Engine in SSE- und synchronem Chatpfad; begrenzte fokussierte Modelltimeout-Option nur fuer die Fortsetzungsaufrufe. Keine Aenderung an Ollama-Modelfile oder Sampler-Optionen.
- Neuer Preflight-Selbsttest fuer Mehrschritt-Planungen, Guardrails, Versionierung, Integritaet und Wiederaufnahme. Antwortpipeline-Test an fokussierte Timeout-Form angepasst.
- Vollstaendige Netcup-/Gemma-Live-Abnahme steht aus. Modelfile.template unveraendert.

## 0.5.47 - 25.10.2026

- Frontend-Regressions-Selbsttest an die in 0.5.46 eingefuehrte Absicherung `!$nativeToolHandled` angepasst. Die vorherige starre Stringsuche meldete faelschlich den gesamten Mehrdateien-/Cloud-/Lokalpfad als defekt.
- Die 17 Mehrdateien-/Cloud-/Lokal-Teilbedingungen werden nun einzeln benannt und geprueft; ein zukuenftiger Fehler benennt die konkrete fehlende Schnittstelle.
- Die spaeteren Composer- und Live-Run-Wiederherstellungsbedingungen am aktuellen Repository abgeglichen; PHP-Fallback-Matcher isoliert mit positiven und negativen Faellen geprueft.
- Keine Aenderung an Runtime, dateierzeugenden Funktionen, Ollama-Parametern, Installer, Python-Sandbox oder config/Modelfile.template. Netcup-Preflight steht nach Deployment aus.

## 0.5.46 - 25.10.2026

- Maras Modellwahl `[COREUI/PLANSAVE]` (auch mit kurzer Einleitung) wird als Planungswerkzeug erkannt. Ein fehlender Abschluss wird nicht zu einem fertigen Plan umgedeutet, sondern als explizit unvollstaendiger privater Markdown-Entwurf versioniert.
- Entwuerfe bleiben als Download verfuegbar und sind niemals fuer den statischen Projekt-Builder freigegeben. Vollstaendige bisherige PLAN_SAVE/PLAN_APPEND/PLAN_AND_BUILD-/PROJECT_BUILD-Protokolle bleiben erhalten.
- Sync und SSE setzen laengere Antworten mit einem kurzen hinteren Kontextfenster fort; grosse Textueberlappungen werden zusammengefuehrt, wiederholte Modell-Neustarts nicht doppelt angehaengt.
- Bei verbleibendem Tokenlimit wird die Ausgabe als unvollstaendig kenntlich gemacht statt still als abgeschlossen im Chat zu erscheinen. Regressionen fuer Screenshot-Planmarker, SQL-Draft-Speicherung, Build-Sperre, Markdown-Fortsetzung und begrenzten Prompt ergaenzt.
- Modelfile, Ollama-Parameter, Installer/One-Liner, Runtime-FIFO, Migrationen, Python-Worker und bestehende Download-Vertraege bleiben unveraendert. Vollstaendige Netcup-E4B-Planungsabnahme noch offen.

## 0.5.45 - 25.10.2026

- Gemma-Preflight prueft die vom Betreiber nach 0.5.44 aktualisierte aktive Modelfile-Vorlage statt der archivierten E2B-SYSTEM-Passage und der damaligen festgelegten Vier-Parameter-Reihenfolge.
- Syntax und numerische Werte der aktiven Parameter, optionales generiertes Modelfile und weiterhin fehlende API-Overrides fuer lokale Gemma-Modelle werden kontrolliert. Das archivierte E2B-Original bleibt unveraendert pruefbar.
- Branding-Selbsttest und Native-/Compose-Diagnose aktualisiert. Konkrete Gemma-Testfehler werden ausgegeben statt verschluckt.
- config/Modelfile.template, Installer, One-Liner, Ollama-Runtime, FIFO, Dateisandbox und Tool-System unveraendert. E4B-/Netcup-Liveabnahme offen.

## 0.5.44 - 25.10.2026

- Drei 0.5.43-Preflight-Regressionen korrigiert: zwei veraltete PROJECT_BUILD-Testformulierungen und ein fehlender Funktionsimport im isolierten Datei-Selbsttest.
- Compose- und Native-Preflight zeigen jetzt bei Datei-Selbsttestfehlern die reale PHP-Ausgabe.
- Original-Mara-Modelfile, Ollama-Optionen, FIFO, Installer und produktive Projektpipeline unveraendert; Netcup/E4B-Livetest weiter ausstehend.
- Uebergabeprotokoll: docs/UEBERGABEPROTOKOLL_0_5_44.txt.

## 0.5.43 - 25.10.2026

- Aktive Mara-Vorlage auf den vollstaendigen vom Betreiber bereitgestellten SYSTEM-Text zurueckgestellt. Nur portable FROM-Basis und Ollama-konforme dreifache SYSTEM-Anfuehrung statt ollama-show-Blobpfaden. Vier Originalparameter, kein num_predict -1, kein num_ctx.
- Modelfile-Regressionstest und Aenderungsrichtlinie angepasst; vorhandene 0.5.42-Leerausgabe-Diagnose, gezieltes think:true bei HTTP-200-Leerantwort und Modell-Live-Audit bleiben erhalten.
- Installer, One-Liner, Modellaufbaureihenfolge und Laufzeit-Queue nicht veraendert. Produktiven Modell-Rebuild und echten E4B-Lauf nach Update verifizieren.

## 0.5.42 - 25.10.2026

- **Leere E4B-Dateiausgabe diagnostizieren:** Das bisherige `model_response_missing` verdeckte Transport-Timeout, Warteschlangenfehler, HTTP-Fehler und erfolgreiche Ollama-Antworten mit leerem `message.content`. CoreUI klassifiziert diese Faelle getrennt und zeigt sicheren HTTP-Status, Laufzeit sowie Tokenzaehler im bestehenden Arbeitsverlauf. Kein Rohprompt oder Modell-Denkinhalt in den Events.
- **Keine identischen 600-s-Fehlversuche:** Nach Transport-Timeout/HTTP-/Warteschlangenfehler erfolgt keine blinde Wiederholung derselben Dateianfrage. Nur bei HTTP 200 mit leerem Inhalt probiert lokales Gemma einmal gezielt `think:true`, um einen bekannten Leerausgabe-Fall zu umgehen. Die normale Thinking-Einstellung wird nicht global veraendert.
- **Kleinere Folgeprompts:** Bei isolierter HTML/CSS/JS-Quelldateierzeugung wird der zuvor im Hauptauftrag geladene Skillkatalog nicht noch einmal als kompletter Anweisungstext angehaengt. Der bereits vorhandene Quelldatei-Vertrag bleibt erhalten. Das native Runtime-Werkzeugprotokoll ist um etwa ein Drittel kuerzer. Normale Skills und die vier Modellwerkzeuge bleiben im Chat nutzbar.
- **Tatsaechlich geladenes Mara-Modell pruefen:** Der neue rein lesende `scripts/model-live-audit.php` vergleicht via Ollama `/api/show` den geladenen SYSTEM-Text mit `config/Modelfile.template` (SHA256 und Zeichenlaenge, ohne den privaten Text auszugeben). Beide bestehenden Preflights pruefen das aktive Modell nach der vorhandenen Modell-Existenzkontrolle sowie die Audit-Funktion offline. Eine Abweichung wird klar angezeigt; kein automatisches `ollama create` durch den Audit.
- **Aktuelle kurze Vorlage respektiert:** Auf `main` wurde `config/Modelfile.template` parallel auf 1717 Zeichen verkuerzt und `num_predict -1` als fuenfter Parameter ergaenzt. Dieser Fix aendert die neue Modelfile **nicht**. Der Gemma-Selbsttest ist an die aktuelle Fassung angepasst; der historische E2B-Export bleibt separat unveraendert. Ollama-API-Tuning, One-Liner, Installer, Installationsreihenfolge, SQL-Migrationen und serielle FIFO-Warteschlange bleiben unangetastet. Ob auf Netcup derselbe SYSTEM-Text aktiv ist, zeigt erst der Live-Audit.
- **Tests und Grenzen:** Ergaenzte Offline-Vertragstests fuer Timeout/HTTP-Fehler/leere Antworten und begrenzten Retry. Der reale Netcup-Fehler konnte nicht per Ollama-Liveaufruf reproduziert werden. Ein defektes Modell oder echter Generationstimeout ist damit nicht automatisch behoben. Keine GitHub Actions ausgefuehrt.

## 0.5.41 - 25.10.2026

- **Echten Parserfehler beim modellgesteuerten Projekt-Writer behoben:** Der vorherige Dateinamen-Abschluss `(?![A-Za-z0-9._-])` hat einen letzten Dateinamen wie `main.js.` wegen des folgenden Satzpunkts verworfen. Der gesicherte `PROJECT_BUILD`-Auftrag `index.html, style.css, main.js. Erstelle projekt.zip.` kam deshalb nur mit zwei statt drei Quelldateien beim Worker an. Genau das erklaert den nach 0.5.40 gemeldeten Preflight-Fehler `Worker did not get exactly the validated static source manifest`.
- **Korrigierte Quelle statt deaktivierter Prüfung:** `api/console_data_link_bridge.php` unterscheidet jetzt Satzzeichen von echten zusätzlichen Endungen. `main.js.` und `homepage.zip.` werden korrekt erkannt; `main.js.backup` und `homepage.zip.tmp` dürfen weiterhin nicht als die kürzeren Dateinamen durchgehen. `api/console_native_tools.php` trennt die serverseitige Pflichtdateiliste zusätzlich per Zeilenumbruch statt eines nachgestellten Satzpunkts.
- **Regressionen:** Die tatsächliche Projektfreigabe-Prüfung `scripts/contextual-project-selftest.php` und der eigenständig laufende `scripts/local-file-tool-selftest.php` prüfen die Dateiliste, ZIP-Namen, ZIP-Worker-Erkennung und unerlaubte Zusatzendungen. Ein isolierter PHP-Test mit dem identischen Parser-/Auftragsformat konnte die alte Fehlstelle reproduzieren und die Korrektur bestätigen.
- **Keine Änderungen** an Mara-Modelfile, Tool-Auswahl, Ollama-Optionen, Installer, One-Liner, Datenbank, Projekt-Sandbox oder serieller Modell-FIFO. Der Netcup-Preflight und der vollständige E4B-Dateiausgabetest stehen weiterhin als Live-Abnahme aus. GitHub Actions wurden nicht gestartet.

## 0.5.40 - 25.10.2026

- **Preflight-Fehlalarm bei der Projektfreigabe beseitigt:** Der bisherige Test `scripts/contextual-project-selftest.php` pruefte immer noch den seit Version 0.5.39 nicht mehr benutzten, wortlistenbasierten Projektadapter aus 0.5.38. Welche der alten Einzelpruefungen scheiterte, war wegen unterdrueckter Testausgabe nicht feststellbar. Der alte Test war jedenfalls kein gueltiger Nachweis fuer den seit 0.5.39 produktiven Modellwerkzeugpfad. Der Ersatz prueft jetzt ausschliesslich den tatsaechlichen modellgesteuerten Werkzeugpfad.
- **Neue wirksame Regression:** Ein vom Modell erzeugtes `[COREUI_PROJECT_BUILD]` wird gelesen, mithilfe eines gespeicherten Markdown-Projektplans in einen vollstaendigen Dateiauftrag umgesetzt und an den bestehenden, sicheren HTML/CSS/JS- und ZIP-Generator uebergeben. Tests umfassen echte SQLite-Planabfragen (wenn verfuegbar), Download-Manifest, auf einen Aufruf begrenzte Ausfuehrung, Rekursionsschutz, Sitzungskontext, Fremdaccount-Isolation, grosse Planungen und nicht unterstuetzte Frameworks.
- **Lesbare Fehlermeldungen:** Compose- und Native-Preflight zeigen bei Projekt-Testfehlern nun die genaue Assertion bzw. PHP-Fehlermeldung. Bisher wurde die Ausgabe durch `>/dev/null 2>&1` geschluckt.
- **Modell-Werkzeughinweis korrigiert:** Das `PROJECT_BUILD`-Beispiel hatte vor dem schliessenden Marker ein woertliches `\\n` statt eines echten Zeilenumbruchs. Der Runtime-Hinweis nutzt nun das richtige Format; der Native-Tools-Selbsttest prueft es.
- **Keine Architektur- oder Installationsaenderung:** Modellselektierte Werkzeuge, dauerhafte MD-Versionen, SQL-Migration 016, der bisherige Installer und One-Liner, Ollama-Parameter, das Betreiber-Modelfile und die serielle FIFO-Warteschlange bleiben unangetastet. Keine GitHub Actions. Live-Netcup-Preflight steht aus.

## 0.5.39 - 25.10.2026

- **Mara waehlt native Werkzeuge selbst:** Die bisherige fest eingebrannte Schluesselwort-Freigabe einer Homepage ist nicht mehr noetig. Das Modell kann im bestehenden privaten Chat selbst einen klar abgegrenzten Werkzeugblock ausgeben: `COREUI_PLAN_SAVE`, `COREUI_PLAN_APPEND`, `COREUI_PLAN_AND_BUILD` oder `COREUI_PROJECT_BUILD`. Der Server validiert und fuehrt genau einen Aufruf aus. Der Nutzer muss nicht jedes Mal "Homepage, ZIP, Download" schreiben. Alte explizite Datei-Auftraege bleiben kompatibel.
- **Dauerhafte Projektplanung:** Neue Migration `016_console_project_plans.sql` speichert Markdown-Versionen als unveraenderliche Records pro Nutzer und Chat. Neue Fassungen und Anhaenge erhalten eigene Versionen, die Originalinhalte bleiben erhalten. Jede vom Modell gesicherte Version wird als echte `.md`-Downloadkarte unter einer authentifizierten, hash-geprueften `api/console_plan.php`-Route sichtbar.
- **Plancontext ohne Modelfile-Ballast:** Die aktuelle Planung wird in den Runtime-Prompt als kleiner gekennzeichneter Kontext geladen; komplette Markdown-Inhalte bleiben serverseitig erhalten. Ein komplexer Plan, der zu gross fuer die derzeitige sichere Quellgenerierung ist, wird ausdruecklich abgewiesen und NICHT heimlich abgeschnitten. Die vollstaendige abschnittsweise Wiedereinlesung sehr grosser Plaene bleibt ein separater Ausbau.
- **Agent / ZIP:** Der Modell-Werkzeugaufruf `PROJECT_BUILD` erzeugt eine servereigene Datei-/ZIP-Aufgabe und startet die bisherige sichere Source-/Sandbox-Pipeline, nur sequenziell. Das Modell bekommt keine freie Ausfuehrung auf dem Host, keine beliebigen Dateipfade oder DB-/Shell-Rechte. Ein abgelehnter oder gescheiterter Build wird nicht als Download ausgegeben.
- **Preflight/Installer entblocken:** Der in 0.5.37 fehlerhaft gewordene Skills-PHP-String war bereits in 0.5.38 repariert; jetzt wird der PHP-Lint beim bestehenden Installationsschritt und beiden Preflights mit **Dateiname und originaler PHP-Fehlermeldung** ausgegeben statt `xargs: php exited 255`. Mehrere veraltete Tests auf den inzwischen ersetzten String/Stream-/Uploadpfad sind korrigiert. Kein neuer Installationsschritt.
- **Tests:** Neuer `scripts/native-tools-selftest.php` prueft Marker, sichere Modelleingaben, Datenbank-Sitzungs-/Kontotrennung, Markdown-Versionen und Auth-Download. In Compose und Native Preflight mit Schema-Check fuer Migration 016 eingebunden. Alle UI- und Javascript-Cache-Fingerprints auf 0.5.39 gesetzt. Keine GitHub Actions; echter Netcup-End-to-End-Test bleibt offen.
- **Unveraendert:** `config/Modelfile.template` und Mara-Persona, Ollama-Modelloptionen einschliesslich `num_ctx`/`num_predict`, One-Liner, Installationsreihenfolge, Modell-FIFO und SQL-Daten alter Versionen. Nur die Ausgabe des vorhandenen PHP-Syntaxchecks in `scripts/install.sh` wurde verbessert.

## 0.5.38 - 25.10.2026

- **Projekt-Agent aus dem Gespräch heraus starten:** Bei einer eindeutigen Umsetzungsfreigabe wie "Dann setz unseren Plan um", "Mach das Projekt fertig" oder "Okay, dann legen wir los" greift CoreUI jetzt auf die letzten, zur selben privaten Sitzung und demselben Nutzer gehörenden Planungsnachrichten zurück. Ein Freigabesatz braucht dadurch nicht erneut alle technischen Dateinamen, "ZIP" und "Download" zu enthalten.
- **Bereits vorhandene Werkzeuge verwenden:** Die serverseitig rekonstruierte Aufgabe wird als begrenzter, expliziter Datei-/ZIP-Auftrag an den bestehenden, isolierten Projekt- und ZIP-Agenten übergeben. HTML/CSS/JS stammen weiter aus vollständigen, einzeln validierten Modell-Quelldateien; der Server schreibt und prüft die Dateien. Der ursprüngliche Chatbeitrag wird nicht umgeschrieben, nur der aktuelle Ausführungsprompt.
- **Beide Ausführungswege:** Der SSE-Stream erkennt die Freigabe vor seinem Dateimodus-Gate und löst genau den bereits gespeicherten Turn über den gemeinsamen Werkzeugpfad aus; der synchrone Antwortweg besitzt dieselbe sitzungsgebundene Erkennung. Kein zusätzlicher LLM-Intent-Call, keine doppelte Inferenz und keine Veränderung der globalen FIFO-Warteschlange.
- **Vorsicht vor Fehlstarts:** Fragen, Planungswünsche, Entwurfsentscheidungen ("Dann machen wir das so"), ausdrückliche Negationen, Termine in der Zukunft sowie fremde oder nicht vorhandene Sitzungskontexte lösen keine automatische Dateierstellung aus. Bereits explizit benannte Dateiaufträge laufen unverändert über den bisherigen Weg.
- **Erster Geltungsbereich:** Statische Webseiten, Homepages, Landingpages und Browsergames; für komplexe Frameworks wie React, WordPress oder Backend-/SQL-Projekte wird nicht fälschlich ein statisches Dreier-ZIP erzeugt. Das ist noch keine universelle Agentenplanung für sämtliche Aufgabentypen.
- **Tests & Wartung:** Neuer `scripts/contextual-project-selftest.php` mit positiven und negativen Anfragen, Quellen- und ZIP-Protokollkontrolle sowie statischen Isolationchecks, in Compose- und Native-Preflight eingebunden. Zusätzlich beschädigtes Nginx-Stringliteral in `scripts/skills-selftest.php` korrigiert, das sonst einen PHP-Syntaxfehler verursacht. Modelfile, Ollama-Parameter, One-Liner, Installationsreihenfolge, Datenbank und Queue bleiben unverändert. Live-Netcup-Test offen, keine GitHub Actions.

## 0.5.37 - 25.10.2026

- **Vollstaendige Mara-Textantworten:** `ember_insert()` in `api/chat.php` schneidet Antworten im privaten CoreUI-Kanal nicht laenger am frueheren 60.000-Zeichen-Deckel ab. Die globale Spielchat-Schranke und der Sicherheitsfilter gegen interne Modellgedanken bleiben unveraendert.
- **Dauerhafte Speicherung:** Die reguläre neue Migration `015_long_chat_messages.sql` erweitert `stu_chat_messages.message` von `MEDIUMTEXT` auf `LONGTEXT`, ohne Daten oder Sitzungen zu verwerfen. Der bestehende Migrationsmechanismus erkennt die neue Datei automatisch; Installer, One-Liner und Reihenfolge bleiben unveraendert.
- **Grosse Texte einfuegen:** `app.html` verwendet ein mehrzeiliges, automatisch wachsendes Eingabefeld ohne hartes HTML-`maxlength`. Beim Einfuegen von mehr als ca. 8.000 Zeichen wird das **unveraenderte Original** als `eingefuegter-text-YYYY-MM-DD_HH-MM-SS.md` ueber die bestehende authentifizierte `console_upload.php`-Pipeline hochgeladen. Mehr als 12.000 Zeichen im Editor werden vor dem Senden ebenfalls als Markdown angehaengt. Die Datei erscheint sichtbar am normalen Nutzerbeitrag, auch nach dem Neuladen.
- Ein waehrend des Uploads angeforderter Versand erfolgt erst nach erfolgreicher Rueckmeldung des Servers. Bei Uploadfehlern kommt der komplette Ausgangstext in das Eingabefeld zurueck; bei voller Zehn-Anhang-Grenze gibt es einen expliziten Hinweis. `Shift+Enter` erzeugt Zeilenumbrueche, `Enter` sendet, IME-Komposition bleibt geschuetzt.
- **Kontextklarheit:** Der ganze hochgeladene Text bleibt als Datei abrufbar. Maras Anhang-Extraktor uebergibt weiterhin nur einen kenntlich gemachten Auszug nach dem bestehenden Kontextbudget. Das aendert weder `num_ctx` noch `num_predict`, sondern verhindert, dass Inhalte ohne Hinweis verloren gehen. Modell-/Ollama-eigene Tokenlimits und Zeitgrenzen gelten weiter.
- Regressionstests fuer private Antwortspeicherung, `LONGTEXT`-Schema und Markdown-Upload; optionale Node-Tests im bestehenden Preflight. Keine GitHub Actions ausgefuehrt, Live-Netcup-/Ollama-Abnahme steht aus.

## 0.5.36 - 25.10.2026

- **Skills wieder erreichbar:** Beide abgesicherten Nginx-Routenlisten enthielten zwar `api/user_settings.php`, aber nicht den seit 0.5.33 existierenden Endpunkt `api/user_skills.php`. Dieser erhielt deshalb HTTP 404. Die bestehende PHP-Freigabeliste wurde in `config/runtime/nginx.conf.template` (Compose) und `config/nginx.conf.template` (native Installation) nur um `user_skills` erweitert. Der Authentifizierungs- und CSRF-Schutz des Endpunkts bleibt unveraendert.
- Der Skills-Bildschirm zeigt jetzt die tatsaechliche Fehlerklasse (z.B. 404, 401, 500 oder fehlende Migration) statt pauschal eine abgelaufene Anmeldung zu behaupten. Ein neuer Regressionstest verhindert das erneute Vergessen des PHP-Endpunkts.
- **Chat nach Seitenwechsel:** Bereits laufende, einem Konto und einer Sitzung zugeordnete Agentenlaeufe werden aus der authentifizierten Nachrichten-History als aktive Arbeit rekonstruiert, einschliesslich der serverseitig freigegebenen Arbeitsschritte. Die UI kehrt zu ihrem Wartezustand zurueck, zeigt wieder den Verlauf und pollt nur die bestehende Antwort. Keine doppelte SSE-Generierung und kein zweiter Ollama-Aufruf.
- Ein kurzer Sende-POST darf mit `fetch keepalive` beim Wechsel in Einstellungen zu Ende gehen; groessere Bodies bleiben ohne keepalive. Ein noch nicht im History-Snapshot enthaltener, bereits bestaetigter Nutzer-Turn bleibt im Cache sichtbar. Fertiggestellte Antworten werden weiterhin aus der DB geladen.
- Backend-Querys sind user_id/session_id/turn_id/run_key-begrenzt; Arbeitsschritte nutzen die bestehende Public-Whitelist. Inaktive/alte Jobs werden nicht wiederhergestellt. Keine DB-Migration.
- PHP-/JavaScript-/Nginx-Quelltests sowie isolierte Rehydrationspruefung dokumentiert. Die produktive Netcup-Abnahme ist noch offen. Keine Aenderung an Modelfile, Ollama, Installer, One-Liner, serieller Warteschlange oder PR #69. GitHub Actions nicht gestartet.

## 0.5.35 - 25.10.2026

- **Aktive Modelfile-Korrektur:** `config/Modelfile.template` nutzt jetzt den SYSTEM-Text und die exakte Parameterreihenfolge der vom Betreiber bereitgestellten `mara.modelfile`-Ausgabe, statt diese nur unter `docs/references` zu archivieren. Der E2B-Export bleibt unberuehrt.
- Aus `ollama show --modelfile` stammende absolute Modell-/DRAFT-Blobpfade werden NICHT als feste Serverpfade eingesetzt. Fuer den bestehenden, unveraenderten Installer bleibt ein einzelnes `FROM gemma4:e4b` erhalten; SYSTEM verwendet die korrekte dreifache Einfassung fuer mehrere Zeilen.
- Modellparameter unveraendert in Nutzerreihenfolge: `draft_num_predict 0`, `temperature 1`, `top_k 64`, `top_p 0.95`; kein `num_ctx` oder `num_predict`-Override. Keine zusaetzlichen Skills oder Coding-Anweisungen im Modelfile.
- Gemma-Selbsttest und Schutzrichtlinie auf die aktive Vorlage angepasst. Installationsreihenfolge, One-Liner, `setup.sh`, `scripts/install.sh`, `scripts/install-native.sh`, `scripts/stack.sh`, Modellqueue und API-Verarbeitung unveraendert.
- 0.5.35 Versionierung, sechs Browseroberflaechen, numerischer JS-Cache-Schluessel, Preflight-Verweise, README, TXT-Changelog und Uebergabeprotokoll konsistent aktualisiert. Keine GitHub Actions. Echter Ollama/Netcup-Live-Test bleibt offen.

## 0.5.34 - 25.10.2026

- Das vom Betreiber gelieferte E2B-`ollama show`-Modelfile mit FROM-/DRAFT-Pfaden und freigegebenen Parametern als unveraenderliche Inhaltsreferenz archiviert; keine Ersetzung der aktiven E4B-Modellbasis.
- Verbindliche Richtlinie fuer knappe, geschuetzte Modelfiles: Mara-Persona und Modellparameter bleiben stabil, nur notwendige Werkzeugprotokollanpassungen sind gezielt moeglich; neue Skills und Coding-Anleitungen verbleiben ausserhalb des Modelfiles.
- Bestehenden Gemma-Selbsttest um Git-Blob-Checksumme, Schutz der Persona ausserhalb der Werkzeugsektion und Abgleich der genehmigten Parameter erweitert.
- Weder `config/Modelfile.template` noch Installer, One-Liner, Ollama-Modellerstellung, Toolrouting oder serielle Modellwarteschlange geaendert.
- Versionsangaben, Preflight-Releasedateien und numerische JS-Cache-Schluessel fuer 0.5.34 angepasst. GitHub Actions nicht gestartet; produktive Netcup-/Ollama-Abnahme bleibt offen.

## 0.5.33 - 25.10.2026

- Neues kontobezogenes Skills-System mit 32 eigenen SKILL.md-Paketen, einzeln schaltbar, durchsuchbar und mit Alle-aktivieren/Alle-deaktivieren sowie Quellen und Prüfdatum.
- Migration 014 speichert die Auswahl pro Konto. Authentifizierte API mit CSRF, strenger Kennungs-/Bool-Prüfung und transaktionalem Speichern. Neue Skills bleiben ausgeschaltet. Kontoexport ergänzt.
- Höchstens vier passende aktivierte Skills je Anfrage; explizite Priorisierung über /skill kennung. Gemeinsame Prompt-Anreicherung für synchrone Ollama-Aufrufe und SSE, einschließlich Fortsetzungen. Arbeitsereignisse melden geladene Kennungen.
- Keine Änderung an Gemma-Modelfile, Modellparametern oder serieller Warteschlange. Skills ergänzen den Nutzerkontext und ersetzen keine SYSTEM-Anweisung, Werkzeuge oder echte Dateiprüfung.
- Quellen am 25.10.2026 geprüft, Anleitungen selbst verfasst. Keine fremden Skripte importiert. Der universelle Projekt-Agent bleibt separat in Draft PR #69.
- Offline-Tests und Live-Abnahme sind im Übergabeprotokoll getrennt aufgeführt. GitHub Actions gemäß Vorgabe übersprungen [skip ci].

## 0.5.32 - 24.10.2026

- **Nur Preflight-Testkorrektur:** Der Frontend-Regressions-Selbsttest suchte noch den alten Aufruf `coreui_console_multifile_source_directive($userMsg)`. Der Code aus 0.5.31 verwendet stattdessen korrekt `$sourceProjectRequest` fuer lokal normalisierte Gemma-Mehrdatei-Auftraege. Dadurch meldete der Test trotz vorhandener Funktion faelschlich einen Fehler.
- Erwartung auf `coreui_console_multifile_source_directive($sourceProjectRequest)` umgestellt; die neue lokale Homepage-Normalisierung und die device-local-Modellbeschraenkung werden ausdruecklich mitgeprueft. Die restlichen 14 Routenbedingungen bleiben erhalten.
- Keine Aenderung an `api/chat.php`, Dateiwerkzeug, Python-Worker, Ollama, Gemma-Modelfile, Cloud-/Qwen-Pfaden, Modellparametern, serialisierter Inferenz, Datenbanken oder Installern. Der universelle Projekt-Agent verbleibt als **Draft PR #69**, unveroeffentlicht und standardmaessig deaktiviert.
- Pflichtgemaesse Versionspflege 0.5.32 mit sichtbaren UI-Metadaten, numerischem inhaltsbasiertem Cache-Key, README, CHANGELOG, TXT-Protokoll und Preflight-Releasepfaden. GitHub Actions nicht gestartet [skip ci]. Auf dem Server bleibt die tatsaechliche Preflight-Nachkontrolle erforderlich.

## 0.5.31 - 24.10.2026

- Reproduzierter Routingfehler der privaten Anime-Videospiel-Homepage: Der Benutzer forderte HTML, CSS und weitere Quelldateien im ZIP als Download, nannte aber keine expliziten Dateinamen. Bisher erkennt der bestehende Quell-ZIP-Pfad nur benannte Dateien oder besondere Browserspielwoerter. Der Auftrag landete deshalb im langen modellgenerierten [PY]-Skript, das bei 3.005 lokalen Ausgabetokens als `ollama_truncated_py_open` abbrach.
- Nur fuer geraetelokale Gemma-Modelle wird bei einer solchen ZIP-Homepage ohne Dateinamen die kleinstmoegliche Projektstruktur inferiert: `index.html`, `style.css`, `main.js`. Kein Eingriff in andere Datei- oder Python-Auftraege. Explizite Dateinamen und Einzeldateien behalten ihre Route.
- Die bereits seit 0.5.16/0.5.21 vorhandene verifizierte Einzel-Quelltext-Erzeugung mit anschliessendem CoreUI-eigenem ZIP-Schreiber wird nun auch fuer diesen Homepage-Fall erreicht. Modellgenerierter Python-Writer ist dafuer nicht mehr noetig; jede Quelldatei wird einzeln, streng seriell und mit den bestehenden Grenzen geprueft.
- Ollama Cloud (`gemma4:31b-cloud` wird weiterhin aus der lokalen Ollama-Liste gewaehlt), Qwen und die allgemeine Python-Sandbox bleiben komplett auf ihrem bisherigen Routing. Keine eigene API-Integration.
- Originalauftrag als Regression, negative Faelle fuer Single-HTML, benannte Dateien, Erklaerungen und fremde ZIP-Formate hinzugefuegt. Ein lokaler isolierter PHP-8.4-Routingtest des Originalauftrags war erfolgreich. Kein produktiver Netcup/Ollama-Datei-End-to-End-Test behauptet.
- Versionsmetadaten auf 0.5.31, sechs Browser-Cache-Fingerprints, Preflights und TXT-Uebergabe synchronisiert. Keine Modelfile- oder Worker-Aenderung, kein `num_ctx`/`num_predict`, keine Migration oder veraenderter Installer. GitHub Actions wegen fehlendem Kontingent nicht ausfuehren. [skip ci]

## 0.5.30 - 24.10.2026

- Eng begrenzter Ollama-Dateiantwort-Diagnosepatch auf Basis 0.5.29. Kein neuer Python-Datei-Writer, kein Aendern des Python-Workers oder der Modell-Routen.
- Modell-Arbeitsereignisse zeigen ausschliesslich sichere Abschlussklassen (z.B. `ollama_stop_py_closed`, `ollama_truncated_py_open`) und Zahlwerte fuer Ausgabezeichen, `eval_count` und `prompt_eval_count`; keine Prompts, Quelltexte, Thinking oder stderr.
- Nur lokal laufende Gemma-4-Modelle weisen nachweislich abgeschnittene Antworten oder nicht geschlossene [PY]-Bloecke vor der Python-Ausfuehrung ab. Bestehender einmaliger Protokoll-Retry sowie spaeterer Korrekturversuch sind abgedeckt. Kein unbegrenzter Retry.
- Ollama Cloud wie `gemma4:31b-cloud` bleibt ueber die lokal konfigurierte Ollama-Modellliste und denselben lokalen Endpunkt erreichbar, ohne separate Cloud-API. Qwen unveraendert.
- Isolierte PHP-8.4-Testlaeufe und aktualisierte lokale Dateiwerkzeugregression bestanden. Produktions-Ollama-End-to-End offen. Kein `num_ctx` / `num_predict` fuer Gemma, keine Modelfile- oder Migrationsaenderung, kein neuer Installer. GitHub Actions nicht gestartet. [skip ci]
- Vollstaendig markierter aber intern ungueltiger Python-Code kann weiterhin einen SyntaxError haben. Die Diagnose soll erst den Ollama-Abschlussgrund nachweisen.

## 0.5.29 - 23.10.2026

- Stabilitaets-Rollback auf den gesamten funktionalen Quellstand von **0.5.25** aus Commit `53dab3f6d78e0cd626824aa3ca883f441a7f193f`, vor den PRs #63 (0.5.26), #64 (0.5.27) und #65 (0.5.28).
- Ruecknahme der gemeinsamen HTML/CSS-Modellausgabe, der HTML-Sonderbehandlung und der generischen Python-Syntaxvorpruefung. Wiederhergestellt sind der alte Python-Worker, das originale Chat-Routing und die Dateiausgabe samt Qwen-Verhalten aus 0.5.25. Keine selektiven Feature-Uebernahmen.
- Ueber 0.5.25 hinaus aendern sich ausschliesslich die numerische Release-Version 0.5.29, sichtbare UI-Versionslabels, der dazu passende inhaltsbasierte Bootstrap-Cache-Schluessel sowie README, Changelog, TXT-Uebergabe, Versionspruefung und Preflight-Dateireferenzen.
- Kein Eingriff in Datenbanken, Nutzerkonten, bestehende Migrationen, gespeicherte Konfigurationen, Ollama-Modelfile oder Installerskripte. Vorheriger 0.5.28-Stand ist auf dem Sicherungsbranch `backup/coreui-0.5.28-pre-rollback` archiviert.
- Kein GitHub Actions Start (Kontingent aufgebraucht). Wiederherstellung auf dem Server erfolgt separat ueber den bewaehrten bestehenden Update-Einzeiler; Repo-Merge ist keine Live-Ausfuehrung. [skip ci]

## 0.5.25 - 22.10.2026

- Offizielle Produktbezeichnung auf **E.M.B.E.R. CoreUI** korrigiert. E.M.B.E.R. ist das Framework, Backend und System; Ember bezeichnet dagegen die eigenstaendige CoreAI der *Starlight Ember*. Mara Thorne bleibt TCI/CoreAI der *Ravager Thorn*. Diese Begriffe werden nicht mehr vertauscht.
- Beide Installer, One-Liner-Launcher, Preflights, Browser-Metadaten, Startbildschirm, Login, Settings, Admin, Runtime-Meldungen, Healthcheck und Dokumentation verwenden die gepunktete E.M.B.E.R.-Schreibweise.
- Lokale Ollama-Modellnamen, technischen IDs, Datenbankschemas, Dienste, Verzeichnisse und Installationspfade bleiben unveraendert. Kein Umbenennen von `ember-coreui:latest` und keine Datenmigration.
- Neue Accounts und frische Installationen erhalten **Mara** als Standard-CoreAI-Namen; alte Profile mit Standardname Ember werden wie bisher nur bei der Anzeige als Mara behandelt. Individuelle andere Namen bleiben erhalten.
- Maras Modelfile stellt E.M.B.E.R.-Framework, CoreUI-Terminal und separate STU-CoreAI Ember ausdruecklich richtig. `draft_num_predict 0` bleibt, kein `num_predict` oder `num_ctx` im Modelfile.
- Lizenz-/Trademark-Texte enthalten weiterhin die alte Schreibweise als historischen Alias fuer Releases bis 0.5.24. Lizenzversion 1.0 und ihre materiellen Nutzungsbedingungen wurden nicht geaendert.
- Brandingtests, Versions- und Cache-Pruefungen angepasst. GitHub Actions nicht ausgefuehrt; produktive Abnahme noch offen. [skip ci]

## 0.5.24 - 22.10.2026

- Rollen sauber getrennt: TSA-Thorn CoreUI ist das Terminal; Mara Thorne die TCI und CoreAI. Bestehende interne Ember-IDs, Tabellen und Pfade bleiben aus Kompatibilitaetsgruenden erhalten.
- Lokales Gemma Modelfile um lockere, spontane Gamer-Chat-Stimme aus dem bisherigen Bildantwort-Stil erweitert; separate Bild-/Video-/PDF-Vision-Anweisungen ebenfalls auf Mara statt alter Menschenidentitaet umgestellt.
- Unbeabsichtigt verbliebenes `PARAMETER num_predict -1` aus dem Modelfile entfernt. `draft_num_predict 0` und die uebrigen freigegebenen Werte bleiben, `num_ctx` bleibt bei Gemma ausnahmslos tabu.
- Sichtbare CoreAI-Bezeichnungen, Standardprofilname, Antwort- und Warteanzeigen auf Mara angepasst. Der Live-Arbeitsweg lautet `E.M.B.E.R. CoreAI arbeitet`. Gespeicherte alte Standardnamen Ember werden beim Anzeigen als Mara dargestellt; andere individuelle Anzeigenamen bleiben erhalten.
- Lokale Qwen-3.6-Coding-Modelle erhalten wieder den historischen `[PY]`-Sandbox-Auftragsweg fuer echte ZIP-Dateien statt des spaeteren strengen Quellen-ZIP-Pfads. Keine Zusage ueber den noch ungetesteten produktiven Qwen-Regressionstest.
- Cloud-Ausgabe, aktuelle lokale Gemma-Recovery, unveraenderter Installer-One-Liner und serielle Queue bleiben erhalten. Keine Migration, keine GitHub Actions. [skip ci]

## 0.5.23 - 22.10.2026

- Livebefund aus 0.5.22: HTML und CSS erfolgreich erkannt; `game.js` in zwei lokalen Gemma-Aufrufen mit `model_output_truncated` beendet. Das ist eine Ollama-Ausgabelimitierung, kein belegter Parser- oder ZIP-Worker-Defekt.
- Lokale JavaScript-Quelltexte werden bei `done_reason=length` nicht mehr verworfen: Maximal zwei serielle Fortsetzungsanfragen vervollstaendigen den vorhandenen Quelltext in derselben privaten Sitzung.
- Ein reiner JavaScript-Fragmentparser entfernt optionale einfache Codefences; wiederholte Textueberlappungen werden abgeglichen. Vollstaendige Daten gehen ausschliesslich durch den vorhandenen sicheren Quellcode- und ZIP-Writer. Unvollstaendige Dateien bleiben ohne Download.
- Der lokale JavaScript-Prompt uebergibt weiter die HTML-Struktur, nicht mehr den vollstaendigen CSS-Inhalt, um Ausgabeplatz einzusparen.
- Arbeitsweg zeigt nur sichere Quelltext- und Modelltoken-Zaehler, ohne geheime Inhalte. Weitere Offline-Regressionstests fuer fortgesetzte JavaScript-Dateien und Safe-Metadata ergaenzt.
- Modelfile (einschliesslich `draft_num_predict 0`, `num_predict -1`), Cloud-Dateipfad, lokale Einzeldateien, Modellqueue und Installer-One-Liner unveraendert. Keine Migration, keine GitHub Actions; produktive Live-Abnahme offen. [skip ci]

## 0.5.22 - 22.10.2026

- Nach Live-Test: GPT Cloud erzeugt erfolgreich vier echte Snake-Downloads, lokales Gemma scheitert weiterhin mit `source_files_incomplete`; dies ist kein belegter Workerdefekt.
- Lokale Gemma-/Ember-CoreUI-Mehrdateien-ZIP-Auftraege fragen jetzt jede Quelldatei in einem kurzen eigenstaendigen Modellaufruf als rohen Quelltext an, statt gleichzeitig Python-Werkzeug- und Markdown-Projektanweisungen zu mischen.
- Der lokale Parser uebernimmt begrenzt vollstaendige Rohdateien oder einzelne Codebloecke, erkennt fehlenden Schluss-Fence bei nachweislich vollstaendigem Dateiinhalt, lehnt Python-Marker/Prosa/ungueltige Inhalte ab und wiederholt hoechstens einmal pro Datei.
- Nach vollstaendiger Erfassung erstellt und prueft ausschliesslich der vorhandene servereigene Worker die Dateien und das ZIP. Der bewiesenermassen funktionierende Cloud-Pfad bleibt unveraendert; Modelfile, API-Parameter, globale serielle Queue und Installer-Befehl bleiben unberuehrt.
- Lokale Parserregressionen fuer HTML/CSS/JS, kurze Quelldateien, fehlende Fences, Python-Marker und ZIP-Writer ergaenzt. Keine Migration. Offline-Parser-Teiltest mit PHP 8.4 bestanden; keine GitHub Actions, Live-Abnahme noch offen. [skip ci]

## 0.5.21 - 22.10.2026

- Mehrdateien-ZIP-Dateiauftraege verwenden vollstaendige, inerte Quelldateien statt fehleranfaelliger vom Modell erzeugter Python-Schreibskripte. Ohne Dateinamen wird dieser Pfad nur fuer ausdruecklich verlangte Snake-/Browser-Spiel-ZIPs erkannt.
- CoreUI fordert fehlende Quellen begrenzt einzeln nach. Erst bei vollstaendigen HTML-/CSS-/JS-Quellen erstellt der bestehende isolierte Worker echte Dateien, prueft das ZIP und registriert Downloadkarten.
- Unvollstaendige Quelltexte fuehren weder zu Python-Ausfuehrung noch zu scheinbaren Downloads. Generische Python-Werkzeuge und Einzeldateien bleiben auf ihrem bisherigen Pfad.
- Offline-Regressionstests fuer Source-Fortschritt, fehlende Dateinamen, abgegrenzte ZIP-Routen und nicht geschlossene Fences ergaenzt; keine Datenbankmigration, kein neuer Installer-One-Liner.
- GitHub Actions wegen aufgebrauchtem Kontingent nicht ausgefuehrt. Live-Abnahme mit lokalem Gemma, Cloud-Modellen, echtem ZIP-Download und Spielbarkeit noch offen. [skip ci]

## 0.5.20 - 22.10.2026

- Vom Nutzer geliefertes Modelfile wortgetreu uebernommen, inklusive Mara Thorne / TSA-Thorn SYSTEM, TEMPLATE, RENDERER und PARSER.
- Parameter wiederhergestellt: temperature 1, top_k 64, top_p 0.95, draft_num_predict 0, num_predict -1.
- Beide Installer verwenden standardmaessig gemma4:e4b. Explizites COREUI_BASE_MODEL ersetzt ausschliesslich die FROM-Zeile. PARAMETER-Verbot im Compose-Installer entfernt.
- Lokale Gemma-API-Aufrufe senden weiterhin keine options-Ueberschreibungen. Preflight und Regression pruefen nun die freigegebenen Modelfile-Parameter statt deren Abwesenheit.
- Keine Migration oder Aenderung am Installer-One-Liner. Ollama-Neuerstellung und Modellverhalten muessen live bestaetigt werden; GitHub Actions bleiben uebersprungen.

## 0.5.19 - 22.10.2026

- Dateiwerkzeug fuer Mehrdateien-ZIP-Auftraege wiederhergestellt: [PY]-Skripte und Python-Fences werden wieder vom bestehenden isolierten Worker verarbeitet.
- Der seit 0.5.16 erzwungene Sourcefile-Modus wird nicht mehr als Prompt-/Parser-Sperre verwendet. Vollstaendige Quelltextbloecke und der begrenzte Merge aus 0.5.18 bleiben optionale Wiederherstellungspfade.
- Reparatur bei fehlenden Worker-Ausgaben richtet sich nach dem tatsaechlichen Kandidatentyp. Modellskripte behalten ihren begrenzten Reparaturversuch; servereigene Quelltext-Wrapper werden nicht sinnlos vom Modell neu geschrieben.
- Live-Screenshots von 0.5.18 zeigen source_files_incomplete vor dem Workerstart. Die tatsaechlichen Modellantworten liegen nicht vor; deren konkreter Formatfehler wird daher nicht behauptet.
- PHP-8.3.6-Dateiwerkzeugtest mit echten Parserfunktionen, Frontend-Regression, PHP-/Shell-Syntax, Branding, Versionsguard und Cache-Hashes lokal bestanden.
- Keine Migration, keine Aenderung am Installer-One-Liner, globale Modellverarbeitung weiterhin seriell. GitHub Actions uebersprungen; erneute Live-Abnahme offen.

## 0.5.18 - 21.10.2026

- Unvollstaendige, aber jeweils sichere Mehrdateien-Antworten koennen jetzt ueber genau einen seriellen Modell-Wiederholungsversuch zusammengefuehrt werden. Die erste und zweite Antwort werden nur als inerte Quelltextfragmente gelesen; der Worker startet erst, wenn alle ausdruecklich verlangten Dateien vorhanden sind.
- Bei demselben Dateinamen gewinnt die zweite Antwort. Gross-/Kleinschreibung wird sicher normalisiert, waehrend die vom Nutzer angeforderte Schreibweise fuer die erzeugte Datei erhalten bleibt.
- Nicht angeforderte Zusatzdateien des Modells gelangen weder in die Ausgabe noch in das ZIP. Unsichere Pfade, Duplikate innerhalb einer Antwort, ungeschlossene Fences, unvollstaendige Pakete sowie Datei-, Gesamt- und Artefaktgrenzen bleiben harte Ablehnungsgruende.
- Markdown-Quellen mit eigenen Drei-Backtick-Bloecken koennen ueber laengere aeussere Fences bytegetreu verarbeitet werden. Der Merge baut den kontrollierten Python-Schreiber direkt aus validierten Bytes und serialisiert Quelltext nicht erneut durch Markdown.
- PHP-8.3-Syntaxpruefung und erweiterter Offline-Selftest fuer Teilantworten, Zweitantwort-Vorrang, Zusatzdateien, Schreibweise, Backticks, ungeschlossene Fences, Duplikate und Grenzwerte erfolgreich. Versionsguard und beide Preflight-Dateilisten auf 0.5.18 synchronisiert.
- Der aus zwei Teilantworten erzeugte servereigene Python-Schreiber wurde isoliert ausgefuehrt: drei Einzeldateien und snake-game.zip, fehlerfreie ZIP-CRC sowie bytegleiche ZIP-Eintraege.
- Keine Datenbankmigration, kein geaenderter Installer-One-Liner, keine GitHub Actions und kein neues archiviertes ZIP/SHA-Paar. Die Produktion wird nach dem Merge mit Gemma, Qwen und Cloud GPT live getestet.

## 0.5.17 - 21.10.2026

- Modellunabhaengiger Mehrdateien-Download: explizite ZIP-Projekte mit mindestens zwei benannten Quelldateien gehen jetzt sowohl bei lokalen Ollama-Modellen als auch bei Cloud/API-Modellen ueber denselben sicheren Quelltext-zu-Artefakt-Werkzeugpfad. Keine stillen Teilergebnisse als angeblich fertiges ZIP.
- Tatsachenbasierter Fehlerfix im CoreUI-Backend: `json_encode()` maskierte `/` in Base64-Payloads als `\\/`. In einem Python-String bleiben diese Backslashes erhalten und `base64.b64decode(...,validate=True)` scheitert mit `binascii.Error`. Der Wrapper verwendet jetzt `JSON_UNESCAPED_SLASHES`.
- Isolierter PHP-/Python-Nachbau: mit dem alten Encoding nachweisbarer Fehler, nach Korrektur drei vollstaendige Quelldateien und gueltige `snake-game.zip` (ZIP-Inhaltsvergleich und CRC-Pruefung).
- Veraltete Frontend-Regression angepasst: der Test prueft den tatsaechlichen Datei-Protokollvertrag, den neuen Cloud/Lokal-Sonderpfad und die sichere Base64-Kodierung, statt eine im vorherigen Release geaenderte Fehlermeldung wortwoertlich zu verlangen.
- Weiterhin streng: Downloads nur bei vorhandenen, geprueften Dateien; bestehende Nutzer-/Sitzungszuordnung, globale Modellwarteschlange, isolierter Worker und unveraenderter Installer-One-Liner. Keine Datenbankmigration, keine GitHub Actions und kein neues archiviertes ZIP/SHA-Paar.
- Produktions-Abnahme fuer Gemma, Qwen und GPT Cloud steht aus; der frühere modellgenerierte Fehlerbericht ist kein Server-Traceback.

## 0.5.16 - 21.10.2026

- Lokale Mehrdateien-ZIP-Auftraege mit zwei bis neun explizit benannten Quelltext-Dateien werden als komplette benannte HTML/CSS/JS- und andere Quelltextbloecke vom Modell angefordert. Ein Modell muss fuer diese Auftraege keinen zusaetzlichen Python-Dateischreibcode mehr erfinden.
- Der bereits vorhandene getestete Quelltext-Bridge schreibt ausschliesslich die bestaetigten Source-Bytes im isolierten Python-Worker und erstellt das ZIP deterministisch, mit `is_file()`-/Groessen- und ZIP-Inhaltstest. Das Modell kann keine lokalen Dateinamen, Download-URLs oder Skript-Ausfuehrungen allein durch Behauptung freigeben.
- Widersprechende allgemeine `[PY]`-Hinweise werden nur im gezielten lokalen Quelltextprojekt-Modus entfernt. Fehlende Source-Bloecke duerfen einmalig gezielt neu angefordert werden; scheitert der kontrollierte Dateischreiber oder Artefaktspeicher, erfolgt kein weiterer teurer modellgenerierter Python-Reparaturlauf.
- Bestehende Single-File-, generische Python-, Cloud- und Downloadpfade bleiben unveraendert. Neuer lokaler Quelltextvertrags-Selbsttest neben den vorhandenen ZIP-Pruefungen.
- Numerischer Versionssprung `0.5.15 -> 0.5.16`; keine Migration, kein veraenderter Installer-Einzeiler, keine GitHub Actions und kein neues offizielles ZIP/SHA-Paket. Eine echte lokale Gemma- und Browser-Abnahme steht aus; Issue #52 zum Ollama-Modellwechsel bleibt separat offen.

## 0.5.15 - 21.10.2026

- `config/Modelfile.template` enthaelt fuer das CoreUI-eigene lokale Gemma-Modell keine `PARAMETER`-Zeilen mehr. `temperature`, `top_p`, `top_k`, `repeat_penalty` und insbesondere `num_ctx 32768` werden nicht mehr automatisch festgeschrieben.
- Der Installer erstellt bei jedem Update das eigene `ember-coreui:latest` neu und blockiert eine generierte Modelfile mit eigenen `PARAMETER`-Direktiven vor `ollama create`. Das gemeinsam verwendete Basismodell, fremde Modelle und bestehende Nutzerkonfigurationen bleiben unveraendert.
- Bei frisch erzeugter `api/config.local.php` wird `STU_EMBER_NUM_CTX` nicht mehr hinterlegt. Selbst wenn alte Installationen diesen Wert behalten, geben der synchrone PHP-Pfad und der SSE-Pfad fuer lokale `ember-coreui:*`/`gemma4:*` keinerlei `options`-Tuning an Ollama weiter. Andere Modelle, etwa Cloud-GPT, behalten ihre eigenen Einstellungen.
- Die kurze synthetische `ember_warmup`-Generierung wird bei lokalen Gemma-Modellen ausgelassen: ohne Tuning konnte sie schon beim Laden ueber ihr achtsekundiges Timeout laufen und eine echte Nutzeranfrage stoeren. Es erfolgt kein automatischer Dienstneustart.
- Neuer PHP-Regressionscheck fuer Template, generierte Datei, Installer-Guard, Sync/SSE-Modellpayload, Modellfamilien und Cloud-Isolation; beide Preflights verlangen diesen Test.
- Wiederholt beobachtete lokale Modellstillstaende nach einiger Laufzeit sind noch nicht ursachlich bewiesen. Die Konfigurationsbereinigung wird nicht als garantierte Behebung eines Ollama-/Worker-Defekts ausgegeben. Keine neue Migration, keine Aenderung des Einzeilers, keine GitHub Actions und kein neues ZIP/SHA-Release.

## 0.5.14 - 21.10.2026

- PHP-/MariaDB-Installationsfix: Der Dockerfile-Build und Installer pruefen `pdo_mysql` direkt mit `extension_loaded()` im frischen PHP-Image. Ein Image ohne den Treiber wird nicht als erfolgreich installiert gemeldet.
- Vor dem Abschluss werden ausschliesslich die eigenen CoreUI-PHP-/Web-Container mit dem gebauten Image neu erstellt und `pdo_mysql` erneut im laufenden PHP geprueft. Datenbank, Nutzer, Uploads, lokale Konfiguration und andere Dienste bleiben erhalten.
- Compose- und Native-Preflight benutzen PHPs Extension-API statt der potenziell fehleranfaelligen Pipeline `php -m | grep -q`; fehlende Module bleiben ein harter Fehler mit zielgerichteter Diagnose.
- Offline-Vertragstest fuer Dockerfile, Installer und Preflights ergaenzt. Keine Migration und keine Veraenderung des unveraenderten Installer-One-Liners; keine GitHub Actions.
- Echte Serverabnahme steht aus. Kein neues offizielles ZIP-/SHA-Release erzeugt.

## 0.5.13 - 21.10.2026

- Lokale Ollama-Dateiauftraege gehen unmittelbar in den bestehenden isolierten Python-Werkzeugpfad. Der zuvor ueberfluessige erste allgemeine SSE-Modellstream entfaellt bei expliziter Dateierstellung mit Downloadwunsch. Alle Modellaufrufe bleiben in derselben globalen seriellen Warteschlange.
- Der Werkzeugaufruf verlangt einen vollstaendigen `[PY]`-Block. Fehlt ein ausfuehrbarer Auftrag, erfolgt maximal ein kurzer, fokussierter Zweitversuch. Bei `ember-coreui:*` und `gemma4:*` laeuft die reine Dateicode-Erzeugung standardmaessig mit `think:false` (optional `STU_EMBER_LOCAL_FILE_THINK=true`). Andere Provider behalten ihre bisherige Einstellung.
- Ein vollstaendiger Python-Codeblock kann bei ausdruecklicher Downloadanforderung sicher im bestehenden Worker ausgefuehrt werden. Benannte oder eindeutig zuordenbare HTML/CSS/JS-Fences werden stattdessen als inerte Dateibytes geschrieben. Ein verlangtes ZIP wird im Sandbox-Worker erstellt und mit `zipfile` geprueft.
- Der Chat meldet keinen vollstaendigen Dateierfolg, wenn im Nutzerauftrag explizit benannte Ausgabedateien oder das ZIP fehlen. Fehlende Werkzeuginstruktionen werden im gespeicherten Arbeitsweg als Fehler ausgewiesen, statt einen Download zu erfinden.
- Neuer PHP-Selbsttest fuer mehrteilige Projekte, ZIP und Dateivollstaendigkeit, in beide Preflights integriert; Frontend-Vertrag geprueft. Ein lokaler isolierter Python-ZIP-Probelauf war erfolgreich. Echte `ember-coreui:latest`-Live-Abnahme bleibt offen.
- Keine Migration, keine Aenderung des Installer-One-Liners, keine GitHub Actions und kein neues geprueftes ZIP/SHA-Release.

## 0.5.12 - 21.10.2026

- Der Info-Schalter unter gespeicherten E.M.B.E.R.-Antworten zeigt wieder die **GESAMTDAUER**, auch nach Chatwechsel und Browser-Reload. Bisher war die Antwortzeit nur fluechtig an den Nachrichtentimestamp gebunden.
- Neue Modellstreams speichern zusaetzlich die **MODELLZEIT** (nur Provider-HTTP-Anfrage, ohne Warteschlange), getrennt von der Gesamtverarbeitung mit Werkzeugen, Dateierstellung und Speicherung.
- Bereits vorhandene 0.5.11-Arbeitsevents liefern weiterhin ihre gespeicherte Gesamtdauer. Bei fehlender Eventdauer steht die gekennzeichnete, aus autorisierten Agentenlauf-Timestamps berechnete **AGENTENLAUFZEIT** zur Verfuegung. Fehlende Modellzeiten werden nicht erfunden.
- JavaScript-UI-Selbsttest, PHP-Agentenlauf-Test, Frontend-Regressionspruefung und beide Preflights erweitert. Keine Migration, keine Installer-One-Liner-Aenderung, keine GitHub Actions.

## 0.5.11 - 21.10.2026

- P06: Live-Arbeitsschritte bleiben nach der fertigen E.M.B.E.R.-Antwort direkt in derselben Nachricht erhalten. Der zuletzt beendete Arbeitsweg ist zunaechst aufgeklappt; ältere Nachrichten besitzen den deutlichen Schalter `Arbeitsweg (N Schritte) anzeigen`.
- Der Chat kombiniert ausschliesslich bereinigte, tatsaechlich aufgezeichnete SSE-Ereignisse mit der authentifizierten Datenbank-History. Antwortwechsel, Polling und Reload verlieren die Chronik nicht; Ereignis-IDs werden ohne Doppelanzeige zusammengefuehrt.
- Serverseitige Antwortzuordnung nutzt bei unterbrochener separater Event-Bindung den bereits kontogebundenen Agentenlauf, ohne Turn-, Sitzungs- oder Variantenvermischung. SSE-Reconnect und bereits abgeschlossene Aktionen liefern die vorhandenen Arbeitsschritte erneut, ohne das Modell zu wiederholen.
- P06-UI- und PHP-Persistenzregressionen sowie Native-/Compose-Preflight erweitert. Keine Migration, kein neuer Hintergrunddienst und keine Aenderung des Installer-One-Liners.
- Sichtbar sind operative Arbeitsschritte, keine rohen Modellgedanken oder vertraulichen Werkzeugdaten. GitHub Actions nicht gestartet; Live-Abnahme auf dem Server noch ausstehend.

## 0.5.10 - 21.10.2026

- Optional anklickbare Folgefragen unter gespeicherten Ember-Antworten, wenn die Antwort mit einer expliziten Auswahlfrage und 2 bis 4 Stichpunkten endet. Schaltflaechen verwenden die normale Chat-Sendefunktion und erlauben keine HTML-/Codeausfuehrung.
- Mobile und Desktop: skalierende, per Tastatur bedienbare Vorschlags-Schaltflaechen; keine gesonderte Modellanfrage bei der Anzeige.
- Roadmap P20: Eigener Plan fuer native CPU-Bildgenerierung mit stable-diffusion.cpp als C/C++-Referenz, FLUX.1-schnell/SDXL-Turbo als Kandidaten, Lizenz-/Formatpruefung, globaler serieller Modellslot, getrennten Ressourcenbudgets und echten Benchmarks.
- Keine Bildengine/Modellgewichte ausgerollt. Bestehende Downloads, Sitzungen, Installationsschema und Modellbetrieb unveraendert. GitHub Actions nicht gestartet.

## 0.5.9 - 21.10.2026

- P05: Migration 013 speichert private Agentenlaeufe und eine globale persistente FIFO-Warteschlange fuer alle Ollama-HTTP-Aufrufe. Ein MariaDB GET_LOCK bleibt die verbindliche Grenze fuer genau einen gleichzeitigen Modellaufruf.
- Kontogebundene Run-IDs, atomare Zustandswechsel, deduplizierte SSE-Wiederverbindungen und sichere Antwortbindung; keine stillen Wiederholungen unklarer Provideraufrufe.
- Die neue Chat-Stop-Schaltflaeche fordert ueber einen CSRF-geschuetzten Endpunkt Abbruch an. Wartende Runs stoppen sofort; laufende Aufrufe werden kooperativ abgebrochen. Ein beendeter HTTP-Transport beweist nicht das Ende einer Cloudprovider-internen Arbeit.
- Browser-Jobs werden an den eigenen Run gebunden und nach Abschluss mit gespeicherten Antworten abgeglichen, ohne das Modell automatisch neu zu starten.
- FIFO-Tickets besitzen Warte-Heartbeat, Besitzerverbindung, begrenzte Wartezeit und sichere Freigabe. Alte abgeschlossene Tickets werden bereinigt. Verschachtelte Aufrufe teilen denselben Modellslot.
- Native und Compose-Preflight pruefen beide P05-Schemata, PHP-Syntax und Offline-Regressionen fuer Agentenstatus, Kontotrennung, FIFO, Timeout und Abbruch.
- **Noch offen:** Echte MariaDB-/Cloud-/Neustart-/Android-Abnahme, providerseitige Reconciliation unklarer Remoteanfragen, unbegrenzte Hintergrundwarteschlange und allgemeine P07/P26-Checkpoint-Fortsetzung. Keine GitHub Actions und kein neues geprueftes ZIP/SHA-Release.

## 0.5.8 - 20.10.2026

- Chatlayout wie bei ChatGPT/Claude: Nur Nutzernachrichten behalten Sprechblasen. E.M.B.E.R. antwortet als vollbreiter, freier Markdown-Text; auch auf Android. Der Live-Arbeitsverlauf ist Teil derselben Nachricht, kein zweiter "DENKSTATUS"-Kasten mehr.
- P06-Verlauf wird in einem einzigen Schritt mit den authentifizierten `console_messages.php`-Antwortdaten geladen. Die fehlschlagende Extraabfrage fuer jede Antwort entfaellt aus dem Chat. SSE liefert die Ereignisse ebenfalls beim Abschluss. Ereignisse bleiben nach Benutzer, Sitzung und Antwort getrennt.
- Python-Worker rettet regulaere Dateien aus dem eigenen isolierten Arbeitsverzeichnis, die Cloud-Modelle abweichend von `EMBER_OUTPUT_DIR` abgelegt haben. Vollstaendige HTML-stdout-Ausgaben koennen ebenfalls zu echten Downloads werden, aber nur ueber den vorhandenen validierten Artefaktpfad.
- Das Ausbleiben einer Ausgabedatei loest bei expliziter Download-Anfrage auch nach Exitcode 0 einen begrenzten Korrekturversuch aus; vorheriger Python-Code wird dabei beruecksichtigt. Keine erfundenen Downloadlinks, keine parallelen Modellaufrufe.
- Sicherer Offline-Workspace-/HTML-Test, Frontend- und Versionspruefer sowie 0.5.8-Release-TXT/Uebergabe. One-Liner unveraendert, GitHub Actions bleiben aus.
- Live-Abnahme mit `gpt-oss:120b` und echten HTML-/ZIP-Downloads auf Android noch offen, Issue #29 bleibt bestehen.

## 0.5.7 - 20.10.2026

- P06-Ausbaustufe: Der bisherige synthetische Denkstatus wird bei neuen Console-Antworten durch gespeicherte, echte Arbeitsschritte abgeloest. Sie werden via SSE live gezeigt und nach Seitenreload aus der Datenbank nachgeladen.
- Migration 012: Kontogebundene und antwortbezogene Modell-, Python-Werkzeug- und Dateiereignisse; eigener authentifizierter Lese-Endpunkt. Antwortvarianten erhalten getrennte Logs.
- Echte Fehlerdiagnose: Python-Job-ID, Exitcode, Laufzeit, bekannte Fehlerklasse wie FileNotFoundError, optional sicherer Dateiname, Reparaturversuch und bestaetigte Dateiausgaben statt pauschaler Fehlermeldung.
- Darstellungs- und Sicherheitsgrenzen: Kein Roh-Thinking, kein Prompt, kein Python-Quellcode, kein stdout/stderr oder Geheimnis im oeffentlichen Ereignisprotokoll. Ausklappbare Details auch auf Android.
- Beide Installer/Preflights, geschuetzte Nginx-Routen, Branding, HTML/JS, README, Changelog-TXT und Uebergabe synchronisiert. Original-One-Liner und GitHub-Actions-Verzicht unveraendert.
- Versionskanon: 0.5.6 -> 0.5.7 bei diesem Merge, ohne Suffix. Vollstaendige P05/P06/P07-Agentenfunktionen sowie Live-Abnahme der echten HTML-/ZIP-Downloads (#29) bleiben offen.

## 0.5.6 - 20.10.2026

- PR #39: gpt-oss:120b Cloud-Dateierstellung ueber den isolierten Python-Worker verbessert. /mnt/data zeigt innerhalb der Sandbox auf denselben begrenzten Ausgabeordner wie /work/output; keine Nutzerdaten oder Hostverzeichnisse werden eingebunden.
- PR #39: Eine echte Downloadkarte wird nur fuer vom Worker registrierte Dateien angeboten. Nach FileNotFoundError ist maximal ein serieller Korrekturversuch erlaubt. Vollstaendiger HTML-Quelltext kann alternativ als echte Datei gespeichert werden; unvollstaendige Ausgaben werden abgewiesen.
- PR #39: Python-Code wird bei Ueberlaenge nicht mehr still gekuerzt. Regressionspruefungen fuer Cloud-Modell-Dateien erweitert.
- Pflichtregel bestaetigt: JEDER Merge nach main erfordert bereits im selben PR einen numerischen Versionssprung in VERSION. PR #39 hatte diesen versaeumt; das wird hier nachvollziehbar berichtigt, ohne alte Releasearchive umzubenennen.
- Aktive Versionsanzeigen, Readme, Versionspruefungen, eigenes TXT-Changelog und Uebergabeprotokoll auf 0.5.6 abgeglichen. Lokaler Pre-Merge-Guard hinzugefuegt. Keine GitHub Actions.
- End-to-End-Livetest unter gpt-oss:120b und reales ZIP/SHA-Release weiterhin ausstehend. Issue #29 bleibt offen.

## 0.5.5 - 20.10.2026

- Numerische CoreUI-Version ohne Suffix wiederhergestellt. Die sechs aktiven HTML-Seiten und der JavaScript-Bootstrap zeigen konsistent `0.5.5`; das Versionsformat wird in Installer und Tests gesichert.
- Cache-Busting von manuell gepflegten Versions- oder Textlabels entkoppelt. Jede CSS-/JS-Datei erhaelt bei der Installation einen eigenen, rein numerischen, aus SHA-256-Dateibytes abgeleiteten Fingerabdruck. Unveraenderter Inhalt bleibt cachebar, Aenderungen invalidieren den jeweiligen Asset-Cache.
- Beide Installer und Preflights verwenden denselben Cache-Buster. Nginx verlangt Revalidierung von HTML, waehrend Fingerprint-Assets weiterhin cachebar sind. Beim atomaren HTML-Update bleiben Leserechte und Eigentum erhalten.
- One-Liner unveraendert; `main` ist die Quelle fuer Live-Updates. Der exakte Git-Commit bleibt separat nachweisbar, alte gepinnte Archive werden nicht umbenannt.
- Gemeinsamer Masterplan, Roadmap und 82 erfasste Funktionen fuer OpenClaw, Open WebUI, CrewAI, LangGraph und AutoGen wurden in GitHub gesichert.
- Gespeicherte Ausgabedateien, Chat-Dateikarten, geschuetzte Endpunkte und Migration 011 fuer Artefakte wurden zusammengefuehrt. Die eigentliche End-to-End-Abnahme auf dem Server und Issue #29 bleiben offen.
- Berechtigungsfehler beim Checkout, Live-Updater, HTML-Data-Link-Ausgaben und ein veralteter SSE-Selbsttest wurden behandelt.
- Quellenstand 0.5.5 war der main-Stand nach PR #38; ein neuer, eigenstaendig verifizierter Release-ZIP/SHA-256-Satz ist damit noch nicht behauptet. `0.5.4` war vorbereitet, aber nicht als eigenstaendiges Paket veroeffentlicht. Die historischen `-alpha`-Archive bleiben unveraendert erhalten.
- Keine GitHub Actions fuer diese Entwicklungs- und Live-Testschritte.


## v0.5.3-alpha - 05.09.2026

- Produktionspfad fuer Gemma 4 + Ollama auf das waehrend der mehrstuendigen CoreUI-Validierung ermittelte Minimalprofil ausgerichtet.
- Normale Ollama-Aufrufe senden standardmaessig nur `temperature=1.0`, `top_p=0.95` und `top_k=64` innerhalb von `options`.
- `num_ctx`, `num_predict`, Thread-, Repeat-, Seed- und Stop-Parameter werden im normalen Generierungspfad nicht mehr automatisch injiziert.
- Synchroner Antwortpfad und privater SSE-Pfad verwenden dieselbe gemeinsame Known-Good-Options-Policy und koennen dadurch nicht mehr still auseinanderlaufen.
- Vision-Aufrufe und Fortsetzungen abgeschnittener Antworten erzwingen keine alten Kontext- oder Predict-Budgets mehr.
- Historische beziehungsweise backend-spezifische Tuningparameter bleiben fuer gezielte A/B-Tests hinter `STU_EMBER_UNVERIFIED_TUNING_ENABLED` und expliziter Konfiguration verfuegbar.
- Die Aenderung behandelt die beobachtete Performanceverbesserung bewusst als Ergebnis des gesamten Minimalprofils und schreibt sie nicht allein `num_ctx` zu.
- Keine neue Datenbankmigration. Migration 009 bleibt der aktuelle Schema-Stand; Konten, Sitzungen, Nachrichten, Reaktionen, Revisionen, Anhaenge, Uploads, private RAG-Daten und lokale Konfiguration bleiben erhalten.
- Version, Cache-Buster, Setup, README, Changelog-Archiv, Preflight-relevante Versionsreferenzen, Uebergabeprotokoll, Release-ZIP und SHA-256 auf `0.5.3-alpha` aktualisiert.

## v0.5.2-alpha - 02.09.2026

- Produktiv gemeldeten Stillstand bei exakten Rechenauftraegen wie `123456789 * 987654321` behoben. Der bisherige Pfad konnte erst eine Streaming-Generierung, danach den synchronen Modellfallback, den Python-Worker und einen weiteren Modellaufruf starten.
- Deterministischen lokalen Schnellpfad fuer genau einen binaeren Ganzzahlausdruck eingefuehrt. Addition, Subtraktion und Multiplikation mit `*`, `x` oder `×` werden ohne Ollama und ohne Python abgeschlossen.
- Eigene dezimale String-Arithmetik fuer vorzeichenbehaftete Operanden bis 512 Ziffern implementiert. Das Ergebnis bleibt unabhaengig von der PHP-Integerbreite exakt.
- Schnellpfad bewusst auf eindeutige Aufgaben begrenzt. Anhaenge, Bilder, Dezimalzahlen, Divisionen, Potenzen, Kettenrechnungen, mehrere numerische Vorgaben und verlangte Rechenwege bleiben im normalen Modell- und Werkzeugpfad.
- Privaten SSE-Pfad vor dem Promptbau um die lokale Berechnung erweitert. Das Ergebnis wird weiterhin als regulaere Ember-Nachricht mit derselben `reply_to_id` gespeichert und ueber das normale `done`-Ereignis abgeschlossen.
- Fuer eine lokal abgeschlossene Rechnung wird kein Console-AFK gesetzt. Modell- und Werkzeuglaeufe behalten ihr bisheriges AFK-Verhalten.
- Nachgelagerte Memory-, Reflexions- und Reputationsauswertung fuer den lokalen Rechenschritt unterbunden. Das Ergebnis bleibt als Chatnachricht gespeichert, startet aber keinen verborgenen weiteren Modellaufruf und wird nicht als Langzeitgedaechtnis behandelt.
- Gemeinsamen synchronen Antwortgenerator ebenfalls abgesichert, damit globale oder nicht streamende Aufrufer denselben exakten Abschluss erhalten.
- Metadaten des lokalen Abschlusses explizit gesetzt, damit kein alter Guardrail-, Thinking- oder Timeoutzustand in den neuen Turn hineinragt.
- Reply-Pipeline-Selftest um den gemeldeten Multiplikationsfall, grosse Zahlen, Vorzeichen, Uebertraege, negative Ergebnisse und strikte Ablehnungsfaelle erweitert.
- Nach dem ersten `0.5.2-alpha`-Paket gemeldeten Preflight-Fehler korrigiert: Ein Punkt oder Komma direkt nach dem zweiten Operanden gilt nun als Satzzeichen und nicht mehr pauschal als Dezimaltrennzeichen.
- Schnellpfad auf ASCII-Ganzzahlen begrenzt und ungueltige Operanden werden sicher abgelehnt. Unicode-Ziffern koennen dadurch nicht mehr als falsches Ergebnis `0` enden.
- Routing gegen Bereiche, Kapitel- und Episodenangaben, Hexadezimalwerte sowie deutsche und englische Erklaerungswuensche gehaertet.
- Beschriftete Bereiche besitzen Vorrang vor Rechenwoertern, waehrend eindeutige Ergebnisformulierungen wie `Was ergibt 12*12?` und `Nenne das Ergebnis von 12*12.` lokal bleiben.
- Lokale Rechenschritte loeschen keinen fremden Console-AFK-Status mehr. Der synchrone Pfad prueft die Rechnung vor dem globalen Ollama-Lock.
- Regressionstest um Satzzeichen, Unicode-Ziffern, `0x10`, Bereichsangaben, englische Erklaerungswuensche, AFK-Eigentum und lockfreien Synchronabschluss erweitert.
- Keine neue Datenbankmigration. Migration 009, Konten, Sitzungen, Nachrichten, Reaktionen, Revisionen, Anhaenge und lokale Konfiguration bleiben unveraendert erhalten.
- Version, Cache-Buster, Setup, README, Changelog-Archiv, Branding-Test, Preflight und Uebergabeprotokoll auf `0.5.2-alpha` aktualisiert.

## v0.5.1-alpha - 01.09.2026

- Praktischen Produktivtest von `0.5.0-alpha` ausgewertet: Markdown, Codebloecke, Kopieren, Neugenerierung, Antwortdetails und alle bestehenden Werkzeuge funktionierten; persistentes Feedback und der gewuenschte Bearbeitungsfluss benoetigten Korrekturen.
- Feedbackspeicherung fuer bereits bestehende STU-Reaktionstabellen gehaertet. Migration `009_message_editing` erweitert den privaten Kanalwert auf `console`, normalisiert die Emoji-Spalte auf `utf8mb4` und CoreUI speichert neue Bewertungen als altkompatible ASCII-Tokens.
- Fehlerhafte Feedbackaufrufe bleiben nicht mehr als stilles rotes Aufblitzen stehen, sondern zeigen zusaetzlich einen konkreten Aktionsfehler an.
- Alternative Ember-Antworten werden nach `reply_to_id` gruppiert. Eine Vorher-/Naechster-Navigation mit Zaehler zeigt alle dauerhaft gespeicherten Varianten auch nach einem Seitenreload.
- Fortsetzungen bleiben an ihre konkrete Quellvariante gebunden und werden nur zusammen mit dieser Variante angezeigt.
- Bearbeiten einer Benutzernachricht arbeitet nun wirklich auf derselben Nachrichten-ID und derselben Blase. Der bisherige "als neue Nachricht"-Entwurfspfad wurde entfernt.
- Migration 009 fuehrt eine kontogebundene Revisionshistorie und den Generierungsmodus `edit` ein. Der Verlauf vor der bearbeiteten Nachricht bleibt Modellkontext; spaetere, auf dem alten Text beruhende Nachrichten werden aus dem aktiven Zweig ausgeblendet und bleiben im Kontoexport nachvollziehbar.
- Modellkontext und SSE-Idempotenz ignorieren ausgeblendete Nachrichten konsequent.
- Der Nachrichteneditor bleibt waehrend einer laufenden Ember-Antwort benutzbar. Abgesendete Folgetexte erscheinen als wartende Benutzer-Turns und werden nach Abschluss der aktuellen Antwort automatisch gesendet.
- Vorgemerkte Texte koennen vor dem Senden wieder entfernt werden. Der vorhandene lokale Sende-Cooldown wird beim automatischen Weiterreichen eingehalten.
- Fortsetzungsaktion verstaendlicher als Aktion fuer unvollstaendige Antworten beschriftet.
- Kontoexport um Nachrichtenrevisionen und ausgeblendete Zweige erweitert, ohne Thinking-Inhalte oder temporaere Anforderungstoken offenzulegen.
- Healthcheck, Compose- und Native-Preflight, Installer und Nachrichtenaktionen-Selftest auf Migration 009, Revisionsspeicherung, ASCII-Feedback, echte Bearbeitung und Variantenanzeige erweitert.
- Version, Cache-Buster, Setup, README, Changelog-Archiv, Branding-Test und Uebergabeprotokoll auf `0.5.1-alpha` aktualisiert.

## v0.5.0-alpha - 01.09.2026

- Sicheren lokalen Markdown-Renderer fuer Ueberschriften, Hervorhebungen, Listen, Zitate, Links, Tabellen, Inline-Code und eingezaeunte Codebloecke eingefuehrt.
- Rohes HTML bleibt immer Text. Nur HTTP-, HTTPS- und Mail-Links werden klickbar. Der Renderer verwendet weder `innerHTML` noch `eval`, Remote-CDNs oder ausfuehrbare Modellausgaben.
- Codebloecke um Sprachkennung, lokale Syntaxfarben, horizontales Scrollen und eine eigene Kopieraktion erweitert.
- Gemeinsame Aktionsleiste unter Benutzer- und Ember-Nachrichten eingefuehrt. Benutzernachrichten koennen kopiert oder als neuer Entwurf in den Editor uebernommen werden, ohne den gespeicherten Verlauf zu veraendern.
- Ember-Antworten koennen kopiert, positiv oder negativ bewertet, als Alternative neu erzeugt, fortgesetzt und mit technischen Antwortdetails angezeigt werden.
- Feedback serverseitig an authentifiziertes Konto, private Sitzung und konkrete Ember-Nachricht gebunden. Positive und negative Bewertung schliessen sich je Benutzer gegenseitig aus.
- Alternative Antworten behalten die bisherige Antwort und dieselbe `reply_to_id`. Antworten desselben Turns werden beim Neuaufbau gezielt aus dem Modellkontext ausgeschlossen, damit die Alternative nicht nur die alte Fassung kopiert.
- Fortsetzungen als getrennte Ember-Nachrichten gespeichert und weiterhin mit dem urspruenglichen Benutzer-Turn verknuepft.
- Migration `008_message_actions` fuer kurzlebige, kontogebundene Neugenerierungsanforderungen eingefuehrt. Eine feste Nachrichten-ID-Grenze ordnet nach SSE-Unterbrechungen nur wirklich spaeter entstandene Antworten zu, ohne temporaere Anforderungstoken im Kontoexport offenzulegen.
- Gleichzeitige Nachrichtenaktionen derselben Sitzung werden durch eine Datenbanksperre serialisiert. Eine Sitzung kann nicht endgueltig geloescht werden, solange eine Alternative oder Fortsetzung noch laeuft.
- Neue API `console_actions.php` mit CSRF-Schutz, Benutzer- und Sitzungspruefung sowie getrenntem Store-Modul fuer Feedback und Generierungsanforderungen eingefuehrt.
- Live-SSE-Antworten und geladener Verlauf verwenden nun denselben Markdown-, Codeblock- und Aktionsleistenpfad.
- Browser-Recherche innerhalb einer Nachrichtenaktion wird ueber ihre exakte Job-ID mit der Anforderung verbunden. Wiederaufnahme, Workerfehler und fehlendes Ergebnis werden dadurch eindeutig abgeschlossen.
- Kontoexport um eigene Bewertungen und Generierungshistorie erweitert. Interne Thinking-Inhalte und temporaere Anforderungstoken bleiben ausgeschlossen.
- Healthcheck, Compose- und Native-Preflight, Installer und Selbsttests auf Migration 008 und die neuen Endpunkte erweitert.
- Neuer Markdown-Selftest prueft gefaehrliche Linkprotokolle, HTML-Text, Tabellen und Codebloecke. Neuer Nachrichtenaktionen-Selftest prueft Feedback, Turnbindung, Isolation und sichere Renderer-Verdrahtung.
- Version, Cache-Buster, Setup, README, Changelog-Archiv, Branding-Test und Uebergabeprotokoll auf `0.5.0-alpha` aktualisiert.
- Audioeingabe, nativer Modell-Audiotransport und gesprochene TTS-Ausgabe bleiben bewusst dem getrennten Audioausbau vorbehalten.

## v0.4.5-alpha - 31.08.2026

- Navigationsschleife zwischen Einstellungen und Admin Core behoben. `CORE CHANNEL` verwendet kein `history.back()` mehr, sondern oeffnet die App deterministisch.
- Rueckweg aus Admin Core ersetzt den Admin-Verlaufseintrag. Die Abfolge Core Channel, Einstellungen, Admin Core, Einstellungen und Core Channel bleibt dadurch bedienbar.
- TXT-Decodierung vor die Unicode-Bereinigung verschoben. Ungueltig decodierte Bytes koennen den Inhalt nicht mehr vorzeitig in einen leeren String verwandeln.
- UTF-8 mit BOM, UTF-16 LE/BE mit und ohne BOM, UTF-32 mit BOM sowie Windows-1252 als reale TXT-Varianten ergaenzt.
- Binaerpruefung fuer falsch benannte Textdateien eingebaut. Auffaellige C0-Steuerzeichen werden nicht als vermeintlicher Text an das Modell weitergereicht.
- Anhang-Pipeline-Selftest um deutsche Umlaute, Windows-1252 und mehrere UTF-Codierungen erweitert.
- Chat-Profilbilder von einem nicht ueberpruefbaren CSS-Hintergrund auf echte `<img>`-Elemente mit Lade- und Fehlerbehandlung umgestellt.
- Initialen bleiben sichtbar, bis das Benutzer- oder CoreAI-Bild erfolgreich geladen wurde. Fehlende oder unlesbare Dateien erzeugen wieder einen eindeutigen Fallback.
- Profil-API und Medienendpunkt verwenden nun dieselbe serverseitige Dateiaufloesung. Nicht vorhandene Datenbankdateien werden nicht mehr als gueltige Avatar-URL ausgegeben.
- Profil/RAG-Selftest erzeugt ein echtes PNG, prueft dessen kontogebundene URL und die lesbare Datei fuer den Medienendpunkt.
- Reply-Pipeline-Selftest definiert vor dem Laden der Installation keine Produktionskonstanten mehr. Die PHP-Warnung zu bereits definiertem `STU_EMBER_NUM_PREDICT` und verwandten Werten entfaellt.
- Reine Budgetpruefung als eigene Funktion aus dem Laufzeitpfad getrennt, damit historische 7200-Zeichen-Konfigurationen ohne Eingriff in `config.local.php` getestet werden koennen.
- Neuer Frontend-Regressions-Selftest fuer Navigation, Bildladezustand, Avatar-Fallback und gemeinsame Medienaufloesung in Compose und Native ergaenzt.
- Freigegebenes Original-Logo unter neuem Assetnamen eingebunden. Start, Login, Einstellungen und Admin Core verwenden dieselbe Datei ohne die fehlerhaften schwarzen Konturen.
- Logo-Selftest an den exakten SHA-256-Fingerabdruck des freigegebenen Originals gebunden. Die alte fehlerhafte Assetdatei wurde entfernt.
- Version, Cache-Buster, Setup, Kontoexport, README, Changelog-Archiv und Branding-Test auf `0.4.5-alpha` aktualisiert.
- Neues Uebergabeprotokoll dokumentiert Quellstand, Tests, Installationspfade, bekannte Grenzen und die geplanten Funktionsstufen ab `0.5.x`.
- Keine neue Datenbankmigration. Konten, Sitzungen, Nachrichten, Uploads, private RAG-Quellen, Profilbilder und lokale Konfiguration bleiben beim Update erhalten.

## v0.4.4-alpha - 31.08.2026

- Offizielles Repository auf die Starlight-Unit-Studio-Organisation umgestellt. Installer, README, Metadaten und Releasepfade verwenden nun `Starlight-Unit-Studio/coreui`.
- TTY-Abbruch der Blitzinstallation nach dem erfolgreichen Image-Build behoben.
- Die beiden temporaeren `docker compose run`-Aufrufe fuer PHP-Lint und Account-Bootstrap verwenden nun explizit `-T`.
- Bestehende und nicht-interaktive Installationen koennen dadurch ohne Pseudo-TTY weiterlaufen.
- Schnellinstallation in der README in einen getrennten Download- und Ausfuehrungsschritt aufgeteilt. Die eigentliche Installation laeuft damit direkt am echten Terminal.
- Irrefuehrende Root-Fehlermeldung in `setup.sh` korrigiert. Sie verweist nicht mehr auf den alten Pipe-Aufruf.
- Releasepaar, Paketwurzel und Pflichtdateien werden weiterhin vor jeder Aenderung an `/opt/ember-coreui` geprueft.
- Alle Upload-, RAG-Lite-, Python-, Datenschutz- und Installerkorrekturen aus `0.4.3-alpha` bleiben unveraendert enthalten.
- Game, Homepage, Repack, KeyHelp, Apache, Host-PHP und Game-Datenbank bleiben unangetastet.

## v0.4.3-alpha - 30.08.2026

- Blitzinstallationsbefehl wieder direkt am Anfang der README aufgenommen.
- `setup.sh` vollstaendig neu aufgebaut. ZIP und SHA-256-Datei werden in ein eindeutiges temporaeres Verzeichnis geladen, gemeinsam geprueft und erst danach nach `/opt/ember-coreui` uebertragen.
- Veraltete Festverdrahtung auf `0.3.2-alpha` entfernt. Der Installer prueft Paketordner, Versionsdatei und alle zwingenden Skripte, bevor eine bestehende Installation veraendert wird.
- Updatepfad des Blitzinstallers erhaelt `api/config.local.php`, `var`, Logs, Uploads, Chatmedien und Profilbilder. Erstinstallationen koennen weiter interaktiv oder ueber sichere Umgebungsvariablen laufen.
- Release- und Setup-Pruefung lehnt Pakete mit den historischen privaten Manuskriptdateien ab.
- Die zwei versehentlich mitgefuehrten privaten Studio-Dokumente aus dem Releasebaum entfernt. Sie werden nicht mehr installiert oder automatisch in RAG-Lite importiert.
- Migration `007_remove_private_studio_lore` ergaenzt. Sie entfernt ausschliesslich die historischen Quellen `bibel_v10_4` und `kompendium_v6` aus bereits vorhandenen globalen Ember-CoreUI-Chunks.
- Voreingestellte lokale Pfade zu privaten Studio-Manuskripten aus `api/config.php` entfernt. Der generische CLI-Importer bleibt fuer rechtmaessig bereitgestellte Betreiberquellen verfuegbar.
- Private Console-Nachrichten adressieren Ember nun serverseitig. Das Frontend haengt kein kuenstliches `@Ember` mehr an, wodurch reine Datei-Turns nicht mehr als einzelnes `@` im Modellprompt erscheinen.
- Gespeicherte 1:n-Anhangzuordnungen werden im SSE-Turn in stabiler Reihenfolge rekonstruiert und in interne Dateimarker umgewandelt, bevor die gemeinsame Anhangsauswertung laeuft.
- Neuer End-to-End-Anhangtest legt echte TXT-, Python-, DOCX- und Text-PDF-Dateien an, bindet sie transaktional an eine Nachricht, rekonstruiert sie wie der SSE-Pfad und prueft ihren Inhalt im Modellprompt.
- Maximal zehn Dateien pro Nachricht bleiben unveraendert. Rohe `[file:...]`-Marker und das historische Adresspraefix duerfen den Modellprompt nach dem Test nicht erreichen.
- Gemeinsamen `rag_lite.php`-Kern fuer Extraktion, UTF-8-Normalisierung, ueberschneidendes Chunking, Suchbegriffe und Ranking eingefuehrt.
- Privates RAG-Lite nutzt diesen gemeinsamen Kern, behaelt aber strikt benutzergebundene Quellen- und Chunk-Tabellen. Eine globale Tabelle ohne `user_id` wird nicht fuer private Daten wiederverwendet.
- Beide Modell-Promptpfade begrenzen privates Benutzerwissen hart auf `channel === 'console'`. Globale Chatantworten koennen keine privaten RAG-Chunks mehr erhalten.
- DOCX-Ueberschriften bleiben bei der Extraktion als Strukturhinweise erhalten. Volltextsuche verwendet fehlertolerantes OR-Ranking statt einer zu strikten Pflichtwort-Abfrage.
- Generische Fragen wie "Fass meine hochgeladene Datei zusammen" koennen kontrolliert auf die zuletzt aktualisierte eigene Wissensquelle zurueckfallen.
- Private RAG-Uploads zusaetzlich fuer Python-, CSV-, JSON-, XML-, YAML-, INI-, PHP-, JavaScript-, HTML-, CSS- und SQL-Textdateien freigegeben.
- Fehlenden Python-Queue-Worker in den Compose-Stack aufgenommen. Der vertrauenswuerdige Worker besitzt den Docker-Socket, der generierte Modellcode laeuft dagegen ausschliesslich in einem wegwerfbaren Container ohne Projekt-, Konfigurations- oder Datenbank-Mount.
- Eigenes `docker/pyworker`-Image und Native-systemd-Dienst ergaenzt. Das eigentliche Sandbox-Image `ember-py:1` wird beim ersten Start gebaut.
- Echter Python-Preflight reiht einen Job ein, wartet auf den Worker und prueft die Ausgabe aus dem isolierten Sandbox-Container.
- Compose-, Native-, Health-, Installer- und Preflight-Pfade auf Version `0.4.3-alpha`, Migration 007, Python-Worker und neue Pipeline-Selftests aktualisiert.
- Veraltete Zukunftsaussage zur STU Console aus der README entfernt. Die interne STU Console wurde bereits mit Game Alpha `v1.1.1.98` am 20.08.2026 entfernt; Ember CoreUI ist ihre eigenstaendige Nachfolge- und Referenzoberflaeche und ersetzt nicht den Global-Chat.
- Produktive Dokumentationsadresse bleibt `https://coreui.starlight-unit.de`.
- Game, Homepage, KeyHelp, Apache, STU-Repack, Game-Datenbank und andere Projektpfade bleiben unangetastet.

## v0.4.2-alpha - 29.08.2026

- Neue `Starlight Unit Studios Ember CoreUI Community Source Lizenz 1.0` eingefuehrt. Die deutsche Fassung in `LICENSE.de.md` ist rechtlich massgeblich; `LICENSE.md` enthaelt die englische Uebersetzung.
- Lizenzmodell als Source Available und nicht als OSI-zertifiziertes Open Source gekennzeichnet.
- Projekt-eigener Code bleibt fuer private, gemeinschaftliche und interne Nutzung kostenlos. Auch interne Unternehmensnutzung ist ohne Lizenzgebuehr erlaubt, solange alle Bedingungen und das Branding eingehalten werden.
- Nutzergrenze vollstaendig verworfen. Es gibt weder eine 50-Nutzer-Ausnahme noch irgendeine andere Schwelle fuer Branding-Aenderungen.
- Entfernung, Ersetzung, Verschleierung oder irrefuehrende Abschwaechung des Ember-CoreUI- und Starlight-Unit-Studios-Brandings fuer jede Bereitstellung untersagt.
- Verbindliche Namensregel aufgenommen: Der Produktname lautet immer `Ember CoreUI`; die alleinstehende Produktbezeichnung `CoreUI` ist fuer dieses Projekt unzulaessig.
- Technische Kennungen wie `coreui`, `ember-coreui`, `COREUI_*`, `/opt/ember-coreui`, `X-CoreUI-CSRF`, Repository-Slugs und Domainbestandteile als reine Kompatibilitaetsbezeichner dokumentiert.
- Kostenpflichtiges White-Label-, Enterprise-, Abonnement-, Spenden-, Werbe- und Supportmodell fuer das offizielle Ember-CoreUI-Projekt ausgeschlossen.
- Monetarisierten Weiterverkauf als SaaS oder Managed Service durch Dritte untersagt. Kostenlose Selbstbereitstellung und interne Unternehmensnutzung bleiben erlaubt.
- Schriftliche Branding-Ausnahmen nur als individuelle Ermessensentscheidung vorgesehen. Die aktuelle Community-Richtlinie verlangt dafuer keine Ember-CoreUI-Lizenzgebuehr und begruendet keinen Anspruch auf Genehmigung.
- Bei veraenderter Weitergabe muessen der vollstaendige entsprechende Quellcode, dieselbe Lizenz, Herkunftshinweise und das geschuetzte Branding erhalten bleiben. Rein interne Aenderungen muessen nicht veroeffentlicht werden.
- Ausdruecklich festgehalten, dass die Lizenz keine Nutzerzaehlung, Registrierung, Lizenztelemetrie oder Phone-home-Funktion verlangt.
- `LICENSE_HISTORY.md` ergaenzt. Fruehere Kopien behalten exakt die mit ihnen verbreiteten Bedingungen; Kopien mit ausdruecklichem CC-BY-NC-SA-4.0-Hinweis behalten diese Gewaehrung. Die neue Lizenz beginnt ohne rueckwirkende Neulizenzierung mit `0.4.2-alpha`.
- `TRADEMARKS.md`, `COMMUNITY_POLICY.md` und `NOTICE.md` fuer Markenfuehrung, kostenlose Projektpolitik, Herkunft und Drittkomponenten ergaenzt.
- Unabhaengigkeitserklaerung aufgenommen: Ember CoreUI ist kein Fork des getrennten Admin-Template- und UI-Komponentenprojekts unter `coreui.io`, enthaelt keine Abhaengigkeit davon und ist damit nicht verbunden.
- Sichtbare Produkttexte, Fehlermeldungen, Installer-Ausgaben, Quellkommentare und historische Dokumentation auf die vollstaendige Bezeichnung `Ember CoreUI` bereinigt. Protokollrelevante technische Header bleiben aus Kompatibilitaetsgruenden unveraendert.
- Oeffentliche Startseite um eindeutige Autoren-, Beschreibungs-, Open-Graph-, Canonical- und strukturierte `SoftwareApplication`-Metadaten ergaenzt. Darin werden Ember CoreUI, Starlight Unit Studios und der kostenlose Zugang maschinenlesbar zugeordnet. Authentifizierte Seiten tragen `noindex,nofollow`.
- Keine neue Datenbankmigration, keine Bezahlschranke, keine Lizenzschluessel und keine Aenderung an Benutzer-, Chat-, RAG-, Profil- oder Sitzungsdaten.
- Alle Funktionen und Sicherheitskorrekturen aus `0.4.1-alpha` unveraendert uebernommen.

## v0.4.1-alpha - 29.08.2026

- Kontosicherheitsbereich in den Benutzereinstellungen ergaenzt. Er trennt Geraete-Anmeldungen klar von den bereits vorhandenen Chat-Sitzungen.
- Sicheren Passwortwechsel mit aktuellem Passwort, Mindestlaenge 12, Bestaetigung, Rate-Limit, Passwort-Hashing und Audit eingefuehrt.
- Nach einem Passwortwechsel werden alle vorhandenen Login-Sitzungen widerrufen und ausschliesslich fuer das aktuelle Geraet ein frisches Token ausgegeben.
- Neue SQL-Tabelle `stu_auth_sessions` fuer widerrufbare Anmeldungen ergaenzt. In der Datenbank liegt nur SHA-256 des zufaelligen Tokens, niemals das Roh-Token.
- Bestehende authentifizierte PHP-Sitzungen werden beim ersten Aufruf transparent in die SQL-Schicht uebernommen.
- Aktive Anmeldungen zeigen Geraetetyp, Browser, letzte Aktivitaet und Ablaufzeit. Token und Token-Hash werden nie an den Browser gesendet.
- Einzelwiderruf einer fremden eigenen Anmeldung sowie Sammelwiderruf aller anderen Geraete umgesetzt. Die aktuelle Sitzung kann nicht versehentlich ueber den Einzelwiderruf entfernt werden.
- SQL-Anmeldungen laufen nach 30 Tagen Inaktivitaet ab und werden bei Aktivitaet hoechstens einmal pro Minute aktualisiert.
- Passwort-Reset widerruft nun ebenfalls alle aktiven Login-Sitzungen.
- Privaten JSON-Kontoexport ergaenzt. Enthalten sind Konto-Metadaten, Profil, Avatar-Daten, KI-Einstellungen, eigene Charaktere, eigene Memories, private RAG-Quellen und Chunks, Chat-Sitzungen, Nachrichten sowie Anhangmetadaten.
- Passwort-Hashes, Reset-Codes, Login-Token, Token-Hashes, interne Thinking-Inhalte, private Serverpfade und hochgeladene Dokumentbinaerdaten sind vom Export ausgeschlossen.
- Jede Exportabfrage ist serverseitig an die authentifizierte `user_id` gebunden. Globaler Studio-Kanon wird nicht als persoenlicher Besitz exportiert.
- Freie Texteingabe fuer Ollama-Modellnamen durch eine echte Auswahlliste der lokal installierten Modelle ersetzt.
- Der Modellkatalog wird nur von der betreiberkonfigurierten `STU_EMBER_OLLAMA_URL` abgeleitet. Benutzer koennen weder Host noch Protokoll beeinflussen; HTTP-Redirects sind deaktiviert.
- Eine Modellwahl wird unmittelbar vor dem Speichern erneut gegen `/api/tags` geprueft. Nicht installierte oder ungueltige Modellnamen werden abgelehnt.
- Serverstandard bleibt als explizite Option erhalten. Bereits gespeicherte, inzwischen entfernte Modelle werden sichtbar als nicht mehr installiert markiert.
- Nginx-Allowlists fuer Compose und Native um `account_security.php`, `account_export.php` und `models.php` erweitert.
- Migration `006_account_security` ergaenzt `password_changed_at`, `last_login_at` und die neue Auth-Sitzungstabelle mit Benutzer-Fremdschluessel und Aktivindex.
- Healthcheck, Compose-Preflight, Native-Preflight und beide Installer auf Migration 006 und die neuen Endpunkte erweitert.
- Neuer Account-Security-Selftest prueft Tokenabschirmung, CSRF- und Passwortgrenzen, Exportausschluesse, Modellvalidierung sowie SQL-Listen- und Widerrufslogik in einer zurueckgerollten Testtransaktion.
- PHP-Grenze `max_file_uploads` fuer Compose und Native auf zehn angeglichen. Die bestehende produktive Anhanglogik bleibt weiterhin bei maximal zehn Dateien je Nachricht.
- Versionsparameter, Healthcheck, README, TXT-Changelog-Archiv und Uebergabeprotokoll auf `0.4.1-alpha` aktualisiert.
- Produktive Adresse bleibt `https://coreui.starlight-unit.de`. Die fruehere Adresse `webui.starlight-unit.de` ist nur noch historischer Kontext.
- Game, Homepage, KeyHelp-VHosts, Apache, STU-Repack, Game-Datenbank, bestehende Ember CoreUI-Konfiguration und persistente Nutzerdaten bleiben unveraendert.

## v0.4.0-alpha - 29.08.2026

- Bisher teilweise lokale Einstellungsseite zu einem echten kontobezogenen Konfigurationsbereich ausgebaut. Profil, KI-Laufzeitwerte, Memory und privates Wissen werden serverseitig dem authentifizierten Benutzer zugeordnet.
- Eigenes Ember CoreUI-Profil je Benutzer mit sichtbarem Anzeigenamen eingefuehrt. Die Login-E-Mail, numerische Benutzer-ID und stabile interne Operator-ID bleiben bei einer Namensaenderung unveraendert.
- Frei waehlbaren CoreAI-Anzeigenamen je Konto ergaenzt. Die sichtbare Bezeichnung wird in Chatblasen, Denkstatus, Tippanzeige und Eingabeplatzhalter verwendet, ohne Embers technische Identitaet oder Sicherheitsregeln umzuschreiben.
- Zwei getrennte private Avatar-Slots aufgenommen: Benutzer und CoreAI. Beide koennen in den Einstellungen hochgeladen, ersetzt und geloescht werden.
- Avatarannahme auf PNG und JPEG bis 4 MiB begrenzt. Abmessungen, Pixelmenge, MIME-Typ und echte Dekodierbarkeit werden serverseitig geprueft.
- Hochgeladene Bilder werden zentral quadratisch zugeschnitten, auf maximal 512 mal 512 Pixel skaliert, neu als PNG kodiert und ohne fremde Bildmetadaten gespeichert.
- Avatar-Dateien aus dem statischen Webroot herausgehalten. `profile_media.php` liefert nur den Avatar des angemeldeten Kontos mit privatem Cache-Verhalten aus.
- Zufallsbasierte Medien-ID als exakten Cache-Buster verwendet. Auch zwei unmittelbar aufeinanderfolgende Avatar-Uploads zeigen sicher den neuesten Stand.
- Neue Profilendpunkte `profile.php` und `profile_media.php` samt gemeinsamem `profile_store.php` hinzugefuegt. Schreiboperationen verlangen Authentifizierung und das technische `X-CoreUI-CSRF`-Token.
- Automatische Altaccount-Provisionierung eingebaut. Bereits vorhandene Benutzer erhalten beim naechsten Login ein Profil und, falls bisher keiner existiert, einen stabilen privaten Operator.
- Gemeinsames transaktionales Account-Provisioning fuer Registrierung und Admin Core eingefuehrt. Neue Benutzer starten nicht mehr ohne Charakter- und Profildaten.
- Admin Core um eine echte Benutzeranlage mit Anzeigename, Login-E-Mail, Startpasswort und Rolle erweitert. System-Admin kann Level 1 bis 4, Admin kann nur niedrigere Rollen Level 2 bis 4 vergeben.
- Startpasswoerter aus dem Admin Core auf mindestens 12 Zeichen begrenzt. Passwortwerte werden weder im Audit noch in API-Antworten gespeichert oder ausgegeben.
- Benutzerliste um Profilname und internen Operator erweitert. Suche findet jetzt Login-E-Mail, sichtbaren Namen und Operatornamen.
- Einstellungsseite um einen optionalen lokalen Ollama-Modelltag erweitert. Der gespeicherte `model_override` wird nun im gemeinsamen Chatpfad tatsaechlich ausgewertet; leer verwendet weiterhin den Serverstandard.
- System-Prompt, Memory-Schalter, Memory-Limit, Antwortbudget und Temperatur bleiben pro Benutzer gespeichert und werden gemeinsam mit Profil und Modellwahl geladen.
- Kontobezogenen Modell-Thinking-Schalter ergaenzt. Ember CoreUI sendet den offiziellen Ollama-Parameter `think` bei Hauptantworten als `true` oder `false`, statt nur die Anzeige zu verstecken.
- Bei deaktiviertem Thinking werden keine Denkstatus-Ereignisse und kein Denkstatus in neuen Verlaufszeilen gespeichert. Die bestehende Schutzschranke bleibt auch bei aktiviertem Thinking bestehen: Rohe Modellgedanken verlassen den Server nicht.
- Composer auf bis zu zehn Dateien pro Nachricht erweitert. Mehrfachauswahl, sequentieller Upload mit Fortschritt, einzelne Entfernung vor dem Senden und Wiederherstellung der Auswahl bei einem Sendefehler sind enthalten.
- Vor dem Senden entfernte Uploads werden ueber einen CSRF-geschuetzten Eigentuemercall geloescht, sofern keine Nachricht sie inzwischen referenziert. Zeilensperren schliessen das Rennen zwischen Entfernen und gleichzeitigem Nachrichten-INSERT.
- Neue Zuordnungstabelle `stu_console_message_attachments` speichert Anhaenge geordnet und benutzergebunden. `file_uuid` bleibt nur fuer Altclients und bereits vorhandene Einzeleintraege erhalten.
- Nachrichtenverlauf und optimistische Clientzeilen liefern jetzt ein `attachments`-Array. Alte Clients koennen weiterhin das erste Element ueber das kompatible Feld `attachment` lesen.
- Serverseitige Eigentumspruefung fuer jede Anhang-UUID eingebaut. Fremde, fehlende oder mehr als zehn UUIDs werden vor dem Nachrichten-INSERT abgelehnt; Nachricht und Zuordnungen werden gemeinsam transaktional gespeichert.
- Gemeinsame Anhangsauswertung auf bis zu zehn Text-, Dokument- oder Mediendateien erweitert. Das Textbudget wird kontrolliert aufgeteilt und Vision-Eingaben werden auf hoechstens 16 Bilder, Frames oder PDF-Seiten begrenzt.
- Sitzungsloeschung um Mehrfachanhaenge erweitert. Zuordnungen werden per Fremdschluessel entfernt und Mediendateien nur geloescht, wenn weder alte `file_uuid`- noch neue 1:n-Referenzen verbleiben.
- Externe OpenAI-kompatible Provider bleiben als deaktivierter Adapter vorbereitet. Der lokale Gemma-4- und Ollama-Pfad bleibt Referenzbetrieb; API-Schluessel gelangen weiterhin nie in Browser oder Datenbankeinstellungen.
- Privates RAG-Lite direkt in den Benutzereinstellungen eingefuehrt. TXT-, Markdown-, Text-PDF- und DOCX-Dateien koennen hochgeladen, gelesen, gechunkt, gelistet und vollstaendig geloescht werden.
- Textnormalisierung, kontrollierte Unicode-Konvertierung und Chunking mit standardmaessig 1400 Zeichen sowie 180 Zeichen Ueberlappung umgesetzt.
- PDF-Import verwendet lokal `pdftotext` mit begrenzter Laufzeit. Scan-PDFs ohne Textebene werden eindeutig abgelehnt, statt einen erfundenen Dokumentinhalt zu erzeugen.
- DOCX-Import liest ausschliesslich `word/document.xml`, deaktiviert externe XML-Netzwerkzugriffe und begrenzt XML-Groesse sowie Absatzanzahl.
- Private Wissensquellen standardmaessig auf 20 MiB je Datei, 40 Quellen, 5.000.000 Zeichen und 1200 Chunks je Einzeldokument begrenzt. Betreiber koennen die Hauptquoten ueber isolierte App-Settings steuern.
- Kontospezifischen MariaDB-Lock um Quotenpruefung und Persistenz gelegt. Gleichzeitige Uploads desselben Benutzers koennen die Quelle- oder Zeichenquote nicht gemeinsam umgehen.
- Originaldateien unter `var/knowledge_uploads` gespeichert und nie direkt durch Nginx ausgeliefert. Quellenmetadaten und Chunks liegen in eigenen Ember CoreUI-Tabellen.
- RAG-Suche filtert in Volltext- und LIKE-Fallback immer nach `user_id` und Quelle. Ein Benutzer kann keine Chunks eines anderen Kontos finden oder loeschen.
- Privates Benutzerwissen klar vom globalen Studio-Kanon in `ember_knowledge_chunks` getrennt. Uploads veraendern weder Bibel- noch Kompendium-Importe.
- Relevante private Chunks in den Modellprompt aufgenommen und ausdruecklich als nicht vertrauenswuerdige Daten markiert. Dokumentanweisungen erhalten keine Systemprioritaet.
- Quellenloeschung entfernt Metadaten und Chunks in einer SQL-Transaktion und danach die gespeicherte Originaldatei. Die Oberflaeche aktualisiert Zaehler und Liste aus der Serverantwort.
- Migration `004_profiles_knowledge` hinzugefuegt. Sie erstellt Profile, Profilmedien, private Wissensquellen und private Chunks mit Benutzer- und Quellindizes, Fremdschluesseln und Volltextindex.
- Migration `005_thinking_attachments` hinzugefuegt. Sie ergaenzt den Thinking-Schalter, erstellt die 1:n-Anhangzuordnung und uebernimmt bestehende `file_uuid`-Eintraege verlustfrei.
- App-Defaults fuer RAG-Quellenzahl, Dateigroesse und Gesamtzeichen in Migration 004 aufgenommen, ohne vorhandene Betreiberwerte zu ueberschreiben.
- Neue private Speicherpfade `var/profile_media` und `var/knowledge_uploads` als eigene beschreibbare PHP-Volumes eingebunden. Der Webcontainer besitzt keinen direkten Mount fuer diese Dateien.
- `stack.sh` legt die neuen privaten Verzeichnisse bei `up`, `start` und `restart` mit passenden Containerrechten an. Das funktioniert auch beim normalen Update, obwohl `var/` absichtlich vom `rsync` ausgeschlossen bleibt.
- Compose- und Native-Nginx-Allowlists um Profil-, Medien- und Knowledge-Endpunkte erweitert. Unauthentifizierte Routenpruefungen erwarten bewusst HTTP 401.
- Healthcheck um Migrationen 004 und 005, alle neuen Tabellen, die Thinking-Spalte und beide privaten Schreibpfade erweitert.
- Eigenen transaktionalen Profil- und Knowledge-Selftest hinzugefuegt. Er prueft Account-Provisioning, Namen, Chunker, Volltextabruf und besonders die Trennung zweier Benutzer und rollt seine Testdaten vollstaendig zurueck.
- Reply-Pipeline- und Sitzungs-Selftests um Thinking-Wahl, Zehn-Dateien-Grenze, persistente Zuordnung sowie deren transaktionale Loeschung erweitert. Der Preflight prueft zusaetzlich PHP-Erweiterungen, Routen, Storage-Rechte und die vollstaendige Verdrahtung der 0.4.0-Funktionen.
- Nativen Fallback-Installer auf Migration 004 und die neuen privaten Speicherpfade angeglichen. Standard-Compose- und Native-Neuinstallation besitzen damit denselben Schema- und Funktionsstand.
- README, TXT-Changelog-Archiv und Uebergabeprotokoll auf `0.4.0-alpha` sowie die produktive Adresse `https://coreui.starlight-unit.de` aktualisiert.
- Echte Sitzungen, archivgeschuetzte endgueltige Loeschung, Thinking-Schranke, lange Ollama-Antworten, Browse-Frames, Game, Homepage, KeyHelp, Apache, Host-PHP, Repack-Pfade und bestehende STU-Datenbank bleiben unveraendert erhalten.

## v0.3.2-alpha - 27.08.2026

- Endgueltige Sitzungsloeschung als eigenstaendige Serverfunktion ergaenzt. Die bisherige Aktion `delete` archivierte lediglich; `delete_permanently` fuehrt nun eine echte SQL-Loeschung aus.
- Schutzfolge bewusst zweistufig gestaltet: Aktive Sitzungen lassen sich nur archivieren. Der rote Befehl `ENDGÜLTIG LÖSCHEN` erscheint ausschliesslich bei archivierten Sitzungen und verlangt zusaetzlich die Texteingabe `LÖSCHEN` oder `LOESCHEN`.
- Serverseitige Bestaetigung mit dem konkreten Sitzungsbezug `DELETE:<session_id>` eingefuehrt. Eine manipulierte Oberflaeche kann die Archivpflicht oder die Bestaetigung nicht umgehen.
- Eigentuemerpruefung und Zeilensperre mit `SELECT ... FOR UPDATE` direkt vor der Loeschung aufgenommen. Fremde, bereits entfernte oder zwischenzeitlich wiederhergestellte Sitzungen werden abgelehnt.
- Laufende und wartende Browser-Recherchen schuetzen die Sitzung vor Loeschung. Jobs im Zustand `queued` oder `running` liefern `session_busy`, statt dem Worker seine Daten waehrend der Verarbeitung zu entziehen.
- Vollstaendige SQL-Transaktion fuer alle eindeutig sitzungsgebundenen Datensaetze umgesetzt. Sie entfernt Reaktionen, Browserframes, Browserschritte, abgeschlossene Browserjobs, Chatnachrichten, verwaiste Console-Medienzeilen und zuletzt die Sitzung selbst.
- Fehlerbehandlung auf vollstaendiges Rollback ausgelegt. Scheitert eine einzelne SQL-Operation, bleiben Sitzung und alle abhaengigen Zeilen unveraendert erhalten.
- Upload-Bereinigung referenzsicher gemacht. Ein `stu_console_media`-Datensatz wird nur entfernt, wenn seine UUID nach dem Nachrichten-DELETE nirgends mehr referenziert wird.
- Physische Mediendateien und der Abschluss-Screenshot entfernter Browserjobs werden erst nach erfolgreichem Datenbank-Commit entfernt. Der Pfadfilter erlaubt ausschliesslich einzelne Dateien unter `var/console_media`, `assets/chat_media` und `uploads/ember_browse`; Traversal, fremde Pfade und Symlink-Ziele ausserhalb werden abgewiesen.
- Loeschergebnis liefert getrennte Zaehler fuer Nachrichten, Reaktionen, Jobs, Schritte, Frames, Medienzeilen sowie geloeschte, fehlende oder nicht entfernbare Dateien. Die Oberflaeche bestaetigt damit die tatsaechliche SQL-Wirkung.
- Archivierung waehrend einer noch offenen lokalen Antwort blockiert. Dadurch kann auch ein direkter, noch nicht als Browse-Job sichtbarer Modellturn nicht versehentlich aus der aktiven Ansicht entfernt werden.
- Browser-Runtime-Caches, Poll-Timer, Pending-Zustand, Verlaufsseiten und Lesecursor der geloeschten Sitzung werden gezielt verworfen; andere Sitzungen bleiben unberuehrt.
- Sitzungs-Selftest um eine produktionsgleiche Loeschtransaktion erweitert. Er legt zwei isolierte Testsitzungen sowie Nachricht, Antwort, Reaktion, Upload, Browse-Job, Schritt und Frame an, prueft Archivpflicht und Busy-Sperre, loescht nur Sitzung A und rollt anschliessend den gesamten Test zurueck.
- Fuer diese Funktion ist keine neue DDL-Migration erforderlich. `0.3.2-alpha` nutzt die in `003_console_sessions` vorhandenen Indizes und fuehrt die eigentliche Loeschung als transaktionales SQL-DML aus.
- Oeffentliche Basisadresse bleibt `https://coreui.starlight-unit.de`. Die erhaltene lokale `config.local.php`, Benutzerkonten, KI-Profile, Memories, Lore, Game, Homepage und STU-Datenbank werden nicht veraendert.

## v0.3.1-alpha - 27.08.2026

- Unfertige Demo-Sitzungen durch echte serverseitige Chat-Sitzungen ersetzt. `stu_chat_messages.session_id` ist jetzt die verbindliche Zugehoerigkeit jeder privaten Nachricht.
- Exakte Turn-Zuordnung mit `reply_to_id` eingefuehrt. Eine Ember-Antwort kann nicht mehr nur deshalb einer falschen Anfrage zugeordnet werden, weil ihre Nachrichten-ID spaeter liegt.
- Eigenen History-Endpunkt `api/console_messages.php` hinzugefuegt. Er prueft Authentifizierung, Sitzungsbesitz und Charakter und filtert jede Abfrage nach Benutzer plus Sitzung.
- Fehlerhafte History-Heuristik `since_id - 80` entfernt. Die neueste Seite wird korrekt rueckwaerts geladen; aeltere Seiten werden ueber einen echten `before_id`-Cursor nachgeladen.
- Instabilen `activeIdx` im Browser durch eine feste `activeSessionId` ersetzt. Sortiert sich die Liste neu, bleibt die geoeffnete Unterhaltung unveraendert.
- Asynchrone History-, Poll-, SSE- und Browser-Callbacks gegen Sitzungswechsel abgesichert. Spaete Antworten duerfen nur ihre Ursprungssitzung aktualisieren und niemals den gerade sichtbaren fremden Verlauf.
- Console-SSE verlangt jetzt `session_id`, laedt den User-Turn aus genau dieser Sitzung und dedupliziert nur ueber die Kombination aus Sitzung plus `reply_to_id`.
- Modellkontext fuer private Unterhaltungen nach `session_id` isoliert. Ohne gueltige Sitzungs-ID liefert der Kontextpfad absichtlich keinen Verlauf.
- Browser-Recherchejobs um `session_id` und `trigger_message_id` erweitert. Systemstatus, finales Rechercheergebnis und Live-Browser-SSE bleiben bis zum Workerabschluss am urspruenglichen Turn gebunden.
- Sitzungsmetadaten um `last_message_id`, `last_read_message_id` und `archived_at` erweitert. Lesemarkierungen veraendern die Aktivitaetsreihenfolge nicht mehr.
- Automatische MariaDB-Aktualisierung von `updated_at` entfernt. Nur echte neue Nachrichten oder eine bewusste Wiederherstellung sortieren Sitzungen neu.
- Automatisches Loeschen der jeweils aeltesten Sitzung entfernt. Es gilt ein klares Limit von 100 aktiven Sitzungen; Archivierung bleibt reversibel und Nachrichten werden nicht geloescht.
- Kanalweite Game-Chat-Kommandos im privaten Console-Kanal deaktiviert, einschliesslich des seltenen modellgenerierten Fallbackpfads ohne SSE. Insbesondere kann ein Console-Text keinen Verlauf anderer Sitzungen ueber einen globalen Prune-Pfad veraendern.
- Sitzungsnamen koennen direkt im Drawer umbenannt, verlustfrei archiviert und ueber die neue Archivansicht wiederhergestellt werden. Neue Unterhaltungen erhalten nach der ersten Nachricht weiterhin automatisch einen sinnvollen Titel.
- Unread-Zaehler, Nachrichtenanzahl, stabile Auswahl nach Reload und Cache pro Sitzung in die Oberflaeche aufgenommen.
- Bereits bestaetigte Nachrichten bleiben bei einem kurzzeitigen History- oder Sitzungslistenfehler sichtbar. Fehlerantworten leeren weder den Sitzungscache noch die lokale Liste.
- Der Hintergrund-Poll beendet den Wartestatus nur noch bei einer Ember-Antwort, deren `reply_to_id` exakt dem offenen Turn dieser Sitzung entspricht.
- Migration `003_console_sessions` hinzugefuegt. Alte, wegen des Demo-Modells nicht verlaesslich trennbare Console-Nachrichten werden pro Benutzer verlustfrei in `WIEDERHERGESTELLTER VERLAUF` gesammelt; leere Demo-Zeiger werden lediglich archiviert.
- `stack.sh up`, `start` und `restart` wenden neue idempotente Migrationen automatisch vor dem Dienststart an. Der explizite Befehl `stack.sh migrate` bleibt verfuegbar.
- Nginx-Allowlists fuer den neuen authentifizierten Endpunkt `console_messages.php` in Compose und Native aktualisiert.
- Eigenen `session-selftest.php` und Preflight-Pruefungen fuer Schema, Scope, Turn-Zuordnung, Legacy-Erhalt, Client-Rennen und Worker-Weitergabe aufgenommen.
- Produktive Dokumentationsadresse von `webui.starlight-unit.de` auf `coreui.starlight-unit.de` aktualisiert. Die lokale `config.local.php` bleibt bei Updates absichtlich erhalten.
- Thinking-Schranke, lange Ollama-Antworten, Benutzer-KI-Einstellungen, Admin Core, Game, Homepage, KeyHelp, Apache, Host-PHP und Repack-Pfade bleiben unveraendert.

## v0.3.0-alpha - 27.08.2026

- Console-SSE auf gepruefte Endausgabe umgestellt. Der Browser erhaelt keine Roh-Tokens aus `message.content` oder `message.thinking`, sondern erst die vollstaendige serverseitig bereinigte Antwort.
- Thinking-Schranke fuer Live-SSE, Datenbankpersistenz, History, Reconnect und Client-DOM geschlossen. Deutsche und englische Prompt-, Entwurfs-, Analyse- und Selbstkorrekturmuster werden fail-closed behandelt.
- Ollama-Abschlussmetadaten `done`, `done_reason` und `eval_count` werden ausgewertet. Antworten am Tokenlimit werden mit bis zu zwei gezielten Segmenten fortgesetzt, ohne die komplette Anfrage erneut zu generieren.
- Frueheren 1000-Token-Deckel entfernt. Das per Benutzer gesetzte `num_predict` wird bis 16384 an Ollama weitergegeben; der gemeinsame Modellpfad erlaubt technisch bis 32768.
- Console-Modelltimeout auf bis zu 900 Sekunden angeglichen. SSE-Heartbeats halten Reverse Proxy und Browser waehrend lokaler langer Generierungen aktiv.
- Erhaltene 0.2.x-Konfigurationen mit `STU_EMBER_MAX_REPLY_CHARS=7200` koennen neue Antworten nicht mehr still kuerzen. Die sichere Ausgabegrenze skaliert mit dem Tokenbudget bis 60000 Zeichen.
- Private Console-Eingaben auf 12000 Zeichen ohne und 20000 Zeichen mit Anhang erweitert. Zeilenumbrueche, Leerzeilen und Code-Einrueckungen bleiben erhalten.
- EventSource uebergibt nur noch die authentifizierte Turn-ID. Lange oder vertrauliche Nachrichten werden aus der Ember CoreUI-Datenbank geladen und landen weder in der URL noch in Proxy-Zugriffslogs.
- Privaten History-Kontext auf bis zu 1200 Zeichen je Nachricht erweitert. Die engen Global-Chat-Grenzen bleiben fuer den Game-Pfad unveraendert.
- Migration `002_coreui_management` erweitert `stu_chat_messages.message` auf `MEDIUMTEXT` und fuegt Benutzer-KI-Profile sowie Admin-Audit hinzu.
- Servergespeichertes KI-Profil je Benutzer hinzugefuegt: System-Prompt, Memory-Schalter, Memory-Limit, Antwortbudget und Temperatur.
- Memory-Verwaltung in den Ember CoreUI-Einstellungen ergaenzt. Benutzer koennen eigene sichtbare Fakten anlegen und loeschen; globale Fakten bleiben geschuetzt.
- Externen Provider als deaktivierte, serverseitig vorbereitete Schnittstelle aufgenommen. API-Schluessel werden nie im Browser gespeichert; lokales Gemma 4 ueber Ollama bleibt der Referenzbetrieb.
- Eigenstaendiges Admin Core mit Uebersicht, Benutzerrechten, Sperren, Memory CRUD, Lore-Suche, Runtime-Schaltern, Registrierung, Wartungsmodus, Logs und Audit hinzugefuegt.
- Werkzeugpfade fuer schnelle Websuche, tiefe Browser-Recherche und isoliertes Python koennen zentral im Admin Core aktiviert oder deaktiviert werden.
- Berechtigungsstufen und aktive Sperren werden bei jedem authentifizierten Request aus der Ember CoreUI-Datenbank aktualisiert und wirken auf bestehende Sitzungen.
- Gehaertete Nginx-Allowlists fuer `settings.html`, `admin/index.html`, `user_settings.php` und `admin.php` in Compose und Native aktualisiert.
- Transparentes Studio-Logo unter `starlight_unit_studios_logo_transparent_v030.png` neu referenziert. Der eindeutige Dateiname umgeht den bis zu sieben Tage alten Browsercache der frueheren schwarzen Datei.
- Preflight um Migration-002-Pruefung, Admin-Route, Reply-Pipeline-Selftest, 15 Thinking-Regressionen und Logo-Cache-Buster-Test erweitert.
- Game, Homepage, KeyHelp, Apache, Host-PHP, Repack-Pfade und bestehende STU-Datenbank bleiben unveraendert.

## v0.2.9-alpha - 19.08.2026

- Rohe `message.thinking`-Tokens aus dem Console-SSE entfernt. Der Server akkumuliert sie nur noch fluechtig fuer die abschliessende Antwortbereinigung und sendet stattdessen feste, inhaltsfreie Fortschrittsstufen.
- Denkpanel zum sicheren `DENKSTATUS` umgebaut. Promptteile, Identitaetsdaten, Systemanweisungen, Entwuerfe und Selbstkorrekturen werden weder live noch aus der Historie in den DOM geschrieben.
- Persistenzschranke in `ember_insert()` ergaenzt: Neue Console-Antworten speichern ausschliesslich einen festen Prozessstatus, niemals den rohen Thinking-Block.
- Console-Fetch fail-closed gehaertet. Historische `thinking_content`-Werte werden serverseitig durch einen sicheren Abschlussstatus ersetzt, bevor die JSON-Antwort erzeugt wird.
- Einmalige Datenbankbereinigung mit `sudo ./scripts/stack.sh scrub-thinking` aufgenommen. Sie ersetzt alte Raw-Thinking-Inhalte nur in der isolierten Ember CoreUI-Datenbank.
- Antwort-Sanitizer um die gemeldeten Muster `Actually, the prompt says`, `The user is asking` und `Let's try` erweitert. Ein fuehrendes, geschlossenes Antwortzitat endet jetzt vor nachfolgender interner Analyse.
- Thinking-Selftest auf zwoelf Faelle erweitert und um Quellcode-Grenzen fuer SSE und Persistenz ergaenzt.
- Studio-Original als echte RGBA-PNG freigestellt. Der schwarze Rechteckhintergrund wurde entfernt; die vorhandenen feinen dunklen Konturen an Wortmarke und Symbolen bleiben erhalten.
- Splash, Login und Einstellungen auf das transparente PNG-Asset umgestellt. Schwarzer Panelhintergrund, Polygonbeschnitt und Rahmen des Logo-Wrappers wurden entfernt.
- Eigene Ember CoreUI-Versionierung auf `0.2.9-alpha` angehoben. Game, Homepage, Repack-Versionierung und auslaufende STU Console bleiben unberuehrt.

## v0.2.8-alpha - 19.08.2026

- 404-Ursache der neuen Einstellungsseite behoben: `settings.html` in die gehaerteten HTML-Allowlists des Compose-Nginx-Templates und des Native-Nginx-Templates aufgenommen.
- Update-Luecke geschlossen, bei der der absichtlich erhaltene `var/`-Ordner eine alte generierte Nginx-Konfiguration behalten konnte.
- `scripts/stack.sh` rendert Nginx- und PHP-FPM-Runtime-Dateien vor `up`, `start` und `restart` automatisch aus den aktuellen Templates und den lokal gespeicherten Portwerten neu.
- Explizite, idempotente Wartungsaktion `scripts/stack.sh refresh-runtime` fuer reine Runtime-Aktualisierungen eingefuehrt.
- Runtime-Refresh validiert Bind-Adresse und Ports, schreibt keine Zugangsdaten in Webserver-Templates und gibt keine Werte aus `compose.env` aus.
- Compose-Preflight liefert bei einer nicht erreichbaren Einstellungsroute nun die konkrete Refresh- und Restart-Anweisung.
- Compose- und Native-Installer lehnen unvollstaendige Pakete ohne Einstellungsseite oder passendes Nginx-Template fruehzeitig ab.
- Keine Schema-, Account-, Session-, Modell-, Game-, Homepage- oder Repack-Aenderung.

## v0.2.7-alpha - 19.08.2026

- Das bereitgestellte originale Starlight Unit Studios-Logo unveraendert als lokales Ember CoreUI-Asset aufgenommen und das fruehere Game-Logo auf Splash und Login ersetzt.
- Die animierte Ren-Sternenmatrix als eigenstaendiges Ember CoreUI-Kernsymbol in der Sidebar erhalten und klar von der Studio-Herausgebermarke getrennt.
- Sichtbare Copyright-Zuordnung fuer Splash, Login, Sidebar, Protokolle und den neuen Info-Bereich ergaenzt.
- Sidebar um den eigenstaendigen Bereich `Einstellungen` erweitert; Einsatz-Protokolle und Abmeldung bleiben direkt erreichbar.
- Funktionale Einstellungsseite fuer reduzierte Bewegung, kompakten Datenstrom, Thinking-Panel-Standard, Desktop-Telemetrie und Live-Browser-Bildfolge eingefuehrt.
- Anzeigeoptionen robust und ohne Schemaaenderung im lokalen Browser gespeichert. Konten, Sessions, Erinnerungen und Modellkonfiguration bleiben unberuehrt.
- Reale Systemstatusmatrix auf Basis von `api/health.php` mit Datenbank-, Schema-, Ollama- und Runtime-Pruefung aufgenommen.
- Angemeldetes Konto und Berechtigungslevel aus der bestehenden isolierten Ember CoreUI-Authentifizierung in den Einstellungen sichtbar gemacht.
- Protokollansicht um Studio-Zuordnung, Copyright und direkten Wechsel zu den Einstellungen erweitert.
- Compose- und Native-Preflight pruefen nun Studio-Logo, Einstellungsseite, gemeinsame Preferences-Logik und die Auslieferung der Einstellungsroute.
- Mobile Viewport-Regel aus dem v1.1.1.96-Patch unveraendert beibehalten; Game, Homepage, Repack-Versionierung und auslaufende STU Console bleiben unberuehrt.

## v0.2.6-alpha - 19.08.2026

- Live-Browser nach echten Aktionen auf kurze Frame-Bursts umgestellt. SearXNG und Abschluss erhalten bis zu zwei Frames; Navigation, Klick, Eingabe und Scrollen standardmaessig drei Frames im Abstand von 240 Millisekunden.
- Frame-Anzahl und Abstand ueber `STU_EMBER_BROWSE_FRAME_BURST` sowie `STU_EMBER_BROWSE_FRAME_INTERVAL_MS` konfigurierbar gemacht und auf sichere Bereiche von 1 bis 5 Frames beziehungsweise 120 bis 800 Millisekunden begrenzt.
- Ghost-Cursor in jedem Browserframe sichtbar gehalten. Bis zum ersten echten Klick startet er an einer festen neutralen Position, danach behaelt er die letzte reale Playwright-Position bei.
- Klickposition und Klickimpuls voneinander getrennt. Die neue Datenbankspalte `cursor_click` kennzeichnet nur den Frame eines bestaetigten Playwright-Klicks; normale Folgeframes loesen keinen falschen Klickring mehr aus.
- Ghost-Cursor kleiner, kontrastreicher und auf hellen wie dunklen Webseiten lesbar gestaltet. Den grossen dekorativen Eckwinkel des Browserpanels reduziert, damit er nicht mehr wie ein uebergrosser Cursor wirkt.
- Client-Puffer auf 16 Browserframes erweitert und die Wiedergabezeit fuer normale Frames sowie echte Klickframes getrennt abgestimmt.
- Bestehende Installationen werden durch den Browse-Worker automatisch migriert. Eine vorhandene `api/config.local.php` muss fuer die neuen Standardwerte nicht ersetzt werden.
- Eigene Ember CoreUI-Versionierung auf `0.2.6-alpha` angehoben. Repack, Game, Homepage und die auslaufende STU Console bleiben unberuehrt.

## v0.2.5-alpha - 19.08.2026

- Das fruehere aufklappbare `IM NETZ`-Schrittprotokoll vollstaendig durch eine eigenstaendige Live-Browser-Agentenansicht ersetzt. Das Browserbild ist nun selbst die Rechercheflaeche und nicht mehr Inhalt einer Textbox.
- Lokale SearXNG-Treffer als sichere, escaped Browserseite gerendert. Dadurch existiert bereits ein sichtbarer Browser-Frame, wenn Ember die Recherche direkt anhand der Treffer abschliesst.
- Playwright-Viewport nach Start, Navigation, Klick, Eingabe, Scrollen und Abschluss als komprimierte JPEG-Frames gespeichert und ueber den authentifizierten Browse-SSE-Endpunkt ausgeliefert.
- Private Tabelle `stu_ember_browse_frames` mit Jobbindung, URL, Statuslabel, Viewport und Cursorposition hinzugefuegt. Der Worker migriert bestehende Installationen selbststaendig und entfernt alte Frames standardmaessig nach 24 Stunden.
- Ghost-Cursor mit echten Bounding-Box-Koordinaten des angeklickten Playwright-Elements sowie sichtbarem Klickimpuls umgesetzt. Ohne echten Klick wird keine Bewegung erfunden.
- Schrittstream auf eine kurze Statuszeile reduziert. Technische Protokolle verdecken das Browserbild nicht mehr.
- Thinking-Bleed im direkten Ember CoreUI-SSE-Pfad geschlossen. Der Streaming-Abschluss und der synchrone Werkzeugpfad verwenden jetzt dieselbe Schranke vor Datenbank und Client.
- Interne Gemma-Labels wie `Response Draft`, `Alternative`, `Final decision`, `Selected response` und `Checking constraints` werden erkannt. Eine explizite Nutzerantwort kann aus dem getrennten Denkblock gerettet werden; reines Meta bleibt leer und loest den sauberen Fallback aus.
- Client puffert fuehrende Werkzeug- und Meta-Fragmente, damit interne Labels auch waehrend des Tokenstreams nicht kurz in der Antwortblase aufblitzen.
- Eigenen Thinking-Sanitizer-Selftest mit dem gemeldeten Leak-Muster aufgenommen. Compose- und Native-Preflight pruefen zusaetzlich die private Frame-Tabelle.
- Eigene Ember CoreUI-Versionierung auf `0.2.5-alpha` angehoben. Repack, Game, Homepage und die auslaufende STU Console bleiben unberuehrt.

## v0.2.4-alpha - 19.08.2026

- Ursache des vermeintlichen Playwright-Fehlers isoliert: SearXNG und Browsernavigation liefen korrekt, der nachfolgende Ollama-Aktionsaufruf besass jedoch eine fest verdrahtete Lesefrist von nur 90 Sekunden.
- Browse-Aktionsfrist auf einen konfigurierbaren Standard von 420 Sekunden angehoben. Die getrennte Report-Frist betraegt ebenfalls 420 Sekunden.
- Verbindungsaufbau und Antwortlesen fuer Ollama getrennt behandelt. Ein nicht erreichbarer Dienst faellt schnell auf, waehrend eine laufende lokale Inferenz genuegend Zeit behaelt.
- Optionalen, begrenzten Retry fuer unmittelbare Ollama-Verbindungsfehler vorbereitet. Read-Timeouts werden bewusst nicht automatisch wiederholt, damit ein serverseitig noch rechnender Request nicht dupliziert wird.
- Browse-Protokoll unterscheidet nun eindeutig zwischen Ollama-Read-Timeout, Ollama-Verbindungsfehler und Playwright-Fehler. Der bisher irrefuehrende Text `agent-call fehlgeschlagen` wurde ersetzt.
- SearXNG-Treffer bleiben bei einem Modellfehler als belastbarer Fallback erhalten; der Browse-Job und die Ember-Antwort gehen dadurch nicht verloren.
- Idle-Frist des Live-Browse-Fensters auf 480 Sekunden und Sicherheits-Cap des Browse-SSE-Endpunkts auf 900 Sekunden angehoben.
- Neue Laufzeitwerte in Standardkonfiguration, Beispielkonfiguration sowie Compose- und Native-Installer aufgenommen. Bestehende `config.local.php`-Dateien muessen fuer den neuen Standard nicht ersetzt werden.
- Eigene Ember CoreUI-Versionierung auf `0.2.4-alpha` angehoben. Die Repack-Version und die auslaufende STU Console bleiben unberuehrt.

## v0.2.3-alpha - 19.08.2026

- `poppler-utils` in das isolierte PHP-Image aufgenommen. `pdftotext`, `pdfinfo` und `pdftoppm` stehen damit auch im Docker-Compose-Betrieb wirklich zur Verfuegung.
- Zweistufige PDF-Erkennung umgesetzt: Dokumente mit Textebene werden direkt extrahiert, Scan-PDFs ohne Textebene werden als repraesentative Seitenbilder an Gemmas Vision-Pfad uebergeben.
- Eigene beschreibbare Laufzeitablage `var/pdf_pages` als PHP-Volume hinzugefuegt. Der schreibgeschuetzte Projekt-Mount bleibt unveraendert.
- PDF-Stichprobe auf standardmaessig vier gleichmaessig verteilte Seiten und maximal 1024 Pixel je Seite begrenzt. Seitenverhaeltnis und Seitenreihenfolge bleiben erhalten.
- SSE-Routing um PDF-Anhaenge erweitert, damit ein moeglicher Scan-Fallback nicht im reinen Text-Streaming-Pfad ohne Bild-Payload landet.
- Vision-Dateiaufloesung auf zwei kanonische Ember CoreUI-Runtime-Wurzeln begrenzt: `var/ember_frames` und `var/pdf_pages`.
- Prompt-Injection-Schutz fuer Scan-PDFs eingebaut. Sichtbare Dokumentanweisungen werden als Inhalt, nicht als Systemanweisung behandelt.
- Halluzinationsschutz fuer PDFs eingebaut: Wenn weder Textebene noch Seitenbilder lesbar sind, wird keine modellgenerierte Inhaltsbeschreibung angefordert.
- Healthcheck, Compose-Preflight und Native-Preflight um den PDF-Seitenordner erweitert.
- Preflight um einen vollstaendigen PHP-Syntaxcheck fuer API-, Skript- und Tool-Dateien erweitert, damit auch ein reines Update ohne erneuten Installer fehlerhafte PHP-Dateien sofort stoppt.
- Eigenen PDF-Selftest hinzugefuegt. Er validiert eine bekannte Textebene und ein bildbasiertes Scan-PDF samt Rasterung, Skalierung, Metadaten und Cleanup.
- Anwendungssperren fuer den Parallelbetrieb dokumentiert: Game und Ember CoreUI verwenden getrennte Datenbanken und getrennte Lock-Namen. Sie blockieren sich auf Anwendungsebene nicht.
- Rein lesenden `ollama-parallel-report.sh` fuer RAM-, VRAM-, Modell- und Dienstdiagnose hinzugefuegt. Der Ember CoreUI-Installer veraendert den gemeinsam genutzten Ollama-Dienst weiterhin nicht.
- Eigene Ember CoreUI-Versionierung auf `0.2.3-alpha` angehoben. Die Repack-Version bleibt davon unberuehrt.

## v0.2.2-alpha - 19.08.2026

- Kritischen Compose-Fehler im Video-Vision-Pfad behoben: `var/ember_frames` wird jetzt als eigenes beschreibbares PHP-Volume eingebunden.
- Frame-Ausgabe von der allgemeinen Python-Arbeitsablage getrennt und ueber `STU_EMBER_VIDEO_FRAME_DIR` eindeutig konfigurierbar gemacht.
- Video-Skalierung auf eine echte Begrenzung von Breite und Hoehe umgestellt. Das Seitenverhaeltnis bleibt unveraendert und kleine Frames werden nicht unnoetig hochskaliert.
- FFmpeg waehlt den ersten Videostream explizit aus, ignoriert Audio im Vision-Pfad und schreibt JPEG-Frames mit kontrollierter Qualitaet.
- Diagnoseprotokoll um reale Frame-Abmessungen, Zeitstempel und klare Fehlercodes fuer fehlende oder nicht beschreibbare Laufzeitpfade erweitert.
- Halluzinationsschutz eingebaut: Wenn kein Frame erzeugt oder eingebettet werden konnte, wird keine modellgenerierte Videobeschreibung mehr angefordert.
- Vision-Prompt gegen kontextgetriebene Sci-Fi-, Androiden-, HUD- und Hologramm-Erfindungen gehaertet.
- Verdeckten 480-Sekunden-cURL-Deckel auf die bereits fuer PHP-FPM und Nginx vorgesehenen 900 Sekunden angehoben. Die konfigurierte Modellfrist von standardmaessig 600 Sekunden greift damit auch bei mehreren Video-Frames wirklich.
- Healthcheck und Preflight pruefen nun den Frame-Ordner. Ein eigener Video-Selftest erzeugt im PHP-Container einen synthetischen Clip und validiert `ffmpeg`, `ffprobe`, Frame-Extraktion, Skalierung und Cleanup gemeinsam.
- Veraltetes Top-Level-Feld `version` aus `compose.yaml` entfernt, damit aktuelle Docker-Compose-Versionen keine kosmetische Warnung mehr ausgeben.
- Eigene Ember CoreUI-Versionierung auf `0.2.2-alpha` angehoben. Die Repack-Version bleibt davon unberuehrt.

## v0.2.1-alpha - 19.08.2026

- Standardinstallation auf einen eigenen Docker-Compose-Stack umgestellt.
- Eigenen Nginx-Container auf Loopback-Port `9080` eingefuehrt. Der Host-Webserver und vorhandene VHosts bleiben unveraendert.
- Eigenen PHP-FPM-Container auf Loopback-Port `19073` eingefuehrt. Es werden keine globalen PHP-Werte mehr geschrieben und kein Host-PHP-FPM neu gestartet.
- Eigene MariaDB-Instanz auf Loopback-Port `13306` eingefuehrt. Bestehende STU-Datenbanken und Datenbanknutzer werden nicht verwendet oder veraendert.
- Eigene SearXNG-Instanz auf Loopback-Port `18889` eingefuehrt, damit eine vorhandene STU-Suche auf `8888` parallel weiterlaufen kann.
- Den Granian-Server im offiziellen SearXNG-Container explizit an `127.0.0.1:18889` gebunden, statt dessen internen Standardport `8080` vorauszusetzen.
- Browse-Worker in einen eigenen Playwright-Container verschoben. Es wird kein Host-systemd-Dienst mehr benoetigt.
- Ember CoreUI-Modellname von `ember-core:latest` auf `ember-coreui:latest` getrennt und durch eine lokale Eigentumsmarkierung gegen versehentliche Uebernahme fremder Modelle abgesichert.
- MariaDB-Advisory-Locks auf einen konfigurierbaren Ember CoreUI-Namespace umgestellt. Die bisherigen globalen Namen `ember_global_ollama` und `ember_self_xp` werden im Standardbetrieb nicht mehr verwendet.
- Vor jeder Erstinstallation Kollisionstests fuer Ports und Compose-Projektidentitaet eingefuehrt.
- Webzugriff standardmaessig auf `127.0.0.1` begrenzt und einen SSH-Tunnel als sicheren Testweg dokumentiert.
- Eigenen Compose-Preflight sowie `scripts/stack.sh` fuer Status, Logs, Neustart und Stop hinzugefuegt.
- Fast-Poll-Fallback an die ausloesende Ember CoreUI-Sitzung gebunden, damit ein SSE-Rueckfall den Sitzungszeiger korrekt aktualisiert und nicht in einer Poll-Schleife haengen bleibt.
- Den frueheren Host-Installer als expliziten `install-native.sh` fuer dedizierte Einzelserver erhalten und mit einer Pflichtbestaetigung gesichert.
- Entfernen der Nginx-Default-Site im Native-Installer standardmaessig deaktiviert.
- Getrennte Ember CoreUI-Versionierung fortgefuehrt. Repack-Changelogs werden von diesem eigenstaendigen Alpha-Release nicht veraendert.

Bewusste gemeinsame Ressource:

- Der lokale Ollama-Dienst wird weiterhin gemeinsam genutzt, damit vorhandene Gemma-4-Modelldaten nicht dupliziert werden. Ember CoreUI verwendet einen eigenen Modellnamen und eigene Daten, gleichzeitige Generierungen koennen aber dieselbe CPU, GPU und denselben RAM beanspruchen.

## v0.2.0-alpha - 19.08.2026

- Eigenstaendiges Distributionspaket ohne Includes, Assets, Proxies oder Laufzeitpfade aus dem Repack aufgebaut.
- Alle Frontend-API-Aufrufe auf die lokale, deployment-relative Schnittstelle `./api` umgestellt.
- Lokale Rajdhani- und Share-Tech-Mono-Fonts eingebunden.
- Eigenes MariaDB-Basisschema fuer Accounts, Charaktere, Sessions, Chat, Medien, RAG-Lite, LZG, Browse-Jobs und Reputation hinzugefuegt.
- CLI-Bootstrap fuer Ember, Admin-Account, Charakter und `stu_kv` hinzugefuegt.
- Standalone-Konfiguration ueber `api/config.local.php` oder `COREUI_*`-Umgebungsvariablen eingefuehrt.
- Eigenen Health-Endpunkt und authentifiziertes CRUD-API fuer `ember_memories` hinzugefuegt.
- Gemma-4-Modelfile mit Ember CoreUI-Werkzeugprotokoll und empfohlenen Sampling-Werten hinzugefuegt.
- Lokales SearXNG-Template mit aktivierter JSON-Search-API hinzugefuegt.
- Asynchronen Playwright-Browse-Worker als eigenen systemd-Dienst paketiert.
- SSH-Installer fuer Debian und Ubuntu sowie einen wiederholbaren Preflight-Test hinzugefuegt.
- Nginx-Allowlist fuer ausschliesslich oeffentliche API-Endpunkte und Schutz interner Projektordner hinzugefuegt.
- Vision-Dateiaufloesung auf das lokale Projektverzeichnis `assets/chat_media` begrenzt.
- Getrennte Ember CoreUI-Versionierung gegenueber der Repack-Versionierung verbindlich dokumentiert.

Bekannte Alpha-Grenze:

- Die visuelle CRUD-LZG-Oberflaeche und weitere spezialisierte Werkzeug-Panels folgen in einem spaeteren Frontend-Schritt. Das lokale CRUD-API ist bereits enthalten.

## v0.1.1-alpha - 19.08.2026

- Kritischer Mobile-Viewport-Fix fuer Ansichten bis 899 px integriert.
- `html`, `body`, `.app-body` und `.app-shell` hart auf die Viewportbreite begrenzt.
- Horizontalen Seiten-Scroll im mobilen Layout gesperrt.
- Drawer auf 290 px festgelegt und im Ruhezustand vollstaendig links ausserhalb des Viewports positioniert.
- Drawer-Einflug auf 380 ms mit eigener Holo-Schattenkante abgestimmt.
- Overlay-Ebene direkt unterhalb des Drawers abgesichert.
- `.drawer.open` ist mobil die einzige Quelle fuer den sichtbaren Drawer-Zustand. Der Checkbox-State steuert dort nicht die Sichtbarkeit, da `#sidebarControl` im aktuellen HTML standardmaessig aktiviert ist.

## v0.1.0-alpha - 19.08.2026

- Eigenstaendiges HTML-Grundgeruest fuer Ember CoreUI erstellt.
- Helles Ayal'ren-HUD, SVG-Runenmatrix, Sidebar, Ticker, Chat-Feed und Composer gestaltet.
- Bestehende DOM-Kopplung fuer die aktuelle Console-JavaScript-Laufzeit erhalten.
- Grundlayout in feste Navigations-, Kopf-, Verlaufs- und Eingabebereiche gegliedert, damit die spaetere funktionale Erweiterung ohne Austausch des visuellen Kerns moeglich bleibt.
- Eigene Ayal'ren-Runenmatrix als Ember CoreUI-Kernsymbol eingefuehrt und vom spaeter getrennt gefuehrten Studio-Branding abgegrenzt.
- Chatblasen, Statusanzeige, Nachrichtenzeiten, Eingabefeld und primaere Aktionen als konsistentes HUD-System angelegt.
- Erste Desktop-Proportionen, Typografie, Farbvariablen, Raster, Schatten und Bewegungsmuster in einer gemeinsamen Ember CoreUI-Styleschicht gebuendelt.
- Game, Homepage und bestehende Repack-Oberflaechen nicht ersetzt; der Stand war ein eigenstaendiger Alpha-Frontendkern.
