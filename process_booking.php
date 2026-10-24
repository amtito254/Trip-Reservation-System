<?php
// =============================================
// process_booking.php — Public seat booking (AJAX endpoint)
// =============================================

include 'db.php';
require_once 'helpers.php';
header('Content-Type: application/json');

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['success' => false, 'error' => 'Invalid request method.'], 405);
}

// --- GET & SANITISE INPUT ---
$trip_id      = intval($_POST['trip_id']      ?? 0);
$seat_number  = intval($_POST['seat_number']  ?? 0);
$full_name    = trim($_POST['full_name']      ?? '');
$id_number    = trim($_POST['id_number']      ?? '');
$phone_number = trim($_POST['phone_number']   ?? '');
$payment      = trim($_POST['payment_method'] ?? '');

// --- VALIDATE ---
$errors = [];

if ($trip_id <= 0)
    $errors[] = 'Invalid trip selected.';

if ($seat_number < 1)
    $errors[] = 'Invalid seat number.';

if (!preg_match('/^[a-zA-Z\s]{2,100}$/', $full_name))
    $errors[] = 'Full name must contain letters only (min 2 characters).';

if (!preg_match('/^\d{6,20}$/', $id_number))
    $errors[] = 'ID number must be at least 6 digits.';

if (!preg_match('/^\d{10}$/', $phone_number))
    $errors[] = 'Phone number must be exactly 10 digits.';

if (!in_array($payment, ['cash_at_terminal', 'call_office'], true))
    $errors[] = 'Invalid payment method selected.';

if (!empty($errors)) {
    json_out(['success' => false, 'error' => implode(' ', $errors)]);
}

// Free seats that were never paid for before we look at availability
release_expired_bookings($conn);

// --- FETCH TRIP (with bus info for booking_ref + seat capacity) ---
$trip_stmt = mysqli_prepare($conn,
    "SELECT t.trip_date, t.bus_id, b.plate_number, b.total_seats
     FROM trips t JOIN buses b ON t.bus_id = b.id
     WHERE t.id = ? LIMIT 1"
);
mysqli_stmt_bind_param($trip_stmt, 'i', $trip_id);
mysqli_stmt_execute($trip_stmt);
$trip_info = mysqli_fetch_assoc(mysqli_stmt_get_result($trip_stmt));
mysqli_stmt_close($trip_stmt);

if (!$trip_info) {
    json_out(['success' => false, 'error' => 'Trip not found. Please refresh and try again.']);
}

$total_seats = (int)($trip_info['total_seats'] ?: DEFAULT_BUS_SEATS);
if ($seat_number > $total_seats) {
    json_out(['success' => false, 'error' => 'Invalid seat number.']);
}

// --- ABUSE PROTECTION ---
// 1) Cap how many unconfirmed seats one person (phone OR ID number) can hold at once
$cap_stmt = mysqli_prepare($conn,
    "SELECT COUNT(*) FROM bookings WHERE status = 'pending' AND (phone_number = ? OR id_number = ?)");
mysqli_stmt_bind_param($cap_stmt, 'ss', $phone_number, $id_number);
mysqli_stmt_execute($cap_stmt);
mysqli_stmt_bind_result($cap_stmt, $pending_now);
mysqli_stmt_fetch($cap_stmt);
mysqli_stmt_close($cap_stmt);

if ($pending_now >= MAX_PENDING_PER_PERSON) {
    json_out([
        'success' => false,
        'error'   => 'You already have the maximum number of unconfirmed seats. Please complete payment for them or contact our office.'
    ], 429);
}

// 2) Cap how many seats one IP address can book per hour
if (!rate_limit_allow($conn, 'booking', BOOKING_MAX_PER_IP, BOOKING_WINDOW_SECONDS)) {
    json_out(['success' => false, 'error' => 'Too many booking requests. Please try again later or contact our office.'], 429);
}

// --- BUILD BOOKING REF ---
// Format: DDMMYYYY-PLATENUM-SEAT  (UNIQUE in the database = one seat per bus per day)
$plate_clean  = strtoupper(preg_replace('/\s+/', '', $trip_info['plate_number']));
$date_clean   = date('dmY', strtotime($trip_info['trip_date']));
$booking_ref  = $date_clean . '-' . $plate_clean . '-' . $seat_number;

// --- CHECK + SAVE INSIDE ONE TRANSACTION ---
// Locking the bus row makes two simultaneous requests for the same bus queue up, so the
// "is it free?" check and the INSERT can no longer interleave (no double booking).
mysqli_begin_transaction($conn);

$lock = mysqli_prepare($conn, "SELECT id FROM buses WHERE id = ? FOR UPDATE");
mysqli_stmt_bind_param($lock, 'i', $trip_info['bus_id']);
mysqli_stmt_execute($lock);
mysqli_stmt_store_result($lock);
mysqli_stmt_close($lock);

$check_stmt = mysqli_prepare($conn,
    "SELECT bk.id FROM bookings bk
     JOIN trips t ON bk.trip_id = t.id
     WHERE t.bus_id = ? AND t.trip_date = ? AND bk.seat_number = ?
     LIMIT 1"
);
mysqli_stmt_bind_param($check_stmt, 'isi', $trip_info['bus_id'], $trip_info['trip_date'], $seat_number);
mysqli_stmt_execute($check_stmt);
mysqli_stmt_store_result($check_stmt);
$taken = mysqli_stmt_num_rows($check_stmt) > 0;
mysqli_stmt_close($check_stmt);

if ($taken) {
    mysqli_rollback($conn);
    json_out(['success' => false, 'error' => "Seat $seat_number is already booked. Please choose a different seat."]);
}

$ins = mysqli_prepare($conn,
    "INSERT INTO bookings
       (booking_ref, trip_id, seat_number, full_name, id_number, phone_number, payment_method, status)
     VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')"
);
mysqli_stmt_bind_param($ins, 'siissss',
    $booking_ref, $trip_id, $seat_number,
    $full_name, $id_number, $phone_number, $payment
);

if (mysqli_stmt_execute($ins)) {
    mysqli_commit($conn);
    json_out([
        'success'     => true,
        'booking_ref' => $booking_ref,
        'seat'        => $seat_number,
        'message'     => 'Booking saved successfully!'
    ]);
}

$errno = mysqli_stmt_errno($ins);
mysqli_rollback($conn);

if ($errno === 1062) {
    // Duplicate key = someone else got this seat first (safety net behind the check above)
    json_out(['success' => false, 'error' => "Seat $seat_number was just booked by someone else. Please choose a different seat."]);
}

error_log("process_booking insert failed (errno $errno): " . mysqli_stmt_error($ins));
json_out(['success' => false, 'error' => 'Could not save booking. Please try again.'], 500);
