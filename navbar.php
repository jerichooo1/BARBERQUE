<?php
if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

include 'db.php';

/* GET SHOP SETTINGS */
$result = $conn->query("SELECT shop_name, logo FROM settings LIMIT 1");
$settings = $result ? $result->fetch_assoc() : [];

$shop_name = $settings['shop_name'] ?? 'Barbershop';
$logo = $settings['logo'] ?? 'logo.png';

$is_logged_in = isset($_SESSION['user_id']);
$first_name = $_SESSION['first_name'] ?? '';
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?= htmlspecialchars($shop_name) ?></title>
  <link rel="stylesheet" href="styles.css" />
</head>

<body>

<header class="customer-header">
  <nav class="customer-navbar">

    <div class="customer-nav-left">
      <a href="index.php" class="customer-brand">
        <span class="customer-logo-wrap">
          <img 
            src="images/<?= htmlspecialchars($logo) ?>" 
            alt="<?= htmlspecialchars($shop_name) ?>" 
            class="customer-nav-logo"
          >
        </span>

        <span class="customer-brand-text">
          <span class="customer-shop-name"><?= htmlspecialchars($shop_name) ?></span>
          <span class="customer-shop-tagline">Premium Barbershop</span>
        </span>
      </a>
    </div>

    <button 
      class="customer-hamburger" 
      type="button" 
      id="hamburgerBtn" 
      aria-label="Toggle menu" 
      aria-expanded="false" 
      aria-controls="navLinks"
    >
      <span></span>
      <span></span>
      <span></span>
    </button>

    <ul class="customer-nav-links" id="navLinks">
      <li><a href="index.php#services">Services</a></li>
      <li><a href="index.php#barber">Barbers</a></li>
      <li><a href="index.php#about">About</a></li>
      <li><a href="index.php#review">Reviews</a></li>

      <?php if ($is_logged_in): ?>
        <li><a href="appointments.php">Appointments</a></li>

        <li class="customer-profile-menu desktop-profile-only" id="profileMenu">
          <button class="customer-profile-btn" type="button" id="profileBtn">
            <span class="customer-profile-avatar">
              <?= htmlspecialchars(strtoupper(substr($first_name ?: 'U', 0, 1))) ?>
            </span>
            <span>Account</span>
            <span class="customer-profile-chevron">▾</span>
          </button>

          <div class="customer-profile-dropdown">
            <div class="customer-dropdown-user">
              <strong><?= htmlspecialchars($first_name ?: 'Customer') ?></strong>
              <small>Customer Account</small>
            </div>

            <a href="profile.php">Edit Profile</a>
            <a href="logout.php" class="customer-logout-link">Logout</a>
          </div>
        </li>

        <li class="mobile-profile-link"><a href="profile.php">Edit Profile</a></li>
        <li class="mobile-profile-link"><a href="logout.php">Logout</a></li>
      <?php else: ?>
        <li>
          <a href="login.php" class="customer-login-btn">Login</a>
        </li>
      <?php endif; ?>
    </ul>

  </nav>
</header>

<script>
const hamburgerBtn = document.getElementById("hamburgerBtn");
const navLinks = document.getElementById("navLinks");
const profileBtn = document.getElementById("profileBtn");
const profileMenu = document.getElementById("profileMenu");

if (hamburgerBtn && navLinks) {
  hamburgerBtn.addEventListener("click", function () {
    navLinks.classList.toggle("show");

    const isOpen = navLinks.classList.contains("show");
    hamburgerBtn.setAttribute("aria-expanded", isOpen ? "true" : "false");
    hamburgerBtn.classList.toggle("active", isOpen);
  });
}

if (profileBtn && profileMenu) {
  profileBtn.addEventListener("click", function (event) {
    if (window.innerWidth <= 768) return;

    event.stopPropagation();
    profileMenu.classList.toggle("open");
  });

  document.addEventListener("click", function () {
    profileMenu.classList.remove("open");
  });
}

document.querySelectorAll("#navLinks a").forEach(link => {
  link.addEventListener("click", function () {
    if (navLinks) {
      navLinks.classList.remove("show");
    }

    if (hamburgerBtn) {
      hamburgerBtn.setAttribute("aria-expanded", "false");
      hamburgerBtn.classList.remove("active");
    }

    if (profileMenu) {
      profileMenu.classList.remove("open");
    }
  });
});
</script>