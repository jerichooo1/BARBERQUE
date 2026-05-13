<?php
session_start();
include 'db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

include 'navbar.php';

$service_id = intval($_GET['service_id'] ?? 0);

/* ---------------------------------------------------
   FETCH LOGGED IN USER
--------------------------------------------------- */
$user = null;
$stmt = $conn->prepare("SELECT id, first_name, last_name, name FROM users WHERE id = ?");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

$accountOwnerName = trim(
    ($user['first_name'] ?? '') .
    (($user['last_name'] ?? '') ? ' ' . $user['last_name'] : '')
);
if ($accountOwnerName === '') {
    $accountOwnerName = trim($user['name'] ?? 'Account Owner');
}

/* ---------------------------------------------------
   FETCH SELECTED BASE SERVICE
   If no service_id from banner button, use the first available main service
--------------------------------------------------- */
$service = null;

if ($service_id > 0) {
    $stmt = $conn->prepare("SELECT id, name, price, duration_minutes FROM services WHERE id = ?");
    $stmt->bind_param("i", $service_id);
    $stmt->execute();
    $service = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

if (!$service) {
    $fallbackServiceQuery = $conn->query("
        SELECT s.id, s.name, s.price, s.duration_minutes
        FROM services s
        WHERE s.id NOT IN (
            SELECT service_id FROM optional_services
        )
        ORDER BY s.name ASC
        LIMIT 1
    ");

    if ($fallbackServiceQuery && $fallbackServiceQuery->num_rows > 0) {
        $service = $fallbackServiceQuery->fetch_assoc();
        $service_id = (int)$service['id'];
    } else {
        $fallbackServiceQuery = $conn->query("
            SELECT id, name, price, duration_minutes
            FROM services
            ORDER BY name ASC
            LIMIT 1
        ");

        if ($fallbackServiceQuery && $fallbackServiceQuery->num_rows > 0) {
            $service = $fallbackServiceQuery->fetch_assoc();
            $service_id = (int)$service['id'];
        }
    }
}

if (!$service) {
    die("No services available. Please add services first.");
}

/* ---------------------------------------------------
   FETCH OPTIONAL SERVICES FIRST
--------------------------------------------------- */
$optional_services = [];
$optional_ids = [];

$optionalQuery = $conn->query("
    SELECT s.id, s.name, s.price, s.duration_minutes
    FROM optional_services os
    JOIN services s ON os.service_id = s.id
    ORDER BY s.name ASC
");

if ($optionalQuery) {
    while ($row = $optionalQuery->fetch_assoc()) {
        $optional_services[] = $row;
        $optional_ids[] = (int)$row['id'];
    }
}

/* ---------------------------------------------------
   FETCH MAIN SERVICES ONLY
   (exclude services that exist in optional_services)
--------------------------------------------------- */
$main_services = [];
$mainServicesQuery = $conn->query("
    SELECT s.id, s.name, s.price, s.duration_minutes
    FROM services s
    WHERE s.id NOT IN (
        SELECT service_id FROM optional_services
    )
    ORDER BY s.name ASC
");

if ($mainServicesQuery) {
    while ($row = $mainServicesQuery->fetch_assoc()) {
        $main_services[] = $row;
    }
}

/* Safety: if selected service was not returned for some reason,
   add it so the current service still appears */
$selectedExistsInMain = false;
foreach ($main_services as $ms) {
    if ((int)$ms['id'] === (int)$service['id']) {
        $selectedExistsInMain = true;
        break;
    }
}
if (!$selectedExistsInMain) {
    $main_services[] = $service;
}

/* ---------------------------------------------------
   FETCH ACTIVE BARBERS + RATINGS
--------------------------------------------------- */
$barbers = [];
$result = $conn->query("
    SELECT b.*,
           COALESCE(ROUND(AVG(r.rating), 1), 0) AS avg_rating,
           COUNT(r.id) AS total_ratings
    FROM barbers b
    LEFT JOIN barber_ratings r ON b.id = r.barber_id
    WHERE b.status = 'active'
    GROUP BY b.id
    ORDER BY b.name ASC
");

while ($row = $result->fetch_assoc()) {
    $barbers[] = $row;
}

/* ---------------------------------------------------
   BOOKING WINDOW
--------------------------------------------------- */
$today = new DateTime();
$end_date = (clone $today)->modify('+1 month');

$start_str = $today->format("Y-m-d");
$end_str   = $end_date->format("Y-m-d");

/* ---------------------------------------------------
   FETCH ALL DATA NEEDED PER BARBER
--------------------------------------------------- */
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
    $stmt = $conn->prepare("
        SELECT day_of_week, start_time, end_time
        FROM barber_schedules
        WHERE barber_id = ?
    ");
    $stmt->bind_param("i", $bid);
    $stmt->execute();
    $scheduleRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($scheduleRows as $sched) {
        $weekly_schedule[$sched['day_of_week']] = [
            'start' => $sched['start_time'],
            'end'   => $sched['end_time']
        ];
    }

    $barberSchedulesData[$bid] = $weekly_schedule;

    $approved_leave_dates = [];
    if ($leaveTableExists) {
        $stmt = $conn->prepare("
            SELECT leave_date
            FROM barber_leaves
            WHERE barber_id = ?
              AND status = 'approved'
              AND leave_date BETWEEN ? AND ?
        ");
        $stmt->bind_param("iss", $bid, $start_str, $end_str);
        $stmt->execute();
        $leaveRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        foreach ($leaveRows as $leave) {
            $approved_leave_dates[] = $leave['leave_date'];
        }
    }
    $barberLeavesData[$bid] = $approved_leave_dates;

    $booked_slots = [];

    /* MAIN APPOINTMENTS */
$stmt = $conn->prepare("
    SELECT start_time, end_time
    FROM appointments
    WHERE barber_id = ?
      AND DATE(start_time) BETWEEN ? AND ?
      AND status NOT IN ('canceled', 'cancelled', 'completed', 'no-show', 'rejected')
");
$stmt->bind_param("iss", $bid, $start_str, $end_str);
$stmt->execute();
$appointments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

foreach ($appointments as $a) {
    $booked_slots[] = [
        "start" => $a['start_time'],
        "end"   => $a['end_time']
    ];
}

/* COMPANION / GROUP BOOKING APPOINTMENTS */
$companionTableExists = false;
$checkCompanionTable = $conn->query("SHOW TABLES LIKE 'appointment_companions'");
if ($checkCompanionTable && $checkCompanionTable->num_rows > 0) {
    $companionTableExists = true;
}

if ($companionTableExists) {
    $stmt = $conn->prepare("
        SELECT ac.start_time, ac.end_time
        FROM appointment_companions ac
        JOIN appointments a ON ac.appointment_id = a.id
        WHERE ac.barber_id = ?
          AND DATE(ac.start_time) BETWEEN ? AND ?
          AND a.status NOT IN ('canceled', 'cancelled', 'completed', 'no-show', 'rejected')
          AND ac.start_time IS NOT NULL
          AND ac.end_time IS NOT NULL
    ");
    $stmt->bind_param("iss", $bid, $start_str, $end_str);
    $stmt->execute();
    $companionAppointments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($companionAppointments as $ca) {
        $booked_slots[] = [
            "start" => $ca['start_time'],
            "end"   => $ca['end_time']
        ];
    }
}
    $stmt = $conn->prepare("
        SELECT started_at, estimated_end_at
        FROM walkins
        WHERE barber_id = ?
          AND status = 'ongoing'
          AND started_at IS NOT NULL
          AND estimated_end_at IS NOT NULL
          AND DATE(started_at) BETWEEN ? AND ?
    ");
    $stmt->bind_param("iss", $bid, $start_str, $end_str);
    $stmt->execute();
    $walkins = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($walkins as $w) {
        $booked_slots[] = [
            "start" => $w['started_at'],
            "end"   => $w['estimated_end_at']
        ];
    }

    $barberBookedData[$bid] = $booked_slots;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Select Booking | Fade Street Hair Studio</title>
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <style>
/* =========================================================
   MODERN SELECT BOOKING PAGE
   Scoped only to .booking-page to avoid affecting other pages
========================================================= */

.booking-page {
  min-height: 100vh;
  padding: 150px 24px 70px;
  background:
    radial-gradient(circle at top left, rgba(11, 61, 145, 0.13), transparent 34%),
    linear-gradient(180deg, #ffffff 0%, #f7f9fc 48%, #ffffff 100%);
  color: #07172f;
}

.booking-page-wrapper {
  width: min(1380px, 100%);
  margin: 0 auto;
  display: grid;
  grid-template-columns: minmax(0, 1fr) 360px;
  gap: 28px;
  align-items: start;
}

.booking-page-left {
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 24px;
}

.booking-page-right {
  min-width: 0;
  align-self: start;
  position: sticky;
  top: 125px;
}

/* ================= MODERN CARDS ================= */

.booking-page .persons-container,
.booking-page .person-editor-container,
.booking-page .schedule-container,
.booking-page .booking-policy,
.booking-page .booking-summary-box {
  background: rgba(255, 255, 255, 0.96);
  border: 1px solid #dbe3ef;
  border-radius: 28px;
  padding: 26px;
  box-shadow: 0 16px 40px rgba(7, 23, 47, 0.08);
  box-sizing: border-box;
}

.booking-page .persons-container,
.booking-page .person-editor-container,
.booking-page .schedule-container {
  margin-bottom: 0;
}

.booking-page .persons-container h2,
.booking-page .person-editor-container h2,
.booking-page .schedule-container h2,
.booking-page .booking-policy h2,
.booking-page .booking-summary-box h4 {
  margin: 0 0 10px;
  color: #07172f;
  font-size: clamp(1.45rem, 3vw, 2rem);
  line-height: 1.1;
  letter-spacing: -0.8px;
  text-align: left;
  font-weight: 900;
}

.booking-page .persons-container p,
.booking-page .schedule-container > p {
  margin: 0;
  color: #64748b;
  line-height: 1.65;
  font-weight: 700;
  text-align: left;
}

.booking-page .schedule-container > p {
  margin-top: 8px;
}

/* ================= PERSON COUNT ================= */

.person-count-wrap {
  display: flex;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
  margin-top: 20px;
}

.person-count-btn {
  width: 44px;
  height: 44px;
  border: none;
  border-radius: 16px;
  background: linear-gradient(135deg, #061f4a, #0b3d91);
  color: #ffffff;
  font-size: 24px;
  font-weight: 900;
  cursor: pointer;
  box-shadow: 0 10px 22px rgba(11, 61, 145, 0.20);
  transition: 0.25s ease;
}

.person-count-btn:hover {
  transform: translateY(-2px);
  background: linear-gradient(135deg, #07172f, #092f70);
}

.person-count-display {
  min-width: 130px;
  min-height: 44px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 0 18px;
  border-radius: 16px;
  background: #eaf1ff;
  color: #061f4a;
  font-weight: 900;
  border: 1px solid #dbe8ff;
}

/* ================= PERSON TABS ================= */

.person-tabs {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  margin-top: 18px;
}

.person-tab {
  min-height: 44px;
  padding: 0 16px;
  border-radius: 999px;
  border: 1px solid #dbe3ef;
  background: #ffffff;
  color: #475569;
  cursor: pointer;
  font-weight: 900;
  transition: 0.25s ease;
}

.person-tab:hover {
  transform: translateY(-2px);
  border-color: #8fb3ff;
  color: #0b3d91;
}

.person-tab.active {
  background: linear-gradient(135deg, #061f4a, #0b3d91);
  color: #ffffff;
  border-color: #0b3d91;
  box-shadow: 0 10px 22px rgba(11, 61, 145, 0.20);
}

.person-tab.completed {
  border-color: #0b3d91;
}

/* ================= FORM FIELDS ================= */

.person-form-grid {
  display: grid;
  gap: 20px;
  margin-top: 18px;
}

.person-field label {
  display: block;
  margin-bottom: 8px;
  font-weight: 900;
  color: #07172f;
  font-size: 0.95rem;
}

.person-field select {
  width: 100%;
  min-height: 54px;
  padding: 0 16px;
  border: 1.5px solid #dbe3ef;
  border-radius: 16px;
  box-sizing: border-box;
  font-size: 1rem;
  color: #07172f;
  background: #f8fbff;
  outline: none;
  transition: 0.22s ease;
}

.person-field select:focus {
  border-color: #0b3d91;
  background: #ffffff;
  box-shadow: 0 0 0 4px rgba(11, 61, 145, 0.12);
}

/* ================= ADD-ONS ================= */

.person-addon-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px;
  margin-top: 10px;
}

.person-addon-item {
  display: flex;
  justify-content: space-between;
  gap: 14px;
  align-items: center;
  padding: 16px;
  border: 1.5px solid #dbe3ef;
  border-radius: 18px;
  background: #ffffff;
  cursor: pointer;
  transition: 0.25s ease;
}

.person-addon-item:hover {
  transform: translateY(-3px);
  border-color: #8fb3ff;
  box-shadow: 0 10px 22px rgba(7, 23, 47, 0.08);
}

.person-addon-item.selected {
  border-color: #0b3d91;
  background: #eaf1ff;
  box-shadow: 0 12px 24px rgba(11, 61, 145, 0.13);
}

.person-addon-item span:first-child {
  color: #07172f;
  font-weight: 900;
}

.person-addon-item span:last-child {
  color: #64748b;
  font-weight: 800;
  text-align: right;
  white-space: nowrap;
}

/* ================= CURRENT PERSON SUMMARY ================= */

.person-current-summary {
  margin-top: 20px;
  padding: 18px;
  border-radius: 20px;
  background: linear-gradient(135deg, #f8fbff, #ffffff);
  border: 1px solid #dbe8ff;
  color: #334155;
  box-shadow: inset 0 0 0 1px rgba(255,255,255,0.6);
}

.person-current-summary strong {
  color: #07172f;
}

.person-current-summary ul {
  margin: 12px 0 0;
  padding-left: 20px;
}

.person-current-summary li {
  margin-bottom: 8px;
  line-height: 1.55;
  color: #475569;
}

/* ================= SCHEDULE ================= */

.calendar-and-slots {
  display: grid;
  grid-template-columns: 380px minmax(0, 1fr);
  align-items: stretch;
  gap: 28px;
  margin-top: 24px;
}

.calendar-box {
  width: 100%;
  min-width: 0;
  display: flex;
  justify-content: center;
  align-items: flex-start;
  padding: 22px;
  border-radius: 24px;
  background: #f8fbff;
  border: 1px solid #dbe3ef;
  box-sizing: border-box;
  overflow: visible;
}

.slots-box {
  min-height: 360px;
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  align-content: flex-start;
  justify-content: flex-start;
  padding: 22px;
  border-radius: 24px;
  background: #f8fbff;
  border: 1px solid #dbe3ef;
  box-sizing: border-box;
}

/* ================= FLATPICKR CALENDAR FINAL FIX ================= */

#booking-calendar {
  width: 100%;
  display: flex;
  justify-content: center;
  overflow: visible !important;
}

#booking-calendar .flatpickr-calendar {
  width: 307px !important;
  max-width: 307px !important;
  min-width: 307px !important;
  box-shadow: 0 16px 40px rgba(7, 23, 47, 0.10) !important;
  border: 1px solid #dbe3ef !important;
  border-radius: 22px !important;
  overflow: hidden !important;
  font-family: inherit !important;
}

#booking-calendar .flatpickr-calendar.inline {
  margin: 0 auto !important;
  display: block !important;
}

#booking-calendar .flatpickr-innerContainer,
#booking-calendar .flatpickr-rContainer,
#booking-calendar .flatpickr-days,
#booking-calendar .dayContainer {
  width: 307px !important;
  min-width: 307px !important;
  max-width: 307px !important;
}

#booking-calendar .flatpickr-months {
  width: 307px !important;
  height: 48px !important;
}

#booking-calendar .flatpickr-month {
  height: 48px !important;
}

#booking-calendar .flatpickr-current-month {
  padding-top: 8px !important;
  font-size: 1rem !important;
}

#booking-calendar .flatpickr-weekdays {
  width: 307px !important;
  height: 36px !important;
}

#booking-calendar .flatpickr-weekdaycontainer {
  width: 307px !important;
}

#booking-calendar span.flatpickr-weekday {
  width: 43.85px !important;
  color: #64748b !important;
  font-weight: 900 !important;
}

#booking-calendar .flatpickr-day {
  width: 43.85px !important;
  max-width: 43.85px !important;
  height: 43px !important;
  line-height: 43px !important;
  margin: 0 !important;
  border-radius: 50% !important;
  color: #07172f !important;
  font-weight: 800 !important;
  box-sizing: border-box !important;
}

#booking-calendar .flatpickr-day.prevMonthDay,
#booking-calendar .flatpickr-day.nextMonthDay {
  color: #cbd5e1 !important;
}

#booking-calendar .flatpickr-day.selected,
#booking-calendar .flatpickr-day.startRange,
#booking-calendar .flatpickr-day.endRange {
  background: #0b3d91 !important;
  border-color: #0b3d91 !important;
  color: #ffffff !important;
}

#booking-calendar .flatpickr-day.today {
  border-color: #0b3d91 !important;
}


/* ================= GROUP BOOKING DATE NOTE ================= */

.group-booking-note {
  margin-top: 16px;
  padding: 16px 18px;
  border-radius: 18px;
  background: #eaf1ff;
  border: 1px solid #cfe0ff;
  color: #0b3d91;
  font-weight: 800;
  line-height: 1.6;
}

.group-date-pill {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  margin-top: 12px;
  padding: 10px 14px;
  border-radius: 999px;
  background: #ffffff;
  border: 1px solid #dbe8ff;
  color: #061f4a;
  font-weight: 900;
}

#booking-calendar .group-date-locked-day {
  cursor: not-allowed !important;
  opacity: 0.35 !important;
}

/* ================= TIME SLOTS ================= */

.time-slot-btn {
  min-width: 120px;
  min-height: 44px;
  padding: 0 16px;
  border-radius: 999px;
  border: 1.5px solid #dbe3ef;
  background: #ffffff;
  color: #07172f;
  cursor: pointer;
  text-align: center;
  font-weight: 900;
  transition: 0.22s ease;
}

.time-slot-btn:hover {
  transform: translateY(-2px);
  border-color: #0b3d91;
  color: #0b3d91;
  box-shadow: 0 10px 20px rgba(7, 23, 47, 0.08);
}

.time-slot-btn.selected {
  background: linear-gradient(135deg, #061f4a, #0b3d91);
  color: #ffffff;
  border-color: #0b3d91;
  box-shadow: 0 10px 22px rgba(11, 61, 145, 0.22);
}

.slots-box .no-slots,
.slots-box .unavailable-note,
.no-slots,
.unavailable-note {
  width: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  text-align: center;
  min-height: 86px;
  border-radius: 18px;
  padding: 18px;
  line-height: 1.5;
  font-weight: 900;
  font-size: 1rem;
}

.no-slots {
  background: #f1f5f9;
  color: #475569;
}

.unavailable-note {
  background: #fff7ed;
  color: #9a3412;
  border: 1px solid #fed7aa;
}

/* ================= BOOKING POLICY ================= */

.booking-policy h4 {
  margin: 18px 0 8px;
  font-size: 1rem;
  color: #0b3d91;
  font-weight: 900;
}

.booking-policy p {
  margin: 0 0 10px;
  color: #475569;
  font-size: 0.96rem;
  line-height: 1.7;
  font-weight: 650;
}

.policy-check-wrap {
  margin-top: 22px;
  display: flex;
  align-items: flex-start;
  gap: 12px;
  padding: 16px;
  border-radius: 18px;
  background: #f8fbff;
  border: 1px solid #dbe3ef;
}

.policy-check-wrap input[type="checkbox"] {
  margin-top: 4px;
  width: 18px;
  height: 18px;
  accent-color: #0b3d91;
  flex-shrink: 0;
}

.policy-check-wrap label {
  font-size: 0.95rem;
  color: #07172f;
  line-height: 1.5;
  font-weight: 800;
}

/* ================= CONTINUE BUTTON ================= */

.continue-wrapper {
  margin-top: 20px;
  width: 100%;
}

.continue-button {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-height: 54px;
  width: 100%;
  background: linear-gradient(135deg, #061f4a, #0b3d91);
  color: white;
  text-align: center;
  padding: 0 18px;
  text-decoration: none;
  font-weight: 900;
  border-radius: 999px;
  transition: 0.25s ease;
  font-size: 1rem;
  border: none;
  cursor: pointer;
  box-shadow: 0 14px 30px rgba(11, 61, 145, 0.25);
}

.continue-button:hover {
  transform: translateY(-3px);
  background: linear-gradient(135deg, #07172f, #092f70);
}

.continue-button:disabled {
  background: #94a3b8;
  cursor: not-allowed;
  opacity: 0.75;
  transform: none;
  box-shadow: none;
}

/* ================= SUMMARY SIDEBAR ================= */

.booking-summary-box {
  position: relative;
  overflow: hidden;
  background:
    radial-gradient(circle at top right, rgba(11, 61, 145, 0.12), transparent 30%),
    #ffffff;
}

.booking-summary-box::before {
  content: "";
  position: absolute;
  inset: 0 0 auto;
  height: 6px;
  background: linear-gradient(90deg, #061f4a, #0b3d91);
}

.booking-summary-box h4 {
  color: #07172f;
  text-align: left;
  margin-top: 10px;
}

.booking-summary-box p {
  margin: 12px 0;
  font-size: 0.96rem;
  color: #475569;
  line-height: 1.55;
  word-break: break-word;
  font-weight: 700;
}

.booking-summary-box strong {
  color: #07172f;
}

#summary-total,
#summary-downpayment {
  color: #0b3d91;
  font-weight: 900;
}

.summary-person-block {
  margin-top: 14px;
  padding: 16px;
  border: 1px solid #dbe3ef;
  border-radius: 18px;
  background: #f8fbff;
}

.summary-person-block h5 {
  margin: 0 0 12px;
  color: #0b3d91;
  font-size: 1.05rem;
  font-weight: 900;
}

.summary-person-block p {
  margin: 8px 0;
  font-size: 0.9rem;
}

/* ================= MODAL ================= */

.booking-error-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(7, 23, 47, 0.65);
  display: none;
  align-items: center;
  justify-content: center;
  z-index: 99999;
  padding: 20px;
  backdrop-filter: blur(8px);
}

.booking-error-backdrop.show {
  display: flex;
}

.booking-error-box {
  background: #ffffff;
  width: min(92%, 430px);
  border-radius: 26px;
  padding: 28px;
  text-align: center;
  border: 1px solid #dbe3ef;
  box-shadow: 0 28px 70px rgba(7, 23, 47, 0.28);
}

.booking-error-box h3 {
  margin: 0 0 12px;
  color: #07172f;
  font-size: 1.5rem;
}

.booking-error-box p {
  margin: 0 0 22px;
  color: #475569;
  line-height: 1.6;
  font-weight: 700;
}

.booking-error-box button {
  min-height: 46px;
  background: linear-gradient(135deg, #061f4a, #0b3d91);
  color: #ffffff;
  border: none;
  border-radius: 999px;
  padding: 0 24px;
  font-weight: 900;
  cursor: pointer;
  transition: 0.25s ease;
}

.booking-error-box button:hover {
  transform: translateY(-2px);
}

/* ================= RESPONSIVE ================= */

@media (max-width: 1150px) {
  .booking-page-wrapper {
    grid-template-columns: 1fr;
  }

  .booking-page-right {
    order: -1;
    position: static;
    top: auto;
  }

  .calendar-and-slots {
    grid-template-columns: 1fr;
  }

  .calendar-box {
    justify-content: center;
  }

  .slots-box {
    min-height: auto;
  }
}

@media (max-width: 900px) {
  .booking-page {
    padding: 125px 16px 50px;
  }

  .person-addon-grid {
    grid-template-columns: 1fr;
  }

  .booking-page .persons-container,
  .booking-page .person-editor-container,
  .booking-page .schedule-container,
  .booking-page .booking-policy,
  .booking-page .booking-summary-box {
    border-radius: 24px;
    padding: 22px;
  }

  .slots-box {
    justify-content: center;
  }
}

@media (max-width: 600px) {
  .booking-page {
    padding: 110px 12px 38px;
  }

  .booking-page-wrapper {
    gap: 18px;
  }

  .booking-page-left {
    gap: 18px;
  }

  .booking-page .persons-container,
  .booking-page .person-editor-container,
  .booking-page .schedule-container,
  .booking-page .booking-policy,
  .booking-page .booking-summary-box {
    border-radius: 20px;
    padding: 18px;
  }

  .person-count-wrap {
    justify-content: center;
  }

  .person-tabs {
    justify-content: center;
  }

  .person-addon-item {
    flex-direction: column;
    align-items: flex-start;
  }

  .person-addon-item span:last-child {
    white-space: normal;
    text-align: left;
  }

  .calendar-and-slots {
    gap: 18px;
  }

  .calendar-box,
  .slots-box {
    padding: 14px;
    border-radius: 20px;
  }

  #booking-calendar {
    width: 100% !important;
    overflow-x: auto !important;
    padding-bottom: 4px;
  }

  #booking-calendar .flatpickr-calendar {
    width: 307px !important;
    min-width: 307px !important;
    max-width: 307px !important;
  }

  .time-slot-btn {
    width: 100%;
  }

  .policy-check-wrap {
    padding: 14px;
  }
}
</style>
</head>
<body>

<main class="booking-page">
    <div class="booking-page-wrapper">
        <div class="booking-page-left">

            <div class="persons-container">
                <h2>Add Persons / Companions</h2>
                <p>This booking will stay under <strong><?= htmlspecialchars($accountOwnerName) ?></strong>.</p>

                <div class="person-count-wrap">
                    <button type="button" class="person-count-btn" id="minus-person">−</button>
                    <div class="person-count-display" id="person-count-display">1 Person</div>
                    <button type="button" class="person-count-btn" id="plus-person">+</button>
                </div>

                <div class="person-tabs" id="person-tabs"></div>
            </div>

            <div class="person-editor-container">
                <h2 id="person-editor-title">Person 1 Details</h2>

                <div class="person-form-grid">
                    <div class="person-field">
                        <label for="person-barber">Barber</label>
                        <select id="person-barber">
                            <option value="">Select barber</option>
                            <?php foreach ($barbers as $row): ?>
                                <option value="<?= (int)$row['id'] ?>"><?= htmlspecialchars($row['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="person-field">
                        <label for="person-main-service">Main Service</label>
                        <select id="person-main-service">
                            <?php foreach ($main_services as $srv): ?>
                                <option value="<?= (int)$srv['id'] ?>" <?= ((int)$srv['id'] === (int)$service['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($srv['name']) ?> - ₱<?= number_format($srv['price'], 2) ?> (<?= (int)$srv['duration_minutes'] ?> mins)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="person-field">
                        <label>Additional Services</label>
                        <div class="person-addon-grid" id="person-addon-grid">
                            <?php if (!empty($optional_services)): ?>
                                <?php foreach ($optional_services as $row): ?>
                                    <div class="person-addon-item"
                                         data-id="<?= (int)$row['id'] ?>"
                                         data-name="<?= htmlspecialchars($row['name']) ?>"
                                         data-price="<?= (float)$row['price'] ?>"
                                         data-duration="<?= (int)$row['duration_minutes'] ?>">
                                        <span><?= htmlspecialchars($row['name']) ?></span>
                                        <span>₱<?= number_format($row['price'], 2) ?> (<?= (int)$row['duration_minutes'] ?> mins)</span>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="no-slots">No additional services available.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="person-current-summary" id="person-current-summary"></div>
            </div>

            <div class="schedule-container">
                <h2 id="schedule-title">Select Schedule for Person 1</h2>
                <p>Booking period: <?= $today->format("M d, Y") ?> → <?= $end_date->format("M d, Y") ?></p>

                <div class="group-booking-note">
                    Companions must come on the same booking date. Once a date is selected, that same date will apply to Person 1 and all companions. Each person may still choose the same or different barber, service, and available time slot.
                    <div class="group-date-pill">Selected group date: <span id="shared-date-display">Not selected</span></div>
                </div>

                <div class="calendar-and-slots">
                    <div class="calendar-box">
                        <div id="booking-calendar"></div>
                    </div>

                    <div class="slots-box" id="time-slots">
                        <div class="no-slots">Please select a barber for this person first.</div>
                    </div>
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

                <div class="policy-check-wrap">
                    <input type="checkbox" id="policy-check">
                    <label for="policy-check">I have read and agree to the booking policy.</label>
                </div>

                <div class="continue-wrapper">
                    <form id="continue-form" action="payment.php" method="post">
                        <input type="hidden" name="total_price" id="total-price-input">
                        <input type="hidden" name="total_duration" id="total-duration-input">
                        <input type="hidden" name="total_persons" id="total-persons-input">
                        <input type="hidden" name="persons_payload" id="persons-payload-input">
                        <button type="submit" class="continue-button" id="continue-btn" disabled>Proceed to Payment</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="booking-page-right">
            <div class="booking-summary-box">
                <h4>Summary</h4>

                <p><strong>Booked Under:</strong> <?= htmlspecialchars($accountOwnerName) ?></p>
                <p><strong>Total Persons:</strong> <span id="summary-person-count">1</span></p>
                <p><strong>Total to pay:</strong> <span id="summary-total">₱<?= number_format($service['price'], 2) ?></span></p>
                <p><strong>Downpayment (15%):</strong> <span id="summary-downpayment">₱<?= number_format($service['price'] * 0.15, 2) ?></span></p>

                <div id="summary-persons-list"></div>
            </div>
        </div>
    </div>
</main>

<div class="booking-error-backdrop" id="bookingErrorModal">
    <div class="booking-error-box">
        <h3>Notice</h3>
        <p id="bookingErrorText"></p>
        <button type="button" id="closeBookingErrorModal">OK</button>
    </div>
</div>

<script>
const mainServices = <?= json_encode(array_map(function($s) {
    return [
        'id' => (int)$s['id'],
        'name' => $s['name'],
        'price' => (float)$s['price'],
        'duration' => (int)$s['duration_minutes']
    ];
}, $main_services)) ?>;

const optionalServices = <?= json_encode(array_map(function($s) {
    return [
        'id' => (int)$s['id'],
        'name' => $s['name'],
        'price' => (float)$s['price'],
        'duration' => (int)$s['duration_minutes']
    ];
}, $optional_services)) ?>;

const defaultServiceId = <?= (int)$service['id'] ?>;
const barberSchedulesData = <?= json_encode($barberSchedulesData) ?>;
const barberLeavesData = <?= json_encode($barberLeavesData) ?>;
const barberBookedData = <?= json_encode($barberBookedData) ?>;
const accountOwnerName = <?= json_encode($accountOwnerName) ?>;

let sharedSelectedDate = "";
let personCount = 1;
let activePersonIndex = 0;
let persons = [];

const slotsDiv = document.getElementById("time-slots");

const summaryTotal = document.getElementById("summary-total");
const summaryDownpayment = document.getElementById("summary-downpayment");
const summaryPersonCount = document.getElementById("summary-person-count");
const summaryPersonsList = document.getElementById("summary-persons-list");

const policyCheck = document.getElementById("policy-check");
const continueBtn = document.getElementById("continue-btn");

const totalPriceInput = document.getElementById("total-price-input");
const totalDurationInput = document.getElementById("total-duration-input");
const totalPersonsInput = document.getElementById("total-persons-input");
const personsPayloadInput = document.getElementById("persons-payload-input");

const minusPersonBtn = document.getElementById("minus-person");
const plusPersonBtn = document.getElementById("plus-person");
const personCountDisplay = document.getElementById("person-count-display");
const personTabs = document.getElementById("person-tabs");

const personEditorTitle = document.getElementById("person-editor-title");
const scheduleTitle = document.getElementById("schedule-title");
const personBarberSelect = document.getElementById("person-barber");
const personMainServiceSelect = document.getElementById("person-main-service");
const personCurrentSummary = document.getElementById("person-current-summary");

function createDefaultPerson(index) {
    return {
        person_no: index + 1,
        service_id: defaultServiceId,
        selected_services: [],
        barber_id: "",
        barber_name: "",
        selected_date: sharedSelectedDate || "",
        selected_start_24: "",
        selected_end_24: "",
        selected_start_display: "",
        selected_end_display: ""
    };
}

function initializePersons() {
    persons = [];
    for (let i = 0; i < personCount; i++) {
        persons.push(createDefaultPerson(i));
    }
}

function syncPersonNumbers() {
    persons.forEach((person, index) => {
        person.person_no = index + 1;
    });
}

function getServiceById(id) {
    return mainServices.find(s => parseInt(s.id) === parseInt(id)) || null;
}

function getOptionalServiceById(id) {
    return optionalServices.find(s => parseInt(s.id) === parseInt(id)) || null;
}

function getPersonSubtotal(person) {
    let service = getServiceById(person.service_id);
    let total = service ? parseFloat(service.price) : 0;

    person.selected_services.forEach(id => {
        const addon = getOptionalServiceById(id);
        if (addon) total += parseFloat(addon.price);
    });

    return total;
}

function getPersonDuration(person) {
    let service = getServiceById(person.service_id);
    let total = service ? parseInt(service.duration) : 0;

    person.selected_services.forEach(id => {
        const addon = getOptionalServiceById(id);
        if (addon) total += parseInt(addon.duration);
    });

    return total;
}

function calculateTotalPrice() {
    return persons.reduce((sum, person) => sum + getPersonSubtotal(person), 0);
}

function calculateTotalDuration() {
    return persons.reduce((sum, person) => sum + getPersonDuration(person), 0);
}

function renderPersonTabs() {
    personTabs.innerHTML = "";

    persons.forEach((person, index) => {
        const btn = document.createElement("button");
        btn.type = "button";
        btn.className = "person-tab";
        if (index === activePersonIndex) btn.classList.add("active");
        if (person.service_id && person.barber_id && person.selected_start_24) btn.classList.add("completed");
        btn.textContent = "Person " + (index + 1);

        btn.addEventListener("click", function() {
            saveActivePersonFromInputs();
            activePersonIndex = index;
            renderPersonTabs();
            renderPersonEditor();
            updateSummary();
            validateForm();
        });

        personTabs.appendChild(btn);
    });
}

function renderPersonEditor() {
    const person = persons[activePersonIndex];
    personEditorTitle.textContent = "Person " + (activePersonIndex + 1) + " Details";
    scheduleTitle.textContent = "Select Schedule for Person " + (activePersonIndex + 1);

    personBarberSelect.value = person.barber_id || "";
    personMainServiceSelect.value = person.service_id;

    document.querySelectorAll(".person-addon-item").forEach(item => {
        const id = parseInt(item.dataset.id);
        item.classList.toggle("selected", person.selected_services.includes(id));
    });

    renderCurrentPersonSummary();
    updateSharedDateDisplay();
    refreshCalendarLock();
    generateSlots();
}

function renderCurrentPersonSummary() {
    const person = persons[activePersonIndex];
    const mainService = getServiceById(person.service_id);

    let html = `
        <strong>Current Person Summary</strong>
        <ul>
            <li><strong>Booked Under:</strong> ${escapeHtml(accountOwnerName)}</li>
            <li><strong>Selected Barber:</strong> ${escapeHtml(person.barber_name || "Not selected")}</li>
            <li><strong>Main Service:</strong> ${mainService ? escapeHtml(mainService.name) : "Not selected"}</li>
    `;

    if (person.selected_services.length > 0) {
        const addons = person.selected_services
            .map(id => getOptionalServiceById(id))
            .filter(Boolean)
            .map(addon => escapeHtml(addon.name));
        html += `<li><strong>Add-ons:</strong> ${addons.join(", ")}</li>`;
    } else {
        html += `<li><strong>Add-ons:</strong> None</li>`;
    }

    html += `
            <li><strong>Selected Schedule:</strong> ${
                person.selected_date
                    ? escapeHtml(formatDateDisplay(person.selected_date)) + (
                        person.selected_start_display
                            ? " | " + escapeHtml(person.selected_start_display) + " - " + escapeHtml(person.selected_end_display)
                            : ""
                      )
                    : "Not selected"
            }</li>
            <li><strong>Person Duration:</strong> ${getPersonDuration(person)} mins</li>
            <li><strong>Subtotal:</strong> ₱${getPersonSubtotal(person).toFixed(2)}</li>
        </ul>
    `;

    personCurrentSummary.innerHTML = html;
}

function saveActivePersonFromInputs() {
    const person = persons[activePersonIndex];
    const selectedBarberOption = personBarberSelect.options[personBarberSelect.selectedIndex];

    person.barber_id = personBarberSelect.value ? parseInt(personBarberSelect.value) : "";
    person.barber_name = person.barber_id ? selectedBarberOption.text : "";
    person.service_id = parseInt(personMainServiceSelect.value);

    const selectedAddons = [];
    document.querySelectorAll(".person-addon-item.selected").forEach(item => {
        selectedAddons.push(parseInt(item.dataset.id));
    });
    person.selected_services = selectedAddons;

    renderCurrentPersonSummary();
}

function renderPersonCount() {
    personCountDisplay.textContent = personCount + (personCount === 1 ? " Person" : " Persons");
}

function rebuildPersons(newCount) {
    saveActivePersonFromInputs();

    const oldPersons = [...persons];
    persons = [];

    for (let i = 0; i < newCount; i++) {
        if (oldPersons[i]) {
            persons.push(oldPersons[i]);
        } else {
            persons.push(createDefaultPerson(i));
        }
    }

    personCount = newCount;
    syncPersonNumbers();

    if (activePersonIndex >= personCount) {
        activePersonIndex = personCount - 1;
    }

    renderPersonCount();
    renderPersonTabs();
    renderPersonEditor();
    updateSummary();
    validateForm();
}

function pad(num) {
    return String(num).padStart(2, "0");
}

function formatTime24(dateObj) {
    return pad(dateObj.getHours()) + ":" + pad(dateObj.getMinutes());
}

function parseDisplayTimeToDate(dateStr, time24) {
    return new Date(dateStr + "T" + time24 + ":00");
}

function showErrorModal(message) {
    document.getElementById("bookingErrorText").textContent = message;
    document.getElementById("bookingErrorModal").classList.add("show");
}

function updateSharedDateDisplay() {
    const el = document.getElementById("shared-date-display");
    if (!el) return;
    el.textContent = sharedSelectedDate ? formatDateDisplay(sharedSelectedDate) : "Not selected";
}

function refreshCalendarLock() {
    if (!bookingCalendar) return;

    if (sharedSelectedDate) {
        bookingCalendar.setDate(sharedSelectedDate, false);
    }

    bookingCalendar.redraw();
}

minusPersonBtn.addEventListener("click", function() {
    if (personCount > 1) {
        rebuildPersons(personCount - 1);
    }
});

plusPersonBtn.addEventListener("click", function() {
    if (personCount < 10) {
        rebuildPersons(personCount + 1);
    }
});

personBarberSelect.addEventListener("change", function() {
    saveActivePersonFromInputs();
    const person = persons[activePersonIndex];
    person.selected_start_24 = "";
    person.selected_end_24 = "";
    person.selected_start_display = "";
    person.selected_end_display = "";
    person.selected_date = sharedSelectedDate || "";
    updateSummary();
    generateSlots();
    validateForm();
});

personMainServiceSelect.addEventListener("change", function() {
    saveActivePersonFromInputs();
    const person = persons[activePersonIndex];
    person.selected_start_24 = "";
    person.selected_end_24 = "";
    person.selected_start_display = "";
    person.selected_end_display = "";
    person.selected_date = sharedSelectedDate || "";
    renderPersonTabs();
    renderPersonEditor();
    updateSummary();
    generateSlots();
    validateForm();
});

document.querySelectorAll(".person-addon-item").forEach(item => {
    item.addEventListener("click", function() {
        this.classList.toggle("selected");
        saveActivePersonFromInputs();
        const person = persons[activePersonIndex];
        person.selected_start_24 = "";
        person.selected_end_24 = "";
        person.selected_start_display = "";
        person.selected_end_display = "";
        person.selected_date = sharedSelectedDate || "";
        renderPersonTabs();
        renderCurrentPersonSummary();
        updateSummary();
        generateSlots();
        validateForm();
    });
});

policyCheck.addEventListener("change", validateForm);

document.getElementById("continue-form").addEventListener("submit", function(e) {
    saveActivePersonFromInputs();

    if (!isFormValid()) {
        e.preventDefault();
        showErrorModal("Please complete the booking form. All persons/companions must use the same booking date, each person must have a barber and available time slot, and you must agree to the booking policy.");
        return;
    }

    totalPriceInput.value = calculateTotalPrice().toFixed(2);
    totalDurationInput.value = calculateTotalDuration();
    totalPersonsInput.value = personCount;

    const payload = persons.map(person => ({
        person_no: person.person_no,
        full_name: accountOwnerName,
        barber_id: person.barber_id,
        barber_name: person.barber_name,
        selected_date: person.selected_date,
        selected_start_time: person.selected_start_display,
        selected_end_time: person.selected_end_display,
        selected_start_24: person.selected_start_24,
        selected_end_24: person.selected_end_24,
        service_id: person.service_id,
        selected_services: person.selected_services,
        total_price: getPersonSubtotal(person),
        total_duration: getPersonDuration(person)
    }));

    personsPayloadInput.value = JSON.stringify(payload);
});

function updateSummary() {
    saveActivePersonFromInputs();

    summaryPersonCount.textContent = personCount;
    summaryTotal.textContent = "₱" + calculateTotalPrice().toFixed(2);
    summaryDownpayment.textContent = "₱" + (calculateTotalPrice() * 0.15).toFixed(2);

    renderSummaryPersons();
}

function renderSummaryPersons() {
    summaryPersonsList.innerHTML = "";

    persons.forEach((person, index) => {
        const mainService = getServiceById(person.service_id);
        const addons = person.selected_services
            .map(id => getOptionalServiceById(id))
            .filter(Boolean);

        const block = document.createElement("div");
        block.className = "summary-person-block";

        let html = `
            <h5>Person ${index + 1}</h5>
            <p><strong>Booked Under:</strong> ${escapeHtml(accountOwnerName)}</p>
            <p><strong>Selected Barber:</strong> ${escapeHtml(person.barber_name || "Not selected")}</p>
            <p><strong>Selected Schedule:</strong> ${
                person.selected_date
                    ? escapeHtml(formatDateDisplay(person.selected_date)) + (
                        person.selected_start_display
                            ? " | " + escapeHtml(person.selected_start_display) + " - " + escapeHtml(person.selected_end_display)
                            : ""
                      )
                    : "Not selected"
            }</p>
            <p><strong>Main Service:</strong> ${mainService ? escapeHtml(mainService.name) : "Not selected"}</p>
        `;

        if (addons.length > 0) {
            html += `<p><strong>Add-ons:</strong> ${addons.map(a => escapeHtml(a.name)).join(", ")}</p>`;
        } else {
            html += `<p><strong>Add-ons:</strong> None</p>`;
        }

        html += `
            <p><strong>Duration:</strong> ${getPersonDuration(person)} mins</p>
            <p><strong>Subtotal:</strong> ₱${getPersonSubtotal(person).toFixed(2)}</p>
        `;

        block.innerHTML = html;
        summaryPersonsList.appendChild(block);
    });
}

function generateSlots() {
    slotsDiv.innerHTML = "";

    const person = persons[activePersonIndex];
    const barberId = person.barber_id;
    const selectedDate = sharedSelectedDate;

    if (!barberId) {
        slotsDiv.innerHTML = `<div class="no-slots">Please select a barber for this person first.</div>`;
        return;
    }

    if (!selectedDate) {
        slotsDiv.innerHTML = `<div class="no-slots">Please select a date.</div>`;
        return;
    }

    const totalDuration = getPersonDuration(person);
    const approvedLeaveDates = barberLeavesData[barberId] || [];
    const weeklySchedule = barberSchedulesData[barberId] || {};
    const booked = barberBookedData[barberId] || [];

    if (approvedLeaveDates.includes(selectedDate)) {
        slotsDiv.innerHTML = `<div class="unavailable-note">This barber is on approved leave on this date.</div>`;
        return;
    }

    const dayName = getDayName(selectedDate);
    const daySchedule = weeklySchedule[dayName];

    if (!daySchedule) {
        slotsDiv.innerHTML = `<div class="unavailable-note">This barber has no schedule on ${dayName}.</div>`;
        return;
    }

    let start = new Date(selectedDate + "T" + daySchedule.start);
    let end = new Date(selectedDate + "T" + daySchedule.end);
    let now = new Date();
    let hasAvailableSlot = false;

    while (start.getTime() + totalDuration * 60000 <= end.getTime()) {
        const slotStart = new Date(start.getTime());
        const slotEnd = new Date(slotStart.getTime() + totalDuration * 60000);

        const isPast = slotStartIsPast(slotStart, now, selectedDate);

        const existingConflict = booked.some(b => {
            const bs = new Date(String(b.start).replace(" ", "T"));
            const be = new Date(String(b.end).replace(" ", "T"));
            return (slotStart < be && slotEnd > bs);
        });

        const localConflict = persons.some((p, idx) => {
            if (idx === activePersonIndex) return false;
            if (!p.barber_id || !p.selected_date || !p.selected_start_24 || !p.selected_end_24) return false;
            if (parseInt(p.barber_id) !== parseInt(barberId)) return false;
            if (p.selected_date !== selectedDate) return false;

            const ps = parseDisplayTimeToDate(p.selected_date, p.selected_start_24);
            const pe = parseDisplayTimeToDate(p.selected_date, p.selected_end_24);

            return (slotStart < pe && slotEnd > ps);
        });

        if (!existingConflict && !localConflict && !isPast) {
            hasAvailableSlot = true;

            const btn = document.createElement("button");
            btn.type = "button";
            btn.className = "time-slot-btn";
            btn.innerText = formatTime(slotStart);

            if (
                person.selected_start_24 === formatTime24(slotStart) &&
                person.selected_end_24 === formatTime24(slotEnd) &&
                person.selected_date === selectedDate
            ) {
                btn.classList.add("selected");
            }

            btn.addEventListener("click", function() {
                document.querySelectorAll(".time-slot-btn").forEach(b => b.classList.remove("selected"));
                btn.classList.add("selected");

                person.selected_date = selectedDate;
                person.selected_start_24 = formatTime24(slotStart);
                person.selected_end_24 = formatTime24(slotEnd);
                person.selected_start_display = formatTime(slotStart);
                person.selected_end_display = formatTime(slotEnd);

                renderCurrentPersonSummary();
                updateSummary();
                validateForm();
            });

            slotsDiv.appendChild(btn);
        }

        start = new Date(start.getTime() + 30 * 60000);
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

function formatDateDisplay(dateStr) {
    const d = new Date(dateStr + "T00:00:00");
    return d.toLocaleDateString([], { year: 'numeric', month: 'long', day: 'numeric' });
}

function getDayName(dateStr) {
    const date = new Date(dateStr + "T00:00:00");
    const days = ["Sunday", "Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday"];
    return days[date.getDay()];
}

function escapeHtml(text) {
    const div = document.createElement("div");
    div.innerText = text;
    return div.innerHTML;
}

function isFormValid() {
    return !!(
        persons.length > 0 &&
        persons.every(person =>
            person.service_id &&
            person.barber_id &&
            person.selected_date &&
            person.selected_start_24 &&
            person.selected_end_24
        ) &&
        policyCheck.checked
    );
}

function validateForm() {
    continueBtn.disabled = !isFormValid();
}

let bookingCalendar = flatpickr("#booking-calendar", {
    inline: true,
    minDate: "<?= $today->format("Y-m-d") ?>",
    maxDate: "<?= $end_date->format("Y-m-d") ?>",
    dateFormat: "Y-m-d",

    onDayCreate: function(dObj, dStr, fp, dayElem) {
        if (!sharedSelectedDate) return;

        const date = dayElem.dateObj;
        const yyyy = date.getFullYear();
        const mm = String(date.getMonth() + 1).padStart(2, "0");
        const dd = String(date.getDate()).padStart(2, "0");
        const currentDate = `${yyyy}-${mm}-${dd}`;

        /*
          Group booking rule:
          - Person 1 controls the booking date.
          - Companions must use the same date.
          - When viewing/editing companions, all other dates are locked.
        */
        if (activePersonIndex !== 0 && currentDate !== sharedSelectedDate) {
            dayElem.classList.add("flatpickr-disabled");
            dayElem.classList.add("group-date-locked-day");
            dayElem.setAttribute("aria-disabled", "true");
            dayElem.style.pointerEvents = "none";
            dayElem.style.opacity = "0.35";
        }
    },

    onChange: function(selectedDates, dateStr) {
        if (!dateStr) return;

        if (activePersonIndex !== 0 && sharedSelectedDate) {
            bookingCalendar.setDate(sharedSelectedDate, false);
            showErrorModal("All companions must use the same booking date. Only Person 1 can change the appointment date.");
            bookingCalendar.redraw();
            return;
        }

        sharedSelectedDate = dateStr;

        persons.forEach(person => {
            person.selected_date = dateStr;
            person.selected_start_24 = "";
            person.selected_end_24 = "";
            person.selected_start_display = "";
            person.selected_end_display = "";
        });

        updateSharedDateDisplay();
        renderPersonTabs();
        renderCurrentPersonSummary();
        updateSummary();
        generateSlots();
        validateForm();
        bookingCalendar.redraw();
    }
});

document.getElementById("closeBookingErrorModal").addEventListener("click", function() {
    document.getElementById("bookingErrorModal").classList.remove("show");
});

initializePersons();
updateSharedDateDisplay();
renderPersonCount();
renderPersonTabs();
renderPersonEditor();
updateSummary();
validateForm();
</script>

<?php include 'footer.php'; ?>
</body>
</html>