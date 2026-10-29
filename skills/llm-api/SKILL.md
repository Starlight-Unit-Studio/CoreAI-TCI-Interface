---
name: llm-api
description: "LLM-Provider-APIs anbinden."
---
# LLM-Provider-APIs

Ermittle den ausdrücklich gewünschten Anbieter, sein dokumentiertes API-Protokoll und das aktuelle Projekt. Bei CoreAI ist Ollama einschließlich verbundener Cloud-Cores die bestehende Standardroute; ein externer SDK-Skill ändert das nicht. Andere Provider nur auf autorisierten Nutzerauftrag hin integrieren.

Prüfe Modellkennung, Authentifizierung, Rechte, Request- und Response-Schema, Kontextgrenzen, Rate Limits und Streaming-Abbruch. Bei Toolcalls Eingaben und Ergebnisse nach dem tatsächlich implementierten Rollenvertrag weitergeben. Führe keine nicht belegten Parameter oder SDK-Versionen ein.

Tokens, Latenz und Kosten nach vorhandenen Messwerten angeben; keine frei erfundenen Kontingente. Secrets serverseitig halten. In CoreAI bleiben alle Modellaufrufe in der globalen seriellen Warteschlange. Keine Providerverbindung ohne verfügbare Werkzeuge oder tatsächlichen Test als funktionsfähig ausgeben.
