# Native Bildgenerierung: Ember Image Engine (Planungsstand 21.10.2026, ergänzt 28.10.2026)

**Stand 0.6.39 (07.11.2026): Schritte A, B und C sind gebaut.** Lauf des Bildtests auf dem Server (20261107-121303, 20 Threads, AVX-512): FLUX.2-klein 4B 63 s fuer 512 × 512 und 211 s fuer 1024 × 1024 bei 9,4 bzw. 14,4 GB RAM; Z-Image-Turbo 128 s und 606 s bei 12,3 bzw. 17,4 GB. Fast die ganze Zeit steckt in den Rechenschritten, das Laden fiel kaum ins Gewicht. Entscheidung des Betreibers: FLUX.2-klein 4B, Standard 512 × 512, 1024 nur auf Wunsch. Umsetzung: Bilddienst `tools/image/image_service.py` im Container `image` (Register `config/image/mara-image.json` mit Revision und SHA-256 je Datei), Auftrag in derselben seriellen Warteschlange wie die Cores (Wartende warten laenger statt aufzugeben), Werkzeug `[BILD: ...]` / `[BILD GROSS: ...]` in Maras Arbeitsschleife (`api/image_gen.php`), Bild als privater Anhang an Maras Antwort. Offen bleiben Bildbearbeitung nach Vorlage (FLUX.2-klein kann laut Hersteller auch mit Referenzbildern arbeiten) und die Abnahme auf dem Server.

**Stand 0.6.37 (07.11.2026): Schritt A, der Technologie-Test, ist gebaut und wartet auf den Lauf auf dem Server.**

- **Recherche 07.11.2026:** Ollama hatte im Januar 2026 eine experimentelle Bildgenerierung (Z-Image-Turbo, FLUX.2 Klein) zuerst für macOS angekündigt; laut Bericht von Anfang November ist sie vorerst entfernt und lief auf Linux nie. stable-diffusion.cpp (MIT, CPU mit AVX/AVX2/AVX-512) kann inzwischen deutlich kleinere Modelle als FLUX.1-schnell, darunter Z-Image (6B) und FLUX.2-klein (4B/9B). FLUX.2-klein 4B und Z-Image-Turbo stehen unter Apache 2.0, FLUX.2-klein 9B nur nicht-kommerziell. CPU-Messwerte gibt es öffentlich nicht.
- **Entscheidung des Betreibers (07.11.2026):** Im Test laufen Z-Image-Turbo (6B, 8 Schritte) und FLUX.2-klein 4B (4 Schritte), beide als Q8_0-GGUF von leejet, mit Qwen3-4B als Textverständnis und dem jeweiligen VAE. FLUX.1-schnell und SDXL-Turbo aus dem früheren Plan sind nicht mehr im Test.
- **Test:** `scripts/image-spike.sh` baut stable-diffusion.cpp `master-1046-d308544` (Commit `d30854463dbc75fb3671209b79016bb0d8ac321e`, ggml `ccb88f810be05bccc9621e706354611ccc2462c7`) in einem Container für genau die Server-CPU (`-march=native`), lädt die Gewichte (etwa 20 GB) mit Revision und SHA-256 (Abgleich mit Hugging Face, Lock-Datei je Modell), erzeugt ohne Netz drei Prompts in 512 × 512 und einen in 1024 × 1024 mit festem Seed und misst Laden, Textverständnis, Rechenschritte, Dekodieren, Gesamtzeit und Spitzen-RAM je Bild. Register: `tools/image_spike/models.json`; Ergebnisse in Admin Core > Betrieb > Bildproben (nur Inhaber) und `var/image-spike/runs/*/report.md`.
- **Danach:** Modellwahl mit echten Zeiten und Bildern, dann Schritt B (Modellregister mit festen SHA-256, Worker in der gemeinsamen seriellen Warteschlange) und C (Werkzeug im Chat).

**Ursprünglicher Status: geplant, nicht implementiert und nicht live gemessen.** Die Engine ist ein eigener Medienpfad im E.M.B.E.R. CoreUI. Sie ist kein Aufruf von ComfyUI, kein Wechsel der Textmodell-Laufzeit und kein fertiges Bildmodell. Schnittstellen, Gewichte und Lizenzbedingungen werden vor einer Aktivierung überprüft.

## Ziel und Einbindung

Der Nutzer fordert im Chat ein Bild an. Der Agent identifiziert den Medientool-Aufruf, erstellt einen validierten Job mit Modellauswahl und Ausgabeparametern und reiht ihn in **dieselbe globale serielle Modellwarteschlange** wie Ollama ein. Der Image Worker lädt geprüfte Modellkomponenten, verarbeitet den Prompt, führt Diffusion und Dekodierung nacheinander aus und schreibt ausschließlich in sein isoliertes Ausgabeverzeichnis. Das vorhandene Artefaktregister speichert die entstandene PNG-/WebP-Datei kontogebunden und bietet einen authentifizierten Download. Kein Downloadlink ohne tatsächlich existierende Datei.

**Wichtig:** Die bestehende P05-Warteschlange kapselt derzeit Ollama-HTTP-Aufrufe. Bevor native Bildinferenz aktiviert wird, muss der Modellslot zu einem providerunabhängigen Resource-/Lease-Vertrag erweitert werden. Ein unabhängiger Worker darf die globale Modellgrenze nicht umgehen. Speicherintensive Jobs erhalten zusätzlich ein RAM-Limit und ein Timeout.

## Kandidaten fuer native CPU-Inferenz

- **stable-diffusion.cpp** als C/C++-Referenz fuer die Diffusionsruntime mit GGML, pruefbaren CLI-/Bibliotheksgrenzen und CPU-Backends. Als bewusste Integrationsentscheidung eine isolierte native Binary oder eigener Adapter. Nicht ungeprueft fremden Code kopieren; Lizenz, Third-Party Notices, ABI und Wartungsstand pro Referenz-Commit erfassen.
- **FLUX.1-schnell** mit passenden, zusammengehoerigen Text-Encodern (z.B. CLIP-L und T5-XXL), Diffusion Transformer und VAE. Die benoetigten Formate und Quantisierungen haengen von der ausgewaehlten Runtime-Version ab; GGUF wird nicht fuer jeden Teil der Pipeline vorausgesetzt. Repository-, Gewichts- und Nutzungsbedingungen sind vor Distribution separat zu pruefen.
- **SDXL-Turbo** als alternativer Kandidat fuer sehr wenige Sampling-Schritte. Ein einzelner Step bedeutet keine garantierte einstellige Laufzeit und keine identische Pipeline zu FLUX.1. Runtime-Kompatibilitaet, benoetigte Encoder/VAE und Qualitaet pro Aufloesung sind gesondert zu validieren.
- Gewichtsquellen wie Hugging Face oder Konvertierungen von Dritten werden nur anhand einer festgehaltenen konkreten Version mit Hash, Herkunft und passender Lizenz akzeptiert. Die Modellgewichte werden nicht stillschweigend im Installationspaket mitgeliefert.

## Konkretes Startsetup (Betreiber-Recherche 28.10.2026, im Test zu bestätigen)

Die Recherche bestätigt die Richtung dieses Plans und nennt ein konkretes erstes Setup für FLUX.1 [schnell] im GGUF-Format, getrennt in drei Stufen, die nacheinander laufen:

| Stufe | Datei laut Recherche | Hinweis |
|---|---|---|
| Text-Encoder (Conditioning) | T5-XXL als GGUF, zum Beispiel `t5v1.1-xxl-encoder-Q4_K_M.gguf`, und CLIP-L, zum Beispiel `clip_l-Q8_0.gguf` | T5-XXL ist der größte RAM-Posten neben dem Transformer. |
| Transformer (Hauptmodell) | `flux1-schnell-Q8_0.gguf`, alternativ `Q5_K_M` für mehr Tempo | Laut Recherche rund 15 GB im RAM; die tatsächliche Größe zählt nach dem Download. FLUX.1 [schnell] ist für sehr wenige Schritte gebaut; gemessen wird mit 4 und mit 8 Schritten. |
| VAE-Decoder | `ae.safetensors` aus dem FLUX.1-schnell-Repository | Liefert die RGB-Pixel; stable-diffusion.cpp liest dieses Format direkt. |

- **Quelle der GGUF-Dateien:** Konvertierungen auf Hugging Face, laut Recherche am verlässlichsten von `city96`. Jede Datei wird mit Repository, Commit, SHA-256 und Lizenz im Modellregister festgehalten.
- **Lizenzen:** FLUX.1 [schnell] steht unter Apache 2.0, die Konvertierungen und der VAE werden pro Datei geprüft. **SDXL-Turbo** stand ursprünglich unter einer nur nicht-kommerziellen Lizenz von Stability AI; vor einem Einsatz ist die aktuell gültige Lizenz zu prüfen, sonst bleibt es ein reiner Testkandidat.
- **SDXL-Turbo als Sofort-Variante:** `sdxl_turbo_Q8_0.gguf` mit einem einzigen Schritt, geringere Qualität als FLUX. Die genannten Zeiten (FLUX 30 bis 60 Sekunden, SDXL-Turbo einstellige Sekunden auf 20 Kernen) sind Schätzungen der Recherche, keine Messwerte.
- **Integration in CoreAI:** Das Backend ist PHP mit eigenem Python-Worker. stable-diffusion.cpp wird als fertig kompiliertes Programm (Kommandozeile oder sein eingebauter Server) in einem isolierten Worker-Prozess mit festen Argumenten aufgerufen, so wie heute der Python-Worker. Die ggml-Rechenkerne werden weder nachgebaut noch herauskopiert; die Bibliothek bleibt eine geprüfte, versionierte Fremdkomponente mit Lizenzhinweis.
- **Host:** 20 Kerne und AVX-512 auf dem EPYC Turin werden vor dem Test auf dem echten Host bestätigt (zum Beispiel über `lscpu`), damit stable-diffusion.cpp mit den passenden CPU-Funktionen gebaut wird.

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
