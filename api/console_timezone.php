<?php
declare(strict_types=1);

/**
 * CoreAI private console only: a browser-provided IANA timezone is evaluated
 * per request, never inferred from the visitor's IP and never used for game
 * channels. No account-wide timezone or database migration is required.
 */
function coreui_console_timezone_normalise($raw): ?string {
  if (!is_string($raw)) return null;
  $zone = trim($raw);
  if (strlen($zone) > 64 || !preg_match('~^[A-Za-z0-9_+/-]{1,64}$~D', $zone)) return null;
  // DateTimeZone accepts offset strings and abbreviations. Permit ONLY named
  // IANA zones, to keep the supplied value out of arbitrary model instructions.
  if (!in_array($zone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) return null;
  return $zone;
}

function coreui_console_timezone_set($raw): void {
  $zone = coreui_console_timezone_normalise($raw);
  if ($zone === null) {
    unset($GLOBALS['COREUI_CONSOLE_CLIENT_TZ']);
    return;
  }
  $GLOBALS['COREUI_CONSOLE_CLIENT_TZ'] = $zone;
}
