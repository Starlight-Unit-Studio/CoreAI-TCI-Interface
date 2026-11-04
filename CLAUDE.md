# Claude: Verbindliche CoreAI-Repository-Regeln

**Vor jeder Aufgabe `AGENTS.md` und `docs/VERSIONING_POLICY.md` lesen.**

**README.md IST KEIN CHANGELOG.**

- Die README beschreibt den aktuellen Produktstand, die Hardware-Richtwerte, den **offiziellen Installations-One-Liner**, Bedienung, Betrieb und Lizenz.
- Niemals Patch-Historien, einzelne Versionsmeldungen, Merge-Listen oder Debug-Abschnitte in README einfuegen.
- Versionsaenderungen ausschliesslich in `CHANGELOG.md` sowie `docs/changelogs-txt/CHANGELOG_X_Y_Z.txt` dokumentieren.
- Neue Features in der README nur als **aktuellen Sachstand** im passenden vorhandenen Abschnitt ergaenzen; keine neue Versionsueberschrift.
- Offizieller Installer: `https://raw.githubusercontent.com/Starlight-Unit-Studio/CoreAI-TCI-Interface/main/setup.sh`.
- Jeder Merge erhaelt einen neuen numerischen Versionsstand. Der lokale Versions-Guard muss vor Merge bestehen; Tests nur als erfolgreich dokumentieren, wenn sie tatsaechlich ausgefuehrt wurden.

**Kurzregel:** README = Anleitung. CHANGELOG = Geschichte. ROADMAP = Zukunft.
