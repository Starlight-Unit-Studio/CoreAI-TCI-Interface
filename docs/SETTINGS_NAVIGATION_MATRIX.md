# CoreAI settings information architecture

Current foundation: CoreAI 0.6.71 (09 November 2026).

This file is a development map, **not a claim that every visible category works**.
Navigation, search, mobile drawer and expert visibility are implemented. A
planned category opens an honest, read-only placeholder until its real feature
has an authenticated API, UI tests and a separate release.

## Personal

| Menu category | Status | Existing function or next work |
| --- | --- | --- |
| General (Allgemein) | Mixed | Original Core, health, knowledge and memory overview preserved. Enter/Ctrl+Enter shortcut works and is browser-local. App UI language, automatic Core promotion and extra reasoning levels are explicitly disabled pending implementation. |
| Appearance (Darstellung) | Existing | System/Light/Dark, reduced motion and chat preferences |
| Notifications (Benachrichtigungen) | Planned | Notification channels, delivery permissions and user controls |
| Profile (Profil) | Existing | User/assistant display names and avatars with crop |
| Security & sign-in (Sicherheit und Anmeldung) | Placeholder | Password and sessions currently under Account; consolidate later |
| Account (Konto) | Existing | Profile identifier, login security, JSON data export |
| Archived chats (Archivierte Chats) | Placeholder | Archive exists in the chat sidebar, dedicated management not yet here |
| Voice (Stimme) | Placeholder | Live conversation and voice input already work; unify preferences later |
| Storage (Speicher) | Placeholder | Knowledge and Memory operate under CoreAI expert categories |
| Personalization (Personalisierung) | Existing | Tone, format, emoji and instruction preferences |
| Pets | Planned | No companion implementation or persistence |
| Keyboard (Tastatur) | Planned | Custom keyboard map and mobile input preferences; the core Send shortcut already works under General |
| Usage (Nutzung) | Planned | User-facing usage metrics without invented model costs |
| Data controls (Datenkontrollen) | Placeholder | Export exists under Account; other controls need scoped API work |

## Teams

| Menu category | Status | Next work |
| --- | --- | --- |
| Home (Startseite) | Planned | Team workspaces, roles and membership |
| Create first team | Planned | Account/permission model and invite/accept flow |

## Integrations

| Menu category | Status | Next work |
| --- | --- | --- |
| Plugins | Planned | Catalog, security model and explicit account connections |
| Passwords | Planned | No credentials are accepted or stored by the placeholder |
| Cloud computer | Planned | Define remote session permissions and lifecycle; separate from local browser |

## Programming

| Menu category | Status | Next work |
| --- | --- | --- |
| Codex Cloud | Planned | No external coding service is connected |
| Code Review | Planned | Repository analysis, privacy and approval workflow |

## CoreAI: existing categories

| Category | Status | Access |
| --- | --- | --- |
| System | Existing | Available normally, including owner-only updater |
| AI profile (KI-Profil) | Existing | Always visible. System prompt, numeric memory limits, token budget, temperature, provider and context window are conditionally shown inside this category by Expert mode. |
| Knowledge (Wissen) | Existing | Always visible. Existing knowledge controls and their saved data remain unchanged. |
| Memory | Existing | Always visible. Numeric relevance input appears in Expert mode; existing facts remain unchanged. |

Expert settings is a browser-local **field visibility** preference, not
an authentication factor or tool permission. It never hides entire categories,
changes saved technical values or grants new privileges. All backend
authorizations remain mandatory.

## Administration

Regular Settings expose one Admin Core link only to server-confirmed owner
or admin roles. The eight existing administration views now live on
`/admin/index.html`: Overview, Users, Access & security, Runtime, Global
Memory, RAG-Lore, Logs and Audit. The existing API continues to enforce
authorization for every action. Browser links do not confer privileges.

## Acceptance checklist for each future category

1. Define actual behavior and data ownership per account/team/server.
2. Add server authorization, validation and CSRF protections for writes.
3. Integrate with the existing CoreAI components without duplicating states.
4. Replace the placeholder only after tested persistence and failure paths exist.
5. Verify mobile layout, Light/Dark contrast, accessibility and privacy.
6. Increment VERSION once per merge and update changelog and handover.

No empty switch, fake Save action, or unfinished integration may be shown as
functional. Keep German UI and English public documentation aligned with
current implementation.
