# CoreAI Skill-Bibliothek: Zielumfang und Werkzeuglücken

Stand: 29.10.2026, geplant für Quellversion 0.5.81. Ausgangspunkt: 33 Skills (0.5.80). Im aktuellen Erweiterungsbranch: **106 Skills**, einschließlich aller **19 Original-Kennungen** aus [anthropics/skills](https://github.com/anthropics/skills/tree/main/skills), weiterer eigener Fach-Skills sowie zwei Planungs-Skills für Agenten/Workflows.

## Grundsatz

Die Skills sollen ihre **vollständige fachliche Fähigkeit** beschreiben, auch wenn ein dafür notwendiges Backend-Werkzeug noch fehlt. Die 19 Skills besitzen jeweils eine aktive, kontextsparende `SKILL.md` und einen ausführlichen eigenen Funktions- und Abnahmeplan in `skills/<id>/references/IMPLEMENTATION.md`. **Dieser Referenzplan wird vom heutigen Loader nicht automatisch gelesen**, bleibt jedoch als verbindliche Entwicklungsvorlage im Repository.

Der neue Skillkatalog ist additive Vorbereitung, keine Implementierung sämtlicher Office-, Browser-, Agenten- oder Grafikfunktionen. Alle technischen Fähigkeiten müssen erst durch echte Werkzeuge, Sicherheit, Tests und Downloads bereitgestellt und abgenommen werden. Keine automatische Installation fremder Abhängigkeiten und keine Änderungen an `api/skills.php`, Modellwahl, Modelfile oder Pflicht-Skill `werkzeuge`.

## 19 Vorbilder: Fähigkeiten und fehlende Werkzeugteile

| Skill | Vollständiger geplanter Bereich | Werkzeuge / Lücken (Richtwert, keine Behauptung bereits implementierter Funktionen) | Roadmap-Bezug |
|---|---|---|---|
| `docx` | Word neu erstellen, OOXML gezielt ändern, Tabellen, Styles, Kopf-/Fußzeilen, Inhaltsverzeichnis, Kommentare und Änderungsverfolgung, Layoutprüfung | DOCX/OOXML-Writer und Editor, Render- und Änderungsprüfer, persistent verfügbare Dateien | P09/P10, P12 |
| `pdf` | Text/Tabellen, Merge, Split, Rotation, Wasserzeichen, OCR, PDF-Formulare, neue Layouts, Signatur-Hinweise | PDF-Writer/Editor, Formular-/OCR-Modul, Renderer; PDF-Analyse ist teilweise bereits vorhanden | P09/P10, P16, P20 |
| `pptx` | PowerPoint erstellen und bearbeiten, Master, Charts, Notes, Layout, Folien-Renderprüfung | PPTX-Generator/OOXML-Editor, Slide-Renderer, Assets und Export | P09/P10 |
| `xlsx` | Excel erstellen, bearbeiten, Formeln, Zellstyles, Filter, Charts, Datenvalidierung und Formelprüfung | Tabellen-Writer/Editor, Berechnung/Preview, Format-/Formelvalidierung | P09/P10 |
| `brand-guidelines` | Gestaltungsregeln der korrekten Projektmarke für Text/Web/Office anwenden | Autorisierte Brand-Assets und Projektstyleguide als Quelle, Designchecks | P09/P12 |
| `frontend-design` | Eigenständige responsive, zugängliche und visuell geprüfte UI-Gestaltung | Workspace/Preview, Browser-/Screenshot-Tests, Asset-Pipeline | P09/P10/P19 |
| `web-artifacts-builder` | Mehrteilige React/TypeScript-Miniapps mit UI-Komponenten, Build, Bundle, Vorschau, Download | NPM-/Build-Umgebung, sichere Komponenten-/Abhängigkeitsauflösung, Projekteditor | P09/P10/P11 |
| `claude-api` | Optionaler Anthropic-Provider mit Messages, Tools, Thinking, Streaming, Limits und Fehlern | Separater Provideradapter samt Credentials und Tool-/Queue-Integration; CoreAI bleibt bisher Ollama-orientiert | P05/P07/P14 |
| `mcp-builder` | MCP-Server, Tools, Resources/Prompts, Schemas, Transports, Auth, Fehler- und Rechteprüfungen | MCP-Client/Server-Anbindung und Plugin-Lebenszyklus, SDK/Tests | P07/P14 |
| `skill-creator` | Skills mit SKILL.md, Ressourcen, Scripts, Templates, Tests, Versionierung, Import/Export | Heute: repository-eigener Katalog; zukünftig Ressourcenloader, Skill-Workspace, Versionen/Import/Export | P12/P13 |
| `webapp-testing` | Playwright E2E für lokale Apps, Testkonten, Screenshots, Traces, Downloads, Mobile | Playwright-Testharness gegen autorisierte lokale/Projekt-URLs; Browserrecherche ist kein Ersatz | P09/P10/P15 |
| `algorithmic-art` | p5.js/Canvas, deterministische Generierung, Partikel/Noise, Export als Bild/Animation | JS-Renderer, Frame-Capture, Medienexport und Performancechecks | P09/P10/P20 |
| `canvas-design` | Poster, Covers, Druck-/Digital-Grafiken, Typografie, PNG-/PDF-Export | Grafik-/Layoutengine, Fonts, Farbraum-/Seitenprüfung, Dateiexport | P09/P20 |
| `slack-gif-creator` | Optimierte GIF/Emoji-Animationen nach Plattformgrenzen und Größenbudget | Frame-Renderer, GIF-Encoder/Optimizer, Bildvorschau, Plattform-Validator | P20 |
| `theme-factory` | Farbpaletten, Tokens, Themes und Light/Dark-Varianten für UI/Artefakte | Token-/Theme-Writer, Preview und Kontrastprüfung, Projektausgabe | P09/P10 |
| `doc-coauthoring` | Gemeinsames Dokument mit Interview, Gliederung, Revision, Feedback und finalem Export | Persistente Dokument-/Revisionsansicht, Office-Export, Kommentare und Nachvollziehbarkeit | P08/P09/P10 |
| `internal-comms` | Interne Ankündigungen, Changelogs, Teamberichte, Newsletter und Memos | Textentwurf möglich; für Versand/Freigabe erst autorisierte Kanaltools und echte Bestätigung | P08/P15/P22 |
| `academy-guide` | Passende aktuelle Claude-Academy-Kurse prüfen; für CoreAI eigene Lernpfade | Verifizierbarer Onlinekatalog und Tutorial-Output; keine erfundenen Kurse | P15/P16 |
| `discernment-nudge` | Kritische Prüfung folgenreicher Antworten mit konkreten Rückfragen, ohne unnötige Wiederholung | Skill jetzt gezielt auswählbar; eine systemweite automatische Schlussprüfung wäre eine **separat freizugebende** Routingfunktion | P08/P12 |

Weitere Referenzen aus der CoreAI-Funktionsmatrix: `agent-task-design` (CrewAI/AutoGen-Rollen und Ergebnisverträge) und `workflow-checkpoints` (LangGraph/AutoGen-Checkpoints, Zustandsübergänge, Resume). Hier fehlen die vollständigen P26-/P27-Engines. [OpenClaw](https://github.com/openclaw/openclaw), [Open WebUI](https://github.com/open-webui/open-webui), [CrewAI Skills](https://github.com/crewAIInc/skills), [LangGraph](https://github.com/langchain-ai/langgraph) und [AutoGen](https://github.com/microsoft/autogen) bleiben zusätzlich für die laufende Detailinventur P01 erfasst.

## OpenAI-/Rechts-Skills: aktuelle Rechtslage und Toolbedarf

Die zusaetzlichen 23 Skills ergaenzen den 77-Skills-Stand auf **100**.

Das aeltere [OpenAI Skills-Repository](https://github.com/openai/skills) ist offiziell als deprecated markiert. Fuer spaetere Codex-/Plugin-Vergleiche auch [OpenAI Plugins](https://github.com/openai/plugins) verwenden. Die hier beschriebenen juristischen Skills sind **eigene CoreAI-Fachentwuerfe**, nicht vorgefertigte OpenAI-Rechtsskills.

 20 juristische Skills sind mit vollstaendiger Fachaufgabe, Werkzeugbedarf und Abnahmetests vorbereitet. Bei **jeder Rechtspruefung** muss der am Ereignisdatum geltende Normstand sowie die aktuelle Rechtslage per Webrecherche ermittelt werden. Die Pflicht gilt direkt in jeder rechtlichen SKILL.md, nicht nur in dieser Dokumentation.

Referenz fuer alle juristischen Skills: [COREAI_LEGAL_CURRENTNESS.md](COREAI_LEGAL_CURRENTNESS.md). Amtliche Quellen: [Bundesgesetzblatt](https://www.recht.bund.de/), [Gesetze im Internet](https://www.gesetze-im-internet.de/hinweise.html) als konsolidierte, nicht amtliche Darstellung, [EUR-Lex](https://eur-lex.europa.eu/collection/eu-law/consleg.html) fuer EU-Rechtsakte und historische konsolidierte Fassungen, zustaendige Landesrecht-Portale und Originalentscheidungen von Gerichten. **Nicht** allein auf Modellwissen, alte RAG-Inhalte, Suchtreffer oder eine ungepruefte Sekundaerquelle vertrauen.

| Skill | Fachlicher Zielumfang | Benoetigte Tools und Abnahme |
|---|---|---|
| `legal-research` | Online-Rechtsquellen, Auslegung und Urteile | Aktuelle/historische Gesetzesversionen, amtliche Verkuendung, Gerichtsquellen |
| `contract-review-summary` | Vertragsanalysetabelle, Klausel-Redlining | DOCX/PDF, Klausel-Diff, geltende Normen und Vertragsplaybook |
| `legal-memo-standard` | Gutachten, Rechtsmemo, Subsumtion | Normen-/Urteilrecherche, Zitatpruefung und Dokumentexport |
| `policy-qa-from-knowledgebase` | Interne Richtlinien gegen Recht pruefen | Privates RAG mit Berechtigungen, Versionen und amtlicher Quellenabgleich |
| `legal-drafting` | Widerspruch, Antrag und Schriftsatz | Bescheid-/Dokumentenparser, Belege, Fristen, Entwurf und Export |
| `legal-deadlines` | Fristbeginn, Zustellung, Feiertage | Normenbasierter Fristenrechner mit Landes-/Verfahrensregeln |
| `administrative-law` | Verwaltungsakte, Behoerdenbescheide | Bundes-/Landesrecht, Zustellungs-, Rechtsweg- und Fristenpruefung |
| `social-law` | SGB, Leistungen, Bescheide und Rechtsbehelfe | SGB-Fassungen, Bescheid-PDF, anwendbare Leistungsrechnung |
| `tenancy-law` | Mietvertraege, Kuendigung, Nebenkosten | BGB, Landes-/Ortssonderrecht, Abrechnung und Fristen |
| `employment-law` | Arbeitsvertraege, Kuendigung, Urlaub | Aktuelle Normen, Tarif-/Vertragsstand, Arbeitsgericht-Recherche |
| `consumer-law` | Widerruf, Reklamation, Forderung | Vertrags-/Rechnungsparser, BGB-Spezialrecht und Fristen |
| `privacy-law` | DSGVO, BDSG, Betroffenenrechte | EUR-Lex, Aufsichtsbehoerden, Dateninventar und Zustandspruefung |
| `intellectual-property` | Urheber-, Marken- und Lizenzrecht | DPMA/EUIPO, Lizenztexte, Rechteketten und Asset-Inventar |
| `tax-law` | Steuerjahre, Veranlagung und Bescheid | Aktuelle/historische Steuerfassungen, BMF/BFH und Finanzrechner |
| `civil-procedure` | Zivilprozess, Kosten, Rechtsmittel | ZPO/Verfahrensrecht, Gerichts-/Fristen-/Kostenpruefung |
| `evidence-review` | Belege, Widersprueche und Zeitachsen | Originaldateien, Hash/Metadaten, OCR mit verifizierten Fundstellen |
| `official-forms` | Amtliche Antragsformulare | Aktuelle Vordrucke, Formularparser, Version und sichere Dateiausgabe |
| `family-law` | Unterhalt, Sorge, Verfahren | BGB/FamFG, aktueller Tabellenstand, vertrauliche Dateiverarbeitung |
| `criminal-law` | StGB/StPO, Rechte, Verfahrensstand | Strafrechtsfassung am Tatzeitpunkt, Urteile, Fristenpruefung |
| `company-law` | Gesellschaften, Gruendung, Register | Aktuelle Register- und Rechtsformvorschriften, Satzungsentwurf |
| `financial-analysis` | Budgets, Liquiditaet und Kennzahlen | Rechen-/Tabellenwerkzeuge, aktueller Quellenstand und Charts |
| `academic-research` | Literatur, Studienbewertung, Zitate | Fachsuche, DOI, PDF-Parser und Evidenzpruefung |
| `threat-modeling` | defensive AppSec-Bedrohungsmodelle | Repository-Analyse, Datenfluss- und Risikopruefung |

**Abnahme fuer alle Rechtsskills:** positiver Stichtagsfall, geaendertes Gesetz, noch nicht in Kraft getretene Novelle, altes Bescheiddatum, Landesrecht, unklare Frist und Fall **ohne Webzugriff**. Nur wenn Quellen aufrufbar und zeitlich richtig sind, darf Mara das Ergebnis als nach aktuellen Originalquellen geprueft bezeichnen.

## Gemeinsame Abnahmevoraussetzungen

1. **SKILL-Ebene:** Name und Trigger registriert, passende Inputs/Outputs, genaue Workflow-Schritte, klare Ausführungsgrenzen und negative Beispiele.
2. **Werkzeug-Ebene:** Input/Output-Schema, installierbare Abhängigkeiten, Rechte, Timeout, Abbruch, Fehlervertrag, verifizierbare Resultate und echte Integration.
3. **Datei-Ebene:** Richtige Bytes, Dateityp, Struktur, Öffnbarkeit, bei grafischen Ausgaben Renderprüfung und persistenter Download.
4. **Modell-Ebene:** Tool- und Skill-Ereignisse auseinanderhalten, keine privaten Thinking-Inhalte im Worklog, globale serielle Modellwarteschlange beachten.
5. **Betrieb:** Offline-Selbsttests, echte autorisierte Laufzeittests und Smartphoneprüfung. Nicht ausgeführte Checks bleiben offen.

## Unveränderte Architektur und rechtliche Abgrenzung

- `werkzeuge` bleibt einziger verpflichtender Skill; alle anderen werden wie seit 0.5.80 von Mara selbst ausgewählt oder vorab geladen.
- Die bestehende `[SKILL: kennung]`-Syntax, die Anleitungsgrenze von 2400 Byte und die Indexgrenze von 3600 Byte bleiben erhalten.
- Referenzdateien in den Skill-Ordnern sind bis zu einer **separat geplanten** Ressourcenladefunktion nur Quell-/Entwicklungsdokumentation. Keine heimliche Loader- oder Modelfile-Änderung.
- Eigene Skill-Anleitungen und Zielverträge; kein Kopieren von proprietären DOCX/PDF/PPTX/XLSX-Skilldateien oder fremden Grafiken/Schriften. Quelloffene Beispiele und SDK-Dokumentationen sind Architekturvorbilder.
- Das zusätzliche lokale Gemma-Grammatik-Feature aus #119 bleibt ein separates späteres Vorhaben, damit kein unerwünschter systemweiter Pflicht-Skill eingeführt wird.

**Status:** Planung und Skill-Dateien vorbereitet. Technische Werkzeug-Lücken sind absichtlich sichtbar und noch nicht als abgeschlossen markiert.


## AgenticSkills.io-Quelleninventur: sechs zusaetzliche Skills

Die inzwischen **106 Skills** enthalten sechs selbst formulierte und im Katalog registrierte Anleitungen aus den bisher noch fehlenden Bereichen Skill-Entdeckung, technisches SEO, Produktmarketing, mobile Anwendungen, Eigenschaftstests und Datenbankleistung. Details, Primaerquellen und Funktionsabnahmen stehen unter [COREAI_AGENTICSKILLS_REFERENCE.md](COREAI_AGENTICSKILLS_REFERENCE.md). Die 139 gelisteten MCP-Server sind eine Recherchequelle, aber weder installiert noch technisch freigegeben. Bestehende P12/P13-/Workspace-/Toolpakete bleiben die Integrationspunkte.


## 0.5.82: Vertiefung statt neuer Kennungen

Die Zahl der Skills bleibt bei **106**. Die sechs bestehenden Anleitungen zu React, Next.js, Debugging, Unit-Tests, wissenschaftlicher Recherche und Bedrohungsmodellen wurden um fachliche Abnahmefaelle und separate Referenzplaene erweitert. Offene Werkzeugklassen: Node-/Browser-Preview, Profiler und Bundle-Analyse, isolierter Test-Runner, reproduzierbare Notebook-Ausfuehrung, Quellcode-Scanner und SARIF-Auswertung. Die bestehenden Skills erhalten durch Referenztexte keine neuen technischen Rechte. Detaillierte Zuordnung: [COREAI_SKILLS_FOLLOWUP.md](COREAI_SKILLS_FOLLOWUP.md).
