# GIFs für Slack und Messenger: kompletter Arbeitsablauf

Dieser Referenzplan bleibt bis zur späteren Toolintegration als fachliches Soll erhalten. Die Runtime lädt aktuell nur SKILL.md und wird dafür nicht verändert.

## Zielbild
GIF, Emoji, Sticker oder Reaktionsanimation aus Storyboard erzeugen. Plattformabhängige Bildmaße und Größenlimit aus aktuellen offiziellen Vorgaben ableiten; Frame-Rate, Schleifenmodus, Bildpalette, Dithering, Transparenz, Lesbarkeit bei Miniaturansicht und Kompression gezielt bearbeiten.

## Schrittfolge
1. Ziel, Nutzerwünsche, vorhandene Entwürfe und Abgabeformat bestimmen.
2. Struktur, Parameter und prüfbare Teilziele festhalten.
3. Die jeweils passenden Erstellungs-/Editierwerkzeuge bedienen und Teilergebnisse überprüfen.
4. Abweichungen im Vergleich mit Vorgaben korrigieren und gegebenenfalls Varianten bereitstellen.
5. Exportierte Medien oder Dokumente auf echte Dateibytes und Darstellbarkeit prüfen.

## Benötigte Werkzeuge
Frame-Renderer, Gif-Encoder/Optimizer, Palette-Quantisierung, Bildvorschau, Größenmessung und optional Slack-spezifische Formatvalidatoren.

## Prüfkriterien
Animation läuft fehlerfrei und loopen korrekt; Text und Motiv in tatsächlicher Zielgröße erkennbar; Dateigröße unter verifizierter Plattformgrenze; Farbdarstellung akzeptabel.

- [ ] Reproduzierbare Qualitätsprüfung durch reale Ausgabe
- [ ] Keine erfundenen Assets, Testläufe oder Daten
- [ ] Versions-/Revisionsstand und Rechte dokumentiert

## Nachrüstung
Fehlende Toolklassen als Backend-/Workspace-Arbeitspakete erhalten. Keine Verkürzung des Zielumfangs nur deshalb, weil ein Renderer oder eine API in CoreAI noch fehlt.
