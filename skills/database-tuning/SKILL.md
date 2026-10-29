---
name: database-tuning
description: "DB-Tuning: DB-Performance. Einsatz bei datenbank optimieren, query optimieren, sql performance."
---
# Datenbanken gezielt optimieren

Ermittle tatsächlichen Datenbankmotor und Version (MariaDB/MySQL/PostgreSQL), Tabellenvolumen, Query, Indexe, Last und gewünschte Kennzahl. Supabase-/Postgres-Regeln nicht unbesehen auf MariaDB übertragen. Zuerst Query-Plan, Filter, Join-Reihenfolge, Sortierung, N+1-Zugriffe, Locks und Ressourcen messen.

Nutze lesende EXPLAIN- und Monitoringabfragen vor Änderungen. EXPLAIN ANALYZE kann Queries tatsächlich ausführen; deshalb Seiteneffekte und Rechte beachten. Index- und Schemaänderungen nur mit Sicherung, Rollback, Freigabe und Test auf echten bzw. repräsentativen Daten vorbereiten. Bestehende Zugriffsrechte und Mandantentrennung erhalten.

Ergebnis: beobachteter Engpass, nachvollziehbare Metrik, konkrete Optimierung, Risiko, Migrationsplan und erneute Messung. Ohne DB-Verbindung keine beschleunigte Abfrage behaupten; SQL-Vorschläge als ungetestet kennzeichnen.

Vollständige Zielwerkzeuge, Negativtests und spätere technische Integrationen: references/IMPLEMENTATION.md. Diese Zusatzdatei wird derzeit nicht automatisch geladen.
