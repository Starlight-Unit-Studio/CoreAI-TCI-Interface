# E.M.B.E.R. CoreUI: Universal Project Agent Pilot

Status: **experimental, opt-in, NOT enabled in production**.
Baseline: CoreUI 0.5.31.
This is a staged replacement design for the numerous ZIP/file-type routing exceptions,
not a claim that every generated language or media type is now supported.

## Objective

The selected Ollama model decides the project file layout instead of CoreUI
guessing \`index.html\`, \`style.css\`, and \`main.js\` from words such as
"homepage". The model remains accessible from the **local Ollama model list**,
including Ollama-managed \`*-cloud\` models. No separate Cloud API is used.

Architecture, strictly serial per E.M.B.E.R.:

1. A small, bounded planning response produces JSON with archive, files,
   purpose, and dependencies. This is a textual JSON adapter to the existing
   Ollama chat endpoint, **not yet native Ollama tool calling or enforced
   server-side \`format\` schemas**.
2. CoreUI validates every relative path, extension, unique filename, count,
   dependency, and cycle. It sorts the files in dependency order.
3. CoreUI requests just **one text file per serial model call**. Every file
   requires an unambiguous terminal marker; a missing marker triggers at most
   two incremental continuation calls before a bounded complete-file retry.
4. Source bytes are held as inert PHP data. There is no generated Python
   file-writing code, shell command, or model-defined executable tool call.
5. Generic source checks plus HTML asset-reference checks must pass. The
   server-owned Python ZIP writer receives only validated compressed/base64
   bytes. It stages files in a temporary sandbox directory (including nested
   folders), verifies ZIP members and CRC, and emits **one ZIP only** into the
   worker output directory.
6. The existing account/session/revision-bound artifact manifest must
   confirm the exact ZIP before a success message or download card appears.

A strictly unique search/replace function is provided as a primitive for a
later Aider-style edit operation. It is not yet an autonomous multi-step
project revision loop.

## Current limits (intentionally conservative)

- Exactly one explicit ZIP project request per turn.
- Up to 10 **UTF-8 text source files**, each <= 120 KB, total <= 400 KB,
  directory depth <= 4; no duplicate full paths.
- No native image/video/audio/font/binary creation or binary references.
- No external model-generated shell commands, untrusted paths or script
  execution. HTML/CSS/JS/Python/TypeScript etc. are carried as DATA.
- JSON and HTML source structure checked. Other languages may still contain
  syntactically invalid or semantically incorrect code. A final browser,
  build, linter or runtime test remains necessary.
- Streaming/concurrent agent actions, more than 10 files, resumable
  across-turn projects, semantic diff repair, source repository edits and
  binary assets are **future phases**, not features silently promised here.

## Opt-in (STAGING ONLY, after running tests)

The pilot is off unless the private \`api/config.local.php\` explicitly
defines this constant:

    define('STU_EMBER_UNIVERSAL_FILES_ENABLED', true);

Without that switch, 0.5.31 routing and downloads run unchanged.
Opt-in does not edit the approved Gemma Modelfile. Neither \`num_ctx\` nor
\`num_predict\` is added to Gemma requests. Model calls stay strictly
serial through the existing Ollama queue.

## Offline test (not a live inference test)

    php -l api/console_universal_project.php
    php -l api/chat.php
    php -l api/console_stream.php
    php scripts/universal-project-agent-selftest.php
    php scripts/local-file-tool-selftest.php

The new selftest fakes model replies and validates a browser project and
a completely different Python-CLI project. It also checks dependency
ordering, parent-directory protection, marker truncation and continuation,
relative asset paths, encoded writer data and unique edits.

## Acceptance still required

1. Run the offline tests **outside the productive checkout**.
2. Verify the emitted trusted ZIP writer in the existing isolated Python
   worker and inspect the archive member bytes, CRC and paths.
3. Try the same request with locally executing \`gemma4:e4b\`,
   \`gemma4:26b\`, and locally selected \`gemma4:31b-cloud\`.
4. Confirm exact artifact cards, account isolation, cancellation, and
   *absence* of duplicate assistant responses.
5. Confirm actual application operation in mobile Chrome.
6. Only then consider enabling the pilot on production and retiring the
   old per-structure rules in a separate, reviewed release.

No GitHub Actions are required to create the branch or PR; do not trigger
GitHub Actions while the account's quota is exhausted.
