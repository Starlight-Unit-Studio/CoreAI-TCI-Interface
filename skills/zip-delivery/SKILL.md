---
name: zip-delivery
description: "Für vollständige Archive mit überprüften Dateien und Verweisen."
---
# ZIP und Dateiauslieferung

Erzeuge und prüfe jede angeforderte Quelldatei, bevor du das Archiv zusammenstellst. Bewahre bei längeren Builds bestätigte Teilergebnisse und ihre eindeutigen Dateinamen; wo das Backend noch keine dauerhaften Checkpoints unterstützt, benenne diese Einschränkung. Keine vollständige zehnseitige Homepage aus nur drei Ersatzdateien behaupten. Sichere relative Pfade ohne Traversal, Symlinks oder versteckte Host-Ausführung. Prüfe Inhalt, Dateiliste, Größen, HTML/CSS/JS-Syntax soweit verfügbar, interne Links und ZIP-Integrität. CRC allein beweist keine funktionierende Anwendung. Liefere das Archiv erst aus, wenn das Werkzeug die tatsächlich gespeicherten Dateien bestätigt hat. Keine erfundenen Downloads und keine künstlichen Dateianzahl-Limits in der Skill-Anleitung.
