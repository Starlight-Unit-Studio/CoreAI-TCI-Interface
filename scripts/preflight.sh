#!/usr/bin/env bash
# CoreAI: TCI Interface 0.8.35: the image folder belongs to PHP again; the image self-test runs as www-data without reading root-only files.
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
  printf '[ERROR] %s\n' "$*" >&2
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
    ok "$label is running in its dedicated Compose project."
  else
    fail "$label is not running."
  fi
}

printf 'CoreAI: TCI Interface Compose-Preflight, Version %s\n\n' "$VERSION"
# PREFLIGHT_SYNC_GATE: verify both shipping modes and registered regressions
# before any host checks; a failed contract is a blocking release defect.
preflight_sync_report=''
if preflight_sync_report="$(python3 "$PROJECT_ROOT/scripts/preflight-sync-selftest.py" 2>&1)"; then
  ok 'Preflight synchronization rules verified.'
else
  fail "Preflight sync contract: ${preflight_sync_report:-Unknown error}"
fi

# TERMINAL_ENGLISH_GATE: catch mixed-language installer/preflight output.
terminal_language_report=''
if terminal_language_report="$(python3 "$PROJECT_ROOT/scripts/terminal-language-selftest.py" 2>&1)"; then
  ok 'English terminal diagnostics verified.'
else
  fail "English terminal diagnostics check: ${terminal_language_report:-Unknown error}"
fi

if [[ ! -f "$ENV_FILE" ]]; then
  fail 'var/compose.env is missing. Run scripts/install.sh.'
fi
if [[ ! -f "$CONFIG_FILE" ]]; then
  fail 'api/config.local.php is missing. Run scripts/install.sh.'
fi
if [[ ! -f "$COMPOSE_FILE" ]]; then
  fail 'compose.yaml is missing.'
fi

UI_FILES=(
  scripts/ensure-generator-core.py
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
  database/migrations/044_project_files.sql
  api/project_files.php
  api/project_files_store.php
  js/coreui-project-files.js
  css/coreui-project-files.css
  scripts/project-files-selftest.php
  scripts/project-files-ui-selftest.cjs
  scripts/project-ui-selftest.cjs
  database/migrations/055_project_descriptions.sql
  scripts/api-allowlist-selftest.cjs
  database/migrations/045_project_files_mara.sql
  api/project_file_tools.php
  scripts/project-file-tools-selftest.php
  scripts/edit-tolerance-selftest.php
  scripts/file-change-pause-selftest.php
  scripts/stabilize-security-selftest.php
  scripts/stabilize-history-selftest.php
  scripts/stabilize-backend-selftest.php
  scripts/stabilize-loop-selftest.php
  scripts/stabilize-loop-journal-selftest.php
  database/migrations/056_chat_workspaces.sql
  js/coreui-chat-dev.js
  css/coreui-chat-dev.css
  scripts/chat-workspace-selftest.php
  database/migrations/046_chat_branches.sql
  api/console_branch_store.php
  scripts/chat-branch-selftest.php
  scripts/chat-branch-ui-selftest.cjs
  api/project_preview_store.php
  scripts/project-preview-selftest.php
  scripts/project-preview-ui-selftest.cjs
  database/migrations/047_project_runs.sql
  api/project_runs_store.php
  tools/ember_project_runs.py
  scripts/project-runs-selftest.php
  scripts/project-runs-worker-selftest.py
  scripts/project-runs-ui-selftest.cjs
  database/migrations/048_project_procs.sql
  api/project_procs_store.php
  tools/ember_project_procs.py
  scripts/project-procs-selftest.php
  scripts/project-procs-worker-selftest.py
  scripts/project-procs-ui-selftest.cjs
  scripts/project-env-selftest.php
  api/model_provider.php
  scripts/external-provider-selftest.php
  scripts/external-provider-ui-selftest.cjs
  scripts/sandbox-image-selftest.py
  database/migrations/049_project_versions.sql
  api/project_vcs_store.php
  scripts/project-vcs-selftest.php
  scripts/project-vcs-ui-selftest.cjs
  database/migrations/050_project_merges.sql
  scripts/project-merge-selftest.php
  scripts/project-merge-ui-selftest.cjs
  database/migrations/051_artifact_lifecycle.sql
  api/artifact_lifecycle_store.php
  scripts/artifact-lifecycle-selftest.php
  scripts/artifact-lifecycle-ui-selftest.cjs
  database/migrations/052_skill_packages.sql
  api/skill_packages_store.php
  api/skill_packages.php
  js/coreui-skills-settings.js
  css/coreui-skills-settings.css
  scripts/skill-packages-selftest.php
  scripts/skill-packages-ui-selftest.cjs
  database/migrations/053_skill_sharing.sql
  js/coreui-chat-skills.js
  css/coreui-chat-skills.css
  scripts/skill-sharing-selftest.php
  scripts/skill-sharing-ui-selftest.cjs
  database/migrations/054_skill_revisions.sql
  api/skill_revisions_store.php
  scripts/skill-revisions-selftest.php
  scripts/skill-revisions-ui-selftest.cjs
  css/coreui-chat-tci-layout.css
  scripts/tci-chat-message-selftest.cjs
  scripts/work-events-ui-selftest.js
  scripts/retry-hint-ui-selftest.cjs
  scripts/stabilize-frontend-selftest.cjs
  scripts/text-selection-ui-selftest.cjs
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
  database/migrations/057_task_journal.sql
  database/migrations/058_project_file_history.sql
  api/console_task_journal.php
  scripts/task-journal-selftest.php
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
  scripts/image-store-diagnostic-selftest.php
  api/image_ab_diagnostic.php
  js/coreui-image-ab.js
  scripts/image-ab-diagnostic-selftest.php
  scripts/image-ab-ui-selftest.cjs
  scripts/image-reference-consistency-selftest.php
  scripts/image-chat-ui-selftest.cjs
  scripts/image-editor-ui-selftest.cjs
  js/coreui-image-viewer.js
  js/coreui-image-editor.js
  css/coreui-image-viewer.css
  js/coreui-image-progress.js
  scripts/image-progress-ui-selftest.cjs
  scripts/progress-browser-selftest.cjs
  api/console_composer_intent.php
  api/composer_tools.php
  js/coreui-composer-menu.js
  scripts/composer-intent-selftest.php
  scripts/browser-routing-selftest.php
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
  scripts/huggingface-library-selftest.php
  scripts/installed-cores-selftest.php
  api/installed_cores.php
  api/ollama_library.php
  api/huggingface_library.php
  js/coreui-model-library.js
  scripts/registration-ui-selftest.cjs
  scripts/guest-access-selftest.php
  api/guest_access.php
  scripts/compaction-selftest.php
  api/console_compaction.php
  scripts/agent-loop-selftest.php
  scripts/task-journal-selftest.php
  api/console_agent_loop.php
  scripts/browse-worker-selftest.py
  scripts/browse-live-ui-selftest.cjs
  scripts/web-search-selftest.php
  scripts/progressive-web-search-selftest.php
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
  scripts/preflight-sync-selftest.py
  scripts/terminal-language-selftest.py
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
    fail "CoreAI: TCI Interface UI file is missing or empty: $ui_file"
  fi
done

if python3 "$PROJECT_ROOT/scripts/pdo-mysql-installer-selftest.py" >/dev/null 2>&1; then
  ok 'PHP PDO MariaDB: image build, installer recreation and preflight contract verified.'
else
  fail 'PHP PDO MariaDB installer self-test failed.'
fi

# 0.5.58: check the installer regression using a temporary, Docker-free fixture.
if bash "$PROJECT_ROOT/tests/migration-permissions-selftest.sh" >/dev/null 2>&1; then
  ok 'Migration: executable permissions and Bash invocation before database import verified.'
else
  fail 'Migration: permissions and invocation self-test failed.'
fi

# 0.6.94: syntax is a release blocker. Report the concrete parse error;
# do not hide host-updater failures in /dev/null.
host_updater_syntax=''
if host_updater_syntax="$(bash -n "$PROJECT_ROOT/scripts/coreai-updater.sh" 2>&1)"; then
  if [[ "${EUID:-$(id -u)}" -eq 0 ]]; then
    host_updater_test=''
    if host_updater_test="$(bash "$PROJECT_ROOT/tests/update-runner-selftest.sh" 2>&1)"; then
      ok 'Update service: checking, backup-first, official setup, installation progress, service restart and error cases verified.'
    else
      fail "Update service: host updater self-test failed. Details: $(printf '%s\n' "$host_updater_test" | tail -n 12)"
    fi
  fi
else
  fail "Update service: Bash syntax error in scripts/coreai-updater.sh: $host_updater_syntax"
fi

uninstall_runner_test=''
if uninstall_runner_test="$(bash "$PROJECT_ROOT/tests/uninstall-runner-selftest.sh" 2>&1)"; then
  ok 'Uninstallation: preview, request validation, isolated Docker stop and archive backup verified.'
else
  fail "Uninstallation: host runner self-test failed. Details: $uninstall_runner_test"
fi

# 0.6.93: automatic updates require opt-in, survive page reloads and respect Later.
if python3 "$PROJECT_ROOT/tests/update-auto-selftest.py" >/dev/null 2>&1; then
  ok 'Auto-update: opt-in, one-hour pause, advance notice, once per commit and preflight failure detection verified.'
else
  fail 'Auto-update / preflight retry self-test failed.'
fi

if COREAI_BROWSE_LIVE=0 python3 "$PROJECT_ROOT/scripts/browse-worker-selftest.py" >/dev/null 2>&1; then
  ok 'Browser rules: protected fields, purchases, logins, messages, local network, user-selected Core and heartbeat verified.'
else
  fail 'Browser rules self-test failed.'
fi

# 0.7.77 (P10 B): project runs in the worker; with Docker and the sandbox image real runs in the sandbox.
project_runs_worker_test=''
if project_runs_worker_test="$(python3 "$PROJECT_ROOT/scripts/project-runs-worker-selftest.py" 2>&1)"; then
  ok 'Project execution worker self-test passed.'
else
  fail "Project runs worker-self-test: ${project_runs_worker_test:-Unknown error}"
fi

# 0.7.78 (P10 C): processes in the worker; with Docker and the sandbox image real processes and a server.
project_procs_worker_test=''
if project_procs_worker_test="$(python3 "$PROJECT_ROOT/scripts/project-procs-worker-selftest.py" 2>&1)"; then
  ok 'Project process worker self-test passed.'
else
  fail "Project processes worker-self-test: ${project_procs_worker_test:-Unknown error}"
fi

# 0.7.79: the Dockerfile, one tag everywhere and, with Docker, Node.js tests behind the sandbox walls.
sandbox_image_test=''
if sandbox_image_test="$(python3 "$PROJECT_ROOT/scripts/sandbox-image-selftest.py" 2>&1)"; then
  ok 'Project sandbox image self-test passed.'
else
  fail "Sandbox-Image-self-test: ${sandbox_image_test:-Unknown error}"
fi

# 0.7.79: the sandbox image of Python jobs, runs and processes has Python and Node.js with npm.
if command -v docker >/dev/null 2>&1 && docker image inspect ember-py:2 >/dev/null 2>&1; then
  sandbox_tools=''
  if sandbox_tools="$(docker run --rm --network none --read-only --user 65534:65534 -e HOME=/tmp --tmpfs /tmp:rw,noexec,nosuid,size=16m \
      ember-py:2 sh -c 'python --version && node --version && npm --version' 2>&1)"; then
    ok "Sandbox-Image ember-py:2: $(printf '%s' "$sandbox_tools" | sed -n 1p), Node.js $(printf '%s' "$sandbox_tools" | sed -n 2p), npm $(printf '%s' "$sandbox_tools" | sed -n 3p)."
  else
    fail "Sandbox image ember-py:2: Python or Node.js failed to start: ${sandbox_tools:-Unknown error}"
  fi
else
  warn 'Sandbox image ember-py:2 is not built yet; the Python service will build it on its next start.'
fi

# 0.7.38: Deep Research in the browse worker (search, read, notes, report with only real sources, Stopp).
if python3 "$PROJECT_ROOT/scripts/deep-research-worker-selftest.py" >/dev/null 2>&1; then
  ok 'Deep Research: search, pages, notes, cited report, cancellation and protection against local addresses verified.'
else
  fail 'Deep Research worker self-test failed.'
fi

# 0.7.42: hybrid Lead/Worker/Citation agent, checkpoints and secure document tools.
hybrid_research_test=''
if hybrid_research_test="$(python3 "$PROJECT_ROOT/scripts/deep-research-hybrid-selftest.py" 2>&1)"; then
  ok 'Hybrid Deep Research: orchestrator/worker, parallel I/O, storage and citation agent verified.'
else
  fail "Hybrid-Deep-Research-self-test failed: ${hybrid_research_test:-Unknown error}"
fi

# 0.7.50: broken search backend must never produce a false completed report.
search_regression=''
if search_regression="$(python3 "$PROJECT_ROOT/scripts/deep-research-zero-source-selftest.py" 2>&1)"; then
  ok 'Deep Research: search outage, zero-source handling and fallback search verified.'
else
  fail "Deep Research search-failure-self-test failed: ${search_regression:-Unknown error}"
fi

if python3 "$PROJECT_ROOT/scripts/workspace-output-selftest.py" >/dev/null 2>&1; then
  ok 'Python worker: recovered HTML/ZIP files and safe output paths verified.'
else
  fail 'Python output file self-test failed.'
fi

# 0.6.1: read aloud: what is read, parts, the voice service with its probe engine, wiring.
voice_service_test=''
if voice_service_test="$(python3 "$PROJECT_ROOT/scripts/voice-service-selftest.py" 2>&1)"; then
  ok 'Read aloud: speakable text, sentences, voice service with test tone (single job, time limit, local-only) verified.'
else
  fail "Voice service-self-test: ${voice_service_test:-Unknown error}"
fi

# 0.5.94: voice test (P20.0) with a probe engine: registry and licenses, pinned
# images, measuring without network and with a core limit, SHA-256 check, report.
# 0.5.96: the reason goes into the update log.
tts_spike_test=''
if tts_spike_test="$(python3 "$PROJECT_ROOT/scripts/tts-spike-selftest.py" 2>&1)"; then
  ok 'Voice test: per-voice licenses, pinned versions, offline benchmarking with Core limits, SHA-256 checks and report verified.'
else
  fail "Voice test-self-test: ${tts_spike_test:-Unknown error}"
fi

# 0.6.37 (P20.5 A): image test: registry (Apache 2.0, commit as in the Dockerfile), loading with
# resume and SHA-256 against a stand-in Hugging Face, lock check, a run with a stand-in sd-cli, report.
image_spike_test=''
if image_spike_test="$(python3 "$PROJECT_ROOT/scripts/image-spike-selftest.py" 2>&1)"; then
  ok 'Image test: registry and licensing (FLUX.2-klein 9B opt-in only), SHA-256 download, lock checks, benchmark, face prompt and report verified.'
else
  fail "Image test-self-test: ${image_spike_test:-Unknown error}"
fi

# 0.6.39 (P20.5 B): Mara’s image service: registry (FLUX.2-klein 4B, pins required), progress
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
  ok 'Mara’s seven reference images are present in the repository.'
else
  printf '[WARN] %s\n' "Mara’s image set is incomplete ($mara_missing of 7 missing); missing images will be skipped." >&2
fi

image_service_test=''
if image_service_test="$(python3 "$PROJECT_ROOT/scripts/image-service-selftest.py" 2>&1)"; then
  ok 'Image service: registry, real steps, pinned SHA-256 weights, one image at a time, cancellation, timeout and benchmark reuse verified.'
else
  fail "Image service-self-test: ${image_service_test:-Unknown error}"
fi

if python3 "$PROJECT_ROOT/scripts/python-input-selftest.py" >/dev/null 2>&1; then
  ok 'Python inputs: message association, original files and ZIP validation tested.'
else
  fail 'Python input-file self-test failed.'
fi

if python3 "$PROJECT_ROOT/scripts/cache-bust.py" --root "$PROJECT_ROOT" --verify > /dev/null; then
  ok 'HTML assets use current numeric content cache fingerprints.'
else
  fail 'Stale asset cache fingerprints or invalid VERSION. Run the installer again.'
fi
if grep -Fq 'console_artifact|console_artifacts' "$PROJECT_ROOT/config/runtime/nginx.conf.template"; then
  ok 'Nginx allows protected file downloads and metadata endpoint.'
else
  fail 'Nginx routing for chat-generated files is missing.'
fi
if grep -Fq '|console_insights|' "$PROJECT_ROOT/config/runtime/nginx.conf.template"; then
  ok 'Nginx allows the information panels (Core, context, tools and project).'
else
  fail 'Nginx routing for the information panels is missing.'
fi
if grep -Fq '|voice_live|' "$PROJECT_ROOT/config/runtime/nginx.conf.template"; then
  ok '0.6.31: Nginx allows live voice calls (api/voice_live.php).'
else
  fail 'Nginx blocks live voice calls (voice_live missing from allowlist).'
fi
if grep -Fq '|notifications|' "$PROJECT_ROOT/config/runtime/nginx.conf.template" && grep -Fq 'location = /sw.js' "$PROJECT_ROOT/config/runtime/nginx.conf.template"; then
  ok '0.6.81: Nginx allows notifications (api/notifications.php) and the service worker (/sw.js).'
else
  fail 'Nginx blocks notifications or the service worker (notifications or /sw.js missing).'
fi
if grep -Fq '|composer_tools|' "$PROJECT_ROOT/config/runtime/nginx.conf.template"; then
  ok '0.6.43: Nginx allows tools configured in the + menu (api/composer_tools.php).'
else
  fail 'Nginx blocks the + menu (composer_tools missing from allowlist).'
fi
if grep -Fq '|voice_input|' "$PROJECT_ROOT/config/runtime/nginx.conf.template" && grep -Fq 'microphone=(self)' "$PROJECT_ROOT/config/runtime/nginx.conf.template" \
   && ! grep -Fq 'microphone=()' "$PROJECT_ROOT/config/runtime/nginx.conf.template"; then
  ok 'Nginx allows voice input (api/voice_input.php and microphone permission for the app).'
else
  fail 'Nginx blocks voice input (voice_input missing or microphone denied).'
fi

if (( FAILURES == 0 )); then
  ok 'E.M.B.E.R. CoreUI branding, license files, AI settings and Admin Core are packaged.'
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
  fail "Private bible or compendium material is still included in the release: $private_release_file"
else
  ok 'The release does not contain any private bible or compendium documents.'
fi

if grep -Fq "action: 'delete_permanently'" "$PROJECT_ROOT/js/console-app.js" \
    && grep -Fq "coreui_console_session_delete_tx" "$PROJECT_ROOT/api/console_session_store.php" \
    && grep -Fq "delete_confirmation_required" "$PROJECT_ROOT/api/console_sessions.php"; then
  ok 'Archive-protected, transactional session deletion is included.'
else
  fail 'Safe session deletion is incomplete in the package.'
fi

if grep -Fq "sse_send('token'" "$PROJECT_ROOT/api/console_stream.php"; then
  fail 'The SSE endpoint still exposes unverified model tokens to the browser.'
else
  ok 'SSE output is sent only after server-side response validation.'
fi

if grep -Fq "{action: 'core', model_override: value}" "$PROJECT_ROOT/js/coreui-core-mode.js" \
    && grep -Fq "coreui_private_knowledge_block" "$PROJECT_ROOT/api/chat.php" \
    && grep -Fq "user_create" "$PROJECT_ROOT/api/admin.php"; then
  ok 'Functional model selection, private RAG-Lite and account creation are included.'
else
  fail 'At least one 0.4.0 feature is not fully wired up.'
fi

# The stream may apply a per-call Thinking override before the account default.
# Match the actual payload binding, not a frozen PHP source string from 0.5.50.
# The reply-pipeline selftest verifies the precise runtime contract later.
if grep -Fq "action: 'thinking'" "$PROJECT_ROOT/js/coreui-core-mode.js" \
    && grep -Fq "coreui_general_think_value(\$model, \$thinkOverride ?? ember_thinking_enabled())" "$PROJECT_ROOT/api/console_stream.php"; then
  ok 'Thinking toggle in chat and model calls, including account defaults, are included.'
else
  fail 'Thinking toggle or model payload integration is missing.'
fi

if grep -Fq 'MAX_MESSAGE_ATTACHMENTS = 10' "$PROJECT_ROOT/js/console-app.js" \
    && grep -Fq 'stu_console_message_attachments' "$PROJECT_ROOT/database/migrations/005_thinking_attachments.sql"; then
  ok 'Up to ten persistent message attachments are included.'
else
  fail 'Multiple attachments or their database migration are missing.'
fi

if grep -Fq 'coreui_ollama_model_exists' "$PROJECT_ROOT/api/user_settings.php" \
    && grep -Fq 'password_change' "$PROJECT_ROOT/api/account_security.php" \
    && grep -Fq 'thinking_content_exported' "$PROJECT_ROOT/api/account_export.php" \
    && grep -Fq 'loadAccountSecurity()' "$PROJECT_ROOT/js/settings.js"; then
  ok 'Account security, secure data export and validated Ollama model selection are included.'
else
  fail 'At least one 0.4.1 feature is not fully wired up.'
fi

if (( FAILURES > 0 )); then
  printf '\nPreflight stopped early: %d issue(s).\n' "$FAILURES" >&2
  exit 1
fi

# Die Datei wird ausschliesslich vom Installer mit validierten einzeiligen Werten erzeugt.
set -a
# shellcheck disable=SC1090
source "$ENV_FILE"
set +a

if command -v docker >/dev/null 2>&1 && docker info >/dev/null 2>&1; then
  ok 'Docker daemon is reachable.'
else
  fail 'Docker daemon is unreachable.'
fi

if docker compose version >/dev/null 2>&1; then
  COMPOSE_CMD=(docker compose)
  ok 'Docker Compose v2 is available.'
elif command -v docker-compose >/dev/null 2>&1; then
  COMPOSE_CMD=(docker-compose)
  ok 'Docker Compose is available.'
else
  fail 'Docker Compose missing.'
fi

if (( ${#COMPOSE_CMD[@]} > 0 )); then
  if compose config >/dev/null 2>&1; then
    ok 'Compose configuration is valid.'
  else
    fail 'Compose configuration is invalid.'
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

  # The configured search URL may point to an independently managed instance.
  if is_enabled "${COREUI_INSTALL_BROWSE:-0}" && service_running browse; then
    if browse_search="$(compose exec -T browse python3 /var/www/coreui/tools/ember_browse_worker.py --check-search 2>&1)"; then
      ok 'The configured SearXNG endpoint returned usable links to the browser worker.'
    else
      warn "${browse_search:-The browser worker could not check its configured SearXNG endpoint.}"
    fi
  fi

  if compose exec -T php php -r \
      'require "/var/www/coreui/api/db.php"; echo (int)stu_pdo()->query("SELECT COUNT(*) FROM stu_schema_migrations")->fetchColumn();' \
      >/dev/null 2>&1; then
    ok 'Dedicated MariaDB connection and CoreAI: TCI Interface schema work.'
  else
    fail 'Dedicated MariaDB connection or CoreAI: TCI Interface schema is faulty.'
  fi

  if compose exec -T php php -r \
      'require "/var/www/coreui/api/db.php"; $p=stu_pdo(); $p->query("SELECT user_id FROM stu_user_ai_settings LIMIT 1"); $p->query("SELECT id FROM stu_admin_audit LIMIT 1");' \
      >/dev/null 2>&1; then
    ok 'Migration 002: user AI settings and admin audit ready.'
  else
    fail 'Migration 002 missing. Fuehre scripts/stack.sh migrate aus.'
  fi

  if compose exec -T php php -r \
      'require "/var/www/coreui/api/db.php"; $p=stu_pdo(); $p->query("SELECT last_message_id,last_read_message_id,archived_at FROM stu_console_sessions LIMIT 0"); $p->query("SELECT session_id,reply_to_id FROM stu_chat_messages LIMIT 0"); $p->query("SELECT session_id,trigger_message_id FROM stu_ember_browse_jobs LIMIT 0");' \
      >/dev/null 2>&1; then
    ok 'Migration 003: persistent sessions and turn association ready.'
  else
    fail 'Migration 003 missing. Fuehre scripts/stack.sh migrate aus.'
  fi

  if compose exec -T php php -r \
      'require "/var/www/coreui/api/db.php"; $p=stu_pdo(); $p->query("SELECT user_id,display_name,assistant_name FROM stu_coreui_profiles LIMIT 0"); $p->query("SELECT user_id,slot FROM stu_coreui_profile_media LIMIT 0"); $p->query("SELECT uuid,user_id,status FROM stu_user_knowledge_sources LIMIT 0"); $p->query("SELECT source_uuid,user_id,chunk_text FROM stu_user_knowledge_chunks LIMIT 0");' \
      >/dev/null 2>&1; then
    ok 'Migration 004: profiles, CoreAI identity and private RAG-Lite ready.'
  else
    fail 'Migration 004 missing. Fuehre scripts/stack.sh migrate aus.'
  fi

  if compose exec -T php php -r \
      'require "/var/www/coreui/api/db.php"; $p=stu_pdo(); $p->query("SELECT thinking_enabled FROM stu_user_ai_settings LIMIT 0"); $p->query("SELECT message_id,media_uuid,user_id,position FROM stu_console_message_attachments LIMIT 0");' \
      >/dev/null 2>&1; then
    ok 'Migration 005: thinking selection and persistent multiple attachments ready.'
  else
    fail 'Migration 005 missing. Fuehre scripts/stack.sh migrate aus.'
  fi

  if compose exec -T php php -r \
      'require "/var/www/coreui/api/db.php"; $p=stu_pdo(); $p->query("SELECT password_changed_at,last_login_at FROM stu_users LIMIT 0"); $p->query("SELECT token_hash,expires_at,revoked_at FROM stu_auth_sessions LIMIT 0");' \
      >/dev/null 2>&1; then
    ok 'Migration 006: revocable sign-ins and password timestamps ready.'
  else
    fail 'Migration 006 missing. Fuehre scripts/stack.sh migrate aus.'
  fi

  if compose exec -T php php -r \
      'require "/var/www/coreui/api/db.php"; $p=stu_pdo(); $s=$p->prepare("SELECT COUNT(*) FROM ember_knowledge_chunks WHERE source IN (?,?)"); $s->execute(["bibel_v10_4","kompendium_v6"]); exit((int)$s->fetchColumn() === 0 ? 0 : 1);' \
      >/dev/null 2>&1; then
    ok 'Migration 007: accidentally imported private studio lore removed.'
  else
    fail 'Migration 007 missing or private studio lore remains in the database.'
  fi

  if compose exec -T php php -r \
      'require "/var/www/coreui/api/db.php"; $p=stu_pdo(); $p->query("SELECT response_floor_id,mode,status,response_message_id,browse_job_id FROM stu_console_generation_requests LIMIT 0");' \
      >/dev/null 2>&1; then
    ok 'Migration 008: feedback, alternatives and continuations ready.'
  else
    fail 'Migration 008 missing. Fuehre scripts/stack.sh migrate aus.'
  fi

  if compose exec -T php php -r \
      'require "/var/www/coreui/api/db.php"; $p=stu_pdo(); $p->query("SELECT revision_no,superseded_message_count FROM stu_console_message_revisions LIMIT 0"); $m=$p->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=\"stu_console_generation_requests\" AND COLUMN_NAME=\"mode\"")->fetchColumn(); exit(str_contains((string)$m,"\x27edit\x27") ? 0 : 1);' \
      >/dev/null 2>&1; then
    ok 'Migration 009: message editing and context restart ready.'
  else
    fail 'Migration 009 missing. Fuehre scripts/stack.sh migrate aus.'
  fi

  if compose exec -T php php -r \
      'require "/var/www/coreui/api/db.php"; require "/var/www/coreui/api/console_artifact_store.php"; exit(coreui_console_artifact_schema_ready(stu_pdo()) ? 0 : 1);' \
      >/dev/null 2>&1; then
    ok 'Migration 011: persistent chat-generated files ready.'
  else
    fail 'Migration 011 missing. Fuehre scripts/stack.sh migrate aus.'
  fi

  if compose exec -T php php -r \
      'require "/var/www/coreui/api/db.php"; require "/var/www/coreui/api/console_work_store.php"; exit(coreui_work_schema_ready(stu_pdo()) ? 0 : 1);' \
      >/dev/null 2>&1; then
    ok 'Migration 012: persistent work events ready.'
  else
    fail 'Migration 012 missing. Fuehre scripts/stack.sh migrate aus.'
  fi

  # Since 0.5.81 skills use the repository library, not per-account DB switches.
  # Migration 014 remains in the migration history; skills-selftest.php below
  # validates the current library and work-loop integration.

  if compose exec -T php php -r \
      'require "/var/www/coreui/api/db.php"; $p=stu_pdo(); $t=$p->query("SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=\"stu_chat_messages\" AND COLUMN_NAME=\"message\"")->fetchColumn(); exit(strtolower((string)$t)==="longtext" ? 0 : 1);' \
      >/dev/null 2>&1; then
    ok 'Migration 015: long chat replies can be stored persistently.'
  else
    fail 'Migration 015 missing: stu_chat_messages.message is not LONGTEXT.'
  fi

  if compose exec -T php php -r \
      'require "/var/www/coreui/api/db.php"; require "/var/www/coreui/api/console_plan_store.php"; exit(coreui_plan_schema_ready(stu_pdo()) ? 0 : 1);' \
      >/dev/null 2>&1; then
    ok 'Migration 016: persistent Markdown plans ready.'
  else
    fail 'Migration 016 missing: Markdown project planning is not ready.'
  fi

  if compose exec -T php php -r \
      'require "/var/www/coreui/api/db.php"; require "/var/www/coreui/api/console_agent_runs.php"; exit(coreui_agent_run_schema_ready(stu_pdo()) ? 0 : 1);' \
      >/dev/null 2>&1; then
    ok 'Migration 013: persistent agent runs ready.'
  else
    fail 'Migration 013 missing. Fuehre scripts/stack.sh migrate aus.'
  fi

  if compose exec -T php php -r \
      'require "/var/www/coreui/api/db.php"; require "/var/www/coreui/api/console_model_queue.php"; exit(coreui_model_queue_schema_ready(stu_pdo()) ? 0 : 1);' \
      >/dev/null 2>&1; then
    ok 'Migration 013: global FIFO model queue ready.'
  else
    fail 'Migration 013: model queue missing.'
  fi

  if compose exec -T php php -r \
      'require "/var/www/coreui/api/db.php"; require "/var/www/coreui/api/console_task_journal.php"; exit(coreui_task_journal_schema_ready(stu_pdo()) ? 0 : 1);' \
      >/dev/null 2>&1; then
    ok 'Migration 057: durable task journal and claim ledger ready.'
  else
    fail 'Migration 057 missing: durable task journal is required for autonomous project tools.'
  fi

  if compose exec -T php php scripts/task-journal-selftest.php >/dev/null 2>&1; then
    ok 'Task journal: idempotent tool claims, checkpoints and owner isolation verified.'
  else
    fail 'Task journal: checkpoint and safe-replay regression failed.'
  fi

  if compose exec -T php php scripts/agent-runs-selftest.php >/dev/null 2>&1; then
    ok 'P05: agent-run idempotency and account isolation verified offline.'
  else
    fail 'P05 agent-run self-test failed.'
  fi

  if compose exec -T php php scripts/agent-run-liveness-selftest.php >/dev/null 2>&1; then
    ok '0.6.9: abandoned agent runs terminate via heartbeat, cancellation and deletion, verified.'
  else
    fail 'Self-test for abandoned agent runs failed.'
  fi

  # 0.6.97: no production DB writes from language selftests, even after an update.
  account_language_safety=''
  if account_language_safety="$(compose exec -T php php scripts/account-language-safety-selftest.php 2>&1)"; then
    ok 'AI profile: language self-test is isolated and test-account cleanup verified.'
  else
    fail "AI profile: security regression: ${account_language_safety:-Unknown}"
  fi
  account_language_output=''
  if account_language_output="$(compose exec -T php env COREUI_SELFTEST_DB=0 php scripts/account-language-selftest.php 2>&1)" && [[ "$account_language_output" == *'live database untouched'* ]]; then
    ok 'AI profile: primary language, prompt and read-aloud settings without production database writes.'
  else
    fail "AI profile: language self-test may touch production data: ${account_language_output:-Unknown}"
  fi

  time_test=''
  if time_test="$(compose exec -T php php scripts/message-time-selftest.php 2>&1)"; then
    ok '0.6.29: time zones in chat (messages, list, UTC database and local time) verified.'
  else
    fail "Zeit-self-test: ${time_test:-Unknown error}"
  fi

  live_test=''
  if live_test="$(compose exec -T php php scripts/voice-live-selftest.php 2>&1)"; then
    ok '0.6.31: live voice (live Core, Eve’s sentences, spoken reply, stable cache prefix, unchanged Modelfile SYSTEM) verified.'
  else
    fail "Live voice-self-test: ${live_test:-Unknown error}"
  fi

  voice_input_test=''
  if voice_input_test="$(compose exec -T php php scripts/voice-input-selftest.php 2>&1)"; then
    ok '0.6.17: voice messages (WAV chunks up to 60 s, thinking disabled, transcript after reply, history, audio WebM; 0.6.22 terms and tools) verified.'
  else
    fail "Voice input-self-test: ${voice_input_test:-Unknown error}"
  fi

  if compose exec -T php php scripts/model-queue-selftest.php >/dev/null 2>&1; then
    ok 'P05: model FIFO queue, timeout, cancellation and lock release verified offline.'
  else
    fail 'P05-Model queue-self-test failed.'
  fi

  if compose exec -T php php scripts/work-events-selftest.php >/dev/null 2>&1; then
    ok 'P06: work events and safe Python error classes verified.'
  else
    fail 'P06-Work event-self-test failed.'
  fi
  if compose exec -T php php scripts/image-ab-diagnostic-selftest.php >/dev/null 2>&1; then
    ok '4B Qwen/Ponpoke: serialized A/B diagnostics, PNG and licenses verified.'
  else
    fail '4B A/B diagnostics-self-test failed.'
  fi
  if compose exec -T php php scripts/image-work-diagnostic-selftest.php >/dev/null 2>&1; then
    ok 'Image failure diagnostics: classified causes without private worker logs verified.'
  else
    fail 'Image failure diagnostics-self-test failed.'
  fi
  if compose exec -T php php scripts/work-events-persistence-selftest.php >/dev/null 2>&1; then
    ok 'P06: work timeline across replies, reloads, variants and account isolation verified.'
  else
    fail 'P06-Work timeline persistence-self-test failed.'
  fi

  if compose exec -T php php scripts/model-live-audit.php --selftest >/dev/null 2>&1; then
    ok 'Modelfile audit: SYSTEM and drift checks passed offline.'
  else
    fail 'Modelfile audit: offline self-test failed.'
  fi

  gemma_test=''
  if gemma_test="$(compose exec -T php php scripts/gemma-zero-parameters-selftest.php 2>&1)"; then
    ok 'Gemma: active Modelfile, file comparison and no API parameter overrides.'
  else
    fail "Gemma Modelfile / Ollama parameter contract: ${gemma_test:-Unknown error}"
  fi

  # Node is optional for PHP installations. Run the real JS unit test when
  # present on the host; the PHP frontend guard always checks integration.
  if native_test="$(compose exec -T php php scripts/native-tools-selftest.php 2>&1)"; then
    ok 'Native tool parsing and persistence self-test passed.'
  else
    fail "Native Tools: $native_test"
  fi

  project_intent_test=''
  if project_intent_test="$(compose exec -T php php scripts/model-project-intent-selftest.php 2>&1)"; then
    ok 'Model-driven project build intent self-test passed.'
  else
    fail "Model-driven project implementation: ${project_intent_test:-Unknown error}"
  fi

  plan_auto_test=''
  if plan_auto_test="$(compose exec -T php php scripts/plan-autocontinue-selftest.php 2>&1)"; then
    ok 'Autonomous Markdown planning self-test passed.'
  else
    fail "Autonomous Markdown planning: ${plan_auto_test:-Unknown error}"
  fi

  project_test=''
  if project_test="$(compose exec -T php php scripts/contextual-project-selftest.php 2>&1)"; then
    ok 'Model-driven project handoff self-test passed.'
  else
    fail "Model-driven project handover: ${project_test:-Unknown error}"
  fi

  file_test=''
  if file_test="$(compose exec -T php php scripts/local-file-tool-selftest.php 2>&1)"; then
    ok 'Local file tasks: Python fences, multipart projects, ZIP output and completeness verified.'
  else
    fail "Local file tool-self-test: ${file_test:-Unknown error}"
  fi

  context_test=''
  if context_test="$(compose exec -T php php scripts/context-budget-selftest.php 2>&1)"; then
    ok 'Context window: detected window size, file prompt budget and plan extract verified.'
  else
    fail "Context window-self-test: ${context_test:-Unknown error}"
  fi

  insights_test=''
  if insights_test="$(compose exec -T php php scripts/insights-selftest.php 2>&1)"; then
    ok 'Info panels: Core, context, tools and project are read-only and session-scoped.'
  else
    fail "Info panels-self-test: ${insights_test:-Unknown error}"
  fi

  ai_context_test=''
  if ai_context_test="$(compose exec -T php php scripts/ai-context-selftest.php 2>&1)"; then
    ok 'AI profile: context window by choice only, never cloud, pinned per run, RAM guidance verified.'
  else
    fail "AI profile-Context window-self-test: ${ai_context_test:-Unknown error}"
  fi

  date_test=''
  if date_test="$(compose exec -T php php scripts/date-awareness-selftest.php 2>&1)"; then
    ok 'Date and time: every Core call in a console task receives both.'
  else
    fail "Date-self-test: ${date_test:-Unknown error}"
  fi

  knowledge_test=''
  if knowledge_test="$(compose exec -T php php scripts/knowledge-search-selftest.php 2>&1)"; then
    ok 'RAG-Lite: rare names take precedence over common terms, history-based follow-ups and visible search step.'
  else
    fail "RAG-Lite search: ${knowledge_test:-Unknown error}"
  fi

  vision_test=''
  if vision_test="$(compose exec -T php php scripts/vision-persona-selftest.php 2>&1)"; then
    ok 'Images and video: same Mara persona as text, no false error and no image claims stored in memory.'
  else
    fail "Image/video persona: ${vision_test:-Unknown error}"
  fi

  audio_test=''
  if audio_test="$(compose exec -T php php scripts/audio-wav-selftest.php 2>&1)"; then
    ok 'Audio: WAV checks, audio-call guidance and media test P20.0 verified.'
  else
    fail "Audio-WAV-self-test: ${audio_test:-Unknown error}"
  fi

  tiers_test=''
  if tiers_test="$(compose exec -T php php scripts/core-tiers-selftest.php 2>&1)"; then
    ok 'Core tiers: TCI names in chat and Mara’s persona for every Core verified.'
  else
    fail "Core tiers-self-test: ${tiers_test:-Unknown error}"
  fi

  install_test=''
  if install_test="$(compose exec -T php php scripts/core-install-selftest.php 2>&1)"; then
    ok 'Core installer: Mara’s Core from Modelfile, hardware check, progress, single job, admins only verified.'
  else
    fail "Core-Installer-self-test: ${install_test:-Unknown error}"
  fi

  library_test=''
  if library_test="$(compose exec -T php php scripts/ollama-library-selftest.php 2>&1)"; then
    ok 'Ollama model search: local variants, trusted origin and shared installation status verified.'
  else
    fail "Ollama model search-self-test: ${library_test:-Unknown error}"
  fi

  hf_library_test=''
  if hf_library_test="$(compose exec -T php php scripts/huggingface-library-selftest.php 2>&1)"; then
    ok 'Hugging Face GGUF library: approvals, quantizations and safe local installation verified.'
  else
    fail "Hugging Face library-self-test: ${hf_library_test:-Unknown error}"
  fi

  # 0.6.96: the PHP-FPM user must be able to queue a request on the host.
  if compose exec -T -u 33:33 php php -r 'exit(is_writable("/var/www/coreui/var/uninstall-request") ? 0 : 1);' >/dev/null 2>&1; then
    ok 'Uninstallation: PHP host requests available in dedicated writable bind mount.'
  else
    fail 'Uninstallation: var/uninstall-request is not writable by PHP (UID 33).'
  fi

  uninstall_store_test=''
  if uninstall_store_test="$(compose exec -T php php scripts/uninstall-store-selftest.php 2>&1)"; then
    ok 'Uninstallation: secure owner request, archive mode and atomic queue verified.'
  else
    fail "Uninstallation: queue self-test failed. Details: $uninstall_store_test"
  fi

  installed_cores_test=''
  if installed_cores_test="$(compose exec -T php php scripts/installed-cores-selftest.php 2>&1)"; then
    ok 'Installed Cores: default and base models protected; individual uninstall and role access verified.'
  else
    fail "Installed Cores-self-test: ${installed_cores_test:-Unknown error}"
  fi

  guest_test=''
  if guest_test="$(compose exec -T php php scripts/guest-access-selftest.php 2>&1)"; then
    ok 'Guest access: quota, count on each reply, private chat only, hourly limit, login and admin verified.'
  else
    fail "Guest access-self-test: ${guest_test:-Unknown error}"
  fi

  abuse_test=''
  if abuse_test="$(compose exec -T php php scripts/abuse-guard-selftest.php 2>&1)"; then
    ok 'Abuse protection: device fingerprint, network quota, daily limit, login throttling and real visitor address verified.'
  else
    fail "Abuse prevention-self-test: ${abuse_test:-Unknown error}"
  fi

  captcha_test=''
  if captcha_test="$(compose exec -T php php scripts/captcha-selftest.php 2>&1)"; then
    ok 'Captcha: signed challenge, single use, tampering, expiration and reuse rejected.'
  else
    fail "Captcha-self-test: ${captcha_test:-Unknown error}"
  fi

  roles_test=''
  if roles_test="$(compose exec -T php php scripts/roles-guest-selftest.php 2>&1)"; then
    ok 'Roles: owner, admin, user and guest; guests use the default Core.'
  else
    fail "Roles-self-test: ${roles_test:-Unknown error}"
  fi

  # 0.5.83: models outside CoreAI only for the Inhaber and released accounts.
  model_access_test=''
  if model_access_test="$(compose exec -T php php scripts/model-access-selftest.php 2>&1)"; then
    ok 'Model permissions: Mara’s Cores for everyone; third-party models only for owner and permitted accounts.'
  else
    fail "Model permissions-self-test: ${model_access_test:-Unknown error}"
  fi

  # 0.5.83: update window: only a new commit, Inhaber only, waiting for everyone.
  update_test=''
  if update_test="$(compose exec -T php php scripts/update-selftest.php 2>&1)"; then
    ok 'Update window: shown only for new commits, started only by owner, waiting screen for all.'
  else
    fail "Update-self-test: ${update_test:-Unknown error}"
  fi

  # 0.5.84: deleting an account (rules, confirmation, files; a full deletion on the database, rolled back).
  delete_test=''
  if delete_test="$(compose exec -T php php scripts/account-delete-selftest.php 2>&1)"; then
    ok 'Delete account: permissions, confirmation, files and complete deletion (with rollback) verified.'
  else
    fail "Account deletion-self-test: ${delete_test:-Unknown error}"
  fi

  # 0.5.85: settings per account (password, signing out, name and e-mail; on the database rolled back).
  manage_test=''
  if manage_test="$(compose exec -T php php scripts/account-admin-selftest.php 2>&1)"; then
    ok 'Edit account: permissions, password, sign-out, name and email (with rollback) verified.'
  else
    fail "Account editing-self-test: ${manage_test:-Unknown error}"
  fi

  # 0.5.85: own rules per account (tools blocked per account, global off stays off).
  policy_test=''
  if policy_test="$(compose exec -T php php scripts/user-policy-selftest.php 2>&1)"; then
    ok 'Per-account policies: disabled tools, global disable, daily limit and context window (with rollback) verified.'
  else
    fail "Per-account policies-self-test: ${policy_test:-Unknown error}"
  fi

  # 0.5.86: Mara’s memories stored and used whole (no cut), budget per window.
  memory_length_test=''
  if memory_length_test="$(compose exec -T php php scripts/memory-length-selftest.php 2>&1)"; then
    ok 'Memory: memories stored and provided in full, budgeted by context-window size verified.'
  else
    fail "Memory length-self-test: ${memory_length_test:-Unknown error}"
  fi

  # 0.6.1: read aloud on the PHP side: cache per account, 7 days, guests never, only own answers.
  voice_read_test=''
  if voice_read_test="$(compose exec -T php php scripts/voice-read-selftest.php 2>&1)"; then
    ok 'Read aloud: 7-day per-account cache, no guests, own replies only and AI-profile toggle verified.'
  else
    fail "Read aloud-self-test: ${voice_read_test:-Unknown error}"
  fi

  # 0.5.94: Stimmproben in Admin Core: runs, samples only when listed, Inhaber only.
  tts_store_test=''
  if tts_store_test="$(compose exec -T php php scripts/tts-spike-store-selftest.php 2>&1)"; then
    ok 'Voice samples: test runs, only listed previews from their run, owner access only verified.'
  else
    fail "Voice previews-self-test: ${tts_store_test:-Unknown error}"
  fi

  # 0.6.37 (P20.5 A): Bildproben in Admin Core: runs, images only when listed and real PNGs, Inhaber only.
  image_store_test=''
  if image_store_test="$(compose exec -T php php scripts/image-spike-store-selftest.php 2>&1)"; then
    ok 'Image samples: test runs, only listed PNG files from their run, owner access only verified.'
  else
    fail "Image samples-self-test: ${image_store_test:-Unknown error}"
  fi

  # 0.6.39 (P20.5 C): Mara’s images in the chat: marker, work loop step, keeping the image for
  # the account, cancel, history, deletion with the chat, switches under Betrieb and per account.
  image_gen_test=''
  if image_gen_test="$(compose exec -T -u 33:33 php php scripts/image-gen-selftest.php 2>&1)"; then
    ok 'Images in chat: markers, work step, per-account storage, cancel, history, delete and toggles verified.'
  else
    fail "Images in chat-self-test: ${image_gen_test:-Unknown error}"
  fi

  # 0.8.34: a finished image that cannot be kept names its cause (work view, log), the image folder stays PHP's.
  image_store_diag_test=''
  if image_store_diag_test="$(compose exec -T -u 33:33 php php scripts/image-store-diagnostic-selftest.php 2>&1)"; then
    ok 'Image saving: a failed save names its cause, self-tests keep the image folder writable for PHP.'
  else
    fail "Image saving self-test: ${image_store_diag_test:-Unknown error}"
  fi

  # 0.6.81: Benachrichtigungen: Web Push (RFC 8291 example, VAPID), saving, dispatch and triggers.
  notifications_test=''
  if notifications_test="$(compose exec -T php php scripts/notifications-selftest.php 2>&1)"; then
    ok 'Notifications: Web Push encryption (RFC 8291), VAPID, categories, delivery and triggers verified.'
  else
    fail "Notification-self-test: ${notifications_test:-Unknown error}"
  fi

  # 0.6.82: E-Mail-Versand (SMTP) in Admin Core: input checks, sealed password, a real SMTP session.
  mail_settings_test=''
  if mail_settings_test="$(compose exec -T php php scripts/mail-settings-selftest.php 2>&1)"; then
    ok 'Email delivery: inputs, encrypted password, server-configuration precedence and SMTP session verified.'
  else
    fail "Email delivery-self-test: ${mail_settings_test:-Unknown error}"
  fi

  # 0.6.84: Profil overview: tokens counted per account, streaks, longest task, raster, tools, insights.
  profile_stats_test=''
  if profile_stats_test="$(compose exec -T php php scripts/profile-stats-selftest.php 2>&1)"; then
    ok 'Profile: token counts, streaks, longest task, activity grid, tools and insights verified.'
  else
    fail "Profile-self-test: ${profile_stats_test:-Unknown error}"
  fi

  # 0.6.85: Sicherheit und Anmeldung: RFC 6238 codes, WebAuthn, lockdown, recovery, advanced account security.
  sign_in_security_test=''
  if sign_in_security_test="$(compose exec -T php php scripts/sign-in-security-selftest.php 2>&1)"; then
    ok 'Security: authenticator codes (RFC 6238), passkeys (WebAuthn), lockdown, recovery and advanced account security verified.'
  else
    fail "Security-self-test: ${sign_in_security_test:-Unknown error}"
  fi

  # 0.6.86: Konto: Benutzername (unique, reserved names), plans Free/Go/Plus/Pro as real rules.
  account_page_test=''
  if account_page_test="$(compose exec -T php php scripts/account-page-selftest.php 2>&1)"; then
    ok 'Account: unique usernames, reserved names and plans (Free, Go, Plus, Pro with enforced rules) verified.'
  else
    fail "Account self-test: ${account_page_test:-Unknown error}"
  fi

  # 0.6.87: chat menu and Archivierte Chats: pin order, archive search and filters, files, search, Markdown.
  chat_menu_test=''
  if chat_menu_test="$(compose exec -T php php scripts/chat-menu-selftest.php 2>&1)"; then
    ok 'Chat menu: pinning, archive search and filtering, files, in-chat search and Markdown sharing verified.'
  else
    fail "Chat menu-self-test: ${chat_menu_test:-Unknown error}"
  fi

  # 0.7.0: Settings > Personalisierung: styles, instructions, reference photos, memory summary, wipe.
  personalization_page_test=''
  if personalization_page_test="$(compose exec -T php php scripts/personalization-page-selftest.php 2>&1)"; then
    ok 'Personalization: styles, instructions, reference photos, memory summary and deletion verified.'
  else
    fail "Personalization-self-test: ${personalization_page_test:-Unknown error}"
  fi

  # 0.7.1: Settings > Pets: built-in sheets, uploads (PNG, WebP), choice, the chat work line.
  pets_test=''
  if pets_test="$(compose exec -T php php scripts/pets-selftest.php 2>&1)"; then
    ok 'Pets: original CoreAI pets, sprite sheets, selection and upload verified.'
  else
    fail "Pets-self-test: ${pets_test:-Unknown error}"
  fi

  # Optional community library assets are NOT shipped unless explicitly
  # imported with a confirmed creator license by the CoreAI owner.
  openpet_library_test=''
  if openpet_library_test="$(compose exec -T php php scripts/openpet-library-selftest.php 2>&1)"; then
    ok 'OpenPet: approved default pet library, private storage and rights checks OK.'
  else
    fail "OpenPet library: ${openpet_library_test:-Unknown error}"
  fi

  # 0.7.14: Settings > Stimme: voices to choose (free licenses), the chosen voice in plans and parts, preview, transcript language; 0.7.15 the microphone switch.
  voice_settings_test=''
  if voice_settings_test="$(compose exec -T php php scripts/voice-settings-selftest.php 2>&1)"; then
    ok 'Voice: voice selection, previews, language of voice messages and microphone toggle verified.'
  else
    fail "Voice-self-test: ${voice_settings_test:-Unknown error}"
  fi

  # 0.7.28: Settings > Speicher: the limit for all accounts and per account, usage, Dateien and Bilder, favourites, deleting.
  storage_test=''
  if storage_test="$(compose exec -T -u 33:33 php php scripts/storage-selftest.php 2>&1)"; then
    ok 'Storage management self-test passed.'
  else
    fail "Storage-self-test: ${storage_test:-Unknown error}"
  fi

  # 0.7.30: Settings > Nutzung: plan and daily limit window, tokens by Core, tools, skills, messages by kind, top chats.
  usage_page_test=''
  if usage_page_test="$(compose exec -T php php scripts/usage-page-selftest.php 2>&1)"; then
    ok 'Usage information self-test passed.'
  else
    fail "Usage-self-test: ${usage_page_test:-Unknown error}"
  fi

  # 0.7.32: Settings > Datenkontrollen: archive and delete all chats (never locked or busy ones), the page.
  data_controls_test=''
  if data_controls_test="$(compose exec -T php php scripts/data-controls-selftest.php 2>&1)"; then
    ok 'Data control self-test passed.'
  else
    fail "Data controls-self-test: ${data_controls_test:-Unknown error}"
  fi

  # 0.7.38: Deep Research runs: plan, Starten, Bearbeiten, Abbrechen, Stopp, sources, chat deletion.
  deep_research_test=''
  if deep_research_test="$(compose exec -T php php scripts/deep-research-selftest.php 2>&1)"; then
    ok 'Deep research self-test passed.'
  else
    fail "Deep-Research-self-test: ${deep_research_test:-Unknown error}"
  fi

  # 0.7.69 (P09): project files: paths, revisions, conflicts, search, ZIP, builds in project chats, limits.
  project_files_test=''
  if project_files_test="$(compose exec -T php php scripts/project-files-selftest.php 2>&1)"; then
    ok 'Project file management self-test passed.'
  else
    fail "Project files-self-test: ${project_files_test:-Unknown error}"
  fi

  # 0.7.73 (P09 B): Mara reads and changes single project files ([DATEI:], [DATEI_AENDERN:], [DATEI_NEU:]).
  project_file_tools_test=''
  if project_file_tools_test="$(compose exec -T php php -d display_errors=stderr -d log_errors=1 scripts/project-file-tools-selftest.php 2>&1)"; then
    ok 'Project file tools self-test passed.'
  else
    project_file_tools_test_exit=$?
    fail "File tools-self-test (exit ${project_file_tools_test_exit}): ${project_file_tools_test:-No PHP output; inspect the PHP service logs and container OOM/kill events}"
  fi

  # 0.8.32: a SUCHEN text with copied line numbers, a fence or other whitespace is matched once, never loosely twice.
  edit_tolerance_test=''
  if edit_tolerance_test="$(compose exec -T php php -d display_errors=stderr -d log_errors=1 scripts/edit-tolerance-selftest.php 2>&1)"; then
    ok 'File changes: copied line numbers and whitespace matched exactly once.'
  else
    edit_tolerance_test_exit=$?
    fail "File change tolerance self-test (exit ${edit_tolerance_test_exit}): ${edit_tolerance_test:-No PHP output; inspect the PHP service logs}"
  fi

  # 0.8.33: one file changed again and again: a check after six changes, a pause with an interim report after ten.
  file_change_pause_test=''
  if file_change_pause_test="$(compose exec -T php php -d display_errors=stderr -d log_errors=1 scripts/file-change-pause-selftest.php 2>&1)"; then
    ok 'Project runs: a check after six changes of one file, a pause after ten.'
  else
    file_change_pause_test_exit=$?
    fail "File change pause self-test (exit ${file_change_pause_test_exit}): ${file_change_pause_test:-No PHP output; inspect the PHP service logs}"
  fi

  # 0.8.34 stabilization.
  security_hardening_test=''
  if security_hardening_test="$(compose exec -T php php -d display_errors=stderr -d log_errors=1 scripts/stabilize-security-selftest.php 2>&1)"; then
    ok 'Accounts: limits for second-factor codes and password resets, capped attachments, no database text in answers.'
  else
    security_hardening_test_exit=$?
    fail "Security hardening self-test (exit ${security_hardening_test_exit}): ${security_hardening_test:-No PHP output; inspect the PHP service logs}"
  fi

  # 0.8.34 stabilization.
  file_history_test=''
  if file_history_test="$(compose exec -T php php -d display_errors=stderr -d log_errors=1 scripts/stabilize-history-selftest.php 2>&1)"; then
    ok 'Project files: saved states survive a change of working state.'
  else
    file_history_test_exit=$?
    fail "File history self-test (exit ${file_history_test_exit}): ${file_history_test:-No PHP output; inspect the PHP service logs}"
  fi

  # 0.8.34 stabilization: stream pauses and refusals, closed actions after a worker loss, queue wait, one first call.
  backend_hardening_test=''
  if backend_hardening_test="$(compose exec -T php php -d display_errors=stderr -d log_errors=1 scripts/stabilize-backend-selftest.php 2>&1)"; then
    ok 'Chat backend: stream pauses, provider refusals, actions after a worker loss and the model queue verified.'
  else
    backend_hardening_test_exit=$?
    fail "Backend hardening self-test (exit ${backend_hardening_test_exit}): ${backend_hardening_test:-No PHP output; inspect the PHP service logs}"
  fi

  # 0.8.34 stabilization.
  loop_hardening_test=''
  if loop_hardening_test="$(compose exec -T php php -d display_errors=stderr -d log_errors=1 scripts/stabilize-loop-selftest.php 2>&1)"; then
    ok 'Project runs: finish evidence, requirements, repairs, oscillation, edits and prompt cache verified.'
  else
    loop_hardening_test_exit=$?
    fail "Loop hardening self-test (exit ${loop_hardening_test_exit}): ${loop_hardening_test:-No PHP output; inspect the PHP service logs}"
  fi

  # 0.8.34 stabilization.
  loop_journal_test=''
  if loop_journal_test="$(compose exec -T php php -d display_errors=stderr -d log_errors=1 scripts/stabilize-loop-journal-selftest.php 2>&1)"; then
    ok 'Task journal: info steps, requirements and resume verified.'
  else
    loop_journal_test_exit=$?
    fail "Loop journal self-test (exit ${loop_journal_test_exit}): ${loop_journal_test:-No PHP output; inspect the PHP service logs}"
  fi

  # 0.8.8: every saved chat owns its real, private file/run/preview workspace.
  chat_workspace_test=''
  if chat_workspace_test="$(compose exec -T php php -d display_errors=stderr -d log_errors=1 scripts/chat-workspace-selftest.php 2>&1)"; then
    ok 'Chat workspace self-test passed.'
  else
    chat_workspace_exit=$?
    fail "Chat workspace-self-test (exit ${chat_workspace_exit}): ${chat_workspace_test:-No PHP output; inspect workspace migration and PHP worker logs}"
  fi

  # 0.7.74 (P09 C): a chat branches off at one of Mara’s replies; the original stays unchanged.
  chat_branch_test=''
  if chat_branch_test="$(compose exec -T php php -d display_errors=stderr -d log_errors=1 scripts/chat-branch-selftest.php 2>&1)"; then
    ok 'Conversation branch self-test passed.'
  else
    chat_branch_test_exit=$?
    fail "Chat branch-self-test (exit ${chat_branch_test_exit}): ${chat_branch_test:-No PHP output; inspect the PHP service logs and container OOM/kill events}"
  fi

  # 0.7.75 (P10 A): the preview of a web project in an origin of its own.
  project_preview_test=''
  if project_preview_test="$(compose exec -T php php scripts/project-preview-selftest.php 2>&1)"; then
    ok 'Project preview self-test passed.'
  else
    fail "Project preview-self-test: ${project_preview_test:-Unknown error}"
  fi

  # 0.7.77 (P10 B): commands on project files in the sandbox, and Mara’s [AUSFUEHREN:].
  project_runs_test=''
  if project_runs_test="$(compose exec -T php php -d display_errors=stderr -d log_errors=1 scripts/project-runs-selftest.php 2>&1)"; then
    ok 'Project execution self-test passed.'
  else
    project_runs_test_exit=$?
    fail "Project runs-self-test (exit ${project_runs_test_exit}): ${project_runs_test:-No PHP output; inspect the PHP service logs and container OOM/kill events}"
  fi

  project_procs_test=''
  if project_procs_test="$(compose exec -T php php -d display_errors=stderr -d log_errors=1 scripts/project-procs-selftest.php 2>&1)"; then
    ok 'Project processes self-test passed.'
  else
    project_procs_test_exit=$?
    fail "Project processes-self-test (exit ${project_procs_test_exit}): ${project_procs_test:-No PHP output; inspect the PHP service logs and container OOM/kill events}"
  fi

  # 0.7.91: Mara knows the project environment and runs processes and development servers herself.
  project_env_test=''
  if project_env_test="$(compose exec -T php php -d display_errors=stderr -d log_errors=1 scripts/project-env-selftest.php 2>&1)"; then
    ok 'Project development environment self-test passed.'
  else
    project_env_test_exit=$?
    fail "Project environment self-test (exit ${project_env_test_exit}): ${project_env_test:-No PHP output; inspect the PHP service logs and container OOM/kill events}"
  fi

  # 0.8.11: the external OpenAI-compatible adapter (translation, permission, connection test, end to end
  # against a stand-in server on 127.0.0.1; the key never reaches a log). 0.8.15: external Cores offered
  # from the provider's list, releases per account, the turn per account, Mara's SYSTEM text.
  external_provider_test=''
  if external_provider_test="$(compose exec -T php php -d display_errors=stderr -d log_errors=1 scripts/external-provider-selftest.php 2>&1)"; then
    ok 'External API adapter and external Cores self-test passed.'
  else
    external_provider_test_exit=$?
    fail "External API adapter self-test (exit ${external_provider_test_exit}): ${external_provider_test:-No PHP output; inspect the PHP service logs}"
  fi

  # 0.7.80 (P11 A): versions and working states; the Git export is checked with git where it exists.
  project_vcs_test=''
  if project_vcs_test="$(compose exec -T php php -d display_errors=stderr -d log_errors=1 scripts/project-vcs-selftest.php 2>&1)"; then
    ok 'Project version history self-test passed.'
  else
    project_vcs_exit=$?
    fail "Project versions-self-test (exit ${project_vcs_exit}): ${project_vcs_test:-No PHP output; check container memory limits and PHP logs}"
  fi

  # 0.7.81 (P11 B): merging working states with conflicts, Mara with versions; the Git export with git where it exists.
  project_merge_test=''
  if project_merge_test="$(compose exec -T php php -d display_errors=stderr -d log_errors=1 scripts/project-merge-selftest.php 2>&1)"; then
    ok 'Project merge self-test passed.'
  else
    project_merge_test_exit=$?
    fail "Merge-self-test (exit ${project_merge_test_exit}): ${project_merge_test:-No PHP output; inspect the PHP service logs and container OOM/kill events}"
  fi

  # 0.7.82 (P11 C): the lifetime of the files Mara made: notes of deleted files, the retention, kept files.
  artifact_lifecycle_test=''
  if artifact_lifecycle_test="$(compose exec -T php php scripts/artifact-lifecycle-selftest.php 2>&1)"; then
    ok 'Generated artifact lifecycle self-test passed.'
  else
    fail "Retention-self-test: ${artifact_lifecycle_test:-Unknown error}"
  fi

  # 0.7.83 (P12 A): the account's own skills: store, companion files, Mara’s library and loading.
  skill_packages_test=''
  if skill_packages_test="$(compose exec -T php php -d display_errors=stderr -d log_errors=1 scripts/skill-packages-selftest.php 2>&1)"; then
    ok 'Custom skill package self-test passed.'
  else
    skill_packages_test_exit=$?
    fail "Own skills-self-test (exit ${skill_packages_test_exit}): ${skill_packages_test:-No PHP output; inspect the PHP service logs and container OOM/kill events}"
  fi

  # 0.7.84 (P12 B): shared skills, precedence and the skills fixed for a chat.
  skill_sharing_test=''
  if skill_sharing_test="$(compose exec -T php php -d display_errors=stderr -d log_errors=1 scripts/skill-sharing-selftest.php 2>&1)"; then
    ok 'Shared skill permissions self-test passed.'
  else
    skill_sharing_test_exit=$?
    fail "Shared skills-self-test (exit ${skill_sharing_test_exit}): ${skill_sharing_test:-No PHP output; inspect the PHP service logs and container OOM/kill events}"
  fi

  # 0.7.90 (P13 A): fixed revisions of skills, restoring, export and import of packages.
  skill_revisions_test=''
  if skill_revisions_test="$(compose exec -T php php -d display_errors=stderr -d log_errors=1 scripts/skill-revisions-selftest.php 2>&1)"; then
    ok 'Skill revisions self-test passed.'
  else
    skill_revisions_test_exit=$?
    fail "Skill revisions self-test (exit ${skill_revisions_test_exit}): ${skill_revisions_test:-No PHP output; inspect the PHP service logs and container OOM/kill events}"
  fi

  profile_reference_test=''
  if profile_reference_test="$(compose exec -T php php scripts/profile-reference-selftest.php 2>&1)"; then
    if [[ "$profile_reference_test" == PARTIAL:* ]]; then
      warn "$profile_reference_test"
    else
      ok 'Profile reference image self-test passed.'
    fi
  else
    fail "Profile reference-self-test: ${profile_reference_test:-Unknown error}"
  fi

  # 0.6.59: all image references share identity, subject and style preservation.
  reference_consistency_test=''
  if reference_consistency_test="$(compose exec -T php php scripts/image-reference-consistency-selftest.php 2>&1)"; then
    ok 'Image references: identity, subject fidelity, style reference and multiple sources verified.'
  else
    fail "Image reference consistency test: ${reference_consistency_test:-Unknown error}"
  fi

  # 0.6.43: the + menu of the input field: marks, Mara’s instruction, the tool started with the
  # person's own words, the endpoint's answer for accounts and guests, the readable archive.
  composer_intent_test=''
  if composer_intent_test="$(compose exec -T php php scripts/composer-intent-selftest.php 2>&1)"; then
    ok '+ menu: tool selection, instructions to Mara, security, permissions and archive verified.'
  else
    fail "+ menu-self-test: ${composer_intent_test:-Unknown error}"
  fi

  browser_routing_test=''
  if browser_routing_test="$(compose exec -T php php scripts/browser-routing-selftest.php 2>&1)"; then
    ok 'Browser research: tool routing, permissions and explicit live-view selection verified.'
  else
    fail "Browser routing self-test: ${browser_routing_test:-Unknown error}"
  fi

  compact_test=''
  if compact_test="$(compose exec -T php php scripts/compaction-selftest.php 2>&1)"; then
    ok 'Compress chat: modal, incremental summary, archive-first, search and deletion verified.'
  else
    fail "Compression-self-test: ${compact_test:-Unknown error}"
  fi

  loop_test=''
  if loop_test="$(compose exec -T php php scripts/agent-loop-selftest.php 2>&1)"; then
    ok 'Work loop: chained steps, response, browser handoff, six steps and cancellation verified.'
  else
    fail "Work loop-self-test: ${loop_test:-Unknown error}"
  fi

  web_test=''
  if web_test="$(compose exec -T php php scripts/web-search-selftest.php 2>&1)"; then
    ok 'Web search: keyword retry, no duplicates, clean snippets and dates verified.'
  else
    fail "Web search-self-test: ${web_test:-Unknown error}"
  fi

  if progressive_web_test="$(compose exec -T php php scripts/progressive-web-search-selftest.php 2>&1)"; then
    ok 'Web search: progressive results without duplicates, paging and source citations verified.'
  else
    fail 'Web search: progressive results / paging regression test failed.'
  fi

  weather_test=''
  if weather_test="$(compose exec -T php php scripts/weather-live-selftest.php 2>&1)"; then
    ok '0.6.22: live weather within web search (WDR for NRW, live data for any location, no stale results) verified.'
  else
    fail "Weather-self-test: ${weather_test:-Unknown error}"
  fi

  if is_enabled "${COREUI_INSTALL_BROWSE:-0}" && service_running browse; then
    browse_live=''
    if browse_live="$(compose exec -T -e COREAI_BROWSE_LIVE=1 browse python3 scripts/browse-worker-selftest.py 2>&1)"; then
      ok 'Browser (real Chromium run): filled form, thinking screenshots, blocked login/contact and blocked local network.'
    else
      fail "Browser-Livetest: ${browse_live:-Unknown error}"
    fi
  fi

  mention_test=''
  if mention_test="$(compose exec -T php php scripts/tool-mention-selftest.php 2>&1)"; then
    ok 'Tool mentions: explanations do not launch tools or correction loops.'
  else
    fail "Tool mention-self-test: ${mention_test:-Unknown error}"
  fi

  think_test=''
  if think_test="$(compose exec -T php php scripts/thinking-switch-selftest.php 2>&1)"; then
    ok 'Thinking toggle and Core selection: no forced thinking in Modelfile, saving, preload, global channel and analysis filter verified.'
  else
    fail "Thinking toggle-self-test: ${think_test:-Unknown error}"
  fi

  if command -v node >/dev/null 2>&1; then
    if node "$PROJECT_ROOT/scripts/terran-terminology-selftest.cjs" >/dev/null 2>&1; then
      ok 'Terran CoreAI terminology: Terra-Net and system text verified.'
    else
      fail 'Terran CoreAI terminology: regression to Arch-Net/Ayalren detected.'
    fi
    if node "$PROJECT_ROOT/scripts/tci-language-selftest.cjs" >/dev/null 2>&1; then
      ok 'Mara-TCI language and voice-message terminology verified.'
    else
      fail 'Mara-TCI language: terminology self-test failed.'
    fi
    # 0.7.69: every endpoint the interface calls is allowed by both nginx templates.
    if node "$PROJECT_ROOT/scripts/api-allowlist-selftest.cjs" >/dev/null 2>&1; then
      ok 'API allowlist: all UI endpoints allowed in both Nginx templates.'
    else
      fail 'API allowlist: the UI calls an endpoint blocked by Nginx.'
    fi
    if node "$PROJECT_ROOT/scripts/tci-chat-message-selftest.cjs" >/dev/null 2>&1; then
      ok 'TCI CORE: chat heading, full-width replies and image completion ordering verified.'
    else
      fail 'TCI CORE: heading / image completion self-test failed.'
    fi
    if node "$PROJECT_ROOT/scripts/text-selection-ui-selftest.cjs" >/dev/null 2>&1; then
      ok 'Chat: text selection, copying and native context menu are enabled.'
    else
      fail 'Chat: legacy game text-selection or copy protection is still active.'
    fi
    if node "$PROJECT_ROOT/scripts/work-events-ui-selftest.js" >/dev/null 2>&1; then
      ok 'Web search: source row follows actual events, without rotating fake webpages.'
    else
      fail 'Web search: source carousel shows an incorrect sequence or is broken.'
    fi
    if node "$PROJECT_ROOT/scripts/retry-hint-ui-selftest.cjs" >/dev/null 2>&1; then
      ok 'Chat: a note above the input and a retry button at a run that broke off.'
    else
      fail 'Chat: the note above the input or the retry at a broken-off run is missing.'
    fi
    if node "$PROJECT_ROOT/scripts/stabilize-frontend-selftest.cjs" >/dev/null 2>&1; then
      ok 'Chat: follow-ups, server errors, stop, research panel, chat switch and polls verified.'
    else
      fail 'Chat: the frontend stabilization self-test failed.'
    fi
    if compose exec -T php php /var/www/coreui/scripts/general-controls-selftest.php >/dev/null 2>&1; then
      ok 'General: Core routing, stored settings and real reasoning levels verified.'
    else
      fail 'General: Core routing or reasoning self-test failed.'
    fi
    if node "$PROJECT_ROOT/scripts/ui-language-selftest.cjs" >/dev/null 2>&1; then
      ok 'General: DE/EN app language and automatic browser detection verified.'
    else
      fail 'General: app language switching failed.'
    fi
    if node "$PROJECT_ROOT/scripts/reasoning-effort-ui-selftest.cjs" >/dev/null 2>&1; then
      ok 'Reasoning effort: chat levels, real persistence and binary Core fallback verified.'
    else
      fail 'Reasoning effort: chat control, general settings or Core fallback failed.'
    fi
    if node "$PROJECT_ROOT/scripts/send-shortcut-selftest.cjs" >/dev/null 2>&1; then
      ok 'General: Enter, Ctrl/Command+Enter and IME protection verified.'
    else
      fail 'General: keyboard shortcuts or composer integration failed.'
    fi
    if node "$PROJECT_ROOT/scripts/theme-preferences-selftest.cjs" >/dev/null 2>&1; then
      ok 'Display: system/light/dark, font, separate light/dark modes, theme checks and punctuation rules verified.'
    else
      fail 'Display: day/night mode self-test failed.'
    fi
    if node "$PROJECT_ROOT/scripts/response-timing-ui-selftest.js" >/dev/null 2>&1; then
      ok 'Response duration: stored total and model time in information display verified.'
    else
      fail 'Response duration-JavaScript-self-test failed.'
    fi
    if node "$PROJECT_ROOT/scripts/welcome-composer-ui-selftest.cjs" >/dev/null 2>&1; then
      ok 'Chat start / composer: greeting draft, first message and stop verified.'
    else
      fail 'Chat start / composer: lazy session or stop button failed.'
    fi
    if node "$PROJECT_ROOT/scripts/chat-navigation-recovery-selftest.cjs" >/dev/null 2>&1; then
      ok 'Chat navigation: ongoing model request restored without regenerating.'
    else
      fail 'Chat navigation: recovery self-test failed.'
    fi
    if node "$PROJECT_ROOT/scripts/insights-ui-selftest.cjs" >/dev/null 2>&1; then
      ok 'Info panels: right column, phone buttons and live context verified.'
    else
      fail 'Info panels: JavaScript-self-test failed.'
    fi
    if node "$PROJECT_ROOT/scripts/context-slider-ui-selftest.cjs" >/dev/null 2>&1; then
      ok 'AI profile: context-window control with guidance at each step verified.'
    else
      fail 'AI profile: Context window-Regler-self-test failed.'
    fi
    if node "$PROJECT_ROOT/scripts/thinking-switch-ui-selftest.cjs" >/dev/null 2>&1; then
      ok 'Chat: Core chip with TCI names, fixed order, status dots (green, yellow, red), legend, loading, thinking toggle and errors verified.'
    else
      fail 'Chat: Thinking toggle-UI test failed.'
    fi
    if node "$PROJECT_ROOT/scripts/core-install-ui-selftest.cjs" >/dev/null 2>&1; then
      ok 'Chat: installation modal with hardware check, confirmation, progress and Use Now verified.'
    else
      fail 'Chat: Install dialog-UI test failed.'
    fi
    if node "$PROJECT_ROOT/scripts/model-library-ui-selftest.cjs" >/dev/null 2>&1; then
      ok 'Ollama model modal: search, details, download confirmation and progress verified.'
    else
      fail 'Ollama model dialog: Offline-UI test failed.'
    fi
    # 0.6.59: touch cropper must preserve originals, per-account re-edit and
    # user-square / assistant-portrait behavior.
    profile_crop_ui=''
    if profile_crop_ui="$(node "$PROJECT_ROOT/scripts/profile-crop-ui-selftest.cjs" 2>&1)"; then
      ok 'Profile: mobile crop, square/portrait, original image and recropping verified.'
    else
      fail 'Profile: image cropping-UI test failed.'
      printf '%s\n' "$profile_crop_ui" >&2
    fi
    if node "$PROJECT_ROOT/scripts/registration-ui-selftest.cjs" >/dev/null 2>&1; then
      ok 'Login: Register and Chat as Guest only when enabled, validation and error cases verified.'
    else
      fail 'Login: Registration-self-test failed.'
    fi
    if node "$PROJECT_ROOT/scripts/browse-live-ui-selftest.cjs" >/dev/null 2>&1; then
      ok 'Live browser: Mara’s thinking timer, smooth cursor, queue and recovery after interruption verified.'
    else
      fail 'Live browser-UI test failed.'
    fi
    # 0.6.67: local Playwright check for viewer -> editor -> FLUX reference handoff.
    if node -e "require('playwright')" >/dev/null 2>&1; then
      if node "$PROJECT_ROOT/scripts/image-editor-ui-selftest.cjs" >/dev/null 2>&1; then
        ok 'Image editor: mobile layout, touch selection, undo/redo and image references verified.'
      else
        fail 'Image editor: mobile UI-self-test failed.'
      fi
    else
      warn 'Image editor: Playwright is missing. Browser self-test skipped (not live verified).'
    fi
    if node "$PROJECT_ROOT/scripts/settings-navigation-foundation-selftest.cjs" >/dev/null 2>&1; then
      ok 'Settings: 25 categories, expert mode, placeholder sections and admin separation verified.'
    else
      fail 'Settings: category or admin structure test failed.'
    fi
    # 0.6.71: category navigation, expert mode and standalone Admin Core; needs Playwright for Node.
    if node -e "require('playwright')" >/dev/null 2>&1; then
      if node "$PROJECT_ROOT/scripts/settings-tabs-ui-selftest.cjs" >/dev/null 2>&1; then
        ok 'Settings: categories, search, expert mode, mobile menu and independent Admin Core verified.'
      else
        fail 'Settings: Tab-UI test failed.'
      fi
    else
      warn 'Settings: Tab-UI test skipped (Playwright for Node.js is not installed).'
    fi
    # 0.6.80: Darstellung (segments, font, hex, Hell/Dunkel getrennt, motif export/import) and
    # smart punctuation in the chat input; needs Playwright for Node.
    if node -e "require('playwright')" >/dev/null 2>&1; then
      if node "$PROJECT_ROOT/scripts/appearance-ui-selftest.cjs" >/dev/null 2>&1; then
        ok 'Appearance: theme export/import, hex values, separate light/dark, fonts and punctuation verified in browser.'
      else
        fail 'Appearance: UI test failed.'
      fi
    else
      warn 'Appearance: UI test skipped (Playwright for Node.js is not installed).'
    fi
    # 0.6.81: Benachrichtigungen page (Push for this device, E-Mail state, categories) and the
    # service worker; needs Playwright for Node.
    if node -e "require('playwright')" >/dev/null 2>&1; then
      if node "$PROJECT_ROOT/scripts/notifications-ui-selftest.cjs" >/dev/null 2>&1; then
        ok 'Notifications: push activation, devices, email status, categories and service worker verified in browser.'
      else
        fail 'Notifications: UI test failed.'
      fi
    else
      warn 'Notifications: UI test skipped (Playwright for Node.js is not installed).'
    fi
    # 0.6.82: Admin Core > Betrieb > E-Mail-Versand (SMTP); needs Playwright for Node.
    if node -e "require('playwright')" >/dev/null 2>&1; then
      if node "$PROJECT_ROOT/scripts/admin-mail-ui-selftest.cjs" >/dev/null 2>&1; then
        ok 'Email delivery: SMTP card (save, retain password, test email, server configuration) verified in browser.'
      else
        fail 'Email delivery: UI test failed.'
      fi
    else
      warn 'Email delivery: UI test skipped (Playwright for Node.js is not installed).'
    fi
    # 0.6.84: Settings > Profil overview (figures, raster, made, tools, insights); needs Playwright for Node.
    if node -e "require('playwright')" >/dev/null 2>&1; then
      if node "$PROJECT_ROOT/scripts/profile-stats-ui-selftest.cjs" >/dev/null 2>&1; then
        ok 'Profile: statistics, activity grid (daily, weekly, cumulative), created artifacts, tools and insights verified in browser.'
      else
        fail 'Profile: UI test failed.'
      fi
    else
      warn 'Profile: UI test skipped (Playwright for Node.js is not installed).'
    fi
    # 0.8.34: research cards in a real browser (hits vs. visited pages, no overflow); needs Playwright for Node.
    if node -e "require('playwright')" >/dev/null 2>&1; then
      if node "$PROJECT_ROOT/scripts/progress-browser-selftest.cjs" >/dev/null 2>&1; then
        ok 'Research cards: search hits and visited pages verified in browser.'
      else
        fail 'Research cards: browser test failed.'
      fi
    else
      warn 'Research cards: browser test skipped (Playwright for Node.js is not installed).'
    fi
    # 0.8.34: the image progress card was only listed as a required file, never run.
    if node "$PROJECT_ROOT/scripts/image-progress-ui-selftest.cjs" >/dev/null 2>&1; then
      ok 'Image progress card: steps, width and image id verified.'
    else
      fail 'Image progress card: UI test failed.'
    fi
    # 0.6.85: Sicherheit und Anmeldung and the sign-in with second step and passkey; needs Playwright for Node.
    if node -e "require('playwright')" >/dev/null 2>&1; then
      if node "$PROJECT_ROOT/scripts/security-ui-selftest.cjs" >/dev/null 2>&1; then
        ok 'Security: settings page (passkeys, QR authenticator, email codes, sessions, history, lockdown) and sign-in verified in browser.'
      else
        fail 'Security: UI test failed.'
      fi
    else
      warn 'Security: UI test skipped (Playwright for Node.js is not installed).'
    fi
    # 0.6.87: chat menu (⋮) and Settings > Archivierte Chats; needs Playwright for Node.
    if node -e "require('playwright')" >/dev/null 2>&1; then
      if node "$PROJECT_ROOT/scripts/chat-menu-ui-selftest.cjs" >/dev/null 2>&1 && node "$PROJECT_ROOT/scripts/archived-chats-ui-selftest.cjs" >/dev/null 2>&1; then
        ok 'Chat menu and archived chats verified in browser.'
      else
        fail 'Chat menu or archived chats: UI test failed.'
      fi
    else
      warn 'Chat menu: UI test skipped (Playwright for Node.js is not installed).'
    fi
    # 0.7.4: four insight panels must exist in saved chats, not on the welcome page.
    if node -e "require('playwright')" >/dev/null 2>&1; then
      if node "$PROJECT_ROOT/scripts/insights-chat-visibility-ui-selftest.cjs" >/dev/null 2>&1; then
        ok 'Info panels: four mobile chat buttons, desktop sidebar and empty welcome screen verified.'
      else
        fail 'Info panels: chat / welcome-screen switch or mobile buttons are faulty.'
      fi
    else
      warn 'Info panels: browser smoke test skipped (Playwright not installed); offline DOM contract still required.'
    fi
    # 0.7.4: TCI identity and full-width assistant replies at phone and desktop widths.
    if node -e "require('playwright')" >/dev/null 2>&1; then
      if node "$PROJECT_ROOT/scripts/tci-chat-layout-ui-selftest.cjs" >/dev/null 2>&1; then
        ok 'TCI CORE: avatar, name lines and full-width replies verified in browser.'
      else
        fail 'TCI CORE: responsive chat layout test failed.'
      fi
    else
      warn 'TCI CORE: browser layout test skipped (Playwright not installed).'
    fi
    # 0.7.14/0.7.15: Settings > Stimme, the microphone switch and the recordings; needs Playwright for Node.
    if node -e "require('playwright')" >/dev/null 2>&1; then
      if node "$PROJECT_ROOT/scripts/voice-settings-ui-selftest.cjs" >/dev/null 2>&1 && node "$PROJECT_ROOT/scripts/voice-recordings-ui-selftest.cjs" >/dev/null 2>&1; then
        ok 'Voice, microphone toggle and recordings verified in browser.'
      else
        fail 'Voice: UI test failed.'
      fi
    else
      warn 'Voice: UI test skipped (Playwright for Node.js is not installed).'
    fi
    # 0.7.28: Settings > Speicher (overview, Dateien with project folders, Bilder, menu, deleting, full storage); needs Playwright for Node.
    if node -e "require('playwright')" >/dev/null 2>&1; then
      if node "$PROJECT_ROOT/scripts/storage-ui-selftest.cjs" >/dev/null 2>&1; then
        ok 'Storage: overview, files, images and deletion verified in browser.'
      else
        fail 'Storage: UI test failed.'
      fi
    else
      warn 'Storage: UI test skipped (Playwright for Node.js is not installed).'
    fi
    # 0.7.29: Settings > Tastatur (recording, clashes, reset) and the shortcuts in the chat; needs Playwright for Node.
    if node -e "require('playwright')" >/dev/null 2>&1; then
      if node "$PROJECT_ROOT/scripts/keyboard-shortcuts-ui-selftest.cjs" >/dev/null 2>&1; then
        ok 'Keyboard: record, test and reset shortcuts, and execute them in chat, verified in browser.'
      else
        fail 'Keyboard: UI test failed.'
      fi
    else
      warn 'Keyboard: UI test skipped (Playwright for Node.js is not installed).'
    fi
    # 0.7.30: Settings > Nutzung (Uebersicht, Analysen, charts); needs Playwright for Node.
    if node -e "require('playwright')" >/dev/null 2>&1; then
      if node "$PROJECT_ROOT/scripts/usage-ui-selftest.cjs" >/dev/null 2>&1; then
        ok 'Usage: overview and analytics verified in browser.'
      else
        fail 'Usage: UI test failed.'
      fi
    else
      warn 'Usage: UI test skipped (Playwright for Node.js is not installed).'
    fi
    # 0.7.32: Settings > Datenkontrollen (confirmations, typed word, results); needs Playwright for Node.
    if node -e "require('playwright')" >/dev/null 2>&1; then
      if node "$PROJECT_ROOT/scripts/data-controls-ui-selftest.cjs" >/dev/null 2>&1; then
        ok 'Data controls: archive, delete and export verified in browser.'
      else
        fail 'Data controls: UI test failed.'
      fi
    else
      warn 'Data controls: UI test skipped (Playwright for Node.js is not installed).'
    fi
    # 0.7.33: the CoreAI theme over every settings page, every page in the main column, Allgemein without
    # duplicates, SoftGlass untouched; needs Playwright for Node.
    if node -e "require('playwright')" >/dev/null 2>&1; then
      if node "$PROJECT_ROOT/scripts/settings-theme-ui-selftest.cjs" >/dev/null 2>&1; then
        ok 'Settings: CoreAI theme and page layout verified in browser.'
      else
        fail 'Settings: Theme-UI test failed.'
      fi
    else
      warn 'Settings: Theme-UI test skipped (Playwright for Node.js is not installed).'
    fi
    # 0.7.38: Deep Research in the chat (plan card, countdown, running, report view); needs Playwright for Node.
    if node -e "require('playwright')" >/dev/null 2>&1; then
      if node "$PROJECT_ROOT/scripts/deep-research-ui-selftest.cjs" >/dev/null 2>&1; then
        ok 'Deep Research: plan, progress and report verified in browser.'
      else
        fail 'Deep Research: UI test failed.'
      fi
    else
      warn 'Deep Research: UI test skipped (Playwright for Node.js is not installed).'
    fi
    # Project UX regression: directory, direct open, mobile details, persistence and permissions.
    if node "$PROJECT_ROOT/scripts/project-ui-selftest.cjs" >/dev/null 2>&1; then
      ok 'Projects: directory, navigation and scoped descriptions verified.'
    else
      fail 'Projects: UI contract test failed.'
    fi
    # 0.7.69 (P09): the tab Dateien on the project page; needs Playwright for Node.
    if node -e "require('playwright')" >/dev/null 2>&1; then
      if node "$PROJECT_ROOT/scripts/project-files-ui-selftest.cjs" >/dev/null 2>&1; then
        ok 'Project files: tree, editor, history, comparison and conflicts verified in browser.'
      else
        fail 'Project files: UI test failed.'
      fi
    else
      warn 'Project files: UI test skipped (Playwright for Node.js is not installed).'
    fi
    # 0.7.74 (P09 C): the branch button and the line after the branch point; needs Playwright for Node.
    if node -e "require('playwright')" >/dev/null 2>&1; then
      if node "$PROJECT_ROOT/scripts/chat-branch-ui-selftest.cjs" >/dev/null 2>&1; then
        ok 'Chat branches: button and origin label verified in browser.'
      else
        fail 'Chat branche: UI test failed.'
      fi
    else
      warn 'Chat branche: UI test skipped (Playwright for Node.js is not installed).'
    fi
    # 0.7.75 (P10 A): the preview in the tab Dateien; needs Playwright for Node and php.
    if node -e "require('playwright')" >/dev/null 2>&1 && command -v php >/dev/null 2>&1; then
      if node "$PROJECT_ROOT/scripts/project-preview-ui-selftest.cjs" >/dev/null 2>&1; then
        ok 'Project preview: page, scripts, data, line-numbered errors and reload verified in browser.'
      else
        fail 'Project preview: UI test failed.'
      fi
    else
      warn 'Project preview: UI test skipped (Playwright for Node.js or PHP is not installed).'
    fi
    # 0.7.77 (P10 B): the panel Ausfuehren in the tab Dateien; needs Playwright for Node.
    if node -e "require('playwright')" >/dev/null 2>&1; then
      if node "$PROJECT_ROOT/scripts/project-runs-ui-selftest.cjs" >/dev/null 2>&1; then
        ok 'Project runs: command, output, exit code, cancellation and Python toggle verified in browser.'
      else
        fail 'Project runs: UI test failed.'
      fi
    else
      warn 'Project runs: UI test skipped (Playwright for Node.js is not installed).'
    fi
    # 0.7.80 (P11 A): the panel Versionen in the tab Dateien; needs Playwright for Node.
    if node -e "require('playwright')" >/dev/null 2>&1; then
      if node "$PROJECT_ROOT/scripts/project-vcs-ui-selftest.cjs" >/dev/null 2>&1; then
        ok 'Project versions: save, working states, restore and Git ZIP verified in browser.'
      else
        fail 'Project versions: UI test failed.'
      fi
    else
      warn 'Project versions: UI test skipped (Playwright for Node.js is not installed).'
    fi
    # 0.7.81 (P11 B): merging with conflicts in the panel Versionen; needs Playwright for Node.
    if node -e "require('playwright')" >/dev/null 2>&1; then
      if node "$PROJECT_ROOT/scripts/project-merge-ui-selftest.cjs" >/dev/null 2>&1; then
        ok 'Merge: preview, conflicts with choices, custom text and two parents verified in browser.'
      else
        fail 'Merge: UI test failed.'
      fi
    else
      warn 'Merge: UI test skipped (Playwright for Node.js is not installed).'
    fi
    # 0.8.11: Admin Core > Operations > External adapter (key pasted once, sealed, connection test).
    # 0.8.15: the provider's Cores, Save adapter, releases, External Cores in the chat's Core menu.
    if node -e "require('playwright')" >/dev/null 2>&1; then
      if node "$PROJECT_ROOT/scripts/external-provider-ui-selftest.cjs" >/dev/null 2>&1; then
        ok 'External adapter: key entry, provider Cores, releases, Core menu and connection test verified in browser.'
      else
        fail 'External adapter: UI test failed.'
      fi
    else
      warn 'External adapter: UI test skipped (Playwright for Node.js is not installed).'
    fi
    # 0.7.90 (P13 A): history, comparison, restoring, conflicts, export and import in Settings > Skills.
    if node -e "require('playwright')" >/dev/null 2>&1; then
      if node "$PROJECT_ROOT/scripts/skill-revisions-ui-selftest.cjs" >/dev/null 2>&1; then
        ok 'Skills: history, comparison, restoring, conflicts, export and import verified in browser.'
      else
        fail 'Skill revisions: UI test failed.'
      fi
    else
      warn 'Skill revisions: UI test skipped (Playwright for Node.js is not installed).'
    fi
    # 0.7.84 (P12 B): Gemeinsam, precedence, sharing, and "Skills für diesen Chat" in the + menu.
    if node -e "require('playwright')" >/dev/null 2>&1; then
      if node "$PROJECT_ROOT/scripts/skill-sharing-ui-selftest.cjs" >/dev/null 2>&1; then
        ok 'Skills: shared skills, priority, sharing and skills fixed to this chat verified in browser.'
      else
        fail 'Shared skills: UI test failed.'
      fi
    else
      warn 'Shared skills: UI test skipped (Playwright for Node.js is not installed).'
    fi
    # 0.7.83 (P12 A): Settings > Skills: own skills, companion files, the built-in library.
    if node -e "require('playwright')" >/dev/null 2>&1; then
      if node "$PROJECT_ROOT/scripts/skill-packages-ui-selftest.cjs" >/dev/null 2>&1; then
        ok 'Skills: own skills with companion files, prerequisites, scope and bundled skills verified in browser.'
      else
        fail 'Skills: UI test failed.'
      fi
    else
      warn 'Skills: UI test skipped (Playwright for Node.js is not installed).'
    fi
    # 0.7.82 (P11 C): the cards of Mara’s files: preview of the same version, Behalten, Loeschen, notes.
    if node -e "require('playwright')" >/dev/null 2>&1; then
      if node "$PROJECT_ROOT/scripts/artifact-lifecycle-ui-selftest.cjs" >/dev/null 2>&1; then
        ok 'Mara’s files: version-matched preview, keep, delete with notice and retention verified in browser.'
      else
        fail 'Mara’s files: UI test failed.'
      fi
    else
      warn 'Mara’s files: UI test skipped (Playwright for Node.js is not installed).'
    fi
    # 0.7.78 (P10 C): the panel Prozesse and the server preview; needs Playwright for Node and php.
    if node -e "require('playwright')" >/dev/null 2>&1 && command -v php >/dev/null 2>&1; then
      if node "$PROJECT_ROOT/scripts/project-procs-ui-selftest.cjs" >/dev/null 2>&1; then
        ok 'Project processes: start, output, input, fetch-based server preview, stop and Python toggle verified in browser.'
      else
        fail 'Project processes: UI test failed.'
      fi
    else
      warn 'Project processes: UI test skipped (Playwright for Node.js or PHP is not installed).'
    fi
    # 0.7.1: Settings > Pets and the pet in Mara’s work line; needs Playwright for Node.
    if node -e "require('playwright')" >/dev/null 2>&1; then
      if node "$PROJECT_ROOT/scripts/pets-ui-selftest.cjs" >/dev/null 2>&1; then
        ok 'Pets verified in browser.'
      else
        fail 'Pets: UI test failed.'
      fi
    else
      warn 'Pets: UI test skipped (Playwright for Node.js is not installed).'
    fi
    # 0.7.0: Settings > Personalisierung after the reference; needs Playwright for Node.
    if node -e "require('playwright')" >/dev/null 2>&1; then
      if node "$PROJECT_ROOT/scripts/personalization-ui-selftest.cjs" >/dev/null 2>&1; then
        ok 'Personalization verified in browser.'
      else
        fail 'Personalization: UI test failed.'
      fi
    else
      warn 'Personalization: UI test skipped (Playwright for Node.js is not installed).'
    fi
    # 0.6.86: Konto (name, Benutzername dialog, e-mail, plan page, Erstellerprofil planned); needs Playwright for Node.
    if node -e "require('playwright')" >/dev/null 2>&1; then
      if node "$PROJECT_ROOT/scripts/account-ui-selftest.cjs" >/dev/null 2>&1; then
        ok 'Account: name, username, email, plan and creator profile verified in browser.'
      else
        fail 'Account UI test failed.'
      fi
    else
      warn 'Account UI test skipped (Playwright for Node.js is not installed).'
    fi
    # 0.5.83: update window (Inhaber) and "Bitte auf Neustart warten"; needs Playwright for Node.
    if node -e "require('playwright')" >/dev/null 2>&1; then
      if node "$PROJECT_ROOT/scripts/update-ui-selftest.cjs" >/dev/null 2>&1; then
        ok 'Update window: owner’s gold dialog, Later, shared progress bar and reload after restart verified.'
      else
        fail 'Update window: UI test failed.'
      fi
    else
      warn 'Update window: UI test skipped (Playwright for Node.js is not installed).'
    fi
    # 0.6.1: speaker button and auto-read switch; needs Playwright for Node.
    if node -e "require('playwright')" >/dev/null 2>&1; then
      if node "$PROJECT_ROOT/scripts/voice-read-ui-selftest.cjs" >/dev/null 2>&1; then
        ok 'Read aloud: button, sentence-by-sentence preloading, stop, auto-read and no guest button verified.'
      else
        fail 'Read aloud: UI test failed.'
      fi
    else
      warn 'Read aloud: UI test skipped (Playwright for Node.js is not installed).'
    fi
    # 0.6.14: microphone button in the input field; needs Playwright for Node.
    if node -e "require('playwright')" >/dev/null 2>&1; then
      if node "$PROJECT_ROOT/scripts/voice-input-ui-selftest.cjs" >/dev/null 2>&1; then
        ok 'Voice messages: microphone button, immediate send, waiting for Mara, silence, transcript line and no guest button verified.'
      else
        fail 'Voice input: UI test failed.'
      fi
    else
      warn 'Voice input: UI test skipped (Playwright for Node.js is not installed).'
    fi
    # 0.6.31: the live conversation (P20.4); needs Playwright for Node.
    if node -e "require('playwright')" >/dev/null 2>&1; then
      if node "$PROJECT_ROOT/scripts/voice-live-ui-selftest.cjs" >/dev/null 2>&1; then
        ok 'Live voice: button next to microphone, fullscreen, voice-message rounds, read aloud, mute, interrupt and end verified.'
      else
        fail 'Live voice: UI test failed.'
      fi
    else
      warn 'Live voice: UI test skipped (Playwright for Node.js is not installed).'
    fi
    # 0.6.19: chat list in the drawer, buttons whole and large enough; needs Playwright for Node.
    if node -e "require('playwright')" >/dev/null 2>&1; then
      if node "$PROJECT_ROOT/scripts/session-list-ui-selftest.cjs" >/dev/null 2>&1; then
        ok 'Chat list: fully visible, adequately sized buttons (mouse/touch, day/night) verified.'
      else
        fail 'Chat list: UI test failed.'
      fi
    else
      warn 'Chat list: UI test skipped (Playwright for Node.js is not installed).'
    fi
    # 0.6.22: names and terms for voice messages in the KI-Profil; needs Playwright for Node.
    if node -e "require('playwright')" >/dev/null 2>&1; then
      if node "$PROJECT_ROOT/scripts/voice-terms-ui-selftest.cjs" >/dev/null 2>&1; then
        ok 'Voice-message terms: AI-profile list, save, cleanup, no guest field, day/night and phone verified.'
      else
        fail 'Voice-message terms: UI test failed.'
      fi
    else
      warn 'Voice-message terms: UI test skipped (Playwright for Node.js is not installed).'
    fi
    # 0.5.94: Stimmproben card (Inhaber only); needs Playwright for Node.
    if node -e "require('playwright')" >/dev/null 2>&1; then
      if node "$PROJECT_ROOT/scripts/voice-samples-ui-selftest.cjs" >/dev/null 2>&1; then
        ok 'Voice previews: licensed voices, sentence playback, test runs, owner-only and phone width verified.'
      else
        fail 'Voice previews: UI test failed.'
      fi
    else
      warn 'Voice previews: UI test skipped (Playwright for Node.js is not installed).'
    fi
    if node "$PROJECT_ROOT/scripts/webui-resilience-selftest.cjs" >/dev/null 2>&1; then
      ok 'WebUI: cancellable loading, reconnect without duplicate requests, backoff and long chats verified.'
    else
      fail 'WebUI: resilience self-test failed.'
    fi
    if node "$PROJECT_ROOT/scripts/chat-follow-selftest.cjs" >/dev/null 2>&1; then
      ok 'Chat scroll: manual reading, work indicators and jump to latest reply verified.'
    else
      fail 'Chat scroll: reader position or jump button regression detected.'
    fi
    if node "$PROJECT_ROOT/scripts/long-chat-selftest.cjs" >/dev/null 2>&1; then
      ok 'Long texts: Markdown attachment and original bytes verified.'
    else
      fail 'Long texts: automatic attachment is defective.'
    fi
  else
    warn 'Node.js is not installed: optional response-duration JavaScript test skipped.'
  fi

  # Test the actual PHP runtime directly. Under pipefail, a "php -m |
  # grep -q" probe can mistake an early-closing pipe for a missing module.
  for extension_name in curl dom gd mbstring pdo_mysql zip; do
    if compose exec -T php php -r 'exit(extension_loaded($argv[1]) ? 0 : 1);' "$extension_name" 2>/dev/null; then
      ok "PHP extension enabled: $extension_name"
    else
      fail "PHP extension missing: $extension_name"
      if [[ "$extension_name" == 'pdo_mysql' ]]; then
        warn 'PDO driver is missing in the RUNNING PHP container. Run the installer again to rebuild the image and recreate PHP.'
      fi
    fi
  done

  if compose exec -T php sh -ec '
      find api scripts tools -type f -name "*.php" -exec sh -c '\''
        for file do
          if ! php -l "$file" >/dev/null 2>&1; then
            printf "[PHP-SYNTAX-ERROR] %s\\n" "$file" >&2
            php -l "$file" >&2 || true
            exit 1
          fi
        done
      '\'' sh {} +
    '; then
    ok 'All packaged PHP files pass syntax checking.'
  else
    fail 'At least one packaged PHP file has a syntax error.'
  fi

  thinking_selftest=''
  if thinking_selftest="$(compose exec -T -u 33:33 php php scripts/thinking-sanitize-selftest.php 2>&1)"; then
    thinking_selftest="${thinking_selftest//$'\r'/}"
    ok 'Thinking settings self-test passed.'
  else
    thinking_selftest="${thinking_selftest//$'\r'/}"
    fail "$thinking_selftest"
  fi

  reply_selftest=''
  if reply_selftest="$(compose exec -T -u 33:33 php php scripts/reply-pipeline-selftest.php 2>&1)"; then
    reply_selftest="${reply_selftest//$'\r'/}"
    ok 'Reply pipeline self-test passed.'
  else
    reply_selftest="${reply_selftest//$'\r'/}"
    fail "$reply_selftest"
  fi

  session_selftest=''
  if session_selftest="$(compose exec -T -u 33:33 php php scripts/session-selftest.php 2>&1)"; then
    session_selftest="${session_selftest//$'\r'/}"
    ok 'Chat sessions self-test passed.'
  else
    session_selftest="${session_selftest//$'\r'/}"
    fail "$session_selftest"
  fi


  personal_selftest=''
  if personal_selftest="$(compose exec -T -u 33:33 php php scripts/personalization-selftest.php 2>&1)"; then
    ok 'Personalization self-test passed.'
  else
    fail "$personal_selftest"
  fi

  if skills_selftest="$(compose exec -T -u 33:33 php php scripts/skills-selftest.php 2>&1)"; then
    ok 'Skill library self-test passed.'
  else
    fail "$skills_selftest"
  fi

  profile_knowledge_selftest=''
  if profile_knowledge_selftest="$(compose exec -T -u 33:33 php php scripts/profile-knowledge-selftest.php 2>&1)"; then
    profile_knowledge_selftest="${profile_knowledge_selftest//$'\r'/}"
    ok 'Profile and knowledge self-test passed.'
  else
    profile_knowledge_selftest="${profile_knowledge_selftest//$'\r'/}"
    fail "$profile_knowledge_selftest"
  fi

  attachment_pipeline_selftest=''
  if attachment_pipeline_selftest="$(compose exec -T -u 33:33 php php scripts/attachment-pipeline-selftest.php 2>&1)"; then
    attachment_pipeline_selftest="${attachment_pipeline_selftest//$'\r'/}"
    ok 'Attachment pipeline self-test passed.'
  else
    attachment_pipeline_selftest="${attachment_pipeline_selftest//$'\r'/}"
    fail "$attachment_pipeline_selftest"
  fi

  python_worker_selftest=''
  if python_worker_selftest="$(compose exec -T -u 33:33 php php scripts/python-worker-selftest.php 2>&1)"; then
    python_worker_selftest="${python_worker_selftest//$'\r'/}"
    ok 'Python worker self-test passed.'
  else
    python_worker_selftest="${python_worker_selftest//$'\r'/}"
    fail "$python_worker_selftest"
  fi

  account_security_selftest=''
  if account_security_selftest="$(compose exec -T -u 33:33 php php scripts/account-security-selftest.php 2>&1)"; then
    account_security_selftest="${account_security_selftest//$'\r'/}"
    ok 'Account security self-test passed.'
  else
    account_security_selftest="${account_security_selftest//$'\r'/}"
    fail "$account_security_selftest"
  fi

  logo_selftest=''
  if logo_selftest="$(compose exec -T -u 33:33 php php scripts/logo-alpha-selftest.php 2>&1)"; then
    logo_selftest="${logo_selftest//$'\r'/}"
    ok 'Branding and logo self-test passed.'
  else
    logo_selftest="${logo_selftest//$'\r'/}"
    fail "$logo_selftest"
  fi

  frontend_selftest=''
  if frontend_selftest="$(compose exec -T -u 33:33 php php scripts/frontend-regression-selftest.php 2>&1)"; then
    frontend_selftest="${frontend_selftest//$'\r'/}"
    ok 'Frontend regression self-test passed.'
  else
    frontend_selftest="${frontend_selftest//$'\r'/}"
    fail "$frontend_selftest"
  fi

  md_preview_test=''
  if md_preview_test="$(compose exec -T -u 33:33 php php scripts/markdown-preview-selftest.php 2>&1)"; then
    ok 'Markdown preview self-test passed.'
  else
    fail "Markdown preview: ${md_preview_test:-Unknown error}"
  fi

  message_actions_selftest=''
  if message_actions_selftest="$(compose exec -T -u 33:33 php php scripts/message-actions-selftest.php 2>&1)"; then
    message_actions_selftest="${message_actions_selftest//$'\r'/}"
    ok 'Message actions self-test passed.'
  else
    message_actions_selftest="${message_actions_selftest//$'\r'/}"
    fail "$message_actions_selftest"
  fi

  # Runtime hosts do not require Node.js to operate CoreAI. Keep a real
  # PHP feedback contract check on every install; run the fuller JavaScript
  # contract as well whenever the optional Node toolchain is available.
  feedback_ui_selftest=''
  if feedback_ui_selftest="$(compose exec -T -u 33:33 php php scripts/feedback-ui-selftest.php 2>&1)"; then
    ok 'Feedback: thumb colors, persistence and Mara’s no-thinking reaction (PHP contract) verified.'
  else
    fail "Feedback: UI/backend contract (PHP) failed: ${feedback_ui_selftest:-Unknown error}"
  fi
  if command -v node >/dev/null 2>&1; then
    feedback_node_selftest=''
    if feedback_node_selftest="$(node "$PROJECT_ROOT/scripts/feedback-ui-selftest.cjs" 2>&1)"; then
      ok 'Feedback: extended Node.js UI/backend contract verified.'
    else
      fail "Feedback: Node.js UI/backend contract failed: ${feedback_node_selftest:-Unknown error}"
    fi
  else
    warn 'Node.js is optional and not installed: Feedback PHP contract verified, extra Node.js test skipped.'
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
      ok 'Private live-browser frame table is ready.'
    else
      fail 'Private live-browser frame table missing. Restart the browser worker.'
    fi
  fi

  # 0.6.1: Mara’s voice. Optional: missing or still loading is a hint, never a failure.
  if is_enabled "${COREUI_INSTALL_VOICE:-1}"; then
    if service_running voice; then
      voice_health="$(curl -fsS --max-time 5 "http://127.0.0.1:${COREUI_VOICE_PORT:-18790}/health" 2>/dev/null || true)"
      if [[ "$voice_health" == *'"ready": true'* ]]; then
        ok 'Voice service ready: Mara can read aloud using Eve.'
      elif [[ "$voice_health" == *'"loading": true'* ]]; then
        warn 'Voice service laedt Eve noch (beim ersten Start etwa 0,7 GB); Read aloud folgt von selbst.'
      else
        warn 'Voice service is running but is not ready. Logs: scripts/stack.sh logs voice'
      fi
    else
      warn 'Voice service is not running; CoreAI continues without read-aloud functionality. Start: scripts/stack.sh up -d voice'
    fi
  fi

  LOCK_NAMESPACE="$(compose exec -T php php -r \
    'require "/var/www/coreui/api/config.php"; echo STU_EMBER_LOCK_NAMESPACE;' 2>/dev/null || true)"
  LOCK_NAMESPACE="${LOCK_NAMESPACE//$'\r'/}"
  if [[ -n "$LOCK_NAMESPACE" && "$LOCK_NAMESPACE" != 'ember' && "$LOCK_NAMESPACE" != 'stu' ]]; then
    ok "Eigener MariaDB-Lock-Namespace aktiv: $LOCK_NAMESPACE"
  else
    fail 'No secure CoreAI: TCI Interface lock namespace is active.'
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
      ok "Container writable directory ready: ${writable_dir#/var/www/coreui/}"
    else
      fail "Container writable directory is not ready: ${writable_dir#/var/www/coreui/}"
    fi
  done

  # 0.8.34: PHP-FPM saves Mara's images in var/console_media/images (made on the first image). A folder
  # left to root (earlier preflights ran the image self-tests as root) made every image fail with storage_failed.
  if compose exec -T -u 33:33 php sh -c 'test ! -e /var/www/coreui/var/console_media/images || test -w /var/www/coreui/var/console_media/images' >/dev/null 2>&1; then
    ok 'Image folder is writable for PHP: var/console_media/images'
  else
    fail 'Image folder var/console_media/images is not writable for PHP. Run: chown -R 33:33 var/console_media'
  fi

  # 0.5.83: the update window needs the host units (installed by scripts/install.sh).
  if command -v systemctl >/dev/null 2>&1 && [[ -d /run/systemd/system ]]; then
    if systemctl is-active --quiet coreai-update.path && systemctl is-active --quiet coreai-update-check.timer; then
      ok 'Update service active: checks for new merges every 10 minutes, launch from the Update window.'
    else
      fail 'Update service is not active (coreai-update.path or coreai-update-check.timer). Run scripts/install.sh again.'
    fi
  else
    warn 'systemd unavailable: the Update window will not have a host service.'
  fi

  video_selftest=''
  if video_selftest="$(compose exec -T -u 33:33 php php scripts/video-selftest.php 2>&1)"; then
    video_selftest="${video_selftest//$'\r'/}"
    ok 'Video processing self-test passed.'
  else
    video_selftest="${video_selftest//$'\r'/}"
    fail "$video_selftest"
  fi

  pdf_selftest=''
  if pdf_selftest="$(compose exec -T -u 33:33 php php scripts/pdf-selftest.php 2>&1)"; then
    pdf_selftest="${pdf_selftest//$'\r'/}"
    ok 'PDF processing self-test passed.'
  else
    pdf_selftest="${pdf_selftest//$'\r'/}"
    fail "$pdf_selftest"
  fi
fi

if curl -fsS --max-time 10 "http://127.0.0.1:${COREUI_HTTP_PORT}/api/health.php" >/dev/null 2>&1; then
  ok "CoreAI: TCI Interface-Healthcheck antwortet auf Loopback-Port ${COREUI_HTTP_PORT}."
else
  fail "CoreAI: TCI Interface health check is not responding on port ${COREUI_HTTP_PORT}."
fi

# Drain each HTTP body completely: grep -q closes early and makes curl fail under pipefail.
if curl -fsS --max-time 10 "http://127.0.0.1:${COREUI_HTTP_PORT}/settings.html" \
    | grep -F 'EINSTELLUNGEN' >/dev/null; then
  ok 'CoreAI: TCI Interface settings are served by the isolated web server.'
else
  fail 'CoreAI: TCI Interface settings are unreachable. Run scripts/stack.sh refresh-runtime, then scripts/stack.sh restart web.'
fi

# 0.6.71: Admin Core is a separate page, authorized per account and server-side API.
if curl -fsS --max-time 10 "http://127.0.0.1:${COREUI_HTTP_PORT}/settings.html" | grep -F 'id="adminSettingsLink"' >/dev/null \
    && curl -fsS --max-time 10 "http://127.0.0.1:${COREUI_HTTP_PORT}/admin/index.html" | grep -F 'id="adminAccessGate"' >/dev/null; then
  ok 'Settings with Roles link and separate access-protected Admin Core page are served.'
else
  fail 'Admin Core page or separate Settings are unreachable.'
fi

SESSION_ROUTE_STATUS="$(curl -sS --max-time 10 -o /dev/null -w '%{http_code}' \
  "http://127.0.0.1:${COREUI_HTTP_PORT}/api/console_messages.php" 2>/dev/null || true)"
if [[ "$SESSION_ROUTE_STATUS" == '401' || "$SESSION_ROUTE_STATUS" == '400' ]]; then
  ok 'Authenticated session history endpoint is reachable through the CoreAI: TCI Interface web server.'
else
  fail "Session history endpoint is routed incorrectly (HTTP ${SESSION_ROUTE_STATUS:-000})."
fi

for private_route in profile knowledge profile_media account_security account_export models; do
  route_status="$(curl -sS --max-time 10 -o /dev/null -w '%{http_code}' \
    "http://127.0.0.1:${COREUI_HTTP_PORT}/api/${private_route}.php" 2>/dev/null || true)"
  if [[ "$route_status" == '401' ]]; then
    ok "Privater CoreAI: TCI Interface endpoint korrekt geroutet: ${private_route}.php"
  else
    fail "CoreAI: TCI Interface endpoint ${private_route}.php is routed incorrectly (HTTP ${route_status:-000})."
  fi
done

if command -v ollama >/dev/null 2>&1 \
    && ollama show "${COREUI_MODEL_NAME}" >/dev/null 2>&1; then
  ok "Getrenntes Ollama model available: ${COREUI_MODEL_NAME}"
  model_audit=''
  if model_audit="$(compose exec -T php php scripts/model-live-audit.php "${COREUI_MODEL_NAME}" 2>&1)"; then
    ok 'Installed Ollama model configuration audit passed.'
  else
    fail "${model_audit:-Ollama Modelfile could not be verified.}"
  fi
else
  fail "CoreAI: TCI Interface-Ollama-Modell missing: ${COREUI_MODEL_NAME}"
fi

generator_audit=''
if generator_audit="$(python3 -I "$PROJECT_ROOT/scripts/ensure-generator-core.py" --check 2>&1)"; then
  ok 'TCI Core Generator audit passed.'
else
  generator_code=$?
  if (( generator_code == 3 )); then
    warn "$generator_audit"
  else
    fail "$generator_audit"
  fi
fi

if is_enabled "${COREUI_INSTALL_SEARXNG:-0}"; then
  if curl -fsS --max-time 10 \
      "http://127.0.0.1:${COREUI_SEARXNG_PORT}/search?q=ember&format=json" >/dev/null 2>&1; then
    ok "The dedicated SearXNG JSON endpoint responds on port ${COREUI_SEARXNG_PORT}."
  else
    fail "Dedicated SearXNG JSON search is not responding on port ${COREUI_SEARXNG_PORT}."
  fi
fi

if [[ "${COREUI_BIND_ADDRESS:-127.0.0.1}" == '127.0.0.1' ]]; then
  ok 'Web access is restricted to loopback by default.'
else
  warn 'CoreAI: TCI Interface is deliberately bound to 0.0.0.0. Verify firewall and TLS separately.'
fi

if [[ -L /etc/nginx/sites-enabled/default || -e /etc/nginx/sites-enabled/default ]]; then
  ok 'An existing Nginx default site was not removed.'
else
  warn 'No Nginx default site found. CoreAI: TCI Interface does not change this state.'
fi

if (( FAILURES > 0 )); then
  printf '\nPreflight failed: %d issue(s).\n' "$FAILURES" >&2
  exit 1
fi

printf '\nPreflight successful. CoreAI: TCI Interface is running in isolated parallel mode.\n'

