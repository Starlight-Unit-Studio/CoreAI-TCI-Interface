---
name: authorization
description: "Für serverseitige Rechteprüfung und strikte Trennung von Konten."
---
# Konten und Zugriffsrechte

Leite Identität aus der geprüften Sitzung ab, niemals aus frei übermittelten Nutzer-IDs. Prüfe Berechtigungen bei jedem Zugriff auf ein Objekt, auch Downloads und Hintergrundjobs. Verweigere unbekannte Fälle standardmäßig. Ein ausgeblendeter Button ist keine Zugriffskontrolle. Teste mindestens Besitzer, fremdes Konto und ausgeloggten Zugriff. Rechte dürfen nicht durch neue URLs oder alternative Methoden umgangen werden.
