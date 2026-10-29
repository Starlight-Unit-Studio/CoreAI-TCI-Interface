---
name: property-testing
description: "Eigenschaftstests: Testinvarianten. Einsatz bei property based testing, eigenschaftstests, invariantentest."
---
# Property-Based Testing

Bei Parsern, Serialisierern, Normalisierern, Sortierern und reinen Berechnungsfunktionen überprüfbare Invarianten bestimmen: Roundtrip, Idempotenz, Eindeutigkeit, Ordnung, Grenzen und Erhaltungssätze. Aus echten Spezifikationen Eingabegeneratoren und minimale Gegenbeispiele ableiten.

Nutze zum Stack passende Testtools nur nach bestätigter Installation, etwa Hypothesis oder fast-check. Seeds, Shrinking, Eingabegrenzen und Laufzeitbudget reproduzierbar festhalten. Tests mit Datenbank-, Zahlungs- oder Netz-Nebenwirkungen isolieren; nicht einfach zufällige Produktivdaten schreiben. Fehlende Invarianten nicht durch viele willkürliche Beispiele ersetzen.

Bei Fehlschlag tatsächliches Gegenbeispiel und Quelle des Fehlers ermitteln, dann zielgerichtet korrigieren und Regressionstest hinzufügen. Ohne ausführbaren Test-Runner Testentwurf, Invarianten und erwartete Negativfälle bereitstellen, keinen bestandenen Test behaupten.

Vollständige Zielwerkzeuge, Negativtests und spätere technische Integrationen: references/IMPLEMENTATION.md. Diese Zusatzdatei wird derzeit nicht automatisch geladen.
