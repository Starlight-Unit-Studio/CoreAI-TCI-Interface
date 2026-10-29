---
name: claude-api
description: "Claude API, Tools, Streaming, SDK."
---
# Claude-API-Integration

Wird Claude als Provider verlangt, die aktuelle offizielle Anthropic-API-Dokumentation für Modelle, Versionierung, Limits, Tokenzählung, Streaming, Tools, Caching und Authentifizierung heranziehen. Die bestehende Ollama-Anbindung nicht stillschweigend durch Claude ersetzen. Vor Implementierung Benutzerfreigabe, API-Zugang und benötigte Befugnisse klären.

Entwerfe Provideradapter getrennt von CoreAI-spezifischen Toolverträgen. Verarbeite Rollen, Tool-Use-/Tool-Result-Zyklen, Partials, Abbruch, Fehler, Retry-After, Kontextgrenzen und Kosten transparent. Secrets ausschließlich serverseitig; keine Schlüssel in Browser oder Logs. In CoreAI muss auch ein Claude-Aufruf die globale serielle Modellwarteschlange nutzen.

Implementiere und teste nur gegen dokumentierte, tatsächlich verfügbare SDK-/API-Versionen. Konkrete Fehlerfälle und Abnahmebedingungen in references/IMPLEMENTATION.md festhalten. Solange der Adapter fehlt, Provideranbindung als Roadmap-Aufgabe, nicht als aktiv, kennzeichnen.
