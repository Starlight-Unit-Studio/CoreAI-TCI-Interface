# Claude: Verbindliche CoreAI-Repository-Regeln

**Vor jeder Aufgabe `AGENTS.md` und `docs/VERSIONING_POLICY.md` lesen.**

**PUBLIC GITHUB DOCUMENTATION DEFAULTS TO ENGLISH.** The entire active `README.md` must be written in English. This includes Quick start, Hardware requirements, Updating CoreAI, explanations and tables. **Translate button names and settings paths into English in the README too, without German labels in parentheses.** This is documentation-only. The future English/German UI language switch and day/night modes are planned, not yet implemented. Historical German changelogs, handovers, UI strings and the legally binding `LICENSE.de.md` are not to be translated automatically.

**README.md IST KEIN CHANGELOG.**

- Die englische README beschreibt den aktuellen Produktstand, Hardware-Richtwerte, den **offiziellen Installations-One-Liner**, den **integrierten Updater unter Settings > System > CoreAI Update**, Bedienung, Betrieb und Lizenz.
- Niemals Patch-Historien, einzelne Versionsmeldungen, Merge-Listen oder Debug-Abschnitte in README einfuegen.
- Versionsaenderungen ausschliesslich in `CHANGELOG.md` sowie `docs/changelogs-txt/CHANGELOG_X_Y_Z.txt` dokumentieren.
- Neue Features in der README nur als **aktuellen Sachstand** im passenden vorhandenen Abschnitt ergaenzen; keine neue Versionsueberschrift.
- Offizieller Installer: `https://raw.githubusercontent.com/Starlight-Unit-Studio/CoreAI-TCI-Interface/main/setup.sh`.
- Jeder Merge erhaelt einen neuen numerischen Versionsstand. Der lokale Versions-Guard muss vor Merge bestehen; Tests nur als erfolgreich dokumentieren, wenn sie tatsaechlich ausgefuehrt wurden.

**Kurzregel:** README = Anleitung. CHANGELOG = Geschichte. ROADMAP = Zukunft.
