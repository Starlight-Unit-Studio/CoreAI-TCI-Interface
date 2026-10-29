# MCP-Tool- und Server-Entwicklung: Vollausbau

Fachliche Vorgaben für spätere CoreAI-Tools; der aktuelle Skill-Loader wird nicht verändert und lädt weiterhin nur SKILL.md. Die hier genannten APIs/Skripte sind keine bereits aktivierten Berechtigungen.

## Vollständiges Ziel
MCP-Server mit Tools, Resources und Prompts planen; JSON-Schemas für Inputs/Outputs, klare Toolnamen, Auth und Rechte, stdio/Streamable-HTTP-Transport, Status, Timeout, Cancellation und Versionierung definieren. Client-/Serververhalten getrennt testen. OAuth oder andere Anmeldungen ausschließlich mit freigegebenem Kontokontext.

## Workflow
1. Auftrag, Anbieter/Format, Versionsstand, erlaubte Daten und geforderten Output festlegen.
2. Schnittstellen und Vorbedingungen definieren, erwartete positive und negative Werkzeugresultate modellieren.
3. Geeignete SDKs/Bibliotheken oder Runner gezielt anbinden, ohne fremden Code unkontrolliert auszuführen.
4. Ein- und Ausgabe gegen Schemata und den tatsächlichen Provider-/Dateivertrag prüfen.
5. Fehler, Rechte, Abbruch und Wiederholbarkeit testen; echte Arbeitsschritte protokollieren.
6. Bestätigtes Ergebnis liefern und fehlende Backend-Teile in der Roadmap als offen erhalten.

## Erforderliche Tool-Klassen
MCP-SDK nach passender Version, Serverlaufzeit, Clientadapter im CoreAI-Toolregister, Sandbox, Credential-Speicher, Toolrechte, Plugin-Lifecycle aus P14 und Testharness.

## Abnahme
Client listet und ruft echte Tools; fehlerhafte Schemata und Rechte werden abgewiesen; Verbindungsabbruch sauber gemeldet; kein Secret-Leak und keine ungeprüfte Tool-Ausführung.

- [ ] Negativfälle und Rechteprüfung reproduzierbar
- [ ] Persistenz und ggf. Restart/Resume nachgewiesen
- [ ] Kein Einfluss auf Modelfile, verpflichtenden Core-Skill und globale Queue

## Grenzen
Entwurf und Anweisungen sind sofort nutzbar. Noch nicht gebaute Runtime-Funktionen gelten bis zur realen Implementierung und Live-Abnahme als geplant, nicht als ausgeliefert.
