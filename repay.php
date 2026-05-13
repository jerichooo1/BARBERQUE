<?php
session_start();
require 'db.php';

if (!isset($_SESSION['user_id'])) {
  header("Location: login.php");
  exit();
}

$user_id = $_SESSION['user_id'];

/* Handle form submission */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $appointment_id = intval($_POST['appointment_id'] ?? 0);
  $new_ref = trim($_POST['new_ref'] ?? '');

  if ($appointment_id <= 0 || $new_ref === '') {
    echo "<script>alert('Please complete all required fields.'); history.back();</script>";
    exit;
  }

  if (!isset($_FILES['proof_image']) || $_FILES['proof_image']['error'] !== 0) {
    echo "<script>alert('Please upload your proof of payment screenshot.'); history.back();</script>";
    exit;
  }

  /* Validate appointment ownership */
  $stmt = $conn->prepare("SELECT id FROM appointments WHERE id = ? AND customer_id = ?");
  $stmt->bind_param("ii", $appointment_id, $user_id);
  $stmt->execute();
  $check = $stmt->get_result();

  if ($check->num_rows === 0) {
    echo "<script>alert('Invalid or unauthorized appointment.'); window.location='appointments.php';</script>";
    exit;
  }
  $stmt->close();

  /* Get latest payment details */
  $stmt = $conn->prepare("
    SELECT total_amount, downpayment_amount
    FROM payments
    WHERE appointment_id = ?
    ORDER BY created_at DESC
    LIMIT 1
  ");
  $stmt->bind_param("i", $appointment_id);
  $stmt->execute();
  $payment = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$payment) {
    echo "<script>alert('No existing payment record found for this appointment.'); window.location='appointments.php';</script>";
    exit;
  }

  /* Upload screenshot */
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
    echo "<script>alert('Invalid file type. Only JPG, JPEG, PNG, and WEBP are allowed.'); history.back();</script>";
    exit;
  }

  if ($file_size > 5 * 1024 * 1024) {
    echo "<script>alert('File is too large. Maximum size is 5MB.'); history.back();</script>";
    exit;
  }

  $new_file_name = 'repay_' . $appointment_id . '_' . time() . '.' . $file_ext;
  $target_path = $upload_dir . $new_file_name;

  if (!move_uploaded_file($tmp_name, $target_path)) {
    echo "<script>alert('Failed to upload proof of payment.'); history.back();</script>";
    exit;
  }

  /* Insert new repayment record */
  $insert = $conn->prepare("
    INSERT INTO payments (
      appointment_id,
      total_amount,
      downpayment_amount,
      method,
      reference_number,
      proof_image,
      status,
      is_repayment
    )
    VALUES (?, ?, ?, 'gcash', ?, ?, 'pending', 1)
  ");
  $insert->bind_param(
    "iddss",
    $appointment_id,
    $payment['total_amount'],
    $payment['downpayment_amount'],
    $new_ref,
    $new_file_name
  );
  $insert->execute();
  $insert->close();

  /* Update appointment status back to pending approval */
  $update = $conn->prepare("UPDATE appointments SET status = 'pending approval' WHERE id = ?");
  $update->bind_param("i", $appointment_id);
  $update->execute();
  $update->close();

  echo "<script>alert('Payment proof submitted. Please wait for staff review.'); window.location='appointments.php';</script>";
  exit;
}

/* If opened directly by link */
if (isset($_GET['appointment_id'])) {
  $appointment_id = intval($_GET['appointment_id']);
} else {
  header("Location: appointments.php");
  exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Pay Again | Fade Street</title>
  <link rel="stylesheet" href="styles.css">
  <style>
    body {
      background-color: #f8f8f8;
    }

    .repay-container {
      max-width: 500px;
      margin: 120px auto;
      background: #fff;
      padding: 2rem;
      border-radius: 15px;
      border: 2px solid #A70202;
      box-shadow: 0 6px 14px rgba(0, 0, 0, 0.1);
    }

    .repay-container h2 {
      color: #A70202;
      text-align: center;
      margin-bottom: 1.5rem;
    }

    label {
      display: block;
      margin-top: 1rem;
      font-weight: bold;
    }

    input[type="text"],
    input[type="file"] {
      width: 100%;
      padding: 0.6rem;
      border-radius: 8px;
      border: 1px solid #ccc;
      margin-top: 0.3rem;
      box-sizing: border-box;
    }

    button {
      margin-top: 1.5rem;
      width: 100%;
      padding: 0.7rem;
      background-color: #A70202;
      color: white;
      border: none;
      border-radius: 8px;
      font-size: 1rem;
      cursor: pointer;
      transition: transform 0.2s ease, box-shadow 0.2s ease;
    }

    button:hover {
      transform: translateY(-2px);
      box-shadow: 0 5px 10px rgba(0,0,0,0.2);
    }

    .back-link {
      display: block;
      text-align: center;
      margin-top: 1rem;
      color: #333;
      text-decoration: none;
    }
  </style>
</head>
<body>
  <?php include 'navbar.php'; ?>

  <div class="repay-container">
    <h2>Pay Again</h2>

    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="appointment_id" value="<?= htmlspecialchars($appointment_id) ?>">

      <label for="new_ref">New Reference Number:</label>
      <input type="text" id="new_ref" name="new_ref" placeholder="Enter new GCash Ref No." required>
      
      <br><br>

      <label for="proof_image">Upload Proof of Payment:</label>
      <input type="file" id="proof_image" name="proof_image" accept="image/*" required>

      <button type="submit">Submit</button>
    </form>

    <a href="appointments.php" class="back-link">← Back to Appointments</a>
  </div>

  <?php include 'footer.php'; ?>
</body>
</html>