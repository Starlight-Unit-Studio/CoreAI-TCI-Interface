# CoreAI: TCI Interface

[![DOI](https://zenodo.org/badge/1349352973.svg)](https://doi.org/10.5281/zenodo.22201310)

**CoreAI: TCI Interface** ist die kostenlose, selbst hostbare Chat- und Arbeitsoberfläche von **Starlight Unit Studios** aus der Quantum-Softwarefamilie. **Mara Thorne** ist die integrierte TCI-Assistentin. E.M.B.E.R. bezeichnet das zugrunde liegende Framework.

CoreAI verbindet Ollama-Cores mit Chats, Recherche, persönlichem Wissen, Dateiverarbeitung und einem eigenen Administrationsbereich. Der Standardbetrieb nutzt einen isolierten Web-, Datenbank- und Worker-Stack auf dem eigenen Linux-Server. CoreAI ist eine Anwendung, kein eigenständiges Betriebssystem.

**Aktueller Quellstand: `0.6.2`** | [CHANGELOG.md](CHANGELOG.md) | [Roadmap](ROADMAP.md) | [Lizenz (DE)](LICENSE.de.md)

> **Entwicklungsstand:** Die folgenden Funktionen beschreiben vorhandene Bestandteile des Quellstands. Nicht jedes Modell oder jede Cloud-Verbindung unterstützt alle Werkzeuge. Ein GitHub-Merge ist kein Nachweis eines erfolgreichen Live-Deployments.

## Schnellstart: Installation und Aktualisierung

Der **offizielle One-Liner** installiert CoreAI erstmals oder aktualisiert eine vorhandene Installation auf den neuesten gemergten Stand des Branches `main`:

```bash
( setup_file="$(mktemp)" && trap 'rm -f -- "$setup_file"' EXIT && curl -fsSL https://raw.githubusercontent.com/Starlight-Unit-Studio/CoreAI-TCI-Interface/main/setup.sh -o "$setup_file" && sudo bash "$setup_file" )
```

Den Befehl **auf dem Zielserver in einer interaktiven SSH-Shell** ausführen, nicht im Browser. Der Launcher wird in eine temporäre Datei geladen, mit Root-Rechten gestartet und anschließend entfernt.

**Voraussetzungen:** Debian 12+ oder Ubuntu 24.04+, `sudo`/Root-Rechte, Internetzugang und ausreichend Ressourcen für das gewählte Ollama-Modell. Der Installer kann benötigte Pakete sowie Docker, Compose und Ollama nachinstallieren. Der Modell- und Container-Download benötigt entsprechend Speicherplatz und Bandbreite.

Bei der **Erstinstallation** fragt das Setup Admin-E-Mail und ein Passwort mit mindestens 12 Zeichen über das Terminal ab. Das Standardverzeichnis ist `/opt/ember-coreui`. Dieser historische technische Pfad bleibt aus Kompatibilitätsgründen erhalten.

Bei einer **Aktualisierung** denselben One-Liner erneut ausführen. Das Setup erkennt die Installation, bewahrt lokale Konfiguration, Datenbank, Konten, Uploads und Logs und führt erforderliche Migrationen sowie den Preflight aus. Danach die Oberfläche im Browser tatsächlich prüfen.

Der gesondert archivierte, SHA-256-verifizierte ZIP-Stand `0.5.3-alpha` bleibt explizit über `COREUI_SETUP_SOURCE=release` verfügbar. Er ist **nicht** der aktuelle Quellstand. `main` wird nicht als neu geprüftes ZIP-Release ausgegeben.

## Hardwareanforderungen

**Hardware-Richtwerte fuer Selbsthosting, keine garantierten oder live vermessenen Mindestwerte.** Die Zahlen gelten fuer einen Linux-Server mit CoreAI, Ollama und Docker, ein aktives lokal laufendes Modell in **4-Bit-Quantisierung (Q4)** und zunaechst einem begrenzten Kontextfenster (z. B. 4K bis 8K Tokens). Fuer andere Quantisierungen, grosse Kontextfenster, mehrere aktive Nutzer und weitere Dienste mehr Ressourcen einplanen.

| Betriebsprofil | RAM gesamt | CPU | Freier Speicherplatz | Einordnung |
| --- | --- | --- | --- | --- |
| E2B, eingeschraenkter Test | ab **8 GB** | ab 4 vCPU | ab 40 GB | Nur mit kurzem Kontext, ohne optionalen Voice- und Browse-Worker; nicht als Standardinstallation empfohlen |
| **E2B, empfohlen** | **16 GB** | 4 bis 6 vCPU | 60 bis 80 GB | Reserve fuer CoreAI-Dienste, kurze bis mittlere Chats und optionale Werkzeuge |
| **E4B, empfohlen** | **24 bis 32 GB** | 6 bis 8 vCPU | ab 80 GB | Sinnvolle Orientierung fuer die regulaere lokale Nutzung; die Standardinstallation verwendet E4B, falls kein geeignetes Basismodell vorhanden ist |
| 12B, Orientierung | ab **32 GB** | ab 8 vCPU | ab 100 GB | 4-Bit-Modell, begrenzter Kontext; CPU-Ausgabe kann deutlich langsamer sein |
| 26B A4B / 31B, Orientierung | **64 bis 96 GB oder mehr** | 12 bis 16 vCPU | ab 120 GB | Fuer grosse lokale Cores; Kontextfenster und Antwortgeschwindigkeit koennen wesentlich hoehere Anforderungen verursachen |

**Warum RAM nicht nur die Modellgroesse ist:** Google nennt fuer Gemma 4 bei Q4 ungefaehr 2,9 GB (E2B), 4,5 GB (E4B), 6,7 GB (12B), 14,4 GB (26B A4B) und 17,5 GB (31B) fuer das Laden des Modells. **KV-Cache, Kontextfenster, Ollama, Linux, Docker, MariaDB, Websuche und laufende Arbeitsprozesse kommen hinzu.** Daher sind diese Modellwerte keine vollstaendigen Server-Mindestanforderungen. Quelle: [Google Gemma 4 Modelldokumentation](https://ai.google.dev/gemma/docs/core).

**Optionale Sprachausgabe:** Eve benoetigt im gemessenen Test etwa 2,5 GB RAM und verwendet bis zu 4 CPU-Kerne; das Containerlimit ist 6 GB. Bei wenig RAM den Sprachdienst mit `COREUI_INSTALL_VOICE=0` bereits bei der Erstinstallation abwaehlen. Bei bestehenden Installationen Installationsoptionen nicht ohne Konfigurationspruefung aendern.

**CPU statt GPU:** Eine dedizierte Grafikkarte ist fuer den CPU-Betrieb nicht zwingend. Genuegend RAM bedeutet aber nicht automatisch gute Geschwindigkeit: CPU-Leistung, Speicherbandbreite, Quantisierung und Kontextgroesse bestimmen die nutzbare Ausgabe. Die Tabellenwerte sind Planungsempfehlungen, **keine Benchmarks und keine Zusage einer bestimmten Tokens-pro-Sekunde-Rate**.

**Serververgleich:** Der Netcup-Tarif *VPS 8000 G12.5* bietet nach Anbieterangaben 16 vCores und 64 GB RAM. Das ist ein Beispiel fuer einen groesseren Ausbau und **keine Mindestvoraussetzung fuer E2B**. Tarife und Leistungen koennen sich aendern. Quelle: [netcup VPS 8000 G12.5](https://www.netcup.com/de/server/vps/vps-8000-g12.5-24m-eu).

## Zugriff und wichtige Optionen

Standardmäßig lauscht die Weboberfläche nur auf `127.0.0.1:9080`. Sie wird nicht automatisch öffentlich freigegeben. Für Zugriffe von anderen Geräten einen SSH-Tunnel oder einen separat abgesicherten Reverse-Proxy verwenden. Bei einer Internetfreigabe müssen HTTPS, Zugangsschutz und die Betreiberpflichten geprüft werden.

| Variable | Standard | Bedeutung |
| --- | --- | --- |
| `COREUI_HTTP_PORT` | `9080` | Lokaler Web-Port |
| `COREUI_BIND_ADDRESS` | `127.0.0.1` | Bind-Adresse der Weboberfläche |
| `COREUI_MODEL_NAME` | `ember-coreui:latest` | Technischer Name des eigenen Ollama-Cores |
| `COREUI_INSTALL_BROWSE` | `1` | Playwright-Browser-Worker |
| `COREUI_INSTALL_SEARXNG` | `1` | Eigene Suchinstanz |
| `COREUI_INSTALL_VOICE` | `1` | Optionalen Eve-Sprachdienst versuchen zu installieren |

Konfigurationen vorhandener Installationen werden nicht stillschweigend überschrieben. Das bewusste Neuerzeugen mit `COREUI_FORCE_CONFIG=1` ist **kein normaler Update-Schritt** und erfordert zuvor eine Datensicherung. Weitere Parameter stehen in [`scripts/install.sh`](scripts/install.sh).

## Funktionen im aktuellen Stand

- **Mara und Cores:** Ollama-basierte Core-Auswahl im Chat, Gemma-4-Stufen, Cloud-Optionen bei verbundenen Anbietern und ein Denken-Schalter. Modellanfragen laufen über eine gemeinsame serielle Warteschlange.
- **Chats und Konten:** Persistente Sitzungen, Verlauf, Folgefragen, Kontextverwaltung sowie Rollen für Inhaber, Admins, Nutzer und optionale Gäste.
- **Recherche:** Websuche über SearXNG und gesonderte Playwright-Browserrecherche. Quellen und tatsächliche Arbeitsschritte werden im Chat sichtbar. Ein Suchtreffer allein gilt nicht als gelesene Webseite.
- **Wissen und Werkzeuge:** Kontogetrenntes RAG-Lite und Memory, automatische Skill-Auswahl, Dateiuploads sowie isolierte Python-Ausführung mit registrierten Ergebnissen. Medienfähigkeiten hängen vom Modell und den verfügbaren Werkzeugen ab.
- **Personalisierung:** Kontobezogene Einstellungen für Ton, Darstellung, Emoji-Dichte, Werkzeuge und persönliche Anweisungen.
- **Sprachausgabe:** Mara kann Antworten mit **Eve** (Pocket TTS Deutsch) vorlesen. Automatisches Vorlesen ist optional und zunächst ausgeschaltet. Scheitert der Bau oder Start des Sprachdienstes, bleibt CoreAI ohne Vorlesen nutzbar.
- **Admin Core:** Benutzer- und Core-Verwaltung, Zugriffsrechte, Diagnose, Betriebsstatus, Audit und ein geführter Update-Weg.

**Noch nicht als fertig zu betrachten:** Durchgehender Live-Sprachchat, Spracheingabe und weitere geplante Funktionen sind nicht allein deshalb implementiert, weil sie in der Planung stehen. Der Soll-/Ist-Stand ist in [ROADMAP.md](ROADMAP.md), der [Funktionsmatrix](docs/COREUI_FEATURE_MATRIX.md) und dem [Sprachplan](docs/COREUI_VOICE_PLAN.md) getrennt dokumentiert.

## Betrieb und Diagnose

Diese Befehle auf dem installierten Server ausführen:

```bash
cd /opt/ember-coreui
sudo ./scripts/preflight.sh
sudo ./scripts/stack.sh ps
sudo ./scripts/stack.sh logs --tail=100
curl -fsS http://127.0.0.1:9080/api/health.php
```

Für den optionalen Sprachdienst gezielt die Logs lesen:

```bash
cd /opt/ember-coreui
sudo ./scripts/stack.sh logs --tail=100 voice
```

Ein regulärer Neustart erfolgt über `sudo ./scripts/stack.sh restart`, ein Stopp ohne Datenlöschung über `sudo ./scripts/stack.sh stop`. Der Admin-Updater verwendet den offiziellen Setup-Weg.

**Abgrenzung:** `scripts/install-native.sh` ist ein alternativer nativer Installer für einen *dedizierten, leeren Server*. Er verändert Host-Dienste und ist nicht der Standard für den Parallelbetrieb mit anderen Starlight-Unit-Projekten.

## Projektaufbau und Dokumentation

| Pfad | Aufgabe |
| --- | --- |
| `api/`, `js/`, `css/` | APIs, Chats und Oberflächen |
| `admin/` | Admin Core |
| `database/migrations/` | Additive Datenbankmigrationen |
| `config/` | Runtime-Vorlagen und Betreiber-Modelfile |
| `docker/`, `compose.yaml` | Isolierter Standard-Stack |
| `scripts/` | Installer, Update, Preflight und Selbsttests |
| `skills/`, `tools/` | Skills und ausführbare Werkzeuge |
| `var/`, `uploads/` | Lokale Laufzeit- und Nutzerdaten, nicht für Git |

**Dokumentationsregel:** `README.md` erklärt den **aktuellen Zustand und die Installation**. Sie ist **kein Changelog**. Die vollständige Versionshistorie steht in [CHANGELOG.md](CHANGELOG.md) und im [TXT-Changelogarchiv](docs/changelogs-txt/INDEX.txt). Entwicklerregeln stehen in [AGENTS.md](AGENTS.md), [CLAUDE.md](CLAUDE.md) und der [Versionierungsrichtlinie](docs/VERSIONING_POLICY.md).

## Lizenz, Datenschutz und Grenzen

CoreAI ist kostenlos und steht unter der **Community-Source-Lizenz**. Sie ist **keine von der Open Source Initiative anerkannte Open-Source-Lizenz**. Die interne Nutzung ist im Rahmen der Lizenz gebührenfrei. Für Weitergabe, Marken und kostenpflichtiges Hosting gelten zusätzliche Bedingungen. Maßgeblich ist [LICENSE.de.md](LICENSE.de.md); ergänzend gelten [LICENSE.md](LICENSE.md), [COMMUNITY_POLICY.md](COMMUNITY_POLICY.md), [NOTICE.md](NOTICE.md) und [TRADEMARKS.md](TRADEMARKS.md).

Der lokale Betrieb erfordert keinen obligatorischen Hersteller-Account und keine Lizenz-Telemetrie. **Cloud-Cores und externe Dienste können Daten an Dritte übertragen und Nutzungskontingenten unterliegen.** Hierfür sind deren jeweilige Datenschutz- und Vertragsbedingungen zu beachten.

Copyright © 2023-2026 Patrick Schildgen, Starlight Unit Studios.
