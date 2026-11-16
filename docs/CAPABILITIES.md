# CoreAI: detailed capability catalog

**Scope:** CoreAI source 0.7.74. [Product README](../README.md) | [Changelog](../CHANGELOG.md) | [Roadmap](../ROADMAP.md) | [Implementation matrix](COREUI_FEATURE_MATRIX.md)

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
- Write and revise Markdown plans, inspect source snippets and structure coding tasks; an unrestricted remote shell or complete IDE is **not** provided.
- Use a curated **Skills** catalog (research, software development, writing, documents and more) as task instructions and referenced knowledge, subject to tool permissions.
- Use observable plan, build and work-event steps without pretending that a named Skill automatically installs its own executable program.

### 4. Projects, sources and memory

- Create, rename, pin, configure and delete **chat-native projects**; place existing chats into project folders.
- Set project-specific instructions, add private sources and use **open or isolated project context** with project-scoped RAG-Lite retrieval.
- Use the same full composer in a project as in a regular chat: text, attachments, Core, Thinking, tools and voice.
- Save text files Mara produces within a project into its **Files** tab, with a folder tree, filename/content search and line numbers.
- Create, edit, rename, delete and download text files; compare revisions, restore earlier versions and export the current project as ZIP.
- In a project chat, Mara reads a single project file, changes exact passages or writes a new file; every change is all-or-nothing, saved as a revision marked as hers and can be restored.
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
| Skills and generated work | [skills.php](../api/skills.php), [console_native_tools.php](../api/console_native_tools.php), [console_artifact_store.php](../api/console_artifact_store.php) |
| Research | [console_web_research.php](../api/console_web_research.php), [coreai_deep_research.py](../tools/coreai_deep_research.py), [Hybrid architecture](DEEP_RESEARCH_HYBRID_ARCHITECTURE.md) |
| Projects and files | [console_project_store.php](../api/console_project_store.php), [project_files_store.php](../api/project_files_store.php) |
| Models and permissions | [core_tier_variants.php](../api/core_tier_variants.php), [model_catalog.php](../api/model_catalog.php), [user_settings.php](../api/user_settings.php) |
| Media | [image_gen.php](../api/image_gen.php), [voice_live.php](../api/voice_live.php), [voice_core.php](../api/voice_core.php) |
| Operator and hosting | [Operations manual](OPERATIONS.md), [Version policy](VERSIONING_POLICY.md) |

**Evidence limits:** The old [feature matrix](COREUI_FEATURE_MATRIX.md) also contains forward-looking requirements; these are not evidence of implementation. Current implementation and changelog statements take precedence. Function availability can vary by model, host, account and service. For all per-release changes, consult [CHANGELOG.md](../CHANGELOG.md).
