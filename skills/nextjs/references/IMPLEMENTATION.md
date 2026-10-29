# Next.js: vollständige Zielarchitektur

## Grundlage
Zuerst `package.json`, Lockfile, Routerstruktur und Deploymentmodus prüfen. App Router, Pages Router, Edge und Node unterscheiden. Für versionsabhängige Details Primärdokumentation https://nextjs.org/docs und vorhandene Vercel-Referenzen verwenden. Ein nicht vorhandenes Framework-Feature niemals behaupten.

## Ablauf
1. Installierte Version, Routing, Runtime, Caching, Auth, Umgebungsvariablen und Assetpipeline inventarisieren.
2. Datenfluss und Vertrauensgrenzen darstellen: Request -> Auth -> Server Data -> Serialisierung -> Client UI -> Mutation.
3. Für jede Route getrennt entscheiden, ob sie statisch/dynamisch sein muss. Cache-, Revalidierungs- und Fetch-Semantik von der wirklich installierten Version ableiten.
4. Server Actions/Route Handlers nach den gleichen Sicherheitsregeln wie externe API-Endpunkte prüfen: Rollen, Resource-Ownership, Schema, CSRF/Origin, Rate Limits und Fehler.
5. Unabhängige, nicht modellbasierte Requests gezielt parallelisieren. Zentrale CoreAI-Modellqueue bleibt seriell.
6. Client-Bundle klein halten; Imports und schwere dynamische Widgets messen. Keine unmotivierte globale Client-Komponente.
7. SEO-Metadaten, Links, Hydration, direkte Navigationspfade, Fehler, Ladezustände und Mobile-Verhalten verifizieren.

## Abnahme
- [ ] Build mit passender Next.js-Version, kein Hydration-/SSR-Fehler
- [ ] Nicht autorisierte Mutation wird serverseitig verweigert
- [ ] Secrets weder in Client-Bundle noch in Netzwerklogs offengelegt
- [ ] Cache zeigt unter Nutzerwechsel keine Daten anderer Konten
- [ ] Direktnavigation, Redirects und Error-/Loading-Grenzen funktionieren
- [ ] Bei Caching-/Performanceänderungen reale Vorher-/Nachher-Messung

## Benötigte Toolklassen
Projektdateien/Versionen, Node-Paketmanager, isolierter Preview-Server, Lint/Typecheck, autorisierte HTTP- und Browser-Tests, Bundle-Analyse, Sicherheits- und Mobile-Viewports, optional Deployment-Logs. `references/IMPLEMENTATION.md` ist heute kein automatisch geladener CoreAI-Kontext, sondern die vollständig vorbereitete Erweiterung.
