# Playwright-Webapp-Tests: Produktionsumfang

Spezifikation der fertigen Funktion für spätere Werkzeuge. SKILL.md bleibt der aktiv geladene Kern; dieses Referenzdokument lädt Mara nur bei Bedarf ([SKILL: webapp-testing/references/IMPLEMENTATION.md]).

## Fähigkeiten
Automatisierte E2E-Tests für lokale Webapps, inklusive Start/Stopp von Entwicklungsservern, Rollen/Locatoren, sicheren Formularen, Browsernavigation, responsive Viewports, Mockdaten, Downloadkontrolle, Screenshots, Traces und Fehlerdiagnose. Browserzustand pro Test isolieren; vorhandene Sessionrechte wahren.

## Workflow
1. Aufgabenart, Eingabematerial, Ausgabeparameter und Prüfmaßstab bestimmen.
2. Ausführungs-/Entwurfsplan, erforderliche Dateien und Versionen festlegen.
3. In isoliertem Werkzeugkontext produzieren; bei fehlenden Tools die geplante Ausführung konkret spezifizieren.
4. Tatsächlichen Render- oder Testoutput zurücklesen und mit dem Soll vergleichen.
5. Fehlerbilder korrigieren, Datei- und Testartefakte registrieren und überprüft ausliefern.

## Werkzeugbedarf
Playwright-Runner und Browser mit Zugriff auf autorisierte Test-URLs, Projekt-Workspace, Test-Server-Lifecycle, Screenshot/Trace-Artefakte und Testreporter.

## Abnahmeszenarien
Echter Testlauf auf schmalem/breitem Viewport; wichtige Nutzerwege funktionieren; Formularfehler reproduzierbar; Download-Bytes korrekt; fehlgeschlagene Schritte mit Trace nachweisbar.

- [ ] Erfolgs- und Fehlerfall getestet
- [ ] Echte Ausgabe und Darstellung auf Zielgerät validiert
- [ ] Keine Vermischung von fremden Tool-Ausgaben und vertrauten Anweisungen

## Integrationsgrenze
Ein Skill darf vor Fertigstellung der Toolintegration bereits den vollständigen Workflow beschreiben. Die echte Ausführung folgt später separat über P09/P10 bzw. Medien-/Browser-Roadmap und verändert nicht den Pflicht-Skill oder das serielle Modelllimit.
