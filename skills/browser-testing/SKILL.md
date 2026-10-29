---
name: browser-testing
description: "Für reproduzierbare Tests aus Sicht der Benutzer."
---
# Browser-Tests

Teste sichtbares Verhalten mit benannten Rollen und Labels. Isoliere Sitzungen und Daten zwischen Tests. Warte auf erwartete Zustände statt auf feste Schlafzeiten. Prüfe Erfolgs-, Fehler- und Leerzustände sowie einen schmalen Viewport. Halte Testdaten kontrolliert und unabhängig von fremden Diensten. Berichte nur tatsächlich gelaufene Tests samt Ergebnis.

Prüfe besonders schmale Android-Viewports, Textumbruch, überlange Dialoge, Scrollbereiche und Download-/Reload-Fälle. Unterscheide einen simulierten Browser-Viewport vom echten Android-Livetest; nur tatsächlich ausgeführte Prüfungen als bestanden melden.

Für Webapp-Tests mit Playwright zuerst prüfen, ob der Browser-Runner tatsächlich verfügbar ist. Hauptpfade und Grenzfälle samt sichtbaren Ergebnissen prüfen; bei Fehlern Screenshot/Trace nur aus real ausgeführten Tests nennen. Ohne Runner einen überprüfbaren Testplan liefern, nicht eine angeblich abgeschlossene Automation.
