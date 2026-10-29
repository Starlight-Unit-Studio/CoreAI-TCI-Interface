# XLSX: fachliche Endausbaustufe

Dies ist die eigene CoreAI-Sollbeschreibung zur Vorbereitung der Werkzeuge. Die bestehende Runtime lädt aktuell nur SKILL.md, nicht diesen Referenztext.

## Verbindliche Fähigkeiten
Neue/alte Workbooks einschließlich Tabellenblättern, Spalten, Formeln, Zellbezügen, Diagrammen, Styles, Datenvalidierung, Named Ranges, Filtern und bedingten Formaten bearbeiten; CSV/TSV-Daten sicher importieren; Makrodateien gezielt erhalten.

## Ablauf
1. Eingabedateien, Benutzerrechte, Version und gewünschten Zielzustand prüfen.
2. Inhalt und Struktur inventarisieren, genaue Operationen und betroffene Teile planen.
3. Änderungen mit passenden Dateibibliotheken in isoliertem Workspace ausführen.
4. Ergebnis neu öffnen, Struktur und Inhalte mit dem Auftrag abgleichen.
5. Sichtprüfung in einem echten Renderer, soweit für das Format relevant.
6. Datei persistent speichern, Download und spätere Wiederaufnahme prüfen.

## Noch bereitzustellende Tool-Klassen
Workbook-Reader/Writer, Formelvalidator und bei berechneten Ausgaben eine echte Rechenengine; Diagramm-/Preview-Engine, sichere Archiv- und Dateiwerkzeuge.

## Abnahme
Sheets und Formatierung erhalten; Formeln und Referenzen gültig; Zahlen/Datumswerte korrekt; Berechnung nicht aus bloßem Formelstring abgeleitet; XLSX lässt sich öffnen.

- [ ] Positive und negative Beispieldateien validiert
- [ ] Echte Ausgabe-Bytes und Wiederöffnen erfolgreich
- [ ] Fehler oder fehlende Abhängigkeiten nachvollziehbar ausgewiesen

## Einbindung
Die Anleitung bleibt unabhängig vom Tool-Backend und vom Modelfile. Fehlende Ausführungswerkzeuge als Roadmap-/Matrix-Einträge dokumentieren statt die Fähigkeit aus dem Skill zu streichen. Konzepte aus Anthropic sind Referenz, keine Übernahme proprietärer Skilldateien.
