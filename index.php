<?php
session_start();
include 'db.php';

$settings = $conn->query("SELECT * FROM settings LIMIT 1");
$shop = $settings ? $settings->fetch_assoc() : [];

$shopName = $shop['shop_name'] ?? 'Fade Street Hair Studio';
$logo = !empty($shop['logo']) ? $shop['logo'] : 'logo.png';
$bannerImage = !empty($shop['banner_image']) ? $shop['banner_image'] : 'banner.jpg';
$bookingPolicy = $shop['booking_policy'] ?? 'A 15% downpayment is required to secure your booking. Appointments are subject to approval.';
$openingHours = $shop['opening_hours'] ?? 'Open daily';
$address = $shop['address'] ?? 'Shop address';
$phone = $shop['phone'] ?? 'Contact number';
$email = $shop['email'] ?? 'Email address';
$aboutUs = $shop['about_us'] ?? 'Welcome to our barbershop. We provide quality grooming services with professional barbers.';

$heroBookingLink = isset($_SESSION['user_id'])
  ? 'select_booking.php'
  : 'login.php?redirect=select_booking.php';

$barberReviewsData = [];

$reviewsQuery = $conn->query("
  SELECT 
    br.barber_id,
    br.rating,
    br.comment,
    br.created_at,
    CONCAT(
      COALESCE(u.first_name, ''),
      CASE 
        WHEN u.last_name IS NOT NULL AND u.last_name != '' 
        THEN CONCAT(' ', LEFT(u.last_name, 1), '.') 
        ELSE ''
      END
    ) AS reviewer_name
  FROM barber_ratings br
  LEFT JOIN users u ON br.customer_id = u.id
  ORDER BY br.created_at DESC, br.id DESC
");

if ($reviewsQuery) {
  while ($review = $reviewsQuery->fetch_assoc()) {
    $barberId = (int)$review['barber_id'];

    if (!isset($barberReviewsData[$barberId])) {
      $barberReviewsData[$barberId] = [];
    }

    $barberReviewsData[$barberId][] = [
      'rating' => (int)$review['rating'],
      'comment' => $review['comment'] ?? '',
      'reviewer_name' => trim($review['reviewer_name'] ?: 'Anonymous'),
      'created_at' => $review['created_at']
    ];
  }
}
?>

<?php include 'navbar.php'; ?>

<style>
:root {
  --fs-blue: #0b3d91;
  --fs-blue-dark: #061f4a;
  --fs-blue-mid: #092f70;
  --fs-blue-soft: #eaf1ff;
  --fs-navy: #07172f;
  --fs-text: #263238;
  --fs-muted: #6b7280;
  --fs-line: #dbe3ef;
  --fs-white: #ffffff;
  --fs-bg: #f7f9fc;
  --fs-shadow: 0 18px 45px rgba(7, 23, 47, 0.10);
  --fs-radius: 20px;
}

* {
  box-sizing: border-box;
}

body {
  background:
    radial-gradient(circle at top left, rgba(11, 61, 145, 0.12), transparent 32%),
    linear-gradient(180deg, #ffffff 0%, var(--fs-bg) 42%, #ffffff 100%);
  color: var(--fs-text);
}

.fs-home-hero {
  position: relative;
  min-height: 86vh;
  display: flex;
  align-items: center;
  overflow: hidden;
  padding: 120px 7% 70px;
  background:
    linear-gradient(115deg, rgba(6, 18, 40, 0.93), rgba(7, 23, 47, 0.82)),
    url("images/<?= htmlspecialchars($bannerImage) ?>") center/cover no-repeat;
}

.fs-home-hero::after {
  content: "";
  position: absolute;
  right: -130px;
  bottom: -130px;
  width: 360px;
  height: 360px;
  border-radius: 50%;
  background: rgba(11, 61, 145, 0.34);
  filter: blur(10px);
}

.fs-home-hero-content {
  position: relative;
  z-index: 2;
  max-width: 730px;
  color: #fff;
}

.fs-home-badge {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 9px 14px;
  border-radius: 999px;
  background: rgba(255, 255, 255, 0.13);
  border: 1px solid rgba(255, 255, 255, 0.22);
  backdrop-filter: blur(10px);
  color: #eaf1ff;
  font-size: 0.9rem;
  font-weight: 700;
  margin-bottom: 22px;
}

.fs-home-hero h1 {
  margin: 0;
  font-size: clamp(2.6rem, 6vw, 5.6rem);
  line-height: 0.95;
  letter-spacing: -3px;
  font-weight: 900;
}

.fs-home-hero h1 span {
  color: #9bbcff;
}

.fs-home-hero p {
  max-width: 650px;
  margin: 24px 0 34px;
  font-size: clamp(1rem, 2vw, 1.2rem);
  line-height: 1.8;
  color: #e8eefc;
}

.fs-home-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 14px;
}

.fs-home-primary-btn,
.fs-home-secondary-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-height: 52px;
  padding: 0 24px;
  border-radius: 999px;
  text-decoration: none;
  font-weight: 800;
  transition: 0.25s ease;
}

.fs-home-primary-btn {
  background: var(--fs-blue);
  color: white;
  box-shadow: 0 14px 30px rgba(11, 61, 145, 0.35);
}

.fs-home-primary-btn:hover {
  transform: translateY(-3px);
  background: var(--fs-blue-dark);
}

.fs-home-secondary-btn {
  color: white;
  border: 1px solid rgba(255, 255, 255, 0.35);
  background: rgba(255, 255, 255, 0.09);
}

.fs-home-secondary-btn:hover {
  background: rgba(255, 255, 255, 0.18);
  transform: translateY(-3px);
}

.fs-home-hero-stats {
  display: flex;
  flex-wrap: wrap;
  gap: 16px;
  margin-top: 45px;
}

.fs-home-stat {
  min-width: 150px;
  padding: 18px;
  border-radius: 20px;
  background: rgba(255, 255, 255, 0.12);
  border: 1px solid rgba(255, 255, 255, 0.16);
  backdrop-filter: blur(10px);
}

.fs-home-stat strong {
  display: block;
  font-size: 1.4rem;
  color: #fff;
}

.fs-home-stat span {
  display: block;
  margin-top: 4px;
  color: #dce7ff;
  font-size: 0.88rem;
}

.fs-home-shell {
  width: min(1180px, calc(100% - 32px));
  margin: 0 auto;
}

.fs-home-section {
  padding: 84px 0 0;
}

.fs-home-section-header {
  display: flex;
  justify-content: space-between;
  align-items: end;
  gap: 22px;
  margin-bottom: 28px;
}

.fs-home-eyebrow {
  margin: 0 0 10px;
  color: var(--fs-blue);
  font-size: 0.82rem;
  font-weight: 900;
  letter-spacing: 0.16em;
  text-transform: uppercase;
}

.fs-home-section-title {
  margin: 0;
  color: var(--fs-navy);
  font-size: clamp(1.9rem, 4vw, 3rem);
  line-height: 1.05;
  letter-spacing: -1.5px;
}

.fs-home-section-subtitle {
  max-width: 550px;
  margin: 10px 0 0;
  color: var(--fs-muted);
  line-height: 1.7;
}

.fs-home-policy-card {
  display: grid;
  grid-template-columns: auto 1fr;
  gap: 18px;
  padding: 26px;
  border-radius: var(--fs-radius);
  background: linear-gradient(135deg, #eaf1ff, #ffffff);
  border: 1px solid #dbe8ff;
  box-shadow: var(--fs-shadow);
}

.fs-home-policy-icon {
  width: 54px;
  height: 54px;
  display: grid;
  place-items: center;
  border-radius: 16px;
  background: var(--fs-blue);
  color: #fff;
  font-size: 1.45rem;
}

.fs-home-policy-card h2 {
  margin: 0 0 8px;
  color: var(--fs-navy);
}

.fs-home-policy-card p {
  margin: 0;
  color: #475569;
  line-height: 1.75;
}

.fs-home-services-grid,
.fs-home-barber-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 22px;
}

.fs-home-service-card {
  position: relative;
  display: flex;
  flex-direction: column;
  min-height: 285px;
  padding: 26px;
  border-radius: var(--fs-radius);
  background: #ffffff;
  border: 1px solid var(--fs-line);
  box-shadow: 0 12px 34px rgba(7, 23, 47, 0.06);
  transition: 0.25s ease;
  overflow: hidden;
}

.fs-home-service-card::before {
  content: "";
  position: absolute;
  inset: 0 0 auto;
  height: 5px;
  background: linear-gradient(90deg, var(--fs-blue-dark), var(--fs-blue));
}

.fs-home-service-card:hover {
  transform: translateY(-7px);
  box-shadow: var(--fs-shadow);
  border-color: #8fb3ff;
}

.fs-home-service-top {
  display: flex;
  justify-content: space-between;
  gap: 16px;
  align-items: flex-start;
  margin-bottom: 18px;
}

.fs-home-service-top h3 {
  margin: 0;
  color: var(--fs-navy);
  font-size: 1.22rem;
  line-height: 1.25;
}

.fs-home-duration {
  display: inline-flex;
  margin-top: 8px;
  padding: 6px 10px;
  border-radius: 999px;
  background: var(--fs-blue-soft);
  color: var(--fs-blue-dark);
  font-size: 0.78rem;
  font-weight: 800;
}

.fs-home-price {
  white-space: nowrap;
  color: var(--fs-blue);
  font-size: 1.1rem;
  font-weight: 900;
}

.fs-home-description {
  margin: 0 0 24px;
  color: var(--fs-muted);
  line-height: 1.7;
  flex: 1;
}

.fs-home-book-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-height: 46px;
  padding: 0 18px;
  border-radius: 14px;
  background: var(--fs-navy);
  color: white;
  text-decoration: none;
  font-weight: 900;
  transition: 0.25s ease;
}

.fs-home-book-btn:hover {
  background: var(--fs-blue);
  transform: translateY(-2px);
}

.fs-home-ar-card {
  position: relative;
  display: grid;
  grid-template-columns: 1.1fr 0.9fr;
  align-items: center;
  gap: 30px;
  padding: 36px;
  border-radius: 28px;
  background:
    radial-gradient(circle at 85% 15%, rgba(255, 255, 255, 0.18), transparent 26%),
    linear-gradient(135deg, var(--fs-navy), var(--fs-blue-dark), var(--fs-blue));
  color: white;
  overflow: hidden;
  box-shadow: var(--fs-shadow);
}

.fs-home-ar-card::after {
  content: "";
  position: absolute;
  right: -80px;
  bottom: -90px;
  width: 260px;
  height: 260px;
  border-radius: 50%;
  background: rgba(255, 255, 255, 0.13);
}

.fs-home-ar-card h2 {
  margin: 0 0 12px;
  font-size: clamp(1.9rem, 4vw, 3.1rem);
  letter-spacing: -1.3px;
  line-height: 1.05;
}

.fs-home-ar-card p {
  margin: 0 0 24px;
  color: #e8eefc;
  line-height: 1.75;
}

.fs-home-ar-button {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-height: 50px;
  padding: 0 22px;
  border-radius: 999px;
  background: #fff;
  color: var(--fs-blue-dark);
  font-weight: 900;
  text-decoration: none;
  transition: 0.25s ease;
}

.fs-home-ar-button:hover {
  transform: translateY(-3px);
  box-shadow: 0 16px 35px rgba(0, 0, 0, 0.16);
}

.fs-home-ar-visual {
  position: relative;
  z-index: 2;
  display: grid;
  place-items: center;
}

.fs-home-ar-phone {
  width: min(100%, 290px);
  min-height: 360px;
  border-radius: 34px;
  padding: 18px;
  background: rgba(255, 255, 255, 0.14);
  border: 1px solid rgba(255, 255, 255, 0.25);
  backdrop-filter: blur(12px);
  box-shadow: 0 18px 35px rgba(0, 0, 0, 0.18);
}

.fs-home-ar-phone-screen {
  position: relative;
  height: 100%;
  min-height: 320px;
  border-radius: 26px;
  background:
    linear-gradient(180deg, rgba(255,255,255,0.16), rgba(255,255,255,0.04)),
    rgba(255, 255, 255, 0.10);
  display: flex;
  align-items: center;
  justify-content: center;
  overflow: hidden;
  padding: 20px;
}

.fs-home-ar-preview-img {
  width: 100%;
  max-width: 210px;
  height: auto;
  object-fit: contain;
  display: block;
  filter: drop-shadow(0 12px 24px rgba(0, 0, 0, 0.15));
}

.fs-home-ar-preview-label {
  position: absolute;
  left: 50%;
  bottom: 18px;
  transform: translateX(-50%);
  padding: 10px 16px;
  border-radius: 999px;
  background: rgba(7, 23, 47, 0.72);
  color: #ffffff;
  font-size: 0.92rem;
  font-weight: 800;
  white-space: nowrap;
  backdrop-filter: blur(8px);
  box-shadow: 0 10px 22px rgba(0, 0, 0, 0.16);
}

.fs-home-barber-card {
  cursor: pointer;
  overflow: hidden;
  border-radius: 24px;
  background:
    linear-gradient(180deg, #0a1d3f 0%, #0b2d66 55%, #0b3d91 100%);
  border: 1px solid rgba(143, 179, 255, 0.24);
  box-shadow: 0 18px 42px rgba(7, 23, 47, 0.18);
  transition: 0.28s ease;
}

.fs-home-barber-card:hover {
  transform: translateY(-8px);
  box-shadow: 0 24px 50px rgba(7, 23, 47, 0.28);
  border-color: rgba(155, 188, 255, 0.6);
}

.fs-home-barber-photo-wrap {
  position: relative;
  width: 100%;
  height: 220px;
  overflow: hidden;
  border-radius: 0;
  background:
    radial-gradient(circle at top, rgba(255,255,255,0.18), transparent 45%),
    linear-gradient(180deg, #0f274f 0%, #0b1d3d 100%);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 10px;
}

.fs-home-barber-photo {
  width: 100%;
  height: 100%;
  object-fit: contain;
  object-position: center center;
  border-radius: 0 !important;
  clip-path: none !important;
  -webkit-clip-path: none !important;
  mask-image: none !important;
  -webkit-mask-image: none !important;
  margin: 0 !important;
  display: block;
  transition: none;
}

.fs-home-barber-card:hover .fs-home-barber-photo {
  transform: none;
}

.fs-home-barber-rating-pill {
  position: absolute;
  left: 16px;
  bottom: 14px;
  padding: 8px 12px;
  border-radius: 999px;
  background: rgba(7, 23, 47, 0.88);
  color: #fff;
  font-size: 0.86rem;
  font-weight: 900;
  backdrop-filter: blur(8px);
}

.fs-home-barber-details {
  padding: 22px;
  background: #ffffff;
  border-top: 1px solid #dbe3ef;
}

.fs-home-barber-details h3 {
  margin: 0 0 5px;
  color: var(--fs-navy);
  font-size: 1.25rem;
}

.fs-home-barber-details p {
  margin: 0 0 12px;
  color: var(--fs-muted);
}

.fs-home-rating {
  color: #f2b01e;
  font-weight: 900;
}

.fs-home-rating span {
  color: var(--fs-muted);
  font-weight: 700;
  font-size: 0.9rem;
}

.fs-home-about-card {
  display: grid;
  grid-template-columns: 1.1fr 0.9fr;
  gap: 28px;
  padding: 34px;
  border-radius: 28px;
  background: #fff;
  border: 1px solid var(--fs-line);
  box-shadow: var(--fs-shadow);
}

.fs-home-about-text h2 {
  margin: 0 0 14px;
  color: var(--fs-navy);
  font-size: clamp(1.9rem, 4vw, 2.7rem);
  letter-spacing: -1px;
}

.fs-home-about-text p {
  color: var(--fs-muted);
  line-height: 1.85;
}

.fs-home-contact-grid {
  display: grid;
  gap: 14px;
  margin-top: 24px;
}

.fs-home-contact-item {
  padding: 16px;
  border-radius: 18px;
  background: #f8fbff;
  border: 1px solid #e5efff;
}

.fs-home-contact-item strong {
  display: block;
  color: var(--fs-navy);
  margin-bottom: 5px;
}

.fs-home-contact-item span {
  color: var(--fs-muted);
}

.fs-home-map-box {
  min-height: 360px;
  border-radius: 24px;
  overflow: hidden;
  background: var(--fs-blue-soft);
}

.fs-home-map-box img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.fs-home-reviews-grid {
  display: grid;
  grid-template-columns: 1fr 340px;
  gap: 24px;
  align-items: start;
}

.fs-home-reviews-left,
.fs-home-review-summary {
  background: #fff;
  border: 1px solid var(--fs-line);
  border-radius: var(--fs-radius);
  box-shadow: 0 12px 34px rgba(7, 23, 47, 0.06);
}

.fs-home-reviews-left {
  padding: 26px;
}

.fs-home-review-summary {
  padding: 28px;
  position: sticky;
  top: 100px;
}

.fs-home-review-score {
  text-align: center;
  padding: 24px;
  border-radius: 22px;
  background: linear-gradient(135deg, #eaf1ff, #ffffff);
  border: 1px solid #dce8ff;
}

.fs-home-review-score h3 {
  margin: 0;
  color: var(--fs-navy);
  font-size: 3rem;
  line-height: 1;
}

.fs-home-review-score p {
  margin: 8px 0 0;
  color: var(--fs-muted);
  font-weight: 800;
}

.fs-home-show-reviews-btn,
.fs-home-review-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 100%;
  min-height: 48px;
  margin-top: 16px;
  border-radius: 14px;
  text-decoration: none;
  font-weight: 900;
  transition: 0.25s ease;
}

.fs-home-show-reviews-btn {
  background: var(--fs-blue-soft);
  color: var(--fs-blue-dark);
}

.fs-home-review-btn {
  background: var(--fs-blue);
  color: #fff;
}

.fs-home-show-reviews-btn:hover,
.fs-home-review-btn:hover {
  transform: translateY(-2px);
}

.barber-review-modal-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(15, 23, 42, 0.65);
  display: none;
  align-items: center;
  justify-content: center;
  z-index: 10000;
  padding: 20px;
  backdrop-filter: blur(8px);
}

.barber-review-modal-backdrop.show {
  display: flex;
}

.barber-review-modal {
  position: relative;
  width: min(100%, 760px);
  max-height: 90vh;
  overflow-y: auto;
  background: #fff;
  border-radius: 28px;
  padding: 26px;
  box-shadow: 0 26px 70px rgba(0, 0, 0, 0.26);
}

.barber-review-close {
  position: absolute;
  top: 14px;
  right: 16px;
  width: 42px;
  height: 42px;
  border-radius: 50%;
  background: #f3f6fb;
  border: none;
  font-size: 28px;
  cursor: pointer;
  line-height: 1;
  color: var(--fs-navy);
}

.barber-review-header {
  display: flex;
  gap: 18px;
  align-items: center;
  margin-bottom: 20px;
  padding-right: 38px;
}

.barber-review-image {
  width: 96px;
  height: 96px;
  object-fit: cover;
  border-radius: 24px;
  border: 3px solid #dce8ff;
  background: #f4f4f4;
}

.barber-review-header-text h2 {
  margin: 0 0 4px;
  color: var(--fs-navy);
}

.barber-review-header-text p {
  margin: 0 0 8px;
  color: var(--fs-muted);
}

.barber-review-summary {
  font-weight: 900;
  color: var(--fs-blue);
}

.barber-review-list {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.barber-review-item {
  border: 1px solid var(--fs-line);
  border-radius: 18px;
  padding: 16px;
  background: #fafcff;
}

.barber-review-top {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: wrap;
  margin-bottom: 8px;
}

.barber-review-name {
  font-weight: 900;
  color: var(--fs-navy);
}

.barber-review-date {
  font-size: 0.9rem;
  color: var(--fs-muted);
}

.barber-review-stars {
  color: #f2b01e;
  font-size: 1rem;
  margin-bottom: 6px;
}

.barber-review-comment {
  color: #334155;
  line-height: 1.6;
}

.barber-review-empty {
  padding: 20px;
  border-radius: 18px;
  background: #f4f8ff;
  border: 1px dashed #b8c7ea;
  color: #355081;
  text-align: center;
}

#barber .fs-home-barber-card .fs-home-barber-photo-wrap {
  height: 220px !important;
  border-radius: 0 !important;
  overflow: hidden !important;
  background:
    radial-gradient(circle at top, rgba(255,255,255,0.18), transparent 45%),
    linear-gradient(180deg, #0f274f 0%, #0b1d3d 100%) !important;
}

#barber .fs-home-barber-card img.fs-home-barber-photo {
  border-radius: 0 !important;
  width: 100% !important;
  height: 100% !important;
  max-width: none !important;
  object-fit: contain !important;
  object-position: center center !important;
  clip-path: none !important;
  -webkit-clip-path: none !important;
  mask-image: none !important;
  -webkit-mask-image: none !important;
  transform: none !important;
}

@media (max-width: 1024px) {
  .fs-home-services-grid,
  .fs-home-barber-grid {
    grid-template-columns: repeat(2, 1fr);
  }

  .fs-home-ar-card,
  .fs-home-about-card,
  .fs-home-reviews-grid {
    grid-template-columns: 1fr;
  }

  .fs-home-review-summary {
    position: static;
  }
}

@media (max-width: 768px) {
  .fs-home-hero {
    min-height: auto;
    padding: 115px 22px 72px;
  }

  .fs-home-hero h1 {
    letter-spacing: -1.8px;
  }

  .fs-home-section {
    padding-top: 66px;
  }

  .fs-home-section-header {
    display: block;
  }

  .fs-home-services-grid,
  .fs-home-barber-grid {
    grid-template-columns: 1fr;
  }

  .fs-home-barber-photo-wrap,
  #barber .fs-home-barber-card .fs-home-barber-photo-wrap {
    height: 200px !important;
  }

  .fs-home-policy-card {
    grid-template-columns: 1fr;
  }

  .fs-home-ar-card,
  .fs-home-about-card {
    padding: 24px;
    border-radius: 24px;
  }

  .barber-review-header {
    flex-direction: column;
    text-align: center;
    padding-right: 0;
  }
}

@media (max-width: 520px) {
  .fs-home-shell {
    width: min(100% - 22px, 1180px);
  }

  .fs-home-hero {
    padding-left: 18px;
    padding-right: 18px;
  }

  .fs-home-actions a {
    width: 100%;
  }

  .fs-home-reviews-left,
  .fs-home-review-summary,
  .fs-home-policy-card {
    padding: 18px;
  }

  .fs-home-service-card {
    padding: 22px;
  }

  .fs-home-service-top {
    flex-direction: column;
  }

  .fs-home-barber-photo-wrap,
  #barber .fs-home-barber-card .fs-home-barber-photo-wrap {
    height: 180px !important;
  }
}
</style>

<section class="fs-home-hero">
  <div class="fs-home-hero-content">
    <div class="fs-home-badge">
      ✂ Premium Grooming Experience
    </div>

    <h1>
      Fresh cuts.<br>
      Clean fades.<br>
      <span>Better confidence.</span>
    </h1>

    <p>
      Welcome to <?= htmlspecialchars($shopName) ?> — book your barber, choose your service,
      try styles virtually, and enjoy a smoother barbershop appointment experience.
    </p>

    <div class="fs-home-actions">
      <button 
  type="button"
  class="fs-home-primary-btn"
  onclick="window.location.href='<?= isset($_SESSION['user_id']) ? 'select_booking.php' : 'login.php?redirect=select_booking.php' ?>'"
>
  Book an Appointment
</button>

      <a href="ar/index.php" class="fs-home-secondary-btn">
        Try AR Hairstyle
      </a>
    </div>

    <div class="fs-home-hero-stats">
      <div class="fs-home-stat">
        <strong>15%</strong>
        <span>Required downpayment</span>
      </div>

      <div class="fs-home-stat">
        <strong>AR</strong>
        <span>Virtual hairstyle try-on</span>
      </div>

      <div class="fs-home-stat">
        <strong>Pro</strong>
        <span>Experienced barbers</span>
      </div>
    </div>
  </div>
</section>

<main class="fs-home-shell">

  <section class="fs-home-section">
    <div class="fs-home-policy-card">
      <div class="fs-home-policy-icon">!</div>

      <div>
        <h2>Booking Policy</h2>
        <p><?= nl2br(htmlspecialchars($bookingPolicy)) ?></p>
      </div>
    </div>
  </section>

  <section id="services" class="fs-home-section">
    <div class="fs-home-section-header">
      <div>
        <p class="fs-home-eyebrow">Our Services</p>
        <h2 class="fs-home-section-title">Choose your next look</h2>
      </div>

      <p class="fs-home-section-subtitle">
        Select a service, choose your barber, add optional services, and pick your preferred schedule.
      </p>
    </div>

    <div class="fs-home-services-grid">
      <?php
      $result = $conn->query("SELECT * FROM services ORDER BY name ASC");

      if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
          $serviceName = htmlspecialchars($row['name']);
          $duration = (int)$row['duration_minutes'] . ' mins';
          $priceText = '₱' . number_format((float)$row['price'], 2);

          if ($serviceName === 'HAIR COLOR' || $serviceName === 'PERM / TRADITIONAL PERM') {
            $priceText .= ' up';
          }

          $bookingLink = isset($_SESSION['user_id'])
            ? 'select_booking.php?service_id=' . (int)$row['id']
            : 'login.php?redirect=select_booking.php&service_id=' . (int)$row['id'];

          echo '<article class="fs-home-service-card">';
          echo '  <div class="fs-home-service-top">';
          echo '    <div>';
          echo '      <h3>' . $serviceName . '</h3>';
          echo '      <span class="fs-home-duration">' . htmlspecialchars($duration) . '</span>';
          echo '    </div>';
          echo '    <div class="fs-home-price">' . $priceText . '</div>';
          echo '  </div>';

          echo '  <p class="fs-home-description">' . htmlspecialchars($row['description']) . '</p>';
          echo '  <a href="' . htmlspecialchars($bookingLink) . '" class="fs-home-book-btn">Book Service</a>';
          echo '</article>';
        }
      } else {
        echo '<p>No services available at the moment.</p>';
      }
      ?>
    </div>
  </section>

  <section class="fs-home-section">
    <div class="fs-home-ar-card">
      <div>
        <p class="fs-home-eyebrow" style="color:#cfe0ff;">Virtual Try-On</p>
        <h2>Not sure about that cut? Try it first.</h2>
        <p>
          Preview hairstyles using our Augmented Reality feature before booking your appointment.
          It helps customers choose a style with more confidence.
        </p>
        <a href="ar/index.php" class="fs-home-ar-button">Open AR Hairstyle Try-On</a>
      </div>

      <div class="fs-home-ar-visual">
        <div class="fs-home-ar-phone">
          <div class="fs-home-ar-phone-screen">
            <img src="images/ar-preview.webp" alt="AR hairstyle preview" class="fs-home-ar-preview-img">

            <div class="fs-home-ar-preview-label">
              Live hairstyle preview
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <section id="barber" class="fs-home-section">
    <div class="fs-home-section-header">
      <div>
        <p class="fs-home-eyebrow">The Artisans</p>
        <h2 class="fs-home-section-title">Meet the barbers</h2>
      </div>

      <p class="fs-home-section-subtitle">
        View barber ratings and customer feedback to help you choose the right barber for your style.
      </p>
    </div>

    <div class="fs-home-barber-grid">
      <?php
      $barbers = $conn->query("
        SELECT 
          b.*,
          COALESCE(ROUND(AVG(br.rating), 1), 0) AS avg_rating,
          COUNT(br.id) AS total_ratings
        FROM barbers b
        LEFT JOIN barber_ratings br ON b.id = br.barber_id
        WHERE b.status = 'active'
        GROUP BY b.id
        ORDER BY b.name ASC
      ");

      if ($barbers && $barbers->num_rows > 0) {
        while ($barber = $barbers->fetch_assoc()) {
          $avg_rating = floatval($barber['avg_rating']);
          $total_ratings = intval($barber['total_ratings']);
          $fullStars = floor($avg_rating);
          $imagePath = !empty($barber['image_path']) ? $barber['image_path'] : 'default-barber.png';

          echo '<article class="fs-home-barber-card open-barber-reviews"
                  data-barber-id="' . (int)$barber['id'] . '"
                  data-barber-name="' . htmlspecialchars($barber['name'], ENT_QUOTES) . '"
                  data-barber-title="' . htmlspecialchars($barber['title'], ENT_QUOTES) . '"
                  data-barber-image="' . htmlspecialchars($imagePath, ENT_QUOTES) . '"
                  data-barber-rating="' . number_format($avg_rating, 1) . '"
                  data-barber-total-ratings="' . $total_ratings . '">';

          echo '  <div class="fs-home-barber-photo-wrap">';
          echo '    <img src="images/' . htmlspecialchars($imagePath) . '" alt="' . htmlspecialchars($barber['name']) . '" class="fs-home-barber-photo">';
          echo '    <div class="fs-home-barber-rating-pill">★ ' . number_format($avg_rating, 1) . ' / 5</div>';
          echo '  </div>';

          echo '  <div class="fs-home-barber-details">';
          echo '    <h3>' . htmlspecialchars($barber['name']) . '</h3>';
          echo '    <p>' . htmlspecialchars($barber['title']) . '</p>';

          echo '    <div class="fs-home-rating">';
          for ($i = 1; $i <= 5; $i++) {
            echo ($i <= $fullStars) ? '★' : '☆';
          }
          echo ' <span>(' . $total_ratings . ' rating' . ($total_ratings == 1 ? '' : 's') . ')</span>';
          echo '    </div>';

          echo '  </div>';
          echo '</article>';
        }
      } else {
        echo '<p>No active barbers available at the moment.</p>';
      }
      ?>
    </div>
  </section>

  <section id="about" class="fs-home-section">
    <div class="fs-home-about-card">
      <div class="fs-home-about-text">
        <p class="fs-home-eyebrow">About Us</p>
        <h2><?= htmlspecialchars($shopName) ?></h2>

        <p><?= nl2br(htmlspecialchars($aboutUs)) ?></p>

        <div class="fs-home-contact-grid">
          <div class="fs-home-contact-item">
            <strong>Phone</strong>
            <span><?= htmlspecialchars($phone) ?></span>
          </div>

          <div class="fs-home-contact-item">
            <strong>Email</strong>
            <span><?= htmlspecialchars($email) ?></span>
          </div>

          <div class="fs-home-contact-item">
            <strong>Social Media</strong>
            <span>📘 <?= htmlspecialchars($shopName) ?></span>
          </div>
        </div>
      </div>

      <div class="fs-home-map-box">
        <img src="images/map.png" alt="Shop Address Map">
      </div>
    </div>
  </section>

  <section id="review" class="fs-home-section">
    <div class="fs-home-section-header">
      <div>
        <p class="fs-home-eyebrow">Customer Feedback</p>
        <h2 class="fs-home-section-title">What clients say</h2>
      </div>

      <p class="fs-home-section-subtitle">
        Where real experiences shape better grooming decisions.
      </p>
    </div>

    <div class="fs-home-reviews-grid">
      <div class="fs-home-reviews-left">
        <?php include 'get_rating_breakdown.php'; ?>
        <?php include 'get_reviews.php'; ?>

        <a href="all_reviews.php" class="fs-home-show-reviews-btn">
          Show All Reviews
        </a>
      </div>

      <aside class="fs-home-review-summary">
        <?php
        $sql = "SELECT AVG(rating) AS avg_rating, COUNT(*) AS total_reviews FROM reviews";
        $result = $conn->query($sql);
        $data = $result ? $result->fetch_assoc() : ['avg_rating' => 0, 'total_reviews' => 0];

        $avgRating = round((float)$data['avg_rating'], 1);
        $totalReviews = (int)$data['total_reviews'];
        ?>

        <div class="fs-home-review-score">
          <h3><?= htmlspecialchars($avgRating) ?></h3>
          <p><?= str_repeat("⭐", (int)round($avgRating)) ?></p>
          <p><?= $totalReviews ?> review<?= $totalReviews == 1 ? '' : 's' ?></p>
        </div>

        <?php if (isset($_SESSION['user_id'])): ?>
          <a href="write_review.php" class="fs-home-review-btn">Write a Review</a>
        <?php else: ?>
          <a href="login.php" class="fs-home-review-btn">Write a Review</a>
        <?php endif; ?>
      </aside>
    </div>
  </section>

</main>

<div class="barber-review-modal-backdrop" id="barberReviewModal">
  <div class="barber-review-modal">
    <button type="button" class="barber-review-close" id="closeBarberReviewModal">&times;</button>

    <div class="barber-review-header">
      <img src="" alt="" id="modalBarberImage" class="barber-review-image">

      <div class="barber-review-header-text">
        <h2 id="modalBarberName">Barber Name</h2>
        <p id="modalBarberTitle">Barber Title</p>
        <div class="barber-review-summary" id="modalBarberSummary"></div>
      </div>
    </div>

    <div class="barber-review-list" id="modalBarberReviewsList"></div>
  </div>
</div>

<script>
const barberReviewsData = <?= json_encode($barberReviewsData) ?>;

const barberCards = document.querySelectorAll('.open-barber-reviews');
const barberReviewModal = document.getElementById('barberReviewModal');
const closeBarberReviewModal = document.getElementById('closeBarberReviewModal');

const modalBarberImage = document.getElementById('modalBarberImage');
const modalBarberName = document.getElementById('modalBarberName');
const modalBarberTitle = document.getElementById('modalBarberTitle');
const modalBarberSummary = document.getElementById('modalBarberSummary');
const modalBarberReviewsList = document.getElementById('modalBarberReviewsList');

function escapeHTML(value) {
  return String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');
}

function buildStars(rating) {
  let stars = '';
  for (let i = 1; i <= 5; i++) {
    stars += i <= rating ? '★' : '☆';
  }
  return stars;
}

function formatReviewDate(dateStr) {
  if (!dateStr) return '';
  const date = new Date(String(dateStr).replace(' ', 'T'));
  if (isNaN(date.getTime())) return '';
  return date.toLocaleDateString([], {
    year: 'numeric',
    month: 'long',
    day: 'numeric'
  });
}

barberCards.forEach(card => {
  card.addEventListener('click', function() {
    const barberId = this.dataset.barberId;
    const barberName = this.dataset.barberName;
    const barberTitle = this.dataset.barberTitle;
    const barberImage = this.dataset.barberImage;
    const barberRating = this.dataset.barberRating;
    const totalRatings = this.dataset.barberTotalRatings;
    const reviews = barberReviewsData[barberId] || [];

    modalBarberImage.src = 'images/' + barberImage;
    modalBarberImage.alt = barberName;
    modalBarberName.textContent = barberName;
    modalBarberTitle.textContent = barberTitle;
    modalBarberSummary.textContent = `${barberRating} / 5 from ${totalRatings} rating(s)`;

    if (reviews.length > 0) {
      modalBarberReviewsList.innerHTML = reviews.map(review => `
        <div class="barber-review-item">
          <div class="barber-review-top">
            <div class="barber-review-name">${escapeHTML(review.reviewer_name)}</div>
            <div class="barber-review-date">${escapeHTML(formatReviewDate(review.created_at))}</div>
          </div>

          <div class="barber-review-stars">${buildStars(parseInt(review.rating))}</div>

          <div class="barber-review-comment">
            ${
              review.comment && review.comment.trim() !== ''
                ? escapeHTML(review.comment)
                : 'No written comment provided.'
            }
          </div>
        </div>
      `).join('');
    } else {
      modalBarberReviewsList.innerHTML = `
        <div class="barber-review-empty">
          No reviews yet for this barber.
        </div>
      `;
    }

    barberReviewModal.classList.add('show');
    document.body.style.overflow = 'hidden';
  });
});

function closeModal() {
  barberReviewModal.classList.remove('show');
  document.body.style.overflow = '';
}

closeBarberReviewModal.addEventListener('click', closeModal);

barberReviewModal.addEventListener('click', function(e) {
  if (e.target === barberReviewModal) {
    closeModal();
  }
});

document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape' && barberReviewModal.classList.contains('show')) {
    closeModal();
  }
});
</script>

<?php include 'footer.php'; ?>