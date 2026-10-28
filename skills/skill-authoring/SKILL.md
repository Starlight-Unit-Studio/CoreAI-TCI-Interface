---
name: skill-authoring
description: "Für kompakte, geprüfte und erweiterbare CoreAI-Arbeitsanleitungen."
---
# Eigene CoreAI-Skills

Erstelle repository-eigene skills/<id>/SKILL.md mit name und description im Frontmatter, präziser Beschreibung und konkretem Ablauf. Registriere Titel, Kategorie, Trigger, Quellen, Version und Prüfdatum in skills/catalog.json. Das Backend interpretiert nur diesen sicheren Skill-Teilumfang: keine unkontrollierten Skripte oder Remote-Imports. Neue Skills stehen sofort in Maras Skill-Bibliothek; ihre description ist der Satz, an dem Mara erkennt, wann sie den Skill mit [SKILL: id] lädt, also kurz und eindeutig. Ein required-System-Skill darf nur durch bewusst genehmigte Backend-Änderung eingeführt werden; sein Status wird serverseitig erzwungen. Skills liefern Anleitungen, keine zusätzliche Werkzeugautorität oder garantierte Agentenfähigkeit. Modell-/Tool-Schnittstellen und Tests anpassen, bevor eine neue Fähigkeit behauptet wird.
