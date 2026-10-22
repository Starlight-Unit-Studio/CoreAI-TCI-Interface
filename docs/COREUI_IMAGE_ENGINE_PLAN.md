# Native Bildgenerierung: Ember Image Engine (Planungsstand 21.10.2026)

**Status: geplant, nicht implementiert und nicht live gemessen.** Die Engine ist ein eigener Medienpfad im E.M.B.E.R. CoreUI. Sie ist kein Aufruf von ComfyUI, kein Wechsel der Textmodell-Laufzeit und kein fertiges Bildmodell. Schnittstellen, Gewichte und Lizenzbedingungen werden vor einer Aktivierung überprüft.

## Ziel und Einbindung

Der Nutzer fordert im Chat ein Bild an. Der Agent identifiziert den Medientool-Aufruf, erstellt einen validierten Job mit Modellauswahl und Ausgabeparametern und reiht ihn in **dieselbe globale serielle Modellwarteschlange** wie Ollama ein. Der Image Worker lädt geprüfte Modellkomponenten, verarbeitet den Prompt, führt Diffusion und Dekodierung nacheinander aus und schreibt ausschließlich in sein isoliertes Ausgabeverzeichnis. Das vorhandene Artefaktregister speichert die entstandene PNG-/WebP-Datei kontogebunden und bietet einen authentifizierten Download. Kein Downloadlink ohne tatsächlich existierende Datei.

**Wichtig:** Die bestehende P05-Warteschlange kapselt derzeit Ollama-HTTP-Aufrufe. Bevor native Bildinferenz aktiviert wird, muss der Modellslot zu einem providerunabhängigen Resource-/Lease-Vertrag erweitert werden. Ein unabhängiger Worker darf die globale Modellgrenze nicht umgehen. Speicherintensive Jobs erhalten zusätzlich ein RAM-Limit und ein Timeout.

## Kandidaten fuer native CPU-Inferenz

- **stable-diffusion.cpp** als C/C++-Referenz fuer die Diffusionsruntime mit GGML, pruefbaren CLI-/Bibliotheksgrenzen und CPU-Backends. Als bewusste Integrationsentscheidung eine isolierte native Binary oder eigener Adapter. Nicht ungeprueft fremden Code kopieren; Lizenz, Third-Party Notices, ABI und Wartungsstand pro Referenz-Commit erfassen.
- **FLUX.1-schnell** mit passenden, zusammengehoerigen Text-Encodern (z.B. CLIP-L und T5-XXL), Diffusion Transformer und VAE. Die benoetigten Formate und Quantisierungen haengen von der ausgewaehlten Runtime-Version ab; GGUF wird nicht fuer jeden Teil der Pipeline vorausgesetzt. Repository-, Gewichts- und Nutzungsbedingungen sind vor Distribution separat zu pruefen.
- **SDXL-Turbo** als alternativer Kandidat fuer sehr wenige Sampling-Schritte. Ein einzelner Step bedeutet keine garantierte einstellige Laufzeit und keine identische Pipeline zu FLUX.1. Runtime-Kompatibilitaet, benoetigte Encoder/VAE und Qualitaet pro Aufloesung sind gesondert zu validieren.
- Gewichtsquellen wie Hugging Face oder Konvertierungen von Dritten werden nur anhand einer festgehaltenen konkreten Version mit Hash, Herkunft und passender Lizenz akzeptiert. Die Modellgewichte werden nicht stillschweigend im Installationspaket mitgeliefert.

## Modulvertrag

1. **Model Registry:** `id`, `family`, `runtime`, Encoder-/UNet-/Transformer-/VAE-Pfade, Quantisierung, SHA-256, Speicherbedarf, Lizenz, unterstützte Auflösungen und Optionen; Pfade nur innerhalb des kontrollierten Modellverzeichnisses.
2. **Generator API:** Bildprompt, optionaler negativer Prompt soweit modellkompatibel, Seed, Breite/Hoehe, Sampling-Schritte, Konfiguration/Guidance nach Modellfamilie, Dateiformat und Limit. Serverseitige Parameter-Validierung; keine rohen Shellargumente aus Chattext.
3. **Job- und Worker-Layer:** Eigentümer/Sitzung/Turn/Run/Job, `queued|running|completed|failed|cancelled|interrupted`, CPU-Thread-Limit, Timeouts, Ressourcen- und Prozessisolation, Abbruch, nachweisbare Werkzeugereignisse. Neustart startet einen ungewiss beendeten Job nie blind erneut.
4. **Native Backend Adapter:** CPU-Features zur Laufzeit feststellen und nur kompatible Instruktionen verwenden; Thread- und NUMA-Konfiguration messen. C-FFI nur nach ABI-/Speichersicherheitsabnahme, sonst isolierter Kommandozeilenprozess mit festen Argumenten.
5. **Artifact Bridge:** Fertige RGB-Pixel korrekt dekodieren, echte PNG/WebP-Datei und Metadaten persistieren, Vorschau und autorisierten Download auch nach Reload anbieten. Keine Ausgabe-Scheinerfolge.
6. **UI:** Modellwahl (optional), Aufloesung, Seed, Fortschritt auf Basis realer Steps, Speicherhinweis, Abbrechen und erneutes Generieren; Android-Chrome-tauglich.

## Benchmarks und Kapazitaet

Als Serverziel wird eine EPYC-Turin-CPU mit 96 GB DDR5 ECC RAM und CPU-only-Inferenz angenommen; **20 dedizierte Kerne, AVX-512 und bestimmte Takt-/NUMA-Werte muessen auf dem echten Host belegt werden**, bevor Optimierungen fest zugesagt werden. Erfasst werden Cold-/Warm-Start, Encoder-, Sampling- und VAE-Zeit, Peak-RSS, CPU-Auslastung, Bildqualitaet, Aufloesung, Steps und Q-Stufe sowie Wechselwirkung mit laufenden Chatjobs. Besonders grosse T5-Encoder und parallele Gewichtsbelegung begrenzen das RAM-Budget. 30–60 s fuer FLUX oder einstellige Sekunden fuer SDXL-Turbo sind **keine bestaetigten Zielwerte**.

## Staffelung und Abnahme

**A. Technologie-Spike:** Aktuelle upstream API, Lizenz und Gewichtskompatibilitaet pruefen; auf CPU ein definiertes Testbild mit gemessener Zeit und Speicher erstellen.

**B. P20-Native-Adapter:** Config, Modellregister, Jobworker, gemeinsame serielle Queue, Aufloesungs-/Threadlimits und konfigurierbare Installation/Updates ohne Downloads versteckter Gewichte.

**C. CoreUI-Integration:** Agent-Tool, Progress und Cancellation, geschuetzter Dateipfad, Chat-Vorschau und Download, gespeicherte Sitzung und mobile Bedienung.

**D. Abnahme:** Reproduzierbarer Prompt/Seed, echter Download nach Reload, Trennung zweier Konten, ungueltige Modell- oder Bildparameter, fehlende Gewichte, niedriger RAM, Worker-Neustart, Stop und no-overlap mit Ollama. Messdaten und Lizenznachweise dokumentieren.

Offen bleiben erweiterte Bildbearbeitung (img2img/inpainting/outpainting), Video und Audio; sie werden als separate Unteraufgaben von P20 inventarisiert. Das Ziel ist native **Integration**, nicht die unrealistische Neuentwicklung jedes mathematischen Kernels.
