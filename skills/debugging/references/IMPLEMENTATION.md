# Debugging: beweisgestützter Fehlerablauf

## Einordnung
Diese Anleitung vertieft `debugging`, nicht ein neuer verpflichtender Skill. Methodische Anregung: https://github.com/obra/superpowers/tree/main/skills/systematic-debugging (eigene CoreAI-Formulierung).

## Vier Diagnosephasen
1. **Istzustand sichern:** Zeitpunkt, Versionsstand, beobachtetes Ergebnis, Nutzerweg, Fehlertext, Zeilenhinweise und Umfang der Auswirkungen erfassen.
2. **Wirkungskette abgrenzen:** Daten vor und nach jeder relevanten Komponente vergleichen. Zuerst einen fehlerfreien Referenzfall finden. Unterschiede in Konfiguration, Auth, Cache, Dateipfad und Dienstversion berücksichtigen.
3. **Hypothesen testen:** Eine überprüfbare Ursache formulieren. Kleinste geeignete Instrumentierung oder Gegenprobe durchführen und nur tatsächlich beobachtete Daten protokollieren.
4. **Fix und Gegenprobe:** Kleine Ursache-bezogene Änderung, Regressionstest, erneut ursprünglichen Fehlerfall, Seitenwirkungen und Rollback prüfen.

## Spezifischer CoreAI-Pfad
Nutzereingabe -> Workevent -> Skill-Entscheidung -> Toolauswahl -> Modell-/Providerresponse -> Filevalidation -> Persistenz/Checkpoint -> SSE/UI -> Download/Reload. An jeder Grenze korrelierbare IDs und Status prüfen, ohne private Gedanken, Access-Tokens oder kompletten Nutzerdatensatz zu loggen. Ollama-/Cloud-Modellpfad mit gleicher Aufgabenspezifikation vergleichen. Eine auf HTTP-Ebene erfolgreiche Teilantwort ist keine fertige Projektdatei.

## Testfälle
- [ ] Reproduktion vor Fix dokumentiert
- [ ] Fehler trat bei Root-Cause-Probe erneut auf
- [ ] Working-vs-Failing-Diff erklärt das Symptom
- [ ] Korrigierter Pfad liefert tatsächlich valides Ergebnis
- [ ] Abbruch, ungültige Daten und Retry/Resume unverändert sicher
- [ ] Wiederholte erfolglose Fixversuche lösen neue Untersuchung aus

## Werkzeuge
Git-Diff/Blame, Status-/Errorlogs, sichere Workevent-Diagnose, reproduzierbare lokale oder Staging-Tests, Parser-/Schema-Prüfungen, Test-Runner und optional Browser-Traces. Falls die Toolklasse fehlt: nächsten kontrollierten Prüfschritt und zu erhebende Daten spezifizieren statt „behoben“ zu melden.
