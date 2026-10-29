# React: vollständiger Entwicklungs- und Prüfablauf

## Zweck
React-Komponenten neu erstellen, ändern, untersuchen und optimieren. Die Anleitung gilt für React selbst und nur mit nachgewiesener Next.js-/Frameworkversion für besondere Server-/Clientregeln. Fachliche Grundlage: https://react.dev/learn und Performancehinweise aus https://github.com/vercel-labs/agent-skills/tree/main/skills/react-best-practices (Funktionsvorbild, keine Textübernahme).

## Eingabe und Ziel
- Repository, Paket-/React-Version, betroffene Route/Komponente, beobachteter Fehler oder gewünschte Funktion
- Konkretes Verhalten, Datenfluss, benötigte Rechte, Responsive-Breakpoints und Erfolgsdefinition
- Vorhandene Tests und Browser-/Profilingresultate, sofern wirklich zugänglich

## Produktionsworkflow
1. Quellcode, Router, React-/Bundlerversion und die betroffenen Komponenten auslesen. Nicht auf veraltete React-Regeln vertrauen.
2. State-Quelle und Datenabhängigkeiten kartieren: Props, lokaler State, Context, Cache, asynchroner Fetch und Side Effects.
3. Komponenten fachlich trennen, Derived State direkt berechnen, stabile Keys setzen und Eventhandler statt unpassender Effects verwenden.
4. Netzwerkabrufe auf echte Abhängigkeiten prüfen. Unabhängige externe Requests dürfen parallel sein, Modellaufrufe in CoreAI nicht.
5. Performance mit Profiler/Bundlereport messen: Wasserfälle, Re-Renders, große Importpfade, teure Listen, unerwartete Resets und Hydration.
6. Nur nach Messung memo-/lazy-/virtualisierungsbasierte Optimierungen einführen; übersichtlichen kleinen Diff bevorzugen.
7. Unit-, Komponenten-, Browser- und mobile Zugänglichkeitstests durchführen. Fehlende Runner klar als offene Abnahme führen.

## Testfälle
- [ ] Kontrollierte Eingabe mit gültigem und ungültigem Wert
- [ ] Liste mit stabilen Identitäten bei Sortierung/Filterung
- [ ] Datenabruf mit Abbruch, Timeout, leerem Ergebnis und Fehler
- [ ] Direktaufruf nach Refresh sowie SSR/Hydration, sofern Projekt SSR nutzt
- [ ] Tastatur/Fokus und schmale Android-Ansicht überprüft
- [ ] Baseline und gemessene Verbesserung bei Performanceoptimierung angegeben

## Werkzeuge und Abhängigkeiten
GitHub-/Workspace-Editor, Node/Package-Manager, TypeScript-/Lint- und Testtool, React DevTools/Profiler, Bundle-Analyzer, Browser-Renderer sowie Screenshots für Mobile. Keine unautorisierte Paketinstallation oder Dienstveröffentlichung. Das unveränderte CoreAI-Skill-System lädt diese Referenzdatei noch nicht automatisch; sie dient Claude-kompatibel als zusätzliche fachliche Information und als Tool-Roadmap.
