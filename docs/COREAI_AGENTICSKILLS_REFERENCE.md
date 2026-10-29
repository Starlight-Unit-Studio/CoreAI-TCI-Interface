# AgenticSkills.io als externe Forschungsquelle fuer CoreAI

Stand: 29.10.2026. Betreiberfund: https://agenticskills.io. Der hier vorbereitete 0.5.81-Branch enthaelt nun **106 Skills**, davon 105 optional und unveraendert genau einen Pflicht-Skill `werkzeuge`.

## Quelle richtig einordnen

- https://agenticskills.io ist ein **unabhaengiges Kurations- und Verzeichnisprojekt**, nicht die offizielle Standard-Website.
- https://agentskills.io und https://github.com/agentskills/agentskills beschreiben den offenen Agent Skills Standard. Die Agentic AI Foundation unter Linux Foundation uebernahm die Verantwortung fuer den Standard im Oktober 2026.
- Das externe Verzeichnis meldet in der Indexfassung vom 27.10.2026 **193 gelistete Skills**, **139 MCP-Server**, **16 Kategorien**, **55 Entwicklerwerkzeuge** und **22 Workflow-Bundles**. Die Zahlen sind Momentaufnahmen, keine einzigartigen, unabhaengig geprueften Funktionen. Ein Eintrag kann selbst ein Paket vieler Skills sein.
- https://agenticskills.io/methodology sagt ausdruecklich, dass die Aufnahme keine Zeile-fuer-Zeile-Sicherheitspruefung garantiert. Qualitaetsranks sind redaktionell; Sterne des Quellrepos sind nicht Installationszahlen.

## Sofort im CoreAI-Branch als eigene Skills vorbereitet

| Skill | Inspiration / Quelle | Voller Workflow | Noch fehlende Technik |
|---|---|---|---|
| `skill-discovery` | [Vercel Find Skills](https://agenticskills.io/skills/find-skills) | Skillkataloge durchsuchen, Originalquelle, Lizenzen, Versionen, Funktionen und Duplikate pruefen | Kontrollierter Skillimport, Quellen-/Skriptaudit, isolierte Testumgebung (P12/P13) |
| `seo-audit` | [Marketing Skills: SEO Audit](https://agenticskills.io/skills/seo-audit) | Robots, Sitemap, Canonical, Rankinggrundlagen, JavaScript-Markup und Content pruefen | URL-Crawler, DOM-Renderer, Search Console/Performance-Messung (P09/P15/P19) |
| `marketing-strategy` | [Corey Haines Marketing-Skills](https://agenticskills.io/skills/marketing-skills) | Produktpositionierung, Launch, Kampagnen, messbare Ziele, Content und Kanalstrategie | Live-Marktdaten, Analytics und freigegebene Verbreitungs-/Contenttools (P09/P15) |
| `mobile-apps` | [Callstack React Native Best Practices](https://agenticskills.io/skills/react-native-best-practices) | Android-/iOS-App-Planung, Architektur, Performance, Tests und signierte Builds | Android SDK, Expo/React Native, ggf. Xcode, Emulatoren, Buildsignatur (P09/P10) |
| `property-testing` | [Trail of Bits Property-Based Testing](https://agenticskills.io/skills/property-based-testing) | Roundtrip-, Idempotenz-, Grenzwert- und Invariantentests mit reproduzierbaren Seeds | Isolierte Runner, Testgeneratoren, Reports und Regressionstest (P09/P10) |
| `database-tuning` | [Supabase Postgres Best Practices](https://agenticskills.io/skills/supabase-postgres) | Queryplaene, Indexe, Locks, Migration, Performance und Rollback; MariaDB und PostgreSQL strikt unterscheiden | Nur autorisierte Datenbanktests, EXPLAIN, Messung, Staging und Backup (P09/P10) |

Zu jedem der sechs Skills liegen `SKILL.md` und `references/IMPLEMENTATION.md` vor. Originalmaterial fremder Autoren wird nicht als Code oder Anleitungsdatei importiert. Eigene CoreAI-Texte nutzen das nachvollziehbare Funktionskonzept.

## Priorisierte weitere Quellen (noch kein neuer Skill)

- [OpenAI Jupyter Notebook](https://agenticskills.io/skills/jupyter-notebook): experimentelle und wissenschaftliche .ipynb-Dateien, mit vorhandenem `academic-research` / `python` abstimmen.
- [Trail of Bits Static Analysis](https://agenticskills.io/skills/static-analysis): Semgrep/CodeQL/SARIF und Sicherheitsreports; Teile des `threat-modeling` sind bereits vorhanden.
- [Vercel React Best Practices](https://agenticskills.io/skills/react-best-practices): fuer vorhandenen `react`-Skill pruefen statt duplizieren.
- [Vercel Next.js Best Practices](https://agenticskills.io/skills/next-best-practices): fuer vorhandenen `nextjs`-Skill.
- [Systematic Debugging](https://agenticskills.io/skills/systematic-debugging): fuer den existierenden `debugging`-Skill.
- [Test-Driven Development](https://agenticskills.io/skills/test-driven-development): fuer `unit-testing`, nur bei real ausfuehrbaren Tests.
- [Content Research Writer](https://agenticskills.io/skills/content-research-writer): Abgleich mit `technical-writing`, `doc-coauthoring` und `creative-writing`.
- Der 139-Server-MCP-Katalog wird separat auf Eignung, Berechtigungen, Netzwerk-/Tokenrisiken und Lizenz geprueft. Die blosse Listung eines MCP-Servers macht ihn noch nicht zu einem CoreAI-Werkzeug.

## Sicherheits- und Integrationsregel

1. Bei jeder externen Skillquelle Original-GitHub-Repostand, Verantwortliche, Lizenz, Referenzen, etwaige Skripte/Hooks und Netzbefugnisse pruefen.
2. Keine automatische `npx skills add`-Installation, kein Remote-Shell-Piping, keine fremden Hooks, ungepruefte MCP-Server oder stilles Freischalten von Toolrechten.
3. Duplicate Skills nicht ungeprueft anlegen; vorhandene SKILL.md nach Bedarf vertiefen, neue nur bei eigenem fachlichem Umfang registrieren.
4. Der Loader liest registrierte `SKILL.md`; Referenzplaene unter `references/*.md` laedt Mara seit 0.5.82 nur auf Anforderung (`[SKILL: kennung/references/DATEI.md]`). Die sechs neuen Referenzplaene sind vorbereitete Anforderungen fuer spaetere Toolentwicklung.
5. `api/skills.php`, `skills/werkzeuge/SKILL.md`, Modelfile, globale serielle Modellwarteschlange und `[SKILL:]`-Auswahl bleiben unveraendert. Der volle Index muss unter 3600 Byte bleiben, kompakte Titel kleiner als die Vollansicht.
6. GitHub Actions und Live-Deployment erst nach Betreiberabnahme; neue Skills sind kein Beleg fuer bereits installierte Ausfuehrungswerkzeuge.

## Abnahmevorschlag

- [ ] 106 eindeutige Skill-Kennungen und 106 vorhandene SKILL.md
- [ ] Pflicht-Skill unveraendert, Full-/Titles-/IDs-Index in bestehender Groessenreihenfolge
- [ ] Jede neue Anleitung innerhalb 2400 Byte
- [ ] Originalquellen und Lizenz-/Sicherheitsgrenzen dokumentiert
- [ ] Fehlende Tools pro Fachbereich erfasst
- [ ] PHP-Selbsttest, Preflight, Netcup-/Android-Livetest nach tatsaechlicher Ausfuehrung
