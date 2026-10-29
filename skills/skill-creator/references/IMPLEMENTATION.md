# Erstellung und Verwaltung kompletter Skills: Vollausbau

Fachliche Vorgaben für spätere CoreAI-Tools; Mara lädt diese Referenz nur bei Bedarf ([SKILL: skill-creator/references/IMPLEMENTATION.md]). Die hier genannten APIs/Skripte sind keine bereits aktivierten Berechtigungen.

## Vollständiges Ziel
Aus Aufgabenbeispielen Skillziel, Input/Output, Trigger und Zielwerkzeuge erheben; eigene SKILL.md plus optionale Skripte, Referenzen, Vorlagen und Assets planen; Tests aus echten Beispielprompts; Versionen, Diffs, Konflikte, Rechte, Import/Export und Rollback aus P12/P13 vorbereiten.

## Workflow
1. Auftrag, Anbieter/Format, Versionsstand, erlaubte Daten und geforderten Output festlegen.
2. Schnittstellen und Vorbedingungen definieren, erwartete positive und negative Werkzeugresultate modellieren.
3. Geeignete SDKs/Bibliotheken oder Runner gezielt anbinden, ohne fremden Code unkontrolliert auszuführen.
4. Ein- und Ausgabe gegen Schemata und den tatsächlichen Provider-/Dateivertrag prüfen.
5. Fehler, Rechte, Abbruch und Wiederholbarkeit testen; echte Arbeitsschritte protokollieren.
6. Bestätigtes Ergebnis liefern und fehlende Backend-Teile in der Roadmap als offen erhalten.

## Erforderliche Tool-Klassen
Skill-Dateiverwaltung, Katalogprüfung, Script-Sandbox, Versionierungs-/Archivsystem, Testharness für Trigger und reale Werkzeugausführung. Der Ressourcenlader für references/*.md besteht seit 0.5.82 (nur auf Anforderung).

## Abnahme
Paket nach Test gültig; Ressourcen sind vorhanden; doppelte Kennungen werden abgewiesen; Import/Export reproduziert Stand; frühere Version kann kontrolliert wiederhergestellt werden.

- [ ] Negativfälle und Rechteprüfung reproduzierbar
- [ ] Persistenz und ggf. Restart/Resume nachgewiesen
- [ ] Kein Einfluss auf Modelfile, verpflichtenden Core-Skill und globale Queue

## Grenzen
Entwurf und Anweisungen sind sofort nutzbar. Noch nicht gebaute Runtime-Funktionen gelten bis zur realen Implementierung und Live-Abnahme als geplant, nicht als ausgeliefert.
