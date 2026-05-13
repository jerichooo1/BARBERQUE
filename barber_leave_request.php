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

$message = '';
$messageType = 'success';

$hasRejectionReason = false;
$checkColumn = $conn->query("SHOW COLUMNS FROM barber_leaves LIKE 'rejection_reason'");
if ($checkColumn && $checkColumn->num_rows > 0) {
    $hasRejectionReason = true;
}

/* HANDLE SUBMIT */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $start_date = trim($_POST['start_date'] ?? '');
    $end_date = trim($_POST['end_date'] ?? '');
    $reason = trim($_POST['reason'] ?? '');

    if ($start_date === '' || $end_date === '' || $reason === '') {
        $message = 'Please complete all required fields.';
        $messageType = 'error';
    } elseif ($start_date < date('Y-m-d') || $end_date < date('Y-m-d')) {
        $message = 'You cannot file a leave request for past dates.';
        $messageType = 'error';
    } elseif ($end_date < $start_date) {
        $message = 'End date cannot be earlier than start date.';
        $messageType = 'error';
    } else {
        $start = new DateTime($start_date);
        $end = new DateTime($end_date);
        $endInclusive = (clone $end)->modify('+1 day');

        $insertedDates = [];
        $skippedDates = [];

        $checkStmt = $conn->prepare("
            SELECT id
            FROM barber_leaves
            WHERE barber_id = ?
              AND leave_date = ?
              AND status IN ('pending', 'approved')
            LIMIT 1
        ");

        $insertStmt = $conn->prepare("
            INSERT INTO barber_leaves (barber_id, leave_date, reason, status, created_at)
            VALUES (?, ?, ?, 'pending', NOW())
        ");

        $period = new DatePeriod($start, new DateInterval('P1D'), $endInclusive);

        foreach ($period as $dateObj) {
            $currentDate = $dateObj->format('Y-m-d');

            $checkStmt->bind_param("is", $barber_id, $currentDate);
            $checkStmt->execute();
            $existing = $checkStmt->get_result()->fetch_assoc();

            if ($existing) {
                $skippedDates[] = $currentDate;
                continue;
            }

            $insertStmt->bind_param("iss", $barber_id, $currentDate, $reason);
            if ($insertStmt->execute()) {
                $insertedDates[] = $currentDate;
            }
        }

        $checkStmt->close();
        $insertStmt->close();

        if (!empty($insertedDates) && empty($skippedDates)) {
            $message = 'Leave request submitted successfully for ' . count($insertedDates) . ' day(s).';
            $messageType = 'success';
        } elseif (!empty($insertedDates) && !empty($skippedDates)) {
            $message = 'Leave request submitted for ' . count($insertedDates) . ' day(s). '
                     . count($skippedDates) . ' day(s) were skipped because they already have pending or approved leave requests.';
            $messageType = 'success';
        } else {
            $message = 'No leave requests were submitted because all selected dates already have pending or approved leave requests.';
            $messageType = 'error';
        }
    }
}

/* SUMMARY */
$summary = [
    'pending_count' => 0,
    'approved_count' => 0,
    'rejected_count' => 0
];

$stmt = $conn->prepare("
    SELECT
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_count,
        SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) AS approved_count,
        SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) AS rejected_count
    FROM barber_leaves
    WHERE barber_id = ?
");
$stmt->bind_param("i", $barber_id);
$stmt->execute();
$summaryRow = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($summaryRow) {
    $summary['pending_count'] = (int)($summaryRow['pending_count'] ?? 0);
    $summary['approved_count'] = (int)($summaryRow['approved_count'] ?? 0);
    $summary['rejected_count'] = (int)($summaryRow['rejected_count'] ?? 0);
}

/* RECENT LEAVES */
$recentLeaves = [];

if ($hasRejectionReason) {
    $stmt = $conn->prepare("
        SELECT id, leave_date, reason, status, rejection_reason, created_at
        FROM barber_leaves
        WHERE barber_id = ?
        ORDER BY leave_date DESC, created_at DESC
        LIMIT 20
    ");
} else {
    $stmt = $conn->prepare("
        SELECT id, leave_date, reason, status, created_at
        FROM barber_leaves
        WHERE barber_id = ?
        ORDER BY leave_date DESC, created_at DESC
        LIMIT 20
    ");
}
$stmt->bind_param("i", $barber_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $recentLeaves[] = $row;
}
$stmt->close();

$minDate = date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Leave Requests | <?= h($shop['shop_name'] ?? 'Barbershop') ?></title>
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

        .stat-card .subtext {
            margin-top: 8px;
            color: #6b7280;
            font-size: 13px;
            line-height: 1.45;
        }

        .grid-two {
            display: grid;
            grid-template-columns: 0.95fr 1.05fr;
            gap: 18px;
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
            margin-bottom: 16px;
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

        .form-group {
            margin-bottom: 18px;
        }

        .date-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-size: 14px;
            font-weight: 600;
            color: #374151;
        }

        .form-group input,
        .form-group textarea {
            width: 100%;
            padding: 12px 14px;
            border-radius: 12px;
            border: 1px solid #dbe3ee;
            background: #fff;
            font-size: 14px;
            outline: none;
            font-family: inherit;
        }

        .form-group textarea {
            min-height: 140px;
            resize: vertical;
        }

        .form-group input:focus,
        .form-group textarea:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.12);
        }

        .btn-primary {
            background: #2563eb;
            color: #fff;
            border: none;
            border-radius: 12px;
            padding: 12px 18px;
            font-weight: 600;
            cursor: pointer;
            font-size: 14px;
            width: auto;
        }

        .btn-primary:hover {
            opacity: 0.95;
        }

        .list-stack {
            display: grid;
            gap: 12px;
        }

        .leave-card {
            background: #fff;
            border: 1px solid #e6edf5;
            border-radius: 16px;
            padding: 15px;
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

        .leave-card p {
            margin: 7px 0 0;
            line-height: 1.65;
            color: #334155;
            font-size: 14px;
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

        .status-rejected {
            background: #fee2e2;
            color: #991b1b;
        }

        .status-default {
            background: #eef2f7;
            color: #475569;
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

        .info-note {
            background: #f8fafc;
            border: 1px solid #e6edf5;
            border-radius: 16px;
            padding: 14px;
            color: #475569;
            font-size: 14px;
            line-height: 1.7;
            margin-top: 16px;
        }

        .info-note strong {
            color: #111827;
        }

        @media (max-width: 1200px) {
            .grid-two {
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
            .date-grid {
                grid-template-columns: 1fr;
            }

            .page-header h1 {
                font-size: 26px;
            }

            .panel,
            .stat-card {
                padding: 16px;
            }

            .btn-primary {
                width: 100%;
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
            <a href="barber_leave_request.php" class="active">Leave Requests</a>
            <a href="barber_reviews.php">Reviews & Ratings</a>
            <a href="admin_logout.php">Logout</a>
        </nav>
    </aside>

    <main class="barber-main">
        <div class="page-header">
            <div>
                <h1>Leave Requests</h1>
                <p>Submit one-day or multi-day leave requests and monitor whether they are pending, approved, or rejected by the owner.</p>
            </div>
        </div>

        <?php if ($message !== ''): ?>
            <div class="alert <?= $messageType === 'success' ? 'alert-success' : 'alert-error' ?>">
                <?= h($message) ?>
            </div>
        <?php endif; ?>

        <section class="stats-grid">
            <div class="stat-card">
                <h3>Pending Requests</h3>
                <div class="value"><?= (int)$summary['pending_count'] ?></div>
                <div class="subtext">Waiting for approval</div>
            </div>

            <div class="stat-card">
                <h3>Approved Requests</h3>
                <div class="value"><?= (int)$summary['approved_count'] ?></div>
                <div class="subtext">Already accepted by owner</div>
            </div>

            <div class="stat-card">
                <h3>Rejected Requests</h3>
                <div class="value"><?= (int)$summary['rejected_count'] ?></div>
                <div class="subtext">Declined requests</div>
            </div>
        </section>

        <section class="grid-two">
            <div class="panel">
                <div class="panel-head">
                    <h2>File New Leave Request</h2>
                    <p>You may request leave even if you already have appointments on those dates.</p>
                </div>

                <form method="POST">
                    <div class="date-grid">
                        <div class="form-group">
                            <label for="start_date">Start Date</label>
                            <input type="date" id="start_date" name="start_date" min="<?= h($minDate) ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="end_date">End Date</label>
                            <input type="date" id="end_date" name="end_date" min="<?= h($minDate) ?>" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="reason">Reason</label>
                        <textarea id="reason" name="reason" placeholder="Enter your reason for leave..." required></textarea>
                    </div>

                    <button type="submit" class="btn-primary">Submit Leave Request</button>
                </form>

                <div class="info-note">
                    <strong>Important:</strong> Filing a leave request does <strong>not</strong> check whether you already have appointments on those dates.<br><br>
                    Your request can still be submitted.<br><br>
                    If the owner approves the leave, customers with appointments on the approved leave date(s) will be notified by email to change their schedule or choose another barber.
                </div>
            </div>

            <div class="panel">
                <div class="panel-head">
                    <h2>Recent Leave Requests</h2>
                    <p>Your latest submitted leave dates and their decision status.</p>
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