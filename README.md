# Quantum CoreAI: TCI Interface

<div align="center">
  <img src="assets/Quantum-CoreAI-github-banner_20261115_212829.png" alt="Quantum CoreAI - TCI Interface" width="100%">
</div>

[![DOI](https://zenodo.org/badge/1349352973.svg)](https://doi.org/10.5281/zenodo.22201310)

**CoreAI: TCI Interface** is a free, self-hosted AI assistant and working environment by **Starlight Unit Studios**. **Mara Thorne** is the default TCI assistant. CoreAI brings together its own chat interface, model management, tools, memory, research and working features. It runs primarily on your Linux server with **Ollama**, supports compatible installed and permitted models alongside the TCI presets, and offers optional external integrations. It is **not a standalone operating system**.

**TCI Cores are defaults, not an exclusive model requirement.** CoreAI supports other compatible Ollama models, including **language models from Hugging Face that have been installed through Ollama**. The owner can select installed models and grant access to individual models for other accounts. Model format, capabilities and permissions still matter. No separate application framework needs to be installed.

**Current source version: `0.8.19`** | [CHANGELOG.md](CHANGELOG.md) | [Roadmap](ROADMAP.md) | [Capabilities](docs/CAPABILITIES.md) | [Operations](docs/OPERATIONS.md) | [License](LICENSE.md)

**Animated chat companion:** Mara's OpenPet v2 Pet uses distinct work, waiting, review, completion, failure and interaction animations; tap to wave, double tap to jump, drag for directional movement, and (on desktop) move the pointer nearby for idle gaze. Other compatible Pet formats remain supported.

**Installer regression protection:** A dedicated CI workflow now executes the offline voice-service and host-update preflight contracts on setup and updater changes, rather than only checking source syntax.

**Installation language:** The official setup one-liner, Docker and native installers, both preflights, host updater, stack management, migrations and uninstall commands use English for their own terminal output. Existing environment variables and command names are unchanged.

**Read this first:** CoreAI includes chat, multiple local Ollama models, bounded multi-step tools, web search, research, projects, files, memory and multimodal functions. **The TCI tier menu does not exclude other installed, permitted Ollama models.** Actual features depend on the selected model, enabled services, account rights and hardware. A merged GitHub change is not an installed or live-verified server update.

## Quick start: install CoreAI

Run the **official one-line installer** on the target server over an interactive SSH connection:

```bash
( setup_file="$(mktemp)" && trap 'rm -f -- "$setup_file"' EXIT && curl -fsSL https://raw.githubusercontent.com/Starlight-Unit-Studio/CoreAI-TCI-Interface/main/setup.sh -o "$setup_file" && sudo bash "$setup_file" )
```

This command downloads the setup launcher to a temporary file, runs it as root and removes it afterwards. It can also update an existing installation, but after initial setup the **built-in updater is the preferred day-to-day method**.

**Requirements:** Debian 12+ or Ubuntu 24.04+, root or `sudo` access, internet access and sufficient CPU, RAM and storage for the chosen Ollama model. The installer can install host tools, Docker/Compose and Ollama. Downloads and model builds require additional disk space and bandwidth.

On first installation, the setup prompts for an administrator email and a password of at least 12 characters at the terminal. Its default installation path is `/opt/ember-coreui`; that historical directory name remains unchanged for compatibility.

The launcher installs the latest `main` commit and runs preflight. Updates preserve settings, accounts, database, uploads and logs. Verify the application in your browser.

An archived, separately checksum-verified historical `0.5.3-alpha` ZIP can still be selected explicitly through `COREUI_SETUP_SOURCE=release`. It is **not** the current source version. A `main` source checkout must not be represented as a newly verified ZIP release.


**Local Ollama Core retention:** The active local Core defaults to `keep_alive: -1` on preload, normal chat and streaming calls, so Ollama does not unload it solely because 15 minutes elapsed. Only one local Core stays in RAM: before every local call, inside the serial model queue, the other local Cores are unloaded (for example the image prompt Core, a higher tier used for a single turn, a vision Core or the Core of another account); cloud Cores take no RAM on the server. A Core switch in the Core menu, also to an external Core, unloads the previous local Core as well. On a machine with room for several Cores, `COREUI_EMBER_SINGLE_RESIDENT=0` keeps the previous behaviour. A host administrator may override the timeout using `COREUI_EMBER_KEEP_ALIVE` or an existing `STU_EMBER_KEEP_ALIVE` setting. Model residency is independent of the conversation history/context that CoreAI sends on each request.

**Chat development (0.8.8):** The shared real Project Files UI is mounted in a responsive chat modal. Saved ordinary chats own their own account-scoped development files, preview, commands, revision history and backups; older chats gain a workspace on their next work request. Projects are optional organizational groupings, not the permission gate for tool use. New chats are still created only after the first message. Opening the developer tab in a draft without a saved session asks the user to send a first message. Server permissions, PIN lock, CSRF, sandbox and file validation still apply. The chat-to-Project file migration on a later move needs a separate conflict-aware import flow; current workspaces are not silently overwritten.

**Existing-project changes:** Once a project chat contains saved source files, later requests such as adding `blog.html` use the project file read/edit/write tools. The historical completed Markdown plan is no longer re-dispatched as an automatic full-site rebuild for every new message. **Safe edit recovery:** After a rejected exact-match patch, Mara re-reads the stored file before proposing one corrected edit. If that edit also fails, she stops the repeated failure with a concrete reason. Successful project operations remain unrestricted; saved versions and conflicts stay protected.

## What Mara can do

This is an inventory of **implemented, user-facing functions**, not a claim that every model supports them. Features marked **optional** require enabled services, hardware, account rights or individual model licenses. Source-level implementation and successful tests do not guarantee that a server has been updated. For evidence, scope and detailed limitations, see the [capability catalog](docs/CAPABILITIES.md).

### 1. Conversation, thinking and agentic work

- Answer questions, explain complex topics, translate, summarize, draft, revise and discuss text or code with a selected **TCI Core or other compatible, authorized Ollama model**.
- Use every saved private chat as a complete working environment: **Entwicklung** beside the chat tool tabs opens a modal with the same editor, preview, terminal, development server and Git-style file history as the Project page. An ordinary chat receives a private hidden source workspace; it is not converted into a visible Project, and its normal memory scope stays unchanged. Projects remain optional organization. No separate Chat/Work switch.
- Open a personalized welcome screen with starter prompts; a conversation is not stored until its first message or attachment.
- Select a **TCI Core or another installed and authorized model**, including compatible Hugging Face models imported through Ollama, separately from **Thinking**; supported model-dependent options apply to subsequent turns.
- Let the operator configure which installed Original/Uncensored Ollama tag backs a TCI tier in **Manage Cores**. The chat menu lists one row per TCI tier **and separately named permitted models where available**.
- Optionally route eligible complex private text turns to a higher allowed local Core, subject to permissions and availability.
- Run a **bounded tool-and-result loop**: pick an allowed action, examine its real result, refine the task and decide whether another step is needed.
- Automatically checkpoint and continue project plans, then complete authorized website creation without needing a separate user `Continue`: CoreAI retrieves the original verified user instruction from versioned plans, including after a resumed or already completed plan, and hands it to the guarded project writer. An unrelated new plan, a revoked task or a plan-only request must not inherit old creation authority. Actual file/ZIP generation remains independently verified.
- Present real work events, technical steps and status; do not expose raw private model thinking or invent tool activity.
- Store the owner-scoped queued chat job before acknowledging a message, then reattach on return from Settings. An unstarted queued job resumes when the chat is reopened; a running server job survives browser navigation.
- Replace a finished reply without rebuilding already loaded images and work cards, reducing mobile flicker.
- Stop supported in-progress responses and work. Model inferences are serialized across the system; one Ollama model call runs at a time.
- Copy, edit, regenerate and read aloud messages; rate answers with persistent 👍/👎 feedback.
- React once in Mara's own words to a recorded rating or confirmed image generation, without a fabricated spoken request or an endless reaction loop.
- Keep the assistant's configured name, portrait and TCI identity across the standard chat experience.

### 2. Web search, reading and Deep Research

- Search the web through **SearXNG** by default, including from ordinary text or supported voice requests.
- Ask for more **unseen search hits** with `[WEB: WEITER]`, continue across search pages, adjust search terms and discard duplicate source URLs.
- Show a live, stable search-hit/source strip with expandable history and safe links. **Search hits are not proof that Mara visited a webpage.**
- Use the **Research** tool's Playwright browser only when explicitly selected in the chat's + menu; ordinary Web Search never launches it automatically.
- Navigate supported pages with the guarded browser worker; website access, forms, JavaScript and CAPTCHA handling depend on the site and safety restrictions.
- Use **Deep Research** (**optional**) as a separate, longer workflow: editable plan, multi-stage searching and reading, evidence review, source register, status and cancellation.
- Combine a serial lead/reasoner with isolated research workers and parallel **network I/O**, checkpoints, source-quality checks and follow-up searches; not parallel Ollama inference.
- Preview and download Deep Research reports as Markdown with numbered, clickable references grounded in sources actually read by that workflow.
- Inspect supported public PDF, CSV and JSON sources via bounded, guarded document adapters where available.
- Retrieve current weather context using configured weather-data providers; this stays separate from ordinary search and browser navigation.
- Respect account restrictions and **Lockdown** mode that disables web and browser access.

### 3. Code, calculations, files and generated outputs

- Handle chat attachments from Photos, Camera and Files (up to ten at a time within account limits).
- Analyze supported uploaded content with an appropriate text/vision/audio Core or tool, rather than assuming every Ollama model supports every media type.
- Use an isolated **Python** tool for allowed calculations and data tasks, with real results and errors.
- Produce named source/text files and validated **multi-file ZIP** downloads for suitable code-generation requests.
- Register completed output artifacts to the account/session and provide verified downloads; do not fabricate files or serve executable HTML as an in-origin preview.
- Show each file Mara made with its version (the first characters of its SHA-256 checksum). **Preview** shows pictures (PNG, JPEG, GIF, WebP) and text files (including CSV, JSON, code, HTML and SVG as source text) from exactly the bytes the download gives: the server checks the stored checksum before it sends the file, the browser compares it with the card (and computes it itself where it can), and **Download** in the preview saves those same bytes. A changed or different version is refused. HTML and SVG never run in the application.
- Keep or delete Mara's files from the chat: **Keep** marks a file (the same as a favourite under **Settings > Storage**), **Delete** removes it for good and leaves a note in the chat message saying when and why it was deleted, so the message that pointed at the file keeps saying what happened. The owner can let Mara's files be deleted automatically after 30, 90, 180 or 365 days (**Admin Core > Operations**, unlimited by default); kept files and files in project chats are never deleted that way, every other file shows the day it will go, in the chat and under **Settings > Storage**.
- Write and revise Markdown plans, inspect source snippets and structure coding tasks; an unrestricted remote shell or complete IDE is **not** provided.
- Use a curated **Skills** catalog (research, software development, writing, documents and more) as task instructions and referenced knowledge, subject to tool permissions.
- Write your own skills under **Settings > Skills**: a name, a short name Mara uses to load it, a description, trigger words, the tools it needs (web search, browser research, Python, images, project files, running in the project), where it applies (all your chats or one project), on or off, and the instruction Mara follows (up to 4,000 bytes). A skill can carry up to 20 companion text files of up to 8,000 bytes each in four folders: `references/` (background Mara reads), `templates/` (texts she fills in), `scripts/` (code she only gets as text; nothing in a skill ever runs by itself) and `assets/` (data such as a CSV). The page shows files the instruction names but the skill does not have, and lists the built-in skills to look up, without switches.
- Mara finds your own skills like the built-in ones: by trigger word, by `/skill short-name`, or by loading one herself with `[SKILL: short-name]`; she loads a companion file only when she needs it (`[SKILL: short-name/templates/report.md]`), as many as the context window allows. If a skill needs a tool the chat does not have, or names a file it lacks, Mara is told so and says what she cannot do instead of claiming a result. A project skill is only there in the chats of its project; private skills of other accounts are never offered.
- Give each own skill a precedence (High, Normal or Low): when more skills fit a request than the context window takes, the higher precedence loads first. Naming a skill with `/skill short-name` still comes before everything.
- The owner and admins can share their own skills with every account (never a project skill). Every signed-in account sees them under **Settings > Skills > Shared** with who shared them, can read them as text and switch each one off for itself; guests get none, and a skill disappears when its owner is no longer owner or admin. When two skills share a short name, a project skill serves first, then your own, then a shared one (higher precedence, then the older one), and the page says which one serves instead.
- **Skills for this chat** in the **+** menu of the input field fixes up to three skills for one chat: your own, project, shared or built-in ones, and for a short name that exists more than once exactly one variant. Mara always has them in that chat and uses exactly that variant, as many as the context window takes (she is told about the rest); they show above the input field and can be removed there. **Let Mara choose** clears the choice; a fixed skill that was deleted meanwhile is named and dropped on the next save.
- Every change of an own skill (saving it, a companion file, restoring, importing) is kept as a fixed revision with all its files. **History** in the skill editor shows who changed what and when, **Compare** shows any two revisions line by line, and **Restore** brings an earlier revision back as a new revision on top, so nothing is lost (the last 100 revisions are kept). A save after someone else's change is refused with a notice: the text stays in the editor, and the person can compare it with the newer revision, save anyway or reload.
- **Export** downloads a skill, or one of its revisions, as a ZIP with `SKILL.md` (front matter with name, title, description, triggers, requires and priority, then the instruction) and its companion files. **Import** takes such a package, including the plain `SKILL.md` format with only a name and a description: as a new skill, as a new revision of your own skill with the same short name, or under another short name. Packages with paths that leave the package, links, binary or oversized files are refused and nothing changes; files outside the four folders are skipped and named.
- When Mara uses one of your own skills, the work view records its revision, and a change made during her turn does not reach that turn.
- Use observable plan, build and work-event steps without pretending that a named Skill automatically installs its own executable program.

### 4. Projects, sources and memory

- Create, rename, pin, configure and delete **chat-native projects**; place existing chats into project folders.
- Set project-specific instructions, add private sources and use **open or isolated project context** with project-scoped RAG-Lite retrieval.
- Use the same full composer in a project as in a regular chat: text, attachments, Core, Thinking, tools and voice.
- Save text files Mara produces within a project into its **Files** tab, with a folder tree, filename/content search and line numbers.
- **Import into project** on a generated file message explicitly moves that owned chat into a project workspace and imports its checksum-verified ZIP and text sources without a second upload; nothing is promoted automatically and files from other accounts are inaccessible.
- Create, edit, rename, delete and download text files; compare revisions, restore earlier versions and export the current project as ZIP.
- In a project chat, Mara reads a single project file, changes exact passages or writes a new file; every change is all-or-nothing, saved as a revision marked as hers and can be restored. Malformed single-tool model replies receive one safe, tool-specific format correction instead of being redirected to a new Markdown plan. Existing project files are not generally locked against additions or revisions.
- Mara writes a large file in parts: a short, complete skeleton first, then one targeted change per section. If a reply is cut off at the output limit before its file block is closed, nothing of it is saved; the work timeline names the cut and Mara gets one correction that asks for a smaller step. A missing check (for a static page, for example `node --check` or a short Python command) is her next tool step, not a reason to stop. When Mara ends her work with a plain answer instead of the control format, that answer is shown; the task counts as finished only when every tool step is confirmed, otherwise a note says so. Long project runs keep the newest tool results complete and send older large results as size and checksum only, so the prompt does not grow with every file read; Mara reads a file again when she needs it. A read-only tool such as the web search that returns the same result three times is dropped for the rest of the run while the work goes on. A message with attached files does not offer the plan and build tools; Mara answers in text.
- Preview an HTML page of a project in the **Files** tab: its stylesheets, scripts, SVG pictures and data files come from the project, links open its other pages, and script errors are listed with file and line. The preview shows the saved state, reloads after saving and runs isolated from the account (its own sandboxed origin, no access to cookies, chats or the API).
- Run a command on a project's saved files in the sandbox, from the **Files** tab or by Mara in a project chat (for example the project's tests). It is the same isolated container as Mara's Python: no access to the host or CoreAI, a read-only system, limits for memory, CPU, processes and 120 seconds. The real output and exit code are shown and kept for the last 20 runs; a running command can be cancelled. Mara can test, change a file and test again. The sandbox has Python 3.12 and Node.js 24 with npm and npx (for example `npm install && npm test` or `node --test`); the suggested command follows the project's files and its `package.json`, and files a command changes stay in the container. Runs count as the Python tool and follow its switches.
- Keep a process running on a project's files in the same sandbox, from the **Files** tab under **Processes**: a program, an interactive shell or a development server (Python or Node.js). Its output arrives live, lines typed there go to its standard input, and **Stop** ends it with its real exit code. Files saved meanwhile reach the running process within a few seconds. In a project chat Mara uses the same processes herself: she starts a program or a development server on the port she names, reads its output, answers its input, requests the server's pages and interfaces to check them, and stops it with the real exit code. She knows this environment (Python 3.12 and Node.js 24 with internet access, a read-only system, the process running now) and points the person to the **Preview**, because an address such as localhost is only reached inside the container. Outside a project she knows that project chats have this environment. With a port, the **Preview** shows the development server's pages: stylesheets, scripts and pictures come from the server, links and GET forms stay on it, and fetch() requests reach it through CoreAI. No port is published; the server is only reached from inside its container, and the preview runs isolated from the account. One process per project and two per account run at once; a process ends after 30 minutes or when nobody has looked at it for 5 minutes. Processes count as the Python tool and follow its switches.
- Keep versions of a project's files in the **Files** tab under **Versions**: **Save** records the state of every file with a short description, and the list shows each version with what it changed, down to a line comparison of a single file. Working states (branches) each have their own files: unsaved changes stay with the working state they were made in, or move along to a new one on request, so two working states never change each other. **Restore** brings back any version on the active working state; unsaved changes are saved as a version of their own first, and the restored state is added as a new version, so nothing is lost. **ZIP with Git** downloads the active working state with a real Git repository of all working states and versions (`git log`, `git status` and `git checkout` work after unpacking; no Git program is needed on the server). **Merge** brings another working state's saved versions into the active one: a preview first lists what comes over, files both sides changed are merged line by line, and where both changed the same or neighbouring lines differently, each conflict needs a choice before anything changes (keep my version, take the other one, or edit a text with both versions marked). Unsaved changes are saved as a version first, the other working state stays as it is, and the merge is a new version with two parents (also in the Git export), so it can be restored like any other version. A project keeps up to 10 working states and 200 versions; stored versions count toward the account's storage.
- In a project chat, Mara saves versions herself, starts or switches to a working state of her own (for example for a larger change, so the previous one stays untouched) and merges a working state when the person wants it, only without conflicts; conflicts stay for the person under **Versions**. Her versions are marked as hers.
- Protect concurrent editor changes and unsaved drafts; files stay account/project scoped and count toward storage limits.
- Keep project chat history after deleting a project; project files and sources are deleted with that project.
- Retrieve account-scoped **RAG-Lite knowledge**, maintain long-term memory where supported and generate an account memory summary.
- Apply custom instructions, preferred answer style, personal context and reference images without treating private memories as public web citations.

### 5. Images, vision and image editing

- **See and discuss images** through compatible multimodal Cores, including attached photos and user-created graphics.
- Generate one, two or four images with the **optional CPU FLUX.2-klein/pGen service**, when enabled and permitted.
- Choose separately among **pGen Standard/Magic 4B or 9B** profiles when available; 9B and Magic have hardware, weight and/or individual license requirements.
- Use model-specific text encoders; unavailable Magic assets never silently become Standard or bypass a user license.
- Display actual image progress, completion and diagnostic errors, preserve original images and offer gallery previews/downloads.
- Edit generated or uploaded images with text/guidance shapes where supported; **not pixel-perfect inpainting**.
- Use a custom Mara portrait plus additional account-specific reference images for identity consistency when generating Mara-related scenes.
- Crop user and assistant avatars on touch devices; manage the chosen images and portraits in profile/personalization settings.
- Let a vision-capable Mara inspect the generated PNGs and comment in her own words, using the established private media path.
- Keep generated-image provenance/watermark behavior and account isolation; owner controls can disable generation.

### 6. Voice, read-aloud and video

- Record and transcribe short **voice messages**, or attach longer audio where allowed; show transcripts in chat.
- Use automatic or configured transcription language, pronunciation hints and a local recent-recording list.
- **Read answers aloud** using optional Eve/Pocket TTS, with a choice of licensed German/English voices, previews and automatic read-aloud opt-in.
- Pronounce dates, numbers, versions and abbreviations in a listening-friendly way; avoid reading entire code blocks as prose.
- Have **hands-free live conversations** (**optional**, HTTPS + voice service): listening, thinking, speaking, mute, interruption and saved turns.
- **See video frames with timestamps** via supported vision input; with an audio-capable Core, also transcribe the soundtrack within its context budget.
- Make limitations explicit for silent video, trimmed audio, unsupported formats, missing permissions or offline services.

### 7. Chats, interface and personalization

- Navigate **Projekte → Angeheftet → Letzte** in the collapsible mobile/desktop sidebar. Pinned projects and chats (including project chats) share one visible shortcut section with an empty state and individual pin controls; archived chats remain accessible separately.
- Pin/unpin a chat; search its messages; list its uploaded files; export its complete conversation as **Markdown**.
- Archive, restore, delete, or add a chat to a project; use account data controls for eligible bulk archive/delete/export actions.
- Branch a chat at one of Mara's replies into a new chat: it keeps the messages up to that reply with their attachments, the project and the summary of older messages when it ends before that reply; downloads stay in the original. The original chat stays unchanged and the branch links back to it.
- Protect individual chats with a verified **four-digit account PIN**; locked chats do not appear in regular previews or bulk deletion.
- View assistant/operator identity headers, timestamps, response actions, and the interactive Core/Context/Tools/Project panels.
- Display a movable, animated **Pet** with working/waiting/idle states. Mara Thorne and other original companions are included.
- Upload supported personal Pet sprite atlases or select owner-published, individually reviewed licensed Pets; hide the Pet if preferred.
- Choose native angular CoreAI or optional SoftGlass; configure **system/light/dark**, colors, typography, contrast, motion and chat width.
- Personalize the assistant using **seven base writing styles**, formatting, instructions, name/portrait and image references.
- Configure voice, keyboard shortcuts, interface language (English is **beta**) and a separate German/English default answer language.
- View account profile, activity history, token totals, tool usage, storage and plan/usage analytics.

### 8. Security, permissions, notifications and ownership

- Use accounts with **owner, admin, user or guest** roles and server-enforced tool/model/message/storage limits.
- Give guests configurable tool permissions and a server-enforced image allowance (default **three images per server day** when enabled).
- Sign in securely with supported **passkeys/security keys, authenticator codes, e-mail codes and recovery codes**; inspect security history.
- Use Lockdown mode, controlled sessions, account-wide sign-out and role-protected endpoints; uploads and generated files are access checked.
- Configure **Web Push and/or e-mail notifications** for eligible long tasks, security events and owner update alerts.
- Manage accounts, roles, limits, audit and operator functions through the separate **Admin Core**.
- Manage **Core library models from Ollama and public Hugging Face GGUFs**: search, inspect, install, update and remove eligible models, protect built-in defaults, choose installed TCI variants and grant access to other Ollama models for individual accounts.
- Control image licensing, optional services, mail settings, backup/update operations, tool permissions and server diagnostics.
- Update through the owner-only built-in host updater after initial setup, or through the official SSH installer; preserve accounts and user data.

### Not yet complete (do not advertise as shipped)

- General-purpose third-party **Connectors** and a fully integrated external-app marketplace.
- Installing **gated or private Hugging Face repositories** is not available through the Core library; upstream authorization must be handled separately.
- An unrestricted Codex/Claude-Code-equivalent coding IDE, a general host terminal, a full terminal emulator (TTY), the live module reloading of development servers such as Vite in the preview (their built output works), importing a Git repository, two working states open at the same time, or unlimited self-executing agent.
- Unsolicited scheduled conversations and broad background automations outside explicitly implemented events.
- Fully translated English/German UI, public creator profile, public chat-link sharing, and settings explicitly labeled **In preparation**.
- Guaranteed vision/audio/image support on every model, browser access to every site, or equal performance on every CPU server.

## Integrations and API connections

CoreAI has its **own HTTP/SSE application API** for local inference, research, images, voice and account features. **TCI models are presets, not the only models supported**: other compatible Ollama models can be selected and individually authorized. Optional external providers can be configured where supported; the presence of a configuration field alone does not prove an adapter is active.

**Status key:** **Integrated** means a corresponding implementation exists; **optional** requires setup, a compatible model or permission; **prepared** means configuration/code scaffolding exists but is **not** a finished selectable service; **planned** means not currently shipped.

### Local, cloud and model-provider connections

| Provider / connection | Status | What actually works |
| --- | --- | --- |
| **Ollama REST API** | **Integrated (default)** | Local inference with **any installed model compatible with CoreAI's Ollama integration and granted to the account**, not just the bundled Gemma/TCI tiers. The application uses Ollama's model inventory, model details and chat endpoints; model calls share CoreAI's serial queue. |
| **Core library (Ollama + Hugging Face)** | **Integrated (admin)** | One catalog searches both the official Ollama registry and public Hugging Face GGUF repositories, with random recent suggestions for empty searches. Admins can install eligible local models via the existing Ollama worker; the installed Core list, management, access controls and progress display remain shared. |
| **Hugging Face GGUFs (via Ollama)** | **Public GGUF discovery and local install integrated** | One combined Core search lists public Hub GGUF repositories and verified single-file variants. These install locally into Ollama using the established worker; installed models can be authorized and selected as regular chat Cores. Private/gated models need a future protected authorization flow. Repository/model licensing remains the operator's responsibility. |
| **TCI Original / Uncensored variants** | **Integrated (admin)** | In **Manage Cores**, the operator binds an installed local Ollama tag to each TCI tier. The chat displays one tier entry, not an extra model gallery. |
| **Ollama Cloud tags** | **Optional** | Available only through a suitable configured Ollama runtime/account, with the remote provider's terms and availability. A cloud model is not equivalent to local/offline processing. |
| **Generic OpenAI-compatible external API** | **Optional (owner sets it up, released per account)** | Any service with an OpenAI-compatible `chat/completions` endpoint (hosted APIs, OpenRouter-style gateways, or a local proxy such as LiteLLM on the same machine). Its Cores appear in the chat's Core menu under **External Cores**. See *External API adapter* below. |
| **Direct OpenAI, Anthropic, Gemini and other native API formats** | **Not shipped** | Only the OpenAI-compatible format is implemented. Providers that offer an OpenAI-compatible endpoint can be used through the generic adapter; their native APIs, tool calling and provider-specific options are not. |

### External API adapter

The adapter brings Cores of a hosted provider into CoreAI as **external Cores**, for example to compare a run with a local Core and rule the model in or out as the cause of a fault. CoreAI is a multi-account system: the owner decides which external Cores are offered and who may use them, because they cost money and send prompts to a third party.

- **Setup (owner):** In **Admin Core > Operations > External adapter**, enter a display name and the base URL (HTTPS, or `http://` only to `127.0.0.1`/`localhost`) and paste the API key. **Load Cores from provider** reads the provider's model list (no tokens are used); tick the Cores CoreAI should offer (up to 50), filter long lists, or add a name by hand for a provider without a list. Switch the adapter on and choose **Save adapter**. **Check connection** works before the adapter is switched on: it saves unsaved changes in the card, reports whether the key is accepted and names any offered Core the provider does not list. Admins see the status and may run the check, but only the owner changes the adapter.
- **API key (owner):** Paste it into the **API key** field and choose **Save key**; no file has to be edited. It is stored encrypted (AES-256-GCM with a key derived from the server configuration, so a copy of the database alone does not reveal it), never shown again and can be removed. Admins see whether a key is stored but cannot set or remove it. Alternatively, a key in the server configuration (`COREUI_EXTERNAL_API_KEY` in the environment or `define('STU_EXTERNAL_API_KEY', '...');` in `api/config.local.php`) takes precedence and locks the field.
- **Who may use which Core:** The owner can use every offered external Core. Every other account, admins included, only uses the ones the owner releases under **Admin Core > Users > Releases**, the same releases as for local Cores outside CoreAI. Guests never use external Cores. An external Core is never treated as a TCI tier, whatever its name. The server checks this when the list is shown, when a Core is chosen and at every turn.
- **Use:** Each account chooses an external Core in the chat's Core menu under **External Cores**, like a local Core. The chip shows its name with `· extern`; nothing is loaded locally. If the owner withdraws the release, switches the adapter off or stops offering that Core, the account chats with the default Core from the next message on.
- **What changes:** Only the inference endpoint. The prompt, skills, project tools, work loop, serial model queue, cancelling and time limits stay exactly as with a local Core. An external Core has no Modelfile, so it receives Mara's SYSTEM text from the operator Modelfile unchanged, like a raw local Core. Each call appears in the work timeline and in usage as `extern:<model>`. Reasoning that a provider returns as `reasoning_content` or `reasoning` is treated like local thinking; token counts come from the provider's usage report.
- **What is not sent:** Ollama-only settings (thinking switch and level, `keep_alive`, context window, output limit, `top_k` and tuning options). The local context window and output limit do not apply to the provider.
- **What stays local:** Voice transcription, live voice conversation, the browser research worker and image generation.
- **Privacy:** Prompts, chat history and project file contents of the accounts that use an external Core go to the configured provider and are subject to its terms. Error messages are shortened and keys are removed before anything is logged.

A Core can have text, tools, image understanding, speech or other capabilities only when its **actual model implementation supports them**. A tier name, license acceptance or installation alone does not guarantee those modalities. The owner can enable/disable available models and tools independently.

### Web search and source providers

**CoreAI Web Search does not require a Google Search API key, a Google login or the Google Custom Search service.** It uses a **self-hosted SearXNG metasearch instance** by default. SearXNG queries whichever upstream search engines the operator has enabled. Those upstream engines can include Google, but there is no single mandatory Google dependency.

- **SearXNG: integrated.** Configure its local JSON search endpoint through `COREUI_SEARXNG_URL` or `STU_EMBER_WEB_URL`. Server templates enable the JSON response format. Upstream results and rate limits depend on the chosen engines.
- **Progressive search: integrated.** Mara can request more unseen source hits using `[WEB: WEITER]`, paginate, refine terms and deduplicate URLs while staying within turn and context budgets.
- **Evidence boundary:** A normal search hit is a **title, URL and excerpt**, not proof that Mara read the original page. The source strip accurately shows what the selected Web Search tool returned.
- **DuckDuckGo public results: conditional research fallback.** Deep Research contains a guarded public-HTML discovery path when its configured SearXNG search fails. It is not an unrestricted second web browser.
- **Playwright browser: optional, explicitly selected.** The **Research** action in the attachment/tool menu authorizes the separate browser reader. Ordinary Web Search must not silently launch Playwright.
- **Deep Research: optional, integrated.** This longer workflow combines guarded HTTP document readers, browser page reads, verified reference extraction, quality checks and reports. Discovery snippets alone are not accepted as proof that a page was read.
- **Public read-only MCP search discovery: optional and restricted.** An owner-configured HTTPS MCP tool can return public URL candidates to Deep Research. The endpoint and tool name are constrained, returned URLs are checked, and private account credentials or arbitrary MCP actions are not exposed.

Searches sent through a self-hosted SearXNG gateway can still reach third-party upstream engines. **Self-hosted search is not the same as fully offline searching**, and no search engine guarantees that every website is available.

### Media, system and notification integrations

| Integration | Status | Purpose |
| --- | --- | --- |
| **Open-Meteo API** | **Integrated, requires internet** | Optional geocoding and location-based weather forecasts for weather-related requests. |
| **Local FLUX.2-klein / pGen image service** | **Optional, integrated** | CPU-based image generation and supported edits; local job, status, preview and image APIs. Hardware, model and account-license checks apply. |
| **Local voice/TTS service (Eve/Pocket TTS)** | **Optional, integrated** | Speech output, available voices, previews and hands-free features; model-dependent audio input/transcription remains a separate capability. |
| **SMTP service or relay** | **Optional, integrated** | Authentication codes, password recovery, security alerts, notifications and owner test mail. Credentials are configured server-side. |
| **Web Push / VAPID** | **Optional, integrated** | Device subscriptions and supported push notifications over HTTPS, subject to browser permissions. |
| **GitHub repository/official installer** | **Integrated for update delivery** | Official source, release information, installer/update discovery. This is **not** a general GitHub account connector that lets Mara edit repositories or create pull requests on a user's behalf. |
| **Generic account-linked Connectors / OAuth app marketplace** | **Planned** | No general-purpose third-party account integration is promised simply because its entry exists as a placeholder. |

### CoreAI internal HTTP and streaming API

CoreAI's web frontend communicates with backend PHP endpoints using **JSON** and authenticated **Server-Sent Events (SSE)** for live replies/progress. These APIs are application interfaces, **not an officially supported unrestricted public REST/SDK service**. Do not expose private endpoints to the internet without the deployed authentication, TLS and reverse-proxy controls.

| API module | User-facing function | Protection / behavior |
| --- | --- | --- |
| `api/auth.php`, `api/account_security.php` | Authentication, sessions, second factors, security management | Session security, rate limits, signed-in controls |
| `api/console_stream.php` | Streaming conversation, tool activity and source previews | Authenticated conversation and scoped job, SSE |
| `api/console_sessions.php`, `api/console_messages.php` | Chat list, messages and conversation context | Account ownership, locks and visibility |
| `api/console_actions.php` | Message actions: feedback, alternative answer, continue, edit, branch into a new chat, compaction | Account ownership, CSRF for writes; refused while Mara is still writing |
| `api/models.php`, `api/user_settings.php` | Model inventory, Core tier preferences, user settings | Allowed-model filters and privileged variant updates |
| `api/console_upload.php`, `api/console_media.php` | Uploaded files, generated images and account media | Scoped storage and private media delivery |
| `api/console_project_store.php`, `api/project_files.php` | Projects, chats, text-file storage, versioning, versions and working states with merging and a Git export, the page preview, runs and long-running processes in the sandbox, and the preview of a development server | Project/account ownership, per-file constraints; previews are sent with a sandbox CSP; runs and processes need CSRF and the Python tool |
| `api/console_artifacts.php`, `api/console_artifact.php` | Generated files, ZIP outputs and download links, with their version, lifetime and notes of deleted files | Real registered artifacts and guarded downloads; the checksum of the verified bytes comes with every download |
| `api/skills.php`, `api/console_native_tools.php` | Curated Skills and controlled tool orchestration | Server-authorized tools, bounded actions |
| `api/skill_packages.php`, `api/skill_packages_store.php`, `api/skill_revisions_store.php` | Your own and shared skills with companion files (Settings > Skills), the skills fixed for a chat, revisions, export and import | Signed-in accounts only, CSRF on every change, sharing only for the owner and admins, chats through the console guard |
| `api/deep_research.php` | Research plan, status, cancellation and report download | Signed-in run ownership; CSRF for writes |
| `api/console_browse_stream.php` | Browser frames and step events | Account-scoped browser-job SSE |
| `api/image_gen.php`, `api/pgen.php` | Image-generation profiles, requests and results | Per-user quotas, hardware/license gates |
| `api/voice.php`, `api/voice_live.php` | Voice settings, read-aloud/live speech sessions | Account, device/browser and service availability |
| `api/notifications.php`, `api/web_push.php` | Notification preferences and browser push | Subscription permissions and push keys |
| `api/update.php`, `api/admin.php` | Owner update controls, system settings, diagnostics | Role-checked operations; privileged actions |
| `api/health.php` | Runtime dependency/health summary | Limited operational diagnostics, not an open admin endpoint |

**Security and data flow:** Write actions require the applicable session permissions and CSRF protection. Uploaded documents, memory, project sources and generated outputs are account-scoped. A selected external inference or search provider may process data off the host; provider configuration and privacy terms should be reviewed before enabling it. Do not paste API secrets into messages or commit them to Git.

### Interface, deployment and source configuration

- **Frontend:** responsive browser app (phone and desktop), with its own CoreAI angular theme, optional SoftGlass, and private session endpoints.
- **Application services:** Nginx, PHP-FPM, MariaDB, model queue/agent worker, Ollama, optional image/voice/browser workers and optional SearXNG service.
- **Configuration:** local overrides live outside committed secrets. See `api/config.local.php.example` and `api/config.php`. Change one operator-facing option at a time and run the preflight.
- **Developer access:** GitHub hosts the source and regression workflows; self-hosted installations do not require a ChatGPT/Claude developer subscription to run the default local chat.
- **Compatibility identifiers:** Some default install paths and Ollama tags retain older technical names. They are internal identifiers, **not extra software dependencies**.


## Updating CoreAI

### Recommended: update from the CoreAI interface

Once the initial setup has installed the host-side update service, **the owner can update CoreAI without SSH**.

1. Sign in with the **owner** account and navigate to **Settings → System → CoreAI Update**.
2. Select **Check now** to request a GitHub check. The host also checks the repository's `main` branch **approximately every 10 minutes** when the systemd update service is running.
3. When a new commit is available, choose **Open update window**, or use the notification shown to the owner. Review the version and available change notes.
4. Choose whether Ollama should be restarted afterwards, then select **Start update**. CoreAI creates a **database backup** first, runs its official setup and reports progress in the interface.
5. During service restarts, connected users see a waiting screen. The application reloads automatically when CoreAI is available again. If an update fails, the owner can inspect its status and update log.

**Important:** Automatic installation is **off by default** and requires the owner's explicit opt-in in **Settings → System → CoreAI Update**. If enabled, the host checks official `main` about every 10 minutes and installs a new merge one check later. A **Later** click pauses both the popup and automatic updates for **60 minutes**, including across reloads and new merges; manual updates remain possible. Each commit is attempted automatically at most once. A failed Preflight is logged with a **Retry** action; no repair is claimed until a corrected release exists. Auto-updates create backups and restart CoreAI services, so active conversations may be interrupted. Other roles cannot change these controls.

> **Interface language:** General offers German, English (beta) and browser-auto detection. Core UI labels have English translations; some strings remain German. Light/dark mode is under **Settings → Appearance**.

The updater is `scripts/coreai-updater.sh` with host-side systemd services; the web UI submits requests, not shell commands. Backups are stored under `/opt/ember-coreui/var/backups`, with the updater retaining the five most recent automatic SQL backups. The built-in updater follows the same official `main` setup path as the one-liner.

### Alternative: update over SSH

If the built-in updater is not yet installed or a server has no working systemd update service, rerun the **same one-liner from Quick start** on the host. This also installs/configures the update service where supported. A GitHub merge is not a completed server update.

For diagnostics:

```bash
systemctl status coreai-update.path coreai-update-check.timer
sudo tail -n 80 /opt/ember-coreui/var/update/update.log
```

The log may not exist before the first update.

## Uninstalling CoreAI

The **owner** can request a safe uninstall from **Admin Core → Operations**. Confirm with the owner password and `COREAI ENTFERNEN`. A host service stops only the `ember-coreui` Compose project and CoreAI's own systemd services. It archives the whole installation (MariaDB data, accounts, uploads, settings, backups) under `/opt/ember-coreui.uninstalled.*`. Other Docker apps, web servers and Ollama with its models stay untouched. A separate reverse-proxy route may need manual removal.

If the web UI or updater is broken, run this **standalone SSH one-liner** on the CoreAI server:

```bash
( uninstall_file="$(mktemp)" && trap 'rm -f -- "$uninstall_file"' EXIT && curl -fsSL https://raw.githubusercontent.com/Starlight-Unit-Studio/CoreAI-TCI-Interface/main/scripts/uninstall.sh -o "$uninstall_file" && sudo bash "$uninstall_file" --execute )
```

The interactive script also asks for `COREAI ENTFERNEN`. Preview without changes: `sudo bash /opt/ember-coreui/scripts/uninstall.sh --dry-run`. **Permanent data deletion** is available only through interactive SSH via `--purge-data` and a second, stronger typed phrase; it is intentionally unavailable from the admin UI. These commands support the standard Docker installation in `/opt/ember-coreui`, not native or custom installations.

## Hardware requirements

**CPU-only is the baseline.** CoreAI is designed to operate on a Linux root server **without a dedicated GPU or VRAM**. CPU performance, system RAM and SSD/NVMe storage matter more than a graphics card for the standard deployment.

These configurations are **planning guidelines, not benchmarks or guarantees**. They assume one locally active Ollama model in roughly 4-bit quantization and a relatively short 4K-8K token context. Much larger contexts, such as 131K tokens, can increase memory requirements substantially.

| CPU-only profile | System RAM | CPU | Available SSD/NVMe space | Intended use |
| --- | --- | --- | --- | --- |
| **E2B, restricted minimum** | **8 GB** | 4 dedicated cores | 40 GB | Short context; omit optional voice and browser worker; limited features |
| **E2B, recommended** | **16 GB** | 4-8 dedicated cores | 60-80 GB | More room for CoreAI services and tools |
| **E4B, recommended** | **24-32 GB** | 6-12 dedicated cores | 80 GB+ | Comfortable starting point for the standard local model |
| **12B, larger model** | **32 GB+** | 8+ dedicated cores | 100 GB+ | CPU generation may be noticeably slower |
| **26B A4B / 31B, large models** | **64-96 GB or more** | 12-16+ dedicated cores | 120 GB+ | Plan separately for context size, responsiveness and concurrency |

**Model memory is not server memory.** Google's [Gemma 4 model documentation](https://ai.google.dev/gemma/docs/core) gives approximate Q4 model memory figures of 2.9 GB (E2B), 4.5 GB (E4B), 6.7 GB (12B), 14.4 GB (26B A4B) and 17.5 GB (31B). They cover model loading only and **are not total server RAM requirements**: add memory for the KV cache, context, Ollama, Linux, containers, MariaDB, search and workers.

**Optional voice service:** Eve's measured voice configuration used about 2.5 GB RAM and up to four CPU cores; the container has a 6 GB memory limit. On small servers, consider `COREUI_INSTALL_VOICE=0` at first installation; changing an existing installation needs a configuration review and backup.

**Sandbox image:** Python jobs, runs and processes use the image `ember-py:2` (Python 3.12 with common data libraries and Node.js 24 with npm, about 750 MB). The installer builds it and removes the earlier `ember-py:1`; packages a project installs with npm or pip stay in its temporary work folder.

**Optional image service:** FLUX.2-klein 4B needs about 9.4 GB RAM for a 512 × 512 image and about 14.4 GB for 1024 × 1024 (20-thread AVX-512 CPU: about 1 and 3.5 minutes); the container has a 20 GB memory limit, and the weights take about 9 GB of disk space. It is installed by default on servers with 32 GB RAM or more; `COREUI_INSTALL_IMAGE=0` leaves it out, `COREUI_INSTALL_IMAGE=1` forces it. The first installation compiles stable-diffusion.cpp for the CPU (10 to 20 minutes).

### Optional high performance: GPU and VRAM

A compatible GPU or vGPU with enough VRAM **may substantially accelerate Ollama inference**, but is **not required**. Benefits depend on drivers, model support, quantization and how much model data and KV cache fit in VRAM; offloading may need host configuration and does not speed up every CoreAI tool.

### Example CPU root-server configurations

[netcup root servers](https://www.netcup.com/de/server/root-server) are included as **price-oriented examples**, not mandatory hardware or a claim that one provider always has the lowest prices in Germany. Server specifications and availability can change.

| Example netcup plan | CPU cores | RAM | Suggested profile |
| --- | ---: | ---: | --- |
| RS 1000 G12 | 4 | 8 GB | Restricted E2B entry point |
| RS 2000 G12 | 8 | 16 GB | Recommended E2B |
| RS 4000 G12 | 12 | 32 GB | E4B / 12B, depending on workload |
| RS 8000 G12 | 16 | 64 GB | Larger CPU-only models and more headroom |

No named hosting product is a required minimum. On a GPU-free server, RAM, memory bandwidth and CPU speed decide the practical experience.

## Access and important settings

By default, CoreAI binds to `127.0.0.1:9080` and is **not directly exposed to the public internet**. Use an SSH tunnel or a separately secured reverse proxy for remote access. Public deployment also requires appropriate HTTPS, access control and operator compliance measures.

| Variable | Default | Purpose |
| --- | --- | --- |
| `COREUI_HTTP_PORT` | `9080` | Local HTTP port |
| `COREUI_BIND_ADDRESS` | `127.0.0.1` | Web interface bind address |
| `COREUI_MODEL_NAME` | `ember-core:e4b` | New installs use this tag; existing saved model names remain unchanged |
| `COREUI_INSTALL_BROWSE` | `1` | Install the Playwright browser worker |
| `COREUI_INSTALL_SEARXNG` | `1` | Set up the dedicated search service |
| `COREUI_INSTALL_VOICE` | `1` | Attempt to build the optional Eve voice service |
| `COREUI_INSTALL_IMAGE` | `1` from 32 GB RAM, else `0` | Build the optional image service (FLUX.2-klein 4B) |

An existing installation's settings are not silently overwritten. `COREUI_FORCE_CONFIG=1` permits deliberate regeneration, **not a routine update**; review and back up configuration beforehand. See [`scripts/install.sh`](scripts/install.sh) for the full list of options.

## Operations and diagnostics

Leftover language-test accounts: preview `bash scripts/stack.sh exec php php scripts/cleanup-language-test-users.php --dry-run`; `--execute` requires confirmation.

Run these commands on the installed host:

```bash
cd /opt/ember-coreui
sudo ./scripts/preflight.sh
sudo ./scripts/stack.sh ps
sudo ./scripts/stack.sh logs --tail=100
curl -fsS http://127.0.0.1:9080/api/health.php
```

Local TCI tiers appear as **TCI Core Schnell, Normal, Plus, Pro and Premium**; newly built Ollama models use `ember-core:e2b`, `:e4b`, `:12b`, `:26b` and `:31b`. After a verified install or update only newly pulled temporary Gemma source tags are deleted; existing tags and older defaults stay (Ollama shares identical weight blobs). Cloud tags are unchanged.

In Settings > Profile, photo uploads open a touch crop editor (square for the user, free/portrait for the assistant). Originals and crop coordinates are kept for re-cropping. Image generation uses the 1024px reference.

Without the optional PDO SQLite driver, the portrait-reference check still covers avatar geometry and prompt consistency but skips its isolation tests; preflight then prints `PARTIAL`.

To inspect the optional voice or image service:

```bash
cd /opt/ember-coreui
sudo ./scripts/stack.sh logs --tail=100 voice
sudo ./scripts/stack.sh logs --tail=100 image
```

Use `sudo ./scripts/stack.sh restart` for a normal stack restart or `sudo ./scripts/stack.sh stop` to stop services without deleting data.

**Separate installation path:** `scripts/install-native.sh` is intended for an explicitly dedicated, empty host. It changes system-level services and is **not** the standard installer for parallel operation alongside other Starlight Unit applications.

## Repository and documentation

| Path | Purpose |
| --- | --- |
| `api/`, `js/`, `css/` | Application endpoints, client and CoreAI themes |
| `admin/` | Role-protected Admin Core |
| `tools/`, `skills/` | Tool workers and curated Skill instructions |
| `config/` | Runtime configuration and owner-maintained Modelfile |
| `database/migrations/` | Additive database migrations |
| `docker/`, `compose.yaml` | Standard isolated deployment |
| `scripts/` | Setup, updater, preflight and self-tests |
| `var/`, `uploads/` | Private runtime/user data; never commit to Git |

**Documentation map:** [All current capabilities](docs/CAPABILITIES.md) · [Installation, hardware, recovery](docs/OPERATIONS.md) · [Deep Research architecture](docs/DEEP_RESEARCH_HYBRID_ARCHITECTURE.md) · [Feature matrix and planned work](docs/COREUI_FEATURE_MATRIX.md) · [Project roadmap](ROADMAP.md) · [Release history](CHANGELOG.md) · [Version policy](docs/VERSIONING_POLICY.md).

The public README describes the **product as it exists in source today**. Version-by-version changes belong in the changelog and its [TXT archive](docs/changelogs-txt/INDEX.txt); future ideas belong in the roadmap. Developer conventions: [AGENTS.md](AGENTS.md) and [CLAUDE.md](CLAUDE.md).

## License, privacy and limits

CoreAI is free to use under a **Community Source license**, **not** an OSI-approved open-source license. Internal use is royalty-free within its terms; redistribution, branding and monetized hosting have additional conditions. The legally controlling text is [LICENSE.de.md](LICENSE.de.md), with [LICENSE.md](LICENSE.md), [COMMUNITY_POLICY.md](COMMUNITY_POLICY.md), [NOTICE.md](NOTICE.md) and [TRADEMARKS.md](TRADEMARKS.md).

**No automatic guarantee:** Not every Ollama model supports audio, vision, reliable tools or large context; not every server runs all optional services. Website access, third-party model licenses and cloud data transfers have their own conditions. Project and chat content stay account scoped, subject to server configuration and authorized tool access.

Copyright © 2023-2026 Patrick Schildgen, Starlight Unit Studios.
