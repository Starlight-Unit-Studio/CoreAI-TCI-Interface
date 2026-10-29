# DOCX: vollständiger Zielumfang

Diese Spezifikation beschreibt die gewünschte spätere CoreAI-Funktion. Die bestehende Runtime lädt aktuell nur SKILL.md, nicht diese Referenzdatei. Es werden keine Änderungen am Loader vorgenommen.

## Eingabe und Ausgabe
Eingaben: Word-Dateien, Vorlagen, Texte, Bilder, Layoutvorgaben und konkrete Änderungswünsche.
Ausgaben: editierbare DOCX-Datei, bei Bedarf zusätzliche PDF-Vorschau und prüfbarer Änderungsbericht.

## Funktionen
- Word-Datei aus dem Nichts erstellen, mit Überschriften, Tabellen, Nummerierung, Listen, Umbrüchen, Kopf- und Fußzeilen, Feldern, Inhaltsverzeichnis und Medien.
- Bestehende DOCX gezielt ändern, ohne fremde Formatvorlagen, Abschnitte und eingebettete Medien zu verlieren.
- Kommentare und Änderungsverfolgung fachgerecht behandeln, einschließlich OOXML-Beziehungen.
- Briefvorlagen und Platzhalter verwenden, auf Wunsch mit mehreren verifizierten Ausgaben.
- Struktur und Layout nach jeder Änderung erneut überprüfen.

## Geplanter Workflow
1. Eingabedateien sicher bereitstellen und Dokumentstruktur auslesen.
2. Änderungsplan samt Abhängigkeiten auf Abschnitts- und Elementebene erstellen.
3. Schreiben und Editieren mit geeigneter DOCX/OOXML-Bibliothek.
4. Output als OOXML validieren und Quelländerungen vergleichen.
5. Rendering der Seiten zur visuellen Prüfung; fehlende Renderer als offene Abhängigkeit melden.
6. Datei persistent registrieren, Größe und Download bestätigen.

## Werkzeugbedarf
Datei- und Archivzugriff, DOCX-Leser, OOXML-Editor, DOCX-Writer, Layout-Renderer, Exportdienst und optional Kommentar-/Redlining-Engine. Fehlende Werkzeuge als Roadmap-Aufgaben führen, insbesondere Projektworkspace P09/P10 und Dokumentexport.

## Abnahmetests
- [ ] Word öffnet die Ausgabedatei fehlerfrei.
- [ ] Umfang, Formatierung, Abbildungen und Seitenreihenfolge sind vollständig.
- [ ] Bestehende Bereiche bleiben erhalten.
- [ ] Änderungen und Kommentare entsprechen dem Auftrag.
- [ ] Die erzeugte Datei ist nach Reload erneut verfügbar.
