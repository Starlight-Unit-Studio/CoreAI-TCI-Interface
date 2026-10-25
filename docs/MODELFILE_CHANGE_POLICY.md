# Model configuration and tool protocol policy

Stand: 2026-10-25. Verbindlich fuer CoreUI-Entwicklung.

## Referenzen und klare Modellgrenzen

- Das vom Betreiber gepostete E2B-`ollama show`-Modelfile liegt als archivierter Inhalt unter `docs/references/MODELFILE_E2B_USER_EXPORT_2026_10_25.txt`. Markdown-Darstellungs-Escapes der Nachricht wurden entfernt. Git-Blob-SHA-1: `bdf70ae29fde3d9172d823720be0f002fa518549`.
- Die archivierten absoluten `FROM`- und `DRAFT`-Blobpfade gehoeren zu diesem E2B-Export. Sie duerfen **nicht** als E4B-Basismodell in `config/Modelfile.template` eingesetzt werden. Das Archiv wird nicht vom Installer geladen.
- Ab Version 0.5.35 ist `config/Modelfile.template` die **aktive, portable Umsetzung der Benutzer-Vorlage**, nicht nur eine separate Archivnotiz. `FROM gemma4:e4b` bleibt ein einzelner, vom unveraenderten Installer ersetzbarer Basis-Tag. SYSTEM-Text und Reihenfolge der vier PARAMETER wurden aus dem bereitgestellten `ollama show`-Export uebernommen. Das Archiv mit E2B-Blob-Pfaden bleibt unveraendert.
- Keine Skills, langen Coding-Anleitungen, RAG-Texte oder anderen dynamischen Kontexte in SYSTEM oder Modelfile einbauen. Neue Werkzeuge und Validierung zuerst im Backend umsetzen, sodass nur passende Skilltexte pro Anfrage geladen werden.
- Die freigegebenen Modellparameter beibehalten: `draft_num_predict 0`, `temperature 1`, `top_k 64`, `top_p 0.95`. Lokalem Gemma weder im Modelfile noch ueber API-Overrides `num_ctx` oder `num_predict` aufdruecken.

## Gezielte Werkzeug-Ausnahme

- Die `Werkzeugprotokoll:`-Sektion innerhalb des SYSTEM-Texts darf bei einer notwendigen, nachvollziehbar getesteten Werkzeuganpassung gezielt geaendert werden. Alle anderen Persona-Abschnitte bleiben ohne gesonderte Freigabe identisch.
- Minimaler Eingriff: nur Werkzeugsyntax, Dispatch, Begrenzung, Fehlerbehandlung oder Parser-Kompatibilitaet. Kein beiläufiges Umschreiben des Modelfiles oder Erweitern um Skillkataloge.
- Bestehende `[PY]`, `[WEB]` und `[BROWSE]`-Protokolle mit den tatsaechlichen Werkzeugen abgleichen. Skills erteilen keine neuen Rechte.
- Globale Ollama-Anfragen bleiben seriell. Ollama-Cloud-Modelle weiterhin ueber die lokale Ollama-Liste und denselben Endpoint waehlen.

## Installation bleibt unveraendert

`setup.sh`, `scripts/install.sh`, `scripts/install-native.sh`, `scripts/stack.sh`, der One-Liner und die bestehende Modell-Erstellungsroutine duerfen durch diesen Auftrag nicht geaendert werden. Jede Installationsaenderung bedarf eines separaten ausdruecklichen Auftrags.

## Pruefung und Grenzen

Der bestehende `scripts/gemma-zero-parameters-selftest.php` prueft die unveraenderte Referenz, portable Modellbasis, Format der SYSTEM-Anweisung, Persona-Inhalte ausserhalb der Werkzeugsektion sowie die exakte Reihenfolge der genehmigten Parameter. Diese Pruefung allein beweist keine produktive Ollama-/Netcup-Funktion. E2B-Erfolge beweisen keine gleichwertige E4B-Ausgabe. Die universelle Dateifortsetzung befindet sich separat in Draft PR #69.

Versionierung gemaess `docs/VERSIONING_POLICY.md`; keine GitHub Actions waehrend das Kontingent aufgebraucht ist.
