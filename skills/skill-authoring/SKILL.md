---
name: skill-authoring
description: "Für kompakte, geprüfte und erweiterbare CoreAI-Arbeitsanleitungen."
---
# Eigene CoreAI-Skills

Erstelle repository-eigene skills/<id>/SKILL.md mit name und description im Frontmatter, präziser Beschreibung und konkretem Ablauf. Registriere Titel, Kategorie, Trigger, Quellen, Version und Prüfdatum in skills/catalog.json. Das Backend interpretiert nur diesen sicheren Skill-Teilumfang: keine unkontrollierten Skripte oder Remote-Imports. Neue optionale Skills bleiben pro Konto ausgeschaltet. Ein required-System-Skill darf nur durch bewusst genehmigte Backend-Änderung eingeführt werden; sein Status muss serverseitig erzwungen und in Einstellungen sichtbar, aber unveränderbar sein. Skills liefern Anleitungen, keine zusätzliche Werkzeugautorität oder garantierte Agentenfähigkeit. Modell-/Tool-Schnittstellen und Tests anpassen, bevor eine neue Fähigkeit behauptet wird.
