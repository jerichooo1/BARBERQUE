<?php
session_start();
include 'db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$userId = (int)$_SESSION['user_id'];

$appointmentId = isset($_POST['appointment_id']) ? (int)$_POST['appointment_id'] : 0;
$barberId      = isset($_POST['barber_id']) ? (int)$_POST['barber_id'] : 0;
$selectedDate  = trim($_POST['selected_date'] ?? '');
$selectedStart = trim($_POST['selected_start_time'] ?? '');
$targetType    = strtolower(trim($_POST['target_type'] ?? 'parent'));
$personNo      = isset($_POST['person_no']) ? (int)$_POST['person_no'] : 0;

if (!$appointmentId || !$barberId || !$selectedDate || !$selectedStart) {
    $_SESSION['error_message'] = "Please complete all appointment edit fields.";
    header("Location: appointments.php");
    exit();
}

if (!in_array($targetType, ['parent', 'companion'], true)) {
    $targetType = 'parent';
}

if ($targetType === 'companion' && $personNo <= 0) {
    $_SESSION['error_message'] = "Invalid person selected for editing.";
    header("Location: appointments.php");
    exit();
}

/* =========================
   CHECK COMPANION TABLE + COLUMNS
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

$hasCompanionBarberId       = in_array('barber_id', $companionTableCols, true);
$hasCompanionStartTime      = in_array('start_time', $companionTableCols, true);
$hasCompanionEndTime        = in_array('end_time', $companionTableCols, true);
$hasCompanionDuration       = in_array('total_duration', $companionTableCols, true);
$hasCompanionRescheduleCnt  = in_array('customer_reschedule_count', $companionTableCols, true);
$hasCompanionLeaveCredit    = in_array('leave_reschedule_credit', $companionTableCols, true);
$hasCompanionReschedReason  = in_array('reschedule_reason', $companionTableCols, true);

if ($targetType === 'companion') {
    if (
        !$companionTableExists ||
        !$hasCompanionBarberId ||
        !$hasCompanionStartTime ||
        !$hasCompanionEndTime ||
        !$hasCompanionDuration ||
        !$hasCompanionRescheduleCnt ||
        !$hasCompanionLeaveCredit
    ) {
        $_SESSION['error_message'] = "Your companion booking structure does not support per-person rescheduling yet.";
        header("Location: appointments.php");
        exit();
    }
}

/* =========================
   CHECK APPOINTMENT OWNERSHIP
========================= */
$stmt = $conn->prepare("
    SELECT id, status, start_time, customer_reschedule_count, leave_reschedule_credit
    FROM appointments
    WHERE id = ? AND customer_id = ?
    LIMIT 1
");
$stmt->bind_param("ii", $appointmentId, $userId);
$stmt->execute();
$appointment = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$appointment) {
    $_SESSION['error_message'] = "Appointment not found.";
    header("Location: appointments.php");
    exit();
}

$status = strtolower(trim($appointment['status'] ?? ''));
if ($status !== 'approved') {
    $_SESSION['error_message'] = "Only approved appointments can be edited.";
    header("Location: appointments.php");
    exit();
}

/* =========================
   TARGET-SPECIFIC RESCHEDULE RULES
========================= */
$customerRescheduleCount = 0;
$leaveRescheduleCredit   = 0;
$currentTargetStart      = $appointment['start_time'] ?? null;
$totalDuration           = 30;

if ($targetType === 'parent') {
    $customerRescheduleCount = (int)($appointment['customer_reschedule_count'] ?? 0);
    $leaveRescheduleCredit   = (int)($appointment['leave_reschedule_credit'] ?? 0);

    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(s.duration_minutes), 30) AS total_duration
        FROM appointment_services aps
        JOIN services s ON aps.service_id = s.id
        WHERE aps.appointment_id = ?
    ");
    $stmt->bind_param("i", $appointmentId);
    $stmt->execute();
    $durationRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $totalDuration = (int)($durationRow['total_duration'] ?? 30);
} else {
    $stmt = $conn->prepare("
        SELECT
            person_no,
            start_time,
            total_duration,
            customer_reschedule_count,
            leave_reschedule_credit
        FROM appointment_companions
        WHERE appointment_id = ? AND person_no = ?
        LIMIT 1
    ");
    $stmt->bind_param("ii", $appointmentId, $personNo);
    $stmt->execute();
    $companionRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$companionRow) {
        $_SESSION['error_message'] = "Selected group person was not found.";
        header("Location: appointments.php");
        exit();
    }

    $customerRescheduleCount = (int)($companionRow['customer_reschedule_count'] ?? 0);
    $leaveRescheduleCredit   = (int)($companionRow['leave_reschedule_credit'] ?? 0);
    $currentTargetStart      = $companionRow['start_time'] ?: ($appointment['start_time'] ?? null);
    $totalDuration           = (int)($companionRow['total_duration'] ?? 30);
}

if ($totalDuration <= 0) {
    $totalDuration = 30;
}

$canEdit = ($customerRescheduleCount < 1) || ($leaveRescheduleCredit > 0);

if (!$canEdit) {
    $_SESSION['error_message'] = "You have already used your allowed reschedule for this appointment.";
    header("Location: appointments.php");
    exit();
}

/* =========================
   30-MINUTE RESCHEDULE LOCK
   Normal customer reschedule is blocked
   within 30 minutes before current schedule.
   Barber-leave credit can bypass this.
========================= */
if (!empty($currentTargetStart) && $leaveRescheduleCredit <= 0) {
    $currentTargetStartDt = new DateTime($currentTargetStart);
    $cutoffTime = clone $currentTargetStartDt;
    $cutoffTime->modify('-30 minutes');
    $now = new DateTime();

    if ($now >= $cutoffTime) {
        $_SESSION['error_message'] = "You cannot reschedule within 30 minutes before your appointment.";
        header("Location: appointments.php");
        exit();
    }
}

/* =========================
   VALIDATE DATE RANGE
========================= */
$today = date('Y-m-d');
$maxDate = date('Y-m-d', strtotime('+30 days'));

if ($selectedDate < $today || $selectedDate > $maxDate) {
    $_SESSION['error_message'] = "Selected date is outside the allowed booking range.";
    header("Location: appointments.php");
    exit();
}

/* =========================
   PARSE START TIME
   supports: 14:30, 02:30 PM, 2:30 PM
========================= */
$startDateTime = DateTime::createFromFormat('Y-m-d H:i', $selectedDate . ' ' . $selectedStart);

if (!$startDateTime) {
    $startDateTime = DateTime::createFromFormat('Y-m-d h:i A', $selectedDate . ' ' . $selectedStart);
}
if (!$startDateTime) {
    $startDateTime = DateTime::createFromFormat('Y-m-d g:i A', $selectedDate . ' ' . $selectedStart);
}

if (!$startDateTime) {
    $_SESSION['error_message'] = "Invalid selected time format.";
    header("Location: appointments.php");
    exit();
}

/* =========================
   BLOCK PAST TIME
========================= */
$now = new DateTime();
if ($startDateTime < $now) {
    $_SESSION['error_message'] = "You cannot select a past time slot.";
    header("Location: appointments.php");
    exit();
}

$endDateTime = clone $startDateTime;
$endDateTime->modify("+{$totalDuration} minutes");

$newStart = $startDateTime->format('Y-m-d H:i:s');
$newEnd   = $endDateTime->format('Y-m-d H:i:s');

/* =========================
   CHECK BARBER WEEKLY SCHEDULE
========================= */
$dayOfWeek = $startDateTime->format('l');

$stmt = $conn->prepare("
    SELECT start_time, end_time
    FROM barber_schedules
    WHERE barber_id = ? AND day_of_week = ?
    LIMIT 1
");
$stmt->bind_param("is", $barberId, $dayOfWeek);
$stmt->execute();
$scheduleRow = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$scheduleRow) {
    $_SESSION['error_message'] = "This barber has no schedule on the selected day.";
    header("Location: appointments.php");
    exit();
}

$scheduleStart = strtotime($selectedDate . ' ' . $scheduleRow['start_time']);
$scheduleEnd   = strtotime($selectedDate . ' ' . $scheduleRow['end_time']);
$newStartTs    = strtotime($newStart);
$newEndTs      = strtotime($newEnd);

if ($newStartTs < $scheduleStart || $newEndTs > $scheduleEnd) {
    $_SESSION['error_message'] = "Selected time is outside the barber's schedule.";
    header("Location: appointments.php");
    exit();
}

/* =========================
   CHECK BARBER LEAVE
========================= */
$checkLeaveTable = $conn->query("SHOW TABLES LIKE 'barber_leaves'");
if ($checkLeaveTable && $checkLeaveTable->num_rows > 0) {
    $leaveCols = [];
    $colsResult = $conn->query("SHOW COLUMNS FROM barber_leaves");
    if ($colsResult) {
        while ($col = $colsResult->fetch_assoc()) {
            $leaveCols[] = $col['Field'];
        }
    }

    if (in_array('leave_date', $leaveCols, true)) {
        if (in_array('status', $leaveCols, true)) {
            $stmt = $conn->prepare("
                SELECT id
                FROM barber_leaves
                WHERE barber_id = ?
                  AND leave_date = ?
                  AND status = 'approved'
                LIMIT 1
            ");
            $stmt->bind_param("is", $barberId, $selectedDate);
        } else {
            $stmt = $conn->prepare("
                SELECT id
                FROM barber_leaves
                WHERE barber_id = ?
                  AND leave_date = ?
                LIMIT 1
            ");
            $stmt->bind_param("is", $barberId, $selectedDate);
        }

        $stmt->execute();
        $leaveExists = $stmt->get_result()->num_rows > 0;
        $stmt->close();

        if ($leaveExists) {
            $_SESSION['error_message'] = "The selected barber is on leave on that date.";
            header("Location: appointments.php");
            exit();
        }
    }
}

/* =========================
   CHECK CONFLICTS: PARENT APPOINTMENTS
========================= */
$stmt = $conn->prepare("
    SELECT id
    FROM appointments
    WHERE barber_id = ?
      AND id != ?
      AND status NOT IN ('canceled', 'cancelled', 'completed', 'no-show', 'rejected')
      AND (? < end_time AND ? > start_time)
    LIMIT 1
");
$stmt->bind_param("iiss", $barberId, $appointmentId, $newStart, $newEnd);
$stmt->execute();
$parentConflict = $stmt->get_result()->num_rows > 0;
$stmt->close();

if ($parentConflict) {
    $_SESSION['error_message'] = "The selected barber and time slot are no longer available.";
    header("Location: appointments.php");
    exit();
}

/* =========================
   CHECK CONFLICTS: COMPANION PERSON SCHEDULES
========================= */
if ($companionTableExists && $hasCompanionBarberId && $hasCompanionStartTime && $hasCompanionEndTime) {
    if ($targetType === 'companion') {
        $stmt = $conn->prepare("
            SELECT person_no
            FROM appointment_companions ac
            JOIN appointments a ON a.id = ac.appointment_id
            WHERE ac.barber_id = ?
              AND NOT (ac.appointment_id = ? AND ac.person_no = ?)
              AND a.status NOT IN ('canceled', 'cancelled', 'completed', 'no-show', 'rejected')
              AND (? < ac.end_time AND ? > ac.start_time)
            LIMIT 1
        ");
        $stmt->bind_param("iiiss", $barberId, $appointmentId, $personNo, $newStart, $newEnd);
    } else {
        $stmt = $conn->prepare("
            SELECT person_no
            FROM appointment_companions ac
            JOIN appointments a ON a.id = ac.appointment_id
            WHERE ac.barber_id = ?
              AND a.status NOT IN ('canceled', 'cancelled', 'completed', 'no-show', 'rejected')
              AND (? < ac.end_time AND ? > ac.start_time)
            LIMIT 1
        ");
        $stmt->bind_param("iss", $barberId, $newStart, $newEnd);
    }

    $stmt->execute();
    $companionConflict = $stmt->get_result()->num_rows > 0;
    $stmt->close();

    if ($companionConflict) {
        $_SESSION['error_message'] = "The selected barber already has another grouped booking at that time.";
        header("Location: appointments.php");
        exit();
    }
}

/* =========================
   CHECK CONFLICTS: WALK-INS
========================= */
$checkWalkinsTable = $conn->query("SHOW TABLES LIKE 'walkins'");
if ($checkWalkinsTable && $checkWalkinsTable->num_rows > 0) {
    $stmt = $conn->prepare("
        SELECT id
        FROM walkins
        WHERE barber_id = ?
          AND status = 'ongoing'
          AND started_at IS NOT NULL
          AND estimated_end_at IS NOT NULL
          AND (? < estimated_end_at AND ? > started_at)
        LIMIT 1
    ");
    $stmt->bind_param("iss", $barberId, $newStart, $newEnd);
    $stmt->execute();
    $walkinConflict = $stmt->get_result()->num_rows > 0;
    $stmt->close();

    if ($walkinConflict) {
        $_SESSION['error_message'] = "The selected barber is currently unavailable for that time slot.";
        header("Location: appointments.php");
        exit();
    }
}

/* =========================
   UPDATE TARGET RECORD
========================= */
$conn->begin_transaction();

try {
    if ($targetType === 'parent') {
        if ($leaveRescheduleCredit > 0) {
            $stmt = $conn->prepare("
                UPDATE appointments
                SET barber_id = ?,
                    start_time = ?,
                    end_time = ?,
                    leave_reschedule_credit = 0,
                    reschedule_reason = 'barber_leave'
                WHERE id = ? AND customer_id = ?
            ");
            $stmt->bind_param("issii", $barberId, $newStart, $newEnd, $appointmentId, $userId);
        } else {
            $stmt = $conn->prepare("
                UPDATE appointments
                SET barber_id = ?,
                    start_time = ?,
                    end_time = ?,
                    customer_reschedule_count = customer_reschedule_count + 1,
                    reschedule_reason = 'customer_request'
                WHERE id = ? AND customer_id = ?
            ");
            $stmt->bind_param("issii", $barberId, $newStart, $newEnd, $appointmentId, $userId);
        }

        if (!$stmt->execute()) {
            throw new Exception("Failed to update appointment.");
        }
        $stmt->close();
    } else {
        $stmt = $conn->prepare("
            UPDATE appointment_companions
            SET barber_id = ?,
                start_time = ?,
                end_time = ?
            WHERE appointment_id = ? AND person_no = ?
        ");
        $stmt->bind_param("issii", $barberId, $newStart, $newEnd, $appointmentId, $personNo);

        if (!$stmt->execute()) {
            throw new Exception("Failed to update companion appointment.");
        }
        $stmt->close();

        if ($leaveRescheduleCredit > 0) {
            $stmt = $conn->prepare("
                UPDATE appointment_companions
                SET leave_reschedule_credit = 0,
                    reschedule_reason = 'barber_leave'
                WHERE appointment_id = ? AND person_no = ?
            ");
            $stmt->bind_param("ii", $appointmentId, $personNo);
        } else {
            $stmt = $conn->prepare("
                UPDATE appointment_companions
                SET customer_reschedule_count = customer_reschedule_count + 1,
                    reschedule_reason = 'customer_request'
                WHERE appointment_id = ? AND person_no = ?
            ");
            $stmt->bind_param("ii", $appointmentId, $personNo);
        }

        if (!$stmt->execute()) {
            throw new Exception("Failed to update companion reschedule counters.");
        }
        $stmt->close();
    }

    $conn->commit();
    $_SESSION['success_message'] = "Appointment updated successfully.";
} catch (Exception $e) {
    $conn->rollback();
    $_SESSION['error_message'] = "Failed to update appointment. Please try again.";
}

header("Location: appointments.php");
exit();
?>