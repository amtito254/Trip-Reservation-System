<?php
// =============================================
// admin_change_password.php — Manage passwords (Admin & Clerk)
// Admin can reset their own password OR the clerk's password.
// No "current password" required — direct reset.
// =============================================

include 'db.php';
require_once 'helpers.php';
require_staff(['admin']);   // admin only
csrf_token();               // make sure a CSRF token exists for the form

$success = '';
$error   = '';
// Which account tab is active: 'admin' or 'clerk'
$active_tab = isset($_POST['target_role']) ? $_POST['target_role'] : (isset($_GET['tab']) ? $_GET['tab'] : 'admin');
if (!in_array($active_tab, ['admin', 'clerk'])) $active_tab = 'admin';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $submitted_token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $submitted_token)) {
        $error = 'Invalid request. Please try again.';
    } else {
        $target_role  = $_POST['target_role']     ?? 'admin';
        $new_password = $_POST['new_password']    ?? '';
        $confirm      = $_POST['confirm_password'] ?? '';

        if (!in_array($target_role, ['admin', 'clerk'])) {
            $error = 'Invalid target account.';
        } elseif (strlen($new_password) < 8) {
            $error = 'Password must be at least 8 characters.';
        } elseif (!preg_match('/[A-Z]/', $new_password)) {
            $error = 'Password must contain at least one uppercase letter.';
        } elseif (!preg_match('/[0-9]/', $new_password)) {
            $error = 'Password must contain at least one number.';
        } elseif ($new_password !== $confirm) {
            $error = 'Passwords do not match.';
        } else {
            // Find the account to update by role
            $stmt = mysqli_prepare($conn,
                "SELECT id FROM admin_credentials WHERE role = ? LIMIT 1"
            );
            mysqli_stmt_bind_param($stmt, 's', $target_role);
            mysqli_stmt_execute($stmt);
            $result  = mysqli_stmt_get_result($stmt);
            $account = mysqli_fetch_assoc($result);

            if (!$account) {
                $error = ucfirst($target_role) . ' account not found.';
            } else {
                $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
                // A password the admin chose for someone else is only temporary:
                // that person must replace it at their next login.
                $must_change = ($target_role === 'clerk') ? 1 : 0;
                $upd = mysqli_prepare($conn,
                    "UPDATE admin_credentials SET password_hash = ?, must_change_password = ? WHERE id = ?"
                );
                mysqli_stmt_bind_param($upd, 'sii', $new_hash, $must_change, $account['id']);
                if (mysqli_stmt_execute($upd)) {
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                    $success = ucfirst($target_role) . ' password updated successfully!';
                } else {
                    $error = 'Database error. Please try again.';
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Manage Passwords — Demo Coaches Admin</title>
  <link href="https://fonts.googleapis.com/css2?family=Oswald:wght@400;600;700&family=Nunito:wght@400;600;700;800;900&display=swap" rel="stylesheet">
  <style>
    *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }
    :root {
      --blue-dark: #0d1b6e; --blue: #1a237e; --blue-mid: #1565c0; --blue-light: #42a5f5;
      --green: #1B7A3E; --green-light: #23A354;
      --white: #ffffff; --light: #f4f6fb; --gray: #5c6b70;
      --admin-accent: #1565c0;
      --clerk-accent: #1B7A3E;
    }
    body { font-family: 'Nunito', sans-serif; background: var(--light); min-height: 100vh; display: flex; flex-direction: column; }

    /* ── NAV ── */
    nav { background: var(--blue-dark); display: flex; align-items: center; justify-content: space-between; padding: 0 5%; height: 62px; box-shadow: 0 2px 16px rgba(0,0,0,.4); position: sticky; top: 0; z-index: 99; }
    .nav-brand { font-family: 'Oswald', sans-serif; font-size: 22px; color: var(--white); letter-spacing: 3px; text-decoration: none; display: flex; align-items: center; gap: 10px; }
    .nav-brand span { color: var(--blue-light); }
    .nav-right { display: flex; align-items: center; gap: 14px; }
    .nav-badge { font-size: 13px; font-weight: 700; color: rgba(255,255,255,.6); }
    .nav-badge strong { color: var(--white); }
    .btn-nav { padding: 8px 18px; border-radius: 6px; font-weight: 800; font-size: 13px; text-decoration: none; transition: all .2s; border: none; cursor: pointer; font-family: 'Nunito', sans-serif; }
    .btn-back { background: rgba(255,255,255,.1); color: var(--white); }
    .btn-back:hover { background: rgba(255,255,255,.2); }
    .btn-logout { background: #c62828; color: var(--white); }
    .btn-logout:hover { background: #b71c1c; }

    /* ── PAGE HEADER ── */
    .page-header { background: linear-gradient(135deg, var(--blue-dark), var(--blue-mid)); padding: 44px 5% 36px; text-align: center; position: relative; overflow: hidden; }
    .page-header::before { content: ''; position: absolute; inset: 0; background-image: radial-gradient(circle, rgba(255,255,255,.04) 1px, transparent 1px); background-size: 36px 36px; }
    .page-header h1 { font-family: 'Oswald', sans-serif; font-size: clamp(28px,4vw,44px); color: var(--white); letter-spacing: 3px; text-transform: uppercase; position: relative; }
    .page-header h1 span { color: var(--blue-light); }
    .page-header p { color: rgba(255,255,255,.55); font-size: 14px; margin-top: 8px; position: relative; }

    /* ── LAYOUT ── */
    .main { flex: 1; display: flex; align-items: flex-start; justify-content: center; padding: 50px 5% 60px; }
    .container { width: 100%; max-width: 560px; }

    /* ── TABS ── */
    .tab-row { display: grid; grid-template-columns: 1fr 1fr; gap: 0; margin-bottom: 0; border-radius: 14px 14px 0 0; overflow: hidden; box-shadow: 0 -2px 10px rgba(0,0,0,.06); }
    .tab-btn {
      padding: 16px 10px; border: none; cursor: pointer; font-family: 'Oswald', sans-serif;
      font-size: 16px; letter-spacing: 2px; text-transform: uppercase; transition: all .25s;
      display: flex; align-items: center; justify-content: center; gap: 10px;
      background: #e4e9f2; color: #8899aa;
    }
    .tab-btn .tab-icon { font-size: 20px; }
    .tab-btn.active-admin { background: var(--blue); color: var(--white); }
    .tab-btn.active-clerk { background: var(--green); color: var(--white); }
    .tab-btn:not(.active-admin):not(.active-clerk):hover { background: #d4dae8; color: #445566; }

    /* ── CARD ── */
    .card { background: var(--white); border-radius: 0 0 16px 16px; box-shadow: 0 8px 40px rgba(0,0,0,.09); border: 1px solid #e2e8f0; border-top: none; overflow: hidden; }
    .card-accent { height: 5px; }
    .card-accent.admin { background: linear-gradient(90deg, var(--blue), var(--blue-light)); }
    .card-accent.clerk { background: linear-gradient(90deg, var(--green), #4caf50); }
    .card-body { padding: 36px 40px 40px; }

    .account-label {
      display: inline-flex; align-items: center; gap: 8px;
      padding: 6px 14px; border-radius: 20px; font-size: 12px; font-weight: 800;
      text-transform: uppercase; letter-spacing: 1.5px; margin-bottom: 16px;
    }
    .account-label.admin { background: rgba(21,101,192,.12); color: var(--admin-accent); border: 1px solid rgba(21,101,192,.25); }
    .account-label.clerk { background: rgba(27,122,62,.12); color: var(--clerk-accent); border: 1px solid rgba(27,122,62,.25); }

    .card-title { font-family: 'Oswald', sans-serif; font-size: 22px; letter-spacing: 1.5px; text-transform: uppercase; margin-bottom: 4px; }
    .card-title.admin { color: var(--blue); }
    .card-title.clerk { color: var(--green); }
    .card-sub { font-size: 13px; color: var(--gray); margin-bottom: 28px; padding-bottom: 20px; border-bottom: 1px solid #f0f4f8; }

    .alert { padding: 14px 18px; border-radius: 8px; margin-bottom: 22px; font-weight: 700; font-size: 14px; line-height: 1.6; }
    .alert-success { background: #e8f5e9; border-left: 4px solid #388e3c; color: #1b5e20; }
    .alert-error   { background: #ffebee; border-left: 4px solid #c62828; color: #b71c1c; }

    .fg { margin-bottom: 20px; }
    .fg label { display: block; font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 1.2px; color: #8eacc0; margin-bottom: 7px; }
    .input-wrap { position: relative; }
    .fg input[type="password"], .fg input[type="text"] {
      width: 100%; padding: 12px 44px 12px 14px; border: 2px solid #e0e8f0; border-radius: 8px;
      font-family: 'Nunito', sans-serif; font-size: 15px; color: #1a1a2e; background: #f8fafc;
      outline: none; transition: border-color .2s, box-shadow .2s;
    }
    .fg input:focus { background: var(--white); box-shadow: 0 0 0 3px rgba(0,0,0,.06); }
    .fg input.admin-focus:focus { border-color: var(--blue); box-shadow: 0 0 0 3px rgba(26,35,126,.08); }
    .fg input.clerk-focus:focus { border-color: var(--green); box-shadow: 0 0 0 3px rgba(27,122,62,.08); }
    .toggle-eye { position: absolute; right: 12px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; font-size: 18px; color: #aab; padding: 4px; line-height: 1; }
    .toggle-eye:hover { color: var(--blue); }

    .strength-wrap { margin-top: 8px; }
    .strength-bar { height: 4px; border-radius: 2px; background: #e0e8f0; overflow: hidden; }
    .strength-fill { height: 100%; width: 0; border-radius: 2px; transition: width .3s, background .3s; }
    .strength-label { font-size: 11px; font-weight: 700; margin-top: 4px; color: var(--gray); }

    .req-list { list-style: none; margin: 10px 0 0; display: grid; grid-template-columns: 1fr 1fr; gap: 4px 12px; }
    .req-list li { font-size: 12px; color: #aab; transition: color .2s; display: flex; align-items: center; gap: 5px; }
    .req-list li.ok { color: var(--green); }
    .req-list li::before { content: '○'; font-size: 10px; }
    .req-list li.ok::before { content: '✓'; }

    .match-label { font-size: 11px; font-weight: 700; margin-top: 6px; }

    .btn-submit {
      width: 100%; padding: 14px; color: var(--white); border: none; border-radius: 8px;
      font-family: 'Oswald', sans-serif; font-size: 18px; letter-spacing: 3px;
      cursor: pointer; transition: all .2s; margin-top: 4px;
    }
    .btn-submit.admin { background: linear-gradient(135deg, var(--blue), var(--blue-mid)); }
    .btn-submit.admin:hover { background: linear-gradient(135deg, #0d47a1, #0d47a1); transform: translateY(-1px); box-shadow: 0 6px 20px rgba(13,71,161,.35); }
    .btn-submit.clerk { background: linear-gradient(135deg, var(--green), var(--green-light)); }
    .btn-submit.clerk:hover { background: linear-gradient(135deg, #135C2E, #1B7A3E); transform: translateY(-1px); box-shadow: 0 6px 20px rgba(27,122,62,.35); }

    .back-link { display: block; text-align: center; margin-top: 18px; font-size: 13px; color: var(--gray); text-decoration: none; font-weight: 700; transition: color .2s; }
    .back-link:hover { color: var(--blue); }

    footer { background: var(--blue-dark); color: rgba(255,255,255,.3); text-align: center; padding: 18px 5%; font-size: 12px; }
    footer span { color: var(--blue-light); font-weight: 700; }

    @media (max-width: 500px) {
      .card-body { padding: 24px 20px 28px; }
      .tab-btn { font-size: 14px; letter-spacing: 1px; }
    }
  </style>
</head>
<body>

<nav>
  <a href="admin.php" class="nav-brand"><span>DEMO</span> COACHES</a>
  <div class="nav-right">
    <div class="nav-badge">Logged in as <strong><?= htmlspecialchars($_SESSION['admin_name'] ?? 'Admin') ?></strong></div>
    <a href="admin.php" class="btn-nav btn-back">← Dashboard</a>
    <?= logout_form('btn-nav btn-logout', 'Logout') ?>
  </div>
</nav>

<div class="page-header">
  <h1>Manage <span>Passwords</span></h1>
  <p>Reset the Admin or Clerk account password. A reset Clerk password is temporary: the clerk must choose their own at next login.</p>
</div>

<div class="main">
  <div class="container">

    <!-- TABS -->
    <div class="tab-row">
      <button type="button"
        class="tab-btn <?= $active_tab === 'admin' ? 'active-admin' : '' ?>"
        onclick="switchTab('admin')">
        <span class="tab-icon">🛡️</span> Admin Account
      </button>
      <button type="button"
        class="tab-btn <?= $active_tab === 'clerk' ? 'active-clerk' : '' ?>"
        onclick="switchTab('clerk')">
        <span class="tab-icon">🎫</span> Clerk Account
      </button>
    </div>

    <!-- CARD -->
    <div class="card">
      <div class="card-accent <?= $active_tab ?>" id="cardAccent"></div>
      <div class="card-body">

        <span class="account-label <?= $active_tab ?>" id="accountLabel">
          <?= $active_tab === 'admin' ? '🛡️ Admin Account' : '🎫 Clerk Account' ?>
        </span>

        <div class="card-title <?= $active_tab ?>" id="cardTitle">
          Reset <?= ucfirst($active_tab) ?> Password
        </div>
        <div class="card-sub" id="cardSub">
          <?= $active_tab === 'admin'
            ? 'Set a new password for the Administrator account.'
            : 'Set a new password for the Booking Clerk account.' ?>
        </div>

        <?php if ($success): ?>
          <div class="alert alert-success">✅ <?= htmlspecialchars($success) ?></div>
          <div style="text-align:center;padding:10px 0 6px;">
            <a href="admin.php" style="display:inline-block;padding:13px 30px;background:linear-gradient(135deg,#1a237e,#1565c0);color:white;border-radius:8px;font-family:'Oswald',sans-serif;font-size:16px;letter-spacing:2px;text-decoration:none;">← Back to Dashboard</a>
          </div>
        <?php else: ?>

          <?php if ($error): ?>
            <div class="alert alert-error">⚠️ <?= htmlspecialchars($error) ?></div>
          <?php endif; ?>

          <form method="POST" action="admin_change_password.php" id="pwForm">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="target_role" id="targetRoleInput" value="<?= htmlspecialchars($active_tab) ?>">

            <div class="fg">
              <label>New Password</label>
              <div class="input-wrap">
                <input type="password" name="new_password" id="newPw"
                       placeholder="Min 8 chars, 1 uppercase, 1 number"
                       autocomplete="new-password"
                       class="<?= $active_tab ?>-focus"
                       oninput="checkStrength(this.value)" required>
                <button type="button" class="toggle-eye" onclick="toggleField('newPw',this)">👁</button>
              </div>
              <div class="strength-wrap">
                <div class="strength-bar"><div class="strength-fill" id="strengthFill"></div></div>
                <div class="strength-label" id="strengthLabel">Enter a new password</div>
              </div>
              <ul class="req-list" id="reqList">
                <li id="req-len">At least 8 characters</li>
                <li id="req-upper">One uppercase letter</li>
                <li id="req-num">One number</li>
              </ul>
            </div>

            <div class="fg">
              <label>Confirm New Password</label>
              <div class="input-wrap">
                <input type="password" name="confirm_password" id="confirmPw"
                       placeholder="Re-enter new password"
                       autocomplete="new-password"
                       class="<?= $active_tab ?>-focus"
                       oninput="checkMatch()" required>
                <button type="button" class="toggle-eye" onclick="toggleField('confirmPw',this)">👁</button>
              </div>
              <div class="match-label" id="matchLabel"></div>
            </div>

            <button type="submit" class="btn-submit <?= $active_tab ?>" id="submitBtn">
              RESET <?= strtoupper($active_tab) ?> PASSWORD →
            </button>
          </form>

        <?php endif; ?>

        <a href="admin.php" class="back-link">← Return to Dashboard</a>
      </div>
    </div>

  </div>
</div>

<footer>
  &copy; 2026 Demo Coaches (fictional demo) &nbsp;|&nbsp; Admin Panel &nbsp;|&nbsp; Designed by <span>Mejah Titus Guthua</span>
</footer>

<script>
const TAB_ADMIN = {
  btnClass:   'active-admin',
  accentClass:'admin',
  labelText:  '🛡️ Admin Account',
  labelClass: 'admin',
  titleText:  'Reset Admin Password',
  titleClass: 'admin',
  subText:    'Set a new password for the Administrator account.',
  btnText:    'RESET ADMIN PASSWORD →',
  btnClass2:  'admin',
  inputClass: 'admin-focus',
};
const TAB_CLERK = {
  btnClass:   'active-clerk',
  accentClass:'clerk',
  labelText:  '🎫 Clerk Account',
  labelClass: 'clerk',
  titleText:  'Reset Clerk Password',
  titleClass: 'clerk',
  subText:    'Set a new password for the Booking Clerk account.',
  btnText:    'RESET CLERK PASSWORD →',
  btnClass2:  'clerk',
  inputClass: 'clerk-focus',
};

let currentTab = '<?= $active_tab ?>';

function switchTab(tab) {
  if (currentTab === tab) return;
  currentTab = tab;
  const cfg = tab === 'admin' ? TAB_ADMIN : TAB_CLERK;

  // Tab buttons
  document.querySelectorAll('.tab-btn').forEach(b => {
    b.classList.remove('active-admin','active-clerk');
  });
  document.querySelectorAll('.tab-btn')[tab === 'admin' ? 0 : 1].classList.add(cfg.btnClass);

  // Card accent
  const accent = document.getElementById('cardAccent');
  accent.className = 'card-accent ' + cfg.accentClass;

  // Label
  const lbl = document.getElementById('accountLabel');
  lbl.className = 'account-label ' + cfg.labelClass;
  lbl.textContent = cfg.labelText;

  // Title
  const title = document.getElementById('cardTitle');
  title.className = 'card-title ' + cfg.titleClass;
  title.textContent = cfg.titleText;

  // Sub
  document.getElementById('cardSub').textContent = cfg.subText;

  // Submit
  const btn = document.getElementById('submitBtn');
  btn.className = 'btn-submit ' + cfg.btnClass2;
  btn.textContent = cfg.btnText;

  // Inputs focus class
  ['newPw','confirmPw'].forEach(id => {
    const el = document.getElementById(id);
    el.classList.remove('admin-focus','clerk-focus');
    el.classList.add(cfg.inputClass);
    el.value = '';
  });

  // Hidden input
  document.getElementById('targetRoleInput').value = tab;

  // Reset strength UI
  document.getElementById('strengthFill').style.width = '0';
  document.getElementById('strengthFill').style.background = '#e0e8f0';
  document.getElementById('strengthLabel').textContent = 'Enter a new password';
  document.getElementById('strengthLabel').style.color = '#aab';
  document.getElementById('matchLabel').textContent = '';
  ['req-len','req-upper','req-num'].forEach(id => document.getElementById(id).className = '');
}

function toggleField(id, btn) {
  const f = document.getElementById(id);
  f.type = f.type === 'password' ? 'text' : 'password';
  btn.textContent = f.type === 'password' ? '👁' : '🙈';
}

function checkStrength(val) {
  const fill  = document.getElementById('strengthFill');
  const label = document.getElementById('strengthLabel');
  const hasLen   = val.length >= 8;
  const hasUpper = /[A-Z]/.test(val);
  const hasNum   = /[0-9]/.test(val);
  const hasSpec  = /[^a-zA-Z0-9]/.test(val);

  document.getElementById('req-len').className   = hasLen   ? 'ok' : '';
  document.getElementById('req-upper').className = hasUpper ? 'ok' : '';
  document.getElementById('req-num').className   = hasNum   ? 'ok' : '';

  const score = [hasLen, hasUpper, hasNum, hasSpec, val.length >= 12].filter(Boolean).length;
  const levels = [
    { w:'0%',   bg:'#e0e8f0', text:'Enter a new password' },
    { w:'25%',  bg:'#e53935', text:'Weak' },
    { w:'50%',  bg:'#fb8c00', text:'Fair' },
    { w:'75%',  bg:'#fdd835', text:'Good' },
    { w:'90%',  bg:'#43a047', text:'Strong' },
    { w:'100%', bg:'#1B7A3E', text:'✓ Very Strong' },
  ];
  const lv = val.length === 0 ? levels[0] : levels[Math.min(score, 5)];
  fill.style.width      = lv.w;
  fill.style.background = lv.bg;
  label.textContent     = lv.text;
  label.style.color     = val.length === 0 ? '#aab' : lv.bg;
  checkMatch();
}

function checkMatch() {
  const nw  = document.getElementById('newPw').value;
  const cnf = document.getElementById('confirmPw').value;
  const ml  = document.getElementById('matchLabel');
  if (!cnf) { ml.textContent = ''; return; }
  if (nw === cnf) {
    ml.textContent = '✓ Passwords match';
    ml.style.color = '#1B7A3E';
  } else {
    ml.textContent = '✗ Passwords do not match';
    ml.style.color = '#c62828';
  }
}
</script>
</body>
</html>