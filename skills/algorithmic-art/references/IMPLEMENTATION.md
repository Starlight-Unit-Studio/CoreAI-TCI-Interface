# Generative Kunst per p5.js: Produktionsumfang

Spezifikation der fertigen Funktion für spätere Werkzeuge. SKILL.md bleibt der heute aktiv geladene Kern; die unveränderte Laufzeit lädt dieses Referenzdokument nicht automatisch.

## Fähigkeiten
Partikel, Flow Fields, L-Systeme, geometrische Regeln, Noise, deterministische Zufallsseeds und animierte Szenen planen. Reproduzierbare Parameter aus Nutzerabsicht ableiten; statische und animierte Exporte rendern; Ressourcenbudgets und Framezeiten berücksichtigen; Werke stilistisch eigenständig halten.

## Workflow
1. Aufgabenart, Eingabematerial, Ausgabeparameter und Prüfmaßstab bestimmen.
2. Ausführungs-/Entwurfsplan, erforderliche Dateien und Versionen festlegen.
3. In isoliertem Werkzeugkontext produzieren; bei fehlenden Tools die geplante Ausführung konkret spezifizieren.
4. Tatsächlichen Render- oder Testoutput zurücklesen und mit dem Soll vergleichen.
5. Fehlerbilder korrigieren, Datei- und Testartefakte registrieren und überprüft ausliefern.

## Werkzeugbedarf
Canvas/p5.js-Renderer, isolierter JS-Lauf, Frame-Capture, PNG/SVG-Export und bei Animation GIF/Video-Encoding sowie Asset-Downloader nur mit Rechteprüfung.

## Abnahmeszenarien
Gleicher Seed liefert gleiche Szene; Framebudget im Zielgerät stabil; Bildmaß und Transparenz korrekt; finale PNG oder Animation lässt sich real öffnen.

- [ ] Erfolgs- und Fehlerfall getestet
- [ ] Echte Ausgabe und Darstellung auf Zielgerät validiert
- [ ] Keine Vermischung von fremden Tool-Ausgaben und vertrauten Anweisungen

## Integrationsgrenze
Ein Skill darf vor Fertigstellung der Toolintegration bereits den vollständigen Workflow beschreiben. Die echte Ausführung folgt später separat über P09/P10 bzw. Medien-/Browser-Roadmap und verändert nicht den Pflicht-Skill oder das serielle Modelllimit.
