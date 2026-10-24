<?php
// =============================================
// get_booked_seats.php - AJAX endpoint to fetch booked seats
// =============================================

include 'db.php';
require_once 'helpers.php';
header('Content-Type: application/json');

$bus_id = intval($_GET['bus_id'] ?? 0);
$trip_date = $_GET['trip_date'] ?? '';

if ($bus_id <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $trip_date)) {
    echo json_encode(['success' => false, 'error' => 'Missing parameters', 'booked_seats' => []]);
    exit();
}

release_expired_bookings($conn);

// Get all booked seats for this bus on this date
$stmt = mysqli_prepare($conn, "
    SELECT DISTINCT bk.seat_number
    FROM bookings bk
    JOIN trips t ON bk.trip_id = t.id
    WHERE t.bus_id = ? AND t.trip_date = ?
");
mysqli_stmt_bind_param($stmt, 'is', $bus_id, $trip_date);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$booked_seats = [];
while ($row = mysqli_fetch_assoc($result)) {
    $booked_seats[] = intval($row['seat_number']);
}

echo json_encode(['success' => true, 'booked_seats' => $booked_seats]);
