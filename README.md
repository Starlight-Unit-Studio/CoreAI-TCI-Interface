# CoreAI: TCI Interface

[![DOI](https://zenodo.org/badge/1349352973.svg)](https://doi.org/10.5281/zenodo.22201310)

**CoreAI: TCI Interface** is a free, self-hosted chat and work interface developed by **Starlight Unit Studios** as part of the Quantum software family. **Mara Thorne** is its TCI assistant; **E.M.B.E.R.** is the underlying framework.

CoreAI combines Ollama-based models with private conversations, browser research, personal knowledge, file tools and an administration interface. Its standard deployment runs an isolated Nginx, PHP-FPM, MariaDB and worker stack on a Linux server. CoreAI is an application, **not a standalone operating system**.

**Current source version: `0.7.32`** | [Changelog](CHANGELOG.md) | [Roadmap](ROADMAP.md) | [Documentation policy](docs/VERSIONING_POLICY.md) | [License](LICENSE.md)

> **Development status:** Features reflect the source, not every model or provider. A merge does not prove live deployment.

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

If the built-in updater is not yet installed or a server has no working systemd update service, rerun the **same one-liner from Quick start** on the host. This also installs/configures the update service where supported. Avoid treating a GitHub merge as an already completed server update.

For diagnostics:

```bash
systemctl status coreai-update.path coreai-update-check.timer
sudo tail -n 80 /opt/ember-coreui/var/update/update.log
```

The last command reads the updater's log; if no update has run, the log may not exist yet.

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

No named hosting product is a required minimum. A GPU-free server can run CoreAI, but available RAM, memory bandwidth and CPU speed determine the practical experience.

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

## Current capabilities

- **Mara and Cores:** The chat Core selector and Thinking effort are separate. In **Cores verwalten**, admins can search Ollama, install additional local Cores, inspect installed Cores, update individual local Cores and safely remove nonprotected Cores. The default Core and its base are deletion-protected; Ollama accounts are optional. Hugging Face browsing is **In preparation**. Inference stays serialized.
- **Local time awareness:** Mara uses the browser's IANA time zone to calculate date and time for private chats. No location permission is needed; invalid or unavailable values fall back to the configured server time zone.
- **Conversations and accounts:** The chat opens on an unsaved welcome screen with a personal greeting and four example questions. A new chat is saved only on its first text, attachment or spoken turn; earlier chats stay in the sidebar. History, follow-up suggestions, context management and owner, admin, user and optional guest roles remain. Mara's TCI header sits beside her avatar; her reply starts below it at full chat width.
- **Chat menu:** The ⋮ menu of a chat shares it as a Markdown file, pins it on top, lists its uploaded files, searches all its messages, gives a direct link for the home screen, locks it with the account PIN, adds it to a project, archives or deletes it. **Settings → Archived chats** searches, filters, restores and deletes them; **Settings → Data controls** exports the account and archives or deletes all chats (never locked or busy ones).
- **Research:** SearXNG search and a Playwright browser show research activity and sources. NRW weather uses WDR place search in browser mode, else the WDR overview when current; other places get Open-Meteo data. Search is a fallback. Open-Meteo's free API is non-commercial only.
- **Input row (+ menu):** **Attach** Photos, Camera or Files (up to ten). **Tools** Create image, Web search or Research; the chosen tool shows as a chip on your message. Unavailable tools are greyed with the reason; Deep Research and Connectors are marked **In preparation**.
- **Knowledge and tools:** Account-scoped RAG-Lite, memory, skill selection, file uploads and isolated Python tasks with registered outputs. Media handling depends on the selected Core and tools.
- **Notifications:** **Settings → Notifications** sends Push (Web Push, also when CoreAI is closed; iPhone/iPad from the Home screen) or E-Mail for long answers, sign-ins and password changes, new features after an installed update, and new CoreAI versions (owner). Planned categories show **In preparation**. The owner sets up E-Mail sending under **Admin Core → Operations → E-Mail sending** (STARTTLS, SSL/TLS, or plain only to a local relay; encrypted password, test mail); SMTP values in `api/config.local.php` or `COREUI_SMTP_*` take precedence.
- **Sign-in and security:** **Settings → Security** offers passkeys and security keys, an authenticator app, E-Mail codes, ten one-time recovery codes, Lockdown mode (no web or browser), advanced account security (passkey-only sign-in, no reset by E-Mail, 24-hour sessions), sign-out everywhere, security history. Account PIN, push approval and CSP are **In preparation**. Host-side reset: `php scripts/security-reset.php <email> [--passkeys]`.
- **Appearance:** **Settings → Appearance** offers System, Light or Dark, motif, accent, font, hex colors, separate light/dark looks, motif export/copy/import, font sizes, contrast, motion, full width, diff markers and opt-in smart punctuation for the chat (code and pasted text unchanged). Saved in this browser.
- **Settings navigation:** 24 pages with a swipe drawer and header Expert/Admin. ChatGPT-style settings, Denkaufwand, smart context sliders and simple visual switches are normal. **Expert** is only raw technical tuning such as token budget, temperature, provider internals or numeric memory weights.
- **Personalization:** Seven styles, formatting, instructions, tools, memory summary, image references and a persistent, movable chat pet. Mara Thorne is the default; other pets stay selectable. Avatars: **Settings > Profile**.
- **Account:** **Settings → Account** shows name, a unique @username, e-mail and plan. Plans (Free, Go, Plus, Pro) are templates the owner or admins assign in **Admin Core → Users**; they set real tools, daily messages and context window, no prices. Creator profile is **In preparation**.
- **Storage:** **Settings → Storage** shows used space and limit, Files (favorites, project folders, type filter, search, list or grid) and Images (Mara's creations, uploads, references); items open, download, go to their chat or are deleted. The owner sets one limit for all accounts in **Admin Core → Operations**; single accounts in **Users → Limits**. Full storage stops uploads and new images.
- **Keyboard:** **Settings → Keyboard** lists the chat shortcuts (send, focus, thinking, dictation, attach, delete or search a chat, new or switch chat, sidebar, shortcut list). Each can be switched off or re-recorded; clashes are refused. Kept per browser.
- **Usage:** **Settings → Usage** shows the plan, the daily message limit (24 hours from the first message) with time to reset, storage and today's messages. Analytics: tokens per day by Core or thinking level, top chats, tools, skills, messages by kind; only counts, never chat content.
- **Profile overview:** **Settings → Profile** shows tokens (counted since 0.6.84), peak day, longest task, streaks, a 53-week raster, what Mara made, top tools and insights; public profile and sharing are **In preparation**.
- **Main language:** In the AI profile, each account chooses German or English for Mara's default replies and read-aloud; the interface language is separate.
- **Read aloud:** Mara reads answers in German and English with a voice chosen per language under **Settings → Voice**: Eve (default) or 19 other freely licensed Pocket TTS voices, with preview. The language is detected per answer. Numbers, dates, times, versions and abbreviations such as E.M.B.E.R. are spoken as words; code blocks and tables are only named. Automatic read-aloud is optional and off by default; CoreAI works without the optional voice service.
- **Voice messages:** Signed-in accounts can record two-minute clips or upload audio files (up to ten minutes, depending on context and model). The chosen audio-capable Core transcribes them in the serial queue before answering; transcripts appear in chat and can inform memory. Voice replies may still use tools. **Settings → Voice** holds pronunciation hints, the recording language (or auto-detect), a switch for the mic button and the last 20 recordings, kept on the device only. Requires HTTPS; guests and unsupported Cores cannot record.
- **Videos:** Mara watches a video as frames with timestamps. For signed-in accounts with an audio-capable Core she also hears the sound track (WAV parts of at most 60 seconds within the context's audio budget), transcribed first; the transcript appears under the video and stays in history. Videos without sound, Cores without audio input and guests get an honest note; a shortened sound track is named.
- **Live conversation:** Signed-in users can talk hands-free over HTTPS. The full-screen view has listening, thinking, speaking, mute and end states. A short pause ends a turn, and speaking interrupts Mara's audio. Each turn is transcribed and saved; answers stream sentence by sentence through Eve. Mara Normal is preferred if permitted, else the chat Core. It needs the optional voice service; CPU inference can take seconds.
- **Images:** Signed-in users can generate one, two or four images with the optional CPU-only FLUX.2-klein 4B engine, save originals, view progress and edit with guidance shapes or text (not pixel-exact inpainting). Exports carry Quantum provenance without private prompts. Mara portraits may use reference photos from `images/mara_references/` for a stable identity; holograms add style only. Custom portraits override the gallery. The owner can disable image generation in Admin Core.
- **Admin Core:** The separate, role-protected **/admin/** page manages users, permissions, audit, runtime and owner-only voice/image samples. Authorized accounts see it in the header and category drawer; server checks still apply.

**Not yet a completed feature:** A selectable English/German interface localization, Deep Research and Connectors are planned and must not be presented as implemented. See the [Roadmap](ROADMAP.md), [Feature matrix](docs/COREUI_FEATURE_MATRIX.md) and [Voice plan](docs/COREUI_VOICE_PLAN.md) for scope and status.

## Operations and diagnostics

Leftover language-test accounts: preview with `bash scripts/stack.sh exec php php scripts/cleanup-language-test-users.php --dry-run`; `--execute` requires confirmation.

Run these commands on the installed host:

```bash
cd /opt/ember-coreui
sudo ./scripts/preflight.sh
sudo ./scripts/stack.sh ps
sudo ./scripts/stack.sh logs --tail=100
curl -fsS http://127.0.0.1:9080/api/health.php
```

Local TCI tiers appear as **TCI Core Schnell, Normal, Plus, Pro and Premium**; newly built Ollama models use `ember-core:e2b`, `:e4b`, `:12b`, `:26b` and `:31b`. After a verified install or update only newly pulled temporary Gemma source tags are deleted; existing tags and older defaults stay (Ollama shares identical weight blobs). Cloud tags are unchanged.

In Settings > Profile, photo uploads open a touch crop editor (square for the user, free/portrait for the assistant). Originals and crop coordinates are kept, so uploads can be cropped again. Image generation uses the 1024px reference.

The portrait-reference regression check uses optional PDO SQLite for account-isolation and memory-side-effect tests. On hosts without that driver, avatar geometry and prompt consistency are still checked; preflight prints a `PARTIAL` warning instead of reporting full coverage.

To inspect the optional voice service:

```bash
cd /opt/ember-coreui
sudo ./scripts/stack.sh logs --tail=100 voice
```

To inspect the optional image service:

```bash
cd /opt/ember-coreui
sudo ./scripts/stack.sh logs --tail=100 image
```

Use `sudo ./scripts/stack.sh restart` for a normal stack restart or `sudo ./scripts/stack.sh stop` to stop services without deleting data.

**Separate installation path:** `scripts/install-native.sh` is intended for an explicitly dedicated, empty host. It changes system-level services and is **not** the standard installer for parallel operation alongside other Starlight Unit applications.

## Repository structure and documentation

| Path | Purpose |
| --- | --- |
| `api/`, `js/`, `css/` | Web APIs, chat and settings UI |
| `admin/` | Admin Core |
| `database/migrations/` | Additive database migrations |
| `config/` | Runtime templates and the owner-maintained Modelfile |
| `docker/`, `compose.yaml` | Isolated standard deployment |
| `scripts/` | Setup, updater, preflight and self-tests |
| `skills/`, `tools/` | Skill instructions and executable tools |
| `var/`, `uploads/` | Local runtime and user data; do not commit to Git |

**Documentation policy:** This English-language `README.md` explains the product **as it exists today** and is **not a changelog**. Release history belongs in [CHANGELOG.md](CHANGELOG.md) and the [per-version TXT archive](docs/changelogs-txt/INDEX.txt). Developer rules: [AGENTS.md](AGENTS.md), [CLAUDE.md](CLAUDE.md) and [docs/VERSIONING_POLICY.md](docs/VERSIONING_POLICY.md). Historical records and the authoritative German license are preserved.

## License, privacy and limitations

CoreAI is free to use under a **Community Source license**. It is **not an OSI-approved open-source license**. Internal use is royalty-free within its terms; redistribution, protected branding and monetized hosting have additional conditions. The legally controlling text is [LICENSE.de.md](LICENSE.de.md); see also [LICENSE.md](LICENSE.md), [COMMUNITY_POLICY.md](COMMUNITY_POLICY.md), [NOTICE.md](NOTICE.md) and [TRADEMARKS.md](TRADEMARKS.md).

Self-hosting does not require a vendor subscription or license phone-home. **Cloud models and external services may process data off-server and may have their own usage limits, prices and privacy terms.** Evaluate those services separately from the CoreAI project license.

Copyright © 2023-2026 Patrick Schildgen, Starlight Unit Studios.
