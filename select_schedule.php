<?php
session_start();
include 'db.php';

if (!isset($_POST['barber_id']) || !isset($_POST['service_id'])) {
    header("Location: index.php");
    exit();
}

include 'navbar.php';

/* Read POST values from previous form */
$service_id = intval($_POST['service_id'] ?? 0);
$barber_id = intval($_POST['barber_id'] ?? 0);
$selected_services = isset($_POST['selected_services']) && $_POST['selected_services'] !== ""
    ? explode(",", $_POST['selected_services'])
    : [$service_id];

$total_price = floatval($_POST['total_price'] ?? 0);
$total_duration = intval($_POST['total_duration'] ?? 0);

/* Fetch service details for summary */
$service_details = [];
foreach ($selected_services as $sid) {
    $sid = intval($sid);
    $stmt = $conn->prepare("SELECT name, price, duration_minutes FROM services WHERE id = ?");
    $stmt->bind_param("i", $sid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if ($row) {
        $service_details[] = $row;
    }
    $stmt->close();
}

/* Fetch barber */
$barber = null;
if ($barber_id > 0) {
    $stmt = $conn->prepare("
        SELECT id, name, status
        FROM barbers
        WHERE id = ?
    ");
    $stmt->bind_param("i", $barber_id);
    $stmt->execute();
    $barber = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

/* Prevent inactive / missing barber */
if (!$barber || ($barber['status'] ?? '') !== 'active') {
    echo "<script>alert('This barber is not available for booking.'); window.location.href='index.php';</script>";
    exit();
}

/* Default duration if nothing was calculated */
if ($total_duration <= 0) {
    $total_duration = 30;
}

/* Booking window: today → +1 month */
$today = new DateTime();
$end_date = (clone $today)->modify('+1 month');

$start_str = $today->format("Y-m-d");
$end_str = $end_date->format("Y-m-d");

/*
|--------------------------------------------------------------------------
| Fetch blocked schedules from:
| 1. Online appointments
| 2. Ongoing walk-ins
| 3. Approved leaves
| 4. Weekly schedule
|--------------------------------------------------------------------------
*/
$booked_slots = [];
$approved_leave_dates = [];
$weekly_schedule = [];

/* 1. Online appointments */
$stmt = $conn->prepare("
    SELECT start_time, end_time
    FROM appointments
    WHERE barber_id = ?
      AND DATE(start_time) BETWEEN ? AND ?
      AND status NOT IN ('canceled', 'cancelled', 'completed', 'no-show', 'rejected')
");
$stmt->bind_param("iss", $barber_id, $start_str, $end_str);
$stmt->execute();
$appointments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

foreach ($appointments as $a) {
    $booked_slots[] = [
        "start" => $a['start_time'],
        "end"   => $a['end_time']
    ];
}

/* 2. Ongoing walk-ins */
$stmt = $conn->prepare("
    SELECT started_at, estimated_end_at
    FROM walkins
    WHERE barber_id = ?
      AND status = 'ongoing'
      AND started_at IS NOT NULL
      AND estimated_end_at IS NOT NULL
      AND DATE(started_at) BETWEEN ? AND ?
");
$stmt->bind_param("iss", $barber_id, $start_str, $end_str);
$stmt->execute();
$walkins = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

foreach ($walkins as $w) {
    $booked_slots[] = [
        "start" => $w['started_at'],
        "end"   => $w['estimated_end_at']
    ];
}

/* 3. Approved leaves */
$leaveTableExists = false;
$checkLeaveTable = $conn->query("SHOW TABLES LIKE 'barber_leaves'");
if ($checkLeaveTable && $checkLeaveTable->num_rows > 0) {
    $leaveTableExists = true;
}

if ($leaveTableExists) {
    $stmt = $conn->prepare("
        SELECT leave_date
        FROM barber_leaves
        WHERE barber_id = ?
          AND status = 'approved'
          AND leave_date BETWEEN ? AND ?
    ");
    $stmt->bind_param("iss", $barber_id, $start_str, $end_str);
    $stmt->execute();
    $leaveRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($leaveRows as $leave) {
        $approved_leave_dates[] = $leave['leave_date'];
    }
}

/* 4. Weekly schedule */
$stmt = $conn->prepare("
    SELECT day_of_week, start_time, end_time
    FROM barber_schedules
    WHERE barber_id = ?
");
$stmt->bind_param("i", $barber_id);
$stmt->execute();
$scheduleRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

foreach ($scheduleRows as $sched) {
    $weekly_schedule[$sched['day_of_week']] = [
        'start' => $sched['start_time'],
        'end'   => $sched['end_time']
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Select Schedule</title>
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="select_schedule.css">

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>

    <style>
        .time-slot-btn {
            margin: 5px;
            padding: 10px 15px;
            border: 1px solid #aaa;
            border-radius: 5px;
            cursor: pointer;
            background: #f9f9f9;
        }
        .time-slot-btn.selected {
            background: #c00;
            color: #fff;
            border-color: #900;
        }
        .no-slots {
            padding: 10px;
            color: #666;
            font-style: italic;
        }
        .unavailable-note {
            padding: 10px;
            color: #991b1b;
            font-weight: 600;
        }
    </style>
</head>
<body>

<main class="select-services-main">
    <div class="content-wrapper">

        <!-- LEFT SIDE -->
        <div class="left-column">
            <div class="schedule-container">
                <h2>Select a Schedule</h2>
                <p>Booking period: <?= $today->format("M d, Y") ?> → <?= $end_date->format("M d, Y") ?></p>

                <div class="calendar-and-slots">
                    <div class="calendar-box">
                        <div id="booking-calendar"></div>
                        <input type="hidden" id="booking-date">
                    </div>

                    <div class="slots-box" id="time-slots"></div>
                </div>
            </div>

            <div class="booking-policy">
                <h2>Our Booking Policy</h2>
                <h4>Downpayment</h4>
                <p>A 15% downpayment of your total bill is required to confirm your booking. Payments can be made via GCash.</p>
                <h4>Cancellation Policy</h4>
                <p>Cancellations are allowed once a booking is confirmed. All payments made are non-refundable.</p>
                <h4>Grace Period</h4>
                <p>Clients are given a 15-minute grace period from their scheduled appointment time. If you do not arrive within this timeframe, your appointment will be marked as a no-show and will be voided with no refund.</p>
                <h4>Additional Information</h4>
                <p>All bookings are subject to approval by management. Once approved, your booking will appear in the Appointments section of your account. This serves as your proof of appointment when visiting our shop.</p>
            </div>

            <div class="continue-wrapper">
                <form id="continue-form" action="payment.php" method="post" style="display:none;">
                    <input type="hidden" name="service_id" value="<?= htmlspecialchars($service_id) ?>">
                    <input type="hidden" name="barber_id" value="<?= htmlspecialchars($barber_id) ?>">
                    <input type="hidden" name="selected_services" value="<?= htmlspecialchars(implode(",", $selected_services)) ?>">
                    <input type="hidden" name="total_price" value="<?= htmlspecialchars($total_price) ?>">
                    <input type="hidden" name="total_duration" value="<?= htmlspecialchars($total_duration) ?>">
                    <input type="hidden" name="date" id="form-date">
                    <input type="hidden" name="time" id="form-time">
                    <button type="submit" class="continue-button">Continue</button>
                </form>
            </div>
        </div>

        <!-- RIGHT SIDE -->
        <div class="sticky-sidebar-wrapper">
            <div class="booking-summary-box">
                <h4>Summary</h4>
                <ul id="summary-services">
                    <?php foreach ($service_details as $s): ?>
                        <li>
                            <strong><?= htmlspecialchars($s['name']) ?>:</strong>
                            ₱<?= number_format($s['price'], 0) ?> (<?= (int)$s['duration_minutes'] ?> mins)
                        </li>
                    <?php endforeach; ?>
                </ul>
                <p><strong>Duration:</strong> <?= (int)$total_duration ?> minutes</p>
                <p><strong>Barber:</strong> <?= htmlspecialchars($barber['name'] ?? 'Unknown') ?></p>
                <p><strong>Date:</strong> <span id="summary-date">Not selected</span></p>
                <p><strong>Time:</strong> <span id="summary-time">Not selected</span></p>
                <p><strong>Total to pay:</strong> ₱<?= number_format($total_price, 2) ?></p>
                <p><strong>Downpayment (15%):</strong> ₱<?= number_format($total_price * 0.15, 2) ?></p>
            </div>
        </div>

    </div>
</main>

<script>
const slotDuration = <?= (int)$total_duration ?>;
const booked = <?= json_encode($booked_slots) ?>;
const approvedLeaveDates = <?= json_encode($approved_leave_dates) ?>;
const weeklySchedule = <?= json_encode($weekly_schedule) ?>;

const slotsDiv = document.getElementById("time-slots");
const summaryDate = document.getElementById("summary-date");
const summaryTime = document.getElementById("summary-time");
const continueForm = document.getElementById("continue-form");
const formDate = document.getElementById("form-date");
const formTime = document.getElementById("form-time");
const dateInput = document.getElementById("booking-date");

flatpickr("#booking-calendar", {
    inline: true,
    minDate: "<?= $today->format("Y-m-d") ?>",
    maxDate: "<?= $end_date->format("Y-m-d") ?>",
    dateFormat: "Y-m-d",
    onChange: function(selectedDates, dateStr) {
        dateInput.value = dateStr;
        generateSlots(dateStr);
    }
});

function generateSlots(date) {
    slotsDiv.innerHTML = "";
    summaryDate.innerText = "Not selected";
    summaryTime.innerText = "Not selected";
    continueForm.style.display = "none";

    if (!date) return;

    summaryDate.innerText = new Date(date + "T00:00:00").toLocaleDateString();

    if (approvedLeaveDates.includes(date)) {
        slotsDiv.innerHTML = `<div class="unavailable-note">This barber is on approved leave on this date.</div>`;
        return;
    }

    const dayName = getDayName(date);
    const daySchedule = weeklySchedule[dayName];

    if (!daySchedule) {
        slotsDiv.innerHTML = `<div class="unavailable-note">This barber has no schedule on ${dayName}.</div>`;
        return;
    }

    let start = new Date(date + "T" + daySchedule.start);
    let end = new Date(date + "T" + daySchedule.end);
    let now = new Date();
    let hasAvailableSlot = false;

    while (start.getTime() + slotDuration * 60000 <= end.getTime()) {
        let slotEnd = new Date(start.getTime() + slotDuration * 60000);

        let isPast = slotStartIsPast(start, now, date);

        let conflict = booked.some(b => {
            let bs = new Date(String(b.start).replace(" ", "T"));
            let be = new Date(String(b.end).replace(" ", "T"));
            return (start < be && slotEnd > bs);
        });

        if (!conflict && !isPast) {
            hasAvailableSlot = true;

            let btn = document.createElement("button");
            btn.type = "button";
            btn.className = "time-slot-btn";
            btn.innerText = `${formatTime(start)} - ${formatTime(slotEnd)}`;

            btn.addEventListener("click", function() {
                document.querySelectorAll(".time-slot-btn").forEach(b => b.classList.remove("selected"));
                btn.classList.add("selected");
                summaryTime.innerText = btn.innerText;
                continueForm.style.display = "block";
                formDate.value = date;
                formTime.value = btn.innerText;
            });

            slotsDiv.appendChild(btn);
        }

        start = new Date(start.getTime() + slotDuration * 60000);
    }

    if (!hasAvailableSlot) {
        slotsDiv.innerHTML = `<div class="no-slots">No available time slots for this date.</div>`;
    }
}

function slotStartIsPast(slotStart, now, selectedDate) {
    const todayStr = now.getFullYear() + "-" +
        String(now.getMonth() + 1).padStart(2, "0") + "-" +
        String(now.getDate()).padStart(2, "0");

    return selectedDate === todayStr && slotStart < now;
}

function formatTime(dateObj) {
    return dateObj.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}

function getDayName(dateStr) {
    const date = new Date(dateStr + "T00:00:00");
    const days = ["Sunday", "Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday"];
    return days[date.getDay()];
}
</script>

<?php include 'footer.php'; ?>
</body>
</html>