# CoreUI Skills (0.5.33)

32 repository-owned, originally written instruction packages, with sources reviewed on 2026-10-25. This is a curated starter collection, not an exhaustive mirror of online skills or a claim that all upstream material is current forever. No third-party scripts or skill implementation code were copied. Source links are references, not bundled documentation. New releases should recheck sources, installed framework versions and triggers.

## Use

1. Update CoreUI and apply migration `014_user_skills.sql` through the existing migration runner (`scripts/stack.sh migrate` for Compose).
2. Settings → MEINE SKILLS: choose individual switches or ALLE AKTIVIEREN, then AUSWAHL SPEICHERN.
3. The next request uses the saved per-account selection. All skills start disabled, including newly added packages. Existing in-flight requests keep their loaded instructions.
4. Natural-language keyword matching selects up to four relevant enabled skills. `/skill zip-delivery` or `$zip-delivery` gives an enabled skill priority. More than four requested skills are limited in stable catalog order. Disabled skills cannot be invoked.
5. The work timeline records `Skill geladen: <id>`. This confirms instruction loading, not successful execution or model compliance.

The first version uses deterministic backend routing, not autonomous native skill retrieval by the model. Selection is based on the current user request, not arbitrary documents or the complete conversation history. Follow-ups should mention the topic or use `/skill <id>` if needed. The selection is retained for model calls and continuations within that request, rather than being re-ranked from generated code. It works with the currently selected local or Ollama-served cloud model; no model replacement is needed.

## Runtime boundaries

- Up to four complete bodies, at most 2,400 bytes each, are added to the user prompt. Oversized bodies fail validation instead of being cut mid-instruction. Current bodies are shorter than this limit.
- Modelfile SYSTEM, template, renderer, parser and sampling parameters remain unchanged. No `num_ctx` override is introduced. The global model queue is unchanged.
- Skills grant no new tool permissions, filesystem access or network access. Existing `[PY]`, `[WEB]`, `[BROWSE]` and source-file protocols remain authoritative.
- Files are loaded exclusively from catalog-approved repository paths. No URL import, script execution, arbitrary path lookup or third-party marketplace installation.
- Skill instructions can improve consistency but do not fix model truncation, missing assets or invalid code by themselves. Existing validators and actual execution results remain necessary.
- Work-event metadata contains only the skill ID, not instruction bodies or private prompts.
- Missing migration leaves all skills disabled and disables saving in the UI. A failed POST is visibly reported. Saved values are account-scoped; account deletion cascades and account export includes the preferences.
- PR #69, the universal project agent, stays a separate draft. This release is based on production main, not that pilot.

## Adding a package

Create `skills/<id>/SKILL.md` with `name` and `description` YAML frontmatter and a concise Markdown body. Register its ID, title, description, category, keyword triggers, version, HTTPS sources and review date in `skills/catalog.json`. This runtime supports this controlled subset of the Agent Skills format; it does not interpret arbitrary YAML, resource references or executable scripts. Run `php scripts/skills-selftest.php`. Deploy catalog and documents together. New entries remain opt-in.

Sources for the format: https://agentskills.io/specification . Individual technical references are recorded per package in the catalog and shown in Settings. Instruction text is original CoreUI guidance under the repository license, including project-specific policies that are not claims about upstream recommendations.

## Validation and live acceptance

- `php scripts/skills-selftest.php`: catalog, every explicit invocation, disabled skills, unknown IDs, invalid value types, traversal rejection, keyword boundaries, selection cap and runtime reset. Optional pdo_sqlite adds actual save/load/account-isolation/rollback tests through a test-only SQL upsert adapter; that is not a MariaDB migration test.
- `node scripts/skills-ui-selftest.cjs`: optional Playwright browser test with mocked API for switches, search, save/reload, CSRF header, failed-save recovery, mobile width and missing migration. Requires installed Playwright plus browser. `PW_CHROMIUM_PATH` can select an existing Chromium binary. Passed during 0.5.33 preparation with Chromium 134 and mocked API; this is not a production browser/server test.
- Existing Gemma policy and frontend regression checks remain part of acceptance.
- On the user's server: migrate; enable only Python and ZIP; save and reload; ask for a small Python ZIP; verify loaded-skill events, real archive and contents. Disable ZIP and repeat a request naming `/skill zip-delivery`, checking that it does not load. Repeat with another account and with SSE and synchronous file paths. Confirm successful migration on MariaDB and the actual selected Ollama model. No live Ollama validation is implied by offline tests.
