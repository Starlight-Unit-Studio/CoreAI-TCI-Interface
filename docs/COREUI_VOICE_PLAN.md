# Mara hört und spricht: Sprachplan (Planungsstand 28.10.2026)

**Status: geplant, nicht implementiert und nicht live gemessen.** Dieser Plan beschreibt die Spracheingabe, das Hören von Videos, die Sprachausgabe und das Live-Gespräch in CoreAI: TCI Interface. Er ergänzt den [Bild-Technikplan](COREUI_IMAGE_ENGINE_PLAN.md) und bildet zusammen mit ihm das Paket P20 der [Roadmap](../ROADMAP.md) (Matrix `CF-057`, `CF-058`, `CF-059`).

## Entscheidungen des Betreibers (28.10.2026)

- **Reihenfolge:** zuerst Sprache, dann Bildgenerierung, danach der Projekt-Workspace (P09).
- **Beides anbieten:** den normalen Audiomodus wie in anderen Chats (Antwort als Text, Lautsprecher-Knopf zum Vorlesen) und ein Live-Gespräch wie bei Gemini oder ChatGPT.
- **Sprachausgabe darf parallel laufen:** Ausnahme von der Regel "höchstens eine Modellanfrage gleichzeitig" nur für die Sprachausgabe (TTS), mit festem Kernlimit. Spracherkennung und Antworten bleiben in der seriellen Core-Warteschlange.
- **Direkt eine eigene Mara-Stimme** auf dem Server, keine Gerätestimme als Zwischenlösung.

## Technische Grundlage

**Hören über Core:** Mara hört selbst; ein separates Spracherkennungsmodell ist nicht nötig. Betreiber-Recherche vom 28.10.2026 (Ollama-Bibliothek `gemma4`, Ollama-Issue #15427, Community-Berichte), im Test P20.0 auf dem echten Server zu bestätigen:

| Punkt | Stand der Recherche | Folge für CoreAI |
|---|---|---|
| Cores mit Audio | `gemma4:e2b`, `gemma4:e4b`, `gemma4:12b` haben den Audio-Encoder. `gemma4:26b` und `gemma4:31b` können nur Text und Bild. | Maras Modelfile (`FROM gemma4:e4b`) kann hören. Ist im KI-Profil ein Core ohne Audio gewählt (26b, 31b, Cloud), wird der Mikrofon-Knopf deaktiviert und klar begründet. Erkennung über die Fähigkeiten aus `/api/show`, falls Ollama dort Audio meldet, sonst über eine geprüfte Namensliste. |
| Übergabe | Audio wird wie ein Bild im `images`-Array der Nutzernachricht übergeben. | Derselbe Weg wie beim Bildpfad; Bild und Ton können in einer Anfrage stehen. |
| Format | WAV mit vollständigem RIFF-Header, exakt 16 kHz, Mono, **16 Bit PCM signed**. Rohes PCM ohne Header wird nicht erkannt; 24 Bit, 32 Bit Float oder andere Formate führen meist zum Abbruch oder zu unbrauchbarem Rauschen. | Der Server wandelt jede Aufnahme und jede Datei mit ffmpeg um (`-ac 1 -ar 16000 -c:a pcm_s16le`, WAV-Container) und prüft danach den Header (RIFF, Mono, 16 kHz, 16 Bit), bevor etwas an Ollama geht. Browserformate wie WebM/Opus gehen nie direkt an Ollama. |
| Länge | Höchstens 30 bis 60 Sekunden am Stück; längere Dateien führen zu Fehlern oder abgeschnittenem Kontext. | Abschnitte von höchstens 30 Sekunden, bevorzugt an Sprechpausen geschnitten, mit Zeitmarken. Der Test prüft, ob 60 Sekunden stabil laufen; erst dann wird die Grenze angehoben. |

Offen für den Test bleiben die Tokenkosten je Sekunde Audio, die Zeit bis zur ersten Antwort und die Qualität bei Deutsch, Dialekt und Hintergrundgeräusch.

**Sprechen braucht eine eigene Engine:** Core antwortet als Text. Für Maras Stimme läuft eine eigene Sprachausgabe (TTS) auf der Server-CPU. Kandidaten werden im Test verglichen, unter anderem **Piper** (schnell auf CPU, deutsche Stimmen) und mindestens ein natürlicher klingender, schwererer Kandidat. Kriterien: deutsche Aussprache und Betonung, Echtzeitfaktor auf der CPU, RAM, Lizenz der Engine **und** der einzelnen Stimme, Weitergabe im Installationspaket. Keine geklonte Stimme einer realen Person ohne deren Einwilligung. Gewichte werden wie beim Bildplan nicht stillschweigend mitgeliefert, sondern mit Herkunft, SHA-256 und Lizenz registriert.

**Ausnahme von der seriellen Regel:** Höchstens **ein** TTS-Auftrag gleichzeitig, mit festem Kernlimit (Wert im Test messen) und eigenem Zeitlimit. TTS darf parallel zu genau einer laufenden Core-Anfrage arbeiten. Alles andere (Hören, Antworten, Gedächtnis, Werkzeuge) bleibt in der globalen Warteschlange.

## Lieferungen

### P20.0: Test auf dem echten Server (zuerst)

- Audio über Ollama: Bestätigung des recherchierten Formats (WAV mit RIFF-Header, 16 kHz, Mono, 16 Bit PCM signed), stabile Länge pro Clip (30 oder 60 Sekunden), Fehlerbild bei zu langen oder falsch formatierten Dateien, Bild und Ton gemeinsam in einer Anfrage, Audio-Fähigkeit in `/api/show` für e2b, e4b, 12b und 26b/31b.
- Messung für 5, 15 und 30 Sekunden Sprache mit e4b: Eingabe-Tokens, Zeit bis zum ersten Antworttoken, Qualität bei Deutsch, Dialekt und Hintergrundgeräusch.
- TTS-Kandidaten: Echtzeitfaktor bei 2 und 4 Kernen, Peak-RAM, Hörproben derselben Sätze, Lizenzlage.
- Ende-zu-Ende-Latenz einer Live-Runde (Sprechpause bis erster gesprochener Satz) mit und ohne parallele TTS.
- **Ergebnis:** Messprotokoll im Repo, Auswahl der Mara-Stimme, festgelegte Grenzwerte (Clip-Länge, Kernlimit, Zeitlimits). Keine Zusage von Latenzen vor dieser Messung.

### P20.1: Spracheingabe

- Mikrofon-Knopf im Eingabefeld, auch auf Android Chrome und Samsung DeX. Aufnahme im Browser, sichtbare Dauer, Abbrechen, Senden.
- Sprachnachrichten und Audiodateien (zum Beispiel MP3, M4A, WAV, OGG) als Anhang, genau wie Bilder.
- Server: Umwandlung mit ffmpeg in WAV (RIFF), 16 kHz, Mono, 16 Bit PCM signed, mit Header-Prüfung vor dem Senden; Aufteilung langer Aufnahmen in Abschnitte von höchstens 30 Sekunden an Sprechpausen, in fester Reihenfolge mit Zeitmarken.
- Mara hört die Aufnahme direkt und antwortet. Im Chat steht eine **Mitschrift** dessen, was gesagt wurde, damit der Verlauf lesbar und durchsuchbar bleibt. Ob die Mitschrift im selben Core-Aufruf (Marker) oder in einem eigenen kurzen Aufruf entsteht, entscheidet der Test nach Zuverlässigkeit und Zeit.
- Cores ohne Audio (`gemma4:26b`, `gemma4:31b`, Cloud-Cores ohne Audioeingang) melden das klar, statt etwas zu erfinden; der Mikrofon-Knopf ist dann deaktiviert.
- Datenschutz: Aufnahmen sind kontogebunden wie andere Anhänge; Option, die Audiodatei nach der Mitschrift automatisch zu löschen.

### P20.2: Videos hören

- ffmpeg trennt die Tonspur (WAV, 16 kHz, Mono, 16 Bit PCM signed) und teilt sie in Abschnitte von höchstens 30 Sekunden. Bilder und passende Tonabschnitte gehen gemeinsam in dieselbe Anfrage, mit Zeitmarken.
- Der heutige Hinweis "Ton hast du nicht" entfällt, wenn eine Tonspur vorhanden ist; Videos ohne Ton bleiben wie bisher.
- Längengrenzen und Stichproben werden offen genannt (welche Abschnitte gehört und gesehen wurden).

### P20.3: Sprachausgabe (Vorlesen)

- Lautsprecher-Knopf unter jeder Mara-Antwort, Pause und Stopp; optional "Antworten automatisch vorlesen" im KI-Profil.
- Vor dem Sprechen wird der Text aufbereitet: Codeblöcke, Links und Tabellen werden nicht vorgelesen, sondern kurz benannt; Zahlen, Daten und Uhrzeiten deutsch ausgesprochen.
- Wiedergabe startet nach dem ersten Satz (satzweise Erzeugung), nicht erst nach der ganzen Antwort.
- Erzeugte Audiodateien werden kontogebunden zwischengespeichert, damit erneutes Abspielen nichts neu berechnet; Aufräumen nach einer festen Frist.
- Eine feste Mara-Stimme mit festen Parametern, überall gleich.

### P20.4: Live-Gespräch

- Eigener Gesprächsmodus (Vollbild, auch am Handy): freihändig mit Sprechpausen-Erkennung im Browser oder per Halten-zum-Sprechen.
- Ablauf je Runde: Sprechpause erkannt, Aufnahme an den Server, Mara hört und antwortet, Antwort wird satzweise gesprochen, sobald der erste Satz fertig ist (parallele TTS nach Betreiberentscheidung).
- **Unterbrechen:** Spricht der Nutzer, während Mara redet, stoppt die Wiedergabe sofort und der laufende Auftrag wird über den vorhandenen Agenten-Abbruch beendet. Nichts wird doppelt ausgelöst.
- Beide Seiten landen als normale Nachrichten (Mitschrift und Antwort) in der Sitzung und sind danach im Chat lesbar.
- Echo- und Rauschunterdrückung über die Browser-Audioeinstellungen; Bildschirm bleibt während des Gesprächs an.
- Hintergrundaufgaben, die Core belegen (Gedächtnis-Reflexion, Beziehungswert), warten während eines Live-Gesprächs, damit keine Runde hinter ihnen ansteht.
- Ehrliche Grenze: Gemma antwortet als Text, die Stimme kommt aus der TTS. Das ist ein schneller Rundenwechsel, kein natives Sprache-zu-Sprache-Modell. Die erreichbare Latenz legt P20.0 fest.

### P20.5: Bildgenerierung

Nach dem vorhandenen [Bild-Technikplan](COREUI_IMAGE_ENGINE_PLAN.md): zuerst der Test (Zeit und RAM pro Bild auf dem Server), dann die Anbindung als Werkzeug, das Mara selbst wählt, mit Vorschau, Download und Abbruch. Für die Bildengine gilt die strenge serielle Regel weiter; sie ist keine Ausnahme wie die TTS.

## Abnahme (gemeinsam für P20.1 bis P20.4)

- Aufnahme, Senden, Mitschrift und Antwort funktionieren auf Desktop, Android Chrome und DeX; Mikrofonverweigerung wird verständlich gemeldet.
- Eine Audiodatei und ein Video mit Ton werden gehört; die Antwort bezieht sich nachweislich auf das Gesagte.
- Vorlesen startet nach dem ersten Satz, lässt sich stoppen und erneut abspielen, ohne neu zu rechnen.
- Live-Gespräch: drei Runden hintereinander, eine Unterbrechung mittendrin, danach steht alles korrekt im Verlauf, keine doppelte Antwort.
- Nie mehr als eine Core-Anfrage und eine TTS gleichzeitig; Kernlimit eingehalten; Kontotrennung für Aufnahmen und Audiodateien.
- Messwerte und Lizenznachweise der gewählten Stimme sind dokumentiert.
