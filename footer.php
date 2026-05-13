<?php
if (!isset($shop)) {
  include 'db.php';
  $settings = $conn->query("SELECT * FROM settings LIMIT 1");
  $shop = $settings ? $settings->fetch_assoc() : [];
}

$shopName = $shop['shop_name'] ?? 'Fade Street Hair Studio';
$logo = !empty($shop['logo']) ? $shop['logo'] : 'logo.png';
$estYear = $shop['est_year'] ?? '2023';
$facebook = $shop['facebook'] ?? $shopName;
$phone = $shop['phone'] ?? 'Contact number';
$email = $shop['email'] ?? 'Email address';
$address = $shop['address'] ?? 'Shop address';
$openingHours = $shop['opening_hours'] ?? 'Opening hours';
?>

<footer class="modern-footer">
  <div class="modern-footer-container">

    <div class="modern-footer-brand">
      <a href="index.php" class="modern-footer-logo-wrap">
        <img 
          src="images/<?= htmlspecialchars($logo) ?>" 
          alt="<?= htmlspecialchars($shopName) ?> Logo" 
          class="modern-footer-logo"
        >
      </a>

      <div>
        <h2><?= htmlspecialchars($shopName) ?></h2>
        <p>Premium grooming, clean fades, and professional barber services.</p>
      </div>
    </div>

    <div class="modern-footer-grid">

      <div class="modern-footer-box">
        <h3>Visit Us</h3>
        <p><?= htmlspecialchars($address) ?></p>
        <p><?= htmlspecialchars($openingHours) ?></p>
      </div>

      <div class="modern-footer-box">
        <h3>Contact</h3>
        <p>
          <span>Phone:</span>
          <?= htmlspecialchars($phone) ?>
        </p>
        <p>
          <span>Email:</span>
          <?= htmlspecialchars($email) ?>
        </p>
      </div>

      <div class="modern-footer-box">
        <h3>Social</h3>
        <p>
          <span>Facebook:</span>
          <?= htmlspecialchars($facebook) ?>
        </p>
      </div>

    </div>

    <div class="modern-footer-bottom">
      <p>EST <?= htmlspecialchars($estYear) ?></p>
      <p>&copy; <?= date('Y') ?> <?= htmlspecialchars($shopName) ?>. All rights reserved.</p>
    </div>

  </div>
</footer>