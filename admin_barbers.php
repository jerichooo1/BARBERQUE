<?php
session_start();
include 'db.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';
require 'PHPMailer/src/Exception.php';

if (!isset($_SESSION['admin_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: admin_login.php");
    exit();
}

/* SHOP SETTINGS */
$settings = $conn->query("SELECT * FROM settings LIMIT 1");
$shop = $settings ? $settings->fetch_assoc() : null;

$message = '';
$message_type = '';

/* CHECK IF barber_leaves TABLE EXISTS */
$leaveTableExists = false;
$checkLeaveTable = $conn->query("SHOW TABLES LIKE 'barber_leaves'");
if ($checkLeaveTable && $checkLeaveTable->num_rows > 0) {
    $leaveTableExists = true;
}

$hasLeaveRejectionReason = false;
if ($leaveTableExists) {
    $checkLeaveReasonColumn = $conn->query("SHOW COLUMNS FROM barber_leaves LIKE 'rejection_reason'");
    if ($checkLeaveReasonColumn && $checkLeaveReasonColumn->num_rows > 0) {
        $hasLeaveRejectionReason = true;
    }
}

function formatNameValue($value) {
    $value = trim($value);
    $value = preg_replace('/\s+/', ' ', $value);
    return ucwords(strtolower($value));
}

function formatTitleValue($value) {
    $value = trim($value);
    $value = preg_replace('/\s+/', ' ', $value);
    return ucwords(strtolower($value));
}

function validPasswordRule($password) {
    return preg_match('/^(?=.*[A-Z])(?=.*[!@#$%^&*()_\-+=\[\]{};:,.<>?\/\\\\|`~]).{8,}$/', $password);
}

function renderStars($rating) {
    $rating = max(0, min(5, (float)$rating));
    $full = (int)floor($rating);
    $half = (($rating - $full) >= 0.5) ? 1 : 0;
    $empty = 5 - $full - $half;
    return str_repeat('★', $full) . ($half ? '☆' : '') . str_repeat('✩', $empty);
}

function uploadBarberImage($file, $oldImage = null) {
    if (!isset($file) || !isset($file['error']) || $file['error'] !== 0) {
        return $oldImage;
    }

    $allowed_ext = ['jpg', 'jpeg', 'png', 'webp'];
    $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    if (!in_array($file_ext, $allowed_ext)) {
        return false;
    }

    if (($file['size'] ?? 0) > 5 * 1024 * 1024) {
        return false;
    }

    $upload_dir = "images/";
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }

    $new_name = 'barber_' . time() . '_' . rand(1000, 9999) . '.' . $file_ext;
    $target = $upload_dir . $new_name;

    if (move_uploaded_file($file['tmp_name'], $target)) {
        return $new_name;
    }

    return false;
}

function sendBarberLeaveConflictEmail(
    string $toEmail,
    string $customerName,
    string $appointmentDateTime,
    string $barberName,
    string $services,
    string $shopName
): bool {
    global $shop;

    $mail = new PHPMailer(true);

    try {
        $smtpEmail = trim($shop['smtp_email'] ?? '');
        $smtpPassword = trim($shop['smtp_password'] ?? '');
        $smtpFromName = trim($shop['smtp_from_name'] ?? ($shopName ?: 'Fade Street Hair Studio'));

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

        $mail->Subject = 'Appointment Update: Barber Unavailable on Your Scheduled Date';

        $body = "
            Hi " . htmlspecialchars($customerName) . ",<br><br>

            We would like to inform you that your assigned barber will be unavailable on your scheduled appointment date due to an approved leave request.<br><br>

            <strong>Schedule:</strong> " . htmlspecialchars($appointmentDateTime) . "<br>
            <strong>Barber:</strong> " . htmlspecialchars($barberName) . "<br>
            <strong>Services:</strong> " . htmlspecialchars($services) . "<br><br>

            Please reschedule your appointment or choose another barber at your earliest convenience.<br><br>
        ";

        if (!empty($shop['phone'])) {
            $body .= "<strong>Contact Number:</strong> " . htmlspecialchars($shop['phone']) . "<br>";
        }

        if (!empty($shop['email'])) {
            $body .= "<strong>Email:</strong> " . htmlspecialchars($shop['email']) . "<br>";
        }

        $body .= "<br>Thank you for your understanding.<br><br>" . htmlspecialchars($smtpFromName);

        $mail->Body = $body;
        $mail->send();

        return true;
    } catch (Exception $e) {
        return false;
    }
}

function sendBarberLeaveDecisionEmail(
    string $toEmail,
    string $barberName,
    string $leaveDate,
    string $decision,
    string $shopName,
    string $reason = ''
): bool {
    global $shop;

    $mail = new PHPMailer(true);

    try {
        $smtpEmail = trim($shop['smtp_email'] ?? '');
        $smtpPassword = trim($shop['smtp_password'] ?? '');
        $smtpFromName = trim($shop['smtp_from_name'] ?? ($shopName ?: 'Fade Street Hair Studio'));

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
        $mail->addAddress($toEmail, $barberName);
        $mail->isHTML(true);

        $formattedDate = date("F j, Y", strtotime($leaveDate));

        if ($decision === 'approved') {
            $mail->Subject = 'Your Leave Request Has Been Approved';
            $body = "
                Hi " . htmlspecialchars($barberName) . ",<br><br>

                Your leave request has been <strong>approved</strong>.<br><br>

                <strong>Leave Date:</strong> " . htmlspecialchars($formattedDate) . "<br><br>

                Please make sure your pending work is properly endorsed if needed.<br><br>
            ";
        } else {
            $mail->Subject = 'Your Leave Request Has Been Rejected';
            $body = "
                Hi " . htmlspecialchars($barberName) . ",<br><br>

                Your leave request has been <strong>rejected</strong>.<br><br>

                <strong>Leave Date:</strong> " . htmlspecialchars($formattedDate) . "<br>
            ";

            if ($reason !== '') {
                $body .= "<strong>Reason:</strong> " . nl2br(htmlspecialchars($reason)) . "<br>";
            }

            $body .= "<br>Please contact management if you have questions regarding this decision.<br><br>";
        }

        if (!empty($shop['phone'])) {
            $body .= "<strong>Contact Number:</strong> " . htmlspecialchars($shop['phone']) . "<br>";
        }

        if (!empty($shop['email'])) {
            $body .= "<strong>Email:</strong> " . htmlspecialchars($shop['email']) . "<br>";
        }

        $body .= "<br>Thank you.<br><br>" . htmlspecialchars($smtpFromName);

        $mail->Body = $body;
        $mail->send();

        return true;
    } catch (Exception $e) {
        return false;
    }
}

/* HANDLE ACTIONS */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $name = formatNameValue($_POST['name'] ?? '');
        $email = strtolower(trim($_POST['email'] ?? ''));
        $password = $_POST['password'] ?? '';
        $title = formatTitleValue($_POST['title'] ?? '');
        $status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';

        if ($name === '' || $email === '' || $password === '' || $title === '') {
            $message = 'Please fill in all barber fields.';
            $message_type = 'error';
        } elseif (!preg_match('/^[a-zA-Z\s]+$/', $name)) {
            $message = 'Barber name should contain letters and spaces only.';
            $message_type = 'error';
        } elseif (!preg_match('/^[a-zA-Z\s&.\-\/]+$/', $title)) {
            $message = 'Title should contain letters and common title symbols only.';
            $message_type = 'error';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = 'Invalid email format.';
            $message_type = 'error';
        } elseif (!validPasswordRule($password)) {
            $message = 'Password must be at least 8 characters long and contain at least 1 uppercase letter and 1 special character.';
            $message_type = 'error';
        } else {
            $stmt = $conn->prepare("SELECT id FROM barbers WHERE email = ? LIMIT 1");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $stmt->store_result();

            if ($stmt->num_rows > 0) {
                $message = 'Email already exists for another barber.';
                $message_type = 'error';
                $stmt->close();
            } else {
                $stmt->close();

                $image_path = uploadBarberImage($_FILES['image_path'] ?? null);

                if ($image_path === false || empty($image_path)) {
                    $message = 'Please upload a valid barber image.';
                    $message_type = 'error';
                } else {
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);

                    $stmt = $conn->prepare("
                        INSERT INTO barbers (name, email, password, status, image_path, title)
                        VALUES (?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->bind_param("ssssss", $name, $email, $hashed_password, $status, $image_path, $title);

                    if ($stmt->execute()) {
                        $message = 'Barber added successfully.';
                        $message_type = 'success';
                    } else {
                        $message = 'Failed to add barber.';
                        $message_type = 'error';
                    }
                    $stmt->close();
                }
            }
        }
    }

    if ($action === 'update') {
        $barber_id = intval($_POST['barber_id'] ?? 0);
        $name = formatNameValue($_POST['name'] ?? '');
        $email = strtolower(trim($_POST['email'] ?? ''));
        $password = $_POST['password'] ?? '';
        $title = formatTitleValue($_POST['title'] ?? '');
        $status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
        $old_image = trim($_POST['old_image'] ?? '');

        if ($barber_id <= 0 || $name === '' || $email === '' || $title === '') {
            $message = 'Please fill in all required barber fields.';
            $message_type = 'error';
        } elseif (!preg_match('/^[a-zA-Z\s]+$/', $name)) {
            $message = 'Barber name should contain letters and spaces only.';
            $message_type = 'error';
        } elseif (!preg_match('/^[a-zA-Z\s&.\-\/]+$/', $title)) {
            $message = 'Title should contain letters and common title symbols only.';
            $message_type = 'error';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = 'Invalid email format.';
            $message_type = 'error';
        } elseif ($password !== '' && !validPasswordRule($password)) {
            $message = 'Password must be at least 8 characters long and contain at least 1 uppercase letter and 1 special character.';
            $message_type = 'error';
        } else {
            $checkStmt = $conn->prepare("SELECT id FROM barbers WHERE email = ? AND id != ? LIMIT 1");
            $checkStmt->bind_param("si", $email, $barber_id);
            $checkStmt->execute();
            $checkStmt->store_result();

            if ($checkStmt->num_rows > 0) {
                $message = 'Email already exists for another barber.';
                $message_type = 'error';
                $checkStmt->close();
            } else {
                $checkStmt->close();

                $new_image = uploadBarberImage($_FILES['image_path'] ?? null, $old_image);

                if ($new_image === false) {
                    $message = 'Invalid barber image.';
                    $message_type = 'error';
                } else {
                    if ($password !== '') {
                        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                        $stmt = $conn->prepare("
                            UPDATE barbers
                            SET name = ?, email = ?, password = ?, status = ?, image_path = ?, title = ?
                            WHERE id = ?
                        ");
                        $stmt->bind_param("ssssssi", $name, $email, $hashed_password, $status, $new_image, $title, $barber_id);
                    } else {
                        $stmt = $conn->prepare("
                            UPDATE barbers
                            SET name = ?, email = ?, status = ?, image_path = ?, title = ?
                            WHERE id = ?
                        ");
                        $stmt->bind_param("sssssi", $name, $email, $status, $new_image, $title, $barber_id);
                    }

                    if ($stmt->execute()) {
                        $message = 'Barber updated successfully.';
                        $message_type = 'success';
                    } else {
                        $message = 'Failed to update barber.';
                        $message_type = 'error';
                    }
                    $stmt->close();
                }
            }
        }
    }

    if ($action === 'delete') {
        $barber_id = intval($_POST['barber_id'] ?? 0);

        if ($barber_id > 0) {
            $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM appointments WHERE barber_id = ?");
            $stmt->bind_param("i", $barber_id);
            $stmt->execute();
            $appointmentCount = intval($stmt->get_result()->fetch_assoc()['total'] ?? 0);
            $stmt->close();

            $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM walkins WHERE barber_id = ?");
            $stmt->bind_param("i", $barber_id);
            $stmt->execute();
            $walkinCount = intval($stmt->get_result()->fetch_assoc()['total'] ?? 0);
            $stmt->close();

            if ($appointmentCount > 0 || $walkinCount > 0) {
                $message = 'This barber cannot be deleted because there are related appointments or walk-ins.';
                $message_type = 'error';
            } else {
                $conn->begin_transaction();

                try {
                    $stmt = $conn->prepare("DELETE FROM barber_schedules WHERE barber_id = ?");
                    $stmt->bind_param("i", $barber_id);
                    $stmt->execute();
                    $stmt->close();

                    $stmt = $conn->prepare("DELETE FROM barber_ratings WHERE barber_id = ?");
                    $stmt->bind_param("i", $barber_id);
                    $stmt->execute();
                    $stmt->close();

                    if ($leaveTableExists) {
                        $stmt = $conn->prepare("DELETE FROM barber_leaves WHERE barber_id = ?");
                        $stmt->bind_param("i", $barber_id);
                        $stmt->execute();
                        $stmt->close();
                    }

                    $stmt = $conn->prepare("DELETE FROM barbers WHERE id = ?");
                    $stmt->bind_param("i", $barber_id);
                    $stmt->execute();
                    $stmt->close();

                    $conn->commit();
                    $message = 'Barber deleted successfully.';
                    $message_type = 'success';
                } catch (Exception $e) {
                    $conn->rollback();
                    $message = 'Failed to delete barber.';
                    $message_type = 'error';
                }
            }
        }
    }

    if ($action === 'save_schedule') {
        $barber_id = intval($_POST['barber_id'] ?? 0);
        $day_of_week = $_POST['day_of_week'] ?? '';
        $start_time = $_POST['start_time'] ?? '';
        $end_time = $_POST['end_time'] ?? '';

        $valid_days = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];

        if ($barber_id > 0 && in_array($day_of_week, $valid_days, true) && $start_time !== '' && $end_time !== '') {
            $stmt = $conn->prepare("
                SELECT id FROM barber_schedules
                WHERE barber_id = ? AND day_of_week = ?
            ");
            $stmt->bind_param("is", $barber_id, $day_of_week);
            $stmt->execute();
            $exists = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($exists) {
                $stmt = $conn->prepare("
                    UPDATE barber_schedules
                    SET start_time = ?, end_time = ?
                    WHERE barber_id = ? AND day_of_week = ?
                ");
                $stmt->bind_param("ssis", $start_time, $end_time, $barber_id, $day_of_week);
            } else {
                $stmt = $conn->prepare("
                    INSERT INTO barber_schedules (barber_id, day_of_week, start_time, end_time)
                    VALUES (?, ?, ?, ?)
                ");
                $stmt->bind_param("isss", $barber_id, $day_of_week, $start_time, $end_time);
            }

            if ($stmt->execute()) {
                $message = 'Barber schedule saved.';
                $message_type = 'success';
            } else {
                $message = 'Failed to save barber schedule.';
                $message_type = 'error';
            }
            $stmt->close();
        }
    }

    if ($action === 'delete_schedule') {
        $schedule_id = intval($_POST['schedule_id'] ?? 0);

        if ($schedule_id > 0) {
            $stmt = $conn->prepare("DELETE FROM barber_schedules WHERE id = ?");
            $stmt->bind_param("i", $schedule_id);
            if ($stmt->execute()) {
                $message = 'Barber schedule deleted.';
                $message_type = 'success';
            } else {
                $message = 'Failed to delete barber schedule.';
                $message_type = 'error';
            }
            $stmt->close();
        }
    }

    if ($leaveTableExists && $action === 'approve_leave') {
        $leave_id = intval($_POST['leave_id'] ?? 0);

        if ($leave_id > 0) {
            $stmt = $conn->prepare("
                SELECT bl.id, bl.barber_id, bl.leave_date, b.name AS barber_name, b.email AS barber_email
                FROM barber_leaves bl
                JOIN barbers b ON bl.barber_id = b.id
                WHERE bl.id = ?
                LIMIT 1
            ");
            $stmt->bind_param("i", $leave_id);
            $stmt->execute();
            $leaveInfo = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($leaveInfo) {
                $conn->begin_transaction();

                try {
                    $stmt = $conn->prepare("
                        UPDATE barber_leaves
                        SET status = 'approved'
                        WHERE id = ?
                    ");
                    $stmt->bind_param("i", $leave_id);
                    $stmt->execute();
                    $stmt->close();

                    $barberId = (int)$leaveInfo['barber_id'];
                    $leaveDate = $leaveInfo['leave_date'];
                    $barberName = $leaveInfo['barber_name'];
                    $barberEmail = trim($leaveInfo['barber_email'] ?? '');
                    $shopName = $shop['shop_name'] ?? 'Fade Street Hair Studio';

                    $creditedCount = 0;

                    /* companion table columns */
                    $companionCols = [];
                    $companionColsRes = $conn->query("SHOW COLUMNS FROM appointment_companions");
                    if ($companionColsRes) {
                        while ($col = $companionColsRes->fetch_assoc()) {
                            $companionCols[] = $col['Field'];
                        }
                    }

                    $hasCompanionBarberId = in_array('barber_id', $companionCols, true);
                    $hasCompanionStartTime = in_array('start_time', $companionCols, true);
                    $hasCompanionEndTime = in_array('end_time', $companionCols, true);
                    $hasCompanionLeaveCredit = in_array('leave_reschedule_credit', $companionCols, true);

                    /* 1) parent appointments directly assigned to this barber/date */
                    $creditStmt = $conn->prepare("
                        UPDATE appointments
                        SET leave_reschedule_credit = 1
                        WHERE barber_id = ?
                          AND DATE(start_time) = ?
                          AND status IN ('pending approval', 'approved')
                    ");
                    $creditStmt->bind_param("is", $barberId, $leaveDate);
                    $creditStmt->execute();
                    $creditedCount += (int)$creditStmt->affected_rows;
                    $creditStmt->close();

                    /* 2) companion/person appointments assigned to this barber/date */
                    if ($hasCompanionBarberId && $hasCompanionStartTime && $hasCompanionLeaveCredit) {
                        $creditCompanionStmt = $conn->prepare("
                            UPDATE appointment_companions ac
                            JOIN appointments a ON a.id = ac.appointment_id
                            SET ac.leave_reschedule_credit = 1
                            WHERE ac.barber_id = ?
                              AND DATE(ac.start_time) = ?
                              AND a.status IN ('pending approval', 'approved')
                        ");
                        $creditCompanionStmt->bind_param("is", $barberId, $leaveDate);
                        $creditCompanionStmt->execute();
                        $creditedCount += (int)$creditCompanionStmt->affected_rows;
                        $creditCompanionStmt->close();
                    }

                    $conn->commit();

                    $barberDecisionSent = false;
                    if ($barberEmail !== '') {
                        $barberDecisionSent = sendBarberLeaveDecisionEmail(
                            $barberEmail,
                            $barberName,
                            $leaveDate,
                            'approved',
                            $shopName
                        );
                    }

                    $affectedRows = [];

                    /* parent appointments */
                    $stmt = $conn->prepare("
                        SELECT
                            a.id,
                            0 AS person_no,
                            'parent' AS target_type,
                            a.start_time,
                            a.end_time,
                            u.first_name,
                            u.last_name,
                            u.email,
                            GROUP_CONCAT(DISTINCT s.name ORDER BY s.name SEPARATOR ', ') AS services
                        FROM appointments a
                        JOIN users u ON a.customer_id = u.id
                        LEFT JOIN appointment_services aps ON a.id = aps.appointment_id
                        LEFT JOIN services s ON aps.service_id = s.id
                        WHERE a.barber_id = ?
                          AND DATE(a.start_time) = ?
                          AND a.status IN ('pending approval', 'approved')
                        GROUP BY a.id
                    ");
                    $stmt->bind_param("is", $barberId, $leaveDate);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    while ($r = $res->fetch_assoc()) {
                        $affectedRows[] = $r;
                    }
                    $stmt->close();

                    /* companion/person appointments without JSON_TABLE */
                    if ($hasCompanionBarberId && $hasCompanionStartTime && $hasCompanionEndTime) {
                        $stmt = $conn->prepare("
                            SELECT
                                a.id,
                                ac.person_no,
                                'companion' AS target_type,
                                ac.start_time,
                                ac.end_time,
                                ac.full_name,
                                ac.service_id,
                                ac.selected_services,
                                u.first_name,
                                u.last_name,
                                u.email,
                                ms.name AS main_service_name
                            FROM appointment_companions ac
                            JOIN appointments a ON a.id = ac.appointment_id
                            JOIN users u ON a.customer_id = u.id
                            LEFT JOIN services ms ON ac.service_id = ms.id
                            WHERE ac.barber_id = ?
                              AND DATE(ac.start_time) = ?
                              AND a.status IN ('pending approval', 'approved')
                        ");
                        $stmt->bind_param("is", $barberId, $leaveDate);
                        $stmt->execute();
                        $res = $stmt->get_result();

                        $tempRows = [];
                        $allAddonIds = [];

                        while ($row = $res->fetch_assoc()) {
                            $selected = json_decode($row['selected_services'] ?? '[]', true);
                            if (!is_array($selected)) {
                                $selected = [];
                            }

                            $row['selected_ids'] = $selected;
                            $tempRows[] = $row;

                            foreach ($selected as $sid) {
                                $sid = (int)$sid;
                                if ($sid > 0 && $sid !== (int)$row['service_id']) {
                                    $allAddonIds[] = $sid;
                                }
                            }
                        }
                        $stmt->close();

                        $addonMap = [];

                        if (!empty($allAddonIds)) {
                            $allAddonIds = array_values(array_unique($allAddonIds));
                            $placeholders = implode(',', array_fill(0, count($allAddonIds), '?'));
                            $types = str_repeat('i', count($allAddonIds));

                            $addonStmt = $conn->prepare("SELECT id, name FROM services WHERE id IN ($placeholders)");
                            $addonStmt->bind_param($types, ...$allAddonIds);
                            $addonStmt->execute();
                            $addonRes = $addonStmt->get_result();

                            while ($a = $addonRes->fetch_assoc()) {
                                $addonMap[(int)$a['id']] = $a['name'];
                            }
                            $addonStmt->close();
                        }

                        foreach ($tempRows as $row) {
                            $addonNames = [];

                            foreach (($row['selected_ids'] ?? []) as $sid) {
                                $sid = (int)$sid;
                                if ($sid !== (int)$row['service_id'] && isset($addonMap[$sid])) {
                                    $addonNames[] = $addonMap[$sid];
                                }
                            }

                            $services = $row['main_service_name'] ?: 'N/A';
                            if (!empty($addonNames)) {
                                $services .= ', ' . implode(', ', $addonNames);
                            }

                            $affectedRows[] = [
                                'id' => $row['id'],
                                'person_no' => $row['person_no'],
                                'target_type' => 'companion',
                                'start_time' => $row['start_time'],
                                'end_time' => $row['end_time'],
                                'first_name' => $row['first_name'],
                                'last_name' => $row['last_name'],
                                'email' => $row['email'],
                                'services' => $services
                            ];
                        }
                    }

                    $emailSentCount = 0;
                    $affectedCount = count($affectedRows);

                    foreach ($affectedRows as $appt) {
                        $customerName = trim(($appt['first_name'] ?? '') . ' ' . ($appt['last_name'] ?? ''));
                        $customerEmail = trim($appt['email'] ?? '');
                        $services = $appt['services'] ?? 'N/A';

                        $appointmentDateTime = date("F j, Y g:i A", strtotime($appt['start_time']));
                        if (!empty($appt['end_time'])) {
                            $appointmentDateTime .= ' - ' . date("g:i A", strtotime($appt['end_time']));
                        }

                        if ($customerEmail !== '') {
                            $sent = sendBarberLeaveConflictEmail(
                                $customerEmail,
                                $customerName,
                                $appointmentDateTime,
                                $barberName,
                                $services,
                                $shopName
                            );
                            if ($sent) {
                                $emailSentCount++;
                            }
                        }
                    }

                    if ($affectedCount > 0) {
                        $message = "Leave approved. {$emailSentCount} affected customer email(s) sent. {$creditedCount} appointment(s) granted extra reschedule access.";
                    } else {
                        $message = 'Leave approved. No affected appointments found for that date.';
                    }

                    if ($barberEmail !== '') {
                        $message .= $barberDecisionSent
                            ? ' Barber approval email sent.'
                            : ' Barber approval email failed.';
                    }

                    $message_type = 'success';
                } catch (Exception $e) {
                    $conn->rollback();
                    $message = 'Failed to approve leave request.';
                    $message_type = 'error';
                }
            } else {
                $message = 'Leave request not found.';
                $message_type = 'error';
            }
        }
    }

    if ($leaveTableExists && $action === 'reject_leave') {
        $leave_id = intval($_POST['leave_id'] ?? 0);
        $rejection_reason = trim($_POST['rejection_reason'] ?? '');

        if ($leave_id > 0) {
            if ($rejection_reason === '') {
                $message = 'Please provide a rejection reason.';
                $message_type = 'error';
            } else {
                $stmt = $conn->prepare("
                    SELECT bl.id, bl.leave_date, b.name AS barber_name, b.email AS barber_email
                    FROM barber_leaves bl
                    JOIN barbers b ON bl.barber_id = b.id
                    WHERE bl.id = ?
                    LIMIT 1
                ");
                $stmt->bind_param("i", $leave_id);
                $stmt->execute();
                $leaveInfo = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if ($hasLeaveRejectionReason) {
                    $stmt = $conn->prepare("
                        UPDATE barber_leaves
                        SET status = 'rejected', rejection_reason = ?
                        WHERE id = ?
                    ");
                    $stmt->bind_param("si", $rejection_reason, $leave_id);
                } else {
                    $stmt = $conn->prepare("
                        UPDATE barber_leaves
                        SET status = 'rejected'
                        WHERE id = ?
                    ");
                    $stmt->bind_param("i", $leave_id);
                }

                $stmt->execute();
                $stmt->close();

                $message = 'Leave rejected.';

                if ($leaveInfo) {
                    $barberEmail = trim($leaveInfo['barber_email'] ?? '');
                    $barberName = $leaveInfo['barber_name'] ?? 'Barber';
                    $leaveDate = $leaveInfo['leave_date'] ?? '';
                    $shopName = $shop['shop_name'] ?? 'Fade Street Hair Studio';

                    if ($barberEmail !== '') {
                        $barberDecisionSent = sendBarberLeaveDecisionEmail(
                            $barberEmail,
                            $barberName,
                            $leaveDate,
                            'rejected',
                            $shopName,
                            $rejection_reason
                        );

                        $message .= $barberDecisionSent
                            ? ' Barber rejection email sent.'
                            : ' Barber rejection email failed.';
                    }
                }

                $message_type = 'success';
            }
        }
    }

    header("Location: admin_barbers.php?msg=" . urlencode($message) . "&type=" . urlencode($message_type));
    exit();
}

/* FLASH FROM QUERY STRING */
if (isset($_GET['msg'])) {
    $message = trim($_GET['msg']);
    $message_type = trim($_GET['type'] ?? 'success');
}

/* FILTERS */
$search_filter = trim($_GET['search'] ?? '');
$status_filter = trim($_GET['status'] ?? '');
$sort_filter = trim($_GET['sort'] ?? 'name_asc');

$whereParts = [];
$params = [];
$types = '';

if ($search_filter !== '') {
    $whereParts[] = "(b.name LIKE ? OR b.email LIKE ? OR b.title LIKE ?)";
    $like = '%' . $search_filter . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= 'sss';
}

if ($status_filter !== '') {
    $whereParts[] = "b.status = ?";
    $params[] = $status_filter;
    $types .= 's';
}

$whereSql = !empty($whereParts) ? 'WHERE ' . implode(' AND ', $whereParts) : '';

$orderSql = "ORDER BY b.name ASC";
if ($sort_filter === 'name_desc') {
    $orderSql = "ORDER BY b.name DESC";
} elseif ($sort_filter === 'rating_desc') {
    $orderSql = "ORDER BY avg_rating DESC, b.name ASC";
} elseif ($sort_filter === 'reviews_desc') {
    $orderSql = "ORDER BY total_reviews DESC, b.name ASC";
} elseif ($sort_filter === 'status_active') {
    $orderSql = "ORDER BY (b.status='active') DESC, b.name ASC";
}

/* SUMMARY STATS */
$summary = [
    'total_barbers' => 0,
    'active_barbers' => 0,
    'inactive_barbers' => 0,
    'total_reviews' => 0
];

$summaryRes = $conn->query("
    SELECT
        COUNT(*) AS total_barbers,
        SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active_barbers,
        SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) AS inactive_barbers
    FROM barbers
");
if ($summaryRes) {
    $summaryRow = $summaryRes->fetch_assoc();
    $summary['total_barbers'] = (int)($summaryRow['total_barbers'] ?? 0);
    $summary['active_barbers'] = (int)($summaryRow['active_barbers'] ?? 0);
    $summary['inactive_barbers'] = (int)($summaryRow['inactive_barbers'] ?? 0);
}

$reviewsCountRes = $conn->query("SELECT COUNT(*) AS total_reviews FROM barber_ratings");
if ($reviewsCountRes) {
    $summary['total_reviews'] = (int)($reviewsCountRes->fetch_assoc()['total_reviews'] ?? 0);
}

/* GET BARBERS WITH AVG RATING */
$barberSql = "
    SELECT 
        b.*,
        COALESCE(ROUND(AVG(br.rating), 1), 0) AS avg_rating,
        COUNT(br.id) AS total_reviews
    FROM barbers b
    LEFT JOIN barber_ratings br ON b.id = br.barber_id
    $whereSql
    GROUP BY b.id
    $orderSql
";
$barberStmt = $conn->prepare($barberSql);
if (!empty($params)) {
    $barberStmt->bind_param($types, ...$params);
}
$barberStmt->execute();
$barbers = $barberStmt->get_result();

/* GET LEAVE REQUESTS */
$leaveRequests = false;
if ($leaveTableExists) {
    $leaveRequests = $conn->query("
        SELECT 
            bl.*,
            b.name AS barber_name
        FROM barber_leaves bl
        JOIN barbers b ON bl.barber_id = b.id
        ORDER BY 
            CASE WHEN bl.status = 'pending' THEN 1 ELSE 2 END,
            bl.leave_date DESC,
            bl.created_at DESC
    ");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Barbers | <?= htmlspecialchars($shop['shop_name'] ?? 'Barbershop') ?></title>
    <link rel="stylesheet" href="styles.css">
    <style>
        * { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: 'Poppins', sans-serif;
            background: #f3f7fb;
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
            width: 100%;
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
        }

        .admin-main {
            flex: 1;
            padding: 28px;
            min-width: 0;
            width: 100%;
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
            margin: 0 0 6px;
            font-size: 30px;
        }

        .page-header p {
            margin: 0;
            color: #6b7280;
        }

        .alert {
            padding: 14px 16px;
            border-radius: 14px;
            margin-bottom: 18px;
            font-weight: 500;
        }

        .alert-success {
            background: #dff6dd;
            color: #256029;
        }

        .alert-error {
            background: #fde2e2;
            color: #991b1b;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(160px, 1fr));
            gap: 14px;
            margin-bottom: 18px;
        }

        .stat-card {
            background: #fff;
            border-radius: 18px;
            padding: 18px;
            box-shadow: 0 8px 24px rgba(15, 23, 42, 0.06);
            border: 1px solid #edf2f7;
            border-left: 5px solid #2563eb;
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

        .panel {
            background: #fff;
            border-radius: 22px;
            padding: 20px;
            box-shadow: 0 10px 30px rgba(15, 23, 42, 0.06);
            border: 1px solid #edf2f7;
            margin-bottom: 18px;
        }

        .panel h2 {
            margin: 0 0 16px;
            font-size: 22px;
        }

        .compact-form-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 14px;
        }

        .filter-grid {
            display: grid;
            grid-template-columns: 1.4fr 0.8fr 0.8fr auto auto;
            gap: 12px;
            align-items: end;
        }

        .form-group label {
            display: block;
            margin-bottom: 7px;
            font-weight: 600;
            color: #374151;
            font-size: 13px;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 11px 13px;
            border: 1px solid #d1d9e6;
            border-radius: 12px;
            font-size: 14px;
            outline: none;
            background: #fff;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.12);
        }

        .toolbar-actions,
        .action-row,
        .modal-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .btn {
            border: none;
            border-radius: 12px;
            padding: 11px 16px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: 0.18s ease;
            font-size: 14px;
        }

        .btn:hover { transform: translateY(-1px); }

        .btn-primary { background: #0b3d91; color: #fff; }
        .btn-secondary { background: #eef2f7; color: #111827; }
        .btn-update { background: #2563eb; color: #fff; }
        .btn-delete { background: #c62828; color: #fff; }
        .btn-approve { background: #1f9d55; color: #fff; }
        .btn-reject { background: #c62828; color: #fff; }
        .btn-view { background: #0f172a; color: #fff; }

        .table-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            margin-bottom: 14px;
            flex-wrap: wrap;
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
            min-width: 1020px;
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
            padding: 14px;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
        }

        tbody td {
            padding: 16px 14px;
            border-bottom: 1px solid #edf2f7;
            vertical-align: top;
            background: #fff;
        }

        tbody tr:hover td {
            background: #fcfcfd;
        }

        .barber-identity {
            display: flex;
            gap: 12px;
            align-items: center;
            min-width: 0;
        }

        .barber-thumb {
            width: 52px;
            height: 52px;
            border-radius: 12px;
            object-fit: cover;
            border: 1px solid #e5e7eb;
            background: #f3f4f6;
            flex-shrink: 0;
        }

        .barber-meta strong {
            display: block;
            color: #111827;
            font-size: 15px;
            line-height: 1.35;
        }

        .barber-meta span,
        .muted {
            color: #6b7280;
            font-size: 13px;
        }

        .status-tag {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 6px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
        }

        .status-active {
            background: #dff6dd;
            color: #256029;
        }

        .status-inactive {
            background: #eee;
            color: #555;
        }

        .rating-block strong {
            display: block;
            font-size: 16px;
            color: #111827;
        }

        .rating-stars {
            font-size: 16px;
            line-height: 1;
            color: #2563eb;
            letter-spacing: 1px;
            margin-bottom: 5px;
        }

        .rating-inline {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
            margin-bottom: 4px;
        }

        .review-preview {
            max-width: 260px;
            white-space: normal;
            word-break: break-word;
            line-height: 1.5;
        }

        .empty-state {
            background: #fff;
            padding: 36px 22px;
            border-radius: 18px;
            text-align: center;
            color: #6b7280;
            border: 1px solid #edf2f7;
        }

        .mini-card {
            background: #fff;
            border: 1px solid #e6edf5;
            border-radius: 14px;
            padding: 14px;
        }

        .leave-panel {
            max-height: 560px;
            display: flex;
            flex-direction: column;
        }

        .leave-panel .table-head {
            margin-bottom: 12px;
        }

        .leave-list {
            display: grid;
            gap: 10px;
            max-height: 430px;
            overflow-y: auto;
            padding-right: 6px;
        }

        .leave-list::-webkit-scrollbar {
            width: 8px;
        }

        .leave-list::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 999px;
        }

        .leave-list .mini-card {
            display: grid;
            grid-template-columns: minmax(160px, 1fr) minmax(120px, 0.7fr) minmax(180px, 1.5fr) auto;
            gap: 12px;
            align-items: center;
            padding: 12px 14px;
        }

        .leave-name {
            min-width: 0;
        }

        .leave-name strong {
            display: block;
            color: #111827;
            font-size: 14px;
        }

        .leave-name span,
        .leave-date,
        .leave-reason {
            color: #64748b;
            font-size: 13px;
            line-height: 1.45;
            word-break: break-word;
        }

        .leave-actions {
            display: flex;
            gap: 8px;
            justify-content: flex-end;
            align-items: center;
            flex-wrap: wrap;
        }

        .leave-actions form {
            margin: 0;
        }

        .leave-actions .btn {
            padding: 8px 12px;
            font-size: 13px;
        }

        .leave-status {
            display: inline-flex;
            padding: 6px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
            margin-top: 6px;
        }

        .status-pending {
            background: #fff4cc;
            color: #8a6d00;
        }

        .status-approved {
            background: #dcfce7;
            color: #166534;
        }

        .status-rejected {
            background: #fee2e2;
            color: #991b1b;
        }

        .hidden-detail { display: none; }

        .modal-backdrop {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.6);
            z-index: 1300;
            padding: 20px;
            overflow-y: auto;
        }

        .modal-backdrop.show {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .modal {
            width: 100%;
            max-width: 1140px;
            background: #fff;
            border-radius: 24px;
            box-shadow: 0 30px 80px rgba(15, 23, 42, 0.2);
            max-height: 92vh;
            overflow-y: auto;
            position: relative;
        }

        .modal-sm {
            max-width: 560px;
        }

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

        .modal-body {
            padding: 24px 26px 26px;
        }

        .details-grid {
            display: grid;
            grid-template-columns: 320px minmax(0, 1fr);
            gap: 18px;
        }

        .profile-card,
        .details-card {
            background: #f8fafc;
            border: 1px solid #e8eef5;
            border-radius: 18px;
            padding: 18px;
        }

        .profile-card img {
            width: 130px;
            height: 130px;
            border-radius: 18px;
            object-fit: cover;
            border: 1px solid #dbe3ee;
            background: #fff;
            display: block;
            margin-bottom: 14px;
        }

        .profile-card h4,
        .details-card h4 {
            margin: 0 0 12px;
            font-size: 18px;
            color: #111827;
        }

        .profile-card p,
        .details-card p {
            margin: 7px 0;
            line-height: 1.55;
        }

        .section-stack { display: grid; gap: 18px; }

        .two-col {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
            gap: 18px;
        }

        .schedule-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }

        .schedule-day-card {
            background: #fff;
            border: 1px solid #e6edf5;
            border-radius: 14px;
            padding: 12px;
        }

        .schedule-day-card strong {
            display: block;
            margin-bottom: 6px;
        }

        .review-list { display: grid; gap: 12px; }

        .review-card,
        .booking-card {
            background: #fff;
            border: 1px solid #e6edf5;
            border-radius: 14px;
            padding: 14px;
        }

        .review-card h5,
        .booking-card h5 {
            margin: 0 0 8px;
            font-size: 15px;
            color: #0b3d91;
        }

        .stars { font-weight: 700; color: #2563eb; }

        .warning-text {
            color: #991b1b;
            background: #fee2e2;
            border: 1px solid #fecaca;
            border-radius: 12px;
            padding: 12px 14px;
            font-size: 14px;
            margin-top: 10px;
        }

        @media (max-width: 1200px) {
            .stats-grid {
                grid-template-columns: repeat(2, minmax(160px, 1fr));
            }

            .details-grid {
                grid-template-columns: 1fr;
            }

            .two-col {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 950px) {
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
                width: 100%;
                padding: 18px;
            }

            .compact-form-grid,
            .filter-grid,
            .schedule-grid {
                grid-template-columns: 1fr;
            }

            .toolbar-actions {
                grid-column: 1 / -1;
            }
        }

        @media (max-width: 640px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }

            .panel,
            .modal-header,
            .modal-body {
                padding-left: 16px;
                padding-right: 16px;
            }

            .btn {
                width: 100%;
            }

            .action-row,
            .modal-actions {
                flex-direction: column;
            }

            .admin-main {
                padding: 14px;
            }

            .modal-backdrop {
                padding: 10px;
            }

            .leave-panel {
                max-height: none;
            }

            .leave-list {
                max-height: 520px;
                padding-right: 2px;
            }

            .leave-list .mini-card {
                grid-template-columns: 1fr;
                align-items: stretch;
            }

            .leave-actions {
                justify-content: stretch;
            }

            .leave-actions .btn,
            .leave-actions form {
                width: 100%;
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
            <p>Owner Portal</p>
        </div>

        <nav class="admin-nav">
            <a href="admin_dashboard.php">Dashboard</a>
            <a href="admin_appointments.php">Appointments</a>
            <a href="admin_walkins.php">Walk-in Queue</a>
            <a href="admin_services.php">Services</a>
            <a href="admin_barbers.php" class="active">Barbers</a>
            <a href="admin_staff.php">Staff</a>
            <a href="admin_reviews.php">Reviews</a>
            <a href="admin_reports.php">Reports</a>
            <a href="admin_ar_models.php">AR Hair Models</a>
            <a href="admin_settings.php">Settings</a>
            <a href="admin_logout.php">Logout</a>
        </nav>
    </aside>

    <main class="admin-main">
        <div class="page-header">
            <div>
                <h1>Manage Barbers</h1>
                <p>View, add, and update barber information and work schedules.</p>
            </div>
        </div>

        <?php if ($message !== ''): ?>
            <div class="alert <?= $message_type === 'success' ? 'alert-success' : 'alert-error' ?>">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <section class="stats-grid">
            <div class="stat-card">
                <h3>Total Barbers</h3>
                <div class="value"><?= $summary['total_barbers'] ?></div>
            </div>
            <div class="stat-card">
                <h3>Active</h3>
                <div class="value"><?= $summary['active_barbers'] ?></div>
            </div>
            <div class="stat-card">
                <h3>Inactive</h3>
                <div class="value"><?= $summary['inactive_barbers'] ?></div>
            </div>
            <div class="stat-card">
                <h3>Total Reviews</h3>
                <div class="value"><?= $summary['total_reviews'] ?></div>
            </div>
        </section>

        <section class="panel">
            <h2>Add New Barber</h2>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="add">

                <div class="compact-form-grid">
                    <div class="form-group">
                        <label>Name</label>
                        <input type="text" name="name" required>
                    </div>

                    <div class="form-group">
                        <label>Title</label>
                        <input type="text" name="title" required>
                    </div>

                    <div class="form-group">
                        <label>Status</label>
                        <select name="status">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Email</label>
                        <input type="email" name="email" required>
                    </div>

                    <div class="form-group">
                        <label>Password</label>
                        <input type="password" name="password" required>
                    </div>

                    <div class="form-group">
                        <label>Barber Image</label>
                        <input type="file" name="image_path" accept="image/*" required>
                    </div>
                </div>

                <div class="muted" style="margin-top:8px;">
                    Password must be at least 8 characters long and include at least 1 uppercase letter and 1 special character.
                </div>

                <div class="action-row" style="margin-top:16px;">
                    <button type="submit" class="btn btn-primary">Add Barber</button>
                </div>
            </form>
        </section>

        <section class="panel">
            <form method="GET" class="filter-grid">
                <div class="form-group">
                    <label for="search">Search</label>
                    <input
                        type="text"
                        name="search"
                        id="search"
                        value="<?= htmlspecialchars($search_filter) ?>"
                        placeholder="Search by barber name, email, or title"
                    >
                </div>

                <div class="form-group">
                    <label for="status">Status</label>
                    <select name="status" id="status">
                        <option value="">All Status</option>
                        <option value="active" <?= $status_filter === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="inactive" <?= $status_filter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="sort">Sort By</label>
                    <select name="sort" id="sort">
                        <option value="name_asc" <?= $sort_filter === 'name_asc' ? 'selected' : '' ?>>Name A-Z</option>
                        <option value="name_desc" <?= $sort_filter === 'name_desc' ? 'selected' : '' ?>>Name Z-A</option>
                        <option value="rating_desc" <?= $sort_filter === 'rating_desc' ? 'selected' : '' ?>>Highest Rating</option>
                        <option value="reviews_desc" <?= $sort_filter === 'reviews_desc' ? 'selected' : '' ?>>Most Reviews</option>
                        <option value="status_active" <?= $sort_filter === 'status_active' ? 'selected' : '' ?>>Active First</option>
                    </select>
                </div>

                <div class="toolbar-actions">
                    <button type="submit" class="btn btn-primary">Apply Filters</button>
                    <a href="admin_barbers.php" class="btn btn-secondary">Reset</a>
                </div>
            </form>
        </section>

        <section class="panel">
            <div class="table-head">
                <div>
                    <h2>Barbers Directory</h2>
                    <p>Open a barber record to review schedules, bookings, and customer ratings.</p>
                </div>
            </div>

            <?php if ($barbers && $barbers->num_rows > 0): ?>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Barber</th>
                                <th>Status</th>
                                <th>Rating</th>
                                <th>Reviews</th>
                                <th>Next Booking</th>
                                <th style="width: 140px;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($barber = $barbers->fetch_assoc()): ?>
                                <?php
                                $barberId = (int)$barber['id'];

                                $scheduleStmt = $conn->prepare("
                                    SELECT *
                                    FROM barber_schedules
                                    WHERE barber_id = ?
                                    ORDER BY FIELD(day_of_week,'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday')
                                ");
                                $scheduleStmt->bind_param("i", $barberId);
                                $scheduleStmt->execute();
                                $scheduleResult = $scheduleStmt->get_result();

                                $scheduleMap = [];
                                while ($sched = $scheduleResult->fetch_assoc()) {
                                    $scheduleMap[$sched['day_of_week']] = $sched;
                                }
                                $scheduleStmt->close();

                                $appointmentStmt = $conn->prepare("
                                    SELECT 
                                        a.start_time,
                                        a.end_time,
                                        a.status,
                                        CONCAT(u.first_name, ' ', u.last_name) AS customer_name
                                    FROM appointments a
                                    JOIN users u ON a.customer_id = u.id
                                    WHERE a.barber_id = ?
                                      AND a.status NOT IN ('completed', 'no-show', 'canceled', 'cancelled', 'rejected')
                                      AND a.start_time >= NOW()
                                    ORDER BY a.start_time ASC
                                    LIMIT 8
                                ");
                                $appointmentStmt->bind_param("i", $barberId);
                                $appointmentStmt->execute();
                                $appointmentResult = $appointmentStmt->get_result();

                                $upcomingBookings = [];
                                while ($appt = $appointmentResult->fetch_assoc()) {
                                    $upcomingBookings[] = $appt;
                                }
                                $appointmentStmt->close();

                                $nextBookingText = 'No upcoming booking';
                                if (!empty($upcomingBookings)) {
                                    $nextBookingText = date("M j, Y g:i A", strtotime($upcomingBookings[0]['start_time']));
                                }

                                $reviewStmt = $conn->prepare("
                                    SELECT 
                                        br.id,
                                        br.rating,
                                        br.comment,
                                        br.created_at,
                                        CONCAT(u.first_name, ' ', u.last_name) AS customer_name
                                    FROM barber_ratings br
                                    JOIN users u ON br.customer_id = u.id
                                    WHERE br.barber_id = ?
                                    ORDER BY br.created_at DESC
                                    LIMIT 20
                                ");
                                $reviewStmt->bind_param("i", $barberId);
                                $reviewStmt->execute();
                                $reviewResult = $reviewStmt->get_result();

                                $barberReviews = [];
                                $latestReviewSnippet = 'No review yet';
                                while ($review = $reviewResult->fetch_assoc()) {
                                    $barberReviews[] = $review;
                                }
                                $reviewStmt->close();

                                if (!empty($barberReviews)) {
                                    $latestReviewSnippet = !empty($barberReviews[0]['comment'])
                                        ? $barberReviews[0]['comment']
                                        : 'Rated ' . (int)$barberReviews[0]['rating'] . '/5';
                                }

                                ob_start();
                                ?>
                                <div class="details-grid">
                                    <div class="profile-card">
                                        <img src="images/<?= htmlspecialchars($barber['image_path']) ?>" alt="<?= htmlspecialchars($barber['name']) ?>">
                                        <h4><?= htmlspecialchars($barber['name']) ?></h4>
                                        <p><strong>Title:</strong> <?= htmlspecialchars($barber['title']) ?></p>
                                        <p><strong>Email:</strong> <?= htmlspecialchars($barber['email']) ?></p>
                                        <p>
                                            <strong>Status:</strong>
                                            <span class="status-tag <?= $barber['status'] === 'active' ? 'status-active' : 'status-inactive' ?>">
                                                <?= htmlspecialchars(ucfirst($barber['status'])) ?>
                                            </span>
                                        </p>
                                        <p><strong>Average Rating:</strong> <?= number_format($barber['avg_rating'], 1) ?> / 5</p>
                                        <p class="rating-stars"><?= htmlspecialchars(renderStars($barber['avg_rating'])) ?></p>
                                        <p><strong>Total Reviews:</strong> <?= (int)$barber['total_reviews'] ?></p>

                                        <button
                                            type="button"
                                            class="btn btn-delete open-delete-barber-modal"
                                            data-barber-id="<?= $barberId ?>"
                                            data-barber-name="<?= htmlspecialchars($barber['name'], ENT_QUOTES) ?>"
                                            style="margin-top:16px;"
                                        >
                                            Delete Barber
                                        </button>
                                    </div>

                                    <div class="section-stack">
                                        <div class="details-card">
                                            <h4>Edit Barber</h4>
                                            <form method="POST" enctype="multipart/form-data">
                                                <input type="hidden" name="action" value="update">
                                                <input type="hidden" name="barber_id" value="<?= $barberId ?>">
                                                <input type="hidden" name="old_image" value="<?= htmlspecialchars($barber['image_path']) ?>">

                                                <div class="compact-form-grid" style="grid-template-columns:repeat(2,minmax(0,1fr));">
                                                    <div class="form-group">
                                                        <label>Name</label>
                                                        <input type="text" name="name" value="<?= htmlspecialchars($barber['name']) ?>" required>
                                                    </div>

                                                    <div class="form-group">
                                                        <label>Title</label>
                                                        <input type="text" name="title" value="<?= htmlspecialchars($barber['title']) ?>" required>
                                                    </div>

                                                    <div class="form-group">
                                                        <label>Email</label>
                                                        <input type="email" name="email" value="<?= htmlspecialchars($barber['email']) ?>" required>
                                                    </div>

                                                    <div class="form-group">
                                                        <label>Status</label>
                                                        <select name="status">
                                                            <option value="active" <?= $barber['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                                                            <option value="inactive" <?= $barber['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                                                        </select>
                                                    </div>

                                                    <div class="form-group">
                                                        <label>New Password</label>
                                                        <input type="password" name="password" placeholder="Leave blank to keep current password">
                                                    </div>

                                                    <div class="form-group">
                                                        <label>Change Image</label>
                                                        <input type="file" name="image_path" accept="image/*">
                                                    </div>
                                                </div>

                                                <div class="muted" style="margin-top:8px;">
                                                    Leave password blank to keep the current one. If changed, it must be at least 8 characters long and include at least 1 uppercase letter and 1 special character.
                                                </div>

                                                <div class="action-row" style="margin-top:16px;">
                                                    <button type="submit" class="btn btn-update">Save Changes</button>
                                                </div>
                                            </form>
                                        </div>

                                        <div class="two-col">
                                            <div class="details-card">
                                                <h4>Weekly Schedule</h4>

                                                <form method="POST" style="margin-bottom:16px;">
                                                    <input type="hidden" name="action" value="save_schedule">
                                                    <input type="hidden" name="barber_id" value="<?= $barberId ?>">

                                                    <div class="compact-form-grid" style="grid-template-columns:repeat(3,minmax(0,1fr));">
                                                        <div class="form-group">
                                                            <label>Day</label>
                                                            <select name="day_of_week" required>
                                                                <option value="Monday">Monday</option>
                                                                <option value="Tuesday">Tuesday</option>
                                                                <option value="Wednesday">Wednesday</option>
                                                                <option value="Thursday">Thursday</option>
                                                                <option value="Friday">Friday</option>
                                                                <option value="Saturday">Saturday</option>
                                                                <option value="Sunday">Sunday</option>
                                                            </select>
                                                        </div>

                                                        <div class="form-group">
                                                            <label>Start Time</label>
                                                            <input type="time" name="start_time" required>
                                                        </div>

                                                        <div class="form-group">
                                                            <label>End Time</label>
                                                            <input type="time" name="end_time" required>
                                                        </div>
                                                    </div>

                                                    <div class="action-row">
                                                        <button type="submit" class="btn btn-primary">Save Schedule</button>
                                                    </div>
                                                </form>

                                                <div class="schedule-grid">
                                                    <?php
                                                    $days = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];
                                                    foreach ($days as $day):
                                                    ?>
                                                        <div class="schedule-day-card">
                                                            <strong><?= $day ?></strong>

                                                            <?php if (isset($scheduleMap[$day])): ?>
                                                                <div>
                                                                    <?= date("g:i A", strtotime($scheduleMap[$day]['start_time'])) ?>
                                                                    -
                                                                    <?= date("g:i A", strtotime($scheduleMap[$day]['end_time'])) ?>
                                                                </div>

                                                                <form method="POST" style="margin-top:10px;">
                                                                    <input type="hidden" name="action" value="delete_schedule">
                                                                    <input type="hidden" name="schedule_id" value="<?= (int)$scheduleMap[$day]['id'] ?>">
                                                                    <button type="submit" class="btn btn-delete">Delete</button>
                                                                </form>
                                                            <?php else: ?>
                                                                <div class="muted">No schedule set</div>
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>

                                            <div class="details-card">
                                                <h4>Upcoming Bookings</h4>
                                                <?php if (!empty($upcomingBookings)): ?>
                                                    <div class="review-list">
                                                        <?php foreach ($upcomingBookings as $appt): ?>
                                                            <div class="booking-card">
                                                                <h5><?= htmlspecialchars($appt['customer_name']) ?></h5>
                                                                <p><?= date("M j, Y", strtotime($appt['start_time'])) ?></p>
                                                                <p><?= date("g:i A", strtotime($appt['start_time'])) ?> - <?= date("g:i A", strtotime($appt['end_time'])) ?></p>
                                                                <p class="muted">Status: <?= htmlspecialchars($appt['status']) ?></p>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                <?php else: ?>
                                                    <div class="booking-card">No upcoming bookings for this barber.</div>
                                                <?php endif; ?>
                                            </div>
                                        </div>

                                        <div class="details-card">
                                            <h4>Customer Reviews & Ratings</h4>
                                            <?php if (!empty($barberReviews)): ?>
                                                <div class="review-list">
                                                    <?php foreach ($barberReviews as $review): ?>
                                                        <div class="review-card">
                                                            <h5><?= htmlspecialchars($review['customer_name']) ?></h5>
                                                            <p class="stars">Rating: <?= (int)$review['rating'] ?>/5</p>
                                                            <p class="rating-stars"><?= htmlspecialchars(renderStars($review['rating'])) ?></p>
                                                            <p><?= !empty($review['comment']) ? htmlspecialchars($review['comment']) : 'No comment provided.' ?></p>
                                                            <p class="muted"><?= date("F j, Y g:i A", strtotime($review['created_at'])) ?></p>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php else: ?>
                                                <div class="review-card">No customer reviews yet for this barber.</div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <?php
                                $modalContent = ob_get_clean();
                                ?>
                                <tr>
                                    <td>
                                        <div class="barber-identity">
                                            <img src="images/<?= htmlspecialchars($barber['image_path']) ?>" alt="<?= htmlspecialchars($barber['name']) ?>" class="barber-thumb">
                                            <div class="barber-meta">
                                                <strong><?= htmlspecialchars($barber['name']) ?></strong>
                                                <span><?= htmlspecialchars($barber['title']) ?></span><br>
                                                <span><?= htmlspecialchars($barber['email']) ?></span>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="status-tag <?= $barber['status'] === 'active' ? 'status-active' : 'status-inactive' ?>">
                                            <?= htmlspecialchars(ucfirst($barber['status'])) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="rating-block">
                                            <div class="rating-stars"><?= htmlspecialchars(renderStars($barber['avg_rating'])) ?></div>
                                            <div class="rating-inline">
                                                <strong><?= number_format($barber['avg_rating'], 1) ?>/5</strong>
                                                <span class="muted">Average</span>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="rating-block">
                                            <strong><?= (int)$barber['total_reviews'] ?></strong>
                                            <div class="review-preview muted"><?= htmlspecialchars(mb_strimwidth($latestReviewSnippet, 0, 60, '...')) ?></div>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="muted"><?= htmlspecialchars($nextBookingText) ?></div>
                                    </td>
                                    <td>
                                        <button
                                            type="button"
                                            class="btn btn-view open-details-modal"
                                            data-title="<?= htmlspecialchars($barber['name'], ENT_QUOTES) ?>"
                                            data-subtitle="<?= htmlspecialchars($barber['title'] . ' • ' . number_format($barber['avg_rating'], 1) . '/5 rating', ENT_QUOTES) ?>"
                                            data-content-id="barber-detail-<?= $barberId ?>"
                                        >
                                            View Details
                                        </button>
                                        <div class="hidden-detail" id="barber-detail-<?= $barberId ?>">
                                            <?= $modalContent ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <h3 style="margin-top:0; color:#111827;">No barbers found</h3>
                    <p>Try changing your filters or add a new barber above.</p>
                </div>
            <?php endif; ?>
        </section>

        <section class="panel leave-panel">
            <div class="table-head">
                <div>
                    <h2>Barber Leave Requests</h2>
                    
                </div>
            </div>
            <div class="leave-list">
                <?php if ($leaveTableExists): ?>
                    <?php if ($leaveRequests && $leaveRequests->num_rows > 0): ?>
                        <?php while ($leave = $leaveRequests->fetch_assoc()): ?>
                            <div class="mini-card">
                                <div class="leave-name">
                                    <strong><?= htmlspecialchars($leave['barber_name']) ?></strong>
                                    <span>Leave request</span>
                                </div>

                                <div class="leave-date">
                                    <strong>Date:</strong><br>
                                    <?= htmlspecialchars(date('M j, Y', strtotime($leave['leave_date']))) ?>
                                </div>

                                <div class="leave-reason">
                                    <strong>Reason:</strong>
                                    <?= htmlspecialchars($leave['reason'] ?? 'No reason provided') ?>

                                    <?php if ($hasLeaveRejectionReason && $leave['status'] === 'rejected' && !empty($leave['rejection_reason'])): ?>
                                        <span class="muted" style="display:block; margin-top:6px;">
                                            Rejection Reason: <?= htmlspecialchars($leave['rejection_reason']) ?>
                                        </span>
                                    <?php endif; ?>
                                </div>

                                <div class="leave-actions">
                                    <span class="leave-status <?=
                                        $leave['status'] === 'approved' ? 'status-approved' :
                                        ($leave['status'] === 'rejected' ? 'status-rejected' : 'status-pending')
                                    ?>">
                                        <?= htmlspecialchars(ucfirst($leave['status'])) ?>
                                    </span>

                                    <?php if ($leave['status'] === 'pending'): ?>
                                        <form method="POST">
                                            <input type="hidden" name="action" value="approve_leave">
                                            <input type="hidden" name="leave_id" value="<?= (int)$leave['id'] ?>">
                                            <button type="submit" class="btn btn-approve">Approve</button>
                                        </form>

                                        <button
                                            type="button"
                                            class="btn btn-reject open-reject-modal"
                                            data-leave-id="<?= (int)$leave['id'] ?>"
                                            data-barber-name="<?= htmlspecialchars($leave['barber_name'], ENT_QUOTES) ?>"
                                            data-leave-date="<?= htmlspecialchars($leave['leave_date'], ENT_QUOTES) ?>"
                                        >
                                            Reject
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="mini-card">No leave requests yet.</div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="mini-card">Create the <strong>barber_leaves</strong> table first to use leave requests.</div>
                <?php endif; ?>
            </div>
        </section>
    </main>
</div>

<div class="modal-backdrop" id="barberDetailsModal">
    <div class="modal">
        <div class="modal-header">
            <h3 id="barberModalTitle">Barber Details</h3>
            <p id="barberModalSubtitle"></p>
            <button type="button" class="modal-close" data-close-modal="barberDetailsModal">&times;</button>
        </div>
        <div class="modal-body" id="barberModalBody"></div>
    </div>
</div>

<div class="modal-backdrop" id="deleteBarberModal">
    <div class="modal modal-sm">
        <div class="modal-header">
            <h3>Delete Barber</h3>
            <p id="deleteBarberSubtitle">This action will permanently remove the selected barber account.</p>
            <button type="button" class="modal-close" data-close-modal="deleteBarberModal">&times;</button>
        </div>
        <div class="modal-body">
            <form method="POST">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="barber_id" id="deleteBarberId">

                <div class="warning-text">
                    Are you sure you want to delete <strong id="deleteBarberName">this barber</strong>? This action cannot be undone.
                </div>

                <div class="modal-actions" style="margin-top:18px;">
                    <button type="submit" class="btn btn-delete">Yes, Delete</button>
                    <button type="button" class="btn btn-secondary" data-close-modal="deleteBarberModal">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal-backdrop" id="rejectLeaveModal">
    <div class="modal modal-sm">
        <div class="modal-header">
            <h3>Reject Leave Request</h3>
            <p id="rejectLeaveSubtitle">Provide a reason for rejecting this leave request.</p>
            <button type="button" class="modal-close" data-close-modal="rejectLeaveModal">&times;</button>
        </div>
        <div class="modal-body">
            <form method="POST">
                <input type="hidden" name="action" value="reject_leave">
                <input type="hidden" name="leave_id" id="rejectLeaveId">

                <div class="form-group">
                    <label for="rejection_reason">Rejection Reason</label>
                    <textarea
                        name="rejection_reason"
                        id="rejection_reason"
                        rows="5"
                        placeholder="Enter the reason for rejection..."
                        required
                    ></textarea>
                </div>

                <div class="modal-actions">
                    <button type="submit" class="btn btn-reject">Confirm Rejection</button>
                    <button type="button" class="btn btn-secondary" data-close-modal="rejectLeaveModal">Cancel</button>
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
    const adminSidebar = document.getElementById('adminSidebar');
    const sidebarOverlay = document.getElementById('sidebarOverlay');
    adminSidebar.classList.add('show');
    sidebarOverlay.classList.add('show');
    document.body.classList.add('sidebar-open');
}

function closeSidebar() {
    const adminSidebar = document.getElementById('adminSidebar');
    const sidebarOverlay = document.getElementById('sidebarOverlay');
    adminSidebar.classList.remove('show');
    sidebarOverlay.classList.remove('show');
    document.body.classList.remove('sidebar-open');
}

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
        document.getElementById('barberModalTitle').textContent = detailBtn.getAttribute('data-title') || 'Barber Details';
        document.getElementById('barberModalSubtitle').textContent = detailBtn.getAttribute('data-subtitle') || '';
        document.getElementById('barberModalBody').innerHTML = contentNode ? contentNode.innerHTML : '';
        openModal('barberDetailsModal');
        return;
    }

    const deleteBtn = e.target.closest('.open-delete-barber-modal');
    if (deleteBtn) {
        const barberId = deleteBtn.getAttribute('data-barber-id') || '';
        const barberName = deleteBtn.getAttribute('data-barber-name') || 'this barber';

        document.getElementById('deleteBarberId').value = barberId;
        document.getElementById('deleteBarberName').textContent = barberName;
        document.getElementById('deleteBarberSubtitle').textContent =
            'You are about to permanently delete the account of ' + barberName + '.';

        openModal('deleteBarberModal');
        return;
    }

    const rejectBtn = e.target.closest('.open-reject-modal');
    if (rejectBtn) {
        document.getElementById('rejectLeaveId').value = rejectBtn.getAttribute('data-leave-id') || '';
        document.getElementById('rejection_reason').value = '';
        document.getElementById('rejectLeaveSubtitle').textContent =
            'Reject leave request for ' +
            (rejectBtn.getAttribute('data-barber-name') || 'this barber') +
            ' on ' +
            (rejectBtn.getAttribute('data-leave-date') || 'the selected date') +
            '.';
        openModal('rejectLeaveModal');
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

const menuBtn = document.getElementById('menuBtn');
const sidebarOverlay = document.getElementById('sidebarOverlay');

if (menuBtn) {
    menuBtn.addEventListener('click', function () {
        openSidebar();
    });
}

if (sidebarOverlay) {
    sidebarOverlay.addEventListener('click', function () {
        closeSidebar();
    });
}

window.addEventListener('resize', function () {
    if (window.innerWidth > 950) {
        closeSidebar();
    }
});
</script>

</body>
</html>