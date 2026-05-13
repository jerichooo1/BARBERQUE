<?php
$conn = new mysqli('localhost', 'root', '', 'barbershop');
if ($conn->connect_error) die("Connection failed.");

$sql = "SELECT ROUND(AVG(rating),1) AS avg_rating, COUNT(*) AS count FROM reviews";
$result = $conn->query($sql);
$row = $result->fetch_assoc();
$avg = $row['avg_rating'] ?? 0;
$count = $row['count'] ?? 0;

echo "<h3 style='font-size:2rem;'>$avg</h3>";
for ($i = 0; $i < round($avg); $i++) echo "⭐";
echo "<p>$count reviews</p>";
echo "<a href='write_review.php' class='ar-button'>Write a Review</a>";


?>
