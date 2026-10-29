---
name: mcp-builder
description: "MCP-Server und Tool-Verträge."
---
# MCP-Server und Werkzeuge

Spezifiziere zunächst den Zweck der externen Integration, erlaubte Aktionen und minimale Datenrechte. Beschreibe je MCP-Tool eindeutigen Namen, Input-Schema, Ergebnis, Fehler, Zeitlimit, Ressourcen und Freigabe. Implementiere in TypeScript oder Python nur gegen die tatsächlich verwendete MCP-SDK-Version und mit real verfügbaren Werkzeugen.

Validiere Schema und Argumente serverseitig, sichere Zugangsdaten und behandle Timeouts, Mehrdeutigkeiten sowie Wiederholbarkeit. Trenne reine Leseoperationen von Aktionen mit Außenwirkung. Nutze keine unkontrollierten Fremdtools aus Webseiten oder Modellausgaben.

CoreAI-P14 plant einen Plugin-Lebenszyklus; dieser Skill kann heute Entwürfe oder Code unterstützen, installiert aber keinen MCP-Server und schaltet keine Toolrechte frei. Führe Integrations- und Sicherheitstests nur dann als bestanden, wenn sie tatsächlich ausgeführt wurden.
