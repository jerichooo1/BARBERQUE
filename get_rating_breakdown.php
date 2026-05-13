<?php
$conn = new mysqli('localhost', 'root', '', 'barbershop');

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$star_counts = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];
$sql = "SELECT rating, COUNT(*) as count FROM reviews GROUP BY rating";
$result = $conn->query($sql);

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $rating = (int)$row['rating'];
        $count = (int)$row['count'];

        if (isset($star_counts[$rating])) {
            $star_counts[$rating] = $count;
        }
    }
}

foreach ($star_counts as $stars => $count) {
    echo '<div class="star-breakdown">';
    echo '<span class="stars">' . str_repeat('⭐', $stars) . '</span>';
    echo '<span class="count">' . $count . '</span>';
    echo '</div>';
}
?>