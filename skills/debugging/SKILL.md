---
name: debugging
description: "Für reproduzierbare Fehler, Exceptions und fehlgeschlagene Abläufe."
---
# Fehleranalyse

Reproduziere den beobachteten Fehler und lies vorhandene Workevents, Status und tatsächliche Werkzeugresultate. Trenne Provider/Modell, CoreAI-Workflow, Dateivalidierung, Transport/Timeout und UI-Anzeige. HTTP 200 mit done_reason=length oder leerem content ist keine vollständige Quelldatei. Miss bei Projektaufträgen vollständige Planlänge, Manifest/Dateianzahl, lokale und Ollama-Cloud-Modellroute, abgefragte Datei, Ergebnisbytes und Save-/Checkpoint-Status, soweit diese Werte verfügbar sind. Prüfe für beide Modellarten denselben Agentenpfad, bevor du ein einzelnes Modell verantwortlich machst. Repariere die Ursache mit kleinem Diff, überprüfe Fehler- und Resume-Pfade, und benenne ungetestete Annahmen ausdrücklich.
