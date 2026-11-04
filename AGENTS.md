# Verbindliche Regeln fuer Coding-Assistenten

Diese Regeln gelten fuer jede Arbeit im Repository **CoreAI: TCI Interface**, auch fuer automatisch erzeugte Pull Requests.

## Language for public documentation

- **English is the default language for the public GitHub README and newly written user-facing documentation.** Keep the entire active `README.md` in English, including titles, installation, hardware profiles, instructions and update procedures.
- Explain **both** the one-line first-install/fallback command **and** the built-in owner-only updater under **Settings > System > CoreAI Update**. The built-in updater is the preferred method after initial setup.
- Do not retroactively translate or modify historical change logs, handover records, or the legally binding German `LICENSE.de.md` solely to meet the English README standard. Application interface labels may remain in German.

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
- Keine GitHub Actions als Voraussetzung. Keine nicht durchgefuehrten Tests als erfolgreich beschreiben.
- Ein Merge ist nicht gleich Live-Deployment.

## Grenzen und Sicherheit

- Produktname: **CoreAI: TCI Interface**. E.M.B.E.R. ist das Framework und Mara die TCI.
- Historische Runtime-Pfade/Kennungen wie `/opt/ember-coreui` und `COREUI_*` nicht eigenmaechtig umbenennen.
- Ohne Auftrag keine Modelfile-, Ollama-, Datenbank-, Installer- oder Fremdprojekt-Aenderungen.
- Keine Secrets, Nutzerdaten, vertrauliche Studio-Lore oder private Uploads in Git committen.
- Die Lizenz ist **Community Source** und nicht OSI Open Source. `LICENSE.de.md` ist massgeblich.
