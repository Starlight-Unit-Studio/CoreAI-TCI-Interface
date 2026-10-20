# Ember CoreUI: Dateiausgaben, Implementierungsstand 20.10.2026

Arbeitsbranch: `release/0.5.5-alpha-artifacts`.
Fortgesetzter Backend-Checkpoint: `9b1714664933e96bff97c108704cf2164609f828`.
Bezug: ROADMAP P02/P03/P04, Issue #29. **Arbeitsstand, kein freigegebenes Release.**

## Anschluss und Umfang

Der vorhandene PHP-Checkpoint bleibt erhalten: Dateiregistrierung, authentifizierte
Downloadroute, Zuordnung zu Konto/Sitzung/Antwort/Job, Verlauf, Stream und Export.
Migration `011_console_artifacts` bleibt der Migrationsweg. `010_console_tools`
bleibt fuer den frueheren Werkzeugausbau reserviert. Masterplan und die Planung
mit 82 Anforderungen und 29 Paketen werden nicht neu erstellt.

Die Fortsetzung ergaenzt den Python-Worker, einen getrennten Dateisammler,
Downloadkarten, einen lesenden Sammelendpunkt fuer Dateimetadaten und die
Anbindung der Migration an den nativen Installer. VERSION und die bisherigen
Releasearchive bleiben unveraendert. Den Branch nicht als fertige 0.5.5 installieren.

## Implementiert

### Dateiuebernahme vor der Bereinigung

Modellcode schreibt nach `/work/output`, erreichbar auch ueber `EMBER_OUTPUT_DIR`.
Dieses Verzeichnis wird separat in den wegwerfbaren Ausfuehrungscontainer
gebunden. Der dauerhafte Speicher, Projektquellen und Konfiguration werden
nicht in diesen Container gebunden. Nach bestaetigtem Containerende uebernimmt
`tools/ember_artifacts.py` regulaere Dateien in
`var/ember_py/artifacts/<job-id>/<zufaellige-id>` beziehungsweise unter dem
konfigurierten Python-Arbeitsverzeichnis. Erst danach wird die temporaere
Arbeitskopie entfernt.

Grenzen: 10 Dateien, 25 MiB je Datei, 100 MiB insgesamt, 256 untersuchte Eintraege
und maximal acht Unterverzeichnisebenen. Namen behalten ihren UTF-8-Inhalt,
werden aber nicht als Speicherpfad verwendet. Symlinks, Hardlinks, Spezialdateien,
Pfadtraversal, ungueltige Namen und zu grosse Ausgaben werden abgewiesen.
SHA-256, Groesse und weitere Metadaten werden aus den tatsaechlichen Bytes gebildet.
Fehler einzelner Dateien verhindern nicht die Rueckgabe anderer akzeptierter
Dateien. Eine ZIP-Datei ist der vorgesehene Export einer vollstaendigen Ordnerstruktur.

Dateien erhalten `0640`, Speicherverzeichnisse `0770` und die Gruppe des
installierten Python-Arbeitsverzeichnisses. Die Gruppen-Schreibrechte an den
Verzeichnissen sind erforderlich, damit der PHP-Dienst Dateien bei Sitzungs-
und Kontoloeschung entfernen kann. Der Speicher ist nicht direkt oeffentlich.

### Worker-Zustand und Teilergebnisse

Der Worker speichert Ergebnis und Manifest gemeinsam und nur solange derselbe
Job noch mit seiner Prozess-ID als `running` markiert ist. Ein bereits vom
PHP-Aufruf abgebrochener Job wird nicht wieder auf erfolgreich gesetzt.
Definitiv abgewiesene spaete Ausgaben werden entfernt. Bei unklarem
Datenbank-Commit bleiben Kopien bewusst erhalten, statt eventuell bereits
zugeordnete Dateien zu loeschen.

Auch nach einem normalen Docker-Aufruf wird das Ende des benannten Containers
bestaetigt. Ist das nicht moeglich, werden keine Dateien veroeffentlicht und das
Arbeitsverzeichnis bleibt fuer die Untersuchung erhalten. Fehlerhafte Code-
oder Timeout-Laeufe koennen gerettete Dateien nur als gekennzeichnete
Teilergebnisse liefern. Ungebundene Diagnosejobs veroeffentlichen keine Dateien.
Die bestehende serielle Modellsteuerung wird nicht veraendert.

### Chat und Downloads

`js/coreui-artifacts.js` ergaenzt die vorhandenen, nachrichten-ID-gebundenen
Antwortzeilen um Dateikarten mit Namen, Typ, Groesse und Fehlerzustand.
Der bestehende Chat-Renderer wird nicht ersetzt. Der Beobachter verarbeitet
neue Antworten, wiederhergestellten Verlauf und neu gerenderte Varianten.
Metadaten werden fuer bis zu 100 Nachrichten in einem authentifizierten Aufruf
von `api/console_artifacts.php` gelesen. Spaete Antworten duerfen keine Karten
an inzwischen entfernte oder ausgetauschte Nachrichtenzeilen haengen.

Der Sammelendpunkt benutzt die bestehende gemeinsame Eigentums-, Sitzungs-,
Antwort-, Job- und Revisionspruefung. Eine fehlende Migration ergibt einen
Fehler und eine Wiederholungsmoeglichkeit, keine scheinbar erfolgreiche leere Liste.
Downloadadressen entstehen nur aus validierten, registrierten Datei-IDs.
Freie Modell-URLs und Hostpfade werden nicht als Dateiquellen verwendet.

Der Download wird als Binaerdatei behandelt. HTML und SVG werden nicht als
aktive Seiten im Ursprung der Anwendung angezeigt. Der normale Klick prueft
Antworttyp und Dateilaenge, zeigt Fehler an und uebergibt die Datei mit ihrem
Namen an den Browser. Der native authentifizierte Anhang-Link bleibt fuer
modifizierte Klicks verfuegbar. Die neue CSS-Datei ergaenzt die vorhandene
Darstellung mit umbruchfaehigen Namen und ausreichend grossen Touchzielen.

### Installation und Aufbewahrung

Der native Installer prueft und spielt nun auch Migration 011 ein; sein
bereits vorhandener Host-Swap-Hook bleibt erhalten. Der Docker-Migrationsweg
verwendet bereits die SQL-Dateien aus dem Migrationsverzeichnis. Die bestehende
`var/ember_py`-Einbindung deckt den Speicher ab. Der Update-Launcher schliesst
`var/` von der Quellcodeueberschreibung aus. Diese Installationspfade wurden
in diesem Arbeitsgang gelesen bzw. syntaktisch geprueft, nicht auf einem
frischen oder produktiven Host ausgefuehrt.

## Tatsaechlich ausgefuehrte Pruefungen

- `python3 -m unittest discover -s tests/artifacts -p 'test_artifacts.py' -v`:
  **27 Tests bestanden.** Reale temporaere Dateien, HTML-/ZIP-Inhalte,
  Bytegleichheit und SHA-256, Unicode, leere Dateien, Grenzen, unzulaessige
  Dateitypen, Kopierfehler und der existierende PHP-Manifestpruefer.
  Lesen und Loeschen als PHP-Dienst UID/GID 33 wurde tatsaechlich ausgefuehrt.
  Docker-Aufrufe und Datenbankverbindungen sind in den Worker-Tests ausdrueckliche
  Test-Doubles; SQL-Transaktionen wurden damit nicht in MariaDB abgenommen.
- `python3 tests/artifacts/browser_offline.py`:
  **17 Komponentenpruefungen in Chromium bestanden.** Darunter Dateikarten,
  Byte-/Namens-/Typ-Erhalt der Blob-Speicheraktion, Fehlermeldungen,
  Wiederherstellung nach erneutem Rendern, Wiederholung fehlgeschlagener
  Metadatenaufrufe, Teilergebnisse, Manipulationsversuche, Schutz vor verspäteter
  Zuordnung und ein 390-Pixel-Layout ohne horizontalen Ueberlauf.
  `fetch` und die Speicheraktion sind Test-Doubles. Das ist weder ein nativer
  Browserdownload noch ein Test auf einem echten Android-Geraet.
- PHP-, JavaScript-, Python- und Shell-Syntax der betroffenen Dateien geprueft.

Die Ausfuehrungsumgebung besitzt keinen Docker-Daemon, keine MariaDB und keinen
PHP-MySQL-Treiber. Eine zusaetzlich versuchte HTTP-Browserpruefung wurde schon
beim Aufruf der Testseite durch `ERR_BLOCKED_BY_ADMINISTRATOR` gestoppt.
Diese Beschraenkung wurde nicht umgangen. Entsprechend wird hier keine
bestandene Ende-zu-Ende-Abnahme behauptet. GitHub Actions werden nicht angefordert.

## Noch vor Zusammenfuehrung und Release abnehmen

1. Migration 011 auf sauberer und bestehender MariaDB ausfuehren; den nativen
   und den Docker-Installationsweg mit vorhandenen Daten pruefen.
2. Echten Docker-Worker mit gebundenem Chatturn ausfuehren: HTML und ein ZIP mit
   mehreren Dateien erzeugen, speichern, an die Antwort binden und ueber den
   angemeldeten HTTP-Endpunkt bytegleich herunterladen.
3. Eigentumsgrenzen mit zwei Konten, bekannte fremde Datei-ID, geaenderte
   Ausgangsnachricht, geloeschte Sitzung, fehlende Datei und manipulierte Bytes
   gegen die echte Datenbank und Downloadroute pruefen.
4. Reload, Sitzungswechsel, Varianten und Downloads in Android Chrome pruefen.
   Der Chromium-Komponententest ersetzt diese Geraeteabnahme nicht.
5. Tatsaechlichen Neustart, Update-Datenerhalt, Sitzungs-/Kontoloeschung und
   Fehler zwischen Dateiuebernahme und Antwortbindung pruefen. Aufraeumen
   verwaister oder nach unklarem Commit zurueckbehaltener Kopien erfordert noch
   einen sicheren Abgleich; es gibt keinen pauschalen Loeschlauf dafuer.
6. Erst danach Issue #29 entsprechend seiner gesamten Abnahme schliessen,
   Versionsstand festlegen und ein konsistentes Quell-/Releasepaket mit
   Pruefsumme bauen. Bis dahin bleiben P03/P04 in Umsetzung.

## Hinweis zum lokalen Quellenabgleich

Der Arbeitscontainer konnte das Repository nicht per Git klonen. Ein vorhandenes
altes Releasearchiv diente ausschliesslich als lokale Vergleichskopie. Jede
ersetzte bestehende Datei wurde gegen ihren aktuellen GitHub-Blob-Hash geprueft;
veroeffentlicht werden nur die ausdruecklich bearbeiteten Pfade auf dem bestehenden
Branch-Baum. Unverwandte alte Dateien, lokale Konfiguration und Laufzeitdaten
werden nicht hochgeladen. Der PHP-Artefaktspeicher wurde fuer die Tests exakt
rekonstruiert und bleibt im Repository unveraendert.
