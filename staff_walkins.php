<?php
session_start();
include 'db.php';

if (!isset($_SESSION['staff_id']) || ($_SESSION['role'] ?? '') !== 'staff') {
    header("Location: admin_login.php");
    exit();
}

/* SHOP SETTINGS */
$settings = $conn->query("SELECT * FROM settings LIMIT 1");
$shop = $settings ? $settings->fetch_assoc() : null;

/* CHECK IF barber_leaves TABLE EXISTS */
$leaveTableExists = false;
$checkLeaveTable = $conn->query("SHOW TABLES LIKE 'barber_leaves'");
if ($checkLeaveTable && $checkLeaveTable->num_rows > 0) {
    $leaveTableExists = true;
}

/* GET MAIN SERVICES (exclude optional services) */
$mainServices = $conn->query("
    SELECT s.id, s.name, s.price
    FROM services s
    LEFT JOIN optional_services os ON s.id = os.service_id
    WHERE os.service_id IS NULL
    ORDER BY s.name ASC
");

/* GET OPTIONAL SERVICES */
$optionalServices = $conn->query("
    SELECT s.id, s.name, s.price
    FROM services s
    INNER JOIN optional_services os ON s.id = os.service_id
    ORDER BY s.name ASC
");

/* GET ALL SERVICES FOR COMPLETE MODAL */
$serviceCatalog = [];
$allServicesQuery = $conn->query("
    SELECT s.id, s.name, s.price, s.duration_minutes
    FROM services s
    ORDER BY s.name ASC
");
if ($allServicesQuery) {
    while ($srv = $allServicesQuery->fetch_assoc()) {
        $serviceCatalog[(int)$srv['id']] = [
            'id' => (int)$srv['id'],
            'name' => $srv['name'],
            'price' => (float)$srv['price'],
            'duration_minutes' => (int)$srv['duration_minutes']
        ];
    }
}

/* ADD WALK-IN */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_walkin'])) {
    $customer_name = trim($_POST['customer_name'] ?? '');
    $main_service_id = intval($_POST['main_service_id'] ?? 0);
    $addon_ids = $_POST['addon_services'] ?? [];

    if ($customer_name === '' || $main_service_id <= 0) {
        header("Location: staff_walkins.php");
        exit();
    }

    $conn->begin_transaction();

    try {
        $stmt = $conn->prepare("
            SELECT id, name, price, duration_minutes
            FROM services
            WHERE id = ?
        ");
        $stmt->bind_param("i", $main_service_id);
        $stmt->execute();
        $mainService = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$mainService) {
            throw new Exception("Invalid main service.");
        }

        $total_amount = floatval($mainService['price']);

        $stmt = $conn->prepare("
            INSERT INTO walkins (customer_name, service_name, barber_id, total_amount, status)
            VALUES (?, ?, NULL, ?, 'waiting')
        ");
        $service_name = $mainService['name'];
        $stmt->bind_param("ssd", $customer_name, $service_name, $total_amount);
        $stmt->execute();
        $walkin_id = $stmt->insert_id;
        $stmt->close();

        $stmt = $conn->prepare("
            INSERT INTO walkin_services (walkin_id, service_id, price, duration_minutes, is_addon)
            VALUES (?, ?, ?, ?, 0)
        ");
        $main_price = floatval($mainService['price']);
        $main_duration = intval($mainService['duration_minutes']);
        $stmt->bind_param("iidi", $walkin_id, $main_service_id, $main_price, $main_duration);
        $stmt->execute();
        $stmt->close();

        if (!empty($addon_ids) && is_array($addon_ids)) {
            $addon_ids = array_map('intval', $addon_ids);
            $addon_ids = array_filter($addon_ids);

            if (!empty($addon_ids)) {
                $placeholders = implode(',', array_fill(0, count($addon_ids), '?'));
                $types = str_repeat('i', count($addon_ids));

                $sql = "
                    SELECT s.id, s.price, s.duration_minutes
                    FROM services s
                    INNER JOIN optional_services os ON s.id = os.service_id
                    WHERE s.id IN ($placeholders)
                ";

                $stmt = $conn->prepare($sql);
                $stmt->bind_param($types, ...$addon_ids);
                $stmt->execute();
                $addonResult = $stmt->get_result();

                $insertAddon = $conn->prepare("
                    INSERT INTO walkin_services (walkin_id, service_id, price, duration_minutes, is_addon)
                    VALUES (?, ?, ?, ?, 1)
                ");

                while ($addon = $addonResult->fetch_assoc()) {
                    $addon_id = intval($addon['id']);
                    $addon_price = floatval($addon['price']);
                    $addon_duration = intval($addon['duration_minutes']);

                    $insertAddon->bind_param("iidi", $walkin_id, $addon_id, $addon_price, $addon_duration);
                    $insertAddon->execute();

                    $total_amount += $addon_price;
                }

                $insertAddon->close();
                $stmt->close();
            }
        }

        $stmt = $conn->prepare("UPDATE walkins SET total_amount = ? WHERE id = ?");
        $stmt->bind_param("di", $total_amount, $walkin_id);
        $stmt->execute();
        $stmt->close();

        $conn->commit();
    } catch (Exception $e) {
        $conn->rollback();
    }

    header("Location: staff_walkins.php");
    exit();
}

/* START WALK-IN */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['start_walkin'])) {
    $walkin_id = intval($_POST['walkin_id'] ?? 0);
    $barber_id = intval($_POST['barber_id'] ?? 0);

    if ($walkin_id > 0 && $barber_id > 0) {
        $stmt = $conn->prepare("
            SELECT COALESCE(SUM(duration_minutes), 0) AS total_duration
            FROM walkin_services
            WHERE walkin_id = ?
        ");
        $stmt->bind_param("i", $walkin_id);
        $stmt->execute();
        $durationRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $total_duration = intval($durationRow['total_duration'] ?? 0);
        if ($total_duration <= 0) {
            $total_duration = 30;
        }

        $now = new DateTime('now', new DateTimeZone('Asia/Manila'));
        $start_now = $now->format('Y-m-d H:i:s');

        $end = clone $now;
        $end->modify("+{$total_duration} minutes");
        $estimated_end = $end->format('Y-m-d H:i:s');

        $stmt = $conn->prepare("
            SELECT id, status
            FROM barbers
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->bind_param("i", $barber_id);
        $stmt->execute();
        $barber = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $isAllowed = true;

        if (!$barber || ($barber['status'] ?? '') !== 'active') {
            $isAllowed = false;
        }

        if ($isAllowed) {
            $day_of_week = $now->format('l');
            $current_time = $now->format('H:i:s');
            $end_time_only = $end->format('H:i:s');

            $stmt = $conn->prepare("
                SELECT start_time, end_time
                FROM barber_schedules
                WHERE barber_id = ? AND day_of_week = ?
                LIMIT 1
            ");
            $stmt->bind_param("is", $barber_id, $day_of_week);
            $stmt->execute();
            $schedule = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$schedule) {
                $isAllowed = false;
            } else {
                if ($current_time < $schedule['start_time'] || $end_time_only > $schedule['end_time']) {
                    $isAllowed = false;
                }
            }
        }

        if ($isAllowed && $leaveTableExists) {
            $today_date = $now->format('Y-m-d');

            $stmt = $conn->prepare("
                SELECT COUNT(*) AS total
                FROM barber_leaves
                WHERE barber_id = ?
                  AND leave_date = ?
                  AND status = 'approved'
            ");
            $stmt->bind_param("is", $barber_id, $today_date);
            $stmt->execute();
            $leaveConflict = intval($stmt->get_result()->fetch_assoc()['total'] ?? 0);
            $stmt->close();

            if ($leaveConflict > 0) {
                $isAllowed = false;
            }
        }

        if ($isAllowed) {
            $stmt = $conn->prepare("
                SELECT COUNT(*) AS total
                FROM appointments
                WHERE barber_id = ?
                  AND status NOT IN ('canceled', 'cancelled', 'completed', 'no-show', 'rejected')
                  AND (? < end_time AND ? > start_time)
            ");
            $stmt->bind_param("iss", $barber_id, $start_now, $estimated_end);
            $stmt->execute();
            $appointmentConflict = intval($stmt->get_result()->fetch_assoc()['total'] ?? 0);
            $stmt->close();

            if ($appointmentConflict > 0) {
                $isAllowed = false;
            }
        }

        if ($isAllowed) {
            $stmt = $conn->prepare("
                SELECT COUNT(*) AS total
                FROM walkins
                WHERE barber_id = ?
                  AND status = 'ongoing'
                  AND id != ?
                  AND started_at IS NOT NULL
                  AND estimated_end_at IS NOT NULL
                  AND (? < estimated_end_at AND ? > started_at)
            ");
            $stmt->bind_param("iiss", $barber_id, $walkin_id, $start_now, $estimated_end);
            $stmt->execute();
            $walkinConflict = intval($stmt->get_result()->fetch_assoc()['total'] ?? 0);
            $stmt->close();

            if ($walkinConflict > 0) {
                $isAllowed = false;
            }
        }

        if ($isAllowed) {
            $stmt = $conn->prepare("
                UPDATE walkins
                SET barber_id = ?, status = 'ongoing', started_at = ?, estimated_end_at = ?, completed_at = NULL
                WHERE id = ?
            ");
            $stmt->bind_param("issi", $barber_id, $start_now, $estimated_end, $walkin_id);
            $stmt->execute();
            $stmt->close();
        }
    }

    header("Location: staff_walkins.php");
    exit();
}

/* COMPLETE WALK-IN WITH ADDITIONAL SERVICES */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['complete_walkin'])) {
    $walkin_id = intval($_POST['walkin_id'] ?? 0);
    $additional_services = $_POST['additional_services'] ?? [];
    if (!is_array($additional_services)) {
        $additional_services = [];
    }
    $additional_services = array_map('intval', $additional_services);

    if ($walkin_id > 0) {
        $conn->begin_transaction();

        try {
            $stmt = $conn->prepare("
                SELECT total_amount
                FROM walkins
                WHERE id = ?
                LIMIT 1
            ");
            $stmt->bind_param("i", $walkin_id);
            $stmt->execute();
            $walkinInfo = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$walkinInfo) {
                throw new Exception("Walk-in not found.");
            }

            $current_total = floatval($walkinInfo['total_amount']);
            $extra_total = 0;

            if (!empty($additional_services)) {
                $existingServiceIds = [];
                $stmt = $conn->prepare("
                    SELECT service_id
                    FROM walkin_services
                    WHERE walkin_id = ?
                ");
                $stmt->bind_param("i", $walkin_id);
                $stmt->execute();
                $existingRes = $stmt->get_result();
                while ($er = $existingRes->fetch_assoc()) {
                    $existingServiceIds[] = (int)$er['service_id'];
                }
                $stmt->close();

                $insertStmt = $conn->prepare("
                    INSERT INTO walkin_services (walkin_id, service_id, price, duration_minutes, is_addon)
                    VALUES (?, ?, ?, ?, 1)
                ");

                foreach ($additional_services as $service_id) {
                    if ($service_id <= 0 || in_array($service_id, $existingServiceIds, true) || !isset($serviceCatalog[$service_id])) {
                        continue;
                    }

                    $srv = $serviceCatalog[$service_id];
                    $srvPrice = (float)$srv['price'];
                    $srvDuration = (int)$srv['duration_minutes'];

                    $insertStmt->bind_param("iidi", $walkin_id, $service_id, $srvPrice, $srvDuration);
                    $insertStmt->execute();

                    $extra_total += $srvPrice;
                }

                $insertStmt->close();
            }

            $new_total = $current_total + $extra_total;

            $stmt = $conn->prepare("
                UPDATE walkins
                SET total_amount = ?, status = 'completed', completed_at = NOW()
                WHERE id = ?
            ");
            $stmt->bind_param("di", $new_total, $walkin_id);
            $stmt->execute();
            $stmt->close();

            $conn->commit();
        } catch (Exception $e) {
            $conn->rollback();
        }
    }

    header("Location: staff_walkins.php");
    exit();
}

/* UPDATE STATUS */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $walkin_id = intval($_POST['walkin_id'] ?? 0);
    $new_status = $_POST['new_status'] ?? '';

    if ($walkin_id > 0 && in_array($new_status, ['waiting', 'ongoing', 'cancelled'], true)) {
        if ($new_status === 'cancelled') {
            $stmt = $conn->prepare("
                UPDATE walkins
                SET status = ?, barber_id = NULL, started_at = NULL, estimated_end_at = NULL, completed_at = NULL
                WHERE id = ?
            ");
            $stmt->bind_param("si", $new_status, $walkin_id);
        } else {
            $stmt = $conn->prepare("
                UPDATE walkins
                SET status = ?, completed_at = NULL
                WHERE id = ?
            ");
            $stmt->bind_param("si", $new_status, $walkin_id);
        }

        $stmt->execute();
        $stmt->close();
    }

    header("Location: staff_walkins.php");
    exit();
}

/* GET TODAY WALKINS WITH TOTAL DURATION */
$walkins = $conn->query("
    SELECT 
        w.*,
        b.name AS barber_name,
        COALESCE(SUM(ws.duration_minutes), 0) AS total_duration,
        GROUP_CONCAT(s.name ORDER BY ws.is_addon ASC, s.name ASC SEPARATOR ', ') AS all_services,
        GROUP_CONCAT(ws.service_id ORDER BY ws.service_id SEPARATOR ',') AS service_ids
    FROM walkins w
    LEFT JOIN barbers b ON w.barber_id = b.id
    LEFT JOIN walkin_services ws ON w.id = ws.walkin_id
    LEFT JOIN services s ON ws.service_id = s.id
    WHERE DATE(w.created_at) = CURDATE()
    GROUP BY w.id
    ORDER BY FIELD(w.status, 'waiting', 'ongoing', 'completed', 'cancelled'), w.created_at ASC
");

/* STATS */
$totalToday = $conn->query("SELECT COUNT(*) c FROM walkins WHERE DATE(created_at)=CURDATE()")->fetch_assoc()['c'] ?? 0;
$waitingCount = $conn->query("SELECT COUNT(*) c FROM walkins WHERE status='waiting' AND DATE(created_at)=CURDATE()")->fetch_assoc()['c'] ?? 0;
$ongoingCount = $conn->query("SELECT COUNT(*) c FROM walkins WHERE status='ongoing' AND DATE(created_at)=CURDATE()")->fetch_assoc()['c'] ?? 0;
$completedCount = $conn->query("SELECT COUNT(*) c FROM walkins WHERE status='completed' AND DATE(created_at)=CURDATE()")->fetch_assoc()['c'] ?? 0;

function getAvailableBarbersForWalkin(mysqli $conn, int $walkinDuration): array
{
    $leaveTableExists = false;
    $checkLeaveTable = $conn->query("SHOW TABLES LIKE 'barber_leaves'");
    if ($checkLeaveTable && $checkLeaveTable->num_rows > 0) {
        $leaveTableExists = true;
    }

    if ($walkinDuration <= 0) {
        $walkinDuration = 30;
    }

    $now = new DateTime('now', new DateTimeZone('Asia/Manila'));
    $start_now = $now->format('Y-m-d H:i:s');

    $end = clone $now;
    $end->modify("+{$walkinDuration} minutes");
    $estimated_end = $end->format('Y-m-d H:i:s');

    $today_date = $now->format('Y-m-d');
    $day_of_week = $now->format('l');
    $start_time_only = $now->format('H:i:s');
    $end_time_only = $end->format('H:i:s');

    $sql = "
        SELECT b.id, b.name
        FROM barbers b
        JOIN barber_schedules bs ON b.id = bs.barber_id
        WHERE b.status = 'active'
          AND bs.day_of_week = ?
          AND ? >= bs.start_time
          AND ? <= bs.end_time
          AND b.id NOT IN (
              SELECT a.barber_id
              FROM appointments a
              WHERE a.barber_id IS NOT NULL
                AND a.status NOT IN ('canceled', 'cancelled', 'completed', 'no-show', 'rejected')
                AND (? < a.end_time AND ? > a.start_time)
          )
          AND b.id NOT IN (
              SELECT w.barber_id
              FROM walkins w
              WHERE w.barber_id IS NOT NULL
                AND w.status = 'ongoing'
                AND w.started_at IS NOT NULL
                AND w.estimated_end_at IS NOT NULL
                AND (? < w.estimated_end_at AND ? > w.started_at)
          )
    ";

    if ($leaveTableExists) {
        $sql .= "
          AND b.id NOT IN (
              SELECT bl.barber_id
              FROM barber_leaves bl
              WHERE bl.status = 'approved'
                AND bl.leave_date = ?
          )
        ";
    }

    $sql .= " ORDER BY b.name ASC";

    $stmt = $conn->prepare($sql);

    if ($leaveTableExists) {
        $stmt->bind_param(
            "ssssssss",
            $day_of_week,
            $start_time_only,
            $end_time_only,
            $start_now,
            $estimated_end,
            $start_now,
            $estimated_end,
            $today_date
        );
    } else {
        $stmt->bind_param(
            "sssssss",
            $day_of_week,
            $start_time_only,
            $end_time_only,
            $start_now,
            $estimated_end,
            $start_now,
            $estimated_end
        );
    }

    $stmt->execute();
    $result = $stmt->get_result();

    $barbers = [];
    while ($row = $result->fetch_assoc()) {
        $barbers[] = $row;
    }

    $stmt->close();
    return $barbers;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Staff Walk-in Queue | <?= htmlspecialchars($shop['shop_name'] ?? 'Barbershop') ?></title>
<link rel="stylesheet" href="styles.css">

<style>
* { box-sizing: border-box; }

body{
    margin:0;
    font-family:'Poppins',sans-serif;
    background:#f4f4f4;
    color:#1f2937;
    overflow-x:hidden;
}
html { overflow-x:hidden; }
body.sidebar-open { overflow:hidden; }

.mobile-topbar {
    display:none;
    width:100%;
    background:#111;
    color:#fff;
    padding:14px 16px;
    align-items:center;
    justify-content:space-between;
    position:sticky;
    top:0;
    z-index:1200;
}
.mobile-topbar strong {
    font-size:15px;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
    max-width:calc(100vw - 90px);
}
.menu-btn {
    width:42px;
    height:42px;
    border:none;
    border-radius:10px;
    background:rgba(255,255,255,0.1);
    color:#fff;
    font-size:20px;
    cursor:pointer;
    flex-shrink:0;
}
.sidebar-overlay {
    display:none;
    position:fixed;
    inset:0;
    background:rgba(15, 23, 42, 0.55);
    z-index:1190;
}
.sidebar-overlay.show {
    display:block;
}

.admin-layout{
    display:flex;
    min-height:100vh;
}
.admin-sidebar{
    width:260px;
    min-width:260px;
    background:#111;
    color:#fff;
    padding:24px 18px;
    position:sticky;
    top:0;
    height:100vh;
    overflow-y:auto;
    flex-shrink:0;
    z-index:1201;
    transition:transform 0.25s ease;
}
.admin-brand{
    text-align:center;
    margin-bottom:30px;
}
.admin-brand img{
    width:80px;
    height:80px;
    object-fit:cover;
    border-radius:50%;
    background:#fff;
    padding:6px;
    margin-bottom:10px;
}
.admin-brand h2{
    font-size:18px;
    margin:0;
    line-height:1.4;
}
.admin-brand p{
    margin:6px 0 0;
    color:#bbb;
    font-size:13px;
}
.admin-nav a{
    display:block;
    color:#fff;
    text-decoration:none;
    padding:12px 14px;
    border-radius:10px;
    margin-bottom:8px;
    background:transparent;
    transition:0.2s ease;
}
.admin-nav a:hover,
.admin-nav a.active{
    background:#2563eb;
    box-shadow:0 10px 22px rgba(37,99,235,0.22);
}

.admin-main{
    flex:1;
    min-width:0;
    padding:28px;
}

.page-header{
    display:flex;
    justify-content:space-between;
    align-items:flex-start;
    gap:16px;
    margin-bottom:20px;
    flex-wrap:wrap;
}
.page-header h1{
    margin:0 0 6px;
    font-size:30px;
    color:#111827;
}
.page-header p{
    margin:0;
    color:#6b7280;
}

.stats{
    display:grid;
    grid-template-columns:repeat(4, minmax(160px,1fr));
    gap:15px;
    margin-bottom:25px;
}
.stat-box{
    background:#fff;
    padding:20px;
    border-radius:18px;
    box-shadow:0 8px 24px rgba(15,23,42,0.06);
    border:1px solid #edf2f7;
    border-left:6px solid #2563eb;
}
.stat-box .label{
    margin:0 0 8px;
    font-size:13px;
    color:#6b7280;
    font-weight:600;
}
.stat-box h2{
    margin:0;
    font-size:28px;
    font-weight:700;
    color:#111827;
}

.content-grid{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:20px;
    align-items:start;
}
.panel{
    background:#fff;
    padding:20px;
    border-radius:22px;
    box-shadow:0 10px 30px rgba(15,23,42,0.06);
    border:1px solid #edf2f7;
    margin-bottom:20px;
}
.panel h2{
    margin-top:0;
    margin-bottom:16px;
    font-size:22px;
    color:#111827;
}

.walkin-form label{
    display:block;
    margin-top:10px;
    font-weight:600;
    color:#374151;
    font-size:14px;
}
input,select,button{
    width:100%;
    padding:11px 13px;
    margin-top:6px;
    border-radius:12px;
    border:1px solid #d1d9e6;
    box-sizing:border-box;
    font-size:14px;
}
input:focus, select:focus{
    outline:none;
    border-color:#2563eb;
    box-shadow:0 0 0 4px rgba(37, 99, 235, 0.12);
}
button{
    background:#2563eb;
    color:#fff;
    border:none;
    cursor:pointer;
    margin-top:14px;
    font-weight:600;
    transition:0.18s ease;
}
button:hover{
    transform:translateY(-1px);
}

.btn-cancel{
    background:#6b7280 !important;
    color:#fff !important;
}

.btn-cancel:hover{
    background:#4b5563 !important;
}

.addon-list{
    margin-top:10px;
    border:1px solid #eee;
    border-radius:14px;
    padding:12px;
    background:#fafafa;
}
.addon-item{
    display:flex;
    align-items:center;
    gap:10px;
    margin-bottom:10px;
}
.addon-item:last-child{
    margin-bottom:0;
}
.addon-item input[type="checkbox"]{
    width:auto;
    margin:0;
}
.addon-item label{
    margin:0;
    font-weight:normal;
}

.queue-card{
    background:#fafafa;
    padding:15px;
    border-radius:14px;
    margin-bottom:12px;
    border:1px solid #eee;
}
.queue-card h3{
    margin:0 0 10px;
    color:#111827;
}
.queue-card p{
    margin:0;
    line-height:1.65;
    color:#374151;
}

.status{
    display:inline-block;
    padding:6px 10px;
    border-radius:999px;
    font-size:12px;
    font-weight:700;
    margin-top:10px;
    text-transform:capitalize;
}
.waiting{background:#fff4cc; color:#8a6d00;}
.ongoing{background:#e7dcff; color:#5b21b6;}
.completed{background:#dff6dd; color:#256029;}
.cancelled{background:#eee; color:#555;}

.queue-actions{
    margin-top:12px;
    display:flex;
    align-items:center;
    gap:12px;
    flex-wrap:wrap;
}

.queue-actions form{
    display:flex;
    align-items:center;
    gap:12px;
    margin:0;
}

.assign-row{
    display:flex;
    align-items:center;
    gap:12px;
    flex-wrap:nowrap;
    margin:0;
    flex:1;
}

.assign-row select{
    flex:1;
    min-width:260px;
    width:auto !important;
    margin-top:0 !important;
    height:46px;
}

.assign-row button{
    width:auto !important;
    min-width:90px;
    margin-top:0 !important;
    height:46px;
    padding:0 18px;
    flex:0 0 auto;
}

.queue-actions > form:last-child button{
    width:auto !important;
    min-width:90px;
    height:46px;
    margin-top:0 !important;
    padding:0 18px;
    flex:0 0 auto;
}

.btn-complete{
    background:#2563eb !important;
    color:#fff !important;
    width:auto !important;
    min-width:90px !important;
    height:46px !important;
    padding:0 18px !important;
    margin-top:0 !important;
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    flex:0 0 auto !important;
}

.small-note{
    color:#6b7280;
    font-size:13px;
    margin-top:8px;
    flex-basis:100%;
}

.modal-backdrop {
    display:none;
    position:fixed;
    inset:0;
    background:rgba(15, 23, 42, 0.6);
    z-index:1300;
    padding:20px;
    overflow-y:auto;
}
.modal-backdrop.show {
    display:flex;
    align-items:center;
    justify-content:center;
}
.modal {
    width:100%;
    max-width:760px;
    background:#fff;
    border-radius:24px;
    box-shadow:0 30px 80px rgba(15, 23, 42, 0.2);
    max-height:92vh;
    overflow-y:auto;
    position:relative;
}
.modal-small { max-width:640px; }
.modal-header {
    padding:24px 26px 16px;
    border-bottom:1px solid #edf2f7;
    position:sticky;
    top:0;
    background:#fff;
    z-index:2;
}
.modal-header h3 {
    margin:0;
    font-size:24px;
    color:#111827;
}
.modal-header p {
    margin:6px 0 0;
    color:#6b7280;
}
.modal-close {
    position:absolute;
    top:16px;
    right:16px;
    width:40px;
    height:40px;
    border:none;
    border-radius:999px;
    background:#f3f4f6;
    font-size:18px;
    font-weight:700;
    cursor:pointer;
    color:#111827;
}
.modal-body {
    padding:24px 26px 26px;
}
.modal .form-group { margin-bottom:18px; }
.modal .form-group label {
    display:block;
    margin-bottom:8px;
    font-weight:600;
    color:#374151;
}
.modal .form-group input {
    width:100%;
    padding:12px 14px;
    border:1px solid #d1d9e6;
    border-radius:12px;
    font-size:14px;
    outline:none;
}
.modal-services-list {
    max-height:260px;
    overflow-y:auto;
    padding-right:4px;
}
.service-option {
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    padding:11px 12px;
    border:1px solid #e5e7eb;
    border-radius:12px;
    margin-bottom:10px;
    background:#fff;
}
.service-option label {
    margin:0;
    display:flex;
    align-items:center;
    gap:10px;
    font-weight:500;
    cursor:pointer;
}
.service-option input[type="checkbox"] {
    width:auto;
    margin:0;
}
.summary-box {
    background:#f8fafc;
    border:1px solid #e2e8f0;
    border-radius:16px;
    padding:18px;
    margin-top:16px;
}
.summary-box p {
    margin:0 0 10px;
    line-height:1.65;
}
.summary-box p:last-child { margin-bottom:0; }
.modal-actions {
    display:flex;
    justify-content:flex-end;
    gap:10px;
    flex-wrap:wrap;
    margin-top:22px;
}
.btn-secondary {
    background:#e5e7eb !important;
    color:#111827 !important;
}

@media (max-width: 1200px){
    .stats{
        grid-template-columns:repeat(2, minmax(160px,1fr));
    }
}
@media (max-width: 992px){
    .content-grid{
        grid-template-columns:1fr;
    }
}
@media (max-width: 900px){
    .mobile-topbar{
        display:flex;
    }

    .admin-layout{
        display:block;
    }

    .admin-sidebar{
        position:fixed;
        top:0;
        left:0;
        height:100vh;
        width:min(82vw, 300px);
        min-width:min(82vw, 300px);
        max-width:300px;
        transform:translateX(-105%);
        box-shadow:0 24px 50px rgba(0,0,0,0.28);
    }

    .admin-sidebar.show{
        transform:translateX(0);
    }

    .admin-main{
        padding:18px;
    }
}
@media (max-width: 768px){
    .stats{
        grid-template-columns:repeat(2,1fr);
    }
}
@media (max-width: 640px){
    .stats{
        grid-template-columns:1fr;
    }

    .queue-actions{
        display:flex;
        flex-direction:column;
        align-items:stretch;
        gap:8px;
    }

    .queue-actions form{
        width:100%;
        flex-direction:column;
        align-items:stretch;
        gap:8px;
        margin-right:0;
    }

    .assign-row{
        flex-direction:column;
        align-items:stretch;
        width:100%;
    }

    .assign-row select,
    .assign-row button,
    .queue-actions > form:last-child button,
    .btn-complete{
        width:100% !important;
        min-width:0 !important;
    }

    .panel,
    .admin-main{
        padding-left:16px;
        padding-right:16px;
    }

    .admin-main{
        padding-top:14px;
        padding-bottom:14px;
    }

    .modal-header,
    .modal-body {
        padding-left:16px;
        padding-right:16px;
    }

    .modal-actions {
        flex-direction:column;
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
        <p>Staff Portal</p>
    </div>

    <nav class="admin-nav">
        <a href="staff_dashboard.php">Dashboard</a>
        <a href="staff_appointments.php">Appointments</a>
        <a href="staff_walkins.php" class="active">Walk-in Queue</a>
        <a href="admin_logout.php">Logout</a>
    </nav>
</aside>

<div class="admin-main">

    <div class="page-header">
        <div>
            <h1>Walk-in Queue</h1>
            <p>Manage today’s walk-ins, assign available barbers, and update queue progress.</p>
        </div>
    </div>

    <div class="stats">
        <div class="stat-box">
            <div class="label">Total Today</div>
            <h2><?= $totalToday ?></h2>
        </div>

        <div class="stat-box">
            <div class="label">Waiting</div>
            <h2><?= $waitingCount ?></h2>
        </div>

        <div class="stat-box">
            <div class="label">Ongoing</div>
            <h2><?= $ongoingCount ?></h2>
        </div>

        <div class="stat-box">
            <div class="label">Completed</div>
            <h2><?= $completedCount ?></h2>
        </div>
    </div>

    <div class="content-grid">

        <div class="panel">
            <h2>Add Walk-in</h2>

            <form method="POST" class="walkin-form">
                <input type="hidden" name="add_walkin" value="1">

                <label>Customer Name</label>
                <input type="text" name="customer_name" required>

                <label>Main Service</label>
                <select name="main_service_id" id="main_service_id" onchange="updateTotal()" required>
                    <option value="">Select Main Service</option>
                    <?php if ($mainServices && $mainServices->num_rows > 0): ?>
                        <?php while($service = $mainServices->fetch_assoc()): ?>
                            <option value="<?= $service['id'] ?>" data-price="<?= $service['price'] ?>">
                                <?= htmlspecialchars($service['name']) ?> - ₱<?= number_format($service['price'], 2) ?>
                            </option>
                        <?php endwhile; ?>
                    <?php endif; ?>
                </select>

                <label>Optional / Add-on Services</label>
                <div class="addon-list">
                    <?php if ($optionalServices && $optionalServices->num_rows > 0): ?>
                        <?php while($addon = $optionalServices->fetch_assoc()): ?>
                            <div class="addon-item">
                                <input
                                    type="checkbox"
                                    name="addon_services[]"
                                    value="<?= $addon['id'] ?>"
                                    data-price="<?= $addon['price'] ?>"
                                    onchange="updateTotal()"
                                    id="addon_<?= $addon['id'] ?>"
                                >
                                <label for="addon_<?= $addon['id'] ?>">
                                    <?= htmlspecialchars($addon['name']) ?> - ₱<?= number_format($addon['price'], 2) ?>
                                </label>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <p style="margin:0;">No optional services available.</p>
                    <?php endif; ?>
                </div>

                <label>Total Amount</label>
                <input type="text" id="total_amount" readonly placeholder="0.00">

                <button type="submit">Add Walk-in</button>
            </form>
        </div>

        <div class="panel">
            <h2>Today’s Queue</h2>

            <?php if ($walkins && $walkins->num_rows > 0): ?>
                <?php $num = 1; while($w = $walkins->fetch_assoc()): ?>
                    <?php
                        $availableBarbers = [];
                        if ($w['status'] === 'waiting') {
                            $availableBarbers = getAvailableBarbersForWalkin($conn, (int)$w['total_duration']);
                        }
                    ?>
                    <div class="queue-card">
                        <h3>#<?= $num ?> - <?= htmlspecialchars($w['customer_name']) ?></h3>
                        <p>
                            <strong>Services:</strong> <?= htmlspecialchars($w['all_services'] ?: $w['service_name']) ?><br>
                            <strong>Total:</strong> ₱<?= number_format((float)$w['total_amount'], 2) ?><br>
                            <strong>Duration:</strong> <?= (int)$w['total_duration'] ?> mins<br>
                            <strong>Barber:</strong> <?= htmlspecialchars($w['barber_name'] ?: 'Not assigned') ?><br>
                            <?php if (!empty($w['started_at'])): ?>
                                <strong>Started:</strong> <?= date("g:i A", strtotime($w['started_at'])) ?><br>
                            <?php endif; ?>
                            <?php if (!empty($w['estimated_end_at'])): ?>
                                <strong>Estimated End:</strong> <?= date("g:i A", strtotime($w['estimated_end_at'])) ?><br>
                            <?php endif; ?>
                        </p>

                        <span class="status <?= htmlspecialchars($w['status']) ?>">
                            <?= htmlspecialchars(ucfirst($w['status'])) ?>
                        </span>

                        <?php if ($w['status'] === 'waiting'): ?>
                            <div class="queue-actions">
                                <form method="POST" class="assign-row">
                                    <input type="hidden" name="start_walkin" value="1">
                                    <input type="hidden" name="walkin_id" value="<?= $w['id'] ?>">

                                    <select name="barber_id" required>
                                        <option value="">Select Available Barber</option>
                                        <?php foreach ($availableBarbers as $barber): ?>
                                            <option value="<?= (int)$barber['id'] ?>">
                                                <?= htmlspecialchars($barber['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>

                                    <button type="submit">Start</button>
                                </form>

                                <form method="POST">
                                    <input type="hidden" name="update_status" value="1">
                                    <input type="hidden" name="walkin_id" value="<?= $w['id'] ?>">
                                    <input type="hidden" name="new_status" value="cancelled">
                                    <button type="submit" class="btn-cancel">Cancel</button>
                                </form>

                                <?php if (empty($availableBarbers)): ?>
                                    <div class="small-note">No barbers are available right now for this walk-in.</div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($w['status'] === 'ongoing'): ?>
                            <div class="queue-actions">
                                <button
                                    type="button"
                                    class="btn-complete open-complete-modal"
                                    data-walkin-id="<?= (int)$w['id'] ?>"
                                    data-customer="<?= htmlspecialchars($w['customer_name'], ENT_QUOTES) ?>"
                                    data-current-total="<?= number_format((float)$w['total_amount'], 2, '.', '') ?>"
                                    data-existing-service-ids="<?= htmlspecialchars($w['service_ids'] ?? '', ENT_QUOTES) ?>"
                                >
                                    Complete
                                </button>

                                <form method="POST">
                                    <input type="hidden" name="update_status" value="1">
                                    <input type="hidden" name="walkin_id" value="<?= $w['id'] ?>">
                                    <input type="hidden" name="new_status" value="cancelled">
                                    <button type="submit" class="btn-cancel">Cancel</button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php $num++; endwhile; ?>
            <?php else: ?>
                <p>No walk-ins today.</p>
            <?php endif; ?>
        </div>

    </div>

</div>

</div>

<div class="modal-backdrop" id="completeWalkinModal">
    <div class="modal modal-small">
        <div class="modal-header">
            <h3>Complete Walk-in</h3>
            <p>Add any extra services before marking this walk-in as completed.</p>
            <button type="button" class="modal-close" data-close-modal="completeWalkinModal">&times;</button>
        </div>
        <div class="modal-body">
            <form method="POST">
                <input type="hidden" name="complete_walkin" value="1">
                <input type="hidden" name="walkin_id" id="completeWalkinId">

                <div class="form-group">
                    <label for="completeWalkinCustomer">Customer</label>
                    <input type="text" id="completeWalkinCustomer" readonly>
                </div>

                <div class="form-group">
                    <label>Additional Services</label>
                    <div class="modal-services-list">
                        <?php if (!empty($serviceCatalog)): ?>
                            <?php foreach ($serviceCatalog as $srv): ?>
                                <div class="service-option" data-service-id="<?= (int)$srv['id'] ?>">
                                    <label>
                                        <input
                                            type="checkbox"
                                            name="additional_services[]"
                                            value="<?= (int)$srv['id'] ?>"
                                            class="additional-service-checkbox"
                                            data-price="<?= number_format((float)$srv['price'], 2, '.', '') ?>"
                                        >
                                        <?= htmlspecialchars($srv['name']) ?>
                                    </label>
                                    <strong>₱<?= number_format((float)$srv['price'], 2) ?></strong>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p>No services found.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="summary-box">
                    <p><strong>Additional Services Total:</strong> ₱<span id="extraWalkinServicesTotalText">0.00</span></p>
                    <p><strong>Updated Total Amount:</strong> ₱<span id="newWalkinTotalText">0.00</span></p>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn-secondary" data-close-modal="completeWalkinModal">Close</button>
                    <button type="submit" class="btn-complete">Complete Walk-in</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function updateTotal() {
    let total = 0;

    const mainService = document.getElementById('main_service_id');
    const selectedMain = mainService.options[mainService.selectedIndex];
    const mainPrice = parseFloat(selectedMain.getAttribute('data-price') || 0);
    total += mainPrice;

    document.querySelectorAll('input[name="addon_services[]"]:checked').forEach(cb => {
        total += parseFloat(cb.getAttribute('data-price') || 0);
    });

    document.getElementById('total_amount').value = total ? total.toFixed(2) : '';
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

function openModal(id) {
    document.getElementById(id).classList.add('show');
}

function closeModal(id) {
    document.getElementById(id).classList.remove('show');
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

const walkinServiceCheckboxes = document.querySelectorAll('.additional-service-checkbox');
const walkinServiceOptions = document.querySelectorAll('.service-option');

let currentWalkinTotal = 0;

function resetWalkinCompleteSelections() {
    walkinServiceCheckboxes.forEach(cb => {
        cb.checked = false;
    });

    walkinServiceOptions.forEach(option => {
        option.style.display = '';
    });
}

function filterExistingWalkinServices(existingIds) {
    const existingSet = new Set(existingIds.map(id => String(id).trim()).filter(id => id !== ''));

    walkinServiceOptions.forEach(option => {
        const serviceId = option.getAttribute('data-service-id');
        const checkbox = option.querySelector('.additional-service-checkbox');

        if (existingSet.has(String(serviceId))) {
            option.style.display = 'none';
            if (checkbox) checkbox.checked = false;
        } else {
            option.style.display = '';
        }
    });
}

function updateWalkinCompleteSummary() {
    let extraTotal = 0;

    walkinServiceCheckboxes.forEach(cb => {
        const parent = cb.closest('.service-option');
        if (parent && parent.style.display !== 'none' && cb.checked) {
            extraTotal += parseFloat(cb.dataset.price || 0);
        }
    });

    const newTotal = currentWalkinTotal + extraTotal;

    document.getElementById('extraWalkinServicesTotalText').textContent = extraTotal.toFixed(2);
    document.getElementById('newWalkinTotalText').textContent = newTotal.toFixed(2);
}

walkinServiceCheckboxes.forEach(cb => {
    cb.addEventListener('change', updateWalkinCompleteSummary);
});

document.addEventListener('click', function(e) {
    const closeBtn = e.target.closest('[data-close-modal]');
    if (closeBtn) {
        closeModal(closeBtn.getAttribute('data-close-modal'));
        return;
    }

    const completeBtn = e.target.closest('.open-complete-modal');
    if (completeBtn) {
        document.getElementById('completeWalkinId').value = completeBtn.dataset.walkinId || '';
        document.getElementById('completeWalkinCustomer').value = completeBtn.dataset.customer || '';

        currentWalkinTotal = parseFloat(completeBtn.dataset.currentTotal || 0);

        const existingServiceIds = (completeBtn.dataset.existingServiceIds || '')
            .split(',')
            .map(id => String(id).trim())
            .filter(id => id !== '');

        resetWalkinCompleteSelections();
        filterExistingWalkinServices(existingServiceIds);
        updateWalkinCompleteSummary();

        openModal('completeWalkinModal');
        return;
    }
});

document.querySelectorAll('.modal-backdrop').forEach(backdrop => {
    backdrop.addEventListener('click', function(e) {
        if (e.target === this) {
            this.classList.remove('show');
        }
    });
});
</script>

</body>
</html>