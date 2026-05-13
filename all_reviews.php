<?php
include 'navbar.php';

$conn = new mysqli('localhost', 'root', '', 'barbershop');

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$selectedRating = isset($_GET['rating']) ? (int)$_GET['rating'] : 0;

$sql = "SELECT reviews.*, users.first_name, users.middle_name, users.last_name 
        FROM reviews 
        JOIN users ON reviews.user_id = users.id";

if ($selectedRating >= 1 && $selectedRating <= 5) {
    $sql .= " WHERE reviews.rating = " . $selectedRating;
}

$sql .= " ORDER BY reviews.created_at DESC, reviews.id DESC";

$result = $conn->query($sql);
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>All Reviews | Fade Street</title>
  <link rel="stylesheet" href="styles.css">

  <style>
    * {
      box-sizing: border-box;
    }

    html,
    body {
      overflow-x: hidden;
    }

    body {
      margin: 0;
      padding-top: 130px;
      background:
        radial-gradient(circle at top left, rgba(11, 61, 145, 0.13), transparent 34%),
        linear-gradient(180deg, #ffffff 0%, #f7f9fc 48%, #ffffff 100%);
    }

    .reviews-page-wrapper {
      width: min(1100px, 100%);
      margin: 0 auto 60px;
      padding: 0 18px;
    }

    .reviews-container {
      max-width: 100%;
      background: #ffffff;
      border-radius: 32px;
      padding: 30px;
      border: 1px solid #dbe3ef;
      box-shadow: 0 20px 50px rgba(7, 23, 47, 0.08);
    }

    #reviews-title {
      text-align: center;
      color: #07172f;
      font-size: clamp(2rem, 4vw, 2.7rem);
      font-weight: 900;
      margin: 0 0 10px;
      letter-spacing: -1.2px;
      line-height: 1.1;
    }

    .reviews-subtitle {
      max-width: 640px;
      margin: 0 auto 24px;
      text-align: center;
      color: #64748b;
      line-height: 1.7;
      font-weight: 700;
    }

    .review-filter-bar {
      max-width: 100%;
      display: flex;
      flex-wrap: wrap;
      justify-content: center;
      gap: 10px;
      padding: 16px;
      background: #f8fbff;
      border: 1px solid #dbe8ff;
      border-radius: 22px;
      margin-bottom: 28px;
    }

    .filter-btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-height: 42px;
      padding: 0 18px;
      border-radius: 999px;
      font-weight: 900;
      font-size: 0.95rem;
      background: #eaf1ff;
      color: #061f4a;
      text-decoration: none;
      transition: 0.25s ease;
      white-space: nowrap;
    }

    .filter-btn:hover {
      transform: translateY(-2px);
    }

    .filter-btn.active {
      background: linear-gradient(135deg, #061f4a, #0b3d91);
      color: #ffffff;
      box-shadow: 0 10px 22px rgba(11, 61, 145, 0.20);
    }

    .review {
      max-width: 100%;
      padding: 20px;
      margin-bottom: 16px;
      background: #ffffff;
      border-radius: 22px;
      border: 1px solid #e3eaf5;
      box-shadow: 0 12px 30px rgba(7, 23, 47, 0.06);
      transition: 0.25s ease;
    }

    .review:hover {
      transform: translateY(-3px);
      box-shadow: 0 18px 38px rgba(7, 23, 47, 0.10);
    }

    .review:last-child {
      margin-bottom: 0;
    }

    .review-header {
      margin-bottom: 10px;
    }

    .review-user-info {
      display: flex;
      flex-direction: column;
      gap: 4px;
    }

    .review-name {
      margin: 0;
      color: #0b3d91;
      font-size: 1.12rem;
      font-weight: 900;
      line-height: 1.3;
      word-break: break-word;
    }

    .review-date {
      margin: 0;
      font-size: 0.88rem;
      color: #64748b;
      font-weight: 700;
    }

    .review-stars {
      margin: 8px 0 12px;
      font-size: 1.25rem;
      line-height: 1.4;
      color: #f59e0b;
      word-break: break-word;
    }

    .review-comment {
      margin: 0;
      color: #475569;
      line-height: 1.7;
      font-size: 1rem;
      font-weight: 650;
      word-break: break-word;
    }

    .review img.review-photo {
      margin-top: 14px;
      width: 100%;
      max-width: 190px;
      border-radius: 16px;
      border: 1px solid #dbe3ef;
      display: block;
    }

    .admin-reply {
      margin-top: 16px;
      padding: 16px;
      border-radius: 18px;
      background: #f0f6ff;
      border: 1px solid #cfe1ff;
      color: #475569;
      line-height: 1.6;
      font-weight: 650;
      word-break: break-word;
    }

    .admin-reply strong {
      color: #0b3d91;
      font-weight: 900;
    }

    .reply-date {
      margin-top: 8px;
      font-size: 0.8rem;
      color: #64748b;
      font-weight: 700;
    }

    .empty-reviews {
      text-align: center;
      padding: 34px 16px;
      color: #64748b;
      font-size: 1rem;
      font-weight: 700;
      line-height: 1.6;
    }

    .back-container {
      text-align: center;
      margin-top: 30px;
      margin-bottom: 50px;
    }

    .back-button {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-height: 48px;
      padding: 0 24px;
      border-radius: 999px;
      background: linear-gradient(135deg, #061f4a, #0b3d91);
      color: #ffffff;
      text-decoration: none;
      font-weight: 900;
      transition: 0.25s ease;
      box-shadow: 0 14px 30px rgba(11, 61, 145, 0.22);
    }

    .back-button:hover {
      transform: translateY(-2px);
    }

    @media (max-width: 768px) {
      body {
        padding-top: 115px;
      }

      .reviews-page-wrapper {
        padding: 0 12px;
        margin-bottom: 42px;
      }

      .reviews-container {
        padding: 20px;
        border-radius: 24px;
      }

      #reviews-title {
        font-size: 1.85rem;
        margin-bottom: 8px;
      }

      .reviews-subtitle {
        font-size: 0.95rem;
        margin-bottom: 20px;
      }

      .review-filter-bar {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 10px;
        padding: 12px;
      }

      .filter-btn {
        width: 100%;
        padding: 0 10px;
        font-size: 0.9rem;
        white-space: normal;
        text-align: center;
      }

      .review {
        padding: 16px;
        border-radius: 18px;
      }

      .review-name {
        font-size: 1rem;
      }

      .review-date {
        font-size: 0.82rem;
      }

      .review-stars {
        font-size: 1.1rem;
      }

      .review-comment {
        font-size: 0.93rem;
      }

      .review img.review-photo {
        max-width: 100%;
      }

      .back-button {
        width: 100%;
      }
    }

    @media (max-width: 420px) {
      body {
        padding-top: 105px;
      }

      .reviews-page-wrapper {
        padding: 0 10px;
      }

      .reviews-container {
        padding: 14px;
        border-radius: 20px;
      }

      #reviews-title {
        font-size: 1.55rem;
      }

      .review-filter-bar {
        grid-template-columns: 1fr;
      }

      .filter-btn {
        font-size: 0.88rem;
      }

      .review {
        padding: 14px;
      }
    }
  </style>
</head>

<body>

  <div class="reviews-page-wrapper">
    <div class="reviews-container">
      <h2 id="reviews-title">All Customer Reviews</h2>
      <p class="reviews-subtitle">
        See what our customers are saying before you book your next appointment.
      </p>

      <div class="review-filter-bar">
        <a href="all_reviews.php" class="filter-btn <?= $selectedRating === 0 ? 'active' : ''; ?>">All</a>
        <a href="all_reviews.php?rating=5" class="filter-btn <?= $selectedRating === 5 ? 'active' : ''; ?>">5 Stars</a>
        <a href="all_reviews.php?rating=4" class="filter-btn <?= $selectedRating === 4 ? 'active' : ''; ?>">4 Stars</a>
        <a href="all_reviews.php?rating=3" class="filter-btn <?= $selectedRating === 3 ? 'active' : ''; ?>">3 Stars</a>
        <a href="all_reviews.php?rating=2" class="filter-btn <?= $selectedRating === 2 ? 'active' : ''; ?>">2 Stars</a>
        <a href="all_reviews.php?rating=1" class="filter-btn <?= $selectedRating === 1 ? 'active' : ''; ?>">1 Star</a>
      </div>

      <?php
      if ($result && $result->num_rows > 0) {
          while ($row = $result->fetch_assoc()) {
              $fullName = htmlspecialchars(trim(
                  ($row['first_name'] ?? '') . ' ' .
                  ($row['middle_name'] ?? '') . ' ' .
                  ($row['last_name'] ?? '')
              ));

              $reviewDate = '';
              if (!empty($row['created_at'])) {
                  $reviewDate = date("F j, Y", strtotime($row['created_at']));
              }

              echo '<div class="review">';

              echo '<div class="review-header">';
              echo '<div class="review-user-info">';
              echo '<p class="review-name">' . $fullName . '</p>';
              if ($reviewDate !== '') {
                  echo '<p class="review-date">Posted on ' . htmlspecialchars($reviewDate) . '</p>';
              }
              echo '</div>';
              echo '</div>';

              echo '<p class="review-stars">';
              for ($i = 1; $i <= 5; $i++) {
                  echo $i <= (int)$row['rating'] ? '⭐' : '☆';
              }
              echo '</p>';

              echo '<p class="review-comment">' . htmlspecialchars($row['comment']) . '</p>';

              if (!empty($row['photo'])) {
                  echo '<img src="' . htmlspecialchars($row['photo']) . '" alt="Review photo" class="review-photo">';
              }

              if (!empty($row['owner_reply'])) {
                  echo '<div class="admin-reply">';
                  echo '<strong>Owner Reply</strong><br>';
                  echo nl2br(htmlspecialchars($row['owner_reply']));
                  if (!empty($row['owner_reply_at'])) {
                      echo '<div class="reply-date">' . date("F j, Y g:i A", strtotime($row['owner_reply_at'])) . '</div>';
                  }
                  echo '</div>';
              }

              echo '</div>';
          }
      } else {
          echo '<div class="empty-reviews">No reviews found for this rating.</div>';
      }

      $conn->close();
      ?>
    </div>

    <div class="back-container">
      <a href="index.php" class="back-button">Back to Home</a>
    </div>
  </div>

<?php include 'footer.php'; ?>

</body>
</html>