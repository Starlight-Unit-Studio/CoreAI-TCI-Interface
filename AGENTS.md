# Verbindliche Regeln fuer Coding-Assistenten

Diese Regeln gelten fuer jede Arbeit im Repository **CoreAI: TCI Interface**, auch fuer automatisch erzeugte Pull Requests.

## Language for public documentation

- **English is the default language for the public GitHub README and newly written user-facing documentation.** Keep the entire active `README.md` in English, including titles, installation, hardware profiles, instructions and update procedures.
- **Translate all UI button names, settings tabs and menu paths into English in the public README.** Do not include parenthetical German button labels alongside their English equivalents. This is a documentation rule, not a change to the current application UI.
- Explain **both** the one-line first-install/fallback command **and** the built-in owner-only updater under **Settings > System > CoreAI Update**. The built-in updater is the preferred method after initial setup.
- Do not retroactively translate or modify historical change logs, handover records, or the legally binding German `LICENSE.de.md` solely to meet the English README standard. Application interface labels may remain in German until full English/German localization ships. **System/Light/Dark appearance is available from 0.6.12**, while complete interface localization remains planned.

## Vollstaendige README und Betreiberlimit (16.11.2026)

- Die README darf **bis zu 512 KiB (524288 Bytes)** umfassen, nicht nur 24 KB.
  Dieses Limit stammt vom Betreiber und ist in
  `scripts/version-merge-check.py` verbindlich hinterlegt.
- Sie muss alle tatsaechlich angebotenen Mara-/CoreAI-Funktionen sowie
  HTTP-/SSE-/interne APIs, externe Dienste und optionale Anbindungen
  nachvollziehbar erlaeutern. Die Kerninformationen muessen direkt in
  der README stehen, nicht ausschliesslich in docs/ verlinkt sein.
- Google-/GitHub-Leser sollen CoreAI ohne Zusatzdatei verstehen;
  Websuche nicht als zwingende Google-API-Abhaengigkeit darstellen.
- Nicht verfuegbare OpenAI-kompatible Adapter, generelle Drittanbieter-
  Connectoren und andere Roadmap-Plaene klar als solche kennzeichnen.

## README ist kein Changelog

1. `README.md` ist eine aktuelle Projekt- und Installationsanleitung: Produktzweck, reale Funktionen, Hardware-Richtwerte, Voraussetzungen, **offizieller One-Liner**, Updates, Diagnose, Lizenz und Links.
2. **Niemals Release-Notizen, Merge-Listen, Debug-Tagebuecher, alte Statusabschnitte oder Version-Ueberschriften an README anhaengen.** Bei neuen Funktionen den vorhandenen Sachtext aktualisieren.
3. Die kanonische Versionshistorie steht in `CHANGELOG.md`. Fuer jeden Merge auch `docs/changelogs-txt/CHANGELOG_X_Y_Z.txt` und dessen `INDEX.txt` pflegen. Historie bleibt dort.
4. Geplante Funktionen nicht als implementiert darstellen. Hardwareangaben nicht als getestete Mindestwerte ausgeben, wenn sie nicht live gemessen wurden. Fuer Planung `ROADMAP.md` und Fach-Dokumentation nutzen.
5. Der offizielle README-One-Liner muss gut sichtbar bleiben und `https://raw.githubusercontent.com/Starlight-Unit-Studio/CoreAI-TCI-Interface/main/setup.sh` verwenden.

## PREFLIGHT_SYNC_GATE: Nach jeder Aenderung (Betreiberregel 18.11.2026)

- **Jede** Code-, Konfigurations-, UI-, Installer-, API- und Testaenderung erfordert einen Abgleich der passenden Selbsttests mit dem neuen SOLL-Verhalten, **vor dem Commit**. Bei Dokumentationsaenderungen den Preflight-Vertrag zumindest pruefen. Ein neues Verhalten bekommt einen positiven Test und, wo relevant, einen negativen Sicherheits-/Fehlerfall.
- Immer **beide** Preflights (`scripts/preflight.sh` fuer Compose und `scripts/preflight-native.sh` fuer Native) sowie deren Testregistrierung pruefen. Betroffene Test-Erwartungen aktualisieren, veraltete Assertions suchen und neue Tests in beide Varianten eintragen, sofern die Funktion in beiden existiert. Keine blosse Versions-/Kommentaraenderung als Testabnahme ausgeben.
- Nach jeder Aenderung die geaenderten Dateien syntaktisch pruefen und die betroffenen Offline-Selbsttests **tatsaechlich ausfuehren**, mit Ergebnis/Fehler im PR. Der globale `preflight-sync-gate` laeuft bei jedem PR automatisch. Bei Fehlern reparieren und erneut testen; keine Assertions entfernen, auskommentieren oder Warnungen statt Fehlern verwenden, um CI gruen zu machen.
- Vor dem Merge alle erforderlichen CI-Pruefungen bestehen lassen. Vor produktiver Freigabe den vollstaendigen zum Deployment passenden Preflight in der echten Umgebung testen. Host-/Container-/Netzwerk- und Datenbankchecks koennen nur dort als bestanden gelten, nicht in GitHub Actions.
- **Preflight FAIL = nicht releasefaehig.** Auch der integrierte Updater und die anderen Nutzer sind Release-Verbraucher: Kein defekter Commit auf `main`, kein Preflight-Bypass und kein erzwungener Updater-Erfolg. Bei Testaenderungen immer die urspruengliche Schutzabsicht beibehalten.
- Ein widerspruechliches altes Limit (z.B. Ablehnung von Projektplaenen ueber 12.000 Byte nach Abschaffung dieser Produktgrenze) muss durch einen Test ersetzt werden, der **vollstaendige Daten** verifiziert, nicht stilles Abschneiden legitimiert.

## Terminal output language (operator rule, 18.11.2026)

- **All operator-visible output in setup, installation, migration, Compose/Native preflight, update and uninstall terminals must be English.** This applies equally to diagnostic summaries from PHP/Python selftests; the application UI's separate language selection is irrelevant.
- Never forward arbitrary selftest **success stdout** directly through `ok "$test_output"`. Use a stable, meaningful English success label **after** the test actually passes; on failures keep the real failing exit status and actionable diagnostics.
- After editing an installer, migration, updater, preflight or invoked selftest, run `python3 scripts/terminal-language-selftest.py` together with the changed script's real tests. Keep `scripts/terminal-language-selftest.py` registered in both preflights and in the unconditional Preflight Sync CI gate. A localized output regression blocks the PR.
- Do not weaken diagnostic exit codes, replace real tests with fake success messages, or silently discard relevant error details. Third-party diagnostic streams can have their own locale, but all CoreAI-authored terminal output is English.

## Versionierung und Abnahme

- **Jeder Merge nach `main` benoetigt eine neue numerische Version**, auch rein dokumentarische Merger. Es gilt `docs/VERSIONING_POLICY.md`.
- `VERSION`, sechs HTML-Seiten, JS-Bootstrap, README, CHANGELOG, TXT-Archiv, Uebergabe, Branding-Test und Preflights synchron halten.
- Vor Merge lokal `python3 scripts/version-merge-check.py --base-version <aktuelle-main-version>` ausfuehren.
- **UI-Text-Regression:** Nach jeder Aenderung sichtbarer Formulierungen alle Testdateien nach den alten Texten durchsuchen, exakte Solltexte aktualisieren und die betroffenen Tests real ausfuehren. Assertions zu Funktionen, Berechtigungen, Kanon und Event-Kennungen nicht entfernen oder umgehen. Ein ungetesteter Sprachfix ist nicht mergefertig.
- **PHP-Syntax ist ein Merge-Blocker:** Nach PHP-Textaenderungen alle geaenderten `.php`-Dateien mit `php -l` pruefen und die betroffenen Selbsttests laufen lassen. Bei fehlender PHP-Laufzeit PR als Draft belassen und NICHT mergen. Insbesondere Quotes und Konkatenation bei User-Textaenderungen pruefen.
- Der lokale Versions-Guard bleibt auch ohne GitHub Actions verbindlich. Fuer neue PRs ist der zusaetzliche `preflight-sync-gate` in GitHub Actions ein verpflichtender Regressionstest; er ersetzt keine Live-Preflights. Keine nicht durchgefuehrten Tests als erfolgreich beschreiben.
- Ein Merge ist nicht gleich Live-Deployment.

## Grenzen und Sicherheit

- Produktname: **CoreAI: TCI Interface**. Mara ist die TCI. Das **E.M.B.E.R.-Framework gehoert weiterhin zum getrennten Gamechat-System**, nicht als eingebundene CoreAI-Abhaengigkeit bezeichnen. CoreAI entstand aus der kopierten Chat-Basis und ist heute eigenstaendig. Die Produkt-README soll keine unnoetige Projektvorgeschichte enthalten.
- Historische Runtime-Pfade/Kennungen wie `/opt/ember-coreui` und `COREUI_*` nicht eigenmaechtig umbenennen.
- Ohne Auftrag keine Modelfile-, Ollama-, Datenbank-, Installer- oder Fremdprojekt-Aenderungen.
- Keine Secrets, Nutzerdaten, vertrauliche Studio-Lore oder private Uploads in Git committen.
- Die Lizenz ist **Community Source** und nicht OSI Open Source. `LICENSE.de.md` ist massgeblich.

## Terran UI lore and naming (05.11.2026)

- CoreAI is a **Terran** terminal. In user-facing text use **Terra-Net** for the Terran connection, never `Arch-Net` or `ARCHNET`.
- Do not describe CoreAI's settings or status screens as an `Ayal'ren` system. Use `TERRANISCHE SYSTEMKONFIGURATION` and appropriate Terra-Net labels.
- Preserve existing protocol identifiers, matrix artwork, historical changelogs and source-lore records. Correct UI copy without rewriting functional network logic or the game repository.
- Before release run `node scripts/terran-terminology-selftest.cjs` and existing preflights.

## Unified TCI narration and terminology (06.11.2026)

- Mara Thorne is **one Terran Cognitive Intelligence**. Her Core(s), tools, memory, voice processing and response pipeline are parts of her functioning, not separate personalities. In user-facing work narration Mara uses natural first-person phrasing (e.g. "Ich bearbeite deine Anfrage") rather than "Core arbeitet" or "Mara und Core".
- Spoken chat messages are called **Stimmsequenzen** in German UI and Mara's ordinary answers. Generic uploaded audio files keep a distinct label. Technical Ollama model identifiers, persisted event titles and the existing `Sprachnachricht-` filename prefix must not be renamed.
- **der Core**, **den Core**, **dem Core**, **des Cores**; plural **die Cores**. Use "Modell" only where technically accurate, not for Mara's identity.
- The operator Modelfile is authoritative and must not be rewritten by UI-copy tasks. Respect the user's actual existing SYSTEM prompts; never silently replace them. Before release run `node scripts/tci-language-selftest.cjs` in addition to existing checks.
