<?php
session_start();
include 'db.php';

if (!isset($_SESSION['user_id'])) {
  header("Location: login.php");
  exit();
}

$settings = $conn->query("SELECT * FROM settings LIMIT 1");
$shop = $settings ? $settings->fetch_assoc() : [];

$shopName = $shop['shop_name'] ?? 'Fade Street Hair Studio';
$bannerImage = !empty($shop['banner_image']) ? $shop['banner_image'] : 'banner.jpg';

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $user_id = (int)$_SESSION['user_id'];
  $rating = intval($_POST['rating'] ?? 0);
  $comment = trim($_POST['comment'] ?? '');
  $photo_path = null;

  if ($rating < 1 || $rating > 5) {
    $errors[] = "Please select a valid rating.";
  }

  if ($comment === '') {
    $errors[] = "Please write your review comment.";
  }

  if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
    $target_dir = __DIR__ . "/uploads/reviews/";

    if (!is_dir($target_dir)) {
      mkdir($target_dir, 0777, true);
    }

    $file_tmp = $_FILES["photo"]["tmp_name"];
    $file_ext = strtolower(pathinfo($_FILES["photo"]["name"], PATHINFO_EXTENSION));
    $allowed_types = ["jpg", "jpeg", "png", "gif", "webp"];

    if (in_array($file_ext, $allowed_types, true)) {
      $new_name = uniqid("review_", true) . "." . $file_ext;
      $target_file = $target_dir . $new_name;

      if (move_uploaded_file($file_tmp, $target_file)) {
        $photo_path = "uploads/reviews/" . $new_name;
      }
    } else {
      $errors[] = "Invalid photo type. Please upload JPG, PNG, GIF, or WEBP.";
    }
  }

  if (empty($errors)) {
    $stmt = $conn->prepare("
      INSERT INTO reviews (user_id, rating, comment, photo, created_at)
      VALUES (?, ?, ?, ?, NOW())
    ");
    $stmt->bind_param("iiss", $user_id, $rating, $comment, $photo_path);

    if ($stmt->execute()) {
      $stmt->close();
      header("Location: index.php#review");
      exit();
    } else {
      $errors[] = "Something went wrong while submitting your review.";
      $stmt->close();
    }
  }
}

$error_message = !empty($errors) ? implode(" ", $errors) : "";
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Write a Review | <?= htmlspecialchars($shopName) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">

  <link rel="stylesheet" href="styles.css">

  <style>
    .write-review-page {
      min-height: 100vh;
      padding: 150px 24px 70px;
      background:
        radial-gradient(circle at top left, rgba(11, 61, 145, 0.13), transparent 34%),
        linear-gradient(180deg, #ffffff 0%, #f7f9fc 48%, #ffffff 100%);
      box-sizing: border-box;
    }

    .write-review-shell {
      width: min(980px, 100%);
      margin: 0 auto;
      display: grid;
      grid-template-columns: 0.9fr 1.1fr;
      background: #ffffff;
      border: 1px solid #dbe3ef;
      border-radius: 34px;
      overflow: hidden;
      box-shadow: 0 24px 70px rgba(7, 23, 47, 0.14);
    }

    .write-review-visual {
      position: relative;
      padding: 64px 42px;
      color: #ffffff;
      display: flex;
      align-items: flex-start;
      background:
        linear-gradient(135deg, rgba(7, 23, 47, 0.93), rgba(6, 31, 74, 0.88)),
        url("images/<?= htmlspecialchars($bannerImage) ?>") center/cover no-repeat;
      overflow: hidden;
    }

    .write-review-visual::after {
      content: "";
      position: absolute;
      right: -90px;
      bottom: -90px;
      width: 260px;
      height: 260px;
      border-radius: 50%;
      background: rgba(255, 255, 255, 0.12);
    }

    .write-review-visual-content {
      position: relative;
      z-index: 2;
    }

    .write-review-eyebrow {
      margin: 0 0 12px;
      color: #9bbcff;
      font-size: 0.82rem;
      font-weight: 900;
      letter-spacing: 0.16em;
      text-transform: uppercase;
    }

    .write-review-visual h1 {
      margin: 0;
      font-size: clamp(2.1rem, 4vw, 3.4rem);
      line-height: 1;
      letter-spacing: -1.5px;
      font-weight: 900;
     
    }

    .write-review-visual p {
      margin: 18px 0 0;
      color: #e8eefc;
      line-height: 1.7;
      font-weight: 700;
    }

    .write-review-form-panel {
      padding: 42px;
    }

    .review-form {
      width: 100%;
      margin: 0;
      padding: 0;
      border: none;
      border-radius: 0;
      background: transparent;
    }

    .review-form h2 {
      margin: 0 0 10px;
      color: #07172f;
      font-size: clamp(2rem, 4vw, 2.7rem);
      line-height: 1.05;
      letter-spacing: -1.2px;
      font-weight: 900;
      text-align: left;
    }

    .review-subtitle {
      margin: 0 0 28px;
      color: #64748b;
      line-height: 1.65;
      font-weight: 700;
    }

    .review-form label {
      display: block;
      margin: 18px 0 8px;
      color: #07172f;
      font-weight: 900;
      font-size: 0.95rem;
    }

    .review-form select,
    .review-form textarea,
    .review-form input[type="file"] {
      width: 100%;
      border: 1.5px solid #dbe3ef;
      border-radius: 16px;
      background: #f8fbff;
      color: #07172f;
      font-size: 1rem;
      box-sizing: border-box;
      outline: none;
      transition: 0.22s ease;
    }

    .review-form select {
      min-height: 54px;
      padding: 0 16px;
    }

    .review-form textarea {
      min-height: 140px;
      padding: 14px 16px;
      resize: vertical;
      line-height: 1.6;
    }

    .review-form input[type="file"] {
      padding: 13px;
    }

    .review-form select:focus,
    .review-form textarea:focus,
    .review-form input[type="file"]:focus {
      border-color: #0b3d91;
      background: #ffffff;
      box-shadow: 0 0 0 4px rgba(11, 61, 145, 0.12);
    }

    .review-form button {
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

    .review-form button:hover {
      transform: translateY(-3px);
      background: linear-gradient(135deg, #07172f, #092f70);
    }

    .preview-wrap {
      display: none;
      margin-top: 14px;
      padding: 14px;
      border-radius: 18px;
      background: #f8fbff;
      border: 1px solid #dbe3ef;
    }

    .preview-wrap p {
      margin: 0 0 10px;
      color: #07172f;
      font-weight: 900;
    }

    .preview-img {
      width: 100%;
      max-width: 260px;
      border-radius: 16px;
      border: 1px solid #dbe3ef;
      display: block;
    }

    .review-note {
      margin-top: 18px;
      padding: 14px;
      border-radius: 16px;
      background: #eaf1ff;
      color: #061f4a;
      font-size: 0.9rem;
      font-weight: 800;
      line-height: 1.55;
    }

    .custom-modal-backdrop {
      position: fixed;
      inset: 0;
      background: rgba(7, 23, 47, 0.68);
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 20px;
      z-index: 20000;
      backdrop-filter: blur(8px);
    }

    .custom-modal-box {
      width: min(92%, 430px);
      background: #ffffff;
      border: 1px solid #dbe3ef;
      border-radius: 26px;
      padding: 28px;
      text-align: center;
      box-shadow: 0 28px 70px rgba(7, 23, 47, 0.28);
    }

    .custom-modal-icon {
      width: 54px;
      height: 54px;
      display: grid;
      place-items: center;
      margin: 0 auto 16px;
      border-radius: 50%;
      background: #fff1f2;
      color: #be123c;
      font-size: 1.5rem;
      font-weight: 900;
    }

    .custom-modal-box h2 {
      margin: 0 0 12px;
      color: #07172f;
    }

    .custom-modal-box p {
      margin: 0 0 22px;
      color: #475569;
      line-height: 1.6;
      font-weight: 700;
    }

    .custom-modal-btn {
      min-height: 46px;
      border: none;
      border-radius: 999px;
      padding: 0 24px;
      background: linear-gradient(135deg, #061f4a, #0b3d91);
      color: #ffffff;
      font-weight: 900;
      cursor: pointer;
    }

    @media (max-width: 900px) {
      .write-review-shell {
        grid-template-columns: 1fr;
      }

      .write-review-visual {
        min-height: 320px;
        align-items: center;
      }
    }

    @media (max-width: 600px) {
      .write-review-page {
        padding: 115px 12px 38px;
      }

      .write-review-shell {
        border-radius: 24px;
      }

      .write-review-visual,
      .write-review-form-panel {
        padding: 26px 20px;
      }

      .write-review-visual {
        min-height: auto;
      }

      .review-form h2 {
        font-size: 1.8rem;
      }
    }
  </style>
</head>

<body>

<?php include 'navbar.php'; ?>

<main class="write-review-page">
  <section class="write-review-shell">

    <div class="write-review-visual">
      <div class="write-review-visual-content">
        <p class="write-review-eyebrow">Customer Feedback</p>
        <h1>Share your grooming experience.</h1>
        <p>
          Your review helps future customers choose the right service and gives us feedback to improve every visit.
        </p>
      </div>
    </div>

    <div class="write-review-form-panel">
      <form class="review-form" method="POST" enctype="multipart/form-data">
        <h2>Write a Review</h2>
        <p class="review-subtitle">
          Rate your overall experience and leave a short comment for other customers.
        </p>

        <label for="rating">Rating</label>
        <select name="rating" id="rating" required>
          <option value="">Select a rating</option>
          <option value="5" <?= ($_POST['rating'] ?? '') === '5' ? 'selected' : '' ?>>⭐⭐⭐⭐⭐ Excellent</option>
          <option value="4" <?= ($_POST['rating'] ?? '') === '4' ? 'selected' : '' ?>>⭐⭐⭐⭐ Very Good</option>
          <option value="3" <?= ($_POST['rating'] ?? '') === '3' ? 'selected' : '' ?>>⭐⭐⭐ Good</option>
          <option value="2" <?= ($_POST['rating'] ?? '') === '2' ? 'selected' : '' ?>>⭐⭐ Fair</option>
          <option value="1" <?= ($_POST['rating'] ?? '') === '1' ? 'selected' : '' ?>>⭐ Poor</option>
        </select>

        <label for="comment">Comment</label>
        <textarea 
          name="comment" 
          id="comment" 
          rows="5" 
          placeholder="Tell us about your experience..."
          required
        ><?= htmlspecialchars($_POST['comment'] ?? '') ?></textarea>

        <label for="photo">Upload Photo <span style="color:#64748b;font-weight:700;">(optional)</span></label>
        <input type="file" name="photo" id="photo" accept="image/*" onchange="previewPhoto(event)">

        <div class="preview-wrap" id="previewWrap">
          <p>Photo Preview</p>
          <img id="photo-preview" class="preview-img" alt="Preview">
        </div>

        <div class="review-note">
          Reviews are shown publicly on the website to help other customers decide before booking.
        </div>

        <button type="submit">Submit Review</button>
      </form>
    </div>

  </section>
</main>

<?php if ($error_message): ?>
<div class="custom-modal-backdrop" id="errorModal">
  <div class="custom-modal-box">
    <div class="custom-modal-icon">!</div>
    <h2>Review Error</h2>
    <p><?= htmlspecialchars($error_message) ?></p>
    <button type="button" class="custom-modal-btn" onclick="document.getElementById('errorModal').style.display='none'">
      Try Again
    </button>
  </div>
</div>
<?php endif; ?>

<script>
function previewPhoto(event) {
  const file = event.target.files[0];
  const preview = document.getElementById('photo-preview');
  const previewWrap = document.getElementById('previewWrap');

  if (file) {
    preview.src = URL.createObjectURL(file);
    previewWrap.style.display = 'block';
  } else {
    preview.src = '';
    previewWrap.style.display = 'none';
  }
}
</script>

<?php include 'footer.php'; ?>

</body>
</html>