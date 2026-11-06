# Verbindliche Regeln fuer Coding-Assistenten

Diese Regeln gelten fuer jede Arbeit im Repository **CoreAI: TCI Interface**, auch fuer automatisch erzeugte Pull Requests.

## Language for public documentation

- **English is the default language for the public GitHub README and newly written user-facing documentation.** Keep the entire active `README.md` in English, including titles, installation, hardware profiles, instructions and update procedures.
- **Translate all UI button names, settings tabs and menu paths into English in the public README.** Do not include parenthetical German button labels alongside their English equivalents. This is a documentation rule, not a change to the current application UI.
- Explain **both** the one-line first-install/fallback command **and** the built-in owner-only updater under **Settings > System > CoreAI Update**. The built-in updater is the preferred method after initial setup.
- Do not retroactively translate or modify historical change logs, handover records, or the legally binding German `LICENSE.de.md` solely to meet the English README standard. Application interface labels may remain in German until full English/German localization ships. **System/Light/Dark appearance is available from 0.6.12**, while complete interface localization remains planned.

## README ist kein Changelog

1. `README.md` ist eine aktuelle Projekt- und Installationsanleitung: Produktzweck, reale Funktionen, Hardware-Richtwerte, Voraussetzungen, **offizieller One-Liner**, Updates, Diagnose, Lizenz und Links.
2. **Niemals Release-Notizen, Merge-Listen, Debug-Tagebuecher, alte Statusabschnitte oder Version-Ueberschriften an README anhaengen.** Bei neuen Funktionen den vorhandenen Sachtext aktualisieren.
3. Die kanonische Versionshistorie steht in `CHANGELOG.md`. Fuer jeden Merge auch `docs/changelogs-txt/CHANGELOG_X_Y_Z.txt` und dessen `INDEX.txt` pflegen. Historie bleibt dort.
4. Geplante Funktionen nicht als implementiert darstellen. Hardwareangaben nicht als getestete Mindestwerte ausgeben, wenn sie nicht live gemessen wurden. Fuer Planung `ROADMAP.md` und Fach-Dokumentation nutzen.
5. Der offizielle README-One-Liner muss gut sichtbar bleiben und `https://raw.githubusercontent.com/Starlight-Unit-Studio/CoreAI-TCI-Interface/main/setup.sh` verwenden.

## Versionierung und Abnahme

- **Jeder Merge nach `main` benoetigt eine neue numerische Version**, auch rein dokumentarische Merger. Es gilt `docs/VERSIONING_POLICY.md`.
- `VERSION`, sechs HTML-Seiten, JS-Bootstrap, README, CHANGELOG, TXT-Archiv, Uebergabe, Branding-Test und Preflights synchron halten.
- Vor Merge lokal `python3 scripts/version-merge-check.py --base-version <aktuelle-main-version>` ausfuehren.
- **PHP-Syntax ist ein Merge-Blocker:** Nach PHP-Textaenderungen alle geaenderten `.php`-Dateien mit `php -l` pruefen und die betroffenen Selbsttests laufen lassen. Bei fehlender PHP-Laufzeit PR als Draft belassen und NICHT mergen. Insbesondere Quotes und Konkatenation bei User-Textaenderungen pruefen.
- Keine GitHub Actions als Voraussetzung. Keine nicht durchgefuehrten Tests als erfolgreich beschreiben.
- Ein Merge ist nicht gleich Live-Deployment.

## Grenzen und Sicherheit

- Produktname: **CoreAI: TCI Interface**. E.M.B.E.R. ist das Framework und Mara die TCI.
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
