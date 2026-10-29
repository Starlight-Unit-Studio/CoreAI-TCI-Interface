# Richtlinien- und Compliance-QA: vollstandiger Zielumfang und Abnahme

Diese Datei ist der vollstandige Fach- und Werkzeugplan. Aktuell laedt CoreAI nur SKILL.md; die Referenz liegt fuer den spaeteren Ausbau bereit, ohne Loader oder Modelfile zu veraendern.

## Ausloeser
policy qa, compliance richtlinie, interne richtlinie, policy prüfen, wissensbasis richtlinien, betriebsvereinbarung prüfen

## Verbindlicher fachlicher Zielumfang
Interne Richtlinien aus ausschließlich autorisiertem RAG/Repository lesen und den genauen geltenden Dokumentstand festhalten. Inhalt, Geltungsbereich und Freigabe mit zwingendem aktuellem Recht abgleichen. Widersprüche zwischen interner Regel und Gesetz markieren, Rechtsquellen und Dokumentspur getrennt belegen. Keine Unternehmensinterna extern veröffentlichen.

## Quellen und zeitliche Gueltigkeit
Bei jedem Fall online gegen heute geltendes Recht UND gegen die Fassung am massgeblichen Ereignisdatum pruefen. Gesetze im Internet ist konsolidiert, aber nicht amtlich; das Bundesgesetzblatt auf recht.bund.de ist die amtliche Verkuendung. EUR-Lex konsolidierte Fassungen sind dokumentarisch, der Rechtsakt im Amtsblatt ist massgeblich. Landesrecht auf offiziellen Landesportalen, Rechtsprechung auf Gerichtsseiten. Verkuendungsdatum, Inkrafttreten, Uebergangsrecht, Rueckwirkung, Gesetzesaenderungen und ggf. neue Urteile getrennt dokumentieren. Bei fehlender Online-Pruefung keinen veralteten Stand behaupten.

## Benotigte Werkzeuge
Konto-/Rollen-beschränkte Wissenssuche, Versionen/Belegstellen, Online-Rechtsquellen, Diff und Nachvollziehbarkeit.

## Ausfuehrungsphasen
1. Auftrag, Rechtsraum/Fachgebiet, Stichtag und Unterlagen strukturiert erfassen.
2. Primaerquellen online recherchieren und ihre aktuelle/historische Gueltigkeit dokumentieren.
3. Sachverhalt oder Daten anhand passender Regeln und der Quellen auswerten.
4. Ergebnis, Gegenargumente, Unsicherheiten und konkrete naechste Schritte vorbereiten.
5. Dokumente nur mit existierenden Werkzeugen erstellen und nachweisbar pruefen.

## Abnahmefaelle
- [ ] Antwort nennt interne und gesetzliche Fundstellen getrennt; keine veraltete Richtlinie als geltendes Recht; Zugriffstrennung intakt.
- [ ] Stichtag, Quellen und Versionsstand aus echten Belegen dokumentiert
- [ ] Aenderungsgesetze, Geltungsdaten und Ausnahmen geprueft, wenn relevant
- [ ] Test ohne Internet meldet fehlende Rechtsstandspruefung statt alte Regeln als neu auszugeben
- [ ] Keine unerlaubte externe Aktion und keine erfundene Datei oder Norm

## Abhaengigkeiten
Fach-Skills duerfen bereits jetzt vollstaendig vorbereitet werden. Fehlende Recherche- und Dokumentwerkzeuge gehoeren in docs/COREAI_SKILL_CAPABILITY_GAPS.md und in die Roadmap. Kein neuer Pflicht-Skill und keine Aenderung an der global seriellen Modellwarteschlange.
