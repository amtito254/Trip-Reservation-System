<?php
// =============================================
// process_clerk_booking.php — Clerk walk-in booking (AJAX endpoint)
// =============================================

include 'db.php';
require_once 'helpers.php';
header('Content-Type: application/json');

// --- AUTH CHECK (admin or clerk; JSON errors for AJAX) ---
require_staff(['admin', 'clerk'], true);

// --- METHOD + CSRF CHECK ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['success' => false, 'error' => 'Invalid request method.'], 405);
}
$token = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (!csrf_valid($token)) {
    json_out(['success' => false, 'error' => 'Your session has expired. Please refresh the page and try again.'], 403);
}

// --- INPUT ---
$trip_id        = intval($_POST['trip_id']        ?? 0);
$seats_raw      = trim($_POST['selected_seats']   ?? '');
$full_name      = trim($_POST['full_name']         ?? '');
$id_number      = trim($_POST['id_number']         ?? '');
$phone_number   = trim($_POST['phone_number']      ?? '');
$payment        = trim($_POST['payment_method']    ?? '');

// --- VALIDATE REQUIRED FIELDS ---
if ($trip_id <= 0 || $seats_raw === '' || $full_name === '' || $id_number === '' || $phone_number === '') {
    json_out(['success' => false, 'error' => 'All fields are required.']);
}
if (!preg_match('/^[a-zA-Z\s]{2,100}$/', $full_name)) {
    json_out(['success' => false, 'error' => 'Full name must contain letters only (min 2 chars).']);
}
if (!preg_match('/^\d{6,20}$/', $id_number)) {
    json_out(['success' => false, 'error' => 'ID number must be at least 6 digits.']);
}
if (!preg_match('/^\d{10}$/', $phone_number)) {
    json_out(['success' => false, 'error' => 'Phone number must be exactly 10 digits.']);
}
if (!in_array($payment, ['cash_at_terminal', 'call_office'], true)) {
    json_out(['success' => false, 'error' => 'Invalid payment method.']);
}

release_expired_bookings($conn);

// --- FETCH TRIP INFO (needed for booking_ref + seat capacity) ---
$trip_stmt = mysqli_prepare($conn,
    "SELECT t.id, t.trip_date, t.bus_id, b.plate_number, b.total_seats
     FROM trips t JOIN buses b ON t.bus_id = b.id
     WHERE t.id = ? LIMIT 1"
);
mysqli_stmt_bind_param($trip_stmt, 'i', $trip_id);
mysqli_stmt_execute($trip_stmt);
$trip = mysqli_fetch_assoc(mysqli_stmt_get_result($trip_stmt));
mysqli_stmt_close($trip_stmt);

if (!$trip) {
    json_out(['success' => false, 'error' => 'Trip not found.']);
}
$total_seats = (int)($trip['total_seats'] ?: DEFAULT_BUS_SEATS);

// Parse seat list: integers only, within the bus capacity, NO duplicates
$selected_seats = array_values(array_unique(array_filter(
    array_map('intval', explode(',', $seats_raw)),
    fn($s) => $s >= 1 && $s <= $total_seats
)));
sort($selected_seats);
if (empty($selected_seats)) {
    json_out(['success' => false, 'error' => 'No valid seats selected.']);
}

$plate_clean = strtoupper(preg_replace('/\s+/', '', $trip['plate_number']));
$date_clean  = date('dmY', strtotime($trip['trip_date']));

// --- ALL SEATS IN ONE TRANSACTION, WITH THE BUS ROW LOCKED ---
mysqli_begin_transaction($conn);

$lock = mysqli_prepare($conn, "SELECT id FROM buses WHERE id = ? FOR UPDATE");
mysqli_stmt_bind_param($lock, 'i', $trip['bus_id']);
mysqli_stmt_execute($lock);
mysqli_stmt_store_result($lock);
mysqli_stmt_close($lock);

$ins = mysqli_prepare($conn,
    "INSERT INTO bookings
       (booking_ref, trip_id, seat_number, full_name, id_number, phone_number, payment_method, status)
     VALUES (?, ?, ?, ?, ?, ?, ?, 'confirmed')"
);
$check_stmt = mysqli_prepare($conn,
    "SELECT bk.id FROM bookings bk
     JOIN trips t ON bk.trip_id = t.id
     WHERE t.bus_id = ? AND t.trip_date = ? AND bk.seat_number = ?
     LIMIT 1"
);

$booked_refs = [];
$skipped     = [];

foreach ($selected_seats as $seat) {

    // Already taken on this bus + date?
    mysqli_stmt_bind_param($check_stmt, 'isi', $trip['bus_id'], $trip['trip_date'], $seat);
    mysqli_stmt_execute($check_stmt);
    mysqli_stmt_store_result($check_stmt);
    if (mysqli_stmt_num_rows($check_stmt) > 0) {
        $skipped[] = $seat;
        continue;
    }

    $booking_ref = $date_clean . '-' . $plate_clean . '-' . $seat;
    mysqli_stmt_bind_param($ins, 'siissss',
        $booking_ref, $trip_id, $seat,
        $full_name, $id_number, $phone_number, $payment
    );

    if (mysqli_stmt_execute($ins)) {
        $booked_refs[] = ['seat' => $seat, 'ref' => $booking_ref];
    } elseif (mysqli_stmt_errno($ins) === 1062) {
        $skipped[] = $seat;                      // someone else got it first
    } else {
        // Real database error: undo EVERYTHING so the customer is never left half-booked
        error_log("process_clerk_booking insert failed (seat $seat): " . mysqli_stmt_error($ins));
        mysqli_rollback($conn);
        json_out(['success' => false, 'error' => 'Database error. No seats were booked; please try again.'], 500);
    }
}

if (count($booked_refs) === 0) {
    mysqli_rollback($conn);
    json_out(['success' => false, 'error' => 'No seats could be booked. Seats already taken: ' . implode(', ', $skipped) . '.']);
}

mysqli_commit($conn);

$msg = count($booked_refs) . ' seat(s) booked successfully! Ref(s): ' . implode(', ', array_column($booked_refs, 'ref'));
if (!empty($skipped)) $msg .= ' | Already taken (skipped): Seat(s) ' . implode(', ', $skipped);

json_out([
    'success' => true,
    'message' => $msg,
    'booked'  => $booked_refs,
    'skipped' => $skipped,
]);
