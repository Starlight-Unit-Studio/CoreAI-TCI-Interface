# CoreAI Deep Research 0.7.42: Hybrid Lead/Worker Architecture

The new hybrid mode integrates the existing asynchronous Deep Research jobs with
one shared Lead Reasoning trajectory and isolated research workers. It is
**model-agnostic** and designed for a CPU-only server first. All local Ollama
requests, including native screenshot Vision and Citation Agent requests, use
the established single global model lock. Only independent web I/O overlaps.

## Architecture and source reference map

| Research principle | CoreAI implementation |
| --- | --- |
| OpenAI-style extended, iterative single-agent reasoning | One Lead repeatedly plans, reviews summarized worker evidence, identifies gaps and pivots before final synthesis. No private chain-of-thought is stored. |
| End-to-end RL-trained model | **Not reproduced by code.** This is model training, requiring a suitable dataset, training pipeline and compute; inference-time orchestration cannot claim RL-trained weights. |
| Depth-first browsing, backtracking and pivots | Follow-up decisions, per-worker evidence coverage, alternative sources, up to the operator-configured depth budget, plus existing Playwright/Vision navigation. |
| Long-context work and compaction | Context allocation in the existing report builder; bounded per-worker notes and external, resumable JSON memory. The Lead never receives all raw HTML as a single giant context. |
| Anthropic-style Lead/Worker orchestration | Independent worker tasks with their own specific goals and page contexts. The Lead synthesizes a map of source-backed findings. New subtasks can be added after reflection. |
| Parallel breadth-first research | Threaded public search/document requests overlap; model calls remain serial for CPU-only and global model-lock correctness. |
| Map/Reduce context isolation | Each Worker builds notes from its own verified documents only. The Lead receives compressed notes and citation references. |
| Interleaved reasoning and tool use | Plan -> parallel search/read -> worker evidence reasoning -> Lead reflect/pivot -> additional search -> synthesis. Private reasoning tokens are never surfaced or persisted. |
| External memory / checkpoints | Migration 041 creates run-scoped durable memory and source-excerpt tables. State is updated after research phases and reads. Expired workers can resume on the next claim instead of automatically discarding the run. |
| Explicit evidence and independent Citation Agent | A separate citation-review role checks the final report against cached extracts. Deterministic postprocessing rejects unregistered citations and low-overlap claims. Lexical checks cannot guarantee semantic truth by themselves. |
| Search and web browser tools | Existing SearXNG, guarded HTTP fetching and conditional Playwright with native image input. Captchas/access controls are recognized and alternate public sources are searched without user intervention. |
| PDF and structured data | Public PDF via pypdf in the browse container, CSV and JSON parsing with redirect/private-network checks, plus existing HTML and plain-text support. |
| Python sandbox for data analysis and visualization | The existing isolated `ember_py_worker` container accepts fixed, CoreAI-generated numerical computation jobs. Inputs must appear in cached source excerpts. Output includes a result and a compact sparkline, not arbitrary model-generated code. |
| MCP connector/tool layer | An optional public, read-only Streamable HTTP MCP search adapter with explicit operator tool allowlist, initialized handshake and URL verification. It is OFF unless configured. Authenticated, user-scoped enterprise connectors require their own permission bridge and are not silently enabled. |
| Background execution | Database-backed jobs continue without an open browser. Completion and a report card are persisted to the same chat. There is no new Android push service in this version. |
| Quality / stopping policies | Configurable rounds, web parallelism, source coverage, time limit, citation audit, honest gap reporting, progress and observable activity events. |

## Files and deployment

- `tools/coreai_research_hybrid.py`: Lead, isolated Worker Map, independent source ledger, gap detection, coverage, Reduce and Citation Agent.
- `tools/coreai_research_documents.py`: bounded public PDF/CSV/JSON/HTML/text handling.
- `tools/coreai_research_mcp.py`: explicitly configured read-only public MCP discovery.
- `tools/coreai_deep_research.py`: opt-in hybrid dispatch with the legacy worker as fallback.
- `tools/ember_browse_worker.py`: shared DB checkpoint, resumed job recovery, role-specific Core settings, Python sandbox adapter and source caching.
- `database/migrations/041_deep_research_hybrid.sql`: per-run checkpoint and evidence storage, removed on chat deletion.
- `tools/requirements-browse.txt`: includes the PDF parser in the browse image; after updating, rebuild the browse container.
- `scripts/deep-research-hybrid-selftest.py`: isolated deterministic tests (no live external site).
- `scripts/preflight.sh` and `scripts/preflight-native.sh`: enforce migration, modules and hybrid tests.

The browser research worker must not be deployed with the hybrid code before
database migration 041 is applied. The already available installation/update
path runs migrations; the optional hybrid path checks the schema before use.

## Operator settings

| Name | Default | Use |
| --- | --- | --- |
| `STU_DEEP_RESEARCH_HYBRID` | `1` | Use hybrid mode when migration 041 is available |
| `STU_DEEP_RESEARCH_IO_WORKERS` | `3` | Concurrent network I/O, never concurrent Ollama inference |
| `STU_DEEP_RESEARCH_DEPTH_ROUNDS` | `2` | Lead Map/Reflect/Pivot passes |
| `STU_DEEP_RESEARCH_EXTRA_WORKERS` | `2` | Additional independent subtasks per run |
| `STU_DEEP_RESEARCH_REPORT_CHARS` | `14000` | Bounded synthesis context |
| `STU_DEEP_RESEARCH_LEAD_MODEL` | unset | Optional installed model for the Lead |
| `STU_DEEP_RESEARCH_WORKER_MODEL` | unset | Optional installed model for source summarization |
| `STU_DEEP_RESEARCH_CITATION_MODEL` | unset | Optional installed model for citation audit |
| `STU_DEEP_RESEARCH_PYTHON_ENABLED` | `1` | Validated numeric jobs in existing container |
| `STU_DEEP_RESEARCH_PYTHON_WAIT` | `35` | Maximum seconds for optional analysis job |
| `STU_DEEP_RESEARCH_MCP_ENABLED` | `0` | Public read-only MCP discovery |
| `STU_DEEP_RESEARCH_MCP_URL` | unset | HTTPS public MCP Streamable HTTP endpoint |
| `STU_DEEP_RESEARCH_MCP_READ_TOOL` | unset | Operator-approved read-only tool name |
| `STU_DEEP_RESEARCH_MCP_QUERY_FIELD` | `query` | Supported argument name |

Existing settings for maximum run minutes, pages, queries, screenshot Vision,
safe network access, stopping the run and Core timeouts remain effective.

## Security and limitations

- The system never creates or claims to fine-tune a model using OpenAI's proprietary RL training. It uses explicit inference-time controller logic instead.
- HTML/PDF/MCP content remains untrusted. A returned URL, title or snippet is not a numbered citation until the actual public document is fetched and checked.
- No direct execution of model-generated Python in the browse process. Numeric analysis programs are constructed from validated numbers and run in the existing isolated Python service.
- Global model lock is unchanged. Multi-agent tasks gain broad web coverage, not multiple simultaneous local model generations.
- A checkpoint is structured and reconstructible, not a dump of proprietary/private thinking.
- CAPTCHA/anti-bot protections are not bypassed; the unattended run pivots to other accessible public sources.
- The public MCP adapter intentionally does not accept personal connector credentials. User-scoped authenticated enterprise connector access requires a separate authorization design.
- Semantic truth still needs human evaluation on real research tasks. The citation role and lexical auditor are safeguards, not a guarantee.
- End-to-end tests on the user's production Ollama/browser/DB deployment, real PDFs, authenticated MCP and long-duration restart scenarios are not established by mocked CI tests.

## References

- OpenAI, Introducing Deep Research: https://openai.com/index/introducing-deep-research/
- Anthropic, How we built our multi-agent research system:
  https://www.anthropic.com/research/multi-agent-research-system
