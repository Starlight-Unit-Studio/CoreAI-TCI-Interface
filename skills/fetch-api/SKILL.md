---
name: fetch-api
description: "Für HTTP-Aufrufe mit expliziten Fehlern und Abbruchverhalten."
---
# HTTP-Client und Fetch

Prüfe HTTP-Status und erwarteten Inhaltstyp, bevor du die Antwort verwendest. Netzwerkfehler und fachliche Fehler sind unterschiedliche Zustände. Plane Zeitlimits beziehungsweise AbortController und bereinige abgebrochene Requests. Verhindere, dass alte Antworten einen neueren Zustand überschreiben. Wiederhole schreibende Aufrufe nur bei geklärter Idempotenz. Sende Cookies und Zugangsdaten nur an vorgesehene Ziele.
