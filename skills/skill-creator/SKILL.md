---
name: skill-creator
description: "Skills planen, validieren, paketieren."
---
# Skill-Creator

Erstelle aus Nutzerauftrag und konkreten Eingabe-/Ausgabebeispielen ein wiederverwendbares Skill-Paket: Kennung, prägnante Triggerbeschreibung, Ablauf, Voraussetzungen, erwartetes Ergebnis und Abnahmefälle. Plane Referenzdokumente, Skripte und Assets nur, wenn sie zur Ausführung beitragen. Bei bestehenden Skills Revisionen und Abhängigkeiten prüfen statt Duplikate zu erzeugen.

Im aktuellen CoreAI nur skills/<id>/SKILL.md plus katalogisierte Metadaten als aktive Anleitung behandeln. Body bis 2400 Byte, Gesamtindex innerhalb des Runtimebudgets, genau ein Pflicht-Skill werkzeuge. Referenzen und Skripte zusätzlich vorbereiten, aber ihre automatische Ausführung oder das dynamische Nachladen nicht vortäuschen.

Validiere Frontmatter, Pfade, Katalog, Auswahltrigger, Quellangaben und negative Testfälle; prüfe Sicherheitsgrenzen und Lizenzlage. Bei ausführbaren Skripten echte Testausführung verlangen. Nach erfolgreicher Erstellung Version und Rollback-Möglichkeit dokumentieren. Für spätere P12/P13-Features vollständiges Paketformat, Import-/Export- und Regressionstests in references/IMPLEMENTATION.md spezifizieren.
