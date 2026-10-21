# Verbindliche Versionierung von Ember CoreUI

Stand: 21.10.2026

## Unveraenderliche Regeln

1. **JEDER Merge nach `main` ist ein neuer Versionsstand.** Bereits im
   Quellbranch muss `VERSION` groesser als der aktuelle `main`-Wert sein.
   Das gilt fuer Bugfixes, Refactoring, UI, Tests, Dokumentation und Tools.
2. `VERSION` besteht ausschliesslich aus `Major.Minor.Patch`, also drei
   dezimalen Zahlen. Die Zaehlerbereiche sind verbindlich:
   `Major = 0..999`, `Minor = 0..9`, `Patch = 0..99`.
   Nach `0.5.99` folgt `0.6.0`, nach `0.9.99` folgt `1.0.0`.
   Es gibt keine Textsuffixe wie alpha, dev, test oder hotfix.
3. Ein neuer Stand muss zeitgleich aktualisieren: `VERSION`, sichtbare
   Versionsangaben in allen sechs HTML-Oberflaechen, JavaScript-Bootstrap,
   `README.md`, `CHANGELOG.md`, `docs/changelogs-txt/INDEX.txt`, eine neue
   `docs/changelogs-txt/CHANGELOG_X_Y_Z.txt`, ein neues
   `docs/UEBERGABEPROTOKOLL_X_Y_Z.txt`, Versionstests und Preflights.
4. Die exakte Git-Commit-ID wird unabhaengig von `VERSION` gespeichert.
   Statische CSS-/JS-Cache-Schluessel basieren auf dem Dateiinhalt (SHA-256)
   und bestehen ausschliesslich aus Ziffern. Sie sind keine Produktversion.
5. Bereits veroeffentlichte ZIP/SHA-256-Paare bleiben unveraendert. Ein
   neuer Source-Merge darf NICHT als offiziell gebautes ZIP-Release bezeichnet
   werden, solange das passende Paket nicht geprueft vorhanden ist.
6. Der eine bestehende One-Liner und Live-Tests am Server bleiben der
   Standard. Keine GitHub Actions zum Erfuellen dieser Versionierungsregel.

## Verpflichtender Check VOR jedem Merge

- Zuerst `VERSION` aus dem zu diesem Zeitpunkt aktuellen `main` lesen.
- Im PR-Branch die Version erhoehen und alle zugehoerigen Stellen nachziehen.
- Lokal aus dem Branch ausfuehren:
  `python3 scripts/version-merge-check.py --base-version <VERSION-von-main>`.
- Wenn die Pruefung fehlschlaegt: PR NICHT mergen. Ausnahmslos.
- Danach wie gewohnt GitHub-PR direkt mergen und per One-Liner live testen.

## Vorfall und Korrektur am 20.10.2026

Mit PR #38 wurde der numerische Stand `0.5.5` hergestellt. PR #39
enthaelt eine Cloud-Dateiausgabe-Korrektur und wurde ohne numerischen
Versionssprung gemergt. Das war ein Regelverstoss. Der Versionsabgleich
mit `0.5.6` dokumentiert den korrekten neuen Stand, ohne zu behaupten,
dass PR #39 historisch eine andere VERSION enthalten haette.

Diese Richtlinie geht nicht davon aus, dass GitHub ohne Actions einen
technischen Branchschutz erzwingt. Der lokale Versions-Guard ist die
verbindliche Pruefung im Entwicklungsablauf vor jedem manuellen Merge.
