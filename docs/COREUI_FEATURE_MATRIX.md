# CoreAI: TCI Interface: Funktionsmatrix und überprüfbare Startinventur

Aktueller Produktname ab 0.5.57: CoreAI: TCI Interface (Quantum-Softwarefamilie). Historische Eintraege behalten ihren damaligen Wortlaut.

Stand: 20.10.2026, ergänzt am 28.10.2026 (CF-057, CF-058 und Regel 1 zur Sprachausgabe; Messstand P20.0 bei CF-058). Status dieses Dokuments: Planung. Durch diese Matrix wurde keine der beschriebenen Funktionen neu implementiert.

Der [Masterplan](COREUI_MASTERPLAN.md) beschreibt die gemeinsame Zielarchitektur, die technische Ausgangslage und die Reihenfolge der Umsetzung.

Die [Umsetzungsroadmap](../ROADMAP.md) ordnet alle derzeitigen CF-IDs in konkrete Lieferpakete ein. Die dortigen Paketstände ersetzen die Einzelabnahmen dieser Matrix nicht.

## Ziel und Aussagegrenze

Ziel ist die vollständige funktionale Bestandsaufnahme von **OpenClaw, Open WebUI, CrewAI, LangGraph und Microsoft AutoGen** und die eigenständige Umsetzung ihrer Funktionen in E.M.B.E.R. CoreUI. Alle fünf Referenzen gehören zum Umfang. Diese erweiterte Startmatrix enthält **82 stabile Einträge**; sie ist weder eine abgeschlossene Vollinventur noch ein Nachweis vollständiger Gleichwertigkeit. CF-001 bis CF-072 behalten ihre IDs, CF-073 bis CF-082 ergänzen konkrete Agenten- und Workflowmechanismen. Bei der Tiefenprüfung werden weitere Einzelanforderungen ergänzt, ohne bestehende IDs umzunummerieren.

Die Spalte „Referenzfunktion“ enthält knappe, paraphrasierte Beleg-Keywords. Die Spalte „Eigenes Soll mit Abnahme“ beschreibt unsere eigenen Anforderungen und zukünftige Prüfungen. Eine dort formulierte Abnahme gilt noch nicht als bestanden. Quellcode, Fremdtexte, Designs und Assets der Referenzprojekte werden nicht übernommen. Eine dokumentierte Referenzfunktion beweist auch nicht, dass sie in jeder Edition, Installation oder Modellverbindung verfügbar ist.

Die Matrix verwendet folgende Zustände:

- **Teilbestand:** Eine verwandte Funktion ist im geprüften CoreUI-Quellstand belegt. Vollständigkeit und Gleichwertigkeit sind nicht bestätigt.
- **Offen:** Der hier benannte zusätzliche Baustein ist als Umsetzungsarbeit eingeplant; die vorhandene Basis erfüllt das Soll noch nicht.
- **Bug:** Für den bezeichneten Weg besteht eine belegte Lücke oder ein konkret gemeldeter Fehler. Die endgültige Abnahme steht aus.
- **Ungeprüft:** Für diese Einzelanforderung liegt noch kein hinreichender CoreUI-Nachweis vor. Das bedeutet weder vorhanden noch fehlend.

## Verbindliche Produktregeln

1. **E.M.B.E.R. darf höchstens eine Modellanfrage gleichzeitig ausführen.** Chat, Browserentscheidungen, Unteragenten, Erinnerungsverarbeitung, Zusammenfassungen, Medienmodelle und Automationen müssen dieselbe serielle Warteschlange nutzen. Das gilt auch für Graphzweige, Manager, Sprecherwahl, Ergebnisprüfer, Embeddings, Replay und Playground-/Evaluationsläufe. Mehrere Aufgaben dürfen warten und nacheinander arbeiten. Neue Adapter, weitere Benutzer oder zusätzliche Prozesse dürfen diese Grenze nicht umgehen. **Einzige Ausnahme (28.10.2026):** die Sprachausgabe (TTS) mit festem Kernlimit parallel zu genau einer Core-Anfrage, höchstens ein TTS-Auftrag gleichzeitig ([Sprachplan](COREUI_VOICE_PLAN.md)).
2. Denkaufwand als Modelleinstellung, tatsächlich bereitgestellte Reasoning-Ausgabe, öffentliche Kommentare und Pläne sowie ausgeführte Werkzeugschritte bleiben getrennte Daten- und Anzeigetypen. Eine nicht gelieferte Gedankenansicht wird nicht erfunden. Liefert ein Anbieter nur eine Zusammenfassung, wird diese als solche angezeigt.
3. Die Entwicklerumgebung umfasst Projekte, Dateibrowser, Editor, Terminal, Git, Tests und Vorschauen. Der vorhandene Python-Einzelaufruf erfüllt dieses Ziel noch nicht.
4. Tools führen Aktionen aus. Skills beschreiben wiederverwendbare Vorgehensweisen mit Ressourcen. Plugins erweitern die Laufzeit und können Tools oder Provider bereitstellen. Ein Skill ist nicht automatisch eine Berechtigung zur Werkzeugausführung.
5. **Download-Bug [#29][C-29] ist früh und unabhängig von der vollständigen IDE lösbar.** In M1 sind Versionsabgleich und Artefakt-Downloads getrennte Teilaufgaben; ein fehlender alter Arbeitsstand darf Analyse, Vorbereitung und Umsetzung des Download-Fixes nicht blockieren. Neue Migrationen benötigen den im Masterplan beschriebenen Schemaabgleich.
6. [#25][C-25], [#23][C-23] und [#24][C-24] bleiben eigenständige Vorhaben mit ihren bereits festgelegten Grenzen. Diese Matrix erweitert ihre Befugnisse nicht.
7. GitHub Actions werden gemäß Nutzeranweisung wegen des ausgeschöpften Kontingents übersprungen. Diese Planungsarbeit löst keine Workflows aus. Spätere tatsächlich ausgeführte lokale oder manuelle Prüfungen sind mit ihrem realen Ergebnis zu dokumentieren.

### Phasenbezug

- **M0:** Plan und Inventur.
- **M1:** Versionsabgleich und Downloads als unabhängige Teilaufgaben.
- **M2:** Agentenlauf, Ereignisse und Toolregister; ergänzend Teams, Zustandsgraphen, Checkpoints und Reviews.
- **M3:** Entwicklerumgebung.
- **M4:** Skills und Plugins.
- **M5:** Browser, Wissen und Memory.
- **M6:** Medien, Automationen, Kanäle, Zusammenarbeit, Administration und visueller Agenten-/Workflowbereich.
- **M7:** Abdeckung und Release.

Die Phasennummer in jeder Soll-Zelle benennt den vorgesehenen Schwerpunkt. Sie ersetzt keine konkrete Abhängigkeitsprüfung. Eine neue Releaseversion oder Migrationsnummer wird mit diesem Dokument nicht vergeben.

## 1. Grundlagen, Modelle und Nachweis

| ID | Referenzfunktion / Quelle | CoreUI-Stand | Eigenes Soll mit Abnahme |
|---|---|---|---|
| CF-001 | Funktionskatalog aller fünf Referenzen [OC-F], [OW-F], [CR-R], [LG-R], [AG-R] | Ungeprüft | **M0.** Menüs, APIs, Konfigurationen, Laufzeitmechanismen und Randfälle einzeln erfassen. Abnahme: Jede gefundene Funktion besitzt Quelle, Voraussetzungen, CoreUI-Zuordnung und eine prüfbare Anforderung; ungeklärte Lücken bleiben sichtbar. |
| CF-002 | Eigener Versions- und Paketstand [C-R] | Teilbestand | **M1.** Quellstand, installierten Stand, Releasearchiv, Installer und vorhandene Migrationen abgleichen. Abnahme: Abweichungen und gewählte Arbeitsbasis sind belegt; keine Version oder Migration wird allein aus früheren Chatbehauptungen übernommen. |
| CF-003 | Modellanbieter und Verbindungen [OC-F], [OW-R], [CR-R], [AG-A] | Teilbestand | **M2.** Die lokale Modellbasis um explizite Provideradapter erweitern. Abnahme: Konfiguration, Authentifizierung und Fehlerfälle funktionieren je Adapter; jeder Modellaufruf passiert die gemeinsame Warteschlange. |
| CF-004 | Modellprofile und Vorlagen [OW-W] | Ungeprüft | **M2.** Wiederverwendbare Profile mit Anweisungen, Werkzeugen und Wissenszuordnung anbieten. Abnahme: Speichern, Auswählen, Ändern und Wiederherstellen eines Profils sind nachvollziehbar; unzulässige Ressourcen werden nicht übernommen. |
| CF-005 | Verbindungs- und Laufzeitdiagnose [OC-F], [C-R] | Teilbestand | **M2.** Modellfähigkeiten und Verbindungszustand sichtbar prüfen. Abnahme: Ein unerreichbarer Provider und ein nicht unterstützter Parameter erzeugen konkrete Meldungen; keine scheinbar erfolgreiche Antwort wird angezeigt. |
| CF-006 | Grenzen und Kontextverwaltung [OC-T], [OW-F] | Ungeprüft | **M2.** Kontext-, Ausgabe- und Laufzeitgrenzen je Aufgabe festlegen. Abnahme: Überschreitung wird verständlich behandelt; eine nötige Modellzusammenfassung wartet ebenfalls in der seriellen Warteschlange. |
| CF-007 | Hauptprojekte, Begleitprojekte und Produktgrenzen [OW-R], [OW-T], [CR-R], [LG-R], [LG-O], [AG-R] | Ungeprüft | **M0.** Jede Funktion ihrem Hauptprojekt, Begleitprojekt oder einer Editionsvoraussetzung zuordnen. Lizenz-, Versions- und Wartungsbelege je Komponente führen. Abnahme: Ein Zusatzdienst wird nicht als enthaltene Kernfunktion gewertet; offene Zusatzprüfungen bleiben erfasst, die MIT-Lizenz eines Clients wird keinem verbundenen Dienst zugeschrieben. |
| CF-008 | Vollständige Abdeckung und Veröffentlichung [V] | Offen | **M7.** Referenzinventur gegen Umsetzungen und Abnahmen abgleichen. Abnahme: Jede Funktion hat einen belegten Status; Restlücken sind benannt, Versionsentscheidungen begründet und tatsächlich ausgeführte Prüfungen dokumentiert. |

## 2. Chat, Projekte und Zusammenarbeit

| ID | Referenzfunktion / Quelle | CoreUI-Stand | Eigenes Soll mit Abnahme |
|---|---|---|---|
| CF-009 | Sitzungen und Nachrichtenverlauf [OW-C], [C-R] | Teilbestand | **M2.** Private Gespräche, Bearbeitungen und Antwortvarianten verlässlich erhalten. Abnahme: Erneutes Öffnen zeigt denselben Verlauf und dieselbe gewählte Variante; ein anderes Konto erhält keinen Zugriff. |
| CF-010 | Markdown, Formeln und Code [OW-R], [C-R] | Teilbestand | **M2.** Text, Formeln, Codeblöcke und Quellen konsistent darstellen. Abnahme: Kopieren erhält Codebytes; nicht vertrauenswürdige Inhalte können keine aktive Anwendung im Chat einschleusen. |
| CF-011 | Chatprojekte und Kontextvorgaben [OW-C] | Offen | **M3.** Projekte bündeln Gespräche, Dateien und Anweisungen. Abnahme: Ein Projektwechsel wechselt den zugeordneten Kontext nachvollziehbar; fremde Projektdateien gelangen nicht unbeabsichtigt in die Anfrage. |
| CF-012 | Anhänge als Gesprächskontext [OW-C], [C-R] | Teilbestand | **M3.** Registrierte Anhänge mit erkennbarer Verarbeitungsart verwenden. Abnahme: Text, Bild und Dokument werden korrekt zugeordnet; Teilanalysen und nicht lesbare Inhalte werden ausdrücklich kenntlich gemacht. |
| CF-013 | Suche, Ordnung und Verzweigungen [OW-C] | Teilbestand | **M3.** Vorhandene Revisionen um Suche, Ordner, Tags, Pins und eigenständige Gesprächszweige ergänzen. Abnahme: Eine Verzweigung erhält den ausgewählten Kontext und verändert das Ursprungsgespräch nicht. |
| CF-014 | Gesprächsimport, Export und Freigabe [OW-C] | Ungeprüft | **M6.** Gespräche mit klarer Ressourcenauswahl übertragen und freigeben. Abnahme: Ein Export lässt sich wieder einlesen; Anhänge und Rechte werden geprüft, eine widerrufene Freigabe verliert ihren Zugriff. |
| CF-015 | Notizen und gemeinsamer Arbeitsraum [OW-F] | Ungeprüft | **M6.** Eigenständige Notizen mit KI-Bearbeitung, Suche und Chatzuordnung anbieten. Abnahme: Änderungen sind nachvollziehbar; nur ausgewählte und zugelassene Notizen werden Gesprächskontext. |
| CF-016 | Gemeinsame Channels und Threads [OW-F] | Ungeprüft | **M6.** Zusammenarbeit mit Antworten, Reaktionen und angehefteten Beiträgen anbieten. Abnahme: Mitgliedschaft regelt Lesen und Schreiben; Modellbeiträge stehen neben menschlichen Beiträgen und verwenden dieselbe Modellwarteschlange. |

## 3. Agentenlauf, Thinking und sichtbare Arbeit

**Teilstand 0.5.7:** CF-021 und CF-022 haben nun eine erste technische Grundlage: gespeicherte, antwortbezogene Ereignisse fuer wirkliche Modell- und Python-Aufrufe, Fehler und bestaetigte Dateien. Der Status bleibt **Teilbestand** bis die P05-Laufsteuerung, gespeicherte Plaene, Wiederaufnahme und allgemeine Werkzeuge hinzukommen. CF-023 und CF-024 (Denkaufwand und echte Provider-Reasoning-Darstellung) sind dadurch **nicht** fertig. Ausgaben enthalten keine rohen Modellgedanken oder geheimen Werkzeugdaten.

| ID | Referenzfunktion / Quelle | CoreUI-Stand | Eigenes Soll mit Abnahme |
|---|---|---|---|
| CF-017 | Serielle E.M.B.E.R.-Ausführung [V], [C-R] | Teilbestand | **M2.** Eine gemeinsame Warteschlange für alle Modellaufrufe erzwingen. Abnahme: Zwei Chats, Unteraufgaben, Memory und Automation erzeugen zusammen zu keinem Zeitpunkt mehr als eine aktive Modellanfrage. |
| CF-018 | Warteschlange, Abbruch und Fortsetzung [V], [OW-C], [LG-F], [AG-P] | Teilbestand | **M2.** Laufende und wartende Aufgaben steuerbar machen. Abnahme: Abbruch, Verbindungsverlust und Neustart erzeugen keine doppelten Aufrufe; ein unklar beendeter Modelllauf wird vor Freigabe des nächsten geklärt. Geordnetes Stoppen, sofortiger Abbruch und Warten auf Eingabe bleiben unterscheidbar. Erweiterte Checkpoints stehen unter CF-075 bis CF-078; der OpenClaw-Einzelbeleg bleibt offen. |
| CF-019 | Allgemeine Werkzeug- und Reparaturschleife [OC-T], [OW-T], [CR-X], [AG-T] | Offen | **M2.** Planen, Werkzeug ausführen, Ergebnis lesen und begrenzt korrigieren ermöglichen. Abnahme: Ein absichtlich scheiternder Schritt wird nachvollziehbar korrigiert oder beendet; die Modellaufrufe bleiben seriell. |
| CF-020 | Delegierte Unteraufgaben und Ziele [OC-T], [OC-A], [AG-C], [LG-U] | Offen | **M2.** Unteraufgaben mit eigenem Kontext und Rückgabe verwalten. Ein versionierter Nachrichtenvertrag ordnet Typ, Sender, Empfänger, Elternlauf und Ergebnis zu. Abnahme: Der Hauptlauf wartet auf nacheinander abgearbeitete Unteraufgaben; Kontext, Nachrichten, Ergebnis und Abbruch bleiben eindeutig zugeordnet. Unbekannte Nachrichtentypen oder fremde Laufziele werden nicht still verarbeitet. |
| CF-021 | Dauerhafter Plan und Fortschritt [OC-P], [C-C], [CR-E], [LG-S] | Teilbestand | **M2.** Feste Statussätze um gespeicherte Plan- und Kommentarereignisse ergänzen. Abnahme: Nach Reload sind erledigte, laufende und offene Schritte erkennbar; Kommentare behaupten keine nicht ausgeführte Arbeit. Lauf-, Unterlauf- und Ereignis-IDs erlauben die eindeutige Wiederaufnahme der Anzeige. |
| CF-022 | Werkzeugereignisse und Ergebnisse [OC-T], [OC-P], [CR-E], [LG-S], [AG-V] | Teilbestand | **M2.** Beginn, Ergebnis, Fehler und Dauer eines echten Werkzeugaufrufs anzeigen. Abnahme: Jedes sichtbare Ereignis hat eine reale Laufzuordnung; Wiederverbinden erzeugt keine doppelten Schritte. Zustandsänderungen und betriebliche Traces bleiben von Reasoning-Inhalten getrennt. |
| CF-023 | Modellabhängiger Denkaufwand [OC-H], [OW-H] | Ungeprüft | **M2.** Unterstützten Denkaufwand getrennt von der Darstellung einstellen. Abnahme: Der gewählte Wert erreicht den geeigneten Adapter; nicht unterstützte Einstellungen werden erkennbar behandelt. |
| CF-024 | Tatsächlich gelieferte Reasoning-Anzeige [OC-H], [OW-H] | Offen | **M2.** Gelieferte Reasoning-Inhalte oder Zusammenfassungen separat anzeigen. Abnahme: Kein leerer oder erfundener Gedankenblock; Antwort, öffentlicher Plan, Kommentare und Werkzeugereignisse bleiben unabhängig erkennbar. |

**P06-Folgestand 0.5.11:** Die Arbeitschronik bleibt nach der Antwort direkt in der zugehoerigen Nachricht und ist auch nach Reload ueber einen eindeutigen Schalter lesbar. Live- und gespeicherte Ereignisse werden anhand der Event-ID zusammengefuehrt; ein unterbrochener Event-Bindungsschritt wird ueber den autoritativen Agentenlauf und dessen Benutzer-/Sitzungs-/Turn-/Run-Key-Zuordnung beim Lesen abgefangen. CF-021/022 bleiben **Teilbestand**, bis allgemeine Plaene, Toolketten, Wiederaufnahme und Live-Abnahme vorliegen. Keine Offenlegung interner Modellgedanken.

**P05-Teilstand 0.5.9:** Migration 013 speichert kontogebundene Runs und globale FIFO-Tickets. Alle Ollama-HTTP-Aufrufe teilen eine MariaDB-Sperre, die serielle Modellverarbeitung erhaelt. Abbruch wird mit CSRF, eigenem Run und kooperativem cURL-Stopp angefordert; Browserjobs werden nach Abschluss ohne Modell-Replay abgeglichen. CF-017/018 bleiben **Teilbestand**, bis parallele MariaDB-/Cloud-/Neustart-/Android-Tests erfolgt sind und providerseitig unklare Aufrufe, dauerhafte Hintergrundjobs und Workflow-Checkpoints gesondert geloest sind.

**Folgestand 0.5.8:** Sichtbare Arbeitsschritte sind Bestandteil derselben ungerahmten Chatantwort. Der zuvor fehlerhafte zweite Verlaufabruf wird im Chat durch die bereits authentifizierte Antwort-History ersetzt. Echte Python-Dateiausgaben aus dem isolierten Arbeitsbereich und vollstaendiges HTML-stdout koennen nun registriert werden; ein fehlendes Ausgabe-Artefakt (auch bei Exitcode 0) fuehrt zu genau einem begrenzten Reparaturversuch. Vollstaendige P05-/P06-Agentenabnahme und Live-Downloadtest bleiben offen.

**Lokaler Modellpfad 0.5.13:** CF-028 bis CF-032 erhalten eine weitere Teilimplementierung. Bei expliziter Dateierstellung wird das lokale Modell ohne vorangehenden generischen Textstream direkt fuer einen Python-Werkzeugauftrag aufgerufen. Eine einmalige, kompakte Protokollkorrektur sowie die sichere Uebernahme benannter HTML/CSS/JS-Fences inklusive ZIP-Erzeugung reduzieren Abhaengigkeit von `[PY]`-Formatdisziplin. Nur bestaetigte, kontogebundene Worker-Ausgaben werden angezeigt; fehlende benannte Dateien werden nicht als vollstaendiger Erfolg gemeldet. Live-Test und ZIP-Endabnahme mit `ember-coreui:latest` stehen aus. Die CF-Zeilen bleiben deshalb vorerst im bisherigen Status.

## 4. Dateien, Downloads und Artefakte

| ID | Referenzfunktion / Quelle | CoreUI-Stand | Eigenes Soll mit Abnahme |
|---|---|---|---|
| CF-025 | Erzeugte Dateien erhalten [OW-B], [C-P], [C-29] | Bug | **M1.** Ausgaben vor dem Aufräumen des Python-Jobs dauerhaft übernehmen. Abnahme: Eine erzeugte Datei bleibt nach Entfernen des temporären Arbeitsverzeichnisses bytegleich verfügbar. |
| CF-026 | Artefaktregister und Antwortzuordnung [OW-B], [C-C], [C-29] | Bug | **M1.** Datei-ID, Name, Typ, Größe, Eigentümer, Sitzung, Job und Antwort serverseitig verknüpfen. Abnahme: Mehrere Dateien erscheinen bei ihrer richtigen Antwort; bloßer Modelltext kann keine Artefakt-ID erfinden. |
| CF-027 | Berechtigter Download [OW-B], [C-M], [C-29] | Bug | **M1.** Registrierte Ausgaben über einen authentifizierten Endpunkt liefern. Abnahme: Eigentümer kann herunterladen; fremde Konten, ungültige IDs und unzulässige Dateipfade erhalten keinen Dateiinhalt. |
| CF-028 | HTML als erzeugte Datei [OW-B], [C-29] | Bug | **M1.** HTML mit echtem Dateinamen zum Download anbieten. Abnahme: Heruntergeladene Bytes entsprechen der Ausgabe; aktives HTML wird nicht im CoreUI-Ursprung ausgeführt. |
| CF-029 | ZIP und mehrere Ergebnisdateien [OW-B], [C-29] | Bug | **M1.** Archive und Dateisammlungen zuverlässig ausliefern. Abnahme: Ein erzeugtes ZIP lässt sich vollständig öffnen; enthaltene Pfade, Leerzeichen und Umlaute bleiben korrekt, Dateigrößen stimmen. |
| CF-030 | Download nach Reload und mobil [V], [C-U], [C-29] | Bug | **M1.** Downloadkarten aus gespeicherten Daten rekonstruieren. Abnahme: HTML und ZIP funktionieren nach Reload, erneutem Sitzungsaufruf und im mobilen Browser; ein Streaming-Ereignis ist dafür nicht erforderlich. |
| CF-031 | Vorschau, Dateibrowser und Lebensdauer [OW-B] | Offen | **M3.** Artefakte sicher öffnen, anzeigen und verwalten. Abnahme: Vorschau und Originaldownload beziehen sich auf dieselbe Version; Aufbewahrung und Löschung sind nachvollziehbar und berücksichtigen bestehende Verweise. |
| CF-032 | Weitere Erzeuger und Fehlerfälle [V], [C-P], [C-29] | Ungeprüft | **M1.** Einen gemeinsamen Ausgabeweg auch für weitere Dateierzeuger vorsehen. Abnahme: Nicht erzeugte oder nicht übernommene Dateien erhalten keinen Erfolgslink; ein Fehler beschädigt keine bereits gespeicherten Artefakte. |

## 5. Vollwertige Entwicklerumgebung

| ID | Referenzfunktion / Quelle | CoreUI-Stand | Eigenes Soll mit Abnahme |
|---|---|---|---|
| CF-033 | Arbeitsverzeichnis und Dateibaum [OC-W], [OW-T] | Offen | **M3.** Beständige, zugeordnete Projektarbeitsbereiche anbieten. Abnahme: Dateien und Ordner lassen sich erstellen, öffnen, umbenennen und wiederfinden; Projektgrenzen werden beim Lesen und Schreiben eingehalten. |
| CF-034 | Editor und gezielte Änderungen [OC-T], [OW-T] | Offen | **M3.** Editor mit Suche, mehreren Dateien und überprüfbaren Änderungen bereitstellen. Abnahme: Eine mehrteilige Änderung zeigt ihren Diff, bleibt nach Reload erhalten und kann gezielt zurückgenommen werden. |
| CF-035 | Terminal und Prozessverwaltung [OC-T], [OW-T] | Offen | **M3.** Persistente Terminal- und Prozesssitzungen anbieten. Abnahme: Starten, Ausgabe lesen, Eingabe senden und Beenden funktionieren; Prozesszustand und Exitcode entsprechen dem tatsächlichen Lauf. |
| CF-036 | Laufzeiten, Pakete und Codeausführung [OW-T], [C-P], [C-R], [AG-X] | Teilbestand | **M3.** Vorhandenen Python-Worker und lokale Rechenwege in eine allgemeine Entwicklungsumgebung einordnen. Abnahme: Abhängigkeiten, Arbeitsverzeichnis, Laufzeit, Prozessende und Aufräumen sind kontrollierbar; Ergebnisse bleiben erhalten, exakte Ganzzahlrechnung bleibt korrekt. |
| CF-037 | Git-Arbeit im Projekt [OC-G], [OW-T] | Offen | **M3.** Status, Diff, Branches, Commits und Konfliktbearbeitung integrieren. Abnahme: Eine Änderung lässt sich prüfen und auf einem eigenen Branch sichern; Konflikte werden ohne Verlust fremder Arbeit behandelt. |
| CF-038 | Isolierte Checkouts und Wiederherstellung [OC-G] | Offen | **M3.** Getrennte Projektarbeiten und Rücksetzpunkte ermöglichen. Abnahme: Zwei Arbeitsstände verändern einander nicht; eine Wiederherstellung betrifft nur den gewählten Stand und berücksichtigt ungesicherte Änderungen. |
| CF-039 | Tests, Build und Diagnose [OC-T], [OW-T] | Offen | **M3.** Projektspezifische Tests und Builds ausführen und auswerten. Abnahme: Ein Fehler ist samt echter Ausgabe nachvollziehbar; nach einer Korrektur wird das relevante Ergebnis neu geprüft. |
| CF-040 | Entwicklungsserver und Vorschau [OW-T], [OW-B] | Offen | **M3.** Laufende Projekte und erzeugte Oberflächen zugänglich vorzeigen. Abnahme: Vorschau gehört zum richtigen Projekt, zeigt aktuelle Änderungen und endet kontrolliert mit dem zugehörigen Prozess. |

## 6. Tools, Skills und Plugins

| ID | Referenzfunktion / Quelle | CoreUI-Stand | Eigenes Soll mit Abnahme |
|---|---|---|---|
| CF-041 | Toolregister und gezieltes Finden [OC-T], [OW-X], [CR-X], [CR-N], [AG-A] | Teilbestand | **M2.** Werkzeuge mit Schema, Fähigkeiten und strukturierten Ergebnissen registrieren. Abnahme: Ein Werkzeug wird gezielt gefunden und validiert aufgerufen; unbekannte Namen oder Argumente führen nicht zur Ausführung. Erfolgreicher Transport, fachlicher Fehler und Verbindungsfehler sind getrennte Ergebnisse; Toolfilter und Cache-Regeln sind nachvollziehbar. |
| CF-042 | Skills als Vorgehensweisen [OC-S], [OW-S] | Offen | **M4.** Echte Skills mit Hauptanweisung und Ressourcen anlegen. Abnahme: Ein Skill lässt sich erstellen, bearbeiten und für eine Aufgabe nutzen; Werkzeuge und Plugins bleiben eigenständige Komponenten. |
| CF-043 | Begleitdateien und bedarfsabhängiges Laden [OC-S], [OW-S] | Offen | **M4.** Referenzen, Vorlagen, Skripte und Assets adressierbar beilegen. Abnahme: Benötigte Inhalte werden gezielt geladen; Ressourcen fehlen nicht stillschweigend und enthaltene Skripte starten nicht allein durch das Laden. |
| CF-044 | Skill-Suche, Geltungsbereich und Voraussetzungen [OC-S], [OW-S] | Offen | **M4.** Persönliche, gemeinsame und projektbezogene Skills auffindbar machen. Abnahme: Auswahl, Priorität und fehlende Voraussetzungen sind erkennbar; eine Sitzung nutzt die ausdrücklich bestimmte Variante. |
| CF-045 | Skill-Versionen und Konflikte [OC-S], [OW-S] | Offen | **M4.** Unveränderliche Revisionsstände, Vergleich und Wiederherstellung anbieten. Abnahme: Ein älterer Stand samt Ressourcen lässt sich wiederherstellen; gleichzeitige Änderungen überschreiben sich nicht unbemerkt. Ein Lauf behält seine ausgewählte Skill-Revision. |
| CF-046 | Skill-Import und Export [OC-S], [OW-S] | Offen | **M4.** Vollständige Skill-Pakete übertragen. Abnahme: Export und erneuter Import erhalten Anweisung, Ressourcen und Metadaten; ungültige Pakete und ausbrechende Dateipfade werden abgewiesen. |
| CF-047 | Skill-Rechte und kontrollierte Verbesserung [OC-S], [OW-S] | Offen | **M4.** Lesen, Ausführen, Ändern und Teilen getrennt regeln. Abnahme: Unberechtigte Änderungen scheitern; vorgeschlagene Verbesserungen zeigen einen Diff und können verworfen oder zurückgenommen werden. |
| CF-048 | Plugin-Lebenszyklus und Provider [OC-T], [OW-X], [CR-N], [AG-A] | Offen | **M4.** Plugins einschließlich Tools, Hooks und MCP-/OpenAPI-Anbindungen verwalten. Abnahme: Installieren, Aktualisieren, Aktivieren, Deaktivieren und Entfernen haben überprüfbare Wirkung; Fähigkeiten, Zusatzabhängigkeiten, Konfiguration und Zugriffe sind je Plugin erkennbar. Verbindungsausfälle verlieren keine Laufzuordnung; Installation allein erteilt keine Ausführungsrechte. |

## 7. Browser, Wissen und Gedächtnis

| ID | Referenzfunktion / Quelle | CoreUI-Stand | Eigenes Soll mit Abnahme |
|---|---|---|---|
| CF-049 | Websuche und Browserrecherche [OC-T], [OW-F], [C-R] | Teilbestand | **M5.** SearXNG und Playwright mit belastbaren Quellen und beobachtbaren Schritten weiterführen. Abnahme: Suchtreffer, gelesene Seite und Beleg sind zuordenbar; Browserzustand wird vor Folgeaktionen erneut geprüft. |
| CF-050 | Authentifizierte Browseraktionen [OC-T], [C-25] | Offen | **M5.** Formulare, Entwürfe und Vorschauen als gesonderten Aktionspfad umsetzen. Abnahme: Extern sichtbare Abschlussaktionen erfolgen erst nach der in #25 vorgesehenen ausdrücklichen Bestätigung; uneindeutige Sendefehler lösen keine Doppelübermittlung aus. |
| CF-051 | Browserübergabe und Sitzungsrechte [C-25] | Offen | **M5.** Autorisierte Sitzungen und menschliche Übergaben erhalten. Abnahme: MFA/CAPTCHA pausiert den Auftrag und erlaubt danach dessen Fortsetzung; Geheimnisse gelangen nicht in Modellkontext oder Aktionsprotokoll. |
| CF-052 | Wissensimport und Extraktion [OW-F], [C-R] | Teilbestand | **M5.** Dokumente und Sammlungen mit erkennbarer Extraktionsqualität aufnehmen. Abnahme: Text- und Scan-Dokumente sind unterscheidbar; unvollständige Verarbeitung und Herkunft bleiben beim späteren Abruf sichtbar. |
| CF-053 | RAG, Suche und Quellenbezug [OC-M], [OW-F], [C-R], [CR-K] | Teilbestand | **M5.** RAG-Lite um gezielte, hybride und bei Bedarf vollständige Kontextwege erweitern. Wissensquellen einzelnen Rollen oder Teams zuordnen und Index-/Embedding-Voraussetzungen festhalten. Abnahme: Antwortbelege führen zu tatsächlich verwendeten Quellen; fremde Benutzerbestände erscheinen nicht als Treffer, beteiligte Modelle nutzen die globale Warteschlange. |
| CF-054 | Wissenssynchronisation und externe Quellen [OW-R] | Ungeprüft | **M5.** Angebundene Wissensquellen mit ihren Zugriffsrechten synchronisieren. Abnahme: Änderung, Entfernung und entzogenes Zugriffsrecht werden nachvollzogen; veraltete oder unzugängliche Inhalte werden nicht still weiterverwendet. |
| CF-055 | Dauerhafte Erinnerungen und Suche [OC-M], [C-R], [CR-M], [LG-M], [AG-M] | Teilbestand | **M5.** Erinnerungen nach Benutzer und Projekt auffindbar, änderbar und löschbar machen; Laufzustand und dauerhafte Memory-Bereiche getrennt speichern. Abnahme: Eine Präferenz wird im zulässigen Bereich gefunden; gelöschte oder fremde Inhalte werden nicht abgerufen. Bereichsübergreifende Ansichten beachten Leserechte, Kontextübernahme und Schreibrechte bleiben getrennt. |
| CF-056 | Memory-Pflege und Zusammenfassungen [OC-M], [V], [CR-M], [LG-M] | Teilbestand | **M5.** Herkunft, Widersprüche und Lebensdauer von Erinnerungen behandeln. Abnahme: Zusammenführung und Korrektur sind nachvollziehbar; ein bestätigter Schreibabschluss ist vor davon abhängigem Lesen sichtbar, Speicherfehler gelten nicht als Erfolg. Jeder nötige Modellaufruf, einschließlich Embeddings und Hintergrundpflege, wartet in derselben seriellen Warteschlange. |

## 8. Multimodalität, Automationen und Kommunikationskanäle

| ID | Referenzfunktion / Quelle | CoreUI-Stand | Eigenes Soll mit Abnahme |
|---|---|---|---|
| CF-057 | Bild-, Video- und Dokumenteingabe [OC-F], [OW-R], [C-R] | Teilbestand | **M6.** Multimodale Eingaben mit erkennbarer Verarbeitungsreichweite anbieten. Stand 0.5.71: Bilder, Video-Einzelbilder und PDF-Seiten werden analysiert; Audio und Tonspur von Videos folgen mit P20.1 und P20.2 ([Sprachplan](COREUI_VOICE_PLAN.md)). Abnahme: Dateityp, verwendete Seiten beziehungsweise Frames und Fehler sind sichtbar; eine Stichprobe wird nicht als vollständige Analyse ausgegeben. |
| CF-058 | Spracheingabe, Ausgabe und Live-Medien [OC-F], [OW-R], [C-R] | Offen | **M6, vorgezogen (P20.0 bis P20.4, [Sprachplan](COREUI_VOICE_PLAN.md)).** Hören über Gemma 4 (e2b, e4b, 12b) via Ollama, eigene Mara-Stimme als Server-TTS mit Kernlimit, Vorlesen und Live-Gespräch mit Unterbrechen. Transkription, TTS und geeignete Live-Audio-/Videoabläufe anbinden. Stand 0.5.75: Hören über Ollama auf dem Server gemessen (25 Tokens je Sekunde Audio, Abschnitte bis 60 Sekunden, kurze Aufnahmen in Maras Persona unzuverlässig, Messprotokoll im Sprachplan), noch nicht im Produkt. Abnahme: Aufnahme, Unterbrechung und Wiedergabe funktionieren; beteiligte Modellaufrufe halten die gemeinsame Ausführungsgrenze ein. |
| CF-059 | Bild-, Video- und Musikgenerierung [OC-T], [OW-R] | Ungeprüft | **M6/P20.** Separate native CPU-Bildengine nach [Technikplan](COREUI_IMAGE_ENGINE_PLAN.md): stable-diffusion.cpp als pruefbare C/C++-Runtime-Referenz; FLUX.1-schnell und SDXL-Turbo als einzeln zu verifizierende Modellpfade. Modellgewichte/Encoder/VAE, SHA-256, Lizenz, CPU-Benchmarks, RAM-/Threadlimits, Provider-unabhaengige serielle Queue, Auftragsabbruch und echtes Chat-Artefaktregister. Bild-/Video-/Musikgenerierung getrennt abnehmen; keine behaupteten Performancewerte vor Live-Messung. |
| CF-060 | Einmalige und wiederkehrende Automationen [OC-A], [OW-R] | Offen | **M6.** Zeitpunkte, Intervalle, Cron und Webhooks mit Historie verwalten. Abnahme: Auslösen, Deaktivieren und Wiederaufnehmen sind nachvollziehbar; ausgelöste Modellarbeit wartet bei belegter Warteschlange. |
| CF-061 | Dauerhafte Aufträge, Hooks und Heartbeats [OC-A] | Offen | **M6.** Dauerhafte Anweisungen, ereignisgebundene Hooks und periodische Heartbeats als getrennte Mechanismen anbieten. Abnahme: Auslöser, Zustand und Wiederaufnahme sind je Mechanismus prüfbar; Neustart erzeugt keinen doppelten Abschluss, Abbruch und Fortschritt bleiben sichtbar. |
| CF-062 | Externe Kanäle, Direkt- und Gruppennachrichten [OC-F] | Ungeprüft | **M6.** Kanaladapter mit eindeutiger Identität, Zuordnung und Pairing anbieten. Abnahme: Antworten erreichen den autorisierten Kanal; Gruppen, Konten und Sitzungen bleiben getrennt, Modellarbeit läuft seriell. |
| CF-063 | Weitere Clients und Gerätefunktionen [OC-R], [OC-F] | Ungeprüft | **M6.** Web, CLI/TUI, mobile Clients und Geräte-Nodes einzeln inventarisieren. Abnahme: Pro Funktion sind eigener Zugang, Berechtigung und Synchronisation nachgewiesen; kein Client umgeht die gemeinsame Modellwarteschlange. |
| CF-064 | Kalender, Erinnerungen und Aufgaben [OW-R], [OC-A] | Ungeprüft | **M6.** Persönliche und gemeinsame Termine sowie Aufgaben integrieren. Abnahme: Zeitzone, Wiederholung, Änderung und Löschung stimmen; Erinnerungen bleiben dem richtigen Benutzer beziehungsweise freigegebenen Arbeitsraum zugeordnet. |

## 9. Administration, Betrieb und eigene Produktvorhaben

| ID | Referenzfunktion / Quelle | CoreUI-Stand | Eigenes Soll mit Abnahme |
|---|---|---|---|
| CF-065 | Rollen, Gruppen und Ressourcenrechte [OW-F], [C-R] | Teilbestand | **M6.** Vorhandene Kontotrennung auf Projekte, Skills, Tools und Freigaben ausdehnen. Abnahme: Rechteentzug wirkt auch auf bereits bekannte IDs und direkte API-Aufrufe. |
| CF-066 | Anmeldung und Identitätsanbindungen [OW-F] | Ungeprüft | **M6.** SSO/OIDC, LDAP, SCIM, MFA und API-Schlüssel einzeln prüfen und anbinden. Abnahme: Aktivierung, Widerruf und Kontenzuordnung sind pro unterstütztem Weg nachvollziehbar; deaktivierte Zugänge verlieren ihre Wirkung. |
| CF-067 | Nutzungsanalyse, Traces und Evaluation [OW-R], [CR-V], [CR-O], [AG-V], [AG-B] | Ungeprüft | **M6.** Laufzeiten, Fehler, Token/Kosten und vergleichbare Bewertungen erfassen. Szenarien mit dokumentiertem Ausgangszustand, Modell, Definition und Bewertungsregel wiederholen; betriebliche Traces einzelnen Läufen zuordnen. Abnahme: Messwerte stammen aus realen Läufen, Vergleichsergebnisse nennen ihre Bedingungen; Modellvergleiche laufen nacheinander. Lokale Auswertung aktiviert weder externe Übertragung noch die optionale Erfassung aus CF-071. |
| CF-068 | Installation, Sicherung und Betrieb [OW-R], [C-R] | Teilbestand | **M6.** Konfiguration, Sicherung, Wiederherstellung, Diagnose und Speicherbetrieb weiterführen. Abnahme: Wiederherstellung erhält Benutzer, Projekte und Artefakte; zusätzliche Prozesse erhöhen die Zahl gleichzeitiger Modellanfragen nicht. |
| CF-069 | Mobile Oberfläche, Bedienbarkeit und Sprachen [OW-R], [C-R] | Teilbestand | **M6.** Kernabläufe mobil und per Tastatur nutzbar machen; Sprach- und PWA-Umfang inventarisieren. Abnahme: Chat, Fortschritt und Dateien funktionieren auf kleinem Bildschirm ohne verdeckte Hauptaktionen. |
| CF-070 | Branding-Integrität [C-23] | Offen | **M6.** Nur ausdrücklich geschützte Dateien und definierte Marker prüfen. Abnahme: Fehlende Marker erzeugen ein dedupliziertes Ereignis und eine konfigurierte Hinweismail mit Metadaten; keine globale Durchsuchung privater Inhalte. |
| CF-071 | Optionale Installationserfassung [C-24] | Offen | **M6.** Das separate, zustimmungsabhängige Installations-/Adoptionsvorhaben erhalten. Abnahme: Ohne Opt-in keine Erfassung; Deaktivierung schränkt CoreUI nicht ein, das Dashboard zeigt nur vorgesehene Aggregate. |
| CF-072 | Tool-Policy, Isolation und Audit [OC-T], [C-M], [C-25] | Teilbestand | **M2/M6.** Serverseitige Rechte, Ressourcengrenzen und nachvollziehbare Aktionen vereinheitlichen. Abnahme: Ein Toolaufruf außerhalb seiner Befugnisse scheitert; das Protokoll erklärt die Entscheidung, ohne Geheimnisse zu speichern. |

## 10. Teams, Zustandsgraphen und Agentenentwicklung

Diese zusätzlichen Einträge sind eigene Anforderungen aus der gezielten Startprüfung von CrewAI, LangGraph und AutoGen. Sie schließen weder die Tiefeninventur noch vorhandene Funktionen als erledigt ab. CF-075/076 betreffen den Agentenlauf; Git-Wiederherstellung unter CF-038 bleibt ein gesonderter Mechanismus.

| ID | Referenzfunktion / Quelle | CoreUI-Stand | Eigenes Soll mit Abnahme |
|---|---|---|---|
| CF-073 | Versionierte Agenten-, Aufgaben- und Teamdefinitionen [CR-T], [AG-J], [LG-G] | Offen | **M2.** Rollen, Ziele, Ressourcen, Aufgabenabhängigkeiten und strukturierte Ein-/Ausgabeformate versionieren. Definitionsexport und Laufzustand getrennt behandeln. Abnahme: Export/Import erhält dieselbe Definition ohne eingebettete Geheimnisse; ungültige Verweise werden erkannt. Ein aktiver Lauf behält seinen Stand; inkompatible Definitionen oder Zustandsschemata verlangen einen geprüften Übergang oder begründeten Stopp. |
| CF-074 | Zustandsgraphen, Übergänge und Unterabläufe [CR-F], [LG-G], [LG-U] | Offen | **M2.** Schritte, feste/bedingte Kanten, Schleifen und Warten auf eines oder mehrere Ergebnisse darstellen. Zustandsfelder, Zusammenführungsregeln sowie Ein-/Ausgaben und Lebensdauer von Unterabläufen definieren. Abnahme: Ein verzweigter Beispielablauf folgt nachvollziehbaren Regeln; Unterzustände bleiben richtig zugeordnet und alle bereiten Modellschritte werden global seriell abgearbeitet. |
| CF-075 | Dauerhafte Checkpoints und Laufzustand [CR-C], [LG-P], [AG-P] | Offen | **M2.** Bestätigten Zustand, erledigte Schritte, übernommene Ergebnisse, nächste Arbeit, Definition und Herkunft dauerhaft sichern. Abnahme: Nach Prozessneustart wird vom letzten bestätigten Stand fortgesetzt; Speicherfehler zeigen die tatsächliche Wiederherstellungsgrenze. Verlauf und Konfiguration allein gelten nicht als vollständiger Checkpoint; Benutzer- und Unterlaufgrenzen bleiben erhalten. |
| CF-076 | Zustandsinspektion, Replay und Lauf-Forks [CR-C], [LG-T] | Offen | **M2.** Einen gespeicherten Zwischenstand lesen und als neuen Laufzweig mit optional geändertem Ergebnis fortsetzen. Abnahme: Originalhistorie bleibt erhalten, Herkunft und Änderungen sind sichtbar; abhängige Ergebnisse werden als neu zu berechnen markiert. Erneute Modell-/API-Aufrufe sind vor dem Start erkennbar und beachten Auftrag, Rechte und Wiederholungsregeln; Replay wird nicht als bloße Cache-Anzeige ausgegeben. |
| CF-077 | Dauerhafte Schritte, Wiederholungen und externe Effekte [LG-D], [LG-F], [LG-I] | Offen | **M2.** Schrittgrenzen, Ergebnisübernahme, Wiederholungslimits, Zeitlimits und Fehlerpfade definieren. Abnahme: Abstürze vor und nach Speicherung eines Ergebnisses führen zu nachvollziehbarem Wiederanlauf. Bestätigte Ergebnisse werden wiederverwendet; unklare externe Effekte werden vor erneutem Ausführen abgeglichen. Checkpointing allein verspricht keine Einmalausführung, und ein Timeout gibt keinen ungeklärt aktiven Modellplatz frei. |
| CF-078 | Allgemeine menschliche Reviews und Wiederaufnahme [CR-H], [LG-I], [AG-I] | Offen | **M2.** Konkrete Ergebnisse mit Anfrage-ID, Revision und zulässigen Antworten zur Rückmeldung vorlegen. Warten dauerhaft speichern und den Modellplatz nach bestätigtem Ende des Aufrufs freigeben. Abnahme: Freigabe, Ablehnung oder Überarbeitung funktionieren nach Reload/Neustart; veraltete, doppelte oder unberechtigte Antworten setzen nichts erneut in Gang. Bereits ausreichende Auftragsfreigaben werden berücksichtigt. |
| CF-079 | Ergebnisvalidierung und begrenzte Überarbeitung [CR-T] | Offen | **M2.** Schema- und Qualitätsprüfungen mit begründetem Ergebnis sowie begrenzten Korrekturschleifen anbieten. Code- und modellgestützte Prüfungen getrennt kennzeichnen. Abnahme: Ein fehlerhaftes Ergebnis wird korrigiert oder mit Prüfgrund beendet; Schleifenlimit und Herkunft der Bewertung bleiben sichtbar. Modellprüfer nutzen die globale Warteschlange; positive Bewertung erteilt keine zusätzlichen Toolrechte. |
| CF-080 | Teamsteuerung, Sprecherwahl und Übergaben [AG-T], [AG-S], [AG-H], [AG-E], [CR-R] | Offen | **M2.** Feste Reihenfolge, regel-/modellgestützte Auswahl, Managerrollen, zulässige Übergaben und kombinierte Stoppbedingungen anbieten. Abnahme: Auswahl und Rückgabe sind nachvollziehbar; ungültige Ziele, Nachrichten-/Zeit-/Tokenlimits und ausbleibende Ergebnisse führen zu definiertem Verhalten. Auch Manager und Sprecherwahl erzeugen keine zweite gleichzeitige Modellanfrage. |
| CF-081 | Visueller Team-/Ablaufeditor und Komponentensammlung [AG-U], [AG-J] | Offen | **M6.** Teams und Abläufe visuell sowie in einer gleichwertigen deklarativen Ansicht bearbeiten; versionierte Komponenten suchen, wiederverwenden, importieren und exportieren. Abnahme: Wechsel der Ansichten erhält Bedeutung und Version, fehlerhafte Verbindungen werden erkannt. Öffnen/Import startet keinen fremden Code; Kernaktionen funktionieren auch ohne Drag-and-drop auf dem Handy. |
| CF-082 | Playground und interaktive Laufinspektion [AG-U], [LG-T], [LG-S] | Offen | **M6.** Ausgewählte Definitionen in einem zugeordneten Testarbeitsraum ausführen und reale Schritte, Zustände, Fehler, Wartepunkte sowie Ergebnisse untersuchen. Abnahme: Unterbrechen, Rückmeldung und kontrolliertes Fortsetzen entsprechen der geprüften Definition; Testdaten und produktive Ressourcen bleiben erkennbar getrennt. Artefakte sind echte Downloads, Modellarbeit bleibt global seriell; wiederholbare Bewertungen verwenden CF-067. |

## Belegter CoreUI-Ausgangspunkt

Der geprüfte Quellstand ist `6ab7d2f54b61409cbd817cdf0b6f3db6a208e51e`. `VERSION` beziehungsweise Installer verweisen auf `0.5.3-alpha`; die bekannte Datenbankbasis reicht bis Migration `009`. Das ist ein beobachteter Ausgangspunkt, keine in diesem Dokument neu festgelegte Release- oder Migrationsplanung. Ein installiertes System wurde im Rahmen dieser Matrix nicht als identisch bestätigt.

Belegt sind private Sitzungen, Chat- und Revisionsfunktionen, registrierte Upload-Medien mit Eigentümerprüfung, benutzerbezogenes RAG-Lite, lokales Langzeitgedächtnis, SearXNG-/Playwright-Recherche, ein isolierter Python-Worker, multimodale Uploads und exakte Ganzzahlrechnung. Diese Teilbestände tragen die entsprechende Statuskennzeichnung; sie beweisen keine vollständige Abdeckung der Referenzfunktionen. [C-R], [C-C], [C-P], [C-M], [C-U]

Die Grenzen sind für die Planung wesentlich:

- Der bestehende Python-Pfad erlaubt bewusst einen einzelnen Werkzeugaufruf pro Turn und keine allgemeine Reparaturschleife. Das ist vom Verbot gleichzeitiger Modellanfragen zu unterscheiden: Mehrere **nacheinander** ausgeführte Schritte sind das neue Ziel. [C-C], [C-P]
- Die Fortschrittsausgabe in `api/chat.php` verwendet feste öffentliche Statussätze. Der Renderer unterdrückt rohe Thinking-/Token-Ereignisse. Eine neue Anzeige benötigt getrennte, tatsächlich verfügbare Ereignistypen. [C-C], [C-U]
- Der Python-Worker gibt Textausgaben und Laufdaten zurück und entfernt sein temporäres `/work`-Verzeichnis. Die Übernahme und Registrierung erzeugter Dateien vor dem Aufräumen fehlt. [C-P], [C-C]
- Der vorhandene Medienendpunkt kann registrierte Dateien mit Eigentümerprüfung ausliefern. Diese Kontoprüfung ist keine zusätzliche eigenständige Sitzungsprüfung und macht unregistrierte Python-Ausgaben noch nicht herunterladbar. [C-M]
- Ein allgemeiner Projekt-/IDE-Baustein und ein vollständiger Skill-Loader sind in der geprüften Basis nicht als bestehende Komponenten belegt. Die Matrix bewertet die entsprechenden neuen Bausteine als offene Arbeit.
- Die Download-Ursache wurde im Quellcode analysiert. Eine zusätzliche Reproduktion in einer laufenden CoreUI-Installation und die Abnahme des Fixes stehen aus. [C-29]

## Bestehende Sondervorhaben bleiben verbindlich

**[#25][C-25] Browseraktionen:** Eigener Backlog für kontrollierte Schreibaktionen, autorisierte Browsersitzungen, Vorschau, Bestätigung, menschliche Übergabe und Audit. Die dort ausgeschlossenen automatischen CAPTCHA-Lösungen, unbeaufsichtigten Käufe und verdeckten Veröffentlichungen werden durch die Referenzinventur nicht freigegeben. Die Recherchefunktion allein gilt nicht als Umsetzung dieses Vorhabens.

**[#23][C-23] Branding:** Schutzregeln gelten ausschließlich für ausdrücklich bezeichnete Dateien oder Vorlagen, einschließlich vorgesehener Ausnahmen. Benachrichtigung und Audit bleiben begrenzt; private Inhaltsbestände werden nicht allgemein durchsucht. Empfänger und Zugangsdaten gehören in die Konfiguration, nicht in öffentliche Quellen.

**[#24][C-24] Installationserfassung:** Eigenständiger, ausdrücklich optionaler Plan mit gesonderter Zustimmung, minimalen Daten und aggregierter Anzeige. Downloadzahlen sind keine Installationszahlen. Die aktuelle Lizenz bleibt unverändert; daraus entsteht keine Lizenztelemetrie, kein heimliches Tracking und kein Phone-home im bestehenden Produkt. Die Zustimmung zu dieser Planungsdokumentation aktiviert keine Erfassung.

## Quellenregister

Die Quellenkürzel in den Tabellen führen direkt zu den folgenden Primärquellen. „Gelesen“ bezeichnet Quellenprüfung, keine vollständige Ausführung oder Prüfung jeder beschriebenen Implementierung.

### OpenClaw, festgeschriebener Quellstand

Repository: [openclaw/openclaw](https://github.com/openclaw/openclaw). Gepinnter Commit: [`54417c68199f58e9b78fddff95c1ae6b610845fe`](https://github.com/openclaw/openclaw/commit/54417c68199f58e9b78fddff95c1ae6b610845fe), am 20.10.2026 verifiziert.

- **[OC-R]**: README und Projektumfang.
- **[OC-F]**: Offizielle Funktionsfamilien in `docs/concepts/features.md`.
- **[OC-T]**: Werkzeuge, Tool-Policy und Erweiterungsansätze in `docs/tools/index.md`.
- **[OC-S]**: Skills in `docs/tools/skills.md`.
- **[OC-M]**: Gedächtnis in `docs/concepts/memory.md`.
- **[OC-W]**: Arbeitsbereiche in `docs/concepts/agent-workspace.md`.
- **[OC-G]**: Verwaltete Arbeitsstände in `docs/concepts/managed-worktrees.md`.
- **[OC-P]**: Fortschrittsansicht in `docs/tools/progress-card.md`.
- **[OC-H]**: Denkaufwand und Darstellung in `docs/tools/thinking.md`.
- **[OC-A]**: Automationen in `docs/automation/index.md`.

Grenze: Diese Quellen belegen Funktionsfamilien und dokumentierte Abläufe. Die Einzelimplementierungen wurden nicht vollständig auditiert. Code Mode und Tool Search werden in der OpenClaw-Dokumentation ausdrücklich als experimentell eingeordnet; eine Aufnahme in den Zielkatalog ist keine Reifebestätigung. Die in OpenClaw angebotene Delegation wird in CoreUI unter der eigenen seriellen Modellregel umgesetzt.

### Open WebUI, festgeschriebene README und aktuelle Dokumentation

Repository: [open-webui/open-webui](https://github.com/open-webui/open-webui). Gepinnter Commit: [`91ea4e3421d1d8069f13d4a463fcb5986d94ea60`](https://github.com/open-webui/open-webui/commit/91ea4e3421d1d8069f13d4a463fcb5986d94ea60), Commit-Zeitpunkt 16.10.2026. Die README und der vollständige rekursive Dateibaum wurden an diesem Stand gelesen.

- **[OW-R]**: Gepinnte README.
- **[OW-F]**: Offizielle Funktionsübersicht.
- **[OW-W]**: Workspace.
- **[OW-C]**: Chat und Gespräche.
- **[OW-S]**: Skills.
- **[OW-X]**: Tools und Functions als Erweiterungen.
- **[OW-T]**: Open Terminal und Ausführungsumgebung.
- **[OW-B]**: Dateibrowser, Dateikarten und Downloads.
- **[OW-H]**: Reasoning-Anzeige und Modelleinstellungen.

**Abruf aller verlinkten Live-Dokumentationsseiten: 20.10.2026.** Diese Seiten sind nicht auf den README-Commit festgeschrieben und können neuere oder abweichende Funktionen beschreiben. Insbesondere differieren veröffentlichte Integrationszahlen zwischen README, Dokumentation und vorhandenen Adapterpfaden. Deshalb enthält die Matrix keine pauschalen Zahlenversprechen.

Im gepinnten Dateibaum sind unter anderem `backend/open_webui/models/skills.py`, `backend/open_webui/routers/skills.py`, `backend/open_webui/utils/skills.py` und `src/lib/components/workspace/Skills.svelte` vorhanden. Das ist ein Strukturbeleg, keine vollständige Prüfung der Skill-Implementierung.

Die Begleitprojekte [Open Terminal](https://github.com/open-webui/open-terminal), [Open WebUI Computer](https://github.com/open-webui/computer), [Terminals](https://github.com/open-webui/terminals), [OIKB](https://github.com/open-webui/oikb) und [Desktop](https://github.com/open-webui/desktop) sind **noch nicht eigenständig auditiert oder gepinnt**. Funktionen aus diesen Projekten sowie Enterprise-Voraussetzungen bleiben gesondert zu prüfen. Der Verweis in der README oder Live-Dokumentation ersetzt diese Prüfung nicht.


### CrewAI, festgeschriebener Kern und Edge-Dokumentation

Repository: [crewAIInc/crewAI](https://github.com/crewAIInc/crewAI). Gepinnter Commit: [`4f4573706ea4afee6c4da4c31cfb9622a4495ab7`](https://github.com/crewAIInc/crewAI/commit/4f4573706ea4afee6c4da4c31cfb9622a4495ab7), 20.10.2026. Geprüft wurden die folgenden offiziellen Dokumentationsbereiche und punktuell die Aufgaben-/Prozessimplementierung; keine vollständige Ausführungsprüfung.

- **[CR-R], [CR-L]:** Projektumfang und MIT-Lizenz.
- **[CR-T], [CR-F], [CR-C], [CR-H]:** Aufgaben und Ergebnisprüfungen, zustandsbehaftete Flows, Checkpoints/Forks und menschliche Rückmeldung.
- **[CR-M], [CR-K], [CR-X], [CR-N]:** Memory, Wissen, Tools und MCP.
- **[CR-E], [CR-V], [CR-O]:** Lebenszyklusereignisse, wiederholte Tests und Tracing.

**Beleggrenze:** `docs/edge` dokumentiert den festgehaltenen Entwicklungsstand; damit ist nicht jede Funktion in jeder veröffentlichten Version bestätigt. Die README trennt den offenen Kern von der **kommerziellen AMP Suite**. Das integrierte Tracing-Dashboard nutzt AMP; eigene lokale Ereignisaufzeichnung wird unter CF-021/022/067 geplant. Externe Dienste, einzelne Integrationen und das gesonderte [CrewAI-Skills-Repository](https://github.com/crewAIInc/skills) brauchen eigene Inventur- und Lizenzbelege. Referenz-Defaults für Hintergrundmodelle oder Telemetrie ändern CF-017/071 nicht. [CR-R], [CR-O]

### LangGraph, festgeschriebener Kern und getrennte Dokumentation

Repository: [langchain-ai/langgraph](https://github.com/langchain-ai/langgraph). Gepinnter Commit: [`a24fc97778fca67cfac4cd3fc63e508ee1d29f59`](https://github.com/langchain-ai/langgraph/commit/a24fc97778fca67cfac4cd3fc63e508ee1d29f59), 20.10.2026. Die offizielle ausgelagerte Dokumentation in [langchain-ai/docs](https://github.com/langchain-ai/docs) wurde separat auf [`332b12ee879b1d04ec47dec765627c009eb84b7a`](https://github.com/langchain-ai/docs/commit/332b12ee879b1d04ec47dec765627c009eb84b7a), ebenfalls 20.10.2026, festgeschrieben.

- **[LG-R], [LG-L]:** Projektumfang und MIT-Lizenz.
- **[LG-G], [LG-U]:** Graphen, Zustand, Übergänge, Zusammenführung, Definitionsänderungen und Untergraphen.
- **[LG-P], [LG-D], [LG-I], [LG-T], [LG-F]:** Checkpoints, dauerhafte Schritte, Unterbrechungen, Replay/Forks und Fehler-/Stoppregeln.
- **[LG-S], [LG-M], [LG-O]:** Ereignisströme, getrennte Memory-Stores und Produktgrenzen laut LangSmith-FAQ.

**Beleggrenze:** Kernquellen und zentrale Mechanismen wurden gezielt gelesen; APIs, Sprachvarianten und Integrationen sind noch nicht vollständig inventarisiert oder ausgeführt. Die MIT-Dateien des [Python-Kerns](https://github.com/langchain-ai/langgraph/blob/a24fc97778fca67cfac4cd3fc63e508ee1d29f59/libs/langgraph/LICENSE), [Python-SDK](https://github.com/langchain-ai/langgraph/blob/a24fc97778fca67cfac4cd3fc63e508ee1d29f59/libs/sdk-py/LICENSE) und der [CLI](https://github.com/langchain-ai/langgraph/blob/a24fc97778fca67cfac4cd3fc63e508ee1d29f59/libs/cli/LICENSE) sind geprüft. **LangSmith ist laut offizieller FAQ proprietär**; die Lizenzen von Kern oder Client gelten nicht automatisch für API-Server, Studio und verwaltete Dienste. Diese Produktteile werden unter CF-007 einzeln eingeordnet. [LG-R], [LG-O]

**Relevante Semantik:** Wiederaufnahme kann Code ab einer Schrittgrenze erneut ausführen; ein Interrupt startet den betroffenen Node erneut. Replay führt spätere Nodes einschließlich Modell-/API-Aufrufen erneut aus. Ein Checkpoint allein garantiert deshalb keine genau einmal ausgeführte externe Aktion. LangGraph erlaubt parallele Zweige; Ember implementiert die Abläufe unter seiner eigenen globalen seriellen Modellregel. [LG-D], [LG-I], [LG-T], [LG-G]

### Microsoft AutoGen, festgeschriebene Komponenten und Wartungshinweis

Repository: [microsoft/autogen](https://github.com/microsoft/autogen). Gepinnter Commit: [`004749e6b7102c8fd317b9b1499ce5807223c278`](https://github.com/microsoft/autogen/commit/004749e6b7102c8fd317b9b1499ce5807223c278), 16.10.2026; Abruf 20.10.2026.

- **[AG-R], [AG-L], [AG-D]:** README, MIT-Lizenz des Codes und CC BY 4.0 für Dokumentation/sonstige Inhalte.
- **[AG-C], [AG-T], [AG-S], [AG-H], [AG-E]:** Core, Teams, Sprecherwahl, Handoffs und kombinierbare Stoppbedingungen.
- **[AG-I], [AG-P], [AG-J], [AG-M]:** Menschliche Rückmeldung, Laufzustand, deklarative Komponenten und Memory.
- **[AG-X], [AG-A]:** CodeExecutors sowie konkrete Extensions mit zusätzlichen Voraussetzungen.
- **[AG-U], [AG-Q], [AG-V], [AG-B]:** Studio-Bedienung und Reifegrad, Tracing sowie AutoGenBench.

**Lizenzgrenze:** Die README ordnet Code MIT und Dokumentation/sonstige Inhalte CC BY 4.0 zu. Eigene MIT-Dateien sind außerdem für [Studio](https://github.com/microsoft/autogen/blob/004749e6b7102c8fd317b9b1499ce5807223c278/python/packages/autogen-studio/LICENSE-CODE) und [AutoGenBench](https://github.com/microsoft/autogen/blob/004749e6b7102c8fd317b9b1499ce5807223c278/python/packages/agbench/LICENSE-CODE) bestätigt. Daraus folgt keine pauschale MIT-Lizenz für optionale Abhängigkeiten, Dienste, Modelle oder Datensätze. Unsere eigenen Implementierungen übernehmen keinen Code, Fremdtext oder Assets. [AG-R], [AG-L], [AG-D]

**Wartungsgrenze:** Die aktuelle Root-README meldet keine weitere Microsoft-Wartung und bezeichnet AutoGen als archiviert. Gleichzeitig zeigte das GitHub-Metadatenfeld `archived: false`; ältere README-/Studio-Abschnitte nennen noch Wartung oder aktive Entwicklung. Der dokumentierte Widerspruch bleibt sichtbar. AutoGen bleibt gemäß Auftrag Referenz; Microsoft Agent Framework wird durch den Nachfolgerhinweis nicht automatisch ergänzt oder an seine Stelle gesetzt. [AG-R], [AG-Q]

**Funktionsgrenzen:** Studio ist als Forschungs-/Prototypwerkzeug beschrieben und belegt keine vollständige produktive Rechteverwaltung. AutoGenBench dokumentiert wiederholbare Szenarien, enthält jedoch ältere AutoGen-0.1/0.2- und Installationshinweise; aktuelle Gesamtkompatibilität wurde nicht geprüft. Ein während eines Laufs blockierender UserProxy-Aufruf ist laut Dokumentation kein stabil speicherbarer Wartezustand. CoreUI braucht den dauerhaften Anfrage-/Resume-Vertrag aus CF-078. Python-, .NET-, Studio- und weitere Erweiterungsfunktionen bleiben Teil der offenen Tiefeninventur. [AG-Q], [AG-B], [AG-I]

### CoreUI und eigene Vorgaben

- **[C-R]**: README an `6ab7d2f54b61409cbd817cdf0b6f3db6a208e51e`.
- **[C-C]**: `api/chat.php` am selben Commit.
- **[C-P]**: `tools/ember_py_worker.py` am selben Commit.
- **[C-M]**: `api/console_media.php` am selben Commit.
- **[C-U]**: `js/console-app.js` am selben Commit.
- **[C-23]**: Bestehendes Branding-Integrity-Vorhaben.
- **[C-24]**: Bestehendes optionales Installations-/Adoptionsvorhaben.
- **[C-25]**: Bestehender Browser-Action-Backlog.
- **[C-29]**: Konkreter Download-Bug und künftige Abnahme.
- **[V]**: Verbindliche Nutzer- und Produktvorgaben aus dieser Planung, keine Behauptung über eine Referenzimplementierung.

## Nächster Inventurschritt

In M0 wird jede Zeile auf konkrete Referenzoberflächen, Quellpfade und dokumentierte Voraussetzungen heruntergebrochen. In M7 werden die so entstandenen Einzelfälle gegen reale CoreUI-Abnahmen geprüft. Zusätzliche Funktionen werden ergänzt. Ungeprüfte Einträge werden nur mit neuer Evidenz umgestuft; die bloße Existenz dieses Plans schließt keine Lücke.

Dabei sind insbesondere Promptvorlagen mit Variablen und Import/Export, die getrennten Erweiterungsarten Pipes/Filters/Actions, programmatische Werkzeugkombinationen sowie konkrete Provider-, Kanal- und Clientfunktionen weiter aufzugliedern. Diese Unterfunktionen bleiben Teil des Ziels; die Sammelzeilen CF-004, CF-041, CF-048 und CF-062 bis CF-064 gelten dafür noch nicht als vollständige Einzelinventur. Der experimentelle Referenzstatus von Code Mode und Tool Search wird bei der Bewertung beibehalten. [OC-T], [OW-W], [OW-X]

Für CrewAI werden außerdem konkrete Prozesse, A2A, Integrationen und externe Skill-Ressourcen einzeln aufgegliedert. Bei LangGraph folgen APIs, Sprach-/Versionsunterschiede, Speicheradapter und Produktgrenzen. Bei AutoGen folgen sämtliche Core-/AgentChat-/Extensions-, Python-/.NET- und Studio-Unterfunktionen. Überlappungen erhalten zusätzliche Quellen und Unterabnahmen bei bestehenden IDs; eigenständige Lücken erhalten neue IDs. Die gezielte Prüfung für CF-073 bis CF-082 ist kein Abschluss dieses Gesamtauftrags. [CR-R], [LG-R], [AG-R]

[CR-R]: https://github.com/crewAIInc/crewAI/blob/4f4573706ea4afee6c4da4c31cfb9622a4495ab7/README.md
[CR-L]: https://github.com/crewAIInc/crewAI/blob/4f4573706ea4afee6c4da4c31cfb9622a4495ab7/LICENSE
[CR-T]: https://github.com/crewAIInc/crewAI/blob/4f4573706ea4afee6c4da4c31cfb9622a4495ab7/docs/edge/en/concepts/tasks.mdx
[CR-F]: https://github.com/crewAIInc/crewAI/blob/4f4573706ea4afee6c4da4c31cfb9622a4495ab7/docs/edge/en/concepts/flows.mdx
[CR-C]: https://github.com/crewAIInc/crewAI/blob/4f4573706ea4afee6c4da4c31cfb9622a4495ab7/docs/edge/en/concepts/checkpointing.mdx
[CR-H]: https://github.com/crewAIInc/crewAI/blob/4f4573706ea4afee6c4da4c31cfb9622a4495ab7/docs/edge/en/learn/human-feedback-in-flows.mdx
[CR-M]: https://github.com/crewAIInc/crewAI/blob/4f4573706ea4afee6c4da4c31cfb9622a4495ab7/docs/edge/en/concepts/memory.mdx
[CR-K]: https://github.com/crewAIInc/crewAI/blob/4f4573706ea4afee6c4da4c31cfb9622a4495ab7/docs/edge/en/concepts/knowledge.mdx
[CR-X]: https://github.com/crewAIInc/crewAI/blob/4f4573706ea4afee6c4da4c31cfb9622a4495ab7/docs/edge/en/concepts/tools.mdx
[CR-N]: https://github.com/crewAIInc/crewAI/blob/4f4573706ea4afee6c4da4c31cfb9622a4495ab7/docs/edge/en/mcp/overview.mdx
[CR-E]: https://github.com/crewAIInc/crewAI/blob/4f4573706ea4afee6c4da4c31cfb9622a4495ab7/docs/edge/en/concepts/event-listener.mdx
[CR-V]: https://github.com/crewAIInc/crewAI/blob/4f4573706ea4afee6c4da4c31cfb9622a4495ab7/docs/edge/en/concepts/testing.mdx
[CR-O]: https://github.com/crewAIInc/crewAI/blob/4f4573706ea4afee6c4da4c31cfb9622a4495ab7/docs/edge/en/observability/tracing.mdx
[LG-R]: https://github.com/langchain-ai/langgraph/blob/a24fc97778fca67cfac4cd3fc63e508ee1d29f59/README.md
[LG-L]: https://github.com/langchain-ai/langgraph/blob/a24fc97778fca67cfac4cd3fc63e508ee1d29f59/LICENSE
[LG-G]: https://github.com/langchain-ai/docs/blob/332b12ee879b1d04ec47dec765627c009eb84b7a/src/oss/langgraph/graph-api.mdx
[LG-P]: https://github.com/langchain-ai/docs/blob/332b12ee879b1d04ec47dec765627c009eb84b7a/src/oss/langgraph/checkpointers.mdx
[LG-D]: https://github.com/langchain-ai/docs/blob/332b12ee879b1d04ec47dec765627c009eb84b7a/src/oss/langgraph/functional-api.mdx
[LG-I]: https://github.com/langchain-ai/docs/blob/332b12ee879b1d04ec47dec765627c009eb84b7a/src/oss/langgraph/interrupts.mdx
[LG-U]: https://github.com/langchain-ai/docs/blob/332b12ee879b1d04ec47dec765627c009eb84b7a/src/oss/langgraph/use-subgraphs.mdx
[LG-S]: https://github.com/langchain-ai/docs/blob/332b12ee879b1d04ec47dec765627c009eb84b7a/src/oss/langgraph/streaming.mdx
[LG-M]: https://github.com/langchain-ai/docs/blob/332b12ee879b1d04ec47dec765627c009eb84b7a/src/oss/langgraph/stores.mdx
[LG-T]: https://github.com/langchain-ai/docs/blob/332b12ee879b1d04ec47dec765627c009eb84b7a/src/oss/langgraph/use-time-travel.mdx
[LG-F]: https://github.com/langchain-ai/docs/blob/332b12ee879b1d04ec47dec765627c009eb84b7a/src/oss/langgraph/fault-tolerance.mdx
[LG-O]: https://github.com/langchain-ai/docs/blob/332b12ee879b1d04ec47dec765627c009eb84b7a/src/langsmith/faq.mdx
[AG-R]: https://github.com/microsoft/autogen/blob/004749e6b7102c8fd317b9b1499ce5807223c278/README.md
[AG-L]: https://github.com/microsoft/autogen/blob/004749e6b7102c8fd317b9b1499ce5807223c278/LICENSE-CODE
[AG-D]: https://github.com/microsoft/autogen/blob/004749e6b7102c8fd317b9b1499ce5807223c278/LICENSE
[AG-C]: https://github.com/microsoft/autogen/blob/004749e6b7102c8fd317b9b1499ce5807223c278/python/docs/src/user-guide/core-user-guide/index.md
[AG-T]: https://github.com/microsoft/autogen/blob/004749e6b7102c8fd317b9b1499ce5807223c278/python/docs/src/user-guide/agentchat-user-guide/tutorial/teams.ipynb
[AG-S]: https://github.com/microsoft/autogen/blob/004749e6b7102c8fd317b9b1499ce5807223c278/python/docs/src/user-guide/agentchat-user-guide/selector-group-chat.ipynb
[AG-H]: https://github.com/microsoft/autogen/blob/004749e6b7102c8fd317b9b1499ce5807223c278/python/docs/src/user-guide/agentchat-user-guide/swarm.ipynb
[AG-E]: https://github.com/microsoft/autogen/blob/004749e6b7102c8fd317b9b1499ce5807223c278/python/docs/src/user-guide/agentchat-user-guide/tutorial/termination.ipynb
[AG-I]: https://github.com/microsoft/autogen/blob/004749e6b7102c8fd317b9b1499ce5807223c278/python/docs/src/user-guide/agentchat-user-guide/tutorial/human-in-the-loop.ipynb
[AG-P]: https://github.com/microsoft/autogen/blob/004749e6b7102c8fd317b9b1499ce5807223c278/python/docs/src/user-guide/agentchat-user-guide/tutorial/state.ipynb
[AG-J]: https://github.com/microsoft/autogen/blob/004749e6b7102c8fd317b9b1499ce5807223c278/python/docs/src/user-guide/agentchat-user-guide/serialize-components.ipynb
[AG-M]: https://github.com/microsoft/autogen/blob/004749e6b7102c8fd317b9b1499ce5807223c278/python/docs/src/user-guide/agentchat-user-guide/memory.ipynb
[AG-X]: https://github.com/microsoft/autogen/blob/004749e6b7102c8fd317b9b1499ce5807223c278/python/docs/src/user-guide/core-user-guide/components/command-line-code-executors.ipynb
[AG-A]: https://github.com/microsoft/autogen/blob/004749e6b7102c8fd317b9b1499ce5807223c278/python/packages/autogen-ext/README.md
[AG-U]: https://github.com/microsoft/autogen/blob/004749e6b7102c8fd317b9b1499ce5807223c278/python/docs/src/user-guide/autogenstudio-user-guide/usage.md
[AG-Q]: https://github.com/microsoft/autogen/blob/004749e6b7102c8fd317b9b1499ce5807223c278/python/docs/src/user-guide/autogenstudio-user-guide/index.md
[AG-V]: https://github.com/microsoft/autogen/blob/004749e6b7102c8fd317b9b1499ce5807223c278/python/docs/src/user-guide/agentchat-user-guide/tracing.ipynb
[AG-B]: https://github.com/microsoft/autogen/blob/004749e6b7102c8fd317b9b1499ce5807223c278/python/packages/agbench/README.md
[V]: #verbindliche-produktregeln
[OC-R]: https://github.com/openclaw/openclaw/blob/54417c68199f58e9b78fddff95c1ae6b610845fe/README.md
[OC-F]: https://github.com/openclaw/openclaw/blob/54417c68199f58e9b78fddff95c1ae6b610845fe/docs/concepts/features.md
[OC-T]: https://github.com/openclaw/openclaw/blob/54417c68199f58e9b78fddff95c1ae6b610845fe/docs/tools/index.md
[OC-S]: https://github.com/openclaw/openclaw/blob/54417c68199f58e9b78fddff95c1ae6b610845fe/docs/tools/skills.md
[OC-M]: https://github.com/openclaw/openclaw/blob/54417c68199f58e9b78fddff95c1ae6b610845fe/docs/concepts/memory.md
[OC-W]: https://github.com/openclaw/openclaw/blob/54417c68199f58e9b78fddff95c1ae6b610845fe/docs/concepts/agent-workspace.md
[OC-G]: https://github.com/openclaw/openclaw/blob/54417c68199f58e9b78fddff95c1ae6b610845fe/docs/concepts/managed-worktrees.md
[OC-P]: https://github.com/openclaw/openclaw/blob/54417c68199f58e9b78fddff95c1ae6b610845fe/docs/tools/progress-card.md
[OC-H]: https://github.com/openclaw/openclaw/blob/54417c68199f58e9b78fddff95c1ae6b610845fe/docs/tools/thinking.md
[OC-A]: https://github.com/openclaw/openclaw/blob/54417c68199f58e9b78fddff95c1ae6b610845fe/docs/automation/index.md
[OW-R]: https://github.com/open-webui/open-webui/blob/91ea4e3421d1d8069f13d4a463fcb5986d94ea60/README.md
[OW-F]: https://docs.openwebui.com/features/
[OW-W]: https://docs.openwebui.com/features/workspace/
[OW-C]: https://docs.openwebui.com/features/chat-conversations/chat-features/
[OW-S]: https://docs.openwebui.com/features/workspace/skills/
[OW-X]: https://docs.openwebui.com/features/extensibility/plugin/
[OW-T]: https://docs.openwebui.com/features/open-terminal/
[OW-B]: https://docs.openwebui.com/features/open-terminal/file-browser/
[OW-H]: https://docs.openwebui.com/features/chat-conversations/chat-features/reasoning-models/
[C-R]: https://github.com/Starlight-Unit-Studio/coreui/blob/6ab7d2f54b61409cbd817cdf0b6f3db6a208e51e/README.md
[C-C]: https://github.com/Starlight-Unit-Studio/coreui/blob/6ab7d2f54b61409cbd817cdf0b6f3db6a208e51e/api/chat.php
[C-P]: https://github.com/Starlight-Unit-Studio/coreui/blob/6ab7d2f54b61409cbd817cdf0b6f3db6a208e51e/tools/ember_py_worker.py
[C-M]: https://github.com/Starlight-Unit-Studio/coreui/blob/6ab7d2f54b61409cbd817cdf0b6f3db6a208e51e/api/console_media.php
[C-U]: https://github.com/Starlight-Unit-Studio/coreui/blob/6ab7d2f54b61409cbd817cdf0b6f3db6a208e51e/js/console-app.js
[C-23]: https://github.com/Starlight-Unit-Studio/coreui/issues/23
[C-24]: https://github.com/Starlight-Unit-Studio/coreui/issues/24
[C-25]: https://github.com/Starlight-Unit-Studio/coreui/issues/25
[C-29]: https://github.com/Starlight-Unit-Studio/coreui/issues/29
