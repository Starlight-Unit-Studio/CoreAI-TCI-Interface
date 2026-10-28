# CoreAI: TCI Interface
[![DOI](https://zenodo.org/badge/1349352973.svg)](https://doi.org/10.5281/zenodo.22201310)

**CoreAI: TCI Interface** is your powerful, 100% free and private alternative to ChatGPT Pro and Claude.ai. Run advanced AI tasks locally or in the cloud without subscriptions, data tracking, or limits.

It combines private conversations and sessions, user-scoped RAG-Lite, local long-term memory, Playwright-based browser research with SearXNG, isolated Python execution, multimodal file handling, deterministic exact integer calculations, and strict separation of internal model analysis from visible answers.

CoreAI: TCI Interface runs on its own containerized Nginx, PHP-FPM, MariaDB, SearXNG, browser, and Python-worker stack and remains isolated from STΛRLIɢHT The Game and other Starlight Unit systems.

Current source version: `0.5.76` (numeric only). The last verified ZIP remains archived under its original historical name.

## Erklaerungen statt Werkzeugaufrufe, Unterstriche, Arbeitsweg (0.5.76)

Erklaert Mara ihre Werkzeuge, loesen die erwaehnten Marker in Code-Markierungen
keine Suche und keine Python-Ausfuehrung mehr aus, und ein Fortschrittsblock
vor normalem Text startet keine Korrekturrunde mehr. Unterstriche mitten im
Wort (snake_case, Dateinamen, Marker) bleiben im Chat stehen. Einfache
Antworten zeigen keinen Arbeitsweg mehr; er erscheint, sobald Mara wirklich
arbeitet. Im Sprachplan steht Lauf 4 mit der Entscheidung: Mitschrift ueber
Googles Transkriptionsvorlage, Sprachnachrichten zweistufig, Live einstufig.

## Denkschalter und Core-Wahl im Chat (0.5.75)

Unter dem Eingabefeld sitzt ein Core-Chip, aehnlich wie in anderen Chat-
Oberflaechen. Er zeigt den aktiven Core und ob Mara denkt. Ein Tipp oeffnet
die Liste aller Cores aus Ollama und den Schalter "Denken"; beides gilt fuer
alle Chats des Kontos ab der naechsten Nachricht. Ein gewaehlter lokaler Core
wird sofort ueber Maras Warteschlange vorgeladen.

Damit der Schalter wirklich schaltet, steht `<|think|>` nicht mehr fest im
`config/Modelfile.template` (Freigabe des Betreibers). Ollamas Gemma-4-
Renderer setzt es bei `think: true` selbst. Das normale Update
(`scripts/update-from-main.sh`) baut Maras Core dabei neu. Der globale Kanal
denkt weiter wie bisher.

Der Medien-Test vergleicht ausserdem Wege, kurze Aufnahmen zuverlaessig
mitzuschreiben (Googles Transkriptionsvorlage, Stille davor, temperature 0
nur im Test) und misst die Live-Antwort einstufig und zweistufig.

## Messprotokoll und erweiterter Medien-Test (0.5.74)

Der erste Medien-Test lief zweimal auf dem echten Server. Mara hoert ueber
Gemma 4 zuverlaessig: Audio kostet 25 Tokens je Sekunde, 60 Sekunden laufen
stabil, Bild und Ton gehen gemeinsam. Die Werte stehen im Messprotokoll von
`docs/COREUI_VOICE_PLAN.md`. Der Test misst jetzt zusaetzlich die Zeit bis
zum ersten Wort, die Kosten des Denkens (Vergleich mit Maras SYSTEM-Text
ohne `<|think|>`, nur im Testaufruf), eine kurze Live-Antwort und wie oft
Core sehr kurze Aufnahmen uebersieht. Naechster Lauf:
`bash scripts/media-spike.sh --audio DATEI --skip-formats --durations 15,60`.
Neu ist `api/voice_core.php` mit den Hilfen fuer Audio-Aufrufe in P20.1 und
P20.4.

## Medien-Test P20.0 fuer den Server (0.5.73)

`bash scripts/media-spike.sh` misst auf dem echten Server, wie Mara ueber
Gemma 4 hoert: Testaufnahme in 5 bis 60 Sekunden als WAV (16 kHz, Mono,
16 Bit), absichtlich falsche Formate (24 Bit, Float, 44,1 kHz Stereo, rohes
PCM) und ein Fall mit Bild und Ton gemeinsam. Jeder Aufruf wartet in Maras
serieller Warteschlange; Modelle, Modelfile und Datenbank bleiben
unveraendert. Optional misst das Skript Stimmen-Kandidaten
(`--tts "NAME=BEFEHL"` mit `{text}` und `{out}`) mit 2 und 4 Kernen sowie
einen Bildbefehl (`--image`). Eine eigene Aufnahme vom Handy geht mit
`--audio DATEI`. Ergebnis: `var/cache/media-spike/ZEITSTEMPEL/report.md`.
Neu ist ausserdem `api/audio_wav.php`, die WAV-Pruefung (RIFF, Mono,
16 kHz, 16 Bit), die P20.1 spaeter vor jedem Senden verwendet.

## Roadmap: Mara hoert und spricht (0.5.72)

Reine Planungsversion, keine Laufzeitaenderung. Die Roadmap stand noch auf
dem 20.10.2026 (0.5.15) und ist jetzt auf dem Stand 0.5.71: Downloads und
Agentenkern laufen live, die naechste Etappe sind Medien. Neuer
[Sprachplan](docs/COREUI_VOICE_PLAN.md): Mara hoert ueber Gemma 4 (e2b, e4b,
12b nehmen Audio ueber das `images`-Array von Ollama an), hoert bei Videos
auch den Ton, spricht mit einer eigenen Server-Stimme (Lautsprecher-Knopf
unter jeder Antwort) und fuehrt Live-Gespraeche mit Unterbrechen.
Betreiberentscheidungen vom 28.10.2026: Reihenfolge Sprache, dann
Bildgenerierung, dann Projekt-Workspace; die Sprachausgabe darf als einzige
Ausnahme mit festem Kernlimit parallel zu einer Core-Anfrage laufen; direkt
eine eigene Mara-Stimme. Der erste Schritt ist ein Test auf dem echten Server
(Audioformate, Laenge, Latenz, Stimmen-Kandidaten).

## RAG-Lite findet Namen, eine Mara fuer Text und Bild (0.5.71)

**RAG-Lite in grossen Dokumenten:** Nach dem Hochladen der drei
Starlight-Unit-Bibeln fand Mara Namen wie "Ayal'ren" oder "Alt Khemet" nicht.
Die Suche bevorzugte lange Woerter, zaehlte Allerweltsbegriffe wie
"Starlight Unit" (in fast jedem Abschnitt) genauso wie den gesuchten Namen,
und der Rest "ren" traf "Waren" oder "Lernen". Jetzt zaehlt, wie selten ein
Begriff im eigenen Wissen ist, Namen behalten ihren Stamm ueber Apostrophe,
und Rueckfragen ohne Stichwort nutzen den Gespraechsverlauf. Der Arbeitsweg
zeigt "Privates Wissen durchsucht" mit Treffern und Quellen.

**Eine Mara fuer Text, Bild und Video:** Nach einer Bild- oder Videoanalyse
klang Mara wie jemand anderes und nahm Aenderungswuensche als Kritik. Der
Bildweg schickte einen eigenen, fest eingebauten Charakter als
System-Prompt; der ersetzt bei Ollama das Modelfile-SYSTEM, und Verlauf,
KI-Profil und Gedaechtnis fehlten. Jetzt nutzt der Bildweg dasselbe leere
System (also das Modelfile) und denselben Prompt wie Textantworten, nur mit
der Bildaufgabe dahinter. Text und Ton aus einem Anhang (etwa ein wuetender
Chat im Screenshot) gelten nicht als Aussage des Absenders, und eine
Bildbeschreibung wird nicht mehr als Gedaechtnisfakt ueber den Nutzer
gespeichert. Die Meldung "Die Uebertragung von Core war unvollstaendig" bei
jedem Anhang ist weg: Bilder laufen bewusst nicht ueber den Textstream, der
Arbeitsweg zeigt jetzt "Anhang wird angesehen".

## Kontextfenster im KI-Profil, "Core" statt "Modell" und robuste WebUI (0.5.70)

**Kontextfenster (Issue #113):** Unter Einstellungen > KI-Profil gibt es
einen Schieberegler mit 4K, 8K, 16K, 32K, 64K und 128K sowie den Modus
"Core-Standard übernehmen" (Voreinstellung fuer jedes Konto). Jede Stufe
zeigt einen gerundeten Speicher-Richtwert fuer den ausgewaehlten Core
(Gewichte, KV-Cache je Kontextlaenge inklusive Sliding-Window-Schichten,
Laufzeitanteil; ist der Core geladen, korrigiert die echte Belegung aus
`/api/ps` die Schaetzung). Fehlen Metadaten, steht dort "nicht zuverlaessig
schaetzbar" statt einer erfundenen Zahl. Uebersteigt ein Richtwert den
verfuegbaren Arbeitsspeicher deutlich, erscheint ein Hinweis, gesperrt wird
nichts. Nur bei ausdruecklicher Wahl sendet CoreAI `options.num_ctx`, im
SSE- und Sync-Weg gleich, nur an lokale Cores der privaten Konsole, nie an
Cloud-Cores, nie weitere Gemma-Parameter. Der Wert gilt pro Lauf; eine
Aenderung waehrend eines Agentenjobs greift ab dem naechsten Auftrag.
Projektbudgets und der Core-Infokasten nutzen das tatsaechlich gesendete
Fenster (Quelle "KI-Profil", der geladene Wert erscheint getrennt). Neue
Migration `017_ai_num_ctx.sql` (NULL = kein Override). Das Modelfile bleibt
unveraendert.

**Lore (Issue #115):** Sichtbare Texte sagen "Core" statt "Modell": Der
Arbeitsweg zeigt "Core arbeitet", "Antwort von Core empfangen" statt
"Modellstream gestartet" oder "Core-Aufruf". Interne Ereignisnamen,
API-Felder und Protokollkennungen bleiben unveraendert. Ein Test verhindert,
dass "Modell" in sichtbare Texte zurueckkehrt.

**WebUI-Robustheit (Issue #112, P1 und P2):** Der Verlauf laedt mit Abbruch
beim Sitzungswechsel, Zeitlimit und einer sichtbaren Wiederholen-Schaltflaeche;
Fehlerarten (offline, Zeitueberschreitung, HTTP, ungueltige Antwort) werden
benannt. Bereits angezeigte Nachrichten und der Entwurf bleiben erhalten.
Nach `online` oder Rueckkehr in den Vordergrund synchronisiert die App nur
lesend; ein laufender Auftrag wird ueber seine Server-ID wieder angebunden,
nie doppelt gesendet. Abfragen weichen bei Fehlern begrenzt aus (Backoff mit
Jitter), verdeckte Tabs fragen seltener. Aeltere Seiten werden vor die
vorhandenen Nachrichten gesetzt statt alles neu aufzubauen: im Nachbau mit
1.000 Nachrichten konstant rund 25 ms je Seite statt bis zu 1 s, die
Leseposition bleibt stehen. Inaktive Sitzungen behalten nur ihre neueste
Seite im Speicher. Messwerte (Dauer, Anzahl, Renderzeit, lange Frames) liegen
ohne Inhalte in `window.CoreUINet.metrics()`. P3 (Android-Wrapper) gehoert
in das Repository Quantum-Mobile-Wrapper.

**Datum und Uhrzeit:** Eine Homepage von Mara trug das Jahr 2024 aus den
Trainingsdaten. Nur der Hauptprompt kannte die SERVERZEIT; die getrennten
Aufrufe fuer einzelne Quelldateien, Fortsetzungen und Folgeschritte hatten
weder Datum noch Uhrzeit. Jetzt beginnt jeder Core-Aufruf eines
Konsolenauftrags mit `[DATUM HEUTE: ...]`, und `[UHRZEIT JETZT: ...]` steht
direkt vor der letzten Anweisung, damit Ollamas Prompt-Cache den gemeinsamen
Auftragstext auch nach einem Minutenwechsel weiter nutzt. Die
SERVERZEIT-Anweisung erlaubt das Datum ausdruecklich fuer erzeugte Inhalte
wie das Copyright-Jahr.

## Infokaesten auf dem Server und kein Chat-Flackern nach Antworten (0.5.69)

Live-Test 0.5.68: Werkzeuge und Projekt blieben im Ladezustand, und nach
einer Antwort flackerte der Chat. Ursache 1: nginx laesst nur API-Dateien
aus einer festen Liste durch; `console_insights` fehlte in beiden
Vorlagen. Core und Kontext wirkten trotzdem gefuellt, weil sie auch aus den
Live-Ereignissen lesen. Die Vorlagen enthalten den Endpunkt jetzt, beide
Preflights pruefen ihn, und ein nicht erreichbarer Endpunkt wird im Kasten
benannt statt endlos zu laden.

Ursache 2 (schon vor 0.5.68 vorhanden): Die Hintergrund-Abfrage alle drei
Sekunden lieferte manchmal noch den eben beendeten Agentenlauf als
"laeuft", weil sie kurz vor dem Abschluss gestellt wurde. Die App belebte
den Lauf wieder, baute den ganzen Verlauf neu auf, zeigte erneut den
Tipp-Indikator und lud die Download-Karten nach. Jetzt merkt sich die App
beendete Laeufe, erkennt einen Lauf mit gespeicherter Antwort und den
Basislauf eines bereits beantworteten Turns als veraltet. Eine echte
Neu-Generierung wird weiterhin fortgesetzt.

## Arbeitsweg wie ein Agent-Terminal, Infokaesten und Autoscroll (0.5.68)

Live-Test des Betreibers nach 0.5.67: Lokale und Cloud-Modelle laufen
schnell durch. Beim Schreiben des Arbeitswegs blieb die Ansicht aber kurz
nach der eigenen Nachricht stehen, und der Text darunter war nur halb zu
sehen. Ursache im Scroll-Controller `js/coreui-chat-follow.js`: Ein
laufender Block, der hoeher als 78 Prozent des Sichtbereichs wurde, wurde
absichtlich an seinem Anfang festgehalten (ein Notbehelf gegen den
Leerraum, dessen echte Ursache 0.5.67 behoben hat). Ausserdem folgte die
Ansicht nur bei neuen Server-Ereignissen, nicht beim buchstabenweisen
Wachsen, und jede nach oben verschobene Scrollposition galt als
Nutzer-Scrollen, auch wenn der Browser sie selbst verursacht hatte.

0.5.68 folgt dem Ende des neuesten Beitrags, reagiert per
`ResizeObserver` auf jedes Wachstum und stoppt nur bei einer echten
Nutzeraktion: Mausrad, Wischen, Tasten oder Ziehen der Scrollleiste.
Im Browser-Nachbau bleibt der neueste Schritt bei 412, 600 und 1010 Pixel
Breite durchgehend sichtbar; nach Hochscrollen bleibt die Leseposition
stehen.

**Arbeitsweg im Nachrichtenfluss:** Maras Erzaehlung (eigene Notizen,
Planentscheidung, Kontextfenster, Dateiliste, Fehler, Ergebnis) steht als
normaler Antworttext in ihrer Nachricht. Routine-Schritte (Core-Aufrufe,
Einzeldateien, Pruefungen) falten sich pro Abschnitt zu einer grauen Zeile
wie "10 Schritte · Quelldatei wird erzeugt · profile.html ▸", die sich
aufklappen laesst. Beim Projektbau zeigt eine Aufgabenkarte im CoreAI-Rahmen
alle geplanten Dateien mit Status, unten laeuft eine Statuszeile mit
aktueller Taetigkeit, Laufzeit und Ausgabe-Tokens. Das neue Ereignis
"Projektdateien geplant" traegt die Dateiliste (nur flache Dateinamen).

**Infokaesten:** `api/console_insights.php` liefert nur lesend und
sitzungsbezogen **Core** (Modell, lokal/Cloud, Kontextfenster mit Quelle,
Denkmodus), **Kontext** (Auslastung des letzten Core-Aufrufs mit Balken),
**Werkzeuge** (RAG-LITE, CRUD-LZG, WEB, BROWSE, Python, Skills) und
**Projekt** (letzter Plan und Dateien mit Download). Ab 1400 Pixel Breite
stehen sie rechts neben dem Chat im CoreAI-Kastenstil (Ecken oben rechts
und unten links abgeschnitten, Blau-Violett-Amber-Verlauf), darunter als
aufklappbare Buttons. Waehrend Mara arbeitet, aktualisiert sich der
Kontext-Kasten live. Keine Modell-, Ollama- oder Modelfile-Aenderung.

## Kontextfenster, Cloud-Werkzeuge, Python-Schutz und Chat-Leerraum (0.5.67)

Die Diagnosewerte der Netcup-Bilder 3250 + 846, 3284 + 812 und
1091 + 3005 ergeben jeweils exakt 4096 Tokens. Das ist Ollamas
Standard-Kontextfenster ohne grosse GPU. CoreAI sendet seit 0.5.15 kein
`num_ctx`; die Einzeldatei-Prompts waren durch den 9000-Zeichen-Planauszug
und den Pflicht-Skill so gross geworden, dass fuer Denkphase und Quelltext
nur rund 800 Tokens blieben.

0.5.67 ermittelt das echte Fenster nur lesend (`/api/ps`, gemerkte volle
Fenster mit `done_reason=length`, sonst 4096) und teilt jeden
Einzeldatei-Prompt danach auf: passender Planauszug statt fester 9000
Zeichen, DOM-Schnittstelle statt Rohquelltext bereits erzeugter Dateien,
kein SKILL-Text in reinen Quelltextaufrufen. Im Selbsttest belegt ein
Galerie-Prompt bei 4096 Tokens rechnerisch rund 1300 Eingabe-Tokens
inklusive Modelfile-SYSTEM; die Laeufe auf den Bildern lagen bei 3250.
Cloud-Modelle behalten den bisherigen Prompt unveraendert. Die Konsole
kuerzt bei kleinem Fenster Verlauf, Planblock und Werkzeughinweise.

Python aus einer abgeschnittenen Modellantwort wird nie ausgefuehrt
(vorher: `SyntaxError: unterminated triple-quoted string`). Lokale Modelle
erhalten fuer mehrseitige Projekte keinen Python-Pflichthinweis mehr, der
`[COREUI_PROJECT_BUILD]` widersprach; ein solcher Versuch geht bei einem
gespeicherten Plan an den geprueften Projekt-Writer. Ein von Mara selbst
gewaehlter Dateijob bekommt auch ohne Schlagwoerter wie "Download" genau
einen begrenzten Korrekturversuch und endet danach mit einer sachlichen
Meldung statt einem Versprechen. "Neu generieren" und "Bearbeiten" fragen
die Bauentscheidung ebenfalls ab.

Werkzeugmarker werden toleranter gelesen (Leerzeilen, kurzer Nachsatz,
reine Fortschrittszeile), und Saetze wie "kein React" sperren kein Projekt
mehr. Konsolenantworten behalten Absaetze und Codeeinrueckung.

Messung des Betreibers am 27.10.2026 (Netcup, CPU, `ember-coreui:latest`):
Ausgabe 25,1 / 22,4 / 24,3 / 22,0 Tokens/s bei `num_ctx` 4096 / 16384 /
32768 / 65536, Prompt 228 bis 262 Tokens/s. Ein groesseres Fenster kostet
dort also nur rund 10 Prozent Tempo. Der Betreiber hat deshalb selbst
`PARAMETER num_ctx` im Modelfile-Template gesetzt (zuerst 65536, jetzt
131072); mit 65536 lieferte das lokale Modell erstmals ein Projekt-ZIP mit
acht Dateien. CoreAI selbst sendet weiterhin kein `num_ctx`: Es liest das
Fenster ueber `/api/ps` oder, bei entladenem Modell, aus dem Modelfile
(`/api/show`) und spart Prompt nur bei kleinen Fenstern.

**Cloud-Werkzeugantworten:** Antworten von `gemma4:31b-cloud` und
`gpt-oss:120b-cloud` endeten trotz `done_reason=stop` als "Werkzeugantwort
unvollstaendig". Genau ein geschlossener Werkzeugblock mit kurzem Satz davor
oder danach, Inline-Code im Nachsatz, fetten Markerzeilen oder CRLF wird
jetzt erkannt. Bleibt eine Antwort unlesbar (mehrere Bloecke, Codeblock,
"Beispiel:"), steht der Grund im Arbeitsweg und Mara korrigiert sich
genau einmal selbst, statt mit einer Sackgasse zu enden.

**Leerraum unter der letzten Nachricht:** Die unsichtbaren Schritt-Titel
des Arbeitswegs waren absolut positioniert, der intern scrollende
Arbeitsweg aber nicht. Aufgeklappt verlaengerten sie den Chat um ihre
verborgenen Positionen (im Nachbau rund 1300 Pixel). Sie bleiben jetzt in
ihrem Eintrag; unter der letzten Nachricht bleiben 16 Pixel.

Issue #102 bleibt offen. Lokale Selbsttests und Browser-Nachbau ersetzen
nicht die Live-Abnahme ueber den Netcup-Updater.

## Quellen-Fortsetzung, Cloud-Entwurf und Chat-Hoehe (0.5.66)

Die Netcup-Tests vom 27.10.2026 zeigen beim lokalen Gemma
`source_continuation_invalid` nach HTTP 200 / `done_reason=stop`.
0.5.66 betrachtet nicht allein den Provider-Endgrund:
Strukturell offene HTML-/CSS-/JS-Quelldaten duerfen weiter als
inerte Fragmente vervollstaendigt werden. Ein kompletter Neustart
derselben Datei wird nur dann uebernommen, wenn er die sichere
Einzeldatei-Validierung besteht und den bestaetigten Dateianfang
beibehält. Eine ungültige Fortsetzung kann einen erneuten
vollstaendigen Dateiversuch ausloesen, statt den Auftrag sofort
abzubrechen. Keine Modellparameter, keine Modelfile-Aenderung.

Eine ungeschlossene, jedoch eindeutig ausgewaehlte
`[COREUI_PLAN_AND_BUILD]`-Modellaktion kann bei genug
valide strukturiertem Markdown nun als **unvollstaendiger Planentwurf**
gespeichert werden. Der Build selbst wird nicht gestartet,
bevor der Plan komplett und autorisiert ist.

Das Chat-Follow richtet sich am Ende der **letzten echten Nachricht**
aus, nicht am leeren Feed-Container. Der zuvor ueber die
Viewport-Hoehe gespannte Platzhalter und 54px untere Auffuellung
entfallen. Der schwebende Ruecksprung-Button bleibt.

Diese Fehlerbehebung schraubt an **keinem** Ollama-Token- oder
Datei-Codebudget. Das 512-KB-Sammellimit blieb seit 0.5.65 entfernt.
Issue #102 zur langfristig persistenten Projekt- und Datei-Wiederaufnahme
bleibt offen. Lokale isolierte PHP- und DOM-Pruefungen ersetzen nicht
die Live-Abnahme ueber den Netcup-Updater.

## Leserorientierter Arbeitsweg und Projekt-ZIP-Writer (0.5.65)

Der CoreAI-Chat hat einen funktionierenden, halbtransparenten
Ruecksprung-Button. Beim Lesen aelterer Nachrichten folgt der Chat
nicht mehr blind jedem Modell- und SSE-Ereignis; erst der ausdrueckliche
Sprung oder die Rueckkehr ans Ende schaltet die automatische Nachfuehrung
wieder ein. Waehrend Mara arbeitet, zeigt der Button drei animierte
Arbeitspunkte statt des Pfeils. Eine grosse laufende Arbeit wird am
Beginn des aktiven Nachrichtenblocks verankert, nicht hinter ihren
sichtbaren Inhalt gescrollt. Live-Arbeitsweg und Quellcodefenster behalten
jeweils ihre eigene Leserposition.

Die Workevents erscheinen als durchlaufende Chattexte statt als
Protokollkarten. Diagnose und Zeitpunkt bleiben unter einem kleinen
Info-Symbol abrufbar; keine wiederholten "Technische Details"-Zeilen
und keine erzwungenen Profilnamen an jedem Arbeitsschritt.

Beim nativen und Quelltext-ZIP-Bau sind die bestaetigten Dateien
weiterhin nach Quelle, Dateiname, Anzahl und Worker-Kontext validiert.
Der fruehere zusaetzliche 512-kB-Sammeldeckel und der fuer modell-
geschriebene Python-Skripte geltende Codeumfang werden nicht mehr
auf den rein serverseitig erstellten, deterministischen ZIP-Schreiber
uebertragen. Dessen tatsaechlicher Bedarf wird aus den geprueften
Quelldateien berechnet. Ein mehrteiliger persistenter Projekt-Runner
und die bisherigen Manifest-/Fortsetzungsgrenzen aus Issue #102 sind
weiterhin offen; kein fertiger zehnseitiger Projektbau behauptet.

Tests: `node scripts/chat-follow-selftest.cjs`,
`node scripts/work-events-ui-selftest.js`,
`php scripts/local-file-tool-selftest.php`,
`python3 scripts/version-merge-check.py --base-version 0.5.64`,
`python3 scripts/cache-bust.py --verify`.
Die Netcup-Liveabnahme folgt erst nach dem Merge und Server-Update.

## Dateiwerkzeug-Selbsttest korrigiert (0.5.64)

Die Netcup-Abnahme von 0.5.63 war nach bestandener PHP-Syntaxpruefung
bei `scripts/local-file-tool-selftest.php` haengen geblieben. Ursache:
Eine im Selbsttest fest hinterlegte Pruefung erwartete noch den alten
optionalen Skill-Hook aus 0.5.61, obwohl CoreAI seit 0.5.62 den
verbindlichen Werkzeuge-System-Skill auch in fokussierten Quelldatei-
Aufrufen uebergibt. Der tatsaechliche Handler erfuellt bereits die
anderen vier Diagnostik-/Fallbackbedingungen.

0.5.64 aktualisiert allein diese veraltete Testannahme und die
verbindliche Versions-/Cache-/Changelog-Dokumentation. Die Modell-,
Runner-, Modelfile-, Datenbank- und Dateiausfuehrungslogik bleiben
unveraendert. Kein produktiver 10-Seiten-Build behauptet. Erneute
Netcup-Liveabnahme durch den vorhandenen Installer steht aus.

## Installer-Syntaxfix (0.5.63)

Hotfix fuer den Netcup-Installer: In `scripts/source-preview-selftest.php`
wurde ein PHP-Variablenname innerhalb eines doppelt gequoteten
Assertion-Strings nicht maskiert. Dadurch stoppte die obligatorische
Syntaxpruefung aller PHP-Dateien, bevor die Installation von 0.5.62
abgeschlossen werden konnte. Die Zeichenkette ist korrigiert und
mit `php -l` auf PHP 8.4 geprueft. Die eigentlichen CoreAI-
Modell-/UI-Funktionen, SQL-Daten, Modelfile und Runner-Limits
wurden nicht veraendert. Netcup-Liveabnahme steht noch aus.

## Permanenter Werkzeug-System-Skill (0.5.62)

CoreAI besitzt jetzt **33** repository-eigene Skills: 32 optionale Fachpakete
und den neuen **Werkzeuge**-Kern-Skill in `skills/werkzeuge/SKILL.md`.
Er bleibt serverseitig auf jedem privaten CoreAI-Turn aktiv, selbst wenn fuer das Konto
alle anderen Skills ausgeschaltet sind oder Migration 014 fehlt. Die
separate Game-Ember erhaelt diese spezielle Mara-Anleitung nicht. Die
Einstellungen zeigen ihn angehakt, ausgegraut und vor allen Fach-Skills an.
Manipulierte Versuche, diesen Skill ueber die API zu deaktivieren, werden
abgewiesen. Bis zu vier optionale Skills koennen zusaetzlich geladen werden.

Der Kern-Skill vermittelt Maras sicheren Umgang mit existierenden Werkzeugen,
der seriellen Ollama-Queue und tatsaechlich nachgewiesenen Dateien. Zehn
bestehende Fach-Skills wurden zu Source-Only-, Agenten-, ZIP-, SSE- und
Modellablauf aktualisiert. Die eigentliche persistente Datei-Checkpoint-
Architektur und die mehrteiligen Build-Limits aus Issue #102 sind weiterhin
offen. Die Chat-Darstellung und die echte Live-Quellcodevorschau aus
dem frueheren PR #105 wurden in denselben 0.5.62-Release-Branch
uebernommen. Sichtbare Work-Notizen erhalten keine wiederholten
Herkunfts-Badges und keine farbigen Karten mehr. Bei direkten
Quelldatei-Auftraegen zeigt ein kleines Fenster tatsaechlich
empfangene Code-Teile an, niemals private Modellausgaben. Fehlerhaft
platzierter SSE-PHP-Initialisierungscode wurde im Zuge der
Zusammenfuehrung korrigiert. Abgeschnittene Modellstreams erscheinen
nicht laenger als gruen bestaetigter Erfolg. Kein Netcup-Livetest.
Der Runner aus Issue #102 bleibt davon unabhaengig offen.
Weitere Einzelheiten: `docs/COREUI_SKILLS.md` und
`docs/UEBERGABEPROTOKOLL_0_5_62.txt`.

## Chatwechsel-Uebergabe und offener 10-Seiten-Build (0.5.61)

Dieser Stand ist **dokumentarisch**: Das vollstaendige Protokoll des
Chatwechsels, der bereits in 0.5.60 integrierten live geschriebenen
Arbeitsberichte und HTML/CSS-Fortsetzungen sowie der **weiterhin
offenen** modelluebergreifenden Projekt-Workflow-Probleme steht in
`docs/UEBERGABEPROTOKOLL_0_5_61.txt`.
Die fortsetzbare Datei-/Agentensteuerung aus Issue #102 ist damit
noch nicht implementiert. Der produktive Live-Test bleibt offen.
Ollama-Modelfile, private Plaene und externe Homepage bleiben unveraendert.

## Live geschriebene Arbeitsberichte und HTML/CSS-Fortsetzung (0.5.60)

Die bereits gespeicherten und erklaerten Arbeitsschritte erscheinen
waehrend eines laufenden CoreAI-Auftrags jetzt **der Reihe nach**:
Jeder Text wird im Arbeitsweg sichtbar geschrieben; danach wird der
naechste Ereignisblock gezeigt. Der Server protokolliert Ereignisse
unverzueglich wie zuvor. Nach Reload, beim Einklappen oder bei
`prefers-reduced-motion` sind die vollstaendigen Berichte ohne
Warteanimation lesbar. Rohes internes Modell-Reasoning wird nicht
ausgegeben, sondern ausschliesslich bestaetigte Arbeitsereignisse
und ausdruecklich oeffentliche Modell-Zwischenmeldungen.

Die bestehende Source-Only-Route kann `done_reason=length` jetzt
auch bei HTML und CSS kontrolliert fortsetzen. Genau diesen Grund
zeigten die Netcup-Screenshots fuer `index.html` und `about.html`.
Es werden nur inerte Quelltextfragmente zusammengesetzt, anschliessend
durch den vorhandenen Parser verifiziert und erst danach fuer ein
ZIP freigegeben. Fuer vollstaendige, ueber einzelne HTTP-Turns
fortsetzbare Grossprojekte ist **Issue #102** weiterhin offen,
insbesondere die 12k-Plan- und Neun-Dateien-Grenzen.

Uebergabe: `docs/UEBERGABEPROTOKOLL_0_5_60.txt`.

## Quantum-Zuordnung und lesbares Copyright (0.5.59)

Der interne Info-Bereich zeigt **QUANTUM SOFTWARE** als Familienzeile und
**COREAI: TCI INTERFACE** als eigenstaendigen Produktnamen. Die alte
Projektbezeichnung ist dort entfernt. Das Studio-Logo bleibt erhalten.

Alle sechs internen Oberflaechen nennen nun sichtbar **2023-2026 Patrick
Schildgen** und Starlight Unit Studios. Die Schrift wurde auch auf
schmalen Anzeigen vergroessert und kontrastreicher gestaltet.
Die begleitenden Copyright-Kopfzeilen sind angeglichen; historische
Lizenznamen und materiell unveraenderte Lizenzbedingungen bleiben bestehen.

Siehe `docs/UEBERGABEPROTOKOLL_0_5_59.txt`.

## Netcup-Migrationsberechtigungsfix (0.5.58)

Bei der Installation von 0.5.57 war `scripts/migrate.sh` im Git-Tree nicht ausfuehrbar (`100644`), obwohl `install.sh` es direkt ausfuehrte. Die Rechte wurden bisher erst *nach* dem Datenbankimport gesetzt. Ab 0.5.58 ist `migrate.sh` als `100755` versioniert, `setup.sh` und der Dev-Updater korrigieren Rechte vor dem Start, und `install.sh` nutzt den expliziten `bash`-Aufruf.

`tests/migration-permissions-selftest.sh` sowie der erweiterte `tests/setup-oneliner-selftest.sh` pruefen die Regression ohne produktive Daten. Kein frischer Datenbankimport oder erfolgreiches Netcup-Deployment wird durch den GitHub-Merge allein belegt. Der bestehende One-Liner kann erneut ausgefuehrt werden.

## Produktname und Quantum-Softwarefamilie (0.5.57)

**CoreAI: TCI Interface** ist der offizielle Produktname. Das Produkt
gehoert zur **Quantum**-Softwarefamilie; der Familienname ist eine
untergeordnete Klassifikation und kein obligatorischer Namensvorsatz.
Die frueheren Namen **E.M.B.E.R. CoreUI** und **Ember CoreUI** bezeichnen
die historischen Versionen derselben Software. Das E.M.B.E.R.-Framework
und Mara als lokale TCI bleiben Bestandteile der Architektur.

Aktueller Repository-Pfad:
`https://github.com/Starlight-Unit-Studio/CoreAI-TCI-Interface`.
Die bisherige `coreui.starlight-unit.de`-Serveradresse, die technischen
`coreui`-Identifikatoren, bestehende Installation, Ollama-Modelle und
private Daten bleiben unveraendert. Die getrennte oeffentliche
Starlight-Unit-Homepage wird nicht in diesem Repository bearbeitet.

Siehe `docs/PRODUCT_NAMING.md` und
`docs/UEBERGABEPROTOKOLL_0_5_57.txt`.

## Oeffentliche Mara-Arbeitsmeldungen und vertiefte Ablaufdiagnose (0.5.56)

Der bereits existente, persistierte Arbeitsweg erklaert nun echte
Modell- und Werkzeugereignisse als lesbare Fortschrittsnotizen. Diese
automatisch aus Ereignissen abgeleiteten Erklaerungen sind im Interface
ausdruecklich als solche gekennzeichnet. Separate, tatsaechlich vom
Modell formulierte Zwischenmeldungen sind ebenfalls moeglich: Vor
einem nativen Werkzeugblock darf das Modell optional genau eine
benutzersichtbare Zeile
`[COREUI_PUBLIC_PROGRESS]Ich pruefe den Plan.[/COREUI_PUBLIC_PROGRESS]`
ausgeben. Der Parser trennt die Notiz vom Werkzeug, der bestehende
Dispatcher validiert die Aktion unveraendert und der private
Work-Store speichert nur eine kurze, gefilterte Notiz aus dem
oeffentlichen Antwortkanal. Rohe Ollama-Thinking-Tokens, interne
Prompts und Werkzeuginhalte werden nicht als Kommentare veroeffentlicht.
Keine zusaetzlichen Modellaufrufe und keine neuen Retry-Schleifen.

Nach Abschluss ist die Timeline standardmaessig zugeklappt, mit
Schrittzaehler und einer kurzen, nachpruefbaren Zusammenfassung.
Im geoeffneten Verlauf bleiben Zeitpunkt, Modell-/Werkzeugstatus,
Fehlerdiagnosen und die Herkunft der Texte sichtbar. Die Darstellung
bleibt mit der geschuetzten Sitzungs-/Antwort-Historie verknuepft.
Eine fehlende explizite Modellnotiz wird nicht als echte
Modellkommentierung ausgegeben.

Siehe Issue #97 und `docs/UEBERGABEPROTOKOLL_0_5_56.txt`.
Realer Netcup-Preflight und Android-Livetest sind noch offen.


## Ausfuehrliche oeffentliche Arbeitsnotizen (0.5.56)

E.M.B.E.R. CoreUI dokumentiert jetzt reale Arbeitsschritte als kompakte,
spaeter erneut aufklappbare Arbeitsnotizen: Was wird gerade getan,
welche Quelldatei fehlt noch, welcher Job ist fehlgeschlagen und
welche gepruefte Aktion folgt. Die Ich-Form erklaert den sichtbaren
Arbeitsablauf und ist **kein ungekueztes privates Modell-Reasoning**.
Die Eintraege stammen weiterhin aus den authentifizierten Work-Events
und zeigen darunter technische Details mit strikt freigegebenen Werten.
Laufende Arbeit bleibt aufgeschlagen, fertige Antworten sind
standardmaessig zusammengeklappt. Auf Android bleibt der Detailverlauf
scrollbar und auch nach Reload bei einer gespeicherten Nachricht erreichbar.
Uebergabe: `docs/UEBERGABEPROTOKOLL_0_5_56.txt`.

## PHP-Syntax-Hotfix (0.5.55)

Der Netcup-Preflight von 0.5.54 stoppte wegen eines nicht escapten PHP-Dollarzeichens in einem neuen Regressionstest. 0.5.55 repariert die reine Test-String-Syntax, ohne den Project-Build, den Installer oder das Modelfile zu veraendern. Der volle Live-Test bleibt offen. Uebergabe: `docs/UEBERGABEPROTOKOLL_0_5_55.txt`.

## Legacy-Countdown im CoreUI-Chat entfernt (0.5.53)

Der sichtbare, orangefarbene 310-Sekunden-Countdown ueber der
Texteingabe war ein rein clientseitiger Rest aus STU Console.
Er wurde durch `startCountdown()` bei Antworten,
Fortsetzungen, Bearbeitungen und Sitzungswechseln gestartet.
Nach Ablauf zeigte er lediglich "noch dabei": Er steuerte
weder einen Ollama-Timeout noch eine echte Abbruchfrist.

0.5.53 entfernt `MAX_WAIT_SEC = 310`, die
`startCountdown/stopCountdown`-Hilfsfunktionen, alle
Aufrufstellen und die CSS-Animation `.ember-countdown`.
Dadurch gibt es keine irrefuehrende Zeitangabe mehr oberhalb
des Chat-Eingabefelds.

Die wirklichen Netzwerk-Timeouts, SSE-Idle-Ueberwachung,
Modellaufrufe, Session-Polling, Typing-Anzeige,
modell- und serverseitig gemessene Antwortlaufzeiten
sowie die optionale Ortszeit im Header bleiben erhalten.
Der Frontend-Regressions-Selbsttest prueft, dass kein
Countdown mehr verdrahtet ist und die wichtigen
Laufzeit- und Pollingfunktionen unberuehrt bleiben.
Die Cache-Schluessel fuer `js/console-app.js` und
`css/console.css` wurden aktualisiert.

Weder das Betreiber-`config/Modelfile.template` noch
Ollama-Kontext, Sliding-Window, Sampler, Server-Timeout,
Planung, Dateigenerierung oder der Installer wurden
geaendert. Uebergabe:
`docs/UEBERGABEPROTOKOLL_0_5_53.txt`.

## Native-Projektumsetzung ohne generierten Python-Schreibcode (0.5.54)

Der Netcup-Livetest vom 26.10.2026 hat die freigegebene
Homepage erstmals an den Python-Worker uebergeben.
Job 108 endete mit `SyntaxError: unterminated triple-quoted
string literal`. Danach lieferte der Modell-Folgeaufruf
nur eine unabgeschlossene Ankuendigung statt Dateien.

Im CoreUI-Code existieren zwei Dateierstellungswege:
(1) allgemeine `[PY]`-Skripte direkt aus dem Modell;
(2) ein vorhandener Source-First-Modus, der
HTML/CSS/JS-Dateien einzeln erzeugen laesst und mit
einem servereigenen, Base64-kodierten Python-Writer
unveraendert speichert und als ZIP verifiziert.
Bisher war Source-First teilweise an die Modell-ID
gebunden. Der freigegebene Native-Build hat den
sicheren Modus nicht verbindlich aktiviert.

Ab 0.5.54 aktiviert ein gepruefter,
kontogebundener `PROJECT_BUILD`-Aufruf einen
**serverseitigen, nur waehrend des Build-Callbacks
gueltigen Source-First-Modus**. Dieser wird
unabhaengig von lokalem Gemma, einem umbenannten
Modell, Qwen oder Ollama Cloud genutzt. Die
einzelnen Projektdateien werden jeweils validiert;
die bestehende servereigene ZIP-Routine uebernimmt
ausschliesslich inerte Quelldaten. Ungueltige
Projektmanifeste schlagen fehl, statt heimlich
auf generiertes `[PY]` oder allgemeine Prosa
zurueckzufallen. Bei fehlgeschlagener Dateierzeugung
gibt es keine erfundenen Downloads oder
Versprechen spaeterer Hintergrundarbeit.

Die vorhandenen Preflight-Selbsttests pruefen
nun auch die Modellnamen-Unabhaengigkeit, die
enge Berechtigung des neuen Ausfuehrungsmodus
und den Schutz gegen ungeschlossene dreifache
Python-Anfuehrungszeichen. Normale Python-Werkzeuge,
die globale serielle Modellwarteschlange, die
persistenten Plaene und alle API-Parameter bleiben
unveraendert.

Hinweis: Das genaue generierte Python aus Job 108
ist im Screenshot nicht vorhanden; die konkrete
Entstehung des Syntaxfehlers ist damit nicht
abschliessend beweisbar. Der Live-Test nach dem
Update bleibt erforderlich. Uebergabe:
`docs/UEBERGABEPROTOKOLL_0_5_54.txt`.

## Preflight-Hotfix nach Thinking-Override (0.5.52)

Der reale Netcup-Compose-Preflight von 0.5.51 meldete unmittelbar
nach den ersten Paketierungspruefungen:
`Thinking-Schalter oder Mehrfachanhaenge sind unvollstaendig paketiert.`
Ursache war **keine fehlende Funktion**, sondern eine veraltete
Grep-Bedingung in `scripts/preflight.sh` und
`scripts/preflight-native.sh`. Sie forderte wortwoertlich
`'think' => ember_thinking_enabled()`, obwohl 0.5.51 den
korrekten neuen Aufruf `$thinkOverride ?? ember_thinking_enabled()`
eingefuehrt hatte. Die beiden Aufrufvarianten erhalten weiterhin
den kontobezogenen Thinking-Standard.

Beide Preflights pruefen nun die tatsaechliche
`'think' => ... ember_thinking_enabled()`-Bindung
unabhaengig von Leerzeichen und zusaetzlichem Override,
anstatt den gesamten PHP-Ausdruck exakt vorzugeben.
Thinking und persistente bis zu zehn Anhaenge haben
separate Erfolg-/Fehlermeldungen. Der tiefergehende
`scripts/reply-pipeline-selftest.php` ueberprueft
unveraendert die konkrete Thinking-Override-Semantik.

**Weder das aktive Modell noch dessen Modelfile oder
Sampler-/Kontextparameter wurden geaendert.** Keine
Migration, kein Installer-Umbau, keine Writer- oder
ZIP-Aenderung. Auch der neue Modell-Projekt-Dispatch
aus 0.5.51 bleibt erhalten. Die korrigierte
Bash-Grep-Bedingung wurde separat mit gueltigen
alten/neuen und ungueltigen PHP-Testausdruecken
durchgespielt. Ein vollstaendiger Netcup-Preflight
nach dem Update steht aus. Details:
`docs/UEBERGABEPROTOKOLL_0_5_52.txt`.

## Modellgesteuerte Umsetzung gespeicherter Projektplaene (0.5.51)

Der Netcup-Livetest nach 0.5.50 zeigte beim Nutzerauftrag
"Lass mal den Plan umsetzen" keinen Build. Der komplette
Streaming-Antworttext umfasste nur 33 Zeichen, danach meldete
der Arbeitsverlauf `continuation_limit`. Ohne ein vom Modell
gewaehltes `PROJECT_BUILD`-Werkzeug blieb der bereits fertige
Markdown-Plan zwar erhalten, aber die Projektdateien wurden
nicht erzeugt.

Neu: Ist in genau diesem authentifizierten privaten Chat bereits
ein fertiger, SHA256-verifizierter Projektplan vorhanden, trifft
Mara **vor** einer grossen Chat-Antwort eine kurze eigene
Modellentscheidung: `START_BUILD` oder `CHAT`. Die
Entscheidung verwendet den vorhandenen Modellnamen mit
`think:false` **nur fuer diesen einzelnen Klassifikationsaufruf**,
ohne SKILL-Dokument-Wiederholung oder Aenderungen am Modelfile
und an den Ollama-Sampleroptionen. Keine Regex auf
"umsetzen", "los" oder andere Woerter des Nutzers.
Eine bestaetigte Build-Wahl wird als echter `PROJECT_BUILD`-
Aufruf in den bisherigen geprueften Native-Tool-/Python-/
ZIP-Pfad uebergeben. Rueckfragen, Entwurfsplaene,
fremde Konten sowie fehlerhafte/abgeschnittene Entscheidungen
duerfen diesen Weg nicht starten und laufen normal als Chat.

Der Projekt-Writer beruecksichtigt jetzt bis zu **neun**
ausdruecklich im fertig gespeicherten Plan genannte
sichere, flache Quelldateinamen. Beispiel: sechs
`.html`-Seiten, `style.css` und `main.js`.
Er erzeugt nicht mehr standardmaessig nur drei Dateien,
wenn der Plan weitere benannte Unterseiten verlangt.
Die zuvor harte 6500-Byte-Planbegrenzung liegt jetzt bei
12000 Bytes ohne stilles Beschneiden. Bei Ueberschreitung
von Groesse oder Dateianzahl erscheint eine
konkrete, nicht als Erfolg ausgegebene Blockermeldung.
Andere Frameworks bleiben ausserhalb des begrenzten
statischen Website-Writers.

Unvollstaendige Ollama-Antworten hinterlassen jetzt
`done_reason`, Tokenzaehler, Thinking- und Textlaengen
als **private technische Arbeitsereignisse**, ohne
den eigentlichen Antworttext mitzuschreiben.
Die Meldung ueber ein Antwortlimit wird fuer
normale Gespräche nicht mehr faelschlich als
"nicht freigegebener Gesamtplan" bezeichnet.

Regressionen fuer die modellwaehlbare Freigabe, die
vollstaendige Dateiliste, Kontotrennung, Entwurfsverbot,
Einmal-Ausfuehrung, unvollstaendige Token und die
zwei Chatpfade liegen in
`scripts/model-project-intent-selftest.php` und
sind an beide Preflight-Varianten angeschlossen.

**Unveraendert:** `config/Modelfile.template`,
Modell-/Sampler-Konfiguration, persistierte Plaene und
Datenbankmigrationen, Python-Sandbox, ZIP-Writer,
Installer und globale serielle Ollama-Warteschlange.
Siehe `docs/UEBERGABEPROTOKOLL_0_5_51.txt`.
Ein echter Netcup-/Gemma-Livetest steht noch aus.

## Integrierte Markdown-Vorschau im Chat (0.5.50)

Geschuetzte Markdown-Dateien, insbesondere Maras versionierte
`projektplan-vN.md`-Dateien und von Python erzeugte .md-Dateien,
erscheinen jetzt direkt unter der zugehoerigen Chatantwort als
scrollbare, in CoreUI gerenderte Lesefenster. Das gilt auch fuer
aeltere Nachrichten beim Neuladen des Chatverlaufs. Andere
Dateitypen behalten ihre bisherigen Downloadkarten.

Die Dokumentleiste bietet drei Touch- und Tastatur-bedienbare
Aktionen: **Kopieren**, **Herunterladen** und **Vergroessern**.
Das dritte Symbol oeffnet auf modernen Browsern eine
bildschirmgroessere Leseansicht, die per Schaltflaeche oder
Escape geschlossen werden kann. Das Laden erfolgt bei
sichtbar werdenden Nachrichten, damit alte Chatverlaeufe
nicht unnoetig viele Dateien gleichzeitig abrufen.

Die Vorschau laedt die unveraenderte Datei nur ueber die
bereits vorhandenen, authentifizierten API-Endpunkte und
prueft erwarteten Binaertyp, Bytegroesse sowie (sofern
browserseitig verfuegbar) SHA256. Sie stellt Markdown mit
`js/coreui-markdown.js` als **inertes DOM** dar, niemals
mit `innerHTML` oder einem fremden Dienst. Der interne
Entwurfsmarker wird nur in der Ansicht ausgeblendet und
als Entwurfsstatus gekennzeichnet; beim Download bleiben
die originalen Bytes erhalten. Die Kopieren-Schaltflaeche
liefert den sichtbaren Markdown-Text ohne internen Marker.
Die Vorschau ist auf kleinere Markdown-Dateien begrenzt;
uebergrosse Dateien behalten die Original-Downloadkarte.

Neue Komponenten:
`js/coreui-markdown-preview.js` und responsive Styles
in `css/artifacts.css`. Die vorhandene Dateikartenlogik
delegiert nur geeignete Markdown-Dateien an diesen Viewer;
die bisherige Sicherung aller anderen Downloads bleibt
unangetastet. Der Browser bezieht die neue Fassung ueber
aktualisierte Asset-Versionen in `app.html`.
`scripts/markdown-preview-selftest.php` ist in beide
Preflight-Varianten eingebunden.

**Unveraendert:** `config/Modelfile.template`,
Ollama-Parameter, PHP-Downloads/Authentifizierung, SQL,
Installer, FIFO, Planungsengine und Python-/ZIP-Writer.
Uebergabe: `docs/UEBERGABEPROTOKOLL_0_5_50.txt`.

## Robustere Modellwerkzeug-Erkennung bei Plan-Fortsetzungen (0.5.49)

Beim ersten produktiven PLAN_CONTINUE-Test nach 0.5.48 schrieb Mara
eine vom Server als unvollstaendig oder ungueltig gewertete
Werkzeugantwort. Der Browser zeigte keine neuen Markdown-Versionen,
obwohl der Modellstream erfolgreich empfangen worden war.
Die tatsaechliche rohe Werkzeugausgabe war im Screenshot nicht
sichtbar. Feststellbar war jedoch: 0.5.48 akzeptierte PLAN_CONTINUE
nur mit einem exakt geschlossenen Block ohne davorstehenden Kommentar.
Fuer PLAN_SAVE gab es bereits eine tolerante Rettung, fuer CONTINUE nicht.

Die neue, strikt auf **planende** Modellwerkzeuge begrenzte
Erkennung behandelt deshalb auch einen einzelnen ungeschlossenen
PLAN_CONTINUE-Aufruf als fortsetzbaren Auftrag, selbst wenn Mara
vorher einen kurzen Satz oder danach Markdown ausgibt.
Der Inhalt dieser ungesicherten Modellantwort wird **nicht**
blind an den Plan angehaengt. Stattdessen wird die letzte
authentifizierte, kontobezogene Markdown-Version geladen und die
bestehende serielle Planungs-Engine gestartet. Auch nach
Ollama-Abbruch darf nur diese reine Plan-Fortsetzung weiterlaufen.
Projekt-Builds und gemischte Werkzeuge bleiben gesperrt.

Beide Wege, SSE und synchroner Chat, erkennen diese Aufrufe,
ohne eine weitere generische Textfortsetzung mit Wiederholungsgefahr
zu starten. Die Arbeitsereignisse enthalten nur technische
Statusinformationen und Groessen, nicht den privaten Modelltext.
Neue Regressionstests pruefen offene und abgeschlossene Marker,
Slash-Schreibweisen, kurze Einleitungen, abgebrochene Generierungen,
mehrzeilige Inhalte und die Ablehnung gemischter oder zitierter
Werkzeugaufrufe.

**Keine Anpassung** an `config/Modelfile.template`, Ollama-Optionen,
Projekt-Writer, Datei-/ZIP-Werkzeuge oder den Installer.
Siehe `docs/UEBERGABEPROTOKOLL_0_5_49.txt`.
Die genaue Rohausgabe des fehlgeschlagenen Netcup-Turns ist nicht
vorhanden; der wirkliche Netcup-Livetest bleibt erforderlich.

## Autonome mehrstufige Markdown-Projektplanung (0.5.48)

Der private CoreUI-Chat kann einen vom Modell begonnenen Markdown-Plan
nun auch dann ohne erneute Nutzeraufforderung in mehreren Schritten
fortsetzen, wenn der erste PLAN_SAVE-Block unvollstaendig blieb.
Das neue isolierte Modul `api/console_plan_autorun.php` legt
jede bestaetigte Textfortsetzung als neue, kontogebundene und
unveraenderliche .md-Version ab. Die in 0.5.46 eingefuehrte
Entwurfssicherung bleibt jederzeit aktiv. Es werden maximal vier
weitere Modellaufrufe pro Werkzeugaktion ausgeloest, nie parallel.

Fortsetzungsanfragen enthalten nur das Arbeitsziel, bisherige
Ueberschriften und ein begrenztes letztes Textfenster, nicht den
gesamten immer groesser werdenden Plan. Das Modell wird explizit
zu neuen Abschnitten und eindeutiger Fertigmeldung aufgefordert.
Ein Abbruch oder eine Antwort ohne bestaetigten Abschluss
verbleibt bewusst im Zustand UNVOLLSTAENDIGER ENTWURF.
Wiederholte Abschnitte oder unerwuenschte neue Werkzeugaktionen
werden abgelehnt. Ein erhaltener Entwurf kann durch das vom
Modell gewaehlte PLAN_CONTINUE auch in einem spaeteren Turn
erneut bearbeitet werden.

Das System erstellt dabei nur Markdown-Planungen, keine
ungefragten Projektdateien. Der bestehende Projekt-Builder
bleibt separat und verweigert Entwuerfe. Ein erfolgreicher
Preflight beweist noch nicht, dass ein bestimmtes lokales Modell
den mehrstufigen Abschlussmarker auch tatsaechlich verwendet.
`scripts/plan-autocontinue-selftest.php` prueft mit fest
vorgegebenen Modellantworten den Mehrschrittprozess, Versions-
und Kontotrennung und die Unterscheidung von fertig/unfertig.

**Unveraendert:** `config/Modelfile.template`, Ollama-Sampling,
Modell-FIFO, Installer/One-Liner, Datenbankmigrationen und
Python-/ZIP-Writer. Siehe `docs/UEBERGABEPROTOKOLL_0_5_48.txt`.

## Frontend-Preflight-Abgleich (0.5.47)

Nach 0.5.46 pruefte der Frontend-Selbsttest weiterhin den alten
SSE-Fallback-Quelltext `if (!$fileTaskHandled && ($hasMarker`,
obwohl die Planungs-Aenderung absichtlich zusaetzlich
`!$nativeToolHandled` voraussetzt. Von den 18 damaligen Teilbedingungen
waren 17 weiterhin erfuellt; ausschliesslich die veraltete
Stringpruefung loeste den Preflight-Fehler aus.

Der Frontend-Vertrag prueft jetzt den **geschuetzten Fallback**
inklusive bereits erledigter Native-Tools und zeigt bei Fehlern
den konkreten Dateipfad-/Cloud-/Lokalvertrag. Die darauf folgenden
Composer- und Run-Recovery-Pruefbedingungen wurden ebenfalls
gegen den aktuellen Quellstand abgeglichen (19/19 erfuellt).
Die PHP-Regel fuer den neuen Fallback wurde isoliert gegen
die aktuelle, alte und ungeschuetzte Variante getestet.

Dies ist ein reiner Selbsttest-Hotfix. Keine Aenderung an
`api/console_stream.php`, Plan-Speicherung, Modell-Ausgaben,
Datei-/ZIP-Generator, Cloud-/Lokalroute oder Benutzer-Modelfile.
Der vollstaendige Netcup-Preflight und der Mara-Planungs-Livetest
bleiben die Abnahme.

## Modellgesteuerte Projektplanung und Markdown-Entwuerfe (0.5.46)

Der Planungsweg verarbeitet jetzt auch Maras vom Modell eigenstaendig
gewaehlte Schreibweise `[COREUI/PLANSAVE]` mit kurzer Einleitung
und sicherem Markdown-Inhalt. Ein fehlender Abschlussmarker oder
ein offen gebliebenes Tokenlimit erzeugt ausdruecklich nur einen
kontobezogenen, versionierten und herunterladbaren Markdown-Entwurf
statt einen angeblich abgeschlossenen Projektplan. Der Entwurf kann
niemals unbemerkt in den Projekt-Builder gelangen.

Die Stream- und Sync-Fortsetzung reicht nur ein kompaktes letztes
Textfenster an Ollama zurueck, entfernt echte Ueberlappungen und
stoppt bei Wiederholung bereits geschriebener Abschnitte, anstatt
die Ausgabe durch Duplikate zu verlaengern. Ein nicht aufloesbares
Tokenlimit wird nun sichtbar benannt. Korrekte native SAVE/APPEND/
PROJECT_BUILD-Aufrufe bleiben unveraendert nutzbar.

Das ist kein Beweis, dass ein lokales Modell beliebig lange Plaene
in einem einzigen Aufruf zu Ende bringen kann. Unvollstaendige
Planungen werden erhalten und als solche ausgezeichnet; eine
fertige Version muss vor dem Bauen vorliegen. Ein echter
Netcup-/Gemma-4-Live-Durchlauf bleibt die Abnahme.

**Unveraendert:** `config/Modelfile.template`, Ollama-Sampler,
Installer/One-Liner, Modell-FIFO, SQL-Migrationen, Python-Sandbox
und bisherige ZIP-/Dateiwerkzeuge. Siehe
`docs/UEBERGABEPROTOKOLL_0_5_46.txt`.

## Betreiber-Modelfile und Preflight-Abgleich (0.5.45)

Die aktive `config/Modelfile.template` ist die massgebliche, vom
Betreiber selbst veraenderbare Mara- und Ollama-Konfiguration. Der
Gemma-Selbsttest verlangt nicht mehr die vier Parameter und die
alte SYSTEM-Passage des archivierten E2B-Exports. Geprueft werden
stattdessen die Gemma-Struktur, Parameter-Syntax, der Abgleich mit
`var/Modelfile.generated` (falls vorhanden), die Unveraendertheit
des historischen Archivs und fehlende API-Overrides fuer lokale Gemma.
Auch der Branding-Test erzwingt keine historischen Parameter.
Die Preflights zeigen bei Gemma-Fehlern jetzt den konkreten Grund.

Das aktive Modelfile selbst und der Ollama-Installer wurden nicht
veraendert. Ein gleicher SYSTEM-Text beim Live-Audit beweist noch
keinen erfolgreichen E4B-HTML/CSS/JS+ZIP-Auftrag.

## Ollama-Modell-Audit und lokale Quelldateien (0.5.42)

**Stand der Vorlage:** Waehrend der Entwicklung dieses Releases wurde die aktive `config/Modelfile.template` auf `main` separat auf 1717 Zeichen gekuerzt und `num_predict -1` hinzugefuegt. CoreUI 0.5.42 ersetzt diesen aktuellen Stand nicht. Die historischen E2B-Exportdaten sind nicht mehr das zu erzwingende aktive System. Der neue read-only Live-Audit prueft die tatsaechlich laufende Konfiguration gegen diese kurze aktuelle Vorlage.

The checked-in `config/Modelfile.template` remains the previously approved owner configuration. It does **not** prove that a running `ember-coreui:latest` model has the same SYSTEM text. The read-only `scripts/model-live-audit.php` compares the actual Ollama `/api/show` system against the template and reports mismatches with SHA-256 prefixes and byte lengths, not the prompt body. It is included in Compose and Native preflights without any new installation step.

A missing local E4B file-source response now exposes its true class (transport timeout, Ollama HTTP error, successful empty content, queue timeout) and safe timings in Work Events. CoreUI does not repeat a known timed-out request unchanged. For the narrow `think:false`+HTTP-200-empty case, it tries once with thinking enabled. Isolated file-source calls do not re-inject full selected SKILL.md bodies; normal chat skills remain available. Runtime native-tool instructions are shorter, but all four tools are unchanged. Owner Modelfile, Ollama options and installation flow stay untouched. Live Netcup acceptance remains necessary. See `docs/UEBERGABEPROTOKOLL_0_5_42.txt`.

## Projektdateien und Satzzeichen (0.5.41)

The project source-file parser no longer drops the last source filename when it is followed by an ordinary sentence-ending period. This caused the 0.5.40 live preflight to see only `index.html` and `style.css` instead of all three files, including `main.js`. The ZIP filename matcher now makes the same distinction for names such as `homepage.zip.`, while rejecting true extra extensions such as `main.js.backup`. The model-selected tool handoff, owner Modelfile and existing installer remain unchanged. See `docs/UEBERGABEPROTOKOLL_0_5_41.txt`.

## Projekt-Agent-Preflight und Werkzeugmarker (0.5.40)

CoreUI 0.5.39 introduced model-selected project tools, but the 0.5.38 contextual-project selftest was still testing a retired keyword-based handler. Version 0.5.40 replaces that stale contract with a live-path test: a model-produced `COREUI_PROJECT_BUILD` marker becomes a validated source-file/ZIP task using the current saved plan, without requiring download keywords from the user. Regression checks cover owner isolation, one-call dispatch, recursion blocking and rejected oversized plans. The old explicit download route remains compatible.

Compose and native Preflight now print the detailed failing project assertion rather than a generic masked message. The runtime `PROJECT_BUILD` tool example also now contains the correct literal newline. No Modelfile, Ollama settings, queue, installer steps or migrations were changed. See `docs/UEBERGABEPROTOKOLL_0_5_40.txt`.

## Native Modellwerkzeuge und Markdown-Projektplaene (0.5.39)

CoreUI now has a small **model-selected** native tool protocol for the private chat: Mara may save a Markdown plan, append a new immutable version, save and build in the same turn, or request an isolated source-file/ZIP build. The user does not need to repeat a rigid word sequence. The server validates every tool invocation; the model never gets host shell access or an unbounded instruction runner. Existing explicit file/Python commands remain usable.

Plan versions are stored per account and chat in migration 016, not in the transient chat context. They are downloadable as authenticated `projektplan-vN.md` artifact cards. The runtime adds a *small, clearly marked* plan excerpt without expanding the owner Modelfile. Plans beyond the safe per-file model budget are **not silently truncated for generation**: the full saved file remains downloadable, while chunked long-plan retrieval is a future extension.

The existing installation steps are unchanged. PHP syntax validation now emits the offending file and exact PHP error rather than the unhelpful `xargs: php exited 255`. Tests for older UI/stream assumptions were updated. See `docs/UEBERGABEPROTOKOLL_0_5_39.txt` for the honest Netcup live-acceptance checklist.

## Kontextuelle Projektfreigabe (0.5.38)

Mara can now recognize a clear implementation approval in the **same private conversation** after a static website or browser-game design has been agreed. "Dann setz unseren Plan um" or "Okay, dann legen wir los" is resolved against at most twelve preceding account- and session-scoped chat messages. The original user message remains unchanged. A bounded project specification is handed to the existing validated source-file and ZIP worker, instead of guessing from isolated words like "Homepage", "Download" or individual source filenames.

The route is intentionally narrow. Explanations, tentative planning, delayed tasks and framework/backend projects do not silently execute. Ordinary explicit file requests, current Skills switches, source validators, serial model queue, existing install process and owner Modelfile stay unchanged. This first step supports static HTML/CSS/JavaScript websites and browser games, not arbitrary software stacks. See `docs/UEBERGABEPROTOKOLL_0_5_38.txt` for remaining acceptance tests.

## Lange Antworten und eingefuegte Texte (0.5.37)

CoreUI no longer truncates private Mara responses to 60,000 characters when saving them to the chat. The regular schema migration 015 promotes the persisted message column from MEDIUMTEXT to LONGTEXT. The global/game chat retains its previous limits. **This does not change Ollama context or token generation limits.**

The private chat composer is now a multiline textarea. A paste exceeding roughly 8,000 characters automatically becomes a byte-for-byte Markdown attachment via the existing authenticated upload pipeline; longer editor text is also converted before sending. The file is bound visibly to the same user message and remains downloadable from history. Uploads must complete successfully before queued sends fire, and failed uploads restore the original text. Up to ten attachments per message are still permitted.

**Context disclosure:** A saved file is complete, but the current attachment extractor includes only a labelled head/tail excerpt in one model prompt. Please do not mistake a complete download for full-context ingestion. No changes were made to the owner Modelfile or model options. See `docs/UEBERGABEPROTOKOLL_0_5_37.txt` for tests and live acceptance.

## Skills API und Chat-Navigation (0.5.36)

The authenticated Skills API is now explicitly exposed by both production Nginx allowlists. An HTTP 404 at `api/user_skills.php` was previously reported as a vague login error. Per-account opt-in, CSRF rules and migration 014 remain unchanged.

When returning to the chat from Settings while a model is still working, CoreUI reads the existing server-owned run and safe work timeline through the authenticated message-history endpoint. The browser restores the waiting UI and polls for the original answer without launching another inference. Short message posts also use `fetch keepalive` to avoid being cancelled by immediate navigation. The serialized model queue remains untouched. See `docs/UEBERGABEPROTOKOLL_0_5_36.txt` for live acceptance.

## Aktives Mara-Modelfile (0.5.35)

The owner-requested change now affects the **active** `config/Modelfile.template`, not just a reference document. Its multi-line Mara SYSTEM content and four sampling parameters, including their order, were transferred from the supplied `ollama show --modelfile` export. Only Ollama export-specific details are normalized: a portable `FROM gemma4:e4b` placeholder compatible with the existing installer (instead of absolute E2B blob paths) and `SYSTEM """..."""` for reliable multiline parsing. The `DRAFT` blob path shown by Ollama is not copied into the portable template; `draft_num_predict 0` remains. **No modifications to the installer or update sequence.**

## Protected owner Modelfile (0.5.34)

The owner-provided E2B `ollama show` export is preserved in [docs/references/MODELFILE_E2B_USER_EXPORT_2026_10_25.txt](docs/references/MODELFILE_E2B_USER_EXPORT_2026_10_25.txt) as an immutable model-specific reference, not a replacement for the active E4B `FROM` directive. The current `config/Modelfile.template` and the complete installation/update flow are unchanged. See [docs/MODELFILE_CHANGE_POLICY.md](docs/MODELFILE_CHANGE_POLICY.md): keep the model prompt compact, use skills and backend tools as the flexible layer, and allow minimal necessary adjustments only within the tool-protocol section.

## Skills (0.5.33)

32 eigene Arbeitsanleitungen mit Quellen, Suchfunktion und eigenen Schaltern unter Einstellungen → MEINE SKILLS. Migration 014 einspielen, Skills auswählen und speichern. Pro Anfrage werden bis zu vier passende aktivierte Pakete geladen; `/skill kennung` priorisiert ein Paket. Siehe [Skills-Dokumentation](docs/COREUI_SKILLS.md) für Grenzen, Quellen und Live-Abnahme. Der universelle Projekt-Agent bleibt separat in Draft PR #69.

## Frontend preflight regression assertion repair (0.5.32)

This is a **test-only stability hotfix**, not a new file-generation route or an optimization. The frontend regression suite still expected `coreui_console_multifile_source_directive($userMsg)`, but 0.5.31 intentionally changed the private local Gemma route to the already validated `$sourceProjectRequest`, retaining `$userMsg` as the original user message. The runtime code was correct; the stale exact-source-string assertion caused the deploy preflight to fail even when its other 14 route conditions passed.

The refreshed assertion checks the actual normalized multi-source call **and** the device-local Gemma guard. Existing authenticated download checks, Cloud routing, ZIP writer, worker, Modelfile and model parameters are unchanged. No database migration, installer behavior change or activation of the separate universal-project pilot. Draft PR #69 remains disabled and unmerged. The new selftest must pass in the normal server preflight before a successful live update can be claimed.

## Homepage ZIP routing for device-local Gemma (0.5.31)

Version 0.5.30 established the exact failure condition `ollama_truncated_py_open` after 3,005 local generation tokens: the user's **unnamed multi-source anime homepage ZIP request** was not recognized as an existing multi-file project and fell into the older huge model-generated [PY] file writer, which was truncated. The screenshot also shows two answer **versions** (`1/2`, `2/2`) of the same user turn, not two different user prompts.

0.5.31 fixes that **routing gap**, without introducing a new file creation architecture. Only for a **device-local Gemma** ZIP homepage request with explicit download/creation intent, no named source files and no single-file instruction, CoreUI internally adds the conservative canonical source set `index.html`, `style.css`, `main.js`. It then uses the already-established local **one-source-per-Ollama-call** path with bounded validations and the CoreUI-owned deterministic ZIP writer. The original request remains otherwise unchanged. Named projects, standalone HTML files, unrelated archives, local Qwen, Ollama Cloud models (`gemma4:31b-cloud` included) and ordinary Python tasks retain their existing routes. All Ollama models remain selected via the locally configured Ollama instance, not a separate API integration.

The original request and negative routing cases have been added to the existing PHP regression suite; a focused PHP 8.4 reproduction verified the three inferred filenames, single-file preservation and unrelated archives. The next server run must confirm the actual three source requests, verified ZIP contents and working homepage in Android Chrome. An excessive or unclosed HTML/CSS/JS output may still fail its existing bounded validation; this release does not bypass those checks or promise full application quality. There is no Gemma Modelfile change, `num_ctx`, `num_predict`, database migration, change to global serial processing, or installer one-liner.

## Ollama file-completion diagnostics (0.5.30)

This is a narrow diagnostic and safety patch over the 0.5.29 rollback, not a new file-generation architecture. Existing model timeline entries now carry a safe Ollama completion label such as `ollama_stop_py_closed` or `ollama_truncated_py_open`, returned content size, `eval_count`, and `prompt_eval_count`. Raw prompts, Python, stderr, and private reasoning are never included.

For device-local Gemma 4 (`gemma4:e4b` and `gemma4:26b`), responses reported as truncated by Ollama or containing an unclosed [PY] marker cannot reach the Python worker. The existing single protocol retry remains; the post-worker recovery reply receives the same guard. `gemma4:31b-cloud` is still selected from local Ollama's model list and routed through that very same local Ollama endpoint. Cloud and Qwen workflows are not rerouted.

This does not guarantee that syntactically incorrect but fully marked Python is fixed. A controlled live retest should compare local Gemma to `gemma4:31b-cloud` and inspect the new completion label in the existing work timeline. There are no Gemma Modelfile changes, no `num_ctx` or `num_predict` overrides, no worker changes, no database migration, and no new installer command. Isolated local PHP validation passed; a full productive server acceptance remains open.

## Stability rollback to the pre-optimization 0.5.25 source (0.5.29)

**0.5.29 is a rollback, not a feature release.** Every operational source file is restored from the 0.5.25 commit `53dab3f6d78e0cd626824aa3ca883f441a7f193f`, the last version before the three unsuccessful post-0.5.25 changes. The rollback removes the HTML/CSS paired local Gemma generation (0.5.26), the single-homepage source-first special handling (0.5.27), and the generic Python syntax-check/repair experiment (0.5.28). The earlier 0.5.25 routing, Python worker, Qwen tool path, approved Gemma Modelfile and other product behavior remain unchanged.

Only the numeric version, six visible page version labels, content-based asset cache fingerprint, release documents and preflight references to the new release documents differ from the original 0.5.25 baseline. This uses a higher version label so the required version check remains monotonic. It does not reintroduce any of the removed optimizations. There are no new migrations, no modifications to the existing update one-liner or DB model and no new Python helpers. The developer updater excludes local `api/config.local.php`, `var/`, `uploads/` and `logs/` to preserve persisted data. Confirm the installed server version after running the existing update procedure; GitHub merging alone is not a live deployment.

## E.M.B.E.R. acronym and official terminal branding (0.5.25)

**E.M.B.E.R.** names the framework, backend and system. Its standalone terminal is **E.M.B.E.R. CoreUI**, and **Mara Thorne** is the TCI/CoreAI of the *Ravager Thorn*. **Ember**, by contrast, is a separate CoreAI of the *Starlight Ember*, linked to the later Sandra-engram storyline. These entities are deliberately not interchangeable.

In releases through `0.5.24`, the product display name `Ember CoreUI` was used incorrectly. From `0.5.25` onward, all current installer messages, user-facing product labels, metadata and branding policy use the correct dotted spelling. The former spelling is retained only as a historical project alias where needed to interpret old releases and license records. Existing technical identifiers such as `ember-coreui:latest`, `/opt/ember-coreui`, environment variables and database IDs are unchanged. No software logic or lore canon outside this naming clarification is altered.

## Mara Thorne CoreAI identity and Qwen compatibility (0.5.24)

The local Gemma Modelfile now uses Mara's more relaxed, spontaneous 2000s gamer-style voice inspired by the native image response style; the separate vision prompt also uses Mara and no longer claims a human biography. Technical file IDs, database tables, endpoints and environment variable names remain untouched to preserve installed data. The user's existing custom assistant display names continue to work, with the old default Ember displayed as Mara.

Gemma's `num_ctx` prohibition is retained: no Modelfile line and no Gemma request override. The accidentally retained `PARAMETER num_predict -1` Modelfile line is deleted as requested; `PARAMETER draft_num_predict 0` remains. Other approved sampling parameters stay as before.

Historical local Qwen coding models such as `qwen3.6:35b-a3e-q4_K_M` are explicitly routed back through the established isolated `[PY]` sandbox file-writing workflow, instead of the strict source-first multi-file ZIP path introduced later. Gemma's special local recovery and both working Cloud file routes remain separate. This restores the earlier *routing approach*, not a proven live performance result. It should be checked against the original Snake ZIP example after update.

## Bounded local JavaScript continuation (0.5.23)

The production test of 0.5.22 narrowed the failure to local `game.js`: HTML and CSS were accepted, but both JavaScript attempts terminated with `model_output_truncated`. This is an Ollama output-limit signal, not a proven source-parser defect. The browser ZIP pipeline and Cloud-provider exports remain unaffected.

When a local Gemma JavaScript source is stopped by the output limit, CoreUI retains the inert generated source **within the same private turn**, strips only an optional outer JavaScript fence, and requests up to **two** short source continuations. Each continuation receives the task and only a bounded exact suffix of existing JavaScript instead of the entire preceding source. Repeated overlaps are removed, full restarts with no progress and unsafe/tool-marker replies are rejected, and the complete concatenation must still satisfy the existing source validator before the ordinary sandbox writer can create any files. Failed or cancelled repairs never fabricate download cards. Non-JS files, Cloud-model exports, model defaults and the owner-approved Modelfile remain unchanged.

The local JavaScript source prompt no longer attaches the full CSS file, retaining the HTML DOM reference while reducing prompt length. Work events now expose only safe `source_chars`, `eval_count` and `prompt_eval_count` counts, never raw prompts, code or private data. The source size budget and two-continuation limit bound work. No migration, model-parameter change, or installer command change. This release needs a live Gemma verification to establish whether it resolves the original Snake request. It does not certify gameplay or JavaScript syntax without browser verification.

## Focused local Gemma ZIP output (0.5.22)

Production screenshots of 0.5.21 showed local Gemma repeatedly returning responses that the strict multi-source Markdown parser could not complete (`source_files_incomplete`). The same Snake ZIP request succeeded on GPT Cloud, proving that the Python sandbox, archive creation and authenticated download cards are operational.

For local `ember-coreui` / `gemma4` models and explicitly requested multi-file ZIPs, the pipeline now requests exactly one source file at a time in a short, independent Ollama message. These calls do NOT append the conversation's general Python tool hint, and the model is asked for raw HTML, CSS, JavaScript or another requested source type, not a `[PY]` writer or Markdown wrapper. Full raw sources, correctly fenced files, and one incomplete enclosing fence with a complete source body can be accepted. Invalid formats and incomplete HTML/CSS/JS are rejected. Each file receives at most one focused retry. The trusted server-owned Python writer then packages the complete source set in the existing isolated sandbox, unchanged.

Cloud models retain their already working 0.5.21 multi-file route. Single-file and other Python tasks also remain unchanged. The original Gemma Modelfile and its owner-approved parameters are not edited. Private data, scripts and raw model outputs are not included in user-facing work events. Source files and reasons are identified by safe metadata to locate future failures without leaking content. No database migration, no change to the original installer command. Production verification of local generation and actual gameplay is pending.

## Source-first browser-game ZIP exports (0.5.21)

For explicit multi-file ZIP projects with named sources (or an unnamed Snake/browser-game ZIP), the model is asked to return complete HTML/CSS/JS source files in inert fenced blocks. Missing sources are requested one at a time, using a bounded sequence. CoreUI then prepares a deterministic Python writer and verifies each emitted file plus the ZIP in the existing sandbox. Model-authored [PY] scripts are not executed on this specific path; generic Python and single-file tasks keep their existing routes. Incomplete source collections fail without a fake download. Actual Ollama output, PHP/DB/worker integration, verified download cards and browser gameplay still need acceptance on the live host.

## File tool routing restored (0.5.19)

Live testing of 0.5.18 still stopped before the worker with `source_files_incomplete`. Multi-file ZIP requests now use the established Python sandbox tool contract again. Both marker-wrapped and fenced Python tool scripts are accepted. Complete named source blocks and bounded two-response source merging remain optional recovery paths. The source-only restriction introduced in 0.5.16 no longer blocks the existing tool protocol. Worker verification, account-scoped artifacts and serial model requests remain required.

The following sections describe historical changes; their source-only routing is superseded by 0.5.19. Production acceptance remains pending.

## Incremental multi-file source recovery (0.5.18)

When the first model response and the single bounded retry each contain only part of an explicitly requested multi-file ZIP project, Ember now combines the safe named source blocks from both responses before invoking the existing deterministic writer. The second response replaces an earlier version of the same requested filename. The bridge still starts no worker unless every requested source is present, and it never adds model-supplied files that the user did not request.

The parser accepts longer outer Markdown fences so Markdown files can safely contain their own triple-backtick examples. It rejects unclosed fences, duplicate files inside one response, unsafe paths, incomplete projects, oversized payloads and jobs that would exceed the ten-artifact limit. Filename matching is case-insensitive while emitted names retain the spelling from the user request. Source bytes remain inert and are written only by the server-owned Python wrapper.

The PHP 8.3 syntax checks and expanded offline file-tool regression pass locally. GitHub Actions were not run because the available Actions quota is exhausted. Production acceptance with local Gemma, Qwen and Cloud GPT, actual authenticated download cards, ZIP inspection and Chrome/Android gameplay remains a required live test after the merge.

## Multi-model file export repair (0.5.17)

Both Ollama Cloud/API models and local Ollama models now use the same verified server-side project source-to-artifact route when the user explicitly requests a ZIP containing two or more named files. Neither provider is trusted to invent server download URLs, ZIP manifests or Python file-writing scripts. The backend assembles actual source blocks, writes files in the existing isolated worker, verifies the ZIP, and only then registers account-scoped download cards.

A confirmed cross-provider failure was the PHP `json_encode()` default slash escaping for base64 payloads: Python received strings containing `\\/` and rejected them with `binascii.Error: Only base64 data is allowed`. The fixed bridge uses `JSON_UNESCAPED_SLASHES` to preserve the exact bytes. The previously stale frontend regression guard now matches the valid missing-file protocol in the backend. An offline PHP-to-Python reproduction created all three Snake source files and a verified `snake-game.zip`; production Ollama, artifact-DB and mobile-browser acceptance is still required.

## Reliable local multi-file ZIP creation (0.5.16)

For an explicit local-model request naming two or more separate source files **and** a ZIP download, Ember now requests **one complete fenced source block per file** instead of demanding that Gemma invent a Python program to generate all the files. The existing vetted project bridge converts the inert source blocks into exact file bytes and runs only its own deterministic file-writing/ZIP-validation script in the isolated Python worker. The user receives only actually confirmed download artifacts. Incomplete responses get at most one focused re-prompt; a failed deterministic worker or artifact registration never triggers another expensive model-generated Python repair attempt. Single-file and unrelated Python tasks retain their existing behavior.

This addresses the `SyntaxError` / exit-code-0-but-`output_missing` failure class seen during a local Snake ZIP request. It does not guarantee that model-generated game logic works: actual HTML/CSS/JS validation and browser gameplay still need a live check. It also does not establish the cause of unrelated Ollama CPU stalls after switching models.

## Owner-approved Gemma 4 Modelfile (0.5.20)

`config/Modelfile.template` now reproduces the owner's supplied Mara Thorne / TSA-Thorn Modelfile verbatim: `FROM gemma4:e4b`, `TEMPLATE {{ .Prompt }}`, `RENDERER gemma4`, `PARSER gemma4`, temperature 1, top_k 64, top_p 0.95, draft_num_predict 0 and num_predict -1. This supersedes the parameter-free template policy introduced in 0.5.15.

Both installers default to gemma4:e4b and rebuild the separate CoreUI model from this file. An explicit COREUI_BASE_MODEL setting still overrides only the FROM line. The installer no longer rejects PARAMETER directives. The existing model name and installer command remain unchanged.

For local `ember-coreui:*` and `gemma4:*`, both streaming and synchronous Ollama API calls omit the `options` object entirely, including on older installations where a preserved `api/config.local.php` still declares `STU_EMBER_NUM_CTX` or experimental tuning. Other models retain their separate, existing settings policy. The top-level `think` switch and the requested model/stream flags are **not** Modelfile `PARAMETER` directives.

The previous eight-second synthetic warmup is disabled for local Gemma: it could time out while loading a CPU model and contend with a real chat request. No automatic Ollama/service restart is introduced.

The base Gemma model can have its own inherited defaults, and `ollama show --modelfile` may display upstream settings not authored by CoreUI. Updating CoreUI does not alter the shared upstream model or prove that occasional Ollama stalls have stopped. The update includes CLI regression checks and separate Compose/Native preflights.

## PHP/MariaDB runtime validation (0.5.14)

The Docker-based installer verifies that the newly built PHP image actually loads `pdo_mysql` before starting services, then force-recreates the dedicated PHP and web containers using the validated image. Both runtime variants now check extensions via PHP's `extension_loaded()` rather than piping `php -m` through `grep -q`, which can report a false negative with `pipefail`. If the PDO driver is truly unavailable, deployment fails visibly, not silently. MariaDB data, accounts, uploads and configuration remain unchanged. Re-run the existing setup one-liner to apply the repaired installer; check the preflight on the server.

## Local file generation with Ollama models

Version 0.5.13 routes explicit file/download requests directly to the isolated Python tool path instead of spending a generic streaming inference first. The model receives a clear, executable `[PY]` file-tool contract and, if it produces no executable instruction, at most one shorter retry. For local `ember-coreui:*` and `gemma4:*` models, pure file-code calls default to `think:false` to avoid expensive analysis-only generations; define `STU_EMBER_LOCAL_FILE_THINK` as `true` in the server-side PHP configuration (for example `define('STU_EMBER_LOCAL_FILE_THINK', true);` in `api/config.local.php`) to opt in to reasoning for these calls. Other models keep their configured thinking behavior.

When a small model emits complete named HTML/CSS/JS code fences rather than a tool marker, the server can convert them into inert source files inside the existing isolated worker and build/verify a ZIP. Filename inference is limited to uniquely named files explicitly requested by the user. A complete project is never claimed when requested filenames or the requested ZIP are missing. Unconfirmed output remains an error, not a fabricated download. Real local-model performance and successful generation must be checked on the target server; this update does not guarantee any fixed latency.

## Persistent response and model timing

Version 0.5.12 restores the duration in the assistant message's information (ⓘ) panel. It displays **GESAMTDAUER** from the persisted server-side work event and, for newly measured model requests, **MODELLZEIT** separately. Both survive chat switches and reloads. Older 0.5.11 answers can show their previously recorded total time; model-only time is never guessed retroactively. When no saved work-event duration exists, a clearly labelled **AGENTENLAUFZEIT** is computed from the scoped, durable run timestamps. No new migration is needed.

## Persistent work timeline after E.M.B.E.R. replies

Version 0.5.11 keeps the public, server-observed operational work timeline attached to the assistant's completed message. The just-finished timeline stays expanded; older answers expose a clearly labelled **Arbeitsweg (N Schritte) anzeigen** control after reload. The browser combines authenticated saved events with a sanitized live SSE snapshot, including reconnect and polling fallbacks. The backend can recover events through the exact account/session/turn/run-key agent-run binding if the separate response-event association was interrupted. This is an operational audit trail, **not** private model chain-of-thought.

## Clickable conversation follow-ups

The Console can show optional tappable replies under an assistant response when E.M.B.E.R. explicitly ends with a closing question and a short bullet list. Buttons send the selected question through the normal authenticated chat workflow and remain available after reload. No HTML, URLs or model-generated code is executed. Mobile choices stack vertically.

## Native CPU image generation (planned)

The [Ember Image Engine plan](docs/COREUI_IMAGE_ENGINE_PLAN.md) adds a native C/C++ diffusion roadmap under P20. This version ships no image generation runtime or model weights. stable-diffusion.cpp, FLUX.1-schnell and SDXL-Turbo are candidates pending license, compatibility and measured CPU benchmarks. Future image jobs must share the global serial model queue.

## Development roadmap

The [implementation roadmap](ROADMAP.md) organizes the work into 29 delivery packages with dependencies, acceptance criteria, and a concrete first implementation sequence for generated file downloads.

The [E.M.B.E.R. CoreUI and E.M.B.E.R. master plan](docs/COREUI_MASTERPLAN.md) records the planned full agent, developer workspace, skill system, and independent implementation of the functionality inventoried from OpenClaw, Open WebUI, CrewAI, LangGraph, and Microsoft AutoGen. The [native image engine plan](docs/COREUI_IMAGE_ENGINE_PLAN.md) specifies a future CPU-only C/C++ diffusion adapter, shared model admission, explicit weight licensing and real benchmarks. No image generation runtime is claimed as shipped yet. The [feature matrix](docs/COREUI_FEATURE_MATRIX.md) tracks 82 stable requirements, reference coverage, existing foundations, open work, and acceptance criteria. The scope includes agent teams, stateful workflows, durable checkpoints, human review, result validation, and a visual workflow playground. These documents describe planned development, not features already shipped; the full reference inventory remains open.

Generated HTML, ZIP, and other output files must become real, persistent chat downloads. This remains an open, early implementation priority in [issue #29](https://github.com/Starlight-Unit-Studio/CoreAI-TCI-Interface/issues/29).

## Quick installation

**The same one-liner now installs and updates the latest merged `main` development source**, without a separate updater or GitHub Actions. It downloads the GitHub branch into a unique temporary directory, records the exact commit, and preserves local configuration, database data, accounts, sessions, uploads, logs, chat media, and profile images. The previous pinned, SHA-256-verified release ZIP remains available as an explicit opt-in via `COREUI_SETUP_SOURCE=release`.

```bash
( setup_file="$(mktemp)" && trap 'rm -f -- "$setup_file"' EXIT && curl -fsSL https://raw.githubusercontent.com/Starlight-Unit-Studio/coreui/main/setup.sh -o "$setup_file" && sudo bash "$setup_file" )
```

A fresh interactive installation asks for the administrator email address and password at the real terminal. The one-line SSH command stores the launcher in a protected, uniquely named temporary file and removes it on exit. The setup explicitly connects the account bootstrap to `/dev/tty`, while non-interactive Docker Compose calls run without a TTY requirement. The cloned checkout and its Git metadata stay in a unique temporary directory and are removed automatically; the installed tree excludes `.git`, `.github`, and bundled `releases`. For unattended first installation, provide `COREUI_ADMIN_EMAIL` and `COREUI_ADMIN_PASSWORD` as environment variables.

## Mandatory version increment for every merge

**Each PR merged into `main` MUST already include a numeric `VERSION` increment.** This applies to bugfixes, documentation, tests and any other changes, with no exceptions. Before merging, compare the PR's version against the existing `main` version using `python3 scripts/version-merge-check.py --base-version 0.5.17` (replace the base value with the one freshly read from `main`). The guard checks the increment, UI labels, selftests, and per-version changelog and handover. It requires no GitHub Actions. The complete rule is documented in [VERSIONING_POLICY.md](docs/VERSIONING_POLICY.md).

## Numeric product version and automatic browser cache

The canonical `VERSION` uses exactly `major.minor.patch` with numbers only, for example `0.5.13`. No words or suffixed states belong in the product version. The installer separately records the exact Git commit in `var/dev_source_commit` without changing the product version.

The same original one-liner calls `scripts/cache-bust.py` through the native or container installer. For each referenced CSS/JS file, the installer derives a fixed-length **numeric cache key from that file's actual SHA-256 content** and rewrites the static HTML URL. Unchanged assets keep the same key; changed assets get a different one even without a product version change. All HTML entry points are marked for revalidation at Nginx level, so browsers can discover new asset URLs. Preflight rejects stale or invalid keys.

**Historical releases are not renamed.** The original verified `0.5.3-alpha` archive is retained for legacy rollback only. `0.5.4` was planned but not published as a release. Versions `0.5.5` through `0.5.17` are preceding main-source checkpoints; `0.5.25` is the current development version. A new verified ZIP/SHA pair must be built separately before presenting it as an archived release.

## Independence and mandatory product name

From version `0.5.25`, the correct product name is **E.M.B.E.R. CoreUI**. The full designation is **STΛRLIɢHT: E.M.B.E.R. CoreUI by Starlight Unit Studios**. The standalone designation `CoreUI` is not used as a product name for this project.

E.M.B.E.R. CoreUI is independently developed. The project is not a fork of the separate admin template and UI component project at `coreui.io`, contains no dependency on it, and is neither affiliated with, sponsored by, nor endorsed by it. Technical compatibility names such as `coreui`, `COREUI_*`, `ember-coreui`, `/opt/ember-coreui`, repository components, and domain components remain purely machine identifiers.

## License and free community model

Starting with `0.4.2-alpha`, the project's own code is licensed under the **Starlight Unit Studios E.M.B.E.R. CoreUI Community Source License 1.0**. The legally authoritative German version is located in `LICENSE.de.md`; `LICENSE.md` is the English translation.

- private, community, and internal use remains free
- internal use by companies remains free with visible E.M.B.E.R. CoreUI branding
- no user limit and no license telemetry
- no paid licensing, white-label, donation, advertising, or support program for the official E.M.B.E.R. CoreUI project
- E.M.B.E.R. CoreUI may not be sold by third parties as a paid SaaS or managed service
- branding changes always require prior written case-by-case approval
- modified redistributions must retain source code, license, attribution notices, and branding

Further details are provided in `LICENSE_HISTORY.md`, `TRADEMARKS.md`, `COMMUNITY_POLICY.md`, `NOTICE.md`, and `COPYRIGHT.md`. The license is Source Available and is not OSI-certified. Third-party components retain their own terms.

## Existing file-output foundation and 0.5.13 local-model fix

Version 0.5.13 adds a dedicated file-tool path for local Ollama models, including a bounded missing-marker retry and verified ZIP assembly from named source fences. The existing foundation includes persistent generated file downloads and chat output cards, the new artifact database migration 011, the browser data-link correction, the original one-liner live-update flow, the Nginx permission repair, the scoped SSE selftest update, and the numeric cache/version repair. The 0.5.6 merge also adds compatibility for `gpt-oss:120b` cloud-generated files under the isolated `/mnt/data` output alias, with bounded recovery after failed Python file creation. Real HTML/ZIP downloads still need end-to-end live verification. The full worker-to-mobile download acceptance and new ZIP/SHA packaging remain pending. See [CHANGELOG.md](CHANGELOG.md), [the 0.5.9 entry](docs/changelogs-txt/CHANGELOG_0_5_9.txt) and [handover](docs/UEBERGABEPROTOKOLL_0_5_9.txt).

## 0.5.9: P05 agent runs, FIFO model admission and cooperative cancellation

The private SSE chat now creates a durable, account- and session-scoped agent run for each turn or explicit generation action (migration `013_console_agent_runs`). The run is claimed atomically, transitions through explicit states, and binds only to a real response belonging to the original turn. The authenticated chat history includes the run's safe state metadata. Reconnected requests do not silently repeat an uncertain provider call; such runs are marked interrupted for inspection.

Every Ollama HTTP call, including private streaming, synchronous follow-ups, warmup and reflection, now passes the same MariaDB named model lock. A bounded wait keeps the ordinary paths serial; no alternate file-lock lane can bypass the database lock. Migration 013 also adds a **durable FIFO admission queue** for Ollama calls, including nested requests that share one connection-bound model slot. Queued runs can be cancelled immediately, active private runs receive a cooperative stop request via the CSRF-protected chat action, and browser jobs can be reconciled to saved replies without replaying the model. A cloud provider may continue working after the HTTP transport ends; provider-side reconciliation, a persistent background scheduler, complete workflow resume, and provider capability adapters remain future steps. A live two-session concurrency and restart test is still required. No GitHub Actions were used.

## 0.5.8: Inline work events and file-output recovery

E.M.B.E.R. now displays responses as full-width chat content **without an assistant speech bubble**; only user messages are styled as bubbles. Work steps appear as a single, borderless, collapsible timeline **inside the same assistant message**, not as two separate rows/cards. Live SSE and saved history reuse the same visual component. The already authenticated `console_messages.php` response includes account- and answer-scoped work events, so a second per-answer API fetch is no longer necessary for the chat history. The protected standalone endpoint remains available for dedicated clients.

File generation is still serial and sandboxed. The Python worker now recovers bounded regular output files created under its isolated `/work` directory instead of the designated output directory, and it can recover a complete HTML document printed to stdout when no file exists. It rejects symlinks, scripts used as the execution source, unexpected file types, and oversized files before the existing durable, hash-verified artifact pipeline runs. An absent file despite exit code 0 triggers one bounded corrective model/tool pass with the prior code as context. A download is announced only when real bytes have been registered.

Offline Python output tests, frontend contracts, and mobile layout are added. A real `gpt-oss:120b` HTML/ZIP download on Android is **not yet claimed as verified**. Issue #29 remains open until that live check succeeds. The original install one-liner, account data, and numeric version policy are unchanged.

## P06: Real work activity (first operational slice)

In the private Console, the collapsible **ARBEITSSCHRITTE** view now presents actual, persisted server events for the current answer rather than invented thinking status lines. Real model starts/completions, Python job IDs, exit codes, recognized failure classes (such as `FileNotFoundError`), repair attempts, and registered downloads are displayed as separate steps. This includes cloud-provider use such as `gpt-oss:120b` through the existing Ollama adapter.

Events are stored in migration `012_console_work_events`, scoped to account, session, triggering turn, and the saved reply. New activity is delivered through SSE; a read-only authenticated API reloads it after reconnect or page refresh. Raw chain-of-thought, prompts, script sources, complete stdout/stderr and secrets are never sent to the browser. Technical diagnostics are deliberately restricted to safe metadata.

**Partial implementation only:** Full P05 run control, saved agent plans, multi-tool workflow replay and provider-dependent reasoning controls/display are still open in the roadmap. Issue #29 remains open until a real HTML/ZIP artifact downloads on Android Chrome. The prior one-liner and separate numeric product version rules are unchanged.

## Archived 0.5.3-alpha baseline

`0.5.3-alpha` aligns the production Gemma 4 + Ollama request path with the minimal known-good inference profile established during extended live CoreUI validation.

- Normal generation sends only `temperature=1.0`, `top_p=0.95`, and `top_k=64` inside Ollama `options` by default.
- `num_ctx`, `num_predict`, thread, repeat, seed, and stop controls are no longer injected into normal generation automatically.
- Synchronous and SSE generation share the same option-policy helper so the two paths cannot silently drift.
- Vision and truncated-continuation paths no longer force the old context or predict budgets.
- Legacy backend-specific tuning remains available only behind the explicit experimental `STU_EMBER_UNVERIFIED_TUNING_ENABLED` gate plus explicit configuration.
- There is no new database migration. Existing accounts, sessions, messages, revisions, uploads, RAG data, and local configuration remain unchanged.

The deterministic calculation fixes from `0.5.2-alpha` remain included:

- A prompt containing exactly one signed integer addition, subtraction, or multiplication can now be completed locally without waiting for Ollama or the Python worker.
- The calculation uses decimal string arithmetic instead of PHP integer conversion. Operands of up to 512 digits remain exact on both 32-bit and 64-bit hosts.
- The private SSE path still stores the result as a normal Ember message, keeps the original `reply_to_id`, and sends the regular completion event to the browser.
- The local path does not mark Ember as AFK and cannot leave a Python job or model request blocking the session.
- Exact calculation results do not start memory reflection or reputation analysis after storage, so no hidden follow-up model call can delay completion.
- Attachments, images, decimals, division, powers, chained expressions, multiple numeric constraints, and requests for an explanation remain in the normal model and tool path.
- A sentence-ending period or comma is accepted after the second operand. Decimal values, Unicode digits, hexadecimal literals, labeled ranges, and German or English explanation requests are rejected from the deterministic path, while unambiguous result wording remains local.
- A local calculation owns no Console-AFK marker and therefore cannot clear the state of a concurrent model or tool turn. The synchronous path also checks the deterministic result before requesting the global Ollama lock.
- The reply-pipeline self-test covers the reported `123456789 * 987654321` case, signed arithmetic, large values, sentence punctuation, ambiguous ranges, Unicode digits, hexadecimal literals, AFK ownership, the lock-free synchronous path, strict rejection boundaries, and SSE integration.

All persistence and conversation-flow corrections from `0.5.1-alpha` remain included:

- Ember and user messages now use a local Markdown renderer for headings, emphasis, lists, blockquotes, links, tables, inline code, and fenced code blocks.
- Raw HTML is always rendered as text. Clickable links are restricted to HTTP, HTTPS, and mail addresses. The renderer does not use `innerHTML`, `eval`, a remote CDN, or executable model output.
- Fenced code blocks show a language label, local syntax coloring, horizontal scrolling, and their own copy control.
- Every stored user message receives copy and true edit actions. Editing keeps the same message bubble and message ID, preserves all earlier context, supersedes the active conversation after that turn, and starts Ember again from the revised text.
- Every Ember answer receives copy, positive feedback, negative feedback, alternative generation, continuation, and answer-detail controls.
- Feedback is stored server-side and restricted to the authenticated user, exact private session, and exact Ember message. Migration 009 repairs legacy reaction-table encodings and the missing private `console` channel; CoreUI feedback uses database-safe ASCII tokens while remaining compatible with existing thumb values.
- Alternative answers keep the original response and use the same `reply_to_id`. Variants are grouped under one turn with previous/next navigation and are reconstructed from the database after reloading the page.
- Continuations are stored as separate Ember messages and remain linked to the original user turn.
- Text entered while Ember is answering can be submitted immediately. It appears as a waiting user turn and is sent automatically after the current response without interrupting it.
- Message revisions are stored account- and session-bound. Superseded downstream messages remain recoverable in the private account export but are excluded from the active model context.
- Migration `008_message_actions` stores short-lived, account-bound generation requests. Migration `009_message_editing` adds true in-place revisions and the `edit` generation mode. A fixed message-ID floor lets a reconnect recover only the answer created after that request, without repeating or losing the existing answer.
- Concurrent answer actions in one session are serialized. Permanent session deletion is blocked while an alternative or continuation is still active.
- Live SSE answers and loaded history now use the same Markdown and action rendering path.
- Tool-based answer actions retain their exact private browser-job ID, so reconnects resume the same job and worker failures do not leave the session blocked.
- The private account export includes feedback and generation history without exporting temporary request tokens.

All installer, privacy, upload, profile, navigation, private RAG-Lite, and Python-runtime changes from `0.4.5-alpha` remain included:

- The quick installer has been rebuilt. It no longer points to `0.3.2-alpha`, verifies the exact package root and version, and never unpacks an extra directory level into `/opt/ember-coreui`.
- Private console turns address Ember on the server. Attachment-only messages therefore no longer leave an artificial `@Ember` prefix or a single `@` in the model prompt.
- The authenticated turn ID now restores all ordered message attachments before prompt construction. A real end-to-end preflight verifies Windows-1252 TXT, UTF-8/16 decoding, Python, DOCX, and text-PDF contents from storage mapping through to the final model attachment block.
- Up to ten files per message remain supported and are stored in stable order with the exact user and session.
- Private RAG-Lite now uses the same shared extraction, normalization, chunking, query, and ranking engine as the operator RAG path, while keeping every source and chunk in user-scoped tables.
- Private knowledge uploads additionally accept common source and data formats such as Python, CSV, JSON, XML, YAML, PHP, JavaScript, HTML, CSS, and SQL.
- A dedicated Compose Python queue worker and sandbox self-test close the previously incomplete execution path. Generated code runs only in a disposable restricted container.
- No private Starlight Unit manuscript is bundled or imported. Migration `007_remove_private_studio_lore` removes only the two historic accidental global source IDs from already-updated E.M.B.E.R. CoreUI databases.
- The production address remains `https://core.starlight-unit.de`.

The session scopes, exact turn assignments, lossless legacy history, controlled session deletion, Thinking protection, profiles, user settings, and Admin Core from the preceding releases remain included. Game, homepage, Apache, KeyHelp, host PHP, Repack paths, and the existing STU database remain untouched.

## Real sessions instead of demo pointers

Before `0.3.1-alpha`, a session consisted only of a title and `since_id`. All private messages were still stored in a shared user stream. This caused incorrect excerpts, jumping order, and missing latest messages. The new path fixes this structurally:

- `stu_chat_messages.session_id` permanently separates conversations in the database.
- `reply_to_id` assigns each Ember response to the exact user turn.
- `console_messages.php` returns only messages from a verified session owned by the user.
- The client remembers the active session ID instead of an unstable list index.
- Asynchronous history, poll, SSE, and browser callbacks verify their session ID before changing the visible interface.
- Read markers no longer change `updated_at`; only genuinely new messages move a session to the top.
- The newest messages are loaded directly. Older pages can be loaded without calculation windows or `since_id` estimation.
- Sessions are no longer deleted automatically. Archiving is a reversible metadata operation; the message history remains preserved.
- A dedicated archive view allows archived sessions to be restored. Temporary loading errors no longer displace already confirmed messages from the view.
- Permanent deletion is available only in the archive view and requires two deliberate confirmations.
- The API endpoint accepts deletion only for a session owned by the user that is already archived and verifies a session-specific confirmation again on the server side.
- An SQL transaction removes reactions, browser frames, browser steps, completed browser jobs, messages, orphaned upload records, and finally the session row. An error rolls back the entire database operation.
- Sessions with a browser job in the `queued` or `running` state are not deleted. This prevents an active research task from being removed underneath the worker.
- Physical upload files and the final screenshot of removed browser jobs are deleted only after a successful database commit and only from `var/console_media`, `assets/chat_media`, or `uploads/ember_browse`. Files with additional message references remain preserved.
- Migration `003_console_sessions` collects legacy messages that cannot be separated unambiguously into a recovered history without data loss.
- The dedicated session self-test checks schema, history scope, turn assignment, client races, browser worker propagation, and the complete transactional deletion cascade including rollback.

## Secure thinking status and cleanup of old sessions

E.M.B.E.R. CoreUI strictly separates transient internal model analysis from the visible interface:

- During generation, SSE returns only fixed status stages such as `KONTEXT WIRD ABGEGLICHEN` and `ANTWORT WIRD FORMULIERT`.
- `message.content` and `message.thinking` are collected in full and processed only after the shared safety check.
- English and German prompt, analysis, draft, and self-correction patterns are intercepted before SSE, database, and history.
- New `thinking_content` values contain only the safe final status when Thinking is enabled; when Thinking is disabled, they remain empty.
- The fetch endpoint replaces old raw values before JSON output using fail-closed behavior.
- The browser has an additional whitelist and does not write arbitrary Thinking text into the DOM.
- The response budget is passed to Ollama per user. A detected token limit continues from the existing response instead of restarting the entire generation.
- Private Console messages may contain up to 12000 characters, or up to 20000 characters with attachments. Line breaks and code indentation are preserved.
- The SSE call passes only the authenticated turn ID. The message text is loaded from the E.M.B.E.R. CoreUI database and does not appear in the EventSource URL or in proxy access logs.
- The message column is expanded to `MEDIUMTEXT` by migration `002_coreui_management`.

After the update, existing raw blocks can be replaced once in the isolated E.M.B.E.R. CoreUI database:

```bash
cd /opt/ember-coreui
sudo ./scripts/stack.sh scrub-thinking
```

The command changes neither the Game database nor the Global Chat.

Only Ollama is shared as an already existing local model interface. E.M.B.E.R. CoreUI creates the separate model `ember-coreui:latest` for this purpose. An existing STU model such as `gemma4:26b` is neither replaced nor reconfigured.

## Profiles, AI settings, and private knowledge

`settings.html` connects local display options with a real server-side user and AI profile:

- custom visible username without changing the login email or internal user ID
- private user profile image as a server-side re-rendered PNG file
- custom visible CoreAI name and separate CoreAI profile image per account
- custom system prompt for style and working method within the unchanged E.M.B.E.R. CoreUI safety and identity rules
- Memory on or off and configurable Memory limit
- model Thinking on or off per account; raw thought processes remain private in both settings
- response budget from 256 to 16384 Ollama tokens
- temperature from 0.1 to 1.5
- optional local Ollama model tag that is actually used in the chat path
- create and delete custom visible Memory facts
- upload private RAG-Lite sources directly, use them chunk by chunk, and delete them completely
- account, permission level, health matrix, model, and version
- local reference operation with Gemma 4 and Ollama

Profile images are not served as freely accessible static files. The private media endpoint verifies the session and always serves only the current avatar of the signed-in account. JPEG and PNG uploads are decoded, center-cropped to a square, limited to a maximum of 512 pixels, and saved as a new PNG file without third-party metadata.

Private RAG-Lite accepts `.txt`, `.md`, `.pdf`, `.docx`, `.py`, `.csv`, `.json`, `.xml`, `.yml`, `.yaml`, `.ini`, `.php`, `.js`, `.html`, `.css`, and `.sql`. PDFs require a real text layer for this settings import; scanned PDFs without readable text are explicitly rejected. Extraction, UTF-8 normalization, overlapping chunking, query-term generation, and relevance ranking use the shared RAG-Lite engine. Sources and chunks remain strictly bound to the authenticated user, and both model prompt paths hard-limit private knowledge to the private console channel. By default, limits are 20 MiB per file, 40 sources, and a total of 5,000,000 extracted characters per account. Account-specific MariaDB locks prevent concurrent uploads from jointly bypassing these quotas. Document contents are marked as untrusted data and never as system instructions.

The prepared external provider adapter remains disabled by default. API keys are never stored in the browser. Only a later server-side tested implementation can enable it; there is no compatibility guarantee for arbitrary cloud providers.

## Admin Core

`admin/index.html` is accessible only to permission levels 0 and 1 and operates exclusively against the E.M.B.E.R. CoreUI database. It includes:

- overview for accounts, sessions, messages, Memories, Lore, browse jobs, and logs
- create new user accounts with display name, login email, initial password, and permitted role
- user permissions, time-limited suspensions, and suspension reasons
- Memory CRUD for global, user, and character visibility
- Lore sources and chunk search
- registration, maintenance mode, and maintenance message
- central switches for fast web search, browser research, and isolated Python
- prepared external provider metadata without browser keys
- error log and immutable admin audit

Permission changes and active suspensions are read from the database again on every authenticated request. They therefore also apply to sessions that are already signed in.

The display options are stored under `emberCoreUI.preferences.v1` in the local browser. They change neither the database schema nor accounts, sessions, memories, or model configuration.

## Safe runtime update

The update workflow preserves `var/compose.env`, databases, uploads, and caches. At the same time, the non-secret Nginx and PHP-FPM runtime files are regenerated from the current package templates before every relevant stack start:

```bash
sudo ./scripts/stack.sh refresh-runtime
sudo ./scripts/stack.sh restart web
```

With `sudo ./scripts/stack.sh up -d --build --force-recreate php web browse pyworker`, the runtime refresh and pending additive database migrations are performed automatically. The runtime values for bind address and ports continue to come from the locally protected `var/compose.env`; credentials are neither written into templates nor output.

## Isolation boundaries

| Area | E.M.B.E.R. CoreUI parallel operation |
|---|---|
| Project files | Exclusively under `/opt/ember-coreui` |
| Web server | Dedicated Nginx container on `127.0.0.1:9080` |
| PHP | Dedicated PHP-FPM container on `127.0.0.1:19073` |
| Database | Dedicated MariaDB container on `127.0.0.1:13306` |
| Web search | Dedicated SearXNG container on `127.0.0.1:18889` |
| Browser research | Dedicated Playwright container |
| Python queue | Dedicated trusted worker; generated code runs in a disposable restricted sandbox container |
| Live browser images | Private E.M.B.E.R. CoreUI database, authenticated job SSE, automatic cleanup |
| Model | Dedicated Ollama model `ember-coreui:latest` |
| Login sessions | Dedicated cookie name `EMBERCOREUISESSID` |
| Chat sessions | Dedicated `session_id`, exact history, and turn assignment in the E.M.B.E.R. CoreUI database |
| Profiles | Account-specific names and private media metadata in the E.M.B.E.R. CoreUI database |
| Profile images | Authenticated delivery from `var/profile_media`, no direct web path |
| Private RAG-Lite | User-bound sources and chunks in migration 004, originals under `var/knowledge_uploads` |
| Locks | Dedicated namespace `ember_coreui:*` |
| Runtime data | Local directories `var/`, `logs/`, `uploads/`, and `assets/chat_media/` |
| PDF page images | Dedicated short-lived runtime path `var/pdf_pages` |

On Linux, the containers use the host network so that they can reach Ollama at `127.0.0.1:11434` without rebinding the existing Ollama service to `0.0.0.0`. All E.M.B.E.R. CoreUI services receive dedicated loopback ports that are checked before installation.

Shared Ollama usage still means shared CPU, RAM, and GPU resources. This is not data or configuration coupling, but simultaneous generations can affect the response time of both interfaces.

## Let Game and E.M.B.E.R. CoreUI respond simultaneously

Game and E.M.B.E.R. CoreUI already do not block each other at the application level:

- The Game uses its existing STU database and the lock `ember_global_ollama`.
- E.M.B.E.R. CoreUI uses its own MariaDB instance and, by default, the lock `ember_coreui:global_ollama`.
- Chat histories, sessions, uploads, Memories, and RAG data remain separate.
- Only the local Ollama service at `127.0.0.1:11434` is shared.

For two different model names to be loaded simultaneously, Ollama needs enough RAM or VRAM. First run the read-only report:

```bash
cd /opt/ember-coreui
sudo ./scripts/ollama-parallel-report.sh
```

If both models fit together in the available RAM or VRAM, Ollama can be limited to two loaded models using a systemd drop-in:

```bash
sudo install -d -m 0755 /etc/systemd/system/ollama.service.d
printf '%s\n' \
  '[Service]' \
  'Environment="OLLAMA_MAX_LOADED_MODELS=2"' \
  'Environment="OLLAMA_NUM_PARALLEL=1"' \
  | sudo tee /etc/systemd/system/ollama.service.d/20-stu-parallel.conf >/dev/null
sudo systemctl daemon-reload
sudo systemctl restart ollama
ollama ps
```

Without a custom limit, Ollama already allows at least three loaded models by default according to the official documentation. The drop-in is therefore needed only if the report shows a different existing limit or if the upper limit is intentionally fixed at exactly two. `OLLAMA_NUM_PARALLEL=1` applies per loaded model and avoids additional context copies. If Game and E.M.B.E.R. CoreUI are later intended to use the same model name, `OLLAMA_NUM_PARALLEL=2` may be necessary. However, this multiplies the memory requirement of the context and should be set only after a RAM/VRAM test. If there is not enough memory, Ollama queues requests instead of forcing true concurrency.

The E.M.B.E.R. CoreUI installer deliberately does not write this system-wide Ollama configuration itself. The Ollama service is the only shared resource and remains under the control of the server operator.

Official reference: <https://docs.ollama.com/faq#how-does-ollama-handle-concurrent-requests>

The internal STU Console was already removed from the Game with Alpha `v1.1.1.98` on 20 August 2026. E.M.B.E.R. CoreUI is its independent successor and reference interface; it does not replace the Global Chat. This standalone package deletes no Repack files and changes no Game routes.

## Supported target systems

- Debian 12 or newer
- Ubuntu Server 24.04 or newer
- Root or sudo access via SSH
- Docker or permission to install Docker automatically
- Local Ollama or permission to install Ollama automatically
- Enough RAM and storage for the selected Gemma 4 tag
- Outbound Internet access during the first image build

Ollama's Linux guide: <https://docs.ollama.com/linux>

Gemma 4 model page: <https://ollama.com/library/gemma4>

SearXNG Docker documentation: <https://docs.searxng.org/admin/installation-docker.html>

## Safe parallel installation via SSH

The **archived pinned release**, rather than the current `main` development tree, can still be installed manually through its verified ZIP/SHA-256 pair:

```bash
cd /home/users/game/tmp
BASE='https://raw.githubusercontent.com/Starlight-Unit-Studio/coreui/main/releases/v0.5.3-alpha'

curl -fL "$BASE/EMBER_COREUI_0_5_3_ALPHA.zip" -o EMBER_COREUI_0_5_3_ALPHA.zip
curl -fL "$BASE/EMBER_COREUI_0_5_3_ALPHA.zip.sha256" -o EMBER_COREUI_0_5_3_ALPHA.zip.sha256
sha256sum -c EMBER_COREUI_0_5_3_ALPHA.zip.sha256
unzip -q EMBER_COREUI_0_5_3_ALPHA.zip

sudo mv EMBER_COREUI_0_5_3_ALPHA /opt/ember-coreui
cd /opt/ember-coreui
sudo chmod 0750 setup.sh scripts/*.sh
sudo ./scripts/install.sh
```

The installer asks for the administrator email address, a password of at least 12 characters, and an optional display name through `COREUI_ADMIN_NAME`.

## Manual update using the archived 0.5.3-alpha ZIP

The existing database, accounts, sessions, uploads, and protected credentials are preserved. The package is copied only over static project files and templates.

```bash
cd /home/users/game/tmp
sha256sum -c EMBER_COREUI_0_5_3_ALPHA.zip.sha256
unzip -q -o EMBER_COREUI_0_5_3_ALPHA.zip

sudo apt-get update
sudo apt-get install -y rsync
sudo rsync -a \
  --exclude='api/config.local.php' \
  --exclude='var/' \
  --exclude='logs/' \
  --exclude='uploads/' \
  --exclude='assets/chat_media/' \
  --exclude='assets/profile_photos/' \
  EMBER_COREUI_0_5_3_ALPHA/ /opt/ember-coreui/

cd /opt/ember-coreui
sudo ./scripts/stack.sh up -d --build --force-recreate php web browse pyworker
sudo ./scripts/stack.sh scrub-thinking
sudo ./scripts/preflight.sh
```

`stack.sh up` refreshes the non-secret runtime configuration and applies migrations `007_remove_private_studio_lore`, `008_message_actions`, and `009_message_editing`. Migration 007 removes only the two historical accidental global source IDs. Migration 008 adds account-bound feedback and generation-request metadata. Migration 009 repairs legacy reaction storage, adds message revisions, and enables the edit generation mode. Existing messages are not rewritten during installation. User knowledge, memories, accounts, uploads, and other operator sources remain intact. No private manuscript binary is present in the archive or imported by either installer.

The first Python sandbox image build can take several minutes. Regenerating the Ember model or importing bundled Studio lore is not required. The existing `api/config.local.php` and the public address `https://coreui.starlight-unit.de` remain preserved.

## Testing without affecting existing domains

By default, E.M.B.E.R. CoreUI listens only on `127.0.0.1:9080`. An SSH tunnel is opened from the local computer:

```bash
ssh -L 9080:127.0.0.1:9080 DEIN_SSH_USER@DEIN_SERVER
```

Then open in the local browser:

```text
http://127.0.0.1:9080/
```

This requires no new host Nginx virtual host, no port forwarding, and no change to an existing homepage.

## Public address

The current production address is:

```text
https://coreui.starlight-unit.de
```

The reverse proxy continues to forward this domain internally to `http://127.0.0.1:9080`. In the preserved file `/opt/ember-coreui/api/config.local.php`, exactly one definition must be active for this:

```php
define('STU_PUBLIC_BASE_URL', 'https://coreui.starlight-unit.de');
```

The previous address `webui.starlight-unit.de` is no longer a current E.M.B.E.R. CoreUI base address. Because `config.local.php` is deliberately excluded from updates, the release does not automatically change this local operator configuration.

## What the standard installer does not do

- It does not remove any file from `/etc/nginx/sites-enabled/`.
- It does not write any host Nginx or Apache configuration.
- It does not write any global PHP configuration and does not restart an existing PHP-FPM.
- It does not create tables in the existing STU database.
- It does not change any existing MariaDB user.
- It does not install a systemd service for the browse worker.
- It does not write to `/home/users/game`, `/var/www`, or a Repack path.
- It does not replace any existing STU Ollama model.

If Docker is missing and `COREUI_INSTALL_DOCKER=1` is active, the installer installs and starts Docker. If Ollama is missing and `COREUI_INSTALL_OLLAMA=1` is active, it installs Ollama. Existing reachable services are not reconfigured.

## Optionaler Host-Swap fuer E.M.B.E.R. und CoreUI

CoreUI prueft nach erfolgreichem Docker- oder Native-Installationslauf den Swap-Zustand des **Linux-Hosts**, nicht eines Containers. Ist bereits eine aktive Swap-Datei, Swap-Partition oder zram vorhanden, wird diese unveraendert uebernommen. Dasselbe gilt fuer konfigurierte, aber inaktive Swap-Eintraege in `/etc/fstab` sowie fuer eine bereits vorhandene `/swapfile`.

Nur wenn keine Swap-Konfiguration erkannt wird, erstellt der Installer standardmaessig eine **8-GiB-Notfall-Swapdatei** `/swapfile` auf ext4 oder XFS (mindestens 9 GiB Platz auf dem Dateisystem erforderlich). Die Datei erhaelt Modus `0600`, wird mit `mkswap` und `swapon` aktiviert und in `/etc/fstab` fuer Neustarts eingetragen. Nur bei einer solchen Neuanlage wird `vm.swappiness=10` unter `/etc/sysctl.d/99-stu-coreui-swap.conf` eingerichtet, sofern kein anderer Betreiberwert unter `/etc/sysctl.conf` oder `/etc/sysctl.d/*.conf` hinterlegt ist. Ein bereits eingerichteter Wert bleibt erhalten.

Die Funktion ist idempotent und mit einem Host-Lock gegen zwei gleichzeitige CoreUI-Installerlaeufe abgesichert. Sie ersetzt **niemals** eine vorhandene Swap-Konfiguration des Game-/Repack-Installers. Nicht unterstuetzte Dateisysteme, zu wenig Platz oder Fehler bei der Anlage werden protokolliert, ohne die erfolgreiche CoreUI-Installation rueckgaengig zu machen. Der Swap ist Notfallreserve und kein Ersatz fuer ausreichenden RAM oder eine Begrenzung von LLM-Arbeitslasten.

Fuer die erneute Swap-Pruefung ohne Neuinstallation:

```bash
sudo bash scripts/swap.sh status
sudo bash scripts/swap.sh ensure
```

Optionale Umgebungsvariablen fuer beide Installer und `scripts/swap.sh ensure`:

| Variable | Standard | Bedeutung |
|---|---:|---|
| `COREUI_SWAP_AUTOCREATE` | `1` | Auf `0` setzen, um jede automatische Swap-Anlage zu deaktivieren |
| `COREUI_SWAP_SIZE_GIB` | `8` | Neue Swapdatei, 1 bis 64 GiB |
| `COREUI_SWAP_SWAPPINESS` | `10` | Nur bei neuer Anlage, Bereich 0 bis 200 |

Bei der Verwendung von `sudo` koennen abweichende Einstellungen mit `sudo --preserve-env=COREUI_SWAP_AUTOCREATE,COREUI_SWAP_SIZE_GIB,COREUI_SWAP_SWAPPINESS ...` uebergeben werden. `scripts/swap.sh status` ist nur lesend, `ensure` benoetigt Root-Rechte.

**Release-Hinweis:** Der unveraenderte One-Liner unter „Quick installation“ nutzt standardmaessig den neuesten GitHub-`main`-Stand. Fuer den laufenden Live-Testbetrieb muss nach dem Merge weder ein Release-ZIP neu gebaut noch ein zweiter Updater aufgerufen werden.

## Live-Entwicklerstand mit dem bestehenden One-Liner

Genau denselben One-Liner wie bei der Erstinstallation erneut ausfuehren. `setup.sh` laedt den neuesten Commit aus `main`, fuehrt den regulaeren Installer und die SQL-Migrationen aus und prueft die aktualisierte Installation. Der konfigurierte isolierte Betrieb bleibt bestehen; Benutzer, Sitzungen, Datenbank, Uploads und Geheimnisse bleiben erhalten. GitHub Actions werden nicht benoetigt.

Der tatsaechlich installierte Commit wird am Ende ausgegeben und in `/opt/ember-coreui/var/dev_source_commit` gespeichert. Die aktuelle Produktversion in `VERSION` ist rein numerisch (`0.5.13`), unabhaengig von Commit und Cache-Schluessel. Fuer den exakten live installierten Quellstand ist die gesondert gespeicherte Commit-SHA massgeblich.

Wer stattdessen **explizit das alte gepruefte Release** installieren moechte, kann den Launcher unter `sudo` mit `COREUI_SETUP_SOURCE=release` ausfuehren. Nur dieser Modus benutzt das gepinnte ZIP mit SHA-256-Verifikation. Das bisherige `scripts/update-from-main.sh` ist nicht mehr als separater Update-Schritt erforderlich.

## Installation process

1. Validate the operating system, project path, and inputs.
2. Check the dedicated ports and Compose project name for collisions.
3. Create dedicated runtime directories and credentials.
4. Validate and build the PHP, Python worker, and optional browse images.
5. Detect or download a Gemma 4 base model.
6. Create the separate model `ember-coreui:latest`.
7. Start the dedicated MariaDB and idempotently apply all SQL migrations.
8. Create Ember, the administrator, character, and `stu_kv` records on first installation.
9. Remove only the exact historical accidental manuscript sources and files. No private Studio manuscript is bundled or imported.
10. Start SearXNG, PHP-FPM, Nginx, browse worker, and Python worker.
11. Run health, schema, upload-pipeline, private-RAG, session, Thinking, and Python-sandbox checks.

## Non-interactive installation

The password should not appear as plain text in the shell history:

```bash
cd /opt/ember-coreui
export COREUI_ADMIN_EMAIL='admin@example.org'
read -r -s -p 'E.M.B.E.R. CoreUI Admin-Passwort: ' COREUI_ADMIN_PASSWORD
printf '\n'
export COREUI_ADMIN_PASSWORD
export COREUI_ADMIN_NAME='Operator'
sudo --preserve-env=COREUI_ADMIN_EMAIL,COREUI_ADMIN_PASSWORD,COREUI_ADMIN_NAME ./scripts/install.sh
unset COREUI_ADMIN_PASSWORD
```

## Important options

| Variable | Default | Meaning |
|---|---:|---|
| `COREUI_HTTP_PORT` | `9080` | Dedicated web port |
| `COREUI_PHP_PORT` | `19073` | Dedicated PHP-FPM port |
| `COREUI_DB_PORT` | `13306` | Dedicated MariaDB port |
| `COREUI_SEARXNG_PORT` | `18889` | Dedicated SearXNG port |
| `COREUI_BIND_ADDRESS` | `127.0.0.1` | Secure web bind address |
| `COREUI_BASE_MODEL` | existing `gemma4:*`, otherwise `gemma4:e4b` | Ollama base model |
| `COREUI_MODEL_NAME` | `ember-coreui:latest` | Separate E.M.B.E.R. CoreUI model name |
| `COREUI_LOCK_NAMESPACE` | `ember_coreui` | Dedicated MariaDB lock namespace |
| `COREUI_VIDEO_FRAMES` | `6` | Evenly distributed frames per video, maximum 32 |
| `COREUI_VIDEO_FRAME_PX` | `512` | Maximum width and height of a vision frame |
| `COREUI_PDF_MAX_PAGES` | `4` | Representative pages per scanned PDF, maximum 12 |
| `COREUI_PDF_PAGE_PX` | `1024` | Maximum width and height of a rasterized PDF page |
| `COREUI_INSTALL_DOCKER` | `1` | Installs Docker if required |
| `COREUI_INSTALL_OLLAMA` | `1` | Installs Ollama if required |
| `COREUI_INSTALL_SEARXNG` | `1` | Starts the dedicated SearXNG instance |
| `COREUI_INSTALL_BROWSE` | `1` | Starts the dedicated browse worker |
| `COREUI_SKIP_BOOTSTRAP` | `0` | Skips Admin and Ember bootstrap |
| `COREUI_FORCE_CONFIG` | `0` | Regenerates `api/config.local.php` |

Any port collision causes the process to abort before the E.M.B.E.R. CoreUI containers are started. Existing third-party containers with the Compose project name `ember-coreui` are likewise not adopted.

## PDF detection

The document path operates in two stages:

1. `pdftotext` reads PDFs with a real text layer directly and limits the prompt excerpt like other text attachments.
2. If the text layer remains empty, `pdftoppm` renders up to four evenly distributed pages as JPEG. These pages are sent to Gemma's vision path in the correct order.

With a long scanned PDF, Ember therefore sees a sample, not automatically every page. The response must openly state this scope. For a different limit, the constants `STU_EMBER_PDF_MAX_PAGES` and `STU_EMBER_PDF_PAGE_PX` can be set in `api/config.local.php`. More pages and higher resolution significantly increase vision runtime, context, and memory requirements.

If Poppler is missing, the runtime path is not writable, or no page can be rendered, model generation for the document content is stopped. This prevents an invented PDF summary.

## Browser research and Ollama runtimes

The browse worker clearly separates browser navigation from model decisions. SearXNG search, `goto`, clicks, and page text capture run through Playwright. Only afterwards does Ollama decide on the next step. An Ollama read timeout is therefore not a Playwright error.

In the chat, the current browser viewport replaces the previous text log. The first view shows the locally received SearXNG results as a safe, escaped results page. After navigation, click, input, and scrolling, three real JPEG frames follow by default at intervals of 240 milliseconds. This captures dynamically loaded content and visible page changes without inventing an artificial video stream. The Ghost Cursor retains its last position in every frame. A separate click signal is set only together with the real bounding box of a clicked Playwright target. The images are not served as public files, but are stored job-bound in the dedicated database, read only through the authenticated SSE endpoint, and removed by default after 24 hours.

The following time limits apply by default:

- action decision by Ollama: 420 seconds
- final Ember report: 420 seconds
- live window without a new step event: 480 seconds
- entire browse SSE channel: 900 seconds

On particularly slow CPU systems, the two Ollama time limits can be adjusted in `api/config.local.php`:

```php
define('STU_EMBER_BROWSE_AGENT_TIMEOUT', 600);
define('STU_EMBER_BROWSE_REPORT_TIMEOUT', 600);
define('STU_EMBER_BROWSE_AGENT_RETRIES', 0);
define('STU_EMBER_BROWSE_FRAME_QUALITY', 45);
define('STU_EMBER_BROWSE_FRAME_BURST', 3);
define('STU_EMBER_BROWSE_FRAME_INTERVAL_MS', 240);
define('STU_EMBER_BROWSE_FRAME_RETENTION_HOURS', 24);
```

`STU_EMBER_BROWSE_AGENT_RETRIES` remains `0` by default because a read-side timed-out request may still be processing on the Ollama server. Optional retries apply only to an immediate connection failure, not to a read timeout. Per action, 1 to 5 follow-up frames are allowed, with the interval limited to 120 to 800 milliseconds. JPEG quality is limited to values from 25 to 75, and retention to 1 to 168 hours. After a change, only the worker needs to be restarted:

```bash
cd /opt/ember-coreui
sudo ./scripts/stack.sh restart browse
```

## Use an existing SearXNG instance

```bash
sudo env \
  COREUI_INSTALL_SEARXNG=0 \
  COREUI_SEARXNG_URL='http://127.0.0.1:8888/search' \
  ./scripts/install.sh
```

This deliberately creates a dependency on that existing search instance. For maximum isolation, the dedicated SearXNG instance remains recommended.

Without web search and browse worker:

```bash
sudo env \
  COREUI_INSTALL_SEARXNG=0 \
  COREUI_INSTALL_BROWSE=0 \
  COREUI_SEARXNG_URL='' \
  ./scripts/install.sh
```

## Diagnostics and operation

```bash
cd /opt/ember-coreui
sudo ./scripts/preflight.sh
sudo ./scripts/stack.sh ps
sudo ./scripts/stack.sh logs --tail=100
curl -fsS http://127.0.0.1:9080/api/health.php | python3 -m json.tool
sudo ./scripts/stack.sh exec -T -u 33:33 php php scripts/video-selftest.php
sudo ./scripts/stack.sh exec -T -u 33:33 php php scripts/pdf-selftest.php
sudo ./scripts/stack.sh exec -T -u 33:33 php php scripts/thinking-sanitize-selftest.php
sudo ./scripts/stack.sh exec -T -u 33:33 php php scripts/reply-pipeline-selftest.php
sudo ./scripts/stack.sh exec -T -u 33:33 php php scripts/session-selftest.php
sudo ./scripts/stack.sh exec -T -u 33:33 php php scripts/profile-knowledge-selftest.php
sudo ./scripts/stack.sh exec -T -u 33:33 php php scripts/attachment-pipeline-selftest.php
sudo ./scripts/stack.sh exec -T -u 33:33 php php scripts/python-worker-selftest.php
sudo ./scripts/stack.sh exec -T -u 33:33 php php scripts/logo-alpha-selftest.php
sudo ./scripts/stack.sh exec -T -u 33:33 php php scripts/frontend-regression-selftest.php
sudo ./scripts/stack.sh exec -T -u 33:33 php php scripts/message-actions-selftest.php
sudo ./scripts/ollama-parallel-report.sh
sudo grep -E 'ember_(video|pdf)_|ember_vision_payload' logs/stu_error.log | tail -n 30
```

Watch only the browse worker:

```bash
sudo ./scripts/stack.sh logs -f browse
```

Restart the stack:

```bash
sudo ./scripts/stack.sh restart
```

Stop the stack without deleting data:

```bash
sudo ./scripts/stack.sh stop
```

## Backup

```bash
cd /opt/ember-coreui
sudo install -d -m 0700 /var/backups/ember-coreui
sudo ./scripts/stack.sh exec -T database sh -ec \
  'mariadb-dump --protocol=socket -uroot -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE"' \
  | sudo tee /var/backups/ember-coreui/database.sql >/dev/null

sudo tar -C /opt -czf /var/backups/ember-coreui/files.tgz \
  ember-coreui/api/config.local.php \
  ember-coreui/var/compose.env \
  ember-coreui/assets/chat_media \
  ember-coreui/uploads \
  ember-coreui/var/console_media \
  ember-coreui/var/profile_media \
  ember-coreui/var/knowledge_uploads
```

## Uninstallation

These commands apply to the standard isolated Compose installation. The native installer changes shared host services and therefore requires an operator-controlled rollback instead of this removal procedure.

On an otherwise clean supported server, the standard installer installs Docker and Docker Compose when they are missing, installs Ollama through its official Linux installer when required, pulls `gemma4:e4b` when no selected Gemma 4 base model is available, and creates the dedicated CoreUI model. Nginx, PHP-FPM, MariaDB, SearXNG, and the workers run as containers; the standard installer does not install or reconfigure a host web server. Docker, Ollama, and the base model remain after uninstallation even when CoreUI originally installed or downloaded them, because they may have become shared dependencies. Only the model recorded by CoreUI in `var/model.owner` is treated as installer-owned and removed by the complete procedure below.

To remove the running E.M.B.E.R. CoreUI services while retaining all accounts, database data, uploads, configuration, images, and the dedicated Ollama model for a later reinstall:

```bash
cd /opt/ember-coreui
sudo ./scripts/stack.sh down --remove-orphans
```

The following complete removal is irreversible. Create the backup described above first if any CoreUI data may still be needed. It removes the Compose services, locally built CoreUI images, the installer-owned Ollama model, and the entire `/opt/ember-coreui` directory including its database and uploads:

```bash
(
  set -Eeuo pipefail
  cd /opt/ember-coreui
  COREUI_OWNED_MODEL="$(sudo sed -n '1p' var/model.owner 2>/dev/null || true)"
  sudo ./scripts/stack.sh down --remove-orphans --volumes --rmi local
  if [[ "$COREUI_OWNED_MODEL" =~ ^[A-Za-z0-9][A-Za-z0-9._:/-]*$ ]]; then
    sudo ollama rm "$COREUI_OWNED_MODEL" 2>/dev/null || true
  fi
  sudo docker image rm ember-py:1 2>/dev/null || true
  cd /opt
  sudo rm -rf -- /opt/ember-coreui
)
```

The base Gemma model, Docker, Ollama, host web servers, other applications, and manually created reverse-proxy configuration remain untouched. A reverse-proxy entry that points to E.M.B.E.R. CoreUI can be removed separately by its operator after the service has been uninstalled.

### Additional cleanup for a CoreUI-only server

Use this final cleanup only after the complete removal above and only when the server was prepared exclusively for E.M.B.E.R. CoreUI. It removes all remaining Ollama models and data, Ollama itself, Docker, Docker Compose, and all Docker image and volume data. It must not be used on a server that runs another container or uses Ollama for another application.

The block refuses to continue while any Docker container still exists, displays the remaining Ollama models, and requires the exact confirmation `REMOVE COREUI HOST`:

```bash
(
  set -Eeuo pipefail

  COREUI_REMAINING_CONTAINERS="$(sudo docker ps -aq)" || {
    printf '[ABORT] Docker could not be queried safely.\n' >&2
    exit 1
  }
  if [[ -n "$COREUI_REMAINING_CONTAINERS" ]]; then
    printf '[ABORT] Other Docker containers still exist:\n' >&2
    sudo docker ps -a --format 'table {{.Names}}\t{{.Image}}\t{{.Status}}' >&2
    exit 1
  fi

  OLLAMA_BIN="$(command -v ollama || true)"
  if [[ -n "$OLLAMA_BIN" ]]; then
    printf 'Remaining Ollama models that will be deleted:\n'
    ollama list || {
      printf '[ABORT] Ollama models could not be listed safely.\n' >&2
      exit 1
    }
  else
    printf 'Ollama is not installed; only Docker cleanup remains.\n'
  fi
  read -r -p 'Type REMOVE COREUI HOST to remove Docker, Ollama, and all of their data: ' COREUI_HOST_CONFIRM </dev/tty
  [[ "$COREUI_HOST_CONFIRM" == 'REMOVE COREUI HOST' ]] || {
    printf 'Cleanup cancelled.\n'
    exit 1
  }

  sudo systemctl disable --now ollama.service 2>/dev/null || true
  sudo rm -f -- /etc/systemd/system/ollama.service
  sudo rm -rf -- /etc/systemd/system/ollama.service.d
  case "$OLLAMA_BIN" in
    /usr/local/bin/ollama|/usr/bin/ollama|/bin/ollama)
      sudo rm -f -- "$OLLAMA_BIN"
      ;;
    '') ;;
    *) printf '[WARN] Unknown Ollama binary path remains: %s\n' "$OLLAMA_BIN" >&2 ;;
  esac
  sudo rm -rf -- /usr/local/lib/ollama /usr/lib/ollama /lib/ollama /usr/share/ollama
  sudo userdel ollama 2>/dev/null || true
  sudo groupdel ollama 2>/dev/null || true
  sudo systemctl daemon-reload

  COREUI_DOCKER_PACKAGES=()
  for package in docker.io docker-compose docker-compose-v2 docker-compose-plugin; do
    if dpkg-query -W -f='${db:Status-Abbrev}' "$package" 2>/dev/null | grep -q '^ii '; then
      COREUI_DOCKER_PACKAGES+=("$package")
    fi
  done
  if (( ${#COREUI_DOCKER_PACKAGES[@]} > 0 )); then
    sudo apt-get purge -y "${COREUI_DOCKER_PACKAGES[@]}"
  fi
  sudo apt-get autoremove -y
  sudo rm -rf -- /var/lib/docker /var/lib/containerd
)
```

The system packages used for normal administration, such as `curl`, certificate support, Python, and archive tools, are deliberately retained. Current upstream removal references: [Ollama Linux uninstall](https://docs.ollama.com/linux#uninstall) and [Docker Engine uninstall](https://docs.docker.com/engine/install/debian/#uninstall-docker-engine).

## Native installer for an empty standalone server

The previous host installer remains included as `scripts/install-native.sh`. It installs Nginx, PHP-FPM, and MariaDB directly on the host and is therefore not intended for the parallel STU production server.

It can only be started with explicit confirmation:

```bash
sudo env COREUI_NATIVE_CONFIRM=DEDICATED_SERVER ./scripts/install-native.sh
```

Even then, the native installer does not remove an existing Nginx default site unless `COREUI_DISABLE_NGINX_DEFAULT=1` has been deliberately set.

## Project structure

```text
ember-coreui/
  admin/               isolated Admin Core for levels 0 and 1
  api/                 local REST, SSE, upload, and CRUD endpoints
  assets/              local fonts and chat media
  config/              runtime, Modelfile, and native templates
  css/                 independent Holo HUD design
  database/migrations/ complete E.M.B.E.R. CoreUI schema
  docker/              dedicated PHP, Playwright, and Python-worker images
  docs/                detailed public release changelogs
  images/              local UI images
  js/                  streaming, session, deduplication, and tool logic
  scripts/             parallel installer, native installer, and preflight
  tools/               Lore, browse, and optional Python workers
  uploads/             local browser screenshots
  var/                 database, runtime configuration, and caches
  compose.yaml         isolated standard stack
```

## Separate versioning

E.M.B.E.R. CoreUI versions are not equivalent to Repack versions. The mobile fix from Repack patch `v1.1.1.96` is included, but does not determine the E.M.B.E.R. CoreUI release sequence.

## Copyright and legal finalization

Copyright: `© 2026 Patrick Schildgen, Starlight Unit Studios.`

The studio logo is used byte-for-byte from the approved original PNG supplied by Starlight Unit Studios. E.M.B.E.R. CoreUI does not redraw its contours or add a dark outline. The approved SHA-256 fingerprint is enforced by the release self-test. The licenses of the locally bundled fonts are documented separately under `assets/fonts/LICENSES.md`. The new license does not replace the still-pending legal review of a later final release.

A complete German legal notice and a final privacy policy are deliberately not yet included as finished legal pages. Before the public final release, the actual operator information, serviceable address, representation details, contact channels, hosting data-processing arrangement, and desired privacy texts must be established. The package does not invent such information.

## Alpha status

`0.5.3-alpha` completes exact single-expression integer calculations locally so a model or Python timeout cannot leave the private Core Channel waiting without an answer. Persistent feedback, alternative-answer navigation, true in-place editing, queued follow-up text, safe local Markdown, code blocks, continuations, response details, profile images, TXT decoding, installer verification, attachment reconstruction, private RAG-Lite retrieval, and isolated Python execution remain included. Speech input, native model audio transport, and spoken TTS output remain scheduled for the separate audio stage. The free E.M.B.E.R. CoreUI Community Source License introduced in `0.4.2-alpha` remains unchanged. There is no user counting, phone-home, license key, paywall, or paid E.M.B.E.R. CoreUI offering.
