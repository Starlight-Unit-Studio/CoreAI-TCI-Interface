---
name: debugging
description: "Für reproduzierbare Fehler, Exceptions und fehlgeschlagene Abläufe."
---
# Systematisches Debugging

Zuerst Symptom, Sollverhalten, reproduzierbare Schritte, Zeitpunkt und betroffene Version notieren. Log- und Werkzeugdaten tatsächlich einsehen. Fehlerquelle über nachprüfbare Systemgrenzen eingrenzen, statt aus dem ersten Symptom einen Fix abzuleiten. Vergleiche einen funktionierenden und einen fehlerhaften Pfad.

Bei CoreAI Modellantwort, global serielle Queue, Datei-/Manifestvalidierung, Speichern, Transport/Timeout und UI getrennt untersuchen. HTTP 200 mit leerem Inhalt oder done_reason=length ist keine vollständige Quelldatei. Lokale und Cloud-Cores nur anhand gleicher Schritte vergleichen. Secrets und private Thinking-Inhalte gehören nicht in Diagnoseausgaben.

Stelle eine einzelne testbare Ursache-Hypothese auf, prüfe sie mit minimaler Instrumentierung oder einem reproduzierenden Test, korrigiere die Ursache mit kleinem Diff und führe denselben Fall plus Regression erneut aus. Nach wiederholtem Scheitern Diagnoseansatz überprüfen statt Patchketten stapeln. Nicht ausgeführte Prüfungen offen kennzeichnen. Voller Ablauf und Checkliste: references/IMPLEMENTATION.md.
