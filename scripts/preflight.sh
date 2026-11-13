#!/usr/bin/env bash
# CoreAI: TCI Interface 0.7.46: Vision Core image service import and endpoint regression.
set -Eeuo pipefail
IFS=$'\n\t'

PROJECT_ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"
VERSION="$(tr -d '[:space:]' < "$PROJECT_ROOT/VERSION")"
# 0.5.56: Work-Event-Diagnosen und UI-Notizen bleiben Teil des vorhandenen Preflights."
COMPOSE_FILE="$PROJECT_ROOT/compose.yaml"
ENV_FILE="$PROJECT_ROOT/var/compose.env"
CONFIG_FILE="$PROJECT_ROOT/api/config.local.php"
COMPOSE_PROJECT='ember-coreui'
COMPOSE_CMD=()
FAILURES=0

ok() {
  printf '[OK]     %s\n' "$*"
}

warn() {
  printf '[WARN]   %s\n' "$*" >&2
}

fail() {
  printf '[FEHLER] %s\n' "$*" >&2
  FAILURES=$((FAILURES + 1))
}

is_enabled() {
  case "${1:-}" in
    1|true|TRUE|yes|YES|on|ON) return 0 ;;
    *) return 1 ;;
  esac
}

compose() {
  "${COMPOSE_CMD[@]}" \
    --project-name "$COMPOSE_PROJECT" \
    --env-file "$ENV_FILE" \
    --file "$COMPOSE_FILE" \
    "$@"
}

service_running() {
  local service="$1"
  local container_id
  container_id="$(compose ps -q "$service" 2>/dev/null || true)"
  [[ -n "$container_id" ]] || return 1
  [[ "$(docker inspect --format '{{.State.Running}}' "$container_id" 2>/dev/null || true)" == 'true' ]]
}

check_service() {
  local service="$1"
  local label="$2"
  if service_running "$service"; then
    ok "$label laeuft im eigenen Compose-Projekt."
  else
    fail "$label laeuft nicht."
  fi
}

printf 'CoreAI: TCI Interface Compose-Preflight, Version %s\n\n' "$VERSION"

if [[ ! -f "$ENV_FILE" ]]; then
  fail 'var/compose.env fehlt. Fuehre scripts/install.sh aus.'
fi
if [[ ! -f "$CONFIG_FILE" ]]; then
  fail 'api/config.local.php fehlt. Fuehre scripts/install.sh aus.'
fi
if [[ ! -f "$COMPOSE_FILE" ]]; then
  fail 'compose.yaml fehlt.'
fi

UI_FILES=(
  scripts/uninstall.sh
  tests/uninstall-runner-selftest.sh
  scripts/uninstall-store-selftest.php
  api/uninstall_store.php
  js/coreui-uninstall-admin.js
  config/systemd/coreai-uninstall.path.template
  config/systemd/coreai-uninstall.service.template
  LICENSE.de.md
  LICENSE.md
  LICENSE_HISTORY.md
  TRADEMARKS.md
  COMMUNITY_POLICY.md
  NOTICE.md
  COPYRIGHT.md
  app.html
  settings.html
  admin/index.html
  protocols.html
  css/console.css
  css/artifacts.css
  css/settings.css
  css/coreui-theme.css
  js/coreui-settings-menu.js
  js/coreui-settings-tabs.js
  scripts/send-shortcut-selftest.cjs
  scripts/reasoning-effort-ui-selftest.cjs
  js/coreui-reasoning-effort.js
  js/coreui-send-keys.js
  js/coreui-ui-language.js
  api/general_core_controls.php
  database/migrations/027_general_core_controls.sql
  scripts/general-controls-selftest.php
  scripts/ui-language-selftest.cjs
  scripts/settings-navigation-foundation-selftest.cjs
  scripts/settings-tabs-ui-selftest.cjs
  scripts/theme-preferences-selftest.cjs
  js/coreui-smart-punctuation.js
  scripts/appearance-ui-selftest.cjs
  database/migrations/028_notifications.sql
  api/web_push.php
  api/notifications_store.php
  api/notifications.php
  sw.js
  js/coreui-notify.js
  js/coreui-notify-settings.js
  scripts/notifications-selftest.php
  scripts/notifications-ui-selftest.cjs
  api/mail_settings.php
  js/coreui-admin-mail.js
  scripts/mail-settings-selftest.php
  scripts/admin-mail-ui-selftest.cjs
  database/migrations/029_usage_counter.sql
  api/usage_store.php
  api/profile_stats.php
  js/coreui-profile-stats.js
  scripts/profile-stats-selftest.php
  scripts/profile-stats-ui-selftest.cjs
  database/migrations/030_sign_in_security.sql
  api/secret_box.php
  api/totp.php
  api/webauthn.php
  api/security_store.php
  js/coreui-qr.js
  js/coreui-security-settings.js
  js/coreui-login-security.js
  scripts/sign-in-security-selftest.php
  scripts/security-ui-selftest.cjs
  scripts/security-reset.php
  database/migrations/031_account_page.sql
  api/account_page_store.php
  js/coreui-account-settings.js
  scripts/account-page-selftest.php
  scripts/account-ui-selftest.cjs
  database/migrations/032_chat_menu.sql
  api/console_chat_tools.php
  js/coreui-chat-menu.js
  js/coreui-archived-chats.js
  scripts/chat-menu-selftest.php
  scripts/chat-menu-ui-selftest.cjs
  scripts/archived-chats-ui-selftest.cjs
  database/migrations/033_memory_summary.sql
  api/memory_summary_store.php
  api/memory_summary.php
  scripts/personalization-page-selftest.php
  scripts/personalization-ui-selftest.cjs
  database/migrations/034_user_pets.sql
  api/pets_store.php
  api/pet_library_store.php
  api/pets.php
  js/coreui-pets.js
  js/coreui-pets-settings.js
  scripts/build-pet-sprites.py
  scripts/pets-selftest.php
  scripts/openpet-library-selftest.php
  scripts/openpet-library-ui-selftest.cjs
  scripts/pets-ui-selftest.cjs
  database/migrations/036_voice_choice.sql
  js/coreui-recordings.js
  js/coreui-voice-settings.js
  scripts/voice-settings-selftest.php
  scripts/voice-settings-ui-selftest.cjs
  scripts/voice-recordings-ui-selftest.cjs
  database/migrations/039_storage.sql
  api/files.php
  api/files_store.php
  js/coreui-storage-settings.js
  scripts/storage-selftest.php
  scripts/storage-ui-selftest.cjs
  js/coreui-shortcuts.js
  js/coreui-shortcuts-app.js
  js/coreui-keyboard-settings.js
  css/coreui-shortcuts.css
  scripts/keyboard-shortcuts-ui-selftest.cjs
  api/usage.php
  api/usage_page_store.php
  js/coreui-usage-settings.js
  scripts/usage-page-selftest.php
  scripts/usage-ui-selftest.cjs
  api/data_controls_store.php
  js/coreui-data-controls.js
  scripts/data-controls-selftest.php
  scripts/data-controls-ui-selftest.cjs
  database/migrations/040_deep_research.sql
  database/migrations/041_deep_research_hybrid.sql
  api/deep_research.php
  api/deep_research_store.php
  tools/coreai_deep_research.py
  tools/coreai_research_hybrid.py
  tools/coreai_research_documents.py
  tools/coreai_research_mcp.py
  tools/coreai_research_search.py
  scripts/deep-research-hybrid-selftest.py
  scripts/deep-research-zero-source-selftest.py
  js/coreui-deep-research.js
  css/coreui-deep-research.css
  scripts/deep-research-selftest.php
  scripts/deep-research-worker-selftest.py
  scripts/deep-research-ui-selftest.cjs
  css/coreui-settings-theme.css
  scripts/settings-theme-ui-selftest.cjs
  scripts/terran-terminology-selftest.cjs
  scripts/tci-language-selftest.cjs
  css/coreui-chat-tci-layout.css
  scripts/tci-chat-message-selftest.cjs
  scripts/tci-chat-layout-ui-selftest.cjs
  js/console-app.js
  js/coreui-markdown.js
  js/coreui-markdown-preview.js
  js/coreui-artifacts.js
  js/coreui-preferences.js
  js/settings.js
  js/coreui-profile-crop.js
  css/coreui-profile-crop.css
  scripts/profile-crop-ui-selftest.cjs
  js/admin.js
  setup.sh
  api/rag_lite.php
  api/skills.php
  skills/catalog.json
  scripts/skills-selftest.php
  database/migrations/014_user_skills.sql
  api/user_settings.php
  api/model_catalog.php
  api/models.php
  api/auth_session_store.php
  api/account_security.php
  api/account_export.php
  api/admin.php
  api/ai_settings.php
  api/account_store.php
  api/profile_store.php
  api/profile.php
  api/profile_media.php
  api/knowledge_store.php
  api/knowledge.php
  api/console_session_store.php
  api/console_attachment_store.php
  api/console_sessions.php
  api/console_messages.php
  api/console_action_store.php
  api/console_actions.php
  database/migrations/002_coreui_management.sql
  database/migrations/003_console_sessions.sql
  database/migrations/004_profiles_knowledge.sql
  database/migrations/005_thinking_attachments.sql
  database/migrations/006_account_security.sql
  database/migrations/007_remove_private_studio_lore.sql
  database/migrations/008_message_actions.sql
  database/migrations/009_message_editing.sql
  database/migrations/011_console_artifacts.sql
  database/migrations/012_console_work_events.sql
  database/migrations/013_console_agent_runs.sql
  api/console_work_events.php
  api/console_work_store.php
  api/console_agent_runs.php
  api/console_model_queue.php
  js/coreui-work-events.js
  scripts/work-events-selftest.php
  scripts/work-events-persistence-selftest.php
  scripts/local-file-tool-selftest.php
  scripts/context-budget-selftest.php
  scripts/insights-selftest.php
  scripts/insights-ui-selftest.cjs
  scripts/insights-chat-visibility-ui-selftest.cjs
  scripts/ai-context-selftest.php
  scripts/context-slider-ui-selftest.cjs
  api/ai_context_estimate.php
  js/coreui-context-slider.js
  database/migrations/017_ai_num_ctx.sql
  database/migrations/018_abuse_guard.sql
  database/migrations/019_coreai_roles.sql
  database/migrations/020_model_access.sql
  api/model_access.php
  scripts/model-access-selftest.php
  api/update.php
  api/update_store.php
  js/coreui-update.js
  css/coreui-update.css
  scripts/coreai-updater.sh
  scripts/lib/coreai_update_state.py
  scripts/update-selftest.php
  api/account_delete.php
  scripts/account-delete-selftest.php
  api/account_admin.php
  scripts/account-admin-selftest.php
  api/user_policy.php
  database/migrations/021_user_policies.sql
  scripts/user-policy-selftest.php
  scripts/memory-length-selftest.php
  api/tts_spike_store.php
  api/image_spike_store.php
  js/coreui-image-samples.js
  js/coreui-voice-samples.js
  scripts/tts-spike.sh
  scripts/tts-spike-selftest.py
  scripts/tts-spike-store-selftest.php
  scripts/image-spike.sh
  scripts/image-spike-selftest.py
  scripts/image-spike-store-selftest.php
  scripts/voice-samples-ui-selftest.cjs
  tools/tts_spike/run.py
  tools/tts_spike/voices.json
  tools/tts_spike/textprep_de.py
  tools/image_spike/run.py
  tools/image_spike/models.json
  tools/image/image_service.py
  tools/image/mara_references.py
  config/image/mara-references.json
  images/mara_references/.gitkeep
  tools/image/adopt_spike.py
  config/image/mara-image.json
  api/image_gen.php
  api/image_reference_consistency.php
  docker/image/Dockerfile
  database/migrations/026_image_tool.sql
  scripts/image-service-selftest.py
  scripts/image-gen-selftest.php
  scripts/image-reference-consistency-selftest.php
  scripts/image-chat-ui-selftest.cjs
  scripts/image-editor-ui-selftest.cjs
  js/coreui-image-viewer.js
  js/coreui-image-editor.js
  css/coreui-image-viewer.css
  js/coreui-image-progress.js
  scripts/image-progress-ui-selftest.cjs
  api/console_composer_intent.php
  api/composer_tools.php
  js/coreui-composer-menu.js
  scripts/composer-intent-selftest.php
  scripts/composer-menu-ui-selftest.cjs
  assets/fonts/rajdhani_700.ttf
  config/voice/mara-voice.json
  api/voice.php
  api/voice_store.php
  js/coreui-voice-read.js
  tools/voice/voice_service.py
  tools/voice/speakable.py
  tools/voice/textprep_en.py
  docker/voice/Dockerfile
  database/migrations/023_voice_settings.sql
  database/migrations/024_account_language.sql
  scripts/voice-service-selftest.py
  scripts/voice-read-selftest.php
  scripts/voice-read-ui-selftest.cjs
  api/voice_transcribe.php
  api/voice_input.php
  js/coreui-voice-input.js
  scripts/voice-input-selftest.php
  scripts/voice-input-ui-selftest.cjs
  scripts/session-list-ui-selftest.cjs
  database/migrations/025_voice_vocabulary.sql
  js/coreui-voice-terms.js
  scripts/voice-terms-ui-selftest.cjs
  api/weather_live.php
  scripts/weather-live-selftest.php
  scripts/voice-hear-check.php
  scripts/voice-live-check.php
  api/voice_live.php
  api/voice_live_lib.php
  js/coreui-voice-live.js
  scripts/voice-live-selftest.php
  scripts/voice-live-ui-selftest.cjs
  scripts/message-time-selftest.php
  docker/tts-spike/Dockerfile
  docker/tts-spike/constraints-piper.txt
  docker/tts-spike/constraints-pocket.txt
  docker/tts-spike/constraints-chatterbox.txt
  docker/image-spike/Dockerfile
  scripts/update-ui-selftest.cjs
  tests/update-runner-selftest.sh
  tests/update-auto-selftest.py
  config/systemd/coreai-update.service.template
  config/systemd/coreai-update.path.template
  config/systemd/coreai-update-check.service.template
  config/systemd/coreai-update-check.timer.template
  config/systemd/coreai-update-check.path.template
  api/abuse_guard.php
  scripts/abuse-guard-selftest.php
  scripts/captcha-selftest.php
  scripts/roles-guest-selftest.php
  api/captcha.php
  js/coreui-captcha.js
  scripts/webui-resilience-selftest.cjs
  scripts/date-awareness-selftest.php
  scripts/knowledge-search-selftest.php
  scripts/vision-persona-selftest.php
  scripts/audio-wav-selftest.php
  api/audio_wav.php
  api/voice_core.php
  scripts/thinking-switch-selftest.php
  scripts/thinking-switch-ui-selftest.cjs
  js/coreui-core-mode.js
  api/core_preload.php
  scripts/tool-mention-selftest.php
  scripts/core-tiers-selftest.php
  scripts/core-install-selftest.php
  scripts/core-install-ui-selftest.cjs
  scripts/model-library-ui-selftest.cjs
  scripts/ollama-library-selftest.php
  scripts/installed-cores-selftest.php
  api/installed_cores.php
  api/ollama_library.php
  js/coreui-model-library.js
  scripts/registration-ui-selftest.cjs
  scripts/guest-access-selftest.php
  api/guest_access.php
  scripts/compaction-selftest.php
  api/console_compaction.php
  scripts/agent-loop-selftest.php
  api/console_agent_loop.php
  scripts/browse-worker-selftest.py
  scripts/browse-live-ui-selftest.cjs
  scripts/web-search-selftest.php
  tools/ember_browse_worker.py
  api/console_browse_stream.php
  api/core_install.php
  js/coreui-core-install.js
  scripts/media-spike.php
  scripts/media-spike.sh
  js/coreui-net.js
  api/console_insights.php
  api/console_insights_store.php
  js/coreui-insights.js
  scripts/contextual-project-selftest.php
  scripts/model-project-intent-selftest.php
  scripts/native-tools-selftest.php
  scripts/plan-autocontinue-selftest.php
  api/console_native_tools.php
  api/console_build_intent.php
  api/console_plan_store.php
  api/console_plan_autorun.php
  api/console_plan.php
  database/migrations/016_console_project_plans.sql
  api/console_project_handoff.php
  scripts/gemma-zero-parameters-selftest.php
  scripts/model-live-audit.php
  config/Modelfile.template
  scripts/response-timing-ui-selftest.js
  scripts/agent-runs-selftest.php
  scripts/agent-run-liveness-selftest.php
  scripts/account-language-selftest.php
  scripts/account-language-safety-selftest.php
  scripts/cleanup-language-test-users.php
  scripts/model-queue-selftest.php
  scripts/workspace-output-selftest.py
  scripts/python-input-selftest.py
  tools/ember_inputs.py
  scripts/pdo-mysql-installer-selftest.py
  api/console_artifact.php
  api/console_artifacts.php
  api/console_data_link_bridge.php
  scripts/session-selftest.php
  scripts/attachment-pipeline-selftest.php
  scripts/python-worker-selftest.php
  scripts/profile-knowledge-selftest.php
  scripts/profile-reference-selftest.php
  scripts/account-security-selftest.php
  scripts/frontend-regression-selftest.php
  scripts/markdown-preview-selftest.php
  scripts/long-chat-selftest.cjs
  scripts/history-render-selftest.cjs
  database/migrations/015_long_chat_messages.sql
  scripts/welcome-composer-ui-selftest.cjs
  scripts/chat-navigation-recovery-selftest.cjs
  scripts/chat-follow-selftest.cjs
  js/coreui-chat-follow.js
  scripts/message-actions-selftest.php
  database/migrations/035_feedback_followups.sql
  api/console_feedback_store.php
  api/console_feedback.php
  css/coreui-feedback.css
  scripts/feedback-ui-selftest.cjs
  scripts/markdown-selftest.js
  scripts/personalization-selftest.php
  js/coreui-personalization-settings.js
  api/console_personalization_store.php
  api/console_answer_style.php
  api/personalization.php
  database/migrations/022_user_personalization.sql
  scripts/branding-license-selftest.py
  scripts/cache-bust.py
  docs/changelogs-txt/CHANGELOG_0_5_43.txt
  docs/changelogs-txt/CHANGELOG_0_5_44.txt
  docs/changelogs-txt/CHANGELOG_0_5_45.txt
  docs/changelogs-txt/CHANGELOG_0_5_46.txt
  docs/changelogs-txt/CHANGELOG_0_5_47.txt
  docs/changelogs-txt/CHANGELOG_0_5_48.txt
  docs/changelogs-txt/CHANGELOG_0_5_49.txt
  docs/changelogs-txt/CHANGELOG_0_5_50.txt
  docs/changelogs-txt/CHANGELOG_0_5_51.txt
  docs/changelogs-txt/CHANGELOG_0_5_52.txt
  docs/changelogs-txt/CHANGELOG_0_5_53.txt
  docs/changelogs-txt/CHANGELOG_0_5_54.txt
  docs/COREUI_IMAGE_ENGINE_PLAN.md
  docs/UEBERGABEPROTOKOLL_0_5_43.txt
  docs/UEBERGABEPROTOKOLL_0_5_44.txt
  docs/UEBERGABEPROTOKOLL_0_5_45.txt
  docs/UEBERGABEPROTOKOLL_0_5_46.txt
  docs/UEBERGABEPROTOKOLL_0_5_47.txt
  docs/UEBERGABEPROTOKOLL_0_5_48.txt
  docs/UEBERGABEPROTOKOLL_0_5_49.txt
  docs/UEBERGABEPROTOKOLL_0_5_50.txt
  docs/UEBERGABEPROTOKOLL_0_5_51.txt
  docs/UEBERGABEPROTOKOLL_0_5_52.txt
  docs/UEBERGABEPROTOKOLL_0_5_53.txt
  docs/UEBERGABEPROTOKOLL_0_5_54.txt
  docker/pyworker/Dockerfile
  docker/pyworker/entrypoint.sh
  images/starlight_unit_studios_logo_original.png
  images/watermark_logo.png
)
for ui_file in "${UI_FILES[@]}"; do
  if [[ ! -s "$PROJECT_ROOT/$ui_file" ]]; then
    fail "CoreAI: TCI Interface-Oberflaechendatei fehlt oder ist leer: $ui_file"
  fi
done

if python3 "$PROJECT_ROOT/scripts/pdo-mysql-installer-selftest.py" >/dev/null 2>&1; then
  ok 'PHP-PDO-MariaDB: Image-Build, Installer-Recreation und Preflight-Vertrag geprueft.'
else
  fail 'PHP-PDO-MariaDB-Installer-Selbsttest fehlgeschlagen.'
fi

# 0.5.58: check the installer regression using a temporary, Docker-free fixture.
if bash "$PROJECT_ROOT/tests/migration-permissions-selftest.sh" >/dev/null 2>&1; then
  ok 'Migration: Ausfuehrungsrechte und Bash-Aufruf vor dem Datenbankimport geprueft.'
else
  fail 'Migration: Berechtigungs-/Aufruf-Selbsttest fehlgeschlagen.'
fi

# 0.6.94: syntax is a release blocker. Report the concrete parse error;
# do not hide host-updater failures in /dev/null.
host_updater_syntax=''
if host_updater_syntax="$(bash -n "$PROJECT_ROOT/scripts/coreai-updater.sh" 2>&1)"; then
  if [[ "${EUID:-$(id -u)}" -eq 0 ]]; then
    host_updater_test=''
    if host_updater_test="$(bash "$PROJECT_ROOT/tests/update-runner-selftest.sh" 2>&1)"; then
      ok 'Update-Dienst: Pruefung, Sicherung zuerst, offizielles Setup, Installationsfortschritt, Neustart der Dienste und Fehlerfaelle geprueft.'
    else
      fail "Update-Dienst: Host-Updater-Selbsttest fehlgeschlagen. Details: $(printf '%s\n' "$host_updater_test" | tail -n 12)"
    fi
  fi
else
  fail "Update-Dienst: Bash-Syntaxfehler in scripts/coreai-updater.sh: $host_updater_syntax"
fi

uninstall_runner_test=''
if uninstall_runner_test="$(bash "$PROJECT_ROOT/tests/uninstall-runner-selftest.sh" 2>&1)"; then
  ok 'Deinstallation: Preview, Anfragepruefung, isolierter Docker-Stopp und Archiv-Backup geprueft.'
else
  fail "Deinstallation: Host-Runner-Selbsttest fehlgeschlagen. Details: $uninstall_runner_test"
fi

# 0.6.93: automatic updates require opt-in, survive page reloads and respect Later.
if python3 "$PROJECT_ROOT/tests/update-auto-selftest.py" >/dev/null 2>&1; then
  ok 'Auto-Update: Opt-in, einstuendige Pause, Vorlauf, einmal pro Commit und Preflight-Fehlererkennung geprueft.'
else
  fail 'Auto-Update-/Preflight-Retry-Selbsttest fehlgeschlagen.'
fi

if COREAI_BROWSE_LIVE=0 python3 "$PROJECT_ROOT/scripts/browse-worker-selftest.py" >/dev/null 2>&1; then
  ok 'Browser-Regeln: geschuetzte Felder, Kaeufe, Logins, Nachrichten, lokales Netz, Core des Nutzers und Herzschlag geprueft.'
else
  fail 'Browser-Regeln-Selbsttest fehlgeschlagen.'
fi

# 0.7.38: Deep Research in the browse worker (search, read, notes, report with only real sources, Stopp).
if python3 "$PROJECT_ROOT/scripts/deep-research-worker-selftest.py" >/dev/null 2>&1; then
  ok 'Deep Research: Suche, Seiten, Notizen, Bericht mit Quellen, Stopp und Schutz vor lokalen Adressen geprueft.'
else
  fail 'Deep-Research-Worker-Selbsttest fehlgeschlagen.'
fi

# 0.7.42: hybrid Lead/Worker/Citation agent, checkpoints and secure document tools.
hybrid_research_test=''
if hybrid_research_test="$(python3 "$PROJECT_ROOT/scripts/deep-research-hybrid-selftest.py" 2>&1)"; then
  ok 'Hybrid Deep Research: Orchestrator/Worker, Parallel-I/O, Speicher und Citation Agent geprueft.'
else
  fail "Hybrid-Deep-Research-Selbsttest fehlgeschlagen: ${hybrid_research_test:-Unbekannter Fehler}"
fi

# 0.7.44: broken search backend must never produce a false completed report.
search_regression=''
if search_regression="$(python3 "$PROJECT_ROOT/scripts/deep-research-zero-source-selftest.py" 2>&1)"; then
  ok 'Deep Research: Suchausfall, Null-Quellen und Ersatzsuche geprueft.'
else
  fail "Deep-Research-Suchausfall-Selbsttest fehlgeschlagen: ${search_regression:-Unbekannter Fehler}"
fi

if python3 "$PROJECT_ROOT/scripts/workspace-output-selftest.py" >/dev/null 2>&1; then
  ok 'Python-Worker: gerettete HTML/ZIP-Dateien und sichere Ausgabepfade geprueft.'
else
  fail 'Python-Ausgabedatei-Selbsttest fehlgeschlagen.'
fi

# 0.6.1: read aloud: what is read, parts, the voice service with its probe engine, wiring.
voice_service_test=''
if voice_service_test="$(python3 "$PROJECT_ROOT/scripts/voice-service-selftest.py" 2>&1)"; then
  ok 'Vorlesen: vorlesbarer Text, Saetze, Sprachdienst mit Testton (ein Auftrag gleichzeitig, Zeitlimit, nur lokal) geprueft.'
else
  fail "Sprachdienst-Selbsttest: ${voice_service_test:-Unbekannter Fehler}"
fi

# 0.5.94: voice test (P20.0) with a probe engine: registry and licenses, pinned
# images, measuring without network and with a core limit, SHA-256 check, report.
# 0.5.96: the reason goes into the update log.
tts_spike_test=''
if tts_spike_test="$(python3 "$PROJECT_ROOT/scripts/tts-spike-selftest.py" 2>&1)"; then
  ok 'Stimmtest: Lizenzen je Stimme, feste Versionen, Messen ohne Netz mit Kernlimit, SHA-256-Pruefung und Bericht geprueft.'
else
  fail "Stimmtest-Selbsttest: ${tts_spike_test:-Unbekannter Fehler}"
fi

# 0.6.37 (P20.5 A): image test: registry (Apache 2.0, commit as in the Dockerfile), loading with
# resume and SHA-256 against a stand-in Hugging Face, lock check, a run with a stand-in sd-cli, report.
image_spike_test=''
if image_spike_test="$(python3 "$PROJECT_ROOT/scripts/image-spike-selftest.py" 2>&1)"; then
  ok 'Bildtest: Register und Lizenzen, Laden mit Wiederaufnahme und SHA-256, Lock-Pruefung, Messlauf und Bericht geprueft.'
else
  fail "Bildtest-Selbsttest: ${image_spike_test:-Unbekannter Fehler}"
fi

# 0.6.39 (P20.5 B): Mara's image service: registry (FLUX.2-klein 4B, pins required), progress
# from sd-cli, weights at their pinned revision and SHA-256, one job at a time, cancel, time
# limit, adopting the image test's weights, compose and installer.
# 0.6.59: Optional gallery assets may be uploaded after this code PR.
# Without a full set, the image service falls back to existing photos.
mara_missing=0
for image_name in \
  mara_identity_primary.png mara_identity_fullbody.jpg \
  mara_identity_cockpit.jpg mara_identity_profile.jpg \
  mara_identity_city.jpg mara_style_hologram_seated.jpg \
  mara_style_hologram_walking.jpg; do
  if [[ ! -f "$PROJECT_ROOT/images/mara_references/$image_name" ]]; then
    mara_missing=$((mara_missing + 1))
  fi
done
if (( mara_missing == 0 )); then
  ok 'Maras sieben Referenzbilder sind im Repository vorhanden.'
else
  printf '[WARN] %s\n' "Maras Bildset noch unvollstaendig ($mara_missing von 7 fehlen); fehlende Bilder werden uebersprungen." >&2
fi

image_service_test=''
if image_service_test="$(python3 "$PROJECT_ROOT/scripts/image-service-selftest.py" 2>&1)"; then
  ok 'Bilddienst: Register, echte Schritte, Gewichte mit festem SHA-256, ein Bild zur Zeit, Abbruch, Zeitlimit und Uebernahme aus dem Bildtest geprueft.'
else
  fail "Bilddienst-Selbsttest: ${image_service_test:-Unbekannter Fehler}"
fi

if python3 "$PROJECT_ROOT/scripts/python-input-selftest.py" >/dev/null 2>&1; then
  ok 'Python-Eingaben: Nachrichtenzuordnung, Originaldateien und ZIP-Pruefung getestet.'
else
  fail 'Python-Eingabedatei-Selbsttest fehlgeschlagen.'
fi

if python3 "$PROJECT_ROOT/scripts/cache-bust.py" --root "$PROJECT_ROOT" --verify > /dev/null; then
  ok 'HTML-Assets verwenden aktuelle numerische Content-Cache-Schluessel.'
else
  fail 'Veraltete Asset-Cache-Schluessel oder ungueltige VERSION. Installer erneut ausfuehren.'
fi
if grep -Fq 'console_artifact|console_artifacts' "$PROJECT_ROOT/config/runtime/nginx.conf.template"; then
  ok 'Nginx erlaubt geschuetzte Datei-Downloads und Metadaten-Endpunkt.'
else
  fail 'Nginx-Routing fuer Chat-Dateiausgaben fehlt.'
fi
if grep -Fq '|console_insights|' "$PROJECT_ROOT/config/runtime/nginx.conf.template"; then
  ok 'Nginx erlaubt die Infokaesten (Core, Kontext, Werkzeuge, Projekt).'
else
  fail 'Nginx-Routing fuer die Infokaesten fehlt.'
fi
if grep -Fq '|voice_live|' "$PROJECT_ROOT/config/runtime/nginx.conf.template"; then
  ok '0.6.31: Nginx erlaubt das Live-Gespraech (api/voice_live.php).'
else
  fail 'Nginx sperrt das Live-Gespraech (voice_live fehlt in der Freigabe).'
fi
if grep -Fq '|notifications|' "$PROJECT_ROOT/config/runtime/nginx.conf.template" && grep -Fq 'location = /sw.js' "$PROJECT_ROOT/config/runtime/nginx.conf.template"; then
  ok '0.6.81: Nginx erlaubt Benachrichtigungen (api/notifications.php) und den Service Worker (/sw.js).'
else
  fail 'Nginx sperrt Benachrichtigungen oder den Service Worker (notifications oder /sw.js fehlt).'
fi
if grep -Fq '|composer_tools|' "$PROJECT_ROOT/config/runtime/nginx.conf.template"; then
  ok '0.6.43: Nginx erlaubt die Werkzeug-Freigaben des + Menues (api/composer_tools.php).'
else
  fail 'Nginx sperrt das + Menue (composer_tools fehlt in der Freigabe).'
fi
if grep -Fq '|voice_input|' "$PROJECT_ROOT/config/runtime/nginx.conf.template" && grep -Fq 'microphone=(self)' "$PROJECT_ROOT/config/runtime/nginx.conf.template" \
   && ! grep -Fq 'microphone=()' "$PROJECT_ROOT/config/runtime/nginx.conf.template"; then
  ok 'Nginx erlaubt die Spracheingabe (api/voice_input.php und Mikrofon fuer die eigene Seite).'
else
  fail 'Nginx sperrt die Spracheingabe (voice_input fehlt oder Mikrofon verboten).'
fi

if (( FAILURES == 0 )); then
  ok 'E.M.B.E.R.-CoreUI-Branding, Lizenzunterlagen, KI-Einstellungen und Admin Core sind paketiert.'
fi

PRIVATE_RELEASE_FILES=()
mapfile -d '' -n 1 PRIVATE_RELEASE_FILES < <(
  find -P "$PROJECT_ROOT" \
    \( -path "$PROJECT_ROOT/var" \
       -o -path "$PROJECT_ROOT/logs" \
       -o -path "$PROJECT_ROOT/uploads" \
       -o -path "$PROJECT_ROOT/assets/chat_media" \
       -o -path "$PROJECT_ROOT/assets/profile_photos" \) -prune \
    -o -type f \( -iname '*master*bibel*' -o -iname '*kompendium*v6*' \) -print0
)
if (( ${#PRIVATE_RELEASE_FILES[@]} > 0 )); then
  private_release_file="${PRIVATE_RELEASE_FILES[0]#"$PROJECT_ROOT"/}"
  fail "Privates Bibel- oder Kompendium-Material ist noch im Release enthalten: $private_release_file"
else
  ok 'Das Release enthaelt kein privates Bibel- oder Kompendium-Dokument.'
fi

if grep -Fq "action: 'delete_permanently'" "$PROJECT_ROOT/js/console-app.js" \
    && grep -Fq "coreui_console_session_delete_tx" "$PROJECT_ROOT/api/console_session_store.php" \
    && grep -Fq "delete_confirmation_required" "$PROJECT_ROOT/api/console_sessions.php"; then
  ok 'Archivgeschuetzte, transaktionale Sitzungsloeschung ist paketiert.'
else
  fail 'Die sichere Sitzungsloeschung ist unvollstaendig paketiert.'
fi

if grep -Fq "sse_send('token'" "$PROJECT_ROOT/api/console_stream.php"; then
  fail 'Der SSE-Endpunkt sendet noch ungepruefte Modell-Tokens an den Browser.'
else
  ok 'SSE-Ausgaben passieren erst nach der serverseitigen Antwortpruefung.'
fi

if grep -Fq "{action: 'core', model_override: value}" "$PROJECT_ROOT/js/coreui-core-mode.js" \
    && grep -Fq "coreui_private_knowledge_block" "$PROJECT_ROOT/api/chat.php" \
    && grep -Fq "user_create" "$PROJECT_ROOT/api/admin.php"; then
  ok 'Funktionale Modellwahl, privates RAG-Lite und Benutzeranlage sind paketiert.'
else
  fail 'Mindestens eine neue 0.4.0-Funktion ist nicht vollstaendig verdrahtet.'
fi

# The stream may apply a per-call Thinking override before the account default.
# Match the actual payload binding, not a frozen PHP source string from 0.5.50.
# The reply-pipeline selftest verifies the precise runtime contract later.
if grep -Fq "action: 'thinking'" "$PROJECT_ROOT/js/coreui-core-mode.js" \
    && grep -Fq "coreui_general_think_value(\$model, \$thinkOverride ?? ember_thinking_enabled())" "$PROJECT_ROOT/api/console_stream.php"; then
  ok 'Denkschalter im Chat und Modellaufruf inklusive kontobezogenem Standardwert sind paketiert.'
else
  fail 'Thinking-Schalter oder Modell-Payload-Anbindung fehlen.'
fi

if grep -Fq 'MAX_MESSAGE_ATTACHMENTS = 10' "$PROJECT_ROOT/js/console-app.js" \
    && grep -Fq 'stu_console_message_attachments' "$PROJECT_ROOT/database/migrations/005_thinking_attachments.sql"; then
  ok 'Bis zu zehn persistente Nachrichtenanhaenge sind paketiert.'
else
  fail 'Mehrfachanhaenge oder ihre Datenbankmigration fehlen.'
fi

if grep -Fq 'coreui_ollama_model_exists' "$PROJECT_ROOT/api/user_settings.php" \
    && grep -Fq 'password_change' "$PROJECT_ROOT/api/account_security.php" \
    && grep -Fq 'thinking_content_exported' "$PROJECT_ROOT/api/account_export.php" \
    && grep -Fq 'loadAccountSecurity()' "$PROJECT_ROOT/js/settings.js"; then
  ok 'Kontosicherheit, sicherer Datenexport und validierte Ollama-Modellwahl sind paketiert.'
else
  fail 'Mindestens eine neue 0.4.1-Funktion ist nicht vollstaendig verdrahtet.'
fi

if (( FAILURES > 0 )); then
  printf '\nPreflight vorzeitig beendet: %d Problem(e).\n' "$FAILURES" >&2
  exit 1
fi

# Die Datei wird ausschliesslich vom Installer mit validierten einzeiligen Werten erzeugt.
set -a
# shellcheck disable=SC1090
source "$ENV_FILE"
set +a

if command -v docker >/dev/null 2>&1 && docker info >/dev/null 2>&1; then
  ok 'Docker-Daemon ist erreichbar.'
else
  fail 'Docker-Daemon ist nicht erreichbar.'
fi

if docker compose version >/dev/null 2>&1; then
  COMPOSE_CMD=(docker compose)
  ok 'Docker Compose v2 ist vorhanden.'
elif command -v docker-compose >/dev/null 2>&1; then
  COMPOSE_CMD=(docker-compose)
  ok 'Docker Compose ist vorhanden.'
else
  fail 'Docker Compose fehlt.'
fi

if (( ${#COMPOSE_CMD[@]} > 0 )); then
  if compose config >/dev/null 2>&1; then
    ok 'Compose-Konfiguration ist gueltig.'
  else
    fail 'Compose-Konfiguration ist ungueltig.'
  fi

  check_service database 'CoreAI: TCI Interface-MariaDB'
  check_service php 'CoreAI: TCI Interface-PHP-FPM'
  check_service web 'CoreAI: TCI Interface-Nginx'
  check_service pyworker 'CoreAI: TCI Interface-Python-Worker'
  if is_enabled "${COREUI_INSTALL_SEARXNG:-0}"; then
    check_service searxng 'CoreAI: TCI Interface-SearXNG'
  fi
  if is_enabled "${COREUI_INSTALL_BROWSE:-0}"; then
    check_service browse 'CoreAI: TCI Interface-Browse-Worker'
  fi

  # A running search container need not produce usable search results.
  # Probe from the same host-network worker and warn if its backend is empty.
  if is_enabled "${COREUI_INSTALL_SEARXNG:-0}" && is_enabled "${COREUI_INSTALL_BROWSE:-0}" \
    && service_running searxng && service_running browse; then
    if compose exec -T browse python3 -c \
       'import sys;sys.path.insert(0,"/var/www/coreui/tools");import ember_browse_worker as w;c=w.parse_php_config("/var/www/coreui/api/config.local.php");r=w.searxng_search(c,"Wikipedia",raise_errors=True);sys.exit(0 if r else 1)' \
       >/dev/null 2>&1; then
      ok 'Deep Research: SearXNG liefert im Browse-Container echte Suchtreffer.'
    else
      warn 'Deep Research: SearXNG liefert keine Suchtreffer oder ist nicht erreichbar. Die Ersatzsuche kann eingreifen.'
    fi
  fi

  if compose exec -T php php -r \
      'require "/var/www/coreui/api/db.php"; echo (int)stu_pdo()->query("SELECT COUNT(*) FROM stu_schema_migrations")->fetchColumn();' \
      >/dev/null 2>&1; then
    ok 'Eigene MariaDB-Verbindung und CoreAI: TCI Interface-Schema funktionieren.'
  else
    fail 'Eigene MariaDB-Verbindung oder CoreAI: TCI Interface-Schema ist fehlerhaft.'
  fi

  if compose exec -T php php -r \
      'require "/var/www/coreui/api/db.php"; $p=stu_pdo(); $p->query("SELECT user_id FROM stu_user_ai_settings LIMIT 1"); $p->query("SELECT id FROM stu_admin_audit LIMIT 1");' \
      >/dev/null 2>&1; then
    ok 'Migration 002: Nutzer-KI-Einstellungen und Admin-Audit sind bereit.'
  else
    fail 'Migration 002 fehlt. Fuehre scripts/stack.sh migrate aus.'
  fi

  if compose exec -T php php -r \
      'require "/var/www/coreui/api/db.php"; $p=stu_pdo(); $p->query("SELECT last_message_id,last_read_message_id,archived_at FROM stu_console_sessions LIMIT 0"); $p->query("SELECT session_id,reply_to_id FROM stu_chat_messages LIMIT 0"); $p->query("SELECT session_id,trigger_message_id FROM stu_ember_browse_jobs LIMIT 0");' \
      >/dev/null 2>&1; then
    ok 'Migration 003: echte Sitzungen und Turn-Zuordnung sind bereit.'
  else
    fail 'Migration 003 fehlt. Fuehre scripts/stack.sh migrate aus.'
  fi

  if compose exec -T php php -r \
      'require "/var/www/coreui/api/db.php"; $p=stu_pdo(); $p->query("SELECT user_id,display_name,assistant_name FROM stu_coreui_profiles LIMIT 0"); $p->query("SELECT user_id,slot FROM stu_coreui_profile_media LIMIT 0"); $p->query("SELECT uuid,user_id,status FROM stu_user_knowledge_sources LIMIT 0"); $p->query("SELECT source_uuid,user_id,chunk_text FROM stu_user_knowledge_chunks LIMIT 0");' \
      >/dev/null 2>&1; then
    ok 'Migration 004: Profile, CoreAI-Identitaet und privates RAG-Lite sind bereit.'
  else
    fail 'Migration 004 fehlt. Fuehre scripts/stack.sh migrate aus.'
  fi

  if compose exec -T php php -r \
      'require "/var/www/coreui/api/db.php"; $p=stu_pdo(); $p->query("SELECT thinking_enabled FROM stu_user_ai_settings LIMIT 0"); $p->query("SELECT message_id,media_uuid,user_id,position FROM stu_console_message_attachments LIMIT 0");' \
      >/dev/null 2>&1; then
    ok 'Migration 005: Thinking-Wahl und persistente Mehrfachanhaenge sind bereit.'
  else
    fail 'Migration 005 fehlt. Fuehre scripts/stack.sh migrate aus.'
  fi

  if compose exec -T php php -r \
      'require "/var/www/coreui/api/db.php"; $p=stu_pdo(); $p->query("SELECT password_changed_at,last_login_at FROM stu_users LIMIT 0"); $p->query("SELECT token_hash,expires_at,revoked_at FROM stu_auth_sessions LIMIT 0");' \
      >/dev/null 2>&1; then
    ok 'Migration 006: widerrufbare Anmeldungen und Passwortzeitpunkte sind bereit.'
  else
    fail 'Migration 006 fehlt. Fuehre scripts/stack.sh migrate aus.'
  fi

  if compose exec -T php php -r \
      'require "/var/www/coreui/api/db.php"; $p=stu_pdo(); $s=$p->prepare("SELECT COUNT(*) FROM ember_knowledge_chunks WHERE source IN (?,?)"); $s->execute(["bibel_v10_4","kompendium_v6"]); exit((int)$s->fetchColumn() === 0 ? 0 : 1);' \
      >/dev/null 2>&1; then
    ok 'Migration 007: versehentlich importiertes privates Studio-Lore ist entfernt.'
  else
    fail 'Migration 007 fehlt oder privates Studio-Lore liegt noch in der Datenbank.'
  fi

  if compose exec -T php php -r \
      'require "/var/www/coreui/api/db.php"; $p=stu_pdo(); $p->query("SELECT response_floor_id,mode,status,response_message_id,browse_job_id FROM stu_console_generation_requests LIMIT 0");' \
      >/dev/null 2>&1; then
    ok 'Migration 008: Feedback, Alternativen und Fortsetzungen sind bereit.'
  else
    fail 'Migration 008 fehlt. Fuehre scripts/stack.sh migrate aus.'
  fi

  if compose exec -T php php -r \
      'require "/var/www/coreui/api/db.php"; $p=stu_pdo(); $p->query("SELECT revision_no,superseded_message_count FROM stu_console_message_revisions LIMIT 0"); $m=$p->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=\"stu_console_generation_requests\" AND COLUMN_NAME=\"mode\"")->fetchColumn(); exit(str_contains((string)$m,"\x27edit\x27") ? 0 : 1);' \
      >/dev/null 2>&1; then
    ok 'Migration 009: echte Nachrichtenbearbeitung und Kontext-Neustart sind bereit.'
  else
    fail 'Migration 009 fehlt. Fuehre scripts/stack.sh migrate aus.'
  fi

  if compose exec -T php php -r \
      'require "/var/www/coreui/api/db.php"; require "/var/www/coreui/api/console_artifact_store.php"; exit(coreui_console_artifact_schema_ready(stu_pdo()) ? 0 : 1);' \
      >/dev/null 2>&1; then
    ok 'Migration 011: dauerhafte Chat-Dateiausgaben sind bereit.'
  else
    fail 'Migration 011 fehlt. Fuehre scripts/stack.sh migrate aus.'
  fi

  if compose exec -T php php -r \
      'require "/var/www/coreui/api/db.php"; require "/var/www/coreui/api/console_work_store.php"; exit(coreui_work_schema_ready(stu_pdo()) ? 0 : 1);' \
      >/dev/null 2>&1; then
    ok 'Migration 012: persistente Arbeitsereignisse bereit.'
  else
    fail 'Migration 012 fehlt. Fuehre scripts/stack.sh migrate aus.'
  fi

  # Since 0.5.81 skills use the repository library, not per-account DB switches.
  # Migration 014 remains in the migration history; skills-selftest.php below
  # validates the current library and work-loop integration.

  if compose exec -T php php -r \
      'require "/var/www/coreui/api/db.php"; $p=stu_pdo(); $t=$p->query("SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=\"stu_chat_messages\" AND COLUMN_NAME=\"message\"")->fetchColumn(); exit(strtolower((string)$t)==="longtext" ? 0 : 1);' \
      >/dev/null 2>&1; then
    ok 'Migration 015: lange Chat-Antworten dauerhaft speicherbar.'
  else
    fail 'Migration 015 fehlt: stu_chat_messages.message ist nicht LONGTEXT.'
  fi

  if compose exec -T php php -r \
      'require "/var/www/coreui/api/db.php"; require "/var/www/coreui/api/console_plan_store.php"; exit(coreui_plan_schema_ready(stu_pdo()) ? 0 : 1);' \
      >/dev/null 2>&1; then
    ok 'Migration 016: dauerhafte Markdown-Planungen bereit.'
  else
    fail 'Migration 016 fehlt: Markdown-Projektplanung nicht bereit.'
  fi

  if compose exec -T php php -r \
      'require "/var/www/coreui/api/db.php"; require "/var/www/coreui/api/console_agent_runs.php"; exit(coreui_agent_run_schema_ready(stu_pdo()) ? 0 : 1);' \
      >/dev/null 2>&1; then
    ok 'Migration 013: dauerhafte Agentenlaeufe bereit.'
  else
    fail 'Migration 013 fehlt. Fuehre scripts/stack.sh migrate aus.'
  fi

  if compose exec -T php php -r \
      'require "/var/www/coreui/api/db.php"; require "/var/www/coreui/api/console_model_queue.php"; exit(coreui_model_queue_schema_ready(stu_pdo()) ? 0 : 1);' \
      >/dev/null 2>&1; then
    ok 'Migration 013: globale FIFO-Modellwarteschlange bereit.'
  else
    fail 'Migration 013: Modellwarteschlange fehlt.'
  fi

  if compose exec -T php php scripts/agent-runs-selftest.php >/dev/null 2>&1; then
    ok 'P05: Agentenlauf-Idempotenz und Kontotrennung offline geprueft.'
  else
    fail 'P05-Agentenlauf-Selbsttest fehlgeschlagen.'
  fi

  if compose exec -T php php scripts/agent-run-liveness-selftest.php >/dev/null 2>&1; then
    ok '0.6.9: Verlorene Agentenlaeufe enden (Herzschlag, Abbruch, Loeschen) geprueft.'
  else
    fail 'Selbsttest fuer verlorene Agentenlaeufe fehlgeschlagen.'
  fi

  # 0.6.97: no production DB writes from language selftests, even after an update.
  account_language_safety=''
  if account_language_safety="$(compose exec -T php php scripts/account-language-safety-selftest.php 2>&1)"; then
    ok 'KI-Profil: Sprach-Selbsttest isoliert und Testkonto-Bereinigung geprueft.'
  else
    fail "KI-Profil: Sicherheitsregression: ${account_language_safety:-Unbekannt}"
  fi
  account_language_output=''
  if account_language_output="$(compose exec -T php env COREUI_SELFTEST_DB=0 php scripts/account-language-selftest.php 2>&1)" && [[ "$account_language_output" == *'live database untouched'* ]]; then
    ok 'KI-Profil: Hauptsprache, Prompt und Vorlesen ohne produktive Datenbankschreibzugriffe.'
  else
    fail "KI-Profil: Sprach-Selbsttest beruehrt moeglicherweise Produktivdaten: ${account_language_output:-Unbekannt}"
  fi

  time_test=''
  if time_test="$(compose exec -T php php scripts/message-time-selftest.php 2>&1)"; then
    ok '0.6.29: Zeiten im Chat mit Zeitzone (Nachrichten und Chatliste, Datenbank in UTC und Ortszeit) geprueft.'
  else
    fail "Zeit-Selbsttest: ${time_test:-Unbekannter Fehler}"
  fi

  live_test=''
  if live_test="$(compose exec -T php php scripts/voice-live-selftest.php 2>&1)"; then
    ok '0.6.31: Live-Gespraech (Live-Core, Saetze fuer Eve, gesprochene Antwort, fester Anfang fuer den Cache, Modelfile-SYSTEM bleibt) geprueft.'
  else
    fail "Live-Gespraech-Selbsttest: ${live_test:-Unbekannter Fehler}"
  fi

  voice_input_test=''
  if voice_input_test="$(compose exec -T php php scripts/voice-input-selftest.php 2>&1)"; then
    ok '0.6.17: Sprachnachrichten (WAVs bis 60 s in Maras Aufruf, ohne Denken, Mitschrift nach der Antwort, Verlauf, WebM als Audio; 0.6.22 Begriffe und Werkzeuge) geprueft.'
  else
    fail "Spracheingabe-Selbsttest: ${voice_input_test:-Unbekannter Fehler}"
  fi

  if compose exec -T php php scripts/model-queue-selftest.php >/dev/null 2>&1; then
    ok 'P05: Modell-FIFO, Timeout, Abbruch und Sperrenfreigabe offline geprueft.'
  else
    fail 'P05-Modellwarteschlangen-Selbsttest fehlgeschlagen.'
  fi

  if compose exec -T php php scripts/work-events-selftest.php >/dev/null 2>&1; then
    ok 'P06-Arbeitsereignisse und sichere Python-Fehlerklassen geprueft.'
  else
    fail 'P06-Arbeitsereignis-Selbsttest fehlgeschlagen.'
  fi
  if compose exec -T php php scripts/work-events-persistence-selftest.php >/dev/null 2>&1; then
    ok 'P06: Arbeitsweg nach Antwort, Reload, Varianten und Kontotrennung geprueft.'
  else
    fail 'P06-Arbeitsweg-Persistenz-Selbsttest fehlgeschlagen.'
  fi

  if compose exec -T php php scripts/model-live-audit.php --selftest >/dev/null 2>&1; then
    ok 'Modelfile-Audit: SYSTEM- und Abweichungspruefung offline bestanden.'
  else
    fail 'Modelfile-Audit: Offline-Selbsttest fehlgeschlagen.'
  fi

  gemma_test=''
  if gemma_test="$(compose exec -T php php scripts/gemma-zero-parameters-selftest.php 2>&1)"; then
    ok 'Gemma: aktives Modelfile, Dateiabgleich und keine API-Parameterueberschreibung.'
  else
    fail "Gemma-Modelfile-/Ollama-Parametervertrag: ${gemma_test:-Unbekannter Fehler}"
  fi

  # Node is optional for PHP installations. Run the real JS unit test when
  # present on the host; the PHP frontend guard always checks integration.
  if native_test="$(compose exec -T php php scripts/native-tools-selftest.php 2>&1)"; then
    ok "$native_test"
  else
    fail "Native Tools: $native_test"
  fi

  project_intent_test=''
  if project_intent_test="$(compose exec -T php php scripts/model-project-intent-selftest.php 2>&1)"; then
    ok "$project_intent_test"
  else
    fail "Modellgesteuerte Projektumsetzung: ${project_intent_test:-Unbekannter Fehler}"
  fi

  plan_auto_test=''
  if plan_auto_test="$(compose exec -T php php scripts/plan-autocontinue-selftest.php 2>&1)"; then
    ok "$plan_auto_test"
  else
    fail "Autonome Markdown-Planung: ${plan_auto_test:-Unbekannter Fehler}"
  fi

  project_test=''
  if project_test="$(compose exec -T php php scripts/contextual-project-selftest.php 2>&1)"; then
    ok "$project_test"
  else
    fail "Modellgesteuerte Projektuebergabe: ${project_test:-Unbekannter Fehler}"
  fi

  file_test=''
  if file_test="$(compose exec -T php php scripts/local-file-tool-selftest.php 2>&1)"; then
    ok 'Lokale Dateiauftraege: Python-Fences, mehrteilige Projekte, ZIP und Vollstaendigkeit geprueft.'
  else
    fail "Lokaler Dateiwerkzeug-Selbsttest: ${file_test:-Unbekannter Fehler}"
  fi

  context_test=''
  if context_test="$(compose exec -T php php scripts/context-budget-selftest.php 2>&1)"; then
    ok 'Kontextfenster: erkannte Fenstergroesse, Datei-Prompt-Budget und Planauszug geprueft.'
  else
    fail "Kontextfenster-Selbsttest: ${context_test:-Unbekannter Fehler}"
  fi

  insights_test=''
  if insights_test="$(compose exec -T php php scripts/insights-selftest.php 2>&1)"; then
    ok 'Infokaesten: Core, Kontext, Werkzeuge und Projekt nur lesend und sitzungsbezogen.'
  else
    fail "Infokaesten-Selbsttest: ${insights_test:-Unbekannter Fehler}"
  fi

  ai_context_test=''
  if ai_context_test="$(compose exec -T php php scripts/ai-context-selftest.php 2>&1)"; then
    ok 'KI-Profil: Kontextfenster nur auf Wunsch, nie Cloud, pro Lauf fixiert, Speicher-Richtwerte geprueft.'
  else
    fail "KI-Profil-Kontextfenster-Selbsttest: ${ai_context_test:-Unbekannter Fehler}"
  fi

  date_test=''
  if date_test="$(compose exec -T php php scripts/date-awareness-selftest.php 2>&1)"; then
    ok 'Datum und Uhrzeit: jeder Core-Aufruf eines Konsolenauftrags kennt beides.'
  else
    fail "Datums-Selbsttest: ${date_test:-Unbekannter Fehler}"
  fi

  knowledge_test=''
  if knowledge_test="$(compose exec -T php php scripts/knowledge-search-selftest.php 2>&1)"; then
    ok 'RAG-Lite: seltene Namen vor Allerweltsbegriffen, Rueckfragen ueber den Verlauf, sichtbarer Suchschritt.'
  else
    fail "RAG-Lite-Suche: ${knowledge_test:-Unbekannter Fehler}"
  fi

  vision_test=''
  if vision_test="$(compose exec -T php php scripts/vision-persona-selftest.php 2>&1)"; then
    ok 'Bild und Video: dieselbe Mara wie bei Text, kein falscher Fehler, keine Bildfakten im Gedaechtnis.'
  else
    fail "Bild-/Video-Persona: ${vision_test:-Unbekannter Fehler}"
  fi

  audio_test=''
  if audio_test="$(compose exec -T php php scripts/audio-wav-selftest.php 2>&1)"; then
    ok 'Audio: WAV-Pruefung, Hilfen fuer Audio-Aufrufe und Medien-Test P20.0 geprueft.'
  else
    fail "Audio-WAV-Selbsttest: ${audio_test:-Unbekannter Fehler}"
  fi

  tiers_test=''
  if tiers_test="$(compose exec -T php php scripts/core-tiers-selftest.php 2>&1)"; then
    ok 'Core-Stufen: TCI-Namen im Chat und Maras Persona fuer jeden Core geprueft.'
  else
    fail "Core-Stufen-Selbsttest: ${tiers_test:-Unbekannter Fehler}"
  fi

  install_test=''
  if install_test="$(compose exec -T php php scripts/core-install-selftest.php 2>&1)"; then
    ok 'Core-Installer: Maras Core aus dem Modelfile, Hardware-Check, Fortschritt, ein Auftrag, nur Admins geprueft.'
  else
    fail "Core-Installer-Selbsttest: ${install_test:-Unbekannter Fehler}"
  fi

  library_test=''
  if library_test="$(compose exec -T php php scripts/ollama-library-selftest.php 2>&1)"; then
    ok 'Ollama-Modellsuche: lokale Varianten, sichere Herkunft und gemeinsamer Installationsstatus geprueft.'
  else
    fail "Ollama-Modellsuche-Selbsttest: ${library_test:-Unbekannter Fehler}"
  fi

  # 0.6.96: the PHP-FPM user must be able to queue a request on the host.
  if compose exec -T -u 33:33 php php -r 'exit(is_writable("/var/www/coreui/var/uninstall-request") ? 0 : 1);' >/dev/null 2>&1; then
    ok 'Deinstallation: PHP-Hostanfragen im eigenen beschreibbaren Bind-Mount verfuegbar.'
  else
    fail 'Deinstallation: var/uninstall-request fuer PHP (UID 33) nicht beschreibbar.'
  fi

  uninstall_store_test=''
  if uninstall_store_test="$(compose exec -T php php scripts/uninstall-store-selftest.php 2>&1)"; then
    ok 'Deinstallation: sichere Eigentumeranfrage, Archiv-Modus und atomare Queue geprueft.'
  else
    fail "Deinstallation: Queue-Selbsttest fehlgeschlagen. Details: $uninstall_store_test"
  fi

  installed_cores_test=''
  if installed_cores_test="$(compose exec -T php php scripts/installed-cores-selftest.php 2>&1)"; then
    ok 'Installierte Cores: Standard und Basis geschützt, Einzel-Deinstallation und Rollenpfad geprüft.'
  else
    fail "Installierte-Cores-Selbsttest: ${installed_cores_test:-Unbekannter Fehler}"
  fi

  guest_test=''
  if guest_test="$(compose exec -T php php scripts/guest-access-selftest.php 2>&1)"; then
    ok 'Gast-Zugang: Limit, Zaehlung bei jeder Antwort, nur privater Chat, Stundenlimit, Login und Admin geprueft.'
  else
    fail "Gast-Zugang-Selbsttest: ${guest_test:-Unbekannter Fehler}"
  fi

  abuse_test=''
  if abuse_test="$(compose exec -T php php scripts/abuse-guard-selftest.php 2>&1)"; then
    ok 'Missbrauchsschutz: Geraetemerkmal, Netz-Kontingent, Tagesgrenze, Login-Drossel und echte Besucher-Adresse geprueft.'
  else
    fail "Missbrauchsschutz-Selbsttest: ${abuse_test:-Unbekannter Fehler}"
  fi

  captcha_test=''
  if captcha_test="$(compose exec -T php php scripts/captcha-selftest.php 2>&1)"; then
    ok 'Captcha: signierte Aufgabe, einmalig gueltig, Faelschung, Ablauf und Wiederverwendung abgelehnt.'
  else
    fail "Captcha-Selbsttest: ${captcha_test:-Unbekannter Fehler}"
  fi

  roles_test=''
  if roles_test="$(compose exec -T php php scripts/roles-guest-selftest.php 2>&1)"; then
    ok 'Rollen: Inhaber, Admin, Nutzer, Gast; Gaeste nutzen den Standard-Core.'
  else
    fail "Rollen-Selbsttest: ${roles_test:-Unbekannter Fehler}"
  fi

  # 0.5.83: models outside CoreAI only for the Inhaber and released accounts.
  model_access_test=''
  if model_access_test="$(compose exec -T php php scripts/model-access-selftest.php 2>&1)"; then
    ok 'Modell-Freigaben: Maras Cores fuer alle, fremde Modelle nur fuer Inhaber und freigegebene Konten.'
  else
    fail "Modell-Freigaben-Selbsttest: ${model_access_test:-Unbekannter Fehler}"
  fi

  # 0.5.83: update window: only a new commit, Inhaber only, waiting for everyone.
  update_test=''
  if update_test="$(compose exec -T php php scripts/update-selftest.php 2>&1)"; then
    ok 'Update-Fenster: nur bei neuem Commit, Start nur durch den Inhaber, Wartefenster fuer alle.'
  else
    fail "Update-Selbsttest: ${update_test:-Unbekannter Fehler}"
  fi

  # 0.5.84: deleting an account (rules, confirmation, files; a full deletion on the database, rolled back).
  delete_test=''
  if delete_test="$(compose exec -T php php scripts/account-delete-selftest.php 2>&1)"; then
    ok 'Konto loeschen: Rechte, Bestaetigung, Dateien und vollstaendige Loeschung (mit Rollback) geprueft.'
  else
    fail "Konto-Loeschen-Selbsttest: ${delete_test:-Unbekannter Fehler}"
  fi

  # 0.5.85: settings per account (password, signing out, name and e-mail; on the database rolled back).
  manage_test=''
  if manage_test="$(compose exec -T php php scripts/account-admin-selftest.php 2>&1)"; then
    ok 'Konto bearbeiten: Rechte, Passwort, Abmelden, Name und E-Mail (mit Rollback) geprueft.'
  else
    fail "Konto-Bearbeiten-Selbsttest: ${manage_test:-Unbekannter Fehler}"
  fi

  # 0.5.85: own rules per account (tools blocked per account, global off stays off).
  policy_test=''
  if policy_test="$(compose exec -T php php scripts/user-policy-selftest.php 2>&1)"; then
    ok 'Regeln pro Konto: Werkzeuge gesperrt, global aus bleibt aus, Tageslimit, Kontextfenster (mit Rollback) geprueft.'
  else
    fail "Regeln-pro-Konto-Selbsttest: ${policy_test:-Unbekannter Fehler}"
  fi

  # 0.5.86: Mara's memories stored and used whole (no cut), budget per window.
  memory_length_test=''
  if memory_length_test="$(compose exec -T php php scripts/memory-length-selftest.php 2>&1)"; then
    ok 'Memory: Erinnerungen ganz gespeichert und ganz im Prompt, Budget je Kontextfenster geprueft.'
  else
    fail "Memory-Laengen-Selbsttest: ${memory_length_test:-Unbekannter Fehler}"
  fi

  # 0.6.1: read aloud on the PHP side: cache per account, 7 days, guests never, only own answers.
  voice_read_test=''
  if voice_read_test="$(compose exec -T php php scripts/voice-read-selftest.php 2>&1)"; then
    ok 'Vorlesen: Zwischenspeicher pro Konto (7 Tage), keine Gaeste, nur eigene Antworten, Schalter im KI-Profil geprueft.'
  else
    fail "Vorlesen-Selbsttest: ${voice_read_test:-Unbekannter Fehler}"
  fi

  # 0.5.94: Stimmproben in Admin Core: runs, samples only when listed, Inhaber only.
  tts_store_test=''
  if tts_store_test="$(compose exec -T php php scripts/tts-spike-store-selftest.php 2>&1)"; then
    ok 'Stimmproben: Testlaeufe, nur gelistete Hoerproben aus ihrem Lauf, nur fuer den Inhaber geprueft.'
  else
    fail "Stimmproben-Selbsttest: ${tts_store_test:-Unbekannter Fehler}"
  fi

  # 0.6.37 (P20.5 A): Bildproben in Admin Core: runs, images only when listed and real PNGs, Inhaber only.
  image_store_test=''
  if image_store_test="$(compose exec -T php php scripts/image-spike-store-selftest.php 2>&1)"; then
    ok 'Bildproben: Testlaeufe, nur gelistete PNG-Bilder aus ihrem Lauf, nur fuer den Inhaber geprueft.'
  else
    fail "Bildproben-Selbsttest: ${image_store_test:-Unbekannter Fehler}"
  fi

  # 0.6.39 (P20.5 C): Mara's images in the chat: marker, work loop step, keeping the image for
  # the account, cancel, history, deletion with the chat, switches under Betrieb and per account.
  image_gen_test=''
  if image_gen_test="$(compose exec -T php php scripts/image-gen-selftest.php 2>&1)"; then
    ok 'Bilder im Chat: Marker, Arbeitsschritt, Speichern fuer das Konto, Abbruch, Verlauf, Loeschen und Schalter geprueft.'
  else
    fail "Bilder-im-Chat-Selbsttest: ${image_gen_test:-Unbekannter Fehler}"
  fi

  # 0.6.81: Benachrichtigungen: Web Push (RFC 8291 example, VAPID), saving, dispatch and triggers.
  notifications_test=''
  if notifications_test="$(compose exec -T php php scripts/notifications-selftest.php 2>&1)"; then
    ok 'Benachrichtigungen: Web-Push-Verschluesselung (RFC 8291), VAPID, Kategorien, Versand und Ausloeser geprueft.'
  else
    fail "Benachrichtigungs-Selbsttest: ${notifications_test:-Unbekannter Fehler}"
  fi

  # 0.6.82: E-Mail-Versand (SMTP) in Admin Core: input checks, sealed password, a real SMTP session.
  mail_settings_test=''
  if mail_settings_test="$(compose exec -T php php scripts/mail-settings-selftest.php 2>&1)"; then
    ok 'E-Mail-Versand: Eingaben, verschluesseltes Passwort, Vorrang der Serverkonfiguration und SMTP-Sitzung geprueft.'
  else
    fail "E-Mail-Versand-Selbsttest: ${mail_settings_test:-Unbekannter Fehler}"
  fi

  # 0.6.84: Profil overview: tokens counted per account, streaks, longest task, raster, tools, insights.
  profile_stats_test=''
  if profile_stats_test="$(compose exec -T php php scripts/profile-stats-selftest.php 2>&1)"; then
    ok 'Profil: Token-Zaehlung, Serien, laengste Aufgabe, Raster, Werkzeuge und Einblicke geprueft.'
  else
    fail "Profil-Selbsttest: ${profile_stats_test:-Unbekannter Fehler}"
  fi

  # 0.6.85: Sicherheit und Anmeldung: RFC 6238 codes, WebAuthn, lockdown, recovery, advanced account security.
  sign_in_security_test=''
  if sign_in_security_test="$(compose exec -T php php scripts/sign-in-security-selftest.php 2>&1)"; then
    ok 'Sicherheit: Authenticator-Codes (RFC 6238), Passkeys (WebAuthn), Lockdown, Wiederherstellung und erweiterte Kontosicherheit geprueft.'
  else
    fail "Sicherheits-Selbsttest: ${sign_in_security_test:-Unbekannter Fehler}"
  fi

  # 0.6.86: Konto: Benutzername (unique, reserved names), plans Free/Go/Plus/Pro as real rules.
  account_page_test=''
  if account_page_test="$(compose exec -T php php scripts/account-page-selftest.php 2>&1)"; then
    ok 'Konto: Benutzername (eindeutig, reservierte Namen) und Plaene (Free, Go, Plus, Pro als echte Regeln) geprueft.'
  else
    fail "Konto-Selbsttest: ${account_page_test:-Unbekannter Fehler}"
  fi

  # 0.6.87: chat menu and Archivierte Chats: pin order, archive search and filters, files, search, Markdown.
  chat_menu_test=''
  if chat_menu_test="$(compose exec -T php php scripts/chat-menu-selftest.php 2>&1)"; then
    ok 'Chat-Menue: Anpinnen, Archivsuche und Filter, Dateien, Suche im Chat und Teilen als Markdown geprueft.'
  else
    fail "Chat-Menue-Selbsttest: ${chat_menu_test:-Unbekannter Fehler}"
  fi

  # 0.7.0: Settings > Personalisierung: styles, instructions, reference photos, memory summary, wipe.
  personalization_page_test=''
  if personalization_page_test="$(compose exec -T php php scripts/personalization-page-selftest.php 2>&1)"; then
    ok 'Personalisierung: Stile, Anweisungen, Referenzfotos, Erinnerungszusammenfassung und Loeschen geprueft.'
  else
    fail "Personalisierung-Selbsttest: ${personalization_page_test:-Unbekannter Fehler}"
  fi

  # 0.7.1: Settings > Pets: built-in sheets, uploads (PNG, WebP), choice, the chat work line.
  pets_test=''
  if pets_test="$(compose exec -T php php scripts/pets-selftest.php 2>&1)"; then
    ok 'Pets: eigene CoreAI-Pets, Sprite-Sheets, Auswahl und Hochladen geprueft.'
  else
    fail "Pets-Selbsttest: ${pets_test:-Unbekannter Fehler}"
  fi

  # Optional community library assets are NOT shipped unless explicitly
  # imported with a confirmed creator license by the CoreAI owner.
  openpet_library_test=''
  if openpet_library_test="$(compose exec -T php php scripts/openpet-library-selftest.php 2>&1)"; then
    ok 'OpenPet: geprüfte Standard-Pet-Bibliothek, private Ablage und Rechteprüfung OK.'
  else
    fail "OpenPet-Bibliothek: ${openpet_library_test:-Unbekannter Fehler}"
  fi

  # 0.7.14: Settings > Stimme: voices to choose (free licenses), the chosen voice in plans and parts, preview, transcript language; 0.7.15 the microphone switch.
  voice_settings_test=''
  if voice_settings_test="$(compose exec -T php php scripts/voice-settings-selftest.php 2>&1)"; then
    ok 'Stimme: Stimmenauswahl, Vorhoeren, Sprache der Stimmsequenzen und Mikrofon-Schalter geprueft.'
  else
    fail "Stimme-Selbsttest: ${voice_settings_test:-Unbekannter Fehler}"
  fi

  # 0.7.28: Settings > Speicher: the limit for all accounts and per account, usage, Dateien and Bilder, favourites, deleting.
  storage_test=''
  if storage_test="$(compose exec -T php php scripts/storage-selftest.php 2>&1)"; then
    ok "$storage_test"
  else
    fail "Speicher-Selbsttest: ${storage_test:-Unbekannter Fehler}"
  fi

  # 0.7.30: Settings > Nutzung: plan and daily limit window, tokens by Core, tools, skills, messages by kind, top chats.
  usage_page_test=''
  if usage_page_test="$(compose exec -T php php scripts/usage-page-selftest.php 2>&1)"; then
    ok "$usage_page_test"
  else
    fail "Nutzung-Selbsttest: ${usage_page_test:-Unbekannter Fehler}"
  fi

  # 0.7.32: Settings > Datenkontrollen: archive and delete all chats (never locked or busy ones), the page.
  data_controls_test=''
  if data_controls_test="$(compose exec -T php php scripts/data-controls-selftest.php 2>&1)"; then
    ok "$data_controls_test"
  else
    fail "Datenkontrollen-Selbsttest: ${data_controls_test:-Unbekannter Fehler}"
  fi

  # 0.7.38: Deep Research runs: plan, Starten, Bearbeiten, Abbrechen, Stopp, sources, chat deletion.
  deep_research_test=''
  if deep_research_test="$(compose exec -T php php scripts/deep-research-selftest.php 2>&1)"; then
    ok "$deep_research_test"
  else
    fail "Deep-Research-Selbsttest: ${deep_research_test:-Unbekannter Fehler}"
  fi

  profile_reference_test=''
  if profile_reference_test="$(compose exec -T php php scripts/profile-reference-selftest.php 2>&1)"; then
    if [[ "$profile_reference_test" == PARTIAL:* ]]; then
      warn "$profile_reference_test"
    else
      ok "$profile_reference_test"
    fi
  else
    fail "Profilreferenz-Selbsttest: ${profile_reference_test:-Unbekannter Fehler}"
  fi

  # 0.6.59: all image references share identity, subject and style preservation.
  reference_consistency_test=''
  if reference_consistency_test="$(compose exec -T php php scripts/image-reference-consistency-selftest.php 2>&1)"; then
    ok 'Bildreferenzen: Identitaet, Motivtreue, Stilvorlage und mehrere Quellen geprueft.'
  else
    fail "Bildreferenz-Konsistenztest: ${reference_consistency_test:-Unbekannter Fehler}"
  fi

  # 0.6.43: the + menu of the input field: marks, Mara's instruction, the tool started with the
  # person's own words, the endpoint's answer for accounts and guests, the readable archive.
  composer_intent_test=''
  if composer_intent_test="$(compose exec -T php php scripts/composer-intent-selftest.php 2>&1)"; then
    ok '+ Menue: Werkzeug-Auswahl, Anweisung an Mara, Absicherung, Freigaben und Archiv geprueft.'
  else
    fail "+-Menue-Selbsttest: ${composer_intent_test:-Unbekannter Fehler}"
  fi

  compact_test=''
  if compact_test="$(compose exec -T php php scripts/compaction-selftest.php 2>&1)"; then
    ok 'Chat komprimieren: Fenster, laufende Zusammenfassung, Archiv zuerst, Suche und Loeschen geprueft.'
  else
    fail "Komprimierungs-Selbsttest: ${compact_test:-Unbekannter Fehler}"
  fi

  loop_test=''
  if loop_test="$(compose exec -T php php scripts/agent-loop-selftest.php 2>&1)"; then
    ok 'Arbeitsschleife: verkettete Schritte, Antwort, Uebergabe an den Browser, sechs Schritte, Abbruch geprueft.'
  else
    fail "Arbeitsschleifen-Selbsttest: ${loop_test:-Unbekannter Fehler}"
  fi

  web_test=''
  if web_test="$(compose exec -T php php scripts/web-search-selftest.php 2>&1)"; then
    ok 'Websuche: Stichworte als zweiter Versuch, keine Doppelten, saubere Snippets und Datum geprueft.'
  else
    fail "Websuche-Selbsttest: ${web_test:-Unbekannter Fehler}"
  fi

  weather_test=''
  if weather_test="$(compose exec -T php php scripts/weather-live-selftest.php 2>&1)"; then
    ok '0.6.22: Live-Wetter im Websuche-Schritt (WDR fuer NRW, Live-Daten fuer jeden Ort, keine alten Suchtreffer) geprueft.'
  else
    fail "Wetter-Selbsttest: ${weather_test:-Unbekannter Fehler}"
  fi

  if is_enabled "${COREUI_INSTALL_BROWSE:-0}" && service_running browse; then
    browse_live=''
    if browse_live="$(compose exec -T -e COREAI_BROWSE_LIVE=1 browse python3 scripts/browse-worker-selftest.py 2>&1)"; then
      ok 'Browser (echter Chromium-Lauf): Formular ausgefuellt, Bilder beim Ueberlegen, Login und Kontakt blockiert, lokales Netz gesperrt.'
    else
      fail "Browser-Livetest: ${browse_live:-Unbekannter Fehler}"
    fi
  fi

  mention_test=''
  if mention_test="$(compose exec -T php php scripts/tool-mention-selftest.php 2>&1)"; then
    ok 'Werkzeug-Erwaehnungen: Erklaerungen starten kein Werkzeug und keine Korrekturrunde.'
  else
    fail "Werkzeug-Erwaehnungs-Selbsttest: ${mention_test:-Unbekannter Fehler}"
  fi

  think_test=''
  if think_test="$(compose exec -T php php scripts/thinking-switch-selftest.php 2>&1)"; then
    ok 'Denkschalter und Core-Wahl: Modelfile ohne festes Denken, Speichern, Vorladen, globaler Kanal und Analyse-Filter geprueft.'
  else
    fail "Denkschalter-Selbsttest: ${think_test:-Unbekannter Fehler}"
  fi

  if command -v node >/dev/null 2>&1; then
    if node "$PROJECT_ROOT/scripts/terran-terminology-selftest.cjs" >/dev/null 2>&1; then
      ok 'Terranische CoreAI-Bezeichnungen: Terra-Net und Systemtexte geprueft.'
    else
      fail 'Terranische CoreAI-Bezeichnungen: Arch-Net/Ayalren-Rueckfall gefunden.'
    fi
    if node "$PROJECT_ROOT/scripts/tci-language-selftest.cjs" >/dev/null 2>&1; then
      ok 'Mara-TCI-Sprache und Stimmsequenz-Terminologie geprueft.'
    else
      fail 'Mara-TCI-Sprache: Terminologie-Selbsttest fehlgeschlagen.'
    fi
    if node "$PROJECT_ROOT/scripts/tci-chat-message-selftest.cjs" >/dev/null 2>&1; then
      ok 'TCI CORE: Chat-Kopf, breite Antwortspalte und Bildabschluss-Reihenfolge geprueft.'
    else
      fail 'TCI CORE: Chat-Kopf-/Bildabschluss-Selbsttest fehlgeschlagen.'
    fi
    if compose exec -T php php /var/www/coreui/scripts/general-controls-selftest.php >/dev/null 2>&1; then
      ok 'Allgemein: Core-Routing, gespeicherte Einstellungen und echte Denkstufen verifiziert.'
    else
      fail 'Allgemein: Core-Routing oder Reasoning-Selbsttest fehlgeschlagen.'
    fi
    if node "$PROJECT_ROOT/scripts/ui-language-selftest.cjs" >/dev/null 2>&1; then
      ok 'Allgemein: App-Sprache DE/EN und automatische Browsererkennung geprueft.'
    else
      fail 'Allgemein: App-Sprachumschaltung fehlgeschlagen.'
    fi
    if node "$PROJECT_ROOT/scripts/reasoning-effort-ui-selftest.cjs" >/dev/null 2>&1; then
      ok 'Denkaufwand: Chat-Stufen, echtes Speichern und binaerer Core-Fallback geprueft.'
    else
      fail 'Denkaufwand: Chat-Regler, General oder Core-Fallback fehlgeschlagen.'
    fi
    if node "$PROJECT_ROOT/scripts/send-shortcut-selftest.cjs" >/dev/null 2>&1; then
      ok 'Allgemein: Enter, Strg/Command+Enter und IME-Schutz geprueft.'
    else
      fail 'Allgemein: Tastatur-Shortcuts oder Composer-Integration fehlgeschlagen.'
    fi
    if node "$PROJECT_ROOT/scripts/theme-preferences-selftest.cjs" >/dev/null 2>&1; then
      ok 'Anzeige: System/Hell/Dunkel, Schriftart, Hell/Dunkel getrennt, Motiv-Pruefung und Zeichensetzungsregeln geprueft.'
    else
      fail 'Anzeige: Tag-/Nachtmodus-Selbsttest fehlgeschlagen.'
    fi
    if node "$PROJECT_ROOT/scripts/response-timing-ui-selftest.js" >/dev/null 2>&1; then
      ok 'Antwortdauer: gespeicherte Gesamt- und Modellzeit in der Infoanzeige geprueft.'
    else
      fail 'Antwortdauer-JavaScript-Selbsttest fehlgeschlagen.'
    fi
    if node "$PROJECT_ROOT/scripts/welcome-composer-ui-selftest.cjs" >/dev/null 2>&1; then
      ok 'Chat-Start/Composer: Begruessungsentwurf, Erst-Nachricht und Stopp geprueft.'
    else
      fail 'Chat-Start/Composer: Lazy-Sitzung oder Stopp-Schaltflaeche fehlgeschlagen.'
    fi
    if node "$PROJECT_ROOT/scripts/chat-navigation-recovery-selftest.cjs" >/dev/null 2>&1; then
      ok 'Chat-Navigation: bestehender Modellauftrag ohne erneute Generierung wiederhergestellt.'
    else
      fail 'Chat-Navigation: Wiederherstellungs-Selbsttest fehlgeschlagen.'
    fi
    if node "$PROJECT_ROOT/scripts/insights-ui-selftest.cjs" >/dev/null 2>&1; then
      ok 'Infokaesten: rechte Spalte, Handy-Buttons und Live-Kontext geprueft.'
    else
      fail 'Infokaesten: JavaScript-Selbsttest fehlgeschlagen.'
    fi
    if node "$PROJECT_ROOT/scripts/context-slider-ui-selftest.cjs" >/dev/null 2>&1; then
      ok 'KI-Profil: Kontextfenster-Regler mit Richtwert an jeder Stufe geprueft.'
    else
      fail 'KI-Profil: Kontextfenster-Regler-Selbsttest fehlgeschlagen.'
    fi
    if node "$PROJECT_ROOT/scripts/thinking-switch-ui-selftest.cjs" >/dev/null 2>&1; then
      ok 'Chat: Core-Chip mit TCI-Namen, fester Reihenfolge, Status-Punkten (gruen, gelb, rot), Legende, Laden, Denkschalter und Fehlerfaellen geprueft.'
    else
      fail 'Chat: Denkschalter-Oberflaechentest fehlgeschlagen.'
    fi
    if node "$PROJECT_ROOT/scripts/core-install-ui-selftest.cjs" >/dev/null 2>&1; then
      ok 'Chat: Install-Fenster mit Hardware-Check, Bestaetigung, Fortschritt und Jetzt nutzen geprueft.'
    else
      fail 'Chat: Install-Fenster-Oberflaechentest fehlgeschlagen.'
    fi
    if node "$PROJECT_ROOT/scripts/model-library-ui-selftest.cjs" >/dev/null 2>&1; then
      ok 'Ollama-Modellfenster: Suche, Detailansicht, Downloadbestaetigung und Fortschritt geprueft.'
    else
      fail 'Ollama-Modellfenster: Offline-Oberflaechentest fehlgeschlagen.'
    fi
    # 0.6.59: touch cropper must preserve originals, per-account re-edit and
    # user-square / assistant-portrait behavior.
    profile_crop_ui=''
    if profile_crop_ui="$(node "$PROJECT_ROOT/scripts/profile-crop-ui-selftest.cjs" 2>&1)"; then
      ok 'Profil: Mobiler Zuschnitt, Quadrat/Hochformat, Originalquelle und erneutes Zuschneiden geprueft.'
    else
      fail 'Profil: Zuschnitt-Oberflaechentest fehlgeschlagen.'
      printf '%s\n' "$profile_crop_ui" >&2
    fi
    if node "$PROJECT_ROOT/scripts/registration-ui-selftest.cjs" >/dev/null 2>&1; then
      ok 'Login: Registrieren und Als Gast chatten nur wenn offen, Pruefungen und Fehlerfaelle geprueft.'
    else
      fail 'Login: Registrierungs-Selbsttest fehlgeschlagen.'
    fi
    if node "$PROJECT_ROOT/scripts/browse-live-ui-selftest.cjs" >/dev/null 2>&1; then
      ok 'Live-Browser: Mara ueberlegt mit Sekunden, Cursor gleitet, Warteschlange, Fortsetzen nach Abbruch geprueft.'
    else
      fail 'Live-Browser-Oberflaechentest fehlgeschlagen.'
    fi
    # 0.6.67: local Playwright check for viewer -> editor -> FLUX reference handoff.
    if node -e "require('playwright')" >/dev/null 2>&1; then
      if node "$PROJECT_ROOT/scripts/image-editor-ui-selftest.cjs" >/dev/null 2>&1; then
        ok 'Bildeditor: mobile Ansicht, Touch-Auswahl, Undo/Redo und Bildreferenzen geprueft.'
      else
        fail 'Bildeditor: mobile UI-Selbsttest fehlgeschlagen.'
      fi
    else
      warn 'Bildeditor: Playwright fehlt. Browser-Selbsttest uebersprungen (keine Live-Abnahme).'
    fi
    if node "$PROJECT_ROOT/scripts/settings-navigation-foundation-selftest.cjs" >/dev/null 2>&1; then
      ok 'Einstellungen: 25 Kategorien, Expertenmodus, vorbereitete Unterseiten und Admin-Trennung geprueft.'
    else
      fail 'Einstellungen: Kategorie- oder Admin-Strukturtest fehlgeschlagen.'
    fi
    # 0.6.71: category navigation, expert mode and standalone Admin Core; needs Playwright for Node.
    if node -e "require('playwright')" >/dev/null 2>&1; then
      if node "$PROJECT_ROOT/scripts/settings-tabs-ui-selftest.cjs" >/dev/null 2>&1; then
        ok 'Einstellungen: Kategorien, Suche, Expertenmodus, mobiles Menue und eigenstaendiger Admin Core geprueft.'
      else
        fail 'Einstellungen: Tab-Oberflaechentest fehlgeschlagen.'
      fi
    else
      warn 'Einstellungen: Tab-Oberflaechentest uebersprungen (Playwright fuer Node nicht installiert).'
    fi
    # 0.6.80: Darstellung (segments, font, hex, Hell/Dunkel getrennt, motif export/import) and
    # smart punctuation in the chat input; needs Playwright for Node.
    if node -e "require('playwright')" >/dev/null 2>&1; then
      if node "$PROJECT_ROOT/scripts/appearance-ui-selftest.cjs" >/dev/null 2>&1; then
        ok 'Darstellung: Motiv-Export/-Import, Hex-Werte, Hell/Dunkel getrennt, Schriftart und Zeichensetzung im Browser geprueft.'
      else
        fail 'Darstellung: Oberflaechentest fehlgeschlagen.'
      fi
    else
      warn 'Darstellung: Oberflaechentest uebersprungen (Playwright fuer Node nicht installiert).'
    fi
    # 0.6.81: Benachrichtigungen page (Push for this device, E-Mail state, categories) and the
    # service worker; needs Playwright for Node.
    if node -e "require('playwright')" >/dev/null 2>&1; then
      if node "$PROJECT_ROOT/scripts/notifications-ui-selftest.cjs" >/dev/null 2>&1; then
        ok 'Benachrichtigungen: Push-Aktivierung, Geraete, E-Mail-Status, Kategorien und Service Worker im Browser geprueft.'
      else
        fail 'Benachrichtigungen: Oberflaechentest fehlgeschlagen.'
      fi
    else
      warn 'Benachrichtigungen: Oberflaechentest uebersprungen (Playwright fuer Node nicht installiert).'
    fi
    # 0.6.82: Admin Core > Betrieb > E-Mail-Versand (SMTP); needs Playwright for Node.
    if node -e "require('playwright')" >/dev/null 2>&1; then
      if node "$PROJECT_ROOT/scripts/admin-mail-ui-selftest.cjs" >/dev/null 2>&1; then
        ok 'E-Mail-Versand: SMTP-Karte (Speichern, Passwort behalten, Testmail, Serverkonfiguration) im Browser geprueft.'
      else
        fail 'E-Mail-Versand: Oberflaechentest fehlgeschlagen.'
      fi
    else
      warn 'E-Mail-Versand: Oberflaechentest uebersprungen (Playwright fuer Node nicht installiert).'
    fi
    # 0.6.84: Settings > Profil overview (figures, raster, made, tools, insights); needs Playwright for Node.
    if node -e "require('playwright')" >/dev/null 2>&1; then
      if node "$PROJECT_ROOT/scripts/profile-stats-ui-selftest.cjs" >/dev/null 2>&1; then
        ok 'Profil: Kennzahlen, Raster (taeglich, woechentlich, kumulativ), Erstelltes, Werkzeuge und Einblicke im Browser geprueft.'
      else
        fail 'Profil: Oberflaechentest fehlgeschlagen.'
      fi
    else
      warn 'Profil: Oberflaechentest uebersprungen (Playwright fuer Node nicht installiert).'
    fi
    # 0.6.85: Sicherheit und Anmeldung and the sign-in with second step and passkey; needs Playwright for Node.
    if node -e "require('playwright')" >/dev/null 2>&1; then
      if node "$PROJECT_ROOT/scripts/security-ui-selftest.cjs" >/dev/null 2>&1; then
        ok 'Sicherheit: Seite (Passkeys, Authenticator mit QR, E-Mail-Code, Codes, Sitzungen, Verlauf, Lockdown) und Anmeldung im Browser geprueft.'
      else
        fail 'Sicherheit: Oberflaechentest fehlgeschlagen.'
      fi
    else
      warn 'Sicherheit: Oberflaechentest uebersprungen (Playwright fuer Node nicht installiert).'
    fi
    # 0.6.87: chat menu (⋮) and Settings > Archivierte Chats; needs Playwright for Node.
    if node -e "require('playwright')" >/dev/null 2>&1; then
      if node "$PROJECT_ROOT/scripts/chat-menu-ui-selftest.cjs" >/dev/null 2>&1 && node "$PROJECT_ROOT/scripts/archived-chats-ui-selftest.cjs" >/dev/null 2>&1; then
        ok 'Chat-Menue und Archivierte Chats im Browser geprueft.'
      else
        fail 'Chat-Menue oder Archivierte Chats: Oberflaechentest fehlgeschlagen.'
      fi
    else
      warn 'Chat-Menue: Oberflaechentest uebersprungen (Playwright fuer Node nicht installiert).'
    fi
    # 0.7.4: four insight panels must exist in saved chats, not on the welcome page.
    if node -e "require('playwright')" >/dev/null 2>&1; then
      if node "$PROJECT_ROOT/scripts/insights-chat-visibility-ui-selftest.cjs" >/dev/null 2>&1; then
        ok 'Infokaesten: vier Schaltflaechen im Handy-Chat, Desktop-Seitenleiste und leere Startseite geprueft.'
      else
        fail 'Infokaesten: Chat-/Startseiten-Umschaltung oder mobile Schaltflaechen fehlerhaft.'
      fi
    else
      warn 'Infokaesten: Browser-Smoke-Test uebersprungen (Playwright nicht installiert); Offline-DOM-Vertrag bleibt Pflicht.'
    fi
    # 0.7.4: TCI identity and full-width assistant replies at phone and desktop widths.
    if node -e "require('playwright')" >/dev/null 2>&1; then
      if node "$PROJECT_ROOT/scripts/tci-chat-layout-ui-selftest.cjs" >/dev/null 2>&1; then
        ok 'TCI CORE: Avatar, Namenszeilen und volle Antwortbreite im Browser geprueft.'
      else
        fail 'TCI CORE: Responsiver Chat-Layouttest fehlgeschlagen.'
      fi
    else
      warn 'TCI CORE: Layout-Browsertest uebersprungen (Playwright nicht installiert).'
    fi
    # 0.7.14/0.7.15: Settings > Stimme, the microphone switch and the recordings; needs Playwright for Node.
    if node -e "require('playwright')" >/dev/null 2>&1; then
      if node "$PROJECT_ROOT/scripts/voice-settings-ui-selftest.cjs" >/dev/null 2>&1 && node "$PROJECT_ROOT/scripts/voice-recordings-ui-selftest.cjs" >/dev/null 2>&1; then
        ok 'Stimme, Mikrofon-Schalter und Aufnahmen im Browser geprueft.'
      else
        fail 'Stimme: Oberflaechentest fehlgeschlagen.'
      fi
    else
      warn 'Stimme: Oberflaechentest uebersprungen (Playwright fuer Node nicht installiert).'
    fi
    # 0.7.28: Settings > Speicher (overview, Dateien with project folders, Bilder, menu, deleting, full storage); needs Playwright for Node.
    if node -e "require('playwright')" >/dev/null 2>&1; then
      if node "$PROJECT_ROOT/scripts/storage-ui-selftest.cjs" >/dev/null 2>&1; then
        ok 'Speicher: Uebersicht, Dateien, Bilder und Loeschen im Browser geprueft.'
      else
        fail 'Speicher: Oberflaechentest fehlgeschlagen.'
      fi
    else
      warn 'Speicher: Oberflaechentest uebersprungen (Playwright fuer Node nicht installiert).'
    fi
    # 0.7.29: Settings > Tastatur (recording, clashes, reset) and the shortcuts in the chat; needs Playwright for Node.
    if node -e "require('playwright')" >/dev/null 2>&1; then
      if node "$PROJECT_ROOT/scripts/keyboard-shortcuts-ui-selftest.cjs" >/dev/null 2>&1; then
        ok 'Tastatur: Tastenkuerzel aufnehmen, pruefen, zuruecksetzen und im Chat ausfuehren im Browser geprueft.'
      else
        fail 'Tastatur: Oberflaechentest fehlgeschlagen.'
      fi
    else
      warn 'Tastatur: Oberflaechentest uebersprungen (Playwright fuer Node nicht installiert).'
    fi
    # 0.7.30: Settings > Nutzung (Uebersicht, Analysen, charts); needs Playwright for Node.
    if node -e "require('playwright')" >/dev/null 2>&1; then
      if node "$PROJECT_ROOT/scripts/usage-ui-selftest.cjs" >/dev/null 2>&1; then
        ok 'Nutzung: Uebersicht und Analysen im Browser geprueft.'
      else
        fail 'Nutzung: Oberflaechentest fehlgeschlagen.'
      fi
    else
      warn 'Nutzung: Oberflaechentest uebersprungen (Playwright fuer Node nicht installiert).'
    fi
    # 0.7.32: Settings > Datenkontrollen (confirmations, typed word, results); needs Playwright for Node.
    if node -e "require('playwright')" >/dev/null 2>&1; then
      if node "$PROJECT_ROOT/scripts/data-controls-ui-selftest.cjs" >/dev/null 2>&1; then
        ok 'Datenkontrollen: Archivieren, Loeschen und Export im Browser geprueft.'
      else
        fail 'Datenkontrollen: Oberflaechentest fehlgeschlagen.'
      fi
    else
      warn 'Datenkontrollen: Oberflaechentest uebersprungen (Playwright fuer Node nicht installiert).'
    fi
    # 0.7.33: the CoreAI theme over every settings page, every page in the main column, Allgemein without
    # duplicates, SoftGlass untouched; needs Playwright for Node.
    if node -e "require('playwright')" >/dev/null 2>&1; then
      if node "$PROJECT_ROOT/scripts/settings-theme-ui-selftest.cjs" >/dev/null 2>&1; then
        ok 'Einstellungen: CoreAI-Theme und Seitenaufbau im Browser geprueft.'
      else
        fail 'Einstellungen: Theme-Oberflaechentest fehlgeschlagen.'
      fi
    else
      warn 'Einstellungen: Theme-Oberflaechentest uebersprungen (Playwright fuer Node nicht installiert).'
    fi
    # 0.7.38: Deep Research in the chat (plan card, countdown, running, report view); needs Playwright for Node.
    if node -e "require('playwright')" >/dev/null 2>&1; then
      if node "$PROJECT_ROOT/scripts/deep-research-ui-selftest.cjs" >/dev/null 2>&1; then
        ok 'Deep Research: Plan, Fortschritt und Bericht im Browser geprueft.'
      else
        fail 'Deep Research: Oberflaechentest fehlgeschlagen.'
      fi
    else
      warn 'Deep Research: Oberflaechentest uebersprungen (Playwright fuer Node nicht installiert).'
    fi
    # 0.7.1: Settings > Pets and the pet in Mara's work line; needs Playwright for Node.
    if node -e "require('playwright')" >/dev/null 2>&1; then
      if node "$PROJECT_ROOT/scripts/pets-ui-selftest.cjs" >/dev/null 2>&1; then
        ok 'Pets im Browser geprueft.'
      else
        fail 'Pets: Oberflaechentest fehlgeschlagen.'
      fi
    else
      warn 'Pets: Oberflaechentest uebersprungen (Playwright fuer Node nicht installiert).'
    fi
    # 0.7.0: Settings > Personalisierung after the reference; needs Playwright for Node.
    if node -e "require('playwright')" >/dev/null 2>&1; then
      if node "$PROJECT_ROOT/scripts/personalization-ui-selftest.cjs" >/dev/null 2>&1; then
        ok 'Personalisierung im Browser geprueft.'
      else
        fail 'Personalisierung: Oberflaechentest fehlgeschlagen.'
      fi
    else
      warn 'Personalisierung: Oberflaechentest uebersprungen (Playwright fuer Node nicht installiert).'
    fi
    # 0.6.86: Konto (name, Benutzername dialog, e-mail, plan page, Erstellerprofil planned); needs Playwright for Node.
    if node -e "require('playwright')" >/dev/null 2>&1; then
      if node "$PROJECT_ROOT/scripts/account-ui-selftest.cjs" >/dev/null 2>&1; then
        ok 'Konto: Name, Benutzername, E-Mail, Plan und Erstellerprofil im Browser geprueft.'
      else
        fail 'Konto: Oberflaechentest fehlgeschlagen.'
      fi
    else
      warn 'Konto: Oberflaechentest uebersprungen (Playwright fuer Node nicht installiert).'
    fi
    # 0.5.83: update window (Inhaber) and "Bitte auf Neustart warten"; needs Playwright for Node.
    if node -e "require('playwright')" >/dev/null 2>&1; then
      if node "$PROJECT_ROOT/scripts/update-ui-selftest.cjs" >/dev/null 2>&1; then
        ok 'Update-Fenster: goldenes Fenster fuer den Inhaber, Spaeter, Fortschrittsbalken fuer alle, Neuladen nach dem Neustart geprueft.'
      else
        fail 'Update-Fenster: Oberflaechentest fehlgeschlagen.'
      fi
    else
      warn 'Update-Fenster: Oberflaechentest uebersprungen (Playwright fuer Node nicht installiert).'
    fi
    # 0.6.1: speaker button and auto-read switch; needs Playwright for Node.
    if node -e "require('playwright')" >/dev/null 2>&1; then
      if node "$PROJECT_ROOT/scripts/voice-read-ui-selftest.cjs" >/dev/null 2>&1; then
        ok 'Vorlesen: Knopf, Satz fuer Satz mit Vorladen, Stopp, automatisch vorlesen, Gaeste ohne Knopf geprueft.'
      else
        fail 'Vorlesen: Oberflaechentest fehlgeschlagen.'
      fi
    else
      warn 'Vorlesen: Oberflaechentest uebersprungen (Playwright fuer Node nicht installiert).'
    fi
    # 0.6.14: microphone button in the input field; needs Playwright for Node.
    if node -e "require('playwright')" >/dev/null 2>&1; then
      if node "$PROJECT_ROOT/scripts/voice-input-ui-selftest.cjs" >/dev/null 2>&1; then
        ok 'Sprachnachrichten: Mikrofon-Knopf, sofort senden, Warten auf Mara, Stille, Mitschrift-Zeile, Gaeste ohne Knopf geprueft.'
      else
        fail 'Spracheingabe: Oberflaechentest fehlgeschlagen.'
      fi
    else
      warn 'Spracheingabe: Oberflaechentest uebersprungen (Playwright fuer Node nicht installiert).'
    fi
    # 0.6.31: the live conversation (P20.4); needs Playwright for Node.
    if node -e "require('playwright')" >/dev/null 2>&1; then
      if node "$PROJECT_ROOT/scripts/voice-live-ui-selftest.cjs" >/dev/null 2>&1; then
        ok 'Live-Gespraech: Knopf neben dem Mikrofon, Vollbild, Runden als Stimmsequenz, Vorlesen, Stummschalten, Unterbrechen, Beenden geprueft.'
      else
        fail 'Live-Gespraech: Oberflaechentest fehlgeschlagen.'
      fi
    else
      warn 'Live-Gespraech: Oberflaechentest uebersprungen (Playwright fuer Node nicht installiert).'
    fi
    # 0.6.19: chat list in the drawer, buttons whole and large enough; needs Playwright for Node.
    if node -e "require('playwright')" >/dev/null 2>&1; then
      if node "$PROJECT_ROOT/scripts/session-list-ui-selftest.cjs" >/dev/null 2>&1; then
        ok 'Chatliste: Knoepfe ganz sichtbar und gross genug (Maus und Touch, Tag und Nacht) geprueft.'
      else
        fail 'Chatliste: Oberflaechentest fehlgeschlagen.'
      fi
    else
      warn 'Chatliste: Oberflaechentest uebersprungen (Playwright fuer Node nicht installiert).'
    fi
    # 0.6.22: names and terms for voice messages in the KI-Profil; needs Playwright for Node.
    if node -e "require('playwright')" >/dev/null 2>&1; then
      if node "$PROJECT_ROOT/scripts/voice-terms-ui-selftest.cjs" >/dev/null 2>&1; then
        ok 'Begriffe fuer Sprachnachrichten: Liste im KI-Profil, Speichern, Bereinigung, Gaeste ohne Feld, Tag und Nacht, Handy geprueft.'
      else
        fail 'Begriffe fuer Sprachnachrichten: Oberflaechentest fehlgeschlagen.'
      fi
    else
      warn 'Begriffe fuer Sprachnachrichten: Oberflaechentest uebersprungen (Playwright fuer Node nicht installiert).'
    fi
    # 0.5.94: Stimmproben card (Inhaber only); needs Playwright for Node.
    if node -e "require('playwright')" >/dev/null 2>&1; then
      if node "$PROJECT_ROOT/scripts/voice-samples-ui-selftest.cjs" >/dev/null 2>&1; then
        ok 'Stimmproben: Stimmen mit Lizenz, Abspielen je Satz, Testlaeufe, nur Inhaber und Handy-Breite geprueft.'
      else
        fail 'Stimmproben: Oberflaechentest fehlgeschlagen.'
      fi
    else
      warn 'Stimmproben: Oberflaechentest uebersprungen (Playwright fuer Node nicht installiert).'
    fi
    if node "$PROJECT_ROOT/scripts/webui-resilience-selftest.cjs" >/dev/null 2>&1; then
      ok 'WebUI: abbrechbares Laden, Reconnect ohne Doppelauftrag, Backoff und lange Chats geprueft.'
    else
      fail 'WebUI: Robustheits-Selbsttest fehlgeschlagen.'
    fi
    if node "$PROJECT_ROOT/scripts/chat-follow-selftest.cjs" >/dev/null 2>&1; then
      ok 'Chat-Scroll: manuelles Lesen, Arbeits-Punkte und Sprung zur aktuellen Antwort geprueft.'
    else
      fail 'Chat-Scroll: Leserposition oder Pfeil-Button Regression fehlgeschlagen.'
    fi
    if node "$PROJECT_ROOT/scripts/long-chat-selftest.cjs" >/dev/null 2>&1; then
      ok 'Lange Texte: Markdown-Dateianhang und Originalbytes geprueft.'
    else
      fail 'Lange Texte: automatischer Anhang ist fehlerhaft.'
    fi
  else
    warn 'Node nicht installiert: optionaler Antwortdauer-JavaScript-Test uebersprungen.'
  fi

  # Test the actual PHP runtime directly. Under pipefail, a "php -m |
  # grep -q" probe can mistake an early-closing pipe for a missing module.
  for extension_name in curl dom gd mbstring pdo_mysql zip; do
    if compose exec -T php php -r 'exit(extension_loaded($argv[1]) ? 0 : 1);' "$extension_name" 2>/dev/null; then
      ok "PHP-Erweiterung aktiv: $extension_name"
    else
      fail "PHP-Erweiterung fehlt: $extension_name"
      if [[ "$extension_name" == 'pdo_mysql' ]]; then
        warn 'PDO-Treiber fehlt im LAUFENDEN PHP-Container. Installer erneut starten, damit das Image neu gebaut und PHP neu erstellt wird.'
      fi
    fi
  done

  if compose exec -T php sh -ec '
      find api scripts tools -type f -name "*.php" -exec sh -c '\''
        for file do
          if ! php -l "$file" >/dev/null 2>&1; then
            printf "[PHP-SYNTAXFEHLER] %s\\n" "$file" >&2
            php -l "$file" >&2 || true
            exit 1
          fi
        done
      '\'' sh {} +
    '; then
    ok 'Alle paketierten PHP-Dateien bestehen den Syntaxcheck.'
  else
    fail 'Mindestens eine paketierte PHP-Datei hat einen Syntaxfehler.'
  fi

  thinking_selftest=''
  if thinking_selftest="$(compose exec -T -u 33:33 php php scripts/thinking-sanitize-selftest.php 2>&1)"; then
    thinking_selftest="${thinking_selftest//$'\r'/}"
    ok "$thinking_selftest"
  else
    thinking_selftest="${thinking_selftest//$'\r'/}"
    fail "$thinking_selftest"
  fi

  reply_selftest=''
  if reply_selftest="$(compose exec -T -u 33:33 php php scripts/reply-pipeline-selftest.php 2>&1)"; then
    reply_selftest="${reply_selftest//$'\r'/}"
    ok "$reply_selftest"
  else
    reply_selftest="${reply_selftest//$'\r'/}"
    fail "$reply_selftest"
  fi

  session_selftest=''
  if session_selftest="$(compose exec -T -u 33:33 php php scripts/session-selftest.php 2>&1)"; then
    session_selftest="${session_selftest//$'\r'/}"
    ok "$session_selftest"
  else
    session_selftest="${session_selftest//$'\r'/}"
    fail "$session_selftest"
  fi


  personal_selftest=''
  if personal_selftest="$(compose exec -T -u 33:33 php php scripts/personalization-selftest.php 2>&1)"; then
    ok "$personal_selftest"
  else
    fail "$personal_selftest"
  fi

  if skills_selftest="$(compose exec -T -u 33:33 php php scripts/skills-selftest.php 2>&1)"; then
    ok "$skills_selftest"
  else
    fail "$skills_selftest"
  fi

  profile_knowledge_selftest=''
  if profile_knowledge_selftest="$(compose exec -T -u 33:33 php php scripts/profile-knowledge-selftest.php 2>&1)"; then
    profile_knowledge_selftest="${profile_knowledge_selftest//$'\r'/}"
    ok "$profile_knowledge_selftest"
  else
    profile_knowledge_selftest="${profile_knowledge_selftest//$'\r'/}"
    fail "$profile_knowledge_selftest"
  fi

  attachment_pipeline_selftest=''
  if attachment_pipeline_selftest="$(compose exec -T -u 33:33 php php scripts/attachment-pipeline-selftest.php 2>&1)"; then
    attachment_pipeline_selftest="${attachment_pipeline_selftest//$'\r'/}"
    ok "$attachment_pipeline_selftest"
  else
    attachment_pipeline_selftest="${attachment_pipeline_selftest//$'\r'/}"
    fail "$attachment_pipeline_selftest"
  fi

  python_worker_selftest=''
  if python_worker_selftest="$(compose exec -T -u 33:33 php php scripts/python-worker-selftest.php 2>&1)"; then
    python_worker_selftest="${python_worker_selftest//$'\r'/}"
    ok "$python_worker_selftest"
  else
    python_worker_selftest="${python_worker_selftest//$'\r'/}"
    fail "$python_worker_selftest"
  fi

  account_security_selftest=''
  if account_security_selftest="$(compose exec -T -u 33:33 php php scripts/account-security-selftest.php 2>&1)"; then
    account_security_selftest="${account_security_selftest//$'\r'/}"
    ok "$account_security_selftest"
  else
    account_security_selftest="${account_security_selftest//$'\r'/}"
    fail "$account_security_selftest"
  fi

  logo_selftest=''
  if logo_selftest="$(compose exec -T -u 33:33 php php scripts/logo-alpha-selftest.php 2>&1)"; then
    logo_selftest="${logo_selftest//$'\r'/}"
    ok "$logo_selftest"
  else
    logo_selftest="${logo_selftest//$'\r'/}"
    fail "$logo_selftest"
  fi

  frontend_selftest=''
  if frontend_selftest="$(compose exec -T -u 33:33 php php scripts/frontend-regression-selftest.php 2>&1)"; then
    frontend_selftest="${frontend_selftest//$'\r'/}"
    ok "$frontend_selftest"
  else
    frontend_selftest="${frontend_selftest//$'\r'/}"
    fail "$frontend_selftest"
  fi

  md_preview_test=''
  if md_preview_test="$(compose exec -T -u 33:33 php php scripts/markdown-preview-selftest.php 2>&1)"; then
    ok "$md_preview_test"
  else
    fail "Markdown-Vorschau: ${md_preview_test:-Unbekannter Fehler}"
  fi

  message_actions_selftest=''
  if message_actions_selftest="$(compose exec -T -u 33:33 php php scripts/message-actions-selftest.php 2>&1)"; then
    message_actions_selftest="${message_actions_selftest//$'\r'/}"
    ok "$message_actions_selftest"
  else
    message_actions_selftest="${message_actions_selftest//$'\r'/}"
    fail "$message_actions_selftest"
  fi

  # Runtime hosts do not require Node.js to operate CoreAI. Keep a real
  # PHP feedback contract check on every install; run the fuller JavaScript
  # contract as well whenever the optional Node toolchain is available.
  feedback_ui_selftest=''
  if feedback_ui_selftest="$(compose exec -T -u 33:33 php php scripts/feedback-ui-selftest.php 2>&1)"; then
    ok 'Feedback: Daumenfarben, Speicherung, Mara-Reaktion ohne Thinking (PHP-Vertrag) geprueft.'
  else
    fail "Feedback: UI-/Backend-Vertrag (PHP) fehlerhaft: ${feedback_ui_selftest:-Unbekannter Fehler}"
  fi
  if command -v node >/dev/null 2>&1; then
    feedback_node_selftest=''
    if feedback_node_selftest="$(node "$PROJECT_ROOT/scripts/feedback-ui-selftest.cjs" 2>&1)"; then
      ok 'Feedback: erweiterter Node.js-UI-/Backend-Vertrag geprueft.'
    else
      fail "Feedback: Node.js-UI-/Backend-Vertrag fehlerhaft: ${feedback_node_selftest:-Unbekannter Fehler}"
    fi
  else
    warn 'Node.js optional und nicht installiert: Feedback-PHP-Vertrag geprueft, Node.js-Zusatztest uebersprungen.'
  fi

  if is_enabled "${COREUI_INSTALL_BROWSE:-0}"; then
    frame_table_ready=0
    for _frame_try in {1..15}; do
      if compose exec -T php php -r \
          'require "/var/www/coreui/api/db.php"; stu_pdo()->query("SELECT id FROM stu_ember_browse_frames LIMIT 1");' \
          >/dev/null 2>&1; then
        frame_table_ready=1
        break
      fi
      sleep 1
    done
    if (( frame_table_ready == 1 )); then
      ok 'Private Live-Browser-Frame-Tabelle ist bereit.'
    else
      fail 'Private Live-Browser-Frame-Tabelle fehlt. Browse-Worker neu starten.'
    fi
  fi

  # 0.6.1: Mara's voice. Optional: missing or still loading is a hint, never a failure.
  if is_enabled "${COREUI_INSTALL_VOICE:-1}"; then
    if service_running voice; then
      voice_health="$(curl -fsS --max-time 5 "http://127.0.0.1:${COREUI_VOICE_PORT:-18790}/health" 2>/dev/null || true)"
      if [[ "$voice_health" == *'"ready": true'* ]]; then
        ok 'Sprachdienst bereit: Mara kann mit Eve vorlesen.'
      elif [[ "$voice_health" == *'"loading": true'* ]]; then
        warn 'Sprachdienst laedt Eve noch (beim ersten Start etwa 0,7 GB); Vorlesen folgt von selbst.'
      else
        warn 'Sprachdienst laeuft, ist aber nicht bereit. Protokoll: scripts/stack.sh logs voice'
      fi
    else
      warn 'Sprachdienst laeuft nicht; CoreAI arbeitet ohne Vorlesen. Start: scripts/stack.sh up -d voice'
    fi
  fi

  LOCK_NAMESPACE="$(compose exec -T php php -r \
    'require "/var/www/coreui/api/config.php"; echo STU_EMBER_LOCK_NAMESPACE;' 2>/dev/null || true)"
  LOCK_NAMESPACE="${LOCK_NAMESPACE//$'\r'/}"
  if [[ -n "$LOCK_NAMESPACE" && "$LOCK_NAMESPACE" != 'ember' && "$LOCK_NAMESPACE" != 'stu' ]]; then
    ok "Eigener MariaDB-Lock-Namespace aktiv: $LOCK_NAMESPACE"
  else
    fail 'Kein sicherer CoreAI: TCI Interface-Lock-Namespace aktiv.'
  fi

  for writable_dir in \
    /var/www/coreui/logs \
    /var/www/coreui/var/cache \
    /var/www/coreui/var/console_media \
    /var/www/coreui/var/profile_media \
    /var/www/coreui/var/knowledge_uploads \
    /var/www/coreui/var/ember_frames \
    /var/www/coreui/var/pdf_pages \
    /var/www/coreui/var/php_sessions \
    /var/www/coreui/var/update-request \
    /var/www/coreui/uploads/ember_browse \
    /var/www/coreui/assets/chat_media \
    /var/www/coreui/assets/profile_photos/pending \
    /var/www/coreui/assets/profile_photos/approved; do
    if compose exec -T -u 33:33 php test -w "$writable_dir" >/dev/null 2>&1; then
      ok "Container-Schreibpfad bereit: ${writable_dir#/var/www/coreui/}"
    else
      fail "Container-Schreibpfad nicht bereit: ${writable_dir#/var/www/coreui/}"
    fi
  done

  # 0.5.83: the update window needs the host units (installed by scripts/install.sh).
  if command -v systemctl >/dev/null 2>&1 && [[ -d /run/systemd/system ]]; then
    if systemctl is-active --quiet coreai-update.path && systemctl is-active --quiet coreai-update-check.timer; then
      ok 'Update-Dienst aktiv: Pruefung auf neue Merges alle 10 Minuten, Start aus dem Update-Fenster.'
    else
      fail 'Update-Dienst nicht aktiv (coreai-update.path oder coreai-update-check.timer). scripts/install.sh erneut ausfuehren.'
    fi
  else
    warn 'Kein systemd: das Update-Fenster bleibt ohne Host-Dienst.'
  fi

  video_selftest=''
  if video_selftest="$(compose exec -T -u 33:33 php php scripts/video-selftest.php 2>&1)"; then
    video_selftest="${video_selftest//$'\r'/}"
    ok "$video_selftest"
  else
    video_selftest="${video_selftest//$'\r'/}"
    fail "$video_selftest"
  fi

  pdf_selftest=''
  if pdf_selftest="$(compose exec -T -u 33:33 php php scripts/pdf-selftest.php 2>&1)"; then
    pdf_selftest="${pdf_selftest//$'\r'/}"
    ok "$pdf_selftest"
  else
    pdf_selftest="${pdf_selftest//$'\r'/}"
    fail "$pdf_selftest"
  fi
fi

if curl -fsS --max-time 10 "http://127.0.0.1:${COREUI_HTTP_PORT}/api/health.php" >/dev/null 2>&1; then
  ok "CoreAI: TCI Interface-Healthcheck antwortet auf Loopback-Port ${COREUI_HTTP_PORT}."
else
  fail "CoreAI: TCI Interface-Healthcheck antwortet nicht auf Port ${COREUI_HTTP_PORT}."
fi

# Drain each HTTP body completely: grep -q closes early and makes curl fail under pipefail.
if curl -fsS --max-time 10 "http://127.0.0.1:${COREUI_HTTP_PORT}/settings.html" \
    | grep -F 'EINSTELLUNGEN' >/dev/null; then
  ok 'CoreAI: TCI Interface-Einstellungen werden vom isolierten Webserver ausgeliefert.'
else
  fail 'CoreAI: TCI Interface-Einstellungen sind nicht erreichbar. Fuehre scripts/stack.sh refresh-runtime und danach scripts/stack.sh restart web aus.'
fi

# 0.6.71: Admin Core is a separate page, authorized per account and server-side API.
if curl -fsS --max-time 10 "http://127.0.0.1:${COREUI_HTTP_PORT}/settings.html" | grep -F 'id="adminSettingsLink"' >/dev/null \
    && curl -fsS --max-time 10 "http://127.0.0.1:${COREUI_HTTP_PORT}/admin/index.html" | grep -F 'id="adminAccessGate"' >/dev/null; then
  ok 'Einstellungen mit Rollen-Link und eigenstaendige, zugangsgeschuetzte Admin-Core-Seite ausgeliefert.'
else
  fail 'Admin-Core-Seite oder getrennte Einstellungen sind nicht erreichbar.'
fi

SESSION_ROUTE_STATUS="$(curl -sS --max-time 10 -o /dev/null -w '%{http_code}' \
  "http://127.0.0.1:${COREUI_HTTP_PORT}/api/console_messages.php" 2>/dev/null || true)"
if [[ "$SESSION_ROUTE_STATUS" == '401' || "$SESSION_ROUTE_STATUS" == '400' ]]; then
  ok 'Der authentifizierte Sitzungs-History-Endpunkt wird vom CoreAI: TCI Interface-Webserver erreicht.'
else
  fail "Sitzungs-History-Endpunkt nicht korrekt geroutet (HTTP ${SESSION_ROUTE_STATUS:-000})."
fi

for private_route in profile knowledge profile_media account_security account_export models; do
  route_status="$(curl -sS --max-time 10 -o /dev/null -w '%{http_code}' \
    "http://127.0.0.1:${COREUI_HTTP_PORT}/api/${private_route}.php" 2>/dev/null || true)"
  if [[ "$route_status" == '401' ]]; then
    ok "Privater CoreAI: TCI Interface-Endpunkt korrekt geroutet: ${private_route}.php"
  else
    fail "CoreAI: TCI Interface-Endpunkt ${private_route}.php nicht korrekt geroutet (HTTP ${route_status:-000})."
  fi
done

if command -v ollama >/dev/null 2>&1 \
    && ollama show "${COREUI_MODEL_NAME}" >/dev/null 2>&1; then
  ok "Getrenntes Ollama-Modell vorhanden: ${COREUI_MODEL_NAME}"
  model_audit=''
  if model_audit="$(compose exec -T php php scripts/model-live-audit.php "${COREUI_MODEL_NAME}" 2>&1)"; then
    ok "$model_audit"
  else
    fail "${model_audit:-Ollama-Modelfile nicht verifizierbar.}"
  fi
else
  fail "CoreAI: TCI Interface-Ollama-Modell fehlt: ${COREUI_MODEL_NAME}"
fi

if is_enabled "${COREUI_INSTALL_SEARXNG:-0}"; then
  if curl -fsS --max-time 10 \
      "http://127.0.0.1:${COREUI_SEARXNG_PORT}/search?q=ember&format=json" >/dev/null 2>&1; then
    ok "Eigene SearXNG-JSON-Suche antwortet auf Port ${COREUI_SEARXNG_PORT}."
  else
    fail "Eigene SearXNG-JSON-Suche antwortet nicht auf Port ${COREUI_SEARXNG_PORT}."
  fi
fi

if [[ "${COREUI_BIND_ADDRESS:-127.0.0.1}" == '127.0.0.1' ]]; then
  ok 'Webzugriff ist standardmaessig auf Loopback begrenzt.'
else
  warn 'CoreAI: TCI Interface lauscht bewusst auf 0.0.0.0. Firewall und TLS muessen separat geprueft werden.'
fi

if [[ -L /etc/nginx/sites-enabled/default || -e /etc/nginx/sites-enabled/default ]]; then
  ok 'Eine bestehende Nginx-Default-Site wurde nicht entfernt.'
else
  warn 'Keine Nginx-Default-Site gefunden. CoreAI: TCI Interface veraendert diesen Zustand nicht.'
fi

if (( FAILURES > 0 )); then
  printf '\nPreflight fehlgeschlagen: %d Problem(e).\n' "$FAILURES" >&2
  exit 1
fi

printf '\nPreflight erfolgreich. CoreAI: TCI Interface laeuft im isolierten Parallelmodus.\n'

