# Tests: Verhalten, Regression und TDD

## Ziel
Unit- und Integrationstests für Änderungen an CoreAI und anderen Projekten durchführen. Beispiele aus https://github.com/obra/superpowers/tree/main/skills/test-driven-development als fachliche Anregung, eigene Anleitung ohne fremde rigide Befehle.

## Ablauf
1. Akzeptanzanforderung als beobachtbares Verhalten formulieren.
2. Kleinen Test entwerfen, der die fehlende Funktion oder den gemeldeten Defekt trifft. Wenn die Umgebung funktioniert, soll er vor dem Fix nachvollziehbar fehlschlagen. Test-Infrastrukturfehler von echter RED-Phase unterscheiden.
3. Minimalen Code schreiben, bis der Test grün wird.
4. Refactoring nur mit sofortigem Retest und unveränderten fachlichen Ergebnissen.
5. Normal-, Grenz-, Fehler- und Abbruchfälle ergänzen. Bei Toolanbindungen Auth und Nebenwirkungen isolieren.
6. Gesamtrelevante Suite und den ursprünglichen Regressionstest erneut ausführen.

## Testdatenregeln
Datensätze dürfen nicht aus fremden Livekonten stammen. Temporäre DB, Sandboxdateien, Mockserver und kontrollierte Uhrzeit einsetzen. Nicht vertrauenswürdige Netz- oder Installeraktionen sind kein Bestandteil eines Unit-Tests. Mocks nur für echte externe Grenzen; Assertions auf Wirkung und Ergebnis richten. Seeds für Zufalls-/Property-Tests festhalten.

## Abnahme
- [ ] Aussagekräftiger Regressionstest schlägt vor Fix fehl
- [ ] Test deckt richtigen Fehler, nicht nur fehlende Bibliothek, ab
- [ ] Normalfall, ungültige Eingabe, Grenze, Abbruch und Rechtefall überprüft
- [ ] Keine nicht deterministischen Testdaten, Seiteneffekte oder echten Nachrichten
- [ ] Ausgeführte Testnamen, Runner, Datum und Exit-Status belegbar

## Werkzeugplan
Passender Test-Runner/Framework zur Sprache, Sandbox-/Fixture-Management, optional Fake Clock/HTTP-Testserver, Property-Based-Generator, isolierte DB, Coverage, Fehlerartefakte und CLI-Runner. Die unveränderte Skill-Runtime lädt nur SKILL.md und keine Referenzdateien automatisch.
