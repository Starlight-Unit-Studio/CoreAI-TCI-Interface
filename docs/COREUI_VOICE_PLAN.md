# Mara hört und spricht: Sprachplan (Planungsstand 28.10.2026)

**Status: geplant und noch nicht implementiert. Das Hören über Ollama ist auf dem echten Server gemessen (P20.0, drei Läufe am 28.10.2026, siehe [Messprotokoll](#messprotokoll-p200-28102026)); Stimme und Live-Latenz ohne festes Denken sind noch nicht gemessen.** Dieser Plan beschreibt die Spracheingabe, das Hören von Videos, die Sprachausgabe und das Live-Gespräch in CoreAI: TCI Interface. Er ergänzt den [Bild-Technikplan](COREUI_IMAGE_ENGINE_PLAN.md) und bildet zusammen mit ihm das Paket P20 der [Roadmap](../ROADMAP.md) (Matrix `CF-057`, `CF-058`, `CF-059`).

## Entscheidungen des Betreibers (28.10.2026)

- **Reihenfolge:** zuerst Sprache, dann Bildgenerierung, danach der Projekt-Workspace (P09).
- **Beides anbieten:** den normalen Audiomodus wie in anderen Chats (Antwort als Text, Lautsprecher-Knopf zum Vorlesen) und ein Live-Gespräch wie bei Gemini oder ChatGPT.
- **Sprachausgabe darf parallel laufen:** Ausnahme von der Regel "höchstens eine Modellanfrage gleichzeitig" nur für die Sprachausgabe (TTS), mit festem Kernlimit. Spracherkennung und Antworten bleiben in der seriellen Core-Warteschlange.
- **Direkt eine eigene Mara-Stimme** auf dem Server, keine Gerätestimme als Zwischenlösung.
- **Denken per Schalter (0.5.75):** `<|think|>` kommt aus dem Modelfile. Ob Mara denkt, entscheidet der Denkschalter im Chat (`think` je Anfrage, Ollamas Gemma-4-Renderer setzt `<|think|>` dann selbst). Mitschrift und Live-Gespräch laufen ohne Denken.
- **Testfreigabe `temperature 0` (0.5.75):** ausschließlich für die Testvariante "vorlage_temp0" des Medien-Tests, nie im Chat.

## Technische Grundlage

**Hören über Core:** Mara hört selbst; ein separates Spracherkennungsmodell ist nicht nötig. Betreiber-Recherche vom 28.10.2026 (Ollama-Bibliothek `gemma4`, Ollama-Issue #15427, Community-Berichte), danach im Test P20.0 auf dem echten Server geprüft:

| Punkt | Stand der Recherche | Folge für CoreAI (nach der Messung) |
|---|---|---|
| Cores mit Audio | `gemma4:e2b`, `gemma4:e4b`, `gemma4:12b` haben den Audio-Encoder. `gemma4:26b` und `gemma4:31b` können nur Text und Bild. | Maras Modelfile (`FROM gemma4:e4b`) kann hören. Ist im KI-Profil ein Core ohne Audio gewählt, wird der Mikrofon-Knopf deaktiviert und klar begründet. **Gemessen:** Ollama 0.40.6 meldet `"audio"` in den Fähigkeiten von `/api/show` zuverlässig (der Cloud-Core `gemma4:31b-cloud` meldet es nicht). Die Erkennung läuft deshalb über diese Fähigkeit, nicht über eine Namensliste. Auf dem Server melden auch `gemma4:26b`, `e4b` und `e2b` jeweils 7,5B und Audio; ob diese Namen auf dieselben Gewichte zeigen, zeigt der Digest im Test ab 0.5.74. |
| Übergabe | Audio wird wie ein Bild im `images`-Array der Nutzernachricht übergeben. | Derselbe Weg wie beim Bildpfad; Bild und Ton können in einer Anfrage stehen. |
| Format | WAV mit vollständigem RIFF-Header, exakt 16 kHz, Mono, **16 Bit PCM signed**. Rohes PCM ohne Header wird nicht erkannt; 24 Bit, 32 Bit Float oder andere Formate führen meist zum Abbruch oder zu unbrauchbarem Rauschen. | Der Server wandelt jede Aufnahme und jede Datei mit ffmpeg um (`-ac 1 -ar 16000 -c:a pcm_s16le`, WAV-Container) und prüft danach den Header (RIFF, Mono, 16 kHz, 16 Bit), bevor etwas an Ollama geht. Browserformate wie WebM/Opus gehen nie direkt an Ollama. **Gemessen:** Ollama 0.40.6 nahm auch 24 Bit, 32 Bit Float und 44,1 kHz Stereo an und schrieb sie brauchbar mit; nur rohes PCM ohne Header scheitert (HTTP 400, "Failed to load image or audio file"). Die Umwandlung bleibt trotzdem Pflicht: kleinere Dateien, immer gleiche Tokenkosten, keine Abhängigkeit von einer Ollama-Version. |
| Länge | Höchstens 30 bis 60 Sekunden am Stück; längere Dateien führen zu Fehlern oder abgeschnittenem Kontext. | **Gemessen:** 60 Sekunden laufen stabil; die Mitschrift reicht bis zum Ende, ohne Abbruch und ohne Wiederholungsschleife. Abschnitte deshalb bis **60 Sekunden**, bevorzugt an Sprechpausen geschnitten, mit Zeitmarken. |

Gemessen sind die Tokenkosten (25 Tokens je Sekunde Audio) und die Qualität bei Fernsehton. Offen sind die Zeit bis zur ersten Antwort ohne Denken, die Zuverlässigkeit bei sehr kurzen Aufnahmen und die Qualität bei direkt ins Handy gesprochener Sprache und Dialekt; dafür ist der Test in 0.5.74 erweitert.

**Sprechen braucht eine eigene Engine:** Core antwortet als Text. Für Maras Stimme läuft eine eigene Sprachausgabe (TTS) auf der Server-CPU. Kandidaten werden im Test verglichen, unter anderem **Piper** (schnell auf CPU, deutsche Stimmen) und mindestens ein natürlicher klingender, schwererer Kandidat. Kriterien: deutsche Aussprache und Betonung, Echtzeitfaktor auf der CPU, RAM, Lizenz der Engine **und** der einzelnen Stimme, Weitergabe im Installationspaket. Keine geklonte Stimme einer realen Person ohne deren Einwilligung. Gewichte werden wie beim Bildplan nicht stillschweigend mitgeliefert, sondern mit Herkunft, SHA-256 und Lizenz registriert.

**Ausnahme von der seriellen Regel:** Höchstens **ein** TTS-Auftrag gleichzeitig, mit festem Kernlimit (Wert im Test messen) und eigenem Zeitlimit. TTS darf parallel zu genau einer laufenden Core-Anfrage arbeiten. Alles andere (Hören, Antworten, Gedächtnis, Werkzeuge) bleibt in der globalen Warteschlange.

## Lieferungen

### P20.0: Test auf dem echten Server (zuerst)

- Audio über Ollama: Bestätigung des recherchierten Formats (WAV mit RIFF-Header, 16 kHz, Mono, 16 Bit PCM signed), stabile Länge pro Clip (30 oder 60 Sekunden), Fehlerbild bei zu langen oder falsch formatierten Dateien, Bild und Ton gemeinsam in einer Anfrage, Audio-Fähigkeit in `/api/show` für e2b, e4b, 12b und 26b/31b.
- Messung für 5, 15 und 30 Sekunden Sprache mit e4b: Eingabe-Tokens, Zeit bis zum ersten Antworttoken, Qualität bei Deutsch, Dialekt und Hintergrundgeräusch.
- TTS-Kandidaten: Echtzeitfaktor bei 2 und 4 Kernen, Peak-RAM, Hörproben derselben Sätze, Lizenzlage.
- Ende-zu-Ende-Latenz einer Live-Runde (Sprechpause bis erster gesprochener Satz) mit und ohne parallele TTS.
- **Ergebnis:** Messprotokoll im Repo, Auswahl der Mara-Stimme, festgelegte Grenzwerte (Clip-Länge, Kernlimit, Zeitlimits). Keine Zusage von Latenzen vor dieser Messung.
- **Stand 28.10.2026:** Hören über Ollama zweimal gemessen (Messprotokoll unten), Clip-Länge auf 60 Sekunden festgelegt. Offen: Messung mit dem erweiterten Test (0.5.74), TTS-Kandidaten, Ende-zu-Ende-Latenz einer Live-Runde.

**So läuft der Test (ab 0.5.73):** `bash scripts/media-spike.sh` im Projektordner auf dem Server. Optionen:
- `--audio DATEI`: eine eigene Sprachaufnahme, etwa vom Handy, in beliebigem Format.
- `--tts "NAME=BEFEHL"`: Stimmen-Kandidaten, mehrfach möglich, mit `{text}` und `{out}` als Platzhaltern.
- `--image "BEFEHL"`: ein Bildbefehl mit `{out}` und `{threads}`.
- `--skip-formats`: die absichtlich falschen Formate weglassen (in beiden Läufen bereits gemessen).
- `--short LISTE` und `--repeat N` (ab 0.5.74): Längen und Wiederholungen der kurzen Aufnahmen, Standard 3,5 und 5; `--repeat 0` schaltet sie ab.

Was der Test macht:
- Server-Daten erfassen: CPU, AVX-512, RAM, Ollama-Version und die Fähigkeiten der Gemma-4-Cores laut `/api/show`.
- Testaufnahme in den Längen 5, 15, 30, 45 und 60 Sekunden als WAV mit 16 kHz, Mono und 16 Bit erzeugen. Dazu kommen absichtlich falsche Formate: 24 Bit, Float, 44,1 kHz Stereo und rohes PCM.
- Jeden Fall über Maras Warteschlange an Ollama schicken und die Antwortzeiten, Tokens und die Mitschrift festhalten. Zusätzlich ein Fall mit Bild und Ton gemeinsam.

Ohne eigene Aufnahme hört Core die erste gemessene Stimme. Das ist ein Rundlauf von der Sprachausgabe zurück zum Hören. Ohne Aufnahme und ohne Stimme gibt es nur einen Formattest mit Sinuston.

Das Ergebnis steht in `var/cache/media-spike/ZEITSTEMPEL/report.md`. Der Test ändert keine Modelle, kein Modelfile und keine Datenbank. Der Formatprüfer `api/audio_wav.php` ist derselbe, den P20.1 vor jedem Senden nutzt.

**Erweiterung in 0.5.74** (Folge des Messprotokolls):
- Jede Antwort kommt gestreamt; der Bericht zeigt pro Fall Ladezeit, Einlesezeit, die Zeit bis zum ersten Wort und bis zum ersten ganzen Satz sowie die Länge eines mitgelieferten Denkens.
- **Vergleich ohne Denken:** derselbe Clip (15 Sekunden) einmal als Mitschrift und zweimal als kurze Gesprächsantwort, mit Denken (Modelfile wie im Chat) und ohne Denken. Ohne Denken heißt: Der Test liest Maras SYSTEM-Text aus `/api/show` und schickt ihn nur in diesem Aufruf ohne `<|think|>` mit. Das Modelfile selbst bleibt unverändert.
- **Kurze Aufnahmen:** 3 und 5 Sekunden je fünfmal, abwechselnd ohne und mit Hinweis "[Angehaengt: Sprachaufnahme, N Sekunden]". Gezählt wird, wie oft Core behauptet, es gebe keine Aufnahme.
- Der Digest jedes Cores aus `/api/tags`, eine Kurzfassung oben im Bericht und die längste Mitschrift vollständig.
- `keep_alive` kommt aus der CoreAI-Konfiguration (`STU_EMBER_KEEP_ALIVE`) statt aus einem eigenen Wert des Tests.
- Die Hilfen dafür liegen in `api/voice_core.php` und werden später von P20.1 und P20.4 genutzt.

Lauf mit dem Test aus 0.5.74 (erledigt, siehe Lauf 3 im Messprotokoll):

```
bash /opt/ember-coreui/scripts/media-spike.sh --audio DATEI --skip-formats --durations 15,60
```

### Messprotokoll P20.0 (28.10.2026)

Server: 20 CPU-Kerne mit AVX-512 (unter anderem `avx512_bf16`, `avx512_vnni`), 94 GiB RAM, Ollama 0.40.6, ffmpeg 5.1. Core `ember-coreui:latest` (Maras Modelfile, `FROM gemma4:e4b`, 7,5B, Q4_K_M). Aufrufe ohne Ollama-Optionen, `think: false`, über Maras Warteschlange; das Modelfile-SYSTEM mit `<|think|>` galt, weil kein eigener SYSTEM-Text mitging.

| Fall | Eingabe-Tokens | Einlesen s (Lauf 1 / 2) | Ausgabe-Tokens (Lauf 1 / 2) | Gesamt s (Lauf 1 / 2) |
|---|---|---|---|---|
| 5 s | 574 | 3,40 / 3,28 | 444 / 355 | 22,41 / 17,48 |
| 15 s | 824 | 4,55 / 5,55 | 445 / 445 | 54,40 / 26,11 |
| 30 s | 1.200 | 6,69 / 7,08 | 642 / 574 | 34,07 / 56,19 |
| 45 s | 1.574 | 8,08 / 8,52 | 732 / 495 | 40,35 / 30,96 |
| 60 s | 1.950 | 10,30 / 11,57 | 738 / 797 | 43,69 / 49,15 |
| Bild und Ton (15 s) | 917 | 5,77 / 5,88 | 851 / 699 | 41,71 / 37,95 |

Lauf 1: kurze Aufnahme, für die langen Fälle wiederholt. Lauf 2: 60 Sekunden einer deutsch synchronisierten Fernsehszene mit Musik und Raumhall (Adrian Monk im Hotelzimmer).

Abgeleitete Werte:
- **Audio kostet 25 Tokens je Sekunde** (1.376 Tokens auf 55 Sekunden, in beiden Läufen identisch); ohne Audio rund 450 Tokens für Auftrag und SYSTEM-Text.
- **Einlesen:** etwa 170 Tokens je Sekunde, also rund 0,15 Sekunden Rechenzeit je Sekunde Audio (60 Sekunden Audio in 11,6 Sekunden).
- **Ausgabe:** etwa 22 Tokens je Sekunde.
- **Kaltstart:** 10,8 bis 12 Sekunden Laden beim ersten Aufruf.
- **Neuladen:** Die Ausreißer (15 s in Lauf 1, 30 s in Lauf 2) entstanden durch Neuladen des Cores, im zweiten Lauf belegt mit 23,5 Sekunden Ladezeit bei normaler Ausgabegeschwindigkeit. Zwischen zwei Testfällen hatte Embers Orchestrator im Global-Chat des Spiels den Core in einer anderen Einstellung angefordert. Das betrifft nur Server, auf denen ein zweites Ember parallel denselben Ollama nutzt.
- **Verborgenes Denken:** Die Ausgabe ist viel länger als die sichtbare Antwort. Bei 5 Sekunden standen 355 Ausgabe-Tokens rund 30 sichtbaren gegenüber, bei 60 Sekunden 797 Ausgabe-Tokens rund 210 sichtbaren. 300 bis 590 Tokens Denken kosten 14 bis 27 Sekunden je Aufruf. Ursache ist `<|think|>` am Anfang des Modelfile-SYSTEM; `think: false` allein schaltet es nicht ab.
- **Formate:** 24 Bit, 32 Bit Float und 44,1 kHz Stereo in beiden Läufen angenommen (HTTP 200, brauchbare Mitschrift); rohes PCM HTTP 400.
- **Bild und Ton gemeinsam** funktionieren: Das Testbild (ffmpeg-Farbbalken) wurde grob richtig beschrieben, die Mitschrift folgte.
- **Kurze Aufnahme übersehen:** In Lauf 2 antwortete Core auf 5 Sekunden "Bitte stellen Sie die Aufnahme zur Verfügung", obwohl die 574 Eingabe-Tokens zeigen, dass das Audio ankam. Wie oft das passiert, misst der erweiterte Test.

Qualität (Lauf 2, sieben Mitschriften desselben Szenenanfangs und eine volle Minute):
- Der Inhalt der Szene ist klar erkannt. "Natürlich. Ah, natürlich, ja. Wussten Sie, dass die meisten ..." stand in allen sieben Mitschriften gleich, "Kopfkissenbezüge" viermal richtig (sonst "Kochkissen..."), der erste Satz schwankte in jedem Lauf.
- Die volle Minute ist durchgehend mitgeschrieben; etwa drei Viertel der Sätze sind richtig oder fast richtig. Schnelle und überlappende Stellen sind verstümmelt.
- Eine 15-Sekunden-Mitschrift kam ohne Großschreibung und Satzzeichen. Die Wortwahl bleibt wegen der parameterfreien Gemma-Regel zufallsabhängig; Satzzeichen werden deshalb im Auftrag verlangt.

Folgerungen für die Lieferungen:
- Abschnitte bis 60 Sekunden (P20.1, P20.2).
- Audio-Fähigkeit über `/api/show` erkennen.
- Umwandlung in 16 kHz, Mono, 16 Bit bleibt, die strenge Prüfung davor ebenfalls.
- Kurze Aufnahmen im Auftrag ausdrücklich als Anhang benennen; behauptet Core trotzdem, es gebe keine Aufnahme, einmal automatisch wiederholen (`coreui_voice_missed_audio`). Ob der Hinweis allein reicht, misst der erweiterte Test.
- Live-Gespräch ohne Denken (P20.4), Core beim Start vorwärmen und ein Neuladen sichtbar machen.

**Lauf 3 (0.5.74, Monk-Szene, 60 Sekunden):**

| Fall (15-s-Clip) | Erstes Wort | Erster Satz |
|---|---|---|
| Mitschrift mit Denken | 28,2 s | 28,5 s |
| Mitschrift ohne Denken | 4,1 s | (Antwort "[keine Sprache]") |
| Live-Antwort mit Denken | 31,4 s | 31,6 s |
| Live-Antwort ohne Denken | 4,3 s | 5,7 s, erster echter Satz nach etwa 6,6 s |

- Einlesen etwa 187, Ausgabe etwa 24 Tokens je Sekunde, kein Neuladen, 60 Sekunden wieder vollständig mitgeschrieben.
- **Kurze Aufnahmen (3 und 5 s, je fünfmal mit und ohne Anhang-Hinweis): 11 von 20 Fehlversuchen**, neunmal "keine Aufnahme" in wechselnden Formulierungen, zweimal "(keine Sprache)", obwohl gesprochen wurde. Der Hinweis half kaum (5 von 10 mit, 6 von 10 ohne). Die Erkennung aus 0.5.74 zählte nur 6 davon; 0.5.75 ordnet alle richtig ein.
- Ohne Denk-Kanal schrieb Mara ihre Vorüberlegung sichtbar als "[Analyse: ...]" vor die Antwort. Ursache ist der Modelfile-Satz "Trenne intern gedachte Analyse strikt von der sichtbaren Antwort"; seit 0.5.75 entfernt CoreAI einen solchen vorangestellten Block.
- Alle vier lokalen Cores haben eigene Digests, sind aber gleich groß (6,6 GB, 7,5B); die angebliche `gemma4:26b` ist vermutlich kein 26B-Modell. Der Test zeigt ab 0.5.75 die Gewichtsdatei hinter `FROM`.
- Ursachen im Modelfile: festes `<|think|>` (Denken trotz `think: false`, nur verborgen), `temperature 1` (jeder Lauf verhört anders), der Analyse-Satz.

Folgerungen aus Lauf 3:
- Mitschrift nicht in Maras Persona und nicht mit dem Ausweg "(keine Sprache)": Kandidat ist Googles Transkriptionsvorlage mit neutralem SYSTEM-Text, ohne Denken, gegebenenfalls mit einer Sekunde Stille davor oder `temperature 0`. Der Test in 0.5.75 vergleicht genau diese Varianten.
- **Zweistufig als Kandidat für P20.1 und P20.4:** erst die Mitschrift mit der Vorlage, dann antwortet Mara auf den Text. Die Persona bleibt aus dem Hören heraus (wie beim Bild-Fix in 0.5.71), und die Mitschrift für den Chat entsteht ohnehin.
- **Sprachaktivität (VAD) vor dem Senden:** Im Browser erkennt eine leichte VAD (etwa Silero VAD als ONNX im Browser) Sprechbeginn und Sprechpause. Reine Stille wird nicht gesendet, der Schnitt lässt vor dem ersten Wort etwas Luft, und im Live-Modus beendet die Sprechpause die Runde. Gegen die Fehlversuche oben hilft VAD nicht, denn diese Clips enthielten Sprache.

**Erweiterung in 0.5.75:**
- Kurze Aufnahmen (3 und 5 s, je fünfmal) in vier Varianten: `bisher` (Persona, alter Auftrag), `vorlage` (Googles Vorlage, neutraler SYSTEM-Text), `vorlage_stille` (eine Sekunde Stille davor), `vorlage_temp0` (temperature 0, freigegeben nur hier). Alle ohne Denken.
- Die Vorlage auch auf den langen Clips, mit beiden 60-Sekunden-Mitschriften vollständig im Bericht.
- Live-Antwort mit Denken (`think: true`, zugleich Prüfung, ob der Denkschalter wirkt), einstufig (zweimal, der zweite Lauf zeigt den Cache) und zweistufig. Gemessen wird der erste wirklich sprechbare Satz, ein "[Analyse: ...]"-Block zählt nicht.
- Kompakter Bericht mit einer Zeile je Variante, Fehlantworten als Liste, Gewichtsdatei je Core.
- Hat der Core auf dem Server noch ein festes `<|think|>`, schickt der Test für Aufrufe ohne Denken denselben SYSTEM-Text ohne diesen Schalter und meldet das im Bericht.

Nächster Lauf nach dem Update auf 0.5.75 (das Update baut Maras Core ohne `<|think|>` neu):

```
bash /opt/ember-coreui/scripts/media-spike.sh --audio DATEI --skip-formats --durations 15,60
```

### P20.1: Spracheingabe

- Mikrofon-Knopf im Eingabefeld, auch auf Android Chrome und Samsung DeX. Aufnahme im Browser, sichtbare Dauer, Abbrechen, Senden.
- Sprachnachrichten und Audiodateien (zum Beispiel MP3, M4A, WAV, OGG) als Anhang, genau wie Bilder.
- Server: Umwandlung mit ffmpeg in WAV (RIFF), 16 kHz, Mono, 16 Bit PCM signed, mit Header-Prüfung vor dem Senden; Aufteilung langer Aufnahmen in Abschnitte von höchstens 60 Sekunden an Sprechpausen, in fester Reihenfolge mit Zeitmarken.
- Mara hört die Aufnahme direkt und antwortet. Im Chat steht eine **Mitschrift** dessen, was gesagt wurde, damit der Verlauf lesbar und durchsuchbar bleibt. Ob die Mitschrift im selben Core-Aufruf (Marker) oder in einem eigenen kurzen Aufruf entsteht, entscheidet der Test nach Zuverlässigkeit und Zeit; ein eigener Aufruf für die Mitschrift kostet mit Denken 15 bis 30 Sekunden (Messprotokoll), ohne Denken misst ihn der Test ab 0.5.74.
- Der Auftrag nennt die Aufnahme ausdrücklich mit Länge ("[Angehaengt: Sprachaufnahme, N Sekunden]"). Behauptet Core trotzdem, es gebe keine Aufnahme, wird der Aufruf einmal automatisch wiederholt; bleibt es dabei, sagt die Oberfläche das offen. Die Einordnung (`coreui_voice_reply_kind`: gehört, keine Aufnahme, keine Sprache) ist an allen echten Fehlantworten geprüft.
- VAD im Browser: Aufnahme startet mit dem ersten Wort und lässt davor etwas Luft; reine Stille wird gar nicht erst gesendet.
- Cores ohne Audio (`gemma4:26b`, `gemma4:31b`, Cloud-Cores ohne Audioeingang) melden das klar, statt etwas zu erfinden; der Mikrofon-Knopf ist dann deaktiviert.
- Datenschutz: Aufnahmen sind kontogebunden wie andere Anhänge; Option, die Audiodatei nach der Mitschrift automatisch zu löschen.

### P20.2: Videos hören

- ffmpeg trennt die Tonspur (WAV, 16 kHz, Mono, 16 Bit PCM signed) und teilt sie in Abschnitte von höchstens 60 Sekunden. Bilder und passende Tonabschnitte gehen gemeinsam in dieselbe Anfrage, mit Zeitmarken.
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
- **Ohne Denken:** Live-Runden schicken `think: false`; seit 0.5.75 enthält das Modelfile kein festes `<|think|>` mehr (für ältere Cores schickt CoreAI den SYSTEM-Text ohne Schalter mit, `coreui_voice_system_without_think`). Ein vorangestellter "[Analyse: ...]"-Block wird nicht gesprochen. Die Persönlichkeit bleibt Wort für Wort gleich; die 14 bis 27 Sekunden Denken je Runde entfallen. Der Gesprächsverlauf wird so aufgebaut, dass sein Anfang gleich bleibt und Ollama ihn aus dem Cache nehmen kann.
- **Vorwärmen und Neuladen:** Beim Start des Gesprächsmodus wird Core geladen, bevor die erste Runde beginnt (Kaltstart 11 bis 12 Sekunden). Muss er mittendrin neu geladen werden (gemessen bis 23,5 Sekunden), zeigt die Oberfläche "Core wird geladen" statt Stille.
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
