<?php
// =============================================
// logout.php — POST-only logout with CSRF check.
// A plain link or <img src="logout.php"> cannot log anyone out.
// =============================================
require_once 'helpers.php';
start_secure_session();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_valid($_POST['csrf_token'] ?? '')) {
    // Not a genuine logout request: leave the session alone and go back
    if (!empty($_SESSION['admin_logged_in'])) {
        header('Location: ' . (($_SESSION['role'] ?? '') === 'clerk' ? 'clerk.php' : 'admin.php'));
    } else {
        header('Location: admin_login.php');
    }
    exit();
}

destroy_session_completely();   // clears data, the server session AND the browser cookie
header('Location: admin_login.php');
exit();
