<?php
session_start();
include 'db.php';

if (!isset($_SESSION['admin_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: admin_login.php");
    exit();
}

/* SHOP SETTINGS */
$settings = $conn->query("SELECT * FROM settings LIMIT 1");
$shop = $settings ? $settings->fetch_assoc() : null;

/* CURRENT MONTH RANGE */
$currentMonthStart = date('Y-m-01');
$currentMonthEnd   = date('Y-m-t');
$currentMonthLabel = date('F Y');

/* DEFAULT VALUES */
$totalAppointments = 0;
$pendingAppointments = 0;
$approvedAppointments = 0;
$completedAppointments = 0;
$rejectedAppointments = 0;
$totalCustomers = 0;
$totalBarbers = 0;
$totalServices = 0;

$totalWalkins = 0;
$waitingWalkins = 0;
$ongoingWalkins = 0;
$completedWalkins = 0;

/* MONTHLY HIGHLIGHTS */
$monthAppointmentRevenue = 0;
$monthWalkinRevenue = 0;
$monthNoShowRevenue = 0;
$monthTotalRevenue = 0;
$monthTopServices = [];
$monthTopBarbers = [];

/* APPOINTMENT COUNTS */
$res = $conn->query("SELECT COUNT(*) AS total FROM appointments");
if ($res) $totalAppointments = (int)($res->fetch_assoc()['total'] ?? 0);

$res = $conn->query("SELECT COUNT(*) AS total FROM appointments WHERE status = 'pending approval'");
if ($res) $pendingAppointments = (int)($res->fetch_assoc()['total'] ?? 0);

$res = $conn->query("SELECT COUNT(*) AS total FROM appointments WHERE status = 'approved'");
if ($res) $approvedAppointments = (int)($res->fetch_assoc()['total'] ?? 0);

$res = $conn->query("SELECT COUNT(*) AS total FROM appointments WHERE status = 'completed'");
if ($res) $completedAppointments = (int)($res->fetch_assoc()['total'] ?? 0);

$res = $conn->query("SELECT COUNT(*) AS total FROM appointments WHERE status = 'rejected'");
if ($res) $rejectedAppointments = (int)($res->fetch_assoc()['total'] ?? 0);

/* OTHER COUNTS */
$res = $conn->query("SELECT COUNT(*) AS total FROM users");
if ($res) $totalCustomers = (int)($res->fetch_assoc()['total'] ?? 0);

$res = $conn->query("SELECT COUNT(*) AS total FROM barbers");
if ($res) $totalBarbers = (int)($res->fetch_assoc()['total'] ?? 0);

$res = $conn->query("SELECT COUNT(*) AS total FROM services");
if ($res) $totalServices = (int)($res->fetch_assoc()['total'] ?? 0);

/* WALK-IN COUNTS FOR TODAY */
$res = $conn->query("SELECT COUNT(*) AS total FROM walkins WHERE DATE(created_at) = CURDATE()");
if ($res) $totalWalkins = (int)($res->fetch_assoc()['total'] ?? 0);

$res = $conn->query("SELECT COUNT(*) AS total FROM walkins WHERE status = 'waiting' AND DATE(created_at) = CURDATE()");
if ($res) $waitingWalkins = (int)($res->fetch_assoc()['total'] ?? 0);

$res = $conn->query("SELECT COUNT(*) AS total FROM walkins WHERE status = 'ongoing' AND DATE(created_at) = CURDATE()");
if ($res) $ongoingWalkins = (int)($res->fetch_assoc()['total'] ?? 0);

$res = $conn->query("SELECT COUNT(*) AS total FROM walkins WHERE status = 'completed' AND DATE(created_at) = CURDATE()");
if ($res) $completedWalkins = (int)($res->fetch_assoc()['total'] ?? 0);

/* MONTHLY APPOINTMENT REVENUE */
$stmt = $conn->prepare("
    SELECT COALESCE(SUM(p.total_amount), 0) AS total
    FROM appointments a
    JOIN payments p ON p.id = (
        SELECT id
        FROM payments
        WHERE appointment_id = a.id
        ORDER BY created_at DESC, id DESC
        LIMIT 1
    )
    WHERE a.status = 'completed'
      AND DATE(a.start_time) BETWEEN ? AND ?
");
$stmt->bind_param("ss", $currentMonthStart, $currentMonthEnd);
$stmt->execute();
$monthAppointmentRevenue = (float)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
$stmt->close();

/* MONTHLY WALK-IN REVENUE */
$stmt = $conn->prepare("
    SELECT COALESCE(SUM(total_amount), 0) AS total
    FROM walkins
    WHERE status = 'completed'
      AND DATE(created_at) BETWEEN ? AND ?
");
$stmt->bind_param("ss", $currentMonthStart, $currentMonthEnd);
$stmt->execute();
$monthWalkinRevenue = (float)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
$stmt->close();

/* MONTHLY NO-SHOW RETAINED */
$stmt = $conn->prepare("
    SELECT COALESCE(SUM(p.amount_paid), 0) AS total
    FROM appointments a
    JOIN payments p ON p.id = (
        SELECT id
        FROM payments
        WHERE appointment_id = a.id
        ORDER BY created_at DESC, id DESC
        LIMIT 1
    )
    WHERE a.status = 'no-show'
      AND DATE(a.start_time) BETWEEN ? AND ?
");
$stmt->bind_param("ss", $currentMonthStart, $currentMonthEnd);
$stmt->execute();
$monthNoShowRevenue = (float)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
$stmt->close();

$monthTotalRevenue = $monthAppointmentRevenue + $monthWalkinRevenue + $monthNoShowRevenue;

/* TOP 3 SERVICES FOR CURRENT MONTH */
$stmt = $conn->prepare("
    SELECT service_name, SUM(total_count) AS total_count, SUM(total_sales) AS total_sales
    FROM (
        SELECT 
            s.name AS service_name,
            COUNT(*) AS total_count,
            COALESCE(SUM(aps.price), 0) AS total_sales
        FROM appointment_services aps
        JOIN appointments a ON aps.appointment_id = a.id
        JOIN services s ON aps.service_id = s.id
        WHERE a.status IN ('completed', 'no-show', 'approved')
          AND DATE(a.start_time) BETWEEN ? AND ?
        GROUP BY s.id

        UNION ALL

        SELECT 
            s.name AS service_name,
            COUNT(*) AS total_count,
            COALESCE(SUM(ws.price), 0) AS total_sales
        FROM walkin_services ws
        JOIN walkins w ON ws.walkin_id = w.id
        JOIN services s ON ws.service_id = s.id
        WHERE w.status = 'completed'
          AND DATE(w.created_at) BETWEEN ? AND ?
        GROUP BY s.id
    ) combined_services
    GROUP BY service_name
    ORDER BY total_count DESC, total_sales DESC
    LIMIT 3
");
$stmt->bind_param("ssss", $currentMonthStart, $currentMonthEnd, $currentMonthStart, $currentMonthEnd);
$stmt->execute();
$serviceResult = $stmt->get_result();
while ($row = $serviceResult->fetch_assoc()) {
    $monthTopServices[] = $row;
}
$stmt->close();

/* TOP 3 BARBERS FOR CURRENT MONTH */
$stmt = $conn->prepare("
    SELECT barber_name, SUM(total_clients) AS total_clients
    FROM (
        SELECT 
            b.name AS barber_name,
            COUNT(*) AS total_clients
        FROM appointments a
        JOIN barbers b ON a.barber_id = b.id
        WHERE a.status IN ('completed', 'approved', 'no-show')
          AND DATE(a.start_time) BETWEEN ? AND ?
        GROUP BY b.id

        UNION ALL

        SELECT 
            b.name AS barber_name,
            COUNT(*) AS total_clients
        FROM walkins w
        JOIN barbers b ON w.barber_id = b.id
        WHERE w.status = 'completed'
          AND DATE(w.created_at) BETWEEN ? AND ?
        GROUP BY b.id
    ) combined_barbers
    GROUP BY barber_name
    ORDER BY total_clients DESC
    LIMIT 3
");
$stmt->bind_param("ssss", $currentMonthStart, $currentMonthEnd, $currentMonthStart, $currentMonthEnd);
$stmt->execute();
$barberResult = $stmt->get_result();
while ($row = $barberResult->fetch_assoc()) {
    $monthTopBarbers[] = $row;
}
$stmt->close();

/* RECENT APPOINTMENTS - WITH SERVICES */
$recentAppointments = $conn->query("
    SELECT 
        a.id,
        a.start_time,
        a.status,
        b.name AS barber_name,
        u.first_name,
        u.last_name,
        COALESCE(appt_services.services_taken, 'No services listed') AS services_taken
    FROM appointments a
    JOIN barbers b ON a.barber_id = b.id
    JOIN users u ON a.customer_id = u.id
    LEFT JOIN (
        SELECT 
            aps.appointment_id,
            GROUP_CONCAT(s.name ORDER BY s.name SEPARATOR ', ') AS services_taken
        FROM appointment_services aps
        JOIN services s ON aps.service_id = s.id
        GROUP BY aps.appointment_id
    ) appt_services ON appt_services.appointment_id = a.id
    ORDER BY a.start_time DESC
    LIMIT 5
");

/* RECENT WALK-INS */
$recentWalkins = $conn->query("
    SELECT 
        w.id,
        w.customer_name,
        w.service_name,
        w.total_amount,
        w.status,
        w.created_at,
        w.started_at,
        w.estimated_end_at,
        b.name AS barber_name
    FROM walkins w
    LEFT JOIN barbers b ON w.barber_id = b.id
    ORDER BY w.created_at DESC
    LIMIT 5
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Portal | <?= htmlspecialchars($shop['shop_name'] ?? 'Barbershop') ?></title>
    <link rel="stylesheet" href="styles.css">
    <style>
        * { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: 'Poppins', sans-serif;
            background: #f7f9fc;
            color: #263238;
            overflow-x: hidden;
        }

        html { overflow-x: hidden; }
        body.sidebar-open { overflow: hidden; }

        .mobile-topbar {
            display: none;
            width: 100%;
            background: #07172f;
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
            word-break: break-word;
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
            background: transparent;
            transition: 0.2s ease;
        }

        .admin-nav a:hover,
        .admin-nav a.active {
            background: #2563eb;
        }

        .admin-main {
            flex: 1;
            min-width: 0;
            padding: 28px;
        }

        .dashboard-hero {
            background: linear-gradient(135deg, #07172f 0%, #0b3d91 58%, #2563eb 100%);
            color: #fff;
            border-radius: 24px;
            padding: 26px;
            margin-bottom: 24px;
            box-shadow: 0 18px 45px rgba(7,23,47,0.18);
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 18px;
            flex-wrap: wrap;
        }

        .dashboard-hero h1 {
            margin: 0;
            font-size: 30px;
        }

        .dashboard-hero p {
            margin: 8px 0 0;
            color: rgba(255,255,255,0.84);
            max-width: 620px;
        }

        .hero-badge {
            background: rgba(255,255,255,0.12);
            border: 1px solid rgba(255,255,255,0.15);
            padding: 12px 16px;
            border-radius: 16px;
            min-width: 210px;
        }

        .hero-badge span {
            display: block;
            font-size: 12px;
            color: rgba(255,255,255,0.78);
            margin-bottom: 6px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .hero-badge strong {
            font-size: 24px;
            word-break: break-word;
        }

        .section-title {
            margin: 0 0 14px;
            font-size: 20px;
            color: #07172f;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(180px, 1fr));
            gap: 18px;
            margin-bottom: 28px;
        }

        .stat-card {
            background: #fff;
            border-radius: 20px;
            padding: 20px;
            box-shadow: 0 12px 34px rgba(7,23,47,0.07);
            border-left: 6px solid #2563eb;
            min-width: 0;
        }

        .stat-card h3 {
            margin: 0 0 10px;
            font-size: 14px;
            color: #6b7280;
            font-weight: 600;
        }

        .stat-card .value {
            font-size: 30px;
            font-weight: 700;
            color: #07172f;
            word-break: break-word;
        }

        .stat-card small {
            display: block;
            margin-top: 8px;
            color: #64748b;
            font-size: 12px;
        }

        .highlights-grid {
            display: grid;
            grid-template-columns: 1.1fr 1fr 1fr;
            gap: 20px;
            margin-bottom: 24px;
        }

        .panel-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }

        .double-panel-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }

        .panel {
            background: #fff;
            border-radius: 20px;
            padding: 22px;
            box-shadow: 0 12px 34px rgba(7,23,47,0.07);
            min-width: 0;
        }

        .panel h2 {
            margin-top: 0;
            margin-bottom: 16px;
            font-size: 20px;
        }

        .highlight-amount {
            font-size: 34px;
            font-weight: 700;
            color: #2563eb;
            margin-bottom: 10px;
        }

        .highlight-mini {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-top: 14px;
        }

        .mini-stat {
            background: #f8f8f8;
            border: 1px solid #edf2f7;
            border-radius: 14px;
            padding: 12px;
        }

        .mini-stat span {
            display: block;
            font-size: 12px;
            color: #6b7280;
            margin-bottom: 6px;
        }

        .mini-stat strong {
            font-size: 18px;
            color: #07172f;
        }

        .rank-list {
            display: grid;
            gap: 12px;
        }

        .rank-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            padding: 14px;
            border-radius: 14px;
            background: #f8fbff;
            border: 1px solid #e5efff;
        }

        .rank-left {
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 0;
        }

        .rank-medal {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            background: #07172f;
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            flex-shrink: 0;
        }

        .rank-item:nth-child(1) .rank-medal { background: #2563eb; }
        .rank-item:nth-child(2) .rank-medal { background: #444; }
        .rank-item:nth-child(3) .rank-medal { background: #777; }

        .rank-meta strong {
            display: block;
            color: #07172f;
            font-size: 15px;
            word-break: break-word;
        }

        .rank-meta span {
            color: #6b7280;
            font-size: 13px;
        }

        .rank-value {
            text-align: right;
            flex-shrink: 0;
        }

        .rank-value strong {
            display: block;
            font-size: 16px;
            color: #07172f;
        }

        .rank-value span {
            font-size: 12px;
            color: #6b7280;
        }

        .empty-note {
            padding: 16px;
            background: #f8fbff;
            border: 1px dashed #b8c7ea;
            border-radius: 14px;
            color: #6b7280;
            text-align: center;
        }

        .table-responsive {
            width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        table th,
        table td {
            text-align: left;
            padding: 12px 10px;
            border-bottom: 1px solid #edf2f7;
            font-size: 14px;
            vertical-align: top;
        }

        table th {
            color: #6b7280;
            font-weight: 600;
        }

        .service-cell {
            min-width: 220px;
            white-space: normal;
            word-break: break-word;
        }

        .status-badge {
            display: inline-block;
            padding: 6px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
            text-transform: capitalize;
            white-space: nowrap;
        }

        .status-pending-approval { background: #fff1c2; color: #8a6d00; }
        .status-approved { background: #dff6dd; color: #256029; }
        .status-completed { background: #dbeafe; color: #1d4ed8; }
        .status-rejected { background: #fddede; color: #991b1b; }
        .status-canceled { background: #eee; color: #555; }
        .status-waiting { background: #fff1c2; color: #8a6d00; }
        .status-ongoing { background: #e7dcff; color: #5b21b6; }
        .status-cancelled { background: #eee; color: #555; }

        @media (max-width: 1300px) {
            .highlights-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 1200px) {
            .stats-grid {
                grid-template-columns: repeat(3, minmax(180px, 1fr));
            }
        }

        @media (max-width: 992px) {
            .stats-grid {
                grid-template-columns: repeat(2, minmax(180px, 1fr));
            }

            .double-panel-grid {
                grid-template-columns: 1fr;
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

            .dashboard-hero h1 {
                font-size: 24px;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .panel {
                padding: 16px;
            }

            .responsive-table thead {
                display: none;
            }

            .responsive-table,
            .responsive-table tbody,
            .responsive-table tr,
            .responsive-table td {
                display: block;
                width: 100%;
            }

            .responsive-table tr {
                border: 1px solid #edf2f7;
                border-radius: 14px;
                padding: 12px;
                margin-bottom: 12px;
                background: #fff;
            }

            .responsive-table td {
                border: none;
                border-bottom: 1px solid #edf2f7;
                padding: 10px 0 10px 45%;
                position: relative;
                min-height: 38px;
                word-break: break-word;
            }

            .responsive-table td:last-child {
                border-bottom: none;
            }

            .responsive-table td::before {
                content: attr(data-label);
                position: absolute;
                left: 0;
                top: 10px;
                width: 40%;
                font-weight: 700;
                color: #555;
                padding-right: 10px;
            }
        }

        @media (max-width: 480px) {
            .admin-brand img {
                width: 70px;
                height: 70px;
            }

            .admin-brand h2 {
                font-size: 16px;
            }

            .stat-card {
                padding: 16px;
            }

            .stat-card .value {
                font-size: 26px;
            }

            .highlight-amount {
                font-size: 28px;
            }

            .responsive-table td {
                padding-left: 0;
                padding-top: 28px;
            }

            .responsive-table td::before {
                position: static;
                display: block;
                width: 100%;
                margin-bottom: 6px;
            }

            .admin-main {
                padding: 14px;
            }

            .dashboard-hero {
                padding: 18px;
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
            <a href="admin_dashboard.php" class="active">Dashboard</a>
            <a href="admin_appointments.php">Appointments</a>
            <a href="admin_walkins.php">Walk-in Queue</a>
            <a href="admin_services.php">Services</a>
            <a href="admin_barbers.php">Barbers</a>
            <a href="admin_staff.php">Staff</a>
            <a href="admin_reviews.php">Reviews</a>
            <a href="admin_reports.php">Reports</a>
            <a href="admin_ar_models.php">AR Hair Models</a>
            <a href="admin_settings.php">Settings</a>
            <a href="admin_logout.php">Logout</a>
        </nav>
    </aside>

    <main class="admin-main">
        <section class="dashboard-hero">
            <div>
                <h1>Dashboard</h1>
                <p>Welcome back, <?= htmlspecialchars($_SESSION['admin_name'] ?? 'Admin') ?>. Monitor appointments, walk-ins, monthly performance, and your shop’s current top performers in one place.</p>
            </div>
            <div class="hero-badge">
                <span>Current Month Revenue</span>
                <strong>₱<?= number_format($monthTotalRevenue, 2) ?></strong>
            </div>
        </section>

        <h2 class="section-title">Shop Overview</h2>
        <section class="stats-grid">
            <div class="stat-card">
                <h3>Total Appointments</h3>
                <div class="value"><?= $totalAppointments ?></div>
                <small>All recorded online bookings</small>
            </div>
            <div class="stat-card">
                <h3>Pending Approval</h3>
                <div class="value"><?= $pendingAppointments ?></div>
                <small>Needs admin action</small>
            </div>
            <div class="stat-card">
                <h3>Approved</h3>
                <div class="value"><?= $approvedAppointments ?></div>
                <small>Confirmed appointments</small>
            </div>
            <div class="stat-card">
                <h3>Completed Appointments</h3>
                <div class="value"><?= $completedAppointments ?></div>
                <small>Finished online services</small>
            </div>
            <div class="stat-card">
                <h3>Rejected</h3>
                <div class="value"><?= $rejectedAppointments ?></div>
                <small>Declined bookings</small>
            </div>
            <div class="stat-card">
                <h3>Total Customers</h3>
                <div class="value"><?= $totalCustomers ?></div>
                <small>Registered customer accounts</small>
            </div>
            <div class="stat-card">
                <h3>Total Barbers</h3>
                <div class="value"><?= $totalBarbers ?></div>
                <small>Barber profiles in system</small>
            </div>
            <div class="stat-card">
                <h3>Total Services</h3>
                <div class="value"><?= $totalServices ?></div>
                <small>Available service offerings</small>
            </div>
        </section>

        <h2 class="section-title">Today’s Walk-in Snapshot</h2>
        <section class="stats-grid">
            <div class="stat-card">
                <h3>Total Walk-ins</h3>
                <div class="value"><?= $totalWalkins ?></div>
                <small>Created today</small>
            </div>
            <div class="stat-card">
                <h3>Waiting Walk-ins</h3>
                <div class="value"><?= $waitingWalkins ?></div>
                <small>Queued customers</small>
            </div>
            <div class="stat-card">
                <h3>Ongoing Walk-ins</h3>
                <div class="value"><?= $ongoingWalkins ?></div>
                <small>Currently in service</small>
            </div>
            <div class="stat-card">
                <h3>Completed Walk-ins</h3>
                <div class="value"><?= $completedWalkins ?></div>
                <small>Finished today</small>
            </div>
        </section>

        <h2 class="section-title">Monthly Highlights — <?= htmlspecialchars($currentMonthLabel) ?></h2>
        <section class="highlights-grid">
            <div class="panel">
                <h2>Revenue Summary</h2>
                <div class="highlight-amount">₱<?= number_format($monthTotalRevenue, 2) ?></div>
                <p style="margin:0; color:#666;">Combined revenue from completed appointments, completed walk-ins, and retained no-show payments for <?= htmlspecialchars($currentMonthLabel) ?>.</p>

                <div class="highlight-mini">
                    <div class="mini-stat">
                        <span>Appointments</span>
                        <strong>₱<?= number_format($monthAppointmentRevenue, 2) ?></strong>
                    </div>
                    <div class="mini-stat">
                        <span>Walk-ins</span>
                        <strong>₱<?= number_format($monthWalkinRevenue, 2) ?></strong>
                    </div>
                    <div class="mini-stat">
                        <span>No-show Retained</span>
                        <strong>₱<?= number_format($monthNoShowRevenue, 2) ?></strong>
                    </div>
                    <div class="mini-stat">
                        <span>Report Basis</span>
                        <strong><?= htmlspecialchars($currentMonthLabel) ?></strong>
                    </div>
                </div>
            </div>

            <div class="panel">
                <h2>Top 3 Barbers This Month</h2>
                <?php if (!empty($monthTopBarbers)): ?>
                    <div class="rank-list">
                        <?php foreach ($monthTopBarbers as $index => $barber): ?>
                            <div class="rank-item">
                                <div class="rank-left">
                                    <div class="rank-medal"><?= $index + 1 ?></div>
                                    <div class="rank-meta">
                                        <strong><?= htmlspecialchars($barber['barber_name']) ?></strong>
                                        <span>Most handled clients this month</span>
                                    </div>
                                </div>
                                <div class="rank-value">
                                    <strong><?= (int)$barber['total_clients'] ?></strong>
                                    <span>clients</span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-note">No barber performance data found for this month.</div>
                <?php endif; ?>
            </div>

            <div class="panel">
                <h2>Top 3 Services This Month</h2>
                <?php if (!empty($monthTopServices)): ?>
                    <div class="rank-list">
                        <?php foreach ($monthTopServices as $index => $service): ?>
                            <div class="rank-item">
                                <div class="rank-left">
                                    <div class="rank-medal"><?= $index + 1 ?></div>
                                    <div class="rank-meta">
                                        <strong><?= htmlspecialchars($service['service_name']) ?></strong>
                                        <span>Most booked service this month</span>
                                    </div>
                                </div>
                                <div class="rank-value">
                                    <strong><?= (int)$service['total_count'] ?></strong>
                                    <span>bookings</span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-note">No service performance data found for this month.</div>
                <?php endif; ?>
            </div>
        </section>

        <section class="double-panel-grid">
            <div class="panel">
                <h2>Recent Appointments</h2>
                <div class="table-responsive">
                    <table class="responsive-table">
                        <thead>
                            <tr>
                                <th>Customer</th>
                                <th>Barber</th>
                                <th>Service</th>
                                <th>Schedule</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($recentAppointments && $recentAppointments->num_rows > 0): ?>
                                <?php while ($appt = $recentAppointments->fetch_assoc()): ?>
                                    <?php $statusClass = 'status-' . str_replace(' ', '-', strtolower($appt['status'])); ?>
                                    <tr>
                                        <td data-label="Customer"><?= htmlspecialchars(trim(($appt['first_name'] ?? '') . ' ' . ($appt['last_name'] ?? ''))) ?></td>
                                        <td data-label="Barber"><?= htmlspecialchars($appt['barber_name']) ?></td>
                                        <td data-label="Service" class="service-cell"><?= htmlspecialchars($appt['services_taken']) ?></td>
                                        <td data-label="Schedule"><?= date("M j, Y g:i A", strtotime($appt['start_time'])) ?></td>
                                        <td data-label="Status">
                                            <span class="status-badge <?= htmlspecialchars($statusClass) ?>">
                                                <?= htmlspecialchars($appt['status']) ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5">No recent appointments found.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="panel">
                <h2>Recent Walk-ins</h2>
                <div class="table-responsive">
                    <table class="responsive-table">
                        <thead>
                            <tr>
                                <th>Customer</th>
                                <th>Service</th>
                                <th>Barber</th>
                                <th>Amount</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($recentWalkins && $recentWalkins->num_rows > 0): ?>
                                <?php while ($walkin = $recentWalkins->fetch_assoc()): ?>
                                    <?php $walkinStatusClass = 'status-' . str_replace(' ', '-', strtolower($walkin['status'])); ?>
                                    <tr>
                                        <td data-label="Customer"><?= htmlspecialchars($walkin['customer_name']) ?></td>
                                        <td data-label="Service"><?= htmlspecialchars($walkin['service_name']) ?></td>
                                        <td data-label="Barber"><?= htmlspecialchars($walkin['barber_name'] ?? 'Not assigned') ?></td>
                                        <td data-label="Amount">₱<?= number_format((float)$walkin['total_amount'], 2) ?></td>
                                        <td data-label="Status">
                                            <span class="status-badge <?= htmlspecialchars($walkinStatusClass) ?>">
                                                <?= htmlspecialchars($walkin['status']) ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5">No walk-ins found.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </main>
</div>

<script>
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