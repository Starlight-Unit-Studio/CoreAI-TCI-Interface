---
name: async-jobs
description: "Für kontrollierte Nebenläufigkeit, Abbruch und Hintergrundaufgaben."
---
# Asynchrone Jobs

Trenne Auftragszustand, Modellaufruf, Werkzeugarbeit und Benutzeranzeige. E.M.B.E.R. verarbeitet Modellanfragen global seriell: niemals mehrere Ollama-Inferenzen parallel, auch nicht über Ollama Cloud. Ein langer Projektbau muss in wiederaufnehmbare, möglichst idempotente Dateischritte zerlegt werden, wenn der entsprechende Runner dies unterstützt; verifizierte Quellen dürfen bei Unterbrechungen nicht stillschweigend verworfen werden. Prüfe bei Resume Benutzer-/Session-Besitz, gespeicherten Zustand, Dateihash und nächste fehlende Datei. Modell-Limits und reale Ressourcenbudgets unterscheiden; endliche Anzeige- und Serverzeiten dürfen keinen fertigen Erfolg vortäuschen. Abbruchsignale weiterleiten und fehlgeschlagen, abgebrochen, unvollständig und abgeschlossen strikt trennen.
