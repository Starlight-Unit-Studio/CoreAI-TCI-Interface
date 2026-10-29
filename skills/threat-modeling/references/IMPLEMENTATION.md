# Bedrohungsmodellierung für Code: vollstandiger Zielumfang und Abnahme

Diese Datei ist der vollstandige Fach- und Werkzeugplan. Aktuell laedt CoreAI nur SKILL.md; die Referenz liegt fuer den spaeteren Ausbau bereit, ohne Loader oder Modelfile zu veraendern.

## Ausloeser
threat model, bedrohungsmodell, angriffspfade, sicherheitsmodell, trust boundaries, angriffsfläche analysieren

## Verbindlicher fachlicher Zielumfang
Quellcode und reale Systemgrenzen prüfen, Datenflüsse, Assets, Einfallstore und vorhandene Kontrollen dokumentieren. Szenarien nach Auswirkung, realistischer Eintrittswahrscheinlichkeit und Schutzmaßnahmen priorisieren. Fokus auf defensive Architektur und autorisierte Projektteile, keine ungefragten Angriffstests. Vorlage des OpenAI-Codex-Skills als Funktionsreferenz, keine fremden Assets kopieren.

## Quellen und zeitliche Gueltigkeit
Die fachlich notwendigen Angaben durch aktuelle Quellen, Originaldaten und nachpruefbare Datumsangaben belegen; bei fehlender Recherche als offen kennzeichnen.

## Benotigte Werkzeuge
Repository-Reader, Architektur-/Datenflussanalyse, DAST/SAST nur mit Freigabe, Befund-/Abnahmetestwerkzeuge.

## Ausfuehrungsphasen
1. Auftrag, Rechtsraum/Fachgebiet, Stichtag und Unterlagen strukturiert erfassen.
2. Primaerquellen online recherchieren und ihre aktuelle/historische Gueltigkeit dokumentieren.
3. Sachverhalt oder Daten anhand passender Regeln und der Quellen auswerten.
4. Ergebnis, Gegenargumente, Unsicherheiten und konkrete naechste Schritte vorbereiten.
5. Dokumente nur mit existierenden Werkzeugen erstellen und nachweisbar pruefen.

## Abnahmefaelle
- [ ] Bedrohungen an echte Dateien/Services gebunden, Risiken begründet, Fixes mit reproduzierbaren Tests.
- [ ] Stichtag, Quellen und Versionsstand aus echten Belegen dokumentiert
- [ ] Aenderungsgesetze, Geltungsdaten und Ausnahmen geprueft, wenn relevant
- [ ] Test ohne Internet meldet fehlende Rechtsstandspruefung statt alte Regeln als neu auszugeben
- [ ] Keine unerlaubte externe Aktion und keine erfundene Datei oder Norm

## Abhaengigkeiten
Fach-Skills duerfen bereits jetzt vollstaendig vorbereitet werden. Fehlende Recherche- und Dokumentwerkzeuge gehoeren in docs/COREAI_SKILL_CAPABILITY_GAPS.md und in die Roadmap. Kein neuer Pflicht-Skill und keine Aenderung an der global seriellen Modellwarteschlange.
