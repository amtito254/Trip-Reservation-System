<?php
// =============================================
// contact.php — Customer contact / feedback form
// =============================================
include 'db.php';
require_once 'helpers.php';

$success = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name  = trim($_POST['last_name']  ?? '');
    $email      = trim($_POST['email']      ?? '');
    $phone_raw  = trim($_POST['phone']      ?? '');  // 9 digits after +254
    $subject    = trim($_POST['subject']    ?? '');
    $message    = trim($_POST['message']    ?? '');

    $errs = [];
    $is_bot = trim($_POST['website'] ?? '') !== '';   // honeypot: real visitors never see or fill this field

    if (!preg_match('/^[a-zA-Z\s]{2,60}$/', $first_name)) $errs[] = 'Valid first name required (letters only).';
    if (!preg_match('/^[a-zA-Z\s]{2,60}$/', $last_name))  $errs[] = 'Valid last name required (letters only).';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL))        $errs[] = 'A valid email address is required.';
    if (!preg_match('/^\d{9}$/', $phone_raw))              $errs[] = 'Phone must be 9 digits after +254 (e.g. 712345678).';
    if (mb_strlen($subject) < 3 || mb_strlen($subject) > 200) $errs[] = 'Subject must be between 3 and 200 characters.';
    if (mb_strlen($message) < 10)                          $errs[] = 'Message must be at least 10 characters.';
    if (mb_strlen($message) > 5000)                        $errs[] = 'Message must be 5000 characters or fewer.';

    if ($is_bot) {
        // Pretend it worked so bots learn nothing, but store nothing
        $success = 'Thank you, ' . htmlspecialchars($first_name) . '! Your message has been received. We\'ll get back to you soon.';
    } elseif (empty($errs) && !rate_limit_allow($conn, 'contact', CONTACT_MAX_PER_IP, CONTACT_WINDOW_SECONDS)) {
        $error = 'You have sent several messages recently. Please wait a while before sending another, or call our office.';
    } elseif (empty($errs)) {
        $phone_full = '0' . $phone_raw;  // store as 0XXXXXXXXX (10 digits)

        $stmt = mysqli_prepare($conn,
            "INSERT INTO feedback (first_name, last_name, email, phone, subject, message)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        mysqli_stmt_bind_param($stmt, 'ssssss',
            $first_name, $last_name, $email, $phone_full, $subject, $message
        );

        if (mysqli_stmt_execute($stmt)) {
            $success = 'Thank you, ' . htmlspecialchars($first_name) . '! Your message has been received. We\'ll get back to you soon.';
        } else {
            $error = 'Sorry, we couldn\'t save your message right now. Please try again.';
        }
    } else {
        $error = implode(' &bull; ', $errs);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Contact Us | Demo Coaches</title>
  <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Nunito:wght@400;600;700;800;900&display=swap" rel="stylesheet"/>
  <style>
    *,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}
    :root{
      --green:#1B7A3E;--green-dark:#135C2E;--green-light:#23A354;
      --gold:#F5C518;--white:#FFFFFF;--light:#F4FAF6;
      --dark:#0D1A11;--gray:#5C6B62;
    }
    body{font-family:'Nunito',sans-serif;background:var(--light);color:var(--dark);}

    nav{position:sticky;top:0;width:100%;z-index:999;background:var(--dark);display:flex;align-items:center;justify-content:space-between;padding:0 6%;height:66px;box-shadow:0 2px 24px rgba(0,0,0,.5);}
    .logo-text{font-family:'Bebas Neue',sans-serif;font-size:26px;color:var(--white);letter-spacing:3px;text-decoration:none;}
    .logo-text span{color:var(--green-light);}
    .nav-btn{background:var(--green);color:var(--white);padding:9px 22px;border-radius:6px;font-weight:800;font-size:14px;text-decoration:none;transition:all .2s;}
    .nav-btn:hover{background:var(--green-light);}
    .back-link{color:#aaa;text-decoration:none;font-size:14px;font-weight:700;transition:color .2s;}
    .back-link:hover{color:var(--gold);}

    .page-header{background:var(--dark);padding:60px 6% 50px;text-align:center;}
    .page-header h1{font-family:'Bebas Neue',sans-serif;font-size:clamp(40px,6vw,72px);color:var(--white);letter-spacing:3px;margin-bottom:12px;}
    .page-header h1 span{color:var(--green-light);}
    .page-header p{color:#8eac99;font-size:16px;max-width:520px;margin:0 auto;}

    .contact-wrap{max-width:1100px;margin:0 auto;padding:60px 5%;display:grid;grid-template-columns:1fr 1.6fr;gap:40px;align-items:start;}
    @media(max-width:800px){.contact-wrap{grid-template-columns:1fr;}}

    .info-panel h2{font-family:'Bebas Neue',sans-serif;font-size:28px;letter-spacing:1.5px;margin-bottom:6px;color:var(--dark);}
    .info-panel p{color:var(--gray);font-size:14px;line-height:1.8;margin-bottom:28px;}
    .info-card{background:var(--white);border-radius:12px;padding:18px 20px;margin-bottom:14px;box-shadow:0 2px 12px rgba(0,0,0,.06);display:flex;align-items:flex-start;gap:14px;border-left:4px solid var(--green);}
    .info-label{font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:1px;color:#8eac99;margin-bottom:4px;}
    .info-value{font-size:14px;font-weight:700;color:var(--dark);line-height:1.6;}
    .info-value a{color:var(--green);text-decoration:none;}
    .info-value a:hover{text-decoration:underline;}
    .hours-grid{display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-top:4px;}
    .hours-row{font-size:13px;color:var(--gray);}
    .hours-row strong{color:var(--dark);}

    .form-panel{background:var(--white);border-radius:16px;padding:36px;box-shadow:0 8px 40px rgba(0,0,0,.08);border:1px solid #e0ece4;}
    .form-panel h2{font-family:'Bebas Neue',sans-serif;font-size:26px;letter-spacing:1.5px;margin-bottom:4px;color:var(--dark);}
    .form-panel .sub{font-size:13px;color:var(--gray);margin-bottom:28px;padding-bottom:20px;border-bottom:1px solid #f0f5f1;}

    .fg{margin-bottom:18px;}
    .fg label{display:block;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:1.2px;color:#8eac99;margin-bottom:7px;}
    .fg input,.fg select,.fg textarea{width:100%;padding:12px 15px;border:2px solid #e0ece4;border-radius:8px;font-family:'Nunito',sans-serif;font-size:15px;color:var(--dark);background:#fafcfa;outline:none;transition:border-color .2s,box-shadow .2s;}
    .fg input:focus,.fg textarea:focus{border-color:var(--green);box-shadow:0 0 0 3px rgba(27,122,62,.08);}
    .fg textarea{resize:vertical;min-height:110px;}
    .fg .phone-row{display:flex;gap:0;}
    .fg .phone-prefix{padding:12px 14px;background:#edf5f0;border:2px solid #e0ece4;border-right:none;border-radius:8px 0 0 8px;font-weight:800;font-size:14px;color:var(--gray);white-space:nowrap;}
    .fg .phone-input{border-radius:0 8px 8px 0;flex:1;}
    .two-col{display:grid;grid-template-columns:1fr 1fr;gap:16px;}
    @media(max-width:500px){.two-col{grid-template-columns:1fr;}}

    .submit-btn{width:100%;padding:14px;background:var(--green);color:var(--white);border:none;border-radius:8px;font-family:'Nunito',sans-serif;font-weight:900;font-size:16px;cursor:pointer;transition:all .2s;margin-top:8px;}
    .submit-btn:hover{background:var(--green-light);transform:translateY(-1px);box-shadow:0 8px 20px rgba(27,122,62,.35);}

    .alert{padding:14px 18px;border-radius:8px;margin-bottom:20px;font-weight:700;font-size:14px;line-height:1.6;}
    .alert-success{background:#e8f5e9;border-left:4px solid #388e3c;color:#1b5e20;}
    .alert-error{background:#ffebee;border-left:4px solid #c62828;color:#b71c1c;}

    footer{background:var(--dark);color:#3a5840;text-align:center;padding:24px 6%;font-size:13px;margin-top:60px;}
    footer span{color:var(--green-light);font-weight:800;}
  </style>
</head>
<body>

<div style="background:#F5C518;color:#1a1a1a;text-align:center;padding:8px 12px;font:700 13px/1.4 Nunito,Arial,sans-serif;position:relative;z-index:1000;">DEMO ONLY &mdash; fictional company for portfolio purposes. Bookings here are not real.</div>
<nav>
  <a href="index.php" class="logo-text"><span>DEMO</span> COACHES</a>
  <a href="index.php" class="back-link">← Back to Booking</a>
  <a href="index.php#booking" class="nav-btn">Book a Seat</a>
</nav>

<div class="page-header">
  <h1>GET IN <span>TOUCH</span></h1>
  <p>Have a question or feedback? We're always ready to help. Reach us through any of the channels below.</p>
</div>

<div class="contact-wrap">

  <div class="info-panel">
    <h2>Our Contact Details</h2>
    <p>We operate daily routes connecting Nairobi, Mombasa and Ukunda. Our team is available to assist you every day.</p>

    <div class="info-card">
      <div>
        <div class="info-label">Phone / WhatsApp</div>
        <div class="info-value">
          <a href="tel:+254700000000">0700 000 000</a><br>
          <a href="tel:+254711000000">0711 000 000</a>
        </div>
      </div>
    </div>

    <div class="info-card">
      <div>
        <div class="info-label">Email</div>
        <div class="info-value">
          <a href="mailto:info@democoaches.example">info@democoaches.example</a><br>
          <a href="mailto:bookings@democoaches.example">bookings@democoaches.example</a>
        </div>
      </div>
    </div>

    <div class="info-card">
      <div>
        <div class="info-label">Terminals</div>
        <div class="info-value">
          Nairobi<br>
          Mombasa<br>
          Ukunda
        </div>
      </div>
    </div>

    <div class="info-card">
      <div>
        <div class="info-label">Office Hours</div>
        <div class="info-value">
          <div class="hours-grid">
            <div class="hours-row"><strong>Mon – Sun</strong></div>
            <div class="hours-row">6:30 AM – 10:30 PM</div>
            <div class="hours-row"><strong>Website</strong></div>
            <div class="hours-row">Open 24 / 7</div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="form-panel">
    <h2>Send Us a Message</h2>
    <div class="sub">Fill in the form below — your message goes directly to our team.</div>

    <?php if ($success): ?>
      <div class="alert alert-success">✅ <?= $success ?></div>
      <div style="text-align:center;padding:10px 0;">
        <a href="contact.php" style="display:inline-block;padding:12px 28px;background:var(--green);color:white;border-radius:8px;font-weight:800;text-decoration:none;">Send Another Message</a>
        <br><br>
        <a href="index.php" style="color:var(--green);font-weight:700;text-decoration:none;">← Back to Booking</a>
      </div>
    <?php else: ?>

      <?php if ($error): ?>
        <div class="alert alert-error">⚠️ <?= $error ?></div>
      <?php endif; ?>

      <form method="POST" action="contact.php">

        <!-- Honeypot: hidden from people, tempting to bots -->
        <div style="position:absolute;left:-9999px;top:auto;width:1px;height:1px;overflow:hidden;" aria-hidden="true">
          <label>Leave this field empty</label>
          <input type="text" name="website" tabindex="-1" autocomplete="off">
        </div>

        <div class="two-col">
          <div class="fg">
            <label>First Name</label>
            <input type="text" name="first_name" placeholder="e.g. Jane"
                   value="<?= htmlspecialchars($_POST['first_name'] ?? '') ?>" required>
          </div>
          <div class="fg">
            <label>Last Name</label>
            <input type="text" name="last_name" placeholder="e.g. Wanjiku"
                   value="<?= htmlspecialchars($_POST['last_name'] ?? '') ?>" required>
          </div>
        </div>

        <div class="two-col">
          <div class="fg">
            <label>Email Address</label>
            <input type="email" name="email" placeholder="e.g. jane@email.com"
                   value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
          </div>
          <div class="fg">
            <label>Mobile Number</label>
            <div class="phone-row">
              <span class="phone-prefix">+254</span>
              <input type="text" name="phone" class="phone-input"
                     placeholder="712345678" inputmode="numeric" maxlength="9"
                     value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>" required>
            </div>
          </div>
        </div>

        <div class="fg">
          <label>Subject</label>
          <input type="text" name="subject" placeholder="e.g. Booking enquiry, Lost item, Complaint..."
                 value="<?= htmlspecialchars($_POST['subject'] ?? '') ?>" required>
        </div>

        <div class="fg">
          <label>Your Message</label>
          <textarea name="message" placeholder="Tell us how we can help you..." minlength="10" maxlength="5000" required><?= htmlspecialchars($_POST['message'] ?? '') ?></textarea>
        </div>

        <button type="submit" class="submit-btn">Send Message →</button>

      </form>
    <?php endif; ?>
  </div>

</div>

<footer>
  &copy; 2026 Demo Coaches (fictional demo) &nbsp;|&nbsp; Designed by <span>Mejah Titus Guthua</span> &nbsp;&bull;&nbsp; NIBS Technical College
</footer>

</body>
</html>