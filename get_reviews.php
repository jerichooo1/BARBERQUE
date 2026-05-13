<?php
$reviewSql = "
    SELECT 
        r.*,
        u.first_name,
        u.middle_name,
        u.last_name
    FROM reviews r
    JOIN users u ON r.user_id = u.id
    ORDER BY r.created_at DESC, r.id DESC
    LIMIT 3
";

$reviewResult = $conn->query($reviewSql);

if ($reviewResult && $reviewResult->num_rows > 0) {
    while ($row = $reviewResult->fetch_assoc()) {
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
        echo '<p class="review-name"><strong>' . $fullName . '</strong></p>';
        if ($reviewDate !== '') {
            echo '<p class="review-date">' . htmlspecialchars($reviewDate) . '</p>';
        }
        echo '</div>';
        echo '</div>';

        echo '<p class="review-stars">';
        for ($i = 0; $i < (int)$row['rating']; $i++) echo '⭐';
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
                echo '<div style="margin-top:6px;font-size:12px;color:#666;">' . date("F j, Y", strtotime($row['owner_reply_at'])) . '</div>';
            }
            echo '</div>';
        }

        echo '</div>';
    }
} else {
    echo "<p>No reviews yet.</p>";
}
?>