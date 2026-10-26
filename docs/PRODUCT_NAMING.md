# CoreAI: TCI Interface - verbindliche Produktbenennung

Stand: 26.10.2026. Gueltig ab 0.5.57.

## Name und Softwarefamilie
- **Produktname (verbindlich):** CoreAI: TCI Interface
- **Softwarefamilie:** Quantum / Quantum Software
- **Hersteller:** Starlight Unit Studios
- **Repository:** https://github.com/Starlight-Unit-Studio/CoreAI-TCI-Interface
- **Optional im Katalog:** Quantum CoreAI: TCI Interface, falls der Familienbezug gebraucht wird
- **Normaler Fliesstext und Dokumentation:** CoreAI: TCI Interface. Quantum nicht als Pflichtvorsatz wiederholen.

## Zugehoerigkeit und historischer Verlauf
- Dasselbe Produkt hiess zuletzt **E.M.B.E.R. CoreUI** (0.5.25 bis 0.5.56) und vorher **Ember CoreUI** (bis 0.5.24).
- E.M.B.E.R. ist weiterhin das interne Framework/Backend, Mara die TCI-Assistentin. Diese fachlichen Identitaeten sind von der Produktmarke zu trennen.
- Die urspruengliche Lizenz `Starlight Unit Studios E.M.B.E.R. CoreUI Community Source Lizenz 1.0` bleibt in historischen Texten und der Lizenzgeschichte nachvollziehbar. Die Markenbezeichnung aendert keine bestehenden Lizenzrechte.
- Die alten Changelog- und Uebergabeversionen sind historische Aufzeichnungen und werden nicht umgeschrieben.

## Kompatibilitaet
- **Nicht ungefragt aendern:** `COREUI_*`-/`coreui_*`-API-Kennungen, Session-/DB-Tabellen, `/opt/ember-coreui`, Docker- und systemd-Dienstnamen, `X-CoreUI-CSRF`, lokale Ollama-Modellnamen, Modellparameter, Python-Worker und bestehende Accountdaten.
- Die aktuelle Betriebsdomain `coreui.starlight-unit.de` bleibt bestehen, bis ein eigenes Hosting-/DNS-Vorhaben dafuer beschlossen ist.
- Das `config/Modelfile.template` bleibt als genehmigte Betreiberkonfiguration bei dieser reinen Produktumbenennung **unveraendert**.
- Das Setup laedt neue Source-Staende aus dem umbenannten GitHub-Repository. Bereits archivierte ZIP/SHA256-Paare bleiben unangetastet.

## Projektgrenze
Dieses Repository umfasst die Software, ihren Installer, ihre enthaltene Login-/Admin-/Chat-Oberflaeche und begleitende rechtliche/technische Dokumentation.
**Externe Starlight-Unit-Webseiten, Central Hub und Produkt-Homepage werden ausdruecklich in einem anderen Chat/Repository aktualisiert.** Hier keine externen Homepage-Edits vornehmen.

## Abnahme
Name und Quantum-Familienvermerk in den sechs HTML-Seiten und in Such-Metadaten, korrektes Repository im Installer, aktuelle Versionsanzeige, unveraenderte TCI-Identitaet, unveraenderte Daten-/Runtime-Kennungen, keine veralteten Produktnamen in aktiven Anzeigen. Echten Live-Deploy, den Netcup-Preflight und Android-Layout gesondert pruefen.

## Sichtbare Namens- und Rechtezuordnung ab 0.5.59

Im internen Info-Bereich steht `QUANTUM SOFTWARE` ueber dem
separaten Produktnamen `COREAI: TCI INTERFACE`. Die historische
Projektbezeichnung `PROJECT STΛRLIɢHT` ist dort keine aktuelle
Produktzuordnung. Starlight Unit Studios bleibt Herausgeber.

Aktuelle interne Oberflaechen nennen `© 2023-2026 Patrick Schildgen`
und Starlight Unit Studios in gut lesbarer Schrift. Historische
Lizenzbezeichnungen und technische Kompatibilitaetsnamen bleiben erhalten.
