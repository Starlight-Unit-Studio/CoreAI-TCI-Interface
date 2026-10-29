---
name: release-handover
description: "Für konsistente Versionsstände und fortsetzbare Übergaben."
---
# Release und Übergabe

Jeder Merge nach main erfordert einen neuen numerischen Versionsstand gemäß docs/VERSIONING_POLICY.md. Pflege VERSION, sechs HTML-Seiten, JS-Bootstrap, SHA256-basierte numerische Cache-Keys, README, Changelog, TXT-Index, Selbsttests und ein neues Übergabeprotokoll gemeinsam. Bestehende historische Artefakte nicht umbenennen. Trenne verifizierten GitHub-Quellstand, Netcup-Deploy, Preflight, Modell-Livetest und fertig bestätigte Downloads. GitHub Actions sind keine verfügbare Erfolgsbedingung, solange das Kontingent ausgeschöpft ist. Dokumentiere offene Issues, gespeicherte Projektpläne und nächste reproduzierbare Arbeitsschritte; kein Erfolg ohne ausgeführte Prüfung.

Vergleiche die Branchversion vor jedem Merge mit main über scripts/version-merge-check.py. Prüfe Skillkatalog, Cache-Keys und Preflights lokal; ein offener PR und ein nicht ausgeführter Servertest sind keine bestätigte Auslieferung.
