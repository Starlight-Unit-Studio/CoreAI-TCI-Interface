# CoreAI Skills (Bibliothek seit 0.5.80, erweitert in 0.5.81)

CoreAI enthaelt 50 vertrauenswuerdige, direkt im Repository gepflegte Anleitungen: einen fest eingebundenen Kern-Skill und 49 Fach-Skills, aus denen Mara seit 0.5.80 selbst waehlt (keine Schalter pro Konto mehr). Die urspruenglichen 32 Pakete stammen aus 0.5.33 und wurden am 25.10.2026 geprueft; zehn fuer den aktuellen Agenten-/Dateibau relevante Pakete wurden am 26.10.2026 an den neuen CoreAI-Stand angepasst.

Seit 0.5.81 sind 17 zusätzliche, thematisch spezialisierte Fach-Skills registriert: Webrecherche, Faktencheck, RAG-Lite, Dokument- und PDF-Auswertung, Tabellen, Diagramme, JSON/YAML, Linux-Shell, Websicherheit, UI/UX, technische Dokumentation, kreatives Schreiben und Lore, Übersetzung, Backup/Restore, Privatsphäre/Memory, Medienanalyse sowie Browseraktionen. Fünf vorhandene Skills enthalten zusätzliche konkrete Prüfregeln. Die Beschreibungen einiger alter Skills wurden für das bestehende Kontextbudget komprimiert.

**Unveränderte Laufzeit:** Keine Änderung an Loader, Skill-Auswahl, Marker, Game-Trennung, Modellparametern, Modelfile, globaler Queue oder dem Pflicht-Skill. Die Bibliothek enthält bei voller Ansicht weiterhin weniger als 3600 Byte. Es werden keine neuen Tools oder Produktfunktionen freigeschaltet. Der gesonderte lokale Gemma-Sprachskill aus #119 bleibt offen, weil seine Modellbindung eine eigene Integration bräuchte.

## Verbindlicher System-Skill: Werkzeuge

- `skills/werkzeuge/SKILL.md` ist im Katalog mit `required: true` registriert.
- Er gilt bei jeder Anfrage im privaten CoreAI-Console-Kanal, unabhaengig von Schlagwoertern. Die Game-Ember erhaelt diese Mara-spezifische Pflichtanleitung nicht.
- Der Server laedt ihn zwingend; es gibt keinen Schalter, der ihn abstellt.
- Seit 0.5.67 erhalten reine Einzeldatei-Quelltextaufrufe und die kurze Bauentscheidung keinen Skilltext: dort gibt es kein Werkzeug, und bei einem lokalen 4096-Token-Fenster wird jedes Token fuer die Datei gebraucht. In normalen Konsolenantworten bleibt der Kern-Skill Pflicht; bei kleinem lokalem Fenster werden weniger optionale Pakete geladen (bis 4096 Tokens eines, bis 8192 Tokens zwei).
- Er gibt Handlungsregeln zu vorhandenen Werkzeugen, ehrlichen Fortschrittsanzeigen, serieller Modellwarteschlange und Datei-Validierung. Er aktiviert **keine** fehlenden Werkzeuge und entfernt **keine** Limits aus dem bestehenden Backend.

## Fach-Skills: Mara waehlt selbst (seit 0.5.80)

Wie bei Claude oder GPT hat Mara immer Zugriff auf alle Fach-Skills; die frueheren Schalter unter Einstellungen -> MEINE SKILLS, `api/user_skills.php` und `js/skills-settings.js` sind entfernt (die Tabelle aus Migration 014 bleibt unbenutzt liegen).

1. **Vorauswahl:** Passende Schlagwoerter oder `/skill kennung` und `$kennung` laden bis zu vier Fach-Skills direkt (bis 8192 Tokens Fenster zwei, bis 4096 einer). Das spart dem lokalen Core einen Rechenschritt. Der Pflicht-Skill belegt keinen dieser Plaetze.
2. **Skill-Bibliothek:** Alle uebrigen Fach-Skills stehen als kurze Liste im Prompt (grosses Fenster mit Beschreibung, mittleres mit Titel, 4096 nur die Kennungen). Passt einer, antwortet Mara nur mit `[SKILL: kennung]` (hoechstens zwei auf einmal).
3. **Nachladen als Arbeitsschritt:** Der Marker ist ein Schritt der Arbeitsschleife (`console_agent_loop.php`, hoechstens sechs Schritte). Der geladene Skill kommt in den vertrauenswuerdigen Skill-Block oben im Prompt, nicht in die Werkzeugergebnisse (die sind ungepruefte Daten, deren Anweisungen sie nicht befolgen darf); der Schritt meldet nur "Geladen". Die Bibliothek fuehrt ihn danach als schon geladen.
4. **Ohne Schleife keine Bibliothek:** Datei-Auftraege laufen ohne Arbeitsschleife; dort bleibt es bei der Vorauswahl, die Liste wird entfernt. Im Game-Kanal gibt es keine Skills.
5. Die Work-Timeline zeigt `Skill geladen: kennung` fuer vorab geladene Skills und "Schritt n: Skill geladen" fuer selbst nachgeladene. Das ist ein Ladeereignis, kein Nachweis fuer einen erfolgreichen Werkzeugaufruf.

## Projektstand, Sicherheit und Grenzen

- Bei lokalen Gemma- und Ollama-Cloud-Modellen wird die bestehende lokale Ollama-Liste verwendet. Keine zusaetzlichen Modellparameter, kein geaendertes Modelfile, keine zweite Modellspur.
- Die fortsetzbare Agenten-/Dateiwerkzeug-Schleife aus Issue #102 ist weiter offen: Planlimits, Manifestgrenzen und fehlende persistente Quelltext-Checkpoints sind **nicht** allein durch Skilltexte repariert.
- Maras Worklog und der Live-Code-Entwurf aus Draft #105 sind in den gemeinsamen 0.5.62-Branch aufgenommen worden. Rohes Modell-Thinking, private Prompts und fremde Daten gehoeren niemals in oeffentliche Skill-/Workevent-Payloads.
- Skill-Inhalte werden nur aus validierten Katalogpfaden des Repositories geladen. Keine Remote-Skripte, implizite Werkzeugfreigabe, Modellausgabe als Host-Kommando oder nicht autorisierte Dateipfade.
- Alle Skills nutzen maximal 2400 Byte pro reiner SKILL.md-Anleitung; keine stille Textabschneidung. Kuerzere Skilltexte ersetzen nicht den im Backend erforderlichen Speicher fuer Projektfortschritt.

## Skill anlegen oder aktualisieren

`skills/<id>/SKILL.md` mit name und description im Frontmatter, klarer Ablaufanleitung und registriertem Katalogeintrag erstellen. Beschreibung, Trigger, Version, Quelle und Pruefdatum angeben. Die Beschreibung steht in Maras Skill-Bibliothek: kurz und so, dass Mara erkennt, wann der Skill passt. Neue Fach-Skills sind sofort fuer alle verfuegbar. `required: true` ist fuer fest genehmigte CoreAI-Kernfunktionen reserviert; bei einem solchen Eingriff sind Backend- und UI-Tests sowie die Versionsrichtlinie zwingend.

## Pruefung

- `php scripts/skills-selftest.php` prueft die 50 Paketdokumente, Pflicht-Skill, Vorauswahl und Budget, die Bibliothek je Fenstergroesse, den Marker (auch in Code nicht ausgeloest), das Nachladen in den vertrauenswuerdigen Block, die Arbeitsschleife, fokussierte Unteraufrufe, Datei-Auftraege ohne Bibliothek, den Game-Kanal und dass keine Schalter mehr existieren.
- Danach `python3 scripts/version-merge-check.py --base-version 0.5.61` und `python3 scripts/cache-bust.py --verify` sowie PHP-Syntaxpruefung und Live-Abnahme durchfuehren.
- Offline-Tests ersetzen keinen erfolgreichen Netcup-/Android-/Ollama-Livetest. Kein fertiges 10-Unterseiten-ZIP behaupten, bevor es tatsaechlich erzeugt und geprueft wurde.

Referenzen: https://agentskills.io/specification und die Quellen jedes Katalogeintrags.
