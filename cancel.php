<?php
session_start();
include 'db.php';

if (!isset($_SESSION['user_id'])) {
    die("You must be logged in.");
}

if (!isset($_GET['appointment_id'])) {
    die("No appointment selected.");
}

$appointment_id = intval($_GET['appointment_id']);
$customer_id = $_SESSION['user_id'];

// ✅ Update status to canceled (only if it belongs to this customer)
$stmt = $conn->prepare("
    UPDATE appointments 
    SET status = 'canceled' 
    WHERE id = ? AND customer_id = ? AND status = 'approved'
");
$stmt->bind_param("ii", $appointment_id, $customer_id);
$stmt->execute();

if ($stmt->affected_rows > 0) {
    // success
    header("Location: appointments.php?msg=canceled");
    exit;
} else {
    // either not found, not owned by user, or already canceled
    header("Location: appointments.php?msg=cancel_failed");
    exit;
}
