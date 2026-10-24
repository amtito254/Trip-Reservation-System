<?php
// =============================================
// clerk_view_seats.php — Walk-in seat selection for clerk
// =============================================

include 'db.php';
require_once 'helpers.php';
require_staff(['admin', 'clerk']);
release_expired_bookings($conn);

$trip_id = intval($_GET['trip_id'] ?? 0);
if ($trip_id <= 0) { header("Location: clerk.php"); exit(); }

$trip_stmt = mysqli_prepare($conn,
    "SELECT t.id, t.origin, t.destination, t.via, t.departure_time, t.trip_date, t.fare, t.bus_id,
            b.plate_number, b.bus_type, b.total_seats
     FROM trips t JOIN buses b ON t.bus_id = b.id
     WHERE t.id = ? LIMIT 1"
);
mysqli_stmt_bind_param($trip_stmt, 'i', $trip_id);
mysqli_stmt_execute($trip_stmt);
$trip = mysqli_fetch_assoc(mysqli_stmt_get_result($trip_stmt));
if (!$trip) { header("Location: clerk.php"); exit(); }

$booked_stmt = mysqli_prepare($conn,
    "SELECT DISTINCT bk.seat_number FROM bookings bk
     JOIN trips t ON bk.trip_id = t.id
     WHERE t.bus_id = ? AND t.trip_date = ?"
);
mysqli_stmt_bind_param($booked_stmt, 'is', $trip['bus_id'], $trip['trip_date']);
mysqli_stmt_execute($booked_stmt);
$booked_res = mysqli_stmt_get_result($booked_stmt);

$bookedList = [];
while ($r = mysqli_fetch_assoc($booked_res)) { $bookedList[] = intval($r['seat_number']); }
$total_seats = (int)($trip['total_seats'] ?: DEFAULT_BUS_SEATS);
$free_seats  = $total_seats - count($bookedList);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Seat Selection — <?= htmlspecialchars($trip['plate_number']) ?></title>
  <link href="https://fonts.googleapis.com/css2?family=Oswald:wght@400;600;700&family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">
  <style>
    *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }
    :root {
      --blue-dark: #0d1b6e; --blue: #1a237e; --blue-mid: #1565c0; --blue-light: #42a5f5;
      --green: #1B7A3E; --green-light: #23A354;
      --gold: #F5C518;
      --white: #fff; --light: #f4f6fb; --gray: #5c6b70;
    }
    body { font-family: 'Nunito', sans-serif; background: var(--light); min-height: 100vh; display: flex; flex-direction: column; }

    /* NAV */
    nav { background: var(--blue-dark); display: flex; align-items: center; justify-content: space-between; padding: 0 4%; height: 58px; box-shadow: 0 2px 16px rgba(0,0,0,.4); flex-shrink: 0; }
    .nav-brand { font-family: 'Oswald', sans-serif; font-size: 20px; color: var(--white); letter-spacing: 3px; text-decoration: none; }
    .nav-brand span { color: var(--blue-light); }
    .back-btn { color: var(--gold); text-decoration: none; font-weight: 800; font-size: 14px; transition: color .2s; }
    .back-btn:hover { color: var(--white); }

    /* TRIP HEADER */
    .trip-header { background: var(--blue-dark); padding: 18px 28px; flex-shrink: 0; }
    .trip-header h2 { font-family: 'Oswald', sans-serif; font-size: 22px; letter-spacing: 2px; color: var(--white); }
    .trip-meta { display: flex; gap: 20px; margin-top: 8px; flex-wrap: wrap; }
    .trip-meta-item { font-size: 13px; color: rgba(255,255,255,.65); font-weight: 700; }
    .trip-meta-item strong { color: var(--white); }

    /* TWO-COLUMN LAYOUT */
    .split-layout {
      display: flex;
      flex: 1;
      min-height: 0;
    }
    @media(max-width: 960px) {
      .split-layout { flex-direction: column; }
    }

    /* LEFT — SEAT MAP PANEL */
    .left-panel {
      background: var(--white);
      padding: 28px;
      border-right: 2px solid #e2e8f0;
      display: flex;
      flex-direction: column;
      gap: 18px;
      width: fit-content;
      flex-shrink: 0;
    }

    /* LEGEND */
    .legend { display: flex; gap: 20px; flex-wrap: wrap; }
    .legend-item { display: flex; align-items: center; gap: 8px; font-size: 13px; font-weight: 700; color: var(--gray); }
    .legend-dot { width: 22px; height: 22px; border-radius: 5px; flex-shrink: 0; }
    .legend-dot.free     { background: #fff; border: 2px solid #81c784; }
    .legend-dot.taken    { background: #ffe082; border: 2px solid #f9a825; }
    .legend-dot.selected { background: var(--green); border: 2px solid var(--green-light); }

    /* SUMMARY BAR */
    .seat-summary { background: var(--blue-dark); border-radius: 10px; padding: 14px 20px; display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-shrink: 0; width: 100%; }
    .summary-seats-text { color: var(--white); font-weight: 800; font-size: 14px; }
    .summary-seats-text span { color: var(--gold); font-family: 'Oswald', sans-serif; font-size: 20px; }
    .summary-fare { font-family: 'Oswald', sans-serif; font-size: 22px; color: var(--gold); letter-spacing: 1px; }

    /* BUS SHELL */
    .bus-shell { background: #f0f4f1; border: 3px solid #c0d4c8; border-radius: 24px; padding: 18px 20px 24px; box-shadow: inset 0 2px 8px rgba(0,0,0,.05); display: inline-block; }
    .seat-grid { display: flex; flex-direction: column; gap: 4px; padding: 4px 0; }
    .seat-row { display: flex; flex-direction: row; gap: 4px; align-items: center; }
    .seat {
      width: 44px; height: 46px; border-radius: 7px;
      display: flex; align-items: center; justify-content: center;
      font-size: 11px; font-weight: 900; cursor: pointer;
      border: 2px solid transparent; transition: transform .15s, box-shadow .15s;
      flex-shrink: 0;
    }
    .seat.free { background: #fff; border-color: #c0cfc8; color: #5a7a6a; box-shadow: 0 2px 4px rgba(0,0,0,.08); }
    .seat.free:hover { transform: translateY(-3px) scale(1.08); box-shadow: 0 6px 16px rgba(35,163,84,.3); border-color: var(--green); }
    .seat.taken { background: #e8c84a; border-color: #c9a800; color: #7a6000; cursor: not-allowed; pointer-events: none; user-select: none; }
    .seat.selected { background: var(--green); border-color: #135C2E; color: #fff; box-shadow: 0 4px 12px rgba(27,122,62,.4); }
    .seat.selected:hover { transform: translateY(-3px) scale(1.08); }
    .aisle-gap { width: 10px; flex-shrink: 0; }
    .spacer-seat { width: 44px; height: 46px; flex-shrink: 0; }

    /* RIGHT — BOOKING FORM PANEL */
    .right-panel {
      background: #f8fafc;
      padding: 28px;
      display: flex;
      flex-direction: column;
      border-left: 1px solid #e2e8f0;
      overflow-y: auto;
      flex: 1;
    }

    .form-panel-title { font-family: 'Oswald', sans-serif; font-size: 19px; letter-spacing: 1.5px; color: var(--blue); text-transform: uppercase; margin-bottom: 16px; padding-bottom: 12px; border-bottom: 2px solid #e0e8f0; }

    /* Empty state on the right */
    .no-select-msg { display: flex; flex-direction: column; align-items: center; justify-content: center; flex: 1; text-align: center; color: #aab; padding: 40px 20px; }
    .no-select-msg .big-arrow { font-size: 48px; margin-bottom: 12px; opacity: .4; }
    .no-select-msg p { font-size: 14px; font-weight: 700; line-height: 1.6; }

    /* Form content */
    .booking-form-inner { display: flex; flex-direction: column; gap: 0; flex: 1; }

    .selected-seats-display { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 16px; }
    .seat-pill { background: var(--green); color: #fff; padding: 5px 12px; border-radius: 20px; font-size: 12px; font-weight: 800; }

    .fare-summary { background: var(--blue-dark); color: var(--white); border-radius: 10px; padding: 14px 18px; margin-bottom: 18px; display: flex; justify-content: space-between; align-items: center; }
    .fare-label { font-size: 12px; color: rgba(255,255,255,.6); }
    .fare-amount { font-family: 'Oswald', sans-serif; font-size: 26px; color: var(--gold); letter-spacing: 1px; }

    .fg { margin-bottom: 14px; }
    .fg label { display: block; font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 1.2px; color: #8eacc0; margin-bottom: 6px; }
    .fg input, .fg select { width: 100%; padding: 11px 14px; border: 2px solid #e0e8f0; border-radius: 8px; font-family: 'Nunito', sans-serif; font-size: 14px; color: #1a1a2e; background: #fff; outline: none; transition: border-color .2s; }
    .fg input:focus, .fg select:focus { border-color: var(--blue); box-shadow: 0 0 0 3px rgba(26,35,126,.07); }
    .form-2col { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }

    .btn-confirm { width: 100%; padding: 14px; background: linear-gradient(135deg, var(--green), var(--green-light)); color: var(--white); border: none; border-radius: 10px; font-family: 'Oswald', sans-serif; font-size: 18px; letter-spacing: 2px; cursor: pointer; transition: all .2s; margin-top: 8px; }
    .btn-confirm:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(27,122,62,.4); }
    .btn-confirm:disabled { background: #ccc; cursor: not-allowed; transform: none; box-shadow: none; }

    /* SUCCESS */
    .success-box { background: #e8f5e9; border: 2px solid #66bb6a; border-radius: 12px; padding: 24px; text-align: center; display: none; }
    .success-box h3 { font-family: 'Oswald', sans-serif; font-size: 22px; color: var(--green); letter-spacing: 1.5px; margin-bottom: 10px; }
    .refs { margin: 14px 0; }
    .ref-pill { display: inline-block; background: var(--green); color: #fff; padding: 7px 14px; border-radius: 8px; font-family: 'Oswald', sans-serif; font-size: 13px; letter-spacing: 1.5px; margin: 3px; }
    .success-box p { color: var(--gray); font-size: 13px; }
    .btn-another { display: inline-block; margin-top: 12px; padding: 10px 20px; background: var(--blue); color: var(--white); border-radius: 8px; font-weight: 800; text-decoration: none; font-size: 13px; }

    footer { background: var(--blue-dark); color: rgba(255,255,255,.3); text-align: center; padding: 14px; font-size: 12px; flex-shrink: 0; }
  </style>
</head>
<body>

<nav>
  <a href="clerk.php" class="nav-brand"><span>DEMO</span> COACHES</a>
  <a href="clerk.php" class="back-btn">← Back to Dashboard</a>
</nav>

<!-- TRIP HEADER — full width above both columns -->
<div class="trip-header">
  <h2><?= htmlspecialchars($trip['origin']) ?> → <?= htmlspecialchars($trip['destination']) ?></h2>
  <div class="trip-meta">
    <div class="trip-meta-item"><strong><?= htmlspecialchars($trip['plate_number']) ?></strong> (<?= htmlspecialchars($trip['bus_type']) ?>)</div>
    <div class="trip-meta-item"><strong><?= date('d M Y', strtotime($trip['trip_date'])) ?></strong></div>
    <div class="trip-meta-item">Departs <strong><?= htmlspecialchars(substr($trip['departure_time'], 0, 5)) ?></strong></div>
    <div class="trip-meta-item"><strong><?= $free_seats ?></strong> of <?= $total_seats ?> seats free</div>
    <div class="trip-meta-item">KSh <strong><?= number_format($trip['fare'], 0) ?></strong> per seat</div>
  </div>
</div>

<!-- SPLIT LAYOUT -->
<div class="split-layout">

  <!-- ════ LEFT: SEAT MAP ════ -->
  <div class="left-panel">

    <div class="legend">
      <div class="legend-item"><div class="legend-dot free"></div> Available</div>
      <div class="legend-item"><div class="legend-dot taken"></div> Already Booked</div>
      <div class="legend-item"><div class="legend-dot selected"></div> Selected</div>
    </div>

    <div class="seat-summary">
      <div class="summary-seats-text">Selected: <span id="selectedSeatNums">None</span></div>
      <div class="summary-fare" id="summaryFare">KSh 0</div>
    </div>

    <div class="bus-shell">
      <div class="seat-grid" id="seatGrid"></div>
    </div>

  </div>

  <!-- ════ RIGHT: BOOKING FORM ════ -->
  <div class="right-panel">

    <!-- Empty state — shown when no seats selected -->
    <div id="noSelectMsg" class="no-select-msg">
      <div class="big-arrow">←</div>
      <p>Select seats from the map<br>to start booking</p>
    </div>

    <!-- Booking form — shown when seats selected -->
    <div id="bookingFormWrap" style="display:none;" class="booking-form-inner">

      <div class="form-panel-title">Complete Walk-In Booking</div>

      <div class="selected-seats-display" id="selectedSeatsDisplay"></div>

      <div class="fare-summary">
        <div>
          <div class="fare-label">Total Fare</div>
          <div id="fareLabel" style="color:rgba(255,255,255,.55);font-size:12px;margin-top:2px;"></div>
        </div>
        <div class="fare-amount" id="fareAmount">KSh 0</div>
      </div>

      <div class="form-2col">
        <div class="fg" style="grid-column:1/-1;">
          <label>Full Name</label>
          <input type="text" id="fullName" placeholder="e.g. Jane Wanjiku">
        </div>
      </div>
      <div class="form-2col">
        <div class="fg">
          <label>ID / Passport No.</label>
          <input type="text" id="idNumber" placeholder="e.g. 12345678" inputmode="numeric">
        </div>
        <div class="fg">
          <label>Phone Number</label>
          <input type="text" id="phoneNumber" placeholder="e.g. 0712345678" inputmode="numeric" maxlength="10">
        </div>
      </div>
      <div class="fg">
        <label>Payment Method</label>
        <select id="paymentMethod">
          <option value="cash_at_terminal">Cash at Terminal</option>
        </select>
      </div>

      <button class="btn-confirm" id="confirmBtn" onclick="submitBooking()">
        CONFIRM & BOOK SEATS →
      </button>
    </div>

    <!-- Success box -->
    <div class="success-box" id="successBox">
      <h3>✓ BOOKING CONFIRMED!</h3>
      <p>Booking reference(s):</p>
      <div class="refs" id="refsDisplay"></div>
      <p id="skippedMsg" style="color:#e65100;display:none;margin-top:8px;"></p>
      <a href="clerk_view_seats.php?trip_id=<?= $trip_id ?>" class="btn-another">Book More Seats</a>
      &nbsp;
      <a href="clerk.php" class="btn-another" style="background:var(--green);">← Dashboard</a>
    </div>

  </div>
</div>

<footer>
  &copy; 2026 Demo Coaches (fictional demo) &nbsp;|&nbsp; Clerk Seat Booking
</footer>

<script>
const BOOKED_SEATS  = <?= json_encode($bookedList) ?>;
const FARE_PER_SEAT = <?= floatval($trip['fare']) ?>;
const TRIP_ID       = <?= intval($trip_id) ?>;
const CSRF_TOKEN    = <?= json_encode(csrf_token()) ?>;
let selectedSeats   = [];

const layout = [
  { seats: [4,8,12,16,20,24,28,32,36,40,45],                     gapRow: false },
  { seats: [3,7,11,15,19,23,27,31,35,39,44],                     gapRow: false },
  { seats: [null,null,null,null,null,null,null,null,null,null,43], gapRow: true  },
  { seats: [2,6,10,14,18,22,26,30,34,38,42],                     gapRow: false },
  { seats: [1,5,9,13,17,21,25,29,33,37,41],                      gapRow: false },
];

function buildSeatMap() {
  const grid = document.getElementById('seatGrid');
  grid.innerHTML = '';
  layout.forEach((rowDef, ri) => {
    const rowEl = document.createElement('div');
    rowEl.className = 'seat-row';
    if (ri === 2) rowEl.style.marginTop = '14px';
    rowDef.seats.forEach((num, ci) => {
      if (ci === 2) {
        const aisle = document.createElement('div');
        aisle.className = 'aisle-gap';
        rowEl.appendChild(aisle);
      }
      if (num === null) {
        const spacer = document.createElement('div');
        spacer.className = 'spacer-seat';
        rowEl.appendChild(spacer);
        return;
      }
      rowEl.appendChild(makeSeat(num));
    });
    grid.appendChild(rowEl);
  });
}

function makeSeat(num) {
  const el = document.createElement('div');
  const taken = BOOKED_SEATS.includes(num);
  el.className = 'seat ' + (taken ? 'taken' : 'free');
  el.textContent = num;
  el.dataset.seat = num;
  if (!taken) el.addEventListener('click', () => toggleSeat(el, num));
  return el;
}

function toggleSeat(el, num) {
  if (selectedSeats.includes(num)) {
    selectedSeats = selectedSeats.filter(s => s !== num);
    el.className = 'seat free';
  } else {
    selectedSeats.push(num);
    selectedSeats.sort((a, b) => a - b);
    el.className = 'seat selected';
  }
  updateUI();
}

function updateUI() {
  const wrap    = document.getElementById('bookingFormWrap');
  const noMsg   = document.getElementById('noSelectMsg');
  const display = document.getElementById('selectedSeatsDisplay');
  const fareEl  = document.getElementById('fareAmount');
  const fareLabel = document.getElementById('fareLabel');
  const summaryNums = document.getElementById('selectedSeatNums');
  const summaryFare = document.getElementById('summaryFare');

  summaryNums.textContent = selectedSeats.length ? selectedSeats.join(', ') : 'None';
  summaryFare.textContent = selectedSeats.length ? 'KSh ' + (selectedSeats.length * FARE_PER_SEAT).toLocaleString() : 'KSh 0';

  if (selectedSeats.length > 0) {
    wrap.style.display  = 'flex';
    noMsg.style.display = 'none';
    display.innerHTML   = selectedSeats.map(s => `<span class="seat-pill">Seat ${s}</span>`).join('');
    const total = selectedSeats.length * FARE_PER_SEAT;
    fareEl.textContent  = 'KSh ' + total.toLocaleString();
    fareLabel.textContent = selectedSeats.length + ' seat(s) × KSh ' + FARE_PER_SEAT.toLocaleString();
  } else {
    wrap.style.display  = 'none';
    noMsg.style.display = 'flex';
  }
}

function submitBooking() {
  const fullName    = document.getElementById('fullName').value.trim();
  const idNumber    = document.getElementById('idNumber').value.trim();
  const phoneNumber = document.getElementById('phoneNumber').value.trim();
  const payment     = document.getElementById('paymentMethod').value;

  if (selectedSeats.length === 0) { alert('Please select at least one seat.'); return; }
  if (!/^[a-zA-Z\s]{2,}$/.test(fullName))  { alert('Please enter a valid full name.'); return; }
  if (!/^\d{6,}$/.test(idNumber))           { alert('ID number must be at least 6 digits.'); return; }
  if (!/^\d{10}$/.test(phoneNumber))        { alert('Phone number must be exactly 10 digits.'); return; }

  const btn = document.getElementById('confirmBtn');
  btn.disabled = true;
  btn.textContent = 'Processing...';

  const formData = new FormData();
  formData.append('trip_id', TRIP_ID);
  formData.append('csrf_token', CSRF_TOKEN);
  formData.append('selected_seats', selectedSeats.join(','));
  formData.append('full_name', fullName);
  formData.append('id_number', idNumber);
  formData.append('phone_number', phoneNumber);
  formData.append('payment_method', payment);

  fetch('process_clerk_booking.php', { method: 'POST', body: formData })
    .then(res => res.json())
    .then(data => {
      btn.disabled = false;
      btn.textContent = 'CONFIRM & BOOK SEATS →';
      if (data.success) {
        document.getElementById('bookingFormWrap').style.display = 'none';
        document.getElementById('noSelectMsg').style.display = 'none';
        const refsEl = document.getElementById('refsDisplay');
        refsEl.innerHTML = data.booked.map(b =>
          `<div class="ref-pill">Seat ${b.seat} — ${b.ref}</div>`
        ).join('');
        if (data.skipped && data.skipped.length > 0) {
          const sk = document.getElementById('skippedMsg');
          sk.textContent = 'Already booked (skipped): Seat(s) ' + data.skipped.join(', ');
          sk.style.display = 'block';
        }
        document.getElementById('successBox').style.display = 'block';
        data.booked.forEach(b => {
          const el = document.querySelector(`.seat[data-seat="${b.seat}"]`);
          if (el) el.className = 'seat taken';
        });
        selectedSeats = [];
        updateUI();
      } else {
        alert('Error: ' + data.error);
      }
    })
    .catch(() => {
      btn.disabled = false;
      btn.textContent = 'CONFIRM & BOOK SEATS →';
      alert('Network error. Please try again.');
    });
}

buildSeatMap();
updateUI();
</script>
</body>
</html>