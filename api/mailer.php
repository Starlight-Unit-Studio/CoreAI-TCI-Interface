<?php
// CoreAI: TCI Interface - minimal SMTP mailer (broad host compatibility)
//
// Why not `mail()`?
// - Some hosts throttle or misconfigure it.
// - SMTP credentials are more predictable.
//
// 0.6.82: the configuration comes from the server (api/config.local.php or the
// COREUI_SMTP_* / COREUI_MAIL_* environment) or, when the server sets nothing, from
// Admin Core > Betrieb > E-Mail-Versand (see api/mail_settings.php).
// Encryption: STARTTLS (usually 587), SSL/TLS from the first byte (usually 465), or none
// (only to a server on this machine or in the private network, never with a password).

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/mail_settings.php';

function stu_mail_enabled(?PDO $pdo = null): bool {
  try {
    return coreui_mail_ready(coreui_mail_effective($pdo));
  } catch (Throwable $e) {
    return false;
  }
}

/**
 * Sends a plain-text mail. $cfg is the configuration to use (Admin Core's mail test passes
 * the one it is checking); without it the effective configuration is used.
 * Throws Exception with a short code (smtp_connect_failed, smtp_tls_failed,
 * smtp_unexpected_NNN, smtp_no_response, mail_not_configured, mail_password_unreadable).
 */
function stu_send_mail(string $to, string $subject, string $textBody, ?array $cfg = null): void {
  $cfg = $cfg ?? coreui_mail_effective();
  if ($cfg !== null && empty($cfg['pass_readable'])) throw new Exception('mail_password_unreadable');
  if (!coreui_mail_ready($cfg)) throw new Exception('mail_not_configured');
  if (!filter_var($to, FILTER_VALIDATE_EMAIL) || preg_match('~[\r\n]~', $to)) throw new Exception('mail_recipient_invalid');

  $host = (string)$cfg['host'];
  $port = (int)$cfg['port'];
  $security = (string)$cfg['security'];
  $user = (string)$cfg['user'];
  $pass = (string)$cfg['pass'];
  $from = (string)$cfg['from'];
  $fromName = (string)($cfg['from_name'] ?: 'CoreAI: TCI Interface');
  $address = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? '[' . $host . ']' : $host;

  $fromHeader = stu_encode_header($fromName) === $fromName
    ? '"' . addcslashes($fromName, "\"\\") . '" <' . $from . '>'
    : stu_encode_header($fromName) . ' <' . $from . '>';

  $headers = [];
  $headers[] = 'From: ' . $fromHeader;
  $headers[] = 'To: <' . $to . '>';
  $headers[] = 'Subject: ' . stu_encode_header($subject);
  $headers[] = 'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000';
  $headers[] = 'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . (substr(strrchr($from, '@') ?: '@coreai.local', 1)) . '>';
  $headers[] = 'MIME-Version: 1.0';
  $headers[] = 'Content-Type: text/plain; charset=utf-8';
  $headers[] = 'Content-Transfer-Encoding: 8bit';

  $data = implode("\r\n", $headers) . "\r\n\r\n" . $textBody . "\r\n";

  $ctx = stream_context_create([
    'ssl' => [
      'verify_peer' => true,
      'verify_peer_name' => true,
      'allow_self_signed' => false,
      'peer_name' => $host,
    ]
  ]);

  $scheme = $security === 'tls' ? 'ssl' : 'tcp';
  $fp = @stream_socket_client("{$scheme}://{$address}:{$port}", $errno, $errstr, 12, STREAM_CLIENT_CONNECT, $ctx);
  if (!$fp) throw new Exception($security === 'tls' && $errno === 0 ? 'smtp_tls_failed' : 'smtp_connect_failed');
  stream_set_timeout($fp, 12);

  try {
    stu_smtp_expect($fp, 220);
    stu_smtp_cmd($fp, 'EHLO stu');
    stu_smtp_expect($fp, 250);

    if ($security === 'starttls') {
      stu_smtp_cmd($fp, 'STARTTLS');
      stu_smtp_expect($fp, 220);
      if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
        throw new Exception('smtp_tls_failed');
      }
      stu_smtp_cmd($fp, 'EHLO stu');
      stu_smtp_expect($fp, 250);
    }

    // AUTH LOGIN, only with a user name (an open relay on the local network needs none).
    if ($user !== '' && $security !== 'none') {
      stu_smtp_cmd($fp, 'AUTH LOGIN');
      stu_smtp_expect($fp, 334);
      stu_smtp_cmd($fp, base64_encode($user));
      stu_smtp_expect($fp, 334);
      stu_smtp_cmd($fp, base64_encode($pass));
      stu_smtp_expect($fp, 235);
    }

    stu_smtp_cmd($fp, 'MAIL FROM:<' . $from . '>');
    stu_smtp_expect($fp, 250);

    stu_smtp_cmd($fp, 'RCPT TO:<' . $to . '>');
    stu_smtp_expect($fp, [250, 251]);

    stu_smtp_cmd($fp, 'DATA');
    stu_smtp_expect($fp, 354);

    // Line endings to CRLF, then dot-stuffing (RFC 5321 4.5.2).
    $normal = preg_replace("/\r\n|\r|\n/", "\r\n", $data);
    $safe = preg_replace('/^\./m', '..', (string)$normal);
    fwrite($fp, $safe . ".\r\n");
    stu_smtp_expect($fp, 250);

    stu_smtp_cmd($fp, 'QUIT');
  } finally {
    fclose($fp);
  }
}

function stu_encode_header(string $s): string {
  // RFC 2047 (simple, UTF-8 base64)
  if (preg_match('/^[\x20-\x7E]+$/', $s)) return $s;
  return '=?UTF-8?B?' . base64_encode($s) . '?=';
}

function stu_smtp_cmd($fp, string $cmd): void {
  fwrite($fp, $cmd . "\r\n");
}

function stu_smtp_expect($fp, $codes): void {
  $codes = (array)$codes;
  $line = '';
  while (!feof($fp)) {
    $line = fgets($fp, 8192);
    if ($line === false) break;
    // Multi-line responses have '-' after the code.
    if (preg_match('/^(\d{3})([ -])/', $line, $m)) {
      $code = (int)$m[1];
      $more = ($m[2] === '-');
      if (!$more) {
        if (!in_array($code, $codes, true)) {
          throw new Exception('smtp_unexpected_' . $code);
        }
        return;
      }
    }
  }
  throw new Exception('smtp_no_response');
}
