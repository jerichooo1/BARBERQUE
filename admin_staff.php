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

function formatNamePart($value) {
    $value = trim($value);
    $value = preg_replace('/\s+/', ' ', $value);
    return ucwords(strtolower($value));
}

function validPasswordRule($password) {
    return preg_match('/^(?=.*[A-Z])(?=.*[!@#$%^&*()_\-+=\[\]{};:,.<>?\/\\\\|`~]).{8,}$/', $password);
}

/* HANDLE ACTIONS */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $fname = formatNamePart($_POST['fname'] ?? '');
        $mname = formatNamePart($_POST['mname'] ?? '');
        $lname = formatNamePart($_POST['lname'] ?? '');
        $email = strtolower(trim($_POST['email'] ?? ''));
        $password = $_POST['password'] ?? '';
        $status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';

        if ($fname === '' || $lname === '' || $email === '' || $password === '') {
            $message = 'Please fill in all required staff fields.';
            $message_type = 'error';
        } elseif (!preg_match('/^[a-zA-Z\s]+$/', $fname)) {
            $message = 'First name should contain letters and spaces only.';
            $message_type = 'error';
        } elseif ($mname !== '' && !preg_match('/^[a-zA-Z\s]+$/', $mname)) {
            $message = 'Middle name should contain letters and spaces only.';
            $message_type = 'error';
        } elseif (!preg_match('/^[a-zA-Z\s]+$/', $lname)) {
            $message = 'Last name should contain letters and spaces only.';
            $message_type = 'error';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = 'Invalid email format.';
            $message_type = 'error';
        } elseif (!validPasswordRule($password)) {
            $message = 'Password must be at least 8 characters long and contain at least 1 uppercase letter and 1 special character.';
            $message_type = 'error';
        } else {
            $stmt = $conn->prepare("SELECT id FROM staff WHERE email = ? LIMIT 1");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $stmt->store_result();

            if ($stmt->num_rows > 0) {
                $message = 'Email already exists for another staff account.';
                $message_type = 'error';
                $stmt->close();
            } else {
                $stmt->close();

                $hashed_password = password_hash($password, PASSWORD_DEFAULT);

                $stmt = $conn->prepare("
                    INSERT INTO staff (fname, mname, lname, email, password, status)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->bind_param("ssssss", $fname, $mname, $lname, $email, $hashed_password, $status);

                if ($stmt->execute()) {
                    $message = 'Staff account added successfully.';
                    $message_type = 'success';
                } else {
                    $message = 'Failed to add staff account.';
                    $message_type = 'error';
                }
                $stmt->close();
            }
        }
    }

    if ($action === 'update') {
        $staff_id = intval($_POST['staff_id'] ?? 0);
        $fname = formatNamePart($_POST['fname'] ?? '');
        $mname = formatNamePart($_POST['mname'] ?? '');
        $lname = formatNamePart($_POST['lname'] ?? '');
        $email = strtolower(trim($_POST['email'] ?? ''));
        $status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';

        if ($staff_id <= 0 || $fname === '' || $lname === '' || $email === '') {
            $message = 'Please fill in all required staff fields.';
            $message_type = 'error';
        } elseif (!preg_match('/^[a-zA-Z\s]+$/', $fname)) {
            $message = 'First name should contain letters and spaces only.';
            $message_type = 'error';
        } elseif ($mname !== '' && !preg_match('/^[a-zA-Z\s]+$/', $mname)) {
            $message = 'Middle name should contain letters and spaces only.';
            $message_type = 'error';
        } elseif (!preg_match('/^[a-zA-Z\s]+$/', $lname)) {
            $message = 'Last name should contain letters and spaces only.';
            $message_type = 'error';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = 'Invalid email format.';
            $message_type = 'error';
        } else {
            $stmt = $conn->prepare("SELECT id FROM staff WHERE email = ? AND id != ? LIMIT 1");
            $stmt->bind_param("si", $email, $staff_id);
            $stmt->execute();
            $stmt->store_result();

            if ($stmt->num_rows > 0) {
                $message = 'Email already exists for another staff account.';
                $message_type = 'error';
                $stmt->close();
            } else {
                $stmt->close();

                $stmt = $conn->prepare("
                    UPDATE staff
                    SET fname = ?, mname = ?, lname = ?, email = ?, status = ?
                    WHERE id = ?
                ");
                $stmt->bind_param("sssssi", $fname, $mname, $lname, $email, $status, $staff_id);

                if ($stmt->execute()) {
                    $message = 'Staff account updated successfully.';
                    $message_type = 'success';
                } else {
                    $message = 'Failed to update staff account.';
                    $message_type = 'error';
                }
                $stmt->close();
            }
        }
    }

    if ($action === 'change_password') {
        $staff_id = intval($_POST['staff_id'] ?? 0);
        $new_password = $_POST['new_password'] ?? '';

        if ($staff_id <= 0 || $new_password === '') {
            $message = 'Please provide a new password.';
            $message_type = 'error';
        } elseif (!validPasswordRule($new_password)) {
            $message = 'Password must be at least 8 characters long and contain at least 1 uppercase letter and 1 special character.';
            $message_type = 'error';
        } else {
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

            $stmt = $conn->prepare("UPDATE staff SET password = ? WHERE id = ?");
            $stmt->bind_param("si", $hashed_password, $staff_id);

            if ($stmt->execute()) {
                $message = 'Staff password updated successfully.';
                $message_type = 'success';
            } else {
                $message = 'Failed to update staff password.';
                $message_type = 'error';
            }
            $stmt->close();
        }
    }

    if ($action === 'delete') {
        $staff_id = intval($_POST['staff_id'] ?? 0);

        if ($staff_id <= 0) {
            $message = 'Invalid staff account selected.';
            $message_type = 'error';
        } else {
            $stmt = $conn->prepare("SELECT id FROM staff WHERE id = ? LIMIT 1");
            $stmt->bind_param("i", $staff_id);
            $stmt->execute();
            $existing = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$existing) {
                $message = 'Staff account not found.';
                $message_type = 'error';
            } else {
                $stmt = $conn->prepare("DELETE FROM staff WHERE id = ?");
                $stmt->bind_param("i", $staff_id);

                if ($stmt->execute()) {
                    $message = 'Staff account deleted successfully.';
                    $message_type = 'success';
                } else {
                    $message = 'Failed to delete staff account.';
                    $message_type = 'error';
                }
                $stmt->close();
            }
        }
    }

    header("Location: admin_staff.php?msg=" . urlencode($message) . "&type=" . urlencode($message_type));
    exit();
}

/* FLASH MESSAGE */
if (isset($_GET['msg'])) {
    $message = trim($_GET['msg']);
    $message_type = trim($_GET['type'] ?? 'success');
}

/* FILTERS */
$search_filter = trim($_GET['search'] ?? '');
$status_filter = trim($_GET['status'] ?? '');
$sort_filter = trim($_GET['sort'] ?? 'newest');

$whereParts = [];
$params = [];
$types = '';

if ($search_filter !== '') {
    $whereParts[] = "(CONCAT(s.fname, ' ', COALESCE(s.mname, ''), ' ', s.lname) LIKE ? OR s.email LIKE ?)";
    $like = '%' . $search_filter . '%';
    $params[] = $like;
    $params[] = $like;
    $types .= 'ss';
}

if ($status_filter !== '') {
    $whereParts[] = "s.status = ?";
    $params[] = $status_filter;
    $types .= 's';
}

$whereSql = !empty($whereParts) ? 'WHERE ' . implode(' AND ', $whereParts) : '';

$orderSql = "ORDER BY s.created_at DESC";
if ($sort_filter === 'oldest') {
    $orderSql = "ORDER BY s.created_at ASC";
} elseif ($sort_filter === 'name_asc') {
    $orderSql = "ORDER BY s.lname ASC, s.fname ASC";
} elseif ($sort_filter === 'name_desc') {
    $orderSql = "ORDER BY s.lname DESC, s.fname DESC";
} elseif ($sort_filter === 'status_active') {
    $orderSql = "ORDER BY (s.status='active') DESC, s.lname ASC, s.fname ASC";
}

/* SUMMARY */
$summary = [
    'total_staff' => 0,
    'active_staff' => 0,
    'inactive_staff' => 0,
    'recent_staff' => 0
];

$summaryRes = $conn->query("
    SELECT
        COUNT(*) AS total_staff,
        SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active_staff,
        SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) AS inactive_staff,
        SUM(CASE WHEN DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS recent_staff
    FROM staff
");
if ($summaryRes) {
    $summaryRow = $summaryRes->fetch_assoc();
    $summary['total_staff'] = (int)($summaryRow['total_staff'] ?? 0);
    $summary['active_staff'] = (int)($summaryRow['active_staff'] ?? 0);
    $summary['inactive_staff'] = (int)($summaryRow['inactive_staff'] ?? 0);
    $summary['recent_staff'] = (int)($summaryRow['recent_staff'] ?? 0);
}

/* GET STAFF */
$staffSql = "
    SELECT s.*
    FROM staff s
    $whereSql
    $orderSql
";
$staffStmt = $conn->prepare($staffSql);
if (!empty($params)) {
    $staffStmt->bind_param($types, ...$params);
}
$staffStmt->execute();
$staffAccounts = $staffStmt->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Staff | <?= htmlspecialchars($shop['shop_name'] ?? 'Barbershop') ?></title>
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

        .admin-layout {
            display: flex;
            min-height: 100vh;
            width: 100%;
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
            width: 100%;
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
            border-left: 5px solid #2563eb;
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

        .panel h2 {
            margin: 0 0 16px;
            font-size: 22px;
        }

        .compact-form-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 14px;
        }

        .filter-grid {
            display: grid;
            grid-template-columns: 1.4fr 0.8fr 0.8fr auto auto;
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

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.12);
        }

        .toolbar-actions,
        .action-row,
        .modal-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .btn {
            border: none;
            border-radius: 12px;
            padding: 11px 16px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: 0.18s ease;
            font-size: 14px;
        }

        .btn:hover { transform: translateY(-1px); }

        .btn-primary { background: #2563eb; color: #fff; }
        .btn-secondary { background: #eef2f7; color: #111827; }
        .btn-update { background: #2563eb; color: #fff; }
        .btn-delete { background: #c62828; color: #fff; }
        .btn-view { background: #0f172a; color: #fff; }
        .btn-warning { background: #0b3d91; color: #fff; }

        .table-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            margin-bottom: 14px;
            flex-wrap: wrap;
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
            min-width: 980px;
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

        .staff-identity {
            min-width: 0;
        }

        .staff-meta strong {
            display: block;
            color: #111827;
            font-size: 15px;
            line-height: 1.35;
        }

        .staff-meta span,
        .muted {
            color: #6b7280;
            font-size: 13px;
        }

        .status-tag {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 6px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
        }

        .status-active {
            background: #dff6dd;
            color: #256029;
        }

        .status-inactive {
            background: #eee;
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

        .hidden-detail { display: none; }

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
            max-width: 980px;
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
            grid-template-columns: 300px minmax(0, 1fr);
            gap: 18px;
        }

        .profile-card,
        .details-card {
            background: #f8fafc;
            border: 1px solid #e8eef5;
            border-radius: 18px;
            padding: 18px;
        }

        .profile-card h4,
        .details-card h4 {
            margin: 0 0 12px;
            font-size: 18px;
            color: #111827;
        }

        .profile-card p,
        .details-card p {
            margin: 7px 0;
            line-height: 1.55;
        }

        .section-stack { display: grid; gap: 18px; }

        .warning-text {
            color: #991b1b;
            background: #fee2e2;
            border: 1px solid #fecaca;
            border-radius: 12px;
            padding: 12px 14px;
            font-size: 14px;
            margin-top: 10px;
        }

        @media (max-width: 1200px) {
            .stats-grid {
                grid-template-columns: repeat(2, minmax(160px, 1fr));
            }

            .details-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 950px) {
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
                width: 100%;
                padding: 18px;
            }

            .compact-form-grid,
            .filter-grid {
                grid-template-columns: 1fr;
            }

            .toolbar-actions {
                grid-column: 1 / -1;
            }
        }

        @media (max-width: 640px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }

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
            .modal-actions {
                flex-direction: column;
            }

            .admin-main {
                padding: 14px;
            }

            .modal-backdrop {
                padding: 10px;
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
            <a href="admin_staff.php" class="active">Staff</a>
            <a href="admin_reviews.php">Reviews</a>
            <a href="admin_reports.php">Reports</a>
            <a href="admin_ar_models.php">AR Hair Models</a>
            <a href="admin_settings.php">Settings</a>
            <a href="admin_logout.php">Logout</a>
        </nav>
    </aside>

    <main class="admin-main">
        <div class="page-header">
            <div>
                <h1>Manage Staff</h1>
                <p>Manage staff accounts, status, login access, and account details.</p>
            </div>
        </div>

        <?php if ($message !== ''): ?>
            <div class="alert <?= $message_type === 'success' ? 'alert-success' : 'alert-error' ?>">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <section class="stats-grid">
            <div class="stat-card">
                <h3>Total Staff</h3>
                <div class="value"><?= $summary['total_staff'] ?></div>
            </div>
            <div class="stat-card">
                <h3>Active</h3>
                <div class="value"><?= $summary['active_staff'] ?></div>
            </div>
            <div class="stat-card">
                <h3>Inactive</h3>
                <div class="value"><?= $summary['inactive_staff'] ?></div>
            </div>
            <div class="stat-card">
                <h3>Added in 30 Days</h3>
                <div class="value"><?= $summary['recent_staff'] ?></div>
            </div>
        </section>

        <section class="panel">
            <h2>Add New Staff</h2>
            <form method="POST">
                <input type="hidden" name="action" value="add">

                <div class="compact-form-grid">
                    <div class="form-group">
                        <label>First Name</label>
                        <input type="text" name="fname" required>
                    </div>

                    <div class="form-group">
                        <label>Middle Name</label>
                        <input type="text" name="mname">
                    </div>

                    <div class="form-group">
                        <label>Last Name</label>
                        <input type="text" name="lname" required>
                    </div>

                    <div class="form-group">
                        <label>Email</label>
                        <input type="email" name="email" required>
                    </div>

                    <div class="form-group">
                        <label>Password</label>
                        <input type="password" name="password" required>
                    </div>

                    <div class="form-group">
                        <label>Status</label>
                        <select name="status">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>

                <div class="muted" style="margin-top:8px;">
                    Password must be at least 8 characters long and include at least 1 uppercase letter and 1 special character.
                </div>

                <div class="action-row" style="margin-top:16px;">
                    <button type="submit" class="btn btn-primary">Add Staff</button>
                </div>
            </form>
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
                        placeholder="Search by name or email"
                    >
                </div>

                <div class="form-group">
                    <label for="status">Status</label>
                    <select name="status" id="status">
                        <option value="">All Status</option>
                        <option value="active" <?= $status_filter === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="inactive" <?= $status_filter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="sort">Sort By</label>
                    <select name="sort" id="sort">
                        <option value="newest" <?= $sort_filter === 'newest' ? 'selected' : '' ?>>Newest</option>
                        <option value="oldest" <?= $sort_filter === 'oldest' ? 'selected' : '' ?>>Oldest</option>
                        <option value="name_asc" <?= $sort_filter === 'name_asc' ? 'selected' : '' ?>>Name A-Z</option>
                        <option value="name_desc" <?= $sort_filter === 'name_desc' ? 'selected' : '' ?>>Name Z-A</option>
                        <option value="status_active" <?= $sort_filter === 'status_active' ? 'selected' : '' ?>>Active First</option>
                    </select>
                </div>

                <div class="toolbar-actions">
                    <button type="submit" class="btn btn-primary">Apply Filters</button>
                    <a href="admin_staff.php" class="btn btn-secondary">Reset</a>
                </div>
            </form>
        </section>

        <section class="panel">
            <div class="table-head">
                <div>
                    <h2>Staff Directory</h2>
                    <p>Open a staff record to review account details, edit information, and change password.</p>
                </div>
            </div>

            <?php if ($staffAccounts && $staffAccounts->num_rows > 0): ?>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Staff</th>
                                <th>Email</th>
                                <th>Status</th>
                                <th>Created</th>
                                <th>Reset Token</th>
                                <th style="width: 140px;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($staff = $staffAccounts->fetch_assoc()): ?>
                                <?php
                                $staffId = (int)$staff['id'];
                                $fullName = trim(
                                    $staff['fname'] .
                                    ($staff['mname'] ? ' ' . $staff['mname'] : '') .
                                    ' ' . $staff['lname']
                                );

                                ob_start();
                                ?>
                                <div class="details-grid">
                                    <div class="profile-card">
                                        <h4><?= htmlspecialchars($fullName) ?></h4>
                                        <p><strong>First Name:</strong> <?= htmlspecialchars($staff['fname']) ?></p>
                                        <p><strong>Middle Name:</strong> <?= htmlspecialchars($staff['mname'] ?: '—') ?></p>
                                        <p><strong>Last Name:</strong> <?= htmlspecialchars($staff['lname']) ?></p>
                                        <p><strong>Email:</strong> <?= htmlspecialchars($staff['email']) ?></p>
                                        <p>
                                            <strong>Status:</strong>
                                            <span class="status-tag <?= $staff['status'] === 'active' ? 'status-active' : 'status-inactive' ?>">
                                                <?= htmlspecialchars(ucfirst($staff['status'])) ?>
                                            </span>
                                        </p>
                                        <p><strong>Created At:</strong> <?= date("F j, Y g:i A", strtotime($staff['created_at'])) ?></p>
                                        <p><strong>Reset Token:</strong> <?= !empty($staff['reset_token']) ? htmlspecialchars($staff['reset_token']) : 'None' ?></p>
                                        <p><strong>Reset Expiry:</strong> <?= !empty($staff['reset_expiry']) ? date("F j, Y g:i A", strtotime($staff['reset_expiry'])) : 'None' ?></p>

                                        <button
                                            type="button"
                                            class="btn btn-delete open-delete-modal"
                                            data-staff-id="<?= $staffId ?>"
                                            data-staff-name="<?= htmlspecialchars($fullName, ENT_QUOTES) ?>"
                                            style="margin-top:16px;"
                                        >
                                            Delete Staff
                                        </button>
                                    </div>

                                    <div class="section-stack">
                                        <div class="details-card">
                                            <h4>Edit Staff</h4>
                                            <form method="POST">
                                                <input type="hidden" name="action" value="update">
                                                <input type="hidden" name="staff_id" value="<?= $staffId ?>">

                                                <div class="compact-form-grid" style="grid-template-columns:repeat(2,minmax(0,1fr));">
                                                    <div class="form-group">
                                                        <label>First Name</label>
                                                        <input type="text" name="fname" value="<?= htmlspecialchars($staff['fname']) ?>" required>
                                                    </div>

                                                    <div class="form-group">
                                                        <label>Middle Name</label>
                                                        <input type="text" name="mname" value="<?= htmlspecialchars($staff['mname']) ?>">
                                                    </div>

                                                    <div class="form-group">
                                                        <label>Last Name</label>
                                                        <input type="text" name="lname" value="<?= htmlspecialchars($staff['lname']) ?>" required>
                                                    </div>

                                                    <div class="form-group">
                                                        <label>Email</label>
                                                        <input type="email" name="email" value="<?= htmlspecialchars($staff['email']) ?>" required>
                                                    </div>

                                                    <div class="form-group">
                                                        <label>Status</label>
                                                        <select name="status">
                                                            <option value="active" <?= $staff['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                                                            <option value="inactive" <?= $staff['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                                                        </select>
                                                    </div>
                                                </div>

                                                <div class="action-row" style="margin-top:16px;">
                                                    <button type="submit" class="btn btn-update">Save Changes</button>
                                                </div>
                                            </form>
                                        </div>

                                        <div class="details-card">
                                            <h4>Change Password</h4>
                                            <form method="POST">
                                                <input type="hidden" name="action" value="change_password">
                                                <input type="hidden" name="staff_id" value="<?= $staffId ?>">

                                                <div class="form-group">
                                                    <label>New Password</label>
                                                    <input type="password" name="new_password" placeholder="Enter new password" required>
                                                </div>

                                                <div class="muted" style="margin-top:8px;">
                                                    Password must be at least 8 characters long and include at least 1 uppercase letter and 1 special character.
                                                </div>

                                                <div class="action-row" style="margin-top:16px;">
                                                    <button type="submit" class="btn btn-warning">Update Password</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                                <?php
                                $modalContent = ob_get_clean();
                                ?>
                                <tr>
                                    <td>
                                        <div class="staff-identity">
                                            <div class="staff-meta">
                                                <strong><?= htmlspecialchars($fullName) ?></strong>
                                                <span>Staff Account</span>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="muted"><?= htmlspecialchars($staff['email']) ?></div>
                                    </td>
                                    <td>
                                        <span class="status-tag <?= $staff['status'] === 'active' ? 'status-active' : 'status-inactive' ?>">
                                            <?= htmlspecialchars(ucfirst($staff['status'])) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="muted"><?= date("M j, Y", strtotime($staff['created_at'])) ?></div>
                                    </td>
                                    <td>
                                        <div class="muted">
                                            <?= !empty($staff['reset_token']) ? 'Available' : 'None' ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="action-row">
                                            <button
                                                type="button"
                                                class="btn btn-view open-details-modal"
                                                data-title="<?= htmlspecialchars($fullName, ENT_QUOTES) ?>"
                                                data-subtitle="<?= htmlspecialchars('Staff Account • ' . ucfirst($staff['status']), ENT_QUOTES) ?>"
                                                data-content-id="staff-detail-<?= $staffId ?>"
                                            >
                                                View Details
                                            </button>
                                        </div>

                                        <div class="hidden-detail" id="staff-detail-<?= $staffId ?>">
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
                    <h3 style="margin-top:0; color:#111827;">No staff accounts found</h3>
                    <p>Try changing your filters or add a new staff account above.</p>
                </div>
            <?php endif; ?>
        </section>
    </main>
</div>

<div class="modal-backdrop" id="staffDetailsModal">
    <div class="modal">
        <div class="modal-header">
            <h3 id="staffModalTitle">Staff Details</h3>
            <p id="staffModalSubtitle"></p>
            <button type="button" class="modal-close" data-close-modal="staffDetailsModal">&times;</button>
        </div>
        <div class="modal-body" id="staffModalBody"></div>
    </div>
</div>

<div class="modal-backdrop" id="deleteStaffModal">
    <div class="modal modal-sm">
        <div class="modal-header">
            <h3>Delete Staff Account</h3>
            <p id="deleteStaffSubtitle">This action will permanently remove the selected staff account.</p>
            <button type="button" class="modal-close" data-close-modal="deleteStaffModal">&times;</button>
        </div>
        <div class="modal-body">
            <form method="POST">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="staff_id" id="deleteStaffId">

                <div class="warning-text">
                    Are you sure you want to delete <strong id="deleteStaffName">this staff account</strong>? This action cannot be undone.
                </div>

                <div class="modal-actions" style="margin-top:18px;">
                    <button type="submit" class="btn btn-delete">Yes, Delete</button>
                    <button type="button" class="btn btn-secondary" data-close-modal="deleteStaffModal">Cancel</button>
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
    const adminSidebar = document.getElementById('adminSidebar');
    const sidebarOverlay = document.getElementById('sidebarOverlay');
    adminSidebar.classList.add('show');
    sidebarOverlay.classList.add('show');
    document.body.classList.add('sidebar-open');
}

function closeSidebar() {
    const adminSidebar = document.getElementById('adminSidebar');
    const sidebarOverlay = document.getElementById('sidebarOverlay');
    adminSidebar.classList.remove('show');
    sidebarOverlay.classList.remove('show');
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
        document.getElementById('staffModalTitle').textContent = detailBtn.getAttribute('data-title') || 'Staff Details';
        document.getElementById('staffModalSubtitle').textContent = detailBtn.getAttribute('data-subtitle') || '';
        document.getElementById('staffModalBody').innerHTML = contentNode ? contentNode.innerHTML : '';
        openModal('staffDetailsModal');
        return;
    }

    const deleteBtn = e.target.closest('.open-delete-modal');
    if (deleteBtn) {
        const staffId = deleteBtn.getAttribute('data-staff-id') || '';
        const staffName = deleteBtn.getAttribute('data-staff-name') || 'this staff account';

        document.getElementById('deleteStaffId').value = staffId;
        document.getElementById('deleteStaffName').textContent = staffName;
        document.getElementById('deleteStaffSubtitle').textContent =
            'You are about to permanently delete the account of ' + staffName + '.';

        openModal('deleteStaffModal');
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
    menuBtn.addEventListener('click', function () {
        openSidebar();
    });
}

if (sidebarOverlay) {
    sidebarOverlay.addEventListener('click', function () {
        closeSidebar();
    });
}

window.addEventListener('resize', function () {
    if (window.innerWidth > 950) {
        closeSidebar();
    }
});
</script>

</body>
</html>