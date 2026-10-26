---
name: project-planning
description: "Für neue Projekte: Ziel, Dateien, Abhängigkeiten und Abnahme vor dem Schreiben klären."
---
# Projektplanung

Leite aus dem Nutzerauftrag ein überprüfbares Ergebnis, eine vollständige Dateiliste und klare Abnahmekriterien ab. Berücksichtige einen bereits gespeicherten, abgeschlossenen Projektplan als verbindliche Grundlage; überschreibe keine privaten Projektversionen. Plane reale Dateien samt Abhängigkeiten und internen Verweisen. Zerlege größere Vorhaben in aufeinanderfolgende Dateischritte, ohne Anforderungen stillschweigend wegzuschneiden. Verwende einen gezielten Abschnittskontext, aber bewahre den vollständigen freigegebenen Plan im persistenten Speicher.

Erzeuge nur tatsächlich erforderliche Quellen. Prüfe jede bestätigte Datei, bevor die nächste abhängig davon erzeugt wird, und verwende bereits verifizierte Quellen erneut, soweit der Runner diese gesichert hat. Melde ausdrücklich, wenn der aktuelle Backend-Runner keine dauerhaften File-Checkpoints bietet oder einen Plan/Manifest-Grenzwert erreicht. Kein Erfolg ohne vollständiges, geprüftes Ergebnis. Der Werkzeug-Kern-Skill gilt zusätzlich; ein Skill allein beseitigt keine Backend-Limits.
