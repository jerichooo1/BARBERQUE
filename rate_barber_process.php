<?php
session_start();
include 'db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$customer_id    = (int)$_SESSION['user_id'];
$appointment_id = isset($_POST['appointment_id']) ? (int)$_POST['appointment_id'] : 0;
$rating         = isset($_POST['rating']) ? (int)$_POST['rating'] : 0;
$comment        = trim($_POST['comment'] ?? '');
$target_type    = strtolower(trim($_POST['target_type'] ?? 'parent'));
$person_no      = isset($_POST['person_no']) ? (int)$_POST['person_no'] : 0;
$posted_barber_id = isset($_POST['barber_id']) ? (int)$_POST['barber_id'] : 0;

if (!$appointment_id || $rating < 1 || $rating > 5) {
    $_SESSION['error_message'] = "Please select a valid rating.";
    header("Location: appointments.php");
    exit();
}

if (!in_array($target_type, ['parent', 'companion'], true)) {
    $target_type = 'parent';
}

if ($target_type === 'companion' && $person_no <= 0) {
    $_SESSION['error_message'] = "Invalid group booking person selected for rating.";
    header("Location: appointments.php");
    exit();
}

/* =========================
   CHECK barber_ratings COLUMNS
========================= */
$ratingsCols = [];
$colsRes = $conn->query("SHOW COLUMNS FROM barber_ratings");
if ($colsRes) {
    while ($col = $colsRes->fetch_assoc()) {
        $ratingsCols[] = $col['Field'];
    }
}

$hasPersonNoColumn = in_array('person_no', $ratingsCols, true);
$hasBarberIdColumn = in_array('barber_id', $ratingsCols, true);

/* =========================
   CHECK APPOINTMENT OWNERSHIP
========================= */
$stmt = $conn->prepare("
    SELECT a.id, a.barber_id, a.status
    FROM appointments a
    WHERE a.id = ? AND a.customer_id = ?
    LIMIT 1
");
$stmt->bind_param("ii", $appointment_id, $customer_id);
$stmt->execute();
$result = $stmt->get_result();
$appointment = $result->fetch_assoc();
$stmt->close();

if (!$appointment) {
    $_SESSION['error_message'] = "Appointment not found.";
    header("Location: appointments.php");
    exit();
}

if (strtolower(trim($appointment['status'] ?? '')) !== 'completed') {
    $_SESSION['error_message'] = "Only completed appointments can be rated.";
    header("Location: appointments.php");
    exit();
}

/* =========================
   RESOLVE ACTUAL BARBER TO RATE
========================= */
$resolved_barber_id = (int)$appointment['barber_id'];
$resolved_person_no = 0;

if ($target_type === 'companion') {
    $checkCompanionTable = $conn->query("SHOW TABLES LIKE 'appointment_companions'");
    if (!$checkCompanionTable || $checkCompanionTable->num_rows === 0) {
        $_SESSION['error_message'] = "Group booking details were not found.";
        header("Location: appointments.php");
        exit();
    }

    $companionCols = [];
    $companionColsRes = $conn->query("SHOW COLUMNS FROM appointment_companions");
    if ($companionColsRes) {
        while ($col = $companionColsRes->fetch_assoc()) {
            $companionCols[] = $col['Field'];
        }
    }

    $hasCompanionBarberId = in_array('barber_id', $companionCols, true);

    if (!$hasCompanionBarberId) {
        $_SESSION['error_message'] = "This group booking does not support per-person barber rating yet.";
        header("Location: appointments.php");
        exit();
    }

    $stmt = $conn->prepare("
        SELECT barber_id
        FROM appointment_companions
        WHERE appointment_id = ? AND person_no = ?
        LIMIT 1
    ");
    $stmt->bind_param("ii", $appointment_id, $person_no);
    $stmt->execute();
    $companion = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$companion) {
        $_SESSION['error_message'] = "Selected group booking person was not found.";
        header("Location: appointments.php");
        exit();
    }

    $resolved_barber_id = (int)($companion['barber_id'] ?? 0);
    $resolved_person_no = $person_no;

    if ($resolved_barber_id <= 0) {
        $_SESSION['error_message'] = "This person does not have a valid barber to rate.";
        header("Location: appointments.php");
        exit();
    }
} else {
    $resolved_person_no = 0;
    if ($posted_barber_id > 0) {
        $resolved_barber_id = $posted_barber_id;
    }
}

/* =========================
   PREVENT DUPLICATE RATING
========================= */
$alreadyRated = false;

if ($hasPersonNoColumn && $hasBarberIdColumn) {
    $check = $conn->prepare("
        SELECT id
        FROM barber_ratings
        WHERE appointment_id = ?
          AND person_no = ?
          AND barber_id = ?
          AND customer_id = ?
        LIMIT 1
    ");
    $check->bind_param("iiii", $appointment_id, $resolved_person_no, $resolved_barber_id, $customer_id);
    $check->execute();
    $alreadyRated = $check->get_result()->num_rows > 0;
    $check->close();
} elseif ($hasPersonNoColumn) {
    $check = $conn->prepare("
        SELECT id
        FROM barber_ratings
        WHERE appointment_id = ?
          AND person_no = ?
          AND customer_id = ?
        LIMIT 1
    ");
    $check->bind_param("iii", $appointment_id, $resolved_person_no, $customer_id);
    $check->execute();
    $alreadyRated = $check->get_result()->num_rows > 0;
    $check->close();
} else {
    $check = $conn->prepare("
        SELECT id
        FROM barber_ratings
        WHERE appointment_id = ?
        LIMIT 1
    ");
    $check->bind_param("i", $appointment_id);
    $check->execute();
    $alreadyRated = $check->get_result()->num_rows > 0;
    $check->close();
}

if ($alreadyRated) {
    $_SESSION['error_message'] = "You already rated this barber.";
    header("Location: appointments.php");
    exit();
}

/* =========================
   SAVE RATING
========================= */
if ($hasPersonNoColumn && $hasBarberIdColumn) {
    $insert = $conn->prepare("
        INSERT INTO barber_ratings (barber_id, customer_id, appointment_id, person_no, rating, comment)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $insert->bind_param(
        "iiiiis",
        $resolved_barber_id,
        $customer_id,
        $appointment_id,
        $resolved_person_no,
        $rating,
        $comment
    );
} elseif ($hasPersonNoColumn) {
    $insert = $conn->prepare("
        INSERT INTO barber_ratings (barber_id, customer_id, appointment_id, person_no, rating, comment)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $insert->bind_param(
        "iiiiis",
        $resolved_barber_id,
        $customer_id,
        $appointment_id,
        $resolved_person_no,
        $rating,
        $comment
    );
} else {
    $insert = $conn->prepare("
        INSERT INTO barber_ratings (barber_id, customer_id, appointment_id, rating, comment)
        VALUES (?, ?, ?, ?, ?)
    ");
    $insert->bind_param(
        "iiiis",
        $resolved_barber_id,
        $customer_id,
        $appointment_id,
        $rating,
        $comment
    );
}

if ($insert->execute()) {
    $_SESSION['success_message'] = "Thank you for rating your barber!";
} else {
    $_SESSION['error_message'] = "Error saving rating.";
}
$insert->close();

header("Location: appointments.php");
exit();
?>