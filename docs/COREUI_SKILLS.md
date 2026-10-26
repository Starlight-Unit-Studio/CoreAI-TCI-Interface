# CoreAI Skills (0.5.62)

CoreAI enthaelt jetzt 33 vertrauenswuerdige, direkt im Repository gepflegte Anleitungen: einen fest eingebundenen Kern-Skill und 32 optional aktivierbare Fach-Skills. Die urspruenglichen 32 Pakete stammen aus 0.5.33 und wurden am 25.10.2026 geprueft; zehn fuer den aktuellen Agenten-/Dateibau relevante Pakete wurden am 26.10.2026 an den neuen CoreAI-Stand angepasst.

## Verbindlicher System-Skill: Werkzeuge

- `skills/werkzeuge/SKILL.md` ist im Katalog mit `required: true` registriert.
- Er gilt bei jeder Anfrage im privaten CoreAI-Console-Kanal, unabhaengig von Schlagwoertern, gespeicherten Nutzer-Praeferenzen und der optionalen Migration 014. Die Game-Ember erhaelt diese Mara-spezifische Pflichtanleitung nicht.
- Der Server laedt ihn zwingend und uebergeht historische `enabled=0`-Datenbankzeilen. `user_skills.php` darf ihn nicht auf `false` setzen, auch nicht bei manipulierten POST-Daten.
- Die Einstellungen zeigen ihn oben, dauerhaft angehakt und ausgegraut. Die Schaltflaechen "Alle aktivieren" und "Alle deaktivieren" betreffen ihn nicht. Der Pflicht-Skill wird nicht in kontoabhaengige Vorlieben geschrieben.
- Bei fokussierten Quellcode-Unteraufrufen bleibt der kurze Kern-Skill aktiv, waehrend bis zu vier weitere optionale Pakete wie bisher ausgelassen werden koennen.
- Er gibt Handlungsregeln zu vorhandenen Werkzeugen, ehrlichen Fortschrittsanzeigen, serieller Modellwarteschlange und Datei-Validierung. Er aktiviert **keine** fehlenden Werkzeuge und entfernt **keine** Limits aus dem bestehenden Backend.

## Optionale Skills

1. Migration `014_user_skills.sql` ueber den vorhandenen Updateweg einspielen, soweit noch nicht geschehen.
2. Einstellungen -> MEINE SKILLS: wahlweise einzelne der 32 Fach-Skills aktivieren, alle aktivieren oder deaktivieren, danach AUSWAHL SPEICHERN.
3. Natuerliche Schlagwoerter oder `/skill zip-delivery` und `$zip-delivery` waehlen maximal vier optionale Pakete pro Anfrage. Der Pflicht-Skill belegt keinen dieser vier Plaetze.
4. Pro Konto gespeicherte Praeferenzen bleiben getrennt und werden erst ab der naechsten Anfrage geladen. Die System-Skill-Anleitung ist auch ohne Praeferenz-Tabelle aktiv.
5. Die Work-Timeline protokolliert `Skill geladen: werkzeuge` und gegebenenfalls weitere Fach-Skills. Das ist ein Ladeereignis, kein Nachweis fuer einen erfolgreichen Werkzeugaufruf.

## Projektstand, Sicherheit und Grenzen

- Bei lokalen Gemma- und Ollama-Cloud-Modellen wird die bestehende lokale Ollama-Liste verwendet. Keine zusaetzlichen Modellparameter, kein geaendertes Modelfile, keine zweite Modellspur.
- Die fortsetzbare Agenten-/Dateiwerkzeug-Schleife aus Issue #102 ist weiter offen: Planlimits, Manifestgrenzen und fehlende persistente Quelltext-Checkpoints sind **nicht** allein durch Skilltexte repariert.
- Maras Worklog und der Live-Code-Entwurf in PR #105 sind separate UI-Arbeiten. Rohes Modell-Thinking, private Prompts und fremde Daten gehoeren niemals in oeffentliche Skill-/Workevent-Payloads.
- Skill-Inhalte werden nur aus validierten Katalogpfaden des Repositories geladen. Keine Remote-Skripte, implizite Werkzeugfreigabe, Modellausgabe als Host-Kommando oder nicht autorisierte Dateipfade.
- Alle Skills nutzen maximal 2400 Byte pro reiner SKILL.md-Anleitung; keine stille Textabschneidung. Kuerzere Skilltexte ersetzen nicht den im Backend erforderlichen Speicher fuer Projektfortschritt.
- Fach-Skills bleiben auch nach einem fehlgeschlagenen POST unveraendert; der Backend-Pflicht-Skill bleibt unabhaengig davon aktiv.

## Skill anlegen oder aktualisieren

`skills/<id>/SKILL.md` mit name und description im Frontmatter, klarer Ablaufanleitung und registriertem Katalogeintrag erstellen. Beschreibung, Trigger, Version, Quelle und Pruefdatum angeben. Neue Fach-Skills sind standardmaessig opt-in. `required: true` ist fuer fest genehmigte CoreAI-Kernfunktionen reserviert; bei einem solchen Eingriff sind Backend- und UI-Tests sowie die Versionsrichtlinie zwingend.

## Pruefung

- `php scripts/skills-selftest.php` prueft die 33 Paketdokumente, Pflicht-Auswahl, Legacy-Disabled-Override, API-Validierung, ohne Migration bereitgestellten Kern-Skill, Auswahlbudget, isolierte Kontovorlieben und Pflicht-Skill in fokussierten Unteraufrufen.
- `node scripts/skills-ui-selftest.cjs` prueft mit Playwright die ausgegraute angehakte Schalteranzeige, Bulk-Aktionen, CSRF, Suche, Reload, fehlgeschlagene Speicherung, Mobile und Migrationsstatus.
- Danach `python3 scripts/version-merge-check.py --base-version 0.5.61` und `python3 scripts/cache-bust.py --verify` sowie PHP-Syntaxpruefung und Live-Abnahme durchfuehren.
- Offline-Tests ersetzen keinen erfolgreichen Netcup-/Android-/Ollama-Livetest. Kein fertiges 10-Unterseiten-ZIP behaupten, bevor es tatsaechlich erzeugt und geprueft wurde.

Referenzen: https://agentskills.io/specification und die Quellen jedes Katalogeintrags.
