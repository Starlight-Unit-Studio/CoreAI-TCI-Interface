# CoreAI: TCI Interface

<div align="center">
  <img src="assets/Quantum-CoreAI-github-banner_20261115_212829.png" alt="Quantum CoreAI - TCI Interface" width="100%">
</div>

[![DOI](https://zenodo.org/badge/1349352973.svg)](https://doi.org/10.5281/zenodo.22201310)

**CoreAI: TCI Interface** is a free, self-hosted AI chat and working environment built by **Starlight Unit Studios**. **Mara Thorne** is the single TCI assistant; **E.M.B.E.R.** is the framework that provides her models, tools, research, memory and work execution. CoreAI runs primarily on your own Linux server with **Ollama**, with optional outside services, and is **not** a standalone operating system.

**Current source version: `0.7.72`** | [CHANGELOG.md](CHANGELOG.md) | [Roadmap](ROADMAP.md) | [Capabilities](docs/CAPABILITIES.md) | [Operations](docs/OPERATIONS.md) | [License](LICENSE.md)

**Read this first:** CoreAI already has text chat, multiple Cores, bounded multi-step tools, web search, research, projects, files, memory and multimodal paths. **Availability depends on the chosen Core, installed optional services, model permissions and server hardware.** A merged GitHub change is not an installed or live-verified server update.

## Quick start: install CoreAI

Use the **official installer** on a Debian 12+ or Ubuntu 24.04+ Linux server with root/`sudo` access, internet access and adequate CPU, RAM and storage. The installer can set up the isolated Nginx, PHP-FPM, MariaDB, workers, Docker/Compose and Ollama environment.

~~~bash
( setup_file="$(mktemp)" && trap 'rm -f -- "$setup_file"' EXIT && curl -fsSL https://raw.githubusercontent.com/Starlight-Unit-Studio/CoreAI-TCI-Interface/main/setup.sh -o "$setup_file" && sudo bash "$setup_file" )
~~~

On first setup, enter an admin e-mail address and a password of **at least 12 characters**. The default installation path is `/opt/ember-coreui` (a retained historical path name). The app binds to `127.0.0.1:9080` by default; put remote access behind a properly secured HTTPS reverse proxy or use an SSH tunnel.

The installer follows the current `main` source, performs a preflight and preserves existing user data on normal updates. For complete setup parameters, host configuration, model downloads and recovery, use the [installation and operations manual](docs/OPERATIONS.md).


## What Mara can do

This is an inventory of **implemented, user-facing functions**, not a claim that every model supports them. Features marked **optional** require enabled services, hardware, account rights or individual model licenses. Source-level implementation and successful tests do not guarantee that a server has been updated. For evidence, scope and detailed limitations, see the [capability catalog](CAPABILITIES.md).

### 1. Conversation, thinking and agentic work

- Answer questions, explain complex topics, translate, summarize, draft, revise and discuss text or code using the selected TCI Core.
- Use one normal private chat as a workspace, with saved context, responses, follow-up suggestions and multimodal attachments; no artificial Chat/Work switch.
- Open a personalized welcome screen with starter prompts; a conversation is not stored until its first message or attachment.
- Choose the **TCI Core** tier separately from **Thinking**; the model-dependent options apply to subsequent turns.
- Let the operator configure which installed Original/Uncensored Ollama tag backs a tier in **Manage Cores**. The chat menu still lists just one row per tier.
- Optionally route eligible complex private text turns to a higher allowed local Core, subject to permissions and availability.
- Run a **bounded tool-and-result loop**: pick an allowed action, examine its real result, refine the task and decide whether another step is needed.
- Save, extend and continue structured Markdown plans through validated native plan actions. This does not grant unrestricted terminal access.
- Present real work events, technical steps and status; do not expose raw private model thinking or invent tool activity.
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
- Request weather context from the configured **WDR/Open-Meteo** integration; the normal search and browser tools remain separate.
- Respect account restrictions and **Lockdown** mode that disables web and browser access.

### 3. Code, calculations, files and generated outputs

- Handle chat attachments from Photos, Camera and Files (up to ten at a time within account limits).
- Analyze supported uploaded content with an appropriate text/vision/audio Core or tool, rather than assuming every Ollama model supports every media type.
- Use an isolated **Python** tool for allowed calculations and data tasks, with real results and errors.
- Produce named source/text files and validated **multi-file ZIP** downloads for suitable code-generation requests.
- Register completed output artifacts to the account/session and provide verified downloads; do not fabricate files or serve executable HTML as an in-origin preview.
- Write and revise Markdown plans, inspect source snippets and structure coding tasks; an unrestricted remote shell or complete IDE is **not** provided.
- Use a curated **Skills** catalog (research, software development, writing, documents and more) as task instructions and referenced knowledge, subject to tool permissions.
- Use observable plan, build and work-event steps without pretending that a named Skill automatically installs its own executable program.

### 4. Projects, sources and memory

- Create, rename, pin, configure and delete **chat-native projects**; place existing chats into project folders.
- Set project-specific instructions, add private sources and use **open or isolated project context** with project-scoped RAG-Lite retrieval.
- Use the same full composer in a project as in a regular chat: text, attachments, Core, Thinking, tools and voice.
- Save text files Mara produces within a project into its **Files** tab, with a folder tree, filename/content search and line numbers.
- Create, edit, rename, delete and download text files; compare revisions, restore earlier versions and export the current project as ZIP.
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

- Navigate recent, pinned, archived and project chats in a collapsible **mobile/desktop sidebar**, with quick search and switching.
- Pin/unpin a chat; search its messages; list its uploaded files; export its complete conversation as **Markdown**.
- Archive, restore, delete, or add a chat to a project; use account data controls for eligible bulk archive/delete/export actions.
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
- Manage **Ollama models**: inspect/install/update/delete eligible Cores, protect built-in defaults and map installed Original/Uncensored variants to a TCI tier.
- Control image licensing, optional services, mail settings, backup/update operations, tool permissions and server diagnostics.
- Update through the owner-only built-in host updater after initial setup, or through the official SSH installer; preserve accounts and user data.

### Not yet complete (do not advertise as shipped)

- General-purpose third-party **Connectors** and a fully integrated external-app marketplace.
- Full Hugging Face model-catalog browsing from the standard Core manager.
- An unrestricted Codex/Claude-Code-equivalent coding IDE, general host terminal or unlimited self-executing agent.
- Unsolicited scheduled conversations and broad background automations outside explicitly implemented events.
- Fully translated English/German UI, public creator profile, public chat-link sharing, and settings explicitly labeled **In preparation**.
- Guaranteed vision/audio/image support on every model, browser access to every site, or equal performance on every CPU server.

## Updating CoreAI

**Preferred after initial setup:** the owner opens **Settings > System > CoreAI Update**, checks for an update, reviews the change notes and starts it from the interface. The host-side updater first backs up the database, runs the official setup and reports success/failure. A host systemd service is needed; the browser alone does not update the server.

Automatic updates are **off by default**. When the owner explicitly enables them, the configured host service checks `main` at approximately 10-minute intervals. **Later** pauses prompts/automatic runs for 60 minutes; manual updates remain available. A merge does not itself deploy to an installed instance.

**SSH fallback:** rerun the same official one-liner above. See [updater setup, automatic-update behavior, backups and troubleshooting](docs/OPERATIONS.md#updating-coreai).

## Uninstalling CoreAI

The owner can start a safe uninstall from **Admin Core > Operations**, with password and typed confirmation. It archives the installation instead of silently purging it. A separate interactive [SSH uninstall procedure](docs/OPERATIONS.md#uninstalling-coreai) is available if the UI fails. Permanent deletion requires stronger host-side confirmation; other Docker apps and Ollama installations are not removed by the safe uninstall.

## Hardware and deployment

**CPU-only is the supported baseline; a GPU/VRAM is optional.** These are **planning guidelines**, not benchmarks or guaranteed minimums. Model quantization, token context, simultaneous services and memory bandwidth significantly change requirements.

| Local model tier | RAM guideline | CPU guideline | SSD/NVMe space |
| --- | --- | --- | --- |
| E2B restricted entry | 8 GB | 4 dedicated cores | 40 GB |
| E2B comfortable entry | 16 GB | 4–8 cores | 60–80 GB |
| E4B everyday | 24–32 GB | 6–12 cores | 80 GB+ |
| 12B larger | 32 GB+ | 8+ cores | 100 GB+ |
| 26B / 31B large | 64–96 GB+ | 12–16+ cores | 120 GB+ |

These totals include system headroom and are **not equivalent to Ollama weight size**. Larger context windows may increase RAM sharply. Optional speech, FLUX image generation and browser workers need additional resources; image downloads and builds also take disk space and time.

The standard private deployment uses an isolated Nginx/PHP-FPM/MariaDB/worker stack. Its default network bind is `127.0.0.1:9080`; publicly exposed installations need a secured reverse proxy, HTTPS and access controls. Default model/runtime names on new installations can differ from retained models on older hosts. A dedicated native installer also exists but is **not** the normal coexisting-app installation path.

[Detailed RAM and CPU estimates, optional image/voice requirements and supported installer flags](docs/OPERATIONS.md#hardware-requirements).

## Managing, diagnosing and backing up CoreAI

- **Model management:** Admins install, update or remove eligible Ollama models; the default is protected. The installed variant chosen per TCI tier is configured in **Manage Cores**. Image-generation pGen profiles have their own selection, hardware and license path.
- **Operator controls:** Admin Core provides user roles, limits, permissions, diagnostics and service options. The owner can configure mail, storage, secure updates and relevant tests.
- **Diagnostics:** From the installed host run `sudo ./scripts/preflight.sh`, `sudo ./scripts/stack.sh ps`, `sudo ./scripts/stack.sh logs --tail=100` or check `http://127.0.0.1:9080/api/health.php`.
- **Privacy:** Self-hosting does not require a subscription or phone-home. Optional cloud models, public search services and external integrations may send data off-server under their own terms.
- **Backups:** The owner updater backs up MariaDB before installing; the safe uninstaller archives installation data. Regular operator backups and access-control checks remain important.

For runnable commands, the native-install warning, voice/image service logs and reset procedures, read [Operations](docs/OPERATIONS.md).

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
