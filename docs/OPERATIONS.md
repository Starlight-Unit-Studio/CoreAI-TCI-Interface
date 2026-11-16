# CoreAI: installation, updates, hardware and operations

This reference preserves the complete operator and deployment guidance previously in the project README. It describes the **source**, not a guarantee that a host has already installed a merge. Start at the [product overview](../README.md) and the [capability catalog](CAPABILITIES.md) for what Mara can do.

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

An existing installation's settings are not silently overwritten. `COREUI_FORCE_CONFIG=1` permits deliberate regeneration, **not a routine update**; review and back up configuration beforehand. See [`scripts/install.sh`](../scripts/install.sh) for the full list of options.

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
