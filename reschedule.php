<?php
session_start();
include 'db.php';
include 'navbar.php';

if (!isset($_SESSION['user_id'])) {
  header("Location: login.php");
  exit();
}

$userId = $_SESSION['user_id'];
$appointmentId = $_GET['appointment_id'] ?? 0;

// ✅ Fetch appointment + barber
$stmt = $conn->prepare("
  SELECT a.*, b.name AS barber_name, b.id AS barber_id
  FROM appointments a
  JOIN barbers b ON a.barber_id = b.id
  WHERE a.id = ? AND a.customer_id = ?
");
$stmt->bind_param("ii", $appointmentId, $userId);
$stmt->execute();
$appointment = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$appointment) {
  echo "<div style='padding: 80px; text-align:center;'>❌ Appointment not found.</div>";
  exit();
}

// ✅ Fetch total duration of all services in this appointment
$stmt = $conn->prepare("
  SELECT SUM(s.duration_minutes) AS total_duration
  FROM appointment_services aps
  JOIN services s ON aps.service_id = s.id
  WHERE aps.appointment_id = ?
");
$stmt->bind_param("i", $appointmentId);
$stmt->execute();
$total_duration = $stmt->get_result()->fetch_assoc()['total_duration'] ?? 30;
$stmt->close();

// ✅ Fetch booked times for the same barber
$stmt = $conn->prepare("
  SELECT start_time, end_time 
  FROM appointments 
  WHERE barber_id = ? 
  AND id != ? 
  AND status != 'canceled'
");
$stmt->bind_param("ii", $appointment['barber_id'], $appointmentId);
$stmt->execute();
$booked = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$booked_slots = [];
foreach ($booked as $b) {
  $booked_slots[] = [
    'start' => $b['start_time'],
    'end'   => $b['end_time']
  ];
}
?>

<!DOCTYPE html>
<html>
<head>
  <title>Reschedule Appointment</title>
  <link rel="stylesheet" href="styles.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
  <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
  <style>
    .reschedule-container {
      max-width: 700px;
      margin: 100px auto;
      background: #fff;
      border: 2px solid #000;
      border-radius: 20px;
      padding: 2rem;
      text-align: center;
      box-shadow: 0 5px 12px rgba(0,0,0,0.1);
    }
    h2 { color: #240195; margin-bottom: 10px; }
    .calendar-slots {
      display: flex;
      justify-content: center;
      gap: 30px;
      margin-top: 20px;
    }
    #time-slots button {
      margin: 5px;
      padding: 10px 15px;
      border-radius: 10px;
      border: 1px solid #aaa;
      cursor: pointer;
      background: #f9f9f9;
    }
    #time-slots button.selected {
      background: #A70202;
      color: #fff;
      border-color: #700;
    }
    .btn-submit {
      background: #A70202;
      color: #fff;
      padding: 10px 20px;
      border: none;
      border-radius: 20px;
      cursor: pointer;
      transition: background 0.3s ease;
      margin-top: 20px;
    }
    .btn-submit:hover { background: #8b1a1a; }
  </style>
</head>
<body>

<div class="reschedule-container">
  <h2>Reschedule Appointment</h2>
  <p><strong>Barber:</strong> <?= htmlspecialchars($appointment['barber_name']) ?></p>
  <p><strong>Current Schedule:</strong><br>
    <?= date("F j, Y g:i A", strtotime($appointment['start_time'])) ?> – 
    <?= date("g:i A", strtotime($appointment['end_time'])) ?>
  </p>

  <form id="reschedule-form" action="reschedule_process.php" method="POST">
    <input type="hidden" name="appointment_id" value="<?= $appointmentId ?>">
    <input type="hidden" name="date" id="form-date">
    <input type="hidden" name="time" id="form-time">

    <div class="calendar-slots">
      <div>
        <div id="calendar"></div>
      </div>
      <div id="time-slots"></div>
    </div>

    <button type="submit" class="btn-submit" style="display:none;">Confirm Reschedule</button>
  </form>
</div>

<script>
const slotDuration = <?= $total_duration ?>;
const booked = <?= json_encode($booked_slots) ?>;
const today = new Date().toISOString().split('T')[0];

const form = document.getElementById("reschedule-form");
const btnSubmit = document.querySelector(".btn-submit");
const timeSlotsDiv = document.getElementById("time-slots");
const formDate = document.getElementById("form-date");
const formTime = document.getElementById("form-time");

flatpickr("#calendar", {
  inline: true,
  minDate: today,
  maxDate: new Date().fp_incr(30),
  onChange: function(selectedDates, dateStr) {
    formDate.value = dateStr;
    generateSlots(dateStr);
  }
});

function generateSlots(date) {
  timeSlotsDiv.innerHTML = "";
  btnSubmit.style.display = "none";

  let start = new Date(date + "T10:00:00");
  let end = new Date(date + "T22:00:00");

  while (start.getTime() + slotDuration * 60000 <= end.getTime()) {
    let slotEnd = new Date(start.getTime() + slotDuration * 60000);
    let conflict = booked.some(b => {
      let bs = new Date(b.start);
      let be = new Date(b.end);
      return (start < be && slotEnd > bs);
    });

    if (!conflict) {
      let btn = document.createElement("button");
      btn.type = "button";
      btn.textContent = 
        start.toLocaleTimeString([], {hour: '2-digit', minute: '2-digit'}) + " - " + 
        slotEnd.toLocaleTimeString([], {hour: '2-digit', minute: '2-digit'});

      btn.onclick = () => {
        document.querySelectorAll("#time-slots button").forEach(b => b.classList.remove("selected"));
        btn.classList.add("selected");
        formTime.value = btn.textContent;
        btnSubmit.style.display = "inline-block";
      };
      timeSlotsDiv.appendChild(btn);
    }

    start = new Date(start.getTime() + slotDuration * 60000);
  }
}
</script>

</body>
</html>
