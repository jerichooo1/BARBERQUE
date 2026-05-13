<?php
session_start();
include 'db.php';

if (!isset($_SESSION['user_id'])) {
  header("Location: login.php");
  exit();
}

$appointment_id = $_POST['appointment_id'] ?? null;
$date = $_POST['date'] ?? null;
$time = $_POST['time'] ?? null;

if (!$appointment_id || !$date || !$time) {
  die("Invalid reschedule request.");
}

// Parse time range (e.g. "11:00 AM - 12:00 PM")
list($start_str, $end_str) = explode(" - ", $time);
$start = date("H:i:s", strtotime($start_str));
$end = date("H:i:s", strtotime($end_str));

$new_start = "$date $start";
$new_end = "$date $end";

// ✅ Preserve existing status (don’t reset to pending)
$stmt = $conn->prepare("
  UPDATE appointments 
  SET start_time = ?, end_time = ?
  WHERE id = ? AND customer_id = ?
");
$stmt->bind_param("ssii", $new_start, $new_end, $appointment_id, $_SESSION['user_id']);

if ($stmt->execute()) {
  header("Location: appointments.php?rescheduled=1");
  exit();
} else {
  echo "Failed to update schedule.";
}
?>
