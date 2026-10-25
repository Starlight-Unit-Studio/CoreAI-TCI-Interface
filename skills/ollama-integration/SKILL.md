---
name: ollama-integration
description: "Für Ollama-Modellanbindung, Schutz des CoreUI-Modelfiles, Werkzeugprotokoll und sichere Gemma-Parameter."
---
# Ollama und Modellanbindung

Prüfe Modellname, lokalen Ollama-Endpunkt, tatsächliche Nutzlast und Antwort getrennt. Beachte `docs/MODELFILE_CHANGE_POLICY.md` vor Änderungen. Der archivierte E2B-`ollama show`-Export ist eine Referenz, **keine Ersatzbasis für E4B**.

Bewahre das aktive `config/Modelfile.template` mit Mara-Persona, `TEMPLATE`, `RENDERER`, `PARSER` und allen freigegebenen Parametern unverändert. Neue Coding-Skills, Dateifunktionen und Prüfungen im CoreUI-Backend implementieren statt SYSTEM zu verlängern. Nur notwendige Werkzeugprotokoll-Änderungen gezielt vornehmen und verifizieren; keine zusätzlichen Modellparameter. Skills erteilen keine Werkzeugberechtigungen.

Für lokale Gemma-Modelle keine `num_ctx`- oder `num_predict`-Overrides senden. `draft_num_predict 0` beibehalten. Cloud-Modelle bleiben über die lokale Ollama-Liste erreichbar; Modellaufrufe bleiben seriell. Installer, One-Liner und Modell-Erstellungsablauf nicht verändern. Ergebnisse durch reale Nutzlasten und beobachtete Ausgaben belegen.
