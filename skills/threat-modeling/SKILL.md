---
name: threat-modeling
description: "IT-Bedrohungen."
---
# Defensive Bedrohungsmodellierung und statische Analyse

Scope, Betriebsmodell, Codeversion, reale Datenflüsse und Vertrauensgrenzen aus Repository und Konfiguration ermitteln. Schützenswerte Daten, Secrets, Zugänge und Ausführungswege priorisieren; unbestätigte Architekturannahmen ausdrücklich markieren. Angriffs- und Missbrauchspfade nur gegen autorisierte Systeme untersuchen.

Für Quellcodeprüfungen Semgrep, CodeQL, SARIF oder vorhandene Linter als optionale Analysewerkzeuge vorsehen, jeweils mit sprach-/frameworkgerechtem Regelstand. Statische Funde sind Hypothesen: false positives, tatsächliche Erreichbarkeit, Datenfluss, bestehende Kontrollen und Auswirkung im Code prüfen. Keine automatischen Scans fremder Ziele.

Jeden bestätigten Fund mit Codepfad, Trust Boundary, realistischer Auswirkung, Wahrscheinlichkeit, Gegenmaßnahme und Regressionstest dokumentieren. Keine Sicherheitsgarantie allein aus einem grünen Scanner ableiten. Ausführlicher defensiver Tool- und Reviewplan: references/STATIC_ANALYSIS.md sowie references/IMPLEMENTATION.md.
