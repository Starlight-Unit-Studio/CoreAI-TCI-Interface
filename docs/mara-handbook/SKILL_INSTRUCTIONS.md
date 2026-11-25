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

## skill.assets

Skill: Assets | Category: Frontend | Version: 1.0.4 | Required: no

Search terms: assets, Assets, bilder, images, assets, grafiken, image, bilddateien.

Source: `skills/assets/SKILL.md` (Git blob 25ceebb6b985b577d27f416ea0fac34a18b615ec).

---
name: assets
description: "Für vollständige Bildressourcen und funktionierende lokale Verweise."
---
# Bilder und Projektressourcen

Erstelle ein Inventar aller referenzierten Bilder, Icons, Fonts und Styles. Verknüpfe nur Dateien, die tatsächlich geliefert werden oder als externe Abhängigkeit ausdrücklich vorgesehen sind. Erfinde keine Bilddateien. Nutze bei fehlenden Bildern eine ehrliche, funktionsfähige Alternative im Projekt. Lege sinnvolle Bildabmessungen und Alternativtexte fest. Prüfe Pfade einschließlich Groß-/Kleinschreibung im fertigen Paket.

Kontrolliere ebenso relative Verweise auf Fonts, CSS und JavaScript im exportierten Projekt. Unklare Bildrechte und nicht vorhandene Mediendateien als offene Abhängigkeiten kennzeichnen statt unsichtbar zu ersetzen.

## skill.fetch-api

Skill: Fetch API | Category: Backend | Version: 1.0.3 | Required: no

Search terms: fetch-api, Fetch API, fetch, http client, api aufrufen, rest client, netzwerkfehler.

Source: `skills/fetch-api/SKILL.md` (Git blob 603607a22f13732570fa04848d8900dcb460aa72).

---
name: fetch-api
description: "Für HTTP-Aufrufe mit expliziten Fehlern und Abbruchverhalten."
---
# HTTP-Client und Fetch

Prüfe HTTP-Status und erwarteten Inhaltstyp, bevor du die Antwort verwendest. Netzwerkfehler und fachliche Fehler sind unterschiedliche Zustände. Plane Zeitlimits beziehungsweise AbortController und bereinige abgebrochene Requests. Verhindere, dass alte Antworten einen neueren Zustand überschreiben. Wiederhole schreibende Aufrufe nur bei geklärter Idempotenz. Sende Cookies und Zugangsdaten nur an vorgesehene Ziele.

## skill.rest-api

Skill: REST API | Category: Backend | Version: 1.0.4 | Required: no

Search terms: rest-api, REST API, rest, endpoint, endpunkt, api design, http status.

Source: `skills/rest-api/SKILL.md` (Git blob 7e91fbbafbc157f36d434dec514296b29f073f7c).

---
name: rest-api
description: "Für konsistente API-Verträge und nachvollziehbare Fehlerantworten."
---
# REST-Endpunkte

Definiere Methode, Eingabeschema, Berechtigungen, Ausgabe und Fehlerfälle pro Endpunkt. Prüfe Eingaben vor Nebenwirkungen. Wähle HTTP-Status passend zum Ergebnis und liefere konsistente maschinenlesbare Fehler. Trenne unauthentifizierte von unberechtigten Zugriffen. Begrenze Listen und Nutzlasten. Dokumentiere Idempotenz und teste ungültige Eingaben sowie nicht vorhandene Ressourcen.

## skill.php-pdo

Skill: PHP/PDO | Category: Backend | Version: 1.0.2 | Required: no

Search terms: php-pdo, PHP/PDO, php, pdo, mariadb.

Source: `skills/php-pdo/SKILL.md` (Git blob 9ddb76165591b0108c933a761d0fa554882a669d).

---
name: php-pdo
description: "Für PHP-Backends mit vorbereiteten SQL-Anweisungen."
---
# PHP und PDO

Prüfe die eingesetzte PHP-Version. Nutze PDO-Parameter für Werte; Spalten- und Tabellennamen müssen aus einer festen Liste stammen. Definiere Typen und behandle Fehler an sinnvollen Grenzen. Mehrere zusammengehörige Schreibvorgänge benötigen eine Transaktion und Rollback bei Fehlern. Liefere keine internen SQL-Fehler an Clients. Prüfe Syntax und mindestens den betroffenen Datenfluss.

## skill.python

Skill: Python | Category: Backend | Version: 1.0.4 | Required: no

Search terms: python, Python, python, py, script, skript.

Source: `skills/python/SKILL.md` (Git blob 84f131ecdd41d806e911a528b386405b047742d7).

---
name: python
description: "Für wartbare Python-Skripte und gezielte Fehlerbehandlung."
---
# Python-Programme

Trenne Ein-/Ausgabe von der eigentlichen Logik. Nutze Kontextmanager für Dateien und explizite Zeichencodierung. Validiere Eingaben, bevor Seiteneffekte beginnen. Fange nur erwartete Exceptions und ergänze verständlichen Kontext. Prüfe Syntax, den normalen Ablauf und relevante Randfälle. Nutze die tatsächlich vorhandenen Bibliotheken; installiere oder importiere keine erfundenen Pakete.

## skill.sql-security

Skill: SQL-Schutz | Category: Sicherheit | Version: 1.0.2 | Required: no

Search terms: sql-security, SQL-Schutz, sql, sql injection, datenbank, database.

Source: `skills/sql-security/SKILL.md` (Git blob da950dcd3c7bbba118bc4629337eee5c5d2430af).

---
name: sql-security
description: "Für sichere Datenbankabfragen und begrenzte Datenzugriffe."
---
# SQL-Sicherheit

Binde Werte über Parameter. Für dynamische Identifikatoren und Sortierrichtungen verwende eine Allowlist. Prüfe Eigentümerschaft direkt in der Datenbankabfrage, wenn Daten einem Konto gehören. Gib dem Datenbankkonto nur notwendige Rechte. Escape-Funktionen ersetzen keine saubere Abfragestruktur. Prüfe manipulierte Werte und Zugriffe auf fremde IDs.

## skill.xss-prevention

Skill: XSS-Schutz | Category: Sicherheit | Version: 1.0.2 | Required: no

Search terms: xss-prevention, XSS-Schutz, xss, innerhtml, html injection, sanitizer.

Source: `skills/xss-prevention/SKILL.md` (Git blob c4293db14c4263c2b42dff5b6740e723126bbecc).

---
name: xss-prevention
description: "Für sichere Darstellung fremder Texte und erlaubter HTML-Inhalte."
---
# XSS-Schutz

Behandle externe Texte als Daten. Nutze textContent für Textausgabe und kontextgerechte Kodierung an anderen Ausgabestellen. HTML darf nur nach einer passenden, gepflegten Sanitizer-Policy dargestellt werden. Prüfe URL-Schemata separat. Vermeide String-Zusammenbau für Script- oder Eventhandler-Kontexte. Teste gefährliche Eingaben in genau dem Kontext, in dem sie erscheinen.

## skill.authorization

Skill: Zugriffsrechte | Category: Sicherheit | Version: 1.0.3 | Required: no

Search terms: authorization, Zugriffsrechte, authorization, berechtigung, auth, login, konten, user isolation.

Source: `skills/authorization/SKILL.md` (Git blob 7aee0dc65856cb937709f3181242262c1901fef0).

---
name: authorization
description: "Für serverseitige Rechteprüfung und strikte Trennung von Konten."
---
# Konten und Zugriffsrechte

Leite Identität aus der geprüften Sitzung ab, niemals aus frei übermittelten Nutzer-IDs. Prüfe Berechtigungen bei jedem Zugriff auf ein Objekt, auch Downloads und Hintergrundjobs. Verweigere unbekannte Fälle standardmäßig. Ein ausgeblendeter Button ist keine Zugriffskontrolle. Teste mindestens Besitzer, fremdes Konto und ausgeloggten Zugriff. Rechte dürfen nicht durch neue URLs oder alternative Methoden umgangen werden.

## skill.browser-testing

Skill: Browsertests | Category: Qualität | Version: 1.0.4 | Required: no

Search terms: browser-testing, Browsertests, playwright, browser test, e2e, end-to-end.

Source: `skills/browser-testing/SKILL.md` (Git blob fee1b7d003df7b2aab6036d28e384bd2741ad2ba).

---
name: browser-testing
description: "Für reproduzierbare Tests aus Sicht der Benutzer."
---
# Browser-Tests

Teste sichtbares Verhalten mit benannten Rollen und Labels. Isoliere Sitzungen und Daten zwischen Tests. Warte auf erwartete Zustände statt auf feste Schlafzeiten. Prüfe Erfolgs-, Fehler- und Leerzustände sowie einen schmalen Viewport. Halte Testdaten kontrolliert und unabhängig von fremden Diensten. Berichte nur tatsächlich gelaufene Tests samt Ergebnis.

Prüfe besonders schmale Android-Viewports, Textumbruch, überlange Dialoge, Scrollbereiche und Download-/Reload-Fälle. Unterscheide einen simulierten Browser-Viewport vom echten Android-Livetest; nur tatsächlich ausgeführte Prüfungen als bestanden melden.

Für Webapp-Tests mit Playwright zuerst prüfen, ob der Browser-Runner tatsächlich verfügbar ist. Hauptpfade und Grenzfälle samt sichtbaren Ergebnissen prüfen; bei Fehlern Screenshot/Trace nur aus real ausgeführten Tests nennen. Ohne Browser-Runner verfügbare Projektprüfungen ausführen und Browserfälle für die offene Abnahme dokumentieren. Erkläre, welche Prüfungen tatsächlich gelaufen sind.

## skill.unit-testing

Skill: Unit-Tests | Category: Qualität | Version: 1.0.3 | Required: no

Search terms: unit-testing, Unit-Tests, pytest, unittest, unit test, regression, tests.

Source: `skills/unit-testing/SKILL.md` (Git blob de4356897827e3bbee6396a113dab860861ea1be).

---
name: unit-testing
description: "Für kleine, unabhängige Tests an relevanten Verhaltensgrenzen."
---
# Unit- und Regressionstests

Wähle Fälle anhand des Risikos: normale Eingabe, Randfall und erwarteter Fehler. Erzeuge frische Testdaten und räume Ressourcen zuverlässig auf. Teile Fixtures nur, wenn ihr Zustand und ihre Lebensdauer klar sind. Ein Test soll Verhalten prüfen, nicht den Produktionscode nachbauen. Bei einem Bug muss der Regressionstest das ursprüngliche Problem erkennen. Dokumentiere nicht verfügbare Testumgebungen.

## skill.docker

Skill: Docker | Category: Betrieb | Version: 1.0.2 | Required: no

Search terms: docker, Docker, docker, dockerfile, container, compose.

Source: `skills/docker/SKILL.md` (Git blob c25618e43efe72ab554aa250bae67164f5aafcaa).

---
name: docker
description: "Für reproduzierbare Container mit begrenzten Abhängigkeiten."
---
# Docker und Container

Prüfe Basisimage und unterstützte Laufzeitversion. Nutze mehrstufige Builds, wenn Build-Werkzeuge nicht ins Laufzeitimage gehören. Halte den Build-Kontext über dockerignore klein. Kopiere keine Zugangsdaten ins Image. Nutze soweit möglich einen unprivilegierten Laufzeitnutzer und getrennte persistente Daten. Dokumentiere Ports, Volumes und Startvoraussetzungen. Ein erfolgreicher Build ersetzt keinen Starttest.

## skill.git-review

Skill: Git-Review | Category: Arbeitsweise | Version: 1.0.3 | Required: no

Search terms: git-review, Git-Review, git, diff, pull request, commit, review, merge.

Source: `skills/git-review/SKILL.md` (Git blob 8c1c38bd40ef5864533aec43685d60eae5c2047d).

---
name: git-review
description: "Für nachvollziehbare Änderungen und gezielte Prüfung vor Veröffentlichung."
---
# Git und Code-Review

Prüfe Status und Diff, einschließlich neuer Dateien, bevor du einen Commit vorbereitest. Erhalte unabhängige Änderungen. Kontrolliere besonders Löschungen, Konfiguration und versehentlich enthaltene Geheimnisse. Beschreibe Problem, Änderung und tatsächliche Tests. Veröffentliche nur den beauftragten Umfang. Nach einem Merge dieses CoreUI-Projekts ist ein neues TXT-Übergabeprotokoll mit Commit, Prüfungen und offenen Punkten erforderlich.

## skill.zip-delivery

Skill: ZIP-Pakete | Category: Dateien | Version: 1.0.3 | Required: no

Search terms: zip-delivery, ZIP-Pakete, zip, download, archiv, archive, dateipaket.

Source: `skills/zip-delivery/SKILL.md` (Git blob 435df2be03adca57da2615f3ca814a39ad02cda4).

---
name: zip-delivery
description: "Für vollständige Archive mit überprüften Dateien und Verweisen."
---
# ZIP und Dateiauslieferung

Erzeuge und prüfe jede angeforderte Quelldatei, bevor du das Archiv zusammenstellst. Bewahre bei längeren Builds bestätigte Teilergebnisse und ihre eindeutigen Dateinamen; wo das Backend noch keine dauerhaften Checkpoints unterstützt, benenne diese Einschränkung. Keine vollständige zehnseitige Homepage aus nur drei Ersatzdateien behaupten. Sichere relative Pfade ohne Traversal, Symlinks oder versteckte Host-Ausführung. Prüfe Inhalt, Dateiliste, Größen, HTML/CSS/JS-Syntax soweit verfügbar, interne Links und ZIP-Integrität. CRC allein beweist keine funktionierende Anwendung. Liefere das Archiv erst aus, wenn das Werkzeug die tatsächlich gespeicherten Dateien bestätigt hat. Keine erfundenen Downloads und keine künstlichen Dateianzahl-Limits in der Skill-Anleitung.

## skill.csv-data

Skill: CSV | Category: Dateien | Version: 1.0.3 | Required: no

Search terms: csv-data, CSV, csv, datenimport, data import, tabellendaten.

Source: `skills/csv-data/SKILL.md` (Git blob 269acd0c60041c17d8300dec8999728e4ede16e9).

---
name: csv-data
description: "Für zuverlässigen Import und Export strukturierter Textdaten."
---
# CSV und Datenimport

Prüfe Trennzeichen, Zeichencodierung, Header und Zeilenenden anhand echter Daten. Nutze einen CSV-Parser statt split an Kommas. Behandle Anführungszeichen, mehrzeilige Felder und fehlende Werte ausdrücklich. Validiere Typen nach dem Einlesen und protokolliere fehlerhafte Zeilen ohne vertrauliche Daten. Prüfe einen Rückimport des erzeugten Exports. Schütze bei Tabellenprogrammen vor unbeabsichtigt ausführbaren Formeln.

## skill.async-jobs

Skill: Jobs/Queues | Category: Backend | Version: 1.0.3 | Required: no

Search terms: async-jobs, Jobs/Queues, asyncio, async, queue, warteschlange, hintergrundjob.

Source: `skills/async-jobs/SKILL.md` (Git blob 22ef7522a6b12c7dcb5b70aa04111dac5381bb26).

---
name: async-jobs
description: "Für kontrollierte Nebenläufigkeit, Abbruch und Hintergrundaufgaben."
---
# Asynchrone Jobs

Trenne Auftragszustand, Modellaufruf, Werkzeugarbeit und Benutzeranzeige. E.M.B.E.R. verarbeitet Modellanfragen global seriell: niemals mehrere Ollama-Inferenzen parallel, auch nicht über Ollama Cloud. Ein langer Projektbau muss in wiederaufnehmbare, möglichst idempotente Dateischritte zerlegt werden, wenn der entsprechende Runner dies unterstützt; verifizierte Quellen dürfen bei Unterbrechungen nicht stillschweigend verworfen werden. Prüfe bei Resume Benutzer-/Session-Besitz, gespeicherten Zustand, Dateihash und nächste fehlende Datei. Modell-Limits und reale Ressourcenbudgets unterscheiden; endliche Anzeige- und Serverzeiten dürfen keinen fertigen Erfolg vortäuschen. Abbruchsignale weiterleiten und fehlgeschlagen, abgebrochen, unvollständig und abgeschlossen strikt trennen.

## skill.sse-streaming

Skill: SSE-Streams | Category: Backend | Version: 1.1.2 | Required: no

Search terms: sse-streaming, SSE-Streams, sse, streaming, eventsource, server-sent.

Source: `skills/sse-streaming/SKILL.md` (Git blob d027eab19300514a5929a7b2c998a9288c7add85).

---
name: sse-streaming
description: "Für robuste Ereignisströme, Wiederverbindung und Abschlusszustände."
---
# SSE und Streaming

Sende nur autorisierte, explizit typisierte Server-Sent-Events mit eindeutigen Ereignis- und Run-Bezügen. Verbinde Workevents mit dem echten laufenden Auftrag; Wiederverbindungen dürfen keine zweite Inferenz starten. Zeige live eingehende, geprüfte öffentliche Arbeitsnotizen schrittweise und in tatsächlicher Reihenfolge. Die Anzeigewarteschlange verlangsamt ausschließlich die Präsentation, niemals Datenbank- oder Modellverarbeitung. Quellcodevorschau darf nur reale freigegebene content-Fragmente enthalten, keine thinking-Tokens, Secrets oder Werkzeug-Logs. Bei Abbruch, fehlendem done oder unvollständigem Quelltext keinen Erfolg melden. Gespeicherte Workevents nach Reload ohne doppelte Schritte rekonstruieren; die flüchtige Live-Vorschau nicht mit dauerhaft gesichertem Quellcode verwechseln.

## skill.observability

Skill: Logs | Category: Betrieb | Version: 1.1.3 | Required: no

Search terms: observability, Logs, logging, logs, diagnose, observability, monitoring.

Source: `skills/observability/SKILL.md` (Git blob e8261046cbd30df029b1809e0e15ede05bae7279).

---
name: observability
description: "Für nachvollziehbare Ereignisse ohne unnötige sensible Inhalte."
---
# Logs und Diagnose

Protokolliere tatsächliche Modell-, Werkzeug- und Dateiereignisse mit Auftrag/Run, Status, Quelle, erlaubtem Dateinamen, Fehlerklasse, HTTP-Status, done_reason, Tokenzählern, Dauer und geprüftem Ergebnis. Bei großen Projekten Plan-Bytezahl, geplante Dateianzahl und fehlenden/verifizierten Dateistatus nachvollziehbar machen, soweit die Infrastruktur diese Daten tatsächlich liefert. Trenne echte, als öffentlich autorisierte Mara-Kommentare von automatischen Erklärungen und technischen Metadaten. Keine privaten Prompts, Thinking-Tokens, fremden Accountpfade, Keys, Roh-stderr oder beliebigen Quelltext im Arbeitsprotokoll speichern. HTTP 200 bestätigt nur Transport, nicht Dateivollständigkeit. Nach Reload Fehler und Korrekturversuche in chronologischer Reihenfolge zeigen.

Ein Skill-Ladeereignis ist kein Werkzeugaufruf. Zeige nur tatsächlich durch Werkzeuge erzeugte Namen und Ergebnisse, mit nachvollziehbarem Fehlerzustand; private Thinking-Tokens und Promptteile niemals als Worklog ausgeben.

## skill.canvas-games

Skill: Canvas-Spiele | Category: Frontend | Version: 1.0.2 | Required: no

Search terms: canvas-games, Canvas-Spiele, canvas, spiel, game, animation, 2d.

Source: `skills/canvas-games/SKILL.md` (Git blob a75d8a7767934fe5404c9e282340f62989d6a855).

---
name: canvas-games
description: "Für Canvas-Darstellung und kleine interaktive Spiele."
---
# Canvas und kleine Spiele

Trenne Spielzustand, Eingabe, Simulation und Zeichnen. Skaliere die Zeichenfläche passend zur Anzeige und berücksichtige das Pixelverhältnis. Berechne Bewegung anhand verstrichener Zeit statt einer angenommenen Bildrate. Pausiere oder begrenze Arbeit bei inaktiver Ansicht. Stelle Start, Neustart und verständliche Steuerung bereit. Nutze vorhandene oder selbst erzeugte Ressourcen und prüfe Kollisionen und Randfälle.

## skill.release-handover

Skill: Releases | Category: Betrieb | Version: 1.1.3 | Required: no

Search terms: release-handover, Releases, release, version, changelog, übergabe, uebergabe, handover.

Source: `skills/release-handover/SKILL.md` (Git blob c2cf8a3ee39cc54652f758097f03ae4f1dac7ec5).

---
name: release-handover
description: "Für konsistente Versionsstände und fortsetzbare Übergaben."
---
# Release und Übergabe

Jeder Merge nach main erfordert einen neuen numerischen Versionsstand gemäß docs/VERSIONING_POLICY.md. Pflege VERSION, sechs HTML-Seiten, JS-Bootstrap, SHA256-basierte numerische Cache-Keys, README, Changelog, TXT-Index, Selbsttests und ein neues Übergabeprotokoll gemeinsam. Bestehende historische Artefakte nicht umbenennen. Trenne verifizierten GitHub-Quellstand, Netcup-Deploy, Preflight, Modell-Livetest und fertig bestätigte Downloads. GitHub Actions sind keine verfügbare Erfolgsbedingung, solange das Kontingent ausgeschöpft ist. Dokumentiere offene Issues, gespeicherte Projektpläne und nächste reproduzierbare Arbeitsschritte; kein Erfolg ohne ausgeführte Prüfung.

Vergleiche die Branchversion vor jedem Merge mit main über scripts/version-merge-check.py. Prüfe Skillkatalog, Cache-Keys und Preflights lokal; ein offener PR und ein nicht ausgeführter Servertest sind keine bestätigte Auslieferung.

## skill.ollama-integration

Skill: Ollama | Category: Backend | Version: 1.1.2 | Required: no

Search terms: ollama-integration, Ollama, ollama, gemma, modelfile, inferenz, llm.

Source: `skills/ollama-integration/SKILL.md` (Git blob 1d84357152492e50bd92b10b3d8bf7d6298012a5).

---
name: ollama-integration
description: "Für Ollama-Modellanbindung, Schutz des CoreAI-Modelfiles, Werkzeugprotokoll und sichere Gemma-Parameter."
---
# Ollama und Modellanbindung

Nutze für lokale und Cloud-Modelle denselben vom Nutzer konfigurierten lokalen Ollama-Endpunkt und die dort sichtbare Modellliste. Bewahre das aktive Betreiber-Modelfile mit Mara-Persona, TEMPLATE, RENDERER und PARSER unverändert; vor Änderungen docs/MODELFILE_CHANGE_POLICY.md prüfen. Der E2B-Referenzexport ersetzt nicht das E4B-Modelfile. Für lokale Gemma-Modelle keine zusätzlichen num_ctx- oder num_predict-Overrides; draft_num_predict 0 beibehalten. Modellaufrufe bleiben auch bei mehrstufigen Werkzeugfolgen seriell.

Unterscheide Ollama HTTP-Erfolg von vollständiger message.content-Ausgabe; prüfe done, done_reason und Zähler. Verwende native tool_calls nur, wenn die aktuelle Backend-Route sie ausdrücklich bereitstellt und Ergebnisse in der korrekten Rolle zurückgibt. Sonst das bereits validierte Marker-/Source-Fallback verwenden, ohne eine native Werkzeugschleife zu behaupten. Neue Skill-Anweisungen statt ungefragtem SYSTEM-Tuning. Skills verleihen keine Werkzeugberechtigungen. Fehlende Ausgaben nicht als Provider-Erfolg verkaufen.

## skill.skill-authoring

Skill: CoreAI-Skills | Category: Arbeitsweise | Version: 1.1.3 | Required: no

Search terms: skill-authoring, CoreAI-Skills, skill, skills, skill.md, anleitungspaket.

Source: `skills/skill-authoring/SKILL.md` (Git blob 59c6019368a4f780485a5aad86c4de9198d741f0).

---
name: skill-authoring
description: "Für kompakte, geprüfte und erweiterbare CoreAI-Arbeitsanleitungen."
---
# Eigene CoreAI-Skills

Erstelle repository-eigene skills/<id>/SKILL.md mit name und description im Frontmatter, präziser Beschreibung und konkretem Ablauf. Registriere Titel, Kategorie, Trigger, Quellen, Version und Prüfdatum in skills/catalog.json. Das Backend interpretiert nur diesen sicheren Skill-Teilumfang: keine unkontrollierten Skripte oder Remote-Imports. Neue Skills stehen sofort in Maras Skill-Bibliothek; ihre description ist der Satz, an dem Mara erkennt, wann sie den Skill mit [SKILL: id] lädt, also kurz und eindeutig. Ein required-System-Skill darf nur durch bewusst genehmigte Backend-Änderung eingeführt werden; sein Status wird serverseitig erzwungen. Skills liefern Anleitungen, keine zusätzliche Werkzeugautorität oder garantierte Agentenfähigkeit. Modell-/Tool-Schnittstellen und Tests anpassen, bevor eine neue Fähigkeit behauptet wird.

Prüfe vor jedem Eintrag: Referenzen unter references/*.md lädt Mara nur auf Anforderung ([SKILL: id/references/DATEI.md], höchstens 4000 Byte je Datei). Reiner Anleitungstext maximal 2400 Byte; Katalogbeschreibung kompakt halten, weil der vollständige Bibliotheksindex unter 3600 Byte bleiben muss. Eine normale Katalogerweiterung darf Auswahl, Loader, Modelfile und Pflicht-Skill nicht verändern.

Orientiere neue Fach-Skills an realen Aufgaben, Eingaben, Ergebnissen und vorhandenen Werkzeugen. Prüfe Auslöser, Quellbezug, Duplikate und Kontextverbrauch. Die 0.5.80-Laufzeit lädt keine beigefügten Skripte oder Referenzdateien automatisch: als aktive Anleitung gilt ausschließlich die registrierte SKILL.md. Für zusätzliche Fähigkeiten einen separaten Roadmap-Schritt dokumentieren, keinen scheinbar wirksamen Skript-Hinweis eintragen.

## skill.web-research

Skill: Webrecherche | Category: Recherche | Version: 1.0.2 | Required: no

Search terms: web-research, Webrecherche, recherche, recherchiere, websuche, quellenvergleich, web research.

Source: `skills/web-research/SKILL.md` (Git blob 5c960e4c8342e53f6f9b8afe46443f5cdf8e2af7).

---
name: web-research
description: "Für Webrecherche mit mehreren belegten Quellen."
---
# Webrecherche und Quellen

Zerlege die Anfrage in überprüfbare Teilfragen. Beginne bei bereitgestelltem Material mit den tatsächlich verfügbaren Anhängen und Projektdaten. Nutze für fehlende Informationen die angebotene Websuche mit kurzen Suchbegriffen. Eine genannte URL öffnest du direkt, wenn der Browser für diesen Zug in **Plus → Recherche** gewählt wurde und angeboten wird. Bevorzuge Primärbelege und vergleiche unabhängige Quellen, soweit sie für den Auftrag nötig sind. Unterscheide Suchtreffer von tatsächlich geöffneten Webseiten; nenne nur belegte Fundstellen und Daten. Bei Widersprüchen beide Sichtweisen begründet darstellen. Fehlende Nachweise und abweichende Veröffentlichungs- und Ereignisdaten offen kennzeichnen. Keine Recherche als durchgeführt behaupten, wenn kein Werkzeugresultat vorliegt.

Wenn die Suche leer bleibt, präzisiere die Suchbegriffe bei Bedarf einmal sinnvoll. Wenn der Runner die Websuche aus dem Werkzeugangebot entfernt, verwende sie für diesen Lauf nicht erneut. Arbeite mit vorhandenen Belegen weiter, sofern sie reichen. Andernfalls benenne die konkret fehlende Quelle.

## skill.fact-checking

Skill: Faktencheck | Category: Recherche | Version: 1.0.1 | Required: no

Search terms: fact-checking, Faktencheck, faktencheck, fact check, behauptung prüfen, quellen prüfen, verifizieren, stimmt das.

Source: `skills/fact-checking/SKILL.md` (Git blob 867cb96f00b29656aab8da3b60240ef9f9cce7e9).

---
name: fact-checking
description: "Für überprüfbare Behauptungen und Quellenkritik."
---
# Faktencheck

Präzisiere zunächst die Behauptung nach Zeit, Ort und Bedeutung. Prüfe Primärquellen, Gegenbelege und die Unabhängigkeit bestätigender Berichte. Bei Zahlen Zeitraum, Definition, Einheit und Grundgesamtheit nachvollziehen. Trenne Fakt, Interpretation und Prognose. Formuliere als Ergebnis belegt, widerlegt, teilweise zutreffend oder offen. Nenne echte Belege und Unsicherheiten; erfinde weder Zitate noch Prüfungen.

## skill.rag-knowledge

Skill: RAG-Wissen | Category: Wissen | Version: 1.0.3 | Required: no

Search terms: rag-knowledge, RAG-Wissen, rag, wissensbasis, privates wissen, wissensarchiv, quellendokument, hochgeladenen dokumenten.

Source: `skills/rag-knowledge/SKILL.md` (Git blob 0642ad965835c46fe08403c9f012b170b0697892).

---
name: rag-knowledge
description: "Für Fragen zu privatem RAG-Wissen und Fundstellen."
---
# Privates Wissen und RAG-Lite

Suche nur im privaten Wissen des autorisierten Kontos. Unterscheide RAG-Lite-Treffer, direkt angehängte Dokumente und externe Webseiten. Mehrere Abschnitte aus einer Datei sind eine Quelle. Gib bei Ausschnitten keine vollständige Dokumentprüfung an. Berücksichtige Eigennamen, Apostrophe, Schreibweisen und Rückfragen zum Gesprächskontext. Trenne wörtliche Treffer, Paraphrasen und eigene Schlussfolgerungen. Nenne Dokumente und Fundstellen nur, wenn die Werkzeugdaten sie tatsächlich ausweisen. RAG-Lite garantiert aktuell weder Seitenzitate noch Texterkennung; fehlende Belege und nicht lesbare Inhalte als Lücke benennen. Keine fremden Kontodaten heranziehen.

## skill.document-analysis

Skill: Dokumente | Category: Dateien | Version: 1.0.4 | Required: no

Search terms: document-analysis, Dokumente, pdf, dokument analysieren, dokument auswerten, text extrahieren, seitenzahl, pdf prüfen.

Source: `skills/document-analysis/SKILL.md` (Git blob e241bf90c3db55fded0ff6a36cf331bb36a4f6ea).

---
name: document-analysis
description: "Für PDF- und Dokumentanalyse mit Seitenbezug."
---
# Dokumente und PDF auswerten

Prüfe zuerst Dateityp, Zugänglichkeit und den tatsächlich verfügbaren Text. Auch Markdown wie README, ROADMAP und CHANGELOG kann als Quelle vorliegen. Unterscheide belegte Funktionen, geplante Funktionen und historische Änderungen. Wenn nur Ausschnitte verfügbar sind, nenne die Abdeckung und behaupte keine Volltextprüfung. Unterscheide Text, Tabellen, Bildseiten, Fußnoten und Anhänge. Bei langen Dateien zunächst Gliederung und relevante Seiten bestimmen. Vergleiche Fristen, Zahlen und Zitate mit dem Original. Seitenangaben nur anführen, wenn verfügbar; bei Scans oder gestörtem Layout Grenzen benennen. Eine neue Ausgabedatei erst nach tatsächlicher Erstellung und Prüfung als fertig melden.

## skill.tabular-analysis

Skill: Tabellen | Category: Daten | Version: 1.0.2 | Required: no

Search terms: tabular-analysis, Tabellen, tabellenanalyse, tabelle auswerten, datenanalyse, kennzahlen, pivot, statistik auswerten.

Source: `skills/tabular-analysis/SKILL.md` (Git blob e0bac0f891d14d32ad30d5c74e3966786606017b).

---
name: tabular-analysis
description: "Für Tabellen, Kennzahlen und Datenprüfung."
---
# Tabellen und Kennzahlen

Definiere Einheit, Zeitraum, Schlüssel und Zielkennzahlen. Beim Import Zeichensatz, Dezimaltrennzeichen, leere Felder und Dubletten prüfen. Rohdaten bewahren; Filter, Aggregation, Nenner, Rundung und Ausreißer offenlegen. Rechenergebnisse möglichst mit vorhandenem Rechenwerkzeug prüfen. Keine unbestätigte Exportdatei behaupten. Datenlücken nicht mit erfundenen Werten füllen.

## skill.data-visualization

Skill: Diagramme | Category: Daten | Version: 1.0.2 | Required: no

Search terms: data-visualization, Diagramme, diagramm, datenvisualisierung, chart, plot, daten visualisieren, grafik aus daten.

Source: `skills/data-visualization/SKILL.md` (Git blob 188cf750120b5c07049287fc68c9b5138af666e4).

---
name: data-visualization
description: "Für passende Diagramme und visuelle Daten."
---
# Diagramme und Datenvisualisierung

Diagrammtyp nach Aussage wählen: Balken für Kategorien, Linien für Verläufe, Punkte für Zusammenhänge. Einheiten, Zeitraum, Datenquelle und Filter sichtbar machen. Irreführende Skalen, 3D-Dekoration und erfundene Zwischenwerte vermeiden. Diagrammdaten mit den Originalzahlen abgleichen; bei wenigen Daten lieber Tabelle zeigen. Nur tatsächlich gerenderte Grafiken als erstellte Datei melden.

## skill.json-yaml

Skill: JSON/YAML | Category: Backend | Version: 1.0.2 | Required: no

Search terms: json-yaml, JSON/YAML, json, yaml, yml, json schema, konfigurationsdatei, konfiguration prüfen.

Source: `skills/json-yaml/SKILL.md` (Git blob 660301ecc85eaba6b416d0205662bcf7ae4c82ce).

---
name: json-yaml
description: "Für sichere JSON- und YAML-Konfiguration."
---
# JSON und YAML

Vorhandenes Schema und gewünschte Änderung bestimmen. Unbekannte Felder und gültige Datentypen erhalten. JSON-Escapes und YAML-Einrückung, doppelte Schlüssel, Nullwerte und Aliasnamen prüfen. Bei verfügbaren Parsern den vollständigen neuen Inhalt validieren. Keine Geheimnisse in Beispieldateien schreiben. Vor produktiven Änderungen Sicherung und Rückweg berücksichtigen.

