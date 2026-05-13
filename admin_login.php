<?php
session_start();
include 'db.php';
require 'modal_helper.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';
require 'PHPMailer/src/Exception.php';

/* =========================
   GET SHOP SETTINGS
========================= */
$settingsQuery = $conn->query("SELECT * FROM settings LIMIT 1");
$settings = $settingsQuery ? $settingsQuery->fetch_assoc() : [];

$shop_name = $settings['shop_name'] ?? "Fade Street Hair Studio";
$logo = $settings['logo'] ?? "logo.png";

function validPasswordRule($password) {
    return preg_match('/^(?=.*[A-Z])(?=.*[!@#$%^&*()_\-+=\[\]{};:,.<>?\/\\\\|`~]).{8,}$/', $password);
}

function sendResetEmail($toEmail, $displayName, $resetLink, $shopSettings, $shopName) {
    $mail = new PHPMailer(true);

    try {
        $smtpEmail = trim($shopSettings['smtp_email'] ?? '');
        $smtpPassword = trim($shopSettings['smtp_password'] ?? '');
        $smtpFromName = trim($shopSettings['smtp_from_name'] ?? ($shopName ?: 'Fade Street Hair Studio'));

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
        $mail->addAddress($toEmail, $displayName);
        $mail->isHTML(true);
        $mail->Subject = 'Password Reset Request';

        $body = "
            Hi " . htmlspecialchars($displayName) . ",<br><br>
            We received a request to reset your password.<br><br>
            Click the link below to set a new password:<br>
            <a href='" . htmlspecialchars($resetLink) . "'>" . htmlspecialchars($resetLink) . "</a><br><br>
            This reset link will expire in 1 hour.<br><br>
            If you did not request this, you can ignore this email.<br><br>
            Regards,<br>" . htmlspecialchars($smtpFromName);

        $mail->Body = $body;
        $mail->send();
        return true;
    } catch (Exception $e) {
        return false;
    }
}

function buildResetLink($token, $accountType) {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') == 443);

    $scheme = $isHttps ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $path = strtok($_SERVER['REQUEST_URI'], '?');

    return $scheme . '://' . $host . $path . '?mode=reset&token=' . urlencode($token) . '&type=' . urlencode($accountType);
}

/* =========================
   RESET VIEW MODE
========================= */
$mode = $_GET['mode'] ?? '';
$tokenFromUrl = trim($_GET['token'] ?? '');
$typeFromUrl = trim($_GET['type'] ?? '');
$showResetForm = false;
$resetTargetName = '';

if ($mode === 'reset' && $tokenFromUrl !== '' && $typeFromUrl !== '') {
    $allowedTypes = ['admin', 'staff', 'barber'];

    if (in_array($typeFromUrl, $allowedTypes, true)) {
        if ($typeFromUrl === 'admin') {
            $stmt = $conn->prepare("
                SELECT id, fname, lname, reset_expiry
                FROM admins
                WHERE reset_token = ?
                LIMIT 1
            ");
        } elseif ($typeFromUrl === 'staff') {
            $stmt = $conn->prepare("
                SELECT id, fname, lname, reset_expiry
                FROM staff
                WHERE reset_token = ?
                LIMIT 1
            ");
        } else {
            $stmt = $conn->prepare("
                SELECT id, name, reset_expiry
                FROM barbers
                WHERE reset_token = ?
                LIMIT 1
            ");
        }

        $stmt->bind_param("s", $tokenFromUrl);
        $stmt->execute();
        $result = $stmt->get_result();
        $account = $result->fetch_assoc();
        $stmt->close();

        if ($account) {
            $expiry = strtotime($account['reset_expiry'] ?? '');
            if ($expiry && $expiry >= time()) {
                $showResetForm = true;
                if ($typeFromUrl === 'barber') {
                    $resetTargetName = $account['name'] ?? 'User';
                } else {
                    $resetTargetName = trim(($account['fname'] ?? '') . ' ' . ($account['lname'] ?? ''));
                }
            } else {
                showModalMessage("Reset Link Expired", "This password reset link is already expired. Please request a new one.", "OK", "admin_login.php");
            }
        } else {
            showModalMessage("Invalid Reset Link", "The password reset link is invalid or already used.", "OK", "admin_login.php");
        }
    } else {
        showModalMessage("Invalid Reset Link", "Invalid account type for password reset.", "OK", "admin_login.php");
    }
}

/* =========================
   HANDLE FORGOT PASSWORD REQUEST
========================= */
if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST['action'] ?? '') === 'forgot_password') {
    $email = trim($_POST['forgot_email'] ?? '');

    if ($email === '') {
        showModalMessage("Forgot Password", "Please enter your email address.", "OK", "javascript:history.back()");
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        showModalMessage("Forgot Password", "Please enter a valid email address.", "OK", "javascript:history.back()");
    }

    $accountType = '';
    $accountId = 0;
    $displayName = '';

    $stmt = $conn->prepare("SELECT id, fname, lname FROM admins WHERE email = ? LIMIT 1");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $adminRes = $stmt->get_result();
    $admin = $adminRes->fetch_assoc();
    $stmt->close();

    if ($admin) {
        $accountType = 'admin';
        $accountId = (int)$admin['id'];
        $displayName = trim(($admin['fname'] ?? '') . ' ' . ($admin['lname'] ?? ''));
    }

    if ($accountType === '') {
        $stmt = $conn->prepare("SELECT id, fname, lname FROM staff WHERE email = ? LIMIT 1");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $staffRes = $stmt->get_result();
        $staff = $staffRes->fetch_assoc();
        $stmt->close();

        if ($staff) {
            $accountType = 'staff';
            $accountId = (int)$staff['id'];
            $displayName = trim(($staff['fname'] ?? '') . ' ' . ($staff['lname'] ?? ''));
        }
    }

    if ($accountType === '') {
        $stmt = $conn->prepare("SELECT id, name FROM barbers WHERE email = ? LIMIT 1");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $barberRes = $stmt->get_result();
        $barber = $barberRes->fetch_assoc();
        $stmt->close();

        if ($barber) {
            $accountType = 'barber';
            $accountId = (int)$barber['id'];
            $displayName = $barber['name'] ?? 'Barber';
        }
    }

    if ($accountType === '') {
        showModalMessage("Forgot Password", "No account was found with that email address.", "OK", "admin_login.php");
    }

    $token = bin2hex(random_bytes(32));
    $expiry = date('Y-m-d H:i:s', strtotime('+1 hour'));
    $resetLink = buildResetLink($token, $accountType);

    if ($accountType === 'admin') {
        $stmt = $conn->prepare("UPDATE admins SET reset_token = ?, reset_expiry = ? WHERE id = ?");
    } elseif ($accountType === 'staff') {
        $stmt = $conn->prepare("UPDATE staff SET reset_token = ?, reset_expiry = ? WHERE id = ?");
    } else {
        $stmt = $conn->prepare("UPDATE barbers SET reset_token = ?, reset_expiry = ? WHERE id = ?");
    }

    $stmt->bind_param("ssi", $token, $expiry, $accountId);

    if ($stmt->execute()) {
        $sent = sendResetEmail($email, $displayName, $resetLink, $settings, $shop_name);
        $stmt->close();

        if ($sent) {
            showModalMessage("Forgot Password", "A password reset link has been sent to your email.", "OK", "admin_login.php");
        } else {
            showModalMessage("Forgot Password", "Reset token saved, but email sending failed. Please check your SMTP settings.", "OK", "admin_login.php");
        }
    } else {
        $stmt->close();
        showModalMessage("Forgot Password", "Failed to process password reset request.", "OK", "admin_login.php");
    }
}

/* =========================
   HANDLE RESET PASSWORD SUBMIT
========================= */
if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST['action'] ?? '') === 'reset_password') {
    $token = trim($_POST['reset_token'] ?? '');
    $accountType = trim($_POST['account_type'] ?? '');
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if ($token === '' || $accountType === '') {
        showModalMessage("Reset Password", "Invalid reset request.", "OK", "admin_login.php");
    }

    if ($newPassword === '' || $confirmPassword === '') {
        showModalMessage("Reset Password", "Please fill in both password fields.", "OK", "javascript:history.back()");
    }

    if ($newPassword !== $confirmPassword) {
        showModalMessage("Reset Password", "Passwords do not match.", "OK", "javascript:history.back()");
    }

    if (!validPasswordRule($newPassword)) {
        showModalMessage(
            "Reset Password",
            "Password must be at least 8 characters long and contain at least 1 uppercase letter and 1 special character.",
            "OK",
            "javascript:history.back()"
        );
    }

    if ($accountType === 'admin') {
        $stmt = $conn->prepare("SELECT id, reset_expiry FROM admins WHERE reset_token = ? LIMIT 1");
    } elseif ($accountType === 'staff') {
        $stmt = $conn->prepare("SELECT id, reset_expiry FROM staff WHERE reset_token = ? LIMIT 1");
    } elseif ($accountType === 'barber') {
        $stmt = $conn->prepare("SELECT id, reset_expiry FROM barbers WHERE reset_token = ? LIMIT 1");
    } else {
        showModalMessage("Reset Password", "Invalid account type.", "OK", "admin_login.php");
    }

    $stmt->bind_param("s", $token);
    $stmt->execute();
    $result = $stmt->get_result();
    $account = $result->fetch_assoc();
    $stmt->close();

    if (!$account) {
        showModalMessage("Reset Password", "Invalid or already used reset token.", "OK", "admin_login.php");
    }

    $expiry = strtotime($account['reset_expiry'] ?? '');
    if (!$expiry || $expiry < time()) {
        showModalMessage("Reset Password", "Reset token is already expired.", "OK", "admin_login.php");
    }

    $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
    $accountId = (int)$account['id'];

    if ($accountType === 'admin') {
        $stmt = $conn->prepare("
            UPDATE admins
            SET password = ?, reset_token = NULL, reset_expiry = NULL
            WHERE id = ?
        ");
    } elseif ($accountType === 'staff') {
        $stmt = $conn->prepare("
            UPDATE staff
            SET password = ?, reset_token = NULL, reset_expiry = NULL
            WHERE id = ?
        ");
    } else {
        $stmt = $conn->prepare("
            UPDATE barbers
            SET password = ?, reset_token = NULL, reset_expiry = NULL
            WHERE id = ?
        ");
    }

    $stmt->bind_param("si", $hashedPassword, $accountId);

    if ($stmt->execute()) {
        $stmt->close();
        showModalMessage("Reset Password", "Your password has been updated successfully. You may now log in.", "OK", "admin_login.php");
    } else {
        $stmt->close();
        showModalMessage("Reset Password", "Failed to reset password. Please try again.", "OK", "admin_login.php");
    }
}

/* =========================
   HANDLE LOGIN
========================= */
if ($_SERVER["REQUEST_METHOD"] === "POST" && !isset($_POST['action'])) {
    $email = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if ($email === '' || $password === '') {
        showModalMessage("Login Error", "Both email and password are required.", "OK", "javascript:history.back()");
    }

    $stmt = $conn->prepare("SELECT id, fname, lname, password FROM admins WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $adminResult = $stmt->get_result();

    if ($adminResult->num_rows === 1) {
        $admin = $adminResult->fetch_assoc();
        $stmt->close();

        if (password_verify($password, $admin['password'])) {
            $_SESSION['admin_id'] = $admin['id'];
            $_SESSION['admin_name'] = $admin['fname'] . " " . $admin['lname'];
            $_SESSION['role'] = "admin";

            header("Location: admin_dashboard.php");
            exit;
        } else {
            showModalMessage("Login Error", "Invalid email or password.", "OK", "javascript:history.back()");
        }
    }

    $stmt->close();

    $stmt = $conn->prepare("SELECT id, fname, lname, password, status FROM staff WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $staffResult = $stmt->get_result();

    if ($staffResult->num_rows === 1) {
        $staff = $staffResult->fetch_assoc();
        $stmt->close();

        if ($staff['status'] !== "active") {
            showModalMessage("Login Error", "Staff account is inactive.", "OK", "javascript:history.back()");
        }

        if (password_verify($password, $staff['password'])) {
            $_SESSION['staff_id'] = $staff['id'];
            $_SESSION['staff_name'] = $staff['fname'] . " " . $staff['lname'];
            $_SESSION['role'] = "staff";

            header("Location: staff_dashboard.php");
            exit;
        } else {
            showModalMessage("Login Error", "Invalid email or password.", "OK", "javascript:history.back()");
        }
    }

    $stmt->close();

    $stmt = $conn->prepare("SELECT id, name, password, status FROM barbers WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $barberResult = $stmt->get_result();

    if ($barberResult->num_rows === 1) {
        $barber = $barberResult->fetch_assoc();
        $stmt->close();

        if ($barber['status'] !== "active") {
            showModalMessage("Login Error", "Barber account is inactive.", "OK", "javascript:history.back()");
        }

        if (password_verify($password, $barber['password'])) {
            $_SESSION['barber_id'] = $barber['id'];
            $_SESSION['barber_name'] = $barber['name'];
            $_SESSION['role'] = "barber";

            header("Location: barber_dashboard.php");
            exit;
        } else {
            showModalMessage("Login Error", "Invalid email or password.", "OK", "javascript:history.back()");
        }
    }

    $stmt->close();

    showModalMessage("Login Error", "Invalid email or password.", "OK", "javascript:history.back()");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<title>Administrator Portal</title>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">

<style>
*{
  box-sizing:border-box;
}

:root{
  --fs-navy:#07172f;
  --fs-navy-2:#0b1f3f;
  --fs-blue:#0b3d91;
  --fs-blue-2:#2563eb;
  --fs-blue-soft:#eaf1ff;
  --fs-line:#dbe3ef;
  --fs-text:#0f172a;
  --fs-muted:#64748b;
  --fs-danger:#a70202;
  --fs-white:#ffffff;
  --fs-shadow:0 24px 70px rgba(7,23,47,.22);
}

html,
body{
  width:100%;
  min-height:100%;
  overflow-x:hidden;
}

body{
  margin:0;
  min-height:100vh;
  display:flex;
  align-items:center;
  justify-content:center;
  font-family:'Poppins',sans-serif;
  background:
    radial-gradient(circle at top left, rgba(37,99,235,.24), transparent 34%),
    radial-gradient(circle at bottom right, rgba(11,61,145,.20), transparent 35%),
    linear-gradient(135deg,#07172f 0%,#0b1f3f 48%,#061126 100%);
  color:var(--fs-text);
  padding:22px;
}

body::before{
  content:"";
  position:fixed;
  inset:0;
  pointer-events:none;
  background:
    linear-gradient(120deg, rgba(255,255,255,.06), transparent 38%),
    radial-gradient(circle at 50% 0%, rgba(255,255,255,.08), transparent 28%);
}

.login-shell{
  position:relative;
  z-index:1;
  width:100%;
  max-width:440px;
  margin:0 auto;
}

.login-card{
  width:100%;
  padding:40px 34px;
  border-radius:26px;
  background:rgba(255,255,255,.97);
  border:1px solid rgba(219,227,239,.95);
  box-shadow:var(--fs-shadow);
  color:var(--fs-text);
  text-align:center;
}

.logo{
  display:flex;
  justify-content:center;
  align-items:center;
  margin-bottom:16px;
}

.logo img{
  width:84px;
  height:84px;
  border-radius:22px;
  border:1px solid var(--fs-line);
  padding:7px;
  background:#fff;
  object-fit:contain;
  box-shadow:0 12px 28px rgba(7,23,47,.12);
}

.title{
  font-size:25px;
  font-weight:800;
  line-height:1.25;
  color:var(--fs-navy);
  letter-spacing:-.03em;
  word-break:break-word;
  text-align:center;
}

.title span{
  color:var(--fs-blue);
}

.subtitle{
  display:inline-flex;
  justify-content:center;
  align-items:center;
  width:max-content;
  max-width:100%;
  padding:7px 12px;
  border-radius:999px;
  background:var(--fs-blue-soft);
  color:var(--fs-blue);
  font-size:11px;
  font-weight:800;
  margin:12px auto 26px;
  letter-spacing:.12em;
}

.form-group{
  text-align:left;
  margin-bottom:15px;
}

.form-group label{
  display:block;
  margin-bottom:8px;
  font-size:13px;
  font-weight:700;
  color:var(--fs-navy);
}

input{
  width:100%;
  padding:14px 15px;
  border-radius:14px;
  border:1px solid var(--fs-line);
  background:#f8fbff;
  color:var(--fs-text);
  outline:none;
  font-size:14px;
  transition:.2s ease;
}

input:focus{
  border-color:var(--fs-blue-2);
  background:#fff;
  box-shadow:0 0 0 4px rgba(37,99,235,.12);
}

input::placeholder{
  color:#94a3b8;
}

button{
  width:100%;
  padding:14px;
  margin-top:8px;
  border:none;
  border-radius:14px;
  font-weight:800;
  background:linear-gradient(135deg,var(--fs-navy),var(--fs-blue));
  color:white;
  cursor:pointer;
  transition:.25s ease;
  font-size:15px;
  box-shadow:0 14px 28px rgba(11,61,145,.26);
}

button:hover{
  transform:translateY(-2px);
  box-shadow:0 18px 36px rgba(11,61,145,.34);
}

.link-row{
  margin-top:14px;
  display:flex;
  justify-content:center;
  gap:10px;
  flex-wrap:wrap;
}

.text-link{
  color:var(--fs-blue);
  text-decoration:none;
  font-size:13px;
  font-weight:700;
}

.text-link:hover{
  text-decoration:underline;
}

.helper-text{
  margin-top:18px;
  font-size:12px;
  color:var(--fs-muted);
  line-height:1.7;
  background:#f8fbff;
  border:1px solid #e5efff;
  border-radius:16px;
  padding:14px;
  text-align:center;
}

.footer{
  margin-top:18px;
  font-size:12px;
  color:var(--fs-muted);
}

.modal-backdrop{
  position:fixed;
  inset:0;
  background:rgba(7,23,47,.68);
  display:none;
  align-items:center;
  justify-content:center;
  padding:18px;
  z-index:9999;
  backdrop-filter:blur(8px);
}

.modal-backdrop.show{
  display:flex;
}

.modal-box{
  width:100%;
  max-width:460px;
  background:#fff;
  color:var(--fs-text);
  border-radius:24px;
  padding:26px;
  box-shadow:0 26px 70px rgba(0,0,0,.24);
  border:1px solid var(--fs-line);
}

.modal-box h3{
  margin:0 0 8px;
  color:var(--fs-navy);
  font-size:22px;
}

.modal-box p{
  margin:0 0 16px;
  color:var(--fs-muted);
  font-size:14px;
  line-height:1.6;
}

.modal-actions{
  display:flex;
  gap:10px;
  justify-content:flex-end;
  flex-wrap:wrap;
  margin-top:16px;
}

.modal-btn-secondary{
  background:#eef2f7;
  color:var(--fs-navy);
  box-shadow:none;
}

.modal-btn-secondary:hover{
  background:#e2e8f0;
  box-shadow:none;
}

.modal-note{
  margin-top:12px;
  font-size:12px;
  color:var(--fs-muted);
  line-height:1.6;
  background:#f8fbff;
  border:1px solid #e5efff;
  border-radius:14px;
  padding:12px;
}

.reset-header{
  margin-bottom:22px;
  text-align:center;
}

.reset-header h2{
  margin:0 0 8px;
  font-size:24px;
  color:var(--fs-navy);
}

.reset-header p{
  margin:0;
  font-size:13px;
  color:var(--fs-muted);
  line-height:1.6;
}

@media (max-width: 480px) {
  body{
    padding:12px;
    align-items:flex-start;
    padding-top:28px;
  }

  .login-shell{
    max-width:100%;
  }

  .login-card{
    padding:28px 18px;
    border-radius:20px;
  }

  .logo img{
    width:74px;
    height:74px;
    border-radius:20px;
  }

  .title{
    font-size:20px;
  }

  .subtitle{
    font-size:10px;
    margin-bottom:20px;
  }

  input{
    padding:12px;
    font-size:13px;
  }

  button{
    padding:12px;
    font-size:14px;
  }

  .footer,
  .helper-text{
    font-size:11px;
  }

  .modal-box{
    padding:18px;
    border-radius:18px;
  }
}
</style>
</head>

<body>

<div class="login-shell">
  <div class="login-card">

    <div class="logo">
      <img src="images/<?= htmlspecialchars($logo) ?>" alt="Shop Logo">
    </div>

    <?php
    $words = explode(" ", $shop_name);
    $last_two = implode(" ", array_slice($words, -2));
    $first_part = implode(" ", array_slice($words, 0, -2));
    if (trim($first_part) === '') {
        $first_part = $shop_name;
        $last_two = '';
    }
    ?>

    <?php if ($showResetForm): ?>
      <div class="reset-header">
        <h2>Reset Password</h2>
        <p>Set a new password for <?= htmlspecialchars($resetTargetName ?: 'your account') ?>.</p>
      </div>

      <form method="POST">
        <input type="hidden" name="action" value="reset_password">
        <input type="hidden" name="reset_token" value="<?= htmlspecialchars($tokenFromUrl) ?>">
        <input type="hidden" name="account_type" value="<?= htmlspecialchars($typeFromUrl) ?>">

        <div class="form-group">
          <label for="new_password">New Password</label>
          <input type="password" name="new_password" id="new_password" placeholder="Enter new password" required>
        </div>

        <div class="form-group">
          <label for="confirm_password">Confirm Password</label>
          <input type="password" name="confirm_password" id="confirm_password" placeholder="Confirm new password" required>
        </div>

        <button type="submit">UPDATE PASSWORD</button>

        <div class="helper-text">
          Password must be at least 8 characters long and contain at least 1 uppercase letter and 1 special character.
        </div>

        <div class="link-row">
          <a href="admin_login.php" class="text-link">Back to login</a>
        </div>
      </form>

    <?php else: ?>
      <div class="title">
        <?= strtoupper(htmlspecialchars(trim($first_part))) ?>
        <?php if (trim($last_two) !== ''): ?>
          <span><?= strtoupper(htmlspecialchars(trim($last_two))) ?></span>
        <?php endif; ?>
      </div>

      <div class="subtitle">PERSONNEL PORTAL</div>

      <form method="POST">
        <div class="form-group">
          <label for="email">Email</label>
          <input type="email" name="email" id="email" placeholder="Admin, Staff, or Barber Email" required>
        </div>

        <div class="form-group">
          <label for="password">Password</label>
          <input type="password" name="password" id="password" placeholder="Password" required>
        </div>

        <button type="submit">AUTHENTICATE</button>

        <div class="link-row">
          <a href="#" class="text-link" id="openForgotPassword">Forgot Password?</a>
        </div>
      </form>

      <div class="helper-text">
        Use your registered email address to sign in. This portal supports Owner, staff, and barber accounts.
      </div>

      
    <?php endif; ?>

  </div>
</div>

<div class="modal-backdrop" id="forgotPasswordModal">
  <div class="modal-box">
    <h3>Forgot Password</h3>
    <p>Enter your registered email address and we will send you a password reset link.</p>

    <form method="POST">
      <input type="hidden" name="action" value="forgot_password">

      <div class="form-group">
        <label for="forgot_email">Email Address</label>
        <input type="email" name="forgot_email" id="forgot_email" placeholder="Enter your email" required style="color:#222; background:#fff; border:1px solid #ccc;">
      </div>

      <div class="modal-actions">
        <button type="button" class="modal-btn-secondary" id="closeForgotPassword" style="width:auto; margin-top:0;">Cancel</button>
        <button type="submit" style="width:auto; margin-top:0;">Send Reset Link</button>
      </div>
    </form>

    <div class="modal-note">
      Make sure your SMTP email settings are already configured in your system settings so the reset email can be sent properly.
    </div>
  </div>
</div>

<script>
const forgotModal = document.getElementById('forgotPasswordModal');
const openForgotBtn = document.getElementById('openForgotPassword');
const closeForgotBtn = document.getElementById('closeForgotPassword');

if (openForgotBtn) {
    openForgotBtn.addEventListener('click', function(e) {
        e.preventDefault();
        forgotModal.classList.add('show');
    });
}

if (closeForgotBtn) {
    closeForgotBtn.addEventListener('click', function() {
        forgotModal.classList.remove('show');
    });
}

if (forgotModal) {
    forgotModal.addEventListener('click', function(e) {
        if (e.target === forgotModal) {
            forgotModal.classList.remove('show');
        }
    });
}
</script>

</body>
</html>