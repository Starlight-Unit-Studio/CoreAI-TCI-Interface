# CoreAI: TCI Interface: Umsetzungsroadmap

Produktname ab 0.5.57: CoreAI: TCI Interface (Quantum-Softwarefamilie). Die folgenden historischen Meilensteine verwenden teils noch den frueheren Projektnamen.

Stand: 28.10.2026. Planungsbasis: [`383bd28`](https://github.com/Starlight-Unit-Studio/CoreAI-TCI-Interface/commit/383bd289f295ede48fef322458baaee3879a0d9a) (Version 0.5.71). Erste Fassung: 20.10.2026 auf Basis `b4a5413`.

**Aktueller Stand 0.5.71: Downloads (M1) und der Agentenkern (M2) laufen live; Projekt-ZIPs entstehen lokal und in der Cloud. Als Nächstes folgt die Medien-Etappe mit Sprache (Mara hört und spricht, auch im Live-Gespräch), danach Bildgenerierung und der Projekt-Workspace. Details zur Sprache stehen im [Sprachplan](docs/COREUI_VOICE_PLAN.md).**

Diese Roadmap ordnet die Anforderungen in **29 abschließbare Arbeitspakete** und nutzbare Lieferungen. Der [Masterplan](docs/COREUI_MASTERPLAN.md) hält Ziel und Architektur fest; die [Funktionsmatrix](docs/COREUI_FEATURE_MATRIX.md) führt Referenzen, bestehenden Teilbestand und Einzelabnahmen. Alle 82 derzeitigen Matrix-IDs sind einem Paket primär zugeordnet. Das bedeutet vollständige Zuordnung des erfassten Startbestands; die Tiefeninventur von **OpenClaw, Open WebUI, CrewAI, LangGraph und Microsoft AutoGen** läuft weiter.

## Aktueller Stand (0.5.71, 28.10.2026)

| Bereich | Stand | Belege |
|---|---|---|
| **M1 Downloads** (P02 bis P04) | Praktisch erledigt: Dateiablage, Download-Karten, geprüfter ZIP-Writer; ein komplettes Projekt-ZIP wurde live lokal gebaut. Formale Abnahme von #29 (Reload, Android) steht aus. | Migration 011, 0.5.47 bis 0.5.67 |
| **M2 Agent** (P05 bis P08) | Großteils erledigt: persistente Agentenläufe mit Abbruch, globale serielle Warteschlange, Arbeitsweg im Agent-Stil, native Werkzeuge (Plan, Bau, Web, Browse, Python) mit Selbstkorrektur, KI-Profil mit Kontextfenster; seit 0.5.75 Denkschalter und Core-Wahl mit Vorladen direkt im Chat, seit 0.5.76 mit TCI-Namen der Stufen in fester Reihenfolge (Lokal/Cloud je Stufe), Legende, Maras Persona auf jedem Core, Installer für fehlende Stufen, Updates mit wöchentlichem Auto-Update und Ollama-Cloud-Anmeldung direkt im Chat; seit 0.5.77 Arbeitsschleife mit bis zu 6 selbst gewählten Schritten und Chat-Komprimierung mit Markdown-Archiv. Offen: Arbeitsprofile und Promptvorlagen mit Variablen. | Migrationen 012, 013, 016, 017; 0.5.68 bis 0.5.70 |
| **P26 bis P28** (Teams, Checkpoints, Reviews) | Offen; die dauerhaften Projektpläne sind ein erster Baustein. | Migration 016 |
| **M3 Entwicklung** (P09 bis P11) | Offen: kein dauerhafter Projektordner, Editor, Terminal oder Git. Projekte entstehen je Bau neu als ZIP. | |
| **M4 Skills** (P12 bis P14) | Teilweise: Skills mit Pflicht-Skill "werkzeuge" und eigene Skills. Offen: Versionen, Import/Export, Plugins; Sprachskill #119. | Migration 014 |
| **M5 Browser, Wissen, Gedächtnis** (P15 bis P17) | Teilweise: Web und Browser nur lesend (#25 offen); RAG-Lite mit Namen und Rückfragen brauchbar, aber ohne Quellenangaben und Texterkennung; Gedächtnis ohne Herkunftsangabe. | 0.5.71 |
| **M6 Medien** (P20) | Bild-, Video- und PDF-Analyse vorhanden, seit 0.5.71 mit derselben Mara wie im Text. Hören über Ollama am 28.10.2026 auf dem Server gemessen (P20.0: 25 Tokens je Sekunde Audio, Abschnitte bis 60 Sekunden). Hören im Chat, Sprechen, Live-Gespräch und Bildgenerierung offen und als nächste Etappe geplant. | [Sprachplan](docs/COREUI_VOICE_PLAN.md), [Bildplan](docs/COREUI_IMAGE_ENGINE_PLAN.md) |
| **M6 übrige Breite** | P19 teilweise (robuste WebUI, Handy, 0.5.70); P18, P21 bis P24, P29 offen. | |
| **Laufzeit** | #51 und #52 (Hänger, Modellwechsel) seit Warteschlange, Kontextfenster-Erkennung und num_ctx vermutlich entschärft; live nachprüfen. | |
| **GitHub Actions** | Wegen ausgeschöpften Kontingents auf Nutzerwunsch ausgelassen; Prüfung über Preflights und lokale Selbsttests. | |

## Nächste Lieferfolge (Betreiberentscheidung 28.10.2026)

| Schritt | Inhalt | Plan |
|---|---|---|
| 1. P20.0 | Test auf dem Server: Audio über Ollama (Gemma 4 e2b, e4b, 12b über das `images`-Array), TTS-Kandidaten, Latenz einer Live-Runde. **Hören erledigt:** vier Läufe (Messprotokoll im Sprachplan). Entscheidung: Mitschrift über Googles Transkriptionsvorlage ohne Denken (10 von 10 kurze Aufnahmen, 60 s in 18 s), Sprachnachrichten zweistufig, Live einstufig. Festes Denken im Modelfile entfernt. **Offen:** TTS-Kandidaten. | [Sprachplan](docs/COREUI_VOICE_PLAN.md) |
| 2. P20.1 | Spracheingabe: Mikrofon, Sprachnachrichten, Audiodateien, Mitschrift im Chat | Sprachplan |
| 3. P20.2 | Videos hören: Tonspur zusammen mit den Bildern | Sprachplan |
| 4. P20.3 | Sprachausgabe: Lautsprecher-Knopf, optional automatisch vorlesen, eigene Mara-Stimme vom Server | Sprachplan |
| 5. P20.4 | Live-Gespräch: freihändig oder Halten-zum-Sprechen, satzweise Antwort, Unterbrechen | Sprachplan |
| 6. P20.5 | Bildgenerierung als Werkzeug nach dem Bild-Technikplan | [Bildplan](docs/COREUI_IMAGE_ENGINE_PLAN.md) |
| 7. P09 | Projekt-Workspace: gebaute Projekte bleiben als Ordner, gezielte Änderungen einzelner Dateien, neues ZIP | dieses Dokument, M3 |
| Danach | Wissen mit Quellenangaben und Texterkennung (P16), Sprachskill #119, Arbeitskommentare und Debug-Protokoll #97, Automationen (P21) | |

Die Paketnummern bleiben als Arbeitsreferenzen bestehen. Sämtliche Umsetzungspakete sind zunächst **offen**; vorhandene Teilfunktionen behalten ihren differenzierten Status in der Matrix. M0 und M7 begleiten die Entwicklung. Sie sind keine Wartephase vor der ersten nutzbaren Korrektur.

## Lieferfolge auf einen Blick

| Etappe | Nutzbares Ergebnis | Abschlussnachweis |
|---|---|---|
| M1 | Erzeugte Dateien direkt im Chat herunterladen | HTML und ZIP funktionieren einschließlich Reload, Handy und dauerhafter Speicherung |
| M2 | E.M.B.E.R. bearbeitet mehrstufige Aufträge; ergänzend Teams, Graphen und dauerhafte Wiederaufnahme | Grundlauf durchläuft echte Werkzeuge und Fehlerbearbeitung; P26 bis P28 nehmen erweiterte Abläufe, Checkpoints und Reviews gesondert ab |
| M3 | In einem dauerhaften Projekt entwickeln | Datei bearbeiten, Anwendung starten, prüfen, per Git sichern und als ZIP exportieren |
| M4 | Erprobte Arbeit als Skills wiederverwenden | Skill samt Ressourcen und Versionen in einem neuen Auftrag nutzen; Plugin-Lebenszyklus funktioniert |
| M5 | Mit Browser, Wissen und Gedächtnis arbeiten | Autorisierter Browserauftrag und eine belegte, später wiederaufgenommene Wissensaufgabe |
| M6 | Medien, Automationen, Kanäle, Zusammenarbeit und visuelle Agentenentwicklung ergänzen | Jede Erweiterung erfüllt ihre eigene Matrixabnahme; P29 verbindet Editor, Playground und reale Laufinspektion |
| M7 | Eine Teilversion oder den vollständig abgeglichenen Stand veröffentlichen | Tatsächliche Implementierung, Prüfung und auslieferbares Paket stimmen überein |

Feste Termine und neue Releaseversionen werden nach dem technischen Abgleich und dem tatsächlichen Umfang einer Lieferung festgelegt. Die Reihenfolge gibt den vorgesehenen Schwerpunkt an; konkrete Paketabhängigkeiten erlauben frühere unabhängige Teilumsetzungen.

## Regeln für jede Lieferung

- **Serieller Modellbetrieb:** Höchstens eine aktive Modellanfrage, gemeinsam für Gespräche, Unteraufgaben, Memory, Automationen und Medienmodelle. Auch Graphzweige, Manager, Sprecherwahl, Ergebnisprüfung, Embeddings, Replay und Evaluations-/Playgroundläufe nutzen dieselbe globale Warteschlange. Paketplanung und die Zahl der Clients ändern diese Grenze nicht. **Einzige Ausnahme (Betreiberentscheidung 28.10.2026):** die Sprachausgabe (TTS) darf mit festem Kernlimit parallel zu genau einer Core-Anfrage laufen, höchstens ein TTS-Auftrag gleichzeitig. Hören, Antworten und Bildgenerierung bleiben seriell.
- **Mobile Nutzung ab dem ersten Paket:** Downloads, Fortschritt, wesentliche Bedienelemente und spätere Entwicklerwerkzeuge auf Android Chrome prüfen. P19 erweitert diesen Umfang; die Handy-Nutzung beginnt bereits in M1.
- **Rechte und Datenerhalt ab dem ersten Paket:** Kontotrennung, reale Datei-IDs, Aufbewahrung, Löschung und Update-Persistenz gehören zur jeweiligen Umsetzung. P18 und P19 erweitern diese Grundlagen später.
- **Eigene Umsetzung:** OpenClaw, Open WebUI, CrewAI, LangGraph und Microsoft AutoGen werden vollständig als Referenzen inventarisiert. Fremder Code, Texte und Assets werden nicht übernommen. Modellabhängige, experimentelle, kommerzielle oder nur historisch gepflegte Bestandteile behalten die im Quellenregister beschriebenen Beleggrenzen.
- **Sichtbare, tatsächliche Arbeit:** Ein fertiger Plan, eine erfolgreich angelegte Datei oder ein beendeter Test braucht das passende reale Ereignis. Thinking-Anzeige und Modell-Denkaufwand bleiben getrennte Funktionen.
- **Prüfung am betroffenen Verhalten:** Direkte lokale oder autorisierte Laufzeitprüfungen mit echtem Ergebnis dokumentieren. GitHub Actions nicht anfordern; nicht ausgeführte Prüfungen bleiben offen.

## Arbeitspakete

„Voraussetzung“ benennt die benötigten Grundlagen. Es genügt ein konkret abgenommener Teil eines Vorgängerpakets, wenn genau dieser die Abhängigkeit erfüllt; das ist im zugehörigen PR zu begründen. Die gesamte Funktionsbreite eines Providers oder einer Integration muss dafür nicht schon fertig sein.

### M0: Inventur als laufende Grundlage

Die Startinventur ist vorhanden. P01 vertieft jeweils die Funktionen des nächsten Pakets und führt die Gesamtinventur fort.

| Paket | Voraussetzung | Ergebnis und Abnahme | Matrix-IDs |
|---|---|---|---|
| **P01: Einzelfunktionen inventarisieren** | Vorhandene Referenzstände | Alle fünf Referenzen und ihre Begleitprojekte in überprüfbare Einzelanforderungen zerlegen. Für jedes kommende Paket dessen Quellen, Voraussetzungen, Versionen, Lizenzen und Wartungsstand prüfen; neue Funktionen mit neuen CF-IDs ergänzen. **Abnahme:** Jede erfasste Funktion hat einen Beleg, eine eigene Sollbeschreibung und eine Zuordnung. Zusatzdienste und offene Detailprüfungen bleiben sichtbar. | `CF-001`, `CF-007` |

### M1: Erste Lieferung: echte Datei-Downloads

P02 bis P04 liefern die Korrektur für Issue #29. Nach deren Abnahme kann eine erste nutzbare Teilversion veröffentlicht werden.

| Paket | Voraussetzung | Ergebnis und Abnahme | Matrix-IDs |
|---|---|---|---|
| **P02: Arbeitsbasis und Schema festlegen** | Aktueller Repo- und Schemastand | Den aktuellen Quell-, Paket- und Schemastand abgleichen. Den fehlenden 0.5.4-Stand ausdrücklich behandeln und die bekannten noch offenen Änderungen einem Paket zuweisen. Einen kollisionsfreien Migrationsweg festlegen. **Abnahme:** Die Arbeitsbasis ist benannt; der Umgang mit 010_console_tools ist entschieden. Fehlender Altcode hält die Downloadkorrektur nicht auf. | `CF-002` |
| **P03: Dauerhafte Dateiausgabe im Backend** | P02 | Erzeugte Dateien vor der Worker-Bereinigung übernehmen, registrieren und Konto, Sitzung, Job und Antwort zuordnen. Einen gemeinsamen Ausgabevertrag sowie einen authentifizierten Downloadweg einführen. **Abnahme:** Echte Ausgaben bleiben bytegleich erhalten. Fremde Konten, unzulässige Pfade und erfundene Referenzen liefern keine Datei; Teilausfälle werden korrekt gemeldet. | `CF-025`, `CF-026`, `CF-027`, `CF-032` |
| **P04: Downloads im Chat fertigstellen** | P03 | Dateikarten mit Name, Typ und Größe integrieren und aus dem gespeicherten Verlauf wiederherstellen. HTML, ZIP und mehrere Ausgabedateien vollständig anbinden. **Abnahme:** HTML und ZIP lassen sich direkt, nach Reload und erneutem Sitzungsaufruf auf dem Handy herunterladen. Das ZIP ist lesbar; #29 erfüllt seine gesamte Abnahme. | `CF-028`, `CF-029`, `CF-030` |

### M2: Zweite Lieferung: E.M.B.E.R. arbeitet mehrstufig

P05 bis P08 verbinden Laufsteuerung, sichtbare Arbeit, Werkzeuge und Chat. Der Agent soll einen konkreten Auftrag über mehrere überprüfbare Schritte abschließen. Der gemeinsame Ereignisvertrag aus P06 und P07 wird früh festgelegt. CF-021 und CF-022 erhalten ihre vollständige Abnahme erst mit der Verbindung zur tatsächlichen Agentenausführung in P07.

| Paket | Voraussetzung | Ergebnis und Abnahme | Matrix-IDs |
|---|---|---|---|
| **P05: Dauerhafte Agentenläufe und Modellwarteschlange** | P02 | Laufzustände, Kontextgrenzen, Providerfähigkeiten und eine zentrale serielle Modellwarteschlange schaffen. Zunächst den vorhandenen Modellweg sauber einordnen; weitere Adapter einzeln abnehmen. **Abnahme:** Konkurrierende Chats und Hintergrundaufträge erzeugen höchstens einen aktiven Modellaufruf. Abbruch und Neustart erzeugen keine Doppelaufrufe; ein ungeklärter Providerzustand wird vor Freigabe des nächsten Laufs geklärt. | `CF-003`, `CF-005`, `CF-006`, `CF-017`, `CF-018` |
| **P06: Thinking, Plan und tatsächliche Arbeit anzeigen** | P05 | Gespeicherte Plan-, Kommentar- und Werkzeugereignisse zunächst an echten Aufrufen des vorhandenen Python-Pfads anzeigen. Denkaufwand und gelieferte Reasoning-Inhalte getrennt und entsprechend der Modellfähigkeiten behandeln. **Abnahme:** Jedes sichtbare Werkzeugereignis gehört zu einem tatsächlichen Aufruf. Nach Reload ist der wirkliche Stand sichtbar; Kommentare, Reasoning, Werkzeugereignisse und Antwort bleiben getrennt. | `CF-021`, `CF-022`, `CF-023`, `CF-024` |
| **P07: Allgemeinen Werkzeugzyklus umsetzen** | P03, P05, P06 | Ein Toolregister mit Schema und Rechten sowie eine begrenzte Ausführungs- und Reparaturschleife aufbauen. Unteraufgaben mit typisierten Nachrichten, Laufzuordnung und eigener Rückgabe seriell bearbeiten. Das frühere geplante Werkzeugmenü und Quellenmetadaten hier einordnen. **Abnahme:** Ein mehrstufiger Auftrag verwendet echte Werkzeuge, verarbeitet einen Fehler und liefert ein überprüftes Ergebnis samt den tatsächlichen Ereignissen aus P06. Unteraufgaben beachten Rechte und die gemeinsame Modellwarteschlange; fachliche und Transportfehler bleiben unterscheidbar. | `CF-019`, `CF-020`, `CF-041`, `CF-072` |
| **P08: Chat und Arbeitsprofile konsistent machen** | P03, P05, P06 | Wiederverwendbare Arbeitsprofile und Promptvorlagen einschließlich Variablen sowie Import/Export inventarisieren und mit dem Laufmodell verbinden. Text, Formeln, Code, Quellen und Antwortvarianten konsistent darstellen. **Abnahme:** Profilwahl, erneutes Öffnen und Variantenwechsel behalten den zugehörigen Kontext. Kopierter Code und heruntergeladene Dateien entsprechen dem Ergebnis. | `CF-004`, `CF-009`, `CF-010` |


**Zwischenstand 0.5.7 (P06, erste Teilumsetzung):** Der vorhandene Python- und Modellpfad erzeugt kontogebundene, antwortbezogene Arbeitsereignisse mit echter Ausfuehrungs- und Fehlerdiagnose (Migration 012). SSE zeigt diese live, nach Reload werden sie authentifiziert nachgeladen. Vollstaendige P05-Laufsteuerung, echte dauerhafte Plaene, Provider-Reasoning und allgemeine Werkzeugzyklen sind weiterhin offen. P06 ist damit **nicht vollstaendig abgenommen**; zuerst mit gpt-oss:120b und dem Fehlerfall aus Issue #29 live validieren.

**P05-Teilstand 0.5.9:** Ein persistenter Run pro privatem Turn/Aktion (Migration 013), atomare Zustandswechsel, sichere Antwortbindung und mitgelieferter Laufstatus in der authentifizierten History. Alle Ollama-HTTP-Modellaufrufe verwenden dieselbe MariaDB-Namenssperre mit begrenzter Wartezeit. Reconnects starten einen unklar unterbrochenen Run nicht erneut. Eine dauerhafte faire Job-Warteschlange, sichere Provider-Reconciliation nach Transportabbruch, aktive Abbruchsteuerung, Checkpoints und Mehrprovideradapter fehlen noch. P05 ist **in Arbeit**, nicht abgenommen. Echte Parallel- und Neustarttests stehen aus.

**P06-Folgestand 0.5.11:** Bei Fertigstellung einer Antwort bleibt der zuvor live angezeigte Arbeitsweg in derselben Nachricht erhalten und zunaechst offen. Gespeicherte Arbeitsschritte sind nach Reload ueber den klar bezeichneten Aufklappschalter lesbar. Ein kontogebundener Agentenlauf kann unterbrochene Event-Antwortzuordnungen beim Lesen wiederherstellen. Die Ereignisse bleiben strikt von privaten Modellgedanken getrennt. Echte Browser-/MariaDB-Abnahme und allgemeine Planungs-/Werkzeugketten sind weiterhin offen.

**M1-Folgestand 0.5.13:** Ein Betreiber-Test mit `ember-coreui:latest` zeigte nach zwei langsamen Modellaufrufen keine Python-Werkzeugaktion und keine Datei. Explizite Datei-/Downloadauftraege umgehen deshalb den ersten generischen SSE-Modellstream und fordern unmittelbar einen isolierten Python-Werkzeugauftrag an. Kleinere Modelle koennen stattdessen vollstaendige, eindeutig benannte HTML/CSS/JS-Fences liefern; diese werden inert in Dateien und optional ein geprueftes ZIP ueberfuehrt. Fehlt der Werkzeugcode, erfolgt nur ein kompakter Zweitversuch. Eine nicht vorhandene Datei bleibt ein sichtbarer Fehler. Die End-to-End-Abnahme mit dem lokalen Modell, ZIP-Inhalt und Android Chrome steht noch aus; Issue #29 bleibt offen.

**Folgestand 0.5.8:** Die neue Arbeitschronik ist nur noch ein inline Element der E.M.B.E.R.-Nachricht. Authentifizierte Antwort-History enthaelt echte Ereignisse, die zuvor doppelte Anzeige und Zusatzabfrage im Chat entfallen. Bei generierten Dateien prueft der Worker jetzt auch Ausgaben ausserhalb des vorgesehenen Sandbox-Verzeichnisses und vollstaendige HTML-stdout-Ausgaben, bevor er eine Datei zur verifizierten Aufbewahrung uebernimmt. Bei fehlenden Ausgabebytes kann ein serieller Korrekturlauf erfolgen. Die echte Live-Abnahme von Issue #29 und die volle P06-Agentenabnahme bleiben ausdruecklich offen.

### M2: Ergänzende Lieferungen für Teams und dauerhafte Workflows

P26 bis P28 erweitern die Agentenbasis um die durch CrewAI, LangGraph und AutoGen konkretisierten Mechanismen. Die vorhandenen Paketnummern bleiben erhalten; die neuen Nummern sind keine starre chronologische Reihenfolge. **P02 bis P04 bleiben die erste Lieferung.** P09 bis P11 können auf der abgenommenen Basis aus P05 bis P08 starten, ohne den vollständigen erweiterten Workflowumfang abzuwarten.

Der Checkpoint-/Schrittvertrag wird bei P26 vorbereitet und in P27 mit tatsächlichem Wiederanlauf abgenommen. CF-018 bleibt die Grundsteuerung; CF-075 bis CF-078 ergänzen detaillierte Persistenz, Replay und Rückmeldungen. Ein Versionswechsel im Workflow ist getrennt von einer neuen Skill-Revision zu behandeln.

| Paket | Voraussetzung | Ergebnis und Abnahme | Matrix-IDs |
|---|---|---|---|
| **P26: Agenten, Teams und Flows definieren** | P07, P08 | Versionierte Rollen, Aufgaben, Ergebnisverträge und Teams mit expliziten Zustandsgraphen aufbauen. Übergänge, Schleifen, Unterabläufe, Zusammenführung, Sprecherwahl und Handoffs nachvollziehbar steuern. **Abnahme:** Ein verzweigter Teamauftrag folgt seiner gespeicherten Definition. Export/Import erhält sie; unzulässige Ziele und Zustandswechsel werden erkannt. Manager, Auswahlmodelle und Zweige laufen global seriell. | `CF-073`, `CF-074`, `CF-080` |
| **P27: Läufe dauerhaft fortsetzen und verzweigen** | P26 | Laufzustand und übernommene Schrittergebnisse mit Versionsbezug dauerhaft sichern. Inspektion, kontrolliertes Replay, neue Laufzweige und begrenzte Wiederholungen ergänzen. **Abnahme:** Ein Prozessabbruch vor beziehungsweise nach der Ergebnisübernahme wird nachvollziehbar behandelt. Originalhistorie bleibt erhalten; Änderungen markieren abhängige Ergebnisse zur Neuberechnung. Unklare externe Effekte werden abgeglichen, inkompatible Zustände erklärt statt still geladen. | `CF-075`, `CF-076`, `CF-077` |
| **P28: Ergebnisse prüfen und Menschen einbeziehen** | P27 | Schema-/Qualitätsprüfungen und begrenzte Überarbeitung mit dauerhaft gespeicherten Rückfragen verbinden. Freigabe, Ablehnung und Korrektur einer konkreten Revision ermöglichen. **Abnahme:** Eine Rückmeldung setzt den richtigen Lauf nach Reload/Neustart genau einmal fort; veraltete oder fremde Antworten greifen nicht. Bereits ausreichende Auftragsfreigaben gelten weiter, Prüfmodelle nutzen die gemeinsame Warteschlange. | `CF-078`, `CF-079` |

### M3: Dritte Lieferung: vollständiger Entwicklungsablauf

P09 bis P11 ermöglichen einen zusammenhängenden Weg vom Projekt über Codeänderung, Ausführung und Prüfung bis zur Git-Sicherung und zum Export.

| Paket | Voraussetzung | Ergebnis und Abnahme | Matrix-IDs |
|---|---|---|---|
| **P09: Projekte, Dateien und Editor** | P03, P07, P08 | Dauerhafte Projekte mit Gesprächen, Anweisungen und Dateien einführen. Dateibaum, Suche, Editor, Änderungenvergleich und Gesprächsverzweigungen anschließen. **Abnahme:** Ein Projekt lässt sich anlegen, bearbeiten, verlassen und wieder öffnen. Dateiänderungen bleiben erhalten; Projektkontext und Zugriffe sind eindeutig getrennt. | `CF-011`, `CF-012`, `CF-013`, `CF-033`, `CF-034` |
| **P10: Terminal, Laufzeiten und Vorschau** | P09 | Terminal- und Prozesssitzungen, Projektabhängigkeiten, Tests, Builds und Anwendungsvorschauen integrieren. Den bestehenden Python- und Rechenweg weiterhin korrekt unterstützen. **Abnahme:** Ein Beispielprojekt startet, zeigt eine Vorschau und liefert echte Testausgaben. Ein eingebauter Fehler wird bearbeitet und der betroffene Ablauf erneut geprüft. | `CF-035`, `CF-036`, `CF-039`, `CF-040` |
| **P11: Git, Wiederherstellung und Projektexport** | P09, P10 | Git-Status, Diffs, Branches, getrennte Arbeitsstände und Wiederherstellung integrieren. Projektdateien und Artefakte über Dateibrowser, Vorschau und Export zugänglich machen. **Abnahme:** Eine Änderung wird auf einem eigenen Branch gesichert, kontrolliert zurückgenommen und als nutzbares Projekt-ZIP exportiert. Andere Arbeitsstände bleiben erhalten. | `CF-031`, `CF-037`, `CF-038` |

### M4: Vierte Lieferung: wiederverwendbare Fähigkeiten

P12 bis P14 ergänzen einen vollständigen Skill-Lebenszyklus und installierbare Laufzeitfähigkeiten.

| Paket | Voraussetzung | Ergebnis und Abnahme | Matrix-IDs |
|---|---|---|---|
| **P12: Skills laden und anwenden** | P07, P09 | Skills mit Metadaten, Hauptanweisung und Ressourcen erstellen, suchen und für einen Arbeitskontext auswählen. Benötigte Inhalte gezielt laden und Voraussetzungen kenntlich machen. **Abnahme:** Ein Skill wird in einem neuen Auftrag mit seinen Referenzen und Vorlagen verwendet. Fehlende Ressourcen oder Werkzeuge werden erkannt; Ressourcen laden führt nicht automatisch Skripte aus. | `CF-042`, `CF-043`, `CF-044` |
| **P13: Skill-Lebenszyklus vervollständigen** | P12 | Rechte, feste Revisionen, Vergleich, Import/Export, Konfliktbehandlung und Wiederherstellung ergänzen. Verbesserungen durch E.M.B.E.R. als nachvollziehbare Änderungen führen. **Abnahme:** Ein vollständiger Skill wird exportiert, wieder importiert und auf einen früheren Stand zurückgesetzt. Laufende Aufträge behalten ihre ausgewählte Revision samt Ressourcen. | `CF-045`, `CF-046`, `CF-047` |
| **P14: Plugins und externe Fähigkeiten** | P05, P07, P12 | Installierbare Erweiterungen mit Abhängigkeiten, Konfiguration und Rechten einführen. Tools, Provider, Hooks, MCP-/OpenAPI-Anbindungen und weitere Erweiterungsarten einzeln inventarisieren. **Abnahme:** Eine Erweiterung lässt sich installieren, nutzen, aktualisieren, deaktivieren und entfernen. Toolfilter, Konfigurations-/Verbindungsfehler und Zugriffe sind nachvollziehbar; zusätzliche Abhängigkeiten und Dienste werden einzeln ausgewiesen. | `CF-048` |

### M5: Fünfte Lieferung: Browser, Wissen und Gedächtnis

P15 bis P17 machen Recherche, Browseraktionen und dauerhaften Kontext zu nachvollziehbaren Bestandteilen der Agentenarbeit.

| Paket | Voraussetzung | Ergebnis und Abnahme | Matrix-IDs |
|---|---|---|---|
| **P15: Recherche und Browseraktionen** | P03, P06, P07 | Die vorhandene Recherche ausbauen und den Browser-Action-Plan #25 umsetzen: getrennte autorisierte Sitzungen, Eingaben, Entwürfe, Uploads, sichtbare Schritte und menschliche Übergaben. **Abnahme:** Ein konkreter Browserauftrag lässt sich nach den Regeln von #25 vorbereiten, prüfen, ausführen und nach einer Übergabe fortsetzen. Quellen und Aktionen bleiben zuordenbar. | `CF-049`, `CF-050`, `CF-051` |
| **P16: Wissen importieren und gezielt abrufen** | P07, P09, P14 | Dokumentextraktion, OCR, Sammlungen, hybride Suche, vollständigen Kontext und externe Wissensquellen einzeln ausbauen. Quellen Rollen oder Teams zuordnen und Index-/Embedding-Voraussetzungen kenntlich machen. Den bestehenden privaten RAG-Bestand weiterführen. **Abnahme:** Die Antwort verweist auf tatsächlich verwendete Quellen. Dokumentänderungen und entzogene Rechte wirken auf nachfolgende Abrufe; Modellarbeit bleibt seriell. | `CF-052`, `CF-053`, `CF-054` |
| **P17: Dauerhaftes Gedächtnis pflegen** | P05, P09, P16 | Erinnerungen mit Herkunft, Bereichen, Suche, Korrektur und getrennten Schreibrechten weiterentwickeln. Laufzustand und langfristiges Memory trennen; Zusammenfassungen, Embeddings und Pflege in die zentrale Warteschlange einordnen. **Abnahme:** Eine passende Erinnerung wird im zulässigen Bereich gefunden, eine gelöschte nicht. Schreibabschluss und Fehler sind sichtbar; Gedächtnispflege erzeugt keine parallelen Modellaufrufe. | `CF-055`, `CF-056` |

### M6: Weitere Lieferungen: Funktionsbreite

P18 bis P24 und P29 werden als getrennte Erweiterungen geliefert. Ihre Paketabhängigkeiten bestimmen die Reihenfolge innerhalb dieser Phase.

| Paket | Voraussetzung | Ergebnis und Abnahme | Matrix-IDs |
|---|---|---|---|
| **P18: Verwaltung und gemeinsame Ressourcen** | P07, P09, P13 | Vorhandene Konto- und Toolrechte um Rollen, Gruppen und geteilte Ressourcen erweitern. Zusätzliche Anmeldewege, API-Schlüssel, Laufdiagnose und wiederholbare Evaluation mit dokumentierter Ausgangslage einzeln abnehmen. **Abnahme:** Entzogene Rechte wirken auf direkte Zugriffe. Traces sind realen Läufen zugeordnet; Modellvergleiche laufen seriell und nennen Definition, Modell sowie Bewertungsbedingungen. | `CF-065`, `CF-066`, `CF-067` |
| **P19: Betrieb, mobile Bedienung und weitere Oberflächen** | P04, P11, P13 | Installation, Speicherbetrieb, Backup, Wiederherstellung, Sprachen, Tastaturbedienung und PWA-Umfang vervollständigen. Zusätzliche Prozesse respektieren die Modellgrenze. **Abnahme:** Ein gesicherter Stand lässt sich vollständig wiederherstellen. Kernabläufe sind auf Android Chrome ohne verdeckte Hauptaktionen bedienbar. | `CF-068`, `CF-069` |
| **P20: Medien und Sprache ausbauen** | P03, P04, P05 (P14 nur für Fremdanbieter) | **Ab 28.10.2026 vorgezogen und in P20.0 bis P20.5 aufgeteilt, siehe "Nächste Lieferfolge" und [Sprachplan](docs/COREUI_VOICE_PLAN.md):** Test, Spracheingabe, Videos hören, Sprachausgabe mit eigener Mara-Stimme, Live-Gespräch, Bildgenerierung. Bild-, Video- und Dokumentverarbeitung, Transkription, TTS, geeignete Live-Medien und Generierungswege je Provider ergänzen. Die CPU-native Bildinferenz nach [Ember Image Engine Plan](docs/COREUI_IMAGE_ENGINE_PLAN.md) mit stable-diffusion.cpp als C/C++-Referenz, FLUX.1-schnell und SDXL-Turbo als getrennten Kandidaten spezifizieren; CPU-Benchmarks, Modell-/Lizenzregister, RAM- und Threadlimits und eine provideruebergreifende serielle Warteschlange sind Voraussetzung. Alle Ergebnisse in den gemeinsamen Dateiweg aufnehmen. **Abnahme:** Unterstützte Medienabläufe funktionieren mit erkennbaren Modellgrenzen. Generierte Medien lassen sich wieder öffnen und herunterladen; beteiligte Modellanfragen bleiben seriell. | `CF-057`, `CF-058`, `CF-059` |
| **P21: Automationen, Kalender und dauerhafte Aufträge** | P05, P06, P07, P18 (P18 für gemeinsame Ressourcen) | Einmalige und wiederkehrende Aufgaben, Kalender, Webhooks, dauerhafte Anweisungen, Ereignishooks und Heartbeats als eigene Mechanismen mit Historie anbieten. **Abnahme:** Auslösen, Ändern, Stoppen und Wiederaufnahme funktionieren mit korrekter Zeitzone. Ein Neustart verdoppelt keine Aufgabe; Modellarbeit wartet bei belegter Warteschlange. | `CF-060`, `CF-061`, `CF-064` |
| **P22: Kanäle, Clients und Geräte** | P07, P14, P18 | Kommunikationskanäle, Gruppen, weitere Clients sowie Gerätefähigkeiten einzeln anbinden. Identität, Pairing, Sitzungszuordnung und Zugriffe festlegen. **Abnahme:** Eine Antwort erreicht den richtigen autorisierten Kanal. Kein Client oder Gerät eröffnet eine zweite gleichzeitige Modellanfrage. | `CF-062`, `CF-063` |
| **P23: Zusammenarbeit und Austausch** | P08, P09, P18 | Gesprächsimport/-export, kontrollierte Freigaben, Notizen und gemeinsame Channels mit Antworten, Reaktionen und Pins ausbauen. **Abnahme:** Ein Gespräch lässt sich mit ausgewählten Ressourcen übertragen. Gemeinsame Arbeit beachtet Mitgliedschaft und widerrufene Freigaben. | `CF-014`, `CF-015`, `CF-016` |
| **P24: Gesonderte Produktvorhaben** | Bestehende Regeln aus #23/#24 | Die bestehenden Vorhaben #23 und #24 in ihrer eigenen Begrenzung weiterführen: festgelegte Branding-Integrität und optionale Installationserfassung. Sie erhalten getrennte Umsetzung und Aktivierung. **Abnahme:** Nur bezeichnete Branding-Dateien werden geprüft. Die Installationserfassung bleibt ohne Opt-in aus und beeinflusst die Nutzbarkeit nicht. | `CF-070`, `CF-071` |
| **P29: Abläufe visuell bauen und erproben** | P04, P09, P14, P18, P28 | Visuellen Team-/Ablaufeditor, gleichwertige deklarative Ansicht und wiederverwendbare Komponenten mit einem zugeordneten Playground verbinden. Reale Schritte, Zustand, Reviews, Fehler und Ergebnisse untersuchen. **Abnahme:** Ein Ablauf lässt sich auch auf dem Handy ohne Drag-and-drop bearbeiten, in beiden Ansichten verlustfrei wechseln und kontrolliert ausführen. Import startet keinen Code; Downloads und serielle Vergleichsläufe verwenden die vorhandenen gemeinsamen Verträge. | `CF-081`, `CF-082` |

### M7: Abnahme und Veröffentlichung

P25 begleitet jede Teilveröffentlichung. Die abschließende Gesamtprüfung folgt auf die vollständige Einzelinventur aller fünf Referenzen und die zugehörigen Implementierungen, einschließlich der ergänzten Pakete P26 bis P29. Eine nützliche Teilveröffentlichung benötigt nur ihre tatsächlich betroffenen und abgenommenen Funktionen.

| Paket | Voraussetzung | Ergebnis und Abnahme | Matrix-IDs |
|---|---|---|---|
| **P25: Abdeckung und Releases nachweisen** | Jeweilige Lieferung; für Gesamtabschluss P01 und alle betroffenen Umsetzungen | Jede Lieferung gegen ihre Matrixpositionen prüfen und paketieren. Für die abschließende Referenzabdeckung alle Einzelanforderungen aus P01 und ihre Umsetzungen abgleichen. **Abnahme:** Quellstand, Migrationen, Archiv, Prüfsumme, Installer und Übergabe stimmen überein. Nur tatsächlich geprüfte Funktionen werden abgeschlossen; Restlücken bleiben benannt. | `CF-008` |

## Der erste Umsetzungsabschnitt

### 1. P02 abschließen: begrenzter Versions- und Schemaabgleich

Den aktuellen Quellstand und die tatsächlich vorhandenen Migrationen festhalten. Für den fehlenden vorbereiteten 0.5.4-Stand dokumentieren, welche Änderungen weiterhin fehlen und wo sie weiterbearbeitet werden. Das geplante Werkzeugmenü und die Werkzeug-/Quellenmetadaten sind P07 zugeordnet. Die bereits integrierte Host-Swap-Änderung bei der nächsten Paketbildung berücksichtigen.

Die im früheren Arbeitsstand beschriebenen Anpassungen des Rechenpfads werden in P10 geprüft; die zugehörige Paketbildung mit Archiv und Prüfsumme gehört zu P25.

P02 endet mit einer belegten Arbeitsbasis und dem Migrationsentscheid. Das Wiederfinden des vollständigen alten Commits ist keine Voraussetzung für seinen Abschluss oder für die Downloadkorrektur. Ein später wieder verfügbarer Stand wird gegen die aktuelle Arbeit abgeglichen.

### 2. P03 umsetzen: Dateiübernahme und Registrierung

Den vorhandenen Python-Worker zuerst anschließen. Echte Ausgaben vor dem Aufräumen übernehmen und strukturiert zurückmelden. Kontozuordnung, Originalname, Typ, Größe, Inhalts-Hash sowie Sitzung, Job und Antwort in den Ausgabevertrag aufnehmen. Den dauerhaften Speicher in den benötigten Lösch-, Sicherungs- und Updateweg einbeziehen.

**Ergebnis dieses Pakets:** Eine tatsächlich erzeugte Datei ist über ihre registrierte Referenz abrufbar und bleibt nach der Worker-Bereinigung erhalten.

### 3. P04 umsetzen: Ausgabe im gespeicherten Gespräch

Die bestätigten Artefakte in Dateikarten umsetzen und beim erneuten Laden des Gesprächs wieder anzeigen. HTML und ZIP als konkrete End-to-End-Fälle prüfen. Auch mehrere Dateien, Unicode-Namen, fehlende Referenzen, Kontotrennung und Android Chrome abnehmen.

**Ergebnis dieser ersten Lieferung:** E.M.B.E.R. erzeugt eine HTML-Datei und ein ZIP; beide lassen sich aus der Antwort und später erneut aus demselben Gespräch herunterladen. [Issue #29](https://github.com/Starlight-Unit-Studio/CoreAI-TCI-Interface/issues/29) wird erst geschlossen, wenn dessen vollständige Abnahme belegt ist.

### 4. Frühe Teilveröffentlichung nach P25

Den geprüften Downloadstand paketieren. Quellcode, Migration, Versionsdatei, Changelog, Archiv, SHA-256, Installer und Übergabe müssen denselben auslieferbaren Stand beschreiben. Eine fertige Downloadkorrektur wartet nicht auf M2 bis M6.

## Fortschritt pflegen und zwischen Chats fortsetzen

Für jedes aktive Paket genau einen nachvollziehbaren Arbeitsstand führen: **offen**, **in Arbeit**, **in Prüfung**, **erledigt** oder **blockiert mit konkretem Grund**. Dazu gehören der Arbeitsbranch beziehungsweise PR, die betroffenen CF-IDs, tatsächlich erfolgte Prüfungen und der nächste ausführbare Schritt.

Die Matrix bleibt die Quelle für den Funktionsstatus. Ein Paket wird erst erledigt, wenn seine vereinbarte Lieferung und die zugehörigen Abnahmen abgeschlossen sind. Teilresultate erhalten einen eigenen belegten Stand; sie schließen weder das gesamte Paket noch eine Referenzfamilie automatisch ab.

Nach jeder Lieferung die Roadmap um Datum, PR/Commit und Ergebnis ergänzen. Neue Referenzfunktionen in der Matrix erfassen und einem Paket oder einem neuen Paket zuordnen. Die vollständige Abdeckung wird anhand der festgehaltenen Referenzstände geprüft, ohne aus der Anzahl der Pakete einen Fertigstellungsprozentsatz abzuleiten.

**Übergabe für den nächsten Arbeitsabschnitt (28.10.2026):** P20.0 läuft: Das Hören über Ollama ist zweimal auf dem echten Server gemessen und im Messprotokoll des [Sprachplans](docs/COREUI_VOICE_PLAN.md) festgehalten. Als Nächstes folgen der Lauf mit dem erweiterten Test aus 0.5.74 (Denken, Live-Antwort, kurze Aufnahmen) und die TTS-Kandidaten. Danach P20.1 bis P20.5 und P09. Die Abschnitte "Der erste Umsetzungsabschnitt" oben beschreiben den historischen Start mit P02 bis P04 und bleiben als Nachweis stehen.

Historische Übergabe vom 20.10.2026: P02 war der nächste Schritt. Download-Bug #29 blieb offen. Masterplan und Matrix waren gesichert; es waren durch die Roadmap noch keine Laufzeitfunktionen fertiggestellt, keine neue Migration vergeben und keine GitHub Actions ausgeführt.

