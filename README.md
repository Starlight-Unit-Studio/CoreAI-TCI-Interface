# CoreAI: TCI Interface

[![DOI](https://zenodo.org/badge/1349352973.svg)](https://doi.org/10.5281/zenodo.22201310)

**CoreAI: TCI Interface** is a free, self-hosted chat and work interface developed by **Starlight Unit Studios** as part of the Quantum software family. **Mara Thorne** is its TCI assistant; **E.M.B.E.R.** is the underlying framework.

CoreAI combines Ollama-based models with private conversations, browser research, personal knowledge, file tools and an administration interface. Its standard deployment runs an isolated Nginx, PHP-FPM, MariaDB and worker stack on a Linux server. CoreAI is an application, **not a standalone operating system**.

**Current source version: `0.6.57`** | [Changelog](CHANGELOG.md) | [Roadmap](ROADMAP.md) | [Documentation policy](docs/VERSIONING_POLICY.md) | [License](LICENSE.md)

> **Development status:** The features below describe the current source code, not guaranteed capabilities of every model or cloud provider. A merged commit does not by itself prove a successful production deployment.

## Quick start: install CoreAI

Run the **official one-line installer** on the target server over an interactive SSH connection:

```bash
( setup_file="$(mktemp)" && trap 'rm -f -- "$setup_file"' EXIT && curl -fsSL https://raw.githubusercontent.com/Starlight-Unit-Studio/CoreAI-TCI-Interface/main/setup.sh -o "$setup_file" && sudo bash "$setup_file" )
```

This command downloads the setup launcher to a temporary file, executes it with root privileges and removes the temporary file afterwards. The same command can also update an existing installation, although the **built-in updater is the preferred day-to-day update method** after initial setup.

**Requirements:** Debian 12+ or Ubuntu 24.04+, root or `sudo` access, internet connectivity and sufficient CPU, RAM and storage for the chosen Ollama model. The installer can provision necessary host tools, Docker/Compose and Ollama. Downloads and model builds require additional disk space and bandwidth.

On first installation, the setup prompts for an administrator email and a password of at least 12 characters at the terminal. Its default installation path is `/opt/ember-coreui`; that historical directory name remains unchanged for compatibility.

The launcher installs the latest merged commit from `main` and runs the installer and preflight. An existing installation retains its local configuration, database, user accounts, uploads and logs. Complete the installation by checking the application in your browser.

An archived, separately checksum-verified historical `0.5.3-alpha` ZIP can still be selected explicitly through `COREUI_SETUP_SOURCE=release`. It is **not** the current source version. A `main` source checkout must not be represented as a newly verified ZIP release.

## Updating CoreAI

### Recommended: update from the CoreAI interface

Once the initial setup has installed the host-side update service, **the owner can update CoreAI without SSH**.

1. Sign in with the **owner** account and navigate to **Settings → System → CoreAI Update**.
2. Select **Check now** to request a GitHub check. The host also checks the repository's `main` branch **approximately every 10 minutes** when the systemd update service is running.
3. When a new commit is available, choose **Open update window**, or use the notification shown to the owner. Review the version and available change notes.
4. Choose whether Ollama should be restarted afterwards, then select **Start update**. CoreAI creates a **database backup** first, runs its official setup and reports progress in the interface.
5. During service restarts, connected users see a waiting screen. The application reloads automatically when CoreAI is available again. If an update fails, the owner can inspect its status and update log.

**Important:** CoreAI checks for updates automatically, but **does not install them without the owner's action**. The owner may dismiss an update notification until the next page load. Other account roles cannot initiate updates.

> **Interface language:** Button and menu names are presented in English throughout this documentation. The current application may still show German labels. A selectable English/German interface language remains planned. A system-aware light/dark appearance mode is already available under **Settings → Display**.

The updater is implemented by `scripts/coreai-updater.sh` and host-side systemd services; the web UI submits requests, not shell commands. Backups are stored under `/opt/ember-coreui/var/backups`, with the updater retaining the five most recent automatic SQL backups. The built-in updater follows the same official `main` setup path as the one-liner.

### Alternative: update over SSH

If the built-in updater is not yet installed or a server has no working systemd update service, rerun the **same one-liner from Quick start** on the host. This also installs/configures the update service where supported. Avoid treating a GitHub merge as an already completed server update.

For diagnostics:

```bash
systemctl status coreai-update.path coreai-update-check.timer
sudo tail -n 80 /opt/ember-coreui/var/update/update.log
```

The last command reads the updater's log; if no update has run, the log may not exist yet.

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

**Model memory is not server memory.** Google's [Gemma 4 model documentation](https://ai.google.dev/gemma/docs/core) gives approximate Q4 model memory figures of 2.9 GB (E2B), 4.5 GB (E4B), 6.7 GB (12B), 14.4 GB (26B A4B) and 17.5 GB (31B). Those figures concern model loading and **are not total CPU-server RAM requirements**. Allow additional memory for the KV cache, context window, Ollama, Linux, containers, MariaDB, search and other worker processes.

**Optional voice service:** Eve's measured voice configuration used about 2.5 GB RAM and up to four CPU cores; the container has a 6 GB memory limit. On small servers, consider disabling voice at first installation with `COREUI_INSTALL_VOICE=0`. Changes to an existing installation's settings require a deliberate configuration review and backup.

**Optional image service:** FLUX.2-klein 4B needs about 9.4 GB RAM for a 512 × 512 image and about 14.4 GB for 1024 × 1024 (measured on a 20-thread AVX-512 CPU: about one minute and three and a half minutes); the container has a 20 GB memory limit, and the weights take about 9 GB of disk space. It is installed by default on servers with 32 GB RAM or more; `COREUI_INSTALL_IMAGE=0` leaves it out, `COREUI_INSTALL_IMAGE=1` forces it. The first installation compiles stable-diffusion.cpp for the server's CPU, which takes 10 to 20 minutes.

### Optional high performance: GPU and VRAM

A compatible GPU or vGPU with sufficient VRAM **may substantially accelerate Ollama model inference**, but is **not required to run CoreAI**. Benefits depend on drivers, model support, quantization and how much model data and KV cache fit in VRAM. GPU offloading may need additional host configuration and does not automatically speed up every CoreAI tool.

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

- **Mara and models:** Core selection in the chat, Gemma 4 tiers, supported connected cloud models and a thinking toggle. In the Core list, a speaker marks Cores that accept audio input and a wave symbol marks local Cores the live conversation can run on. Model inference shares one serial work queue.
- **Local time awareness:** Mara uses the browser's IANA time zone to calculate date and time for private chats. No location permission is needed; invalid or unavailable values fall back to the configured server time zone.
- **Conversations and accounts:** Persistent sessions, history, follow-up suggestions, context management and distinct owner, admin, user and optional guest roles.
- **Research:** SearXNG-backed web search and a separate Playwright browsing mode. Sources and actual tool activity can be shown in the chat; a search result alone is not proof that its page was read. Weather questions about a place in North Rhine-Westphalia go to the place search of the WDR weather page: the browser research opens the page, enters the place and reads its forecast, and Mara reports back when it is done. Weather questions about every other place and country get live data instead of search snippets at once: Open-Meteo data for now and the next three days. Without browser research, NRW places get the current WDR overview (only when its time stamp is recent) with the nearest of its eleven regions, plus the Open-Meteo data. The normal search runs only when no weather source answers. Open-Meteo's free API is meant for non-commercial use.
- **Input row (+ menu):** the + button at the left of the input row opens a menu with three groups. **Attach:** Photos (images from the gallery), Camera (on devices with a camera, opens the back camera) and Files (up to ten files per message: PDF, audio, video, documents, archives). **Tools:** Create image, Web search and Research make Mara use that tool for the message instead of deciding herself; the chosen tool appears as a chip above the text (for Create image with **Normal** 512 × 512 or **Large** 1024 × 1024) and stays visible as a chip on your message in the chat, also after a reload or an edit. Without words of their own (for example together with a voice message) the chip still applies. Tools an account may not use stay visible but greyed out with the reason; guests never create images. Deep Research (a longer report from many sources) and Connectors (external services) are shown as **In preparation**: they are planned and not available yet. On a phone the menu opens as a sheet from the bottom edge.
- **Knowledge and tools:** Account-scoped RAG-Lite, memory, skill selection, file uploads and isolated Python tasks with registered outputs. Media handling depends on the selected model and available tools.
- **Appearance:** Select **System**, **Light**, or **Dark** under **Settings → Display**. Changes apply instantly to chat, settings, login and archives, follow OS changes in System mode, and are saved in the current browser.
- **Personalization:** Per-account tone, formatting, emoji density, tools and instructions. Under **Settings > Profile**, choose a display name and TCI portrait without changing Mara Thorne's technical identity. Assistant portraits retain the full image up to 1024 pixels on the longest edge; re-upload previously cropped images. Removing the portrait restores `images/mara_winter.jpg`. Profile changes create no memory entries.
- **Main language:** each account chooses German or English in the AI profile. Mara answers in it by default and still replies in any other language a person writes in; read-aloud uses it when an answer's language is unclear. Buttons and menus are still German; an English interface is planned.
- **Read aloud:** Mara can read answers using **Eve** (Pocket TTS) in German and English. The language is detected per answer; the English voice is loaded the first time an English answer is read and stays loaded while both voices fit into the service's memory limit. Before Eve speaks, numbers, dates, times and time ranges, time zones, version numbers, dotted abbreviations such as E.M.B.E.R. and common English terms are turned into spoken German; code blocks and tables are only named. Answers are read in growing parts so playback continues without long gaps. Automatic read-aloud is optional and disabled by default. CoreAI remains usable if the optional voice service is unavailable.
- **Voice messages:** signed-in accounts (not guests) get a microphone button next to the send button. A recording of up to two minutes is sent at once, together with any text already typed, and Mara hears it herself through the selected Core's audio input (Gemma 4 takes at most 60 seconds per WAV, so ffmpeg converts the recording to 16 kHz mono WAV and splits it at pauses). Voice messages are answered without the thinking phase. Uploaded audio files are heard the same way, up to ten minutes as far as the context window allows. Before Mara answers, the selected Core first transcribes the recording through the same serial work queue; Mara then gets both the audio and the transcript, which keeps her answer on the new message. The transcript appears under the voice message, keeps the conversation history readable and lets Mara decide what to keep in her long-term memory, as she does for text. Words in uploaded audio files are not treated as the user's own statements. In the AI profile, each account can list names and terms that the Core mishears (places, people, technical words, one per line, up to 40); the Core and Mara are told to spell them exactly that way. After a voice message Mara can use her tools like after a typed message, for example a web search. The browser records without its own noise suppression, which can blur soft consonants. Cores without audio input show the button as unavailable. The browser needs HTTPS for microphone access.
- **Videos:** Mara watches a video as a series of frames with timestamps. For signed-in accounts whose selected Core accepts audio input, she also hears its sound track: it is cut into WAV parts of at most 60 seconds (within the same audio budget of the context window as audio files), the spoken words are transcribed first, and frames and sound go into the same call. The transcript appears under the video and stays in the conversation history. A video without a sound track, with a silent track, a Core without audio input or a guest account get an honest note instead; a shortened sound track is named.
- **Live conversation:** while the input row is empty, the send button turns into the live button (a gently pulsing wave icon, still when the system asks for reduced motion); as soon as you type or attach something it is the send button again. The live button opens a full-screen conversation view (greeting, a state indicator for listening, thinking and speaking, mute and end). It works hands-free: a short pause (about 0.7 s) ends your turn, and speaking while Mara talks interrupts her. Each turn is transcribed first and Mara answers the text; her answer is streamed sentence by sentence and read aloud by Eve as soon as each sentence is ready, with the next part prepared while the current one plays (emojis are shown in the chat but not spoken). The live conversation always uses the Mara Normal Core when it is installed, hears and is allowed for the account, otherwise the chat's Core. The same Core writes the transcript of each turn. The conversation history is sent in a fixed order so Ollama can reuse its cache. Both sides of every turn are stored as normal chat messages (the spoken turn with its transcript, then Mara's answer), and Mara's long-term memory step runs after the conversation ends. Signed-in accounts only; it needs HTTPS and, for the voice, the optional voice service. On a CPU server expect a few seconds per turn: it is a fast turn-taking conversation, not a native speech-to-speech model.
- **Images:** Signed-in accounts can request **1, 2 or 4 images** through the optional CPU-based FLUX.2-klein 4B image service. Default output is 512 × 512, or 1024 × 1024 on request. Generation uses the shared serial model queue, with real progress stages, previews and completed thumbnails; cancellation or failure keeps finished images. Outputs remain private, attached to the chat and downloadable after reload, and are removed with their chat or account. Exports show only the subtle Quantum logo; PNG/XMP metadata credits Quantum CoreAI and TCI: Mara Thorne and records model details without private prompts. **TCI self-portraits** use the active profile photo as Mara Thorne's own identity, not a model or lookalike (fallback: `images/mara_winter.jpg`); a different nickname never changes that identity. Other image requests can use multiple account-scoped uploaded photos as separate references; image analysis alone does not create new images. The server-side **identity / subject / style** rule anchors faces, hair, physique and defining traits to the original reference while allowing new pose, costume and scene; style-only requires an explicit request. Likeness depends on the model. Guests cannot generate images; the owner can disable generation globally or per account under **Admin Core**.
- **Admin Core:** Account and model management, permissions, diagnostics, audit information, runtime controls and the integrated update interface. Owner-only cards show the results of the server tests for Mara's voice and for image generation (`scripts/tts-spike.sh`, `scripts/image-spike.sh`).

**Not yet a completed feature:** A selectable English/German interface localization, Deep Research and Connectors are planned and must not be presented as implemented. See the [Roadmap](ROADMAP.md), [Feature matrix](docs/COREUI_FEATURE_MATRIX.md) and [Voice plan](docs/COREUI_VOICE_PLAN.md) for scope and status.

## Operations and diagnostics

Run these commands on the installed host:

```bash
cd /opt/ember-coreui
sudo ./scripts/preflight.sh
sudo ./scripts/stack.sh ps
sudo ./scripts/stack.sh logs --tail=100
curl -fsS http://127.0.0.1:9080/api/health.php
```

Local TCI tiers appear as **TCI Core Schnell, Normal, Plus, Pro and Premium**; newly built Ollama models use `ember-core:e2b`, `:e4b`, `:12b`, `:26b` and `:31b`. After a verified install/update, only newly pulled temporary Gemma source tags are deleted. Pre-existing tags and older defaults remain intact for compatibility; Ollama shares identical weight blobs across tags. Cloud tags are unchanged.

In Settings > Profile, photo uploads open a touch crop editor: square for the user, free/portrait ratios for the assistant. Private original bytes and crop coordinates are retained, so existing uploads can be cropped again without re-uploading. Image generation keeps using the 1024px reference derivative.

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

**Documentation policy:** This English-language `README.md` explains the product **as it exists today** and is **not a changelog**. Release history belongs in [CHANGELOG.md](CHANGELOG.md) and the [per-version TXT archive](docs/changelogs-txt/INDEX.txt). Developer rules are maintained in [AGENTS.md](AGENTS.md), [CLAUDE.md](CLAUDE.md) and [docs/VERSIONING_POLICY.md](docs/VERSIONING_POLICY.md). Historical records and the authoritative German license are preserved.

## License, privacy and limitations

CoreAI is free to use under a **Community Source license**. It is **not an OSI-approved open-source license**. Internal use is royalty-free within its terms; redistribution, protected branding and monetized hosting have additional conditions. The legally controlling text is [LICENSE.de.md](LICENSE.de.md); see also [LICENSE.md](LICENSE.md), [COMMUNITY_POLICY.md](COMMUNITY_POLICY.md), [NOTICE.md](NOTICE.md) and [TRADEMARKS.md](TRADEMARKS.md).

Self-hosting does not require a vendor subscription or license phone-home. **Cloud models and external services may process data off-server and may have their own usage limits, prices and privacy terms.** Evaluate those services separately from the CoreAI project license.

Copyright © 2023-2026 Patrick Schildgen, Starlight Unit Studios.
