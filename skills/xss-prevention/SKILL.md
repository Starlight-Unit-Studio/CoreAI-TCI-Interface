---
name: xss-prevention
description: "Für sichere Darstellung fremder Texte und erlaubter HTML-Inhalte."
---
# XSS-Schutz

Behandle externe Texte als Daten. Nutze textContent für Textausgabe und kontextgerechte Kodierung an anderen Ausgabestellen. HTML darf nur nach einer passenden, gepflegten Sanitizer-Policy dargestellt werden. Prüfe URL-Schemata separat. Vermeide String-Zusammenbau für Script- oder Eventhandler-Kontexte. Teste gefährliche Eingaben in genau dem Kontext, in dem sie erscheinen.
