<?php
session_start();
require 'db.php';

$settings = $conn->query("SELECT * FROM settings LIMIT 1");
$shop = $settings ? $settings->fetch_assoc() : [];

$shopName = $shop['shop_name'] ?? 'Fade Street Hair Studio';
$bannerImage = !empty($shop['banner_image']) ? $shop['banner_image'] : 'banner.jpg';

$errors = [];
$emailError = "";
$passwordError = "";

$redirect = $_GET['redirect'] ?? ($_POST['redirect'] ?? '');
$service_id = $_GET['service_id'] ?? ($_POST['service_id'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!$email) {
        $emailError = "Email address is required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $emailError = "Please enter a valid email address.";
    }

    if (!$password) {
        $passwordError = "Password is required.";
    }

    if (!$emailError && !$passwordError) {
        $stmt = $conn->prepare("
            SELECT id, first_name, password, email_verified 
            FROM users 
            WHERE email = ?
            LIMIT 1
        ");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows === 1) {
            $stmt->bind_result($user_id, $first_name, $hashed_password, $email_verified);
            $stmt->fetch();

            if (password_verify($password, $hashed_password)) {
                if ((int)$email_verified !== 1) {
                    $emailError = "Please verify your email address before logging in.";
                } else {
                    $_SESSION['user_id'] = $user_id;
                    $_SESSION['first_name'] = $first_name;

                    $redirect = trim($_POST['redirect'] ?? '');
                    $service_id = trim($_POST['service_id'] ?? '');

                    if ($redirect !== '') {
                        $allowed_redirects = [
                            'index.php',
                            'select_barber.php',
                            'select_booking.php',
                            'select_additional_services.php',
                            'select_schedule.php',
                            'payment.php',
                            'appointments.php',
                            'ar/index.php'
                        ];

                        $normalized_redirect = str_replace('\\', '/', $redirect);

                        if (in_array($normalized_redirect, $allowed_redirects, true)) {
                            if ($service_id !== '' && ctype_digit($service_id)) {
                                header("Location: {$normalized_redirect}?service_id={$service_id}");
                            } else {
                                header("Location: {$normalized_redirect}");
                            }
                            exit;
                        }
                    }

                    header("Location: index.php");
                    exit;
                }
            } else {
                $passwordError = "Invalid email or password.";
            }
        } else {
            $emailError = "Invalid email or password.";
        }

        $stmt->close();
    }
}

function fieldClass($error) {
    return $error ? 'field-error' : '';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Login | <?= htmlspecialchars($shopName) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="styles.css">

  <style>
    .auth-visual-panel {
      background:
        linear-gradient(135deg, rgba(7, 23, 47, 0.92), rgba(6, 31, 74, 0.88)),
        url("images/<?= htmlspecialchars($bannerImage) ?>") center/cover no-repeat;
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
  </style>
</head>
<body>

<?php include 'navbar.php'; ?>

<main class="auth-page auth-login-page">

  <section class="auth-shell">

    <div class="auth-visual-panel">
      <div class="auth-visual-overlay"></div>

      <div class="auth-visual-content">
        <p class="auth-eyebrow">Welcome Back</p>

        <h1>
          Book your next clean cut with confidence.
        </h1>

        <p class="auth-visual-text">
          Login to manage your appointments, view your booking status, and continue your grooming experience with <?= htmlspecialchars($shopName) ?>.
        </p>

        <div class="auth-feature-list">
          <div class="auth-feature-item">
            <span>✓</span>
            <p>Book appointments online</p>
          </div>

          <div class="auth-feature-item">
            <span>✓</span>
            <p>Track approval and payment status</p>
          </div>

          <div class="auth-feature-item">
            <span>✓</span>
            <p>Try hairstyles using AR preview</p>
          </div>
        </div>
      </div>
    </div>

    <div class="auth-form-panel">
      <div class="auth-card">

        <p class="auth-small-title">Customer Portal</p>

        <h1 class="auth-title">
          Sign in to your account
        </h1>

        <p class="auth-subtitle">
          Enter your verified email and password to continue.
        </p>

        <form class="auth-form" method="POST" novalidate>
          <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect) ?>">
          <input type="hidden" name="service_id" value="<?= htmlspecialchars($service_id) ?>">

          <div class="auth-field">
            <label for="email">Email Address</label>
            <input 
              type="email" 
              id="email"
              name="email" 
              placeholder="example@email.com" 
              required 
              class="<?= fieldClass($emailError) ?>"
              value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
            >

            <?php if ($emailError): ?>
              <div class="field-message"><?= htmlspecialchars($emailError) ?></div>
            <?php endif; ?>
          </div>

          <div class="auth-field">
            <label for="password">Password</label>

            <div class="password-wrap">
              <input 
                type="password" 
                id="password"
                name="password" 
                placeholder="Enter your password" 
                required
                class="<?= fieldClass($passwordError) ?>"
              >

              <button type="button" class="toggle-password" id="togglePassword" aria-label="Show password">
                <svg id="eyeIcon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.25 12s3.75-6.75 9.75-6.75S21.75 12 21.75 12 18 18.75 12 18.75 2.25 12 2.25 12z" />
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15.25A3.25 3.25 0 1 0 12 8.75a3.25 3.25 0 0 0 0 6.5z" />
                </svg>
              </button>
            </div>

            <?php if ($passwordError): ?>
              <div class="field-message"><?= htmlspecialchars($passwordError) ?></div>
            <?php endif; ?>
          </div>

          <div class="auth-extra-row">
            <span></span>
            <a href="forgot_password.php">Forgot Password?</a>
          </div>

          <button type="submit" class="auth-btn">Login</button>

          <p class="auth-link-text">
            Don't have an account?
            <a href="register.php">Create an account</a>
          </p>
        </form>

      </div>
    </div>

  </section>

</main>

<?php include 'footer.php'; ?>

<script>
const passwordInput = document.getElementById('password');
const togglePassword = document.getElementById('togglePassword');

if (passwordInput && togglePassword) {
  togglePassword.addEventListener('click', function () {
    const isPassword = passwordInput.type === 'password';
    passwordInput.type = isPassword ? 'text' : 'password';
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
}
</script>

</body>
</html>