<?php
session_start();
include 'db.php';

$settings = $conn->query("SELECT * FROM settings LIMIT 1");
$shop = $settings ? $settings->fetch_assoc() : [];

include 'navbar.php';

if (!isset($_SESSION['user_id'])) {
  header("Location: login.php");
  exit();
}

$customer_id = (int)$_SESSION['user_id'];

$success_message = $_SESSION['success_message'] ?? '';
$error_message   = $_SESSION['error_message'] ?? '';
unset($_SESSION['success_message'], $_SESSION['error_message']);

/* =========================
   HELPERS
========================= */
function formatScheduleRange($start, $end) {
  if (empty($start)) return 'Not set';

  $startTs = strtotime($start);
  $endTs   = !empty($end) ? strtotime($end) : null;

  if (!$startTs) return 'Not set';

  if ($endTs) {
    return date("F j, Y g:i A", $startTs) . ' - ' . date("g:i A", $endTs);
  }

  return date("F j, Y g:i A", $startTs);
}

function splitMainAndAddons($servicesText) {
  $services = array_filter(array_map('trim', explode(',', (string)$servicesText)));
  $mainService = $services[0] ?? 'N/A';
  $addons = array_slice($services, 1);

  return [
    'main_service' => $mainService,
    'addons' => $addons
  ];
}

function canStillRescheduleByTime($scheduledStart, $leaveCredit = 0) {
  if ((int)$leaveCredit > 0) {
    return true;
  }

  if (empty($scheduledStart)) {
    return true;
  }

  $startTs = strtotime($scheduledStart);
  if (!$startTs) {
    return true;
  }

  return time() < ($startTs - (30 * 60));
}

/* =========================
   APPOINTMENTS OF CUSTOMER
========================= */
$sql = "
  SELECT 
         a.id AS appointment_id, 
         a.barber_id,
         a.start_time, 
         a.end_time, 
         a.status AS appointment_status, 
         a.rejection_reason,
         a.customer_reschedule_count,
         a.leave_reschedule_credit,
         a.reschedule_reason,
         b.name AS barber_name,
         GROUP_CONCAT(DISTINCT s.name ORDER BY s.name SEPARATOR ', ') AS services,
         COALESCE(SUM(aps.price), 0) AS total_price,
         COALESCE(SUM(aps.downpayment), 0) AS total_downpayment,
         COALESCE(SUM(aps.duration_minutes), 0) AS total_duration,
         p.id AS payment_id,
         p.amount_paid,
         p.balance_amount,
         p.total_amount AS payment_total_amount,
         p.downpayment_amount,
         p.reference_number,
         p.proof_image,
         p.status AS payment_status
  FROM appointments a
  JOIN barbers b ON a.barber_id = b.id
  LEFT JOIN appointment_services aps ON a.id = aps.appointment_id
  LEFT JOIN services s ON aps.service_id = s.id
  LEFT JOIN payments p ON p.id = (
      SELECT id
      FROM payments
      WHERE appointment_id = a.id
      ORDER BY created_at DESC, id DESC
      LIMIT 1
  )
  WHERE a.customer_id = ?
  GROUP BY a.id
  ORDER BY a.start_time DESC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $customer_id);
$stmt->execute();
$result = $stmt->get_result();

$appointments = [];
$appointmentIds = [];

while ($row = $result->fetch_assoc()) {
  $row['appointment_id'] = (int)$row['appointment_id'];
  $row['barber_id'] = (int)$row['barber_id'];
  $row['total_price'] = (float)($row['total_price'] ?? 0);
  $row['total_downpayment'] = (float)($row['total_downpayment'] ?? 0);
  $row['total_duration'] = (int)($row['total_duration'] ?? 0);
  $row['amount_paid'] = isset($row['amount_paid']) ? (float)$row['amount_paid'] : 0;
  $row['balance_amount'] = isset($row['balance_amount']) ? (float)$row['balance_amount'] : 0;
  $row['payment_total_amount'] = isset($row['payment_total_amount']) ? (float)$row['payment_total_amount'] : 0;
  $row['downpayment_amount'] = isset($row['downpayment_amount']) ? (float)$row['downpayment_amount'] : 0;
  $row['customer_reschedule_count'] = (int)($row['customer_reschedule_count'] ?? 0);
  $row['leave_reschedule_credit'] = (int)($row['leave_reschedule_credit'] ?? 0);
  $row['reschedule_reason'] = $row['reschedule_reason'] ?? '';
  $appointments[] = $row;
  $appointmentIds[] = (int)$row['appointment_id'];
}
$stmt->close();

/* =========================
   COMPANION TABLE CHECK
========================= */
$companionTableExists = false;
$companionTableCols = [];

$checkCompanionTable = $conn->query("SHOW TABLES LIKE 'appointment_companions'");
if ($checkCompanionTable && $checkCompanionTable->num_rows > 0) {
  $companionTableExists = true;

  $companionColsRes = $conn->query("SHOW COLUMNS FROM appointment_companions");
  if ($companionColsRes) {
    while ($col = $companionColsRes->fetch_assoc()) {
      $companionTableCols[] = $col['Field'];
    }
  }
}

$hasCompanionBarberId      = in_array('barber_id', $companionTableCols, true);
$hasCompanionStartTime     = in_array('start_time', $companionTableCols, true);
$hasCompanionEndTime       = in_array('end_time', $companionTableCols, true);
$hasCompanionRescheduleCnt = in_array('customer_reschedule_count', $companionTableCols, true);
$hasCompanionLeaveCredit   = in_array('leave_reschedule_credit', $companionTableCols, true);
$hasCompanionReschedReason = in_array('reschedule_reason', $companionTableCols, true);

/* =========================
   COMPANIONS PER APPOINTMENT
========================= */
$companionsMap = [];

if ($companionTableExists && !empty($appointmentIds)) {
  $placeholders = implode(',', array_fill(0, count($appointmentIds), '?'));
  $types = str_repeat('i', count($appointmentIds));

  $selectBarberId      = $hasCompanionBarberId      ? "ac.barber_id," : "NULL AS barber_id,";
  $selectStartTime     = $hasCompanionStartTime     ? "ac.start_time," : "NULL AS start_time,";
  $selectEndTime       = $hasCompanionEndTime       ? "ac.end_time," : "NULL AS end_time,";
  $selectRescheduleCnt = $hasCompanionRescheduleCnt ? "ac.customer_reschedule_count," : "0 AS customer_reschedule_count,";
  $selectLeaveCredit   = $hasCompanionLeaveCredit   ? "ac.leave_reschedule_credit," : "0 AS leave_reschedule_credit,";
  $selectReschedReason = $hasCompanionReschedReason ? "ac.reschedule_reason," : "NULL AS reschedule_reason,";

  $companionSql = "
    SELECT 
      ac.appointment_id,
      ac.person_no,
      ac.full_name,
      ac.service_id,
      ac.selected_services,
      ac.total_price,
      ac.total_duration,
      s.name AS main_service_name,
      {$selectBarberId}
      {$selectStartTime}
      {$selectEndTime}
      {$selectRescheduleCnt}
      {$selectLeaveCredit}
      {$selectReschedReason}
      b.name AS companion_barber_name
    FROM appointment_companions ac
    LEFT JOIN services s ON ac.service_id = s.id
    LEFT JOIN barbers b ON " . ($hasCompanionBarberId ? "ac.barber_id = b.id" : "1=0") . "
    WHERE ac.appointment_id IN ($placeholders)
    ORDER BY ac.appointment_id DESC, ac.person_no ASC
  ";

  $companionStmt = $conn->prepare($companionSql);
  $companionStmt->bind_param($types, ...$appointmentIds);
  $companionStmt->execute();
  $companionResult = $companionStmt->get_result();

  while ($comp = $companionResult->fetch_assoc()) {
    $appointmentId = (int)$comp['appointment_id'];
    $selectedServiceIds = json_decode($comp['selected_services'] ?? '[]', true);
    if (!is_array($selectedServiceIds)) {
      $selectedServiceIds = [];
    }

    $companionsMap[$appointmentId][] = [
      'person_no' => (int)$comp['person_no'],
      'full_name' => $comp['full_name'] ?? '',
      'service_id' => (int)($comp['service_id'] ?? 0),
      'main_service_name' => $comp['main_service_name'] ?? '',
      'selected_services_ids' => $selectedServiceIds,
      'total_price' => (float)($comp['total_price'] ?? 0),
      'total_duration' => (int)($comp['total_duration'] ?? 0),
      'addon_names' => [],
      'barber_id' => isset($comp['barber_id']) ? (int)$comp['barber_id'] : 0,
      'barber_name' => $comp['companion_barber_name'] ?? '',
      'start_time' => $comp['start_time'] ?? null,
      'end_time' => $comp['end_time'] ?? null,
      'customer_reschedule_count' => (int)($comp['customer_reschedule_count'] ?? 0),
      'leave_reschedule_credit' => (int)($comp['leave_reschedule_credit'] ?? 0),
      'reschedule_reason' => $comp['reschedule_reason'] ?? ''
    ];
  }
  $companionStmt->close();

  $allAddonIds = [];
  foreach ($companionsMap as $appointmentId => $persons) {
    foreach ($persons as $person) {
      foreach ($person['selected_services_ids'] as $sid) {
        $sid = (int)$sid;
        if ($sid > 0 && $sid !== (int)$person['service_id']) {
          $allAddonIds[] = $sid;
        }
      }
    }
  }

  $allAddonIds = array_values(array_unique($allAddonIds));
  $addonServiceMap = [];

  if (!empty($allAddonIds)) {
    $ph = implode(',', array_fill(0, count($allAddonIds), '?'));
    $tp = str_repeat('i', count($allAddonIds));

    $addonStmt = $conn->prepare("SELECT id, name FROM services WHERE id IN ($ph)");
    $addonStmt->bind_param($tp, ...$allAddonIds);
    $addonStmt->execute();
    $addonRes = $addonStmt->get_result();
    while ($addonRow = $addonRes->fetch_assoc()) {
      $addonServiceMap[(int)$addonRow['id']] = $addonRow['name'];
    }
    $addonStmt->close();
  }

  foreach ($companionsMap as $appointmentId => &$persons) {
    foreach ($persons as &$person) {
      $addonNames = [];
      foreach ($person['selected_services_ids'] as $sid) {
        $sid = (int)$sid;
        if ($sid > 0 && $sid !== (int)$person['service_id'] && isset($addonServiceMap[$sid])) {
          $addonNames[] = $addonServiceMap[$sid];
        }
      }
      $person['addon_names'] = $addonNames;
    }
    unset($person);
  }
  unset($persons);
}

/* =========================
   RATED APPOINTMENTS / PERSONS
========================= */
$ratedAppointments = [];
$ratedPersons = [];

$barberRatingsTableExists = false;
$barberRatingsCols = [];

$checkRatingsTable = $conn->query("SHOW TABLES LIKE 'barber_ratings'");
if ($checkRatingsTable && $checkRatingsTable->num_rows > 0) {
  $barberRatingsTableExists = true;

  $ratingsColsRes = $conn->query("SHOW COLUMNS FROM barber_ratings");
  if ($ratingsColsRes) {
    while ($col = $ratingsColsRes->fetch_assoc()) {
      $barberRatingsCols[] = $col['Field'];
    }
  }
}

$hasRatingPersonNo = in_array('person_no', $barberRatingsCols, true);
$hasRatingBarberId = in_array('barber_id', $barberRatingsCols, true);

if ($barberRatingsTableExists) {
  if ($hasRatingPersonNo && $hasRatingBarberId) {
    $ratedStmt = $conn->prepare("
      SELECT appointment_id, person_no, barber_id
      FROM barber_ratings
      WHERE customer_id = ?
    ");
    $ratedStmt->bind_param("i", $customer_id);
    $ratedStmt->execute();
    $ratedResult = $ratedStmt->get_result();

    while ($ratedRow = $ratedResult->fetch_assoc()) {
      $appointmentId = (int)$ratedRow['appointment_id'];
      $personNo = (int)($ratedRow['person_no'] ?? 0);
      $barberId = (int)($ratedRow['barber_id'] ?? 0);

      $ratedAppointments[] = $appointmentId;
      $ratedPersons[$appointmentId . '_' . $personNo . '_' . $barberId] = true;
    }
    $ratedStmt->close();
  } elseif ($hasRatingPersonNo) {
    $ratedStmt = $conn->prepare("
      SELECT appointment_id, person_no
      FROM barber_ratings
      WHERE customer_id = ?
    ");
    $ratedStmt->bind_param("i", $customer_id);
    $ratedStmt->execute();
    $ratedResult = $ratedStmt->get_result();

    while ($ratedRow = $ratedResult->fetch_assoc()) {
      $appointmentId = (int)$ratedRow['appointment_id'];
      $personNo = (int)($ratedRow['person_no'] ?? 0);

      $ratedAppointments[] = $appointmentId;
      $ratedPersons[$appointmentId . '_' . $personNo] = true;
    }
    $ratedStmt->close();
  } else {
    $ratedStmt = $conn->prepare("
      SELECT appointment_id
      FROM barber_ratings
      WHERE customer_id = ?
    ");
    $ratedStmt->bind_param("i", $customer_id);
    $ratedStmt->execute();
    $ratedResult = $ratedStmt->get_result();

    while ($ratedRow = $ratedResult->fetch_assoc()) {
      $ratedAppointments[] = (int)$ratedRow['appointment_id'];
    }
    $ratedStmt->close();
  }
}

/* =========================
   ACTIVE BARBERS
========================= */
$barbers = [];
$barberQuery = $conn->query("
  SELECT id, name
  FROM barbers
  WHERE status = 'active'
  ORDER BY name ASC
");
if ($barberQuery) {
  while ($b = $barberQuery->fetch_assoc()) {
    $barbers[] = [
      'id'   => (int)$b['id'],
      'name' => $b['name']
    ];
  }
}

/* =========================
   BOOKING WINDOW
========================= */
$today = new DateTime();
$end_date = (clone $today)->modify('+1 month');
$start_str = $today->format("Y-m-d");
$end_str   = $end_date->format("Y-m-d");

/* =========================
   DATA PER BARBER
========================= */
$barberSchedulesData = [];
$barberLeavesData = [];
$barberBookedData = [];

$leaveTableExists = false;
$checkLeaveTable = $conn->query("SHOW TABLES LIKE 'barber_leaves'");
if ($checkLeaveTable && $checkLeaveTable->num_rows > 0) {
  $leaveTableExists = true;
}

foreach ($barbers as $b) {
  $bid = (int)$b['id'];

  $weekly_schedule = [];
  $scheduleStmt = $conn->prepare("
      SELECT day_of_week, start_time, end_time
      FROM barber_schedules
      WHERE barber_id = ?
  ");
  $scheduleStmt->bind_param("i", $bid);
  $scheduleStmt->execute();
  $scheduleRows = $scheduleStmt->get_result()->fetch_all(MYSQLI_ASSOC);
  $scheduleStmt->close();

  foreach ($scheduleRows as $sched) {
    $weekly_schedule[$sched['day_of_week']] = [
      'start' => $sched['start_time'],
      'end'   => $sched['end_time']
    ];
  }
  $barberSchedulesData[$bid] = $weekly_schedule;

  $approved_leave_dates = [];
  if ($leaveTableExists) {
    $stmtCols = $conn->query("SHOW COLUMNS FROM barber_leaves");
    $leaveCols = [];
    if ($stmtCols) {
      while ($col = $stmtCols->fetch_assoc()) {
        $leaveCols[] = $col['Field'];
      }
    }

    if (in_array('leave_date', $leaveCols)) {
      $leaveStmt = $conn->prepare("
          SELECT leave_date
          FROM barber_leaves
          WHERE barber_id = ?
            AND status = 'approved'
            AND leave_date BETWEEN ? AND ?
      ");
      $leaveStmt->bind_param("iss", $bid, $start_str, $end_str);
      $leaveStmt->execute();
      $leaveRows = $leaveStmt->get_result()->fetch_all(MYSQLI_ASSOC);
      $leaveStmt->close();

      foreach ($leaveRows as $leave) {
        $approved_leave_dates[] = $leave['leave_date'];
      }
    }
  }
  $barberLeavesData[$bid] = $approved_leave_dates;

  $booked_slots = [];

  $appointmentBlockStmt = $conn->prepare("
      SELECT id, start_time, end_time
      FROM appointments
      WHERE barber_id = ?
        AND DATE(start_time) BETWEEN ? AND ?
        AND status NOT IN ('canceled', 'cancelled', 'completed', 'no-show', 'rejected')
  ");
  $appointmentBlockStmt->bind_param("iss", $bid, $start_str, $end_str);
  $appointmentBlockStmt->execute();
  $appointmentsBlocked = $appointmentBlockStmt->get_result()->fetch_all(MYSQLI_ASSOC);
  $appointmentBlockStmt->close();

  foreach ($appointmentsBlocked as $a) {
    $booked_slots[] = [
      "appointment_id" => (int)$a['id'],
      "person_no" => 0,
      "source" => "appointment",
      "start" => $a['start_time'],
      "end"   => $a['end_time']
    ];
  }

  if ($companionTableExists && $hasCompanionBarberId && $hasCompanionStartTime && $hasCompanionEndTime) {
    $companionBlockStmt = $conn->prepare("
      SELECT 
        ac.appointment_id,
        ac.person_no,
        ac.start_time,
        ac.end_time
      FROM appointment_companions ac
      JOIN appointments a ON a.id = ac.appointment_id
      WHERE ac.barber_id = ?
        AND ac.start_time IS NOT NULL
        AND ac.end_time IS NOT NULL
        AND DATE(ac.start_time) BETWEEN ? AND ?
        AND a.status NOT IN ('canceled', 'cancelled', 'completed', 'no-show', 'rejected')
    ");
    $companionBlockStmt->bind_param("iss", $bid, $start_str, $end_str);
    $companionBlockStmt->execute();
    $companionBlocked = $companionBlockStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $companionBlockStmt->close();

    foreach ($companionBlocked as $cb) {
      $booked_slots[] = [
        "appointment_id" => (int)$cb['appointment_id'],
        "person_no" => (int)$cb['person_no'],
        "source" => "companion",
        "start" => $cb['start_time'],
        "end"   => $cb['end_time']
      ];
    }
  }

  $walkinStmt = $conn->prepare("
      SELECT started_at, estimated_end_at
      FROM walkins
      WHERE barber_id = ?
        AND status = 'ongoing'
        AND started_at IS NOT NULL
        AND estimated_end_at IS NOT NULL
        AND DATE(started_at) BETWEEN ? AND ?
  ");
  $walkinStmt->bind_param("iss", $bid, $start_str, $end_str);
  $walkinStmt->execute();
  $walkins = $walkinStmt->get_result()->fetch_all(MYSQLI_ASSOC);
  $walkinStmt->close();

  foreach ($walkins as $w) {
    $booked_slots[] = [
      "appointment_id" => 0,
      "person_no" => 0,
      "source" => "walkin",
      "start" => $w['started_at'],
      "end"   => $w['estimated_end_at']
    ];
  }

  $barberBookedData[$bid] = $booked_slots;
}
?>

<link rel="stylesheet" href="appointments.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>

<style>
/* ================= MODERN APPOINTMENTS PAGE ================= */

.appointments-main {
  min-height: 100vh;
  padding: 150px 24px 70px;
  background:
    radial-gradient(circle at top left, rgba(11, 61, 145, 0.13), transparent 34%),
    linear-gradient(180deg, #ffffff 0%, #f7f9fc 48%, #ffffff 100%);
  color: #07172f;
}

.content-wrapper {
  width: min(1180px, 100%);
  margin: 0 auto;
}

.left-column > h2 {
  margin: 0 0 24px;
  color: #07172f;
  font-size: clamp(2rem, 4vw, 3rem);
  font-weight: 900;
  letter-spacing: -1.3px;
}

.appointment-card {
  position: relative;
  overflow: hidden;
  background: #ffffff;
  border: 1px solid #dbe3ef;
  border-radius: 28px;
  padding: 26px;
  margin-bottom: 24px;
  box-shadow: 0 16px 40px rgba(7, 23, 47, 0.08);
}

.appointment-card::before {
  content: "";
  position: absolute;
  inset: 0 0 auto;
  height: 6px;
  background: linear-gradient(90deg, #061f4a, #0b3d91);
}

.appointment-card h3 {
  margin: 10px 0 18px;
  color: #07172f;
  font-size: 1.25rem;
  font-weight: 900;
  line-height: 1.3;
}

.appointment-card p {
  margin: 8px 0;
  color: #475569;
  line-height: 1.6;
  font-weight: 700;
}

.appointment-card strong {
  color: #07172f;
}

.status {
  display: inline-flex;
  align-items: center;
  margin-left: 10px;
  padding: 7px 12px;
  border-radius: 999px;
  font-size: 0.78rem;
  font-weight: 900;
  vertical-align: middle;
}

.status.pending-approval {
  background: #fff7ed;
  color: #9a3412;
}

.status.approved {
  background: #ecfdf5;
  color: #047857;
}

.status.rejected {
  background: #fff1f2;
  color: #be123c;
}

.status.completed {
  background: #eaf1ff;
  color: #0b3d91;
}

.status.no-show {
  background: #fef3c7;
  color: #92400e;
}

.status.canceled,
.status.cancelled {
  background: #f1f5f9;
  color: #475569;
}

.btn {
  min-height: 42px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 0 16px;
  border-radius: 999px;
  border: none;
  text-decoration: none;
  font-weight: 900;
  cursor: pointer;
  transition: 0.25s ease;
}

.btn:hover {
  transform: translateY(-2px);
}

.btn.btn-primary {
  background: linear-gradient(135deg, #061f4a, #0b3d91);
  color: #ffffff;
}

.btn.btn-secondary {
  background: #eaf1ff;
  color: #061f4a;
}

.btn.btn-danger {
  background: #b91c1c;
  color: #ffffff;
}

.btn.btn-success {
  background: #047857;
  color: #ffffff;
}

.actions,
.companion-actions {
  margin-top: 18px;
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
}

.status-block,
.rejection-box,
.companion-card {
  margin-top: 14px;
  padding: 16px;
  border-radius: 18px;
  border: 1px solid #dbe3ef;
  background: #f8fbff;
}

.status-block.approved-block {
  background: #ecfdf5;
  border-color: #bbf7d0;
}

.status-block.completed-block {
  background: #eaf1ff;
  border-color: #dbe8ff;
}

.status-block.no-show-block {
  background: #fff7ed;
  border-color: #fed7aa;
}

.status-block.cancelled-block {
  background: #f8fafc;
  border-color: #e2e8f0;
}

.rejection-box {
  background: #fff1f2;
  border-color: #fecdd3;
}

.rejection-reason {
  color: #9f1239 !important;
}

.companion-list {
  margin-top: 16px;
  display: grid;
  gap: 14px;
}

.companion-card {
  background: #ffffff;
  box-shadow: 0 10px 24px rgba(7, 23, 47, 0.05);
}

.companion-card h4 {
  margin: 0 0 12px;
  color: #0b3d91;
  font-size: 1.1rem;
  font-weight: 900;
}

.already-rated-badge {
  display: inline-flex;
  align-items: center;
  min-height: 42px;
  padding: 0 16px;
  border-radius: 999px;
  background: #fef3c7;
  color: #92400e;
  font-weight: 900;
}

/* ================= MODALS ================= */

.custom-alert-backdrop,
.edit-modal-backdrop,
.rate-modal-backdrop,
.cancel-modal-backdrop,
.repay-modal-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(7, 23, 47, 0.68);
  display: none;
  align-items: center;
  justify-content: center;
  z-index: 20000;
  padding: 20px;
  backdrop-filter: blur(8px);
}

.custom-alert-backdrop.show,
.edit-modal-backdrop.show,
.rate-modal-backdrop.show,
.cancel-modal-backdrop.show,
.repay-modal-backdrop.show {
  display: flex;
}

.custom-alert-box,
.cancel-modal-box,
.repay-modal-box,
.rate-modal,
.edit-modal {
  background: #ffffff;
  border: 1px solid #dbe3ef;
  border-radius: 28px;
  padding: 28px;
  box-shadow: 0 28px 70px rgba(7, 23, 47, 0.28);
}

.custom-alert-box,
.cancel-modal-box,
.repay-modal-box,
.rate-modal {
  width: min(92%, 500px);
}

.edit-modal {
  width: min(100%, 980px);
  max-height: 92vh;
  overflow-y: auto;
}

.custom-alert-box,
.cancel-modal-box,
.repay-modal-box {
  text-align: center;
}

.custom-alert-box h3,
.cancel-modal-box h3,
.repay-modal-box h3,
.rate-modal-header h2,
.edit-modal-header h2 {
  margin: 0;
  color: #07172f;
  font-weight: 900;
}

.custom-alert-btn {
  min-height: 44px;
  padding: 0 22px;
  border: none;
  border-radius: 999px;
  background: linear-gradient(135deg, #061f4a, #0b3d91);
  color: #ffffff;
  font-weight: 900;
  cursor: pointer;
}

.edit-modal-header,
.rate-modal-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
  margin-bottom: 18px;
}

.edit-close-btn,
.rate-close-btn,
.repay-close-btn {
  width: 42px;
  height: 42px;
  border: none;
  border-radius: 50%;
  background: #f1f5f9;
  color: #07172f;
  font-size: 26px;
  cursor: pointer;
}

.edit-modal-subtext {
  margin-bottom: 18px;
  color: #64748b;
  line-height: 1.6;
  font-weight: 700;
}

.edit-grid {
  display: grid;
  grid-template-columns: 300px 1fr;
  gap: 24px;
}

.edit-panel {
  background: #f8fbff;
  border: 1px solid #dbe3ef;
  border-radius: 20px;
  padding: 18px;
}

.edit-panel h4 {
  margin: 0 0 12px;
  color: #0b3d91;
  font-weight: 900;
}

.edit-form-group {
  margin-bottom: 16px;
}

.edit-form-group label,
.repay-form-group label,
.rate-label {
  display: block;
  margin-bottom: 8px;
  color: #07172f;
  font-weight: 900;
}

.edit-form-group select,
.repay-form-group input[type="text"],
.repay-form-group input[type="file"],
.rate-textarea {
  width: 100%;
  padding: 12px 14px;
  border: 1.5px solid #dbe3ef;
  border-radius: 16px;
  background: #ffffff;
  font-size: 15px;
  box-sizing: border-box;
  outline: none;
}

.edit-form-group select:focus,
.repay-form-group input:focus,
.rate-textarea:focus {
  border-color: #0b3d91;
  box-shadow: 0 0 0 4px rgba(11, 61, 145, 0.12);
}

.calendar-slots-wrap {
  display: grid;
  grid-template-columns: 320px 1fr;
  gap: 20px;
  align-items: start;
}

#edit-calendar {
  display: none;
}

.flatpickr-calendar.inline {
  margin: 0 !important;
  box-shadow: 0 12px 30px rgba(7, 23, 47, 0.10) !important;
  border: 1px solid #dbe3ef !important;
  border-radius: 20px !important;
}

.slots-title {
  font-weight: 900;
  margin-bottom: 10px;
  color: #07172f;
}

.slots-grid {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
}

.slot-btn {
  min-width: 110px;
  min-height: 42px;
  padding: 0 14px;
  border-radius: 999px;
  border: 1.5px solid #dbe3ef;
  background: #ffffff;
  color: #07172f;
  cursor: pointer;
  font-weight: 900;
}

.slot-btn:hover,
.slot-btn.selected {
  background: linear-gradient(135deg, #061f4a, #0b3d91);
  border-color: #0b3d91;
  color: #ffffff;
}

.slot-empty,
.selected-summary {
  background: #f8fbff;
  border: 1px solid #dbe3ef;
  color: #475569;
  border-radius: 16px;
  padding: 14px;
  font-weight: 800;
}

.edit-actions,
.rate-actions,
.cancel-modal-actions,
.repay-actions {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
  margin-top: 18px;
  flex-wrap: wrap;
}

.rate-stars {
  display: flex;
  justify-content: center;
  gap: 8px;
  font-size: 42px;
  margin: 16px 0 10px;
}

.rate-star {
  color: #cbd5e1;
  cursor: pointer;
  transition: 0.2s ease;
}

.rate-star.active {
  color: #f59e0b;
}

.rate-star:hover {
  transform: scale(1.08);
}

.rate-helper-text {
  text-align: center;
  color: #64748b;
  font-weight: 700;
}

.repay-preview-wrap {
  margin-top: 10px;
  text-align: left;
  display: none;
}

.repay-preview-wrap img {
  width: 100%;
  max-width: 220px;
  border-radius: 16px;
  border: 1px solid #dbe3ef;
}

/* ================= RESPONSIVE ================= */

@media (max-width: 900px) {
  .edit-grid,
  .calendar-slots-wrap {
    grid-template-columns: 1fr;
  }

  .appointments-main {
    padding: 125px 16px 50px;
  }
}

@media (max-width: 600px) {
  .appointments-main {
    padding: 110px 12px 38px;
  }

  .appointment-card,
  .custom-alert-box,
  .cancel-modal-box,
  .repay-modal-box,
  .rate-modal,
  .edit-modal {
    border-radius: 22px;
    padding: 20px;
  }

  .appointment-card h3 {
    display: flex;
    flex-direction: column;
    gap: 10px;
  }

  .status {
    margin-left: 0;
    width: fit-content;
  }

  .btn,
  .finish-btn {
    width: 100%;
  }

  .actions,
  .companion-actions,
  .edit-actions,
  .rate-actions,
  .cancel-modal-actions,
  .repay-actions {
    flex-direction: column;
  }

  .rate-stars {
    font-size: 36px;
  }
}
</style>

<main class="appointments-main">
  <div class="content-wrapper">
    <div class="left-column">
      <h2>Appointments</h2>

      <?php if (!empty($appointments)): ?>
        <?php foreach ($appointments as $row): ?>
          <?php
            $appointmentId = (int)$row['appointment_id'];
            $status = strtolower(trim($row['appointment_status'] ?? 'unknown'));

            switch ($status) {
              case 'pending approval': $status_label = "Pending Approval"; break;
              case 'approved': $status_label = "Approved"; break;
              case 'rejected': $status_label = "Rejected"; break;
              case 'canceled':
              case 'cancelled': $status_label = "Canceled"; break;
              case 'completed': $status_label = "Completed"; break;
              case 'no-show': $status_label = "No-Show"; break;
              default: $status_label = "Unknown";
            }

            $companions = $companionsMap[$appointmentId] ?? [];
            $amount_paid = (float)$row['amount_paid'];
            $balance_amount = (float)$row['balance_amount'];
            $parentSchedule = formatScheduleRange($row['start_time'], $row['end_time']);
            $currentSchedule = $parentSchedule;

            $splitServices = splitMainAndAddons($row['services'] ?? '');
            $parentMainService = $splitServices['main_service'];
            $parentAddons = $splitServices['addons'];

            $rescheduleCount = (int)($row['customer_reschedule_count'] ?? 0);
            $leaveCredit = (int)($row['leave_reschedule_credit'] ?? 0);
            $withinRescheduleWindowParent = canStillRescheduleByTime($row['start_time'], $leaveCredit);
            $canEditAppointment = (($rescheduleCount < 1) || ($leaveCredit > 0)) && $withinRescheduleWindowParent;

            $parentAlreadyRated = false;
            if ($hasRatingPersonNo && $hasRatingBarberId) {
              $parentAlreadyRated = isset($ratedPersons[$appointmentId . '_0_' . (int)$row['barber_id']]);
            } elseif ($hasRatingPersonNo) {
              $parentAlreadyRated = isset($ratedPersons[$appointmentId . '_0']);
            } else {
              $parentAlreadyRated = in_array($appointmentId, $ratedAppointments, true);
            }
          ?>
          <div class="appointment-card">
            <h3>
              <?= date("F j, Y g:i A", strtotime($row['start_time'])) ?>
              <span class="status <?= str_replace(' ', '-', $status) ?>">
                <?= $status_label ?>
              </span>
            </h3>

            <?php if (empty($companions)): ?>
              <p><strong>Main Service:</strong> <?= htmlspecialchars($parentMainService) ?></p>
              <p><strong>Add-on Services:</strong> <?= !empty($parentAddons) ? htmlspecialchars(implode(', ', $parentAddons)) : 'None' ?></p>
              <p><strong>Barber:</strong> <?= htmlspecialchars($row['barber_name']) ?></p>
              <p><strong>Schedule:</strong> <?= htmlspecialchars($parentSchedule) ?></p>
              <p><strong>Total:</strong> ₱<?= number_format($row['total_price'], 2) ?></p>
              <p><strong>Downpayment:</strong> ₱<?= number_format($row['total_downpayment'], 2) ?></p>
            <?php else: ?>
              <p><strong>Total:</strong> ₱<?= number_format($row['total_price'], 2) ?></p>
              <p><strong>Downpayment:</strong> ₱<?= number_format($row['total_downpayment'], 2) ?></p>

              <div class="companion-list">
                <?php foreach ($companions as $person): ?>
                  <?php
                    $personBarberId = !empty($person['barber_id']) ? (int)$person['barber_id'] : (int)$row['barber_id'];
                    $personBarber = !empty($person['barber_name']) ? $person['barber_name'] : $row['barber_name'];
                    $personSchedule = (!empty($person['start_time']) || !empty($person['end_time']))
                      ? formatScheduleRange($person['start_time'], $person['end_time'])
                      : $parentSchedule;
                    $personServiceText = trim(($person['main_service_name'] ?: 'N/A') . (!empty($person['addon_names']) ? ', ' . implode(', ', $person['addon_names']) : ''));
                    $personRescheduleCount = (int)($person['customer_reschedule_count'] ?? 0);
                    $personLeaveCredit = (int)($person['leave_reschedule_credit'] ?? 0);
                    $withinRescheduleWindowPerson = canStillRescheduleByTime($person['start_time'] ?? $row['start_time'], $personLeaveCredit);
                    $canEditPerson = (($personRescheduleCount < 1) || ($personLeaveCredit > 0)) && $withinRescheduleWindowPerson;

                    $personAlreadyRated = false;
                    if ($hasRatingPersonNo && $hasRatingBarberId) {
                      $personAlreadyRated = isset($ratedPersons[$appointmentId . '_' . (int)$person['person_no'] . '_' . $personBarberId]);
                    } elseif ($hasRatingPersonNo) {
                      $personAlreadyRated = isset($ratedPersons[$appointmentId . '_' . (int)$person['person_no']]);
                    } else {
                      $personAlreadyRated = in_array($appointmentId, $ratedAppointments, true);
                    }
                  ?>
                  <div class="companion-card">
                    <h4>Person <?= (int)$person['person_no'] ?></h4>

                    <?php if (!empty($person['full_name'])): ?>
                      <p><strong>Name:</strong> <?= htmlspecialchars($person['full_name']) ?></p>
                    <?php endif; ?>

                    <p><strong>Main Service:</strong> <?= htmlspecialchars($person['main_service_name'] ?: 'N/A') ?></p>
                    <p><strong>Add-on Services:</strong> <?= !empty($person['addon_names']) ? htmlspecialchars(implode(', ', $person['addon_names'])) : 'None' ?></p>
                    <p><strong>Barber:</strong> <?= htmlspecialchars($personBarber) ?></p>
                    <p><strong>Schedule:</strong> <?= htmlspecialchars($personSchedule) ?></p>
                    <p><strong>Subtotal:</strong> ₱<?= number_format((float)$person['total_price'], 2) ?></p>

                    <?php if ($personLeaveCredit > 0): ?>
                      <div class="status-block no-show-block" style="margin-top:12px;">
                        <p><strong>Notice:</strong> This person's barber has an approved leave on the appointment date, so this person may reschedule.</p>
                      </div>
                    <?php endif; ?>

                    <?php if ($status === 'approved' && $canEditPerson): ?>
                      <div class="companion-actions">
                        <button
                          type="button"
                          class="btn btn-primary open-edit-modal"
                          data-appointment-id="<?= $appointmentId ?>"
                          data-target-type="companion"
                          data-person-no="<?= (int)$person['person_no'] ?>"
                          data-current-barber-id="<?= $personBarberId ?>"
                          data-current-barber-name="<?= htmlspecialchars($personBarber, ENT_QUOTES) ?>"
                          data-services="<?= htmlspecialchars($personServiceText ?: 'No services found', ENT_QUOTES) ?>"
                          data-total-duration="<?= (int)$person['total_duration'] ?>"
                          data-current-schedule="<?= htmlspecialchars($personSchedule, ENT_QUOTES) ?>"
                          data-reschedule-count="<?= $personRescheduleCount ?>"
                          data-leave-credit="<?= $personLeaveCredit ?>"
                        >
                          <?= $personLeaveCredit > 0 ? 'Reschedule This Person' : 'Edit This Person' ?>
                        </button>
                      </div>
                    <?php elseif ($status === 'approved'): ?>
                      <div class="status-block cancelled-block" style="margin-top:12px;">
                        <p>
                          <strong>Reschedule unavailable:</strong>
                          <?php if (!$withinRescheduleWindowPerson && $personLeaveCredit <= 0): ?>
                            You cannot reschedule within 30 minutes before this person's appointment.
                          <?php else: ?>
                            This person already used the allowed reschedule.
                          <?php endif; ?>
                        </p>
                      </div>
                    <?php endif; ?>

                    <?php if ($status === 'completed'): ?>
                      <div class="companion-actions">
                        <?php if ($personAlreadyRated): ?>
                          <span class="already-rated-badge">⭐ Already Rated</span>
                        <?php else: ?>
                          <button
                            type="button"
                            class="btn btn-success open-rate-modal"
                            data-appointment-id="<?= $appointmentId ?>"
                            data-target-type="companion"
                            data-person-no="<?= (int)$person['person_no'] ?>"
                            data-barber-id="<?= $personBarberId ?>"
                            data-barber-name="<?= htmlspecialchars($personBarber, ENT_QUOTES) ?>"
                          >
                            ⭐ Rate This Barber
                          </button>
                        <?php endif; ?>
                      </div>
                    <?php endif; ?>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>

            <?php if ($status === 'approved'): ?>
              <div class="status-block approved-block">
                <p><strong>Amount Paid:</strong> ₱<?= number_format($amount_paid, 2) ?></p>
                <p><strong>Remaining Balance:</strong> ₱<?= number_format($balance_amount, 2) ?></p>
              </div>
            <?php elseif ($status === 'completed'): ?>
              <div class="status-block completed-block">
                <p><strong>Amount Paid:</strong> ₱<?= number_format($amount_paid, 2) ?></p>
              </div>
            <?php elseif ($status === 'no-show'): ?>
              <div class="status-block no-show-block">
                <p><strong>Amount Paid:</strong> ₱<?= number_format($amount_paid, 2) ?></p>
                <p><strong>Remaining Balance:</strong> ₱<?= number_format($balance_amount, 2) ?></p>
                <p><strong>Note:</strong> This appointment was marked as no-show.</p>
              </div>
            <?php elseif ($status === 'canceled' || $status === 'cancelled'): ?>
              <div class="status-block cancelled-block">
                <p><strong>Note:</strong> This appointment was canceled.</p>
              </div>
            <?php endif; ?>

            <div class="actions">
              <?php if ($status === 'rejected'): ?>
                <div class="rejection-box">
                  <p class="rejection-reason"><strong>Reason:</strong>
                    <?= !empty($row['rejection_reason']) ? htmlspecialchars($row['rejection_reason']) : 'No reason provided' ?>
                  </p>

                  <button
                    type="button"
                    class="btn btn-danger open-repay-modal"
                    data-appointment-id="<?= $appointmentId ?>"
                    data-rejection-reason="<?= htmlspecialchars($row['rejection_reason'] ?: 'No reason provided', ENT_QUOTES) ?>"
                    data-amount="<?= number_format((float)$row['total_downpayment'], 2, '.', '') ?>"
                  >
                    Pay Again
                  </button>
                </div>

              <?php elseif ($status === 'approved'): ?>
                <?php if (empty($companions) && $leaveCredit > 0): ?>
                  <div class="status-block no-show-block" style="margin-bottom:10px;">
                    <p><strong>Notice:</strong> Your barber has an approved leave on your appointment date, so you may reschedule.</p>
                  </div>
                <?php endif; ?>

                <?php if (empty($companions)): ?>
                  <?php if ($canEditAppointment): ?>
                    <button
                      type="button"
                      class="btn btn-primary open-edit-modal"
                      data-appointment-id="<?= $appointmentId ?>"
                      data-target-type="parent"
                      data-person-no="0"
                      data-current-barber-id="<?= (int)$row['barber_id'] ?>"
                      data-current-barber-name="<?= htmlspecialchars($row['barber_name'], ENT_QUOTES) ?>"
                      data-services="<?= htmlspecialchars($row['services'] ?: 'No services found', ENT_QUOTES) ?>"
                      data-total-duration="<?= (int)$row['total_duration'] ?>"
                      data-current-schedule="<?= htmlspecialchars($currentSchedule, ENT_QUOTES) ?>"
                      data-reschedule-count="<?= $rescheduleCount ?>"
                      data-leave-credit="<?= $leaveCredit ?>"
                    >
                      <?= $leaveCredit > 0 ? 'Reschedule' : 'Edit Appointment' ?>
                    </button>
                  <?php else: ?>
                    <div class="status-block cancelled-block" style="margin-bottom:10px;">
                      <p>
                        <strong>Reschedule unavailable:</strong>
                        <?php if (!$withinRescheduleWindowParent && $leaveCredit <= 0): ?>
                          You cannot reschedule within 30 minutes before your appointment.
                        <?php else: ?>
                          You already used your allowed appointment edit/reschedule.
                        <?php endif; ?>
                      </p>
                    </div>
                  <?php endif; ?>
                <?php else: ?>
                  <div class="status-block approved-block" style="margin-bottom:10px;">
                    <p><strong>Group Booking:</strong> Choose which person you want to edit using the button inside each person card above.</p>
                  </div>
                <?php endif; ?>

                <button
                  type="button"
                  class="btn btn-secondary open-cancel-modal"
                  data-appointment-id="<?= $appointmentId ?>"
                >
                  Cancel
                </button>

              <?php elseif ($status === 'completed'): ?>
                <?php if (empty($companions)): ?>
                  <?php if ($parentAlreadyRated): ?>
                    <span class="already-rated-badge">⭐ Already Rated</span>
                  <?php else: ?>
                    <button
                      type="button"
                      class="btn btn-success open-rate-modal"
                      data-appointment-id="<?= $appointmentId ?>"
                      data-target-type="parent"
                      data-person-no="0"
                      data-barber-id="<?= (int)$row['barber_id'] ?>"
                      data-barber-name="<?= htmlspecialchars($row['barber_name'], ENT_QUOTES) ?>"
                    >
                      ⭐ Rate Barber
                    </button>
                  <?php endif; ?>
                <?php endif; ?>

              <?php elseif ($status === 'no-show'): ?>
                <!-- no action -->

              <?php elseif ($status === 'canceled' || $status === 'cancelled'): ?>
                <!-- no action -->
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      <?php else: ?>
        <p>No appointments found.</p>
      <?php endif; ?>
    </div>
  </div>
</main>

<?php if ($success_message || $error_message): ?>
  <div class="custom-alert-backdrop show" id="messageModal">
    <div class="custom-alert-box">
      <h3><?= $success_message ? 'Success' : 'Notice' ?></h3>
      <p><?= htmlspecialchars($success_message ?: $error_message) ?></p>
      <button type="button" class="custom-alert-btn" onclick="document.getElementById('messageModal').style.display='none'">OK</button>
    </div>
  </div>
<?php endif; ?>

<div class="edit-modal-backdrop" id="editAppointmentModal">
  <div class="edit-modal">
    <div class="edit-modal-header">
      <h2>Edit Appointment</h2>
      <button type="button" class="edit-close-btn" id="closeEditModal">&times;</button>
    </div>

    <p class="edit-modal-subtext">
      Select a new barber, date, and start time only. The end time will be calculated automatically based on the total duration.
    </p>

    <form action="edit_appointment_process.php" method="POST" id="editAppointmentForm">
      <input type="hidden" name="appointment_id" id="edit_appointment_id">
      <input type="hidden" name="target_type" id="edit_target_type" value="parent">
      <input type="hidden" name="person_no" id="edit_person_no" value="0">
      <input type="hidden" name="selected_date" id="edit_selected_date">
      <input type="hidden" name="selected_start_time" id="edit_selected_start_time">

      <div class="edit-grid">
        <div class="edit-panel">
          <h4>Current Appointment</h4>
          <p><strong>Editing:</strong> <span id="modal_editing_target">Appointment</span></p>
          <p><strong>Services:</strong> <span id="modal_services"></span></p>
          <p><strong>Current Barber:</strong> <span id="modal_current_barber"></span></p>
          <p><strong>Current Schedule:</strong> <span id="modal_current_schedule"></span></p>
          <p><strong>Total Duration:</strong> <span id="modal_total_duration"></span> minutes</p>
        </div>

        <div class="edit-panel">
          <div class="edit-form-group">
            <label for="edit_barber_id">Choose Barber</label>
            <select name="barber_id" id="edit_barber_id" required>
              <option value="">Select barber</option>
              <?php foreach ($barbers as $barber): ?>
                <option value="<?= (int)$barber['id'] ?>"><?= htmlspecialchars($barber['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="calendar-slots-wrap">
            <div>
              <div class="slots-title">Choose Date</div>
              <input type="text" id="edit-calendar-input" style="position:absolute; opacity:0; pointer-events:none; height:0;">
              <div id="edit-calendar-message" class="slot-empty">Please select a barber first.</div>
              <div id="edit-calendar"></div>
            </div>

            <div id="edit-time-slots">
              <div class="slots-title">Available Start Times</div>
              <div class="slot-empty">Please select a barber first.</div>
            </div>
          </div>

          <div class="selected-summary" id="selectedSummary" style="display:none;">
            <strong>Selected Update:</strong>
            <div id="selectedSummaryText"></div>
          </div>

          <div class="edit-actions">
            <button type="button" class="btn btn-secondary" id="cancelEditBtn">Close</button>
            <button type="submit" class="btn btn-primary" id="saveEditBtn" disabled>Save Changes</button>
          </div>
        </div>
      </div>
    </form>
  </div>
</div>

<div class="rate-modal-backdrop" id="rateBarberModal">
  <div class="rate-modal">
    <div class="rate-modal-header">
      <h2 id="rateModalTitle">Rate Barber</h2>
      <button type="button" class="rate-close-btn" id="closeRateModal">&times;</button>
    </div>

    <form action="rate_barber_process.php" method="POST" id="rateBarberForm">
      <input type="hidden" name="appointment_id" id="rate_appointment_id">
      <input type="hidden" name="rating" id="rate_rating" required>
      <input type="hidden" name="target_type" id="rate_target_type" value="parent">
      <input type="hidden" name="person_no" id="rate_person_no" value="0">
      <input type="hidden" name="barber_id" id="rate_barber_id" value="0">

      <div class="rate-stars">
        <span class="rate-star" data-value="1">★</span>
        <span class="rate-star" data-value="2">★</span>
        <span class="rate-star" data-value="3">★</span>
        <span class="rate-star" data-value="4">★</span>
        <span class="rate-star" data-value="5">★</span>
      </div>

      <p class="rate-helper-text">Select a star rating.</p>

      <label for="rate_comment" class="rate-label">Comment (optional)</label>
      <textarea name="comment" id="rate_comment" class="rate-textarea" placeholder="Write something about your experience..."></textarea>

      <div class="rate-actions">
        <button type="button" class="btn btn-secondary" id="cancelRateBtn">Close</button>
        <button type="submit" class="btn btn-success">Submit Rating</button>
      </div>
    </form>
  </div>
</div>

<div class="cancel-modal-backdrop" id="cancelAppointmentModal">
  <div class="cancel-modal-box">
    <h3>Cancel Appointment</h3>
    <p>Are you sure you want to cancel this appointment?</p>
    <div class="cancel-modal-actions">
      <button type="button" class="btn btn-secondary" id="closeCancelModal">No</button>
      <a href="#" class="btn btn-danger" id="confirmCancelBtn">Yes, Cancel</a>
    </div>
  </div>
</div>

<div class="repay-modal-backdrop" id="repayModal">
  <div class="repay-modal-box">
    <div class="rate-modal-header" style="margin-bottom:12px;">
      <h3>Pay Again</h3>
      <button type="button" class="repay-close-btn" id="closeRepayModal">&times;</button>
    </div>

    <p id="repayReasonText"></p>
    <p><strong>Required Downpayment:</strong> ₱<span id="repayAmountText">0.00</span></p>

    <form action="repay.php" method="POST" enctype="multipart/form-data" id="repayForm">
      <input type="hidden" name="appointment_id" id="repay_appointment_id">

      <div class="repay-form-group">
        <label for="repay_new_ref">New Reference Number</label>
        <input
          type="text"
          name="new_ref"
          id="repay_new_ref"
          placeholder="Enter new Reference No."
          required
          minlength="5"
          maxlength="20"
          pattern="[A-Za-z0-9]{5,20}"
        >
        <div class="repay-help">Use letters and numbers only. No spaces or special characters.</div>
      </div>

      <div class="repay-form-group">
        <label for="repay_proof_image">Upload Proof of Payment</label>
        <input type="file" id="repay_proof_image" name="proof_image" accept="image/*" required>
      </div>

      <div class="repay-preview-wrap" id="repayPreviewWrap">
        <p><strong>Preview:</strong></p>
        <img id="repayPreviewImage" alt="Proof Preview">
      </div>

      <div class="repay-actions">
        <button type="button" class="btn btn-secondary" id="cancelRepayBtn">Close</button>
        <button type="submit" class="btn btn-danger">Submit Payment Again</button>
      </div>
    </form>
  </div>
</div>

<?php include 'footer.php'; ?>

<script>
const barberSchedulesData = <?= json_encode($barberSchedulesData) ?>;
const barberLeavesData = <?= json_encode($barberLeavesData) ?>;
const barberBookedData = <?= json_encode($barberBookedData) ?>;

const modal = document.getElementById('editAppointmentModal');
const openButtons = document.querySelectorAll('.open-edit-modal');
const closeEditModal = document.getElementById('closeEditModal');
const cancelEditBtn = document.getElementById('cancelEditBtn');

const editAppointmentId = document.getElementById('edit_appointment_id');
const editTargetType = document.getElementById('edit_target_type');
const editPersonNo = document.getElementById('edit_person_no');
const editBarberId = document.getElementById('edit_barber_id');
const editSelectedDate = document.getElementById('edit_selected_date');
const editSelectedStartTime = document.getElementById('edit_selected_start_time');
const saveEditBtn = document.getElementById('saveEditBtn');

const modalEditingTarget = document.getElementById('modal_editing_target');
const modalServices = document.getElementById('modal_services');
const modalCurrentBarber = document.getElementById('modal_current_barber');
const modalCurrentSchedule = document.getElementById('modal_current_schedule');
const modalTotalDuration = document.getElementById('modal_total_duration');

const timeSlotsContainer = document.getElementById('edit-time-slots');
const selectedSummary = document.getElementById('selectedSummary');
const selectedSummaryText = document.getElementById('selectedSummaryText');
const editCalendar = document.getElementById('edit-calendar');
const editCalendarMessage = document.getElementById('edit-calendar-message');

const rateModal = document.getElementById('rateBarberModal');
const openRateButtons = document.querySelectorAll('.open-rate-modal');
const closeRateModal = document.getElementById('closeRateModal');
const cancelRateBtn = document.getElementById('cancelRateBtn');
const rateAppointmentId = document.getElementById('rate_appointment_id');
const rateRatingInput = document.getElementById('rate_rating');
const rateTargetType = document.getElementById('rate_target_type');
const ratePersonNo = document.getElementById('rate_person_no');
const rateBarberId = document.getElementById('rate_barber_id');
const rateModalTitle = document.getElementById('rateModalTitle');
const rateStars = document.querySelectorAll('.rate-star');

const cancelModal = document.getElementById('cancelAppointmentModal');
const openCancelButtons = document.querySelectorAll('.open-cancel-modal');
const closeCancelModal = document.getElementById('closeCancelModal');
const confirmCancelBtn = document.getElementById('confirmCancelBtn');

const repayModal = document.getElementById('repayModal');
const openRepayButtons = document.querySelectorAll('.open-repay-modal');
const closeRepayModal = document.getElementById('closeRepayModal');
const cancelRepayBtn = document.getElementById('cancelRepayBtn');
const repayAppointmentId = document.getElementById('repay_appointment_id');
const repayReasonText = document.getElementById('repayReasonText');
const repayAmountText = document.getElementById('repayAmountText');
const repayNewRef = document.getElementById('repay_new_ref');
const repayProofImage = document.getElementById('repay_proof_image');
const repayPreviewWrap = document.getElementById('repayPreviewWrap');
const repayPreviewImage = document.getElementById('repayPreviewImage');

let selectedRateValue = 0;
let calendarInstance = null;
let currentAppointment = null;

function convertTo12Hour(time24) {
  const [hourStr, minuteStr] = time24.split(':');
  let hour = parseInt(hourStr, 10);
  const minute = minuteStr || '00';
  const suffix = hour >= 12 ? 'PM' : 'AM';
  hour = hour % 12;
  if (hour === 0) hour = 12;
  return `${hour}:${minute} ${suffix}`;
}

function toDateTimeString(dateStr, timeStr) {
  return `${dateStr} ${timeStr}:00`;
}

function overlaps(startA, endA, startB, endB) {
  return startA < endB && endA > startB;
}

function renderNoSlots(message) {
  timeSlotsContainer.innerHTML = `
    <div class="slots-title">Available Start Times</div>
    <div class="slot-empty">${message}</div>
  `;
}

function generateSlots() {
  const barberId = editBarberId.value;
  const selectedDate = editSelectedDate.value;

  saveEditBtn.disabled = true;
  selectedSummary.style.display = 'none';
  editSelectedStartTime.value = '';

  if (!barberId || !selectedDate || !currentAppointment) {
    renderNoSlots('Please select a barber and date first.');
    return;
  }

  const weeklySchedule = barberSchedulesData[barberId] || {};
  const leaveDates = barberLeavesData[barberId] || [];
  const bookedSlots = barberBookedData[barberId] || [];

  const jsDate = new Date(selectedDate + 'T00:00:00');
  const dayNames = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
  const dayName = dayNames[jsDate.getDay()];

  if (leaveDates.includes(selectedDate)) {
    renderNoSlots('This barber is on leave for the selected date.');
    return;
  }

  if (!weeklySchedule[dayName]) {
    renderNoSlots('This barber is not available on the selected date.');
    return;
  }

  const daySchedule = weeklySchedule[dayName];
  if (!daySchedule.start || !daySchedule.end) {
    renderNoSlots('This barber has no working hours set for the selected date.');
    return;
  }

  const startWork = String(daySchedule.start).slice(0, 5);
  const endWork = String(daySchedule.end).slice(0, 5);
  const totalDuration = parseInt(currentAppointment.total_duration || 0, 10);

  if (!totalDuration || totalDuration <= 0) {
    renderNoSlots('Invalid appointment duration.');
    return;
  }

  let currentMinutes = (() => {
    const [h, m] = startWork.split(':').map(Number);
    return (h * 60) + m;
  })();

  const endMinutes = (() => {
    const [h, m] = endWork.split(':').map(Number);
    return (h * 60) + m;
  })();

  const now = new Date();
  const todayStr = now.getFullYear() + '-' +
    String(now.getMonth() + 1).padStart(2, '0') + '-' +
    String(now.getDate()).padStart(2, '0');

  const isToday = selectedDate === todayStr;
  let nowMinutes = (now.getHours() * 60) + now.getMinutes();

  if (isToday) {
    nowMinutes = Math.ceil(nowMinutes / 30) * 30;
    if (currentMinutes < nowMinutes) {
      currentMinutes = nowMinutes;
    }
  }

  const slots = [];

  while (currentMinutes + totalDuration <= endMinutes) {
    const hour = Math.floor(currentMinutes / 60);
    const minute = currentMinutes % 60;
    const startTime = `${String(hour).padStart(2, '0')}:${String(minute).padStart(2, '0')}`;

    const endSlotMinutes = currentMinutes + totalDuration;
    const endHour = Math.floor(endSlotMinutes / 60);
    const endMinute = endSlotMinutes % 60;
    const endTime = `${String(endHour).padStart(2, '0')}:${String(endMinute).padStart(2, '0')}`;

    const newStart = new Date(toDateTimeString(selectedDate, startTime));
    const newEnd = new Date(toDateTimeString(selectedDate, endTime));

    let isBlocked = false;

    bookedSlots.forEach(slot => {
      const slotAppointmentId = parseInt(slot.appointment_id || 0, 10);
      const slotPersonNo = parseInt(slot.person_no || 0, 10);
      const slotSource = String(slot.source || '');

      if (currentAppointment.target_type === 'parent') {
        if (slotSource === 'appointment' && slotAppointmentId === parseInt(currentAppointment.appointment_id, 10)) {
          return;
        }
      }

      if (currentAppointment.target_type === 'companion') {
        if (
          slotSource === 'companion' &&
          slotAppointmentId === parseInt(currentAppointment.appointment_id, 10) &&
          slotPersonNo === parseInt(currentAppointment.person_no || 0, 10)
        ) {
          return;
        }
      }

      const bookedStart = new Date(String(slot.start).replace(' ', 'T'));
      const bookedEnd = new Date(String(slot.end).replace(' ', 'T'));

      if (overlaps(newStart, newEnd, bookedStart, bookedEnd)) {
        isBlocked = true;
      }
    });

    if (!isBlocked) {
      slots.push({
        value: startTime,
        label: `${convertTo12Hour(startTime)}`
      });
    }

    currentMinutes += 30;
  }

  if (!slots.length) {
    renderNoSlots('No available start times for this date.');
    return;
  }

  let html = `<div class="slots-title">Available Start Times</div><div class="slots-grid">`;
  slots.forEach(slot => {
    html += `<button type="button" class="slot-btn" data-time="${slot.value}" data-label="${slot.label}">${slot.label}</button>`;
  });
  html += `</div>`;

  timeSlotsContainer.innerHTML = html;

  document.querySelectorAll('.slot-btn').forEach(btn => {
    btn.addEventListener('click', function() {
      document.querySelectorAll('.slot-btn').forEach(b => b.classList.remove('selected'));
      this.classList.add('selected');

      editSelectedStartTime.value = this.dataset.time;
      selectedSummaryText.textContent = `${selectedDate} at ${this.dataset.label}`;
      selectedSummary.style.display = 'block';
      saveEditBtn.disabled = false;
    });
  });
}

function initEditCalendar() {
  const barberId = editBarberId.value;

  if (!barberId) {
    editCalendar.innerHTML = '';
    editCalendar.style.display = 'none';
    editCalendarMessage.style.display = 'block';
    editCalendarMessage.textContent = 'Please select a barber first.';
    return;
  }

  if (calendarInstance) {
    calendarInstance.destroy();
    calendarInstance = null;
  }

  editCalendar.innerHTML = '';
  editCalendar.style.display = 'block';
  editCalendarMessage.style.display = 'none';

  setTimeout(() => {
    calendarInstance = flatpickr("#edit-calendar-input", {
      inline: true,
      appendTo: editCalendar,
      minDate: "today",
      maxDate: new Date().fp_incr(30),
      disable: [
        function(date) {
          const y = date.getFullYear();
          const m = String(date.getMonth() + 1).padStart(2, '0');
          const d = String(date.getDate()).padStart(2, '0');
          const dateStr = `${y}-${m}-${d}`;

          const approvedLeaveDates = barberLeavesData[barberId] || [];
          return approvedLeaveDates.includes(dateStr);
        }
      ],
      onChange: function(selectedDates, dateStr) {
        editSelectedDate.value = dateStr;
        editSelectedStartTime.value = '';
        generateSlots();
      }
    });
  }, 50);
}

function openEditModal(button) {
  const targetType = button.dataset.targetType || 'parent';
  const personNo = button.dataset.personNo || '0';

  currentAppointment = {
    appointment_id: button.dataset.appointmentId,
    target_type: targetType,
    person_no: personNo,
    current_barber_id: button.dataset.currentBarberId,
    current_barber_name: button.dataset.currentBarberName,
    services: button.dataset.services,
    total_duration: button.dataset.totalDuration,
    current_schedule: button.dataset.currentSchedule,
    reschedule_count: button.dataset.rescheduleCount || 0,
    leave_credit: button.dataset.leaveCredit || 0
  };

  editAppointmentId.value = currentAppointment.appointment_id;
  editTargetType.value = currentAppointment.target_type;
  editPersonNo.value = currentAppointment.person_no;

  modalEditingTarget.textContent = currentAppointment.target_type === 'companion'
    ? `Person ${currentAppointment.person_no}`
    : 'Appointment';

  modalServices.textContent = currentAppointment.services;
  modalCurrentBarber.textContent = currentAppointment.current_barber_name;
  modalCurrentSchedule.textContent = currentAppointment.current_schedule;
  modalTotalDuration.textContent = currentAppointment.total_duration;

  editBarberId.value = "";
  editSelectedDate.value = '';
  editSelectedStartTime.value = '';
  saveEditBtn.disabled = true;
  selectedSummary.style.display = 'none';

  if (calendarInstance) {
    calendarInstance.destroy();
    calendarInstance = null;
  }

  editCalendar.innerHTML = '';
  editCalendar.style.display = 'none';
  editCalendarMessage.style.display = 'block';
  editCalendarMessage.textContent = 'Please select a barber first.';

  renderNoSlots('Please select a barber first.');
  modal.classList.add('show');
}

function paintRateStars(value) {
  rateStars.forEach(star => {
    const starValue = parseInt(star.dataset.value);
    star.classList.toggle('active', starValue <= value);
  });
}

/* EDIT MODAL EVENTS */
openButtons.forEach(button => {
  button.addEventListener('click', function() {
    openEditModal(this);
  });
});

editBarberId.addEventListener('change', function() {
  editSelectedDate.value = '';
  editSelectedStartTime.value = '';
  saveEditBtn.disabled = true;
  selectedSummary.style.display = 'none';

  if (!this.value) {
    if (calendarInstance) {
      calendarInstance.destroy();
      calendarInstance = null;
    }

    editCalendar.innerHTML = '';
    editCalendar.style.display = 'none';
    editCalendarMessage.style.display = 'block';
    editCalendarMessage.textContent = 'Please select a barber first.';

    renderNoSlots('Please select a barber first.');
    return;
  }

  initEditCalendar();
  renderNoSlots('Please choose a date for the selected barber.');
});

closeEditModal.addEventListener('click', function() {
  modal.classList.remove('show');
});

cancelEditBtn.addEventListener('click', function() {
  modal.classList.remove('show');
});

modal.addEventListener('click', function(e) {
  if (e.target === modal) {
    modal.classList.remove('show');
  }
});

/* RATE MODAL EVENTS */
openRateButtons.forEach(button => {
  button.addEventListener('click', function() {
    rateAppointmentId.value = this.dataset.appointmentId;
    rateTargetType.value = this.dataset.targetType || 'parent';
    ratePersonNo.value = this.dataset.personNo || '0';
    rateBarberId.value = this.dataset.barberId || '0';
    rateModalTitle.textContent = `Rate ${this.dataset.barberName}`;
    rateRatingInput.value = '';
    selectedRateValue = 0;
    document.getElementById('rate_comment').value = '';
    paintRateStars(0);
    rateModal.classList.add('show');
  });
});

rateStars.forEach(star => {
  star.addEventListener('mouseover', function() {
    paintRateStars(parseInt(this.dataset.value));
  });

  star.addEventListener('mouseout', function() {
    paintRateStars(selectedRateValue);
  });

  star.addEventListener('click', function() {
    selectedRateValue = parseInt(this.dataset.value);
    rateRatingInput.value = selectedRateValue;
    paintRateStars(selectedRateValue);
  });
});

closeRateModal.addEventListener('click', function() {
  rateModal.classList.remove('show');
});

cancelRateBtn.addEventListener('click', function() {
  rateModal.classList.remove('show');
});

rateModal.addEventListener('click', function(e) {
  if (e.target === rateModal) {
    rateModal.classList.remove('show');
  }
});

document.getElementById('rateBarberForm').addEventListener('submit', function(e) {
  if (!rateRatingInput.value) {
    e.preventDefault();
    alert('Please select a star rating first.');
  }
});

/* CANCEL MODAL EVENTS */
openCancelButtons.forEach(button => {
  button.addEventListener('click', function() {
    confirmCancelBtn.href = 'cancel.php?appointment_id=' + this.dataset.appointmentId;
    cancelModal.classList.add('show');
  });
});

closeCancelModal.addEventListener('click', function() {
  cancelModal.classList.remove('show');
});

cancelModal.addEventListener('click', function(e) {
  if (e.target === cancelModal) {
    cancelModal.classList.remove('show');
  }
});

/* REPAY MODAL EVENTS */
openRepayButtons.forEach(button => {
  button.addEventListener('click', function() {
    repayAppointmentId.value = this.dataset.appointmentId;
    repayReasonText.innerHTML = '<strong>Reason:</strong> ' + this.dataset.rejectionReason;
    repayAmountText.textContent = this.dataset.amount;
    repayNewRef.value = '';
    repayProofImage.value = '';
    repayPreviewImage.src = '';
    repayPreviewWrap.style.display = 'none';
    repayModal.classList.add('show');
  });
});

closeRepayModal.addEventListener('click', function() {
  repayModal.classList.remove('show');
});

cancelRepayBtn.addEventListener('click', function() {
  repayModal.classList.remove('show');
});

repayModal.addEventListener('click', function(e) {
  if (e.target === repayModal) {
    repayModal.classList.remove('show');
  }
});

repayProofImage.addEventListener('change', function() {
  const file = this.files[0];
  if (!file) {
    repayPreviewWrap.style.display = 'none';
    repayPreviewImage.src = '';
    return;
  }

  const reader = new FileReader();
  reader.onload = function(e) {
    repayPreviewImage.src = e.target.result;
    repayPreviewWrap.style.display = 'block';
  };
  reader.readAsDataURL(file);
});
</script>