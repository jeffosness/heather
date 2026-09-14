<?php
declare(strict_types=1);

require_once __DIR__ . '/users_service.php';
require_once __DIR__ . '/auth.php';

/**
 * Outbound email pipeline. Currently used by the password-reset flow;
 * feature notifications can hook send_admin_notification() as they land.
 *
 * When SMTP is configured in /admin/notifications.php (host + user + pass)
 * we speak SMTP directly. Otherwise we fall back to PHP mail(), which is
 * almost always broken on IONOS shared hosting — so real deployments
 * should always fill in SMTP.
 *
 * Every send attempt writes one line to notifications.log so any silent
 * failure has a paper trail visible in the admin UI (Notifications page).
 */

const NOTIFY_FROM_EMAIL_DEFAULT = 'no-reply@heather.osness.org';
const NOTIFY_FROM_NAME_DEFAULT  = 'Heather Osness';
const NOTIFY_LOG_FILE           = APP_PROTECTED_DIR . DIRECTORY_SEPARATOR . 'notifications.log';
const NOTIFY_LOG_MAX_LINES      = 500;
const SMTP_DEFAULT_HOST         = 'smtp.ionos.com';

/**
 * Resolved From address + display name. Prefers what an admin set in
 * /admin/notifications.php, falls back to sensible defaults.
 */
function notify_from(): array
{
    $s = app_settings();
    $email = trim((string) ($s['notification_from_email'] ?? ''));
    $name  = trim((string) ($s['notification_from_name']  ?? ''));
    if ($email === '') $email = NOTIFY_FROM_EMAIL_DEFAULT;
    if ($name === '')  $name  = trim((string) ($s['site_name'] ?? '')) ?: NOTIFY_FROM_NAME_DEFAULT;
    return ['email' => $email, 'name' => $name];
}

/**
 * Effective SMTP config with defaults applied. Host defaults to IONOS
 * (this site's actual host); username defaults to the From address
 * (mailbox-based SMTP almost always uses the address as the login).
 * Password has no default — the one field that must be set explicitly.
 */
function notify_smtp_config(): array
{
    $s = app_settings();
    $sender = notify_from();
    return [
        'host'       => trim((string) ($s['smtp_host']       ?? '')) ?: SMTP_DEFAULT_HOST,
        'port'       => (int)         ($s['smtp_port']       ?? 587),
        'encryption' => trim((string) ($s['smtp_encryption'] ?? 'tls')) ?: 'tls',
        'username'   => trim((string) ($s['smtp_username']   ?? '')) ?: $sender['email'],
        'password'   => (string)      ($s['smtp_password']   ?? ''),
    ];
}

function notify_smtp_configured(): bool
{
    $c = notify_smtp_config();
    return $c['password'] !== '' && $c['host'] !== '' && $c['username'] !== '';
}

/**
 * Append one line to the notification log. Rolling tail so it never
 * grows unbounded.
 */
function notify_log(string $eventType, string $to, string $subject, bool $ok, string $note = ''): void
{
    $line = sprintf(
        "[%s] event=%s to=%s ok=%s%s subject=%s\n",
        date('Y-m-d H:i:s'),
        $eventType,
        $to,
        $ok ? 'yes' : 'no',
        $note !== '' ? ' note=' . str_replace(["\n", "\r"], ' ', $note) : '',
        str_replace(["\n", "\r"], ' ', $subject)
    );
    $dir = dirname(NOTIFY_LOG_FILE);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) return;
    if (is_file(NOTIFY_LOG_FILE) && filesize(NOTIFY_LOG_FILE) > 200_000) {
        $lines = @file(NOTIFY_LOG_FILE, FILE_IGNORE_NEW_LINES) ?: [];
        if (count($lines) > NOTIFY_LOG_MAX_LINES - 1) {
            $lines = array_slice($lines, -(NOTIFY_LOG_MAX_LINES - 1));
            @file_put_contents(NOTIFY_LOG_FILE, implode("\n", $lines) . "\n");
        }
    }
    @file_put_contents(NOTIFY_LOG_FILE, $line, FILE_APPEND | LOCK_EX);
}

function notify_log_tail(int $n = 30): array
{
    if (!is_file(NOTIFY_LOG_FILE)) return [];
    $lines = @file(NOTIFY_LOG_FILE, FILE_IGNORE_NEW_LINES) ?: [];
    if (count($lines) > $n) $lines = array_slice($lines, -$n);
    return array_reverse($lines);
}

/**
 * Speak SMTP directly. Supports STARTTLS on 587 and implicit SSL on 465;
 * AUTH LOGIN with base64 creds; dot-stuffing; unicode subjects via
 * mb_encode_mimeheader. Every failure logs which verb bounced so we can
 * debug from the admin without SSH.
 */
function notify_send_via_smtp(string $to, string $subject, string $body, string $eventType): bool
{
    $c = notify_smtp_config();
    $host = $c['host']; $port = $c['port']; $enc = $c['encryption'];
    $user = $c['username']; $pass = $c['password'];
    if ($host === '' || $user === '' || $pass === '') return false;

    $sender = notify_from();
    $connString = ($enc === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
    $errno = 0; $errstr = '';
    $sock = @stream_socket_client($connString, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$sock) {
        notify_log($eventType, $to, $subject, false, "smtp_connect_fail=$errstr (errno=$errno)");
        return false;
    }
    stream_set_timeout($sock, 15);

    $read = function () use ($sock): array {
        $lines = [];
        while (!feof($sock)) {
            $line = fgets($sock, 2048);
            if ($line === false) return $lines;
            $lines[] = rtrim($line, "\r\n");
            if (strlen($line) >= 4 && $line[3] === ' ') break;
        }
        return $lines;
    };
    $write = function (string $cmd) use ($sock): void { fwrite($sock, $cmd . "\r\n"); };
    $code  = function (array $reply): string { return $reply === [] ? '' : substr($reply[0], 0, 3); };
    $fail  = function (string $note) use ($sock, $eventType, $to, $subject) {
        notify_log($eventType, $to, $subject, false, $note);
        @fclose($sock);
        return false;
    };

    $greet = $read();
    if ($code($greet) !== '220') return $fail("smtp_greet=" . implode('|', $greet));

    $ehloHost = $_SERVER['SERVER_NAME'] ?? 'localhost';
    $write("EHLO $ehloHost");
    $r = $read();
    if ($code($r) !== '250') return $fail("smtp_ehlo=" . implode('|', $r));

    if ($enc === 'tls') {
        $write("STARTTLS");
        $r = $read();
        if ($code($r) !== '220') return $fail("smtp_starttls=" . implode('|', $r));
        if (!@stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            return $fail("smtp_tls_enable_fail");
        }
        $write("EHLO $ehloHost");
        $r = $read();
        if ($code($r) !== '250') return $fail("smtp_ehlo_after_tls=" . implode('|', $r));
    }

    $write("AUTH LOGIN");
    $r = $read();
    if ($code($r) !== '334') return $fail("smtp_auth_init=" . implode('|', $r));
    $write(base64_encode($user));
    $r = $read();
    if ($code($r) !== '334') return $fail("smtp_auth_user=" . implode('|', $r));
    $write(base64_encode($pass));
    $r = $read();
    if ($code($r) !== '235') return $fail("smtp_auth_pass=" . implode('|', $r));

    $write("MAIL FROM:<{$sender['email']}>");
    $r = $read();
    if ($code($r) !== '250') return $fail("smtp_from=" . implode('|', $r));

    $write("RCPT TO:<{$to}>");
    $r = $read();
    if (!in_array($code($r), ['250', '251'], true)) return $fail("smtp_rcpt=" . implode('|', $r));

    $write("DATA");
    $r = $read();
    if ($code($r) !== '354') return $fail("smtp_data=" . implode('|', $r));

    $encodedSubj = function_exists('mb_encode_mimeheader')
        ? mb_encode_mimeheader($subject, 'UTF-8', 'B')
        : $subject;
    $fromHeader = sprintf('%s <%s>', $sender['name'], $sender['email']);
    $msg = "From: {$fromHeader}\r\n"
         . "To: {$to}\r\n"
         . "Subject: {$encodedSubj}\r\n"
         . "MIME-Version: 1.0\r\n"
         . "Content-Type: text/plain; charset=UTF-8\r\n"
         . "Content-Transfer-Encoding: 8bit\r\n"
         . "Date: " . date('r') . "\r\n"
         . "X-Mailer: heather-osness-site\r\n"
         . "\r\n"
         . $body;
    $msg = str_replace(["\r\n", "\r", "\n"], ["\n", "\n", "\r\n"], $msg);
    $msg = preg_replace('/(^|\r\n)\./m', "$1..", $msg);
    fwrite($sock, $msg . "\r\n.\r\n");
    $r = $read();
    $ok = $code($r) === '250';
    if (!$ok) return $fail("smtp_deliver=" . implode('|', $r));

    $write("QUIT");
    @fclose($sock);
    notify_log($eventType, $to, $subject, true, 'via_smtp');
    return true;
}

/**
 * Send one email. Uses SMTP when configured, PHP mail() otherwise.
 */
function notify_send_one(string $to, string $subject, string $body, string $eventType = 'notification'): bool
{
    if (notify_smtp_configured()) {
        return notify_send_via_smtp($to, $subject, $body, $eventType);
    }
    if (!function_exists('mail')) {
        notify_log($eventType, $to, $subject, false, 'mail() unavailable');
        return false;
    }
    $sender = notify_from();
    $from = sprintf('%s <%s>', $sender['name'], $sender['email']);
    $headers = [
        'From: ' . $from,
        'Reply-To: ' . $sender['email'],
        'Content-Type: text/plain; charset=UTF-8',
        'MIME-Version: 1.0',
        'X-Mailer: heather-osness-site',
    ];
    error_clear_last();
    $ok = @mail($to, $subject, $body, implode("\r\n", $headers), '-f' . $sender['email']);
    $note = '';
    if (!$ok) {
        $lastErr = error_get_last();
        $note = 'mail_error=' . (is_array($lastErr) && !empty($lastErr['message'])
            ? $lastErr['message'] : 'mail() returned false with no PHP error captured');
    }
    notify_log($eventType, $to, $subject, (bool) $ok, $note);
    return (bool) $ok;
}
