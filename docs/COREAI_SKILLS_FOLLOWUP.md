# Skill-Tiefenausbau nach PR #132

Arbeitsbranch: `feat/skills-depth-react-nextjs-tests-20261029`, basierend auf dem inzwischen gemergten 106-Skills-PR #132. Produktversion dieses unabgenommenen Folgebranches: **0.5.82**.

## Verbesserte vorhandene Skills
- **react**: Dataflow, Stable Keys, Effekte, Render-Messung, Wasserfälle, Bundlegröße und mobile Tests
- **nextjs**: Router-/Versionsunterschiede, Server-/Client-Grenzen, Authorization, Cache-Revalidierung, RSC und Hydration
- **debugging**: beweisgestützte Root-Cause-Analyse entlang des CoreAI-Arbeitswegs, Minipatch und Regression
- **unit-testing**: Red-Green-Refactor, Isolation, Regressionsfälle, Property-Based-Tests und echte Runner-Nachweise
- **academic-research**: DOI-/Retraction-Prüfung und reproduzierbare Jupyter-Notebooks ohne fiktive Exporte
- **threat-modeling**: Scope, Trust Boundaries, CodeQL/Semgrep/SARIF und Befundvalidierung

Zu jeder bestehenden `SKILL.md` liegt eine neue eigenständige `references/`-Anleitung für komplexere Fälle vor. Es werden **keine neuen Katalogeinträge** benötigt; damit bleiben 106 Skills, der 3600-Byte-Fullindex und der ursprüngliche einzige verpflichtende Kernskill bestehen. Die alten Katalogmetadaten, Triggers und Runtime bleiben unverändert.

## Portabilität
Details zur Claude-Integration stehen in [COREAI_CLAUDE_SKILL_PORTABILITY.md](COREAI_CLAUDE_SKILL_PORTABILITY.md). Das aktuelle Repository `skills/<id>/` ist nicht automatisch der Projekt-Discovery-Pfad von Claude Code. Künftiger Export ist eine separate, zustimmungspflichtige Aufgabe und **nicht** Teil dieser Änderung.

## Vor einem späteren Merge
1. Die Version 0.5.82 ist in diesem Folgebranch vorbereitet. Vor Merge das aktuelle main erneut auf Versions- und Inhaltskonflikte pruefen.
2. `php scripts/skills-selftest.php`, `python3 scripts/version-merge-check.py --base-version 0.5.81`, `python3 scripts/cache-bust.py --verify` und Preflights tatsaechlich ausfuehren. Ist main inzwischen weiter, Versionsziel und Checkparameter anpassen.
3. Nach verifiziertem Merge per bestehendem One-Liner auf dem autorisierten Server testen. Keine GitHub Actions verbrauchen, keine falschen Live-Nachweise.
4. Ohne bestaetigte Tests kein Merge oder Deploy; diese Referenzen sind fachliche Zielplaene und schalten keine fehlenden Werkzeuge frei.
