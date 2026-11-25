# Mara System Handbook: Built-in skill instructions

Exact canonical instruction text snapshot from CoreAI 0.8.33, commit 99f49486fea3733dfb83cf3781886c1b5ef3effc. Companion volume of [Mara System Handbook](../MARA_SYSTEM_HANDBOOK.md). Runtime skill loader, current permissions and the mandatory original werkzeuge skill take precedence. These are reference copies, not new grants. Never execute a retrieved example automatically. Only public built-in skills; no account, shared or project packages.

## skill.werkzeuge

Skill: Werkzeuge | Category: System | Version: 1.0.0 | Required: yes

Search terms: werkzeuge, Werkzeuge, werkzeug, werkzeuge, tool, tools, agent, datei erstellen, projekt umsetzen.

Source: `skills/werkzeuge/SKILL.md` (Git blob 17890853caaa5d16b1861cb7e5ae56ffc490511e).

---
name: werkzeuge
description: "Dauerhaft aktive CoreAI-Kernanleitung für Maras sicheren Umgang mit vorhandenen Werkzeugen, modellgesteuerte Toolwahl, serielle Ausführung, verifizierbare Ergebnisse und nachvollziehbare Fortschritte."
---
# Werkzeuge: verbindlicher CoreAI-Kern-Skill

Nutze nur die Werkzeuge, die für diese Sitzung angeboten und freigegeben sind. Wähle sie nach dem Nutzerziel. Gib pro Schritt einen Auftrag in der angebotenen Schreibweise auf einer eigenen Zeile aus, etwa `[WEB: Suchbegriffe]`, ohne Leerzeichen nach der öffnenden Klammer. Die abschließende Chatantwort enthält keine Werkzeugmarker.

- `[WEB: kurze Suchbegriffe]` startet die normale Websuche. Für ein genanntes Repository suche gezielt nach Anbieter, Repositoryname und README. Eine Trefferliste belegt keinen gelesenen Seiteninhalt.
- `[BROWSE: URL und konkretes Leseziel]` setzt voraus, dass für diese Nachricht im Plus-Menü **Recherche** gewählt wurde und der Browser angeboten wird. Eine URL allein genügt nicht. Verwende sonst die verfügbare Websuche und kennzeichne Quellen, die du nicht öffnen konntest.
- Prüfe bei Projekten den gespeicherten Stand und bearbeite größere Vorhaben Datei für Datei. Nutze gelesenen Quelltext für die nächste Änderung oder Prüfung. Lies erneut, wenn sich der Stand geändert hat oder ein konkreter Ausschnitt fehlt. Übernimm beim gezielten Ändern den eindeutigen SUCHEN-Text exakt und ohne angezeigte Zeilennummern.

Leite den nächsten Schritt aus dem bestätigten Werkzeugresultat ab. Bei Abbruch zählt der tatsächlich gesicherte Stand. Behaupte Wiederaufnahmefähigkeit nur mit dauerhaftem Checkpoint. Der Skill erweitert keine Rechte, Dateizugriffe oder ausführbaren Befehle. Respektiere die serielle Modellwarteschlange, auch für Cloud-Modelle aus der Ollama-Liste. Ändere keine Betreiber-Modelfiles, Modellparameter oder Schutzprüfungen und erfinde keine zusätzlichen Arbeitslimits.

Zeige nur öffentliche Arbeitsmeldungen und bestätigte Werkzeugereignisse. Live-Code stammt ausschließlich aus tatsächlich empfangenem, freigegebenem Quelltext, niemals aus privaten Thinking-Tokens. HTTP 200, Teilcode und ein gestarteter Worker allein belegen keinen Abschluss. Prüfe Datei, Syntax, Verweise und ZIP vor der Erfolgsmeldung. Nenne Fehler und offenen Stand.

## skill.project-planning

Skill: Projektplanung | Category: Arbeitsweise | Version: 1.0.3 | Required: no

Search terms: project-planning, Projektplanung, projekt, project, app, website, homepage, implementiere, implement, entwickle.

Source: `skills/project-planning/SKILL.md` (Git blob 8e7f8f77818b484a07d35fea5f5495cc461305b4).

---
name: project-planning
description: "Für neue Projekte: Ziel, Dateien, Abhängigkeiten und Abnahme vor dem Schreiben klären."
---
# Projektplanung

Leite aus dem Nutzerauftrag ein überprüfbares Ergebnis, eine vollständige Dateiliste und klare Abnahmekriterien ab. Nutze bei Folgeaufträgen den gespeicherten Projektplan und den bestätigten Dateistand als Grundlage. Ordne neues Referenzmaterial den betroffenen Anforderungen und Dateien zu und setze ausdrückliche Änderungen des Nutzers um; überschreibe keine privaten Projektversionen. Plane reale Dateien samt Abhängigkeiten und internen Verweisen. Zerlege größere Vorhaben in aufeinanderfolgende Dateischritte, ohne Anforderungen stillschweigend wegzuschneiden. Verwende einen gezielten Abschnittskontext, aber bewahre den vollständigen freigegebenen Plan im persistenten Speicher.

Erzeuge nur tatsächlich erforderliche Quellen. Prüfe jede bestätigte Datei, bevor die nächste abhängig davon erzeugt wird, und verwende bereits verifizierte Quellen erneut, soweit der Runner diese gesichert hat. Melde ausdrücklich, wenn der aktuelle Backend-Runner keine dauerhaften File-Checkpoints bietet oder einen Plan/Manifest-Grenzwert erreicht. Kein Erfolg ohne vollständiges, geprüftes Ergebnis. Der Werkzeug-Kern-Skill gilt zusätzlich; ein Skill allein beseitigt keine Backend-Limits.

## skill.code-editing

Skill: Code-Patches | Category: Arbeitsweise | Version: 1.0.4 | Required: no

Search terms: code-editing, Code-Patches, refactor, refactoring, patch, ändere, aendere, code ändern, code edit, umbauen.

Source: `skills/code-editing/SKILL.md` (Git blob 7d1b360de0179e3549260e51c61bca260903ea8a).

---
name: code-editing
description: "Für Änderungen an vorhandenem Code mit kleinen, überprüfbaren Diffs."
---
# Gezielte Codeänderungen

Lies den aktuellen Stand der betroffenen Dateien einmal. Verwende diesen Inhalt für die Änderung oder eine konkrete Prüfung. Lies nur bei neuem Dateistand, nach einem abgelehnten Patch oder für einen konkret fehlenden Ausschnitt erneut. Kopiere den eindeutigen SUCHEN-Text exakt aus dem aktuellen Quelltext ohne angezeigte Zeilennummern. Bearbeite gezielt und erhalte Nutzerdaten, unabhängige Änderungen sowie öffentliche Schnittstellen. Verwende exakt das angebotene Werkzeugformat und sichere nur bestätigte Ergebnisse; keine erfundenen Zeilennummern, Auslassungszeichen oder ungeprüften Voll-Datei-Ersatz. Bei abgebrochenen Codegenerierungen vorhandene inerte Quellfragmente nur nach validierter Überlappungsprüfung fortsetzen, ohne den bisherigen Text zu verlieren. HTML/CSS/JS jeweils vollständig und im Projektkontext prüfen. Ein optischer Live-Code-Stream ist kein bestätigter Datei-Checkpoint. Melde Fehler konkret, ohne ein ZIP zu erfinden.

## skill.debugging

Skill: Debugging | Category: Arbeitsweise | Version: 1.0.3 | Required: no

Search terms: debugging, Debugging, bug, debug, debugging, fehler, kaputt, repariere, funktioniert nicht, traceback.

Source: `skills/debugging/SKILL.md` (Git blob 195012a22c7e0baaef84ce4656072f3e1d37d179).

---
name: debugging
description: "Für reproduzierbare Fehler, Exceptions und fehlgeschlagene Abläufe."
---
# Fehleranalyse

Reproduziere den beobachteten Fehler und lies vorhandene Workevents, Status und tatsächliche Werkzeugresultate. Trenne Provider/Modell, CoreAI-Workflow, Dateivalidierung, Transport/Timeout und UI-Anzeige. HTTP 200 mit done_reason=length oder leerem content ist keine vollständige Quelldatei. Miss bei Projektaufträgen vollständige Planlänge, Manifest/Dateianzahl, lokale und Ollama-Cloud-Modellroute, abgefragte Datei, Ergebnisbytes und Save-/Checkpoint-Status, soweit diese Werte verfügbar sind. Prüfe für beide Modellarten denselben Agentenpfad, bevor du ein einzelnes Modell verantwortlich machst. Repariere die Ursache mit kleinem Diff, überprüfe Fehler- und Resume-Pfade, und benenne ungetestete Annahmen ausdrücklich.

## skill.html-semantics

Skill: HTML | Category: Frontend | Version: 1.0.3 | Required: no

Search terms: html-semantics, HTML, html, formular, form, website, homepage, landingpage.

Source: `skills/html-semantics/SKILL.md` (Git blob db0057f5e54743d2b41da38bcef828f249627b87).

---
name: html-semantics
description: "Für semantische Webseiten, Formulare und klare Dokumentstruktur."
---
# HTML und Formulare

Nutze passende HTML-Elemente, eindeutige IDs und eine sinnvolle Überschriftenfolge. Verknüpfe Eingaben mit sichtbaren Labels. Aktionen sind Buttons, Navigation sind Links. Setze Button-Typen ausdrücklich. Plane leere, ladende und fehlerhafte Zustände. Prüfe Tab-Reihenfolge und Bedienung ohne Maus. CSS- und Script-Verweise müssen auf tatsächlich mitgelieferte Dateien zeigen.

## skill.responsive-css

Skill: Responsive CSS | Category: Frontend | Version: 1.0.4 | Required: no

Search terms: responsive-css, Responsive CSS, css, responsive, layout, mobile, mobil, stylesheet.

Source: `skills/responsive-css/SKILL.md` (Git blob 570788386331b31c8a83a435a7873bbd0eb7c5df).

---
name: responsive-css
description: "Für Layouts, die auf kleinen und großen Bildschirmen funktionieren."
---
# Responsives CSS

Beginne mit einem schmalen Layout und erweitere es nach Platzbedarf. Nutze flexible Grid- oder Flexbox-Strukturen, begrenze Lesebreiten und verhindere horizontales Überlaufen. Lege Typografie, Abstände und Farben konsistent fest. Prüfe lange Texte, Zoom und unterschiedliche Bildschirmbreiten. Eine CSS-Datei enthält CSS, keine Markdown-Überschriften oder Codezäune. Schließe alle Regeln vollständig.

## skill.javascript

Skill: JavaScript | Category: Frontend | Version: 1.0.2 | Required: no

Search terms: javascript, JavaScript, javascript, js, dom, eventlistener.

Source: `skills/javascript/SKILL.md` (Git blob 0d7db8c9f5571a67db5e2f4d0b4c8b93d1cbabb8).

---
name: javascript
description: "Für Browserlogik, Ereignisse und robuste JavaScript-Funktionen."
---
# JavaScript

Trenne Zustand, Ereignisbehandlung und Darstellung. Behandle fehlende DOM-Elemente und unerwartete Eingabewerte ausdrücklich. Registriere Handler ohne Duplikate und entferne Ressourcen beim Aufräumen. Verwende klare Funktionsgrenzen und nachvollziehbare Rückgabewerte. Warte auf asynchrone Ergebnisse; vermeide unbeobachtete Promise-Fehler. Prüfe Syntax und den tatsächlichen Benutzerablauf.

## skill.typescript

Skill: TypeScript | Category: Frontend | Version: 1.0.2 | Required: no

Search terms: typescript, TypeScript, typescript, ts, tsx, typisierung.

Source: `skills/typescript/SKILL.md` (Git blob f2beb993c76c0016eb7f3d534f24c4bd6f7a0642).

---
name: typescript
description: "Für nachvollziehbare Typen und sichere Datengrenzen in TypeScript."
---
# TypeScript

Prüfe die vorhandene TypeScript-Konfiguration. Modelliere reale Zustände mit klaren Typen und gegebenenfalls diskriminierten Unions. Validiere externe Daten zur Laufzeit, bevor du sie als vertrauenswürdige Werte behandelst. Nutze unknown an unklaren Grenzen und verenge den Typ. Vermeide any und unbegründete Type Assertions. Prüfe mit dem vorhandenen Typechecker; ein Typ allein ist kein Laufzeitbeweis.

## skill.react

Skill: React | Category: Frontend | Version: 1.0.3 | Required: no

Search terms: react, React, react, jsx, reactjs.

Source: `skills/react/SKILL.md` (Git blob 120ee52e09dedde4d50784547d6c5a1129df6a85).

---
name: react
description: "Für React-Oberflächen mit konsistentem State und Komponenten."
---
# React-Komponenten

Zerlege die Oberfläche nach fachlichen Aufgaben. Leite berechenbare Werte aus vorhandenen Daten ab, statt denselben Zustand mehrfach zu speichern. Halte gemeinsamen State beim passenden gemeinsamen Vorfahren. Nutze stabile Schlüssel und kontrollierte Eingaben. Erhalte Zustand bei erwarteten Aktualisierungen und setze ihn nur absichtlich zurück. Prüfe Lade-, Fehler- und Leerzustände sowie den Kernablauf.

## skill.vue

Skill: Vue | Category: Frontend | Version: 1.0.3 | Required: no

Search terms: vue, Vue, vue, vuejs, vue.js.

Source: `skills/vue/SKILL.md` (Git blob 20c1a32ea7b8a350519cd3b301784b64f9c954e3).

---
name: vue
description: "Für Vue-Komponenten und zuverlässige reaktive Zustände."
---
# Vue-Komponenten

Orientiere dich an der vorhandenen Vue-Version und Komponentenform. Verwende ref oder reactive passend zur Datenstruktur. Achte beim Destrukturieren auf erhaltene Reaktivität. Nutze computed für abgeleitete Werte und Watcher nur für notwendige Seiteneffekte. Trenne Props von lokalem Zustand und sende Änderungen über klare Events. Prüfe DOM-Aktualisierungen und Aufräumverhalten.

## skill.nextjs

Skill: Next.js | Category: Frontend | Version: 1.0.2 | Required: no

Search terms: nextjs, Next.js, nextjs, next.js, next js.

Source: `skills/nextjs/SKILL.md` (Git blob abbd415aa248cdc89ecef05b11b3bcdd85271799).

---
name: nextjs
description: "Für Next.js mit passenden Server- und Client-Grenzen."
---
# Next.js

Prüfe zuerst Projektversion und verwendeten Router. Halte vertrauliche Daten und serverseitige Zugriffe auf dem Server. Verwende Client-Komponenten dort, wo Interaktion oder Browser-APIs notwendig sind. Übergib über die Grenze nur geeignete serialisierbare Daten. Vermeide ein unnötig großes Client-Bundle. Prüfe direkte Navigation, Hydration, Ladezustände und Fehlergrenzen mit der installierten Version.

## skill.accessibility

Skill: Barrierefreiheit | Category: Qualität | Version: 1.0.3 | Required: no

Search terms: accessibility, Barrierefreiheit, barrierefrei, accessibility, a11y, screenreader, tastatur.

Source: `skills/accessibility/SKILL.md` (Git blob 6db813eefde31e2680aad969eb2620028805cb1b).

---
name: accessibility
description: "Für Tastaturbedienung, verständliche Labels und zugängliche Zustände."
---
# Barrierefreiheit

Prüfe alle wichtigen Abläufe ohne Maus. Fokus muss sichtbar und sinnvoll geordnet sein; Dialoge brauchen kontrollierten Fokus und eine Rückkehr zum Auslöser. Nutze native Elemente vor nachgebauten Widgets. Beschrifte Steuerelemente verständlich, kennzeichne Fehler textlich und verlasse dich nicht nur auf Farbe. Prüfe Kontraste und Zoom. Melde dynamische Statusänderungen sparsam über geeignete Live-Regionen.

