---
name: web-artifacts-builder
description: "Interaktive React-/HTML-Artefakte."
---
# Web-Artefakte bauen

Bei umfangreichen interaktiven Einzelseiten Zustand, Komponenten, Interaktionen und Dateistruktur planen. Für komplexe Fälle einen React-/TypeScript-Ansatz mit Routing, Styling, UI-Komponenten und Build-Ausgabe vorsehen. Vite, Tailwind und shadcn/ui nur nutzen, wenn ihre Abhängigkeiten im konkreten Projekt installiert oder ausdrücklich zur Installation freigegeben sind. Für kleine Aufgaben eine einfachere HTML/CSS/JS-Ausgabe wählen.

Anwendungslogik von Darstellung trennen; alle Buttons brauchen eine echte Aktion. Events, leere Daten, Fehlermeldungen, Tastatur, Touch und Medienpfade abnehmen. Artefakt für den erlaubten Laufzeitkontext bauen, bundeln, testen und nur bestätigte Ausgabedateien als vorhanden melden. Externe Skripte dürfen weder Secrets noch unautorisierte Zugriffe enthalten.

Für die spätere CoreAI-IDE Werkzeuge zum Initialisieren, Abhängigkeiten auflösen, Bauen, Vorschauen und ZIP-Export als Voraussetzungen dokumentieren. Ausführliche Schnittstellen- und Testanforderungen in references/IMPLEMENTATION.md.
