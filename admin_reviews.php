<?php
session_start();
include 'db.php';

if (!isset($_SESSION['admin_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: admin_staff_login.php");
    exit();
}

/* SHOP SETTINGS */
$settings = $conn->query("SELECT * FROM settings LIMIT 1");
$shop = $settings ? $settings->fetch_assoc() : null;

$message = '';
$message_type = '';

/* HANDLE ACTIONS */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_reply') {
        $review_id = intval($_POST['review_id'] ?? 0);
        $owner_reply = trim($_POST['owner_reply'] ?? '');

        if ($review_id <= 0) {
            $message = 'Invalid review selected.';
            $message_type = 'error';
        } else {
            $stmt = $conn->prepare("
                UPDATE reviews
                SET owner_reply = ?, owner_reply_at = NOW()
                WHERE id = ?
            ");
            $stmt->bind_param("si", $owner_reply, $review_id);

            if ($stmt->execute()) {
                $message = 'Owner reply saved successfully.';
                $message_type = 'success';
            } else {
                $message = 'Failed to save owner reply.';
                $message_type = 'error';
            }
            $stmt->close();
        }
    }

    if ($action === 'delete_review') {
        $review_id = intval($_POST['review_id'] ?? 0);

        if ($review_id > 0) {
            $stmt = $conn->prepare("SELECT photo FROM reviews WHERE id = ? LIMIT 1");
            $stmt->bind_param("i", $review_id);
            $stmt->execute();
            $reviewRow = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            $stmt = $conn->prepare("DELETE FROM reviews WHERE id = ?");
            $stmt->bind_param("i", $review_id);

            if ($stmt->execute()) {
                if (!empty($reviewRow['photo']) && file_exists(__DIR__ . '/' . $reviewRow['photo'])) {
                    @unlink(__DIR__ . '/' . $reviewRow['photo']);
                }

                $message = 'Review deleted successfully.';
                $message_type = 'success';
            } else {
                $message = 'Failed to delete review.';
                $message_type = 'error';
            }
            $stmt->close();
        }
    }

    header("Location: admin_reviews.php?msg=" . urlencode($message) . "&type=" . urlencode($message_type));
    exit();
}

/* FLASH */
if (isset($_GET['msg'])) {
    $message = trim($_GET['msg']);
    $message_type = trim($_GET['type'] ?? 'success');
}

/* FILTERS */
$search_filter = trim($_GET['search'] ?? '');
$rating_filter = trim($_GET['rating'] ?? '');
$photo_filter = trim($_GET['photo_filter'] ?? '');
$reply_filter = trim($_GET['reply_filter'] ?? '');

$whereParts = [];
$params = [];
$types = '';

if ($search_filter !== '') {
    $whereParts[] = "(
        CONCAT(
            COALESCE(u.first_name, ''),
            ' ',
            COALESCE(u.middle_name, ''),
            ' ',
            COALESCE(u.last_name, '')
        ) LIKE ?
        OR r.comment LIKE ?
        OR r.owner_reply LIKE ?
    )";
    $like = '%' . $search_filter . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= 'sss';
}

if ($rating_filter !== '' && ctype_digit($rating_filter)) {
    $whereParts[] = "r.rating = ?";
    $params[] = intval($rating_filter);
    $types .= 'i';
}

if ($photo_filter === 'with_photo') {
    $whereParts[] = "r.photo IS NOT NULL AND r.photo != ''";
}

if ($photo_filter === 'no_photo') {
    $whereParts[] = "(r.photo IS NULL OR r.photo = '')";
}

if ($reply_filter === 'replied') {
    $whereParts[] = "r.owner_reply IS NOT NULL AND TRIM(r.owner_reply) != ''";
}

if ($reply_filter === 'not_replied') {
    $whereParts[] = "(r.owner_reply IS NULL OR TRIM(r.owner_reply) = '')";
}

$whereSql = !empty($whereParts) ? 'WHERE ' . implode(' AND ', $whereParts) : '';

/* SUMMARY */
$summary = [
    'total_reviews' => 0,
    'avg_rating' => 0,
    'with_photos' => 0,
    'replied' => 0
];

$res = $conn->query("SELECT COUNT(*) AS total_reviews, COALESCE(ROUND(AVG(rating),1),0) AS avg_rating FROM reviews");
if ($res) {
    $row = $res->fetch_assoc();
    $summary['total_reviews'] = (int)($row['total_reviews'] ?? 0);
    $summary['avg_rating'] = (float)($row['avg_rating'] ?? 0);
}

$res = $conn->query("SELECT COUNT(*) AS total FROM reviews WHERE photo IS NOT NULL AND photo != ''");
if ($res) {
    $summary['with_photos'] = (int)($res->fetch_assoc()['total'] ?? 0);
}

$res = $conn->query("SELECT COUNT(*) AS total FROM reviews WHERE owner_reply IS NOT NULL AND TRIM(owner_reply) != ''");
if ($res) {
    $summary['replied'] = (int)($res->fetch_assoc()['total'] ?? 0);
}

/* GET REVIEWS */
$sql = "
    SELECT
        r.*,
        u.first_name,
        u.middle_name,
        u.last_name,
        u.email
    FROM reviews r
    JOIN users u ON r.user_id = u.id
    $whereSql
    ORDER BY r.created_at DESC, r.id DESC
";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$reviews = $stmt->get_result();

function fullName($row) {
    return trim(
        ($row['first_name'] ?? '') . ' ' .
        ($row['middle_name'] ?? '') . ' ' .
        ($row['last_name'] ?? '')
    );
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Reviews | <?= htmlspecialchars($shop['shop_name'] ?? 'Barbershop') ?></title>
    <link rel="stylesheet" href="styles.css">
    <style>
        * { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: 'Poppins', sans-serif;
            background: #f4f4f4;
            color: #222;
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

        .admin-layout {
            display: flex;
            min-height: 100vh;
        }

        .admin-sidebar {
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

        .admin-brand {
            text-align: center;
            margin-bottom: 30px;
        }

        .admin-brand img {
            width: 80px;
            height: 80px;
            object-fit: cover;
            border-radius: 50%;
            background: #fff;
            padding: 6px;
            margin-bottom: 10px;
        }

        .admin-brand h2 {
            font-size: 18px;
            margin: 0;
            line-height: 1.4;
        }

        .admin-brand p {
            margin: 6px 0 0;
            color: #bbb;
            font-size: 13px;
        }

        .admin-nav a {
            display: block;
            color: #fff;
            text-decoration: none;
            padding: 12px 14px;
            border-radius: 10px;
            margin-bottom: 8px;
            transition: 0.2s ease;
        }

        .admin-nav a:hover,
        .admin-nav a.active {
            background: #2563eb;
        }

        .admin-main {
            flex: 1;
            padding: 28px;
            min-width: 0;
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
            margin: 0 0 6px;
            font-size: 30px;
        }

        .page-header p {
            margin: 0;
            color: #6b7280;
        }

        .alert {
            padding: 14px 16px;
            border-radius: 14px;
            margin-bottom: 18px;
            font-weight: 500;
        }

        .alert-success {
            background: #dff6dd;
            color: #256029;
        }

        .alert-error {
            background: #fde2e2;
            color: #991b1b;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(160px, 1fr));
            gap: 14px;
            margin-bottom: 18px;
        }

        .stat-card {
            background: #fff;
            border-radius: 18px;
            padding: 18px;
            box-shadow: 0 8px 24px rgba(15, 23, 42, 0.06);
            border: 1px solid #edf2f7;
        }

        .stat-card h3 {
            margin: 0 0 8px;
            font-size: 13px;
            color: #6b7280;
            font-weight: 600;
        }

        .stat-card .value {
            font-size: 28px;
            font-weight: 700;
            color: #111827;
        }

        .panel {
            background: #fff;
            border-radius: 22px;
            padding: 20px;
            box-shadow: 0 10px 30px rgba(15, 23, 42, 0.06);
            border: 1px solid #edf2f7;
            margin-bottom: 18px;
        }

        .filter-grid {
            display: grid;
            grid-template-columns: 1.5fr 0.8fr 0.9fr 0.9fr auto auto;
            gap: 12px;
            align-items: end;
        }

        .form-group label {
            display: block;
            margin-bottom: 7px;
            font-weight: 600;
            color: #374151;
            font-size: 13px;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 11px 13px;
            border: 1px solid #d1d9e6;
            border-radius: 12px;
            font-size: 14px;
            outline: none;
            background: #fff;
        }

        .form-group textarea {
            min-height: 140px;
            resize: vertical;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.12);
        }

        .btn {
            border: none;
            border-radius: 12px;
            padding: 11px 16px;
            font-weight: 600;
            cursor: pointer;
            transition: 0.18s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
        }

        .btn:hover {
            transform: translateY(-1px);
        }

        .btn-primary { background: #2563eb; color: #fff; }
        .btn-secondary { background: #eef2f7; color: #111827; }
        .btn-delete { background: #c62828; color: #fff; }
        .btn-view { background: #111827; color: #fff; }
        .btn-save { background: #2563eb; color: #fff; }

        .toolbar-actions,
        .action-row,
        .modal-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .table-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            margin-bottom: 14px;
            flex-wrap: wrap;
        }

        .table-head h2 {
            margin: 0;
            font-size: 22px;
        }

        .table-head p {
            margin: 4px 0 0;
            color: #6b7280;
            font-size: 14px;
        }

        .table-responsive {
            width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        table {
            width: 100%;
            min-width: 1100px;
            border-collapse: separate;
            border-spacing: 0;
        }

        thead th {
            text-align: left;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: #64748b;
            font-weight: 700;
            padding: 14px;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
        }

        tbody td {
            padding: 16px 14px;
            border-bottom: 1px solid #edf2f7;
            vertical-align: top;
            background: #fff;
        }

        tbody tr:hover td {
            background: #fcfcfd;
        }

        .reviewer-meta strong {
            display: block;
            color: #111827;
            font-size: 15px;
        }

        .reviewer-meta span {
            color: #6b7280;
            font-size: 13px;
        }

        .stars {
            color: #f2b01e;
            font-size: 16px;
            letter-spacing: 1px;
            margin-bottom: 4px;
        }

        .reply-tag,
        .photo-tag {
            display: inline-flex;
            padding: 6px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
        }

        .reply-yes {
            background: #dff6dd;
            color: #256029;
        }

        .reply-no {
            background: #fde2e2;
            color: #991b1b;
        }

        .photo-yes {
            background: #e0f2fe;
            color: #075985;
        }

        .photo-no {
            background: #eee;
            color: #555;
        }

        .comment-preview,
        .reply-preview {
            max-width: 260px;
            white-space: normal;
            word-break: break-word;
            line-height: 1.5;
            color: #555;
        }

        .empty-state {
            background: #fff;
            padding: 36px 22px;
            border-radius: 18px;
            text-align: center;
            color: #6b7280;
            border: 1px solid #edf2f7;
        }

        .hidden-detail {
            display: none;
        }

        .modal-backdrop {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.6);
            z-index: 1300;
            padding: 20px;
            overflow-y: auto;
        }

        .modal-backdrop.show {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .modal {
            width: 100%;
            max-width: 900px;
            background: #fff;
            border-radius: 24px;
            box-shadow: 0 30px 80px rgba(15, 23, 42, 0.2);
            max-height: 92vh;
            overflow-y: auto;
            position: relative;
        }

        .modal-sm {
            max-width: 560px;
        }

        .modal-header {
            padding: 24px 26px 16px;
            border-bottom: 1px solid #edf2f7;
            position: sticky;
            top: 0;
            background: #fff;
            z-index: 2;
        }

        .modal-header h3 {
            margin: 0;
            font-size: 24px;
            color: #111827;
        }

        .modal-header p {
            margin: 6px 0 0;
            color: #6b7280;
        }

        .modal-close {
            position: absolute;
            top: 16px;
            right: 16px;
            width: 40px;
            height: 40px;
            border: none;
            border-radius: 999px;
            background: #f3f4f6;
            font-size: 18px;
            font-weight: 700;
            cursor: pointer;
        }

        .modal-body {
            padding: 24px 26px 26px;
        }

        .details-grid {
            display: grid;
            gap: 18px;
        }

        .details-card {
            background: #f8fafc;
            border: 1px solid #e8eef5;
            border-radius: 18px;
            padding: 18px;
        }

        .details-card h4 {
            margin: 0 0 12px;
            font-size: 18px;
            color: #111827;
        }

        .details-card p {
            margin: 8px 0;
            line-height: 1.6;
        }

        .review-photo {
            width: 100%;
            max-width: 260px;
            border-radius: 16px;
            border: 1px solid #dbe3ee;
            display: block;
            margin-top: 12px;
            background: #fff;
        }

        .owner-reply-box {
            background: #eff6ff;
            border-left: 4px solid #2563eb;
            padding: 14px;
            border-radius: 12px;
            margin-top: 10px;
        }

        .warning-text {
            color: #991b1b;
            background: #fee2e2;
            border: 1px solid #fecaca;
            border-radius: 12px;
            padding: 12px 14px;
            font-size: 14px;
            margin-top: 10px;
        }

        @media (max-width: 1100px) {
            .filter-grid {
                grid-template-columns: 1fr 1fr;
            }
        }

        @media (max-width: 900px) {
            .mobile-topbar {
                display: flex;
            }

            .admin-layout {
                display: block;
            }

            .admin-sidebar {
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

            .admin-sidebar.show {
                transform: translateX(0);
            }

            .admin-main {
                padding: 18px;
            }

            .stats-grid,
            .filter-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 640px) {
            .panel,
            .modal-header,
            .modal-body {
                padding-left: 16px;
                padding-right: 16px;
            }

            .btn {
                width: 100%;
            }

            .action-row,
            .modal-actions,
            .toolbar-actions {
                flex-direction: column;
            }

            .modal-backdrop {
                padding: 10px;
            }

            .admin-main {
                padding: 14px;
            }
        }
    </style>
</head>
<body>

<div class="mobile-topbar">
    <button type="button" class="menu-btn" id="menuBtn">☰</button>
    <strong><?= htmlspecialchars($shop['shop_name'] ?? 'Barbershop') ?></strong>
</div>

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<div class="admin-layout">
    <aside class="admin-sidebar" id="adminSidebar">
        <div class="admin-brand">
            <img src="images/<?= htmlspecialchars($shop['logo'] ?? 'logo.png') ?>" alt="Shop Logo">
            <h2><?= htmlspecialchars($shop['shop_name'] ?? 'Barbershop') ?></h2>
            <p>Owner Portal</p>
        </div>

        <nav class="admin-nav">
            <a href="admin_dashboard.php">Dashboard</a>
            <a href="admin_appointments.php">Appointments</a>
            <a href="admin_walkins.php">Walk-in Queue</a>
            <a href="admin_services.php">Services</a>
            <a href="admin_barbers.php">Barbers</a>
            <a href="admin_staff.php">Staff</a>
            <a href="admin_reviews.php" class="active">Reviews</a>
            <a href="admin_reports.php">Reports</a>
            <a href="admin_ar_models.php">AR Hair Models</a>
            <a href="admin_settings.php">Settings</a>
            <a href="admin_logout.php">Logout</a>
        </nav>
    </aside>

    <main class="admin-main">
        <div class="page-header">
            <div>
                <h1>Shop Reviews</h1>
                <p>See what customers say about their barbershop experience.</p>
            </div>
        </div>

        <?php if ($message !== ''): ?>
            <div class="alert <?= $message_type === 'success' ? 'alert-success' : 'alert-error' ?>">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <section class="stats-grid">
            <div class="stat-card">
                <h3>Total Reviews</h3>
                <div class="value"><?= $summary['total_reviews'] ?></div>
            </div>
            <div class="stat-card">
                <h3>Average Rating</h3>
                <div class="value"><?= number_format($summary['avg_rating'], 1) ?></div>
            </div>
            <div class="stat-card">
                <h3>With Photos</h3>
                <div class="value"><?= $summary['with_photos'] ?></div>
            </div>
            <div class="stat-card">
                <h3>Replied</h3>
                <div class="value"><?= $summary['replied'] ?></div>
            </div>
        </section>

        <section class="panel">
            <form method="GET" class="filter-grid">
                <div class="form-group">
                    <label for="search">Search</label>
                    <input
                        type="text"
                        name="search"
                        id="search"
                        value="<?= htmlspecialchars($search_filter) ?>"
                        placeholder="Customer name, review comment, or owner reply"
                    >
                </div>

                <div class="form-group">
                    <label for="rating">Rating</label>
                    <select name="rating" id="rating">
                        <option value="">All Ratings</option>
                        <option value="5" <?= $rating_filter === '5' ? 'selected' : '' ?>>5 Stars</option>
                        <option value="4" <?= $rating_filter === '4' ? 'selected' : '' ?>>4 Stars</option>
                        <option value="3" <?= $rating_filter === '3' ? 'selected' : '' ?>>3 Stars</option>
                        <option value="2" <?= $rating_filter === '2' ? 'selected' : '' ?>>2 Stars</option>
                        <option value="1" <?= $rating_filter === '1' ? 'selected' : '' ?>>1 Star</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="photo_filter">Photo</label>
                    <select name="photo_filter" id="photo_filter">
                        <option value="">All</option>
                        <option value="with_photo" <?= $photo_filter === 'with_photo' ? 'selected' : '' ?>>With Photo</option>
                        <option value="no_photo" <?= $photo_filter === 'no_photo' ? 'selected' : '' ?>>No Photo</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="reply_filter">Owner Reply</label>
                    <select name="reply_filter" id="reply_filter">
                        <option value="">All</option>
                        <option value="replied" <?= $reply_filter === 'replied' ? 'selected' : '' ?>>Replied</option>
                        <option value="not_replied" <?= $reply_filter === 'not_replied' ? 'selected' : '' ?>>Not Replied</option>
                    </select>
                </div>

                <div class="toolbar-actions">
                    <button type="submit" class="btn btn-primary">Apply Filters</button>
                    <a href="admin_reviews.php" class="btn btn-secondary">Reset</a>
                </div>
            </form>
        </section>

        <section class="panel">
            <div class="table-head">
                <div>
                    <h2>Customer Reviews</h2>
                    <p>Open a review to see the full photo, comment, and write or update the owner reply.</p>
                </div>
            </div>

            <?php if ($reviews && $reviews->num_rows > 0): ?>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Customer</th>
                                <th>Rating</th>
                                <th>Photo</th>
                                <th>Comment</th>
                                <th>Owner Reply</th>
                                <th>Date</th>
                                <th style="width:140px;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($review = $reviews->fetch_assoc()): ?>
                                <?php
                                $reviewId = (int)$review['id'];
                                $customerName = fullName($review);
                                $stars = str_repeat('★', (int)$review['rating']) . str_repeat('☆', 5 - (int)$review['rating']);

                                ob_start();
                                ?>
                                <div class="details-grid">
                                    <div class="details-card">
                                        <h4>Review Details</h4>
                                        <p><strong>Customer:</strong> <?= htmlspecialchars($customerName) ?></p>
                                        <p><strong>Email:</strong> <?= htmlspecialchars($review['email']) ?></p>
                                        <p><strong>Rating:</strong> <span class="stars"><?= htmlspecialchars($stars) ?></span> (<?= (int)$review['rating'] ?>/5)</p>
                                        <p><strong>Submitted:</strong> <?= date("F j, Y g:i A", strtotime($review['created_at'])) ?></p>
                                        <p><strong>Comment:</strong><br><?= nl2br(htmlspecialchars($review['comment'])) ?></p>

                                        <?php if (!empty($review['photo'])): ?>
                                            <img src="<?= htmlspecialchars($review['photo']) ?>" alt="Review Photo" class="review-photo">
                                        <?php else: ?>
                                            <p><strong>Photo:</strong> No photo uploaded.</p>
                                        <?php endif; ?>

                                        <?php if (!empty($review['owner_reply'])): ?>
                                            <div class="owner-reply-box">
                                                <strong>Current Owner Reply</strong><br>
                                                <?= nl2br(htmlspecialchars($review['owner_reply'])) ?><br>
                                                <?php if (!empty($review['owner_reply_at'])): ?>
                                                    <small class="muted">Replied on <?= date("F j, Y g:i A", strtotime($review['owner_reply_at'])) ?></small>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <div class="details-card">
                                        <h4>Owner Reply</h4>
                                        <form method="POST">
                                            <input type="hidden" name="action" value="save_reply">
                                            <input type="hidden" name="review_id" value="<?= $reviewId ?>">

                                            <div class="form-group">
                                                <label>Write or Update Reply</label>
                                                <textarea name="owner_reply" placeholder="Write a professional owner response..."><?= htmlspecialchars($review['owner_reply'] ?? '') ?></textarea>
                                            </div>

                                            <div class="modal-actions">
                                                <button type="submit" class="btn btn-save">Save Reply</button>
                                            </div>
                                        </form>

                                        <div class="modal-actions" style="margin-top:14px;">
                                            <button
                                                type="button"
                                                class="btn btn-delete open-delete-review-modal"
                                                data-review-id="<?= $reviewId ?>"
                                                data-review-name="<?= htmlspecialchars($customerName, ENT_QUOTES) ?>"
                                            >
                                                Delete Review
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                <?php
                                $modalContent = ob_get_clean();
                                ?>
                                <tr>
                                    <td>
                                        <div class="reviewer-meta">
                                            <strong><?= htmlspecialchars($customerName) ?></strong>
                                            <span><?= htmlspecialchars($review['email']) ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="stars"><?= htmlspecialchars($stars) ?></div>
                                        <div><?= (int)$review['rating'] ?>/5</div>
                                    </td>
                                    <td>
                                        <?php if (!empty($review['photo'])): ?>
                                            <span class="photo-tag photo-yes">With Photo</span>
                                        <?php else: ?>
                                            <span class="photo-tag photo-no">No Photo</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="comment-preview"><?= htmlspecialchars(mb_strimwidth($review['comment'], 0, 90, '...')) ?></div>
                                    </td>
                                    <td>
                                        <?php if (!empty(trim($review['owner_reply'] ?? ''))): ?>
                                            <span class="reply-tag reply-yes">Replied</span>
                                            <div class="reply-preview"><?= htmlspecialchars(mb_strimwidth($review['owner_reply'], 0, 70, '...')) ?></div>
                                        <?php else: ?>
                                            <span class="reply-tag reply-no">No Reply</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= date("M j, Y", strtotime($review['created_at'])) ?></td>
                                    <td>
                                        <button
                                            type="button"
                                            class="btn btn-view open-details-modal"
                                            data-title="<?= htmlspecialchars($customerName, ENT_QUOTES) ?>"
                                            data-subtitle="<?= htmlspecialchars('Review • ' . (int)$review['rating'] . '/5 stars', ENT_QUOTES) ?>"
                                            data-content-id="review-detail-<?= $reviewId ?>"
                                        >
                                            View / Reply
                                        </button>

                                        <div class="hidden-detail" id="review-detail-<?= $reviewId ?>">
                                            <?= $modalContent ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    No reviews found.
                </div>
            <?php endif; ?>
        </section>
    </main>
</div>

<div class="modal-backdrop" id="reviewDetailsModal">
    <div class="modal">
        <div class="modal-header">
            <h3 id="reviewModalTitle">Review Details</h3>
            <p id="reviewModalSubtitle"></p>
            <button type="button" class="modal-close" data-close-modal="reviewDetailsModal">&times;</button>
        </div>
        <div class="modal-body" id="reviewModalBody"></div>
    </div>
</div>

<div class="modal-backdrop" id="deleteReviewModal">
    <div class="modal modal-sm">
        <div class="modal-header">
            <h3>Delete Review</h3>
            <p id="deleteReviewSubtitle">This action will permanently remove the selected review.</p>
            <button type="button" class="modal-close" data-close-modal="deleteReviewModal">&times;</button>
        </div>
        <div class="modal-body">
            <form method="POST">
                <input type="hidden" name="action" value="delete_review">
                <input type="hidden" name="review_id" id="deleteReviewId">

                <div class="warning-text">
                    Are you sure you want to delete the review from <strong id="deleteReviewName">this customer</strong>? This action cannot be undone.
                </div>

                <div class="modal-actions" style="margin-top:18px;">
                    <button type="submit" class="btn btn-delete">Yes, Delete</button>
                    <button type="button" class="btn btn-secondary" data-close-modal="deleteReviewModal">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openModal(id) {
    document.getElementById(id).classList.add('show');
}

function closeModal(id) {
    document.getElementById(id).classList.remove('show');
}

function openSidebar() {
    document.getElementById('adminSidebar').classList.add('show');
    document.getElementById('sidebarOverlay').classList.add('show');
    document.body.classList.add('sidebar-open');
}

function closeSidebar() {
    document.getElementById('adminSidebar').classList.remove('show');
    document.getElementById('sidebarOverlay').classList.remove('show');
    document.body.classList.remove('sidebar-open');
}

document.addEventListener('click', function (e) {
    const closeBtn = e.target.closest('[data-close-modal]');
    if (closeBtn) {
        closeModal(closeBtn.getAttribute('data-close-modal'));
        return;
    }

    const detailBtn = e.target.closest('.open-details-modal');
    if (detailBtn) {
        const contentId = detailBtn.getAttribute('data-content-id');
        const contentNode = document.getElementById(contentId);
        document.getElementById('reviewModalTitle').textContent = detailBtn.getAttribute('data-title') || 'Review Details';
        document.getElementById('reviewModalSubtitle').textContent = detailBtn.getAttribute('data-subtitle') || '';
        document.getElementById('reviewModalBody').innerHTML = contentNode ? contentNode.innerHTML : '';
        openModal('reviewDetailsModal');
        return;
    }

    const deleteBtn = e.target.closest('.open-delete-review-modal');
    if (deleteBtn) {
        const reviewId = deleteBtn.getAttribute('data-review-id') || '';
        const reviewName = deleteBtn.getAttribute('data-review-name') || 'this customer';

        document.getElementById('deleteReviewId').value = reviewId;
        document.getElementById('deleteReviewName').textContent = reviewName;
        document.getElementById('deleteReviewSubtitle').textContent =
            'You are about to permanently delete the review from ' + reviewName + '.';

        openModal('deleteReviewModal');
        return;
    }
});

document.querySelectorAll('.modal-backdrop').forEach(backdrop => {
    backdrop.addEventListener('click', function (e) {
        if (e.target === this) {
            this.classList.remove('show');
        }
    });
});

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