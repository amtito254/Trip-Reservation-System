<?php

include 'db.php';
require_once 'helpers.php';
require_staff(['admin']);          // admin only: a clerk is redirected to clerk.php
release_expired_bookings($conn);   // free unpaid public bookings that have run out of time

// --- HANDLE CONFIRM BOOKING (POST only) ---
if (isset($_POST['confirm_booking'])) {
 $id = intval($_POST['confirm_booking']);
 $bus_back = isset($_POST['bus_id']) ? '&bus_id='.intval($_POST['bus_id']) : '';
 mysqli_query($conn, "UPDATE bookings SET status='confirmed' WHERE id=$id");
 header("Location: admin.php?tab=bookings{$bus_back}&msg=Booking+confirmed!");
 exit();
}

// --- HANDLE DELETE BOOKING (POST only) ---
if (isset($_POST['delete_booking'])) {
 $id = intval($_POST['delete_booking']);
 $bus_back = isset($_POST['bus_id']) ? '&bus_id='.intval($_POST['bus_id']) : '';
 mysqli_query($conn, "DELETE FROM bookings WHERE id=$id");
 header("Location: admin.php?tab=bookings{$bus_back}&msg=Booking+deleted+successfully!");
 exit();
}

// --- HANDLE FEEDBACK REPLY ---
if (isset($_POST['reply_feedback'])) {
 $fid = intval($_POST['feedback_id']);
 $reply = mysqli_real_escape_string($conn, trim($_POST['reply_text']));
 if ($fid > 0 && strlen($reply) >= 2) {
 mysqli_query($conn, "UPDATE feedback SET admin_reply='$reply', replied_at=NOW(), is_read=1 WHERE id=$fid");
 }
 header("Location: admin.php?tab=feedback&msg=Reply+sent+successfully!");
 exit();
}

// --- MARK FEEDBACK AS READ ---
if (isset($_GET['mark_read'])) {
 $fid = intval($_GET['mark_read']);
 mysqli_query($conn, "UPDATE feedback SET is_read=1 WHERE id=$fid");
 exit(); // AJAX call, no redirect needed
}

// --- DELETE FEEDBACK (POST only) ---
if (isset($_POST['del_feedback'])) {
 $fid = intval($_POST['del_feedback']);
 mysqli_query($conn, "DELETE FROM feedback WHERE id=$fid");
 header("Location: admin.php?tab=feedback&msg=Feedback+deleted.");
 exit();
}

// (Template / auto-generate system removed — admin adds trips manually)

// --- ADD SINGLE TRIP (manual override) ---
if (isset($_POST['add_trip'])) {
 $bus_id = intval($_POST['bus_id']);
 $origin = mysqli_real_escape_string($conn, strtoupper(trim($_POST['origin'])));
 $dest = mysqli_real_escape_string($conn, strtoupper(trim($_POST['destination'])));
 $via = mysqli_real_escape_string($conn, trim($_POST['via'] ?? ''));
 $route = $origin . ' - ' . $dest;
 $dep_time = mysqli_real_escape_string($conn, $_POST['departure_time']);
 $trip_date = mysqli_real_escape_string($conn, $_POST['trip_date']);
 $fare = floatval($_POST['fare']);
 $min_date = date('Y-m-d');
 $max_date = date('Y-m-d', strtotime('+28 days'));
 if ($bus_id && $origin && $dest && $origin !== $dest && $dep_time && $trip_date >= $min_date && $trip_date <= $max_date && $fare >= 100 && $fare <= 2500) {
 mysqli_query($conn, "INSERT INTO trips (bus_id,origin,destination,via,route,departure_time,trip_date,fare)
 VALUES ($bus_id,'$origin','$dest','$via','$route','$dep_time','$trip_date',$fare)");
 header("Location: admin.php?tab=trips&msg=Trip+added+successfully!");
 } else {
 header("Location: admin.php?tab=trips&msg=Error:+Please+fill+all+fields+correctly.");
 }
 exit();
}

// --- DELETE SINGLE TRIP (POST only) ---
if (isset($_POST['del_trip'])) {
 $tid = intval($_POST['del_trip']);
 $has_bookings = mysqli_num_rows(mysqli_query($conn, "SELECT id FROM bookings WHERE trip_id=$tid"));
 if ($has_bookings === 0) {
 mysqli_query($conn, "DELETE FROM trips WHERE id=$tid");
 header("Location: admin.php?tab=trips&msg=Trip+deleted.");
 } else {
 header("Location: admin.php?tab=trips&msg=Cannot+delete+a+trip+that+has+existing+bookings.");
 }
 exit();
}

// --- EDIT SINGLE TRIP ---
if (isset($_POST['edit_trip'])) {
 $tid      = intval($_POST['trip_id']);
 $bus_id   = intval($_POST['bus_id']);
 $origin   = mysqli_real_escape_string($conn, strtoupper(trim($_POST['origin'])));
 $dest     = mysqli_real_escape_string($conn, strtoupper(trim($_POST['destination'])));
 $via      = mysqli_real_escape_string($conn, trim($_POST['via'] ?? ''));
 $route    = $origin . ' - ' . $dest;
 $dep_time = mysqli_real_escape_string($conn, $_POST['departure_time']);
 $trip_date= mysqli_real_escape_string($conn, $_POST['trip_date']);
 $fare     = floatval($_POST['fare']);
 if ($tid && $bus_id && $origin && $dest && $origin !== $dest && $dep_time && $trip_date && $fare >= 100 && $fare <= 2500) {
  mysqli_query($conn, "UPDATE trips SET bus_id=$bus_id, origin='$origin', destination='$dest',
   via='$via', route='$route', departure_time='$dep_time', trip_date='$trip_date', fare=$fare
   WHERE id=$tid");
  header("Location: admin.php?tab=trips&msg=Trip+updated+successfully!");
 } else {
  header("Location: admin.php?tab=trips&msg=Error:+Please+check+all+fields+(fare+must+be+100-2500).");
 }
 exit();
}

// --- DATA ---
$bookings = mysqli_query($conn, "
 SELECT b.*, t.route, t.origin, t.destination, t.departure_time, t.fare, t.trip_date, bu.plate_number, bu.id AS bus_id
 FROM bookings b
 JOIN trips t ON b.trip_id = t.id
 JOIN buses bu ON t.bus_id = bu.id
 ORDER BY b.booked_at DESC
");

$feedbacks = mysqli_query($conn, "SELECT * FROM feedback ORDER BY submitted_at DESC");
$unread_count = mysqli_num_rows(mysqli_query($conn, "SELECT id FROM feedback WHERE is_read=0"));
$active_tab = $_GET['tab'] ?? 'bookings';

// --- BUS REVENUE SUMMARY (for bookings bus cards) ---
// buses LEFT JOIN trips LEFT JOIN bookings produces one row per trip even without bookings.
// Using b.id IS NOT NULL guards ensure we only sum fares for rows that have real bookings.
$bus_revenue = mysqli_query($conn, "
 SELECT
   bu.id AS bus_id,
   bu.plate_number,
   bu.bus_type,
   COUNT(b.id)                                                                        AS total_passengers,
   COALESCE(SUM(CASE WHEN b.id IS NOT NULL THEN t.fare ELSE 0 END), 0)               AS total_revenue,
   COALESCE(SUM(CASE WHEN b.id IS NOT NULL AND b.status='confirmed' THEN t.fare ELSE 0 END), 0) AS confirmed_revenue,
   COALESCE(SUM(CASE WHEN b.id IS NOT NULL AND b.status='pending'   THEN t.fare ELSE 0 END), 0) AS pending_revenue,
   COUNT(CASE WHEN b.status='confirmed' THEN 1 END)                                  AS confirmed_count,
   COUNT(CASE WHEN b.status='pending'   THEN 1 END)                                  AS pending_count
 FROM buses bu
 LEFT JOIN trips t    ON t.bus_id  = bu.id
 LEFT JOIN bookings b ON b.trip_id = t.id
 GROUP BY bu.id, bu.plate_number, bu.bus_type
 ORDER BY bu.plate_number
");

// --- PER-BUS TRIP BREAKDOWN (clicked bus detail) ---
// Group bookings by bus_id → trip_date → list of passengers
$selected_bus_id = isset($_GET['bus_id']) ? intval($_GET['bus_id']) : null;
$bus_detail = [];
$selected_bus_info = null;
if ($selected_bus_id) {
 $selected_bus_info = mysqli_fetch_assoc(mysqli_query($conn,
  "SELECT id, plate_number, bus_type FROM buses WHERE id=$selected_bus_id"
 ));
 // All bookings for this bus, grouped will be done in PHP
 $detail_res = mysqli_query($conn, "
  SELECT b.*, t.origin, t.destination, t.departure_time, t.fare, t.trip_date, t.route
  FROM bookings b
  JOIN trips t ON b.trip_id = t.id
  WHERE t.bus_id = $selected_bus_id
  ORDER BY t.trip_date DESC, t.departure_time ASC, b.seat_number ASC
 ");
 while ($row = mysqli_fetch_assoc($detail_res)) {
  $key = $row['trip_date'];
  if (!isset($bus_detail[$key])) {
   $bus_detail[$key] = [
    'date'        => $row['trip_date'],
    'departure'   => substr($row['departure_time'], 0, 5),
    'origin'      => $row['origin'],
    'destination' => $row['destination'],
    'passengers'  => [],
    'total'       => 0,
    'confirmed'   => 0,
    'pending'     => 0,
   ];
  }
  $bus_detail[$key]['passengers'][] = $row;
  $bus_detail[$key]['total']       += floatval($row['fare']);
  if ($row['status'] === 'confirmed') $bus_detail[$key]['confirmed'] += floatval($row['fare']);
  else                                 $bus_detail[$key]['pending']   += floatval($row['fare']);
 }
}

// Trips tab data
$all_trips = mysqli_query($conn, "
 SELECT t.*, b.plate_number, b.bus_type, b.total_seats,
 (SELECT COUNT(DISTINCT bk.seat_number) FROM bookings bk JOIN trips t2 ON bk.trip_id = t2.id
   WHERE t2.bus_id = t.bus_id AND t2.trip_date = t.trip_date) AS booked_seats,
 (SELECT COUNT(*) FROM bookings bk2 WHERE bk2.trip_id = t.id) AS own_bookings
 FROM trips t JOIN buses b ON t.bus_id = b.id
 ORDER BY t.trip_date ASC, t.departure_time ASC
");
$all_buses = mysqli_query($conn, "SELECT id, plate_number, bus_type FROM buses ORDER BY plate_number");
?>
<!DOCTYPE html>
<html lang="en">
<head>
 <meta charset="UTF-8">
 <title>Admin Panel — Demo Coaches</title>
 <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Nunito:wght@400;600;700;800;900&display=swap" rel="stylesheet"/>
 <style>
 *,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}
 :root{--green:#1B7A3E;--green-light:#23A354;--gold:#F5C518;--dark:#0D1A11;--blue:#1a237e;--red:#c62828;}
 body{font-family:'Nunito',sans-serif;background:#f0f2f5;color:#333;}

 /* HEADER */
 .header{background:var(--blue);color:white;padding:0 30px;height:66px;display:flex;justify-content:space-between;align-items:center;box-shadow:0 2px 16px rgba(0,0,0,.3);}
 .h-logo{font-family:'Bebas Neue',sans-serif;font-size:22px;letter-spacing:3px;color:white;}
 .h-logo span{color:var(--gold);}
 .h-sub{color:rgba(255,255,255,.45);font-size:12px;margin-top:2px;}
 .h-right{display:flex;align-items:center;gap:14px;}
 .h-user{color:rgba(255,255,255,.7);font-size:13px;}
 .h-link{color:var(--gold);text-decoration:none;font-size:13px;font-weight:700;}
 .h-logout{background:var(--red);color:white;text-decoration:none;padding:7px 16px;border-radius:6px;font-size:13px;font-weight:700;}

 /* TABS */
 .tabs-bar{background:white;border-bottom:2px solid #e0e8ee;padding:0 30px;display:flex;}
 .tab-btn{padding:15px 26px;border:none;background:none;font-family:'Nunito',sans-serif;font-size:14px;font-weight:800;color:#999;cursor:pointer;border-bottom:3px solid transparent;margin-bottom:-2px;transition:all .2s;text-decoration:none;display:flex;align-items:center;gap:8px;}
 .tab-btn:hover{color:var(--blue);}
 .tab-btn.active{color:var(--blue);border-bottom-color:var(--blue);}
 .badge{background:var(--red);color:white;border-radius:20px;padding:2px 8px;font-size:11px;font-weight:900;}
 .badge.g{background:var(--green);}

 /* BODY */
 .container{padding:28px 30px;}
 .msg{background:#c8e6c9;border-left:4px solid #388e3c;padding:12px 18px;margin-bottom:20px;border-radius:4px;color:#1b5e20;font-weight:700;}

 /* STATS */
 .stats{display:flex;gap:14px;margin-bottom:22px;flex-wrap:wrap;}
 .scard{background:white;border-radius:10px;padding:16px 26px;box-shadow:0 2px 8px rgba(0,0,0,.07);text-align:center;min-width:120px;}
 .scard .n{font-size:32px;font-weight:900;color:var(--blue);}
 .scard .l{font-size:11px;color:#999;font-weight:700;text-transform:uppercase;letter-spacing:.5px;margin-top:2px;}
 .scard.g .n{color:var(--green);}
 .scard.r .n{color:var(--red);}
 .scard.o .n{color:#e65100;}

 /* TABLE */
 table{width:100%;border-collapse:collapse;background:white;border-radius:10px;overflow:hidden;box-shadow:0 2px 10px rgba(0,0,0,.07);}
 th{background:var(--blue);color:white;padding:13px 11px;text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.5px;}
 td{padding:11px 11px;border-bottom:1px solid #eef0f2;font-size:13px;vertical-align:middle;}
 tr:last-child td{border:none;}
 tr:hover td{background:#f8fafc;}
 .status-pending{color:#e65100;font-weight:800;}
 .status-confirmed{color:#388e3c;font-weight:800;}

 /* BUTTONS */
 .btn{padding:6px 12px;border:none;border-radius:5px;cursor:pointer;font-size:12px;font-weight:700;text-decoration:none;display:inline-block;margin:2px;font-family:'Nunito',sans-serif;}
 .bc{background:#388e3c;color:white;}
 .br{background:var(--red);color:white;}
 .bb{background:var(--blue);color:white;}
 .btn:hover{opacity:.85;}

 /* FEEDBACK */
 .fb-unread td{background:#fffde7 !important;}
 .fb-unread td:first-child{border-left:4px solid var(--gold);}
 .tag-replied{background:#e8f5e9;color:#388e3c;border-radius:4px;padding:2px 8px;font-size:11px;font-weight:800;}
 .tag-pending{background:#fff3e0;color:#e65100;border-radius:4px;padding:2px 8px;font-size:11px;font-weight:800;}

 /* MODAL */
 .mo{display:none;position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:5000;align-items:center;justify-content:center;padding:20px;}
 .mo.open{display:flex;}
 .mbox{background:white;border-radius:16px;width:100%;max-width:600px;max-height:90vh;overflow-y:auto;box-shadow:0 24px 60px rgba(0,0,0,.3);}
 .mhdr{background:var(--blue);color:white;padding:20px 24px;border-radius:16px 16px 0 0;display:flex;justify-content:space-between;align-items:center;}
 .mhdr h3{font-family:'Bebas Neue',sans-serif;font-size:22px;letter-spacing:1.5px;}
 .mclose{background:rgba(255,255,255,.15);border:none;color:white;width:32px;height:32px;border-radius:50%;font-size:18px;cursor:pointer;}
 .mbody{padding:24px;}

 .pills{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px;}
 .pill{background:#f0f4f8;border-radius:6px;padding:5px 12px;font-size:13px;font-weight:700;}
 .pill span{color:#888;font-weight:400;margin-right:4px;}

 .bubble{background:#f5f7f9;border-radius:10px;padding:14px 18px;margin-bottom:14px;border-left:4px solid #ccc;font-size:14px;line-height:1.7;}
 .bubble.cust{border-left-color:var(--blue);}
 .bubble.admin{border-left-color:var(--green);background:#e8f5e9;}
 .blbl{font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.8px;margin-bottom:5px;}
 .bubble.cust .blbl{color:var(--blue);}
 .bubble.admin .blbl{color:var(--green);}

 .reply-box{width:100%;padding:12px 15px;border:2px solid #e0ece4;border-radius:8px;font-family:'Nunito',sans-serif;font-size:14px;resize:vertical;min-height:100px;outline:none;transition:border-color .2s;}
 .reply-box:focus{border-color:var(--blue);}
 .reply-lbl{display:block;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:1px;color:#8eac99;margin-bottom:7px;}
 .send-btn{width:100%;padding:13px;background:var(--blue);color:white;border:none;border-radius:8px;font-family:'Nunito',sans-serif;font-weight:900;font-size:15px;cursor:pointer;margin-top:10px;transition:background .2s;}
 .send-btn:hover{background:#283593;}

 .no-data{text-align:center;padding:60px;color:#bbb;font-size:15px;}

 /* BUS REVENUE CARDS */
 .bus-cards-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:20px;margin-bottom:28px;}
 .bus-rev-card{background:white;border-radius:14px;padding:24px 26px;box-shadow:0 4px 18px rgba(0,0,0,.08);cursor:pointer;transition:all .25s;border:2px solid transparent;position:relative;overflow:hidden;}
 .bus-rev-card:hover{border-color:var(--blue);transform:translateY(-3px);box-shadow:0 10px 32px rgba(26,35,126,.15);}
 .bus-rev-card::before{content:'';position:absolute;top:0;left:0;right:0;height:4px;background:linear-gradient(90deg,var(--blue),#42a5f5);}
 .bus-rev-card .brc-plate{font-family:'Bebas Neue',sans-serif;font-size:28px;letter-spacing:3px;color:var(--dark);margin-bottom:2px;}
 .bus-rev-card .brc-type{font-size:12px;color:#999;font-weight:700;text-transform:uppercase;letter-spacing:1px;margin-bottom:18px;}
 .bus-rev-card .brc-revenue{font-family:'Bebas Neue',sans-serif;font-size:38px;color:var(--green);letter-spacing:1px;line-height:1;}
 .bus-rev-card .brc-rev-label{font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:1px;color:#aaa;margin-bottom:14px;}
 .bus-rev-card .brc-row{display:flex;justify-content:space-between;padding-top:14px;border-top:1px solid #f0f4f0;gap:10px;}
 .bus-rev-card .brc-stat{text-align:center;flex:1;}
 .bus-rev-card .brc-stat .val{font-size:18px;font-weight:900;}
 .bus-rev-card .brc-stat .lbl{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.8px;color:#aaa;margin-top:2px;}
 .bus-rev-card .brc-arrow{position:absolute;bottom:20px;right:22px;font-size:20px;color:#ccc;transition:color .2s;}
 .bus-rev-card:hover .brc-arrow{color:var(--blue);}
 .bus-rev-card.no-bookings{cursor:default;}
 .bus-rev-card.no-bookings:hover{border-color:transparent;transform:none;box-shadow:0 4px 18px rgba(0,0,0,.08);}

 /* BUS DETAIL VIEW */
 .detail-back{display:inline-flex;align-items:center;gap:8px;background:none;border:2px solid #e0e8ee;color:var(--gray);padding:9px 18px;border-radius:8px;font-family:'Nunito',sans-serif;font-weight:700;font-size:14px;cursor:pointer;text-decoration:none;transition:all .2s;margin-bottom:22px;}
 .detail-back:hover{border-color:var(--blue);color:var(--blue);}
 .detail-header{background:var(--blue);border-radius:14px;padding:24px 28px;margin-bottom:24px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:16px;}
 .detail-header .dh-plate{font-family:'Bebas Neue',sans-serif;font-size:34px;color:white;letter-spacing:3px;}
 .detail-header .dh-type{font-size:13px;color:rgba(255,255,255,.55);font-weight:700;margin-top:2px;}
 .detail-header .dh-total{text-align:right;}
 .detail-header .dh-total .amt{font-family:'Bebas Neue',sans-serif;font-size:42px;color:var(--gold);letter-spacing:1px;line-height:1;}
 .detail-header .dh-total .lbl{font-size:12px;color:rgba(255,255,255,.5);font-weight:700;text-transform:uppercase;letter-spacing:1px;}
 .detail-trip-block{background:white;border-radius:12px;margin-bottom:20px;box-shadow:0 2px 10px rgba(0,0,0,.07);overflow:hidden;}
 .dtb-head{background:#f8fafc;border-bottom:2px solid #eef0f4;padding:16px 22px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;}
 .dtb-head .dtb-date{font-family:'Bebas Neue',sans-serif;font-size:20px;color:var(--blue);letter-spacing:1.5px;}
 .dtb-head .dtb-route{font-size:13px;font-weight:800;color:#555;display:flex;align-items:center;gap:8px;}
 .dtb-head .dtb-route .arr{color:#ccc;}
 .dtb-head .dtb-meta{display:flex;gap:20px;align-items:center;flex-wrap:wrap;}
 .dtb-head .dtb-chip{background:white;border:1px solid #e0e8ee;border-radius:20px;padding:4px 14px;font-size:12px;font-weight:800;}
 .dtb-head .dtb-chip.revenue{background:rgba(27,122,62,.1);color:var(--green);border-color:rgba(27,122,62,.2);}
 .dtb-head .dtb-chip.confirmed{background:rgba(26,35,126,.07);color:var(--blue);border-color:rgba(26,35,126,.15);}
 .dtb-head .dtb-chip.pending{background:#fff8e1;color:#f57f17;border-color:#ffe082;}
 

 /* TRIPS TAB */
 .section-card{background:white;border-radius:12px;padding:24px 28px;margin-bottom:24px;box-shadow:0 2px 10px rgba(0,0,0,.07);}
 .section-card h3{font-family:'Bebas Neue',sans-serif;font-size:20px;letter-spacing:1px;color:var(--blue);margin-bottom:6px;}
 .section-card .sh{font-size:13px;color:#999;margin-bottom:18px;}
 .trip-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:14px;align-items:end;}
 .tfield label{display:block;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:1px;color:#888;margin-bottom:6px;}
 .tfield input,.tfield select{width:100%;padding:10px 13px;border:2px solid #e0e8ee;border-radius:7px;font-family:'Nunito',sans-serif;font-size:14px;color:#333;outline:none;transition:border-color .2s;}
 .tfield input:focus,.tfield select:focus{border-color:var(--blue);}
 .tadd-btn{width:100%;padding:11px;background:var(--green);color:white;border:none;border-radius:7px;font-family:'Nunito',sans-serif;font-weight:900;font-size:14px;cursor:pointer;transition:background .2s;}
 .tadd-btn:hover{background:#155c2e;}
 .tgen-btn{display:inline-flex;align-items:center;gap:8px;padding:11px 22px;background:var(--blue);color:white;border:none;border-radius:7px;font-family:'Nunito',sans-serif;font-weight:900;font-size:14px;cursor:pointer;text-decoration:none;transition:background .2s;}
 .tgen-btn:hover{background:#283593;}
 .trip-status-future{color:#1a237e;font-weight:800;}
 .trip-status-today{color:#e65100;font-weight:800;}
 .trip-status-past{color:#aaa;font-weight:700;}
 .tpl-active{color:#388e3c;font-weight:800;}
 .tpl-inactive{color:#aaa;font-weight:700;}
 .run-badge{display:inline-block;background:#e8f0fe;color:#1a237e;border-radius:4px;padding:2px 8px;font-size:11px;font-weight:800;}
 </style>
</head>
<body>

<!-- HEADER -->
<div class="header">
 <div>
 <div class="h-logo"><span>DEMO</span> COACHES</div>
 <div class="h-sub">Office Admin Panel</div>
 </div>
 <div class="h-right">
 <span class="h-user">Logged in as: <strong><?= htmlspecialchars($_SESSION['admin_name']) ?></strong></span>
 <a href="admin_change_password.php" class="h-link" style="background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.15);padding:6px 14px;border-radius:6px;color:rgba(255,255,255,.8);">Change Password</a>
 <a href="clerk.php" class="h-link" style="background:rgba(245,197,24,.15);border:1px solid rgba(245,197,24,.3);padding:6px 14px;border-radius:6px;">Clerk Dashboard</a>
 <a href="#" class="h-logout"
 onclick="showLogoutConfirm(event)">Logout</a>
 </div>
</div>

<!-- TABS -->
<div class="tabs-bar">
 <a href="admin.php?tab=bookings" class="tab-btn <?= $active_tab==='bookings'?'active':'' ?>">
 Bookings
 <?php $tb=mysqli_num_rows($bookings); if($tb>0): ?>
 <span class="badge g"><?= $tb ?></span>
 <?php endif; mysqli_data_seek($bookings,0); ?>
 </a>
 <a href="admin.php?tab=feedback" class="tab-btn <?= $active_tab==='feedback'?'active':'' ?>">
 Customer Feedback
 <?php if ($unread_count > 0): ?>
 <span class="badge"><?= $unread_count ?> new</span>
 <?php endif; ?>
 </a>
 <a href="admin.php?tab=trips" class="tab-btn <?= $active_tab==='trips'?'active':'' ?>">
 Manage Trips
 </a>
</div>

<div class="container">

 <?php if (isset($_GET['msg'])): ?>
 <div class="msg"><?= htmlspecialchars($_GET['msg']) ?></div>
 <?php endif; ?>

 <?php if ($active_tab === 'bookings'): ?>
 <!-- ═══ BOOKINGS TAB ═══ -->
 <?php
 $total_res = mysqli_num_rows($bookings);
 $confirmed = mysqli_num_rows(mysqli_query($conn,"SELECT id FROM bookings WHERE status='confirmed'"));
 $pending   = mysqli_num_rows(mysqli_query($conn,"SELECT id FROM bookings WHERE status='pending'"));
 $grand_total = mysqli_fetch_assoc(mysqli_query($conn,"SELECT SUM(t.fare) AS gt FROM bookings b JOIN trips t ON b.trip_id=t.id"))['gt'] ?? 0;
 mysqli_data_seek($bookings, 0);
 ?>

 <?php if ($selected_bus_id && $selected_bus_info): ?>
 <!-- ── BUS DETAIL VIEW ── -->

 <!-- Overall totals for this bus -->
 <?php
 $bus_grand = 0; $bus_confirmed = 0; $bus_pending = 0; $bus_pax = 0;
 $bus_confirmed_count = 0; $bus_pending_count = 0;
 foreach ($bus_detail as $d) {
  $bus_grand     += $d['total'];
  $bus_confirmed += $d['confirmed'];
  $bus_pending   += $d['pending'];
  $bus_pax       += count($d['passengers']);
  foreach ($d['passengers'] as $p) {
   if ($p['status'] === 'confirmed') $bus_confirmed_count++;
   else                               $bus_pending_count++;
  }
 }
 ?>

 <a href="admin.php?tab=bookings" class="detail-back">← All Buses</a>

 <div class="detail-header">
  <div>
   <div class="dh-plate"><?= htmlspecialchars($selected_bus_info['plate_number']) ?></div>
   <div class="dh-type"><?= htmlspecialchars($selected_bus_info['bus_type']) ?></div>
  </div>
  <div style="display:flex;gap:30px;align-items:center;flex-wrap:wrap;">
   <div style="text-align:center;">
    <div style="font-family:'Bebas Neue',sans-serif;font-size:28px;color:white;"><?= $bus_pax ?></div>
    <div style="font-size:11px;color:rgba(255,255,255,.45);font-weight:800;text-transform:uppercase;letter-spacing:.8px;">Passengers</div>
   </div>
   <div style="text-align:center;">
    <div style="font-family:'Bebas Neue',sans-serif;font-size:28px;color:#a5d6a7;"><?= $bus_confirmed_count ?></div>
    <div style="font-size:11px;color:rgba(255,255,255,.45);font-weight:800;text-transform:uppercase;letter-spacing:.8px;">Confirmed</div>
   </div>
   <div style="text-align:center;">
    <div style="font-family:'Bebas Neue',sans-serif;font-size:28px;color:#ffe082;"><?= $bus_pending_count ?></div>
    <div style="font-size:11px;color:rgba(255,255,255,.45);font-weight:800;text-transform:uppercase;letter-spacing:.8px;">Pending</div>
   </div>
   <div class="dh-total">
    <div class="amt">KSh <?= number_format($bus_grand, 0) ?></div>
    <div class="lbl">Total Revenue Collected</div>
   </div>
  </div>
 </div>

 <?php if (empty($bus_detail)): ?>
  <div class="no-data">No bookings found for this bus.</div>
 <?php else: ?>
  <?php foreach ($bus_detail as $trip_date => $day): ?>
  <div class="detail-trip-block">
   <!-- Trip day header -->
   <div class="dtb-head">
    <div>
     <div class="dtb-date">
      <?= date('l, d F Y', strtotime($trip_date)) ?>
     </div>
     <div class="dtb-route">
      <span style="color:var(--green);font-weight:900;"><?= htmlspecialchars($day['origin']) ?></span>
      <span class="arr">→</span>
      <span style="color:var(--red);font-weight:900;"><?= htmlspecialchars($day['destination']) ?></span>
      <span style="color:#aaa;font-weight:600;">· Dep. <?= $day['departure'] ?></span>
     </div>
    </div>
    <div class="dtb-meta">
     <span class="dtb-chip"><?= count($day['passengers']) ?> passenger<?= count($day['passengers'])!=1?'s':'' ?></span>
     <span class="dtb-chip confirmed">KSh <?= number_format($day['confirmed'], 0) ?> confirmed</span>
     <span class="dtb-chip pending">KSh <?= number_format($day['pending'], 0) ?> pending</span>
     <span class="dtb-chip revenue" style="font-size:13px;">KSh <?= number_format($day['total'], 0) ?> total</span>
    </div>
   </div>
   <!-- Passengers table -->
   <div style="overflow-x:auto;">
   <table style="border-radius:0;box-shadow:none;">
    <tr>
     <th>Ref</th><th>Passenger</th><th>ID No.</th><th>Phone</th>
     <th>Boards At</th><th>Alights At</th><th>Seat</th>
     <th>Fare (KSh)</th><th>Payment</th><th>Status</th><th>Booked At</th><th>Actions</th>
    </tr>
    <?php foreach ($day['passengers'] as $b): ?>
    <tr>
     <td><strong style="font-size:12px;letter-spacing:.5px;"><?= htmlspecialchars($b['booking_ref']) ?></strong></td>
     <td><strong><?= htmlspecialchars($b['full_name']) ?></strong></td>
     <td><?= htmlspecialchars($b['id_number']) ?></td>
     <td><?= htmlspecialchars($b['phone_number']) ?></td>
     <td><strong style="color:var(--green);"><?= htmlspecialchars($b['origin']) ?></strong></td>
     <td><strong style="color:var(--red);"><?= htmlspecialchars($b['destination']) ?></strong></td>
     <td><strong><?= $b['seat_number'] ?></strong></td>
     <td><?= number_format($b['fare'], 2) ?></td>
     <td style="font-size:12px;"><?= str_replace('_',' ',$b['payment_method']) ?></td>
     <td class="status-<?= $b['status'] ?>"><?= strtoupper($b['status']) ?></td>
     <td style="font-size:12px;white-space:nowrap;"><?= date("d/m/Y H:i", strtotime($b['booked_at'])) ?></td>
     <td>
      <?php if ($b['status']==='pending'): ?>
      <form method="POST" action="admin.php?tab=bookings&bus_id=<?= $selected_bus_id ?>" style="display:inline;">
       <input type="hidden" name="confirm_booking" value="<?= $b['id'] ?>"/>
       <input type="hidden" name="bus_id" value="<?= $selected_bus_id ?>"/>
       <button type="submit" class="btn bc" onclick="return brandConfirm(event, this.form, 'Confirm this booking?')">Confirm</button>
      </form>
      <?php endif; ?>
      <form method="POST" action="admin.php?tab=bookings&bus_id=<?= $selected_bus_id ?>" style="display:inline;">
       <input type="hidden" name="delete_booking" value="<?= $b['id'] ?>"/>
       <input type="hidden" name="bus_id" value="<?= $selected_bus_id ?>"/>
       <button type="submit" class="btn br" onclick="return brandConfirm(event, this.form, 'Delete this booking and free the seat? This cannot be undone.')">Delete</button>
      </form>
     </td>
    </tr>
    <?php endforeach; ?>
   </table>
   </div>
   <!-- Day revenue summary bar -->
   <div style="background:#f8fafc;padding:14px 22px;border-top:1px solid #eef0f4;display:flex;justify-content:flex-end;align-items:center;gap:8px;">
    <span style="font-size:13px;font-weight:800;color:#999;">Day Total:</span>
    <span style="font-family:'Bebas Neue',sans-serif;font-size:22px;color:var(--green);letter-spacing:1px;">
     KSh <?= number_format($day['total'], 0) ?>
    </span>
   </div>
  </div>
  <?php endforeach; ?>

  <!-- Grand total footer -->
  <div style="background:var(--dark);border-radius:12px;padding:20px 28px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:16px;">
   <div style="color:rgba(255,255,255,.55);font-size:13px;font-weight:800;text-transform:uppercase;letter-spacing:1px;">
    All-Time Revenue — <?= htmlspecialchars($selected_bus_info['plate_number']) ?>
   </div>
   <div>
    <span style="font-size:13px;color:#a5d6a7;font-weight:800;">Confirmed: KSh <?= number_format($bus_confirmed,0) ?></span>
    &nbsp;&nbsp;
    <span style="font-size:13px;color:#ffe082;font-weight:800;">Pending: KSh <?= number_format($bus_pending,0) ?></span>
    &nbsp;&nbsp;
    <span style="font-family:'Bebas Neue',sans-serif;font-size:28px;color:var(--gold);letter-spacing:1px;">
     TOTAL: KSh <?= number_format($bus_grand,0) ?>
    </span>
   </div>
  </div>
 <?php endif; ?>

 <?php else: ?>
 <!-- ── BUS SELECTION VIEW ── -->

 <!-- Overall stats -->
 <div class="stats">
  <div class="scard"><div class="n"><?= $total_res ?></div><div class="l">Total Bookings</div></div>
  <div class="scard g"><div class="n"><?= $confirmed ?></div><div class="l">Confirmed</div></div>
  <div class="scard o"><div class="n"><?= $pending ?></div><div class="l">Pending</div></div>
  <div class="scard" style="min-width:160px;">
   <div class="n" style="font-size:22px;color:var(--green);">KSh <?= number_format($grand_total,0) ?></div>
   <div class="l">Total Revenue</div>
  </div>
 </div>

 <div style="font-family:'Bebas Neue',sans-serif;font-size:18px;letter-spacing:1.5px;color:#aaa;margin-bottom:16px;">
  SELECT A BUS TO VIEW PASSENGERS &amp; REVENUE
 </div>

 <?php if ($total_res === 0): ?>
  <div class="no-data">No bookings yet.</div>
 <?php else: ?>
 <div class="bus-cards-grid">
  <?php while ($brc = mysqli_fetch_assoc($bus_revenue)):
   $has = intval($brc['total_passengers']) > 0;
  ?>
  <a href="<?= $has ? 'admin.php?tab=bookings&bus_id='.$brc['bus_id'] : '#' ?>"
     class="bus-rev-card <?= $has?'':'no-bookings' ?>"
     style="text-decoration:none;color:inherit;">
   <div class="brc-plate"><?= htmlspecialchars($brc['plate_number']) ?></div>
   <div class="brc-type"><?= htmlspecialchars($brc['bus_type']) ?></div>

   <?php if ($has): ?>
   <div class="brc-revenue">KSh <?= number_format($brc['total_revenue'], 0) ?></div>
   <div class="brc-rev-label">Total Revenue Collected</div>
   <div class="brc-row">
    <div class="brc-stat">
     <div class="val" style="color:var(--dark);"><?= $brc['total_passengers'] ?></div>
     <div class="lbl">Passengers</div>
    </div>
    <div class="brc-stat">
     <div class="val" style="color:var(--green);"><?= $brc['confirmed_count'] ?></div>
     <div class="lbl">Confirmed</div>
    </div>
    <div class="brc-stat">
     <div class="val" style="color:#e65100;"><?= $brc['pending_count'] ?></div>
     <div class="lbl">Pending</div>
    </div>
    <div class="brc-stat">
     <div class="val" style="color:#1a237e;font-size:14px;">KSh <?= number_format($brc['confirmed_revenue'],0) ?></div>
     <div class="lbl">Confirmed KSh</div>
    </div>
   </div>
   <div class="brc-arrow">→</div>
   <?php else: ?>
   <div style="padding:20px 0;color:#ccc;font-size:14px;font-weight:700;">No bookings yet</div>
   <?php endif; ?>
  </a>
  <?php endwhile; ?>
 </div>
 <?php endif; ?>
 <?php endif; /* end bus_id check */  ?>

 <?php elseif ($active_tab === 'feedback'): ?>
 <!-- ═══ FEEDBACK TAB ═══ -->
 <?php
 $total_fb = mysqli_num_rows($feedbacks);
 $replied_fb = mysqli_num_rows(mysqli_query($conn,"SELECT id FROM feedback WHERE admin_reply IS NOT NULL AND admin_reply != ''"));
 mysqli_data_seek($feedbacks, 0);
 ?>
 <div class="stats">
 <div class="scard"><div class="n"><?= $total_fb ?></div><div class="l">Total Messages</div></div>
 <div class="scard r"><div class="n"><?= $unread_count ?></div><div class="l">Unread</div></div>
 <div class="scard o"><div class="n"><?= $total_fb - $replied_fb ?></div><div class="l">Awaiting Reply</div></div>
 <div class="scard g"><div class="n"><?= $replied_fb ?></div><div class="l">Replied</div></div>
 </div>

 <?php if ($total_fb === 0): ?>
 <div class="no-data">No customer feedback yet.</div>
 <?php else: ?>
 <table>
 <tr>
 <th>Date</th><th>Name</th><th>Email</th><th>Phone</th>
 <th>Subject</th><th>Status</th><th>Actions</th>
 </tr>
 <?php while ($fb = mysqli_fetch_assoc($feedbacks)): ?>
 <tr class="<?= !$fb['is_read']?'fb-unread':'' ?>">
 <td style="white-space:nowrap;"><?= date("d/m/Y H:i",strtotime($fb['submitted_at'])) ?></td>
 <td><strong><?= htmlspecialchars($fb['first_name'].' '.$fb['last_name']) ?></strong></td>
 <td><?= htmlspecialchars($fb['email']) ?></td>
 <td><?= $fb['phone'] ?></td>
 <td><?= htmlspecialchars(mb_strimwidth($fb['subject'],0,38,'...')) ?></td>
 <td>
 <?= $fb['admin_reply']
 ? '<span class="tag-replied">Replied</span>'
 : '<span class="tag-pending">Pending</span>' ?>
 </td>
 <td>
 <button class="btn bb" onclick="openFb(
 <?= $fb['id'] ?>,
 '<?= addslashes(htmlspecialchars($fb['first_name'].' '.$fb['last_name'])) ?>',
 '<?= addslashes(htmlspecialchars($fb['email'])) ?>',
 '<?= $fb['phone'] ?>',
 '<?= addslashes(htmlspecialchars($fb['subject'])) ?>',
 `<?= addslashes(nl2br(htmlspecialchars($fb['message']))) ?>`,
 `<?= addslashes(nl2br(htmlspecialchars($fb['admin_reply']??''))) ?>`,
 '<?= $fb['replied_at']?date('d/m/Y H:i',strtotime($fb['replied_at'])):'' ?>'
 )">View / Reply</button>
 <form method="POST" action="admin.php?tab=feedback" style="display:inline;">
  <input type="hidden" name="del_feedback" value="<?= $fb['id'] ?>"/>
  <button type="submit" class="btn br" onclick="return brandConfirm(event, this.form, 'Delete this feedback message?')">Delete</button>
 </form>
 </td>
 </tr>
 <?php endwhile; ?>
 </table>
 <?php endif; ?>

 <?php elseif ($active_tab === 'trips'): ?>
 <!-- ═══ TRIPS TAB ═══ -->

 <?php $today_str = date('Y-m-d'); ?>

 <!-- BUS LOCATION HELPER -->
 <?php
 $buses_all = mysqli_query($conn, "SELECT id, plate_number FROM buses ORDER BY id");
 $bus_status = [];
 while ($b = mysqli_fetch_assoc($buses_all)) {
     // Priority 1: today's trip
     $last = mysqli_fetch_assoc(mysqli_query($conn,
         "SELECT origin, destination, trip_date FROM trips
          WHERE bus_id = {$b['id']} AND trip_date = '$today_str'
          ORDER BY departure_time DESC LIMIT 1"
     ));
     $trip_type = 'today'; // used for label

     // Priority 2: next upcoming trip (future)
     if (!$last) {
         $last = mysqli_fetch_assoc(mysqli_query($conn,
             "SELECT origin, destination, trip_date FROM trips
              WHERE bus_id = {$b['id']} AND trip_date > '$today_str'
              ORDER BY trip_date ASC, departure_time ASC LIMIT 1"
         ));
         $trip_type = 'upcoming';
     }

     // Priority 3: most recent past trip (fallback only)
     if (!$last) {
         $last = mysqli_fetch_assoc(mysqli_query($conn,
             "SELECT origin, destination, trip_date FROM trips
              WHERE bus_id = {$b['id']} AND trip_date < '$today_str'
              ORDER BY trip_date DESC, departure_time DESC LIMIT 1"
         ));
         $trip_type = 'past';
     }

     if ($last) {
         // Label and arrival text based on which trip type we found
         if ($trip_type === 'today') {
             $when          = 'today';
             $arrival_label = 'tomorrow'; // overnight bus, arrives next day
         } elseif ($trip_type === 'upcoming') {
             $dep_date      = date('d/m/Y', strtotime($last['trip_date']));
             $when          = 'on ' . $dep_date;
             $arrival_label = 'tomorrow'; // will arrive day after departure
         } else {
             // Past fallback — calculate how long ago
             $days_ago = (int)((strtotime($today_str) - strtotime($last['trip_date'])) / 86400);
             $when          = $days_ago === 1 ? 'yesterday' : $days_ago . ' days ago';
             $arrival_label = 'now';
         }

         $bus_status[$b['id']] = [
             'plate'         => $b['plate_number'],
             'origin'        => $last['origin'],
             'dest'          => $last['destination'],
             'when'          => $when,
             'likely'        => $last['destination'],
             'arrival_label' => $arrival_label,
             'trip_type'     => $trip_type,
         ];
     } else {
         $bus_status[$b['id']] = [
             'plate'         => $b['plate_number'],
             'origin'        => null, 'dest' => null,
             'when'          => null, 'likely' => 'Unknown',
             'arrival_label' => 'now',
             'trip_type'     => 'none',
         ];
     }
 }
 ?>
 <div style="display:flex;gap:16px;margin-bottom:24px;flex-wrap:wrap;">
 <?php foreach ($bus_status as $bid => $bs):
     $loc = $bs['likely'];
     $loc_color = ($loc==='NAIROBI') ? '#1a237e' : (($loc==='MOMBASA') ? '#e65100' : (($loc==='UKUNDA') ? '#1B7A3E' : '#888'));
 ?>
 <div style="flex:1;min-width:230px;background:white;border-radius:12px;padding:18px 22px;
             box-shadow:0 2px 10px rgba(0,0,0,.08);border-top:4px solid <?= $loc_color ?>;">
     <div style="font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:1px;color:#aaa;margin-bottom:4px;">Bus</div>
     <div style="font-family:'Bebas Neue',sans-serif;font-size:22px;letter-spacing:2px;color:#222;margin-bottom:10px;">
         <?= htmlspecialchars($bs['plate']) ?>
     </div>
     <?php if ($bs['origin']): ?>
         <div style="font-size:12px;color:#888;margin-bottom:8px;">
             <?= $bs['trip_type'] === 'upcoming' ? 'Departing' : 'Last trip' ?> (<?= $bs['when'] ?>):&nbsp;
             <strong><?= $bs['origin'] ?> → <?= $bs['dest'] ?></strong>
         </div>
         <div style="display:flex;align-items:center;gap:8px;">
             <span style="font-size:11px;font-weight:800;text-transform:uppercase;color:#aaa;">
                 Likely <?= $bs['arrival_label'] ?> in
             </span>
             <span style="background:<?= $loc_color ?>;color:white;border-radius:6px;padding:3px 12px;
                          font-weight:900;font-size:13px;letter-spacing:1px;"><?= $loc ?></span>
         </div>
     <?php else: ?>
         <div style="font-size:13px;color:#bbb;">No trips recorded yet</div>
     <?php endif; ?>
 </div>
 <?php endforeach; ?>
 </div>

 <!-- ADD TRIP FORM -->
 <div class="section-card">
 <h3>Add a Trip</h3>
 <div class="sh">Add a trip for today, tomorrow, or the day after tomorrow.</div>
 <form method="POST" action="admin.php?tab=trips">
 <input type="hidden" name="add_trip" value="1"/>
 <div class="trip-grid">
 <div class="tfield">
 <label>Bus</label>
 <select name="bus_id" required>
  <?php mysqli_data_seek($all_buses, 0); while ($bus = mysqli_fetch_assoc($all_buses)): ?>
 <option value="<?= $bus['id'] ?>">
 <?= htmlspecialchars($bus['plate_number']) ?> (<?= htmlspecialchars($bus['bus_type']) ?>)
 </option>
 <?php endwhile; ?>
 </select>
 </div>
 <div class="tfield">
 <label>From</label>
 <select name="origin" required>
  <option value="NAIROBI">Nairobi</option>
 <option value="MOMBASA">Mombasa</option>
 <option value="UKUNDA">Ukunda</option>
 </select>
 </div>
 <div class="tfield">
 <label>To</label>
 <select name="destination" required>
  <option value="NAIROBI">Nairobi</option>
 <option value="MOMBASA">Mombasa</option>
 <option value="UKUNDA">Ukunda</option>
 </select>
 </div>
 <div class="tfield">
 <label>Via (optional)</label>
 <input type="text" name="via" placeholder="e.g. Mombasa"/>
 </div>
 <div class="tfield">
 <label>Travel Date</label>
 <input type="date" name="trip_date"
 min="<?= date('Y-m-d') ?>"
 max="<?= date('Y-m-d', strtotime('+28 days')) ?>"
 required/>
 </div>
 <div class="tfield">
 <label>Departure Time</label>
 <input type="time" name="departure_time" required/>
 </div>
 <div class="tfield">
 <label>Fare (KSh)</label>
 <input type="number" name="fare" min="100" max="2500" step="1" placeholder="e.g. 1500" required/>
 </div>
 <div class="tfield">
 <label>&nbsp;</label>
 <button type="submit" class="tadd-btn">Add Trip</button>
 </div>
 </div>
 </form>
 </div>

  <!-- 4. UPCOMING TRIPS TABLE -->
 <?php
 $trip_count = mysqli_num_rows($all_trips);
 mysqli_data_seek($all_trips, 0);
 $cnt_future = mysqli_num_rows(mysqli_query($conn, "SELECT id FROM trips WHERE trip_date > '$today_str'"));
 $cnt_today = mysqli_num_rows(mysqli_query($conn, "SELECT id FROM trips WHERE trip_date = '$today_str'"));
 $cnt_past = mysqli_num_rows(mysqli_query($conn, "SELECT id FROM trips WHERE trip_date < '$today_str'"));
 ?>
 <div class="stats">
 <div class="scard"><div class="n"><?= $trip_count ?></div><div class="l">All Trips</div></div>
 <div class="scard g"><div class="n"><?= $cnt_future ?></div><div class="l">Upcoming</div></div>
 <div class="scard o"><div class="n"><?= $cnt_today ?></div><div class="l">Today</div></div>
 <div class="scard r"><div class="n"><?= $cnt_past ?></div><div class="l">Past</div></div>
 </div>

 <?php if ($trip_count === 0): ?>
	 <div class="no-data">No trips yet — use the form above to add one.</div>
 <?php else: ?>
 <table>
 <tr>
 <th>Date</th><th>Bus</th><th>Plate</th><th>Route</th>
 <th>Via</th><th>Departure</th><th>Fare (KSh)</th>
 <th>Seats Booked</th><th>Status</th><th>Action</th>
 </tr>
 <?php while ($tr = mysqli_fetch_assoc($all_trips)):
 $is_past = $tr['trip_date'] < $today_str;
 $is_today = $tr['trip_date'] === $today_str;
 $capacity = intval($tr['total_seats'] ?: DEFAULT_BUS_SEATS);
 $avail = $capacity - intval($tr['booked_seats']);
 if ($is_past) { $sc = 'trip-status-past'; $sl = 'Past'; }
 elseif ($is_today) { $sc = 'trip-status-today'; $sl = 'Today'; }
 else { $sc = 'trip-status-future'; $sl = 'Upcoming'; }
 ?>
 <tr style="<?= $is_past ? 'opacity:.5;' : '' ?>">
 <td><strong><?= date('d/m/Y', strtotime($tr['trip_date'])) ?></strong><br>
 <span style="font-size:11px;color:#aaa;"><?= date('l', strtotime($tr['trip_date'])) ?></span></td>
 <td><?= htmlspecialchars($tr['bus_type']) ?></td>
 <td><strong><?= htmlspecialchars($tr['plate_number']) ?></strong></td>
 <td><?= htmlspecialchars($tr['origin']) ?> → <?= htmlspecialchars($tr['destination']) ?></td>
 <td><?= $tr['via'] ? htmlspecialchars($tr['via']) : '<span style="color:#ccc;">—</span>' ?></td>
 <td><?= substr($tr['departure_time'], 0, 5) ?></td>
 <td><?= number_format($tr['fare'], 2) ?></td>
 <td>
 <strong><?= $tr['booked_seats'] ?></strong> / <?= $capacity ?>
 <span style="font-size:11px;color:<?= $avail>10?'#388e3c':($avail>0?'#e65100':'#c62828') ?>">
 (<?= $avail ?> free)
 </span>
 </td>
 <td class="<?= $sc ?>"><?= $sl ?></td>
 <td>
 <button class="btn bb" onclick="openEditTrip(
  <?= $tr['id'] ?>,
  <?= $tr['bus_id'] ?>,
  '<?= htmlspecialchars($tr['origin']) ?>',
  '<?= htmlspecialchars($tr['destination']) ?>',
  '<?= htmlspecialchars($tr['via'] ?? '') ?>',
  '<?= $tr['trip_date'] ?>',
  '<?= substr($tr['departure_time'], 0, 5) ?>',
  <?= $tr['fare'] ?>
 )">Edit</button>
 <?php if (intval($tr['own_bookings']) === 0): ?>
 <form method="POST" action="admin.php?tab=trips" style="display:inline;">
  <input type="hidden" name="del_trip" value="<?= $tr['id'] ?>"/>
  <button type="submit" class="btn br" onclick="return brandConfirm(event, this.form, 'Delete this trip? This cannot be undone.')">Delete</button>
 </form>
 <?php else: ?>
 <span style="font-size:11px;color:#aaa;">Has bookings</span>
 <?php endif; ?>
 </td>
 </tr>
 <?php endwhile; ?>
 </table>
 <?php endif; ?>

 <?php endif; ?>

</div>

<!-- FEEDBACK MODAL -->
<div class="mo" id="fbModal">
 <div class="mbox">
 <div class="mhdr">
 <h3>Customer Message</h3>
 <button class="mclose" onclick="closeFb()">×</button>
 </div>
 <div class="mbody">
 <div class="pills" id="fbPills"></div>

 <div class="bubble cust">
 <div class="blbl">Customer Message</div>
 <div id="fbMsg"></div>
 </div>

 <div id="fbReplyWrap" style="display:none;">
 <div class="bubble admin">
 <div class="blbl">Your Reply &nbsp;<span id="fbReplyDate" style="color:#888;font-weight:400;font-size:11px;"></span></div>
 <div id="fbReplyText"></div>
 </div>
 </div>

 <form method="POST" action="admin.php?tab=feedback">
 <input type="hidden" name="reply_feedback" value="1"/>
 <input type="hidden" name="feedback_id" id="fbHidId"/>
 <label class="reply-lbl" id="fbReplyLbl">Write Your Reply</label>
 <textarea class="reply-box" name="reply_text" id="fbReplyBox"
 placeholder="Type your response to the customer..."></textarea>
 <button type="submit" class="send-btn">Send Reply</button>
 </form>
 </div>
 </div>
</div>

<script>
function openFb(id, name, email, phone, subject, message, reply, replyDate) {
 document.getElementById('fbHidId').value = id;

 document.getElementById('fbPills').innerHTML =
 `<div class="pill"><span>Name:</span>${name}</div>
 <div class="pill"><span>Email:</span>${email}</div>
 <div class="pill"><span>Phone:</span>${phone}</div>
 <div class="pill"><span>Subject:</span>${subject}</div>`;

 document.getElementById('fbMsg').innerHTML = message;

 if (reply && reply.trim() !== '') {
 document.getElementById('fbReplyWrap').style.display = 'block';
 document.getElementById('fbReplyText').textContent = reply.replace(/<br\s*\/?>/gi, '\n');
 document.getElementById('fbReplyDate').textContent = replyDate ? '— ' + replyDate : '';
 document.getElementById('fbReplyLbl').textContent = 'Update Reply';
 document.getElementById('fbReplyBox').value = reply.replace(/<br\s*\/?>/gi, '\n');
 } else {
 document.getElementById('fbReplyWrap').style.display = 'none';
 document.getElementById('fbReplyLbl').textContent = 'Write Your Reply';
 document.getElementById('fbReplyBox').value = '';
 }

 fetch('admin.php?mark_read=' + id);
 document.getElementById('fbModal').classList.add('open');
}

function closeFb() {
 document.getElementById('fbModal').classList.remove('open');
}

document.getElementById('fbModal').addEventListener('click', function(e) {
 if (e.target === this) closeFb();
});
</script>

<!-- EDIT TRIP MODAL -->
<div class="mo" id="editTripModal">
 <div class="mbox">
  <div class="mhdr">
   <h3>Edit Trip</h3>
   <button class="mclose" onclick="closeEditTrip()">×</button>
  </div>
  <div class="mbody">
   <form method="POST" action="admin.php?tab=trips">
    <input type="hidden" name="edit_trip" value="1"/>
    <input type="hidden" name="trip_id" id="etTripId"/>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">

     <div class="fg">
      <label class="reply-lbl">Bus</label>
      <select name="bus_id" id="etBusId" style="width:100%;padding:11px 13px;border:2px solid #e0ece4;border-radius:8px;font-family:'Nunito',sans-serif;font-size:14px;outline:none;" required>
       <?php
        $buses_edit = mysqli_query($conn, "SELECT id, plate_number, bus_type FROM buses ORDER BY plate_number");
        while ($be = mysqli_fetch_assoc($buses_edit)):
       ?>
       <option value="<?= $be['id'] ?>"><?= htmlspecialchars($be['plate_number']) ?> (<?= htmlspecialchars($be['bus_type']) ?>)</option>
       <?php endwhile; ?>
      </select>
     </div>

     <div class="fg">
      <label class="reply-lbl">Travel Date</label>
      <input type="date" name="trip_date" id="etDate"
       style="width:100%;padding:11px 13px;border:2px solid #e0ece4;border-radius:8px;font-family:'Nunito',sans-serif;font-size:14px;outline:none;"
       required/>
     </div>

     <div class="fg">
      <label class="reply-lbl">From</label>
      <select name="origin" id="etOrigin" style="width:100%;padding:11px 13px;border:2px solid #e0ece4;border-radius:8px;font-family:'Nunito',sans-serif;font-size:14px;outline:none;" required>
       <option value="NAIROBI">Nairobi</option>
       <option value="MOMBASA">Mombasa</option>
       <option value="UKUNDA">Ukunda</option>
      </select>
     </div>

     <div class="fg">
      <label class="reply-lbl">To</label>
      <select name="destination" id="etDest" style="width:100%;padding:11px 13px;border:2px solid #e0ece4;border-radius:8px;font-family:'Nunito',sans-serif;font-size:14px;outline:none;" required>
       <option value="NAIROBI">Nairobi</option>
       <option value="MOMBASA">Mombasa</option>
       <option value="UKUNDA">Ukunda</option>
      </select>
     </div>

     <div class="fg">
      <label class="reply-lbl">Via (optional)</label>
      <input type="text" name="via" id="etVia" placeholder="e.g. Mombasa"
       style="width:100%;padding:11px 13px;border:2px solid #e0ece4;border-radius:8px;font-family:'Nunito',sans-serif;font-size:14px;outline:none;"/>
     </div>

     <div class="fg">
      <label class="reply-lbl">Departure Time</label>
      <input type="time" name="departure_time" id="etTime"
       style="width:100%;padding:11px 13px;border:2px solid #e0ece4;border-radius:8px;font-family:'Nunito',sans-serif;font-size:14px;outline:none;"
       required/>
     </div>

     <div class="fg">
      <label class="reply-lbl">Fare (KSh 100 – 2,500)</label>
      <input type="number" name="fare" id="etFare" min="100" max="2500" step="1"
       style="width:100%;padding:11px 13px;border:2px solid #e0ece4;border-radius:8px;font-family:'Nunito',sans-serif;font-size:14px;outline:none;"
       required/>
     </div>

    </div>

    <button type="submit" class="send-btn">Save Changes</button>
   </form>
  </div>
 </div>
</div>

<script>
function openEditTrip(id, busId, origin, dest, via, date, time, fare) {
 document.getElementById('etTripId').value = id;
 document.getElementById('etBusId').value  = busId;
 document.getElementById('etOrigin').value = origin;
 document.getElementById('etDest').value   = dest;
 document.getElementById('etVia').value    = via;
 document.getElementById('etDate').value   = date;
 document.getElementById('etTime').value   = time;
 document.getElementById('etFare').value   = fare;
 document.getElementById('editTripModal').classList.add('open');
}
function closeEditTrip() {
 document.getElementById('editTripModal').classList.remove('open');
}
document.getElementById('editTripModal').addEventListener('click', function(e) {
 if (e.target === this) closeEditTrip();
});
</script>


<!-- ══ BRANDED CONFIRM MODAL ══ -->
<div id="brandConfirmOverlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:9999;align-items:center;justify-content:center;">
  <div style="background:white;border-radius:16px;width:100%;max-width:420px;box-shadow:0 24px 60px rgba(0,0,0,.35);overflow:hidden;margin:20px;">
    <div style="background:var(--blue);padding:18px 24px;display:flex;align-items:center;gap:12px;">
      <div style="font-family:'Bebas Neue',sans-serif;font-size:13px;letter-spacing:2px;color:rgba(255,255,255,.55);">TRIP RESERVATION SYSTEM</div>
    </div>
    <div style="padding:28px 28px 10px;">
      <div id="brandConfirmMsg" style="font-size:16px;color:#222;font-weight:700;line-height:1.5;margin-bottom:24px;"></div>
      <div style="display:flex;gap:12px;justify-content:flex-end;">
        <button onclick="brandConfirmCancel()" style="padding:10px 24px;border:2px solid #e0e8ee;border-radius:8px;background:none;font-family:'Nunito',sans-serif;font-weight:800;font-size:14px;cursor:pointer;color:#555;">Cancel</button>
        <button id="brandConfirmOkBtn" style="padding:10px 28px;border:none;border-radius:8px;background:var(--red);color:white;font-family:'Nunito',sans-serif;font-weight:900;font-size:14px;cursor:pointer;">Confirm</button>
      </div>
    </div>
    <div style="height:4px;background:linear-gradient(90deg,var(--blue),#42a5f5);"></div>
  </div>
</div>

<!-- ══ LOGOUT CONFIRM MODAL ══ -->
<div id="logoutConfirmOverlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:9999;align-items:center;justify-content:center;">
  <div style="background:white;border-radius:16px;width:100%;max-width:380px;box-shadow:0 24px 60px rgba(0,0,0,.35);overflow:hidden;margin:20px;">
    <div style="background:var(--blue);padding:18px 24px;">
      <div style="font-family:'Bebas Neue',sans-serif;font-size:13px;letter-spacing:2px;color:rgba(255,255,255,.55);">TRIP RESERVATION SYSTEM</div>
    </div>
    <div style="padding:28px 28px 10px;">
      <div style="font-size:16px;color:#222;font-weight:700;line-height:1.5;margin-bottom:24px;">Are you sure you want to log out?</div>
      <div style="display:flex;gap:12px;justify-content:flex-end;">
        <button onclick="document.getElementById('logoutConfirmOverlay').style.display='none';" style="padding:10px 24px;border:2px solid #e0e8ee;border-radius:8px;background:none;font-family:'Nunito',sans-serif;font-weight:800;font-size:14px;cursor:pointer;color:#555;">Cancel</button>
        <?= logout_form('', 'Log Out', "padding:10px 28px;border:none;border-radius:8px;background:var(--red);color:white;font-family:'Nunito',sans-serif;font-weight:900;font-size:14px;cursor:pointer;") ?>
      </div>
    </div>
    <div style="height:4px;background:linear-gradient(90deg,var(--blue),#42a5f5);"></div>
  </div>
</div>

<script>
// ── Custom branded confirm dialog ──
let _brandForm = null;
function brandConfirm(e, form, msg) {
  e.preventDefault();
  _brandForm = form;
  document.getElementById('brandConfirmMsg').textContent = msg;
  const overlay = document.getElementById('brandConfirmOverlay');
  overlay.style.display = 'flex';
  document.getElementById('brandConfirmOkBtn').onclick = function() {
    overlay.style.display = 'none';
    if (_brandForm) _brandForm.submit();
  };
  return false;
}
function brandConfirmCancel() {
  document.getElementById('brandConfirmOverlay').style.display = 'none';
  _brandForm = null;
}
document.getElementById('brandConfirmOverlay').addEventListener('click', function(e) {
  if (e.target === this) brandConfirmCancel();
});

// ── Logout confirm ──
function showLogoutConfirm(e) {
  e.preventDefault();
  document.getElementById('logoutConfirmOverlay').style.display = 'flex';
}
document.getElementById('logoutConfirmOverlay').addEventListener('click', function(e) {
  if (e.target === this) this.style.display = 'none';
});
</script>
</body>
</html>