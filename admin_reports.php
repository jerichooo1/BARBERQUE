<?php
session_start();
include 'db.php';

if (!isset($_SESSION['admin_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: admin_staff_login.php");
    exit();
}

/* =========================
   HELPERS
========================= */
function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function peso($amount) {
    return '₱' . number_format((float)$amount, 2);
}

function formatAppointmentRef($id) {
    return 'A' . str_pad((int)$id, 4, '0', STR_PAD_LEFT);
}

function formatWalkinRef($id) {
    return 'W' . str_pad((int)$id, 4, '0', STR_PAD_LEFT);
}

function reportTypeClass($type) {
    $type = strtolower(trim((string)$type));

    if ($type === 'appointment') return 'type-appointment';
    if ($type === 'walk-in' || $type === 'walkin') return 'type-walkin';

    return 'type-default';
}

function reportStatusClass($status) {
    $status = strtolower(trim((string)$status));
    $status = str_replace(' ', '-', $status);

    if ($status === 'pending-approval') return 'status-pending-approval';
    if ($status === 'approved') return 'status-approved';
    if ($status === 'completed') return 'status-completed';
    if ($status === 'rejected') return 'status-rejected';
    if ($status === 'cancelled' || $status === 'canceled') return 'status-cancelled';
    if ($status === 'no-show' || $status === 'no-show') return 'status-no-show';

    return 'status-default';
}

function tableExists($conn, $table) {
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $tableEscaped = $conn->real_escape_string($table);

    $result = $conn->query("SHOW TABLES LIKE '$tableEscaped'");

    return $result && $result->num_rows > 0;
}

function columnExists($conn, $table, $column) {
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $columnEscaped = $conn->real_escape_string($column);

    $result = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$columnEscaped'");

    return $result && $result->num_rows > 0;
}

function getServiceNamesByIds($conn, $ids) {
    if (empty($ids)) return [];

    $cleanIds = [];

    foreach ($ids as $id) {
        if (is_numeric($id)) {
            $cleanIds[] = (int)$id;
        }
    }

    if (empty($cleanIds)) return [];

    $placeholders = implode(',', array_fill(0, count($cleanIds), '?'));
    $types = str_repeat('i', count($cleanIds));

    $stmt = $conn->prepare("SELECT name FROM services WHERE id IN ($placeholders) ORDER BY name ASC");
    $stmt->bind_param($types, ...$cleanIds);
    $stmt->execute();

    $res = $stmt->get_result();
    $names = [];

    while ($row = $res->fetch_assoc()) {
        $names[] = $row['name'];
    }

    $stmt->close();

    return $names;
}

function getAppointmentCompanionInfo($conn, $appointmentId, $customerId) {

    if (!tableExists($conn, 'appointment_companions')) {
        return [
            'count' => 0,
            'services_text' => '',
            'total_price' => 0
        ];
    }

    $stmt = $conn->prepare("
        SELECT ac.*, s.name AS main_service_name
        FROM appointment_companions ac
        LEFT JOIN services s ON s.id = ac.service_id
        WHERE ac.appointment_id = ?
        ORDER BY ac.person_no ASC, ac.id ASC
    ");

    $stmt->bind_param("i", $appointmentId);
    $stmt->execute();
    $res = $stmt->get_result();

    $count = 0;
    $details = [];
    $totalPrice = 0;

    while ($row = $res->fetch_assoc()) {

        // Skip rows with no meaningful data
        $hasRealData =
            trim((string)$row['full_name']) !== '' ||
            (!empty($row['service_id']) && (int)$row['service_id'] > 0) ||
            trim((string)$row['selected_services']) !== '';

        if (!$hasRealData) {
            continue;
        }

        // SKIP IF THIS IS MAIN CUSTOMER
        if ((int)$row['person_no'] === 1) {
            // person_no=1 always belongs to main customer
            continue;
        }

        // Count this as a real companion
        $count++;

        $personLabel = trim($row['full_name'] ?? '');
        if ($personLabel === '') {
            $personLabel = 'Person ' . ($row['person_no'] ?? $count);
        }

        $services = [];

        if (!empty($row['main_service_name'])) {
            $services[] = $row['main_service_name'];
        }

        if (!empty($row['selected_services'])) {
            $decoded = json_decode($row['selected_services'], true);
            if (is_array($decoded)) {
                $addonIds = [];

                foreach ($decoded as $item) {
                    if (is_array($item)) {
                        $addonIds[] = $item['id'] ?? $item['service_id'] ?? null;
                    } else {
                        $addonIds[] = $item;
                    }
                }

                $addonIds = array_filter($addonIds, fn($x) => !empty($x));
                $addonNames = getServiceNamesByIds($conn, $addonIds);
                $services = array_merge($services, $addonNames);
            }
        }

        if (!empty($row['total_price'])) {
            $totalPrice += (float)$row['total_price'];
        }

        if (!empty($services)) {
            $details[] = $personLabel . ': ' . implode(', ', array_unique($services));
        } else {
            $details[] = $personLabel;
        }
    }

    $stmt->close();

    return [
        'count' => $count,
        'services_text' => implode(' | ', $details),
        'total_price' => $totalPrice
    ];
}




function normalizeStatusLabel($status) {
    $status = strtolower(trim((string)$status));

    if ($status === 'pending approval') return 'Pending Approval';
    if ($status === 'approved') return 'Approved';
    if ($status === 'completed') return 'Completed';
    if ($status === 'rejected') return 'Rejected';
    if ($status === 'cancelled' || $status === 'canceled') return 'Cancelled';
    if ($status === 'no-show') return 'No-show';

    return ucfirst($status);
}

function getAppointmentAmountByStatus($status, $paymentTotal, $amountPaid, $computedTotal, $companionTotal) {
    $status = strtolower(trim((string)$status));

    if ($status === 'completed' || $status === 'approved') {
        $amount = (float)$paymentTotal;

        if ($amount <= 0) {
            $amount = (float)$computedTotal + (float)$companionTotal;
        }

        return $amount;
    }

    if ($status === 'no-show') {
        return (float)$amountPaid;
    }

    return 0;
}

/* =========================
   SHOP SETTINGS
========================= */
$settings = $conn->query("SELECT * FROM settings LIMIT 1");
$shop = $settings ? $settings->fetch_assoc() : [];

$shopName = $shop['shop_name'] ?? 'Fade Street Hair Studio';
$shopLogo = $shop['logo'] ?? 'logo.png';
$shopAddress = $shop['address'] ?? '';
$shopPhone = $shop['phone'] ?? '';
$shopEmail = $shop['email'] ?? '';
$shopHours = $shop['opening_hours'] ?? '';

/* =========================
   FILTERS
========================= */
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date   = $_GET['end_date'] ?? date('Y-m-d');

if ($start_date > $end_date) {
    [$start_date, $end_date] = [$end_date, $start_date];
}

$validStatuses = [
    'pending approval',
    'approved',
    'completed',
    'rejected',
    'cancelled',
    'no-show'
];

$selectedStatuses = $_GET['statuses'] ?? ['completed'];

if (!is_array($selectedStatuses)) {
    $selectedStatuses = ['completed'];
}

$selectedStatuses = array_values(array_intersect($selectedStatuses, $validStatuses));

if (empty($selectedStatuses)) {
    $selectedStatuses = ['completed'];
}

$includeWalkins = isset($_GET['include_walkins']) && $_GET['include_walkins'] === '1';

$isPrintMode = isset($_GET['print']) && $_GET['print'] === 'pdf';

$generatedOn = date("F j, Y g:i A");
$generatedBy = $_SESSION['admin_name']
    ?? $_SESSION['first_name']
    ?? $_SESSION['name']
    ?? 'Owner / Administrator';

$reportRows = [];

/* =========================
   APPOINTMENTS BY STATUS
========================= */
$appointmentQueryStatuses = [];

foreach ($selectedStatuses as $status) {
    if ($status === 'cancelled') {
        $appointmentQueryStatuses[] = 'cancelled';
        $appointmentQueryStatuses[] = 'canceled';
    } else {
        $appointmentQueryStatuses[] = $status;
    }
}

$appointmentQueryStatuses = array_values(array_unique($appointmentQueryStatuses));

if (!empty($appointmentQueryStatuses)) {
    $appointmentServicePriceColumn = columnExists($conn, 'appointment_services', 'price')
        ? "COALESCE(NULLIF(aps.price, 0), s.price, 0)"
        : "COALESCE(s.price, 0)";

    $statusPlaceholders = implode(',', array_fill(0, count($appointmentQueryStatuses), '?'));

    $sql = "
    SELECT 
    a.id,
    a.customer_id,

        a.start_time,
        a.status,

        COALESCE(
            NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name)), ''),
            NULLIF(u.name, ''),
            u.email,
            'Customer'
        ) AS client_name,

        /* main barber (for main person) */
        b.name AS main_barber,

        /* NEW: get barbers of companions */
        GROUP_CONCAT(DISTINCT bc.name ORDER BY bc.name SEPARATOR ', ') AS companion_barbers,

        /* services */
        GROUP_CONCAT(DISTINCT s.name ORDER BY s.name SEPARATOR ', ') AS services,

        /* totals */
        COALESCE(SUM($appointmentServicePriceColumn), 0) AS computed_service_total,
        COALESCE(p.total_amount, 0) AS payment_total,
        COALESCE(p.amount_paid, 0) AS amount_paid,
        COALESCE(p.balance_amount, 0) AS balance_amount

    FROM appointments a
    LEFT JOIN users u ON u.id = a.customer_id

    /* main barber */
    LEFT JOIN barbers b ON b.id = a.barber_id

    /* companion JOINs */
    LEFT JOIN appointment_companions ac ON ac.appointment_id = a.id
    LEFT JOIN barbers bc ON bc.id = ac.barber_id

    /* services */
    LEFT JOIN appointment_services aps ON aps.appointment_id = a.id
    LEFT JOIN services s ON s.id = aps.service_id

    LEFT JOIN payments p ON p.id = (
        SELECT p2.id
        FROM payments p2
        WHERE p2.appointment_id = a.id
        ORDER BY p2.created_at DESC, p2.id DESC
        LIMIT 1
    )

    WHERE a.status IN ($statusPlaceholders)
      AND DATE(a.start_time) BETWEEN ? AND ?

    GROUP BY a.id
    ORDER BY a.start_time DESC, a.id DESC 
";


    $stmt = $conn->prepare($sql);

    $types = str_repeat('s', count($appointmentQueryStatuses)) . 'ss';
    $params = array_merge($appointmentQueryStatuses, [$start_date, $end_date]);

    $stmt->bind_param($types, ...$params);
    $stmt->execute();

    $res = $stmt->get_result();

    while ($row = $res->fetch_assoc()) {
        $GLOBALS['mainCustomerName'] = $row['client_name'];
        $companionInfo = getAppointmentCompanionInfo($conn, (int)$row['id'], (int)$row['customer_id']);


        $mainServices = trim($row['services'] ?? '');

        if ($mainServices === '') {
            $mainServices = 'Service details not available';
        }

        $servicesText = 'Main: ' . $mainServices;

        if ($companionInfo['services_text'] !== '') {
            $servicesText .= ' | Companions: ' . $companionInfo['services_text'];
        }

        $pax = 1 + (int)$companionInfo['count'];

        $price = getAppointmentAmountByStatus(
            $row['status'],
            $row['payment_total'],
            $row['amount_paid'],
            $row['computed_service_total'],
            $companionInfo['total_price']
        );

        $reportRows[] = [
            'ref_id' => formatAppointmentRef($row['id']),
            'date' => date('Y-m-d', strtotime($row['start_time'])),
            'time' => date('g:i A', strtotime($row['start_time'])),
            'client' => $row['client_name'],
            'pax' => $pax,
            'services' => $servicesText,
            'price' => $price,
            'barber' => ($row['companion_barbers'] != '' 
                ? $row['companion_barbers'] 
                : ($row['main_barber'] ?? 'Not assigned')
            ),

            'type' => 'Appointment',
            'status' => normalizeStatusLabel($row['status'])
        ];
    }

    $stmt->close();
}

/* =========================
   WALK-INS
========================= */
if ($includeWalkins && tableExists($conn, 'walkins')) {
    $walkinServicePriceColumn = columnExists($conn, 'walkin_services', 'price')
        ? "COALESCE(NULLIF(ws.price, 0), s.price, 0)"
        : "COALESCE(s.price, 0)";

    $stmt = $conn->prepare("
        SELECT 
            w.id,
            w.created_at,
            w.customer_name,
            w.total_amount,
            w.status,
            b.name AS barber_name,
            GROUP_CONCAT(DISTINCT s.name ORDER BY s.name SEPARATOR ', ') AS services,
            COALESCE(SUM($walkinServicePriceColumn), 0) AS computed_service_total
        FROM walkins w
        LEFT JOIN barbers b ON b.id = w.barber_id
        LEFT JOIN walkin_services ws ON ws.walkin_id = w.id
        LEFT JOIN services s ON s.id = ws.service_id
        WHERE w.status = 'completed'
          AND DATE(w.created_at) BETWEEN ? AND ?
        GROUP BY w.id
        ORDER BY w.created_at DESC, w.id DESC
    ");

    $stmt->bind_param("ss", $start_date, $end_date);
    $stmt->execute();

    $res = $stmt->get_result();

    while ($row = $res->fetch_assoc()) {
        $servicesText = trim($row['services'] ?? '');

        if ($servicesText === '') {
            $servicesText = 'Service details not available';
        }

        $price = (float)$row['total_amount'];

        if ($price <= 0) {
            $price = (float)$row['computed_service_total'];
        }

        $reportRows[] = [
            'ref_id' => formatWalkinRef($row['id']),
            'date' => date('Y-m-d', strtotime($row['created_at'])),
            'time' => date('g:i A', strtotime($row['created_at'])),
            'client' => $row['customer_name'] ?: 'Walk-in Client',
            'pax' => 1,
            'services' => $servicesText,
            'price' => $price,
            'barber' => $row['barber_name'] ?? 'Not assigned',
            'type' => 'Walk-in',
            'status' => 'Completed'
        ];
    }

    $stmt->close();
}

/* =========================
   SORT FINAL REPORT ROWS
========================= */
usort($reportRows, function ($a, $b) {
    $left = strtotime($a['date'] . ' ' . $a['time']);
    $right = strtotime($b['date'] . ' ' . $b['time']);

    if ($left === $right) {
        return strcmp((string)($b['ref_id'] ?? ''), (string)($a['ref_id'] ?? ''));
    }

    return $right <=> $left;
});

$totalPax = 0;
$totalAmount = 0;

foreach ($reportRows as $row) {
    $totalPax += (int)$row['pax'];
    $totalAmount += (float)$row['price'];
}

$queryBase = http_build_query([
    'start_date' => $start_date,
    'end_date' => $end_date,
    'statuses' => $selectedStatuses,
    'include_walkins' => $includeWalkins ? '1' : '0'
]);

$selectedLabels = [];

foreach ($selectedStatuses as $status) {
    $selectedLabels[] = normalizeStatusLabel($status);
}

if ($includeWalkins) {
    $selectedLabels[] = 'Walk-ins';
}

$dropdownLabel = !empty($selectedLabels) ? implode(', ', $selectedLabels) : 'Select report content';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Reports | <?= h($shopName) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <style>
        * {
            box-sizing: border-box;
        }

        :root {
            --primary: #2563eb;
            --primary-dark: #1d4ed8;
            --dark: #0f172a;
            --text: #1f2937;
            --muted: #6b7280;
            --line: #e5e7eb;
            --bg: #f3f4f6;
            --card: #ffffff;
            --soft: #f9fafb;
            --shadow: 0 14px 35px rgba(15, 23, 42, 0.08);
            --radius: 18px;
        }

        body {
            margin: 0;
            font-family: 'Poppins', Arial, sans-serif;
            background: var(--bg);
            color: var(--text);
        }

        body.sidebar-open {
            overflow: hidden;
        }

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
            max-width: calc(100vw - 90px);
            overflow: hidden;
            white-space: nowrap;
            text-overflow: ellipsis;
        }

        .menu-btn {
            width: 42px;
            height: 42px;
            border: none;
            border-radius: 10px;
            background: rgba(255,255,255,0.1);
            color: #fff;
            font-size: 21px;
            cursor: pointer;
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
            margin-bottom: 28px;
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
            margin: 0;
            font-size: 18px;
            line-height: 1.3;
        }

        .admin-brand p {
            margin: 6px 0 0;
            font-size: 13px;
            color: #bbb;
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

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 18px;
            flex-wrap: wrap;
            margin-bottom: 22px;
        }

        .page-header h1 {
            margin: 0 0 6px;
            font-size: 30px;
            color: var(--dark);
        }

        .page-header p {
            margin: 0;
            color: var(--muted);
        }

        .filter-card,
        .report-paper {
            background: var(--card);
            border: 1px solid #edf2f7;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
        }

        .filter-card {
            padding: 22px;
            margin-bottom: 22px;
        }

        .filter-form {
            display: grid;
            grid-template-columns: 180px 180px 280px auto auto;
            gap: 14px;
            align-items: end;
        }

        .field label,
        .report-dropdown label {
            display: block;
            margin-bottom: 7px;
            font-size: 13px;
            font-weight: 700;
            color: #374151;
        }

        .field input[type="date"] {
            width: 100%;
            height: 46px;
            border: 1px solid #d1d5db;
            border-radius: 12px;
            padding: 0 13px;
            font-size: 14px;
            outline: none;
            background: #fff;
        }

        .field input[type="date"]:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.12);
        }

        .report-dropdown {
            position: relative;
            width: 100%;
        }

        .report-dropdown-btn {
            width: 100%;
            height: 46px;
            border: 1px solid #d1d5db;
            border-radius: 12px;
            background: #fff;
            padding: 0 13px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 700;
            color: #111827;
            text-align: left;
        }

        .report-dropdown-btn span:first-child {
    overflow: hidden;
    white-space: nowrap;
    text-overflow: ellipsis;
    min-width: 0;
    max-width: 100%;
}

        .dropdown-arrow {
            color: #6b7280;
            flex-shrink: 0;
        }

        .report-dropdown-menu {
            display: none;
            position: absolute;
            top: calc(100% + 8px);
            left: 0;
            width: 100%;
            min-width: 280px;
            background: #fff;
            border: 1px solid #d1d5db;
            border-radius: 14px;
            box-shadow: 0 18px 40px rgba(15, 23, 42, 0.16);
            padding: 8px;
            z-index: 80;
        }

        .report-dropdown-menu.show {
            display: block;
        }

        .dropdown-check {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 11px 10px;
            border-radius: 10px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 700;
            color: #374151;
        }

        .dropdown-check:hover {
            background: #f9fafb;
        }

        .dropdown-check input {
            width: 16px;
            height: 16px;
            accent-color: #2563eb;
            cursor: pointer;
        }

        .btn {
            height: 46px;
            padding: 0 18px;
            border-radius: 12px;
            border: none;
            cursor: pointer;
            font-weight: 800;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            white-space: nowrap;
            transition: 0.2s ease;
        }

        .btn:hover {
            transform: translateY(-1px);
        }

        .btn-primary {
            background: #2563eb;
            color: #fff;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
        }

        .btn-dark {
            background: var(--dark);
            color: #fff;
        }

        .report-paper {
            max-width: 1200px;
            margin: 0 auto;
            padding: 30px;
        }

        .report-top {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 24px;
            align-items: flex-start;
            padding-bottom: 22px;
            border-bottom: 3px solid #0f172a;
        }

        .shop-header {
            display: flex;
            gap: 16px;
            align-items: flex-start;
        }

        .shop-logo {
            width: 84px;
            height: 84px;
            border-radius: 16px;
            border: 1px solid var(--line);
            object-fit: cover;
            background: #fff;
            padding: 5px;
        }

        .shop-info h2 {
            margin: 0;
            font-size: 25px;
            color: var(--dark);
        }

        .shop-info p {
            margin: 4px 0;
            color: #4b5563;
            font-size: 13px;
        }

        .report-title-box {
            text-align: right;
        }

        .report-title-box h1 {
            margin: 0 0 8px;
            font-size: 24px;
            color: #2563eb;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }

        .report-title-box p {
            margin: 4px 0;
            font-size: 13px;
            color: #4b5563;
        }

        .report-summary {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 14px;
            margin: 22px 0;
        }

        .summary-box {
            border: 1px solid var(--line);
            border-radius: 14px;
            padding: 16px;
            background: var(--soft);
        }

        .summary-box span {
            display: block;
            color: var(--muted);
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            margin-bottom: 8px;
        }

        .summary-box strong {
            font-size: 22px;
            color: var(--dark);
        }

        .table-wrap {
            width: 100%;
            overflow-x: auto;
            border: 1px solid var(--line);
            border-radius: 14px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 900px;
            background: #fff;
        }

        th,
        td {
            padding: 12px 11px;
            border-bottom: 1px solid var(--line);
            text-align: left;
            vertical-align: top;
            font-size: 13px;
        }

        th {
            background: #0f172a;
            color: #fff;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            font-size: 11px;
        }

        tbody tr:nth-child(even) td {
            background: #fafafa;
        }

        tbody tr:last-child td {
            border-bottom: none;
        }

        tfoot th {
            background: #f3f4f6;
            color: #111827;
            border-top: 2px solid #0f172a;
            font-size: 12px;
        }

        .text-center {
            text-align: center;
        }

        .text-right {
            text-align: right;
        }

        .money {
            font-weight: 800;
            color: #2563eb;
            white-space: nowrap;
        }

        .badge,
        .status-mini-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 5px 9px;
            border-radius: 999px;
            font-weight: 800;
            font-size: 11px;
            margin-bottom: 5px;
            white-space: nowrap;
        }

        .type-appointment {
            background: #dbeafe;
            color: #1d4ed8;
        }

        .type-walkin {
            background: #dcfce7;
            color: #166534;
        }

        .type-default {
            background: #e5e7eb;
            color: #4b5563;
        }

        
        .small-muted {
            display: block;
            color: var(--muted);
            font-size: 12px;
            margin-top: 3px;
        }

        .empty-state {
            padding: 40px 20px;
            text-align: center;
            color: var(--muted);
        }

        .report-footer {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
            margin-top: 32px;
            align-items: end;
        }

        .generated-box {
            font-size: 13px;
            color: #374151;
            line-height: 1.7;
        }

        .signature-box {
            text-align: center;
            justify-self: end;
            min-width: 260px;
        }

        .signature-line {
            height: 44px;
            border-bottom: 1px solid #111827;
            margin-bottom: 8px;
        }

        .signature-box strong {
            display: block;
            font-size: 13px;
            color: #111827;
        }

        .signature-box span {
            color: var(--muted);
            font-size: 12px;
        }

        .print-note {
            margin-top: 14px;
            color: var(--muted);
            font-size: 13px;
            text-align: center;
        }

        @media (max-width: 1180px) {
            .filter-form {
                grid-template-columns: 1fr 1fr;
            }

            .report-dropdown {
                grid-column: 1 / -1;
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

            .filter-form {
                grid-template-columns: 1fr;
            }

            .btn {
                width: 100%;
            }

            .report-top,
            .report-footer {
                grid-template-columns: 1fr;
            }

            .report-title-box,
            .signature-box {
                text-align: left;
                justify-self: start;
            }

            .report-summary {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 520px) {
            .admin-main {
                padding: 14px;
            }
            .report-dropdown,
.report-dropdown-btn,
.report-dropdown-menu {
    width: 100%;
    max-width: 100%;
    min-width: 0;
}

.report-dropdown-btn {
    overflow: hidden;
}

.report-dropdown-btn span:first-child {
    display: block;
    min-width: 0;
    max-width: calc(100vw - 95px);
    overflow: hidden;
    white-space: nowrap;
    text-overflow: ellipsis;
}

.report-dropdown-menu {
    left: 0;
    right: 0;
    min-width: 0;
}

.dropdown-check {
    font-size: 13px;
}

            .filter-card,
            .report-paper {
                padding: 14px;
                border-radius: 14px;
                width: 100%;
                max-width: 100%;
            }

            .shop-header {
                flex-direction: column;
            }

            .shop-logo {
                width: 72px;
                height: 72px;
            }

            .shop-info h2 {
                font-size: 21px;
            }

            .report-top {
                gap: 16px;
            }

            .report-title-box h1 {
                font-size: 20px;
            }

            .report-title-box p,
            .shop-info p,
            .generated-box {
                font-size: 12px;
            }

            .summary-box {
                padding: 14px;
            }

            .summary-box strong {
                font-size: 20px;
            }
        }

        @media (max-width: 760px) {
    .table-wrap {
        width: 100%;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }

    table {
        min-width: 900px;
        width: 100%;
    }
}

        @media print {
            @page {
                size: letter portrait;
                margin: 10mm;
            }

            html,
            body {
                background: #fff !important;
                overflow: visible !important;
            }

            .mobile-topbar,
            .sidebar-overlay,
            .admin-sidebar,
            .page-header,
            .filter-card,
            .print-note {
                display: none !important;
            }

            .admin-layout,
            .admin-main {
                display: block !important;
                padding: 0 !important;
                margin: 0 !important;
                width: 100% !important;
                min-height: auto !important;
            }

            .report-paper {
                max-width: none !important;
                width: 100% !important;
                margin: 0 !important;
                padding: 0 !important;
                border: none !important;
                box-shadow: none !important;
                border-radius: 0 !important;
            }

            .report-top {
                page-break-inside: avoid;
            }

            .report-summary {
                grid-template-columns: repeat(3, 1fr) !important;
                page-break-inside: avoid;
            }

            .table-wrap {
                overflow: visible !important;
                border-radius: 0 !important;
            }

            table {
                min-width: 0 !important;
                width: 100% !important;
                font-size: 11px !important;
            }

            th,
            td {
                padding: 7px 6px !important;
                font-size: 10px !important;
            }

            th {
                background: #111827 !important;
                color: #fff !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            tfoot th,
            .summary-box,
            tbody tr:nth-child(even) td {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .badge,
            .status-mini-badge {
                border: 1px solid currentColor;
                background: #fff !important;
            }

            a {
                color: inherit !important;
                text-decoration: none !important;
            }
        }
    </style>
</head>

<body>

<?php if (!$isPrintMode): ?>
<div class="mobile-topbar">
    <button type="button" class="menu-btn" id="menuBtn">☰</button>
    <strong><?= h($shopName) ?></strong>
</div>

<div class="sidebar-overlay" id="sidebarOverlay"></div>
<?php endif; ?>

<div class="admin-layout">

    <?php if (!$isPrintMode): ?>
    <aside class="admin-sidebar" id="adminSidebar">
        <div class="admin-brand">
            <img src="images/<?= h($shopLogo) ?>" alt="Shop Logo">
            <h2><?= h($shopName) ?></h2>
            <p>Owner Portal</p>
        </div>

        <nav class="admin-nav">
            <a href="admin_dashboard.php">Dashboard</a>
            <a href="admin_appointments.php">Appointments</a>
            <a href="admin_walkins.php">Walk-in Queue</a>
            <a href="admin_services.php">Services</a>
            <a href="admin_barbers.php">Barbers</a>
            <a href="admin_staff.php">Staff</a>
            <a href="admin_reviews.php">Reviews</a>
            <a href="admin_reports.php" class="active">Reports</a>
            <a href="admin_ar_models.php">AR Hair Models</a>
            <a href="admin_settings.php">Settings</a>
            <a href="admin_logout.php">Logout</a>
        </nav>
    </aside>
    <?php endif; ?>

    <main class="admin-main">

        <?php if (!$isPrintMode): ?>
        <div class="page-header">
            <div>
                <h1>Reports</h1>
                <p>Generate a clean printable business report using your selected date range and status.</p>
            </div>
        </div>

        <section class="filter-card">
            <form method="GET" class="filter-form">
                <div class="field">
                    <label for="start_date">From</label>
                    <input type="date" id="start_date" name="start_date" value="<?= h($start_date) ?>" required>
                </div>

                <div class="field">
                    <label for="end_date">To</label>
                    <input type="date" id="end_date" name="end_date" value="<?= h($end_date) ?>" required>
                </div>

                <div class="report-dropdown">
                    <label>Report Content</label>

                    <button type="button" class="report-dropdown-btn" id="reportDropdownBtn">
                        <span id="reportDropdownText"><?= h($dropdownLabel) ?></span>
                        <span class="dropdown-arrow">▾</span>
                    </button>

                    <div class="report-dropdown-menu" id="reportDropdownMenu">
                        <label class="dropdown-check">
                            <input type="checkbox" name="statuses[]" value="pending approval" <?= in_array('pending approval', $selectedStatuses) ? 'checked' : '' ?>>
                            <span>Pending Approval</span>
                        </label>

                        <label class="dropdown-check">
                            <input type="checkbox" name="statuses[]" value="approved" <?= in_array('approved', $selectedStatuses) ? 'checked' : '' ?>>
                            <span>Approved</span>
                        </label>

                        <label class="dropdown-check">
                            <input type="checkbox" name="statuses[]" value="completed" <?= in_array('completed', $selectedStatuses) ? 'checked' : '' ?>>
                            <span>Completed</span>
                        </label>

                        <label class="dropdown-check">
                            <input type="checkbox" name="statuses[]" value="rejected" <?= in_array('rejected', $selectedStatuses) ? 'checked' : '' ?>>
                            <span>Rejected</span>
                        </label>

                        <label class="dropdown-check">
                            <input type="checkbox" name="statuses[]" value="cancelled" <?= in_array('cancelled', $selectedStatuses) ? 'checked' : '' ?>>
                            <span>Cancelled</span>
                        </label>

                        <label class="dropdown-check">
                            <input type="checkbox" name="statuses[]" value="no-show" <?= in_array('no-show', $selectedStatuses) ? 'checked' : '' ?>>
                            <span>No-show</span>
                        </label>

                        <label class="dropdown-check">
                            <input type="checkbox" name="include_walkins" value="1" <?= $includeWalkins ? 'checked' : '' ?>>
                            <span>Walk-ins</span>
                        </label>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary">Generate Report</button>

                <a href="admin_reports.php?<?= h($queryBase) ?>&print=pdf" target="_blank" class="btn btn-dark">
                    Print / Save as PDF
                </a>
            </form>
        </section>
        <?php endif; ?>

        <section class="report-paper">

            <div class="report-top">
                <div class="shop-header">
                    <img src="images/<?= h($shopLogo) ?>" alt="Shop Logo" class="shop-logo">

                    <div class="shop-info">
                        <h2><?= h($shopName) ?></h2>

                        <?php if (!empty($shopAddress)): ?>
                            <p><?= h($shopAddress) ?></p>
                        <?php endif; ?>

                        <?php if (!empty($shopPhone)): ?>
                            <p>Phone: <?= h($shopPhone) ?></p>
                        <?php endif; ?>

                        <?php if (!empty($shopEmail)): ?>
                            <p>Email: <?= h($shopEmail) ?></p>
                        <?php endif; ?>

                        <?php if (!empty($shopHours)): ?>
                            <p>Opening Hours: <?= h($shopHours) ?></p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="report-title-box">
                    <h1>Business Report</h1>
                    <p><strong>Coverage:</strong> <?= date("F j, Y", strtotime($start_date)) ?> to <?= date("F j, Y", strtotime($end_date)) ?></p>
                    <p><strong>Generated on:</strong> <?= h($generatedOn) ?></p>
                    <p><strong>Generated by:</strong> <?= h($generatedBy) ?></p>
                </div>
            </div>

            <div class="report-summary">
                <div class="summary-box">
                    <span>Total Records</span>
                    <strong><?= count($reportRows) ?></strong>
                </div>

                <div class="summary-box">
                    <span>Total Pax</span>
                    <strong><?= number_format($totalPax) ?></strong>
                </div>

                <div class="summary-box">
                    <span>Total Amount</span>
                    <strong><?= peso($totalAmount) ?></strong>
                </div>
            </div>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th style="width: 90px;">ID</th>
                            <th style="width: 95px;">Date</th>
                            <th style="width: 80px;">Time</th>
                            <th style="width: 150px;">Client</th>
                            <th style="width: 70px;" class="text-center">No. of Pax</th>
                            <th>Services</th>
                            <th style="width: 130px;">Barber</th>
                            <th style="width: 120px;">Report Type</th>
                            <th style="width: 110px;" class="text-right">Price</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php if (!empty($reportRows)): ?>
                            <?php foreach ($reportRows as $row): ?>
                                <tr>
                                    <td data-label="ID"><strong><?= h($row['ref_id'] ?? '') ?></strong></td>
                                    <td data-label="Date"><?= date("M j, Y", strtotime($row['date'])) ?></td>
                                    <td data-label="Time"><?= h($row['time']) ?></td>
                                    <td data-label="Client"><?= h($row['client']) ?></td>
                                    <td data-label="No. of Pax" class="text-center"><?= (int)$row['pax'] ?></td>
                                    <td data-label="Services"><?= h($row['services']) ?></td>
                                    <td data-label="Barber"><?= h($row['barber']) ?></td>
                                    <td data-label="Report Type">
                                        <span class="badge <?= h(reportTypeClass($row['type'] ?? '')) ?>">
                                            <?= h($row['type']) ?>
                                        </span>
                                        <span class="small-muted"><?= h($row['status']) ?></span>
                                    </td>
                                    <td data-label="Price" class="text-right money"><?= peso($row['price']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="9">
                                    <div class="empty-state">
                                        No report records found for the selected date range and report content.
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>

                    <?php if (!empty($reportRows)): ?>
                    <tfoot>
                        <tr>
                            <th colspan="4">Grand Total</th>
                            <th class="text-center"><?= number_format($totalPax) ?></th>
                            <th colspan="3"></th>
                            <th class="text-right"><?= peso($totalAmount) ?></th>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
            </div>

            <div class="report-footer">
                <div class="generated-box">
                    <strong>Generated by:</strong> <?= h($generatedBy) ?><br>
                    <strong>Date Generated:</strong> <?= h($generatedOn) ?><br>
                    <strong>Report Period:</strong> <?= date("F j, Y", strtotime($start_date)) ?> - <?= date("F j, Y", strtotime($end_date)) ?>
                </div>

                <div class="signature-box">
                    <div class="signature-line"></div>
                    <strong><?= h($generatedBy) ?></strong>
                    <span>Authorized Representative</span>
                </div>
            </div>

            <?php if (!$isPrintMode): ?>
                <p class="print-note">
                    Click <strong>Print / Save as PDF</strong>, then choose <strong>Save as PDF</strong> in the print dialog.
                </p>
            <?php endif; ?>

        </section>

    </main>
</div>

<?php if ($isPrintMode): ?>
<script>
window.addEventListener('load', function () {
    setTimeout(function () {
        window.print();
    }, 300);
});
</script>
<?php else: ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const dropdownBtn = document.getElementById('reportDropdownBtn');
    const dropdownMenu = document.getElementById('reportDropdownMenu');
    const dropdownText = document.getElementById('reportDropdownText');

    if (dropdownBtn && dropdownMenu && dropdownText) {
        dropdownBtn.addEventListener('click', function (event) {
            event.stopPropagation();
            dropdownMenu.classList.toggle('show');
        });

        dropdownMenu.addEventListener('click', function (event) {
            event.stopPropagation();
            updateReportDropdownText();
        });

        document.addEventListener('click', function () {
            dropdownMenu.classList.remove('show');
        });

        function updateReportDropdownText() {
            const labels = [];

            dropdownMenu.querySelectorAll('input[type="checkbox"]:checked').forEach(function (checkbox) {
                const text = checkbox.closest('label').querySelector('span').textContent.trim();
                labels.push(text);
            });

            dropdownText.textContent = labels.length > 0
                ? labels.join(', ')
                : 'Select report content';
        }
    }
});

function openSidebar() {
    const sidebar = document.getElementById('adminSidebar');
    const overlay = document.getElementById('sidebarOverlay');

    if (sidebar) sidebar.classList.add('show');
    if (overlay) overlay.classList.add('show');

    document.body.classList.add('sidebar-open');
}

function closeSidebar() {
    const sidebar = document.getElementById('adminSidebar');
    const overlay = document.getElementById('sidebarOverlay');

    if (sidebar) sidebar.classList.remove('show');
    if (overlay) overlay.classList.remove('show');

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
<?php endif; ?>

</body>
</html>