<?php
session_start();
require 'db.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'PHPMailer/src/Exception.php';
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';

$settings = $conn->query("SELECT * FROM settings LIMIT 1");
$shop = $settings ? $settings->fetch_assoc() : [];

$shopName = $shop['shop_name'] ?? 'Fade Street Hair Studio';
$bannerImage = !empty($shop['banner_image']) ? $shop['banner_image'] : 'banner.jpg';

$smtpEmail = trim($shop['smtp_email'] ?? '');
$smtpPassword = trim($shop['smtp_password'] ?? '');
$smtpFromName = trim($shop['smtp_from_name'] ?? $shopName);

$fieldErrors = [];
$successMessage = "";

function formatName($name) {
    $name = trim($name);
    $name = preg_replace('/\s+/', ' ', $name);
    return ucwords(strtolower($name));
}

function old($key) {
    return htmlspecialchars($_POST[$key] ?? '');
}

function hasError($field, $fieldErrors) {
    return isset($fieldErrors[$field]) ? 'field-error' : '';
}

function sendVerificationEmail($toEmail, $toName, $token, $shopName, $smtpEmail, $smtpPassword, $smtpFromName) {
    if ($smtpEmail === '' || $smtpPassword === '') {
        throw new Exception("SMTP settings are not configured.");
    }

    $mail = new PHPMailer(true);

    $baseUrl = "http://localhost/BARBERQUE";
    $verifyLink = $baseUrl . "/verify_email.php?token=" . urlencode($token);

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
    $mail->Subject = "Verify your {$shopName} account";

    $mail->Body = "
        <div style='font-family:Arial,sans-serif;background:#f4f7fb;padding:30px;'>
            <div style='max-width:600px;margin:auto;background:#ffffff;border-radius:16px;padding:28px;border:1px solid #dbe3ef;'>
                <h2 style='color:#0f172a;margin-top:0;'>Verify your email address</h2>
                <p style='color:#475569;font-size:15px;line-height:1.6;'>
                    Hi " . htmlspecialchars($toName) . ", thank you for creating an account with <strong>" . htmlspecialchars($shopName) . "</strong>.
                    Please verify your email address before logging in.
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

    $mail->AltBody = "Hi {$toName}, verify your account here: {$verifyLink}";

    $mail->send();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first = formatName($_POST['first_name'] ?? '');
    $middle = formatName($_POST['middle_name'] ?? '');
    $last = formatName($_POST['last_name'] ?? '');
    $phone = preg_replace('/\D/', '', $_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (!$first) {
        $fieldErrors['first_name'] = "First name is required.";
    } elseif (!preg_match('/^[a-zA-Z\s]+$/', $first)) {
        $fieldErrors['first_name'] = "First name must contain letters only.";
    }

    if ($middle && !preg_match('/^[a-zA-Z\s]+$/', $middle)) {
        $fieldErrors['middle_name'] = "Middle name must contain letters only.";
    }

    if (!$last) {
        $fieldErrors['last_name'] = "Last name is required.";
    } elseif (!preg_match('/^[a-zA-Z\s]+$/', $last)) {
        $fieldErrors['last_name'] = "Last name must contain letters only.";
    }

    if (!$phone) {
        $fieldErrors['phone'] = "Phone number is required.";
    } elseif (!preg_match('/^09\d{9}$/', $phone)) {
        $fieldErrors['phone'] = "Enter a valid 11-digit PH number starting with 09.";
    }

    if (!$email) {
        $fieldErrors['email'] = "Email address is required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $fieldErrors['email'] = "Enter a valid email address.";
    }

    if (!$password) {
        $fieldErrors['password'] = "Password is required.";
    } elseif (!preg_match('/^(?=.*[A-Z])(?=.*[!@#$%^&*()_\-+=\[\]{};:,.<>?\/\\\\|`~]).{8,}$/', $password)) {
        $fieldErrors['password'] = "Password must have 8 characters, 1 uppercase letter, and 1 special character.";
    }

    if (!$confirm_password) {
        $fieldErrors['confirm_password'] = "Please confirm your password.";
    } elseif ($password !== $confirm_password) {
        $fieldErrors['confirm_password'] = "Passwords do not match.";
    }

    if (empty($fieldErrors)) {
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows > 0) {
            $fieldErrors['email'] = "This email is already registered.";
        }

        $stmt->close();
    }

    if (empty($fieldErrors)) {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $full_name = trim("$first $middle $last");
        $token = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', strtotime('+24 hours'));

        $insert = $conn->prepare("
            INSERT INTO users 
            (first_name, middle_name, last_name, name, phone, email, password, email_verified, verification_token, verification_expires)
            VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, ?)
        ");

        $insert->bind_param(
            "sssssssss",
            $first,
            $middle,
            $last,
            $full_name,
            $phone,
            $email,
            $hash,
            $token,
            $expires
        );

        if ($insert->execute()) {
            try {
                sendVerificationEmail($email, $first, $token, $shopName, $smtpEmail, $smtpPassword, $smtpFromName);
                $successMessage = "Account created successfully. Please check your email to verify your account before logging in.";
                $_POST = [];
            } catch (Exception $e) {
                $fieldErrors['email'] = "Account created, but verification email could not be sent. Please contact the shop.";
            }
        } else {
            $fieldErrors['general'] = "Something went wrong. Please try again.";
        }

        $insert->close();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Register | <?= htmlspecialchars($shopName) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="styles.css">

  <style>
    .auth-visual-panel {
      background:
        linear-gradient(135deg, rgba(7, 23, 47, 0.92), rgba(6, 31, 74, 0.88)),
        url("images/<?= htmlspecialchars($bannerImage) ?>") center/cover no-repeat;
    }

    .auth-register-shell {
      grid-template-columns: 0.9fr 1.1fr;
      min-height: 760px;
    }

    .auth-register-card {
      max-width: 680px;
    }

    .auth-form-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 18px;
    }

    .auth-field-full {
      grid-column: 1 / -1;
    }

    .auth-required {
      color: #dc2626;
      font-weight: 900;
    }

    .auth-field input.field-error {
      border-color: #dc2626 !important;
      background: #fff7f7;
      box-shadow: 0 0 0 4px rgba(220, 38, 38, 0.10);
    }

    .field-message {
      margin-top: 7px;
      color: #dc2626;
      font-size: 0.82rem;
      font-weight: 700;
      line-height: 1.4;
    }

    .auth-alert {
      grid-column: 1 / -1;
      padding: 14px 16px;
      border-radius: 14px;
      font-size: 0.9rem;
      font-weight: 700;
      line-height: 1.5;
    }

    .auth-alert-error {
      background: #fff1f2;
      color: #b91c1c;
      border: 1px solid #fecdd3;
    }

    .auth-alert-success {
      background: #ecfdf5;
      color: #047857;
      border: 1px solid #a7f3d0;
    }

    .password-hint {
      margin: -6px 0 0;
      padding: 12px 14px;
      border-radius: 14px;
      background: #f3f7ff;
      border: 1px solid #dbe8ff;
      color: #475569;
      line-height: 1.5;
      font-size: 0.86rem;
      font-weight: 700;
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
      border: none;
      background: transparent;
      color: #64748b;
      cursor: pointer;
      width: 34px;
      height: 34px;
      display: flex;
      align-items: center;
      justify-content: center;
      border-radius: 10px;
      transition: 0.2s ease;
    }

    .toggle-password:hover {
      background: #eef4ff;
      color: #0b3d91;
    }

    .toggle-password svg {
      width: 21px;
      height: 21px;
      pointer-events: none;
    }

    @media (max-width: 980px) {
      .auth-register-shell {
        grid-template-columns: 1fr;
        min-height: auto;
      }

      .auth-register-card {
        max-width: 720px;
      }
    }

    @media (max-width: 640px) {
      .auth-form-grid {
        grid-template-columns: 1fr;
      }

      .auth-field-full {
        grid-column: auto;
      }
    }
  </style>
</head>

<body>

<?php include 'navbar.php'; ?>

<main class="auth-page auth-register-page">

  <section class="auth-shell auth-register-shell">

    <div class="auth-visual-panel">
      <div class="auth-visual-overlay"></div>

      <div class="auth-visual-content">
        <p class="auth-eyebrow">Create Account</p>

        <h1>Start your booking experience today.</h1>

        <p class="auth-visual-text">
          Register your customer account to book appointments, track your booking status, and access your grooming history with <?= htmlspecialchars($shopName) ?>.
        </p>

        <div class="auth-feature-list">
          <div class="auth-feature-item">
            <span>✓</span>
            <p>Fast online appointment booking</p>
          </div>

          <div class="auth-feature-item">
            <span>✓</span>
            <p>Secure account and booking records</p>
          </div>

          <div class="auth-feature-item">
            <span>✓</span>
            <p>Email verification for account safety</p>
          </div>
        </div>
      </div>
    </div>

    <div class="auth-form-panel">
      <div class="auth-card auth-register-card">

        <p class="auth-small-title">Customer Registration</p>

        <h1 class="auth-title">Create your account</h1>

        <p class="auth-subtitle">
          Fill out your details below. After registration, verify your email before logging in.
        </p>

        <form class="auth-form" method="POST" novalidate autocomplete="on">

          <div class="auth-form-grid">

            <?php if (!empty($fieldErrors['general'])): ?>
              <div class="auth-alert auth-alert-error">
                <?= htmlspecialchars($fieldErrors['general']) ?>
              </div>
            <?php endif; ?>

            <?php if ($successMessage): ?>
              <div class="auth-alert auth-alert-success">
                <?= htmlspecialchars($successMessage) ?>
              </div>
            <?php endif; ?>

            <div class="auth-field">
              <label for="first_name">
                First Name <span class="auth-required">*</span>
              </label>
              <input
                type="text"
                id="first_name"
                name="first_name"
                placeholder="Enter first name"
                required
                autocomplete="given-name"
                class="<?= hasError('first_name', $fieldErrors) ?>"
                value="<?= old('first_name') ?>"
              >
              <?php if (isset($fieldErrors['first_name'])): ?>
                <div class="field-message"><?= htmlspecialchars($fieldErrors['first_name']) ?></div>
              <?php endif; ?>
            </div>

            <div class="auth-field">
              <label for="middle_name">Middle Name</label>
              <input
                type="text"
                id="middle_name"
                name="middle_name"
                placeholder="Optional"
                autocomplete="additional-name"
                class="<?= hasError('middle_name', $fieldErrors) ?>"
                value="<?= old('middle_name') ?>"
              >
              <?php if (isset($fieldErrors['middle_name'])): ?>
                <div class="field-message"><?= htmlspecialchars($fieldErrors['middle_name']) ?></div>
              <?php endif; ?>
            </div>

            <div class="auth-field">
              <label for="last_name">
                Last Name <span class="auth-required">*</span>
              </label>
              <input
                type="text"
                id="last_name"
                name="last_name"
                placeholder="Enter last name"
                required
                autocomplete="family-name"
                class="<?= hasError('last_name', $fieldErrors) ?>"
                value="<?= old('last_name') ?>"
              >
              <?php if (isset($fieldErrors['last_name'])): ?>
                <div class="field-message"><?= htmlspecialchars($fieldErrors['last_name']) ?></div>
              <?php endif; ?>
            </div>

            <div class="auth-field">
              <label for="phone">
                Phone Number <span class="auth-required">*</span>
              </label>
              <input
                type="text"
                id="phone"
                name="phone"
                placeholder="09XXXXXXXXX"
                required
                autocomplete="tel"
                inputmode="numeric"
                maxlength="11"
                pattern="^09\d{9}$"
                class="<?= hasError('phone', $fieldErrors) ?>"
                value="<?= old('phone') ?>"
              >
              <?php if (isset($fieldErrors['phone'])): ?>
                <div class="field-message"><?= htmlspecialchars($fieldErrors['phone']) ?></div>
              <?php endif; ?>
            </div>

            <div class="auth-field auth-field-full">
              <label for="email">
                Email Address <span class="auth-required">*</span>
              </label>
              <input
                type="email"
                id="email"
                name="email"
                placeholder="example@email.com"
                required
                autocomplete="email"
                class="<?= hasError('email', $fieldErrors) ?>"
                value="<?= old('email') ?>"
              >
              <?php if (isset($fieldErrors['email'])): ?>
                <div class="field-message"><?= htmlspecialchars($fieldErrors['email']) ?></div>
              <?php endif; ?>
            </div>

            <div class="auth-field">
              <label for="password">
                Password <span class="auth-required">*</span>
              </label>

              <div class="password-wrap">
                <input
                  type="password"
                  id="password"
                  name="password"
                  placeholder="Create password"
                  required
                  autocomplete="new-password"
                  minlength="8"
                  class="<?= hasError('password', $fieldErrors) ?>"
                >

                <button type="button" class="toggle-password" data-target="password" aria-label="Show password">
                  <svg class="eye-open" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.25 12s3.75-6.75 9.75-6.75S21.75 12 21.75 12 18 18.75 12 18.75 2.25 12 2.25 12z" />
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15.25A3.25 3.25 0 1 0 12 8.75a3.25 3.25 0 0 0 0 6.5z" />
                  </svg>
                </button>
              </div>

              <?php if (isset($fieldErrors['password'])): ?>
                <div class="field-message"><?= htmlspecialchars($fieldErrors['password']) ?></div>
              <?php endif; ?>
            </div>

            <div class="auth-field">
              <label for="confirm_password">
                Confirm Password <span class="auth-required">*</span>
              </label>

              <div class="password-wrap">
                <input
                  type="password"
                  id="confirm_password"
                  name="confirm_password"
                  placeholder="Repeat password"
                  required
                  autocomplete="new-password"
                  class="<?= hasError('confirm_password', $fieldErrors) ?>"
                >

                <button type="button" class="toggle-password" data-target="confirm_password" aria-label="Show confirm password">
                  <svg class="eye-open" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.25 12s3.75-6.75 9.75-6.75S21.75 12 21.75 12 18 18.75 12 18.75 2.25 12 2.25 12z" />
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15.25A3.25 3.25 0 1 0 12 8.75a3.25 3.25 0 0 0 0 6.5z" />
                  </svg>
                </button>
              </div>

              <?php if (isset($fieldErrors['confirm_password'])): ?>
                <div class="field-message"><?= htmlspecialchars($fieldErrors['confirm_password']) ?></div>
              <?php endif; ?>
            </div>

            <div class="auth-field-full password-hint">
              Password must be at least 8 characters long and include at least 1 uppercase letter and 1 special character.
            </div>

          </div>

          <button type="submit" class="auth-btn">Create Account</button>

          <p class="auth-link-text">
            Already have an account?
            <a href="login.php">Login here</a>
          </p>

        </form>

      </div>
    </div>

  </section>

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