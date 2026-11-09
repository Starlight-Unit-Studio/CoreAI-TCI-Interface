# CoreAI settings information architecture

Current foundation: CoreAI 0.6.79 (09 November 2026).

This file is a development map, **not a claim that every visible category works**.
Navigation, search, swipe-in mobile drawer, desktop collapse and header Expert control work. A
planned category opens an honest, read-only placeholder until its real feature
has an authenticated API, UI tests and a separate release.

## Personal

| Menu category | Status | Existing function or next work |
| --- | --- | --- |
| General (Allgemein) | Implemented / English beta | App language DE/EN and automatic higher Core. Denkaufwand is visible without Expert and uses the same account preference as its chat control. Named efforts require migration 027 and model support; other Cores retain Thinking on/off. Send shortcut is on Keyboard. |
| Appearance (Darstellung) | Implemented | System/Light/Dark, motifs, accent, font (Rajdhani or system), per-scheme colors with hex input, separate light/dark look, motif export/copy/import, font sizes, contrast, motion, full width, diff markers and smart punctuation in the chat input. All normal settings, no Expert toggle. |
| Notifications (Benachrichtigungen) | Implemented | Web Push per device (service worker, VAPID, RFC 8291) and E-Mail (SMTP from the server configuration or set by the owner in Admin Core > Betrieb since 0.6.82) for long answers (Reaktionen), security events, new features after an installed update (0.6.83) and CoreAI updates (owner); agents, tasks, library, code tasks, personalized tips, projects, workspace requests and updates to requests shown as planned, in the order of the reference page |
| Profile (Profil) | Existing | Overview modeled on the ChatGPT profile page (0.6.84): tokens counted per account since 0.6.84 (migration 029), peak day, longest task, streaks, 53-week raster (daily, weekly, cumulative), what Mara made, top tools, insights; public profile, sharing and highlights planned. Below: user/assistant display names and avatars with crop |
| Security & sign-in (Sicherheit und Anmeldung) | Implemented | Rebuilt after the ChatGPT security page (0.6.85, migration 030): passkeys and security keys (WebAuthn, also on the sign-in page), password, authenticator app (TOTP), E-Mail code, recovery codes, a second sign-in step, active sessions with sign-out everywhere, security history, advanced account security (passkey-only, no reset by e-mail, 24-hour sessions) and Lockdown mode (no web search or browser). Account PIN, push approval of sign-ins and CSP for custom apps planned |
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
| AI profile (KI-Profil) | Existing | Main language and smart context slider (including Core default and memory estimate) are normal. Advanced system prompt editing, raw token budget, raw temperature and provider path remain Expert. Memory fact-limit is on Memory. |
| Knowledge (Wissen) | Existing | Always visible. Existing knowledge controls and their saved data remain unchanged. |
| Memory | Existing | Memory enable/disable, context-fact limit (Expert), stored facts and fact relevance (Expert). Its Save uses the existing AI settings API and preserves unsaved changes on unrelated pages. |

**Classification rule for all future pages:** The settings shown in the
user-supplied ChatGPT reference screenshots are ordinary, always-visible
settings. Our own controls are also ordinary when a nontechnical person can
understand and use them without raw model parameters. Examples: Denkaufwand,
automatic higher Core, UI/answer language, voice, display and playback toggles,
and the context window's guided slider. **Expert** is for direct low-level,
code/runtime tuning: raw numeric parameters such as token count, temperature
and a future explicit numeric `num_ctx` input, provider internals, arbitrary
system instructions and numeric fact-relevance weights. The guided `num_ctx`
slider is not such a raw input, even though it saves a context choice.
No raw numeric `num_ctx` input is being added in 0.6.76.

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
