# PDF: fachliche Endausbaustufe

Dies ist die eigene CoreAI-Sollbeschreibung zur Vorbereitung der Werkzeuge. Die bestehende Runtime lädt aktuell nur SKILL.md, nicht diesen Referenztext.

## Verbindliche Fähigkeiten
Text und Tabellen extrahieren; PDF-Seiten zusammenfügen, teilen, drehen, sortieren, entnehmen, Wasserzeichen; neue PDF-Dokumente setzen; AcroForm-Felder auslesen und ausfüllen; gescannte Seiten mit OCR erschließen; Signaturfolgen respektieren.

## Ablauf
1. Eingabedateien, Benutzerrechte, Version und gewünschten Zielzustand prüfen.
2. Inhalt und Struktur inventarisieren, genaue Operationen und betroffene Teile planen.
3. Änderungen mit passenden Dateibibliotheken in isoliertem Workspace ausführen.
4. Ergebnis neu öffnen, Struktur und Inhalte mit dem Auftrag abgleichen.
5. Sichtprüfung in einem echten Renderer, soweit für das Format relevant.
6. Datei persistent speichern, Download und spätere Wiederaufnahme prüfen.

## Noch bereitzustellende Tool-Klassen
PDF-Reader, Writer, Seitenrenderer, OCR-Engine mit Sprachdateien, Formularwerkzeug, Font- und Layoutengine, sicherer Export.

## Abnahme
Seitenzahlen, Rotation und Reihenfolge stimmen; Felder behalten korrekte Werte; OCR-Text ist nachprüfbar; Seitenlayout ist lesbar; Output öffnet und überlebt Reload.

- [ ] Positive und negative Beispieldateien validiert
- [ ] Echte Ausgabe-Bytes und Wiederöffnen erfolgreich
- [ ] Fehler oder fehlende Abhängigkeiten nachvollziehbar ausgewiesen

## Einbindung
Die Anleitung bleibt unabhängig vom Tool-Backend und vom Modelfile. Fehlende Ausführungswerkzeuge als Roadmap-/Matrix-Einträge dokumentieren statt die Fähigkeit aus dem Skill zu streichen. Konzepte aus Anthropic sind Referenz, keine Übernahme proprietärer Skilldateien.
