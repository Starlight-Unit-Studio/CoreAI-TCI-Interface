# Claude/Anthropic API: Vollausbau

Fachliche Vorgaben für spätere CoreAI-Tools; der aktuelle Skill-Loader wird nicht verändert und lädt weiterhin nur SKILL.md. Die hier genannten APIs/Skripte sind keine bereits aktivierten Berechtigungen.

## Vollständiges Ziel
Anthropic Messages API, Provider-Modelle, Auth, Versionen, Tool-Use/Tool-Result, Tokenzählung, Streaming, Prompt-Caching und kompatibles Thinking anhand aktueller Dokumentation aufbauen. Rate Limits, Kosten, Timeout, Abbruch und Retry-After behandeln. Claude darf Ollama nicht ungefragt ersetzen.

## Workflow
1. Auftrag, Anbieter/Format, Versionsstand, erlaubte Daten und geforderten Output festlegen.
2. Schnittstellen und Vorbedingungen definieren, erwartete positive und negative Werkzeugresultate modellieren.
3. Geeignete SDKs/Bibliotheken oder Runner gezielt anbinden, ohne fremden Code unkontrolliert auszuführen.
4. Ein- und Ausgabe gegen Schemata und den tatsächlichen Provider-/Dateivertrag prüfen.
5. Fehler, Rechte, Abbruch und Wiederholbarkeit testen; echte Arbeitsschritte protokollieren.
6. Bestätigtes Ergebnis liefern und fehlende Backend-Teile in der Roadmap als offen erhalten.

## Erforderliche Tool-Klassen
Expliziter Anthropic-Provideradapter, sichere Secrets-Konfiguration, Modell-/Pricing-Metadaten, SSE-Parser, Tool-Schema, globale serielle Modellqueue und Diagnoselog.

## Abnahme
Antworten, Tokenzählung, Tool-Runden und Fehler real getestet; ein kaputter Key wird erkannt; alle Provideraufrufe seriell; keine ungeprüften Limits oder Preise.

- [ ] Negativfälle und Rechteprüfung reproduzierbar
- [ ] Persistenz und ggf. Restart/Resume nachgewiesen
- [ ] Kein Einfluss auf Modelfile, verpflichtenden Core-Skill und globale Queue

## Grenzen
Entwurf und Anweisungen sind sofort nutzbar. Noch nicht gebaute Runtime-Funktionen gelten bis zur realen Implementierung und Live-Abnahme als geplant, nicht als ausgeliefert.
