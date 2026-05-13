<?php
session_start();
include 'db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

/* Collect from previous page */
$total_price         = floatval($_POST['total_price'] ?? 0);
$total_duration      = intval($_POST['total_duration'] ?? 0);
$total_persons       = intval($_POST['total_persons'] ?? 1);
$persons_payload_raw = $_POST['persons_payload'] ?? '';

$downpayment = $total_price * 0.15;

/* Load shop settings */
$settingsQuery = $conn->query("SELECT * FROM settings LIMIT 1");
$shop = $settingsQuery ? $settingsQuery->fetch_assoc() : [];

$shopName = $shop['shop_name'] ?? 'Fade Street Hair Studio';
$logo = !empty($shop['logo']) ? $shop['logo'] : 'logo.png';
$bannerImage = !empty($shop['banner_image']) ? $shop['banner_image'] : 'banner.jpg';

/* Basic validation */
$page_error = "";

if (empty($persons_payload_raw)) {
    $page_error = "Missing booking details. Please select your booking details again.";
}

$persons_payload = json_decode($persons_payload_raw, true);

if (!$page_error && (!is_array($persons_payload) || count($persons_payload) <= 0)) {
    $page_error = "Invalid person booking details. Please try again.";
}

if ($page_error) {
    $persons_payload = [];
}

if ($total_persons <= 0 && is_array($persons_payload)) {
    $total_persons = count($persons_payload);
}

/* Helpers */
function formatScheduleDisplay($date, $start, $end) {
    if (empty($date)) {
        return 'Not selected';
    }

    $dateFormatted = date("F j, Y", strtotime($date));

    if (!empty($start) && !empty($end)) {
        return $dateFormatted . ' | ' . $start . ' - ' . $end;
    }

    return $dateFormatted;
}

/* Fetch services and barbers for each person */
$allServiceIds = [];
$allBarberIds = [];

if (!$page_error) {
    foreach ($persons_payload as $person) {
        $main_service_id = intval($person['service_id'] ?? 0);
        if ($main_service_id > 0) {
            $allServiceIds[] = $main_service_id;
        }

        $barber_id = intval($person['barber_id'] ?? 0);
        if ($barber_id > 0) {
            $allBarberIds[] = $barber_id;
        }

        $selected_services = $person['selected_services'] ?? [];
        if (is_array($selected_services)) {
            foreach ($selected_services as $sid) {
                $sid = intval($sid);
                if ($sid > 0) {
                    $allServiceIds[] = $sid;
                }
            }
        }
    }
}

$allServiceIds = array_values(array_unique($allServiceIds));
$allBarberIds = array_values(array_unique($allBarberIds));

$servicesMap = [];

if (!empty($allServiceIds)) {
    $placeholders = implode(',', array_fill(0, count($allServiceIds), '?'));
    $types = str_repeat('i', count($allServiceIds));

    $stmt = $conn->prepare("SELECT id, name, price, duration_minutes FROM services WHERE id IN ($placeholders)");
    $stmt->bind_param($types, ...$allServiceIds);
    $stmt->execute();

    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $servicesMap[(int)$row['id']] = $row;
    }

    $stmt->close();
}

$barbersMap = [];

if (!empty($allBarberIds)) {
    $placeholders = implode(',', array_fill(0, count($allBarberIds), '?'));
    $types = str_repeat('i', count($allBarberIds));

    $stmt = $conn->prepare("SELECT id, name FROM barbers WHERE id IN ($placeholders)");
    $stmt->bind_param($types, ...$allBarberIds);
    $stmt->execute();

    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $barbersMap[(int)$row['id']] = $row['name'];
    }

    $stmt->close();
}

/* Normalize persons for display */
$persons_display = [];

if (!$page_error) {
    foreach ($persons_payload as $person) {
        $person_no   = intval($person['person_no'] ?? 0);
        $full_name   = trim($person['full_name'] ?? 'Account Owner');
        $service_id  = intval($person['service_id'] ?? 0);
        $barber_id   = intval($person['barber_id'] ?? 0);

        $selected_date = trim($person['selected_date'] ?? '');

        $selected_start_time = trim(
            $person['selected_start_time']
            ?? $person['selected_start_24']
            ?? ''
        );

        $selected_end_time = trim(
            $person['selected_end_time']
            ?? $person['selected_end_24']
            ?? ''
        );

        $selected_services = $person['selected_services'] ?? [];
        if (!is_array($selected_services)) {
            $selected_services = [];
        }

        $main_service_name = 'N/A';
        $addon_names = [];
        $person_total_price = 0;
        $person_total_duration = 0;

        if ($service_id > 0 && isset($servicesMap[$service_id])) {
            $main_service_name = $servicesMap[$service_id]['name'];
            $person_total_price += floatval($servicesMap[$service_id]['price']);
            $person_total_duration += intval($servicesMap[$service_id]['duration_minutes']);
        }

        foreach ($selected_services as $sid) {
            $sid = intval($sid);

            if ($sid > 0 && isset($servicesMap[$sid])) {
                if ($sid !== $service_id) {
                    $addon_names[] = $servicesMap[$sid]['name'];
                }

                $person_total_price += floatval($servicesMap[$sid]['price']);
                $person_total_duration += intval($servicesMap[$sid]['duration_minutes']);
            }
        }

        $persons_display[] = [
            'person_no' => $person_no,
            'full_name' => $full_name,
            'barber_name' => $barbersMap[$barber_id] ?? ($person['barber_name'] ?? 'Unknown'),
            'selected_date' => $selected_date,
            'selected_start_time' => $selected_start_time,
            'selected_end_time' => $selected_end_time,
            'schedule_display' => formatScheduleDisplay($selected_date, $selected_start_time, $selected_end_time),
            'main_service_name' => $main_service_name,
            'addon_names' => $addon_names,
            'total_price' => $person_total_price,
            'total_duration' => $person_total_duration
        ];
    }
}

/* Fetch GCash settings */
$gcash_qr = "images/default_qr.png";
$gcash_number = "";
$gcash_name = "";

$res = $conn->query("SELECT gcash_number, gcash_qr, gcash_name FROM payment_settings ORDER BY id ASC LIMIT 1");

if ($res && $res->num_rows > 0) {
    $row = $res->fetch_assoc();

    $gcash_number = trim($row['gcash_number'] ?? '');
    $gcash_name = trim($row['gcash_name'] ?? '');

    if (!empty($row['gcash_qr'])) {
        $dbQr = trim($row['gcash_qr']);

        if (strpos($dbQr, 'images/') === 0) {
            $gcash_qr = $dbQr;
        } else {
            $gcash_qr = 'images/' . ltrim($dbQr, '/');
        }
    }
}

$hasCompanion = count($persons_display) > 1;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Payment | <?= htmlspecialchars($shopName) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link rel="stylesheet" href="styles.css">

    <style>
        /* =========================================
           MODERN PAYMENT PAGE
        ========================================= */

        .payment-page {
            min-height: 100vh;
            padding: 150px 24px 70px;
            background:
                radial-gradient(circle at top left, rgba(11, 61, 145, 0.13), transparent 34%),
                linear-gradient(180deg, #ffffff 0%, #f7f9fc 48%, #ffffff 100%);
            color: #07172f;
        }

        .payment-page-wrapper {
            width: min(1180px, 100%);
            margin: 0 auto;
        }

        .payment-hero {
            position: relative;
            overflow: hidden;
            border-radius: 32px;
            padding: 34px;
            margin-bottom: 26px;
            background:
                linear-gradient(135deg, rgba(7, 23, 47, 0.94), rgba(6, 31, 74, 0.90)),
                url("images/<?= htmlspecialchars($bannerImage) ?>") center/cover no-repeat;
            color: #ffffff;
            box-shadow: 0 18px 45px rgba(7, 23, 47, 0.18);
        }

        .payment-hero::after {
            content: "";
            position: absolute;
            right: -90px;
            bottom: -90px;
            width: 260px;
            height: 260px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.11);
        }

        .payment-hero-inner {
            position: relative;
            z-index: 2;
            display: grid;
            grid-template-columns: auto 1fr;
            gap: 20px;
            align-items: center;
        }

        .payment-hero-logo {
            width: 88px;
            height: 88px;
            object-fit: contain;
            background: #ffffff;
            border-radius: 24px;
            padding: 8px;
            box-shadow: 0 14px 30px rgba(0,0,0,0.20);
        }

        .payment-hero h1 {
            margin: 0;
            font-size: clamp(2rem, 4vw, 3.2rem);
            line-height: 1.05;
            letter-spacing: -1.4px;
            font-weight: 900;
        }

        .payment-hero p {
            margin: 10px 0 0;
            max-width: 720px;
            color: #e8eefc;
            line-height: 1.65;
            font-weight: 700;
        }

        .payment-page-layout {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 360px;
            gap: 26px;
            align-items: start;
        }

        .payment-page-left {
            display: flex;
            flex-direction: column;
            gap: 24px;
            min-width: 0;
        }

        .payment-page-right {
            position: sticky;
            top: 125px;
            min-width: 0;
        }

        .payment-box,
        .booking-policy,
        .payment-side-card {
            background: rgba(255,255,255,0.96);
            border: 1px solid #dbe3ef;
            border-radius: 28px;
            padding: 26px;
            box-sizing: border-box;
            box-shadow: 0 16px 40px rgba(7, 23, 47, 0.08);
        }

        .payment-box h2,
        .booking-policy h2,
        .payment-side-card h3 {
            margin: 0 0 18px;
            color: #07172f;
            text-align: left;
            font-size: clamp(1.45rem, 3vw, 2rem);
            line-height: 1.1;
            letter-spacing: -0.8px;
            font-weight: 900;
        }

        .payment-summary-list {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
            margin-bottom: 18px;
        }

        .payment-summary-item {
            padding: 16px;
            border-radius: 18px;
            background: #f8fbff;
            border: 1px solid #dbe8ff;
        }

        .payment-summary-item span {
            display: block;
            color: #64748b;
            font-size: 0.86rem;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            margin-bottom: 6px;
        }

        .payment-summary-item strong {
            display: block;
            color: #07172f;
            font-size: 1.08rem;
            font-weight: 900;
        }

        .person-payment-block {
            margin-top: 16px;
            padding: 18px;
            border: 1px solid #dbe3ef;
            border-radius: 20px;
            background: #ffffff;
            box-shadow: 0 10px 24px rgba(7,23,47,0.05);
        }

        .person-payment-block h4 {
            margin: 0 0 14px;
            color: #0b3d91;
            font-size: 1.15rem;
            font-weight: 900;
        }

        .summary-line {
            margin: 9px 0;
            font-size: 0.96rem;
            color: #475569;
            line-height: 1.6;
            word-wrap: break-word;
            font-weight: 700;
        }

        .summary-line strong {
            color: #07172f;
        }

        .payment-totals {
            margin-top: 18px;
            padding: 18px;
            border-radius: 20px;
            background: linear-gradient(135deg, #eaf1ff, #ffffff);
            border: 1px solid #dbe8ff;
        }

        .payment-totals p {
            margin: 8px 0;
            color: #475569;
            font-weight: 800;
            line-height: 1.5;
        }

        .payment-totals strong {
            color: #07172f;
        }

        .payment-total-highlight {
            color: #0b3d91;
            font-weight: 900;
        }

        .payment-instruction {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 270px;
            gap: 24px;
            align-items: start;
            margin-bottom: 20px;
        }

        .payment-steps {
            padding: 18px;
            border-radius: 22px;
            background: #f8fbff;
            border: 1px solid #dbe3ef;
        }

        .payment-steps ol {
            margin: 0 0 14px 22px;
            padding: 0;
            line-height: 1.8;
            color: #475569;
            font-weight: 700;
        }

        .payment-steps li {
            margin-bottom: 4px;
        }

        .gcash-number,
        .downpayment-highlight {
            margin: 12px 0 0;
            font-size: 0.98rem;
            line-height: 1.5;
        }

        .gcash-number {
            color: #07172f;
            font-weight: 800;
        }

        .downpayment-highlight {
            color: #0b3d91;
            font-weight: 900;
        }

        .payment-qr-wrap {
            text-align: center;
            padding: 18px;
            border-radius: 22px;
            background: #ffffff;
            border: 1px solid #dbe3ef;
            box-shadow: 0 10px 24px rgba(7,23,47,0.06);
        }

        .payment-qr-label {
            margin: 0 0 12px;
            color: #64748b;
            font-size: 0.85rem;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }

        .payment-qr {
            width: 210px;
            max-width: 100%;
            border: 1px solid #dbe3ef;
            border-radius: 18px;
            background: #fff;
            padding: 8px;
            box-sizing: border-box;
        }

        .gcash-owner-name {
            margin: 14px 0 0;
            color: #07172f;
            font-size: 0.92rem;
            font-weight: 900;
            line-height: 1.5;
        }

        .gcash-owner-name span {
            color: #0b3d91;
        }

        .payment-form {
            margin-top: 12px;
        }

        .payment-form-grid {
            display: grid;
            gap: 18px;
        }

        .proof-upload,
        .reference-input,
        .agreement {
            margin-top: 0;
        }

        .proof-upload label,
        .reference-input label {
            display: block;
            margin-bottom: 8px;
            color: #07172f;
            font-weight: 900;
            font-size: 0.95rem;
        }

        .proof-upload input[type="file"],
        .reference-input input[type="text"] {
            width: 100%;
            min-height: 54px;
            padding: 12px 14px;
            border: 1.5px solid #dbe3ef;
            border-radius: 16px;
            background: #f8fbff;
            color: #07172f;
            box-sizing: border-box;
            font-size: 1rem;
            outline: none;
            transition: 0.22s ease;
        }

        .proof-upload input[type="file"]:focus,
        .reference-input input[type="text"]:focus {
            border-color: #0b3d91;
            background: #ffffff;
            box-shadow: 0 0 0 4px rgba(11, 61, 145, 0.12);
        }

        .preview-container {
            margin-top: 14px;
            padding: 16px;
            border-radius: 18px;
            background: #f8fbff;
            border: 1px solid #dbe3ef;
        }

        .preview-container p {
            margin: 0 0 10px;
            color: #07172f;
            font-weight: 900;
        }

        .preview-image {
            max-width: 260px;
            width: 100%;
            border: 1px solid #dbe3ef;
            border-radius: 16px;
            padding: 6px;
            background: #fff;
            box-sizing: border-box;
            display: block;
        }

        .agreement {
            padding: 16px;
            border-radius: 18px;
            background: #f8fbff;
            border: 1px solid #dbe3ef;
        }

        .agreement label {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            line-height: 1.6;
            color: #07172f;
            font-weight: 800;
        }

        .agreement input[type="checkbox"] {
            margin-top: 4px;
            width: 18px;
            height: 18px;
            accent-color: #0b3d91;
            flex-shrink: 0;
        }

        .continue-button {
            width: 100%;
            min-height: 54px;
            padding: 0 18px;
            border: none;
            border-radius: 999px;
            background: linear-gradient(135deg, #061f4a, #0b3d91);
            color: #fff;
            font-size: 1rem;
            font-weight: 900;
            cursor: pointer;
            margin-top: 20px;
            transition: 0.25s ease;
            box-shadow: 0 14px 30px rgba(11, 61, 145, 0.25);
        }

        .continue-button:hover {
            transform: translateY(-3px);
            background: linear-gradient(135deg, #07172f, #092f70);
        }

        .continue-button:disabled {
            background: #94a3b8;
            cursor: not-allowed;
            opacity: 0.75;
            transform: none;
            box-shadow: none;
        }

        .booking-policy h4 {
            margin: 18px 0 8px;
            font-size: 1rem;
            color: #0b3d91;
            font-weight: 900;
        }

        .booking-policy p {
            margin: 0 0 10px;
            color: #475569;
            font-size: 0.96rem;
            line-height: 1.7;
            font-weight: 650;
        }

        .payment-side-card {
            position: relative;
            overflow: hidden;
            background:
                radial-gradient(circle at top right, rgba(11, 61, 145, 0.13), transparent 34%),
                #ffffff;
        }

        .payment-side-card::before {
            content: "";
            position: absolute;
            inset: 0 0 auto;
            height: 6px;
            background: linear-gradient(90deg, #061f4a, #0b3d91);
        }

        .payment-side-card h3 {
            margin-top: 12px;
        }

        .payment-side-row {
            display: flex;
            justify-content: space-between;
            gap: 14px;
            padding: 14px 0;
            border-bottom: 1px solid #e5edf8;
            color: #475569;
            font-weight: 800;
        }

        .payment-side-row:last-child {
            border-bottom: none;
        }

        .payment-side-row strong {
            color: #07172f;
            text-align: right;
        }

        .payment-side-row.total strong,
        .payment-side-row.downpayment strong {
            color: #0b3d91;
            font-size: 1.08rem;
        }

        .payment-warning {
            margin-top: 18px;
            padding: 14px;
            border-radius: 16px;
            background: #fff7ed;
            border: 1px solid #fed7aa;
            color: #9a3412;
            font-size: 0.9rem;
            font-weight: 800;
            line-height: 1.55;
        }

        .payment-error-box {
            background: #ffffff;
            border: 1px solid #dbe3ef;
            border-radius: 28px;
            padding: 32px;
            text-align: center;
            box-shadow: 0 16px 40px rgba(7, 23, 47, 0.08);
        }

        .payment-error-icon {
            width: 58px;
            height: 58px;
            margin: 0 auto 16px;
            border-radius: 50%;
            background: #fff7ed;
            color: #9a3412;
            display: grid;
            place-items: center;
            font-size: 1.6rem;
            font-weight: 900;
        }

        .payment-error-box h2 {
            margin: 0 0 12px;
            color: #07172f;
            font-size: 1.8rem;
        }

        .payment-error-box p {
            margin: 0 0 22px;
            color: #475569;
            line-height: 1.6;
            font-weight: 700;
        }

        .payment-error-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 48px;
            padding: 0 22px;
            border-radius: 999px;
            background: linear-gradient(135deg, #061f4a, #0b3d91);
            color: #ffffff;
            text-decoration: none;
            font-weight: 900;
        }

        @media (max-width: 1050px) {
            .payment-page-layout {
                grid-template-columns: 1fr;
            }

            .payment-page-right {
                order: -1;
                position: static;
            }

            .payment-summary-list {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 768px) {
            .payment-page {
                padding: 125px 16px 50px;
            }

            .payment-hero {
                padding: 28px;
                border-radius: 26px;
            }

            .payment-hero-inner {
                grid-template-columns: 1fr;
                text-align: center;
            }

            .payment-hero-logo {
                margin: 0 auto;
            }

            .payment-instruction {
                grid-template-columns: 1fr;
            }

            .payment-qr-wrap {
                text-align: center;
            }

            .payment-box,
            .booking-policy,
            .payment-side-card {
                border-radius: 24px;
                padding: 22px;
            }
        }

        @media (max-width: 600px) {
            .payment-page {
                padding: 110px 12px 38px;
            }

            .payment-page-wrapper {
                max-width: 100%;
            }

            .payment-hero {
                padding: 24px 18px;
                border-radius: 22px;
            }

            .payment-summary-list {
                grid-template-columns: 1fr;
            }

            .payment-box,
            .booking-policy,
            .payment-side-card,
            .payment-error-box {
                border-radius: 20px;
                padding: 18px;
            }

            .person-payment-block,
            .payment-steps,
            .payment-qr-wrap,
            .agreement,
            .preview-container {
                border-radius: 16px;
                padding: 14px;
            }

            .summary-line,
            .payment-totals p,
            .gcash-number,
            .downpayment-highlight,
            .booking-policy p {
                font-size: 0.9rem;
            }

            .payment-qr {
                width: 180px;
            }

            .preview-image {
                max-width: 100%;
            }

            .payment-side-row {
                display: block;
            }

            .payment-side-row strong {
                display: block;
                text-align: left;
                margin-top: 4px;
            }
        }
    </style>
</head>

<body>

<?php include 'navbar.php'; ?>

<main class="payment-page">
    <div class="payment-page-wrapper">

        <?php if ($page_error): ?>
            <div class="payment-error-box">
                <div class="payment-error-icon">!</div>
                <h2>Booking Details Missing</h2>
                <p><?= htmlspecialchars($page_error) ?></p>
                <a href="index.php#services" class="payment-error-btn">Select Booking Again</a>
            </div>
        <?php else: ?>

            <section class="payment-hero">
                <div class="payment-hero-inner">
                    <img
                        src="images/<?= htmlspecialchars($logo) ?>"
                        alt="<?= htmlspecialchars($shopName) ?> Logo"
                        class="payment-hero-logo"
                    >

                    <div>
                        <h1>Complete Your Downpayment</h1>
                        <p>
                            Review your booking details and send the required 15% downpayment through GCash.
                            Your appointment will be marked as pending approval until management verifies your payment.
                        </p>
                    </div>
                </div>
            </section>

            <div class="payment-page-layout">

                <div class="payment-page-left">

                    <div class="payment-box">
                        <h2>Booking Summary</h2>

                        <div class="payment-summary-list">
                            <div class="payment-summary-item">
                                <span>Total Persons</span>
                                <strong><?= (int)$total_persons ?></strong>
                            </div>

                            <div class="payment-summary-item">
                                <span>Total Duration</span>
                                <strong><?= (int)$total_duration ?> minutes</strong>
                            </div>
                        </div>

                        <?php if (!$hasCompanion && !empty($persons_display)): ?>
                            <?php $person = $persons_display[0]; ?>

                            <div class="person-payment-block">
                                <p class="summary-line"><strong>Main Service:</strong> <?= htmlspecialchars($person['main_service_name']) ?></p>
                                <p class="summary-line"><strong>Add-on Services:</strong> <?= !empty($person['addon_names']) ? htmlspecialchars(implode(', ', $person['addon_names'])) : 'None' ?></p>
                                <p class="summary-line"><strong>Barber:</strong> <?= htmlspecialchars($person['barber_name']) ?></p>
                                <p class="summary-line"><strong>Schedule:</strong> <?= htmlspecialchars($person['schedule_display']) ?></p>
                                <p class="summary-line"><strong>Total:</strong> <span class="payment-total-highlight">₱<?= number_format($total_price, 2) ?></span></p>
                                <p class="summary-line"><strong>Downpayment:</strong> <span class="payment-total-highlight">₱<?= number_format($downpayment, 2) ?></span></p>
                            </div>
                        <?php endif; ?>

                        <?php if ($hasCompanion): ?>
                            <div class="payment-totals">
                                <p><strong>Total:</strong> <span class="payment-total-highlight">₱<?= number_format($total_price, 2) ?></span></p>
                                <p><strong>Downpayment:</strong> <span class="payment-total-highlight">₱<?= number_format($downpayment, 2) ?></span></p>
                            </div>

                            <?php foreach ($persons_display as $person): ?>
                                <div class="person-payment-block">
                                    <h4>Person <?= (int)$person['person_no'] ?></h4>
                                    <p class="summary-line"><strong>Main Service:</strong> <?= htmlspecialchars($person['main_service_name']) ?></p>
                                    <p class="summary-line"><strong>Add-on Services:</strong> <?= !empty($person['addon_names']) ? htmlspecialchars(implode(', ', $person['addon_names'])) : 'None' ?></p>
                                    <p class="summary-line"><strong>Barber:</strong> <?= htmlspecialchars($person['barber_name']) ?></p>
                                    <p class="summary-line"><strong>Schedule:</strong> <?= htmlspecialchars($person['schedule_display']) ?></p>
                                    <p class="summary-line"><strong>Subtotal:</strong> <span class="payment-total-highlight">₱<?= number_format($person['total_price'], 2) ?></span></p>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <div class="payment-box">
                        <h2>Payment Instructions</h2>

                        <div class="payment-instruction">
                            <div class="payment-steps">
                                <ol>
                                    <li>Open your GCash or bank application.</li>
                                    <li>Scan the QR code shown on this page.</li>
                                    <li>Send the exact downpayment amount.</li>
                                    <li>Upload your proof of payment screenshot.</li>
                                    <li>Enter your payment reference number.</li>
                                </ol>

                                <?php if ($gcash_number !== ''): ?>
                                    <p class="gcash-number">
                                        <strong>GCash Number:</strong> <?= htmlspecialchars($gcash_number) ?>
                                    </p>
                                <?php endif; ?>

                                <p class="downpayment-highlight">
                                    <strong>Amount to send:</strong> ₱<?= number_format($downpayment, 2) ?>
                                </p>
                            </div>

                            <div class="payment-qr-wrap">
                                <p class="payment-qr-label">Scan QR Code</p>
                                <img src="<?= htmlspecialchars($gcash_qr) ?>" alt="GCash QR" class="payment-qr">

                                <?php if ($gcash_name !== ''): ?>
                                    <p class="gcash-owner-name">
                                        
                                        <span><?= htmlspecialchars($gcash_name) ?></span>
                                    </p>
                                <?php endif; ?>
                            </div>
                        </div>

                        <form action="submit_payment.php" method="post" enctype="multipart/form-data" class="payment-form">
                            <input type="hidden" name="total_price" value="<?= htmlspecialchars($total_price) ?>">
                            <input type="hidden" name="total_duration" value="<?= htmlspecialchars($total_duration) ?>">
                            <input type="hidden" name="total_persons" value="<?= (int)$total_persons ?>">
                            <input type="hidden" name="persons_payload" value='<?= htmlspecialchars(json_encode($persons_payload), ENT_QUOTES) ?>'>

                            <div class="payment-form-grid">

                                <div class="proof-upload">
                                    <label for="proof_image">Upload Proof of Payment Screenshot</label>
                                    <input type="file" name="proof_image" id="proof_image" accept="image/*" required>

                                    <div id="previewContainer" class="preview-container" style="display:none;">
                                        <p>Preview</p>
                                        <img id="previewImage" class="preview-image" alt="Proof Preview">
                                    </div>
                                </div>

                                <div class="reference-input">
                                    <label for="reference">Reference Number</label>
                                    <input
                                        type="text"
                                        name="reference_number"
                                        id="reference"
                                        placeholder="Enter reference number"
                                        required
                                        minlength="5"
                                        maxlength="20"
                                        pattern="[A-Za-z0-9]{5,20}"
                                        title="Reference number must be 5 to 20 characters and letters/numbers only."
                                    >
                                </div>

                                <div class="agreement">
                                    <label>
                                        <input type="checkbox" id="agree">
                                        <span>
                                            I confirm that I have read and agreed to the booking policy,
                                            including the no refund policy and 15-minute grace period.
                                        </span>
                                    </label>
                                </div>

                            </div>

                            <button type="submit" class="continue-button" id="submitBtn" disabled>
                                Submit Payment
                            </button>
                        </form>
                    </div>

                    <div class="booking-policy">
                        <h2>Our Booking Policy</h2>

                        <h4>Downpayment</h4>
                        <p>A 15% downpayment of your total bill is required to confirm your booking. Payments can be made via GCash.</p>

                        <h4>Cancellation Policy</h4>
                        <p>Cancellations are allowed once a booking is confirmed. All payments made are non-refundable.</p>

                        <h4>Grace Period</h4>
                        <p>Clients are given a 15-minute grace period from their scheduled appointment time. If you do not arrive within this timeframe, your appointment will be marked as a no-show and will be voided with no refund.</p>

                        <h4>Additional Information</h4>
                        <p>All bookings are subject to approval by management. Once approved, your booking will appear in the Appointments section of your account.</p>
                    </div>

                </div>

                <aside class="payment-page-right">
                    <div class="payment-side-card">
                        <h3>Payment Summary</h3>

                        <div class="payment-side-row">
                            <span>Total Persons</span>
                            <strong><?= (int)$total_persons ?></strong>
                        </div>

                        <div class="payment-side-row">
                            <span>Total Duration</span>
                            <strong><?= (int)$total_duration ?> mins</strong>
                        </div>

                        <div class="payment-side-row total">
                            <span>Total Amount</span>
                            <strong>₱<?= number_format($total_price, 2) ?></strong>
                        </div>

                        <div class="payment-side-row downpayment">
                            <span>Required Downpayment</span>
                            <strong>₱<?= number_format($downpayment, 2) ?></strong>
                        </div>

                        <div class="payment-warning">
                            Your booking will be reviewed by management after your payment proof is submitted.
                        </div>
                    </div>
                </aside>

            </div>

        <?php endif; ?>

    </div>
</main>

<?php if (!$page_error): ?>
<script>
const agree = document.getElementById('agree');
const submitBtn = document.getElementById('submitBtn');
const proofImage = document.getElementById('proof_image');
const previewContainer = document.getElementById('previewContainer');
const previewImage = document.getElementById('previewImage');

if (agree && submitBtn) {
    agree.addEventListener('change', function () {
        submitBtn.disabled = !this.checked;
    });
}

if (proofImage && previewContainer && previewImage) {
    proofImage.addEventListener('change', function () {
        const file = this.files[0];

        if (!file) {
            previewContainer.style.display = 'none';
            previewImage.src = '';
            return;
        }

        const reader = new FileReader();

        reader.onload = function(e) {
            previewImage.src = e.target.result;
            previewContainer.style.display = 'block';
        };

        reader.readAsDataURL(file);
    });
}
</script>
<?php endif; ?>

<?php include 'footer.php'; ?>

</body>
</html>