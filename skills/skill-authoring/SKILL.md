---
name: skill-authoring
description: "Für kompakte, geprüfte und erweiterbare CoreAI-Arbeitsanleitungen."
---
# Eigene CoreAI-Skills

Erstelle repository-eigene skills/<id>/SKILL.md mit name und description im Frontmatter, präziser Beschreibung und konkretem Ablauf. Registriere Titel, Kategorie, Trigger, Quellen, Version und Prüfdatum in skills/catalog.json. Das Backend interpretiert nur diesen sicheren Skill-Teilumfang: keine unkontrollierten Skripte oder Remote-Imports. Neue Skills stehen sofort in Maras Skill-Bibliothek; ihre description ist der Satz, an dem Mara erkennt, wann sie den Skill mit [SKILL: id] lädt, also kurz und eindeutig. Ein required-System-Skill darf nur durch bewusst genehmigte Backend-Änderung eingeführt werden; sein Status wird serverseitig erzwungen. Skills liefern Anleitungen, keine zusätzliche Werkzeugautorität oder garantierte Agentenfähigkeit. Modell-/Tool-Schnittstellen und Tests anpassen, bevor eine neue Fähigkeit behauptet wird.

Prüfe vor jedem Eintrag: Referenzen unter references/*.md lädt Mara nur auf Anforderung ([SKILL: id/references/DATEI.md], höchstens 4000 Byte je Datei). Reiner Anleitungstext maximal 2400 Byte; Katalogbeschreibung kompakt halten, weil der vollständige Bibliotheksindex unter 3600 Byte bleiben muss. Eine normale Katalogerweiterung darf Auswahl, Loader, Modelfile und Pflicht-Skill nicht verändern.

Orientiere neue Fach-Skills an realen Aufgaben, Eingaben, Ergebnissen und vorhandenen Werkzeugen. Prüfe Auslöser, Quellbezug, Duplikate und Kontextverbrauch. Die 0.5.80-Laufzeit lädt keine beigefügten Skripte oder Referenzdateien automatisch: als aktive Anleitung gilt ausschließlich die registrierte SKILL.md. Für zusätzliche Fähigkeiten einen separaten Roadmap-Schritt dokumentieren, keinen scheinbar wirksamen Skript-Hinweis eintragen.
