# Quantum CoreAI: User Handbook

Starlight Unit Studios | Product behavior verified against source 0.8.33 (25 November 2026)

This handbook explains how to use Quantum CoreAI: TCI Interface on a phone or desktop, from your first chat to research, development, personalization and administration. It describes the source version, not the installed state of your server. Some functions depend on your account, selected Core, enabled services, hardware or licenses. English interface labels are used throughout; the English UI is still in beta.

For installation and host administration, see [Operations](OPERATIONS.md). For the complete capability inventory and technical limitations, see [Capabilities](CAPABILITIES.md) and the [README](../README.md). For Mara's own product reference, see [Mara System Handbook](MARA_SYSTEM_HANDBOOK.md).

## 1. Getting started

### What is Quantum CoreAI?

Quantum CoreAI is a self-hosted AI assistant and working environment. Mara Thorne is its default Terran Cognitive Intelligence (TCI). You access it in a browser; the server supplies model inference, tools, files and storage. You do not need a desktop computer to use an existing installation. A CPU-only Linux server is supported; a GPU is optional. CoreAI is not an operating system or a dependency on the separate game chat system.

### Sign in and start a conversation

1. Open your operator's CoreAI address in a browser. Use HTTPS for remote access, microphone and security features.
2. Sign in with your account and complete the configured second factor if required. A guest session, if offered, has operator-defined restrictions.
3. Open a new chat. The welcome view offers starter prompts; selecting one sends its question. You can also type your own message.
4. Select a Core and Thinking setting if needed, then send the message.
5. Read the response or open the work timeline to see confirmed tool activity. Ask follow-up questions in the same conversation.

A draft welcome screen is not saved as a chat until the first message or attachment is sent. A normal saved chat can be a full working environment without creating a Project.

### Choose the right Core

The Core selector chooses the language model used for the conversation. TCI presets are convenient names for operator-selected models, not the only models supported. Other installed and authorized Ollama models may appear individually, including compatible Hugging Face GGUF models imported through Ollama. External Cores appear separately if enabled and released to your account.

- For everyday questions, start with a responsive smaller Core.
- For difficult analysis or large coding tasks, try a larger authorized Core or supported higher Thinking effort.
- For pictures, audio or video, select a Core that actually supports the required modality; a tier name is not a guarantee.
- If a Core is absent, ask your operator about installation, permissions and service availability rather than assuming a fault.

Thinking is a separate, model-dependent control. Some Cores support named effort levels; others support only an on/off choice. Increasing effort or context can make a response slower and use more server memory. An automatic higher Core preference may route eligible complex private text requests to a permitted higher tier.

### Context and performance

The context indicator and guided context-window setting describe how much text the selected Core can work with. They are not a measure of all stored messages, knowledge or memory. Long chats, retrieved documents, attachments and tool results consume context. Ask Mara to focus on relevant sections, or start a new chat with a useful summary if necessary. Model residency is separate from chat history: a resident model does not remember every earlier prompt indefinitely.

Local model calls are serialized across CoreAI. An active Core normally remains loaded until a different local Core is needed or the operator changes the policy. Waiting for another model call, loading a large model or processing a long context can cause delays. This is not by itself a failed run.

## 2. Everyday chat

### Ask, refine and stop

Use natural language. Describe the outcome, relevant constraints, preferred format and any required sources. For example: "Explain this contract clause in plain language", "Compare these two designs", or "Create a responsive website with three pages and test it." Mara can make mistakes, especially on unfamiliar, current or high-stakes subjects; verify critical results.

The input offers text, attachment/tool controls and voice features where available. Stop cancels supported active work. A running response may not accept edit, regenerate or branch operations until it stops. The work view shows confirmed events, not a promise that every planned step succeeded.

### Message actions

Use the action bar on a response to copy its text, read it aloud, rate it, or generate another answer where available. Editing a previous user message or regenerating an answer changes the conversation path; keep important output separately. Thumbs-up/down feedback is stored and may prompt one short reaction from Mara. It is not proof that the underlying model was retrained.

If a response shows "This run is not finished", use Try again or the highlighted retry action to generate a new answer for the same message. For development work, saved changes remain available; tell Mara what to continue or verify. Do not assume a retry resumes at exactly the same internal token or tool step.

### Navigation and chat organization

On mobile, open the collapsible sidebar to see Projects, Pinned and Recent. On desktop, the same organization is available in the sidebar. Use a chat's menu to rename, pin/unpin, archive, delete, move into a project or open its other supported actions. Pinned projects and chats share the Pinned section. Archiving hides a chat from the regular list without deleting it. Restore archived chats under Settings > Archived chats.

Use chat message search to find a passage and the uploaded-files view to revisit attachments. Export a conversation as Markdown when you need a portable copy. Branch from a Mara response to create a separate chat with the earlier conversation and attachments. The original remains unchanged; its generated downloads are not copied to the branch.

### Lock a chat

Set up the four-digit account PIN through the available account/security controls, then use the chat's lock option. Locked chats require verification and are excluded from regular previews and bulk deletion. Locking is not the same as deleting or encrypting a file with an independent key. Never disclose your PIN to Mara or another account.

## 3. Attachments, analysis and downloadable files

Use the + menu to select Photos, Camera or Files. Choose supported files, then describe what Mara should do with them. Up to ten attachments may be accepted at a time, subject to account and size limits. The available text, vision and audio tools determine which content can be understood. Scanned PDFs, silent video, unusual formats and very large documents may require additional extraction or a smaller selection.

Typical tasks include summarizing a PDF, explaining a screenshot, reviewing source code, analyzing a CSV and transcribing supported audio. Do not assume an attachment was fully understood just because it uploaded. Ask for a specific page, section, data column or visible feature to check the result.

### Create and manage files

Ask for an actual downloadable file rather than only a code snippet if you need a file on your device. CoreAI can register supported text/source files and verified multi-file ZIP archives. A finished file card includes a version derived from its SHA-256 checksum.

- Preview displays supported images and text (including HTML and SVG as source).
- Download saves the same verified version shown by the preview. An unexpected checksum or version is rejected.
- Keep marks a generated file as a favorite under Settings > Storage and exempts it from configured automatic generated-file deletion.
- Delete permanently removes the file and leaves a deletion note in its original chat message.

HTML source previews do not execute in the application. For a running web page, use the isolated development preview described in chapter 6. ZIP archives can be unpacked locally; inspect their contents before running unfamiliar code. A text response saying a file exists is not a substitute for a verified download card.

### Storage and retention

Settings > Storage shows stored items and storage use, including generated files. The owner can set automatic deletion for eligible generated files after 30, 90, 180 or 365 days; the default is unlimited. Kept files and project-chat files are excluded from this automatic cleanup. Download important material before its displayed deletion date. Deleting a chat, source or project may have separate consequences; read its confirmation dialog.

## 4. Web Search, Research and Deep Research

These are distinct workflows.

| Feature | Choose it for | Evidence |
| --- | --- | --- |
| Web Search | Quick current information and discovery | Search results, titles, URLs and snippets |
| Research | Reading and navigating relevant pages | Actual browser reads and visible tool events when available |
| Deep Research | Longer evidence-based investigations | A research plan, source register and report from confirmed reads |

### Web Search

Ask a question that needs current information or choose Web Search in the + menu. CoreAI uses its configured SearXNG search service by default; a separate Google account or Google Search API key is not required. Open sources from the search strip yourself when needed. A returned hit is not proof that Mara visited the page. Ask for additional sources or refine the question when the initial hits are insufficient.

### Research (browser)

Select Research in the + menu when you need actual page reading or browser navigation. The optional Playwright service must be enabled, the account must have permission and the site must be accessible. If live browser display is enabled, its work view can show the browser. Ordinary Web Search does not automatically launch it. Forms, JavaScript, CAPTCHA and login-required pages can fail or require user involvement; do not expect every site to be accessible.

### Deep Research

1. Select Deep Research if offered by your installation.
2. Enter a precise research question, desired scope, time range and deliverable.
3. Review the proposed plan and edit it if necessary.
4. Start the research. Follow confirmed search, read, review and progress events.
5. Inspect the resulting source register and report; preview or download the Markdown report when available.
6. Use Stop/Cancel if the task is no longer needed. Incomplete results are not a completed report.

Deep Research can use multiple network workers but shares the serial model queue. It checks sources and may follow additional leads. A source register distinguishes read material from discovery candidates. Review citations for relevance, accuracy and date, particularly for legal, medical, financial and current information.

Weather requests can use an enabled weather provider and are separate from generic web search. Lockdown mode disables web and browser access. Searches may reach third-party upstream engines even when SearXNG is self-hosted.

## 5. Projects, knowledge and memory

### Create and organize a Project

A Project is an optional grouping of related chats, instructions, sources and files. Open Projects in the sidebar, create a project and name it. Use its settings to specify a description, project instructions and context scope where offered. Start chats in the project or explicitly move an existing chat into it.

Project instructions help define a task but cannot override account permissions. Project sources are separate from other projects and other accounts. Choose the open or isolated project context appropriate to the task. An isolated project should not silently import personal context from unrelated chats. Organizational membership does not itself grant access to another person's project.

Use the Project page and its Files view to inspect its saved files. Removing a project deletes its project files and sources; its chat history is retained. Export important work first.

### Import files produced in chat

On an eligible generated file card, Import into project explicitly promotes the owned chat and verified ZIP/text source files into a project workspace. It is not an automatic action. A regular saved chat also has a private development workspace; moving a chat into a project does not silently overwrite or merge conflicting workspaces. Inspect and export your existing files before moving.

### Personal knowledge (RAG-Lite)

Open Settings > Knowledge to add reusable documents for your own account. Upload a supported file, set its title where offered, and wait for the source to be accepted and indexed. Ask a concrete question using a document title, distinctive topic or term. A project can have its own sources. Uploading a document to ordinary chat and adding it to reusable knowledge are different operations.

RAG-Lite extracts text, divides it into overlapping chunks and retrieves relevant excerpts for a request. It is not model training, and a retrieved excerpt may omit part of the original. Ask for specific material or provide the original file again when exact wording matters. Remove a source in Knowledge if it is no longer needed; its indexed chunks are removed with it. Private sources are not global operator sources.

Common formats include TXT, MD, PDF, DOCX, CSV, JSON and supported text/source formats. The default user-knowledge limit is 40 sources and 20 MiB per file, configurable by the operator; extraction and total account limits also apply. Use clean, searchable text rather than relying on image-only scans.

### Memory and personalization

Settings > Memory controls supported long-term facts and summaries. Settings > Personalization controls writing style, formatting, instructions and other preferences. Conversation history, stored facts, knowledge documents and project context are different systems. Turning off memory is not equivalent to deleting a chat or its files. Review saved data and use the relevant deletion/export controls when managing privacy.

## 6. Development: build, test, preview and revise

### Start in any saved chat

You do not have to create a Project first. Send a message to save the chat, then open Development beside the chat tool tabs. The responsive modal opens the same real file/editor, preview, command, process and version facilities used by Projects. A draft without a saved session asks you to send a first message.

Example: "Create a mobile-friendly portfolio with index.html, styles.css and app.js. Save the files, run appropriate checks and show me the preview." Inspect actual saved files and the work timeline. Ask for a later change by naming the existing file and outcome, such as "Add a guestbook page and link it in the navigation." A completed historical plan should not automatically be treated as a new full rebuild.

### Files and editor

Open the file tree; choose a file to read or edit. Use filename/content search and line numbers to locate a section. Create, rename, delete or download supported text files. Save changes and inspect the result. Mara can read, write and edit project files through authorized tools; changes are recorded as revisions and can be restored. An exact-match edit that cannot be resolved uniquely is rejected rather than corrupting a file. The system detects concurrent edits; do not overwrite another change without reviewing it.

### Preview a website

Select a saved HTML page in the development Files view. The isolated preview loads the project's own linked CSS, scripts, images and data and supports navigation between its pages. It reloads from saved state and shows script errors when available. It does not inherit CoreAI cookies, chat access or privileged APIs.

If a live development server is running, use its Preview, not an arbitrary localhost address in your phone browser. The port exists only inside the sandbox; it is not a public deployment. A preview of a file download is not the running website preview.

### Run a command

Use the command/run area of the development Files view to test the saved project, or ask Mara to do so. The sandbox offers Python 3.12 and Node.js 24 with npm/npx in the documented environment. Suitable examples include syntax checks, unit tests and package scripts. Review the actual exit code and output; a test that was not executed is not a passed test.

Commands run in an isolated container with a read-only system, resource limits and a 120-second command limit. They require the Python/run permission. They do not provide root access to the CoreAI host. Files a command changes in its temporary environment are not automatically committed to saved workspace files. The command history retains the last 20 runs.

### Processes and development servers

Under Processes, start an allowed program, interactive shell or dev server. The UI streams output and can send input, request supported pages and stop the process. Ask Mara to start a server on a suitable port, verify its output and then direct you to Preview. An open port is not published to the internet.

The documented limits are one process per workspace/project, two per account, 30 minutes total and five minutes without anyone looking at it. A stopped process does not necessarily delete the saved source files. Full terminal emulation, arbitrary host commands and all live-reload mechanisms are not provided.

### Versions and working states

Use Versions > Save to record the current file tree with a description. Inspect versions and their line-by-line changes. Create/switch working states for experimental changes; the states have independent files. Restore an older version as a new version rather than erasing history; unsaved changes are preserved as a version first.

Use Merge to preview bringing saved versions from another working state into the active one. For conflicts, choose your version, the incoming version or manually edit the conflicting text before applying. Mara merges on request only without conflicts. A ZIP with Git includes a real Git repository of working states and versions. This is not a GitHub connector or a direct deployment. The documented limits are ten working states and 200 versions per project, counted against storage.

### Long work, interruptions and completion

For authorized creation tasks, Mara can save a Markdown plan, continue verified steps, build files and run checks without a separate Continue after every stage. A plan-only request is not permission to build. Review the work timeline for successful writes, checks and any explicit blocker.

As of source 0.8.33, project runs do not have a global total-step or wall-clock cap. A repeated saved change of the same file triggers a completion check after six changes and pauses the run after ten. If this happens, the interim report lists changed files; saved changes remain. Inspect them, then use Try again or give a concrete continuation request. Failed or rejected edits are not counted as successful changes. A task is not complete merely because a long response stopped.

## 7. Skills

Skills are reusable task instructions and companion reference material, not new executable tools. Built-in skills are offered when relevant. Use Settings > Skills to create a private or project-specific skill: name, short name, description, trigger words, required tools, scope, priority and instruction. A skill can be disabled; it cannot grant access to a missing or forbidden tool.

The instruction supports up to 4,000 bytes and up to 20 companion text files of up to 8,000 bytes each in references/, templates/, scripts/ or assets/. Script files are reference text, not automatically executed. Name missing resources explicitly and correct them before relying on a skill.

Use /skill short-name to request a skill. In the + menu, Skills for this chat can fix up to three available skills. Remove a fixed selection or choose Let Mara choose to restore automatic selection. Context limits can still prevent every selected skill from loading.

Owners and admins can share their own non-project skills with signed-in accounts. Shared skills can be read or individually disabled; guests do not receive them. A project skill has priority over an own or shared skill with the same short name. A specifically fixed variant resolves ambiguity.

Use History to inspect skill revisions, Compare to review changes and Restore to create a new revision from an earlier one. Export a skill or revision as ZIP and Import a compatible package as a new skill or new revision. Invalid or unsafe paths, links, binary or oversized files are rejected. The last 100 revisions are retained. A concurrent-save conflict requires review rather than silent overwrite. A running turn uses the revision selected for that turn.

## 8. Images, vision and profile references

### Understand pictures

Attach a picture and ask a specific question about its contents. Use a compatible vision Core. If the model cannot see images, switching to a capable authorized Core or enabling the needed service may help. Do not assume an image filename means Mara has examined its pixels.

### Generate images

Choose the image-generation tool if available and describe the subject, composition, style and desired output. The optional local pGen/FLUX.2-klein service supports one, two or four images per request where allowed. Wait for actual job progress and result cards; use the gallery or download to inspect the originals.

In Manage Cores, eligible pGen profiles are Standard or Magic, in 4B/9B variants. Availability depends on the operator, hardware, weights and the account's license acceptance. A Magic profile cannot silently fall back to Standard or bypass its individual license. Generation and vision are separate capabilities. Guests have a default allowance of three images per server day when generation is enabled; operators may configure limits.

### Edit images and maintain Mara's appearance

Use supported image editing controls, text instructions and guidance shapes where offered. The service is not guaranteed pixel-perfect inpainting. For identity consistency, configure Mara's profile portrait and additional personal reference images in the profile editor. Ask for the configured appearance and inspect the resulting image. The quality of resemblance depends on the selected models and inputs. Avatar cropping and the account's reference image controls do not guarantee an identical generated face.

## 9. Voice, audio and video

### Stimmsequenzen / voice messages

Use the microphone control in the chat, grant browser microphone permission, speak and finish the recording. Review the transcription in chat. Supported voice input can recognize a selected language and pronunciation hints; longer audio can be attached subject to limits. Voice message recording and file-based audio analysis are distinct features.

### Read-aloud

Choose the read-aloud action on a response or enable automatic read-aloud under voice settings. The optional Eve/Pocket TTS service provides licensed German/English voices and previews when installed. Adjust voice and pronunciation preferences where available. Long code blocks may be skipped in speech.

### Live voice

Use the Live voice control where available, allow microphone access over HTTPS and watch listening, thinking and speaking states. Mute, interrupt or stop as appropriate. Supported turns can be saved in chat. If live voice fails, check microphone permissions, HTTPS, audio output and the optional voice service before assuming a Core problem.

### Video and audio content

Attach a supported video and ask about particular timestamps or its content. A vision-capable Core can inspect selected video frames with timestamps. Soundtrack transcription requires compatible audio processing and is limited by the context budget. State whether you need visual analysis, transcription or both. A silent, unsupported or truncated recording must not be presented as fully heard.

## 10. Personal settings and interface

Settings categories may differ by installed version, role and service. A visible "In preparation" entry is not an available feature.

| Area | What to configure |
| --- | --- |
| General | Interface language, automatic higher Core, supported Thinking settings |
| Appearance | System/Light/Dark, motif/theme, accent, fonts, contrast, motion and chat width |
| Notifications | Eligible long-task, security and update alerts via push or e-mail |
| Profile | Name, portrait, activity, tokens and tool-use statistics |
| Security & sign-in | Password, passkeys, second factors, recovery, sessions, Lockdown |
| Account | Account identity, username, e-mail, assigned plan and sign-out |
| Archived chats | Search, restore or delete archived conversations |
| Voice | Speech and recognition preferences |
| Storage | Stored material and generated-file retention information |
| Personalization | Base writing styles, formatting, instructions, Mara's name/portrait and references |
| Pets | Animated chat companion configuration |
| Keyboard | Supported sending shortcut |
| Data controls | Available account data export and bulk chat actions |
| Skills | Private, project and shared skills |
| System | Runtime information and owner-only update controls |
| AI profile | Answer language, guided context and eligible Expert controls |
| Knowledge / Memory | Reusable sources and separate long-term memory controls |

Expert mode changes the visibility of advanced controls; it is not an admin role and does not bypass permissions. Change raw temperature, context or system-instruction settings only if you understand their consequences. The operator Modelfile remains authoritative.

### Pets

The optional animated Pet is a visual companion, not a separate AI or a guarantee that a task is running. Select a supported built-in, personal or operator-published Pet in the available Pets settings, or hide it. Mara's OpenPet v2 supports distinct working, waiting, review, completion and failure states. Tap to wave, double tap to jump, drag to move and use idle gaze on desktop. Legacy compatible formats remain supported. If animations seem wrong, compare the actual work timeline rather than diagnosing a run from the sprite alone.

## 11. Account security, privacy and permissions

CoreAI has owner, admin, user and guest roles. Available tools, models, storage and message/image quotas are enforced by the server. Plans and display labels are not independent authorization.

Use supported passkeys/security keys, authenticator codes, e-mail codes or recovery codes as configured. Save recovery codes securely outside chats. Review active sessions and security history, and sign out all sessions when necessary. Lockdown prevents web and browser access. Never send passwords, recovery codes or API keys in ordinary chat messages.

Local inference stays with the configured server, but external Cores send inference inputs, including relevant chat history and project file contents, to their configured provider. Ollama cloud tags may also use a remote provider. Self-hosted SearXNG can contact upstream search engines. Choose a suitable local workflow for sensitive information and review the operator's data handling. Do not assume every service is offline simply because CoreAI is self-hosted.

## 12. Operator and administrator guide

This chapter is for the appropriate server role, not instructions to bypass it. An authorized owner/admin opens Admin Core using its available Settings link. Admin and owner privileges are not identical.

### Install and access

See [Operations](OPERATIONS.md) for the official one-line SSH installer and hardware matrix. The official launcher downloads setup.sh from the project's main branch, runs it on Debian 12+ or Ubuntu 24.04+ with root/sudo, prompts for initial admin credentials and runs preflight. The default installation directory is /opt/ember-coreui and the default bind address is loopback on port 9080. Configure a secured reverse proxy or tunnel for remote access. Never expose private backend endpoints directly. CPU-only operation is the baseline, not a performance guarantee; account for model weights, context/KV cache, services and storage.

### Accounts and permissions

Under Admin Core, use the applicable user controls to create/manage accounts, assign roles and permission/limit templates, release supported Cores and manage tools. Review audit/security status and storage consumption. Grant only the access required. Guest settings and limits are separate from signed-in account settings.

### Core library and Manage Cores

Use the unified Core library to browse/search Ollama and public Hugging Face GGUF repositories. Empty searches may show recent suggestions. Inspect the model's size, format, capabilities and license before installing. Use the installation progress view and verify the installed model appears in inventory. Private/gated Hugging Face installation is not supported through this library.

In Manage Cores, select installed Original/Uncensored tags for the TCI tier presets, manage individually released models and eligible pGen profiles. Protect required default Cores and do not remove a model in use without planning its replacement. Grant access to the account and verify the Core appears in the chat selector. Installing a Core does not automatically give every account permission to use it.

### External adapter

The owner opens Admin Core > Operations > External adapter. Enter the display name and supported base URL, save the API key through the dedicated secret field, load or add provider models, choose those to offer, enable and save the adapter, then check connection. HTTPS is required except supported localhost addresses. Admins can see status/check connection but do not change the owner's adapter or key. API keys are encrypted at rest and not displayed again; a server-configured key overrides the UI field. Release each external Core under Admin Core > Users > Releases. Guests cannot use external Cores.

The generic adapter speaks the OpenAI-compatible chat/completions format; provider-native APIs are not independently implemented simply because their names exist. The provider's terms, costs, privacy and model availability apply. Optional voice, browser and image generation stay local when using this external inference adapter.

### Updates and diagnostics

Prefer Settings > System > CoreAI Update as owner after initial setup. Select Check now, open the available update window, review change notes, choose whether to restart Ollama and start the update. The host updater backs up the database and runs the official setup. Connected clients may see a waiting screen and reload when services return. Automatic updates are off by default; the owner may opt in. Later postpones automatic updates and its popup for 60 minutes.

If preflight fails, inspect the update log and reported error; do not bypass the check or claim the update is successful. A retry requires a valid corrected source or resolved host fault. If the updater is unavailable, use the official SSH setup fallback from Operations. Verify the installed version and live functions after updating. GitHub source changes and a server update are not the same thing.

### Backup, logs and removal

Use operator backup procedures, health status, logs and audit views to diagnose failures. Updater backups keep the five most recent automatic SQL backups; ensure additional independent backups where required. Do not publish logs containing private data. Safe uninstall in Admin Core > Operations requires owner password and the explicit confirmation phrase shown by the UI. The documented standard Docker uninstall archives the installation and leaves unrelated services and Ollama models intact. Permanent deletion is a separate, stronger-confirmed SSH-only action. See Operations for exact commands and restrictions.

## 13. Troubleshooting

| Symptom | What to check or do |
| --- | --- |
| A Core is missing | Installed inventory, actual model compatibility, per-account release and provider availability |
| A response waits a long time | Serial queue, model loading, Thinking, context size and actual work events |
| A response stops unfinished | Try again; for a project inspect saved files and give a focused continuation request |
| Repeated edit rejected | Re-read the saved file; match a unique current passage rather than stale line numbers |
| Development tab asks for a message | Save the chat by sending the first message |
| Preview is blank | Save HTML, check linked paths and script errors; for a server check process/port and use its isolated Preview |
| A downloaded file is unavailable | Check the file card/version, storage quota, deletion note and retention date |
| Search finds a page but cannot read it | Use authorized Research; a snippet is not a page read |
| Web tools are missing | Account permissions, Lockdown and search/browser services |
| Knowledge answer is inaccurate | Verify the source was indexed, use specific terms, check context scope and original text |
| Image tool is absent or fails | Account quota, pGen profile, hardware, weights, license and service status |
| Mara does not see an image/video | Verify the selected Core's vision and audio capabilities and input limits |
| Voice is silent or unavailable | HTTPS, microphone/output permission, selected voice and service status |
| A settings item says In preparation | Feature is not implemented; consult the current Roadmap rather than inventing a workaround |
| Update fails preflight | Owner checks the real log and repairs the cause; do not bypass |

For a support report include installed CoreAI version, role (not credentials), selected Core, tool/action, expected behavior, actual result, error text, and sanitized screenshots or relevant log extracts. A successful HTTP request, started process or written plan does not prove the user task finished.

## 14. What is not currently provided

General account-linked third-party connectors and marketplace, unrestricted host shell, direct GitHub editing/deployment from Mara, unrestricted external coding IDE, full terminal emulator, all live module-reloading mechanisms, importing a Git repository, general scheduled autonomous conversations, full UI translation and planned public sharing/creator features are not shipped merely because they appear in a roadmap or menu. Actual behavior depends on the installed version and services.

## 15. Further reading and maintenance

- [README](../README.md): complete product and installation description.
- [Capabilities](CAPABILITIES.md): implemented capabilities and optional dependencies.
- [Operations](OPERATIONS.md): installer, requirements, updates, removal and host diagnostics.
- [Skills](COREUI_SKILLS.md): built-in skills reference.
- [Mara System Handbook](MARA_SYSTEM_HANDBOOK.md): product knowledge prepared for future RAG-Lite integration.
- [Changelog](../CHANGELOG.md) and [Roadmap](../ROADMAP.md): historical changes and planned work, respectively.

Maintain this handbook whenever a released change affects a user action, permission, requirement, menu path or limitation. Never replace current instructions with old changelog entries. This source review is not a live acceptance test.
