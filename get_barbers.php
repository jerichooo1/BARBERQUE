<?php
$conn = new mysqli('localhost', 'root', '', 'barbershop');

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Get each barber with average rating
$sql = "
    SELECT 
        b.id, 
        b.name, 
        b.title, 
        b.image_path,
        COALESCE(AVG(r.rating), 0) AS avg_rating,
        COUNT(r.id) AS total_reviews
    FROM barbers b
    LEFT JOIN barber_ratings r ON b.id = r.barber_id
    GROUP BY b.id
";

$result = $conn->query($sql);

while ($row = $result->fetch_assoc()) {
    $avg_rating = round($row['avg_rating'], 1);
    $total_reviews = $row['total_reviews'];

    echo '<div class="barber-card">';
    echo '  <img src="images/' . htmlspecialchars($row['image_path']) . '" alt="' . htmlspecialchars($row['name']) . '" class="barber-photo">';
    echo '  <div class="barber-info">';
    echo '    <h3>' . htmlspecialchars($row['name']) . '</h3>';
    echo '    <p>' . htmlspecialchars($row['title']) . '</p>';

    // ⭐ Rating display
    echo '    <div class="rating-stars">';
    for ($i = 1; $i <= 5; $i++) {
        $star_class = ($i <= floor($avg_rating)) ? 'filled' : 'empty';
        echo '<span class="star ' . $star_class . '">★</span>';
    }
    echo '      <span class="rating-text">(' . $avg_rating . ' / 5 from ' . $total_reviews . ')</span>';
    echo '    </div>';

    echo '  </div>';
    echo '</div>';
}


?>

<style>

.rating-stars {
  display: flex;
  justify-content: center;
  align-items: center;
  gap: 2px;
  margin-top: 0.3rem;
}

.star {
  font-size: 1.2rem;
  color: #ccc;
}

.star.filled {
  color: gold;
}

.rating-text {
  font-size: 0.9rem;
  color: #444;
  margin-left: 5px;
}
</style>