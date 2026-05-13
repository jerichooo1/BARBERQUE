<?php
session_start();
include 'db.php';

if (!isset($_SESSION['barber_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'barber') {
    header("Location: admin_login.php");
    exit();
}

$barber_id = (int)$_SESSION['barber_id'];

/* SHOP SETTINGS */
$settings = $conn->query("SELECT * FROM settings LIMIT 1");
$shop = $settings ? $settings->fetch_assoc() : [];

/* BARBER PROFILE */
$barber = null;
$stmt = $conn->prepare("
    SELECT id, name, email, image_path, title, status
    FROM barbers
    WHERE id = ?
    LIMIT 1
");
$stmt->bind_param("i", $barber_id);
$stmt->execute();
$barber = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$barber) {
    session_destroy();
    header("Location: admin_login.php");
    exit();
}

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function renderStars($rating) {
    $rating = max(0, min(5, (float)$rating));
    $full = (int)floor($rating);
    $half = (($rating - $full) >= 0.5) ? 1 : 0;
    $empty = 5 - $full - $half;

    return str_repeat('★', $full) . ($half ? '☆' : '') . str_repeat('✩', $empty);
}

function ratingPercent($count, $total) {
    if ($total <= 0) return 0;
    return round(($count / $total) * 100);
}

/* SUMMARY */
$summary = [
    'avg_rating' => 0,
    'total_reviews' => 0,
    'five_star' => 0,
    'four_star' => 0,
    'three_star' => 0,
    'two_star' => 0,
    'one_star' => 0
];

$stmt = $conn->prepare("
    SELECT
        COALESCE(ROUND(AVG(rating), 1), 0) AS avg_rating,
        COUNT(*) AS total_reviews,
        SUM(CASE WHEN rating = 5 THEN 1 ELSE 0 END) AS five_star,
        SUM(CASE WHEN rating = 4 THEN 1 ELSE 0 END) AS four_star,
        SUM(CASE WHEN rating = 3 THEN 1 ELSE 0 END) AS three_star,
        SUM(CASE WHEN rating = 2 THEN 1 ELSE 0 END) AS two_star,
        SUM(CASE WHEN rating = 1 THEN 1 ELSE 0 END) AS one_star
    FROM barber_ratings
    WHERE barber_id = ?
");
$stmt->bind_param("i", $barber_id);
$stmt->execute();
$summaryRow = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($summaryRow) {
    $summary['avg_rating'] = (float)($summaryRow['avg_rating'] ?? 0);
    $summary['total_reviews'] = (int)($summaryRow['total_reviews'] ?? 0);
    $summary['five_star'] = (int)($summaryRow['five_star'] ?? 0);
    $summary['four_star'] = (int)($summaryRow['four_star'] ?? 0);
    $summary['three_star'] = (int)($summaryRow['three_star'] ?? 0);
    $summary['two_star'] = (int)($summaryRow['two_star'] ?? 0);
    $summary['one_star'] = (int)($summaryRow['one_star'] ?? 0);
}

/* FILTER */
$rating_filter = intval($_GET['rating'] ?? 0);
$valid_filter = in_array($rating_filter, [0,1,2,3,4,5], true) ? $rating_filter : 0;

/* REVIEWS LIST */
$reviews = [];

$sql = "
    SELECT
        br.id,
        br.rating,
        br.comment,
        br.created_at,
        br.appointment_id,
        CONCAT(u.first_name, ' ', u.last_name) AS customer_name,
        a.start_time,
        a.end_time
    FROM barber_ratings br
    JOIN users u ON br.customer_id = u.id
    LEFT JOIN appointments a ON br.appointment_id = a.id
    WHERE br.barber_id = ?
";

if ($valid_filter > 0) {
    $sql .= " AND br.rating = ? ";
}

$sql .= " ORDER BY br.created_at DESC ";

if ($valid_filter > 0) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $barber_id, $valid_filter);
} else {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $barber_id);
}

$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $reviews[] = $row;
}
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reviews & Ratings | <?= h($shop['shop_name'] ?? 'Barbershop') ?></title>
    <link rel="stylesheet" href="styles.css">
    <style>
        * { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: 'Poppins', sans-serif;
            background: #f4f4f4;
            color: #1f2937;
            overflow-x: hidden;
        }

        html { overflow-x: hidden; }
        body.sidebar-open { overflow: hidden; }

        .mobile-topbar {
            display: none;
            width: 100%;
            background: #111;
            color: #fff;
            padding: 14px 16px;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 1200;
        }

        .mobile-topbar strong {
            font-size: 15px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: calc(100vw - 90px);
        }

        .menu-btn {
            width: 42px;
            height: 42px;
            border: none;
            border-radius: 10px;
            background: rgba(255,255,255,0.1);
            color: #fff;
            font-size: 20px;
            cursor: pointer;
            flex-shrink: 0;
        }

        .sidebar-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.55);
            z-index: 1190;
        }

        .sidebar-overlay.show {
            display: block;
        }

        .barber-layout {
            display: flex;
            min-height: 100vh;
        }

        .barber-sidebar {
            width: 260px;
            min-width: 260px;
            background: #111;
            color: #fff;
            padding: 24px 18px;
            position: sticky;
            top: 0;
            height: 100vh;
            overflow-y: auto;
            flex-shrink: 0;
            z-index: 1201;
            transition: transform 0.25s ease;
        }

        .barber-brand {
            text-align: center;
            margin-bottom: 30px;
        }

        .barber-brand img {
            width: 80px;
            height: 80px;
            object-fit: cover;
            border-radius: 50%;
            background: #fff;
            padding: 6px;
            margin-bottom: 10px;
        }

        .barber-brand h2 {
            font-size: 18px;
            margin: 0;
            line-height: 1.4;
        }

        .barber-brand p {
            margin: 6px 0 0;
            color: #bbb;
            font-size: 13px;
        }

        .barber-profile-box {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px;
            margin-bottom: 24px;
            background: transparent;
            border-radius: 0;
        }

        .barber-profile-box img {
            width: 54px;
            height: 54px;
            border-radius: 50%;
            object-fit: cover;
            background: #fff;
            flex-shrink: 0;
        }

        .barber-profile-box strong {
            display: block;
            font-size: 15px;
            line-height: 1.3;
        }

        .barber-profile-box span {
            display: block;
            font-size: 12px;
            color: #d1d5db;
            margin-top: 2px;
        }

        .barber-nav a {
            display: block;
            color: #fff;
            text-decoration: none;
            padding: 12px 14px;
            border-radius: 10px;
            margin-bottom: 8px;
            transition: 0.2s ease;
            font-weight: 500;
        }

        .barber-nav a:hover,
        .barber-nav a.active {
            background: #2563eb;
        }

        .barber-main {
            flex: 1;
            min-width: 0;
            padding: 28px;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 16px;
            margin-bottom: 18px;
            flex-wrap: wrap;
        }

        .page-header h1 {
            margin: 0;
            font-size: 30px;
            color: #111827;
        }

        .page-header p {
            margin: 6px 0 0;
            color: #6b7280;
            max-width: 760px;
        }

        .summary-grid {
            display: grid;
            grid-template-columns: 320px minmax(0, 1fr);
            gap: 18px;
            margin-bottom: 18px;
        }

        .panel {
            background: #fff;
            border-radius: 22px;
            padding: 20px;
            box-shadow: 0 10px 30px rgba(15, 23, 42, 0.06);
            border: 1px solid #edf2f7;
            min-width: 0;
        }

        .rating-overview {
            text-align: center;
        }

        .rating-overview .big {
            font-size: 52px;
            font-weight: 700;
            line-height: 1;
            color: #111827;
        }

        .stars-big {
            color: #2563eb;
            font-size: 22px;
            letter-spacing: 1px;
            margin: 10px 0 8px;
        }

        .muted {
            color: #6b7280;
            font-size: 14px;
        }

        .breakdown-list {
            display: grid;
            gap: 12px;
        }

        .breakdown-row {
            display: grid;
            grid-template-columns: 70px 1fr 50px;
            gap: 10px;
            align-items: center;
        }

        .bar-track {
            width: 100%;
            height: 12px;
            background: #eef2f7;
            border-radius: 999px;
            overflow: hidden;
        }

        .bar-fill {
            height: 100%;
            background: #2563eb;
            border-radius: 999px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(180px, 1fr));
            gap: 14px;
            margin-bottom: 18px;
        }

        .stat-card {
            background: #fff;
            border-radius: 18px;
            padding: 18px;
            box-shadow: 0 8px 24px rgba(15, 23, 42, 0.06);
            border: 1px solid #eef2f7;
        }

        .stat-card h3 {
            margin: 0 0 8px;
            font-size: 13px;
            color: #6b7280;
            font-weight: 600;
        }

        .stat-card .value {
            font-size: 30px;
            font-weight: 700;
            color: #111827;
        }

        .filter-card {
            background: #fff;
            border-radius: 18px;
            padding: 16px;
            box-shadow: 0 8px 24px rgba(15, 23, 42, 0.06);
            border: 1px solid #eef2f7;
            margin-bottom: 18px;
        }

        .filter-row {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .filter-chip {
            text-decoration: none;
            padding: 10px 14px;
            border-radius: 999px;
            background: #eef2f7;
            color: #111827;
            font-weight: 600;
            font-size: 14px;
            transition: 0.18s ease;
        }

        .filter-chip:hover {
            transform: translateY(-1px);
        }

        .filter-chip.active {
            background: #2563eb;
            color: #fff;
        }

        .reviews-list {
            display: grid;
            gap: 14px;
        }

        .review-card {
            background: #fff;
            border: 1px solid #e6edf5;
            border-radius: 18px;
            padding: 18px;
            box-shadow: 0 8px 24px rgba(15, 23, 42, 0.04);
        }

        .review-top {
            display: flex;
            justify-content: space-between;
            gap: 14px;
            align-items: flex-start;
            flex-wrap: wrap;
            margin-bottom: 8px;
        }

        .review-top h3 {
            margin: 0;
            font-size: 17px;
            color: #111827;
        }

        .review-date {
            color: #6b7280;
            font-size: 13px;
        }

        .review-stars {
            color: #2563eb;
            font-size: 18px;
            letter-spacing: 1px;
            margin: 8px 0 10px;
        }

        .review-card p {
            margin: 0;
            color: #334155;
            line-height: 1.7;
            font-size: 14px;
        }

        .review-meta {
            margin-top: 12px;
            color: #6b7280;
            font-size: 13px;
            line-height: 1.6;
        }

        .empty-state {
            background: #fafbfc;
            border: 1px dashed #d5dde7;
            padding: 34px 18px;
            border-radius: 18px;
            text-align: center;
            color: #6b7280;
            font-size: 14px;
            line-height: 1.6;
        }

        @media (max-width: 1200px) {
            .summary-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 900px) {
            .mobile-topbar {
                display: flex;
            }

            .barber-layout {
                display: block;
            }

            .barber-sidebar {
                position: fixed;
                top: 0;
                left: 0;
                height: 100vh;
                width: min(82vw, 300px);
                min-width: min(82vw, 300px);
                max-width: 300px;
                transform: translateX(-105%);
                box-shadow: 0 24px 50px rgba(0,0,0,0.28);
            }

            .barber-sidebar.show {
                transform: translateX(0);
            }

            .barber-main {
                padding: 18px;
            }
        }

        @media (max-width: 640px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }

            .breakdown-row {
                grid-template-columns: 1fr;
            }

            .page-header h1 {
                font-size: 26px;
            }

            .panel,
            .stat-card,
            .filter-card,
            .review-card {
                padding: 16px;
            }

            .review-top {
                flex-direction: column;
                align-items: flex-start;
            }

            .barber-main {
                padding: 14px;
            }
        }
    </style>
</head>
<body>

<div class="mobile-topbar">
    <button type="button" class="menu-btn" id="menuBtn">☰</button>
    <strong><?= h($shop['shop_name'] ?? 'Barbershop') ?></strong>
</div>

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<div class="barber-layout">
    <aside class="barber-sidebar" id="barberSidebar">
        <div class="barber-brand">
            <img src="images/<?= h($shop['logo'] ?? 'logo.png') ?>" alt="Shop Logo">
            <h2><?= h($shop['shop_name'] ?? 'Barbershop') ?></h2>
            <p>Barber Portal</p>
        </div>

        <div class="barber-profile-box">
            <img src="images/<?= h($barber['image_path'] ?? 'default.png') ?>" alt="<?= h($barber['name']) ?>">
            <div>
                <strong><?= h($barber['name']) ?></strong>
                <span><?= h($barber['title'] ?? 'Barber') ?></span>
                <span><?= ucfirst(h($barber['status'] ?? 'active')) ?></span>
            </div>
        </div>

        <nav class="barber-nav">
            <a href="barber_dashboard.php">Dashboard</a>
            <a href="barber_leave_request.php">Leave Requests</a>
            <a href="barber_reviews.php" class="active">Reviews & Ratings</a>
            <a href="admin_logout.php">Logout</a>
        </nav>
    </aside>

    <main class="barber-main">
        <div class="page-header">
            <div>
                <h1>Reviews & Ratings</h1>
                <p>View your customer feedback, rating trends, and detailed review history.</p>
            </div>
        </div>

        <section class="summary-grid">
            <div class="panel rating-overview">
                <div class="big"><?= number_format($summary['avg_rating'], 1) ?></div>
                <div class="stars-big"><?= h(renderStars($summary['avg_rating'])) ?></div>
                <div class="muted"><?= (int)$summary['total_reviews'] ?> total review(s)</div>
            </div>

            <div class="panel">
                <div class="breakdown-list">
                    <?php
                    $rows = [
                        5 => $summary['five_star'],
                        4 => $summary['four_star'],
                        3 => $summary['three_star'],
                        2 => $summary['two_star'],
                        1 => $summary['one_star']
                    ];
                    foreach ($rows as $star => $count):
                        $percent = ratingPercent($count, $summary['total_reviews']);
                    ?>
                        <div class="breakdown-row">
                            <div><strong><?= $star ?> Star</strong></div>
                            <div class="bar-track">
                                <div class="bar-fill" style="width: <?= $percent ?>%;"></div>
                            </div>
                            <div class="muted"><?= $count ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <section class="stats-grid">
            <div class="stat-card">
                <h3>5-Star Reviews</h3>
                <div class="value"><?= (int)$summary['five_star'] ?></div>
            </div>
            <div class="stat-card">
                <h3>4-Star Reviews</h3>
                <div class="value"><?= (int)$summary['four_star'] ?></div>
            </div>
            <div class="stat-card">
                <h3>1-3 Star Reviews</h3>
                <div class="value"><?= (int)($summary['three_star'] + $summary['two_star'] + $summary['one_star']) ?></div>
            </div>
        </section>

        <section class="filter-card">
            <div class="filter-row">
                <a href="barber_reviews.php" class="filter-chip <?= $valid_filter === 0 ? 'active' : '' ?>">All</a>
                <a href="barber_reviews.php?rating=5" class="filter-chip <?= $valid_filter === 5 ? 'active' : '' ?>">5 Stars</a>
                <a href="barber_reviews.php?rating=4" class="filter-chip <?= $valid_filter === 4 ? 'active' : '' ?>">4 Stars</a>
                <a href="barber_reviews.php?rating=3" class="filter-chip <?= $valid_filter === 3 ? 'active' : '' ?>">3 Stars</a>
                <a href="barber_reviews.php?rating=2" class="filter-chip <?= $valid_filter === 2 ? 'active' : '' ?>">2 Stars</a>
                <a href="barber_reviews.php?rating=1" class="filter-chip <?= $valid_filter === 1 ? 'active' : '' ?>">1 Star</a>
            </div>
        </section>

        <?php if (!empty($reviews)): ?>
            <section class="reviews-list">
                <?php foreach ($reviews as $review): ?>
                    <article class="review-card">
                        <div class="review-top">
                            <div>
                                <h3><?= h($review['customer_name']) ?></h3>
                                <div class="review-stars">
                                    <?= h(renderStars((float)$review['rating'])) ?>
                                    <span style="font-size:14px;color:#334155;">(<?= (int)$review['rating'] ?>/5)</span>
                                </div>
                            </div>
                            <div class="review-date">
                                <?= h(date("F j, Y g:i A", strtotime($review['created_at']))) ?>
                            </div>
                        </div>

                        <p><?= !empty($review['comment']) ? h($review['comment']) : 'No written comment provided.' ?></p>

                        <div class="review-meta">
                            <?php if (!empty($review['appointment_id'])): ?>
                                Appointment #<?= (int)$review['appointment_id'] ?><br>
                            <?php endif; ?>

                            <?php if (!empty($review['start_time'])): ?>
                                Appointment Date:
                                <?= h(date("F j, Y g:i A", strtotime($review['start_time']))) ?>
                                <?php if (!empty($review['end_time'])): ?>
                                    - <?= h(date("g:i A", strtotime($review['end_time']))) ?>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </section>
        <?php else: ?>
            <div class="empty-state">
                No reviews found for this filter.
            </div>
        <?php endif; ?>
    </main>
</div>

<script>
function openSidebar() {
    document.getElementById('barberSidebar').classList.add('show');
    document.getElementById('sidebarOverlay').classList.add('show');
    document.body.classList.add('sidebar-open');
}

function closeSidebar() {
    document.getElementById('barberSidebar').classList.remove('show');
    document.getElementById('sidebarOverlay').classList.remove('show');
    document.body.classList.remove('sidebar-open');
}

const menuBtn = document.getElementById('menuBtn');
const sidebarOverlay = document.getElementById('sidebarOverlay');

if (menuBtn) {
    menuBtn.addEventListener('click', openSidebar);
}

if (sidebarOverlay) {
    sidebarOverlay.addEventListener('click', closeSidebar);
}

window.addEventListener('resize', function () {
    if (window.innerWidth > 900) {
        closeSidebar();
    }
});
</script>

</body>
</html>