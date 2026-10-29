---
name: nextjs
description: "Für Next.js mit passenden Server- und Client-Grenzen."
---
# Next.js: Architektur, Daten und Sicherheit

Ermittle installierte Next.js-/React-Version und App Router oder Pages Router. Verwende ausschließlich die APIs und Caching-Regeln dieser Version. Trenne Server- von Client-Komponenten nach tatsächlichem Bedarf; Geheimnisse und Datenbankzugriffe bleiben auf dem Server. Öffentliche Umgebungsvariablen bewusst prüfen.

Plane für Datenzugriff, Mutation, Streaming und Revalidierung einen nachvollziehbaren Ablauf. Route Handler und Server Actions benötigen eigene Authentifizierung, Autorisierung, Eingabevalidierung und passende CSRF-/Origin-Prüfungen; eine versteckte UI-Aktion ist kein Sicherheitsschutz. Keine gemeinsam veränderlichen nutzerspezifischen Module-Caches anlegen. Übergib über Server-Client-Grenzen nur geeignete Daten.

Miss unnötige Request-Wasserfälle und Bundlegrößen, bevor du parallelisierst, cacht oder lazy lädst. Prüfe Deep Links, Refresh, Fehler-/Ladegrenzen, Cache-Invaliderung und Hydration. Build, Unit- und Browserergebnisse nur nach Ausführung als bestanden melden. Detaillierte Versionsmatrix: references/IMPLEMENTATION.md.
