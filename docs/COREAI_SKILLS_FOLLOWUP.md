# Skill-Tiefenausbau nach PR #132

Arbeitsbranch: `feat/skills-depth-react-nextjs-tests-20261029`, basierend auf dem noch offenen 106-Skills-PR #132. **Diese Änderungen gehören nicht mehr zu PR #132**, damit der Betreiber ihn ungestört mergen und mit seiner Claude-Integration testen kann.

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
1. Verifizieren, dass PR #132 in main gemergt wurde und ein neues Versionsintervall frei ist.
2. Diesen Folgebranch auf den dann aktuellen main-Stand bringen.
3. `VERSION` auf die nächste zulässige Version erhöhen, alle UI-/JS-/README-/Changelog-/TXT-/Übergabereferenzen nach `docs/VERSIONING_POLICY.md` nachführen.
4. `php scripts/skills-selftest.php`, `python3 scripts/version-merge-check.py --base-version <VERSION-MAIN>`, `python3 scripts/cache-bust.py --verify` und Preflights ausführen. Ohne bestätigten Checkout nicht als bestanden kennzeichnen.
5. Kein Merge, GitHub-Actions-Lauf oder Server-Deploy ohne Betreiberfreigabe und Liveprüfung.

## Quellen
- https://github.com/vercel-labs/agent-skills/tree/main/skills/react-best-practices
- https://github.com/obra/superpowers/tree/main/skills/systematic-debugging
- https://github.com/obra/superpowers/tree/main/skills/test-driven-development
- https://github.com/openai/skills/tree/main/skills/.curated/jupyter-notebook
- https://github.com/openai/skills/tree/main/skills/.curated/security-threat-model
