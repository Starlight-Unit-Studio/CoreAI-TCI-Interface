# Rechtsstand und Onlinequellen: verbindliche Regel aller juristischen CoreAI-Skills

Gilt fuer die Rechtsskills im Skillkatalog von CoreAI 0.5.81, unabhaengig davon, welche einzelne Fachanleitung Mara auswaehlt. Die Regel steht zusaetzlich explizit im Body **jedes** dieser SKILL.md. Diese Datei ist ein Entwicklungs- und Testbeleg; der bestehende Loader liest sie nicht automatisch.

## Pflichtpruefung bei jedem Fall

1. Rechtsraum und Rechtsweg bestimmen (EU, Bund, Land, lokal, Ausland). Bei fehlender entscheidender Angabe gezielt nachfragen und nicht stillschweigend deutsches Recht unterstellen.
2. Nutzerfrage zeitlich praezisieren: Datum des Ereignisses, Vertragsabschlusses, Bescheides, Steuerjahrs, Zugangs und Datum der geplanten Handlung. Aktuelle und historische Fassung bewusst unterscheiden.
3. Vor jeder konkreten Rechtsbehauptung **online** nach den geltenden Gesetzesvorschriften, Aenderungsgesetzen, amtlicher Verkuendung und relevanter Rechtsprechung suchen. Trainiertes Modellwissen, alter RAG-Text und bisherige Chatantworten sind keine aktuellen Rechtsnachweise.
4. Primaerquellen bevorzugen: Bundesrecht konsolidiert auf https://www.gesetze-im-internet.de/ (nicht amtlich), amtliche Verkuendung im Bundesgesetzblatt https://www.recht.bund.de/, bei Landesrecht amtliches Landesrecht; fuer EU-Recht https://eur-lex.europa.eu/ und Amtsblatt. Urteile nur ueber authentische Gerichtsportale oder verifizierbare Fundstellen.
5. Pruefen, ob eine Norm geaendert, aufgehoben, noch nicht in Kraft oder nur durch Uebergangsregel anwendbar ist. Zukunftsrecht und geltendes Recht nicht vermischen. Moegliche Rueckwirkung und Rechtsaenderungen waehrend laufender Verfahren ausdruecklich beruecksichtigen.
6. Bei Fristen: Zugang/Bekanntgabe/Zustellung, Ausloeser, Bundesland, Wochenende/Feiertag, gesetzliche Sonderregeln und verifizierten Fristbeginn dokumentieren.
7. Ergebnis mit konkreten Normstellen, Fassung/Geltungszeitraum, Abrufdatum und Originalquelle belegen. Veraltete und aktuelle Fassung bei Konflikten gegenueberstellen, Unterschiede erklaeren.
8. Ist das Webtool offline, Quelle nicht pruefbar oder die konkrete Norm nicht zuverlaessig auffindbar: **keine behauptete aktuelle, rechtsverbindliche oder fristfeste Antwort geben**. Nur als vorlaeufige Information kennzeichnen und eine konkrete amtliche Nachpruefung benennen.

## Negativtests

- Aelterer Gesetzestext aus Modellwissen wird von einer juengeren amtlichen Novelle abgeloest.
- Eine Vorschrift ist bereits verkuendet, gilt aber noch nicht.
- Ein 2023er Bescheid muss nach dem Rechtsstand 2023, nicht automatisch 2026, geprueft werden.
- SGB II und SGB XII sowie Bundes- und Landesrecht duerfen nicht vermischt werden.
- Ein Gesetzesportal fuehrt konsolidierten Dokumentationstext; Quelle muss zwischen dokumentarisch und amtlich unterscheiden.
- Kein Browser verfuegbar: Mara sagt, dass die Aktualitaetspruefung nicht stattfand, ohne eine scheinaktuelle Norm zu erfinden.

## Roadmap

Die bestehenden Skill-Runtime-Teile (Loader, automatische Auswahl, Marker, Modelfile, Werkzeuge-Kernskill, serielle Modellqueue) sind nicht Teil dieses Ausbaus. Fuer den vollen rechtlichen Werkzeugworkflow werden eine autorisierte amtliche Webrecherche, Dokumentenparser, Gesetzesversionsvergleich, Gerichtsquellensuche, Fristenpruefung, Quellenzitate und ein aktualisierbarer Quellenkatalog benoetigt. Alle fehlenden Funktionen als offene Toolpakete dokumentieren.
