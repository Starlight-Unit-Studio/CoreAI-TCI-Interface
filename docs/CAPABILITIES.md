# CoreAI: detailed capability catalog

**Scope:** CoreAI source 0.7.89. [Product README](../README.md) | [Changelog](../CHANGELOG.md) | [Roadmap](../ROADMAP.md) | [Implementation matrix](COREUI_FEATURE_MATRIX.md)

Mara's OpenPet v2 chat Pet uses separate animation states for working, tool-waiting, reviewing, completing, errors, waving and dragging, plus idle gaze poses. Touch controls support tapping, double-tapping and dragging; legacy Pets remain compatible.

Installation output from setup, both installers and their preflights is in English, regardless of the selected chat UI language. This does not change the app's DE/EN interface settings or Mara's response language.

This catalog inventories Mara's existing user-facing tools and CoreAI's application functionality. **Built-in** means code is present, **optional** means another installed service, model, permission or license is needed, and **planned** means not yet a delivered feature. An installed source merge is not the same as server deployment or a live end-to-end test.

## What Mara can do

This is an inventory of **implemented, user-facing functions**, not a claim that every model supports them. Features marked **optional** require enabled services, hardware, account rights or individual model licenses. Source-level implementation and successful tests do not guarantee that a server has been updated. For API integrations, operating requirements and detailed boundaries, see the [full product README](../README.md).

### 1. Conversation, thinking and agentic work

- Answer questions, explain complex topics, translate, summarize, draft, revise and discuss text or code using the selected TCI Core.
- Use one normal private chat as a workspace, with saved context, responses, follow-up suggestions and multimodal attachments; no artificial Chat/Work switch.
- Open a personalized welcome screen with starter prompts; a conversation is not stored until its first message or attachment.
- Choose **TCI Cores or other compatible, installed Ollama language models**, including Hugging Face-sourced models installed through Ollama, separately from **Thinking**. Account permissions and model capabilities still apply.
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
- Retrieve current weather context using configured weather-data providers; normal search and browser navigation remain separate.
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
- Use observable plan, build and work-event steps without pretending that a named Skill automatically installs its own executable program.

### 4. Projects, sources and memory

- Create, rename, pin, configure and delete **chat-native projects**; place existing chats into project folders.
- Set project-specific instructions, add private sources and use **open or isolated project context** with project-scoped RAG-Lite retrieval.
- Use the same full composer in a project as in a regular chat: text, attachments, Core, Thinking, tools and voice.
- Save text files Mara produces within a project into its **Files** tab, with a folder tree, filename/content search and line numbers.
- Create, edit, rename, delete and download text files; compare revisions, restore earlier versions and export the current project as ZIP.
- In a project chat, Mara reads a single project file, changes exact passages or writes a new file; every change is all-or-nothing, saved as a revision marked as hers and can be restored.
- Preview an HTML page of a project in the **Files** tab: its stylesheets, scripts, SVG pictures and data files come from the project, links open its other pages, and script errors are listed with file and line. The preview shows the saved state, reloads after saving and runs isolated from the account (its own sandboxed origin, no access to cookies, chats or the API).
- Run a command on a project's saved files in the sandbox, from the **Files** tab or by Mara in a project chat (for example the project's tests). It is the same isolated container as Mara's Python: no access to the host or CoreAI, a read-only system, limits for memory, CPU, processes and 120 seconds. The real output and exit code are shown and kept for the last 20 runs; a running command can be cancelled. Mara can test, change a file and test again. The sandbox has Python 3.12 and Node.js 24 with npm and npx (for example `npm install && npm test` or `node --test`); the suggested command follows the project's files and its `package.json`, and files a command changes stay in the container. Runs count as the Python tool and follow its switches.
- Keep a process running on a project's files in the same sandbox, from the **Files** tab under **Processes**: a program, an interactive shell or a development server (Python or Node.js). Its output arrives live, lines typed there go to its standard input, and **Stop** ends it with its real exit code. Files saved meanwhile reach the running process within a few seconds. With a port, the **Preview** shows the development server's pages: stylesheets, scripts and pictures come from the server, links and GET forms stay on it, and fetch() requests reach it through CoreAI. No port is published; the server is only reached from inside its container, and the preview runs isolated from the account. One process per project and two per account run at once; a process ends after 30 minutes or when nobody has looked at it for 5 minutes. Processes count as the Python tool and follow its switches.
- Keep versions of a project's files in the **Files** tab under **Versions**: **Save** records the state of every file with a short description, and the list shows each version with what it changed, down to a line comparison of a single file. Working states (branches) each have their own files: unsaved changes stay with the working state they were made in, or move along to a new one on request, so two working states never change each other. **Restore** brings back any version on the active working state; unsaved changes are saved as a version of their own first, and the restored state is added as a new version, so nothing is lost. **ZIP with Git** downloads the active working state with a real Git repository of all working states and versions (`git log`, `git status` and `git checkout` work after unpacking; no Git program is needed on the server). **Merge** brings another working state's saved versions into the active one: a preview lists what comes over, files both sides changed are merged line by line (three-way, from the newest version both have in common), and each conflict (the same or neighbouring lines changed differently, or a file deleted on one side and changed on the other) needs a choice before anything changes: keep my version, take the other one, or edit a text with both versions between conflict markers. Unsaved changes are saved as a version first, the other working state stays as it is, and the merge is a new version with two parents, also in the Git export. A project keeps up to 10 working states and 200 versions; stored versions count toward the account's storage.
- In a project chat Mara works with versions herself: `[VERSION_SICHERN: description]` saves a version marked as hers, `[ARBEITSSTAND: name]` switches to a working state or starts her own from the active one (unsaved changes come along), and `[ZUSAMMENFUEHREN: name]` merges a working state into the active one only when there are no conflicts; otherwise nothing changes and the conflicts stay for the person under **Versions**. Merging needs migration 050.
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
- Manage **Ollama models**: inspect/install/update/delete eligible Cores, protect built-in defaults and map installed Original/Uncensored variants to a TCI tier.
- Control image licensing, optional services, mail settings, backup/update operations, tool permissions and server diagnostics.
- Update through the owner-only built-in host updater after initial setup, or through the official SSH installer; preserve accounts and user data.

### Not yet complete (do not advertise as shipped)

- General-purpose third-party **Connectors** and a fully integrated external-app marketplace.
- Direct Hugging Face Hub catalog browsing and one-click model installation in **Manage Cores** (in preparation). Compatible Hugging Face language models **already installed in Ollama** are usable as regular authorized chat models.
- An unrestricted Codex/Claude-Code-equivalent coding IDE, general host terminal or unlimited self-executing agent.
- Unsolicited scheduled conversations and broad background automations outside explicitly implemented events.
- Fully translated English/German UI, public creator profile, public chat-link sharing, and settings explicitly labeled **In preparation**.
- Guaranteed vision/audio/image support on every model, browser access to every site, or equal performance on every CPU server.


## Architecture and source reference

| Topic | Source of truth |
| --- | --- |
| Conversation and tool loop | [chat.php](../api/chat.php), [console_agent_loop.php](../api/console_agent_loop.php), [console_work_store.php](../api/console_work_store.php) |
| Skills and generated work | [skills.php](../api/skills.php), [skill_packages_store.php](../api/skill_packages_store.php), [skill_packages.php](../api/skill_packages.php), [console_native_tools.php](../api/console_native_tools.php), [console_artifact_store.php](../api/console_artifact_store.php) |
| Research | [console_web_research.php](../api/console_web_research.php), [coreai_deep_research.py](../tools/coreai_deep_research.py), [Hybrid architecture](DEEP_RESEARCH_HYBRID_ARCHITECTURE.md) |
| Projects and files | [console_project_store.php](../api/console_project_store.php), [project_files_store.php](../api/project_files_store.php) |
| Models and permissions | [core_tier_variants.php](../api/core_tier_variants.php), [model_catalog.php](../api/model_catalog.php), [user_settings.php](../api/user_settings.php) |
| Media | [image_gen.php](../api/image_gen.php), [voice_live.php](../api/voice_live.php), [voice_core.php](../api/voice_core.php) |
| Operator and hosting | [Operations manual](OPERATIONS.md), [Version policy](VERSIONING_POLICY.md) |

**Evidence limits:** The old [feature matrix](COREUI_FEATURE_MATRIX.md) also contains forward-looking requirements; these are not evidence of implementation. Current implementation and changelog statements take precedence. Function availability can vary by model, host, account and service. For all per-release changes, consult [CHANGELOG.md](../CHANGELOG.md).
