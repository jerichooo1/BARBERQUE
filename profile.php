<?php
session_start();
require 'db.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'PHPMailer/src/Exception.php';
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';

if (!isset($_SESSION['user_id'])) {
  header("Location: login.php");
  exit;
}

$user_id = $_SESSION['user_id'];

$message = "";
$pass_message = "";

$profileErrors = [];
$passwordErrors = [];

$settings = $conn->query("SELECT * FROM settings LIMIT 1");
$shop = $settings ? $settings->fetch_assoc() : [];

$shopName = $shop['shop_name'] ?? 'Fade Street Hair Studio';
$smtpEmail = trim($shop['smtp_email'] ?? '');
$smtpPassword = trim($shop['smtp_password'] ?? '');
$smtpFromName = trim($shop['smtp_from_name'] ?? $shopName);

function formatName($name) {
  $name = trim($name);
  $name = preg_replace('/\s+/', ' ', $name);
  return ucwords(strtolower($name));
}

function fieldClass($error) {
  return $error ? 'field-error' : '';
}

function sendVerificationEmail($toEmail, $toName, $token, $shopName, $smtpEmail, $smtpPassword, $smtpFromName) {
  if ($smtpEmail === '' || $smtpPassword === '') {
    throw new Exception("SMTP settings are not configured.");
  }

  $mail = new PHPMailer(true);
  $verifyLink = "http://localhost/BARBERQUE/verify_email.php?token=" . urlencode($token);

  $mail->isSMTP();
  $mail->Host = 'smtp.gmail.com';
  $mail->SMTPAuth = true;
  $mail->Username = $smtpEmail;
  $mail->Password = $smtpPassword;
  $mail->SMTPSecure = 'tls';
  $mail->Port = 587;

  $mail->setFrom($smtpEmail, $smtpFromName);
  $mail->addAddress($toEmail, $toName);

  $mail->isHTML(true);
  $mail->Subject = "Verify your updated email address";

  $mail->Body = "
    <div style='font-family:Arial,sans-serif;background:#f4f7fb;padding:30px;'>
      <div style='max-width:600px;margin:auto;background:#ffffff;border-radius:16px;padding:28px;border:1px solid #dbe3ef;'>
        <h2 style='color:#0f172a;margin-top:0;'>Verify your email address</h2>

        <p style='color:#475569;font-size:15px;line-height:1.6;'>
          Hi " . htmlspecialchars($toName) . ", you recently changed your email address for your <strong>" . htmlspecialchars($shopName) . "</strong> account.
          Please verify this new email address before logging in again.
        </p>

        <div style='text-align:center;margin:30px 0;'>
          <a href='{$verifyLink}'
             style='background:#0b3d91;color:#ffffff;text-decoration:none;padding:14px 24px;border-radius:999px;font-weight:bold;display:inline-block;'>
            Verify Email
          </a>
        </div>

        <p style='color:#64748b;font-size:13px;line-height:1.6;'>
          This verification link will expire in 24 hours.
        </p>

        <p style='color:#64748b;font-size:13px;line-height:1.6;'>
          If the button does not work, copy and paste this link into your browser:<br>
          <a href='{$verifyLink}' style='color:#0b3d91;'>{$verifyLink}</a>
        </p>
      </div>
    </div>
  ";

  $mail->AltBody = "Verify your updated email address here: {$verifyLink}";
  $mail->send();
}

// -------------------- FETCH CURRENT USER BEFORE UPDATES --------------------
$stmt = $conn->prepare("SELECT * FROM users WHERE id=?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
  session_destroy();
  header("Location: login.php");
  exit;
}

// -------------------- UPDATE PROFILE --------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
  $first = formatName($_POST['first_name'] ?? '');
  $middle = formatName($_POST['middle_name'] ?? '');
  $last = formatName($_POST['last_name'] ?? '');
  $phone = preg_replace('/\D/', '', $_POST['phone'] ?? '');
  $email = trim($_POST['email'] ?? '');

  if (!$first) {
    $profileErrors['first_name'] = "First name is required.";
  } elseif (!preg_match('/^[a-zA-Z\s]+$/', $first)) {
    $profileErrors['first_name'] = "First name must contain letters only.";
  }

  if ($middle && !preg_match('/^[a-zA-Z\s]+$/', $middle)) {
    $profileErrors['middle_name'] = "Middle name must contain letters only.";
  }

  if (!$last) {
    $profileErrors['last_name'] = "Last name is required.";
  } elseif (!preg_match('/^[a-zA-Z\s]+$/', $last)) {
    $profileErrors['last_name'] = "Last name must contain letters only.";
  }

  if (!$phone) {
    $profileErrors['phone'] = "Phone number is required.";
  } elseif (!preg_match('/^09\d{9}$/', $phone)) {
    $profileErrors['phone'] = "Enter a valid 11-digit PH number starting with 09.";
  }

  if (!$email) {
    $profileErrors['email'] = "Email address is required.";
  } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $profileErrors['email'] = "Enter a valid email address.";
  }

  if (empty($profileErrors)) {
    $check = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
    $check->bind_param("si", $email, $user_id);
    $check->execute();
    $check->store_result();

    if ($check->num_rows > 0) {
      $profileErrors['email'] = "This email is already being used.";
    }

    $check->close();
  }

  if (empty($profileErrors)) {
    $full_name = trim("$first $middle $last");
    $emailChanged = strtolower($email) !== strtolower($user['email']);

    if ($emailChanged) {
      $token = bin2hex(random_bytes(32));
      $expires = date('Y-m-d H:i:s', strtotime('+24 hours'));

      $stmt = $conn->prepare("
        UPDATE users 
        SET first_name=?, middle_name=?, last_name=?, name=?, phone=?, email=?, 
            email_verified=0, verification_token=?, verification_expires=?
        WHERE id=?
      ");

      $stmt->bind_param(
        "ssssssssi",
        $first,
        $middle,
        $last,
        $full_name,
        $phone,
        $email,
        $token,
        $expires,
        $user_id
      );
    } else {
      $stmt = $conn->prepare("
        UPDATE users 
        SET first_name=?, middle_name=?, last_name=?, name=?, phone=?, email=?
        WHERE id=?
      ");

      $stmt->bind_param(
        "ssssssi",
        $first,
        $middle,
        $last,
        $full_name,
        $phone,
        $email,
        $user_id
      );
    }

    if ($stmt->execute()) {
      $_SESSION['first_name'] = $first;

      if ($emailChanged) {
        try {
          sendVerificationEmail($email, $first, $token, $shopName, $smtpEmail, $smtpPassword, $smtpFromName);

          session_destroy();
          header("Location: login.php?verify_required=1");
          exit;
        } catch (Exception $e) {
          $message = "⚠️ Profile updated, but verification email could not be sent. Please contact the shop.";
        }
      } else {
        $message = "✅ Profile updated successfully!";
      }
    } else {
      $message = "❌ Something went wrong.";
    }

    $stmt->close();
  }
}

// -------------------- CHANGE PASSWORD --------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
  $current = $_POST['current_password'] ?? '';
  $new = $_POST['new_password'] ?? '';
  $confirm = $_POST['confirm_password'] ?? '';

  if (!$current) {
    $passwordErrors['current_password'] = "Current password is required.";
  }

  if (!$new) {
    $passwordErrors['new_password'] = "New password is required.";
  } elseif (!preg_match('/^(?=.*[A-Z])(?=.*[!@#$%^&*()_\-+=\[\]{};:,.<>?\/\\\\|`~]).{8,}$/', $new)) {
    $passwordErrors['new_password'] = "New password must have 8 characters, 1 uppercase letter, and 1 special character.";
  }

  if (!$confirm) {
    $passwordErrors['confirm_password'] = "Please confirm your new password.";
  } elseif ($new !== $confirm) {
    $passwordErrors['confirm_password'] = "New passwords do not match.";
  }

  if (empty($passwordErrors)) {
    $stmt = $conn->prepare("SELECT password FROM users WHERE id=?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $stmt->bind_result($hashed);
    $stmt->fetch();
    $stmt->close();

    if (!password_verify($current, $hashed)) {
      $passwordErrors['current_password'] = "Current password is incorrect.";
    } else {
      $new_hash = password_hash($new, PASSWORD_DEFAULT);

      $update = $conn->prepare("UPDATE users SET password=? WHERE id=?");
      $update->bind_param("si", $new_hash, $user_id);

      if ($update->execute()) {
        $pass_message = "✅ Password changed successfully!";
      } else {
        $pass_message = "❌ Failed to update password.";
      }

      $update->close();
    }
  }
}

// -------------------- REFETCH USER INFO --------------------
$stmt = $conn->prepare("SELECT * FROM users WHERE id=?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

$formFirst = $_POST['first_name'] ?? ($user['first_name'] ?? '');
$formMiddle = $_POST['middle_name'] ?? ($user['middle_name'] ?? '');
$formLast = $_POST['last_name'] ?? ($user['last_name'] ?? '');
$formPhone = $_POST['phone'] ?? ($user['phone'] ?? '');
$formEmail = $_POST['email'] ?? ($user['email'] ?? '');
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>My Profile | Fade Street</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="styles.css">

  <style>
    .profile-main {
      min-height: 100vh;
      padding: 150px 24px 70px;
      background:
        radial-gradient(circle at top left, rgba(11, 61, 145, 0.13), transparent 34%),
        linear-gradient(180deg, #ffffff 0%, #f7f9fc 48%, #ffffff 100%);
      box-sizing: border-box;
    }

    .profile-shell {
      width: min(1100px, 100%);
      margin: 0 auto;
      display: grid;
      grid-template-columns: 340px minmax(0, 1fr);
      gap: 26px;
      align-items: start;
    }

    .profile-side-card,
    .profile-container {
      background: #ffffff;
      border: 1px solid #dbe3ef;
      border-radius: 30px;
      box-shadow: 0 20px 50px rgba(7, 23, 47, 0.08);
    }

    .profile-side-card {
      position: sticky;
      top: 125px;
      overflow: hidden;
      padding: 28px;
      background:
        radial-gradient(circle at top right, rgba(11, 61, 145, 0.15), transparent 34%),
        linear-gradient(135deg, #07172f, #061f4a);
      color: #ffffff;
    }

    .profile-avatar {
      width: 86px;
      height: 86px;
      border-radius: 26px;
      background: #ffffff;
      color: #061f4a;
      display: grid;
      place-items: center;
      font-size: 2rem;
      font-weight: 900;
      box-shadow: 0 14px 30px rgba(0, 0, 0, 0.18);
      margin-bottom: 18px;
    }

    .profile-side-card h1 {
      margin: 0;
      font-size: 1.7rem;
      line-height: 1.15;
      font-weight: 900;
    }

    .profile-side-card p {
      margin: 10px 0 0;
      color: #dbe8ff;
      line-height: 1.65;
      font-weight: 700;
      word-break: break-word;
    }

    .profile-side-meta {
      margin-top: 22px;
      display: grid;
      gap: 12px;
    }

    .profile-side-meta div {
      padding: 14px;
      border-radius: 18px;
      background: rgba(255,255,255,0.10);
      border: 1px solid rgba(255,255,255,0.12);
    }

    .profile-side-meta span {
      display: block;
      color: #9bbcff;
      font-size: 0.78rem;
      font-weight: 900;
      text-transform: uppercase;
      letter-spacing: 0.08em;
      margin-bottom: 4px;
    }

    .profile-side-meta strong {
      color: #ffffff;
      font-size: 0.95rem;
      line-height: 1.4;
      word-break: break-word;
    }

    .verify-badge {
      margin-top: 16px;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 10px 14px;
      border-radius: 999px;
      font-size: 0.84rem;
      font-weight: 900;
    }

    .verify-badge.verified {
      background: rgba(16, 185, 129, 0.18);
      color: #bbf7d0;
      border: 1px solid rgba(16, 185, 129, 0.35);
    }

    .verify-badge.unverified {
      background: rgba(239, 68, 68, 0.16);
      color: #fecaca;
      border: 1px solid rgba(239, 68, 68, 0.32);
    }

    .profile-container {
      padding: 30px;
    }

    .profile-section-header {
      margin-bottom: 22px;
    }

    .profile-eyebrow {
      margin: 0 0 8px;
      color: #0b3d91;
      font-size: 0.82rem;
      font-weight: 900;
      letter-spacing: 0.14em;
      text-transform: uppercase;
    }

    .profile-container h2,
    .profile-container h3 {
      margin: 0;
      color: #07172f;
      font-weight: 900;
      letter-spacing: -0.8px;
    }

    .profile-container h2 {
      font-size: clamp(2rem, 4vw, 2.7rem);
      line-height: 1.05;
    }

    .profile-container h3 {
      font-size: clamp(1.5rem, 3vw, 2rem);
      line-height: 1.1;
    }

    .profile-subtitle {
      margin: 10px 0 0;
      color: #64748b;
      line-height: 1.65;
      font-weight: 700;
    }

    .profile-form-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 18px;
    }

    .profile-field-full {
      grid-column: 1 / -1;
    }

    .profile-container label {
      display: block;
      margin-bottom: 8px;
      color: #07172f;
      font-weight: 900;
      font-size: 0.95rem;
    }

    .required {
      color: #dc2626;
      font-weight: 900;
    }

    .profile-container input {
      width: 100%;
      min-height: 54px;
      padding: 0 16px;
      border: 1.5px solid #dbe3ef;
      border-radius: 16px;
      background: #f8fbff;
      color: #07172f;
      font-size: 1rem;
      box-sizing: border-box;
      outline: none;
      transition: 0.22s ease;
    }

    .profile-container input:focus {
      border-color: #0b3d91;
      background: #ffffff;
      box-shadow: 0 0 0 4px rgba(11, 61, 145, 0.12);
    }

    .field-error {
      border-color: #dc2626 !important;
      background: #fff7f7 !important;
      box-shadow: 0 0 0 4px rgba(220, 38, 38, 0.10) !important;
    }

    .field-message {
      margin-top: 7px;
      color: #dc2626;
      font-size: 0.82rem;
      font-weight: 700;
      line-height: 1.4;
    }

    .profile-container button.profile-submit-btn {
      width: 100%;
      min-height: 54px;
      margin-top: 22px;
      border: none;
      border-radius: 999px;
      background: linear-gradient(135deg, #061f4a, #0b3d91);
      color: #ffffff;
      font-size: 1rem;
      font-weight: 900;
      cursor: pointer;
      transition: 0.25s ease;
      box-shadow: 0 14px 30px rgba(11, 61, 145, 0.25);
    }

    .profile-container button.profile-submit-btn:hover {
      transform: translateY(-3px);
      background: linear-gradient(135deg, #07172f, #092f70);
    }

    .divider {
      height: 1px;
      background: #e5edf8;
      margin: 34px 0;
    }

    .password-note {
      margin: 10px 0 0;
      padding: 12px 14px;
      border-radius: 14px;
      background: #eaf1ff;
      border: 1px solid #dbe8ff;
      color: #061f4a;
      line-height: 1.5;
      font-size: 0.88rem;
      font-weight: 800;
    }

    .message {
      padding: 14px 16px;
      border-radius: 16px;
      margin: 0 0 20px;
      line-height: 1.5;
      font-weight: 800;
    }

    .message.success {
      background: #ecfdf5;
      border: 1px solid #bbf7d0;
      color: #047857;
    }

    .message.error {
      background: #fff1f2;
      border: 1px solid #fecdd3;
      color: #be123c;
    }

    .password-wrap {
      position: relative;
      width: 100%;
    }

    .password-wrap input {
      padding-right: 52px !important;
    }

    .toggle-password {
      position: absolute;
      top: 50%;
      right: 14px;
      transform: translateY(-50%);
      width: 34px;
      height: 34px;
      margin: 0;
      border: none;
      background: transparent;
      color: #64748b;
      cursor: pointer;
      border-radius: 10px;
      display: flex;
      align-items: center;
      justify-content: center;
      box-shadow: none;
      transition: 0.2s ease;
    }

    .toggle-password:hover {
      background: #eef4ff;
      color: #0b3d91;
      transform: translateY(-50%);
    }

    .toggle-password svg {
      width: 21px;
      height: 21px;
      pointer-events: none;
    }

    @media (max-width: 950px) {
      .profile-shell {
        grid-template-columns: 1fr;
      }

      .profile-side-card {
        position: static;
      }
    }

    @media (max-width: 640px) {
      .profile-main {
        padding: 115px 12px 38px;
      }

      .profile-container,
      .profile-side-card {
        border-radius: 24px;
        padding: 22px;
      }

      .profile-form-grid {
        grid-template-columns: 1fr;
      }

      .profile-field-full {
        grid-column: auto;
      }
    }
  </style>
</head>

<body>
<?php include 'navbar.php'; ?>

<main class="profile-main">
  <div class="profile-shell">

    <aside class="profile-side-card">
      <div class="profile-avatar">
        <?= htmlspecialchars(strtoupper(substr($user['first_name'] ?? 'U', 0, 1))) ?>
      </div>

      <h1><?= htmlspecialchars(trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''))) ?></h1>
      <p>Manage your personal information and account password securely.</p>

      <div class="verify-badge <?= !empty($user['email_verified']) ? 'verified' : 'unverified' ?>">
        <?= !empty($user['email_verified']) ? '✓ Verified Email' : '⚠ Unverified Email' ?>
      </div>

      <div class="profile-side-meta">
        <div>
          <span>Email</span>
          <strong><?= htmlspecialchars($user['email'] ?? '') ?></strong>
        </div>

        <div>
          <span>Phone</span>
          <strong><?= htmlspecialchars($user['phone'] ?? '') ?></strong>
        </div>
      </div>
    </aside>

    <section class="profile-container">
      <div class="profile-section-header">
        <p class="profile-eyebrow">Customer Account</p>
        <h2>My Profile</h2>
        <p class="profile-subtitle">
          Update your contact details. If you change your email, you must verify the new email before logging in again.
        </p>
      </div>

      <?php if ($message): ?>
        <p class="message <?= str_contains($message, '✅') ? 'success' : 'error' ?>">
          <?= htmlspecialchars($message) ?>
        </p>
      <?php endif; ?>

      <form method="POST" novalidate>
        <div class="profile-form-grid">
          <div>
            <label>First Name <span class="required">*</span></label>
            <input 
              type="text" 
              name="first_name" 
              value="<?= htmlspecialchars($formFirst) ?>" 
              class="<?= fieldClass($profileErrors['first_name'] ?? '') ?>"
              required
            >
            <?php if (!empty($profileErrors['first_name'])): ?>
              <div class="field-message"><?= htmlspecialchars($profileErrors['first_name']) ?></div>
            <?php endif; ?>
          </div>

          <div>
            <label>Middle Name</label>
            <input 
              type="text" 
              name="middle_name" 
              value="<?= htmlspecialchars($formMiddle) ?>"
              class="<?= fieldClass($profileErrors['middle_name'] ?? '') ?>"
            >
            <?php if (!empty($profileErrors['middle_name'])): ?>
              <div class="field-message"><?= htmlspecialchars($profileErrors['middle_name']) ?></div>
            <?php endif; ?>
          </div>

          <div>
            <label>Last Name <span class="required">*</span></label>
            <input 
              type="text" 
              name="last_name" 
              value="<?= htmlspecialchars($formLast) ?>" 
              class="<?= fieldClass($profileErrors['last_name'] ?? '') ?>"
              required
            >
            <?php if (!empty($profileErrors['last_name'])): ?>
              <div class="field-message"><?= htmlspecialchars($profileErrors['last_name']) ?></div>
            <?php endif; ?>
          </div>

          <div>
            <label>Phone <span class="required">*</span></label>
            <input 
              type="text" 
              name="phone" 
              id="phone"
              maxlength="11"
              inputmode="numeric"
              pattern="^09\d{9}$"
              value="<?= htmlspecialchars($formPhone) ?>" 
              class="<?= fieldClass($profileErrors['phone'] ?? '') ?>"
              required
            >
            <?php if (!empty($profileErrors['phone'])): ?>
              <div class="field-message"><?= htmlspecialchars($profileErrors['phone']) ?></div>
            <?php endif; ?>
          </div>

          <div class="profile-field-full">
            <label>Email <span class="required">*</span></label>
            <input 
              type="email" 
              name="email" 
              value="<?= htmlspecialchars($formEmail) ?>" 
              class="<?= fieldClass($profileErrors['email'] ?? '') ?>"
              required
            >
            <?php if (!empty($profileErrors['email'])): ?>
              <div class="field-message"><?= htmlspecialchars($profileErrors['email']) ?></div>
            <?php endif; ?>
          </div>
        </div>

        <button type="submit" name="update_profile" class="profile-submit-btn">Save Changes</button>
      </form>

      <div class="divider"></div>

      <div class="profile-section-header">
        <p class="profile-eyebrow">Security</p>
        <h3>Change Password</h3>
        <p class="profile-subtitle">Use a strong password to keep your appointment account protected.</p>
      </div>

      <?php if ($pass_message): ?>
        <p class="message <?= str_contains($pass_message, '✅') ? 'success' : 'error' ?>">
          <?= htmlspecialchars($pass_message) ?>
        </p>
      <?php endif; ?>

      <form method="POST" novalidate>
        <div class="profile-form-grid">
          <div class="profile-field-full">
            <label>Current Password <span class="required">*</span></label>
            <div class="password-wrap">
              <input 
                type="password" 
                name="current_password" 
                id="current_password"
                class="<?= fieldClass($passwordErrors['current_password'] ?? '') ?>"
                required
              >
              <button type="button" class="toggle-password" data-target="current_password" aria-label="Show password">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.25 12s3.75-6.75 9.75-6.75S21.75 12 21.75 12 18 18.75 12 18.75 2.25 12 2.25 12z" />
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15.25A3.25 3.25 0 1 0 12 8.75a3.25 3.25 0 0 0 0 6.5z" />
                </svg>
              </button>
            </div>
            <?php if (!empty($passwordErrors['current_password'])): ?>
              <div class="field-message"><?= htmlspecialchars($passwordErrors['current_password']) ?></div>
            <?php endif; ?>
          </div>

          <div>
            <label>New Password <span class="required">*</span></label>
            <div class="password-wrap">
              <input
                type="password"
                name="new_password"
                id="new_password"
                class="<?= fieldClass($passwordErrors['new_password'] ?? '') ?>"
                required
                minlength="8"
              >
              <button type="button" class="toggle-password" data-target="new_password" aria-label="Show password">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.25 12s3.75-6.75 9.75-6.75S21.75 12 21.75 12 18 18.75 12 18.75 2.25 12 2.25 12z" />
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15.25A3.25 3.25 0 1 0 12 8.75a3.25 3.25 0 0 0 0 6.5z" />
                </svg>
              </button>
            </div>
            <?php if (!empty($passwordErrors['new_password'])): ?>
              <div class="field-message"><?= htmlspecialchars($passwordErrors['new_password']) ?></div>
            <?php endif; ?>

            <div class="password-note">
              Password must be at least 8 characters long and include at least 1 uppercase letter and 1 special character.
            </div>
          </div>

          <div>
            <label>Confirm New Password <span class="required">*</span></label>
            <div class="password-wrap">
              <input 
                type="password" 
                name="confirm_password" 
                id="confirm_password"
                class="<?= fieldClass($passwordErrors['confirm_password'] ?? '') ?>"
                required
              >
              <button type="button" class="toggle-password" data-target="confirm_password" aria-label="Show password">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.25 12s3.75-6.75 9.75-6.75S21.75 12 21.75 12 18 18.75 12 18.75 2.25 12 2.25 12z" />
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15.25A3.25 3.25 0 1 0 12 8.75a3.25 3.25 0 0 0 0 6.5z" />
                </svg>
              </button>
            </div>
            <?php if (!empty($passwordErrors['confirm_password'])): ?>
              <div class="field-message"><?= htmlspecialchars($passwordErrors['confirm_password']) ?></div>
            <?php endif; ?>
          </div>
        </div>

        <button type="submit" name="change_password" class="profile-submit-btn">Update Password</button>
      </form>
    </section>

  </div>
</main>

<?php include 'footer.php'; ?>

<script>
const phoneInput = document.getElementById('phone');

if (phoneInput) {
  phoneInput.addEventListener('input', function () {
    this.value = this.value.replace(/\D/g, '').slice(0, 11);
  });
}

document.querySelectorAll('.toggle-password').forEach(button => {
  button.addEventListener('click', function () {
    const targetId = this.getAttribute('data-target');
    const input = document.getElementById(targetId);

    if (!input) return;

    const isPassword = input.type === 'password';
    input.type = isPassword ? 'text' : 'password';
    this.setAttribute('aria-label', isPassword ? 'Hide password' : 'Show password');

    this.innerHTML = isPassword
      ? `
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3l18 18" />
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.58 10.58A2 2 0 0 0 12 14a2 2 0 0 0 1.42-.58" />
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.88 5.09A8.94 8.94 0 0 1 12 4.84C18 4.84 21.75 12 21.75 12a18.09 18.09 0 0 1-3.05 4.08" />
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6.53 6.53C3.86 8.35 2.25 12 2.25 12s3.75 7.16 9.75 7.16a8.83 8.83 0 0 0 4.18-1.05" />
        </svg>
      `
      : `
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.25 12s3.75-6.75 9.75-6.75S21.75 12 21.75 12 18 18.75 12 18.75 2.25 12 2.25 12z" />
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15.25A3.25 3.25 0 1 0 12 8.75a3.25 3.25 0 0 0 0 6.5z" />
        </svg>
      `;
  });
});
</script>

</body>
</html>