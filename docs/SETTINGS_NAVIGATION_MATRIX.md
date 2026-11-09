# CoreAI settings information architecture

Current foundation: CoreAI 0.6.75 (09 November 2026).

This file is a development map, **not a claim that every visible category works**.
Navigation, search, swipe-in mobile drawer, desktop collapse and header Expert control work. A
planned category opens an honest, read-only placeholder until its real feature
has an authenticated API, UI tests and a separate release.

## Personal

| Menu category | Status | Existing function or next work |
| --- | --- | --- |
| General (Allgemein) | Implemented / English beta | App language DE/EN and automatic higher Core. Denkaufwand is visible without Expert and uses the same account preference as its chat control. Named efforts require migration 027 and model support; other Cores retain Thinking on/off. Send shortcut is on Keyboard. |
| Appearance (Darstellung) | Existing | System/Light/Dark, reduced motion and chat preferences |
| Notifications (Benachrichtigungen) | Planned | Notification channels, delivery permissions and user controls |
| Profile (Profil) | Existing | User/assistant display names and avatars with crop |
| Security & sign-in (Sicherheit und Anmeldung) | Existing | Working password changes, device sessions and session revocation moved intact from Account |
| Account (Konto) | Existing | Account identity, role and sign out; security under Security and data export under Data controls |
| Archived chats (Archivierte Chats) | Placeholder | Archive exists in the chat sidebar, dedicated management not yet here |
| Voice (Stimme) | Existing / partial | Automatic read-aloud and recognition-term hints moved intact from AI profile. Voice input and Live remain in chat; other voice settings are future work |
| Storage (Speicher) | Placeholder | Knowledge and Memory operate under CoreAI expert categories |
| Personalization (Personalisierung) | Existing | Tone, format, emoji and instruction preferences |
| Pets | Planned | No companion implementation or persistence |
| Keyboard (Tastatur) | Existing / partial | Existing Enter or Ctrl/Command+Enter option saves locally and is honored in the chat. Custom shortcuts are not yet available |
| Usage (Nutzung) | Planned | User-facing usage metrics without invented model costs |
| Data controls (Datenkontrollen) | Existing / partial | Existing private JSON account export moved intact from Account. Other retention and delete controls remain future work |

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
| AI profile (KI-Profil) | Existing | System prompt, token budget, temperature, provider, answer language and context window. Technical fields remain under Expert. Memory context limit is on Memory. |
| Knowledge (Wissen) | Existing | Always visible. Existing knowledge controls and their saved data remain unchanged. |
| Memory | Existing | Memory enable/disable, context-fact limit (Expert), stored facts and fact relevance (Expert). Its Save uses the existing AI settings API and preserves unsaved changes on unrelated pages. |

Expert settings is a browser-local **field visibility** preference, not
an authentication factor or tool permission. It never hides entire categories,
changes saved technical values or grants new privileges. All backend
authorizations remain mandatory.

## Administration

Regular Settings expose both a header shortcut and a sidebar link only to
server-confirmed owner or admin roles. The eight existing administration views now live on
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
