<?php
// =============================================
// staff_change_password.php — Any staff member changes THEIR OWN password.
// Required on first login (must_change_password = 1); also reachable voluntarily.
// =============================================
include 'db.php';
require_once 'helpers.php';
require_staff(['admin', 'clerk'], false, true);   // true = this page is allowed while a change is pending

$forced  = !empty($_SESSION['must_change_password']);
$error   = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current = (string)($_POST['current_password'] ?? '');
    $new     = (string)($_POST['new_password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');

    if (!csrf_valid($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please refresh and try again.';
    } elseif (!rate_limit_allow($conn, 'pwchange', 10, 900)) {
        $error = 'Too many attempts. Please wait a few minutes and try again.';
    } else {
        $id   = (int)$_SESSION['admin_id'];
        $stmt = mysqli_prepare($conn, "SELECT password_hash FROM admin_credentials WHERE id = ? LIMIT 1");
        mysqli_stmt_bind_param($stmt, 'i', $id);
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);

        if (!$row || !password_verify($current, $row['password_hash'])) {
            $error = 'Your current password is incorrect.';
        } elseif (strlen($new) < 8) {
            $error = 'New password must be at least 8 characters.';
        } elseif (!preg_match('/[A-Z]/', $new)) {
            $error = 'New password must contain at least one uppercase letter.';
        } elseif (!preg_match('/[0-9]/', $new)) {
            $error = 'New password must contain at least one number.';
        } elseif ($new === $current) {
            $error = 'New password must be different from the current one.';
        } elseif ($new !== $confirm) {
            $error = 'New passwords do not match.';
        } else {
            $hash = password_hash($new, PASSWORD_DEFAULT);
            $upd  = mysqli_prepare($conn, "UPDATE admin_credentials SET password_hash = ?, must_change_password = 0 WHERE id = ?");
            mysqli_stmt_bind_param($upd, 'si', $hash, $id);
            if (mysqli_stmt_execute($upd)) {
                session_regenerate_id(true);
                $_SESSION['must_change_password'] = false;
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                $success = true;
            } else {
                $error = 'Database error. Please try again.';
            }
        }
    }
}
$dash = ($_SESSION['role'] ?? '') === 'clerk' ? 'clerk.php' : 'admin.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Password — Demo Coaches</title>
    <link href="https://fonts.googleapis.com/css2?family=Oswald:wght@400;600;700&family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }
        :root { --blue: #1a237e; --blue-mid: #1565c0; --blue-light: #42a5f5; --white: #fff; --gray: #6c757d; }
        body { font-family: 'Nunito', sans-serif; min-height: 100vh; background: linear-gradient(135deg, #0d1b6e 0%, #1a237e 45%, #1565c0 100%); display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 20px; }
        .top-logo { text-align: center; margin-bottom: 26px; }
        .top-logo h1 { font-family: 'Oswald', sans-serif; font-size: 34px; color: var(--white); letter-spacing: 4px; text-transform: uppercase; }
        .top-logo h1 span { color: var(--blue-light); }
        .card { background: var(--white); border-radius: 18px; width: 100%; max-width: 440px; box-shadow: 0 24px 60px rgba(0,0,0,.35); overflow: hidden; }
        .stripe { height: 5px; background: linear-gradient(90deg, var(--blue), var(--blue-light)); }
        .inner { padding: 34px 38px 38px; }
        .title { font-family: 'Oswald', sans-serif; font-size: 23px; color: var(--blue); letter-spacing: 2px; text-transform: uppercase; }
        .sub { font-size: 13px; color: var(--gray); margin: 4px 0 24px; padding-bottom: 18px; border-bottom: 1px solid #f0f4f8; line-height: 1.6; }
        .alert { padding: 13px 16px; border-radius: 8px; margin-bottom: 18px; font-weight: 700; font-size: 14px; line-height: 1.6; }
        .alert-error { background: #ffebee; border-left: 4px solid #c62828; color: #b71c1c; }
        .alert-info  { background: #fff8e1; border-left: 4px solid #f9a825; color: #6d4c00; }
        .alert-ok    { background: #e8f5e9; border-left: 4px solid #388e3c; color: #1b5e20; }
        .fg { margin-bottom: 18px; }
        .fg label { display: block; font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 1.2px; color: #8eacc0; margin-bottom: 7px; }
        .fg input { width: 100%; padding: 12px 14px; border: 2px solid #e0e8f0; border-radius: 8px; font-family: 'Nunito', sans-serif; font-size: 15px; background: #f8fafc; outline: none; }
        .fg input:focus { border-color: var(--blue); background: var(--white); }
        .hint { font-size: 12px; color: var(--gray); margin-top: 5px; }
        .btn { width: 100%; padding: 14px; background: linear-gradient(135deg, var(--blue), var(--blue-mid)); color: var(--white); border: none; border-radius: 8px; font-family: 'Oswald', sans-serif; font-size: 18px; letter-spacing: 3px; cursor: pointer; margin-top: 6px; }
        .btn:hover { background: #0d47a1; }
        .links { display: flex; justify-content: space-between; align-items: center; margin-top: 18px; font-size: 13px; }
        .links a, .links button { color: var(--gray); font-weight: 700; text-decoration: none; background: none; border: none; cursor: pointer; font-family: 'Nunito', sans-serif; font-size: 13px; }
        .links a:hover, .links button:hover { color: var(--blue); }
    </style>
</head>
<body>
<div class="top-logo"><h1><span>Demo</span> Coaches</h1></div>
<div class="card">
    <div class="stripe"></div>
    <div class="inner">
        <div class="title">Change Password</div>
        <div class="sub">Signed in as <strong><?= htmlspecialchars($_SESSION['admin_name'] ?? '') ?></strong></div>

        <?php if ($success): ?>
            <div class="alert alert-ok">✅ Your password has been updated.</div>
            <a href="<?= $dash ?>" style="display:block;text-align:center;padding:13px;background:linear-gradient(135deg,#1a237e,#1565c0);color:#fff;border-radius:8px;font-family:'Oswald',sans-serif;letter-spacing:2px;text-decoration:none;">CONTINUE →</a>
        <?php else: ?>
            <?php if ($forced): ?>
                <div class="alert alert-info">🔐 For security, you must choose your own password before continuing.</div>
            <?php endif; ?>
            <?php if ($error): ?><div class="alert alert-error">🚫 <?= htmlspecialchars($error) ?></div><?php endif; ?>

            <form method="POST" action="staff_change_password.php" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                <div class="fg"><label>Current Password</label><input type="password" name="current_password" autocomplete="current-password" required></div>
                <div class="fg"><label>New Password</label><input type="password" name="new_password" autocomplete="new-password" required>
                    <div class="hint">At least 8 characters, one uppercase letter and one number.</div></div>
                <div class="fg"><label>Confirm New Password</label><input type="password" name="confirm_password" autocomplete="new-password" required></div>
                <button type="submit" class="btn">SAVE PASSWORD →</button>
            </form>
            <div class="links">
                <?php if (!$forced): ?><a href="<?= $dash ?>">← Back</a><?php else: ?><span></span><?php endif; ?>
                <?= logout_form('', 'Logout') ?>
            </div>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
