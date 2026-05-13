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

/* HELPERS */
function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function statusBadgeClass($status) {
    $status = strtolower(trim($status));
    switch ($status) {
        case 'pending approval':
        case 'pending':
            return 'status-pending';
        case 'approved':
            return 'status-approved';
        case 'completed':
            return 'status-completed';
        case 'rejected':
            return 'status-rejected';
        case 'no-show':
            return 'status-noshow';
        case 'canceled':
        case 'cancelled':
            return 'status-canceled';
        default:
            return 'status-default';
    }
}

function leaveBadgeClass($status) {
    $status = strtolower(trim($status));
    switch ($status) {
        case 'approved':
            return 'status-approved';
        case 'rejected':
            return 'status-rejected';
        case 'pending':
            return 'status-pending';
        default:
            return 'status-default';
    }
}

function renderStars($rating) {
    $rating = max(0, min(5, (float)$rating));
    $full = (int)floor($rating);
    $half = (($rating - $full) >= 0.5) ? 1 : 0;
    $empty = 5 - $full - $half;

    return str_repeat('★', $full) . ($half ? '☆' : '') . str_repeat('✩', $empty);
}

function formatScheduleRange($start, $end) {
    if (empty($start)) return 'Not set';
    $startTs = strtotime($start);
    if (!$startTs) return 'Not set';

    if (!empty($end) && strtotime($end)) {
        return date("M j, Y g:i A", $startTs) . " - " . date("g:i A", strtotime($end));
    }

    return date("M j, Y g:i A", $startTs);
}

/* VALUES */
$today = date('Y-m-d');
$nowDateTime = date('Y-m-d H:i:s');
$currentDayName = date('l');

/* RATING SUMMARY */
$ratingSummary = [
    'avg_rating' => 0,
    'total_reviews' => 0
];

$stmt = $conn->prepare("
    SELECT
        COALESCE(ROUND(AVG(rating), 1), 0) AS avg_rating,
        COUNT(*) AS total_reviews
    FROM barber_ratings
    WHERE barber_id = ?
");
$stmt->bind_param("i", $barber_id);
$stmt->execute();
$ratingResult = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($ratingResult) {
    $ratingSummary['avg_rating'] = (float)($ratingResult['avg_rating'] ?? 0);
    $ratingSummary['total_reviews'] = (int)($ratingResult['total_reviews'] ?? 0);
}

/* TODAY SCHEDULE */
$todaySchedule = null;
$stmt = $conn->prepare("
    SELECT start_time, end_time
    FROM barber_schedules
    WHERE barber_id = ? AND day_of_week = ?
    LIMIT 1
");
$stmt->bind_param("is", $barber_id, $currentDayName);
$stmt->execute();
$todaySchedule = $stmt->get_result()->fetch_assoc();
$stmt->close();

/* TODAY APPOINTMENTS */
$todaysAppointments = [];
$stmt = $conn->prepare("
    SELECT
        a.id,
        a.start_time,
        a.end_time,
        a.status,
        CONCAT(u.first_name, ' ', u.last_name) AS customer_name,
        u.email,
        GROUP_CONCAT(DISTINCT s.name ORDER BY s.name SEPARATOR ', ') AS services
    FROM appointments a
    JOIN users u ON a.customer_id = u.id
    LEFT JOIN appointment_services aps ON a.id = aps.appointment_id
    LEFT JOIN services s ON aps.service_id = s.id
    WHERE a.barber_id = ?
      AND DATE(a.start_time) = ?
      AND a.status IN ('pending approval', 'approved', 'completed')
    GROUP BY a.id
    ORDER BY a.start_time ASC
");
$stmt->bind_param("is", $barber_id, $today);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $todaysAppointments[] = $row;
}
$stmt->close();

/* UPCOMING APPOINTMENTS */
$upcomingAppointments = [];
$stmt = $conn->prepare("
    SELECT
        a.id,
        a.start_time,
        a.end_time,
        a.status,
        CONCAT(u.first_name, ' ', u.last_name) AS customer_name,
        u.email,
        GROUP_CONCAT(DISTINCT s.name ORDER BY s.name SEPARATOR ', ') AS services
    FROM appointments a
    JOIN users u ON a.customer_id = u.id
    LEFT JOIN appointment_services aps ON a.id = aps.appointment_id
    LEFT JOIN services s ON aps.service_id = s.id
    WHERE a.barber_id = ?
      AND a.start_time >= ?
      AND a.status IN ('pending approval', 'approved')
    GROUP BY a.id
    ORDER BY a.start_time ASC
    LIMIT 10
");
$stmt->bind_param("is", $barber_id, $nowDateTime);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $upcomingAppointments[] = $row;
}
$stmt->close();

/* NEXT APPOINTMENT */
$nextAppointment = null;
$stmt = $conn->prepare("
    SELECT
        a.id,
        a.start_time,
        a.end_time,
        a.status,
        CONCAT(u.first_name, ' ', u.last_name) AS customer_name
    FROM appointments a
    JOIN users u ON a.customer_id = u.id
    WHERE a.barber_id = ?
      AND a.start_time >= ?
      AND a.status IN ('pending approval', 'approved')
    ORDER BY a.start_time ASC
    LIMIT 1
");
$stmt->bind_param("is", $barber_id, $nowDateTime);
$stmt->execute();
$nextAppointment = $stmt->get_result()->fetch_assoc();
$stmt->close();

/* RECENT REVIEWS */
$recentReviews = [];
$stmt = $conn->prepare("
    SELECT
        br.rating,
        br.comment,
        br.created_at,
        CONCAT(u.first_name, ' ', u.last_name) AS customer_name
    FROM barber_ratings br
    JOIN users u ON br.customer_id = u.id
    WHERE br.barber_id = ?
    ORDER BY br.created_at DESC
    LIMIT 6
");
$stmt->bind_param("i", $barber_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $recentReviews[] = $row;
}
$stmt->close();

/* WEEKLY SCHEDULE */
$weeklySchedules = [];
$stmt = $conn->prepare("
    SELECT day_of_week, start_time, end_time
    FROM barber_schedules
    WHERE barber_id = ?
    ORDER BY FIELD(day_of_week,'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday')
");
$stmt->bind_param("i", $barber_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $weeklySchedules[$row['day_of_week']] = $row;
}
$stmt->close();

/* LEAVE SUMMARY */
$leaveSummary = [
    'pending_count' => 0,
    'approved_upcoming_count' => 0,
    'rejected_count' => 0
];

$stmt = $conn->prepare("
    SELECT
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_count,
        SUM(CASE WHEN status = 'approved' AND leave_date >= CURDATE() THEN 1 ELSE 0 END) AS approved_upcoming_count,
        SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) AS rejected_count
    FROM barber_leaves
    WHERE barber_id = ?
");
$stmt->bind_param("i", $barber_id);
$stmt->execute();
$leaveCounts = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($leaveCounts) {
    $leaveSummary['pending_count'] = (int)($leaveCounts['pending_count'] ?? 0);
    $leaveSummary['approved_upcoming_count'] = (int)($leaveCounts['approved_upcoming_count'] ?? 0);
    $leaveSummary['rejected_count'] = (int)($leaveCounts['rejected_count'] ?? 0);
}

/* RECENT LEAVES */
$recentLeaves = [];
$hasRejectionReason = false;

$checkColumn = $conn->query("SHOW COLUMNS FROM barber_leaves LIKE 'rejection_reason'");
if ($checkColumn && $checkColumn->num_rows > 0) {
    $hasRejectionReason = true;
}

if ($hasRejectionReason) {
    $stmt = $conn->prepare("
        SELECT leave_date, reason, status, rejection_reason, created_at
        FROM barber_leaves
        WHERE barber_id = ?
        ORDER BY created_at DESC, leave_date DESC
        LIMIT 6
    ");
} else {
    $stmt = $conn->prepare("
        SELECT leave_date, reason, status, created_at
        FROM barber_leaves
        WHERE barber_id = ?
        ORDER BY created_at DESC, leave_date DESC
        LIMIT 6
    ");
}
$stmt->bind_param("i", $barber_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $recentLeaves[] = $row;
}
$stmt->close();

$stats = [
    'today_count' => count($todaysAppointments),
    'upcoming_count' => count($upcomingAppointments),
    'avg_rating' => $ratingSummary['avg_rating'],
    'pending_leave_count' => $leaveSummary['pending_count']
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Barber Dashboard | <?= h($shop['shop_name'] ?? 'Barbershop') ?></title>
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
            max-width: 780px;
        }

        .header-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .btn {
            padding: 11px 16px;
            border-radius: 12px;
            border: none;
            cursor: pointer;
            font-weight: 600;
            font-size: 14px;
            transition: 0.18s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .btn:hover {
            transform: translateY(-1px);
        }

        .btn-primary {
            background: #2563eb;
            color: #fff;
        }

        .btn-light {
            background: #eef2f7;
            color: #111827;
        }

        .hero-card {
            display: grid;
            grid-template-columns: 1.4fr 0.8fr;
            gap: 18px;
            background: linear-gradient(135deg, #0b1730 0%, #18253a 100%);
            color: #fff;
            border-radius: 24px;
            padding: 24px;
            box-shadow: 0 10px 30px rgba(15, 23, 42, 0.08);
            margin-bottom: 18px;
            align-items: center;
        }

        .hero-card h2 {
            margin: 0 0 10px;
            font-size: 28px;
            line-height: 1.2;
        }

        .hero-card p {
            margin: 0;
            color: #dbe4f0;
            line-height: 1.7;
        }

        .hero-side {
            background: rgba(255,255,255,0.08);
            border-radius: 18px;
            padding: 18px;
        }

        .hero-side .label {
            display: block;
            font-size: 13px;
            color: #dbe4f0;
            margin-bottom: 8px;
        }

        .hero-side .big {
            font-size: 22px;
            font-weight: 700;
            line-height: 1.3;
            margin-bottom: 10px;
        }

        .hero-side .sub {
            color: #e5e7eb;
            font-size: 14px;
            line-height: 1.6;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(170px, 1fr));
            gap: 14px;
            margin-bottom: 18px;
        }

        .stat-card {
            background: #fff;
            border-radius: 18px;
            padding: 18px;
            box-shadow: 0 8px 24px rgba(15, 23, 42, 0.06);
            border: 1px solid #eef2f7;
            border-left: 5px solid #2563eb;
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

        .stat-card .subtext {
            margin-top: 8px;
            color: #6b7280;
            font-size: 13px;
            line-height: 1.45;
        }

        .grid-two {
            display: grid;
            grid-template-columns: 1.3fr 0.9fr;
            gap: 18px;
            margin-bottom: 18px;
        }

        .grid-two-equal {
            display: grid;
            grid-template-columns: 1fr 1fr;
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

        .panel-head {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            align-items: flex-start;
            margin-bottom: 16px;
            flex-wrap: wrap;
        }

        .panel-head h2 {
            margin: 0;
            font-size: 22px;
            color: #111827;
        }

        .panel-head p {
            margin: 6px 0 0;
            color: #6b7280;
            font-size: 14px;
            line-height: 1.55;
        }

        .schedule-info-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
            margin-bottom: 16px;
        }

        .info-box {
            background: #f8fafc;
            border: 1px solid #e6edf5;
            border-radius: 16px;
            padding: 14px;
        }

        .info-box label {
            display: block;
            margin-bottom: 7px;
            color: #6b7280;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }

        .info-box div {
            color: #111827;
            line-height: 1.5;
            font-weight: 600;
        }

        .list-stack {
            display: grid;
            gap: 12px;
        }

        .appointment-card,
        .review-card,
        .leave-card,
        .day-card {
            background: #fff;
            border: 1px solid #e6edf5;
            border-radius: 16px;
            padding: 15px;
        }

        .appointment-card {
            background: #fbfcfe;
        }

        .card-top {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            align-items: flex-start;
            flex-wrap: wrap;
            margin-bottom: 8px;
        }

        .card-top h3 {
            margin: 0;
            font-size: 16px;
            color: #111827;
            line-height: 1.35;
        }

        .meta-text {
            color: #6b7280;
            font-size: 13px;
            line-height: 1.6;
        }

        .service-line {
            color: #334155;
            font-size: 14px;
            line-height: 1.65;
            margin-top: 8px;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 6px 11px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
            white-space: nowrap;
        }

        .status-pending {
            background: #fff4cc;
            color: #8a6d00;
        }

        .status-approved {
            background: #dcfce7;
            color: #166534;
        }

        .status-completed {
            background: #dbeafe;
            color: #1d4ed8;
        }

        .status-rejected {
            background: #fee2e2;
            color: #991b1b;
        }

        .status-noshow {
            background: #ede9fe;
            color: #6d28d9;
        }

        .status-canceled {
            background: #e5e7eb;
            color: #4b5563;
        }

        .status-default {
            background: #eef2f7;
            color: #475569;
        }

        .rating-box {
            text-align: center;
            padding: 18px;
            border-radius: 18px;
            background: #eff6ff;
            border: 1px solid #dbeafe;
        }

        .rating-box .big {
            font-size: 34px;
            font-weight: 700;
            color: #111827;
        }

        .rating-stars {
            font-size: 19px;
            color: #2563eb;
            letter-spacing: 1px;
            margin: 8px 0;
        }

        .rating-note {
            color: #6b7280;
            font-size: 13px;
            line-height: 1.55;
        }

        .mini-grid {
            display: grid;
            gap: 12px;
            margin-top: 14px;
        }

        .weekly-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }

        .day-card strong {
            display: block;
            margin-bottom: 7px;
            font-size: 15px;
            color: #111827;
        }

        .review-card p,
        .leave-card p {
            margin: 7px 0 0;
            line-height: 1.65;
            color: #334155;
            font-size: 14px;
        }

        .empty-state {
            background: #fafbfc;
            border: 1px dashed #d5dde7;
            padding: 28px 18px;
            border-radius: 18px;
            text-align: center;
            color: #6b7280;
            font-size: 14px;
            line-height: 1.6;
        }

        @media (max-width: 1200px) {
            .stats-grid {
                grid-template-columns: repeat(2, minmax(170px, 1fr));
            }

            .hero-card,
            .grid-two,
            .grid-two-equal {
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
            .stats-grid,
            .schedule-info-grid,
            .weekly-grid {
                grid-template-columns: 1fr;
            }

            .page-header h1 {
                font-size: 26px;
            }

            .header-actions {
                width: 100%;
                flex-direction: column;
            }

            .btn {
                width: 100%;
            }

            .panel,
            .stat-card,
            .hero-card {
                padding: 16px;
            }

            .hero-card h2 {
                font-size: 22px;
            }

            .hero-side .big {
                font-size: 18px;
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
            <a href="barber_dashboard.php" class="active">Dashboard</a>
            <a href="barber_leave_request.php">Leave Requests</a>
            <a href="barber_reviews.php">Reviews & Ratings</a>
            <a href="admin_logout.php">Logout</a>
        </nav>
    </aside>

    <main class="barber-main">
        <div class="page-header">
            <div>
                <h1>Welcome back, <?= h($barber['name']) ?></h1>
                <p>Manage your appointments, availability, and daily work schedule.</p>
            </div>

            <div class="header-actions">
                <a href="barber_leave_request.php" class="btn btn-primary">File Leave Request</a>
                <a href="barber_reviews.php" class="btn btn-light">View All Reviews</a>
            </div>
        </div>

        <section class="hero-card">
            <div>
                <h2>Your day, organized and client-ready.</h2>
                <p>Track your daily workload, check your next client, review customer feedback, and stay updated on leave request decisions in real time.</p>
            </div>

            <div class="hero-side">
                <span class="label">Next Appointment</span>
                <?php if ($nextAppointment): ?>
                    <div class="big">
                        <?= h(date("g:i A", strtotime($nextAppointment['start_time']))) ?><br>
                        <?= h($nextAppointment['customer_name']) ?>
                    </div>
                    <div class="sub">
                        <?= h(date("M j, Y", strtotime($nextAppointment['start_time']))) ?><br>
                        Status: <?= h(ucwords(str_replace('-', ' ', $nextAppointment['status']))) ?>
                    </div>
                <?php else: ?>
                    <div class="big">No upcoming booking</div>
                    <div class="sub">You currently have no pending or approved appointments ahead.</div>
                <?php endif; ?>
            </div>
        </section>

        <section class="stats-grid">
            <div class="stat-card">
                <h3>Today's Appointments</h3>
                <div class="value"><?= (int)$stats['today_count'] ?></div>
                <div class="subtext">Bookings scheduled for <?= h(date("F j, Y")) ?></div>
            </div>

            <div class="stat-card">
                <h3>Upcoming Bookings</h3>
                <div class="value"><?= (int)$stats['upcoming_count'] ?></div>
                <div class="subtext">Pending approval and approved future bookings</div>
            </div>

            <div class="stat-card">
                <h3>Average Rating</h3>
                <div class="value"><?= number_format((float)$stats['avg_rating'], 1) ?></div>
                <div class="subtext"><?= (int)$ratingSummary['total_reviews'] ?> total review(s)</div>
            </div>

            <div class="stat-card">
                <h3>Pending Leave Requests</h3>
                <div class="value"><?= (int)$stats['pending_leave_count'] ?></div>
                <div class="subtext">Requests still waiting for owner approval</div>
            </div>
        </section>

        <section class="grid-two">
            <div class="panel">
                <div class="panel-head">
                    <div>
                        <h2>Today's Work Schedule</h2>
                        <p>Your assigned schedule and client list for today.</p>
                    </div>
                </div>

                <div class="schedule-info-grid">
                    <div class="info-box">
                        <label>Day</label>
                        <div><?= h($currentDayName) ?></div>
                    </div>

                    <div class="info-box">
                        <label>Working Hours</label>
                        <div>
                            <?php if ($todaySchedule): ?>
                                <?= h(date("g:i A", strtotime($todaySchedule['start_time']))) ?> -
                                <?= h(date("g:i A", strtotime($todaySchedule['end_time']))) ?>
                            <?php else: ?>
                                No schedule set
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <?php if (!empty($todaysAppointments)): ?>
                    <div class="list-stack">
                        <?php foreach ($todaysAppointments as $appt): ?>
                            <div class="appointment-card">
                                <div class="card-top">
                                    <div>
                                        <h3><?= h($appt['customer_name']) ?></h3>
                                        <div class="meta-text"><?= h(formatScheduleRange($appt['start_time'], $appt['end_time'])) ?></div>
                                    </div>
                                    <span class="status-badge <?= h(statusBadgeClass($appt['status'])) ?>">
                                        <?= h(ucwords(str_replace('-', ' ', $appt['status']))) ?>
                                    </span>
                                </div>
                                <div class="service-line"><strong>Services:</strong> <?= h($appt['services'] ?: 'No services listed') ?></div>
                                <div class="meta-text">Customer Email: <?= h($appt['email']) ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">No appointments scheduled for today.</div>
                <?php endif; ?>
            </div>

            <div class="panel">
                <div class="panel-head">
                    <div>
                        <h2>Ratings Overview</h2>
                        <p>Your current customer feedback snapshot.</p>
                    </div>
                </div>

                <div class="rating-box">
                    <div class="big"><?= number_format($ratingSummary['avg_rating'], 1) ?>/5</div>
                    <div class="rating-stars"><?= h(renderStars($ratingSummary['avg_rating'])) ?></div>
                    <div class="rating-note"><?= (int)$ratingSummary['total_reviews'] ?> total verified review(s)</div>
                </div>

                
            </div>
        </section>

        <section class="grid-two-equal">
            <div class="panel">
                <div class="panel-head">
                    <div>
                        <h2>Upcoming Appointments</h2>
                        <p>Your next pending and approved bookings.</p>
                    </div>
                </div>

                <?php if (!empty($upcomingAppointments)): ?>
                    <div class="list-stack">
                        <?php foreach ($upcomingAppointments as $appt): ?>
                            <div class="appointment-card">
                                <div class="card-top">
                                    <div>
                                        <h3><?= h($appt['customer_name']) ?></h3>
                                        <div class="meta-text"><?= h(formatScheduleRange($appt['start_time'], $appt['end_time'])) ?></div>
                                    </div>
                                    <span class="status-badge <?= h(statusBadgeClass($appt['status'])) ?>">
                                        <?= h(ucwords(str_replace('-', ' ', $appt['status']))) ?>
                                    </span>
                                </div>
                                <div class="service-line"><strong>Services:</strong> <?= h($appt['services'] ?: 'No services listed') ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">No upcoming appointments found.</div>
                <?php endif; ?>
            </div>

            <div class="panel">
                <div class="panel-head">
                    <div>
                        <h2>Recent Reviews</h2>
                        <p>Your most recent customer comments and ratings.</p>
                    </div>
                </div>

                <?php if (!empty($recentReviews)): ?>
                    <div class="list-stack">
                        <?php foreach ($recentReviews as $review): ?>
                            <div class="review-card">
                                <div class="card-top">
                                    <h3><?= h($review['customer_name']) ?></h3>
                                    <span class="meta-text"><?= h(date("M j, Y", strtotime($review['created_at']))) ?></span>
                                </div>
                                <div class="rating-stars">
                                    <?= h(renderStars($review['rating'])) ?>
                                    <span style="font-size:14px;color:#334155;">(<?= (int)$review['rating'] ?>/5)</span>
                                </div>
                                <p><?= !empty($review['comment']) ? h($review['comment']) : 'No written comment provided.' ?></p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">No customer reviews yet.</div>
                <?php endif; ?>
            </div>
        </section>

        <section class="grid-two">
            <div class="panel">
                <div class="panel-head">
                    <div>
                        <h2>Weekly Schedule</h2>
                        <p>Your configured barber schedule used for appointment availability.</p>
                    </div>
                </div>

                <div class="weekly-grid">
                    <?php
                    $days = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];
                    foreach ($days as $day):
                    ?>
                        <div class="day-card">
                            <strong><?= h($day) ?></strong>
                            <div class="meta-text">
                                <?php if (isset($weeklySchedules[$day])): ?>
                                    <?= h(date("g:i A", strtotime($weeklySchedules[$day]['start_time']))) ?>
                                    -
                                    <?= h(date("g:i A", strtotime($weeklySchedules[$day]['end_time']))) ?>
                                <?php else: ?>
                                    No schedule set
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="panel">
                <div class="panel-head">
                    <div>
                        <h2>Recent Leave Requests</h2>
                        <p>Track your recent leave submissions and decision status.</p>
                    </div>
                </div>

                <?php if (!empty($recentLeaves)): ?>
                    <div class="list-stack">
                        <?php foreach ($recentLeaves as $leave): ?>
                            <div class="leave-card">
                                <div class="card-top">
                                    <div>
                                        <h3><?= h(date("F j, Y", strtotime($leave['leave_date']))) ?></h3>
                                        <div class="meta-text">Filed on <?= h(date("F j, Y g:i A", strtotime($leave['created_at']))) ?></div>
                                    </div>
                                    <span class="status-badge <?= h(leaveBadgeClass($leave['status'])) ?>">
                                        <?= h(ucfirst($leave['status'])) ?>
                                    </span>
                                </div>
                                <p><strong>Reason:</strong> <?= h($leave['reason'] ?: 'No reason provided.') ?></p>
                                <?php if ($hasRejectionReason && strtolower($leave['status']) === 'rejected' && !empty($leave['rejection_reason'])): ?>
                                    <p><strong>Rejection Reason:</strong> <?= h($leave['rejection_reason']) ?></p>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">No leave requests found yet.</div>
                <?php endif; ?>
            </div>
        </section>
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