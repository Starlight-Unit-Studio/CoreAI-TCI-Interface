# Font-Hinweise

Die lokal paketierten Schriften Rajdhani und Share Tech Mono werden unter der SIL Open Font License 1.1 angeboten.

- Rajdhani: <https://github.com/itfoundry/rajdhani>
- Share Tech Mono: <https://fonts.google.com/specimen/Share+Tech+Mono>
- SIL Open Font License 1.1: <https://openfontlicense.org/open-font-license-official-text/>

Die Fontdateien werden lokal ausgeliefert. Ember CoreUI stellt zur Laufzeit keine Verbindung zu Google Fonts her.

`rajdhani_700.ttf` ist dieselbe Schrift Rajdhani Bold, nur von WOFF2 in TTF umgewandelt (0.6.39). CoreAI schreibt damit "Erzeugt von TCI: Mara©" unter das Studio-Logo in Maras Bildern (api/image_gen.php); PHP (GD) kann WOFF2 nicht lesen. Die Lizenz bleibt die SIL Open Font License 1.1.
