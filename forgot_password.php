<?php
session_start();
require 'db.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';
require 'PHPMailer/src/Exception.php';

$messages = [];
$errors = [];

/* LOAD SETTINGS */
$settingsQuery = $conn->query("SELECT * FROM settings LIMIT 1");
$shop = $settingsQuery ? $settingsQuery->fetch_assoc() : [];

$shopName = $shop['shop_name'] ?? 'Fade Street Hair Studio';
$bannerImage = !empty($shop['banner_image']) ? $shop['banner_image'] : 'banner.jpg';

$smtpEmail = trim($shop['smtp_email'] ?? '');
$smtpPassword = trim($shop['smtp_password'] ?? '');
$smtpFromName = trim($shop['smtp_from_name'] ?? $shopName);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');

    if (!$email) {
        $errors[] = "Please enter your email address.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Please enter a valid email address.";
    } else {
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows === 1) {
            $stmt->bind_result($user_id);
            $stmt->fetch();

            $token = bin2hex(random_bytes(16));
            $expiry = gmdate("Y-m-d H:i:s", time() + 3600);

            $stmt_update = $conn->prepare("UPDATE users SET reset_token = ?, reset_expiry = ? WHERE id = ?");
            $stmt_update->bind_param("ssi", $token, $expiry, $user_id);
            $stmt_update->execute();

            if ($stmt_update->affected_rows === 0) {
                $errors[] = "Failed to save reset token. Please try again.";
            } else {
                $reset_link = "http://localhost/BARBERQUE/reset_password.php?token=" . urlencode($token);

                if ($smtpEmail === '' || $smtpPassword === '') {
                    $errors[] = "SMTP settings are not configured. Please contact the administrator.";
                } else {
                    $mail = new PHPMailer(true);

                    try {
                        $mail->isSMTP();
                        $mail->Host = 'smtp.gmail.com';
                        $mail->SMTPAuth = true;
                        $mail->Username = $smtpEmail;
                        $mail->Password = $smtpPassword;
                        $mail->SMTPSecure = 'tls';
                        $mail->Port = 587;

                        $mail->setFrom($smtpEmail, $smtpFromName);
                        $mail->addAddress($email);

                        $mail->isHTML(true);
                        $mail->Subject = 'Password Reset Request';
                        $mail->Body = "
                            <div style='font-family: Arial, sans-serif; background:#f7f9fc; padding:24px; color:#07172f;'>
                                <div style='max-width:560px; margin:0 auto; background:#ffffff; border-radius:18px; padding:28px; border:1px solid #dbe3ef;'>
                                    <h2 style='margin:0 0 12px; color:#07172f;'>{$smtpFromName}</h2>
                                    <p style='line-height:1.6; color:#475569;'>Hi,</p>
                                    <p style='line-height:1.6; color:#475569;'>
                                        We received a request to reset your password. Click the button below to create a new password.
                                    </p>
                                    <p style='margin:24px 0;'>
                                        <a href='{$reset_link}' style='display:inline-block; background:#0b3d91; color:#ffffff; padding:13px 20px; border-radius:999px; text-decoration:none; font-weight:bold;'>
                                            Reset Password
                                        </a>
                                    </p>
                                    <p style='line-height:1.6; color:#475569;'>
                                        This link will expire in 1 hour.
                                    </p>
                                    <p style='line-height:1.6; color:#64748b; font-size:13px;'>
                                        If the button does not work, copy and paste this link into your browser:<br>
                                        <a href='{$reset_link}' style='color:#0b3d91;'>{$reset_link}</a>
                                    </p>
                                    <p style='line-height:1.6; color:#64748b; font-size:13px;'>
                                        If you did not request this, you can safely ignore this email.
                                    </p>
                                </div>
                            </div>
                        ";

                        $mail->send();
                        $messages[] = "Password reset link has been sent to your email.";
                    } catch (Exception $e) {
                        $errors[] = "Mailer Error: " . $mail->ErrorInfo;
                    }
                }
            }

            $stmt_update->close();
        } else {
            $errors[] = "No account found with that email.";
        }

        $stmt->close();
    }
}

$error_message = !empty($errors) ? implode(" ", $errors) : "";
$success_message = !empty($messages) ? implode(" ", $messages) : "";
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Forgot Password | <?= htmlspecialchars($shopName) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="styles.css">

  <style>
    .auth-visual-panel {
      background:
        linear-gradient(135deg, rgba(7, 23, 47, 0.92), rgba(6, 31, 74, 0.88)),
        url("images/<?= htmlspecialchars($bannerImage) ?>") center/cover no-repeat;
    }

    .auth-forgot-shell {
      min-height: 620px;
    }

    .auth-forgot-card {
      max-width: 500px;
    }

    .auth-help-box {
      margin-top: 18px;
      padding: 14px;
      border-radius: 16px;
      background: #f8fbff;
      border: 1px solid #dbe3ef;
      color: #64748b;
      font-size: 0.9rem;
      font-weight: 700;
      line-height: 1.55;
      text-align: center;
    }
  </style>
</head>

<body>

<?php include 'navbar.php'; ?>

<main class="auth-page auth-forgot-page">

  <section class="auth-shell auth-forgot-shell">

    <div class="auth-visual-panel">
      <div class="auth-visual-overlay"></div>

      <div class="auth-visual-content">
        <p class="auth-eyebrow">Password Recovery</p>

        <h1>
          Recover your account securely.
        </h1>

        <p class="auth-visual-text">
          Enter your registered email address and we will send you a secure password reset link.
        </p>

        <div class="auth-feature-list">
          <div class="auth-feature-item">
            <span>✓</span>
            <p>Secure reset link sent by email</p>
          </div>

          <div class="auth-feature-item">
            <span>✓</span>
            <p>Reset link expires after 1 hour</p>
          </div>

          <div class="auth-feature-item">
            <span>✓</span>
            <p>Return to your appointment booking after login</p>
          </div>
        </div>
      </div>
    </div>

    <div class="auth-form-panel">
      <div class="auth-card auth-forgot-card">

        <p class="auth-small-title">Forgot Password</p>

        <h1 class="auth-title">
          Reset your password
        </h1>

        <p class="auth-subtitle">
          Enter the email connected to your customer account.
        </p>

        <form class="auth-form" method="POST" novalidate>

          <div class="auth-field">
            <label for="email">Email Address</label>
            <input
              type="email"
              id="email"
              name="email"
              placeholder="example@email.com"
              required
              value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
            >
          </div>

          <button type="submit" class="auth-btn">Send Reset Link</button>

          <p class="auth-link-text">
            Remembered your password?
            <a href="login.php">Back to Login</a>
          </p>

          <div class="auth-help-box">
            For your security, the reset link can only be used within 1 hour.
          </div>

        </form>

      </div>
    </div>

  </section>

</main>

<?php if ($error_message): ?>
<div class="custom-modal-backdrop" id="errorModal">
  <div class="custom-modal-box">
    <div class="custom-modal-icon">!</div>
    <h2>Request Failed</h2>
    <p><?= htmlspecialchars($error_message) ?></p>
    <button
      type="button"
      class="custom-modal-btn"
      onclick="document.getElementById('errorModal').style.display='none'"
    >
      Try Again
    </button>
  </div>
</div>
<?php endif; ?>

<?php if ($success_message): ?>
<div class="custom-modal-backdrop" id="successModal">
  <div class="custom-modal-box">
    <div class="custom-modal-icon success">✓</div>
    <h2>Reset Link Sent</h2>
    <p><?= htmlspecialchars($success_message) ?></p>
    <button
      type="button"
      class="custom-modal-btn"
      onclick="window.location.href='login.php'"
    >
      Back to Login
    </button>
  </div>
</div>
<?php endif; ?>

<?php include 'footer.php'; ?>

</body>
</html>