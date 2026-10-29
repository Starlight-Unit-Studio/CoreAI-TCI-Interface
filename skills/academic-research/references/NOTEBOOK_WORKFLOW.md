# Reproduzierbare Jupyter-Notebooks zu wissenschaftlichen Aufgaben

## Ziel
Neben Literaturreviews Experimente oder Tutorials als validierte `.ipynb` ausgeben. Inhalt aus Nutzerauftrag ableiten; Inputdateien und Ausgabedaten autorisieren. Fachliche Referenz: https://github.com/openai/skills/tree/main/skills/.curated/jupyter-notebook; OpenAI-Repo ist inzwischen veraltet, daher Versionen und Werkzeuge bei Verwendung neu prüfen.

## Struktur
- Markdown-Einstieg: Forschungsfrage, Hypothese, Datenherkunft, Lizenzen, benötigte Umgebung, Zielgruppe und Reproduktionsziel
- Konfigurationszelle: Softwareversionen, Parameter, Seed und Dateipfade
- Datenladen und Datenvalidierung als eigenständige Zelle, mit Anzahl Zeilen und Schemakontrolle
- Verarbeitung, Modellierung/Statistik und Visualisierung als nachvollziehbare getrennte Schritte
- Ergebnis, Grenzen, Fehlerquellen und Literaturbelege am Ende
- `nbformat`-Version und Kernel-Metadaten sauber setzen, Zellen-IDs und Outputs konsistent halten

## Test- und Datenschutzregeln
- Keine geheimen API-Schlüssel, Gesundheits-/Kontoidentifikatoren oder privaten Dataset-Inhalte in Checkpoint-/Notebook-Outputs
- Externe Daten nur aus erlaubten Quellen laden, Datenstand festhalten, API-/Dataset-Version pinnen
- Ausführung von oben nach unten in frischem Kernel prüfen; State aus vorherigen Experimenten ausschließen
- Notebook-JSON erneut einlesen, Script-/Codezellen isoliert validieren und Resultate mit zugehörigem Paper vergleichen
- Retraktions-/Korrekturhinweise, Publikationsjahr und eventuelle methodische Schwächen für Zitierungen prüfen
- Kein erfundenes Notebook oder geschätztes numerisches Ergebnis als echten Berechnungslauf ausgeben

## Werkzeuge für spätere CoreAI-Integration
Datei-Workspace, Notebook-/JSON-Validator, Python/Jupyter/IPython-Kernel, Paketmanager mit Freigabe, isolierte Ausführung, DOI-/Forschungsrecherche, CSV/XLSX-Eingang, Plot-/Dateiexport und Tests. Diese Referenz steht bis zu separater Ressourcenladung nur als Planung bereit.
