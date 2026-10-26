---
name: sse-streaming
description: "Für robuste Ereignisströme, Wiederverbindung und Abschlusszustände."
---
# SSE und Streaming

Sende nur autorisierte, explizit typisierte Server-Sent-Events mit eindeutigen Ereignis- und Run-Bezügen. Verbinde Workevents mit dem echten laufenden Auftrag; Wiederverbindungen dürfen keine zweite Inferenz starten. Zeige live eingehende, geprüfte öffentliche Arbeitsnotizen schrittweise und in tatsächlicher Reihenfolge. Die Anzeigewarteschlange verlangsamt ausschließlich die Präsentation, niemals Datenbank- oder Modellverarbeitung. Quellcodevorschau darf nur reale freigegebene content-Fragmente enthalten, keine thinking-Tokens, Secrets oder Werkzeug-Logs. Bei Abbruch, fehlendem done oder unvollständigem Quelltext keinen Erfolg melden. Gespeicherte Workevents nach Reload ohne doppelte Schritte rekonstruieren; die flüchtige Live-Vorschau nicht mit dauerhaft gesichertem Quellcode verwechseln.
