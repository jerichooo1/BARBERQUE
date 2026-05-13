<?php
$conn = new mysqli('localhost', 'root', '', 'barbershop');

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Get average rating and total count
$sql = "SELECT AVG(rating) AS avg_rating, COUNT(*) AS total_reviews FROM reviews";
$result = $conn->query($sql);

if ($row = $result->fetch_assoc()) {
    $avgRating = number_format($row['avg_rating'], 1);
    $totalReviews = $row['total_reviews'];
    echo "⭐ $avgRating • $totalReviews Review" . ($totalReviews != 1 ? "s" : "");
} else {
    echo "⭐ No reviews yet";
}


?>
