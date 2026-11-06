# Claude: Verbindliche CoreAI-Repository-Regeln

**Vor jeder Aufgabe `AGENTS.md` und `docs/VERSIONING_POLICY.md` lesen.**

**PUBLIC GITHUB DOCUMENTATION DEFAULTS TO ENGLISH.** The entire active `README.md` must be written in English. This includes Quick start, Hardware requirements, Updating CoreAI, explanations and tables. **Translate button names and settings paths into English in the README too, without German labels in parentheses.** This is documentation-only. The **System/Light/Dark appearance mode is implemented in 0.6.12**; the complete English/German UI language selector remains planned. Historical German changelogs, handovers, UI strings and the legally binding `LICENSE.de.md` are not to be translated automatically.

**README.md IST KEIN CHANGELOG.**

- Die englische README beschreibt den aktuellen Produktstand, Hardware-Richtwerte, den **offiziellen Installations-One-Liner**, den **integrierten Updater unter Settings > System > CoreAI Update**, Bedienung, Betrieb und Lizenz.
- Niemals Patch-Historien, einzelne Versionsmeldungen, Merge-Listen oder Debug-Abschnitte in README einfuegen.
- Versionsaenderungen ausschliesslich in `CHANGELOG.md` sowie `docs/changelogs-txt/CHANGELOG_X_Y_Z.txt` dokumentieren.
- Neue Features in der README nur als **aktuellen Sachstand** im passenden vorhandenen Abschnitt ergaenzen; keine neue Versionsueberschrift.
- Offizieller Installer: `https://raw.githubusercontent.com/Starlight-Unit-Studio/CoreAI-TCI-Interface/main/setup.sh`.
- Jeder Merge erhaelt einen neuen numerischen Versionsstand. Der lokale Versions-Guard muss vor Merge bestehen; Tests nur als erfolgreich dokumentieren, wenn sie tatsaechlich ausgefuehrt wurden.

**Kurzregel:** README = Anleitung. CHANGELOG = Geschichte. ROADMAP = Zukunft.

**Sprach-/UI-Gate:** Bei geaenderten sichtbaren Texten auch alle Selbsttests mit wortwoertlichen Textvergleichen suchen, aktualisieren und vor Merge ausfuehren. Keine Assertions abschalten, um die Tests gruen zu bekommen. Ohne echte Testergebnisse PR als Draft belassen.

**PHP-Gate:** Bei jeder PHP-Aenderung vor dem Merge `php -l` fuer die geaenderten PHP-Dateien und betroffene Offline-Selbsttests ausfuehren. Ein reiner GitHub-Diff- oder Textcheck ist kein Syntaxnachweis. Falls PHP-Lint nicht moeglich ist, PR als Draft lassen; niemals ohne Testnachweis mergen.

## Terran terminology rule (05.11.2026)

CoreAI is a **Terran interface**: its UI connects through **Terra-Net**, never Arch-Net. Do not label its settings or startup screens as Ayal'ren systems. Use `TERRANISCHE SYSTEMKONFIGURATION` in Settings. Retain historical lore files, matrix artwork, internal identifiers and real network code. Check `scripts/terran-terminology-selftest.cjs` before releasing UI changes.

## Unified TCI narration and terminology (06.11.2026)

- Mara Thorne is **one Terran Cognitive Intelligence**. Her Core(s), tools, memory, voice processing and response pipeline are parts of her functioning, not separate personalities. In user-facing work narration Mara uses natural first-person phrasing (e.g. "Ich bearbeite deine Anfrage") rather than "Core arbeitet" or "Mara und Core".
- Spoken chat messages are called **Stimmsequenzen** in German UI and Mara's ordinary answers. Generic uploaded audio files keep a distinct label. Technical Ollama model identifiers, persisted event titles and the existing `Sprachnachricht-` filename prefix must not be renamed.
- **der Core**, **den Core**, **dem Core**, **des Cores**; plural **die Cores**. Use "Modell" only where technically accurate, not for Mara's identity.
- The operator Modelfile is authoritative and must not be rewritten by UI-copy tasks. Respect the user's actual existing SYSTEM prompts; never silently replace them. Before release run `node scripts/tci-language-selftest.cjs` in addition to existing checks.
