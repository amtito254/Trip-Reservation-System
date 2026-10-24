<?php
// =============================================
// clerk.php — Booking Clerk Dashboard
// Walk-in customer bookings managed here.
// Admin also has access to this dashboard.
// =============================================

include 'db.php';
require_once 'helpers.php';
require_staff(['admin', 'clerk']);   // both roles may use the clerk dashboard
release_expired_bookings($conn);

$is_admin = ($_SESSION['role'] === 'admin');
$today    = date('Y-m-d');

// --- FETCH DATA ---
// Today's and upcoming trips.
// Seats are shared by every trip of the same bus on the same day, so booked_seats counts by bus + date.
$trips_stmt = mysqli_prepare($conn, "
    SELECT t.id, t.origin, t.destination, t.departure_time, t.trip_date, t.fare,
           b.plate_number, b.bus_type,
           b.total_seats,
           (SELECT COUNT(DISTINCT bk.seat_number)
              FROM bookings bk JOIN trips t2 ON bk.trip_id = t2.id
             WHERE t2.bus_id = t.bus_id AND t2.trip_date = t.trip_date) AS booked_seats
    FROM trips t
    JOIN buses b ON t.bus_id = b.id
    WHERE t.trip_date >= ?
    ORDER BY t.trip_date ASC, t.departure_time ASC
");
mysqli_stmt_bind_param($trips_stmt, 's', $today);
mysqli_stmt_execute($trips_stmt);
$trips_result = mysqli_stmt_get_result($trips_stmt);

$msg = isset($_GET['msg']) ? htmlspecialchars($_GET['msg']) : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Clerk Dashboard — Demo Coaches</title>
    <link href="https://fonts.googleapis.com/css2?family=Oswald:wght@400;600;700&family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }
        :root {
            --blue-dark: #0d1b6e; --blue: #1a237e; --blue-mid: #1565c0; --blue-light: #42a5f5;
            --green: #1B7A3E; --green-light: #23A354;
            --white: #fff; --light: #f4f6fb; --gray: #5c6b70;
        }
        body { font-family: 'Nunito', sans-serif; background: var(--light); min-height: 100vh; display: flex; flex-direction: column; }

        nav { background: var(--blue-dark); display: flex; align-items: center; justify-content: space-between; padding: 0 4%; height: 60px; box-shadow: 0 2px 16px rgba(0,0,0,.4); position: sticky; top: 0; z-index: 99; }
        .nav-brand { font-family: 'Oswald', sans-serif; font-size: 20px; color: var(--white); letter-spacing: 3px; text-decoration: none; }
        .nav-brand span { color: var(--blue-light); }
        .nav-right { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
        .nav-user { font-size: 13px; color: rgba(255,255,255,.6); font-weight: 700; }
        .nav-user strong { color: var(--white); }
        .nav-role { font-size: 11px; font-weight: 800; padding: 3px 10px; border-radius: 20px; letter-spacing: 1px; text-transform: uppercase; }
        .role-clerk { background: rgba(66,165,245,.2); color: var(--blue-light); border: 1px solid rgba(66,165,245,.3); }
        .role-admin { background: rgba(245,197,24,.2); color: #F5C518; border: 1px solid rgba(245,197,24,.3); }
        .btn-nav { padding: 7px 16px; border-radius: 6px; font-weight: 800; font-size: 13px; text-decoration: none; border: none; cursor: pointer; font-family: 'Nunito', sans-serif; }
        .btn-admin { background: rgba(255,255,255,.1); color: var(--white); }
        .btn-admin:hover { background: rgba(255,255,255,.2); }
        .btn-logout { background: #c62828; color: var(--white); }
        .btn-logout:hover { background: #b71c1c; }

        .page-header { background: linear-gradient(135deg, var(--blue-dark), var(--blue-mid)); padding: 32px 4% 28px; }
        .page-header h1 { font-family: 'Oswald', sans-serif; font-size: clamp(24px,3.5vw,36px); color: var(--white); letter-spacing: 2px; }
        .page-header h1 span { color: var(--blue-light); }
        .page-header p { color: rgba(255,255,255,.5); font-size: 13px; margin-top: 6px; }

        .container { padding: 0 4% 40px; flex: 1; }

        .alert { padding: 13px 18px; border-radius: 8px; margin-bottom: 20px; font-weight: 700; font-size: 14px; }
        .alert-success { background: #e8f5e9; border-left: 4px solid #388e3c; color: #1b5e20; }

        .section-title { font-family: 'Oswald', sans-serif; font-size: 20px; letter-spacing: 1.5px; color: var(--blue); margin: 28px 0 14px; text-transform: uppercase; display: flex; align-items: center; gap: 10px; }

        .table-wrap { background: var(--white); border-radius: 12px; box-shadow: 0 2px 12px rgba(0,0,0,.07); border: 1px solid #e2e8f0; overflow: hidden; overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; min-width: 600px; }
        thead th { background: var(--blue); color: var(--white); padding: 12px 14px; text-align: left; font-size: 12px; font-weight: 800; text-transform: uppercase; letter-spacing: .8px; }
        tbody td { padding: 12px 14px; border-bottom: 1px solid #f0f4f8; font-size: 14px; color: #2d3748; vertical-align: middle; }
        tbody tr:last-child td { border-bottom: none; }
        tbody tr:hover td { background: #f8fafc; }

        .btn { padding: 7px 14px; border: none; border-radius: 6px; cursor: pointer; font-weight: 800; font-size: 12px; font-family: 'Nunito', sans-serif; text-decoration: none; display: inline-block; transition: all .2s; }
        .btn-seats   { background: var(--blue); color: var(--white); }
        .btn-seats:hover { background: var(--blue-mid); }

        .empty-state { text-align: center; padding: 40px 20px; color: var(--gray); }
        .empty-state .icon { font-size: 40px; margin-bottom: 10px; }
        .empty-state p { font-weight: 700; }

        .trip-date { font-weight: 800; color: var(--blue); }
        .trip-date small { display: block; font-size: 11px; color: var(--gray); font-weight: 600; }

        footer { background: var(--blue-dark); color: rgba(255,255,255,.3); text-align: center; padding: 16px; font-size: 12px; margin-top: 0; }
        footer span { color: var(--blue-light); font-weight: 700; }
    </style>
</head>
<body>

<nav>
    <a href="clerk.php" class="nav-brand"><span>DEMO</span> COACHES</a>
    <div class="nav-right">
        <div class="nav-user">Welcome, <strong><?= htmlspecialchars($_SESSION['admin_name'] ?? 'Clerk') ?></strong></div>
        <span class="nav-role <?= $is_admin ? 'role-admin' : 'role-clerk' ?>"><?= $is_admin ? 'Admin' : 'Clerk' ?></span>
        <?php if ($is_admin): ?>
            <a href="admin.php" class="btn-nav btn-admin">Admin Panel</a>
        <?php endif; ?>
        <a href="staff_change_password.php" class="btn-nav btn-admin">Change Password</a>
        <?= logout_form('btn-nav btn-logout', 'Logout') ?>
    </div>
</nav>

<div class="page-header">
    <h1>Clerk <span>Dashboard</span></h1>
    <p>Manage walk-in customer bookings and view upcoming trips</p>
</div>

<div class="container">
    <?php if ($msg): ?>
        <div class="alert alert-success"><?= $msg ?></div>
    <?php endif; ?>

    <div class="section-title">Today's &amp; Upcoming Trips</div>
    <div class="table-wrap">
        <?php if (mysqli_num_rows($trips_result) > 0): ?>
            <table>
                <thead><tr><th>Date</th><th>Departure</th><th>Bus</th><th>Route</th><th>Seats Booked</th><th>Action</th></tr></thead>
                <tbody>
                    <?php while ($trip = mysqli_fetch_assoc($trips_result)):
                        $booked = intval($trip['booked_seats']);
                        $capacity = (int)($trip['total_seats'] ?: DEFAULT_BUS_SEATS);
                        $free = $capacity - $booked;
                    ?>
                        <tr>
                            <td><div class="trip-date"><?= date('d M Y', strtotime($trip['trip_date'])) ?><?php if ($trip['trip_date'] === $today): ?><small>Today</small><?php endif; ?></div></td>
                            <td><?= htmlspecialchars(substr($trip['departure_time'], 0, 5)) ?></td>
                            <td><strong><?= htmlspecialchars($trip['plate_number']) ?></strong><br><small><?= htmlspecialchars($trip['bus_type']) ?></small></td>
                            <td><?= htmlspecialchars($trip['origin']) ?> -> <?= htmlspecialchars($trip['destination']) ?></td>
                            <td><strong><?= $booked ?></strong>/<?= $capacity ?> <span style="color:<?= $free===0?'#c62828':($free<=10?'#e65100':'var(--green)') ?>;font-size:12px;font-weight:700;">(<?= $free ?> free)</span></td>
                            <td><a href="clerk_view_seats.php?trip_id=<?= intval($trip['id']) ?>" class="btn btn-seats">Select Seats</a></td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="empty-state"><div class="icon">-</div><p>No upcoming trips found. Ask an admin to add trips.</p></div>
        <?php endif; ?>
    </div>
</div>

<footer>&copy; 2026 Demo Coaches (fictional demo) | Clerk Portal | Designed by <span>Mejah Titus Guthua</span></footer>

</body>
</html>