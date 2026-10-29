---
name: unit-testing
description: "Für kleine, unabhängige Tests an relevanten Verhaltensgrenzen."
---
# Unit-, Regression- und TDD-Tests

Leite testbare Anforderungen aus dem Nutzerziel ab. Wähle Normalfall, Grenze und Fehlerszenario. Bei neuen Funktionen und Bugfixes, soweit Runner vorhanden, einen schmalen Red-Green-Refactor-Ablauf nutzen: zunächst erwartetes Fehlverhalten reproduzieren, dann minimal korrigieren, danach ohne Verhaltensänderung aufräumen. Bei vorhandenen Features sind gezielte Regressionstests ebenfalls sinnvoll.

Teste öffentliche Verhaltensergebnisse statt reine Mock-Interaktionen. Isoliere Dateisystem, Netzwerk, Uhrzeit, Konto- und DB-Daten; Fixtures kontrolliert aufräumen. Bei Parsern oder Berechnungen zusätzlich geeignete Invarianten aus property-testing berücksichtigen. Ein nicht reproduzierbar grüner Test ist keine bestätigte Abnahme.

Nenne ausgeführten Runner, Testauswahl, Ergebnis und offene Lücken. Fehlt der Testzugriff, liefere Cases und erwartetes Resultat, nicht einen erfundenen Pass. Komplette Teststrategie und Toolbedarf: references/IMPLEMENTATION.md.
