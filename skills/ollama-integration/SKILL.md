---
name: ollama-integration
description: "Für Ollama-Modellanbindung, Schutz des CoreAI-Modelfiles, Werkzeugprotokoll und sichere Gemma-Parameter."
---
# Ollama und Modellanbindung

Nutze für lokale und Cloud-Modelle denselben vom Nutzer konfigurierten lokalen Ollama-Endpunkt und die dort sichtbare Modellliste. Bewahre das aktive Betreiber-Modelfile mit Mara-Persona, TEMPLATE, RENDERER und PARSER unverändert; vor Änderungen docs/MODELFILE_CHANGE_POLICY.md prüfen. Der E2B-Referenzexport ersetzt nicht das E4B-Modelfile. Für lokale Gemma-Modelle keine zusätzlichen num_ctx- oder num_predict-Overrides; draft_num_predict 0 beibehalten. Modellaufrufe bleiben auch bei mehrstufigen Werkzeugfolgen seriell.

Unterscheide Ollama HTTP-Erfolg von vollständiger message.content-Ausgabe; prüfe done, done_reason und Zähler. Verwende native tool_calls nur, wenn die aktuelle Backend-Route sie ausdrücklich bereitstellt und Ergebnisse in der korrekten Rolle zurückgibt. Sonst das bereits validierte Marker-/Source-Fallback verwenden, ohne eine native Werkzeugschleife zu behaupten. Neue Skill-Anweisungen statt ungefragtem SYSTEM-Tuning. Skills verleihen keine Werkzeugberechtigungen. Fehlende Ausgaben nicht als Provider-Erfolg verkaufen.
