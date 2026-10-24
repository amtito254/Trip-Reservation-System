<?php
// =============================================
// expire_pending.php — Release unpaid public bookings (run from cron / Task Scheduler)
//
//   Linux cron (every 10 minutes):   */10 * * * *  php /path/to/expire_pending.php
//   Windows Task Scheduler action:   C:\xampp\php\php.exe  C:\xampp\htdocs\demo_coaches\expire_pending.php
//
// The site ALSO runs this same cleanup whenever seats are viewed or booked, so the cron job is a
// belt-and-braces extra. Hold rules are set in helpers.php (HOLD_* constants).
// =============================================
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(); }   // never runnable from a browser

include __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

$n = release_expired_bookings($conn);
echo date('Y-m-d H:i:s') . " — released $n unpaid booking(s)\n";
