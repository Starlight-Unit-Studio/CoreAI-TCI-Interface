# Statische Sicherheitsanalyse im defensiven Threat Modeling

## Scope
Nur freigegebene Repositorys und relevante Dienste. Abgrenzung aus echten Dateien, APIs, Deployment- und Auth-Datenflüssen. Fachliche Referenz: https://github.com/openai/skills/tree/main/skills/.curated/security-threat-model und weitere öffentlich dokumentierte Semgrep-/CodeQL-Konzepte. Keine automatische Fremdausführung.

## Analyseworkflow
1. Sprache, Framework, Paketversion und tatsächlich genutzte Codepfade identifizieren.
2. Datenflüsse und Trust Boundaries kartieren. Bedrohungsszenarien aus konkreten Assets/Eintrittspunkten ableiten.
3. Wenn vorhanden und erlaubt, versionspassenden statischen Scanner einsetzen. Ergebnisse mit Projektpfaden, Zeilen, Regeln, Version und Scanbasis protokollieren.
4. SARIF/JSON-Ergebnisse parsen und Duplikate nach tatsächlicher Ursache gruppieren. Scanner-Ausgaben sind keine verifizierten Exploits.
5. Kritische Funde manuell im Codefluss prüfen: Ist die Quelle erreichbar? Werden Daten validiert? Ist Auth auf Serverebene vorhanden? Gibt es Zugriffsgrenzen?
6. Risiko aus konkreter Auswirkung und Eintrittswahrscheinlichkeit priorisieren, gezielte Abwehrmaßnahmen mit Tests vorschlagen.
7. Nach Änderung denselben Fall plus benachbarte Regressionen testen. Kein pauschaler Sicherheitsstatus ohne reale Prüfungen.

## Testfälle
- [ ] Echtes Code-/Konfigurationszitat für jeden Fund
- [ ] Beispieldatensatz mit true positive, false positive und unklarer Erreichbarkeit
- [ ] Fehlende Scanner oder unzureichende Berechtigung ausdrücklich benannt
- [ ] Bei Token-/Secretfunden Logdetails redigiert
- [ ] Rechte-, XSS-, SQL-/SSRF- und Abhängigkeitsrisiken nach aktueller Projektarchitektur
- [ ] Reproduzierbare Scanner-/Regelversion sowie Retest

## Werkzeugbedarf
Sicherer Git-/Codezugriff, statische Scanner wie CodeQL/Semgrep nach autorisierter Installation, SARIF-/JSON-Leser, Dependency-/SBOM-Reader, Diff- und Unit-Testwerkzeuge. Aktuelle CoreAI-Runtime lädt nur SKILL.md; diese umfangreiche Referenz ist zunächst fachliche Planung und bei Claude als separater Leseschritt vorgesehen.
