<?php
// =============================================
// helpers.php — Shared security helpers
// Sessions, CSRF, role guard, rate limiting,
// login lockout and pending-booking expiry.
// Include AFTER db.php:  include 'db.php'; require_once 'helpers.php';
// =============================================

// ---------- TUNABLE SETTINGS ----------
define('DEFAULT_BUS_SEATS', 45);              // only used if a bus row has no total_seats

define('LOGIN_MAX_ATTEMPTS',   5);            // failed logins allowed ...
define('LOGIN_WINDOW_SECONDS', 900);          // ... within this window (15 min)
define('LOGIN_LOCKOUT_SECONDS', 300);         // lock for this long after the last failure (5 min)

define('BOOKING_MAX_PER_IP',     12);         // seats one IP may book ...
define('BOOKING_WINDOW_SECONDS', 3600);       // ... per hour
define('MAX_PENDING_PER_PERSON', 6);          // unconfirmed seats per phone number / ID number

define('CONTACT_MAX_PER_IP',     5);          // contact messages per IP ...
define('CONTACT_WINDOW_SECONDS', 3600);       // ... per hour

define('HOLD_CALL_OFFICE_HOURS', 24);         // "call office" bookings must be confirmed within this
define('HOLD_CASH_MINUTES_BEFORE_DEPARTURE', 60); // "cash at terminal" holds are released this long before departure

// ---------- SESSIONS ----------
function is_https(): bool {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') == 443);
}

/** Start the session once, with hardened cookie settings (Secure flag is automatic on HTTPS). */
function start_secure_session(): void {
    if (session_status() !== PHP_SESSION_NONE) return;
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => is_https(),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

/** Fully end the session, including the browser cookie. */
function destroy_session_completely(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $p['path'],
            'domain'   => $p['domain'],
            'secure'   => $p['secure'],
            'httponly' => $p['httponly'],
            'samesite' => $p['samesite'] ?: 'Strict',
        ]);
    }
    session_destroy();
}

/** Client IP. Uses REMOTE_ADDR only: forwarded headers can be spoofed unless you control the proxy. */
function client_ip(): string {
    return substr($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', 0, 45);
}

// ---------- CSRF ----------
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_valid(?string $submitted): bool {
    return !empty($_SESSION['csrf_token']) && is_string($submitted)
        && hash_equals($_SESSION['csrf_token'], $submitted);
}

/** Small HTML snippet for the logout button (POST + CSRF). */
function logout_form(string $btn_class, string $label = 'Logout', string $btn_style = ''): string {
    return '<form method="POST" action="logout.php" style="display:inline;margin:0;">'
         . '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token()) . '">'
         . '<button type="submit" class="' . htmlspecialchars($btn_class) . '" style="' . htmlspecialchars($btn_style) . '">'
         . htmlspecialchars($label) . '</button></form>';
}

// ---------- ACCESS CONTROL ----------
function json_out(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit();
}

/**
 * Require a logged-in staff member with one of $roles.
 * $json = true  -> answer with JSON (AJAX endpoints) instead of redirecting.
 * $allow_pw_change = true is used ONLY by the "change password" page itself.
 */
function require_staff(array $roles, bool $json = false, bool $allow_pw_change = false): void {
    start_secure_session();

    if (empty($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
        if ($json) json_out(['success' => false, 'error' => 'Unauthorized. Please log in.'], 401);
        header('Location: admin_login.php'); exit();
    }

    $role = $_SESSION['role'] ?? '';
    if (!in_array($role, $roles, true)) {
        if ($json) json_out(['success' => false, 'error' => 'Insufficient permissions.'], 403);
        // A clerk who wanders into an admin-only page goes back to their own dashboard
        header('Location: ' . ($role === 'clerk' ? 'clerk.php' : 'admin_login.php')); exit();
    }

    if (!$allow_pw_change && !empty($_SESSION['must_change_password'])) {
        if ($json) json_out(['success' => false, 'error' => 'You must change your password first.'], 403);
        header('Location: staff_change_password.php'); exit();
    }
}

// ---------- RATE LIMITING (database backed, per IP) ----------
/**
 * Returns true and records a hit if the caller is under the limit; false if over it.
 */
function rate_limit_allow(mysqli $conn, string $action, int $max, int $window_seconds): bool {
    $ip = client_ip();

    if (random_int(1, 50) === 1) {   // cheap housekeeping
        mysqli_query($conn, "DELETE FROM rate_limits WHERE created_at < (NOW() - INTERVAL 1 DAY)");
    }

    $stmt = mysqli_prepare($conn,
        "SELECT COUNT(*) FROM rate_limits
         WHERE action = ? AND ip_address = ? AND created_at >= (NOW() - INTERVAL ? SECOND)");
    if (!$stmt) return true;   // table missing (migration not run): fail open rather than block everyone
    mysqli_stmt_bind_param($stmt, 'ssi', $action, $ip, $window_seconds);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $count);
    mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);

    if ($count >= $max) return false;

    $ins = mysqli_prepare($conn, "INSERT INTO rate_limits (action, ip_address) VALUES (?, ?)");
    mysqli_stmt_bind_param($ins, 'ss', $action, $ip);
    mysqli_stmt_execute($ins);
    mysqli_stmt_close($ins);
    return true;
}

// ---------- LOGIN LOCKOUT (database backed: by IP and by username) ----------
function login_lock_info(mysqli $conn, string $username): array {
    $info = ['locked' => false, 'remaining' => 0, 'used' => 0];
    $checks = [['ip_address', client_ip()]];
    if ($username !== '') $checks[] = ['username', $username];

    foreach ($checks as [$col, $val]) {            // $col comes from this fixed list only
        $stmt = mysqli_prepare($conn,
            "SELECT COUNT(*), UNIX_TIMESTAMP(MAX(attempted_at)), UNIX_TIMESTAMP()
             FROM login_attempts
             WHERE $col = ? AND attempted_at >= (NOW() - INTERVAL ? SECOND)");
        if (!$stmt) continue;
        $win = LOGIN_WINDOW_SECONDS;
        mysqli_stmt_bind_param($stmt, 'si', $val, $win);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $count, $last_at, $now_ts);
        mysqli_stmt_fetch($stmt);
        mysqli_stmt_close($stmt);

        $info['used'] = max($info['used'], (int)$count);
        if ($count >= LOGIN_MAX_ATTEMPTS && $last_at) {
            $left = (int)$last_at + LOGIN_LOCKOUT_SECONDS - (int)$now_ts;
            if ($left > 0) {
                $info['locked']    = true;
                $info['remaining'] = max($info['remaining'], $left);
            }
        }
    }
    return $info;
}

function login_record_failure(mysqli $conn, string $username): void {
    $ip = client_ip();
    $ins = mysqli_prepare($conn, "INSERT INTO login_attempts (username, ip_address) VALUES (?, ?)");
    if (!$ins) return;
    mysqli_stmt_bind_param($ins, 'ss', $username, $ip);
    mysqli_stmt_execute($ins);
    mysqli_stmt_close($ins);
    mysqli_query($conn, "DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL 1 DAY)");
}

function login_clear_failures(mysqli $conn, string $username): void {
    $ip = client_ip();
    $del = mysqli_prepare($conn, "DELETE FROM login_attempts WHERE username = ? AND ip_address = ?");
    if (!$del) return;
    mysqli_stmt_bind_param($del, 'ss', $username, $ip);
    mysqli_stmt_execute($del);
    mysqli_stmt_close($del);
}

// ---------- PENDING BOOKING EXPIRY ----------
/**
 * Releases unpaid public bookings so seats cannot be hoarded:
 *  - call_office:      still pending HOLD_CALL_OFFICE_HOURS after it was made
 *  - cash_at_terminal: still pending at (departure - HOLD_CASH_MINUTES_BEFORE_DEPARTURE),
 *                      but only if it was made BEFORE that cut-off (late bookings are never deleted instantly)
 * Confirmed bookings are never touched. Safe to call often; also run by expire_pending.php (cron).
 */
function release_expired_bookings(mysqli $conn): int {
    $h = (int)HOLD_CALL_OFFICE_HOURS;
    $m = (int)HOLD_CASH_MINUTES_BEFORE_DEPARTURE;
    mysqli_query($conn,
        "DELETE bk FROM bookings bk
         JOIN trips t ON t.id = bk.trip_id
         WHERE bk.status = 'pending' AND (
              (bk.payment_method = 'call_office'
                  AND bk.booked_at < (NOW() - INTERVAL $h HOUR))
           OR (bk.payment_method = 'cash_at_terminal'
                  AND (TIMESTAMP(t.trip_date, t.departure_time) - INTERVAL $m MINUTE) < NOW()
                  AND bk.booked_at < (TIMESTAMP(t.trip_date, t.departure_time) - INTERVAL $m MINUTE))
         )");
    return max(0, (int)mysqli_affected_rows($conn));
}
