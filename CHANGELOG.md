# Project STΛRLIɢHT: Ember CoreUI

Ember CoreUI wird bis zur finalen Aufnahme in den STU-Repack eigenstaendig versioniert.

## 0.5.51 - 25.10.2026

- Fehlerbehebung fuer "Lass mal den Plan umsetzen": 0.5.50 erzeugte statt Projektdateien nur 33 Zeichen Modelltext und danach `continuation_limit`. Der native Build wurde mangels erkanntem Modellwerkzeug gar nicht aufgerufen.
- Neue kurze, **modellgesteuerte** Werkzeugentscheidung bei vorhandenem vollstaendigem Privatplan vor dem grossen Antwortlauf. Dasselbe konfigurierte Modell entscheidet ohne User-Keyword-Router, ob START_BUILD oder normaler CHAT. Nur eine vollstaendig bestaetigte Modellwahl darf den vorhandenen geschuetzten `PROJECT_BUILD`-Pfad ausfuehren. Thinking ist ausschliesslich fuer diese kurze Entscheidung deaktiviert, die urspruengliche Modellkonfiguration bleibt bestehen.
- Sync- und SSE-Routen identisch angebunden; vorhandener Native-Tool-/Datei-/ZIP-Writer wird erneut verwendet, keine parallelen Modellaufrufe und kein zweiter Projektbau bei Wiederaufrufen desselben Turns.
- Projekt-Build kann nun bis zu neun explizite sichere Quelldateien aus dem gespeicherten Plan uebernehmen. Sechs HTML-Unterseiten plus CSS und JS werden nicht auf ein dreiteiliges Standardpaket reduziert. Der Plan wird bis 12000 Bytes vollstaendig uebergeben und niemals still beschnitten; Fehler wegen zu vieler Dateien/grossem Plan werden konkret benannt.
- Weitere datensparsame Ollama-Arbeitsereignisse: done_reason, eval_count, prompt_eval_count, Thinking-/Textlaenge und Fortsetzungszaehler. Der 33-Zeichen-Abbruch kann so kuenftig ohne private Modelltexte genauer eingegrenzt werden.
- Neuer `scripts/model-project-intent-selftest.php` mit simulierter Modellentscheidung, SQL-Fixures, Kontotrennung, abgebrochenen Antworten und verifiziertem Sechs-Seiten-ZIP-Auftrag. Beide Preflight-Varianten und bisherige SSE-/Laengentests synchronisiert.
- Betreiber-Modelfile, Ollama-Optionspolitik, SQL-Migrationen, Installer, Python-Sandbox und eigentlicher ZIP-Writer unveraendert. Vollstaendige Netcup-Preflight- und Live-Gemma-Abnahme noch offen.

## 0.5.50 - 25.10.2026

- Gespeicherte Markdown-Dateien (`.md`, einschliesslich aller privaten Projektplan-Versionen) werden direkt unter Maras Antwort als scrollbare, sicher gerenderte Vorschau gezeigt. Andere Dateitypen behalten ihre bewaehrten Downloadkarten.
- Drei Aktionen oben rechts: Markdown-Inhalt kopieren, Originaldatei herunterladen, Vorschau gross anzeigen; mobile Bedienung, Tastaturfokus und Statusmeldungen integriert.
- Bestehende authentifizierte Plan-/Artifact-Endpunkte unveraendert wiederverwendet. Dateiabfrage nur gleichurspruenglich, ohne Cache oder Redirect, mit Bytegroessen-, MIME- und optionaler SHA256-Pruefung. Kein aktives HTML und kein externer Viewer.
- Der interne Entwurfsmarker wird nur in der Ansicht ausgeblendet und als unvollstaendiger Entwurf angezeigt. Originaldownload bleibt byteidentisch; bei Kopieren wird nutzbarer, sichtbarer Markdown-Text verwendet.
- Vorschauen werden bedarfsweise fuer sichtbare Chatnachrichten geladen. Bei fehlender oder zu grosser Datei bleibt der vorherige sichere Downloadweg erhalten; neu laden innerhalb der Vorschau moeglich.
- Eigener Frontend-Vertragstest fuer sicheren Viewer und unveraenderte API-Downloads in beiden Preflight-Varianten. Browser-Interaktionsprobe und JavaScript-Syntaxpruefung isoliert durchgefuehrt. Netcup-/Android-Livetest steht aus.
- Betreiber-Modelfile, Ollama-Optionen, Modell-FIFO, Plan-Speicherung, SQL-Migrationen, Installer, Python-Worker und ZIP-Erzeugung unveraendert.

## 0.5.49 - 25.10.2026

- Behebt eine Parserluecke bei der Wiederaufnahme schon gespeicherter Markdown-Planentwuerfe: ein einzelner, vom Modell gewaehlter PLAN_CONTINUE-Marker wird jetzt auch mit kurzer Einleitung, Slash-Schreibweise, fehlendem Abschluss oder zusaetzlichem Modelltext erkannt.
- Im Recovery-Fall wird ausschliesslich die neueste private, authentifizierte Planversion aus der Datenbank als Ausgangspunkt verwendet. Unbestaetigte Modelltexte werden nicht ungeprueft angehaengt, Python/Projekt-Build-Werkzeuge werden nicht gestartet.
- SSE- und Sync-Antwortweg umgehen die allgemeine lange Antwortfortsetzung fuer diese Marker und erlauben ein sicheres PLAN_CONTINUE selbst nach abgebrochener Ollama-Generation. Sicherheitsregeln fuer PROJECT_BUILD, PLAN_AND_BUILD und gemischte Marker bleiben streng.
- Technische Work-Events weisen eine tolerante Wiederherstellung oder einen unlesbaren Fortsetzungsmarker mit Groessenangabe aus, ohne Planinhalt zu loggen.
- Neue Regressionstests fuer offene und geschlossene Modellmarker, zusaetzliche Einleitungen, lange Markdown-Bloecke, Mehrfachmarker, eingebettete Code-Beispiele, gemischte Werkzeuge und das sichere Wiederaufnehmen bereits gespeicherter Plaene.
- Vollstaendiger Netcup-/Gemma-Livetest steht aus; der konkrete Rohtext des fehlgeschlagenen Turns ist nicht verfuegbar. Betreiber-Modelfile, Ollama-Sampler und die bestehenden Datei-/ZIP-Werkzeuge sind unveraendert.

## 0.5.48 - 25.10.2026

- Autonome, serielle Projektplanung: Bei einem Modell-gewaehlten offenen PLAN_SAVE-Block wird der sofort gespeicherte Markdown-Entwurf innerhalb desselben Turns in bis zu vier fokussierten Modellaufrufen weitergeschrieben. Nach jedem verwertbaren Abschnitt wird eine neue private, unveraenderliche .md-Version gesichert.
- Planungs-Fortsetzungen erhalten nur das aktuelle Arbeitsziel, bereits vorhandene Ueberschriften und ein kurzes Endfenster. Modell-Neustarts, wiederholte Abschnitte und unerwuenschte gemischte Werkzeugaktionen werden verworfen.
- Erst eine bestaetigte Modellantwort mit explizitem Abschlussmarker und grundlegender Markdown-Struktur macht den Plan fertig; andernfalls bleibt der bisherige Text als abrufbarer Entwurf erhalten.
- Neues modellwaehlbares PLAN_CONTINUE erlaubt es Mara, auch bereits gespeicherte Entwuerfe eines frueheren Chat-Turns in weiteren Versionen zu vervollstaendigen. Kein automatischer Build und kein Umgehen der kontogebundenen Downloads.
- Gleiche Engine in SSE- und synchronem Chatpfad; begrenzte fokussierte Modelltimeout-Option nur fuer die Fortsetzungsaufrufe. Keine Aenderung an Ollama-Modelfile oder Sampler-Optionen.
- Neuer Preflight-Selbsttest fuer Mehrschritt-Planungen, Guardrails, Versionierung, Integritaet und Wiederaufnahme. Antwortpipeline-Test an fokussierte Timeout-Form angepasst.
- Vollstaendige Netcup-/Gemma-Live-Abnahme steht aus. Modelfile.template unveraendert.

## 0.5.47 - 25.10.2026

- Frontend-Regressions-Selbsttest an die in 0.5.46 eingefuehrte Absicherung `!$nativeToolHandled` angepasst. Die vorherige starre Stringsuche meldete faelschlich den gesamten Mehrdateien-/Cloud-/Lokalpfad als defekt.
- Die 17 Mehrdateien-/Cloud-/Lokal-Teilbedingungen werden nun einzeln benannt und geprueft; ein zukuenftiger Fehler benennt die konkrete fehlende Schnittstelle.
- Die spaeteren Composer- und Live-Run-Wiederherstellungsbedingungen am aktuellen Repository abgeglichen; PHP-Fallback-Matcher isoliert mit positiven und negativen Faellen geprueft.
- Keine Aenderung an Runtime, dateierzeugenden Funktionen, Ollama-Parametern, Installer, Python-Sandbox oder config/Modelfile.template. Netcup-Preflight steht nach Deployment aus.

## 0.5.46 - 25.10.2026

- Maras Modellwahl `[COREUI/PLANSAVE]` (auch mit kurzer Einleitung) wird als Planungswerkzeug erkannt. Ein fehlender Abschluss wird nicht zu einem fertigen Plan umgedeutet, sondern als explizit unvollstaendiger privater Markdown-Entwurf versioniert.
- Entwuerfe bleiben als Download verfuegbar und sind niemals fuer den statischen Projekt-Builder freigegeben. Vollstaendige bisherige PLAN_SAVE/PLAN_APPEND/PLAN_AND_BUILD-/PROJECT_BUILD-Protokolle bleiben erhalten.
- Sync und SSE setzen laengere Antworten mit einem kurzen hinteren Kontextfenster fort; grosse Textueberlappungen werden zusammengefuehrt, wiederholte Modell-Neustarts nicht doppelt angehaengt.
- Bei verbleibendem Tokenlimit wird die Ausgabe als unvollstaendig kenntlich gemacht statt still als abgeschlossen im Chat zu erscheinen. Regressionen fuer Screenshot-Planmarker, SQL-Draft-Speicherung, Build-Sperre, Markdown-Fortsetzung und begrenzten Prompt ergaenzt.
- Modelfile, Ollama-Parameter, Installer/One-Liner, Runtime-FIFO, Migrationen, Python-Worker und bestehende Download-Vertraege bleiben unveraendert. Vollstaendige Netcup-E4B-Planungsabnahme noch offen.

## 0.5.45 - 25.10.2026

- Gemma-Preflight prueft die vom Betreiber nach 0.5.44 aktualisierte aktive Modelfile-Vorlage statt der archivierten E2B-SYSTEM-Passage und der damaligen festgelegten Vier-Parameter-Reihenfolge.
- Syntax und numerische Werte der aktiven Parameter, optionales generiertes Modelfile und weiterhin fehlende API-Overrides fuer lokale Gemma-Modelle werden kontrolliert. Das archivierte E2B-Original bleibt unveraendert pruefbar.
- Branding-Selbsttest und Native-/Compose-Diagnose aktualisiert. Konkrete Gemma-Testfehler werden ausgegeben statt verschluckt.
- config/Modelfile.template, Installer, One-Liner, Ollama-Runtime, FIFO, Dateisandbox und Tool-System unveraendert. E4B-/Netcup-Liveabnahme offen.

## 0.5.44 - 25.10.2026

- Drei 0.5.43-Preflight-Regressionen korrigiert: zwei veraltete PROJECT_BUILD-Testformulierungen und ein fehlender Funktionsimport im isolierten Datei-Selbsttest.
- Compose- und Native-Preflight zeigen jetzt bei Datei-Selbsttestfehlern die reale PHP-Ausgabe.
- Original-Mara-Modelfile, Ollama-Optionen, FIFO, Installer und produktive Projektpipeline unveraendert; Netcup/E4B-Livetest weiter ausstehend.
- Uebergabeprotokoll: docs/UEBERGABEPROTOKOLL_0_5_44.txt.

## 0.5.43 - 25.10.2026

- Aktive Mara-Vorlage auf den vollstaendigen vom Betreiber bereitgestellten SYSTEM-Text zurueckgestellt. Nur portable FROM-Basis und Ollama-konforme dreifache SYSTEM-Anfuehrung statt ollama-show-Blobpfaden. Vier Originalparameter, kein num_predict -1, kein num_ctx.
- Modelfile-Regressionstest und Aenderungsrichtlinie angepasst; vorhandene 0.5.42-Leerausgabe-Diagnose, gezieltes think:true bei HTTP-200-Leerantwort und Modell-Live-Audit bleiben erhalten.
- Installer, One-Liner, Modellaufbaureihenfolge und Laufzeit-Queue nicht veraendert. Produktiven Modell-Rebuild und echten E4B-Lauf nach Update verifizieren.

## 0.5.42 - 25.10.2026

- **Leere E4B-Dateiausgabe diagnostizieren:** Das bisherige `model_response_missing` verdeckte Transport-Timeout, Warteschlangenfehler, HTTP-Fehler und erfolgreiche Ollama-Antworten mit leerem `message.content`. CoreUI klassifiziert diese Faelle getrennt und zeigt sicheren HTTP-Status, Laufzeit sowie Tokenzaehler im bestehenden Arbeitsverlauf. Kein Rohprompt oder Modell-Denkinhalt in den Events.
- **Keine identischen 600-s-Fehlversuche:** Nach Transport-Timeout/HTTP-/Warteschlangenfehler erfolgt keine blinde Wiederholung derselben Dateianfrage. Nur bei HTTP 200 mit leerem Inhalt probiert lokales Gemma einmal gezielt `think:true`, um einen bekannten Leerausgabe-Fall zu umgehen. Die normale Thinking-Einstellung wird nicht global veraendert.
- **Kleinere Folgeprompts:** Bei isolierter HTML/CSS/JS-Quelldateierzeugung wird der zuvor im Hauptauftrag geladene Skillkatalog nicht noch einmal als kompletter Anweisungstext angehaengt. Der bereits vorhandene Quelldatei-Vertrag bleibt erhalten. Das native Runtime-Werkzeugprotokoll ist um etwa ein Drittel kuerzer. Normale Skills und die vier Modellwerkzeuge bleiben im Chat nutzbar.
- **Tatsaechlich geladenes Mara-Modell pruefen:** Der neue rein lesende `scripts/model-live-audit.php` vergleicht via Ollama `/api/show` den geladenen SYSTEM-Text mit `config/Modelfile.template` (SHA256 und Zeichenlaenge, ohne den privaten Text auszugeben). Beide bestehenden Preflights pruefen das aktive Modell nach der vorhandenen Modell-Existenzkontrolle sowie die Audit-Funktion offline. Eine Abweichung wird klar angezeigt; kein automatisches `ollama create` durch den Audit.
- **Aktuelle kurze Vorlage respektiert:** Auf `main` wurde `config/Modelfile.template` parallel auf 1717 Zeichen verkuerzt und `num_predict -1` als fuenfter Parameter ergaenzt. Dieser Fix aendert die neue Modelfile **nicht**. Der Gemma-Selbsttest ist an die aktuelle Fassung angepasst; der historische E2B-Export bleibt separat unveraendert. Ollama-API-Tuning, One-Liner, Installer, Installationsreihenfolge, SQL-Migrationen und serielle FIFO-Warteschlange bleiben unangetastet. Ob auf Netcup derselbe SYSTEM-Text aktiv ist, zeigt erst der Live-Audit.
- **Tests und Grenzen:** Ergaenzte Offline-Vertragstests fuer Timeout/HTTP-Fehler/leere Antworten und begrenzten Retry. Der reale Netcup-Fehler konnte nicht per Ollama-Liveaufruf reproduziert werden. Ein defektes Modell oder echter Generationstimeout ist damit nicht automatisch behoben. Keine GitHub Actions ausgefuehrt.

## 0.5.41 - 25.10.2026

- **Echten Parserfehler beim modellgesteuerten Projekt-Writer behoben:** Der vorherige Dateinamen-Abschluss `(?![A-Za-z0-9._-])` hat einen letzten Dateinamen wie `main.js.` wegen des folgenden Satzpunkts verworfen. Der gesicherte `PROJECT_BUILD`-Auftrag `index.html, style.css, main.js. Erstelle projekt.zip.` kam deshalb nur mit zwei statt drei Quelldateien beim Worker an. Genau das erklaert den nach 0.5.40 gemeldeten Preflight-Fehler `Worker did not get exactly the validated static source manifest`.
- **Korrigierte Quelle statt deaktivierter Prüfung:** `api/console_data_link_bridge.php` unterscheidet jetzt Satzzeichen von echten zusätzlichen Endungen. `main.js.` und `homepage.zip.` werden korrekt erkannt; `main.js.backup` und `homepage.zip.tmp` dürfen weiterhin nicht als die kürzeren Dateinamen durchgehen. `api/console_native_tools.php` trennt die serverseitige Pflichtdateiliste zusätzlich per Zeilenumbruch statt eines nachgestellten Satzpunkts.
- **Regressionen:** Die tatsächliche Projektfreigabe-Prüfung `scripts/contextual-project-selftest.php` und der eigenständig laufende `scripts/local-file-tool-selftest.php` prüfen die Dateiliste, ZIP-Namen, ZIP-Worker-Erkennung und unerlaubte Zusatzendungen. Ein isolierter PHP-Test mit dem identischen Parser-/Auftragsformat konnte die alte Fehlstelle reproduzieren und die Korrektur bestätigen.
- **Keine Änderungen** an Mara-Modelfile, Tool-Auswahl, Ollama-Optionen, Installer, One-Liner, Datenbank, Projekt-Sandbox oder serieller Modell-FIFO. Der Netcup-Preflight und der vollständige E4B-Dateiausgabetest stehen weiterhin als Live-Abnahme aus. GitHub Actions wurden nicht gestartet.

## 0.5.40 - 25.10.2026

- **Preflight-Fehlalarm bei der Projektfreigabe beseitigt:** Der bisherige Test `scripts/contextual-project-selftest.php` pruefte immer noch den seit Version 0.5.39 nicht mehr benutzten, wortlistenbasierten Projektadapter aus 0.5.38. Welche der alten Einzelpruefungen scheiterte, war wegen unterdrueckter Testausgabe nicht feststellbar. Der alte Test war jedenfalls kein gueltiger Nachweis fuer den seit 0.5.39 produktiven Modellwerkzeugpfad. Der Ersatz prueft jetzt ausschliesslich den tatsaechlichen modellgesteuerten Werkzeugpfad.
- **Neue wirksame Regression:** Ein vom Modell erzeugtes `[COREUI_PROJECT_BUILD]` wird gelesen, mithilfe eines gespeicherten Markdown-Projektplans in einen vollstaendigen Dateiauftrag umgesetzt und an den bestehenden, sicheren HTML/CSS/JS- und ZIP-Generator uebergeben. Tests umfassen echte SQLite-Planabfragen (wenn verfuegbar), Download-Manifest, auf einen Aufruf begrenzte Ausfuehrung, Rekursionsschutz, Sitzungskontext, Fremdaccount-Isolation, grosse Planungen und nicht unterstuetzte Frameworks.
- **Lesbare Fehlermeldungen:** Compose- und Native-Preflight zeigen bei Projekt-Testfehlern nun die genaue Assertion bzw. PHP-Fehlermeldung. Bisher wurde die Ausgabe durch `>/dev/null 2>&1` geschluckt.
- **Modell-Werkzeughinweis korrigiert:** Das `PROJECT_BUILD`-Beispiel hatte vor dem schliessenden Marker ein woertliches `\\n` statt eines echten Zeilenumbruchs. Der Runtime-Hinweis nutzt nun das richtige Format; der Native-Tools-Selbsttest prueft es.
- **Keine Architektur- oder Installationsaenderung:** Modellselektierte Werkzeuge, dauerhafte MD-Versionen, SQL-Migration 016, der bisherige Installer und One-Liner, Ollama-Parameter, das Betreiber-Modelfile und die serielle FIFO-Warteschlange bleiben unangetastet. Keine GitHub Actions. Live-Netcup-Preflight steht aus.

## 0.5.39 - 25.10.2026

- **Mara waehlt native Werkzeuge selbst:** Die bisherige fest eingebrannte Schluesselwort-Freigabe einer Homepage ist nicht mehr noetig. Das Modell kann im bestehenden privaten Chat selbst einen klar abgegrenzten Werkzeugblock ausgeben: `COREUI_PLAN_SAVE`, `COREUI_PLAN_APPEND`, `COREUI_PLAN_AND_BUILD` oder `COREUI_PROJECT_BUILD`. Der Server validiert und fuehrt genau einen Aufruf aus. Der Nutzer muss nicht jedes Mal "Homepage, ZIP, Download" schreiben. Alte explizite Datei-Auftraege bleiben kompatibel.
- **Dauerhafte Projektplanung:** Neue Migration `016_console_project_plans.sql` speichert Markdown-Versionen als unveraenderliche Records pro Nutzer und Chat. Neue Fassungen und Anhaenge erhalten eigene Versionen, die Originalinhalte bleiben erhalten. Jede vom Modell gesicherte Version wird als echte `.md`-Downloadkarte unter einer authentifizierten, hash-geprueften `api/console_plan.php`-Route sichtbar.
- **Plancontext ohne Modelfile-Ballast:** Die aktuelle Planung wird in den Runtime-Prompt als kleiner gekennzeichneter Kontext geladen; komplette Markdown-Inhalte bleiben serverseitig erhalten. Ein komplexer Plan, der zu gross fuer die derzeitige sichere Quellgenerierung ist, wird ausdruecklich abgewiesen und NICHT heimlich abgeschnitten. Die vollstaendige abschnittsweise Wiedereinlesung sehr grosser Plaene bleibt ein separater Ausbau.
- **Agent / ZIP:** Der Modell-Werkzeugaufruf `PROJECT_BUILD` erzeugt eine servereigene Datei-/ZIP-Aufgabe und startet die bisherige sichere Source-/Sandbox-Pipeline, nur sequenziell. Das Modell bekommt keine freie Ausfuehrung auf dem Host, keine beliebigen Dateipfade oder DB-/Shell-Rechte. Ein abgelehnter oder gescheiterter Build wird nicht als Download ausgegeben.
- **Preflight/Installer entblocken:** Der in 0.5.37 fehlerhaft gewordene Skills-PHP-String war bereits in 0.5.38 repariert; jetzt wird der PHP-Lint beim bestehenden Installationsschritt und beiden Preflights mit **Dateiname und originaler PHP-Fehlermeldung** ausgegeben statt `xargs: php exited 255`. Mehrere veraltete Tests auf den inzwischen ersetzten String/Stream-/Uploadpfad sind korrigiert. Kein neuer Installationsschritt.
- **Tests:** Neuer `scripts/native-tools-selftest.php` prueft Marker, sichere Modelleingaben, Datenbank-Sitzungs-/Kontotrennung, Markdown-Versionen und Auth-Download. In Compose und Native Preflight mit Schema-Check fuer Migration 016 eingebunden. Alle UI- und Javascript-Cache-Fingerprints auf 0.5.39 gesetzt. Keine GitHub Actions; echter Netcup-End-to-End-Test bleibt offen.
- **Unveraendert:** `config/Modelfile.template` und Mara-Persona, Ollama-Modelloptionen einschliesslich `num_ctx`/`num_predict`, One-Liner, Installationsreihenfolge, Modell-FIFO und SQL-Daten alter Versionen. Nur die Ausgabe des vorhandenen PHP-Syntaxchecks in `scripts/install.sh` wurde verbessert.

## 0.5.38 - 25.10.2026

- **Projekt-Agent aus dem Gespräch heraus starten:** Bei einer eindeutigen Umsetzungsfreigabe wie "Dann setz unseren Plan um", "Mach das Projekt fertig" oder "Okay, dann legen wir los" greift CoreUI jetzt auf die letzten, zur selben privaten Sitzung und demselben Nutzer gehörenden Planungsnachrichten zurück. Ein Freigabesatz braucht dadurch nicht erneut alle technischen Dateinamen, "ZIP" und "Download" zu enthalten.
- **Bereits vorhandene Werkzeuge verwenden:** Die serverseitig rekonstruierte Aufgabe wird als begrenzter, expliziter Datei-/ZIP-Auftrag an den bestehenden, isolierten Projekt- und ZIP-Agenten übergeben. HTML/CSS/JS stammen weiter aus vollständigen, einzeln validierten Modell-Quelldateien; der Server schreibt und prüft die Dateien. Der ursprüngliche Chatbeitrag wird nicht umgeschrieben, nur der aktuelle Ausführungsprompt.
- **Beide Ausführungswege:** Der SSE-Stream erkennt die Freigabe vor seinem Dateimodus-Gate und löst genau den bereits gespeicherten Turn über den gemeinsamen Werkzeugpfad aus; der synchrone Antwortweg besitzt dieselbe sitzungsgebundene Erkennung. Kein zusätzlicher LLM-Intent-Call, keine doppelte Inferenz und keine Veränderung der globalen FIFO-Warteschlange.
- **Vorsicht vor Fehlstarts:** Fragen, Planungswünsche, Entwurfsentscheidungen ("Dann machen wir das so"), ausdrückliche Negationen, Termine in der Zukunft sowie fremde oder nicht vorhandene Sitzungskontexte lösen keine automatische Dateierstellung aus. Bereits explizit benannte Dateiaufträge laufen unverändert über den bisherigen Weg.
- **Erster Geltungsbereich:** Statische Webseiten, Homepages, Landingpages und Browsergames; für komplexe Frameworks wie React, WordPress oder Backend-/SQL-Projekte wird nicht fälschlich ein statisches Dreier-ZIP erzeugt. Das ist noch keine universelle Agentenplanung für sämtliche Aufgabentypen.
- **Tests & Wartung:** Neuer `scripts/contextual-project-selftest.php` mit positiven und negativen Anfragen, Quellen- und ZIP-Protokollkontrolle sowie statischen Isolationchecks, in Compose- und Native-Preflight eingebunden. Zusätzlich beschädigtes Nginx-Stringliteral in `scripts/skills-selftest.php` korrigiert, das sonst einen PHP-Syntaxfehler verursacht. Modelfile, Ollama-Parameter, One-Liner, Installationsreihenfolge, Datenbank und Queue bleiben unverändert. Live-Netcup-Test offen, keine GitHub Actions.

## 0.5.37 - 25.10.2026

- **Vollstaendige Mara-Textantworten:** `ember_insert()` in `api/chat.php` schneidet Antworten im privaten CoreUI-Kanal nicht laenger am frueheren 60.000-Zeichen-Deckel ab. Die globale Spielchat-Schranke und der Sicherheitsfilter gegen interne Modellgedanken bleiben unveraendert.
- **Dauerhafte Speicherung:** Die reguläre neue Migration `015_long_chat_messages.sql` erweitert `stu_chat_messages.message` von `MEDIUMTEXT` auf `LONGTEXT`, ohne Daten oder Sitzungen zu verwerfen. Der bestehende Migrationsmechanismus erkennt die neue Datei automatisch; Installer, One-Liner und Reihenfolge bleiben unveraendert.
- **Grosse Texte einfuegen:** `app.html` verwendet ein mehrzeiliges, automatisch wachsendes Eingabefeld ohne hartes HTML-`maxlength`. Beim Einfuegen von mehr als ca. 8.000 Zeichen wird das **unveraenderte Original** als `eingefuegter-text-YYYY-MM-DD_HH-MM-SS.md` ueber die bestehende authentifizierte `console_upload.php`-Pipeline hochgeladen. Mehr als 12.000 Zeichen im Editor werden vor dem Senden ebenfalls als Markdown angehaengt. Die Datei erscheint sichtbar am normalen Nutzerbeitrag, auch nach dem Neuladen.
- Ein waehrend des Uploads angeforderter Versand erfolgt erst nach erfolgreicher Rueckmeldung des Servers. Bei Uploadfehlern kommt der komplette Ausgangstext in das Eingabefeld zurueck; bei voller Zehn-Anhang-Grenze gibt es einen expliziten Hinweis. `Shift+Enter` erzeugt Zeilenumbrueche, `Enter` sendet, IME-Komposition bleibt geschuetzt.
- **Kontextklarheit:** Der ganze hochgeladene Text bleibt als Datei abrufbar. Maras Anhang-Extraktor uebergibt weiterhin nur einen kenntlich gemachten Auszug nach dem bestehenden Kontextbudget. Das aendert weder `num_ctx` noch `num_predict`, sondern verhindert, dass Inhalte ohne Hinweis verloren gehen. Modell-/Ollama-eigene Tokenlimits und Zeitgrenzen gelten weiter.
- Regressionstests fuer private Antwortspeicherung, `LONGTEXT`-Schema und Markdown-Upload; optionale Node-Tests im bestehenden Preflight. Keine GitHub Actions ausgefuehrt, Live-Netcup-/Ollama-Abnahme steht aus.

## 0.5.36 - 25.10.2026

- **Skills wieder erreichbar:** Beide abgesicherten Nginx-Routenlisten enthielten zwar `api/user_settings.php`, aber nicht den seit 0.5.33 existierenden Endpunkt `api/user_skills.php`. Dieser erhielt deshalb HTTP 404. Die bestehende PHP-Freigabeliste wurde in `config/runtime/nginx.conf.template` (Compose) und `config/nginx.conf.template` (native Installation) nur um `user_skills` erweitert. Der Authentifizierungs- und CSRF-Schutz des Endpunkts bleibt unveraendert.
- Der Skills-Bildschirm zeigt jetzt die tatsaechliche Fehlerklasse (z.B. 404, 401, 500 oder fehlende Migration) statt pauschal eine abgelaufene Anmeldung zu behaupten. Ein neuer Regressionstest verhindert das erneute Vergessen des PHP-Endpunkts.
- **Chat nach Seitenwechsel:** Bereits laufende, einem Konto und einer Sitzung zugeordnete Agentenlaeufe werden aus der authentifizierten Nachrichten-History als aktive Arbeit rekonstruiert, einschliesslich der serverseitig freigegebenen Arbeitsschritte. Die UI kehrt zu ihrem Wartezustand zurueck, zeigt wieder den Verlauf und pollt nur die bestehende Antwort. Keine doppelte SSE-Generierung und kein zweiter Ollama-Aufruf.
- Ein kurzer Sende-POST darf mit `fetch keepalive` beim Wechsel in Einstellungen zu Ende gehen; groessere Bodies bleiben ohne keepalive. Ein noch nicht im History-Snapshot enthaltener, bereits bestaetigter Nutzer-Turn bleibt im Cache sichtbar. Fertiggestellte Antworten werden weiterhin aus der DB geladen.
- Backend-Querys sind user_id/session_id/turn_id/run_key-begrenzt; Arbeitsschritte nutzen die bestehende Public-Whitelist. Inaktive/alte Jobs werden nicht wiederhergestellt. Keine DB-Migration.
- PHP-/JavaScript-/Nginx-Quelltests sowie isolierte Rehydrationspruefung dokumentiert. Die produktive Netcup-Abnahme ist noch offen. Keine Aenderung an Modelfile, Ollama, Installer, One-Liner, serieller Warteschlange oder PR #69. GitHub Actions nicht gestartet.

## 0.5.35 - 25.10.2026

- **Aktive Modelfile-Korrektur:** `config/Modelfile.template` nutzt jetzt den SYSTEM-Text und die exakte Parameterreihenfolge der vom Betreiber bereitgestellten `mara.modelfile`-Ausgabe, statt diese nur unter `docs/references` zu archivieren. Der E2B-Export bleibt unberuehrt.
- Aus `ollama show --modelfile` stammende absolute Modell-/DRAFT-Blobpfade werden NICHT als feste Serverpfade eingesetzt. Fuer den bestehenden, unveraenderten Installer bleibt ein einzelnes `FROM gemma4:e4b` erhalten; SYSTEM verwendet die korrekte dreifache Einfassung fuer mehrere Zeilen.
- Modellparameter unveraendert in Nutzerreihenfolge: `draft_num_predict 0`, `temperature 1`, `top_k 64`, `top_p 0.95`; kein `num_ctx` oder `num_predict`-Override. Keine zusaetzlichen Skills oder Coding-Anweisungen im Modelfile.
- Gemma-Selbsttest und Schutzrichtlinie auf die aktive Vorlage angepasst. Installationsreihenfolge, One-Liner, `setup.sh`, `scripts/install.sh`, `scripts/install-native.sh`, `scripts/stack.sh`, Modellqueue und API-Verarbeitung unveraendert.
- 0.5.35 Versionierung, sechs Browseroberflaechen, numerischer JS-Cache-Schluessel, Preflight-Verweise, README, TXT-Changelog und Uebergabeprotokoll konsistent aktualisiert. Keine GitHub Actions. Echter Ollama/Netcup-Live-Test bleibt offen.

## 0.5.34 - 25.10.2026

- Das vom Betreiber gelieferte E2B-`ollama show`-Modelfile mit FROM-/DRAFT-Pfaden und freigegebenen Parametern als unveraenderliche Inhaltsreferenz archiviert; keine Ersetzung der aktiven E4B-Modellbasis.
- Verbindliche Richtlinie fuer knappe, geschuetzte Modelfiles: Mara-Persona und Modellparameter bleiben stabil, nur notwendige Werkzeugprotokollanpassungen sind gezielt moeglich; neue Skills und Coding-Anleitungen verbleiben ausserhalb des Modelfiles.
- Bestehenden Gemma-Selbsttest um Git-Blob-Checksumme, Schutz der Persona ausserhalb der Werkzeugsektion und Abgleich der genehmigten Parameter erweitert.
- Weder `config/Modelfile.template` noch Installer, One-Liner, Ollama-Modellerstellung, Toolrouting oder serielle Modellwarteschlange geaendert.
- Versionsangaben, Preflight-Releasedateien und numerische JS-Cache-Schluessel fuer 0.5.34 angepasst. GitHub Actions nicht gestartet; produktive Netcup-/Ollama-Abnahme bleibt offen.

## 0.5.33 - 25.10.2026

- Neues kontobezogenes Skills-System mit 32 eigenen SKILL.md-Paketen, einzeln schaltbar, durchsuchbar und mit Alle-aktivieren/Alle-deaktivieren sowie Quellen und Prüfdatum.
- Migration 014 speichert die Auswahl pro Konto. Authentifizierte API mit CSRF, strenger Kennungs-/Bool-Prüfung und transaktionalem Speichern. Neue Skills bleiben ausgeschaltet. Kontoexport ergänzt.
- Höchstens vier passende aktivierte Skills je Anfrage; explizite Priorisierung über /skill kennung. Gemeinsame Prompt-Anreicherung für synchrone Ollama-Aufrufe und SSE, einschließlich Fortsetzungen. Arbeitsereignisse melden geladene Kennungen.
- Keine Änderung an Gemma-Modelfile, Modellparametern oder serieller Warteschlange. Skills ergänzen den Nutzerkontext und ersetzen keine SYSTEM-Anweisung, Werkzeuge oder echte Dateiprüfung.
- Quellen am 25.10.2026 geprüft, Anleitungen selbst verfasst. Keine fremden Skripte importiert. Der universelle Projekt-Agent bleibt separat in Draft PR #69.
- Offline-Tests und Live-Abnahme sind im Übergabeprotokoll getrennt aufgeführt. GitHub Actions gemäß Vorgabe übersprungen [skip ci].

## 0.5.32 - 24.10.2026

- **Nur Preflight-Testkorrektur:** Der Frontend-Regressions-Selbsttest suchte noch den alten Aufruf `coreui_console_multifile_source_directive($userMsg)`. Der Code aus 0.5.31 verwendet stattdessen korrekt `$sourceProjectRequest` fuer lokal normalisierte Gemma-Mehrdatei-Auftraege. Dadurch meldete der Test trotz vorhandener Funktion faelschlich einen Fehler.
- Erwartung auf `coreui_console_multifile_source_directive($sourceProjectRequest)` umgestellt; die neue lokale Homepage-Normalisierung und die device-local-Modellbeschraenkung werden ausdruecklich mitgeprueft. Die restlichen 14 Routenbedingungen bleiben erhalten.
- Keine Aenderung an `api/chat.php`, Dateiwerkzeug, Python-Worker, Ollama, Gemma-Modelfile, Cloud-/Qwen-Pfaden, Modellparametern, serialisierter Inferenz, Datenbanken oder Installern. Der universelle Projekt-Agent verbleibt als **Draft PR #69**, unveroeffentlicht und standardmaessig deaktiviert.
- Pflichtgemaesse Versionspflege 0.5.32 mit sichtbaren UI-Metadaten, numerischem inhaltsbasiertem Cache-Key, README, CHANGELOG, TXT-Protokoll und Preflight-Releasepfaden. GitHub Actions nicht gestartet [skip ci]. Auf dem Server bleibt die tatsaechliche Preflight-Nachkontrolle erforderlich.

## 0.5.31 - 24.10.2026

- Reproduzierter Routingfehler der privaten Anime-Videospiel-Homepage: Der Benutzer forderte HTML, CSS und weitere Quelldateien im ZIP als Download, nannte aber keine expliziten Dateinamen. Bisher erkennt der bestehende Quell-ZIP-Pfad nur benannte Dateien oder besondere Browserspielwoerter. Der Auftrag landete deshalb im langen modellgenerierten [PY]-Skript, das bei 3.005 lokalen Ausgabetokens als `ollama_truncated_py_open` abbrach.
- Nur fuer geraetelokale Gemma-Modelle wird bei einer solchen ZIP-Homepage ohne Dateinamen die kleinstmoegliche Projektstruktur inferiert: `index.html`, `style.css`, `main.js`. Kein Eingriff in andere Datei- oder Python-Auftraege. Explizite Dateinamen und Einzeldateien behalten ihre Route.
- Die bereits seit 0.5.16/0.5.21 vorhandene verifizierte Einzel-Quelltext-Erzeugung mit anschliessendem CoreUI-eigenem ZIP-Schreiber wird nun auch fuer diesen Homepage-Fall erreicht. Modellgenerierter Python-Writer ist dafuer nicht mehr noetig; jede Quelldatei wird einzeln, streng seriell und mit den bestehenden Grenzen geprueft.
- Ollama Cloud (`gemma4:31b-cloud` wird weiterhin aus der lokalen Ollama-Liste gewaehlt), Qwen und die allgemeine Python-Sandbox bleiben komplett auf ihrem bisherigen Routing. Keine eigene API-Integration.
- Originalauftrag als Regression, negative Faelle fuer Single-HTML, benannte Dateien, Erklaerungen und fremde ZIP-Formate hinzugefuegt. Ein lokaler isolierter PHP-8.4-Routingtest des Originalauftrags war erfolgreich. Kein produktiver Netcup/Ollama-Datei-End-to-End-Test behauptet.
- Versionsmetadaten auf 0.5.31, sechs Browser-Cache-Fingerprints, Preflights und TXT-Uebergabe synchronisiert. Keine Modelfile- oder Worker-Aenderung, kein `num_ctx`/`num_predict`, keine Migration oder veraenderter Installer. GitHub Actions wegen fehlendem Kontingent nicht ausfuehren. [skip ci]

## 0.5.30 - 24.10.2026

- Eng begrenzter Ollama-Dateiantwort-Diagnosepatch auf Basis 0.5.29. Kein neuer Python-Datei-Writer, kein Aendern des Python-Workers oder der Modell-Routen.
- Modell-Arbeitsereignisse zeigen ausschliesslich sichere Abschlussklassen (z.B. `ollama_stop_py_closed`, `ollama_truncated_py_open`) und Zahlwerte fuer Ausgabezeichen, `eval_count` und `prompt_eval_count`; keine Prompts, Quelltexte, Thinking oder stderr.
- Nur lokal laufende Gemma-4-Modelle weisen nachweislich abgeschnittene Antworten oder nicht geschlossene [PY]-Bloecke vor der Python-Ausfuehrung ab. Bestehender einmaliger Protokoll-Retry sowie spaeterer Korrekturversuch sind abgedeckt. Kein unbegrenzter Retry.
- Ollama Cloud wie `gemma4:31b-cloud` bleibt ueber die lokal konfigurierte Ollama-Modellliste und denselben lokalen Endpunkt erreichbar, ohne separate Cloud-API. Qwen unveraendert.
- Isolierte PHP-8.4-Testlaeufe und aktualisierte lokale Dateiwerkzeugregression bestanden. Produktions-Ollama-End-to-End offen. Kein `num_ctx` / `num_predict` fuer Gemma, keine Modelfile- oder Migrationsaenderung, kein neuer Installer. GitHub Actions nicht gestartet. [skip ci]
- Vollstaendig markierter aber intern ungueltiger Python-Code kann weiterhin einen SyntaxError haben. Die Diagnose soll erst den Ollama-Abschlussgrund nachweisen.

## 0.5.29 - 23.10.2026

- Stabilitaets-Rollback auf den gesamten funktionalen Quellstand von **0.5.25** aus Commit `53dab3f6d78e0cd626824aa3ca883f441a7f193f`, vor den PRs #63 (0.5.26), #64 (0.5.27) und #65 (0.5.28).
- Ruecknahme der gemeinsamen HTML/CSS-Modellausgabe, der HTML-Sonderbehandlung und der generischen Python-Syntaxvorpruefung. Wiederhergestellt sind der alte Python-Worker, das originale Chat-Routing und die Dateiausgabe samt Qwen-Verhalten aus 0.5.25. Keine selektiven Feature-Uebernahmen.
- Ueber 0.5.25 hinaus aendern sich ausschliesslich die numerische Release-Version 0.5.29, sichtbare UI-Versionslabels, der dazu passende inhaltsbasierte Bootstrap-Cache-Schluessel sowie README, Changelog, TXT-Uebergabe, Versionspruefung und Preflight-Dateireferenzen.
- Kein Eingriff in Datenbanken, Nutzerkonten, bestehende Migrationen, gespeicherte Konfigurationen, Ollama-Modelfile oder Installerskripte. Vorheriger 0.5.28-Stand ist auf dem Sicherungsbranch `backup/coreui-0.5.28-pre-rollback` archiviert.
- Kein GitHub Actions Start (Kontingent aufgebraucht). Wiederherstellung auf dem Server erfolgt separat ueber den bewaehrten bestehenden Update-Einzeiler; Repo-Merge ist keine Live-Ausfuehrung. [skip ci]

## 0.5.25 - 22.10.2026

- Offizielle Produktbezeichnung auf **E.M.B.E.R. CoreUI** korrigiert. E.M.B.E.R. ist das Framework, Backend und System; Ember bezeichnet dagegen die eigenstaendige CoreAI der *Starlight Ember*. Mara Thorne bleibt TCI/CoreAI der *Ravager Thorn*. Diese Begriffe werden nicht mehr vertauscht.
- Beide Installer, One-Liner-Launcher, Preflights, Browser-Metadaten, Startbildschirm, Login, Settings, Admin, Runtime-Meldungen, Healthcheck und Dokumentation verwenden die gepunktete E.M.B.E.R.-Schreibweise.
- Lokale Ollama-Modellnamen, technischen IDs, Datenbankschemas, Dienste, Verzeichnisse und Installationspfade bleiben unveraendert. Kein Umbenennen von `ember-coreui:latest` und keine Datenmigration.
- Neue Accounts und frische Installationen erhalten **Mara** als Standard-CoreAI-Namen; alte Profile mit Standardname Ember werden wie bisher nur bei der Anzeige als Mara behandelt. Individuelle andere Namen bleiben erhalten.
- Maras Modelfile stellt E.M.B.E.R.-Framework, CoreUI-Terminal und separate STU-CoreAI Ember ausdruecklich richtig. `draft_num_predict 0` bleibt, kein `num_predict` oder `num_ctx` im Modelfile.
- Lizenz-/Trademark-Texte enthalten weiterhin die alte Schreibweise als historischen Alias fuer Releases bis 0.5.24. Lizenzversion 1.0 und ihre materiellen Nutzungsbedingungen wurden nicht geaendert.
- Brandingtests, Versions- und Cache-Pruefungen angepasst. GitHub Actions nicht ausgefuehrt; produktive Abnahme noch offen. [skip ci]

## 0.5.24 - 22.10.2026

- Rollen sauber getrennt: TSA-Thorn CoreUI ist das Terminal; Mara Thorne die TCI und CoreAI. Bestehende interne Ember-IDs, Tabellen und Pfade bleiben aus Kompatibilitaetsgruenden erhalten.
- Lokales Gemma Modelfile um lockere, spontane Gamer-Chat-Stimme aus dem bisherigen Bildantwort-Stil erweitert; separate Bild-/Video-/PDF-Vision-Anweisungen ebenfalls auf Mara statt alter Menschenidentitaet umgestellt.
- Unbeabsichtigt verbliebenes `PARAMETER num_predict -1` aus dem Modelfile entfernt. `draft_num_predict 0` und die uebrigen freigegebenen Werte bleiben, `num_ctx` bleibt bei Gemma ausnahmslos tabu.
- Sichtbare CoreAI-Bezeichnungen, Standardprofilname, Antwort- und Warteanzeigen auf Mara angepasst. Der Live-Arbeitsweg lautet `E.M.B.E.R. CoreAI arbeitet`. Gespeicherte alte Standardnamen Ember werden beim Anzeigen als Mara dargestellt; andere individuelle Anzeigenamen bleiben erhalten.
- Lokale Qwen-3.6-Coding-Modelle erhalten wieder den historischen `[PY]`-Sandbox-Auftragsweg fuer echte ZIP-Dateien statt des spaeteren strengen Quellen-ZIP-Pfads. Keine Zusage ueber den noch ungetesteten produktiven Qwen-Regressionstest.
- Cloud-Ausgabe, aktuelle lokale Gemma-Recovery, unveraenderter Installer-One-Liner und serielle Queue bleiben erhalten. Keine Migration, keine GitHub Actions. [skip ci]

## 0.5.23 - 22.10.2026

- Livebefund aus 0.5.22: HTML und CSS erfolgreich erkannt; `game.js` in zwei lokalen Gemma-Aufrufen mit `model_output_truncated` beendet. Das ist eine Ollama-Ausgabelimitierung, kein belegter Parser- oder ZIP-Worker-Defekt.
- Lokale JavaScript-Quelltexte werden bei `done_reason=length` nicht mehr verworfen: Maximal zwei serielle Fortsetzungsanfragen vervollstaendigen den vorhandenen Quelltext in derselben privaten Sitzung.
- Ein reiner JavaScript-Fragmentparser entfernt optionale einfache Codefences; wiederholte Textueberlappungen werden abgeglichen. Vollstaendige Daten gehen ausschliesslich durch den vorhandenen sicheren Quellcode- und ZIP-Writer. Unvollstaendige Dateien bleiben ohne Download.
- Der lokale JavaScript-Prompt uebergibt weiter die HTML-Struktur, nicht mehr den vollstaendigen CSS-Inhalt, um Ausgabeplatz einzusparen.
- Arbeitsweg zeigt nur sichere Quelltext- und Modelltoken-Zaehler, ohne geheime Inhalte. Weitere Offline-Regressionstests fuer fortgesetzte JavaScript-Dateien und Safe-Metadata ergaenzt.
- Modelfile (einschliesslich `draft_num_predict 0`, `num_predict -1`), Cloud-Dateipfad, lokale Einzeldateien, Modellqueue und Installer-One-Liner unveraendert. Keine Migration, keine GitHub Actions; produktive Live-Abnahme offen. [skip ci]

## 0.5.22 - 22.10.2026

- Nach Live-Test: GPT Cloud erzeugt erfolgreich vier echte Snake-Downloads, lokales Gemma scheitert weiterhin mit `source_files_incomplete`; dies ist kein belegter Workerdefekt.
- Lokale Gemma-/Ember-CoreUI-Mehrdateien-ZIP-Auftraege fragen jetzt jede Quelldatei in einem kurzen eigenstaendigen Modellaufruf als rohen Quelltext an, statt gleichzeitig Python-Werkzeug- und Markdown-Projektanweisungen zu mischen.
- Der lokale Parser uebernimmt begrenzt vollstaendige Rohdateien oder einzelne Codebloecke, erkennt fehlenden Schluss-Fence bei nachweislich vollstaendigem Dateiinhalt, lehnt Python-Marker/Prosa/ungueltige Inhalte ab und wiederholt hoechstens einmal pro Datei.
- Nach vollstaendiger Erfassung erstellt und prueft ausschliesslich der vorhandene servereigene Worker die Dateien und das ZIP. Der bewiesenermassen funktionierende Cloud-Pfad bleibt unveraendert; Modelfile, API-Parameter, globale serielle Queue und Installer-Befehl bleiben unberuehrt.
- Lokale Parserregressionen fuer HTML/CSS/JS, kurze Quelldateien, fehlende Fences, Python-Marker und ZIP-Writer ergaenzt. Keine Migration. Offline-Parser-Teiltest mit PHP 8.4 bestanden; keine GitHub Actions, Live-Abnahme noch offen. [skip ci]

## 0.5.21 - 22.10.2026

- Mehrdateien-ZIP-Dateiauftraege verwenden vollstaendige, inerte Quelldateien statt fehleranfaelliger vom Modell erzeugter Python-Schreibskripte. Ohne Dateinamen wird dieser Pfad nur fuer ausdruecklich verlangte Snake-/Browser-Spiel-ZIPs erkannt.
- CoreUI fordert fehlende Quellen begrenzt einzeln nach. Erst bei vollstaendigen HTML-/CSS-/JS-Quellen erstellt der bestehende isolierte Worker echte Dateien, prueft das ZIP und registriert Downloadkarten.
- Unvollstaendige Quelltexte fuehren weder zu Python-Ausfuehrung noch zu scheinbaren Downloads. Generische Python-Werkzeuge und Einzeldateien bleiben auf ihrem bisherigen Pfad.
- Offline-Regressionstests fuer Source-Fortschritt, fehlende Dateinamen, abgegrenzte ZIP-Routen und nicht geschlossene Fences ergaenzt; keine Datenbankmigration, kein neuer Installer-One-Liner.
- GitHub Actions wegen aufgebrauchtem Kontingent nicht ausgefuehrt. Live-Abnahme mit lokalem Gemma, Cloud-Modellen, echtem ZIP-Download und Spielbarkeit noch offen. [skip ci]

## 0.5.20 - 22.10.2026

- Vom Nutzer geliefertes Modelfile wortgetreu uebernommen, inklusive Mara Thorne / TSA-Thorn SYSTEM, TEMPLATE, RENDERER und PARSER.
- Parameter wiederhergestellt: temperature 1, top_k 64, top_p 0.95, draft_num_predict 0, num_predict -1.
- Beide Installer verwenden standardmaessig gemma4:e4b. Explizites COREUI_BASE_MODEL ersetzt ausschliesslich die FROM-Zeile. PARAMETER-Verbot im Compose-Installer entfernt.
- Lokale Gemma-API-Aufrufe senden weiterhin keine options-Ueberschreibungen. Preflight und Regression pruefen nun die freigegebenen Modelfile-Parameter statt deren Abwesenheit.
- Keine Migration oder Aenderung am Installer-One-Liner. Ollama-Neuerstellung und Modellverhalten muessen live bestaetigt werden; GitHub Actions bleiben uebersprungen.

## 0.5.19 - 22.10.2026

- Dateiwerkzeug fuer Mehrdateien-ZIP-Auftraege wiederhergestellt: [PY]-Skripte und Python-Fences werden wieder vom bestehenden isolierten Worker verarbeitet.
- Der seit 0.5.16 erzwungene Sourcefile-Modus wird nicht mehr als Prompt-/Parser-Sperre verwendet. Vollstaendige Quelltextbloecke und der begrenzte Merge aus 0.5.18 bleiben optionale Wiederherstellungspfade.
- Reparatur bei fehlenden Worker-Ausgaben richtet sich nach dem tatsaechlichen Kandidatentyp. Modellskripte behalten ihren begrenzten Reparaturversuch; servereigene Quelltext-Wrapper werden nicht sinnlos vom Modell neu geschrieben.
- Live-Screenshots von 0.5.18 zeigen source_files_incomplete vor dem Workerstart. Die tatsaechlichen Modellantworten liegen nicht vor; deren konkreter Formatfehler wird daher nicht behauptet.
- PHP-8.3.6-Dateiwerkzeugtest mit echten Parserfunktionen, Frontend-Regression, PHP-/Shell-Syntax, Branding, Versionsguard und Cache-Hashes lokal bestanden.
- Keine Migration, keine Aenderung am Installer-One-Liner, globale Modellverarbeitung weiterhin seriell. GitHub Actions uebersprungen; erneute Live-Abnahme offen.

## 0.5.18 - 21.10.2026

- Unvollstaendige, aber jeweils sichere Mehrdateien-Antworten koennen jetzt ueber genau einen seriellen Modell-Wiederholungsversuch zusammengefuehrt werden. Die erste und zweite Antwort werden nur als inerte Quelltextfragmente gelesen; der Worker startet erst, wenn alle ausdruecklich verlangten Dateien vorhanden sind.
- Bei demselben Dateinamen gewinnt die zweite Antwort. Gross-/Kleinschreibung wird sicher normalisiert, waehrend die vom Nutzer angeforderte Schreibweise fuer die erzeugte Datei erhalten bleibt.
- Nicht angeforderte Zusatzdateien des Modells gelangen weder in die Ausgabe noch in das ZIP. Unsichere Pfade, Duplikate innerhalb einer Antwort, ungeschlossene Fences, unvollstaendige Pakete sowie Datei-, Gesamt- und Artefaktgrenzen bleiben harte Ablehnungsgruende.
- Markdown-Quellen mit eigenen Drei-Backtick-Bloecken koennen ueber laengere aeussere Fences bytegetreu verarbeitet werden. Der Merge baut den kontrollierten Python-Schreiber direkt aus validierten Bytes und serialisiert Quelltext nicht erneut durch Markdown.
- PHP-8.3-Syntaxpruefung und erweiterter Offline-Selftest fuer Teilantworten, Zweitantwort-Vorrang, Zusatzdateien, Schreibweise, Backticks, ungeschlossene Fences, Duplikate und Grenzwerte erfolgreich. Versionsguard und beide Preflight-Dateilisten auf 0.5.18 synchronisiert.
- Der aus zwei Teilantworten erzeugte servereigene Python-Schreiber wurde isoliert ausgefuehrt: drei Einzeldateien und snake-game.zip, fehlerfreie ZIP-CRC sowie bytegleiche ZIP-Eintraege.
- Keine Datenbankmigration, kein geaenderter Installer-One-Liner, keine GitHub Actions und kein neues archiviertes ZIP/SHA-Paar. Die Produktion wird nach dem Merge mit Gemma, Qwen und Cloud GPT live getestet.

## 0.5.17 - 21.10.2026

- Modellunabhaengiger Mehrdateien-Download: explizite ZIP-Projekte mit mindestens zwei benannten Quelldateien gehen jetzt sowohl bei lokalen Ollama-Modellen als auch bei Cloud/API-Modellen ueber denselben sicheren Quelltext-zu-Artefakt-Werkzeugpfad. Keine stillen Teilergebnisse als angeblich fertiges ZIP.
- Tatsachenbasierter Fehlerfix im CoreUI-Backend: `json_encode()` maskierte `/` in Base64-Payloads als `\\/`. In einem Python-String bleiben diese Backslashes erhalten und `base64.b64decode(...,validate=True)` scheitert mit `binascii.Error`. Der Wrapper verwendet jetzt `JSON_UNESCAPED_SLASHES`.
- Isolierter PHP-/Python-Nachbau: mit dem alten Encoding nachweisbarer Fehler, nach Korrektur drei vollstaendige Quelldateien und gueltige `snake-game.zip` (ZIP-Inhaltsvergleich und CRC-Pruefung).
- Veraltete Frontend-Regression angepasst: der Test prueft den tatsaechlichen Datei-Protokollvertrag, den neuen Cloud/Lokal-Sonderpfad und die sichere Base64-Kodierung, statt eine im vorherigen Release geaenderte Fehlermeldung wortwoertlich zu verlangen.
- Weiterhin streng: Downloads nur bei vorhandenen, geprueften Dateien; bestehende Nutzer-/Sitzungszuordnung, globale Modellwarteschlange, isolierter Worker und unveraenderter Installer-One-Liner. Keine Datenbankmigration, keine GitHub Actions und kein neues archiviertes ZIP/SHA-Paar.
- Produktions-Abnahme fuer Gemma, Qwen und GPT Cloud steht aus; der frühere modellgenerierte Fehlerbericht ist kein Server-Traceback.

## 0.5.16 - 21.10.2026

- Lokale Mehrdateien-ZIP-Auftraege mit zwei bis neun explizit benannten Quelltext-Dateien werden als komplette benannte HTML/CSS/JS- und andere Quelltextbloecke vom Modell angefordert. Ein Modell muss fuer diese Auftraege keinen zusaetzlichen Python-Dateischreibcode mehr erfinden.
- Der bereits vorhandene getestete Quelltext-Bridge schreibt ausschliesslich die bestaetigten Source-Bytes im isolierten Python-Worker und erstellt das ZIP deterministisch, mit `is_file()`-/Groessen- und ZIP-Inhaltstest. Das Modell kann keine lokalen Dateinamen, Download-URLs oder Skript-Ausfuehrungen allein durch Behauptung freigeben.
- Widersprechende allgemeine `[PY]`-Hinweise werden nur im gezielten lokalen Quelltextprojekt-Modus entfernt. Fehlende Source-Bloecke duerfen einmalig gezielt neu angefordert werden; scheitert der kontrollierte Dateischreiber oder Artefaktspeicher, erfolgt kein weiterer teurer modellgenerierter Python-Reparaturlauf.
- Bestehende Single-File-, generische Python-, Cloud- und Downloadpfade bleiben unveraendert. Neuer lokaler Quelltextvertrags-Selbsttest neben den vorhandenen ZIP-Pruefungen.
- Numerischer Versionssprung `0.5.15 -> 0.5.16`; keine Migration, kein veraenderter Installer-Einzeiler, keine GitHub Actions und kein neues offizielles ZIP/SHA-Paket. Eine echte lokale Gemma- und Browser-Abnahme steht aus; Issue #52 zum Ollama-Modellwechsel bleibt separat offen.

## 0.5.15 - 21.10.2026

- `config/Modelfile.template` enthaelt fuer das CoreUI-eigene lokale Gemma-Modell keine `PARAMETER`-Zeilen mehr. `temperature`, `top_p`, `top_k`, `repeat_penalty` und insbesondere `num_ctx 32768` werden nicht mehr automatisch festgeschrieben.
- Der Installer erstellt bei jedem Update das eigene `ember-coreui:latest` neu und blockiert eine generierte Modelfile mit eigenen `PARAMETER`-Direktiven vor `ollama create`. Das gemeinsam verwendete Basismodell, fremde Modelle und bestehende Nutzerkonfigurationen bleiben unveraendert.
- Bei frisch erzeugter `api/config.local.php` wird `STU_EMBER_NUM_CTX` nicht mehr hinterlegt. Selbst wenn alte Installationen diesen Wert behalten, geben der synchrone PHP-Pfad und der SSE-Pfad fuer lokale `ember-coreui:*`/`gemma4:*` keinerlei `options`-Tuning an Ollama weiter. Andere Modelle, etwa Cloud-GPT, behalten ihre eigenen Einstellungen.
- Die kurze synthetische `ember_warmup`-Generierung wird bei lokalen Gemma-Modellen ausgelassen: ohne Tuning konnte sie schon beim Laden ueber ihr achtsekundiges Timeout laufen und eine echte Nutzeranfrage stoeren. Es erfolgt kein automatischer Dienstneustart.
- Neuer PHP-Regressionscheck fuer Template, generierte Datei, Installer-Guard, Sync/SSE-Modellpayload, Modellfamilien und Cloud-Isolation; beide Preflights verlangen diesen Test.
- Wiederholt beobachtete lokale Modellstillstaende nach einiger Laufzeit sind noch nicht ursachlich bewiesen. Die Konfigurationsbereinigung wird nicht als garantierte Behebung eines Ollama-/Worker-Defekts ausgegeben. Keine neue Migration, keine Aenderung des Einzeilers, keine GitHub Actions und kein neues ZIP/SHA-Release.

## 0.5.14 - 21.10.2026

- PHP-/MariaDB-Installationsfix: Der Dockerfile-Build und Installer pruefen `pdo_mysql` direkt mit `extension_loaded()` im frischen PHP-Image. Ein Image ohne den Treiber wird nicht als erfolgreich installiert gemeldet.
- Vor dem Abschluss werden ausschliesslich die eigenen CoreUI-PHP-/Web-Container mit dem gebauten Image neu erstellt und `pdo_mysql` erneut im laufenden PHP geprueft. Datenbank, Nutzer, Uploads, lokale Konfiguration und andere Dienste bleiben erhalten.
- Compose- und Native-Preflight benutzen PHPs Extension-API statt der potenziell fehleranfaelligen Pipeline `php -m | grep -q`; fehlende Module bleiben ein harter Fehler mit zielgerichteter Diagnose.
- Offline-Vertragstest fuer Dockerfile, Installer und Preflights ergaenzt. Keine Migration und keine Veraenderung des unveraenderten Installer-One-Liners; keine GitHub Actions.
- Echte Serverabnahme steht aus. Kein neues offizielles ZIP-/SHA-Release erzeugt.

## 0.5.13 - 21.10.2026

- Lokale Ollama-Dateiauftraege gehen unmittelbar in den bestehenden isolierten Python-Werkzeugpfad. Der zuvor ueberfluessige erste allgemeine SSE-Modellstream entfaellt bei expliziter Dateierstellung mit Downloadwunsch. Alle Modellaufrufe bleiben in derselben globalen seriellen Warteschlange.
- Der Werkzeugaufruf verlangt einen vollstaendigen `[PY]`-Block. Fehlt ein ausfuehrbarer Auftrag, erfolgt maximal ein kurzer, fokussierter Zweitversuch. Bei `ember-coreui:*` und `gemma4:*` laeuft die reine Dateicode-Erzeugung standardmaessig mit `think:false` (optional `STU_EMBER_LOCAL_FILE_THINK=true`). Andere Provider behalten ihre bisherige Einstellung.
- Ein vollstaendiger Python-Codeblock kann bei ausdruecklicher Downloadanforderung sicher im bestehenden Worker ausgefuehrt werden. Benannte oder eindeutig zuordenbare HTML/CSS/JS-Fences werden stattdessen als inerte Dateibytes geschrieben. Ein verlangtes ZIP wird im Sandbox-Worker erstellt und mit `zipfile` geprueft.
- Der Chat meldet keinen vollstaendigen Dateierfolg, wenn im Nutzerauftrag explizit benannte Ausgabedateien oder das ZIP fehlen. Fehlende Werkzeuginstruktionen werden im gespeicherten Arbeitsweg als Fehler ausgewiesen, statt einen Download zu erfinden.
- Neuer PHP-Selbsttest fuer mehrteilige Projekte, ZIP und Dateivollstaendigkeit, in beide Preflights integriert; Frontend-Vertrag geprueft. Ein lokaler isolierter Python-ZIP-Probelauf war erfolgreich. Echte `ember-coreui:latest`-Live-Abnahme bleibt offen.
- Keine Migration, keine Aenderung des Installer-One-Liners, keine GitHub Actions und kein neues geprueftes ZIP/SHA-Release.

## 0.5.12 - 21.10.2026

- Der Info-Schalter unter gespeicherten E.M.B.E.R.-Antworten zeigt wieder die **GESAMTDAUER**, auch nach Chatwechsel und Browser-Reload. Bisher war die Antwortzeit nur fluechtig an den Nachrichtentimestamp gebunden.
- Neue Modellstreams speichern zusaetzlich die **MODELLZEIT** (nur Provider-HTTP-Anfrage, ohne Warteschlange), getrennt von der Gesamtverarbeitung mit Werkzeugen, Dateierstellung und Speicherung.
- Bereits vorhandene 0.5.11-Arbeitsevents liefern weiterhin ihre gespeicherte Gesamtdauer. Bei fehlender Eventdauer steht die gekennzeichnete, aus autorisierten Agentenlauf-Timestamps berechnete **AGENTENLAUFZEIT** zur Verfuegung. Fehlende Modellzeiten werden nicht erfunden.
- JavaScript-UI-Selbsttest, PHP-Agentenlauf-Test, Frontend-Regressionspruefung und beide Preflights erweitert. Keine Migration, keine Installer-One-Liner-Aenderung, keine GitHub Actions.

## 0.5.11 - 21.10.2026

- P06: Live-Arbeitsschritte bleiben nach der fertigen E.M.B.E.R.-Antwort direkt in derselben Nachricht erhalten. Der zuletzt beendete Arbeitsweg ist zunaechst aufgeklappt; ältere Nachrichten besitzen den deutlichen Schalter `Arbeitsweg (N Schritte) anzeigen`.
- Der Chat kombiniert ausschliesslich bereinigte, tatsaechlich aufgezeichnete SSE-Ereignisse mit der authentifizierten Datenbank-History. Antwortwechsel, Polling und Reload verlieren die Chronik nicht; Ereignis-IDs werden ohne Doppelanzeige zusammengefuehrt.
- Serverseitige Antwortzuordnung nutzt bei unterbrochener separater Event-Bindung den bereits kontogebundenen Agentenlauf, ohne Turn-, Sitzungs- oder Variantenvermischung. SSE-Reconnect und bereits abgeschlossene Aktionen liefern die vorhandenen Arbeitsschritte erneut, ohne das Modell zu wiederholen.
- P06-UI- und PHP-Persistenzregressionen sowie Native-/Compose-Preflight erweitert. Keine Migration, kein neuer Hintergrunddienst und keine Aenderung des Installer-One-Liners.
- Sichtbar sind operative Arbeitsschritte, keine rohen Modellgedanken oder vertraulichen Werkzeugdaten. GitHub Actions nicht gestartet; Live-Abnahme auf dem Server noch ausstehend.

## 0.5.10 - 21.10.2026

- Optional anklickbare Folgefragen unter gespeicherten Ember-Antworten, wenn die Antwort mit einer expliziten Auswahlfrage und 2 bis 4 Stichpunkten endet. Schaltflaechen verwenden die normale Chat-Sendefunktion und erlauben keine HTML-/Codeausfuehrung.
- Mobile und Desktop: skalierende, per Tastatur bedienbare Vorschlags-Schaltflaechen; keine gesonderte Modellanfrage bei der Anzeige.
- Roadmap P20: Eigener Plan fuer native CPU-Bildgenerierung mit stable-diffusion.cpp als C/C++-Referenz, FLUX.1-schnell/SDXL-Turbo als Kandidaten, Lizenz-/Formatpruefung, globaler serieller Modellslot, getrennten Ressourcenbudgets und echten Benchmarks.
- Keine Bildengine/Modellgewichte ausgerollt. Bestehende Downloads, Sitzungen, Installationsschema und Modellbetrieb unveraendert. GitHub Actions nicht gestartet.

## 0.5.9 - 21.10.2026

- P05: Migration 013 speichert private Agentenlaeufe und eine globale persistente FIFO-Warteschlange fuer alle Ollama-HTTP-Aufrufe. Ein MariaDB GET_LOCK bleibt die verbindliche Grenze fuer genau einen gleichzeitigen Modellaufruf.
- Kontogebundene Run-IDs, atomare Zustandswechsel, deduplizierte SSE-Wiederverbindungen und sichere Antwortbindung; keine stillen Wiederholungen unklarer Provideraufrufe.
- Die neue Chat-Stop-Schaltflaeche fordert ueber einen CSRF-geschuetzten Endpunkt Abbruch an. Wartende Runs stoppen sofort; laufende Aufrufe werden kooperativ abgebrochen. Ein beendeter HTTP-Transport beweist nicht das Ende einer Cloudprovider-internen Arbeit.
- Browser-Jobs werden an den eigenen Run gebunden und nach Abschluss mit gespeicherten Antworten abgeglichen, ohne das Modell automatisch neu zu starten.
- FIFO-Tickets besitzen Warte-Heartbeat, Besitzerverbindung, begrenzte Wartezeit und sichere Freigabe. Alte abgeschlossene Tickets werden bereinigt. Verschachtelte Aufrufe teilen denselben Modellslot.
- Native und Compose-Preflight pruefen beide P05-Schemata, PHP-Syntax und Offline-Regressionen fuer Agentenstatus, Kontotrennung, FIFO, Timeout und Abbruch.
- **Noch offen:** Echte MariaDB-/Cloud-/Neustart-/Android-Abnahme, providerseitige Reconciliation unklarer Remoteanfragen, unbegrenzte Hintergrundwarteschlange und allgemeine P07/P26-Checkpoint-Fortsetzung. Keine GitHub Actions und kein neues geprueftes ZIP/SHA-Release.

## 0.5.8 - 20.10.2026

- Chatlayout wie bei ChatGPT/Claude: Nur Nutzernachrichten behalten Sprechblasen. E.M.B.E.R. antwortet als vollbreiter, freier Markdown-Text; auch auf Android. Der Live-Arbeitsverlauf ist Teil derselben Nachricht, kein zweiter "DENKSTATUS"-Kasten mehr.
- P06-Verlauf wird in einem einzigen Schritt mit den authentifizierten `console_messages.php`-Antwortdaten geladen. Die fehlschlagende Extraabfrage fuer jede Antwort entfaellt aus dem Chat. SSE liefert die Ereignisse ebenfalls beim Abschluss. Ereignisse bleiben nach Benutzer, Sitzung und Antwort getrennt.
- Python-Worker rettet regulaere Dateien aus dem eigenen isolierten Arbeitsverzeichnis, die Cloud-Modelle abweichend von `EMBER_OUTPUT_DIR` abgelegt haben. Vollstaendige HTML-stdout-Ausgaben koennen ebenfalls zu echten Downloads werden, aber nur ueber den vorhandenen validierten Artefaktpfad.
- Das Ausbleiben einer Ausgabedatei loest bei expliziter Download-Anfrage auch nach Exitcode 0 einen begrenzten Korrekturversuch aus; vorheriger Python-Code wird dabei beruecksichtigt. Keine erfundenen Downloadlinks, keine parallelen Modellaufrufe.
- Sicherer Offline-Workspace-/HTML-Test, Frontend- und Versionspruefer sowie 0.5.8-Release-TXT/Uebergabe. One-Liner unveraendert, GitHub Actions bleiben aus.
- Live-Abnahme mit `gpt-oss:120b` und echten HTML-/ZIP-Downloads auf Android noch offen, Issue #29 bleibt bestehen.

## 0.5.7 - 20.10.2026

- P06-Ausbaustufe: Der bisherige synthetische Denkstatus wird bei neuen Console-Antworten durch gespeicherte, echte Arbeitsschritte abgeloest. Sie werden via SSE live gezeigt und nach Seitenreload aus der Datenbank nachgeladen.
- Migration 012: Kontogebundene und antwortbezogene Modell-, Python-Werkzeug- und Dateiereignisse; eigener authentifizierter Lese-Endpunkt. Antwortvarianten erhalten getrennte Logs.
- Echte Fehlerdiagnose: Python-Job-ID, Exitcode, Laufzeit, bekannte Fehlerklasse wie FileNotFoundError, optional sicherer Dateiname, Reparaturversuch und bestaetigte Dateiausgaben statt pauschaler Fehlermeldung.
- Darstellungs- und Sicherheitsgrenzen: Kein Roh-Thinking, kein Prompt, kein Python-Quellcode, kein stdout/stderr oder Geheimnis im oeffentlichen Ereignisprotokoll. Ausklappbare Details auch auf Android.
- Beide Installer/Preflights, geschuetzte Nginx-Routen, Branding, HTML/JS, README, Changelog-TXT und Uebergabe synchronisiert. Original-One-Liner und GitHub-Actions-Verzicht unveraendert.
- Versionskanon: 0.5.6 -> 0.5.7 bei diesem Merge, ohne Suffix. Vollstaendige P05/P06/P07-Agentenfunktionen sowie Live-Abnahme der echten HTML-/ZIP-Downloads (#29) bleiben offen.

## 0.5.6 - 20.10.2026

- PR #39: gpt-oss:120b Cloud-Dateierstellung ueber den isolierten Python-Worker verbessert. /mnt/data zeigt innerhalb der Sandbox auf denselben begrenzten Ausgabeordner wie /work/output; keine Nutzerdaten oder Hostverzeichnisse werden eingebunden.
- PR #39: Eine echte Downloadkarte wird nur fuer vom Worker registrierte Dateien angeboten. Nach FileNotFoundError ist maximal ein serieller Korrekturversuch erlaubt. Vollstaendiger HTML-Quelltext kann alternativ als echte Datei gespeichert werden; unvollstaendige Ausgaben werden abgewiesen.
- PR #39: Python-Code wird bei Ueberlaenge nicht mehr still gekuerzt. Regressionspruefungen fuer Cloud-Modell-Dateien erweitert.
- Pflichtregel bestaetigt: JEDER Merge nach main erfordert bereits im selben PR einen numerischen Versionssprung in VERSION. PR #39 hatte diesen versaeumt; das wird hier nachvollziehbar berichtigt, ohne alte Releasearchive umzubenennen.
- Aktive Versionsanzeigen, Readme, Versionspruefungen, eigenes TXT-Changelog und Uebergabeprotokoll auf 0.5.6 abgeglichen. Lokaler Pre-Merge-Guard hinzugefuegt. Keine GitHub Actions.
- End-to-End-Livetest unter gpt-oss:120b und reales ZIP/SHA-Release weiterhin ausstehend. Issue #29 bleibt offen.

## 0.5.5 - 20.10.2026

- Numerische CoreUI-Version ohne Suffix wiederhergestellt. Die sechs aktiven HTML-Seiten und der JavaScript-Bootstrap zeigen konsistent `0.5.5`; das Versionsformat wird in Installer und Tests gesichert.
- Cache-Busting von manuell gepflegten Versions- oder Textlabels entkoppelt. Jede CSS-/JS-Datei erhaelt bei der Installation einen eigenen, rein numerischen, aus SHA-256-Dateibytes abgeleiteten Fingerabdruck. Unveraenderter Inhalt bleibt cachebar, Aenderungen invalidieren den jeweiligen Asset-Cache.
- Beide Installer und Preflights verwenden denselben Cache-Buster. Nginx verlangt Revalidierung von HTML, waehrend Fingerprint-Assets weiterhin cachebar sind. Beim atomaren HTML-Update bleiben Leserechte und Eigentum erhalten.
- One-Liner unveraendert; `main` ist die Quelle fuer Live-Updates. Der exakte Git-Commit bleibt separat nachweisbar, alte gepinnte Archive werden nicht umbenannt.
- Gemeinsamer Masterplan, Roadmap und 82 erfasste Funktionen fuer OpenClaw, Open WebUI, CrewAI, LangGraph und AutoGen wurden in GitHub gesichert.
- Gespeicherte Ausgabedateien, Chat-Dateikarten, geschuetzte Endpunkte und Migration 011 fuer Artefakte wurden zusammengefuehrt. Die eigentliche End-to-End-Abnahme auf dem Server und Issue #29 bleiben offen.
- Berechtigungsfehler beim Checkout, Live-Updater, HTML-Data-Link-Ausgaben und ein veralteter SSE-Selbsttest wurden behandelt.
- Quellenstand 0.5.5 war der main-Stand nach PR #38; ein neuer, eigenstaendig verifizierter Release-ZIP/SHA-256-Satz ist damit noch nicht behauptet. `0.5.4` war vorbereitet, aber nicht als eigenstaendiges Paket veroeffentlicht. Die historischen `-alpha`-Archive bleiben unveraendert erhalten.
- Keine GitHub Actions fuer diese Entwicklungs- und Live-Testschritte.


## v0.5.3-alpha - 05.09.2026

- Produktionspfad fuer Gemma 4 + Ollama auf das waehrend der mehrstuendigen CoreUI-Validierung ermittelte Minimalprofil ausgerichtet.
- Normale Ollama-Aufrufe senden standardmaessig nur `temperature=1.0`, `top_p=0.95` und `top_k=64` innerhalb von `options`.
- `num_ctx`, `num_predict`, Thread-, Repeat-, Seed- und Stop-Parameter werden im normalen Generierungspfad nicht mehr automatisch injiziert.
- Synchroner Antwortpfad und privater SSE-Pfad verwenden dieselbe gemeinsame Known-Good-Options-Policy und koennen dadurch nicht mehr still auseinanderlaufen.
- Vision-Aufrufe und Fortsetzungen abgeschnittener Antworten erzwingen keine alten Kontext- oder Predict-Budgets mehr.
- Historische beziehungsweise backend-spezifische Tuningparameter bleiben fuer gezielte A/B-Tests hinter `STU_EMBER_UNVERIFIED_TUNING_ENABLED` und expliziter Konfiguration verfuegbar.
- Die Aenderung behandelt die beobachtete Performanceverbesserung bewusst als Ergebnis des gesamten Minimalprofils und schreibt sie nicht allein `num_ctx` zu.
- Keine neue Datenbankmigration. Migration 009 bleibt der aktuelle Schema-Stand; Konten, Sitzungen, Nachrichten, Reaktionen, Revisionen, Anhaenge, Uploads, private RAG-Daten und lokale Konfiguration bleiben erhalten.
- Version, Cache-Buster, Setup, README, Changelog-Archiv, Preflight-relevante Versionsreferenzen, Uebergabeprotokoll, Release-ZIP und SHA-256 auf `0.5.3-alpha` aktualisiert.

## v0.5.2-alpha - 02.09.2026

- Produktiv gemeldeten Stillstand bei exakten Rechenauftraegen wie `123456789 * 987654321` behoben. Der bisherige Pfad konnte erst eine Streaming-Generierung, danach den synchronen Modellfallback, den Python-Worker und einen weiteren Modellaufruf starten.
- Deterministischen lokalen Schnellpfad fuer genau einen binaeren Ganzzahlausdruck eingefuehrt. Addition, Subtraktion und Multiplikation mit `*`, `x` oder `×` werden ohne Ollama und ohne Python abgeschlossen.
- Eigene dezimale String-Arithmetik fuer vorzeichenbehaftete Operanden bis 512 Ziffern implementiert. Das Ergebnis bleibt unabhaengig von der PHP-Integerbreite exakt.
- Schnellpfad bewusst auf eindeutige Aufgaben begrenzt. Anhaenge, Bilder, Dezimalzahlen, Divisionen, Potenzen, Kettenrechnungen, mehrere numerische Vorgaben und verlangte Rechenwege bleiben im normalen Modell- und Werkzeugpfad.
- Privaten SSE-Pfad vor dem Promptbau um die lokale Berechnung erweitert. Das Ergebnis wird weiterhin als regulaere Ember-Nachricht mit derselben `reply_to_id` gespeichert und ueber das normale `done`-Ereignis abgeschlossen.
- Fuer eine lokal abgeschlossene Rechnung wird kein Console-AFK gesetzt. Modell- und Werkzeuglaeufe behalten ihr bisheriges AFK-Verhalten.
- Nachgelagerte Memory-, Reflexions- und Reputationsauswertung fuer den lokalen Rechenschritt unterbunden. Das Ergebnis bleibt als Chatnachricht gespeichert, startet aber keinen verborgenen weiteren Modellaufruf und wird nicht als Langzeitgedaechtnis behandelt.
- Gemeinsamen synchronen Antwortgenerator ebenfalls abgesichert, damit globale oder nicht streamende Aufrufer denselben exakten Abschluss erhalten.
- Metadaten des lokalen Abschlusses explizit gesetzt, damit kein alter Guardrail-, Thinking- oder Timeoutzustand in den neuen Turn hineinragt.
- Reply-Pipeline-Selftest um den gemeldeten Multiplikationsfall, grosse Zahlen, Vorzeichen, Uebertraege, negative Ergebnisse und strikte Ablehnungsfaelle erweitert.
- Nach dem ersten `0.5.2-alpha`-Paket gemeldeten Preflight-Fehler korrigiert: Ein Punkt oder Komma direkt nach dem zweiten Operanden gilt nun als Satzzeichen und nicht mehr pauschal als Dezimaltrennzeichen.
- Schnellpfad auf ASCII-Ganzzahlen begrenzt und ungueltige Operanden werden sicher abgelehnt. Unicode-Ziffern koennen dadurch nicht mehr als falsches Ergebnis `0` enden.
- Routing gegen Bereiche, Kapitel- und Episodenangaben, Hexadezimalwerte sowie deutsche und englische Erklaerungswuensche gehaertet.
- Beschriftete Bereiche besitzen Vorrang vor Rechenwoertern, waehrend eindeutige Ergebnisformulierungen wie `Was ergibt 12*12?` und `Nenne das Ergebnis von 12*12.` lokal bleiben.
- Lokale Rechenschritte loeschen keinen fremden Console-AFK-Status mehr. Der synchrone Pfad prueft die Rechnung vor dem globalen Ollama-Lock.
- Regressionstest um Satzzeichen, Unicode-Ziffern, `0x10`, Bereichsangaben, englische Erklaerungswuensche, AFK-Eigentum und lockfreien Synchronabschluss erweitert.
- Keine neue Datenbankmigration. Migration 009, Konten, Sitzungen, Nachrichten, Reaktionen, Revisionen, Anhaenge und lokale Konfiguration bleiben unveraendert erhalten.
- Version, Cache-Buster, Setup, README, Changelog-Archiv, Branding-Test, Preflight und Uebergabeprotokoll auf `0.5.2-alpha` aktualisiert.

## v0.5.1-alpha - 01.09.2026

- Praktischen Produktivtest von `0.5.0-alpha` ausgewertet: Markdown, Codebloecke, Kopieren, Neugenerierung, Antwortdetails und alle bestehenden Werkzeuge funktionierten; persistentes Feedback und der gewuenschte Bearbeitungsfluss benoetigten Korrekturen.
- Feedbackspeicherung fuer bereits bestehende STU-Reaktionstabellen gehaertet. Migration `009_message_editing` erweitert den privaten Kanalwert auf `console`, normalisiert die Emoji-Spalte auf `utf8mb4` und CoreUI speichert neue Bewertungen als altkompatible ASCII-Tokens.
- Fehlerhafte Feedbackaufrufe bleiben nicht mehr als stilles rotes Aufblitzen stehen, sondern zeigen zusaetzlich einen konkreten Aktionsfehler an.
- Alternative Ember-Antworten werden nach `reply_to_id` gruppiert. Eine Vorher-/Naechster-Navigation mit Zaehler zeigt alle dauerhaft gespeicherten Varianten auch nach einem Seitenreload.
- Fortsetzungen bleiben an ihre konkrete Quellvariante gebunden und werden nur zusammen mit dieser Variante angezeigt.
- Bearbeiten einer Benutzernachricht arbeitet nun wirklich auf derselben Nachrichten-ID und derselben Blase. Der bisherige "als neue Nachricht"-Entwurfspfad wurde entfernt.
- Migration 009 fuehrt eine kontogebundene Revisionshistorie und den Generierungsmodus `edit` ein. Der Verlauf vor der bearbeiteten Nachricht bleibt Modellkontext; spaetere, auf dem alten Text beruhende Nachrichten werden aus dem aktiven Zweig ausgeblendet und bleiben im Kontoexport nachvollziehbar.
- Modellkontext und SSE-Idempotenz ignorieren ausgeblendete Nachrichten konsequent.
- Der Nachrichteneditor bleibt waehrend einer laufenden Ember-Antwort benutzbar. Abgesendete Folgetexte erscheinen als wartende Benutzer-Turns und werden nach Abschluss der aktuellen Antwort automatisch gesendet.
- Vorgemerkte Texte koennen vor dem Senden wieder entfernt werden. Der vorhandene lokale Sende-Cooldown wird beim automatischen Weiterreichen eingehalten.
- Fortsetzungsaktion verstaendlicher als Aktion fuer unvollstaendige Antworten beschriftet.
- Kontoexport um Nachrichtenrevisionen und ausgeblendete Zweige erweitert, ohne Thinking-Inhalte oder temporaere Anforderungstoken offenzulegen.
- Healthcheck, Compose- und Native-Preflight, Installer und Nachrichtenaktionen-Selftest auf Migration 009, Revisionsspeicherung, ASCII-Feedback, echte Bearbeitung und Variantenanzeige erweitert.
- Version, Cache-Buster, Setup, README, Changelog-Archiv, Branding-Test und Uebergabeprotokoll auf `0.5.1-alpha` aktualisiert.

## v0.5.0-alpha - 01.09.2026

- Sicheren lokalen Markdown-Renderer fuer Ueberschriften, Hervorhebungen, Listen, Zitate, Links, Tabellen, Inline-Code und eingezaeunte Codebloecke eingefuehrt.
- Rohes HTML bleibt immer Text. Nur HTTP-, HTTPS- und Mail-Links werden klickbar. Der Renderer verwendet weder `innerHTML` noch `eval`, Remote-CDNs oder ausfuehrbare Modellausgaben.
- Codebloecke um Sprachkennung, lokale Syntaxfarben, horizontales Scrollen und eine eigene Kopieraktion erweitert.
- Gemeinsame Aktionsleiste unter Benutzer- und Ember-Nachrichten eingefuehrt. Benutzernachrichten koennen kopiert oder als neuer Entwurf in den Editor uebernommen werden, ohne den gespeicherten Verlauf zu veraendern.
- Ember-Antworten koennen kopiert, positiv oder negativ bewertet, als Alternative neu erzeugt, fortgesetzt und mit technischen Antwortdetails angezeigt werden.
- Feedback serverseitig an authentifiziertes Konto, private Sitzung und konkrete Ember-Nachricht gebunden. Positive und negative Bewertung schliessen sich je Benutzer gegenseitig aus.
- Alternative Antworten behalten die bisherige Antwort und dieselbe `reply_to_id`. Antworten desselben Turns werden beim Neuaufbau gezielt aus dem Modellkontext ausgeschlossen, damit die Alternative nicht nur die alte Fassung kopiert.
- Fortsetzungen als getrennte Ember-Nachrichten gespeichert und weiterhin mit dem urspruenglichen Benutzer-Turn verknuepft.
- Migration `008_message_actions` fuer kurzlebige, kontogebundene Neugenerierungsanforderungen eingefuehrt. Eine feste Nachrichten-ID-Grenze ordnet nach SSE-Unterbrechungen nur wirklich spaeter entstandene Antworten zu, ohne temporaere Anforderungstoken im Kontoexport offenzulegen.
- Gleichzeitige Nachrichtenaktionen derselben Sitzung werden durch eine Datenbanksperre serialisiert. Eine Sitzung kann nicht endgueltig geloescht werden, solange eine Alternative oder Fortsetzung noch laeuft.
- Neue API `console_actions.php` mit CSRF-Schutz, Benutzer- und Sitzungspruefung sowie getrenntem Store-Modul fuer Feedback und Generierungsanforderungen eingefuehrt.
- Live-SSE-Antworten und geladener Verlauf verwenden nun denselben Markdown-, Codeblock- und Aktionsleistenpfad.
- Browser-Recherche innerhalb einer Nachrichtenaktion wird ueber ihre exakte Job-ID mit der Anforderung verbunden. Wiederaufnahme, Workerfehler und fehlendes Ergebnis werden dadurch eindeutig abgeschlossen.
- Kontoexport um eigene Bewertungen und Generierungshistorie erweitert. Interne Thinking-Inhalte und temporaere Anforderungstoken bleiben ausgeschlossen.
- Healthcheck, Compose- und Native-Preflight, Installer und Selbsttests auf Migration 008 und die neuen Endpunkte erweitert.
- Neuer Markdown-Selftest prueft gefaehrliche Linkprotokolle, HTML-Text, Tabellen und Codebloecke. Neuer Nachrichtenaktionen-Selftest prueft Feedback, Turnbindung, Isolation und sichere Renderer-Verdrahtung.
- Version, Cache-Buster, Setup, README, Changelog-Archiv, Branding-Test und Uebergabeprotokoll auf `0.5.0-alpha` aktualisiert.
- Audioeingabe, nativer Modell-Audiotransport und gesprochene TTS-Ausgabe bleiben bewusst dem getrennten Audioausbau vorbehalten.

## v0.4.5-alpha - 31.08.2026

- Navigationsschleife zwischen Einstellungen und Admin Core behoben. `CORE CHANNEL` verwendet kein `history.back()` mehr, sondern oeffnet die App deterministisch.
- Rueckweg aus Admin Core ersetzt den Admin-Verlaufseintrag. Die Abfolge Core Channel, Einstellungen, Admin Core, Einstellungen und Core Channel bleibt dadurch bedienbar.
- TXT-Decodierung vor die Unicode-Bereinigung verschoben. Ungueltig decodierte Bytes koennen den Inhalt nicht mehr vorzeitig in einen leeren String verwandeln.
- UTF-8 mit BOM, UTF-16 LE/BE mit und ohne BOM, UTF-32 mit BOM sowie Windows-1252 als reale TXT-Varianten ergaenzt.
- Binaerpruefung fuer falsch benannte Textdateien eingebaut. Auffaellige C0-Steuerzeichen werden nicht als vermeintlicher Text an das Modell weitergereicht.
- Anhang-Pipeline-Selftest um deutsche Umlaute, Windows-1252 und mehrere UTF-Codierungen erweitert.
- Chat-Profilbilder von einem nicht ueberpruefbaren CSS-Hintergrund auf echte `<img>`-Elemente mit Lade- und Fehlerbehandlung umgestellt.
- Initialen bleiben sichtbar, bis das Benutzer- oder CoreAI-Bild erfolgreich geladen wurde. Fehlende oder unlesbare Dateien erzeugen wieder einen eindeutigen Fallback.
- Profil-API und Medienendpunkt verwenden nun dieselbe serverseitige Dateiaufloesung. Nicht vorhandene Datenbankdateien werden nicht mehr als gueltige Avatar-URL ausgegeben.
- Profil/RAG-Selftest erzeugt ein echtes PNG, prueft dessen kontogebundene URL und die lesbare Datei fuer den Medienendpunkt.
- Reply-Pipeline-Selftest definiert vor dem Laden der Installation keine Produktionskonstanten mehr. Die PHP-Warnung zu bereits definiertem `STU_EMBER_NUM_PREDICT` und verwandten Werten entfaellt.
- Reine Budgetpruefung als eigene Funktion aus dem Laufzeitpfad getrennt, damit historische 7200-Zeichen-Konfigurationen ohne Eingriff in `config.local.php` getestet werden koennen.
- Neuer Frontend-Regressions-Selftest fuer Navigation, Bildladezustand, Avatar-Fallback und gemeinsame Medienaufloesung in Compose und Native ergaenzt.
- Freigegebenes Original-Logo unter neuem Assetnamen eingebunden. Start, Login, Einstellungen und Admin Core verwenden dieselbe Datei ohne die fehlerhaften schwarzen Konturen.
- Logo-Selftest an den exakten SHA-256-Fingerabdruck des freigegebenen Originals gebunden. Die alte fehlerhafte Assetdatei wurde entfernt.
- Version, Cache-Buster, Setup, Kontoexport, README, Changelog-Archiv und Branding-Test auf `0.4.5-alpha` aktualisiert.
- Neues Uebergabeprotokoll dokumentiert Quellstand, Tests, Installationspfade, bekannte Grenzen und die geplanten Funktionsstufen ab `0.5.x`.
- Keine neue Datenbankmigration. Konten, Sitzungen, Nachrichten, Uploads, private RAG-Quellen, Profilbilder und lokale Konfiguration bleiben beim Update erhalten.

## v0.4.4-alpha - 31.08.2026

- Offizielles Repository auf die Starlight-Unit-Studio-Organisation umgestellt. Installer, README, Metadaten und Releasepfade verwenden nun `Starlight-Unit-Studio/coreui`.
- TTY-Abbruch der Blitzinstallation nach dem erfolgreichen Image-Build behoben.
- Die beiden temporaeren `docker compose run`-Aufrufe fuer PHP-Lint und Account-Bootstrap verwenden nun explizit `-T`.
- Bestehende und nicht-interaktive Installationen koennen dadurch ohne Pseudo-TTY weiterlaufen.
- Schnellinstallation in der README in einen getrennten Download- und Ausfuehrungsschritt aufgeteilt. Die eigentliche Installation laeuft damit direkt am echten Terminal.
- Irrefuehrende Root-Fehlermeldung in `setup.sh` korrigiert. Sie verweist nicht mehr auf den alten Pipe-Aufruf.
- Releasepaar, Paketwurzel und Pflichtdateien werden weiterhin vor jeder Aenderung an `/opt/ember-coreui` geprueft.
- Alle Upload-, RAG-Lite-, Python-, Datenschutz- und Installerkorrekturen aus `0.4.3-alpha` bleiben unveraendert enthalten.
- Game, Homepage, Repack, KeyHelp, Apache, Host-PHP und Game-Datenbank bleiben unangetastet.

## v0.4.3-alpha - 30.08.2026

- Blitzinstallationsbefehl wieder direkt am Anfang der README aufgenommen.
- `setup.sh` vollstaendig neu aufgebaut. ZIP und SHA-256-Datei werden in ein eindeutiges temporaeres Verzeichnis geladen, gemeinsam geprueft und erst danach nach `/opt/ember-coreui` uebertragen.
- Veraltete Festverdrahtung auf `0.3.2-alpha` entfernt. Der Installer prueft Paketordner, Versionsdatei und alle zwingenden Skripte, bevor eine bestehende Installation veraendert wird.
- Updatepfad des Blitzinstallers erhaelt `api/config.local.php`, `var`, Logs, Uploads, Chatmedien und Profilbilder. Erstinstallationen koennen weiter interaktiv oder ueber sichere Umgebungsvariablen laufen.
- Release- und Setup-Pruefung lehnt Pakete mit den historischen privaten Manuskriptdateien ab.
- Die zwei versehentlich mitgefuehrten privaten Studio-Dokumente aus dem Releasebaum entfernt. Sie werden nicht mehr installiert oder automatisch in RAG-Lite importiert.
- Migration `007_remove_private_studio_lore` ergaenzt. Sie entfernt ausschliesslich die historischen Quellen `bibel_v10_4` und `kompendium_v6` aus bereits vorhandenen globalen Ember-CoreUI-Chunks.
- Voreingestellte lokale Pfade zu privaten Studio-Manuskripten aus `api/config.php` entfernt. Der generische CLI-Importer bleibt fuer rechtmaessig bereitgestellte Betreiberquellen verfuegbar.
- Private Console-Nachrichten adressieren Ember nun serverseitig. Das Frontend haengt kein kuenstliches `@Ember` mehr an, wodurch reine Datei-Turns nicht mehr als einzelnes `@` im Modellprompt erscheinen.
- Gespeicherte 1:n-Anhangzuordnungen werden im SSE-Turn in stabiler Reihenfolge rekonstruiert und in interne Dateimarker umgewandelt, bevor die gemeinsame Anhangsauswertung laeuft.
- Neuer End-to-End-Anhangtest legt echte TXT-, Python-, DOCX- und Text-PDF-Dateien an, bindet sie transaktional an eine Nachricht, rekonstruiert sie wie der SSE-Pfad und prueft ihren Inhalt im Modellprompt.
- Maximal zehn Dateien pro Nachricht bleiben unveraendert. Rohe `[file:...]`-Marker und das historische Adresspraefix duerfen den Modellprompt nach dem Test nicht erreichen.
- Gemeinsamen `rag_lite.php`-Kern fuer Extraktion, UTF-8-Normalisierung, ueberschneidendes Chunking, Suchbegriffe und Ranking eingefuehrt.
- Privates RAG-Lite nutzt diesen gemeinsamen Kern, behaelt aber strikt benutzergebundene Quellen- und Chunk-Tabellen. Eine globale Tabelle ohne `user_id` wird nicht fuer private Daten wiederverwendet.
- Beide Modell-Promptpfade begrenzen privates Benutzerwissen hart auf `channel === 'console'`. Globale Chatantworten koennen keine privaten RAG-Chunks mehr erhalten.
- DOCX-Ueberschriften bleiben bei der Extraktion als Strukturhinweise erhalten. Volltextsuche verwendet fehlertolerantes OR-Ranking statt einer zu strikten Pflichtwort-Abfrage.
- Generische Fragen wie "Fass meine hochgeladene Datei zusammen" koennen kontrolliert auf die zuletzt aktualisierte eigene Wissensquelle zurueckfallen.
- Private RAG-Uploads zusaetzlich fuer Python-, CSV-, JSON-, XML-, YAML-, INI-, PHP-, JavaScript-, HTML-, CSS- und SQL-Textdateien freigegeben.
- Fehlenden Python-Queue-Worker in den Compose-Stack aufgenommen. Der vertrauenswuerdige Worker besitzt den Docker-Socket, der generierte Modellcode laeuft dagegen ausschliesslich in einem wegwerfbaren Container ohne Projekt-, Konfigurations- oder Datenbank-Mount.
- Eigenes `docker/pyworker`-Image und Native-systemd-Dienst ergaenzt. Das eigentliche Sandbox-Image `ember-py:1` wird beim ersten Start gebaut.
- Echter Python-Preflight reiht einen Job ein, wartet auf den Worker und prueft die Ausgabe aus dem isolierten Sandbox-Container.
- Compose-, Native-, Health-, Installer- und Preflight-Pfade auf Version `0.4.3-alpha`, Migration 007, Python-Worker und neue Pipeline-Selftests aktualisiert.
- Veraltete Zukunftsaussage zur STU Console aus der README entfernt. Die interne STU Console wurde bereits mit Game Alpha `v1.1.1.98` am 20.08.2026 entfernt; Ember CoreUI ist ihre eigenstaendige Nachfolge- und Referenzoberflaeche und ersetzt nicht den Global-Chat.
- Produktive Dokumentationsadresse bleibt `https://coreui.starlight-unit.de`.
- Game, Homepage, KeyHelp, Apache, STU-Repack, Game-Datenbank und andere Projektpfade bleiben unangetastet.

## v0.4.2-alpha - 29.08.2026

- Neue `Starlight Unit Studios Ember CoreUI Community Source Lizenz 1.0` eingefuehrt. Die deutsche Fassung in `LICENSE.de.md` ist rechtlich massgeblich; `LICENSE.md` enthaelt die englische Uebersetzung.
- Lizenzmodell als Source Available und nicht als OSI-zertifiziertes Open Source gekennzeichnet.
- Projekt-eigener Code bleibt fuer private, gemeinschaftliche und interne Nutzung kostenlos. Auch interne Unternehmensnutzung ist ohne Lizenzgebuehr erlaubt, solange alle Bedingungen und das Branding eingehalten werden.
- Nutzergrenze vollstaendig verworfen. Es gibt weder eine 50-Nutzer-Ausnahme noch irgendeine andere Schwelle fuer Branding-Aenderungen.
- Entfernung, Ersetzung, Verschleierung oder irrefuehrende Abschwaechung des Ember-CoreUI- und Starlight-Unit-Studios-Brandings fuer jede Bereitstellung untersagt.
- Verbindliche Namensregel aufgenommen: Der Produktname lautet immer `Ember CoreUI`; die alleinstehende Produktbezeichnung `CoreUI` ist fuer dieses Projekt unzulaessig.
- Technische Kennungen wie `coreui`, `ember-coreui`, `COREUI_*`, `/opt/ember-coreui`, `X-CoreUI-CSRF`, Repository-Slugs und Domainbestandteile als reine Kompatibilitaetsbezeichner dokumentiert.
- Kostenpflichtiges White-Label-, Enterprise-, Abonnement-, Spenden-, Werbe- und Supportmodell fuer das offizielle Ember-CoreUI-Projekt ausgeschlossen.
- Monetarisierten Weiterverkauf als SaaS oder Managed Service durch Dritte untersagt. Kostenlose Selbstbereitstellung und interne Unternehmensnutzung bleiben erlaubt.
- Schriftliche Branding-Ausnahmen nur als individuelle Ermessensentscheidung vorgesehen. Die aktuelle Community-Richtlinie verlangt dafuer keine Ember-CoreUI-Lizenzgebuehr und begruendet keinen Anspruch auf Genehmigung.
- Bei veraenderter Weitergabe muessen der vollstaendige entsprechende Quellcode, dieselbe Lizenz, Herkunftshinweise und das geschuetzte Branding erhalten bleiben. Rein interne Aenderungen muessen nicht veroeffentlicht werden.
- Ausdruecklich festgehalten, dass die Lizenz keine Nutzerzaehlung, Registrierung, Lizenztelemetrie oder Phone-home-Funktion verlangt.
- `LICENSE_HISTORY.md` ergaenzt. Fruehere Kopien behalten exakt die mit ihnen verbreiteten Bedingungen; Kopien mit ausdruecklichem CC-BY-NC-SA-4.0-Hinweis behalten diese Gewaehrung. Die neue Lizenz beginnt ohne rueckwirkende Neulizenzierung mit `0.4.2-alpha`.
- `TRADEMARKS.md`, `COMMUNITY_POLICY.md` und `NOTICE.md` fuer Markenfuehrung, kostenlose Projektpolitik, Herkunft und Drittkomponenten ergaenzt.
- Unabhaengigkeitserklaerung aufgenommen: Ember CoreUI ist kein Fork des getrennten Admin-Template- und UI-Komponentenprojekts unter `coreui.io`, enthaelt keine Abhaengigkeit davon und ist damit nicht verbunden.
- Sichtbare Produkttexte, Fehlermeldungen, Installer-Ausgaben, Quellkommentare und historische Dokumentation auf die vollstaendige Bezeichnung `Ember CoreUI` bereinigt. Protokollrelevante technische Header bleiben aus Kompatibilitaetsgruenden unveraendert.
- Oeffentliche Startseite um eindeutige Autoren-, Beschreibungs-, Open-Graph-, Canonical- und strukturierte `SoftwareApplication`-Metadaten ergaenzt. Darin werden Ember CoreUI, Starlight Unit Studios und der kostenlose Zugang maschinenlesbar zugeordnet. Authentifizierte Seiten tragen `noindex,nofollow`.
- Keine neue Datenbankmigration, keine Bezahlschranke, keine Lizenzschluessel und keine Aenderung an Benutzer-, Chat-, RAG-, Profil- oder Sitzungsdaten.
- Alle Funktionen und Sicherheitskorrekturen aus `0.4.1-alpha` unveraendert uebernommen.

## v0.4.1-alpha - 29.08.2026

- Kontosicherheitsbereich in den Benutzereinstellungen ergaenzt. Er trennt Geraete-Anmeldungen klar von den bereits vorhandenen Chat-Sitzungen.
- Sicheren Passwortwechsel mit aktuellem Passwort, Mindestlaenge 12, Bestaetigung, Rate-Limit, Passwort-Hashing und Audit eingefuehrt.
- Nach einem Passwortwechsel werden alle vorhandenen Login-Sitzungen widerrufen und ausschliesslich fuer das aktuelle Geraet ein frisches Token ausgegeben.
- Neue SQL-Tabelle `stu_auth_sessions` fuer widerrufbare Anmeldungen ergaenzt. In der Datenbank liegt nur SHA-256 des zufaelligen Tokens, niemals das Roh-Token.
- Bestehende authentifizierte PHP-Sitzungen werden beim ersten Aufruf transparent in die SQL-Schicht uebernommen.
- Aktive Anmeldungen zeigen Geraetetyp, Browser, letzte Aktivitaet und Ablaufzeit. Token und Token-Hash werden nie an den Browser gesendet.
- Einzelwiderruf einer fremden eigenen Anmeldung sowie Sammelwiderruf aller anderen Geraete umgesetzt. Die aktuelle Sitzung kann nicht versehentlich ueber den Einzelwiderruf entfernt werden.
- SQL-Anmeldungen laufen nach 30 Tagen Inaktivitaet ab und werden bei Aktivitaet hoechstens einmal pro Minute aktualisiert.
- Passwort-Reset widerruft nun ebenfalls alle aktiven Login-Sitzungen.
- Privaten JSON-Kontoexport ergaenzt. Enthalten sind Konto-Metadaten, Profil, Avatar-Daten, KI-Einstellungen, eigene Charaktere, eigene Memories, private RAG-Quellen und Chunks, Chat-Sitzungen, Nachrichten sowie Anhangmetadaten.
- Passwort-Hashes, Reset-Codes, Login-Token, Token-Hashes, interne Thinking-Inhalte, private Serverpfade und hochgeladene Dokumentbinaerdaten sind vom Export ausgeschlossen.
- Jede Exportabfrage ist serverseitig an die authentifizierte `user_id` gebunden. Globaler Studio-Kanon wird nicht als persoenlicher Besitz exportiert.
- Freie Texteingabe fuer Ollama-Modellnamen durch eine echte Auswahlliste der lokal installierten Modelle ersetzt.
- Der Modellkatalog wird nur von der betreiberkonfigurierten `STU_EMBER_OLLAMA_URL` abgeleitet. Benutzer koennen weder Host noch Protokoll beeinflussen; HTTP-Redirects sind deaktiviert.
- Eine Modellwahl wird unmittelbar vor dem Speichern erneut gegen `/api/tags` geprueft. Nicht installierte oder ungueltige Modellnamen werden abgelehnt.
- Serverstandard bleibt als explizite Option erhalten. Bereits gespeicherte, inzwischen entfernte Modelle werden sichtbar als nicht mehr installiert markiert.
- Nginx-Allowlists fuer Compose und Native um `account_security.php`, `account_export.php` und `models.php` erweitert.
- Migration `006_account_security` ergaenzt `password_changed_at`, `last_login_at` und die neue Auth-Sitzungstabelle mit Benutzer-Fremdschluessel und Aktivindex.
- Healthcheck, Compose-Preflight, Native-Preflight und beide Installer auf Migration 006 und die neuen Endpunkte erweitert.
- Neuer Account-Security-Selftest prueft Tokenabschirmung, CSRF- und Passwortgrenzen, Exportausschluesse, Modellvalidierung sowie SQL-Listen- und Widerrufslogik in einer zurueckgerollten Testtransaktion.
- PHP-Grenze `max_file_uploads` fuer Compose und Native auf zehn angeglichen. Die bestehende produktive Anhanglogik bleibt weiterhin bei maximal zehn Dateien je Nachricht.
- Versionsparameter, Healthcheck, README, TXT-Changelog-Archiv und Uebergabeprotokoll auf `0.4.1-alpha` aktualisiert.
- Produktive Adresse bleibt `https://coreui.starlight-unit.de`. Die fruehere Adresse `webui.starlight-unit.de` ist nur noch historischer Kontext.
- Game, Homepage, KeyHelp-VHosts, Apache, STU-Repack, Game-Datenbank, bestehende Ember CoreUI-Konfiguration und persistente Nutzerdaten bleiben unveraendert.

## v0.4.0-alpha - 29.08.2026

- Bisher teilweise lokale Einstellungsseite zu einem echten kontobezogenen Konfigurationsbereich ausgebaut. Profil, KI-Laufzeitwerte, Memory und privates Wissen werden serverseitig dem authentifizierten Benutzer zugeordnet.
- Eigenes Ember CoreUI-Profil je Benutzer mit sichtbarem Anzeigenamen eingefuehrt. Die Login-E-Mail, numerische Benutzer-ID und stabile interne Operator-ID bleiben bei einer Namensaenderung unveraendert.
- Frei waehlbaren CoreAI-Anzeigenamen je Konto ergaenzt. Die sichtbare Bezeichnung wird in Chatblasen, Denkstatus, Tippanzeige und Eingabeplatzhalter verwendet, ohne Embers technische Identitaet oder Sicherheitsregeln umzuschreiben.
- Zwei getrennte private Avatar-Slots aufgenommen: Benutzer und CoreAI. Beide koennen in den Einstellungen hochgeladen, ersetzt und geloescht werden.
- Avatarannahme auf PNG und JPEG bis 4 MiB begrenzt. Abmessungen, Pixelmenge, MIME-Typ und echte Dekodierbarkeit werden serverseitig geprueft.
- Hochgeladene Bilder werden zentral quadratisch zugeschnitten, auf maximal 512 mal 512 Pixel skaliert, neu als PNG kodiert und ohne fremde Bildmetadaten gespeichert.
- Avatar-Dateien aus dem statischen Webroot herausgehalten. `profile_media.php` liefert nur den Avatar des angemeldeten Kontos mit privatem Cache-Verhalten aus.
- Zufallsbasierte Medien-ID als exakten Cache-Buster verwendet. Auch zwei unmittelbar aufeinanderfolgende Avatar-Uploads zeigen sicher den neuesten Stand.
- Neue Profilendpunkte `profile.php` und `profile_media.php` samt gemeinsamem `profile_store.php` hinzugefuegt. Schreiboperationen verlangen Authentifizierung und das technische `X-CoreUI-CSRF`-Token.
- Automatische Altaccount-Provisionierung eingebaut. Bereits vorhandene Benutzer erhalten beim naechsten Login ein Profil und, falls bisher keiner existiert, einen stabilen privaten Operator.
- Gemeinsames transaktionales Account-Provisioning fuer Registrierung und Admin Core eingefuehrt. Neue Benutzer starten nicht mehr ohne Charakter- und Profildaten.
- Admin Core um eine echte Benutzeranlage mit Anzeigename, Login-E-Mail, Startpasswort und Rolle erweitert. System-Admin kann Level 1 bis 4, Admin kann nur niedrigere Rollen Level 2 bis 4 vergeben.
- Startpasswoerter aus dem Admin Core auf mindestens 12 Zeichen begrenzt. Passwortwerte werden weder im Audit noch in API-Antworten gespeichert oder ausgegeben.
- Benutzerliste um Profilname und internen Operator erweitert. Suche findet jetzt Login-E-Mail, sichtbaren Namen und Operatornamen.
- Einstellungsseite um einen optionalen lokalen Ollama-Modelltag erweitert. Der gespeicherte `model_override` wird nun im gemeinsamen Chatpfad tatsaechlich ausgewertet; leer verwendet weiterhin den Serverstandard.
- System-Prompt, Memory-Schalter, Memory-Limit, Antwortbudget und Temperatur bleiben pro Benutzer gespeichert und werden gemeinsam mit Profil und Modellwahl geladen.
- Kontobezogenen Modell-Thinking-Schalter ergaenzt. Ember CoreUI sendet den offiziellen Ollama-Parameter `think` bei Hauptantworten als `true` oder `false`, statt nur die Anzeige zu verstecken.
- Bei deaktiviertem Thinking werden keine Denkstatus-Ereignisse und kein Denkstatus in neuen Verlaufszeilen gespeichert. Die bestehende Schutzschranke bleibt auch bei aktiviertem Thinking bestehen: Rohe Modellgedanken verlassen den Server nicht.
- Composer auf bis zu zehn Dateien pro Nachricht erweitert. Mehrfachauswahl, sequentieller Upload mit Fortschritt, einzelne Entfernung vor dem Senden und Wiederherstellung der Auswahl bei einem Sendefehler sind enthalten.
- Vor dem Senden entfernte Uploads werden ueber einen CSRF-geschuetzten Eigentuemercall geloescht, sofern keine Nachricht sie inzwischen referenziert. Zeilensperren schliessen das Rennen zwischen Entfernen und gleichzeitigem Nachrichten-INSERT.
- Neue Zuordnungstabelle `stu_console_message_attachments` speichert Anhaenge geordnet und benutzergebunden. `file_uuid` bleibt nur fuer Altclients und bereits vorhandene Einzeleintraege erhalten.
- Nachrichtenverlauf und optimistische Clientzeilen liefern jetzt ein `attachments`-Array. Alte Clients koennen weiterhin das erste Element ueber das kompatible Feld `attachment` lesen.
- Serverseitige Eigentumspruefung fuer jede Anhang-UUID eingebaut. Fremde, fehlende oder mehr als zehn UUIDs werden vor dem Nachrichten-INSERT abgelehnt; Nachricht und Zuordnungen werden gemeinsam transaktional gespeichert.
- Gemeinsame Anhangsauswertung auf bis zu zehn Text-, Dokument- oder Mediendateien erweitert. Das Textbudget wird kontrolliert aufgeteilt und Vision-Eingaben werden auf hoechstens 16 Bilder, Frames oder PDF-Seiten begrenzt.
- Sitzungsloeschung um Mehrfachanhaenge erweitert. Zuordnungen werden per Fremdschluessel entfernt und Mediendateien nur geloescht, wenn weder alte `file_uuid`- noch neue 1:n-Referenzen verbleiben.
- Externe OpenAI-kompatible Provider bleiben als deaktivierter Adapter vorbereitet. Der lokale Gemma-4- und Ollama-Pfad bleibt Referenzbetrieb; API-Schluessel gelangen weiterhin nie in Browser oder Datenbankeinstellungen.
- Privates RAG-Lite direkt in den Benutzereinstellungen eingefuehrt. TXT-, Markdown-, Text-PDF- und DOCX-Dateien koennen hochgeladen, gelesen, gechunkt, gelistet und vollstaendig geloescht werden.
- Textnormalisierung, kontrollierte Unicode-Konvertierung und Chunking mit standardmaessig 1400 Zeichen sowie 180 Zeichen Ueberlappung umgesetzt.
- PDF-Import verwendet lokal `pdftotext` mit begrenzter Laufzeit. Scan-PDFs ohne Textebene werden eindeutig abgelehnt, statt einen erfundenen Dokumentinhalt zu erzeugen.
- DOCX-Import liest ausschliesslich `word/document.xml`, deaktiviert externe XML-Netzwerkzugriffe und begrenzt XML-Groesse sowie Absatzanzahl.
- Private Wissensquellen standardmaessig auf 20 MiB je Datei, 40 Quellen, 5.000.000 Zeichen und 1200 Chunks je Einzeldokument begrenzt. Betreiber koennen die Hauptquoten ueber isolierte App-Settings steuern.
- Kontospezifischen MariaDB-Lock um Quotenpruefung und Persistenz gelegt. Gleichzeitige Uploads desselben Benutzers koennen die Quelle- oder Zeichenquote nicht gemeinsam umgehen.
- Originaldateien unter `var/knowledge_uploads` gespeichert und nie direkt durch Nginx ausgeliefert. Quellenmetadaten und Chunks liegen in eigenen Ember CoreUI-Tabellen.
- RAG-Suche filtert in Volltext- und LIKE-Fallback immer nach `user_id` und Quelle. Ein Benutzer kann keine Chunks eines anderen Kontos finden oder loeschen.
- Privates Benutzerwissen klar vom globalen Studio-Kanon in `ember_knowledge_chunks` getrennt. Uploads veraendern weder Bibel- noch Kompendium-Importe.
- Relevante private Chunks in den Modellprompt aufgenommen und ausdruecklich als nicht vertrauenswuerdige Daten markiert. Dokumentanweisungen erhalten keine Systemprioritaet.
- Quellenloeschung entfernt Metadaten und Chunks in einer SQL-Transaktion und danach die gespeicherte Originaldatei. Die Oberflaeche aktualisiert Zaehler und Liste aus der Serverantwort.
- Migration `004_profiles_knowledge` hinzugefuegt. Sie erstellt Profile, Profilmedien, private Wissensquellen und private Chunks mit Benutzer- und Quellindizes, Fremdschluesseln und Volltextindex.
- Migration `005_thinking_attachments` hinzugefuegt. Sie ergaenzt den Thinking-Schalter, erstellt die 1:n-Anhangzuordnung und uebernimmt bestehende `file_uuid`-Eintraege verlustfrei.
- App-Defaults fuer RAG-Quellenzahl, Dateigroesse und Gesamtzeichen in Migration 004 aufgenommen, ohne vorhandene Betreiberwerte zu ueberschreiben.
- Neue private Speicherpfade `var/profile_media` und `var/knowledge_uploads` als eigene beschreibbare PHP-Volumes eingebunden. Der Webcontainer besitzt keinen direkten Mount fuer diese Dateien.
- `stack.sh` legt die neuen privaten Verzeichnisse bei `up`, `start` und `restart` mit passenden Containerrechten an. Das funktioniert auch beim normalen Update, obwohl `var/` absichtlich vom `rsync` ausgeschlossen bleibt.
- Compose- und Native-Nginx-Allowlists um Profil-, Medien- und Knowledge-Endpunkte erweitert. Unauthentifizierte Routenpruefungen erwarten bewusst HTTP 401.
- Healthcheck um Migrationen 004 und 005, alle neuen Tabellen, die Thinking-Spalte und beide privaten Schreibpfade erweitert.
- Eigenen transaktionalen Profil- und Knowledge-Selftest hinzugefuegt. Er prueft Account-Provisioning, Namen, Chunker, Volltextabruf und besonders die Trennung zweier Benutzer und rollt seine Testdaten vollstaendig zurueck.
- Reply-Pipeline- und Sitzungs-Selftests um Thinking-Wahl, Zehn-Dateien-Grenze, persistente Zuordnung sowie deren transaktionale Loeschung erweitert. Der Preflight prueft zusaetzlich PHP-Erweiterungen, Routen, Storage-Rechte und die vollstaendige Verdrahtung der 0.4.0-Funktionen.
- Nativen Fallback-Installer auf Migration 004 und die neuen privaten Speicherpfade angeglichen. Standard-Compose- und Native-Neuinstallation besitzen damit denselben Schema- und Funktionsstand.
- README, TXT-Changelog-Archiv und Uebergabeprotokoll auf `0.4.0-alpha` sowie die produktive Adresse `https://coreui.starlight-unit.de` aktualisiert.
- Echte Sitzungen, archivgeschuetzte endgueltige Loeschung, Thinking-Schranke, lange Ollama-Antworten, Browse-Frames, Game, Homepage, KeyHelp, Apache, Host-PHP, Repack-Pfade und bestehende STU-Datenbank bleiben unveraendert erhalten.

## v0.3.2-alpha - 27.08.2026

- Endgueltige Sitzungsloeschung als eigenstaendige Serverfunktion ergaenzt. Die bisherige Aktion `delete` archivierte lediglich; `delete_permanently` fuehrt nun eine echte SQL-Loeschung aus.
- Schutzfolge bewusst zweistufig gestaltet: Aktive Sitzungen lassen sich nur archivieren. Der rote Befehl `ENDGÜLTIG LÖSCHEN` erscheint ausschliesslich bei archivierten Sitzungen und verlangt zusaetzlich die Texteingabe `LÖSCHEN` oder `LOESCHEN`.
- Serverseitige Bestaetigung mit dem konkreten Sitzungsbezug `DELETE:<session_id>` eingefuehrt. Eine manipulierte Oberflaeche kann die Archivpflicht oder die Bestaetigung nicht umgehen.
- Eigentuemerpruefung und Zeilensperre mit `SELECT ... FOR UPDATE` direkt vor der Loeschung aufgenommen. Fremde, bereits entfernte oder zwischenzeitlich wiederhergestellte Sitzungen werden abgelehnt.
- Laufende und wartende Browser-Recherchen schuetzen die Sitzung vor Loeschung. Jobs im Zustand `queued` oder `running` liefern `session_busy`, statt dem Worker seine Daten waehrend der Verarbeitung zu entziehen.
- Vollstaendige SQL-Transaktion fuer alle eindeutig sitzungsgebundenen Datensaetze umgesetzt. Sie entfernt Reaktionen, Browserframes, Browserschritte, abgeschlossene Browserjobs, Chatnachrichten, verwaiste Console-Medienzeilen und zuletzt die Sitzung selbst.
- Fehlerbehandlung auf vollstaendiges Rollback ausgelegt. Scheitert eine einzelne SQL-Operation, bleiben Sitzung und alle abhaengigen Zeilen unveraendert erhalten.
- Upload-Bereinigung referenzsicher gemacht. Ein `stu_console_media`-Datensatz wird nur entfernt, wenn seine UUID nach dem Nachrichten-DELETE nirgends mehr referenziert wird.
- Physische Mediendateien und der Abschluss-Screenshot entfernter Browserjobs werden erst nach erfolgreichem Datenbank-Commit entfernt. Der Pfadfilter erlaubt ausschliesslich einzelne Dateien unter `var/console_media`, `assets/chat_media` und `uploads/ember_browse`; Traversal, fremde Pfade und Symlink-Ziele ausserhalb werden abgewiesen.
- Loeschergebnis liefert getrennte Zaehler fuer Nachrichten, Reaktionen, Jobs, Schritte, Frames, Medienzeilen sowie geloeschte, fehlende oder nicht entfernbare Dateien. Die Oberflaeche bestaetigt damit die tatsaechliche SQL-Wirkung.
- Archivierung waehrend einer noch offenen lokalen Antwort blockiert. Dadurch kann auch ein direkter, noch nicht als Browse-Job sichtbarer Modellturn nicht versehentlich aus der aktiven Ansicht entfernt werden.
- Browser-Runtime-Caches, Poll-Timer, Pending-Zustand, Verlaufsseiten und Lesecursor der geloeschten Sitzung werden gezielt verworfen; andere Sitzungen bleiben unberuehrt.
- Sitzungs-Selftest um eine produktionsgleiche Loeschtransaktion erweitert. Er legt zwei isolierte Testsitzungen sowie Nachricht, Antwort, Reaktion, Upload, Browse-Job, Schritt und Frame an, prueft Archivpflicht und Busy-Sperre, loescht nur Sitzung A und rollt anschliessend den gesamten Test zurueck.
- Fuer diese Funktion ist keine neue DDL-Migration erforderlich. `0.3.2-alpha` nutzt die in `003_console_sessions` vorhandenen Indizes und fuehrt die eigentliche Loeschung als transaktionales SQL-DML aus.
- Oeffentliche Basisadresse bleibt `https://coreui.starlight-unit.de`. Die erhaltene lokale `config.local.php`, Benutzerkonten, KI-Profile, Memories, Lore, Game, Homepage und STU-Datenbank werden nicht veraendert.

## v0.3.1-alpha - 27.08.2026

- Unfertige Demo-Sitzungen durch echte serverseitige Chat-Sitzungen ersetzt. `stu_chat_messages.session_id` ist jetzt die verbindliche Zugehoerigkeit jeder privaten Nachricht.
- Exakte Turn-Zuordnung mit `reply_to_id` eingefuehrt. Eine Ember-Antwort kann nicht mehr nur deshalb einer falschen Anfrage zugeordnet werden, weil ihre Nachrichten-ID spaeter liegt.
- Eigenen History-Endpunkt `api/console_messages.php` hinzugefuegt. Er prueft Authentifizierung, Sitzungsbesitz und Charakter und filtert jede Abfrage nach Benutzer plus Sitzung.
- Fehlerhafte History-Heuristik `since_id - 80` entfernt. Die neueste Seite wird korrekt rueckwaerts geladen; aeltere Seiten werden ueber einen echten `before_id`-Cursor nachgeladen.
- Instabilen `activeIdx` im Browser durch eine feste `activeSessionId` ersetzt. Sortiert sich die Liste neu, bleibt die geoeffnete Unterhaltung unveraendert.
- Asynchrone History-, Poll-, SSE- und Browser-Callbacks gegen Sitzungswechsel abgesichert. Spaete Antworten duerfen nur ihre Ursprungssitzung aktualisieren und niemals den gerade sichtbaren fremden Verlauf.
- Console-SSE verlangt jetzt `session_id`, laedt den User-Turn aus genau dieser Sitzung und dedupliziert nur ueber die Kombination aus Sitzung plus `reply_to_id`.
- Modellkontext fuer private Unterhaltungen nach `session_id` isoliert. Ohne gueltige Sitzungs-ID liefert der Kontextpfad absichtlich keinen Verlauf.
- Browser-Recherchejobs um `session_id` und `trigger_message_id` erweitert. Systemstatus, finales Rechercheergebnis und Live-Browser-SSE bleiben bis zum Workerabschluss am urspruenglichen Turn gebunden.
- Sitzungsmetadaten um `last_message_id`, `last_read_message_id` und `archived_at` erweitert. Lesemarkierungen veraendern die Aktivitaetsreihenfolge nicht mehr.
- Automatische MariaDB-Aktualisierung von `updated_at` entfernt. Nur echte neue Nachrichten oder eine bewusste Wiederherstellung sortieren Sitzungen neu.
- Automatisches Loeschen der jeweils aeltesten Sitzung entfernt. Es gilt ein klares Limit von 100 aktiven Sitzungen; Archivierung bleibt reversibel und Nachrichten werden nicht geloescht.
- Kanalweite Game-Chat-Kommandos im privaten Console-Kanal deaktiviert, einschliesslich des seltenen modellgenerierten Fallbackpfads ohne SSE. Insbesondere kann ein Console-Text keinen Verlauf anderer Sitzungen ueber einen globalen Prune-Pfad veraendern.
- Sitzungsnamen koennen direkt im Drawer umbenannt, verlustfrei archiviert und ueber die neue Archivansicht wiederhergestellt werden. Neue Unterhaltungen erhalten nach der ersten Nachricht weiterhin automatisch einen sinnvollen Titel.
- Unread-Zaehler, Nachrichtenanzahl, stabile Auswahl nach Reload und Cache pro Sitzung in die Oberflaeche aufgenommen.
- Bereits bestaetigte Nachrichten bleiben bei einem kurzzeitigen History- oder Sitzungslistenfehler sichtbar. Fehlerantworten leeren weder den Sitzungscache noch die lokale Liste.
- Der Hintergrund-Poll beendet den Wartestatus nur noch bei einer Ember-Antwort, deren `reply_to_id` exakt dem offenen Turn dieser Sitzung entspricht.
- Migration `003_console_sessions` hinzugefuegt. Alte, wegen des Demo-Modells nicht verlaesslich trennbare Console-Nachrichten werden pro Benutzer verlustfrei in `WIEDERHERGESTELLTER VERLAUF` gesammelt; leere Demo-Zeiger werden lediglich archiviert.
- `stack.sh up`, `start` und `restart` wenden neue idempotente Migrationen automatisch vor dem Dienststart an. Der explizite Befehl `stack.sh migrate` bleibt verfuegbar.
- Nginx-Allowlists fuer den neuen authentifizierten Endpunkt `console_messages.php` in Compose und Native aktualisiert.
- Eigenen `session-selftest.php` und Preflight-Pruefungen fuer Schema, Scope, Turn-Zuordnung, Legacy-Erhalt, Client-Rennen und Worker-Weitergabe aufgenommen.
- Produktive Dokumentationsadresse von `webui.starlight-unit.de` auf `coreui.starlight-unit.de` aktualisiert. Die lokale `config.local.php` bleibt bei Updates absichtlich erhalten.
- Thinking-Schranke, lange Ollama-Antworten, Benutzer-KI-Einstellungen, Admin Core, Game, Homepage, KeyHelp, Apache, Host-PHP und Repack-Pfade bleiben unveraendert.

## v0.3.0-alpha - 27.08.2026

- Console-SSE auf gepruefte Endausgabe umgestellt. Der Browser erhaelt keine Roh-Tokens aus `message.content` oder `message.thinking`, sondern erst die vollstaendige serverseitig bereinigte Antwort.
- Thinking-Schranke fuer Live-SSE, Datenbankpersistenz, History, Reconnect und Client-DOM geschlossen. Deutsche und englische Prompt-, Entwurfs-, Analyse- und Selbstkorrekturmuster werden fail-closed behandelt.
- Ollama-Abschlussmetadaten `done`, `done_reason` und `eval_count` werden ausgewertet. Antworten am Tokenlimit werden mit bis zu zwei gezielten Segmenten fortgesetzt, ohne die komplette Anfrage erneut zu generieren.
- Frueheren 1000-Token-Deckel entfernt. Das per Benutzer gesetzte `num_predict` wird bis 16384 an Ollama weitergegeben; der gemeinsame Modellpfad erlaubt technisch bis 32768.
- Console-Modelltimeout auf bis zu 900 Sekunden angeglichen. SSE-Heartbeats halten Reverse Proxy und Browser waehrend lokaler langer Generierungen aktiv.
- Erhaltene 0.2.x-Konfigurationen mit `STU_EMBER_MAX_REPLY_CHARS=7200` koennen neue Antworten nicht mehr still kuerzen. Die sichere Ausgabegrenze skaliert mit dem Tokenbudget bis 60000 Zeichen.
- Private Console-Eingaben auf 12000 Zeichen ohne und 20000 Zeichen mit Anhang erweitert. Zeilenumbrueche, Leerzeilen und Code-Einrueckungen bleiben erhalten.
- EventSource uebergibt nur noch die authentifizierte Turn-ID. Lange oder vertrauliche Nachrichten werden aus der Ember CoreUI-Datenbank geladen und landen weder in der URL noch in Proxy-Zugriffslogs.
- Privaten History-Kontext auf bis zu 1200 Zeichen je Nachricht erweitert. Die engen Global-Chat-Grenzen bleiben fuer den Game-Pfad unveraendert.
- Migration `002_coreui_management` erweitert `stu_chat_messages.message` auf `MEDIUMTEXT` und fuegt Benutzer-KI-Profile sowie Admin-Audit hinzu.
- Servergespeichertes KI-Profil je Benutzer hinzugefuegt: System-Prompt, Memory-Schalter, Memory-Limit, Antwortbudget und Temperatur.
- Memory-Verwaltung in den Ember CoreUI-Einstellungen ergaenzt. Benutzer koennen eigene sichtbare Fakten anlegen und loeschen; globale Fakten bleiben geschuetzt.
- Externen Provider als deaktivierte, serverseitig vorbereitete Schnittstelle aufgenommen. API-Schluessel werden nie im Browser gespeichert; lokales Gemma 4 ueber Ollama bleibt der Referenzbetrieb.
- Eigenstaendiges Admin Core mit Uebersicht, Benutzerrechten, Sperren, Memory CRUD, Lore-Suche, Runtime-Schaltern, Registrierung, Wartungsmodus, Logs und Audit hinzugefuegt.
- Werkzeugpfade fuer schnelle Websuche, tiefe Browser-Recherche und isoliertes Python koennen zentral im Admin Core aktiviert oder deaktiviert werden.
- Berechtigungsstufen und aktive Sperren werden bei jedem authentifizierten Request aus der Ember CoreUI-Datenbank aktualisiert und wirken auf bestehende Sitzungen.
- Gehaertete Nginx-Allowlists fuer `settings.html`, `admin/index.html`, `user_settings.php` und `admin.php` in Compose und Native aktualisiert.
- Transparentes Studio-Logo unter `starlight_unit_studios_logo_transparent_v030.png` neu referenziert. Der eindeutige Dateiname umgeht den bis zu sieben Tage alten Browsercache der frueheren schwarzen Datei.
- Preflight um Migration-002-Pruefung, Admin-Route, Reply-Pipeline-Selftest, 15 Thinking-Regressionen und Logo-Cache-Buster-Test erweitert.
- Game, Homepage, KeyHelp, Apache, Host-PHP, Repack-Pfade und bestehende STU-Datenbank bleiben unveraendert.

## v0.2.9-alpha - 19.08.2026

- Rohe `message.thinking`-Tokens aus dem Console-SSE entfernt. Der Server akkumuliert sie nur noch fluechtig fuer die abschliessende Antwortbereinigung und sendet stattdessen feste, inhaltsfreie Fortschrittsstufen.
- Denkpanel zum sicheren `DENKSTATUS` umgebaut. Promptteile, Identitaetsdaten, Systemanweisungen, Entwuerfe und Selbstkorrekturen werden weder live noch aus der Historie in den DOM geschrieben.
- Persistenzschranke in `ember_insert()` ergaenzt: Neue Console-Antworten speichern ausschliesslich einen festen Prozessstatus, niemals den rohen Thinking-Block.
- Console-Fetch fail-closed gehaertet. Historische `thinking_content`-Werte werden serverseitig durch einen sicheren Abschlussstatus ersetzt, bevor die JSON-Antwort erzeugt wird.
- Einmalige Datenbankbereinigung mit `sudo ./scripts/stack.sh scrub-thinking` aufgenommen. Sie ersetzt alte Raw-Thinking-Inhalte nur in der isolierten Ember CoreUI-Datenbank.
- Antwort-Sanitizer um die gemeldeten Muster `Actually, the prompt says`, `The user is asking` und `Let's try` erweitert. Ein fuehrendes, geschlossenes Antwortzitat endet jetzt vor nachfolgender interner Analyse.
- Thinking-Selftest auf zwoelf Faelle erweitert und um Quellcode-Grenzen fuer SSE und Persistenz ergaenzt.
- Studio-Original als echte RGBA-PNG freigestellt. Der schwarze Rechteckhintergrund wurde entfernt; die vorhandenen feinen dunklen Konturen an Wortmarke und Symbolen bleiben erhalten.
- Splash, Login und Einstellungen auf das transparente PNG-Asset umgestellt. Schwarzer Panelhintergrund, Polygonbeschnitt und Rahmen des Logo-Wrappers wurden entfernt.
- Eigene Ember CoreUI-Versionierung auf `0.2.9-alpha` angehoben. Game, Homepage, Repack-Versionierung und auslaufende STU Console bleiben unberuehrt.

## v0.2.8-alpha - 19.08.2026

- 404-Ursache der neuen Einstellungsseite behoben: `settings.html` in die gehaerteten HTML-Allowlists des Compose-Nginx-Templates und des Native-Nginx-Templates aufgenommen.
- Update-Luecke geschlossen, bei der der absichtlich erhaltene `var/`-Ordner eine alte generierte Nginx-Konfiguration behalten konnte.
- `scripts/stack.sh` rendert Nginx- und PHP-FPM-Runtime-Dateien vor `up`, `start` und `restart` automatisch aus den aktuellen Templates und den lokal gespeicherten Portwerten neu.
- Explizite, idempotente Wartungsaktion `scripts/stack.sh refresh-runtime` fuer reine Runtime-Aktualisierungen eingefuehrt.
- Runtime-Refresh validiert Bind-Adresse und Ports, schreibt keine Zugangsdaten in Webserver-Templates und gibt keine Werte aus `compose.env` aus.
- Compose-Preflight liefert bei einer nicht erreichbaren Einstellungsroute nun die konkrete Refresh- und Restart-Anweisung.
- Compose- und Native-Installer lehnen unvollstaendige Pakete ohne Einstellungsseite oder passendes Nginx-Template fruehzeitig ab.
- Keine Schema-, Account-, Session-, Modell-, Game-, Homepage- oder Repack-Aenderung.

## v0.2.7-alpha - 19.08.2026

- Das bereitgestellte originale Starlight Unit Studios-Logo unveraendert als lokales Ember CoreUI-Asset aufgenommen und das fruehere Game-Logo auf Splash und Login ersetzt.
- Die animierte Ren-Sternenmatrix als eigenstaendiges Ember CoreUI-Kernsymbol in der Sidebar erhalten und klar von der Studio-Herausgebermarke getrennt.
- Sichtbare Copyright-Zuordnung fuer Splash, Login, Sidebar, Protokolle und den neuen Info-Bereich ergaenzt.
- Sidebar um den eigenstaendigen Bereich `Einstellungen` erweitert; Einsatz-Protokolle und Abmeldung bleiben direkt erreichbar.
- Funktionale Einstellungsseite fuer reduzierte Bewegung, kompakten Datenstrom, Thinking-Panel-Standard, Desktop-Telemetrie und Live-Browser-Bildfolge eingefuehrt.
- Anzeigeoptionen robust und ohne Schemaaenderung im lokalen Browser gespeichert. Konten, Sessions, Erinnerungen und Modellkonfiguration bleiben unberuehrt.
- Reale Systemstatusmatrix auf Basis von `api/health.php` mit Datenbank-, Schema-, Ollama- und Runtime-Pruefung aufgenommen.
- Angemeldetes Konto und Berechtigungslevel aus der bestehenden isolierten Ember CoreUI-Authentifizierung in den Einstellungen sichtbar gemacht.
- Protokollansicht um Studio-Zuordnung, Copyright und direkten Wechsel zu den Einstellungen erweitert.
- Compose- und Native-Preflight pruefen nun Studio-Logo, Einstellungsseite, gemeinsame Preferences-Logik und die Auslieferung der Einstellungsroute.
- Mobile Viewport-Regel aus dem v1.1.1.96-Patch unveraendert beibehalten; Game, Homepage, Repack-Versionierung und auslaufende STU Console bleiben unberuehrt.

## v0.2.6-alpha - 19.08.2026

- Live-Browser nach echten Aktionen auf kurze Frame-Bursts umgestellt. SearXNG und Abschluss erhalten bis zu zwei Frames; Navigation, Klick, Eingabe und Scrollen standardmaessig drei Frames im Abstand von 240 Millisekunden.
- Frame-Anzahl und Abstand ueber `STU_EMBER_BROWSE_FRAME_BURST` sowie `STU_EMBER_BROWSE_FRAME_INTERVAL_MS` konfigurierbar gemacht und auf sichere Bereiche von 1 bis 5 Frames beziehungsweise 120 bis 800 Millisekunden begrenzt.
- Ghost-Cursor in jedem Browserframe sichtbar gehalten. Bis zum ersten echten Klick startet er an einer festen neutralen Position, danach behaelt er die letzte reale Playwright-Position bei.
- Klickposition und Klickimpuls voneinander getrennt. Die neue Datenbankspalte `cursor_click` kennzeichnet nur den Frame eines bestaetigten Playwright-Klicks; normale Folgeframes loesen keinen falschen Klickring mehr aus.
- Ghost-Cursor kleiner, kontrastreicher und auf hellen wie dunklen Webseiten lesbar gestaltet. Den grossen dekorativen Eckwinkel des Browserpanels reduziert, damit er nicht mehr wie ein uebergrosser Cursor wirkt.
- Client-Puffer auf 16 Browserframes erweitert und die Wiedergabezeit fuer normale Frames sowie echte Klickframes getrennt abgestimmt.
- Bestehende Installationen werden durch den Browse-Worker automatisch migriert. Eine vorhandene `api/config.local.php` muss fuer die neuen Standardwerte nicht ersetzt werden.
- Eigene Ember CoreUI-Versionierung auf `0.2.6-alpha` angehoben. Repack, Game, Homepage und die auslaufende STU Console bleiben unberuehrt.

## v0.2.5-alpha - 19.08.2026

- Das fruehere aufklappbare `IM NETZ`-Schrittprotokoll vollstaendig durch eine eigenstaendige Live-Browser-Agentenansicht ersetzt. Das Browserbild ist nun selbst die Rechercheflaeche und nicht mehr Inhalt einer Textbox.
- Lokale SearXNG-Treffer als sichere, escaped Browserseite gerendert. Dadurch existiert bereits ein sichtbarer Browser-Frame, wenn Ember die Recherche direkt anhand der Treffer abschliesst.
- Playwright-Viewport nach Start, Navigation, Klick, Eingabe, Scrollen und Abschluss als komprimierte JPEG-Frames gespeichert und ueber den authentifizierten Browse-SSE-Endpunkt ausgeliefert.
- Private Tabelle `stu_ember_browse_frames` mit Jobbindung, URL, Statuslabel, Viewport und Cursorposition hinzugefuegt. Der Worker migriert bestehende Installationen selbststaendig und entfernt alte Frames standardmaessig nach 24 Stunden.
- Ghost-Cursor mit echten Bounding-Box-Koordinaten des angeklickten Playwright-Elements sowie sichtbarem Klickimpuls umgesetzt. Ohne echten Klick wird keine Bewegung erfunden.
- Schrittstream auf eine kurze Statuszeile reduziert. Technische Protokolle verdecken das Browserbild nicht mehr.
- Thinking-Bleed im direkten Ember CoreUI-SSE-Pfad geschlossen. Der Streaming-Abschluss und der synchrone Werkzeugpfad verwenden jetzt dieselbe Schranke vor Datenbank und Client.
- Interne Gemma-Labels wie `Response Draft`, `Alternative`, `Final decision`, `Selected response` und `Checking constraints` werden erkannt. Eine explizite Nutzerantwort kann aus dem getrennten Denkblock gerettet werden; reines Meta bleibt leer und loest den sauberen Fallback aus.
- Client puffert fuehrende Werkzeug- und Meta-Fragmente, damit interne Labels auch waehrend des Tokenstreams nicht kurz in der Antwortblase aufblitzen.
- Eigenen Thinking-Sanitizer-Selftest mit dem gemeldeten Leak-Muster aufgenommen. Compose- und Native-Preflight pruefen zusaetzlich die private Frame-Tabelle.
- Eigene Ember CoreUI-Versionierung auf `0.2.5-alpha` angehoben. Repack, Game, Homepage und die auslaufende STU Console bleiben unberuehrt.

## v0.2.4-alpha - 19.08.2026

- Ursache des vermeintlichen Playwright-Fehlers isoliert: SearXNG und Browsernavigation liefen korrekt, der nachfolgende Ollama-Aktionsaufruf besass jedoch eine fest verdrahtete Lesefrist von nur 90 Sekunden.
- Browse-Aktionsfrist auf einen konfigurierbaren Standard von 420 Sekunden angehoben. Die getrennte Report-Frist betraegt ebenfalls 420 Sekunden.
- Verbindungsaufbau und Antwortlesen fuer Ollama getrennt behandelt. Ein nicht erreichbarer Dienst faellt schnell auf, waehrend eine laufende lokale Inferenz genuegend Zeit behaelt.
- Optionalen, begrenzten Retry fuer unmittelbare Ollama-Verbindungsfehler vorbereitet. Read-Timeouts werden bewusst nicht automatisch wiederholt, damit ein serverseitig noch rechnender Request nicht dupliziert wird.
- Browse-Protokoll unterscheidet nun eindeutig zwischen Ollama-Read-Timeout, Ollama-Verbindungsfehler und Playwright-Fehler. Der bisher irrefuehrende Text `agent-call fehlgeschlagen` wurde ersetzt.
- SearXNG-Treffer bleiben bei einem Modellfehler als belastbarer Fallback erhalten; der Browse-Job und die Ember-Antwort gehen dadurch nicht verloren.
- Idle-Frist des Live-Browse-Fensters auf 480 Sekunden und Sicherheits-Cap des Browse-SSE-Endpunkts auf 900 Sekunden angehoben.
- Neue Laufzeitwerte in Standardkonfiguration, Beispielkonfiguration sowie Compose- und Native-Installer aufgenommen. Bestehende `config.local.php`-Dateien muessen fuer den neuen Standard nicht ersetzt werden.
- Eigene Ember CoreUI-Versionierung auf `0.2.4-alpha` angehoben. Die Repack-Version und die auslaufende STU Console bleiben unberuehrt.

## v0.2.3-alpha - 19.08.2026

- `poppler-utils` in das isolierte PHP-Image aufgenommen. `pdftotext`, `pdfinfo` und `pdftoppm` stehen damit auch im Docker-Compose-Betrieb wirklich zur Verfuegung.
- Zweistufige PDF-Erkennung umgesetzt: Dokumente mit Textebene werden direkt extrahiert, Scan-PDFs ohne Textebene werden als repraesentative Seitenbilder an Gemmas Vision-Pfad uebergeben.
- Eigene beschreibbare Laufzeitablage `var/pdf_pages` als PHP-Volume hinzugefuegt. Der schreibgeschuetzte Projekt-Mount bleibt unveraendert.
- PDF-Stichprobe auf standardmaessig vier gleichmaessig verteilte Seiten und maximal 1024 Pixel je Seite begrenzt. Seitenverhaeltnis und Seitenreihenfolge bleiben erhalten.
- SSE-Routing um PDF-Anhaenge erweitert, damit ein moeglicher Scan-Fallback nicht im reinen Text-Streaming-Pfad ohne Bild-Payload landet.
- Vision-Dateiaufloesung auf zwei kanonische Ember CoreUI-Runtime-Wurzeln begrenzt: `var/ember_frames` und `var/pdf_pages`.
- Prompt-Injection-Schutz fuer Scan-PDFs eingebaut. Sichtbare Dokumentanweisungen werden als Inhalt, nicht als Systemanweisung behandelt.
- Halluzinationsschutz fuer PDFs eingebaut: Wenn weder Textebene noch Seitenbilder lesbar sind, wird keine modellgenerierte Inhaltsbeschreibung angefordert.
- Healthcheck, Compose-Preflight und Native-Preflight um den PDF-Seitenordner erweitert.
- Preflight um einen vollstaendigen PHP-Syntaxcheck fuer API-, Skript- und Tool-Dateien erweitert, damit auch ein reines Update ohne erneuten Installer fehlerhafte PHP-Dateien sofort stoppt.
- Eigenen PDF-Selftest hinzugefuegt. Er validiert eine bekannte Textebene und ein bildbasiertes Scan-PDF samt Rasterung, Skalierung, Metadaten und Cleanup.
- Anwendungssperren fuer den Parallelbetrieb dokumentiert: Game und Ember CoreUI verwenden getrennte Datenbanken und getrennte Lock-Namen. Sie blockieren sich auf Anwendungsebene nicht.
- Rein lesenden `ollama-parallel-report.sh` fuer RAM-, VRAM-, Modell- und Dienstdiagnose hinzugefuegt. Der Ember CoreUI-Installer veraendert den gemeinsam genutzten Ollama-Dienst weiterhin nicht.
- Eigene Ember CoreUI-Versionierung auf `0.2.3-alpha` angehoben. Die Repack-Version bleibt davon unberuehrt.

## v0.2.2-alpha - 19.08.2026

- Kritischen Compose-Fehler im Video-Vision-Pfad behoben: `var/ember_frames` wird jetzt als eigenes beschreibbares PHP-Volume eingebunden.
- Frame-Ausgabe von der allgemeinen Python-Arbeitsablage getrennt und ueber `STU_EMBER_VIDEO_FRAME_DIR` eindeutig konfigurierbar gemacht.
- Video-Skalierung auf eine echte Begrenzung von Breite und Hoehe umgestellt. Das Seitenverhaeltnis bleibt unveraendert und kleine Frames werden nicht unnoetig hochskaliert.
- FFmpeg waehlt den ersten Videostream explizit aus, ignoriert Audio im Vision-Pfad und schreibt JPEG-Frames mit kontrollierter Qualitaet.
- Diagnoseprotokoll um reale Frame-Abmessungen, Zeitstempel und klare Fehlercodes fuer fehlende oder nicht beschreibbare Laufzeitpfade erweitert.
- Halluzinationsschutz eingebaut: Wenn kein Frame erzeugt oder eingebettet werden konnte, wird keine modellgenerierte Videobeschreibung mehr angefordert.
- Vision-Prompt gegen kontextgetriebene Sci-Fi-, Androiden-, HUD- und Hologramm-Erfindungen gehaertet.
- Verdeckten 480-Sekunden-cURL-Deckel auf die bereits fuer PHP-FPM und Nginx vorgesehenen 900 Sekunden angehoben. Die konfigurierte Modellfrist von standardmaessig 600 Sekunden greift damit auch bei mehreren Video-Frames wirklich.
- Healthcheck und Preflight pruefen nun den Frame-Ordner. Ein eigener Video-Selftest erzeugt im PHP-Container einen synthetischen Clip und validiert `ffmpeg`, `ffprobe`, Frame-Extraktion, Skalierung und Cleanup gemeinsam.
- Veraltetes Top-Level-Feld `version` aus `compose.yaml` entfernt, damit aktuelle Docker-Compose-Versionen keine kosmetische Warnung mehr ausgeben.
- Eigene Ember CoreUI-Versionierung auf `0.2.2-alpha` angehoben. Die Repack-Version bleibt davon unberuehrt.

## v0.2.1-alpha - 19.08.2026

- Standardinstallation auf einen eigenen Docker-Compose-Stack umgestellt.
- Eigenen Nginx-Container auf Loopback-Port `9080` eingefuehrt. Der Host-Webserver und vorhandene VHosts bleiben unveraendert.
- Eigenen PHP-FPM-Container auf Loopback-Port `19073` eingefuehrt. Es werden keine globalen PHP-Werte mehr geschrieben und kein Host-PHP-FPM neu gestartet.
- Eigene MariaDB-Instanz auf Loopback-Port `13306` eingefuehrt. Bestehende STU-Datenbanken und Datenbanknutzer werden nicht verwendet oder veraendert.
- Eigene SearXNG-Instanz auf Loopback-Port `18889` eingefuehrt, damit eine vorhandene STU-Suche auf `8888` parallel weiterlaufen kann.
- Den Granian-Server im offiziellen SearXNG-Container explizit an `127.0.0.1:18889` gebunden, statt dessen internen Standardport `8080` vorauszusetzen.
- Browse-Worker in einen eigenen Playwright-Container verschoben. Es wird kein Host-systemd-Dienst mehr benoetigt.
- Ember CoreUI-Modellname von `ember-core:latest` auf `ember-coreui:latest` getrennt und durch eine lokale Eigentumsmarkierung gegen versehentliche Uebernahme fremder Modelle abgesichert.
- MariaDB-Advisory-Locks auf einen konfigurierbaren Ember CoreUI-Namespace umgestellt. Die bisherigen globalen Namen `ember_global_ollama` und `ember_self_xp` werden im Standardbetrieb nicht mehr verwendet.
- Vor jeder Erstinstallation Kollisionstests fuer Ports und Compose-Projektidentitaet eingefuehrt.
- Webzugriff standardmaessig auf `127.0.0.1` begrenzt und einen SSH-Tunnel als sicheren Testweg dokumentiert.
- Eigenen Compose-Preflight sowie `scripts/stack.sh` fuer Status, Logs, Neustart und Stop hinzugefuegt.
- Fast-Poll-Fallback an die ausloesende Ember CoreUI-Sitzung gebunden, damit ein SSE-Rueckfall den Sitzungszeiger korrekt aktualisiert und nicht in einer Poll-Schleife haengen bleibt.
- Den frueheren Host-Installer als expliziten `install-native.sh` fuer dedizierte Einzelserver erhalten und mit einer Pflichtbestaetigung gesichert.
- Entfernen der Nginx-Default-Site im Native-Installer standardmaessig deaktiviert.
- Getrennte Ember CoreUI-Versionierung fortgefuehrt. Repack-Changelogs werden von diesem eigenstaendigen Alpha-Release nicht veraendert.

Bewusste gemeinsame Ressource:

- Der lokale Ollama-Dienst wird weiterhin gemeinsam genutzt, damit vorhandene Gemma-4-Modelldaten nicht dupliziert werden. Ember CoreUI verwendet einen eigenen Modellnamen und eigene Daten, gleichzeitige Generierungen koennen aber dieselbe CPU, GPU und denselben RAM beanspruchen.

## v0.2.0-alpha - 19.08.2026

- Eigenstaendiges Distributionspaket ohne Includes, Assets, Proxies oder Laufzeitpfade aus dem Repack aufgebaut.
- Alle Frontend-API-Aufrufe auf die lokale, deployment-relative Schnittstelle `./api` umgestellt.
- Lokale Rajdhani- und Share-Tech-Mono-Fonts eingebunden.
- Eigenes MariaDB-Basisschema fuer Accounts, Charaktere, Sessions, Chat, Medien, RAG-Lite, LZG, Browse-Jobs und Reputation hinzugefuegt.
- CLI-Bootstrap fuer Ember, Admin-Account, Charakter und `stu_kv` hinzugefuegt.
- Standalone-Konfiguration ueber `api/config.local.php` oder `COREUI_*`-Umgebungsvariablen eingefuehrt.
- Eigenen Health-Endpunkt und authentifiziertes CRUD-API fuer `ember_memories` hinzugefuegt.
- Gemma-4-Modelfile mit Ember CoreUI-Werkzeugprotokoll und empfohlenen Sampling-Werten hinzugefuegt.
- Lokales SearXNG-Template mit aktivierter JSON-Search-API hinzugefuegt.
- Asynchronen Playwright-Browse-Worker als eigenen systemd-Dienst paketiert.
- SSH-Installer fuer Debian und Ubuntu sowie einen wiederholbaren Preflight-Test hinzugefuegt.
- Nginx-Allowlist fuer ausschliesslich oeffentliche API-Endpunkte und Schutz interner Projektordner hinzugefuegt.
- Vision-Dateiaufloesung auf das lokale Projektverzeichnis `assets/chat_media` begrenzt.
- Getrennte Ember CoreUI-Versionierung gegenueber der Repack-Versionierung verbindlich dokumentiert.

Bekannte Alpha-Grenze:

- Die visuelle CRUD-LZG-Oberflaeche und weitere spezialisierte Werkzeug-Panels folgen in einem spaeteren Frontend-Schritt. Das lokale CRUD-API ist bereits enthalten.

## v0.1.1-alpha - 19.08.2026

- Kritischer Mobile-Viewport-Fix fuer Ansichten bis 899 px integriert.
- `html`, `body`, `.app-body` und `.app-shell` hart auf die Viewportbreite begrenzt.
- Horizontalen Seiten-Scroll im mobilen Layout gesperrt.
- Drawer auf 290 px festgelegt und im Ruhezustand vollstaendig links ausserhalb des Viewports positioniert.
- Drawer-Einflug auf 380 ms mit eigener Holo-Schattenkante abgestimmt.
- Overlay-Ebene direkt unterhalb des Drawers abgesichert.
- `.drawer.open` ist mobil die einzige Quelle fuer den sichtbaren Drawer-Zustand. Der Checkbox-State steuert dort nicht die Sichtbarkeit, da `#sidebarControl` im aktuellen HTML standardmaessig aktiviert ist.

## v0.1.0-alpha - 19.08.2026

- Eigenstaendiges HTML-Grundgeruest fuer Ember CoreUI erstellt.
- Helles Ayal'ren-HUD, SVG-Runenmatrix, Sidebar, Ticker, Chat-Feed und Composer gestaltet.
- Bestehende DOM-Kopplung fuer die aktuelle Console-JavaScript-Laufzeit erhalten.
- Grundlayout in feste Navigations-, Kopf-, Verlaufs- und Eingabebereiche gegliedert, damit die spaetere funktionale Erweiterung ohne Austausch des visuellen Kerns moeglich bleibt.
- Eigene Ayal'ren-Runenmatrix als Ember CoreUI-Kernsymbol eingefuehrt und vom spaeter getrennt gefuehrten Studio-Branding abgegrenzt.
- Chatblasen, Statusanzeige, Nachrichtenzeiten, Eingabefeld und primaere Aktionen als konsistentes HUD-System angelegt.
- Erste Desktop-Proportionen, Typografie, Farbvariablen, Raster, Schatten und Bewegungsmuster in einer gemeinsamen Ember CoreUI-Styleschicht gebuendelt.
- Game, Homepage und bestehende Repack-Oberflaechen nicht ersetzt; der Stand war ein eigenstaendiger Alpha-Frontendkern.
