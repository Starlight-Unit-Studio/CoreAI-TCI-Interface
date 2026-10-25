---
name: docker
description: "Für reproduzierbare Container mit begrenzten Abhängigkeiten."
---
# Docker und Container

Prüfe Basisimage und unterstützte Laufzeitversion. Nutze mehrstufige Builds, wenn Build-Werkzeuge nicht ins Laufzeitimage gehören. Halte den Build-Kontext über dockerignore klein. Kopiere keine Zugangsdaten ins Image. Nutze soweit möglich einen unprivilegierten Laufzeitnutzer und getrennte persistente Daten. Dokumentiere Ports, Volumes und Startvoraussetzungen. Ein erfolgreicher Build ersetzt keinen Starttest.
