# Model configuration and tool protocol policy

Stand: 2026-10-25. Verbindlich fuer CoreUI-Entwicklung.

## Referenzen und klare Modellgrenzen

- Das vom Betreiber gepostete E2B-`ollama show`-Modelfile liegt als archivierter Inhalt unter `docs/references/MODELFILE_E2B_USER_EXPORT_2026_10_25.txt`. Markdown-Darstellungs-Escapes der Nachricht wurden entfernt. Git-Blob-SHA-1: `bdf70ae29fde3d9172d823720be0f002fa518549`.
- Die archivierten absoluten `FROM`- und `DRAFT`-Blobpfade gehoeren zu diesem E2B-Export. Sie duerfen **nicht** als E4B-Basismodell in `config/Modelfile.template` eingesetzt werden. Das Archiv wird nicht vom Installer geladen.
- Version 0.5.35 hatte die erste portable Umsetzung des E2B-Exports aktiviert. Am 25.10.2026 wurde `config/Modelfile.template` auf `main` **separat verkleinert** (3043 auf 1717 Zeichen), mit neuer kompakter Mara-TCI-Beschreibung, `TSA-Thorn CoreUI` und `PARAMETER num_predict -1`. Diese aktuelle Version gilt; der historische E2B-Export bleibt als Referenz erhalten und darf nicht ungefragt zurueckkopiert werden. `FROM gemma4:e4b` bleibt der bestehende austauschbare Basis-Tag.
- Keine Skills, langen Coding-Anleitungen, RAG-Texte oder anderen dynamischen Kontexte in SYSTEM oder Modelfile einbauen. Neue Werkzeuge und Validierung zuerst im Backend umsetzen, sodass nur passende Skilltexte pro Anfrage geladen werden.
- Die **aktuelle kurze Main-Vorlage** (Git-Blob `8b0c17b954f0ddfe65947fbcbfa1453fd17521e3`) besitzt fuenf Parameter: `draft_num_predict 0`, `num_predict -1`, `temperature 1`, `top_k 64`, `top_p 0.95`. `num_ctx` bleibt aus. Dem lokalen Gemma werden weiterhin **keine API-`options`-Overrides** aufgezwungen; explizite Modelfile-Werte sind davon getrennt.

## Gezielte Werkzeug-Ausnahme

- Die `Werkzeugprotokoll:`-Sektion innerhalb des SYSTEM-Texts darf bei einer notwendigen, nachvollziehbar getesteten Werkzeuganpassung gezielt geaendert werden. Alle anderen Persona-Abschnitte bleiben ohne gesonderte Freigabe identisch.
- Minimaler Eingriff: nur Werkzeugsyntax, Dispatch, Begrenzung, Fehlerbehandlung oder Parser-Kompatibilitaet. Kein beiläufiges Umschreiben des Modelfiles oder Erweitern um Skillkataloge.
- Bestehende `[PY]`, `[WEB]` und `[BROWSE]`-Protokolle mit den tatsaechlichen Werkzeugen abgleichen. Skills erteilen keine neuen Rechte.
- Globale Ollama-Anfragen bleiben seriell. Ollama-Cloud-Modelle weiterhin ueber die lokale Ollama-Liste und denselben Endpoint waehlen.

## Installation bleibt unveraendert

`setup.sh`, `scripts/install.sh`, `scripts/install-native.sh`, `scripts/stack.sh`, der One-Liner und die bestehende Modell-Erstellungsroutine duerfen durch diesen Auftrag nicht geaendert werden. Jede Installationsaenderung bedarf eines separaten ausdruecklichen Auftrags.

## Pruefung und Grenzen

Der bestehende `scripts/gemma-zero-parameters-selftest.php` prueft den historischen Export nur auf Unveraendertheit und die **neue kompakte Live-Vorlage separat**, einschliesslich exakter Parameterreihenfolge. Der neue `scripts/model-live-audit.php` vergleicht zusaetzlich den tatsaechlich geladenen Ollama-SYSTEM-Text mit der aktuellen Vorlage. Diese Pruefung allein beweist keine produktive Ollama-/Netcup-Funktion. E2B-Erfolge beweisen keine gleichwertige E4B-Ausgabe. Die universelle Dateifortsetzung befindet sich separat in Draft PR #69.

Versionierung gemaess `docs/VERSIONING_POLICY.md`; keine GitHub Actions waehrend das Kontingent aufgebraucht ist.
