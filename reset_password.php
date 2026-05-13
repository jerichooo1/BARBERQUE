<?php
session_start();
require 'db.php';

$errors = [];
$messages = [];

/* LOAD SETTINGS FOR SHOP DETAILS */
$settingsQuery = $conn->query("SELECT * FROM settings LIMIT 1");
$shop = $settingsQuery ? $settingsQuery->fetch_assoc() : [];

$shopName = $shop['shop_name'] ?? 'Fade Street Hair Studio';
$bannerImage = !empty($shop['banner_image']) ? $shop['banner_image'] : 'banner.jpg';

/* GET TOKEN FROM URL */
$token = trim($_GET['token'] ?? '');
$user = null;

if ($token === '') {
    $errors[] = "Invalid request. No token provided.";
} else {
    /* CHECK IF TOKEN EXISTS AND NOT EXPIRED */
    $stmt = $conn->prepare("
        SELECT id, reset_expiry 
        FROM users 
        WHERE reset_token = ? 
          AND reset_expiry > UTC_TIMESTAMP()
        LIMIT 1
    ");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();

    if (!$user) {
        $errors[] = "Invalid or expired token.";
    }
}

$user_id = $user['id'] ?? 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $user_id > 0) {
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (!$password || !$confirm_password) {
        $errors[] = "Both password fields are required.";
    } elseif (!preg_match('/^(?=.*[A-Z])(?=.*[!@#$%^&*()_\-+=\[\]{};:,.<>?\/\\\\|`~]).{8,}$/', $password)) {
        $errors[] = "Password must be at least 8 characters long and contain at least 1 uppercase letter and 1 special character.";
    } elseif ($password !== $confirm_password) {
        $errors[] = "Passwords do not match.";
    } else {
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);

        $stmt_update = $conn->prepare("
            UPDATE users 
            SET password = ?, reset_token = NULL, reset_expiry = NULL 
            WHERE id = ?
        ");
        $stmt_update->bind_param("si", $hashed_password, $user_id);
        $stmt_update->execute();

        if ($stmt_update->affected_rows > 0) {
            $messages[] = "Your password has been reset successfully.";
        } else {
            $errors[] = "Failed to update password. Please try again.";
        }

        $stmt_update->close();
    }
}

$error_message = !empty($errors) ? implode(" ", $errors) : "";
$success_message = !empty($messages) ? implode(" ", $messages) : "";
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Reset Password | <?= htmlspecialchars($shopName) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="styles.css">

  <style>
    .auth-visual-panel {
      background:
        linear-gradient(135deg, rgba(7, 23, 47, 0.92), rgba(6, 31, 74, 0.88)),
        url("images/<?= htmlspecialchars($bannerImage) ?>") center/cover no-repeat;
    }

    .auth-reset-shell {
      min-height: 640px;
    }

    .auth-reset-card {
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

    .auth-token-warning {
      margin-top: 18px;
      padding: 16px;
      border-radius: 16px;
      background: #fff7ed;
      border: 1px solid #fed7aa;
      color: #9a3412;
      font-size: 0.95rem;
      font-weight: 800;
      line-height: 1.55;
      text-align: center;
    }
  </style>
</head>

<body>

<?php include 'navbar.php'; ?>

<main class="auth-page auth-reset-page">

  <section class="auth-shell auth-reset-shell">

    <div class="auth-visual-panel">
      <div class="auth-visual-overlay"></div>

      <div class="auth-visual-content">
        <p class="auth-eyebrow">Password Reset</p>

        <h1>
          Create a stronger password.
        </h1>

        <p class="auth-visual-text">
          Set a new password for your <?= htmlspecialchars($shopName) ?> customer account. Use a password that is secure and easy for you to remember.
        </p>

        <div class="auth-feature-list">
          <div class="auth-feature-item">
            <span>✓</span>
            <p>Reset link is checked securely</p>
          </div>

          <div class="auth-feature-item">
            <span>✓</span>
            <p>New password replaces the old one</p>
          </div>

          <div class="auth-feature-item">
            <span>✓</span>
            <p>Reset token is removed after success</p>
          </div>
        </div>
      </div>
    </div>

    <div class="auth-form-panel">
      <div class="auth-card auth-reset-card">

        <p class="auth-small-title">Reset Password</p>

        <h1 class="auth-title">
          Set new password
        </h1>

        <p class="auth-subtitle">
          Enter and confirm your new password below.
        </p>

        <?php if ($user_id > 0 && !$success_message): ?>
          <form class="auth-form" method="POST" novalidate>

            <div class="auth-field">
              <label for="password">New Password</label>
              <input
                type="password"
                id="password"
                name="password"
                placeholder="Create new password"
                required
                autocomplete="new-password"
                minlength="8"
                pattern="^(?=.*[A-Z])(?=.*[!@#$%^&*()_\-+=\[\]{};:,.<>?\/\\\\|`~]).{8,}$"
                title="Password must be at least 8 characters long, contain at least 1 uppercase letter, and 1 special character."
              >
            </div>

            <div class="auth-field">
              <label for="confirm_password">Confirm Password</label>
              <input
                type="password"
                id="confirm_password"
                name="confirm_password"
                placeholder="Repeat new password"
                required
                autocomplete="new-password"
              >
            </div>

            <div class="auth-help-box">
              Password must be at least 8 characters long and include at least 1 uppercase letter and 1 special character.
            </div>

            <button type="submit" class="auth-btn">Reset Password</button>

            <p class="auth-link-text">
              Remember your password?
              <a href="login.php">Back to Login</a>
            </p>

          </form>
        <?php else: ?>
          <div class="auth-token-warning">
            This reset link is invalid, expired, or already used.
          </div>

          <p class="auth-link-text" style="margin-top: 18px;">
            <a href="forgot_password.php">Request a new reset link</a>
          </p>

          <p class="auth-link-text">
            <a href="login.php">Back to Login</a>
          </p>
        <?php endif; ?>

      </div>
    </div>

  </section>

</main>

<?php if ($error_message): ?>
<div class="custom-modal-backdrop" id="errorModal">
  <div class="custom-modal-box">
    <div class="custom-modal-icon">!</div>
    <h2>Reset Password Error</h2>
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
    <h2>Password Updated</h2>
    <p><?= htmlspecialchars($success_message) ?></p>
    <button
      type="button"
      class="custom-modal-btn"
      onclick="window.location.href='login.php'"
    >
      Go to Login
    </button>
  </div>
</div>
<?php endif; ?>

<?php include 'footer.php'; ?>

</body>
</html>