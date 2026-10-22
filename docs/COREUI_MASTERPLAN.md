# Masterplan für E.M.B.E.R. CoreUI und E.M.B.E.R.

Stand: 20.10.2026. Dieses Dokument hält das vereinbarte Ziel, die belegte Ausgangslage und die Reihenfolge der Entwicklung fest. Es beschreibt geplante Arbeit; die Aufnahme einer Funktion ist kein Nachweis ihrer Implementierung.

Die [Funktionsmatrix](COREUI_FEATURE_MATRIX.md) ist das zugehörige Arbeitsregister. [Issue #29](https://github.com/Starlight-Unit-Studio/coreui/issues/29) führt den Fehler bei Downloads erzeugter Dateien. Beide Dokumente sollen bei Umsetzung und Übergaben gemeinsam aktualisiert werden.

Die [Umsetzungsroadmap](../ROADMAP.md) ordnet diese Anforderungen in 29 Lieferpakete mit Abhängigkeiten, Abnahmen und dem konkret nächsten Arbeitsabschnitt.

## 1. Verbindliches Ziel

**E.M.B.E.R. CoreUI soll eine vollständige Arbeitsumgebung mit dem E.M.B.E.R.-Framework und Mara als ausführender TCI werden.** Dazu gehören mehrstufige Aufgaben, sichtbare Arbeitsschritte, ein echter Entwicklungsbereich, wiederverwendbare Skills und verlässliche Dateiausgabe.

**OpenClaw, Open WebUI, CrewAI, LangGraph und Microsoft AutoGen** werden systematisch untersucht. Alle fünf Projekte gehören zum verbindlichen Referenzumfang. Ziel ist, sämtliche erfassten Funktionen durch eigene Implementierungen in E.M.B.E.R. CoreUI abzudecken. Es werden keine fremden Quellcodeabschnitte, Oberflächentexte oder Assets übernommen. Referenzverhalten wird verstanden, als eigenständige Anforderung beschrieben und anhand eigener Abnahmekriterien umgesetzt. Ein vorgefertigter Klon ist kein Umsetzungsschritt.

Die Gesamtinventur bleibt offen, bis auch Unterfunktionen, Einstellungen, Menüs, APIs, Voraussetzungen und relevante Begleitprojekte einzeln erfasst sind. Ein übernommener Familienname wie „Skills“ oder „Agent“ erfüllt diese Anforderung noch nicht. Notwendige Abweichungen werden ausdrücklich begründet und im Register erhalten, statt Funktionen stillschweigend zu streichen.

Für die Aufgabenbearbeitung dient der vom Betreiber gewünschte Arbeitsumfang von Claude/Codex als Orientierung: Auftrag verstehen, Arbeit organisieren, Werkzeuge ausführen, Dateien ändern, Ergebnisse prüfen, Fehler bearbeiten und das fertige Ergebnis bereitstellen. Damit wird keine bereits erreichte Gleichwertigkeit behauptet.

### Feste Rahmenbedingungen

- **Höchstens eine Modellanfrage gleichzeitig.** Alle interaktiven Aufträge, Unteraufgaben, Automationen, Gedächtnispflege und Zusammenfassungen verwenden dieselbe zentrale serielle Ausführung. Mehrere Rollen oder Agenten dürfen keine zusätzlichen parallelen Modellanfragen erzeugen.
- **Echte Arbeit muss sichtbar sein.** Geplante Schritte, tatsächliche Werkzeugereignisse und Ergebnisse haben getrennte Zustände. Statussätze dürfen nicht vortäuschen, dass ein Werkzeug bereits ausgeführt wurde.
- **Eigenständiges Produkt.** Name, Branding, bestehende Lizenz- und Community-Regeln von E.M.B.E.R. CoreUI bleiben maßgeblich. Die Referenzprodukte bestimmen weder Lizenzmodell noch Bezahlfunktionen der eigenen Umsetzung.
- **Am Handy bedienbar.** Die zentralen Abläufe müssen in Android Chrome einschließlich Dateidownload, Wiederaufnahme, Editor, Terminalbedienung und Freigaben nutzbar sein.
- **Vorhandene Arbeit erhalten.** Konten, Gespräche, Uploads, Wissensdaten, Konfiguration und später Projekte, Skills sowie erzeugte Dateien brauchen einen konsistenten Update-, Export- und Wiederherstellungsweg.
- **Freigaben im Auftragskontext beachten.** Bereits autorisierte Arbeit soll fortgesetzt werden. Eine menschliche Übergabe braucht einen konkreten Grund und einen überprüfbaren nächsten Schritt.
- **GitHub Actions auslassen.** Der Betreiber hat am 20.10.2026 mitgeteilt, dass das Kontingent ausgeschöpft ist. Verfügbare direkte Prüfungen werden mit ihrem wirklichen Ergebnis dokumentiert. Nicht ausgeführte Prüfungen gelten nicht als bestanden.

## 2. Belegte Ausgangslage

Ausgangspunkt der Prüfung ist [CoreUI-Commit `6ab7d2f`](https://github.com/Starlight-Unit-Studio/coreui/commit/6ab7d2f54b61409cbd817cdf0b6f3db6a208e51e). Nachfolgende Dokumentationscommits ändern diese technische Bestandsaufnahme nicht.

| Bereich | Belegter Stand | Konsequenz |
|---|---|---|
| Veröffentlichter Versionsstand | `0.5.3-alpha`, Schema bis Migration 009 | Keine neue Release- oder Migrationsnummer allein durch diesen Plan. |
| Bereits vorhanden | Private Chats und Sitzungen, Uploads, RAG-Lite, lokales Gedächtnis, Browserrecherche, Python-Ausführung und exakte Ganzzahlrechnung | Diese Bausteine werden weiterentwickelt; ihre Existenz beweist noch keine vollständige Referenzabdeckung. |
| Host-Swap | [PR #28](https://github.com/Starlight-Unit-Studio/coreui/pull/28) ist in `main` integriert | Das vorhandene 0.5.3-Archiv enthält diesen späteren Quellstand noch nicht. Das nächste tatsächlich gebaute Paket muss ihn berücksichtigen. |
| Vorbereitete 0.5.4-Arbeit | Im früheren Work-Verlauf wurden `release/0.5.4-alpha`, lokaler Commit `3fec85d` und `010_console_tools` dokumentiert | Dieser Commit und Branch sind im geprüften Remote nicht vorhanden. Einzelne hochgeladene Blobs ersetzen keinen vollständigen Git-Stand. |
| Inhalt des alten Arbeitsstands | Werkzeugmenü für Datei/RAG-Lite/Web/Browse/Python, persistente Werkzeug-/Quellenmetadaten und Anpassungen des Rechenpfads wurden im früheren Verlauf beschrieben | Die zugehörigen vollständigen Dateien sind hier nicht wiederhergestellt. Veröffentlichung, Migration und damalige Testergebnisse lassen sich daraus nicht erneut bestätigen. |
| Generierte Downloads | [Bug #29](https://github.com/Starlight-Unit-Studio/coreui/issues/29) ist offen | Die Fehlerursache wurde im Code untersucht, noch nicht behoben oder zusätzlich auf dem Produktionssystem reproduziert. |
| Vollständiger Agent und IDE | Einzelne Grundlagen sind vorhanden, ein allgemeiner Entwicklungsarbeitsbereich und vollständiger Skill-Lebenszyklus sind nicht belegt | Als eigener Ausbau planen und gezielt abnehmen. |

Die technische Basis und ihre bisherige Ausrichtung sind in der [gepinnten README](https://github.com/Starlight-Unit-Studio/coreui/blob/6ab7d2f54b61409cbd817cdf0b6f3db6a208e51e/README.md) sowie den [bisherigen Übergaben](.) dokumentiert. Dieses Dokument ergänzt sie um den zusammenhängenden Agenten- und Referenzplan.

**Versionsabgleich:** Falls der vollständige alte 0.5.4-Arbeitsstand wieder verfügbar wird, zunächst gegen den aktuellen Branch vergleichen und den bereits integrierten Swap berücksichtigen. Fehlt er weiterhin, erforderliche Funktionen nachvollziehbar neu umsetzen. Die Nummer `010_console_tools` vor einer neuen Migration abgleichen; keine zwei unterschiedlichen Migrationen unter derselben Nummer veröffentlichen.

## 3. Umfang und Belegregeln der Referenzinventur

Die Startinventur verwendet folgende reproduzierbare Quellen:

| Referenz | Festgehaltener Stand | Beleggrenze |
|---|---|---|
| OpenClaw | [`54417c68199f58e9b78fddff95c1ae6b610845fe`](https://github.com/openclaw/openclaw/commit/54417c68199f58e9b78fddff95c1ae6b610845fe), 20.10.2026 | Offizielle Dokumentation der Funktionsfamilien wurde gelesen; nicht jede Implementierung wurde ausgeführt oder geprüft. |
| Open WebUI | [`91ea4e3421d1d8069f13d4a463fcb5986d94ea60`](https://github.com/open-webui/open-webui/commit/91ea4e3421d1d8069f13d4a463fcb5986d94ea60), 16.10.2026 | README und Repository-Struktur dienen als Einstieg. Die laufende Dokumentation wurde am 20.10.2026 gelesen und ist nicht an diesen Commit gebunden. |
| CrewAI | [`4f4573706ea4afee6c4da4c31cfb9622a4495ab7`](https://github.com/crewAIInc/crewAI/commit/4f4573706ea4afee6c4da4c31cfb9622a4495ab7), 20.10.2026 | Aufgaben, Teams, Flows, Checkpoints, Ergebnisprüfung, Feedback, Wissen, Memory und Tools gezielt anhand der offiziellen Dokumentation geprüft. `docs/edge` an diesem Commit ist kein Nachweis derselben Funktionen in jedem veröffentlichten Paket. |
| LangGraph | [`a24fc97778fca67cfac4cd3fc63e508ee1d29f59`](https://github.com/langchain-ai/langgraph/commit/a24fc97778fca67cfac4cd3fc63e508ee1d29f59), 20.10.2026 | Kern und zentrale Mechanismen gezielt geprüft. Die ausgelagerte offizielle Dokumentation ist separat auf [`332b12ee879b1d04ec47dec765627c009eb84b7a`](https://github.com/langchain-ai/docs/commit/332b12ee879b1d04ec47dec765627c009eb84b7a) festgeschrieben. |
| Microsoft AutoGen | [`004749e6b7102c8fd317b9b1499ce5807223c278`](https://github.com/microsoft/autogen/commit/004749e6b7102c8fd317b9b1499ce5807223c278), 16.10.2026 | Core, AgentChat, Extensions, Studio und Evaluation gezielt über Dokumentation und Paketmetadaten eingeordnet. Keine vollständige API-, Sprach- oder Ausführungsprüfung. |
| Open-WebUI-Begleitprojekte | [Open Terminal](https://github.com/open-webui/open-terminal), [Computer](https://github.com/open-webui/computer), [Terminals](https://github.com/open-webui/terminals), [OIKB](https://github.com/open-webui/oikb), [Desktop](https://github.com/open-webui/desktop) | Separate Projekte. Ihre vollständige Inventur und eigene feste Referenzstände stehen noch aus. |

**Lizenz- und Produktzuordnung:** Der CrewAI-Kern und LangGraph stehen an den geprüften Ständen unter MIT. Die CrewAI-README grenzt die kommerzielle AMP Suite ab; LangSmith ist laut offizieller FAQ proprietär. Bei AutoGen steht der Code unter MIT, während Dokumentation und sonstige Inhalte CC BY 4.0 zugeordnet sind. Diese Aussagen gelten für die geprüften Dateien, nicht pauschal für optionale Abhängigkeiten, Dienste, Modelle oder Datensätze. Die genauen Lizenzdateien und Produktgrenzen stehen im [Quellenregister der Matrix](COREUI_FEATURE_MATRIX.md#quellenregister). Unsere eigenständige Umsetzung und die bestehenden CoreUI-Produktregeln bleiben maßgeblich.

**AutoGen-Wartungsstand:** Die [geprüfte README](https://github.com/microsoft/autogen/blob/004749e6b7102c8fd317b9b1499ce5807223c278/README.md) meldet, dass Microsoft das Projekt nicht mehr pflegt. Das GitHub-Metadatenfeld `archived` stand beim Abruf am 20.10.2026 dagegen auf `false`; ältere Abschnitte sprechen noch von Wartung oder aktiver Studio-Entwicklung. Diese widersprüchlichen Hinweise werden als Beleggrenze erhalten. AutoGen bleibt eine vollständige Funktionsreferenz. Der dort genannte Microsoft Agent Framework wird dadurch nicht automatisch zum Ersatz oder zusätzlichen Referenzprojekt.

Für jede Einzelanforderung werden Referenz, beobachtetes Verhalten, CoreUI-Beleg, eigener Zielumfang, Abhängigkeiten und Abnahme festgehalten. „Dokumentiert“, „im Code geprüft“ und „im Lauf geprüft“ bleiben unterschiedliche Belegstufen.

Neue Erkenntnisse erweitern die Matrix. Eine Fertigmeldung erfordert eine konkrete Implementierung, einen Beleg und die passende Abnahme. Es wird kein pauschaler Prozentwert für Referenzparität aus Familienüberschriften abgeleitet. Experimentelle Referenzfunktionen bleiben als solche gekennzeichnet.

## 4. Früher Schwerpunkt: erzeugte Dateien wirklich herunterladen

**Der Downloadfehler ist unabhängig von der vollständigen IDE früh zu beheben.** Das gemeinsame Ziel lautet: Datei erzeugen, dauerhaft übernehmen, registrieren, der Antwort zuordnen und als echte Datei herunterladen.

Der [Python-Worker](https://github.com/Starlight-Unit-Studio/coreui/blob/6ab7d2f54b61409cbd817cdf0b6f3db6a208e51e/tools/ember_py_worker.py) gibt bislang Text, Exitcode und Laufzeit zurück und entfernt danach sein temporäres Arbeitsverzeichnis. Er übergibt keine registrierten Ausgabedateien. Der [Medienendpunkt](https://github.com/Starlight-Unit-Studio/coreui/blob/6ab7d2f54b61409cbd817cdf0b6f3db6a208e51e/api/console_media.php) kann bereits registrierte Uploads mit Eigentümerprüfung ausliefern, bekommt diese Python-Ergebnisse jedoch nicht.

Daraus folgt für die eigene Umsetzung:

1. Einen kontrollierten Ausgabebereich und eine dauerhafte Artefaktregistrierung einführen.
2. Tatsächlich erzeugte Dateien vor dem Aufräumen übernehmen. Name, Typ, Größe, Inhalts-Hash, Eigentümer, Sitzung, Job und Antwortzuordnung speichern.
3. Geprüfte Artefaktreferenzen als Teil des Werkzeugergebnisses und des gespeicherten Gesprächs übertragen.
4. Downloadadressen serverseitig aus realen IDs erzeugen. Der Chat zeigt Dateikarten, die auch nach einem Reload und auf dem Handy funktionieren.
5. Vorschau und Download getrennt behandeln. HTML und SVG dürfen durch einen Download nicht als aktive Seite im CoreUI-Ursprung ausgeführt werden.
6. Dauerhafte Artefakte in Aufbewahrung, Löschen, Export, Backup und reguläre Updates aufnehmen.

Ein Modelltext mit einem Dateinamen oder einem `sandbox:`-Pfad ersetzt diese Schritte nicht. Ein fehlgeschlagener Job darf keine nicht vorhandene Datei als erfolgreich ausgegeben melden.

Die verbindliche Einzelabnahme steht in [Issue #29](https://github.com/Starlight-Unit-Studio/coreui/issues/29). Sie umfasst insbesondere echte HTML-Bytes, ein vollständig lesbares ZIP, mehrere Dateien, Unicode-Namen, erneutes Öffnen der Sitzung, Kontotrennung und Erhalt nach Worker-Bereinigung. Die gemeinsame Schnittstelle soll später auch Dateien aus Editor, Dokument-, Bild- und anderen Werkzeugen aufnehmen.

## 5. E.M.B.E.R. als vollständiger Agent

### Laufmodell und serielle Ausführung

Ein Auftrag erhält einen dauerhaften Lauf mit Zustand, Plan, Ereignissen und Ergebnissen. Eigene Arbeitsschritte können vorbereitet, aktiv, abgeschlossen, fehlgeschlagen, abgebrochen oder zur menschlichen Übergabe angehalten sein. Wiederverbindung und Neustart dürfen nicht zu einer nur scheinbar laufenden Aufgabe führen.

Die zentrale Warteschlange besitzt die Ausführungsberechtigung für Modellanfragen. Werkzeuge, Delegation, Gedächtnis und Automationen dürfen keinen Seitenweg eröffnen. Folgeschritte werden erst gestartet, wenn der vorherige Modellaufruf abgeschlossen oder kontrolliert beendet ist. Ob unabhängige rein technische Vorgänge gleichzeitig laufen dürfen, ist eine spätere Ressourcenentscheidung und ändert diese Modellgrenze nicht.

Der Agent braucht einen begrenzten, steuerbaren Arbeitszyklus: nächste Aktion bestimmen, Werkzeug ausführen, Ergebnis auswerten, bei Bedarf korrigieren und mit einem überprüften Ergebnis abschließen. Budgets, Abbruch und Wiederaufnahme gehören zum Lauf. Der bisherige einzelne Python-Aufruf pro Turn in [`api/chat.php`](https://github.com/Starlight-Unit-Studio/coreui/blob/6ab7d2f54b61409cbd817cdf0b6f3db6a208e51e/api/chat.php) ist noch keine allgemeine Ausführungs- und Reparaturschleife.

### Sichtbares Thinking und sichtbare Arbeit

Vier getrennte Funktionen sind vorgesehen:

| Funktion | Erwartetes Verhalten |
|---|---|
| Modell-Denkaufwand | Nur tatsächlich unterstützte Provider-/Modelleinstellungen anbieten und ihren Geltungsbereich anzeigen. |
| Bereitgestellte Reasoning-Inhalte | Vom verwendeten Modell tatsächlich gelieferte, für die Anzeige bestimmte Inhalte oder Zusammenfassungen in einer eigenen Ansicht darstellen. Bei fehlender Unterstützung keinen Inhalt erfinden. |
| Arbeitskommentare und Plan | Verständlich erklären, woran E.M.B.E.R. arbeitet, was bereits bekannt ist und welche konkrete Unsicherheit als Nächstes geklärt wird. |
| Werkzeug- und Dateiaktivität | Tatsächliche Lese-/Schreibaktionen, Befehle, Ergebnisse, Fehler und Wiederholungen dem richtigen Arbeitsschritt zuordnen. Details aufklappbar halten. |

Das [aktuelle Chat-Backend](https://github.com/Starlight-Unit-Studio/coreui/blob/6ab7d2f54b61409cbd817cdf0b6f3db6a208e51e/api/chat.php) enthält vorgefertigte öffentliche Statussätze. Die neue Darstellung benötigt belastbare Laufereignisse und einen definierten Ausgabekanal. Bestehende Filter für interne Inhalte einfach zu entfernen erfüllt dieses Ziel nicht.

Als Referenz dienen die getrennten Konzepte für [Fortschritt](https://github.com/openclaw/openclaw/blob/54417c68199f58e9b78fddff95c1ae6b610845fe/docs/tools/progress-card.md), [Thinking-Steuerung](https://github.com/openclaw/openclaw/blob/54417c68199f58e9b78fddff95c1ae6b610845fe/docs/tools/thinking.md) und die [anbieterabhängige Anzeige in Open WebUI](https://docs.openwebui.com/features/chat-conversations/chat-features/reasoning-models/).

### Werkzeuge, Fähigkeiten und Ergebnisse

Das Werkzeugregister beschreibt Eingaben, Ergebnisse, Voraussetzungen, Berechtigungen und Laufereignisse. Ein Ergebnis kann Text, strukturierte Daten, Dateien, eine Vorschau oder eine erforderliche Übergabe enthalten. Tool-Verfügbarkeit und tatsächlich nutzbare Fähigkeiten werden vor der Ausführung geklärt.

Delegierte Unteraufgaben dürfen unterschiedliche Rollen und eigene Ergebnisse besitzen. Ihre Modellarbeit bleibt seriell. Elternaufgabe, Warteschlangenposition, Fortschritt, Abbruch und Rückgabe müssen sichtbar und nach einem Reload rekonstruierbar sein.

### Teams, Zustandsgraphen und dauerhafte Wiederaufnahme

CrewAI, LangGraph und AutoGen erweitern den Agentenumfang um ausdrücklich abnehmbare Mechanismen. Die [Matrix](COREUI_FEATURE_MATRIX.md) führt sie unter CF-073 bis CF-082; verwandte vorhandene Einträge für Tools, Memory, Ereignisse und Evaluation werden vertieft.

- **Versionierte Agenten-, Aufgaben- und Teamdefinitionen:** Rollen, Ziele, Kontext, Abhängigkeiten sowie Ein- und Ausgabeformate werden nachvollziehbar konfiguriert. Ein laufender Auftrag behält seine Definition. Konfigurationsexport und gespeicherter Ausführungszustand sind unterschiedliche Daten.
- **Explizite Abläufe:** Schritte, bedingte Übergänge, Schleifen und das Warten auf eines oder mehrere Ergebnisse erhalten definierte Regeln. Zustandsfelder und ihre Zusammenführung sowie Ein-/Ausgaben und Lebensdauer von Unterabläufen sind prüfbar. Mehrere bereite Zweige werden hinsichtlich ihrer Modellarbeit seriell abgearbeitet.
- **Checkpoints und Wiederaufnahme:** Laufzustand, erledigte Schritte, übernommene Ergebnisse, Versionsbezug und Herkunft werden dauerhaft gespeichert. Nach einem Neustart wird vom bestätigten Stand fortgesetzt. Inkompatible Definitions- oder Zustandsänderungen benötigen einen geprüften Übergang oder einen sichtbar begründeten Stopp.
- **Replay und neue Laufzweige:** Ein alter Zwischenstand lässt sich untersuchen und als neuer Laufzweig mit geändertem Ergebnis fortführen. Abhängige Folgeergebnisse werden neu berechnet; die ursprüngliche Historie bleibt erhalten. Erneute Modell- oder API-Aufrufe werden vor dem Start kenntlich gemacht. Git-Snapshots allein erfüllen diese Funktion nicht.
- **Robuste Schritte und Ergebnisprüfung:** Wiederholungen, Fehlerpfade, geordnetes Stoppen und sofortiger Abbruch haben getrennte Regeln. Bereits bestätigte Ergebnisse werden wiederverwendet. Für unklar abgeschlossene externe Aktionen wird zuerst der tatsächliche Zustand geklärt; Checkpoints garantieren keine pauschale Einmalausführung. Schema- und Qualitätsprüfungen dürfen begrenzte Überarbeitung verlangen und dokumentieren ihre Entscheidung.
- **Allgemeine menschliche Beteiligung:** E.M.B.E.R. kann konkrete Ergebnisse zur Prüfung vorlegen, Rückmeldung speichern und später fortfahren. Freigabe, Ablehnung und Überarbeitung beziehen sich auf die vorgelegte Revision. Bereits ausreichende Auftragsfreigaben gelten fort; eine Qualitätsprüfung ersetzt keine serverseitige Berechtigung.
- **Teamsteuerung:** Feste oder regel-/modellgestützte Sprecherwahl, zulässige Übergaben, Rückgaben und kombinierte Stoppbedingungen steuern mehrere Rollen. Auch Manager, Auswahlmodelle, Ergebnisprüfer und Testläufe nutzen die eine globale Modellwarteschlange.

P26 bis P28 vertiefen diese Mechanismen auf der Grundlage von P05 bis P08. Die erste Downloadlieferung und die grundlegende Entwicklerumgebung brauchen dafür nicht den gesamten erweiterten Workflowumfang abzuwarten.

## 6. Echte Entwicklerumgebung

Ein Entwicklungsprojekt braucht einen dauerhaften Arbeitsbereich mit einem stabilen Bezug zu Gespräch, Auftrag und Repository. Der Umfang umfasst:

- Dateibaum, Suche, Erstellen, Umbenennen, Verschieben, Löschen, Upload und Download.
- Editor mit Syntaxdarstellung, mehreren Dateien, Änderungenvergleich und klar erkennbarem Speicherstand.
- Befehlsausführung mit Arbeitsverzeichnis, Ausgabe, Exitcode, Unterbrechung und wiederauffindbaren Prozessen.
- Projektabhängigkeiten und Laufzeitumgebungen, damit der Agent Code tatsächlich starten und Fehler bearbeiten kann.
- Vorschauen für gestartete Anwendungen sowie unterscheidbare Zustände für Start, erreichbar, Fehler und beendet.
- Git-Status, Diff, Branches, auftragsbezogene Checkouts, Commits, Konfliktbearbeitung und geregelte Übernahme von Änderungen.
- Tests und andere projektspezifische Prüfungen mit echten Ergebnissen; kein grünes Symbol für einen bloß vorgeschlagenen Befehl.
- Snapshots oder ein gleichwertiger Wiederherstellungsweg sowie verständliche Lebenszyklen für temporäre und dauerhafte Dateien.
- ZIP-Export eines Projekts und einzelne Ausgabedateien über dieselbe Artefaktschnittstelle wie im Chat.

Arbeitsverzeichnis, Prozessumgebung und Berechtigungsgrenze sind getrennte Konzepte. Die konkrete technische Umsetzung wird gegen den vorhandenen PHP-/Worker-Stack geprüft. Der Plan schreibt keine Übernahme der Referenzarchitektur vor.

Die Referenzen [verwaltete Arbeitsstände in OpenClaw](https://github.com/openclaw/openclaw/blob/54417c68199f58e9b78fddff95c1ae6b610845fe/docs/concepts/managed-worktrees.md) und [Open Terminal](https://docs.openwebui.com/features/open-terminal/) begründen getrennte Inventuraufgaben. Funktionen aus Begleitprojekten werden nicht pauschal dem Open-WebUI-Hauptrepository zugeschrieben.

Für Agentenentwicklung kommt in P29 ein eigener visueller Team-/Ablaufeditor mit gleichwertiger deklarativer Ansicht und wiederverwendbaren Komponenten hinzu. Ein Playground führt ausgewählte Definitionen in einem zugeordneten Testarbeitsraum aus und zeigt reale Schritte, Zustandsänderungen, Fehler, Unterbrechungen und Ergebnisvergleiche. Die Ausführungsgrenzen gelten auch dort. Importierte Konfigurationen starten keinen fremden Code allein durch das Öffnen; Zugangsdaten werden als Referenzen auf die eigene Konfiguration behandelt. Ein Testlauf erhält überprüfbare Ergebnisse und denselben Dateiweg wie andere Aufträge. Diese Anforderungen sind in CF-081/082 eingeordnet; der Skill-Lebenszyklus bleibt eine eigene Funktion.

## 7. Skills, Tools und Plugins

| Begriff | Eigene Bedeutung | Erforderlicher Umfang |
|---|---|---|
| Tool | Führt eine konkrete Aktion aus | Eingabeschema, Ergebnisvertrag, Rechte, Laufzeit und Ereignisse. |
| Skill | Beschreibt einen wiederverwendbaren Arbeitsablauf mit den vorhandenen Fähigkeiten | Anweisung, Metadaten, Referenzen, Vorlagen, Skripte und weitere Ressourcen; Laden bei Bedarf. |
| Plugin | Liefert installierbare Fähigkeiten oder Integrationen | Installation, Konfiguration, Abhängigkeiten, aktiv/inaktiv, Aktualisierung und Entfernen. |

Ein Skill ist kein Ersatz für ein fehlendes ausführbares Werkzeug. Ein Skill kann auch keine zusätzlichen Berechtigungen oder parallelen Modellanfragen gewähren.

Das Skillsystem benötigt Erstellung und Bearbeitung, Suche, persönliche und gemeinsame Geltungsbereiche, Auswahl für Projekt/Modell/Gespräch, Import und Export, feste Versionen, Vergleich, Wiederherstellung und Konfliktbehandlung. Ein Agent soll geeignete erprobte Abläufe mit nachvollziehbarer Herkunft als Skills weiterentwickeln können. Die tatsächlich verwendete Skill-Version bleibt einem Lauf zugeordnet.

Die Quellen [OpenClaw Skills](https://github.com/openclaw/openclaw/blob/54417c68199f58e9b78fddff95c1ae6b610845fe/docs/tools/skills.md) und [Open WebUI Skills](https://docs.openwebui.com/features/workspace/skills/) sind Vergleichsgrundlagen. Die eigene Struktur und Implementierung werden für E.M.B.E.R. CoreUI festgelegt.

## 8. Weitere verbindliche Ausbauflächen

Die Matrix führt Chatorganisation und Projekte, Prompt-/Modellprofile, Wissen und RAG, Gedächtnis, Websuche, Browseraktionen, Medien, Sprachfunktionen, Automationen, Kommunikationskanäle, Zusammenarbeit, Administration, Provider und Betrieb.

[Issue #25](https://github.com/Starlight-Unit-Studio/coreui/issues/25) bleibt der vorhandene Browser-Action-Plan: Recherche, authentifizierte und getrennte Browsersitzungen, Eingaben und Formulare, Uploads, sichtbare Aktionen, Entwürfe, Ausführung sowie menschliche Übergabe und Wiederaufnahme. Die bereits beschriebenen Zuständigkeiten und Freigabegrenzen werden bei der Umsetzung berücksichtigt.

[Issue #23](https://github.com/Starlight-Unit-Studio/coreui/issues/23) zum Schutz festgelegter Branding-/Copyright-Dateien und [Issue #24](https://github.com/Starlight-Unit-Studio/coreui/issues/24) zur optionalen Installationserfassung bleiben gesonderte bestehende Planungen. Sie autorisieren kein allgemeines Durchsuchen privater Inhalte oder standardmäßiges Tracking. Die geltenden Regeln zu fehlender Lizenztelemetrie werden durch diese Roadmap nicht verändert.

Breite Funktionsabdeckung bedeutet auch, Voraussetzungen sichtbar zu machen: Nicht jeder lokale Modellprovider bietet dieselben Medien-, Tool- oder Reasoning-Funktionen. Fehlende Fähigkeiten werden nachvollziehbar angezeigt und über passende eigene Integrationen ergänzt.

### Native CPU-Bildgenerierung als P20-Unterarchitektur

Die [Ember Image Engine Planung](COREUI_IMAGE_ENGINE_PLAN.md) dokumentiert einen eigenen C/C++-basierten Bildpfad. stable-diffusion.cpp ist Referenz und moegliche native Runtime, FLUX.1-schnell und SDXL-Turbo sind nicht vorausgewaehlte, noch zu benchmarkende Modellkandidaten. Alle Bildjobs teilen denselben provideruebergreifenden Modellslot wie Ollama und werden mit echten Bilddateien im Artefaktregister gespeichert. Gewichte, Textencoder, VAE, Lizenzen, AVX-512-/CPU-Faehigkeiten, Speicherverbrauch und Laufzeit werden vor Freigabe auf dem Zielserver geprueft. Ein GGUF-Modell allein ergibt keine lauffaehige Pipeline.

## 9. Reihenfolge und überprüfbare Meilensteine

Die Reihenfolge beschreibt technische Abhängigkeiten. Sie setzt keine Veröffentlichungstermine fest und vergibt keine neuen Versionsnummern. Einzelne unabhängige Aufgaben können vorbereitet werden; E.M.B.E.R.s Modellverarbeitung bleibt in allen Phasen seriell.

| Meilenstein | Ergebnis | Voraussetzung und Abschlussnachweis |
|---|---|---|
| M0: Plan und Inventur | Zusammenhängender Masterplan, Startmatrix, feste Referenzstände und offener Download-Bug | Dokumente sind im Repository erreichbar. Die vollständige Einzelinventur läuft weiter. |
| M1: Versionsabgleich und Downloads | Alter 0.5.4-Stand ist abgeglichen oder die noch fehlenden Änderungen sind ausdrücklich benannt; gemeinsame Dateiausgabe behebt #29 | Das sind unabhängige Teilaufgaben. Fehlender Altcode blockiert die Downloadkorrektur nicht. Migrationen erst nach Schemaabgleich festlegen; HTML-/ZIP-Abnahme tatsächlich durchführen. |
| M2: Agentenlauf und Ereignisse | Serielle Laufsteuerung, Werkzeugregister, mehrstufige Ausführung und sichtbarer Fortschritt; P26 bis P28 ergänzen Teams, Graphen, Checkpoints und Reviews | Gleichzeitige Anforderungen führen zu höchstens einem aktiven Modellaufruf. Abbruch, Fehler und Reload sind überprüft; erweiterte Wiederaufnahme und Ergebnisprüfung erhalten eigene Abnahmen. |
| M3: Entwicklerumgebung | Persistente Projekte, Dateieditor, Terminal, Prozesse, Vorschau, Git und Prüfungen | Ein Beispielprojekt vollständig bearbeiten, ausführen, korrigieren, prüfen und als nutzbares Ergebnis exportieren. |
| M4: Skills und Plugins | Vollständiger Skill-Lebenszyklus und installierbare Fähigkeitserweiterungen | Einen Skill mit Ressourcen erstellen, versionieren, in einem neuen Lauf verwenden und auf eine frühere Version zurücksetzen. |
| M5: Browser, Wissen und Memory | Browser-Action-Plan sowie systematischer Wissens- und Kontextausbau | #25 schrittweise abnehmen; Quellen und Kontextzuordnung sowie echte Übergabe/Wiederaufnahme prüfen. |
| M6: Breite Funktionen | Medien, Automationen, Kanäle, Zusammenarbeit, Verwaltung, ergänzende Clients und visueller Agenten-/Workflowbereich | Jede Funktion durch Matrixeinträge und passende End-to-End-Szenarien nachweisen. Hintergrundarbeit und Playground respektieren dieselbe Modellwarteschlange. |
| M7: Abdeckung und Release | Vollständige Funktionsinventur abgleichen und tatsächlich implementierten Stand paketieren | Offene Lücken explizit ausweisen. Quellstand, Migrationen, Releasearchiv, SHA-256, Installer, Changelog und Übergabe müssen denselben Stand beschreiben. |

M7 begleitet auch frühere Teilreleases als Freigabedisziplin. Eine sichere, nützliche Teilfunktion muss nicht auf die gesamte Referenzabdeckung warten.

## 10. Definition einer erledigten Funktion

Eine Matrixposition ist erst erledigt, wenn der eigene Code vorhanden ist, die tatsächlich bereitgestellte Oberfläche oder API nutzbar ist und die beschriebene Abnahme durchgeführt wurde. Ein Plan, ein Mockup oder ein positiver Modelltext genügt dafür nicht.

Zu jedem abgeschlossenen Eintrag gehören:

- Implementierungs-Commit oder PR und bei relevanten Schemaänderungen die Migration.
- Tatsächlich geprüfter Ausgangsstand, Befehl oder Bedienablauf, Ergebnis und verbleibende Einschränkungen.
- Bei dauerhaften Daten der Nachweis für Wiederöffnung, Update und den festgelegten Lebenszyklus.
- Bei nutzerübergreifenden Funktionen die korrekte Zuordnung und Berechtigung.
- Ein aktualisierter Matrixstatus sowie passende Release- und Übergabeinformationen.

Für reine Dokumentationsänderungen genügen direkte Inhalts-, Verweis- und Diffprüfungen. Für einen Laufzeitfix sind die betroffenen tatsächlichen Abläufe zu prüfen. Wegen des ausgeschöpften Kontingents werden aktuell keine GitHub-Actions-Läufe angefordert oder als Voraussetzung eingeplant; bestehende Repository-Regeln werden dafür nicht umgangen.

## 11. Übergabe zwischen Chats und Arbeitsumgebungen

Vor weiterer Implementierung diesen Masterplan, die Matrix, den aktuellen Branch und die betroffenen Issues lesen. Neue Arbeit soll an konkrete Matrix-IDs und überprüfbare Ergebnisse anknüpfen.

Ein Übergabestand nennt mindestens aktuellen Commit/Branch, erledigte Änderungen, noch offene Arbeit, tatsächliche Prüfungen und den nächsten ausführbaren Schritt. Für Releases zusätzlich Paketstand, Prüfsumme und Migration festhalten. Erstellte Git-Blobs allein sind keine gesicherte Übergabe; Änderungen brauchen einen erreichbaren Commit und Branch.

Für den Stand dieses Dokuments gilt: **Der umfassende Ausbau ist geplant. Der Download-Bug ist erfasst und offen. Der alte 0.5.4-Arbeitsstand ist nicht als vollständiger Remote-Commit wiederhergestellt. GitHub Actions werden ausgelassen.**

