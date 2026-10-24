<?php
// =============================================
// admin_login.php — Secure staff login (Admin + Clerk)
// =============================================

include 'db.php';
require_once 'helpers.php';
start_secure_session();

// Logout is POST-only now (see logout.php); a GET link can no longer log anyone out.

// If already logged in, send to the right place
if (!empty($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    if (!empty($_SESSION['must_change_password'])) { header("Location: staff_change_password.php"); exit(); }
    header("Location: " . (($_SESSION['role'] ?? '') === 'clerk' ? 'clerk.php' : 'admin.php'));
    exit();
}

csrf_token();   // make sure a token exists for the form

// Valid bcrypt hash used only to keep response time the same for unknown usernames
const DUMMY_HASH = '$2y$12$S0YetpamxiTM1HhOIbZkieSzphIr/ek.JkHgBoeXhC8bWzTvqzrTS';

$error = '';
$posted_user = '';

// Lockout state is stored in the DATABASE (by IP and by username), so clearing cookies does not reset it.
$lock = login_lock_info($conn, '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $posted_user = mb_substr(trim($_POST['username'] ?? ''), 0, 60);
    $username    = mb_strtolower($posted_user);
    $password    = (string)($_POST['password'] ?? '');
    $lock        = login_lock_info($conn, $username);

    if (!csrf_valid($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please refresh and try again.';
    } elseif ($lock['locked']) {
        // No password check while locked
    } else {
        $stmt = mysqli_prepare($conn, "SELECT id, username, password_hash, display_name, role, must_change_password FROM admin_credentials WHERE username = ? LIMIT 1");

        if (!$stmt) {
            $error = 'Database setup incomplete. Please run db.sql (new install) or migrate_security.sql (existing database).';
        } else {
            mysqli_stmt_bind_param($stmt, 's', $username);
            mysqli_stmt_execute($stmt);
            $admin = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            mysqli_stmt_close($stmt);

            $ok = password_verify($password, $admin['password_hash'] ?? DUMMY_HASH) && $admin;

            if ($ok) {
                login_clear_failures($conn, $username);
                session_regenerate_id(true);

                $_SESSION['admin_logged_in'] = true;
                $_SESSION['admin_id']        = $admin['id'];
                $_SESSION['admin_name']      = $admin['display_name'];
                $_SESSION['admin_username']  = $admin['username'];
                $_SESSION['role']            = $admin['role'] ?? 'admin';
                $_SESSION['must_change_password'] = (int)$admin['must_change_password'] === 1;
                $_SESSION['csrf_token']      = bin2hex(random_bytes(32));

                if ($_SESSION['must_change_password']) { header("Location: staff_change_password.php"); exit(); }
                header("Location: " . ($_SESSION['role'] === 'clerk' ? 'clerk.php' : 'admin.php'));
                exit();
            }

            login_record_failure($conn, $username);
            $lock = login_lock_info($conn, $username);
            if (!$lock['locked']) {
                $left  = max(0, LOGIN_MAX_ATTEMPTS - $lock['used']);
                $error = 'Incorrect username or password. ' . $left . ' attempt(s) remaining.';
            }
        }
    }
}

$locked_out    = $lock['locked'];
$remaining     = $lock['remaining'];
$attempts_used = min($lock['used'], LOGIN_MAX_ATTEMPTS);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Login — Demo Coaches</title>
    <link href="https://fonts.googleapis.com/css2?family=Oswald:wght@400;600;700&family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }
        :root { --blue-dark: #0d1b6e; --blue: #1a237e; --blue-mid: #1565c0; --blue-light: #42a5f5; --white: #ffffff; --light: #f4f6fb; --gray: #6c757d; }
        body { font-family: 'Nunito', sans-serif; min-height: 100vh; background: linear-gradient(135deg, #0d1b6e 0%, #1a237e 45%, #1565c0 100%); display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 20px; }
        .top-logo { text-align: center; margin-bottom: 30px; }
        .top-logo h1 { font-family: 'Oswald', sans-serif; font-size: 36px; color: var(--white); letter-spacing: 4px; text-transform: uppercase; }
        .top-logo h1 span { color: var(--blue-light); }
        .top-logo p { color: rgba(255,255,255,.5); font-size: 13px; margin-top: 6px; font-weight: 700; letter-spacing: 2px; text-transform: uppercase; }
        .login-card { background: var(--white); border-radius: 18px; width: 100%; max-width: 420px; box-shadow: 0 24px 60px rgba(0,0,0,.35); overflow: hidden; }
        .card-stripe { height: 5px; background: linear-gradient(90deg, var(--blue), var(--blue-light)); }
        .card-inner { padding: 36px 40px 40px; }
        .card-title { font-family: 'Oswald', sans-serif; font-size: 24px; color: var(--blue); letter-spacing: 2px; text-transform: uppercase; margin-bottom: 2px; }
        .card-subtitle { font-size: 13px; color: var(--gray); margin-bottom: 28px; padding-bottom: 20px; border-bottom: 1px solid #f0f4f8; }
        .alert { padding: 13px 16px; border-radius: 8px; margin-bottom: 20px; font-weight: 700; font-size: 14px; line-height: 1.6; }
        .alert-error { background: #ffebee; border-left: 4px solid #c62828; color: #b71c1c; }
        .alert-warning { background: #fff3e0; border-left: 4px solid #e65100; color: #bf360c; }
        .lockout-timer { font-size: 28px; font-family: 'Oswald', sans-serif; color: #e65100; text-align: center; margin-top: 6px; }
        .fg { margin-bottom: 20px; }
        .fg label { display: block; font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 1.2px; color: #8eacc0; margin-bottom: 7px; }
        .input-wrap { position: relative; }
        .fg input { width: 100%; padding: 12px 44px 12px 14px; border: 2px solid #e0e8f0; border-radius: 8px; font-family: 'Nunito', sans-serif; font-size: 15px; color: #1a1a2e; background: #f8fafc; outline: none; transition: border-color .2s, box-shadow .2s; }
        .fg input:focus { border-color: var(--blue); background: var(--white); box-shadow: 0 0 0 3px rgba(26,35,126,.08); }
        .toggle-eye { position: absolute; right: 12px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; font-size: 18px; color: #aab; padding: 4px; line-height: 1; }
        .toggle-eye:hover { color: var(--blue); }
        .btn-login { width: 100%; padding: 14px; background: linear-gradient(135deg, var(--blue), var(--blue-mid)); color: var(--white); border: none; border-radius: 8px; font-family: 'Oswald', sans-serif; font-size: 18px; letter-spacing: 3px; cursor: pointer; transition: all .2s; margin-top: 8px; }
        .btn-login:hover { background: linear-gradient(135deg, #0d47a1, #0d47a1); transform: translateY(-1px); box-shadow: 0 6px 20px rgba(13,71,161,.35); }
        .btn-login:disabled { background: #ccc; cursor: not-allowed; transform: none; box-shadow: none; }
        .back-link { display: block; text-align: center; margin-top: 20px; font-size: 13px; color: rgba(255,255,255,.5); text-decoration: none; font-weight: 700; transition: color .2s; }
        .back-link:hover { color: var(--white); }
        .attempts-bar { display: flex; gap: 5px; margin-bottom: 16px; }
        .attempt-dot { flex: 1; height: 4px; border-radius: 2px; background: #e0e8f0; transition: background .3s; }
        .attempt-dot.used { background: #c62828; }
    </style>
</head>
<body>

<div style="background:#F5C518;color:#1a1a1a;text-align:center;padding:8px 12px;font:700 13px/1.4 Nunito,Arial,sans-serif;position:relative;z-index:1000;">DEMO ONLY &mdash; fictional company for portfolio purposes. Bookings here are not real.</div>
<div class="top-logo">
    <h1><span>Demo</span> Coaches</h1>
    <p>Office Staff Portal</p>
</div>

<div class="login-card">
    <div class="card-stripe"></div>
    <div class="card-inner">
        <div class="card-title">Staff Login</div>
        <div class="card-subtitle">Admin &amp; Booking Clerk Access</div>

        <?php if ($locked_out): ?>
            <div class="alert alert-warning">⚠️ Account temporarily locked. Please wait before trying again.<div class="lockout-timer" id="lockTimer"><?= $remaining ?>s</div></div>
        <?php elseif ($error): ?>
            <div class="alert alert-error">🚫 <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if ($attempts_used > 0 && !$locked_out): ?>
            <div class="attempts-bar">
                <?php for ($i = 0; $i < LOGIN_MAX_ATTEMPTS; $i++): ?>
                    <div class="attempt-dot <?= $i < $attempts_used ? 'used' : '' ?>"></div>
                <?php endfor; ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="admin_login.php" id="loginForm" <?= $locked_out ? 'style="opacity:.5;pointer-events:none;"' : '' ?>>
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
            <div class="fg"><label>Username</label><input type="text" name="username" placeholder="Enter your username" autocomplete="username" required value="<?= htmlspecialchars($posted_user) ?>"></div>
            <div class="fg"><label>Password</label><div class="input-wrap"><input type="password" name="password" id="passwordField" placeholder="Enter your password" autocomplete="current-password" required><button type="button" class="toggle-eye" onclick="togglePassword()">👁</button></div></div>
            <button type="submit" class="btn-login" id="loginBtn">LOGIN →</button>
        </form>
    </div>
</div>

<a href="index.php" class="back-link">← Back to Booking Page</a>

<script>
function togglePassword() { const f = document.getElementById('passwordField'); f.type = f.type === 'password' ? 'text' : 'password'; }
<?php if ($locked_out && $remaining > 0): ?>
let secs = <?= $remaining ?>;
const timerEl = document.getElementById('lockTimer');
const interval = setInterval(() => { secs--; if (timerEl) timerEl.textContent = secs + 's'; if (secs <= 0) { clearInterval(interval); location.reload(); } }, 1000);
<?php endif; ?>
document.getElementById('loginForm')?.addEventListener('submit', function() { const btn = document.getElementById('loginBtn'); btn.disabled = true; btn.textContent = 'Logging in…'; });
</script>

</body>
</html>