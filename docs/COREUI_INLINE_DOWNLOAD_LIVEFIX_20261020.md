# Ember CoreUI: Live-Fix fuer HTML-/ZIP-Dateien (20.10.2026)

## Anlass

Beim Live-Test auf Android/Chrome gab E.M.B.E.R. statt einer echten HTML-Datei
einen extrem langen `data:text/html;charset=utf-8,...`-Link als Chattext aus.
Das alte Antwortobjekt bleibt in der Datenbank unveraendert, wird aber ab jetzt
im Verlauf verstaendlich als missglueckter Inline-Download angezeigt.

## Drei getrennte Fehler

1. Der Modellhinweis priorisierte Berechnung mit `[PY]`, aber nicht ausnahmslos
   Dateierzeugung. Ein nicht registrierter `data:`-Link kann keine Chat-Downloadkarte
   ersetzen. Der neue Hinweis verlangt Datei-Ausgaben in `/work/output`; vollstaendige,
   begrenzte Inline-HTML-/ZIP-Antworten werden notfalls durch denselben isolierten
   Python-Worker gespeichert und erst nach Registrierung als erfolgreich betrachtet.
2. Die Nginx-Freigabelisten der isolierten und nativen Installation enthielten weder
   `console_artifact.php` noch `console_artifacts.php`. Beide werden als authentifizierte
   PHP-Endpunkte freigegeben, die restliche PHP-Deny-Regel bleibt bestehen.
3. Der offizielle `setup.sh` laedt bewusst ein gepinntes Release-ZIP (`0.5.3-alpha`)
   und damit **nicht** die neuere Arbeit in `main`. Deshalb kann ein erfolgreicher
   Preflight der Releaseinstallation den Entwicklungsstand nicht nachweisen.

Der normale `scripts/migrate.sh` wendet bereits alle vorhandenen SQL-Migrationen an;
Migration 011 wurde also **nicht** prinzipiell ausgelassen. Neu ist die explizite
Schema-011-Pruefung in beiden Preflights.

## Live-Update des Entwicklungsstands

Nur auf einer bestehenden isolierten CoreUI-Installation mit persistenten Konten,
Datenbank und Konfiguration. Der Standard-Release-Installer bleibt unveraendert:

```bash
curl -fsSL https://raw.githubusercontent.com/Starlight-Unit-Studio/coreui/main/scripts/update-from-main.sh -o /tmp/ember-coreui-dev-update.sh
sudo bash /tmp/ember-coreui-dev-update.sh
```

Der Quellstand wird anhand des Git-Commit-SHA ausgegeben und unter
`/opt/ember-coreui/var/dev_source_commit` abgelegt. Keine GitHub Actions.
**Dies ist ein Live-Entwicklerupdate, kein neuer Release-Build.**

## Pruefungen

- PHP-Syntax von Chat und Inline-Dateibruecke, Shell-Syntax der Installer und
  Preflights, JavaScript-Syntax, beide Nginx-Templates mit `nginx -t` geprueft.
- 27 bestehende Artefakt-/Worker-Unittests bestanden.
- 17 bestehende Chromium-Komponententests bestanden (HTTP/Save simuliert).
- 13 neue PHP-Brueckentests bestanden und eine echte HTML-Testdatei bytegleich
  durch den generierten Python-Code geschrieben.
- Neue UI-Pruefung: ein alter extrem langer Inline-Link wird kompakt erklaert,
  normale Antworten bleiben unveraendert.
- Kein Zugriff auf den produktiven Host und kein Docker/MariaDB-End-to-End-Test
  in dieser Arbeitsumgebung. Kein produktiver Live-Test behauptet.

## Noch direkt am Server zu pruefen

1. Entwicklerstand mit obigem Script installieren; Preflight meldet Migration 011.
2. In Ember um eine kleine HTML-Datei zum Download bitten, nicht um reinen HTML-Code.
3. Echte Downloadkarte, korrekte HTML-Bytes und Download auf Android Chrome pruefen.
4. Mit ZIP, mehreren Dateien, Seitenreload, Sitzungswechsel und Kontotrennung testen.
5. Erneut geoeffneter alter `data:`-Link darf nicht mehr das gesamte Chatlayout
   mit URL-codiertem Text fuellen. Bei unvollstaendigen Modelldaten klare
   Fehlermeldung statt erfundener Datei.
6. Erst nach erfolgreichem Live-Test Release-ZIP, SHA-256 und Version separat
   aktualisieren; Issue #29 bis zur Abnahme offen lassen.
