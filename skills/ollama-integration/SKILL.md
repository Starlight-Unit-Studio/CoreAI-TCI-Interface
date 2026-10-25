---
name: ollama-integration
description: "Für CoreUI-Modellanbindung unter Erhalt der projektspezifischen Parameter."
---
# Ollama und Modellanbindung

Prüfe Modellname, lokalen Ollama-Endpunkt, tatsächliche Nutzlast und Antwort getrennt. Skills sind Anweisungen, keine neuen Modellfähigkeiten. Überschreibe das Modelfile-SYSTEM nicht mit Skilltext. Für Gemma darf CoreUI keinen num_ctx-Override senden. Entferne keine bestätigten Modelfile-Parameter. Cloud-Modelle bleiben in diesem Projekt über die lokale Ollama-Modellauswahl erreichbar. Modellaufrufe bleiben seriell. Belege Änderungen mit Nutzlast und beobachtetem Ergebnis.
