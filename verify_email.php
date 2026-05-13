<?php
session_start();
require 'db.php';

$message = "";
$status = "error";

$token = $_GET['token'] ?? '';

if (!$token) {
    $message = "Invalid verification link.";
} else {
    $stmt = $conn->prepare("
        SELECT id, first_name 
        FROM users 
        WHERE verification_token = ? 
        AND verification_expires > NOW()
        AND email_verified = 0
        LIMIT 1
    ");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($user = $result->fetch_assoc()) {
        $update = $conn->prepare("
            UPDATE users 
            SET email_verified = 1, verification_token = NULL, verification_expires = NULL 
            WHERE id = ?
        ");
        $update->bind_param("i", $user['id']);

        if ($update->execute()) {
            $status = "success";
            $message = "Your email has been verified successfully. You can now login.";
        } else {
            $message = "Something went wrong while verifying your email.";
        }

        $update->close();
    } else {
        $message = "This verification link is invalid, expired, or already used.";
    }

    $stmt->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Email Verification</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">

  <style>
    body {
      margin: 0;
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      background: #f4f7fb;
      font-family: Arial, sans-serif;
      padding: 20px;
    }

    .verify-card {
      width: 100%;
      max-width: 460px;
      background: #fff;
      border-radius: 18px;
      padding: 32px;
      text-align: center;
      box-shadow: 0 20px 45px rgba(15, 23, 42, 0.12);
      border: 1px solid #e2e8f0;
    }

    .verify-icon {
      width: 64px;
      height: 64px;
      margin: 0 auto 18px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 30px;
      font-weight: bold;
    }

    .success {
      background: #ecfdf5;
      color: #047857;
    }

    .error {
      background: #fff1f2;
      color: #dc2626;
    }

    h1 {
      margin: 0 0 12px;
      color: #0f172a;
      font-size: 1.6rem;
    }

    p {
      color: #475569;
      line-height: 1.6;
      margin-bottom: 24px;
    }

    a {
      display: inline-block;
      background: #2563eb;
      color: white;
      text-decoration: none;
      padding: 13px 22px;
      border-radius: 12px;
      font-weight: bold;
    }
  </style>
</head>
<body>

<div class="verify-card">
  <div class="verify-icon <?= $status ?>">
    <?= $status === "success" ? "✓" : "!" ?>
  </div>

  <h1><?= $status === "success" ? "Email Verified" : "Verification Failed" ?></h1>

  <p><?= htmlspecialchars($message) ?></p>

  <a href="login.php">Go to Login</a>
</div>

</body>
</html>