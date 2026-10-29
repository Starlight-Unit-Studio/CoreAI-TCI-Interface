# CoreAI- und Claude-Skill-Kompatibilität (Stand 29.10.2026)

## Zwei unterschiedliche Entdeckungssysteme
Die heutige CoreAI-Runtime benutzt `skills/catalog.json`, registrierte `skills/<id>/SKILL.md` und die automatische Auswahl mit `[SKILL: <id>]`. Der verpflichtende `werkzeuge`-Skill, die maximale aktive Bodygröße von 2400 Byte, der Vollindex kleiner als 3600 Byte und die globale serielle Modellqueue bleiben unverändert.

**Claude Code** und Claude Managed Agents erkennen Skills aus einem Repository regulär unter `.claude/skills/<name>/SKILL.md`. Der Ordner `skills/<name>/SKILL.md` alleine wird dort nicht automatisch als projektweiter Skill entdeckt. In Claude.ai erfolgt die Aufnahme kundeneigener Skills typischerweise über eine ZIP-Importfunktion, nicht über einen automatisch verknüpften GitHub-PR. Offizielle Dokumentation:
- https://platform.claude.com/docs/en/managed-agents/skills
- https://platform.claude.com/docs/en/agents-and-tools/agent-skills/overview
- https://support.claude.com/en/articles/12512180-use-skills-in-claude

## Gemeinsame Paketstruktur
Jede aktuelle CoreAI-Anleitung verwendet YAML-Frontmatter mit `name` und `description`, gefolgt vom Markdown-Body. Das Grundformat ist auch für Claude verständlich. Zusätzliche `references/IMPLEMENTATION.md`, Notebook- oder Analyseabläufe können in einem Claude-kompatiblen Paket als Lesereferenzen dienen.

CoreAI lädt solche Referenzen **heute nicht automatisch**. Ein Dokument, das neben `SKILL.md` liegt, aktiviert weder den im Text genannten Toolzugriff noch einen zusätzlichen Loader. Claude lädt unterstützende Dateien nur dann, wenn die aktive Skillumgebung tatsächlich Dateizugriff besitzt und der Paketordner korrekt eingebunden ist.

## Spätere Einbindung, ohne CoreAI-Umbau
1. Source of truth bleibt `skills/<id>/`. Keine Dopplung unabhängig editierbarer Inhalte.
2. Bei ausdrücklichem Wunsch einen **separaten, geprüften Export** nach `.claude/skills/<id>/` oder in einen Claude-kompatiblen ZIP erstellen. Vorher Lizenz, Paketgröße, Scriptrechte und Namenskonflikte prüfen.
3. Ein Export/Generator darf nur kopieren bzw. packen, nicht fremde Skripte, Hooks, Plugins oder MCP-Server ausführen.
4. Mit tatsächlicher Claude-Sitzung die Erkennung, das Nachladen von Referenzen und die Grenzen fehlender Tools testen. Ein GitHub-Merge oder korrektes Frontmatter allein beweist keine erfolgreiche Integration.
5. Beim Einführen späterer Tool-/Ressourcenladung eigene Roadmap-/Versionsänderung mit Regressionstest einplanen, nicht im Rahmen einer Skill-Inhaltserweiterung heimlich nachrüsten.

## Testmatrix
- [ ] In CoreAI funktioniert die ursprüngliche Skill-Auswahl weiterhin unverändert.
- [ ] `SKILL.md` hat gültiges `name`-/`description`-Frontmatter und Body kleiner als 2400 Byte.
- [ ] Ein reales Claude-Code-Projekt erkennt eine **korrekt eingebundene** Kopie unter `.claude/skills/<id>/`.
- [ ] Claude darf Referenzen je nach Aufgabe laden, führt aber keine fremden Scripts ohne Berechtigung aus.
- [ ] Fehlende Backends werden als fehlend gekennzeichnet, nicht als erfolgreich aufgerufen.
- [ ] Keine neue verpflichtende CoreAI-Anleitung, keine Änderung an `api/skills.php` oder am Modelfile.

**Status:** Diese Datei ist eine Kompatibilitäts- und Abnahmeplanung. Es wurde bewusst noch keine Claude-Projektexportkopie oder Loaderänderung eingeführt.
