<?php
session_start();
include 'db.php';

if (!isset($_SESSION['admin_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: admin_login.php");
    exit();
}

/* SHOP SETTINGS */
$settings = $conn->query("SELECT * FROM settings LIMIT 1");
$shop = $settings ? $settings->fetch_assoc() : null;

$message = '';
$message_type = '';

function formatServiceName($value) {
    $value = trim($value);
    $value = preg_replace('/\s+/', ' ', $value);
    return ucwords(strtolower($value));
}

/* HANDLE FORM ACTIONS */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    /* ADD SERVICE */
    if ($action === 'add') {
        $name = formatServiceName($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $price = floatval($_POST['price'] ?? 0);
        $duration = intval($_POST['duration_minutes'] ?? 0);
        $is_optional = isset($_POST['is_optional']) ? 1 : 0;

        if ($name === '' || $price <= 0 || $duration <= 0) {
            $message = 'Please fill in all required fields correctly.';
            $message_type = 'error';
        } else {
            $check = $conn->prepare("SELECT id FROM services WHERE LOWER(name) = LOWER(?) LIMIT 1");
            $check->bind_param("s", $name);
            $check->execute();
            $check->store_result();

            if ($check->num_rows > 0) {
                $message = 'A service with this name already exists.';
                $message_type = 'error';
                $check->close();
            } else {
                $check->close();

                $stmt = $conn->prepare("
                    INSERT INTO services (name, description, price, duration_minutes)
                    VALUES (?, ?, ?, ?)
                ");
                $stmt->bind_param("ssdi", $name, $description, $price, $duration);

                if ($stmt->execute()) {
                    $service_id = $stmt->insert_id;
                    $stmt->close();

                    if ($is_optional) {
                        $stmt = $conn->prepare("
                            INSERT INTO optional_services (service_id)
                            VALUES (?)
                        ");
                        $stmt->bind_param("i", $service_id);
                        $stmt->execute();
                        $stmt->close();
                    }

                    $message = 'Service added successfully.';
                    $message_type = 'success';
                } else {
                    $message = 'Failed to add service.';
                    $message_type = 'error';
                    $stmt->close();
                }
            }
        }
    }

    /* UPDATE SERVICE */
    if ($action === 'update') {
        $service_id = intval($_POST['service_id'] ?? 0);
        $name = formatServiceName($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $price = floatval($_POST['price'] ?? 0);
        $duration = intval($_POST['duration_minutes'] ?? 0);
        $is_optional = isset($_POST['is_optional']) ? 1 : 0;

        if ($service_id <= 0 || $name === '' || $price <= 0 || $duration <= 0) {
            $message = 'Please fill in all required fields correctly.';
            $message_type = 'error';
        } else {
            $check = $conn->prepare("SELECT id FROM services WHERE LOWER(name) = LOWER(?) AND id != ? LIMIT 1");
            $check->bind_param("si", $name, $service_id);
            $check->execute();
            $check->store_result();

            if ($check->num_rows > 0) {
                $message = 'Another service with this name already exists.';
                $message_type = 'error';
                $check->close();
            } else {
                $check->close();

                $stmt = $conn->prepare("
                    UPDATE services
                    SET name = ?, description = ?, price = ?, duration_minutes = ?
                    WHERE id = ?
                ");
                $stmt->bind_param("ssdii", $name, $description, $price, $duration, $service_id);

                if ($stmt->execute()) {
                    $stmt->close();

                    $stmt = $conn->prepare("SELECT id FROM optional_services WHERE service_id = ?");
                    $stmt->bind_param("i", $service_id);
                    $stmt->execute();
                    $optionalResult = $stmt->get_result();
                    $alreadyOptional = $optionalResult->num_rows > 0;
                    $stmt->close();

                    if ($is_optional && !$alreadyOptional) {
                        $stmt = $conn->prepare("
                            INSERT INTO optional_services (service_id)
                            VALUES (?)
                        ");
                        $stmt->bind_param("i", $service_id);
                        $stmt->execute();
                        $stmt->close();
                    }

                    if (!$is_optional && $alreadyOptional) {
                        $stmt = $conn->prepare("
                            DELETE FROM optional_services
                            WHERE service_id = ?
                        ");
                        $stmt->bind_param("i", $service_id);
                        $stmt->execute();
                        $stmt->close();
                    }

                    $message = 'Service updated successfully.';
                    $message_type = 'success';
                } else {
                    $message = 'Failed to update service.';
                    $message_type = 'error';
                    $stmt->close();
                }
            }
        }
    }

    /* DELETE SERVICE */
    if ($action === 'delete') {
        $service_id = intval($_POST['service_id'] ?? 0);

        if ($service_id > 0) {
            $stmt = $conn->prepare("
                SELECT COUNT(*) AS total
                FROM appointment_services
                WHERE service_id = ?
            ");
            $stmt->bind_param("i", $service_id);
            $stmt->execute();
            $appointmentCount = intval($stmt->get_result()->fetch_assoc()['total'] ?? 0);
            $stmt->close();

            $stmt = $conn->prepare("
                SELECT COUNT(*) AS total
                FROM walkin_services
                WHERE service_id = ?
            ");
            $stmt->bind_param("i", $service_id);
            $stmt->execute();
            $walkinCount = intval($stmt->get_result()->fetch_assoc()['total'] ?? 0);
            $stmt->close();

            if ($appointmentCount > 0 || $walkinCount > 0) {
                $message = 'This service cannot be deleted because it is already used in appointments or walk-ins.';
                $message_type = 'error';
            } else {
                $conn->begin_transaction();

                try {
                    $stmt = $conn->prepare("DELETE FROM optional_services WHERE service_id = ?");
                    $stmt->bind_param("i", $service_id);
                    $stmt->execute();
                    $stmt->close();

                    $stmt = $conn->prepare("DELETE FROM services WHERE id = ?");
                    $stmt->bind_param("i", $service_id);
                    $stmt->execute();
                    $stmt->close();

                    $conn->commit();
                    $message = 'Service deleted successfully.';
                    $message_type = 'success';
                } catch (Exception $e) {
                    $conn->rollback();
                    $message = 'Failed to delete service.';
                    $message_type = 'error';
                }
            }
        }
    }

    header("Location: admin_services.php?msg=" . urlencode($message) . "&type=" . urlencode($message_type));
    exit();
}

/* FLASH */
if (isset($_GET['msg'])) {
    $message = trim($_GET['msg']);
    $message_type = trim($_GET['type'] ?? 'success');
}

/* FILTERS */
$search_filter = trim($_GET['search'] ?? '');
$type_filter = trim($_GET['type_filter'] ?? '');
$sort_filter = trim($_GET['sort'] ?? 'name_asc');

$whereParts = [];
$params = [];
$types = '';

if ($search_filter !== '') {
    $whereParts[] = "(s.name LIKE ? OR s.description LIKE ?)";
    $like = '%' . $search_filter . '%';
    $params[] = $like;
    $params[] = $like;
    $types .= 'ss';
}

if ($type_filter === 'main') {
    $whereParts[] = "os.service_id IS NULL";
}

if ($type_filter === 'optional') {
    $whereParts[] = "os.service_id IS NOT NULL";
}

$whereSql = !empty($whereParts) ? 'WHERE ' . implode(' AND ', $whereParts) : '';

$orderSql = "ORDER BY s.name ASC";
if ($sort_filter === 'name_desc') {
    $orderSql = "ORDER BY s.name DESC";
} elseif ($sort_filter === 'price_asc') {
    $orderSql = "ORDER BY s.price ASC";
} elseif ($sort_filter === 'price_desc') {
    $orderSql = "ORDER BY s.price DESC";
} elseif ($sort_filter === 'duration_asc') {
    $orderSql = "ORDER BY s.duration_minutes ASC";
} elseif ($sort_filter === 'duration_desc') {
    $orderSql = "ORDER BY s.duration_minutes DESC";
}

/* SUMMARY */
$summary = [
    'total_services' => 0,
    'main_services' => 0,
    'optional_services' => 0
];

$summaryRes = $conn->query("
    SELECT 
        COUNT(*) AS total_services
    FROM services
");
if ($summaryRes) {
    $summary['total_services'] = (int)($summaryRes->fetch_assoc()['total_services'] ?? 0);
}

$mainRes = $conn->query("
    SELECT COUNT(*) AS total_main
    FROM services s
    LEFT JOIN optional_services os ON s.id = os.service_id
    WHERE os.service_id IS NULL
");
if ($mainRes) {
    $summary['main_services'] = (int)($mainRes->fetch_assoc()['total_main'] ?? 0);
}

$optionalRes = $conn->query("SELECT COUNT(*) AS total_optional FROM optional_services");
if ($optionalRes) {
    $summary['optional_services'] = (int)($optionalRes->fetch_assoc()['total_optional'] ?? 0);
}

/* GET SERVICES */
$sql = "
    SELECT 
        s.*,
        CASE WHEN os.service_id IS NOT NULL THEN 1 ELSE 0 END AS is_optional
    FROM services s
    LEFT JOIN optional_services os ON s.id = os.service_id
    $whereSql
    $orderSql
";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$services = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Services | <?= htmlspecialchars($shop['shop_name'] ?? 'Barbershop') ?></title>
    <link rel="stylesheet" href="styles.css">
    <style>
        * { box-sizing: border-box; }

        :root {
            --fs-blue: #0b3d91;
            --fs-blue-dark: #061f4a;
            --fs-blue-soft: #eaf1ff;
            --fs-navy: #07172f;
            --fs-bg: #f7f9fc;
            --fs-line: #dbe3ef;
            --fs-muted: #6b7280;
            --fs-danger: #b91c1c;
        }

        body {
            margin: 0;
            font-family: 'Poppins', sans-serif;
            background: #f7f9fc;
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
            background: #0b3d91;
            box-shadow: 0 8px 18px rgba(11, 61, 145, 0.22);
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
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .page-header h1 {
            margin: 0 0 6px;
            font-size: 30px;
            color: #07172f;
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
            grid-template-columns: repeat(3, minmax(160px, 1fr));
            gap: 14px;
            margin-bottom: 18px;
        }

        .stat-card {
            background: #fff;
            border-radius: 18px;
            padding: 18px;
            box-shadow: 0 8px 24px rgba(15, 23, 42, 0.06);
            border: 1px solid #edf2f7;
            border-left: 5px solid #0b3d91;
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

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
        }

        .filter-grid {
            display: grid;
            grid-template-columns: minmax(280px, 1.6fr) minmax(180px, 0.85fr) minmax(200px, 0.95fr) auto auto;
            gap: 14px;
            align-items: end;
        }

        .filter-grid .form-group {
            margin-bottom: 0;
        }

        .filter-grid .btn {
            height: 46px;
            min-width: 145px;
            white-space: nowrap;
            align-self: end;
        }

        .form-group {
            margin-bottom: 14px;
        }

        .form-group label {
            display: block;
            margin-bottom: 6px;
            font-weight: 600;
            color: #374151;
            font-size: 13px;
        }

        .form-group input,
        .form-group textarea,
        .form-group select {
            width: 100%;
            padding: 11px 13px;
            border: 1px solid #d1d9e6;
            border-radius: 12px;
            font-size: 14px;
            outline: none;
            background: #fff;
        }

        .form-group input:focus,
        .form-group textarea:focus,
        .form-group select:focus {
            border-color: #0b3d91;
            box-shadow: 0 0 0 4px rgba(11, 61, 145, 0.10);
        }

        .form-group textarea {
            min-height: 110px;
            resize: vertical;
        }

        .checkbox-row {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 16px;
        }

        .checkbox-row input[type="checkbox"] {
            width: auto;
            margin: 0;
            accent-color: #0b3d91;
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

        .btn-primary {
            background: #0b3d91;
            color: #fff;
        }

        .btn-primary:hover {
            background: #061f4a;
        }

        .btn-update {
            background: #2563eb;
            color: #fff;
        }

        .btn-update:hover {
            background: #1d4ed8;
        }

        .btn-delete {
            background: #c62828;
            color: #fff;
        }

        .btn-secondary {
            background: #eef2f7;
            color: #111827;
        }

        .btn-view {
            background: #07172f;
            color: #fff;
        }

        .btn-view:hover {
            background: #0b3d91;
        }

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

        .service-name strong {
            display: block;
            color: #111827;
            font-size: 15px;
        }

        .service-name span {
            color: #6b7280;
            font-size: 13px;
        }

        .tag {
            display: inline-flex;
            padding: 6px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
        }

        .tag-duration {
            background: #e0f2fe;
            color: #075985;
        }

        .tag-main {
            background: #dbeafe;
            color: #1d4ed8;
        }

        .tag-optional {
            background: #fef3c7;
            color: #92400e;
        }

        .description-preview {
            max-width: 280px;
            white-space: normal;
            word-break: break-word;
            line-height: 1.5;
            color: #555;
        }

        .empty-state {
            background: #fff;
            padding: 30px;
            border-radius: 18px;
            text-align: center;
            color: #666;
            box-shadow: 0 4px 14px rgba(0,0,0,0.08);
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
            max-width: 860px;
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

            .filter-grid .btn {
                min-width: 160px;
                width: auto;
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

            .form-grid,
            .filter-grid,
            .stats-grid {
                grid-template-columns: 1fr;
            }

            .filter-grid .btn {
                width: 100%;
                min-width: 0;
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
            .modal-actions {
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
            <a href="admin_services.php" class="active">Services</a>
            <a href="admin_barbers.php">Barbers</a>
            <a href="admin_staff.php">Staff</a>
            <a href="admin_reviews.php">Reviews</a>
            <a href="admin_reports.php">Reports</a>
            <a href="admin_ar_models.php">AR Hair Models</a>
            <a href="admin_settings.php">Settings</a>
            <a href="logout.php">Logout</a>
        </nav>
    </aside>

    <main class="admin-main">
        <div class="page-header">
            <div>
                <h1>Manage Services</h1>
                <p>Add, edit, and organize the barbershop services you offer.</p>
            </div>
        </div>

        <?php if ($message !== ''): ?>
            <div class="alert <?= $message_type === 'success' ? 'alert-success' : 'alert-error' ?>">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <section class="stats-grid">
            <div class="stat-card">
                <h3>Total Services</h3>
                <div class="value"><?= $summary['total_services'] ?></div>
            </div>
            <div class="stat-card">
                <h3>Main Services</h3>
                <div class="value"><?= $summary['main_services'] ?></div>
            </div>
            <div class="stat-card">
                <h3>Optional / Add-ons</h3>
                <div class="value"><?= $summary['optional_services'] ?></div>
            </div>
        </section>

        <section class="panel">
            <h2>Add New Service</h2>

            <form method="POST">
                <input type="hidden" name="action" value="add">

                <div class="form-grid">
                    <div class="form-group">
                        <label>Service Name</label>
                        <input type="text" name="name" required>
                    </div>

                    <div class="form-group">
                        <label>Price</label>
                        <input type="number" name="price" step="0.01" min="0.01" required>
                    </div>

                    <div class="form-group">
                        <label>Duration (minutes)</label>
                        <input type="number" name="duration_minutes" min="1" required value="30">
                    </div>

                    <div class="form-group">
                        <label>Type</label>
                        <select name="type_selector" onchange="document.getElementById('is_optional_add').checked = (this.value === 'optional');">
                            <option value="main">Main Service</option>
                            <option value="optional">Optional / Add-on</option>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" placeholder="Enter service description"></textarea>
                </div>

                <div class="checkbox-row" style="display:none;">
                    <input type="checkbox" name="is_optional" id="is_optional_add">
                    <label for="is_optional_add">Mark as Optional / Add-on Service</label>
                </div>

                <div class="action-row">
                    <button type="submit" class="btn btn-primary">Add Service</button>
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
                        placeholder="Search by service name or description"
                    >
                </div>

                <div class="form-group">
                    <label for="type_filter">Type</label>
                    <select name="type_filter" id="type_filter">
                        <option value="">All Types</option>
                        <option value="main" <?= $type_filter === 'main' ? 'selected' : '' ?>>Main Service</option>
                        <option value="optional" <?= $type_filter === 'optional' ? 'selected' : '' ?>>Optional / Add-on</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="sort">Sort By</label>
                    <select name="sort" id="sort">
                        <option value="name_asc" <?= $sort_filter === 'name_asc' ? 'selected' : '' ?>>Name A-Z</option>
                        <option value="name_desc" <?= $sort_filter === 'name_desc' ? 'selected' : '' ?>>Name Z-A</option>
                        <option value="price_asc" <?= $sort_filter === 'price_asc' ? 'selected' : '' ?>>Price Low to High</option>
                        <option value="price_desc" <?= $sort_filter === 'price_desc' ? 'selected' : '' ?>>Price High to Low</option>
                        <option value="duration_asc" <?= $sort_filter === 'duration_asc' ? 'selected' : '' ?>>Duration Low to High</option>
                        <option value="duration_desc" <?= $sort_filter === 'duration_desc' ? 'selected' : '' ?>>Duration High to Low</option>
                    </select>
                </div>

                <button type="submit" class="btn btn-primary">Apply Filters</button>
                <a href="admin_services.php" class="btn btn-secondary">Reset</a>
            </form>
        </section>

        <section class="panel">
            <div class="table-head">
                <div>
                    <h2>Services Directory</h2>
                    <p>Use the compact table view to manage services without taking too much screen space.</p>
                </div>
            </div>

            <?php if ($services && $services->num_rows > 0): ?>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Service</th>
                                <th>Type</th>
                                <th>Duration</th>
                                <th>Price</th>
                                <th>Description</th>
                                <th style="width: 140px;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($service = $services->fetch_assoc()): ?>
                                <?php
                                ob_start();
                                ?>
                                <div class="details-grid">
                                    <div class="details-card">
                                        <h4>Service Overview</h4>
                                        <p><strong>Name:</strong> <?= htmlspecialchars($service['name']) ?></p>
                                        <p>
                                            <strong>Type:</strong>
                                            <?php if ((int)$service['is_optional'] === 1): ?>
                                                <span class="tag tag-optional">Optional / Add-on</span>
                                            <?php else: ?>
                                                <span class="tag tag-main">Main Service</span>
                                            <?php endif; ?>
                                        </p>
                                        <p><strong>Duration:</strong> <?= (int)$service['duration_minutes'] ?> minutes</p>
                                        <p><strong>Price:</strong> ₱<?= number_format($service['price'], 2) ?></p>
                                        <p><strong>Description:</strong><br><?= nl2br(htmlspecialchars($service['description'] ?? 'No description provided.')) ?></p>
                                    </div>

                                    <div class="details-card">
                                        <h4>Edit Service</h4>
                                        <form method="POST">
                                            <input type="hidden" name="action" value="update">
                                            <input type="hidden" name="service_id" value="<?= $service['id'] ?>">

                                            <div class="form-group">
                                                <label>Service Name</label>
                                                <input type="text" name="name" value="<?= htmlspecialchars($service['name']) ?>" required>
                                            </div>

                                            <div class="form-group">
                                                <label>Description</label>
                                                <textarea name="description"><?= htmlspecialchars($service['description']) ?></textarea>
                                            </div>

                                            <div class="form-grid">
                                                <div class="form-group">
                                                    <label>Price</label>
                                                    <input type="number" name="price" step="0.01" min="0.01" value="<?= htmlspecialchars($service['price']) ?>" required>
                                                </div>

                                                <div class="form-group">
                                                    <label>Duration (minutes)</label>
                                                    <input type="number" name="duration_minutes" min="1" value="<?= (int)$service['duration_minutes'] ?>" required>
                                                </div>
                                            </div>

                                            <div class="checkbox-row">
                                                <input
                                                    type="checkbox"
                                                    name="is_optional"
                                                    id="optional_<?= $service['id'] ?>"
                                                    <?= (int)$service['is_optional'] === 1 ? 'checked' : '' ?>
                                                >
                                                <label for="optional_<?= $service['id'] ?>">Optional / Add-on Service</label>
                                            </div>

                                            <div class="modal-actions">
                                                <button type="submit" class="btn btn-update">Save Changes</button>
                                            </div>
                                        </form>

                                        <div class="modal-actions" style="margin-top:14px;">
                                            <button
                                                type="button"
                                                class="btn btn-delete open-delete-modal"
                                                data-service-id="<?= (int)$service['id'] ?>"
                                                data-service-name="<?= htmlspecialchars($service['name'], ENT_QUOTES) ?>"
                                            >
                                                Delete Service
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                <?php
                                $modalContent = ob_get_clean();
                                ?>
                                <tr>
                                    <td class="service-name">
                                        <strong><?= htmlspecialchars($service['name']) ?></strong>
                                    </td>
                                    <td>
                                        <?php if ((int)$service['is_optional'] === 1): ?>
                                            <span class="tag tag-optional">Optional / Add-on</span>
                                        <?php else: ?>
                                            <span class="tag tag-main">Main Service</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="tag tag-duration"><?= (int)$service['duration_minutes'] ?> mins</span>
                                    </td>
                                    <td>₱<?= number_format($service['price'], 2) ?></td>
                                    <td>
                                        <div class="description-preview">
                                            <?= htmlspecialchars(mb_strimwidth($service['description'] ?: 'No description provided.', 0, 90, '...')) ?>
                                        </div>
                                    </td>
                                    <td>
                                        <button
                                            type="button"
                                            class="btn btn-view open-details-modal"
                                            data-title="<?= htmlspecialchars($service['name'], ENT_QUOTES) ?>"
                                            data-subtitle="<?= htmlspecialchars(((int)$service['is_optional'] === 1 ? 'Optional / Add-on' : 'Main Service') . ' • ₱' . number_format($service['price'], 2), ENT_QUOTES) ?>"
                                            data-content-id="service-detail-<?= $service['id'] ?>"
                                        >
                                            View / Edit
                                        </button>

                                        <div class="hidden-detail" id="service-detail-<?= $service['id'] ?>">
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
                    No services found.
                </div>
            <?php endif; ?>
        </section>
    </main>

</div>

<div class="modal-backdrop" id="serviceDetailsModal">
    <div class="modal">
        <div class="modal-header">
            <h3 id="serviceModalTitle">Service Details</h3>
            <p id="serviceModalSubtitle"></p>
            <button type="button" class="modal-close" data-close-modal="serviceDetailsModal">&times;</button>
        </div>
        <div class="modal-body" id="serviceModalBody"></div>
    </div>
</div>

<div class="modal-backdrop" id="deleteServiceModal">
    <div class="modal modal-sm">
        <div class="modal-header">
            <h3>Delete Service</h3>
            <p id="deleteServiceSubtitle">This action will permanently remove the selected service.</p>
            <button type="button" class="modal-close" data-close-modal="deleteServiceModal">&times;</button>
        </div>
        <div class="modal-body">
            <form method="POST">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="service_id" id="deleteServiceId">

                <div class="warning-text">
                    Are you sure you want to delete <strong id="deleteServiceName">this service</strong>? This action cannot be undone.
                </div>

                <div class="modal-actions" style="margin-top:18px;">
                    <button type="submit" class="btn btn-delete">Yes, Delete</button>
                    <button type="button" class="btn btn-secondary" data-close-modal="deleteServiceModal">Cancel</button>
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
        document.getElementById('serviceModalTitle').textContent = detailBtn.getAttribute('data-title') || 'Service Details';
        document.getElementById('serviceModalSubtitle').textContent = detailBtn.getAttribute('data-subtitle') || '';
        document.getElementById('serviceModalBody').innerHTML = contentNode ? contentNode.innerHTML : '';
        openModal('serviceDetailsModal');
        return;
    }

    const deleteBtn = e.target.closest('.open-delete-modal');
    if (deleteBtn) {
        const serviceId = deleteBtn.getAttribute('data-service-id') || '';
        const serviceName = deleteBtn.getAttribute('data-service-name') || 'this service';

        document.getElementById('deleteServiceId').value = serviceId;
        document.getElementById('deleteServiceName').textContent = serviceName;
        document.getElementById('deleteServiceSubtitle').textContent =
            'You are about to permanently delete the service "' + serviceName + '".';

        openModal('deleteServiceModal');
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