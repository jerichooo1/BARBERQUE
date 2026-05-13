<?php
session_start();
include 'db.php';

if (!isset($_SESSION['admin_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: admin_staff_login.php");
    exit();
}

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die("Invalid hairstyle ID.");
}

$id = intval($_GET['id']);

$stmt = $conn->prepare("SELECT image_path FROM hairstyles WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();
$hair = $result->fetch_assoc();

if ($hair) {
    if (!empty($hair['image_path']) && file_exists($hair['image_path'])) {
        unlink($hair['image_path']);
    }

    $delete = $conn->prepare("DELETE FROM hairstyles WHERE id = ?");
    $delete->bind_param("i", $id);
    $delete->execute();
}

header("Location: admin_ar_models.php");
exit();
