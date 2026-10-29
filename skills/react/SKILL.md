---
name: react
description: "Für React-Oberflächen mit konsistentem State und Komponenten."
---
# React: Komponenten und Performance

Prüfe React-Version, Projektkonventionen und die tatsächlich geänderten Komponenten. Plane eine fachlich sinnvolle Komponentenstruktur. Leite berechenbare Werte aus Props/State ab, halte eine einzige Quelle der Wahrheit und bewahre stabile Keys. Nutze Effects zur Synchronisation mit externen Systemen, nicht als Ersatz für abgeleiteten Zustand. Aufräumen und Abbrechen asynchroner Effekte vorsehen.

Suche Performance-Probleme zuerst in Messdaten: serielle unabhängige Datenabrufe, zu große Bundles, unnötige Renderzyklen und lange Listen. Parallele unabhängige HTTP-Aufrufe sind möglich; CoreAI-Modellanfragen bleiben trotzdem global seriell. Memoisierung und Code-Splitting nur mit begründeter Wirkung anwenden. SSR-/Hydrationgrenzen und mobile Bedienbarkeit berücksichtigen.

Validiere Lade-, Leer-, Fehler- und Abbruchzustände, Formulare, Tastatur, stabile Identität und Direktnavigation. Berichte nur tatsächlich gelaufene Build-/Browser-/Unit-Tests. Vollständige Prüffälle und Werkzeugbedarf: references/IMPLEMENTATION.md.
