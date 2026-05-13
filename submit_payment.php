<?php
session_start();
include 'db.php';
include 'modal_helper.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$total_price         = floatval($_POST['total_price'] ?? 0);
$total_duration      = intval($_POST['total_duration'] ?? 0);
$total_persons       = intval($_POST['total_persons'] ?? 1);
$persons_payload_raw = $_POST['persons_payload'] ?? '';
$reference           = trim($_POST['reference_number'] ?? '');
$customer_id         = intval($_SESSION['user_id']);

$downpayment = $total_price * 0.15;

if ($total_price <= 0 || empty($persons_payload_raw) || $reference === '') {
    showModalMessage('Notice', 'Missing required booking or payment details.');
    exit();
}

if (!preg_match('/^[A-Za-z0-9]{5,20}$/', $reference)) {
    showModalMessage('Notice', 'Reference number must be 5 to 20 characters using letters and numbers only.');
    exit();
}

$persons_payload = json_decode($persons_payload_raw, true);
if (!is_array($persons_payload) || count($persons_payload) <= 0) {
    showModalMessage('Notice', 'Invalid booking details.');
    exit();
}

if ($total_persons <= 0) {
    $total_persons = count($persons_payload);
}

/* Upload proof image */
if (!isset($_FILES['proof_image']) || $_FILES['proof_image']['error'] !== 0) {
    showModalMessage('Notice', 'Please upload proof of payment.');
    exit();
}

$upload_dir = "uploads/payment_proofs/";
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

$original_name = $_FILES['proof_image']['name'];
$tmp_name = $_FILES['proof_image']['tmp_name'];
$file_size = $_FILES['proof_image']['size'];
$file_ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));

$allowed_ext = ['jpg', 'jpeg', 'png', 'webp'];

if (!in_array($file_ext, $allowed_ext)) {
    showModalMessage('Notice', 'Invalid file type. Only JPG, JPEG, PNG, and WEBP are allowed.');
    exit();
}

if ($file_size > 5 * 1024 * 1024) {
    showModalMessage('Notice', 'File is too large. Maximum size is 5MB.');
    exit();
}

$proof_image_name = 'payment_' . time() . '_' . $customer_id . '.' . $file_ext;
$target_path = $upload_dir . $proof_image_name;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->begin_transaction();

try {
    if (!move_uploaded_file($tmp_name, $target_path)) {
        throw new Exception("Failed to upload proof of payment.");
    }

    $validatedPersons = [];
    $allServiceIds = [];
    $earliestStart = null;
    $latestEnd = null;

    foreach ($persons_payload as $person) {
        $person_no             = intval($person['person_no'] ?? 0);
        $full_name             = trim($person['full_name'] ?? 'Account Owner');
        $barber_id             = intval($person['barber_id'] ?? 0);
        $service_id            = intval($person['service_id'] ?? 0);
        $selected_date         = trim($person['selected_date'] ?? '');
        $selected_start_24     = trim($person['selected_start_24'] ?? '');
        $selected_end_24       = trim($person['selected_end_24'] ?? '');
        $selected_services     = $person['selected_services'] ?? [];
        $person_total_price    = floatval($person['total_price'] ?? 0);
        $person_total_duration = intval($person['total_duration'] ?? 0);

        if (
            $barber_id <= 0 ||
            $service_id <= 0 ||
            $selected_date === '' ||
            $selected_start_24 === '' ||
            $selected_end_24 === ''
        ) {
            throw new Exception("A person booking entry is incomplete.");
        }

        if (!is_array($selected_services)) {
            $selected_services = [];
        }

        if ($person_total_price <= 0 || $person_total_duration <= 0) {
            throw new Exception("Invalid person total amount or duration.");
        }

        $start_timestamp = strtotime($selected_date . ' ' . $selected_start_24);
        $end_timestamp   = strtotime($selected_date . ' ' . $selected_end_24);

        if ($start_timestamp === false || $end_timestamp === false) {
            throw new Exception("Invalid booking date or time.");
        }

        $start_time = date("Y-m-d H:i:s", $start_timestamp);
        $end_time   = date("Y-m-d H:i:s", $end_timestamp);

        if ($end_timestamp <= $start_timestamp) {
            throw new Exception("Invalid schedule range.");
        }

        $now = date("Y-m-d H:i:s");
        if ($start_time < $now) {
            throw new Exception("One of the selected schedules is already in the past.");
        }

        /* 1. Check barber exists and is active */
        $stmt = $conn->prepare("
            SELECT id, status
            FROM barbers
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->bind_param("i", $barber_id);
        $stmt->execute();
        $barber = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$barber || ($barber['status'] ?? '') !== 'active') {
            throw new Exception("A selected barber is not available for booking.");
        }

        /* 2. Check approved leave */
        $leaveTableExists = false;
        $checkLeaveTable = $conn->query("SHOW TABLES LIKE 'barber_leaves'");
        if ($checkLeaveTable && $checkLeaveTable->num_rows > 0) {
            $leaveTableExists = true;
        }

        if ($leaveTableExists) {
            $stmt = $conn->prepare("
                SELECT COUNT(*) AS total
                FROM barber_leaves
                WHERE barber_id = ?
                  AND leave_date = ?
                  AND status = 'approved'
            ");
            $stmt->bind_param("is", $barber_id, $selected_date);
            $stmt->execute();
            $leaveConflict = intval($stmt->get_result()->fetch_assoc()['total'] ?? 0);
            $stmt->close();

            if ($leaveConflict > 0) {
                throw new Exception("A selected barber is on approved leave on the selected date.");
            }
        }

        /* 3. Check weekly barber schedule */
        $day_of_week = date('l', strtotime($selected_date));

        $stmt = $conn->prepare("
            SELECT start_time, end_time
            FROM barber_schedules
            WHERE barber_id = ? AND day_of_week = ?
            LIMIT 1
        ");
        $stmt->bind_param("is", $barber_id, $day_of_week);
        $stmt->execute();
        $schedule = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$schedule) {
            throw new Exception("A selected barber has no working schedule on the selected day.");
        }

        $schedule_start = strtotime($selected_date . ' ' . $schedule['start_time']);
        $schedule_end   = strtotime($selected_date . ' ' . $schedule['end_time']);

        if ($start_timestamp < $schedule_start || $end_timestamp > $schedule_end) {
            throw new Exception("A selected schedule is outside the barber's working hours.");
        }

        /* 4. Check conflict with appointments */
        $stmt = $conn->prepare("
            SELECT COUNT(*) AS total
            FROM appointments
            WHERE barber_id = ?
              AND status NOT IN ('canceled', 'cancelled', 'completed', 'no-show', 'rejected')
              AND (? < end_time AND ? > start_time)
        ");
        $stmt->bind_param("iss", $barber_id, $start_time, $end_time);
        $stmt->execute();
        $appointmentConflict = intval($stmt->get_result()->fetch_assoc()['total'] ?? 0);
        $stmt->close();

        /* 5. Check conflict with ongoing walk-ins */
        $stmt = $conn->prepare("
            SELECT COUNT(*) AS total
            FROM walkins
            WHERE barber_id = ?
              AND status = 'ongoing'
              AND started_at IS NOT NULL
              AND estimated_end_at IS NOT NULL
              AND (? < estimated_end_at AND ? > started_at)
        ");
        $stmt->bind_param("iss", $barber_id, $start_time, $end_time);
        $stmt->execute();
        $walkinConflict = intval($stmt->get_result()->fetch_assoc()['total'] ?? 0);
        $stmt->close();

        if ($appointmentConflict > 0 || $walkinConflict > 0) {
            throw new Exception("Sorry, one of the selected schedules is no longer available.");
        }

        $ids = [$service_id];
        foreach ($selected_services as $sid) {
            $sid = intval($sid);
            if ($sid > 0) {
                $ids[] = $sid;
            }
        }
        $ids = array_values(array_unique($ids));

        foreach ($ids as $sid) {
            $allServiceIds[] = $sid;
        }

        if ($earliestStart === null || $start_timestamp < $earliestStart) {
            $earliestStart = $start_timestamp;
        }

        if ($latestEnd === null || $end_timestamp > $latestEnd) {
            $latestEnd = $end_timestamp;
        }

        $validatedPersons[] = [
            'person_no' => $person_no,
            'full_name' => $full_name,
            'barber_id' => $barber_id,
            'service_id' => $service_id,
            'selected_services' => $selected_services,
            'total_price' => $person_total_price,
            'total_duration' => $person_total_duration,
            'start_time' => $start_time,
            'end_time' => $end_time,
            'service_ids' => $ids
        ];
    }

    if (empty($validatedPersons)) {
        throw new Exception("No valid booking entries found.");
    }

    $parentBarberId = intval($validatedPersons[0]['barber_id']);
    $parentServiceId = intval($validatedPersons[0]['service_id']);
    $parentStartTime = date("Y-m-d H:i:s", $earliestStart);
    $parentEndTime   = date("Y-m-d H:i:s", $latestEnd);

    /* Insert ONE parent appointment */
    $stmt = $conn->prepare("
        INSERT INTO appointments (
            barber_id,
            service_id,
            customer_id,
            start_time,
            end_time,
            total_persons,
            status
        )
        VALUES (?, ?, ?, ?, ?, ?, 'pending approval')
    ");
    $stmt->bind_param(
        "iiissi",
        $parentBarberId,
        $parentServiceId,
        $customer_id,
        $parentStartTime,
        $parentEndTime,
        $total_persons
    );
    $stmt->execute();
    $appointment_id = $stmt->insert_id;
    $stmt->close();

    /* Insert combined appointment services once */
    $allServiceIds = array_values(array_unique(array_map('intval', $allServiceIds)));

    if (!empty($allServiceIds)) {
        $placeholders = implode(",", array_fill(0, count($allServiceIds), "?"));
        $types = str_repeat("i", count($allServiceIds));

        $stmt = $conn->prepare("
            SELECT id, price, duration_minutes
            FROM services
            WHERE id IN ($placeholders)
        ");
        $stmt->bind_param($types, ...$allServiceIds);
        $stmt->execute();
        $result = $stmt->get_result();

        $serviceRows = [];
        while ($row = $result->fetch_assoc()) {
            $serviceRows[(int)$row['id']] = $row;
        }
        $stmt->close();

        $insert = $conn->prepare("
            INSERT INTO appointment_services
            (appointment_id, service_id, price, downpayment, duration_minutes)
            VALUES (?, ?, ?, ?, ?)
        ");

        foreach ($allServiceIds as $sid) {
            if (!isset($serviceRows[$sid])) {
                continue;
            }

            $row = $serviceRows[$sid];
            $price = floatval($row['price']);
            $down = $price * 0.15;
            $duration = intval($row['duration_minutes']);

            $insert->bind_param("iiddi", $appointment_id, $sid, $price, $down, $duration);
            $insert->execute();
        }

        $insert->close();
    }

    /* Insert each person into appointment_companions with separate barber and schedule */
    $companionStmt = $conn->prepare("
        INSERT INTO appointment_companions
        (
            appointment_id,
            person_no,
            full_name,
            service_id,
            barber_id,
            selected_services,
            total_price,
            total_duration,
            start_time,
            end_time
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    foreach ($validatedPersons as $person) {
        $selected_services_json = json_encode($person['selected_services']);

        $companionStmt->bind_param(
            "iisiisdiss",
            $appointment_id,
            $person['person_no'],
            $person['full_name'],
            $person['service_id'],
            $person['barber_id'],
            $selected_services_json,
            $person['total_price'],
            $person['total_duration'],
            $person['start_time'],
            $person['end_time']
        );
        $companionStmt->execute();
    }
    $companionStmt->close();

    /* Insert ONE payment for the whole booking */
    $stmt = $conn->prepare("
        INSERT INTO payments (
            appointment_id,
            total_amount,
            downpayment_amount,
            amount_paid,
            balance_amount,
            method,
            reference_number,
            proof_image,
            status
        )
        VALUES (?, ?, ?, ?, ?, 'gcash', ?, ?, 'pending')
    ");
    $balance_amount = $total_price - $downpayment;
    $stmt->bind_param(
        "iddddss",
        $appointment_id,
        $total_price,
        $downpayment,
        $downpayment,
        $balance_amount,
        $reference,
        $proof_image_name
    );
    $stmt->execute();
    $stmt->close();

    $conn->commit();

    $_SESSION['payment_success'] = true;
    header("Location: success.php");
    exit();

} catch (Exception $e) {
    $conn->rollback();

    if (file_exists($target_path)) {
        unlink($target_path);
    }

    showModalMessage('Notice', $e->getMessage());
    exit();
}
?>