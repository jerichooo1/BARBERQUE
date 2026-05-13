<?php
session_start();
include 'db.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';
require 'PHPMailer/src/Exception.php';

if (!isset($_SESSION['staff_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'staff') {
    header("Location: admin_login.php");
    exit();
}

/* SHOP SETTINGS */
$settings = $conn->query("SELECT * FROM settings LIMIT 1");
$shop = $settings ? $settings->fetch_assoc() : [];

/* SERVICES FOR COMPLETE MODAL */
$serviceCatalog = [];
$servicesQuery = $conn->query("SELECT id, name, price, duration_minutes FROM services ORDER BY name ASC");
if ($servicesQuery) {
    while ($srv = $servicesQuery->fetch_assoc()) {
        $serviceCatalog[(int)$srv['id']] = [
            'id' => (int)$srv['id'],
            'name' => $srv['name'],
            'price' => (float)$srv['price'],
            'duration_minutes' => (int)$srv['duration_minutes']
        ];
    }
}

function sendAppointmentStatusEmail(
    string $toEmail,
    string $customerName,
    string $status,
    string $appointmentDateTime,
    string $barberName,
    string $services,
    ?string $reason = null,
    ?float $amountPaid = null,
    ?float $balanceAmount = null
): bool {
    global $shop;

    $mail = new PHPMailer(true);

    try {
        $smtpEmail = trim($shop['smtp_email'] ?? '');
        $smtpPassword = trim($shop['smtp_password'] ?? '');
        $smtpFromName = trim($shop['smtp_from_name'] ?? 'Fade Street Hair Studio');

        if ($smtpEmail === '' || $smtpPassword === '') {
            return false;
        }

        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = $smtpEmail;
        $mail->Password = $smtpPassword;
        $mail->SMTPSecure = 'tls';
        $mail->Port = 587;

        $mail->setFrom($smtpEmail, $smtpFromName);
        $mail->addAddress($toEmail, $customerName);
        $mail->isHTML(true);

        $subject = 'Appointment Status Update';
        $body = "
            Hi " . htmlspecialchars($customerName) . ",<br><br>
            Your appointment status has been updated.<br><br>
            <strong>Status:</strong> " . htmlspecialchars(ucwords(str_replace('-', ' ', $status))) . "<br>
            <strong>Schedule:</strong> " . htmlspecialchars($appointmentDateTime) . "<br>
            <strong>Barber:</strong> " . htmlspecialchars($barberName) . "<br>
            <strong>Services:</strong> " . htmlspecialchars($services) . "<br>
        ";

        if ($amountPaid !== null) {
            $body .= "<strong>Amount Paid:</strong> ₱" . number_format($amountPaid, 2) . "<br>";
        }

        if ($balanceAmount !== null && strtolower($status) !== 'completed') {
            $body .= "<strong>Remaining Balance:</strong> ₱" . number_format($balanceAmount, 2) . "<br>";
        }

        if (!empty($reason)) {
            $body .= "<strong>Reason:</strong> " . htmlspecialchars($reason) . "<br>";
        }

        if ($status === 'approved') {
            $subject = 'Your Appointment Has Been Approved';
            $body .= "<br>Your booking has been approved. Please keep your appointment schedule and arrive on time. A no-show or arrival more than 15 minutes late will result in a voided booking with no refund.";
        } elseif ($status === 'rejected') {
            $subject = 'Your Appointment Has Been Rejected';
            $body .= "<br>Your booking was not approved.";
        } elseif ($status === 'completed') {
            $subject = 'Your Appointment Has Been Completed';
            $body .= "<br>Your appointment has been completed and fully paid. Thank you for visiting Fade Street.";
        } elseif ($status === 'no-show') {
            $subject = 'Appointment Marked as No-Show';
            $body .= "
                <br>Your appointment was marked as no-show.
                <br><br>
                As stated in our booking policy, a no-show or arrival more than 15 minutes late will result in a voided booking with no refund.
            ";
        } elseif ($status === 'canceled') {
            $subject = 'Your Appointment Has Been Canceled';
            $body .= "<br>Your appointment has been canceled.";
        }

        $body .= "<br><br>" . htmlspecialchars($smtpFromName);
        $mail->Subject = $subject;
        $mail->Body = $body;
        $mail->send();

        return true;
    } catch (Exception $e) {
        return false;
    }
}

function formatScheduleRange(?string $start, ?string $end): string {
    if (empty($start)) {
        return 'Not set';
    }

    $startTs = strtotime($start);
    if (!$startTs) {
        return 'Not set';
    }

    if (!empty($end) && strtotime($end)) {
        return date("F j, Y g:i A", $startTs) . ' - ' . date("g:i A", strtotime($end));
    }

    return date("F j, Y g:i A", $startTs);
}

function statusLabel(string $status): string {
    $status = strtolower(trim($status));
    switch ($status) {
        case 'pending approval': return 'Pending Approval';
        case 'approved': return 'Approved';
        case 'rejected': return 'Rejected';
        case 'completed': return 'Completed';
        case 'no-show': return 'No-Show';
        case 'canceled':
        case 'cancelled': return 'Canceled';
        default: return ucwords(str_replace('-', ' ', $status));
    }
}

function paymentStatusLabel(string $status): string {
    $status = strtolower(trim($status));
    return ucwords(str_replace('-', ' ', $status));
}

function formatAppointmentRef($id): string {
    return 'A' . str_pad((int)$id, 4, '0', STR_PAD_LEFT);
}

function splitMainAndAddons(string $servicesText): array {
    $services = array_values(array_filter(array_map('trim', explode(',', $servicesText))));
    return [
        'main_service' => $services[0] ?? 'N/A',
        'addons' => array_slice($services, 1)
    ];
}

/* COMPANION TABLE CHECK */
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

$hasCompanionPersonNo    = in_array('person_no', $companionTableCols, true);
$hasCompanionFullName    = in_array('full_name', $companionTableCols, true);
$hasCompanionServiceId   = in_array('service_id', $companionTableCols, true);
$hasCompanionSelected    = in_array('selected_services', $companionTableCols, true);
$hasCompanionTotalPrice  = in_array('total_price', $companionTableCols, true);
$hasCompanionDuration    = in_array('total_duration', $companionTableCols, true);
$hasCompanionBarberId    = in_array('barber_id', $companionTableCols, true);
$hasCompanionStartTime   = in_array('start_time', $companionTableCols, true);
$hasCompanionEndTime     = in_array('end_time', $companionTableCols, true);

/* HANDLE ACTIONS */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $appointment_id = intval($_POST['appointment_id'] ?? 0);
    $action = trim($_POST['action'] ?? '');
    $reason = trim($_POST['rejection_reason'] ?? '');
    $amount_paid_input = floatval($_POST['amount_paid'] ?? 0);
    $additional_services_by_person = $_POST['additional_services_by_person'] ?? [];

    if (!is_array($additional_services_by_person)) {
        $additional_services_by_person = [];
    }

    foreach ($additional_services_by_person as $personKey => $serviceIds) {
        if (!is_array($serviceIds)) {
            $additional_services_by_person[$personKey] = [];
            continue;
        }

        $normalizedIds = array_values(array_unique(array_map('intval', $serviceIds)));
        $normalizedIds = array_values(array_filter($normalizedIds, fn($id) => $id > 0));
        $additional_services_by_person[$personKey] = $normalizedIds;
    }

    if ($appointment_id > 0) {
        $stmtInfo = $conn->prepare("
            SELECT 
                a.id,
                a.start_time,
                a.end_time,
                a.status,
                u.first_name,
                u.last_name,
                u.email,
                b.name AS barber_name,
                GROUP_CONCAT(DISTINCT s.name ORDER BY s.name SEPARATOR ', ') AS services
            FROM appointments a
            JOIN users u ON a.customer_id = u.id
            JOIN barbers b ON a.barber_id = b.id
            JOIN appointment_services aps ON a.id = aps.appointment_id
            JOIN services s ON aps.service_id = s.id
            WHERE a.id = ?
            GROUP BY a.id
            LIMIT 1
        ");
        $stmtInfo->bind_param("i", $appointment_id);
        $stmtInfo->execute();
        $appointmentInfo = $stmtInfo->get_result()->fetch_assoc();
        $stmtInfo->close();

        if ($appointmentInfo) {
            $customerName = trim(($appointmentInfo['first_name'] ?? '') . ' ' . ($appointmentInfo['last_name'] ?? ''));
            $customerEmail = trim($appointmentInfo['email'] ?? '');
            $barberName = $appointmentInfo['barber_name'] ?? 'N/A';
            $services = $appointmentInfo['services'] ?? 'N/A';

            $appointmentDateTime = date("F j, Y g:i A", strtotime($appointmentInfo['start_time']));
            if (!empty($appointmentInfo['end_time'])) {
                $appointmentDateTime .= ' - ' . date("g:i A", strtotime($appointmentInfo['end_time']));
            }

            if ($action === 'approve') {
                $stmt = $conn->prepare("
                    SELECT id, total_amount, downpayment_amount
                    FROM payments
                    WHERE appointment_id = ?
                    ORDER BY created_at DESC, id DESC
                    LIMIT 1
                ");
                $stmt->bind_param("i", $appointment_id);
                $stmt->execute();
                $payment = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if ($payment) {
                    $payment_id = intval($payment['id']);
                    $total_amount = floatval($payment['total_amount']);
                    $downpayment_amount = floatval($payment['downpayment_amount']);

                    if ($amount_paid_input <= 0) {
                        $amount_paid_input = $downpayment_amount;
                    }

                    if ($amount_paid_input > $total_amount) {
                        $amount_paid_input = $total_amount;
                    }

                    $balance_amount = $total_amount - $amount_paid_input;
                    if ($balance_amount < 0) {
                        $balance_amount = 0;
                    }

                    $stmt = $conn->prepare("
                        UPDATE appointments
                        SET status = 'approved', rejection_reason = NULL
                        WHERE id = ?
                    ");
                    $stmt->bind_param("i", $appointment_id);
                    $stmt->execute();
                    $stmt->close();

                    $stmt = $conn->prepare("
                        UPDATE payments
                        SET status = 'verified',
                            amount_paid = ?,
                            balance_amount = ?
                        WHERE id = ?
                    ");
                    $stmt->bind_param("ddi", $amount_paid_input, $balance_amount, $payment_id);
                    $stmt->execute();
                    $stmt->close();

                    if ($customerEmail !== '') {
                        sendAppointmentStatusEmail(
                            $customerEmail,
                            $customerName,
                            'approved',
                            $appointmentDateTime,
                            $barberName,
                            $services,
                            null,
                            $amount_paid_input,
                            $balance_amount
                        );
                    }
                }
            }

            if ($action === 'reject') {
                if ($reason === '') {
                    $reason = 'Payment verification failed.';
                }

                $stmt = $conn->prepare("
                    UPDATE appointments
                    SET status = 'rejected', rejection_reason = ?
                    WHERE id = ?
                ");
                $stmt->bind_param("si", $reason, $appointment_id);
                $stmt->execute();
                $stmt->close();

                $stmt = $conn->prepare("
                    UPDATE payments
                    SET status = 'rejected'
                    WHERE id = (
                        SELECT id FROM (
                            SELECT id
                            FROM payments
                            WHERE appointment_id = ?
                            ORDER BY created_at DESC, id DESC
                            LIMIT 1
                        ) latest_payment
                    )
                ");
                $stmt->bind_param("i", $appointment_id);
                $stmt->execute();
                $stmt->close();

                if ($customerEmail !== '') {
                    sendAppointmentStatusEmail(
                        $customerEmail,
                        $customerName,
                        'rejected',
                        $appointmentDateTime,
                        $barberName,
                        $services,
                        $reason
                    );
                }
            }

            if ($action === 'complete') {
                $conn->begin_transaction();

                try {
                    $stmt = $conn->prepare("
                        SELECT id, total_amount, downpayment_amount, amount_paid, balance_amount
                        FROM payments
                        WHERE appointment_id = ?
                        ORDER BY created_at DESC, id DESC
                        LIMIT 1
                    ");
                    $stmt->bind_param("i", $appointment_id);
                    $stmt->execute();
                    $latestPayment = $stmt->get_result()->fetch_assoc();
                    $stmt->close();

                    $current_total = $latestPayment ? floatval($latestPayment['total_amount']) : 0;
                    $current_downpayment = $latestPayment ? floatval($latestPayment['downpayment_amount']) : 0;
                    $current_paid = $latestPayment ? floatval($latestPayment['amount_paid']) : 0;
                    $current_balance = $latestPayment ? floatval($latestPayment['balance_amount']) : 0;

                    $extra_total = 0;
                    $addedServiceNames = [];

                    $companionState = [];
                    if ($companionTableExists && $hasCompanionPersonNo && $hasCompanionSelected && $hasCompanionTotalPrice && $hasCompanionDuration) {
                        $companionFetchStmt = $conn->prepare("
                            SELECT person_no, selected_services, total_price, total_duration
                            FROM appointment_companions
                            WHERE appointment_id = ?
                        ");
                        $companionFetchStmt->bind_param("i", $appointment_id);
                        $companionFetchStmt->execute();
                        $companionFetchRes = $companionFetchStmt->get_result();

                        while ($compRow = $companionFetchRes->fetch_assoc()) {
                            $selectedIds = json_decode($compRow['selected_services'] ?? '[]', true);
                            if (!is_array($selectedIds)) {
                                $selectedIds = [];
                            }

                            $personNo = (int)($compRow['person_no'] ?? 0);
                            $companionState[$personNo] = [
                                'selected_ids' => array_values(array_unique(array_map('intval', $selectedIds))),
                                'total_price' => (float)($compRow['total_price'] ?? 0),
                                'total_duration' => (int)($compRow['total_duration'] ?? 0)
                            ];
                        }
                        $companionFetchStmt->close();
                    }

                    $insertServiceStmt = $conn->prepare("
                        INSERT INTO appointment_services (
                            appointment_id,
                            service_id,
                            price,
                            downpayment,
                            duration_minutes
                        )
                        VALUES (?, ?, ?, ?, ?)
                    ");

                    foreach ($additional_services_by_person as $personKey => $serviceIds) {
                        $personNo = (int)$personKey;
                        $personLabel = $personNo > 0 ? 'Person ' . $personNo : 'Person 1';

                        foreach ($serviceIds as $service_id) {
                            if ($service_id <= 0 || !isset($serviceCatalog[$service_id])) {
                                continue;
                            }

                            $servicePrice = (float)$serviceCatalog[$service_id]['price'];
                            $serviceDuration = (int)$serviceCatalog[$service_id]['duration_minutes'];
                            $serviceDownpayment = 0.00;

                            $insertServiceStmt->bind_param(
                                "iiddi",
                                $appointment_id,
                                $service_id,
                                $servicePrice,
                                $serviceDownpayment,
                                $serviceDuration
                            );
                            $insertServiceStmt->execute();

                            $extra_total += $servicePrice;
                            $addedServiceNames[] = $serviceCatalog[$service_id]['name'] . ' (' . $personLabel . ')';

                            if ($personNo > 0 && isset($companionState[$personNo])) {
                                if (!in_array($service_id, $companionState[$personNo]['selected_ids'], true)) {
                                    $companionState[$personNo]['selected_ids'][] = $service_id;
                                    $companionState[$personNo]['total_price'] += $servicePrice;
                                    $companionState[$personNo]['total_duration'] += $serviceDuration;
                                }
                            }
                        }
                    }

                    $insertServiceStmt->close();

                    if (!empty($companionState) && $companionTableExists && $hasCompanionPersonNo && $hasCompanionSelected && $hasCompanionTotalPrice && $hasCompanionDuration) {
                        $updateCompanionStmt = $conn->prepare("
                            UPDATE appointment_companions
                            SET selected_services = ?, total_price = ?, total_duration = ?
                            WHERE appointment_id = ? AND person_no = ?
                        ");

                        foreach ($companionState as $personNo => $personState) {
                            $selectedJson = json_encode(array_values(array_unique(array_map('intval', $personState['selected_ids']))));
                            $updatedTotalPrice = (float)$personState['total_price'];
                            $updatedDuration = (int)$personState['total_duration'];
                            $updateCompanionStmt->bind_param(
                                "sdiii",
                                $selectedJson,
                                $updatedTotalPrice,
                                $updatedDuration,
                                $appointment_id,
                                $personNo
                            );
                            $updateCompanionStmt->execute();
                        }

                        $updateCompanionStmt->close();
                    }

                    $new_total = $current_total + $extra_total;
                    $total_to_collect_now = $current_balance + $extra_total;
                    if ($total_to_collect_now < 0) {
                        $total_to_collect_now = 0;
                    }

                    $new_amount_paid = $new_total;
                    $new_balance = 0.00;

                    $methodLabel = 'In-shop Completion';
                    $referenceLabel = 'COMPLETE-' . $appointment_id . '-' . time();

                    $insertPaymentStmt = $conn->prepare("
                        INSERT INTO payments (
                            appointment_id,
                            total_amount,
                            downpayment_amount,
                            amount_paid,
                            balance_amount,
                            reference_number,
                            proof_image,
                            method,
                            status,
                            is_repayment,
                            created_at
                        ) VALUES (?, ?, ?, ?, ?, ?, NULL, ?, 'verified', 1, NOW())
                    ");

                    $insertPaymentStmt->bind_param(
                        "iddddss",
                        $appointment_id,
                        $new_total,
                        $current_downpayment,
                        $new_amount_paid,
                        $new_balance,
                        $referenceLabel,
                        $methodLabel
                    );
                    $insertPaymentStmt->execute();
                    $insertPaymentStmt->close();

                    $stmt = $conn->prepare("
                        UPDATE appointments
                        SET status = 'completed', rejection_reason = NULL
                        WHERE id = ?
                    ");
                    $stmt->bind_param("i", $appointment_id);
                    $stmt->execute();
                    $stmt->close();

                    $conn->commit();

                    if (!empty($addedServiceNames)) {
                        $services .= ', ' . implode(', ', $addedServiceNames);
                    }

                    if ($customerEmail !== '') {
                        sendAppointmentStatusEmail(
                            $customerEmail,
                            $customerName,
                            'completed',
                            $appointmentDateTime,
                            $barberName,
                            $services,
                            null,
                            $new_amount_paid,
                            null
                        );
                    }
                } catch (Exception $e) {
                    $conn->rollback();
                }
            }

            if ($action === 'no_show') {
                $stmt = $conn->prepare("
                    UPDATE appointments
                    SET status = 'no-show', rejection_reason = NULL
                    WHERE id = ?
                ");
                $stmt->bind_param("i", $appointment_id);
                $stmt->execute();
                $stmt->close();

                $stmt = $conn->prepare("
                    SELECT amount_paid, balance_amount
                    FROM payments
                    WHERE appointment_id = ?
                    ORDER BY created_at DESC, id DESC
                    LIMIT 1
                ");
                $stmt->bind_param("i", $appointment_id);
                $stmt->execute();
                $paymentInfo = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if ($customerEmail !== '') {
                    sendAppointmentStatusEmail(
                        $customerEmail,
                        $customerName,
                        'no-show',
                        $appointmentDateTime,
                        $barberName,
                        $services,
                        null,
                        isset($paymentInfo['amount_paid']) ? floatval($paymentInfo['amount_paid']) : null,
                        isset($paymentInfo['balance_amount']) ? floatval($paymentInfo['balance_amount']) : null
                    );
                }
            }

            if ($action === 'cancel') {
                $stmt = $conn->prepare("
                    UPDATE appointments
                    SET status = 'canceled', rejection_reason = NULL
                    WHERE id = ?
                ");
                $stmt->bind_param("i", $appointment_id);
                $stmt->execute();
                $stmt->close();

                if ($customerEmail !== '') {
                    sendAppointmentStatusEmail(
                        $customerEmail,
                        $customerName,
                        'canceled',
                        $appointmentDateTime,
                        $barberName,
                        $services
                    );
                }
            }
        }
    }

    header("Location: staff_appointments.php");
    exit();
}

/* FILTERS */
$search_filter = trim($_GET['search'] ?? '');
$status_filter = trim($_GET['status'] ?? '');
$date_filter = trim($_GET['date'] ?? '');
$showStatusColumn = ($status_filter === '');

$whereParts = [];
$params = [];
$types = '';

if ($status_filter !== '') {
    $whereParts[] = "a.status = ?";
    $params[] = $status_filter;
    $types .= 's';
}

if ($date_filter !== '') {
    $whereParts[] = "DATE(a.start_time) = ?";
    $params[] = $date_filter;
    $types .= 's';
}

if ($search_filter !== '') {
    $searchConditions = [
        "CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,'')) LIKE ?",
        "b.name LIKE ?",
        "p.reference_number LIKE ?",
        "CAST(a.id AS CHAR) LIKE ?"
    ];

    $searchLike = '%' . $search_filter . '%';
    $params[] = $searchLike;
    $params[] = $searchLike;
    $params[] = $searchLike;
    $params[] = $searchLike;
    $types .= 'ssss';

    if ($companionTableExists && $hasCompanionFullName) {
        $searchConditions[] = "EXISTS (
            SELECT 1
            FROM appointment_companions acs
            WHERE acs.appointment_id = a.id
              AND acs.full_name LIKE ?
        )";
        $params[] = $searchLike;
        $types .= 's';
    }

    $whereParts[] = "(" . implode(' OR ', $searchConditions) . ")";
}

$whereSql = '';
if (!empty($whereParts)) {
    $whereSql = 'WHERE ' . implode(' AND ', $whereParts);
}

/* STATS */
$stats = [
    'all' => 0,
    'pending approval' => 0,
    'approved' => 0,
    'completed' => 0,
    'rejected' => 0
];
$statsRes = $conn->query("
    SELECT status, COUNT(*) AS total
    FROM appointments
    GROUP BY status
");
if ($statsRes) {
    while ($r = $statsRes->fetch_assoc()) {
        $stats[strtolower($r['status'])] = (int)$r['total'];
        $stats['all'] += (int)$r['total'];
    }
}

/* APPOINTMENTS QUERY */
$sql = "
    SELECT 
        a.id AS appointment_id,
        a.customer_id,
        a.barber_id,
        a.start_time,
        a.end_time,
        a.status,
        a.rejection_reason,
        u.first_name,
        u.last_name,
        u.email,
        b.name AS barber_name,
        GROUP_CONCAT(DISTINCT s.name ORDER BY s.name SEPARATOR ', ') AS services,
        GROUP_CONCAT(DISTINCT aps.service_id ORDER BY aps.service_id SEPARATOR ',') AS booked_service_ids,
        COALESCE(SUM(aps.duration_minutes), 0) AS total_duration,
        p.id AS payment_id,
        p.total_amount,
        p.downpayment_amount,
        p.amount_paid,
        p.balance_amount,
        p.reference_number,
        p.proof_image,
        p.method,
        p.status AS payment_status
    FROM appointments a
    JOIN users u ON a.customer_id = u.id
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
    $whereSql
    GROUP BY a.id
    ORDER BY a.start_time DESC, a.id DESC
";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

$appointments = [];
$appointmentIds = [];

while ($row = $result->fetch_assoc()) {
    $row['appointment_id'] = (int)$row['appointment_id'];
    $row['customer_id'] = (int)$row['customer_id'];
    $row['barber_id'] = (int)$row['barber_id'];
    $row['total_amount'] = (float)($row['total_amount'] ?? 0);
    $row['downpayment_amount'] = (float)($row['downpayment_amount'] ?? 0);
    $row['amount_paid'] = (float)($row['amount_paid'] ?? 0);
    $row['balance_amount'] = (float)($row['balance_amount'] ?? 0);
    $row['total_duration'] = (int)($row['total_duration'] ?? 0);

    $appointments[] = $row;
    $appointmentIds[] = (int)$row['appointment_id'];
}
$stmt->close();

/* COMPANIONS MAP */
$companionsMap = [];
$addonServiceMap = [];

if ($companionTableExists && !empty($appointmentIds)) {
    $placeholders = implode(',', array_fill(0, count($appointmentIds), '?'));
    $bindTypes = str_repeat('i', count($appointmentIds));

    $selectPersonNo   = $hasCompanionPersonNo   ? "ac.person_no," : "0 AS person_no,";
    $selectFullName   = $hasCompanionFullName   ? "ac.full_name," : "'' AS full_name,";
    $selectServiceId  = $hasCompanionServiceId  ? "ac.service_id," : "0 AS service_id,";
    $selectSelected   = $hasCompanionSelected   ? "ac.selected_services," : "'[]' AS selected_services,";
    $selectPrice      = $hasCompanionTotalPrice ? "ac.total_price," : "0 AS total_price,";
    $selectDuration   = $hasCompanionDuration   ? "ac.total_duration," : "0 AS total_duration,";
    $selectBarberId   = $hasCompanionBarberId   ? "ac.barber_id," : "NULL AS barber_id,";
    $selectStartTime  = $hasCompanionStartTime  ? "ac.start_time," : "NULL AS start_time,";
    $selectEndTime    = $hasCompanionEndTime    ? "ac.end_time," : "NULL AS end_time,";

    $companionSql = "
        SELECT
            ac.appointment_id,
            {$selectPersonNo}
            {$selectFullName}
            {$selectServiceId}
            {$selectSelected}
            {$selectPrice}
            {$selectDuration}
            {$selectBarberId}
            {$selectStartTime}
            {$selectEndTime}
            s.name AS main_service_name,
            b.name AS companion_barber_name
        FROM appointment_companions ac
        LEFT JOIN services s ON " . ($hasCompanionServiceId ? "ac.service_id = s.id" : "1=0") . "
        LEFT JOIN barbers b ON " . ($hasCompanionBarberId ? "ac.barber_id = b.id" : "1=0") . "
        WHERE ac.appointment_id IN ($placeholders)
        ORDER BY ac.appointment_id DESC, " . ($hasCompanionPersonNo ? "ac.person_no ASC" : "ac.appointment_id ASC") . "
    ";

    $companionStmt = $conn->prepare($companionSql);
    $companionStmt->bind_param($bindTypes, ...$appointmentIds);
    $companionStmt->execute();
    $companionResult = $companionStmt->get_result();

    while ($comp = $companionResult->fetch_assoc()) {
        $appointmentId = (int)$comp['appointment_id'];

        $selectedServiceIds = json_decode($comp['selected_services'] ?? '[]', true);
        if (!is_array($selectedServiceIds)) {
            $selectedServiceIds = [];
        }

        $companionsMap[$appointmentId][] = [
            'person_no' => (int)($comp['person_no'] ?? 0),
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
            'end_time' => $comp['end_time'] ?? null
        ];
    }
    $companionStmt->close();

    $allAddonIds = [];
    foreach ($companionsMap as $persons) {
        foreach ($persons as $person) {
            foreach (($person['selected_services_ids'] ?? []) as $sid) {
                $sid = (int)$sid;
                if ($sid > 0 && $sid !== (int)$person['service_id']) {
                    $allAddonIds[] = $sid;
                }
            }
        }
    }

    $allAddonIds = array_values(array_unique($allAddonIds));

    if (!empty($allAddonIds)) {
        $addonPh = implode(',', array_fill(0, count($allAddonIds), '?'));
        $addonTypes = str_repeat('i', count($allAddonIds));

        $addonStmt = $conn->prepare("SELECT id, name FROM services WHERE id IN ($addonPh)");
        $addonStmt->bind_param($addonTypes, ...$allAddonIds);
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
            foreach (($person['selected_services_ids'] ?? []) as $sid) {
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Appointments | <?= htmlspecialchars($shop['shop_name'] ?? 'Barbershop') ?></title>
    <link rel="stylesheet" href="styles.css">
    <style>
        * { box-sizing: border-box; }

        :root {
            --admin-blue: #0b3d91;
            --admin-blue-dark: #061f4a;
            --admin-navy: #0f172a;
            --admin-soft-blue: #eaf1ff;
            --admin-bg: #f7f9fc;
            --admin-line: #dbe3ef;
        }

        body {
            margin: 0;
            font-family: 'Poppins', sans-serif;
            background: #f7f9fc;
            color: #1f2937;
            overflow-x: hidden;
        }

        html { overflow-x: hidden; }
        body.sidebar-open { overflow: hidden; }

        .mobile-topbar {
            display: none;
            width: 100%;
            background: #111;
            color: #fff;
            padding: 14px 16px;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 1200;
        }

        .mobile-topbar strong {
            font-size: 15px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: calc(100vw - 90px);
        }

        .menu-btn {
            width: 42px;
            height: 42px;
            border: none;
            border-radius: 10px;
            background: rgba(255,255,255,0.1);
            color: #fff;
            font-size: 20px;
            cursor: pointer;
            flex-shrink: 0;
        }

        .sidebar-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.55);
            z-index: 1190;
        }

        .sidebar-overlay.show {
            display: block;
        }

        .admin-layout {
            display: flex;
            min-height: 100vh;
        }

        .admin-sidebar {
            width: 260px;
            min-width: 260px;
            background: #111;
            color: #fff;
            padding: 24px 18px;
            position: sticky;
            top: 0;
            height: 100vh;
            overflow-y: auto;
            flex-shrink: 0;
            z-index: 1201;
            transition: transform 0.25s ease;
        }

        .admin-brand {
            text-align: center;
            margin-bottom: 30px;
        }

        .admin-brand img {
            width: 80px;
            height: 80px;
            object-fit: cover;
            border-radius: 50%;
            background: #fff;
            padding: 6px;
            margin-bottom: 10px;
        }

        .admin-brand h2 {
            font-size: 18px;
            margin: 0;
            line-height: 1.4;
        }

        .admin-brand p {
            margin: 6px 0 0;
            color: #bbb;
            font-size: 13px;
        }

        .admin-nav a {
            display: block;
            color: #fff;
            text-decoration: none;
            padding: 12px 14px;
            border-radius: 10px;
            margin-bottom: 8px;
            transition: 0.2s ease;
        }

        .admin-nav a:hover,
        .admin-nav a.active {
            background: #0b3d91;
            box-shadow: 0 10px 22px rgba(11, 61, 145, 0.22);
        }

        .admin-main {
            flex: 1;
            min-width: 0;
            padding: 28px;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 16px;
            margin-bottom: 18px;
            flex-wrap: wrap;
        }

        .page-header h1 {
            margin: 0;
            font-size: 30px;
            color: #111827;
        }

        .page-header p {
            margin: 6px 0 0;
            color: #6b7280;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(5, minmax(150px, 1fr));
            gap: 14px;
            margin-bottom: 18px;
        }

        .stat-card {
            background: #fff;
            border-radius: 18px;
            padding: 18px;
            box-shadow: 0 8px 24px rgba(15, 23, 42, 0.06);
            border: 1px solid #eef2f7;
            border-left: 5px solid #0b3d91;
        }

        .stat-card h3 {
            margin: 0 0 8px;
            font-size: 13px;
            color: #6b7280;
            font-weight: 600;
        }

        .stat-card .value {
            font-size: 28px;
            font-weight: 700;
            color: #111827;
        }

        .toolbar-card,
        .table-card {
            background: #fff;
            border-radius: 22px;
            padding: 18px;
            box-shadow: 0 10px 30px rgba(15, 23, 42, 0.06);
            border: 1px solid #dbe3ef;
        }

        .toolbar-card {
            margin-bottom: 18px;
        }

        .filter-form {
            display: grid;
            grid-template-columns: 1.4fr 0.9fr 0.9fr auto auto;
            gap: 12px;
            align-items: end;
        }

        .filter-group label {
            display: block;
            margin-bottom: 8px;
            font-size: 13px;
            font-weight: 600;
            color: #475569;
        }

        .filter-group input,
        .filter-group select {
            width: 100%;
            padding: 12px 14px;
            border-radius: 12px;
            border: 1px solid #dbe3ee;
            background: #fff;
            font-size: 14px;
            outline: none;
        }

        .filter-group input:focus,
        .filter-group select:focus {
            border-color: #0b3d91;
            box-shadow: 0 0 0 4px rgba(11, 61, 145, 0.10);
        }

        .toolbar-actions {
            display: flex;
            gap: 10px;
            align-items: end;
            flex-wrap: wrap;
        }

        .btn {
            padding: 11px 16px;
            border-radius: 12px;
            border: none;
            cursor: pointer;
            font-weight: 600;
            font-size: 14px;
            transition: 0.18s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .btn:hover {
            transform: translateY(-1px);
        }

        .btn-primary { background: #0b3d91; color: #fff; }
        .btn-light { background: #eef2f7; color: #111827; }
        .btn-view { background: #0f172a; color: #fff; padding: 9px 14px; }
        .btn-approve { background: #166534; color: #fff; }
        .btn-reject { background: #b91c1c; color: #fff; }
        .btn-complete { background: #2563eb; color: #fff; }
        .btn-noshow { background: #7c3aed; color: #fff; }
        .btn-cancel { background: #6b7280; color: #fff; }
        .btn-secondary { background: #e5e7eb; color: #111827; }

        .table-head {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            align-items: center;
            margin-bottom: 14px;
            flex-wrap: wrap;
        }

        .table-head h2 {
            margin: 0;
            font-size: 20px;
            color: #111827;
        }

        .table-head p {
            margin: 4px 0 0;
            color: #6b7280;
            font-size: 14px;
        }

        .table-responsive {
            width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        table {
            width: 100%;
            min-width: <?= $showStatusColumn ? '1160px' : '1060px' ?>;
            border-collapse: separate;
            border-spacing: 0;
        }

        thead th {
            text-align: left;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: #64748b;
            font-weight: 700;
            padding: 14px 14px;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
            position: sticky;
            top: 0;
            z-index: 1;
        }

        tbody td {
            padding: 16px 14px;
            border-bottom: 1px solid #edf2f7;
            vertical-align: top;
            font-size: 14px;
            color: #1f2937;
            background: #fff;
        }

        tbody tr:hover td {
            background: #fcfcfd;
        }

        .customer-cell strong {
            display: block;
            font-size: 14px;
            color: #111827;
        }

        .customer-cell span,
        .muted {
            color: #6b7280;
            font-size: 13px;
        }

        .service-text {
            max-width: 240px;
            white-space: normal;
            word-break: break-word;
            line-height: 1.45;
        }

        .persons-cell {
            text-align: center;
        }

        .persons-pill {
            display: inline-flex;
            min-width: 38px;
            height: 38px;
            align-items: center;
            justify-content: center;
            border-radius: 999px;
            background: #eef2ff;
            color: #312e81;
            font-weight: 700;
            font-size: 14px;
            border: 1px solid #dbe4ff;
        }

        .payment-pill,
        .status-badge,
        .history-status {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 6px 11px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
            white-space: nowrap;
        }

        .status-pending-approval { background: #fff4cc; color: #8a6d00; }
        .status-approved { background: #dcfce7; color: #166534; }
        .status-rejected { background: #fee2e2; color: #991b1b; }
        .status-completed { background: #dbeafe; color: #1d4ed8; }
        .status-no-show { background: #ede9fe; color: #6d28d9; }
        .status-canceled,
        .status-cancelled { background: #e5e7eb; color: #4b5563; }

        .payment-pending { background: #fff4cc; color: #8a6d00; }
        .payment-verified { background: #dcfce7; color: #166534; }
        .payment-rejected { background: #fee2e2; color: #991b1b; }
        .payment-default { background: #e5e7eb; color: #4b5563; }

        .history-pending { background: #fff4cc; color: #8a6d00; }
        .history-verified { background: #dcfce7; color: #166534; }
        .history-rejected { background: #fee2e2; color: #991b1b; }

        .amount-stack strong {
            display: block;
            color: #111827;
        }

        .amount-stack span {
            color: #6b7280;
            font-size: 12px;
        }

        .empty-state {
            background: #fff;
            padding: 42px 24px;
            border-radius: 22px;
            text-align: center;
            color: #6b7280;
            box-shadow: 0 10px 30px rgba(15, 23, 42, 0.06);
            border: 1px solid #dbe3ef;
        }

        .hidden-detail { display: none; }

        .modal-backdrop {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(7, 23, 47, 0.64);
            z-index: 1300;
            padding: 22px;
            overflow-y: auto;
        }

        .modal-backdrop.show {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .modal {
            width: 100%;
            max-width: 1120px;
            background: #fff;
            border-radius: 24px;
            box-shadow: 0 30px 80px rgba(15, 23, 42, 0.2);
            max-height: 92vh;
            overflow-y: auto;
            position: relative;
        }

        .modal-small { max-width: 720px; }

        .modal-header {
            padding: 24px 26px 16px;
            border-bottom: 1px solid #edf2f7;
            position: sticky;
            top: 0;
            background: #fff;
            z-index: 2;
        }

        .modal-header h3 {
            margin: 0;
            font-size: 24px;
            color: #111827;
        }

        .modal-header p {
            margin: 6px 0 0;
            color: #6b7280;
        }

        .modal-body {
            padding: 24px 26px 26px;
        }

        .modal-close {
            position: absolute;
            top: 16px;
            right: 16px;
            width: 40px;
            height: 40px;
            border: none;
            border-radius: 999px;
            background: #f3f4f6;
            font-size: 18px;
            font-weight: 700;
            cursor: pointer;
        }

        .details-modal-grid {
            display: grid;
            grid-template-columns: 1.1fr 0.9fr;
            gap: 18px;
        }

        .details-section {
            background: #f8fafc;
            border: 1px solid #e8eef5;
            border-radius: 18px;
            padding: 18px;
            min-width: 0;
        }

        .details-section h4 {
            margin: 0 0 14px;
            font-size: 16px;
            color: #111827;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }

        .info-item {
            background: #fff;
            border-radius: 14px;
            border: 1px solid #e6edf5;
            padding: 12px 14px;
        }

        .info-item label {
            display: block;
            margin-bottom: 6px;
            color: #6b7280;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }

        .info-item div {
            color: #111827;
            line-height: 1.5;
            word-break: break-word;
        }

        .proof-card img,
        .history-card img {
            width: 100%;
            max-width: 280px;
            border: 1px solid #dbe3ee;
            border-radius: 14px;
            display: block;
            margin-top: 12px;
            background: #fff;
        }

        .history-list {
            display: grid;
            gap: 12px;
        }

        .history-card {
            background: #fff;
            border-radius: 14px;
            border: 1px solid #e6edf5;
            padding: 14px;
        }

        .history-card h5 {
            margin: 0 0 10px;
            font-size: 15px;
            color: #0b3d91;
        }

        .history-card p {
            margin: 6px 0;
            line-height: 1.5;
        }

        .details-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 18px;
        }

        .persons-breakdown-grid {
            display: grid;
            gap: 14px;
        }

        .person-card {
            background: #fff;
            border: 1px solid #e6edf5;
            border-radius: 16px;
            padding: 16px;
        }

        .person-card h5 {
            margin: 0 0 12px;
            font-size: 16px;
            color: #0b3d91;
        }

        .person-card p {
            margin: 6px 0;
            line-height: 1.55;
            word-break: break-word;
        }

        .modal .form-group {
            margin-bottom: 18px;
        }

        .modal label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #374151;
        }

        .modal input[type="text"],
        .modal input[type="number"],
        .modal textarea {
            width: 100%;
            padding: 12px 14px;
            border: 1px solid #d1d9e6;
            border-radius: 12px;
            font-size: 14px;
            outline: none;
        }

        .modal textarea {
            min-height: 120px;
            resize: vertical;
        }

        .modal input:focus,
        .modal textarea:focus {
            border-color: #0b3d91;
            box-shadow: 0 0 0 4px rgba(11, 61, 145, 0.10);
        }

        .modal-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 22px;
        }

        .service-option {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 11px 12px;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            margin-bottom: 10px;
            background: #fff;
        }

        .service-option label {
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 500;
            cursor: pointer;
        }

        .modal-services-list {
            max-height: 260px;
            overflow-y: auto;
            padding-right: 4px;
        }

        .summary-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            padding: 18px;
            margin-top: 16px;
        }

        .summary-box p {
            margin: 0 0 10px;
            line-height: 1.65;
        }

        .summary-box p:last-child {
            margin-bottom: 0;
        }

        .complete-persons-container {
            display: grid;
            gap: 14px;
        }

        .complete-person-card {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            padding: 14px;
        }

        .complete-person-card h4 {
            margin: 0 0 12px;
            font-size: 15px;
            color: #111827;
        }

        @media (max-width: 1200px) {
            .stats-grid {
                grid-template-columns: repeat(3, minmax(150px, 1fr));
            }
        }

        @media (max-width: 1024px) {
            .filter-form {
                grid-template-columns: 1fr 1fr;
            }

            .toolbar-actions {
                grid-column: 1 / -1;
            }

            .details-modal-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 900px) {
            .mobile-topbar {
                display: flex;
            }

            .admin-layout {
                display: block;
            }

            .admin-sidebar {
                position: fixed;
                top: 0;
                left: 0;
                height: 100vh;
                width: min(82vw, 300px);
                min-width: min(82vw, 300px);
                max-width: 300px;
                transform: translateX(-105%);
                box-shadow: 0 24px 50px rgba(0,0,0,0.28);
            }

            .admin-sidebar.show {
                transform: translateX(0);
            }

            .admin-main {
                padding: 18px;
            }

            .stats-grid {
                grid-template-columns: repeat(2, minmax(150px, 1fr));
            }
        }

        @media (max-width: 640px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }

            .filter-form {
                grid-template-columns: 1fr;
            }

            .table-card,
            .toolbar-card {
                padding: 14px;
            }

            .modal-header,
            .modal-body {
                padding-left: 16px;
                padding-right: 16px;
            }

            .info-grid {
                grid-template-columns: 1fr;
            }

            .details-actions,
            .modal-actions {
                flex-direction: column;
            }

            .btn {
                width: 100%;
            }

            .modal-backdrop {
                padding: 10px;
            }
        }
    </style>
</head>
<body>

<div class="mobile-topbar">
    <button type="button" class="menu-btn" id="menuBtn">☰</button>
    <strong><?= htmlspecialchars($shop['shop_name'] ?? 'Barbershop') ?></strong>
</div>

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<div class="admin-layout">
    <aside class="admin-sidebar" id="adminSidebar">
        <div class="admin-brand">
            <img src="images/<?= htmlspecialchars($shop['logo'] ?? 'logo.png') ?>" alt="Shop Logo">
            <h2><?= htmlspecialchars($shop['shop_name'] ?? 'Barbershop') ?></h2>
            <p>Staff Portal</p>
        </div>

        <nav class="admin-nav">
            <a href="staff_dashboard.php">Dashboard</a>
            <a href="staff_appointments.php" class="active">Appointments</a>
            <a href="staff_walkins.php">Walk-in Queue</a>
            <a href="admin_logout.php">Logout</a>
        </nav>
    </aside>

    <main class="admin-main">
        <div class="page-header">
            <div>
                <h1>Manage Appointments</h1>
                <p>Review bookings, verify payments, and manage appointment lifecycle in one place.</p>
            </div>
        </div>

        <section class="stats-grid">
            <div class="stat-card">
                <h3>Total Appointments</h3>
                <div class="value"><?= (int)$stats['all'] ?></div>
            </div>
            <div class="stat-card">
                <h3>Pending Approval</h3>
                <div class="value"><?= (int)($stats['pending approval'] ?? 0) ?></div>
            </div>
            <div class="stat-card">
                <h3>Approved</h3>
                <div class="value"><?= (int)($stats['approved'] ?? 0) ?></div>
            </div>
            <div class="stat-card">
                <h3>Completed</h3>
                <div class="value"><?= (int)($stats['completed'] ?? 0) ?></div>
            </div>
            <div class="stat-card">
                <h3>Rejected</h3>
                <div class="value"><?= (int)($stats['rejected'] ?? 0) ?></div>
            </div>
        </section>

        <section class="toolbar-card">
            <form method="GET" class="filter-form">
                <div class="filter-group">
                    <label for="search">Search</label>
                    <input
                        type="text"
                        name="search"
                        id="search"
                        value="<?= htmlspecialchars($search_filter) ?>"
                        placeholder="Customer, companion name, barber, reference number, or appointment ID"
                    >
                </div>

                <div class="filter-group">
                    <label for="status">Status</label>
                    <select name="status" id="status">
                        <option value="">All Status</option>
                        <option value="pending approval" <?= $status_filter === 'pending approval' ? 'selected' : '' ?>>Pending Approval</option>
                        <option value="approved" <?= $status_filter === 'approved' ? 'selected' : '' ?>>Approved</option>
                        <option value="rejected" <?= $status_filter === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                        <option value="completed" <?= $status_filter === 'completed' ? 'selected' : '' ?>>Completed</option>
                        <option value="no-show" <?= $status_filter === 'no-show' ? 'selected' : '' ?>>No-Show</option>
                        <option value="canceled" <?= $status_filter === 'canceled' ? 'selected' : '' ?>>Canceled</option>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="date">Date</label>
                    <input type="date" name="date" id="date" value="<?= htmlspecialchars($date_filter) ?>">
                </div>

                <div class="toolbar-actions">
                    <button type="submit" class="btn btn-primary">Apply Filters</button>
                    <a href="staff_appointments.php" class="btn btn-light">Reset</a>
                </div>
            </form>
        </section>

        <section class="table-card">
            <div class="table-head">
                <div>
                    <h2>Appointments Table</h2>
                    <p>Click “View Details” to inspect payment, proof, status, and person breakdown.</p>
                </div>
            </div>

            <?php if (!empty($appointments)): ?>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th style="width: 105px;">Appointment ID</th>
                                <th>Customer</th>
                                <th>Persons</th>
                                <th>Services</th>
                                <th>Barber</th>
                                <th>Schedule</th>
                                <th>Payment</th>
                                <?php if ($showStatusColumn): ?>
                                    <th>Status</th>
                                <?php endif; ?>
                                <th style="width: 130px;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($appointments as $row): ?>
                                <?php
                                $appointmentId = (int)$row['appointment_id'];
                                $companions = $companionsMap[$appointmentId] ?? [];
                                $totalPersons = !empty($companions) ? count($companions) : 1;

                                $statusClass = 'status-' . str_replace(' ', '-', strtolower($row['status']));
                                $paymentClass = 'payment-' . strtolower(trim((string)($row['payment_status'] ?? 'default')));
                                if (!in_array($paymentClass, ['payment-pending', 'payment-verified', 'payment-rejected'])) {
                                    $paymentClass = 'payment-default';
                                }

                                $customerName = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
                                $scheduleText = formatScheduleRange($row['start_time'], $row['end_time']);

                                $tableBarbers = [];
                                if (!empty($companions)) {
                                    foreach ($companions as $person) {
                                        $barberDisplay = trim((string)($person['barber_name'] ?? ''));
                                        if ($barberDisplay !== '') {
                                            $tableBarbers[] = $barberDisplay;
                                        }
                                    }
                                }

                                $tableBarbers = array_values(array_unique($tableBarbers));
                                $tableBarberText = !empty($tableBarbers)
                                    ? implode(', ', $tableBarbers)
                                    : ($row['barber_name'] ?: 'N/A');

                                $paymentHistoryRows = [];
                                $paymentHistoryStmt = $conn->prepare("
                                    SELECT 
                                        id,
                                        total_amount,
                                        downpayment_amount,
                                        amount_paid,
                                        balance_amount,
                                        method,
                                        reference_number,
                                        proof_image,
                                        status,
                                        is_repayment,
                                        created_at
                                    FROM payments
                                    WHERE appointment_id = ?
                                    ORDER BY created_at DESC, id DESC
                                ");
                                $paymentHistoryStmt->bind_param("i", $appointmentId);
                                $paymentHistoryStmt->execute();
                                $paymentHistoryRes = $paymentHistoryStmt->get_result();
                                while ($pr = $paymentHistoryRes->fetch_assoc()) {
                                    $paymentHistoryRows[] = $pr;
                                }
                                $paymentHistoryStmt->close();

                                $splitServices = splitMainAndAddons((string)($row['services'] ?? ''));
                                $completePersonsPayload = [];

                                if (!empty($companions)) {
                                    foreach ($companions as $person) {
                                        $personNo = (int)($person['person_no'] ?? 0);
                                        $personNo = $personNo > 0 ? $personNo : 1;

                                        $bookedIds = [];
                                        if (!empty($person['service_id'])) {
                                            $bookedIds[] = (int)$person['service_id'];
                                        }
                                        foreach (($person['selected_services_ids'] ?? []) as $sid) {
                                            $sid = (int)$sid;
                                            if ($sid > 0) {
                                                $bookedIds[] = $sid;
                                            }
                                        }

                                        $completePersonsPayload[] = [
                                            'person_no' => $personNo,
                                            'label' => 'Person ' . $personNo . (!empty($person['full_name']) ? ' - ' . $person['full_name'] : ''),
                                            'booked_service_ids' => array_values(array_unique($bookedIds))
                                        ];
                                    }
                                } else {
                                    $bookedIds = [];
                                    foreach (explode(',', (string)($row['booked_service_ids'] ?? '')) as $sid) {
                                        $sid = (int)trim($sid);
                                        if ($sid > 0) {
                                            $bookedIds[] = $sid;
                                        }
                                    }

                                    $completePersonsPayload[] = [
                                        'person_no' => 0,
                                        'label' => 'Person 1 - ' . $customerName,
                                        'booked_service_ids' => array_values(array_unique($bookedIds))
                                    ];
                                }

                                ob_start();
                                ?>
                                <div class="details-modal-grid">
                                    <div class="details-section">
                                        <h4>Appointment Information</h4>
                                        <div class="info-grid">
                                            <div class="info-item">
                                                <label>Appointment ID</label>
                                                <div><?= htmlspecialchars(formatAppointmentRef($appointmentId)) ?></div>
                                            </div>
                                            <div class="info-item">
                                                <label>Status</label>
                                                <div><span class="status-badge <?= htmlspecialchars($statusClass) ?>"><?= htmlspecialchars(statusLabel($row['status'])) ?></span></div>
                                            </div>
                                            <div class="info-item">
                                                <label>Customer</label>
                                                <div><?= htmlspecialchars($customerName) ?></div>
                                            </div>
                                            <div class="info-item">
                                                <label>Email</label>
                                                <div><?= htmlspecialchars($row['email'] ?? 'N/A') ?></div>
                                            </div>
                                            <div class="info-item">
                                                <label>No. of Persons</label>
                                                <div><?= (int)$totalPersons ?></div>
                                            </div>
                                            <div class="info-item">
                                                <label>Barber</label>
                                                <div><?= htmlspecialchars($tableBarberText) ?></div>
                                            </div>
                                            <div class="info-item">
                                                <label>Schedule</label>
                                                <div><?= htmlspecialchars($scheduleText) ?></div>
                                            </div>
                                            <div class="info-item">
                                                <label>Booked Services</label>
                                                <div><?= htmlspecialchars($row['services'] ?: 'No services found') ?></div>
                                            </div>
                                            <div class="info-item">
                                                <label>Total Amount</label>
                                                <div>₱<?= number_format((float)$row['total_amount'], 2) ?></div>
                                            </div>
                                            <div class="info-item">
                                                <label>Downpayment Required</label>
                                                <div>₱<?= number_format((float)$row['downpayment_amount'], 2) ?></div>
                                            </div>
                                            <div class="info-item">
                                                <label>Amount Paid</label>
                                                <div>₱<?= number_format((float)$row['amount_paid'], 2) ?></div>
                                            </div>
                                            <div class="info-item">
                                                <label>Balance</label>
                                                <div>₱<?= number_format((float)$row['balance_amount'], 2) ?></div>
                                            </div>
                                            <div class="info-item">
                                                <label>Payment Method</label>
                                                <div><?= htmlspecialchars(!empty($row['method']) ? $row['method'] : 'N/A') ?></div>
                                            </div>
                                            <div class="info-item">
                                                <label>Reference Number</label>
                                                <div><?= htmlspecialchars(!empty($row['reference_number']) ? $row['reference_number'] : 'N/A') ?></div>
                                            </div>
                                            <div class="info-item">
                                                <label>Payment Status</label>
                                                <div>
                                                    <span class="payment-pill <?= htmlspecialchars($paymentClass) ?>">
                                                        <?= htmlspecialchars(paymentStatusLabel((string)($row['payment_status'] ?? 'N/A'))) ?>
                                                    </span>
                                                </div>
                                            </div>
                                            <?php if (!empty($row['rejection_reason'])): ?>
                                                <div class="info-item" style="grid-column: 1 / -1;">
                                                    <label>Rejection Reason</label>
                                                    <div><?= htmlspecialchars($row['rejection_reason']) ?></div>
                                                </div>
                                            <?php endif; ?>
                                        </div>

                                        <div class="details-actions">
                                            <?php if (strtolower($row['status']) === 'pending approval'): ?>
                                                <button
                                                    type="button"
                                                    class="btn btn-approve open-approve-modal"
                                                    data-appointment-id="<?= $appointmentId ?>"
                                                    data-customer="<?= htmlspecialchars($customerName, ENT_QUOTES) ?>"
                                                    data-default-amount="<?= number_format((float)$row['downpayment_amount'], 2, '.', '') ?>"
                                                >
                                                    Approve
                                                </button>

                                                <button
                                                    type="button"
                                                    class="btn btn-reject open-reject-modal"
                                                    data-appointment-id="<?= $appointmentId ?>"
                                                    data-customer="<?= htmlspecialchars($customerName, ENT_QUOTES) ?>"
                                                >
                                                    Reject
                                                </button>
                                            <?php elseif (strtolower($row['status']) === 'approved'): ?>
                                                <button
                                                    type="button"
                                                    class="btn btn-complete open-complete-modal"
                                                    data-appointment-id="<?= $appointmentId ?>"
                                                    data-customer="<?= htmlspecialchars($customerName, ENT_QUOTES) ?>"
                                                    data-current-total="<?= number_format((float)$row['total_amount'], 2, '.', '') ?>"
                                                    data-current-paid="<?= number_format((float)$row['amount_paid'], 2, '.', '') ?>"
                                                    data-current-balance="<?= number_format((float)$row['balance_amount'], 2, '.', '') ?>"
                                                    data-persons-json="<?= htmlspecialchars(json_encode($completePersonsPayload), ENT_QUOTES) ?>"
                                                >
                                                    Complete
                                                </button>

                                                <button
                                                    type="button"
                                                    class="btn btn-noshow open-noshow-modal"
                                                    data-appointment-id="<?= $appointmentId ?>"
                                                    data-customer="<?= htmlspecialchars($customerName, ENT_QUOTES) ?>"
                                                >
                                                    No-Show
                                                </button>

                                                <button
                                                    type="button"
                                                    class="btn btn-cancel open-cancel-modal"
                                                    data-appointment-id="<?= $appointmentId ?>"
                                                    data-customer="<?= htmlspecialchars($customerName, ENT_QUOTES) ?>"
                                                >
                                                    Cancel
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <div class="details-section proof-card">
                                        <h4>Latest Proof of Payment</h4>
                                        <p><strong>Method:</strong> <?= htmlspecialchars(!empty($row['method']) ? $row['method'] : 'N/A') ?></p>
                                        <p><strong>Reference:</strong> <?= htmlspecialchars(!empty($row['reference_number']) ? $row['reference_number'] : 'N/A') ?></p>
                                        <p><strong>Status:</strong> 
                                            <span class="payment-pill <?= htmlspecialchars($paymentClass) ?>">
                                                <?= htmlspecialchars(paymentStatusLabel((string)($row['payment_status'] ?? 'N/A'))) ?>
                                            </span>
                                        </p>

                                        <?php if (!empty($row['proof_image'])): ?>
                                            <img src="uploads/payment_proofs/<?= htmlspecialchars($row['proof_image']) ?>" alt="Payment Proof">
                                        <?php else: ?>
                                            <p class="muted">No proof image uploaded.</p>
                                        <?php endif; ?>
                                    </div>

                                    <div class="details-section" style="grid-column: 1 / -1;">
                                        <h4>Persons Breakdown</h4>

                                        <div class="persons-breakdown-grid">
                                            <?php if (!empty($companions)): ?>
                                                <?php foreach ($companions as $person): ?>
                                                    <?php
                                                    $personNo = (int)($person['person_no'] ?? 0);
                                                    $personNo = $personNo > 0 ? $personNo : 1;

                                                    $personBarber = !empty($person['barber_name']) ? $person['barber_name'] : ($row['barber_name'] ?? 'N/A');

                                                    $personSchedule = (!empty($person['start_time']) || !empty($person['end_time']))
                                                        ? formatScheduleRange($person['start_time'], $person['end_time'])
                                                        : $scheduleText;

                                                    $personMain = $person['main_service_name'] ?: 'N/A';
                                                    $personAddons = !empty($person['addon_names']) ? implode(', ', $person['addon_names']) : 'None';
                                                    ?>
                                                    <div class="person-card">
                                                        <h5>Person <?= $personNo ?></h5>
                                                        <?php if (!empty($person['full_name'])): ?>
                                                            <p><strong>Name:</strong> <?= htmlspecialchars($person['full_name']) ?></p>
                                                        <?php endif; ?>
                                                        <p><strong>Main Service:</strong> <?= htmlspecialchars($personMain) ?></p>
                                                        <p><strong>Add-on Services:</strong> <?= htmlspecialchars($personAddons) ?></p>
                                                        <p><strong>Barber:</strong> <?= htmlspecialchars($personBarber) ?></p>
                                                        <p><strong>Schedule:</strong> <?= htmlspecialchars($personSchedule) ?></p>
                                                        <p><strong>Subtotal:</strong> ₱<?= number_format((float)$person['total_price'], 2) ?></p>
                                                    </div>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <div class="person-card">
                                                    <h5>Person 1</h5>
                                                    <p><strong>Name:</strong> <?= htmlspecialchars($customerName) ?></p>
                                                    <p><strong>Main Service:</strong> <?= htmlspecialchars($splitServices['main_service']) ?></p>
                                                    <p><strong>Add-on Services:</strong> <?= htmlspecialchars(!empty($splitServices['addons']) ? implode(', ', $splitServices['addons']) : 'None') ?></p>
                                                    <p><strong>Barber:</strong> <?= htmlspecialchars($row['barber_name'] ?: 'N/A') ?></p>
                                                    <p><strong>Schedule:</strong> <?= htmlspecialchars($scheduleText) ?></p>
                                                    <p><strong>Subtotal:</strong> ₱<?= number_format((float)$row['total_amount'], 2) ?></p>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <div class="details-section" style="grid-column: 1 / -1;">
                                        <h4>Payment History</h4>
                                        <?php if (!empty($paymentHistoryRows)): ?>
                                            <div class="history-list">
                                                <?php foreach ($paymentHistoryRows as $paymentRow): ?>
                                                    <?php
                                                    $historyClass = 'history-' . strtolower($paymentRow['status'] ?? '');
                                                    if (!in_array($historyClass, ['history-pending', 'history-verified', 'history-rejected'])) {
                                                        $historyClass = 'history-pending';
                                                    }
                                                    ?>
                                                    <div class="history-card">
                                                        <h5><?= intval($paymentRow['is_repayment']) === 1 ? 'Repayment / Updated Payment' : 'Initial Payment' ?></h5>
                                                        <p><strong>Submitted:</strong> <?= date("F j, Y g:i A", strtotime($paymentRow['created_at'])) ?></p>
                                                        <p><strong>Status:</strong> <span class="history-status <?= htmlspecialchars($historyClass) ?>"><?= htmlspecialchars(paymentStatusLabel($paymentRow['status'] ?? '')) ?></span></p>
                                                        <p><strong>Method:</strong> <?= htmlspecialchars(!empty($paymentRow['method']) ? $paymentRow['method'] : 'N/A') ?></p>
                                                        <p><strong>Reference Number:</strong> <?= htmlspecialchars($paymentRow['reference_number'] ?? 'N/A') ?></p>
                                                        <p><strong>Total Amount:</strong> ₱<?= number_format((float)$paymentRow['total_amount'], 2) ?></p>
                                                        <p><strong>Required Downpayment:</strong> ₱<?= number_format((float)$paymentRow['downpayment_amount'], 2) ?></p>
                                                        <p><strong>Amount Paid:</strong> ₱<?= number_format((float)$paymentRow['amount_paid'], 2) ?></p>
                                                        <p><strong>Balance:</strong> ₱<?= number_format((float)$paymentRow['balance_amount'], 2) ?></p>
                                                        <?php if (!empty($paymentRow['proof_image'])): ?>
                                                            <img src="uploads/payment_proofs/<?= htmlspecialchars($paymentRow['proof_image']) ?>" alt="Payment Proof">
                                                        <?php else: ?>
                                                            <p class="muted">No proof image uploaded.</p>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php else: ?>
                                            <p class="muted">No payment history found.</p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php
                                $modalContent = ob_get_clean();
                                ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars(formatAppointmentRef($appointmentId)) ?></strong></td>

                                    <td class="customer-cell">
                                        <strong><?= htmlspecialchars($customerName) ?></strong>
                                        <span><?= htmlspecialchars(formatAppointmentRef($appointmentId)) ?></span>
                                    </td>

                                    <td class="persons-cell">
                                        <span class="persons-pill"><?= (int)$totalPersons ?></span>
                                    </td>

                                    <td>
                                        <div class="service-text"><?= htmlspecialchars($row['services'] ?: 'No services found') ?></div>
                                    </td>

                                    <td>
                                        <div class="service-text"><?= htmlspecialchars($tableBarberText) ?></div>
                                    </td>

                                    <td>
                                        <div class="service-text"><?= htmlspecialchars($scheduleText) ?></div>
                                    </td>

                                    <td>
                                        <div class="amount-stack">
                                            <strong>₱<?= number_format((float)$row['amount_paid'], 2) ?></strong>
                                            <span>Bal: ₱<?= number_format((float)$row['balance_amount'], 2) ?></span>
                                        </div>
                                    </td>

                                    <?php if ($showStatusColumn): ?>
                                        <td>
                                            <span class="status-badge <?= htmlspecialchars($statusClass) ?>">
                                                <?= htmlspecialchars(statusLabel($row['status'])) ?>
                                            </span>
                                        </td>
                                    <?php endif; ?>

                                    <td>
                                        <button
                                            type="button"
                                            class="btn btn-view open-details-modal"
                                            data-title="<?= htmlspecialchars($customerName . ' • ' . formatAppointmentRef($appointmentId), ENT_QUOTES) ?>"
                                            data-subtitle="<?= htmlspecialchars($scheduleText, ENT_QUOTES) ?>"
                                            data-content-id="detail-content-<?= $appointmentId ?>"
                                        >
                                            View Details
                                        </button>

                                        <div class="hidden-detail" id="detail-content-<?= $appointmentId ?>">
                                            <?= $modalContent ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <h3 style="margin-top:0; color:#111827;">No appointments found</h3>
                    <p>Try changing your filters to see matching appointment records.</p>
                </div>
            <?php endif; ?>
        </section>
    </main>
</div>

<div class="modal-backdrop" id="detailsModalBackdrop">
    <div class="modal">
        <div class="modal-header">
            <h3 id="detailsModalTitle">Appointment Details</h3>
            <p id="detailsModalSubtitle"></p>
            <button type="button" class="modal-close" data-close-modal="detailsModalBackdrop">&times;</button>
        </div>
        <div class="modal-body" id="detailsModalBody"></div>
    </div>
</div>

<div class="modal-backdrop" id="approveModalBackdrop">
    <div class="modal modal-small">
        <div class="modal-header">
            <h3>Approve Appointment</h3>
            <p>Enter the amount already paid before approval.</p>
            <button type="button" class="modal-close" data-close-modal="approveModalBackdrop">&times;</button>
        </div>
        <div class="modal-body">
            <form method="POST">
                <input type="hidden" name="action" value="approve">
                <input type="hidden" name="appointment_id" id="approveAppointmentId">

                <div class="form-group">
                    <label for="approveCustomerName">Customer</label>
                    <input type="text" id="approveCustomerName" readonly>
                </div>

                <div class="form-group">
                    <label for="approveAmountPaid">Amount Paid</label>
                    <input type="number" step="0.01" min="0" name="amount_paid" id="approveAmountPaid" required>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" data-close-modal="approveModalBackdrop">Close</button>
                    <button type="submit" class="btn btn-approve">Approve Appointment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal-backdrop" id="rejectModalBackdrop">
    <div class="modal modal-small">
        <div class="modal-header">
            <h3>Reject Appointment</h3>
            <p>Provide a reason for rejecting this appointment.</p>
            <button type="button" class="modal-close" data-close-modal="rejectModalBackdrop">&times;</button>
        </div>
        <div class="modal-body">
            <form method="POST">
                <input type="hidden" name="action" value="reject">
                <input type="hidden" name="appointment_id" id="rejectAppointmentId">

                <div class="form-group">
                    <label for="rejectCustomerName">Customer</label>
                    <input type="text" id="rejectCustomerName" readonly>
                </div>

                <div class="form-group">
                    <label for="rejectReason">Rejection Reason</label>
                    <textarea name="rejection_reason" id="rejectReason" placeholder="Enter rejection reason" required></textarea>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" data-close-modal="rejectModalBackdrop">Close</button>
                    <button type="submit" class="btn btn-reject">Reject Appointment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal-backdrop" id="completeModalBackdrop">
    <div class="modal modal-small">
        <div class="modal-header">
            <h3>Complete Appointment</h3>
            <p>Add any extra services per person. Completing the appointment means the customer is fully paid.</p>
            <button type="button" class="modal-close" data-close-modal="completeModalBackdrop">&times;</button>
        </div>
        <div class="modal-body">
            <form method="POST">
                <input type="hidden" name="action" value="complete">
                <input type="hidden" name="appointment_id" id="completeAppointmentId">

                <div class="form-group">
                    <label for="completeCustomerName">Customer</label>
                    <input type="text" id="completeCustomerName" readonly>
                </div>

                <div class="form-group">
                    <label>Additional Services Per Person</label>
                    <div id="completePersonsServicesContainer"></div>
                </div>

                <div class="summary-box">
                    <p><strong>Current Remaining Balance:</strong> ₱<span id="currentBalanceText">0.00</span></p>
                    <p><strong>Additional Services Total:</strong> ₱<span id="extraServicesTotalText">0.00</span></p>
                    <p><strong>Total to Collect Now:</strong> ₱<span id="collectNowText">0.00</span></p>
                    <p><strong>New Total Amount:</strong> ₱<span id="newTotalText">0.00</span></p>
                    <p><strong>Final Amount Paid:</strong> ₱<span id="updatedPaidText">0.00</span></p>
                    <p><strong>Final Balance:</strong> ₱<span id="updatedBalanceText">0.00</span></p>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" data-close-modal="completeModalBackdrop">Close</button>
                    <button type="submit" class="btn btn-complete">Complete Appointment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal-backdrop" id="noShowModalBackdrop">
    <div class="modal modal-small">
        <div class="modal-header">
            <h3>Mark as No-Show</h3>
            <p>This will mark the appointment as no-show.</p>
            <button type="button" class="modal-close" data-close-modal="noShowModalBackdrop">&times;</button>
        </div>
        <div class="modal-body">
            <form method="POST">
                <input type="hidden" name="action" value="no_show">
                <input type="hidden" name="appointment_id" id="noShowAppointmentId">

                <div class="form-group">
                    <label for="noShowCustomerName">Customer</label>
                    <input type="text" id="noShowCustomerName" readonly>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" data-close-modal="noShowModalBackdrop">Close</button>
                    <button type="submit" class="btn btn-noshow">Confirm No-Show</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal-backdrop" id="cancelModalBackdrop">
    <div class="modal modal-small">
        <div class="modal-header">
            <h3>Cancel Appointment</h3>
            <p>This will cancel the appointment.</p>
            <button type="button" class="modal-close" data-close-modal="cancelModalBackdrop">&times;</button>
        </div>
        <div class="modal-body">
            <form method="POST">
                <input type="hidden" name="action" value="cancel">
                <input type="hidden" name="appointment_id" id="cancelAppointmentId">

                <div class="form-group">
                    <label for="cancelCustomerName">Customer</label>
                    <input type="text" id="cancelCustomerName" readonly>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" data-close-modal="cancelModalBackdrop">Close</button>
                    <button type="submit" class="btn btn-cancel">Confirm Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openModal(id) {
    document.getElementById(id).classList.add('show');
}

function closeModal(id) {
    document.getElementById(id).classList.remove('show');
}

function openSidebar() {
    document.getElementById('adminSidebar').classList.add('show');
    document.getElementById('sidebarOverlay').classList.add('show');
    document.body.classList.add('sidebar-open');
}

function closeSidebar() {
    document.getElementById('adminSidebar').classList.remove('show');
    document.getElementById('sidebarOverlay').classList.remove('show');
    document.body.classList.remove('sidebar-open');
}

const menuBtn = document.getElementById('menuBtn');
const sidebarOverlay = document.getElementById('sidebarOverlay');

if (menuBtn) {
    menuBtn.addEventListener('click', openSidebar);
}

if (sidebarOverlay) {
    sidebarOverlay.addEventListener('click', closeSidebar);
}

window.addEventListener('resize', function () {
    if (window.innerWidth > 900) {
        closeSidebar();
    }
});

document.addEventListener('click', function (e) {
    const closeBtn = e.target.closest('[data-close-modal]');
    if (closeBtn) {
        closeModal(closeBtn.getAttribute('data-close-modal'));
        return;
    }

    const detailBtn = e.target.closest('.open-details-modal');
    if (detailBtn) {
        const contentId = detailBtn.getAttribute('data-content-id');
        const contentNode = document.getElementById(contentId);
        document.getElementById('detailsModalTitle').textContent = detailBtn.getAttribute('data-title') || 'Appointment Details';
        document.getElementById('detailsModalSubtitle').textContent = detailBtn.getAttribute('data-subtitle') || '';
        document.getElementById('detailsModalBody').innerHTML = contentNode ? contentNode.innerHTML : '';
        openModal('detailsModalBackdrop');
        return;
    }

    const approveBtn = e.target.closest('.open-approve-modal');
    if (approveBtn) {
        document.getElementById('approveAppointmentId').value = approveBtn.dataset.appointmentId || '';
        document.getElementById('approveCustomerName').value = approveBtn.dataset.customer || '';
        document.getElementById('approveAmountPaid').value = approveBtn.dataset.defaultAmount || '0.00';
        openModal('approveModalBackdrop');
        return;
    }

    const rejectBtn = e.target.closest('.open-reject-modal');
    if (rejectBtn) {
        document.getElementById('rejectAppointmentId').value = rejectBtn.dataset.appointmentId || '';
        document.getElementById('rejectCustomerName').value = rejectBtn.dataset.customer || '';
        document.getElementById('rejectReason').value = '';
        openModal('rejectModalBackdrop');
        return;
    }

    const noShowBtn = e.target.closest('.open-noshow-modal');
    if (noShowBtn) {
        document.getElementById('noShowAppointmentId').value = noShowBtn.dataset.appointmentId || '';
        document.getElementById('noShowCustomerName').value = noShowBtn.dataset.customer || '';
        openModal('noShowModalBackdrop');
        return;
    }

    const cancelBtn = e.target.closest('.open-cancel-modal');
    if (cancelBtn) {
        document.getElementById('cancelAppointmentId').value = cancelBtn.dataset.appointmentId || '';
        document.getElementById('cancelCustomerName').value = cancelBtn.dataset.customer || '';
        openModal('cancelModalBackdrop');
        return;
    }

    const completeBtn = e.target.closest('.open-complete-modal');
    if (completeBtn) {
        document.getElementById('completeAppointmentId').value = completeBtn.dataset.appointmentId || '';
        document.getElementById('completeCustomerName').value = completeBtn.dataset.customer || '';

        currentTotal = parseFloat(completeBtn.dataset.currentTotal || 0);
        currentPaid = parseFloat(completeBtn.dataset.currentPaid || 0);
        currentBalance = parseFloat(completeBtn.dataset.currentBalance || 0);

        try {
            currentCompletePersons = JSON.parse(completeBtn.dataset.personsJson || '[]');
        } catch (error) {
            currentCompletePersons = [];
        }

        renderCompletePersonsServices(currentCompletePersons);
        updateCompleteSummary();

        openModal('completeModalBackdrop');
        return;
    }
});

document.querySelectorAll('.modal-backdrop').forEach(backdrop => {
    backdrop.addEventListener('click', function (e) {
        if (e.target === this) {
            this.classList.remove('show');
        }
    });
});

const serviceCatalog = <?= json_encode(array_values($serviceCatalog)) ?>;
const completePersonsServicesContainer = document.getElementById('completePersonsServicesContainer');

let currentTotal = 0;
let currentPaid = 0;
let currentBalance = 0;
let currentCompletePersons = [];

function renderCompletePersonsServices(persons) {
    if (!completePersonsServicesContainer) return;

    if (!Array.isArray(persons) || !persons.length) {
        completePersonsServicesContainer.innerHTML = '<p>No person breakdown found.</p>';
        return;
    }

    const catalogHtml = serviceCatalog.length
        ? serviceCatalog.map(service => `
            <div class="service-option" data-service-id="${service.id}">
                <label>
                    <input
                        type="checkbox"
                        name="additional_services_by_person[__PERSON_NO__][]"
                        value="${service.id}"
                        class="additional-service-checkbox"
                        data-price="${Number(service.price || 0).toFixed(2)}"
                    >
                    ${service.name}
                </label>
                <strong>₱${Number(service.price || 0).toFixed(2)}</strong>
            </div>
        `).join('')
        : '<p>No services found.</p>';

    completePersonsServicesContainer.innerHTML = `
        <div class="complete-persons-container">
            ${persons.map(person => {
                const bookedIds = Array.isArray(person.booked_service_ids) ? person.booked_service_ids.map(id => String(id)) : [];
                const personNo = Number(person.person_no || 0);
                return `
                    <div class="complete-person-card" data-person-no="${personNo}" data-booked-service-ids="${bookedIds.join(',')}">
                        <h4>${person.label || ('Person ' + (personNo || 1))}</h4>
                        <div class="modal-services-list">
                            ${catalogHtml.replaceAll('__PERSON_NO__', String(personNo))}
                        </div>
                    </div>
                `;
            }).join('')}
        </div>
    `;

    document.querySelectorAll('.complete-person-card').forEach(card => {
        const bookedSet = new Set(
            String(card.getAttribute('data-booked-service-ids') || '')
                .split(',')
                .map(id => id.trim())
                .filter(id => id !== '')
        );

        card.querySelectorAll('.service-option').forEach(option => {
            const serviceId = option.getAttribute('data-service-id');
            const checkbox = option.querySelector('.additional-service-checkbox');

            if (bookedSet.has(String(serviceId))) {
                option.style.display = 'none';
                if (checkbox) checkbox.checked = false;
            }
        });
    });

    document.querySelectorAll('#completePersonsServicesContainer .additional-service-checkbox').forEach(cb => {
        cb.addEventListener('change', updateCompleteSummary);
    });
}

function updateCompleteSummary() {
    let extraTotal = 0;

    document.querySelectorAll('#completePersonsServicesContainer .additional-service-checkbox').forEach(cb => {
        const option = cb.closest('.service-option');
        if (option && option.style.display !== 'none' && cb.checked) {
            extraTotal += parseFloat(cb.dataset.price || 0);
        }
    });

    const newTotal = currentTotal + extraTotal;
    const collectNow = currentBalance + extraTotal;
    const updatedPaid = newTotal;
    const updatedBalance = 0;

    document.getElementById('currentBalanceText').textContent = currentBalance.toFixed(2);
    document.getElementById('extraServicesTotalText').textContent = extraTotal.toFixed(2);
    document.getElementById('collectNowText').textContent = collectNow.toFixed(2);
    document.getElementById('newTotalText').textContent = newTotal.toFixed(2);
    document.getElementById('updatedPaidText').textContent = updatedPaid.toFixed(2);
    document.getElementById('updatedBalanceText').textContent = updatedBalance.toFixed(2);
}
</script>

</body>
</html>