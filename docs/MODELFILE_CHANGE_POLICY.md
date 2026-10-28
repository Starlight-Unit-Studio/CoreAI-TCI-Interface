# Model configuration and tool protocol policy

Stand: 2026-10-25. Verbindlich fuer CoreUI-Entwicklung.

## Referenzen und klare Modellgrenzen

- Das vom Betreiber gepostete E2B-`ollama show`-Modelfile liegt als archivierter Inhalt unter `docs/references/MODELFILE_E2B_USER_EXPORT_2026_10_25.txt`. Markdown-Darstellungs-Escapes der Nachricht wurden entfernt. Git-Blob-SHA-1: `bdf70ae29fde3d9172d823720be0f002fa518549`.
- Die archivierten absoluten `FROM`- und `DRAFT`-Blobpfade gehoeren zu diesem E2B-Export. Sie duerfen **nicht** als E4B-Basismodell in `config/Modelfile.template` eingesetzt werden. Das Archiv wird nicht vom Installer geladen.
- 0.5.43 stellte den archivierten Originaltext voruebergehend wieder her. Seit dem anschliessenden Betreiber-Commit vom 25.10.2026 ist die aktive Vorlage bewusst eigenstaendig bearbeitet. Sie ist die massgebliche Quelle fuer Mara-SYSTEM und Ollama-PARAMETER, nicht der archivierte E2B-Export.
- Keine Skills, langen Coding-Anleitungen, RAG-Texte oder anderen dynamischen Kontexte in SYSTEM oder Modelfile einbauen. Neue Werkzeuge und Validierung zuerst im Backend umsetzen, sodass nur passende Skilltexte pro Anfrage geladen werden.
- Ollama-Parameter aus der aktiven Betreiber-Vorlage, einschliesslich eines gegebenenfalls gesetzten num_predict, sind zu respektieren. CoreUI fuegt fuer lokale Gemma-Aufrufe keine eigenen Sampler-, Kontext- oder Token-Overrides im API-Payload hinzu. Das Runtime-Werkzeugprotokoll bleibt ausserhalb des Modelfiles.

## Gezielte Werkzeug-Ausnahme

- Die `Werkzeugprotokoll:`-Sektion innerhalb des SYSTEM-Texts darf bei einer notwendigen, nachvollziehbar getesteten Werkzeuganpassung gezielt geaendert werden. Alle anderen Persona-Abschnitte bleiben ohne gesonderte Freigabe identisch.
- Minimaler Eingriff: nur Werkzeugsyntax, Dispatch, Begrenzung, Fehlerbehandlung oder Parser-Kompatibilitaet. Kein beiläufiges Umschreiben des Modelfiles oder Erweitern um Skillkataloge.
- Bestehende `[PY]`, `[WEB]` und `[BROWSE]`-Protokolle mit den tatsaechlichen Werkzeugen abgleichen. Skills erteilen keine neuen Rechte.
- Globale Ollama-Anfragen bleiben seriell. Ollama-Cloud-Modelle weiterhin ueber die lokale Ollama-Liste und denselben Endpoint waehlen.

## Freigabe vom 28.10.2026: Denken per Schalter (0.5.75)

- Der Betreiber hat freigegeben, `<|think|>` am Anfang des SYSTEM-Texts zu entfernen. Sonst bleibt der SYSTEM-Text Wort fuer Wort gleich, ebenso alle PARAMETER.
- Grund: Das feste Token liess Mara bei jeder Anfrage denken, auch mit `think: false`; der Schalter im KI-Profil konnte das Denken nur verbergen (gemessen: 22 bis 28 Sekunden bis zum ersten Wort). Ollamas Gemma-4-Renderer (`RENDERER gemma4`) setzt `<|think|>` selbst in den System-Turn, wenn eine Anfrage `think: true` schickt.
- Der Denkschalter sitzt seit 0.5.75 im Chat (Core-Chip unter dem Eingabefeld). Der globale Kanal sendet weiterhin `think: true` und verhaelt sich damit wie bisher.
- Der normale Updater (`scripts/update-from-main.sh` ruft `scripts/install.sh` auf) erzeugt `var/Modelfile.generated` und Maras Core neu; danach meldet `scripts/model-live-audit.php` wieder Uebereinstimmung.

## Installation bleibt unveraendert

`setup.sh`, `scripts/install.sh`, `scripts/install-native.sh`, `scripts/stack.sh`, der One-Liner und die bestehende Modell-Erstellungsroutine duerfen durch diesen Auftrag nicht geaendert werden. Jede Installationsaenderung bedarf eines separaten ausdruecklichen Auftrags.

## Pruefung und Grenzen

Der `scripts/gemma-zero-parameters-selftest.php` prueft den archivierten E2B-Export nur auf Unveraendertheit, die aktive Betreiber-Vorlage auf Struktur und gueltige Parameter sowie ein vorhandenes `var/Modelfile.generated` auf Uebereinstimmung mit der aktiven Fassung. Er verlangt keinen historischen SYSTEM-Text und keine historischen vier PARAMETER-Zeilen mehr. Der `scripts/model-live-audit.php` vergleicht weiterhin das tatsaechlich geladene Ollama-SYSTEM mit der aktuellen Vorlage. Diese Pruefung allein beweist keine produktive Ollama-/Netcup-Funktion. E2B-Erfolge beweisen keine gleichwertige E4B-Ausgabe. Die universelle Dateifortsetzung befindet sich separat in Draft PR #69.

Versionierung gemaess `docs/VERSIONING_POLICY.md`; keine GitHub Actions waehrend das Kontingent aufgebraucht ist.
