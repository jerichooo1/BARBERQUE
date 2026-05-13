<?php
session_start();
include 'db.php';

if (!isset($_SESSION['admin_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: admin_login.php");
    exit();
}

$message = '';
$message_type = '';

/* =========================
   ENSURE PAYMENT SETTINGS COLUMNS
========================= */
$columnCheck = $conn->query("SHOW COLUMNS FROM payment_settings LIKE 'gcash_name'");
if ($columnCheck && $columnCheck->num_rows === 0) {
    $conn->query("ALTER TABLE payment_settings ADD COLUMN gcash_name VARCHAR(255) DEFAULT NULL");
}

/* =========================
   ENSURE SETTINGS ROW EXISTS
========================= */
$settingsQuery = $conn->query("SELECT * FROM settings ORDER BY id ASC LIMIT 1");
if ($settingsQuery && $settingsQuery->num_rows > 0) {
    $settings = $settingsQuery->fetch_assoc();
} else {
    $defaultShopName = 'Fade Street Hair Studio';
    $insertStmt = $conn->prepare("INSERT INTO settings (shop_name) VALUES (?)");
    $insertStmt->bind_param("s", $defaultShopName);
    $insertStmt->execute();
    $insertStmt->close();

    $settingsQuery = $conn->query("SELECT * FROM settings ORDER BY id ASC LIMIT 1");
    $settings = $settingsQuery ? $settingsQuery->fetch_assoc() : null;
}

if (!$settings) {
    die("Failed to load settings.");
}

$settings_id = (int)$settings['id'];

/* =========================
   ENSURE PAYMENT SETTINGS ROW EXISTS
========================= */
$paymentSettingsQuery = $conn->query("SELECT * FROM payment_settings ORDER BY id ASC LIMIT 1");
if ($paymentSettingsQuery && $paymentSettingsQuery->num_rows > 0) {
    $paymentSettings = $paymentSettingsQuery->fetch_assoc();
} else {
    $defaultGcashNumber = '';
    $defaultGcashQr = '';
    $defaultGcashName = '';
    $insertPayStmt = $conn->prepare("INSERT INTO payment_settings (gcash_number, gcash_qr, gcash_name) VALUES (?, ?, ?)");
    $insertPayStmt->bind_param("sss", $defaultGcashNumber, $defaultGcashQr, $defaultGcashName);
    $insertPayStmt->execute();
    $insertPayStmt->close();

    $paymentSettingsQuery = $conn->query("SELECT * FROM payment_settings ORDER BY id ASC LIMIT 1");
    $paymentSettings = $paymentSettingsQuery ? $paymentSettingsQuery->fetch_assoc() : null;
}

if (!$paymentSettings) {
    die("Failed to load payment settings.");
}

$payment_settings_id = (int)$paymentSettings['id'];

/* =========================
   HELPERS
========================= */
function cleanText($value) {
    return trim($value ?? '');
}

function uploadImageFile($file, $oldValue = null, $prefix = 'img_') {
    if (!isset($file) || !isset($file['error']) || $file['error'] !== 0) {
        return $oldValue;
    }

    $allowed = ['jpg', 'jpeg', 'png', 'webp'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    if (!in_array($ext, $allowed, true)) {
        return false;
    }

    if (($file['size'] ?? 0) > 5 * 1024 * 1024) {
        return false;
    }

    $uploadDir = "images/";
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    $newName = $prefix . time() . '_' . rand(1000, 9999) . '.' . $ext;
    $target = $uploadDir . $newName;

    if (move_uploaded_file($file['tmp_name'], $target)) {
        return $newName;
    }

    return false;
}

/* =========================
   HANDLE FORM SUBMIT
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $shop_name       = cleanText($_POST['shop_name'] ?? '');
    $booking_policy  = cleanText($_POST['booking_policy'] ?? '');
    $about_us        = cleanText($_POST['about_us'] ?? '');
    $address         = cleanText($_POST['address'] ?? '');
    $phone           = cleanText($_POST['phone'] ?? '');
    $email           = cleanText($_POST['email'] ?? '');
    $facebook        = cleanText($_POST['facebook'] ?? '');
    $est_year        = cleanText($_POST['est_year'] ?? '');
    $opening_hours   = cleanText($_POST['opening_hours'] ?? '');
    $smtp_email      = cleanText($_POST['smtp_email'] ?? '');
    $smtp_password   = cleanText($_POST['smtp_password'] ?? '');
    $smtp_from_name  = cleanText($_POST['smtp_from_name'] ?? '');

    $gcash_number    = cleanText($_POST['gcash_number'] ?? '');
    $gcash_name      = cleanText($_POST['gcash_name'] ?? '');

    if ($shop_name === '') {
        $message = 'Shop name is required.';
        $message_type = 'error';
    } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = 'Please enter a valid shop email address.';
        $message_type = 'error';
    } elseif ($smtp_email !== '' && !filter_var($smtp_email, FILTER_VALIDATE_EMAIL)) {
        $message = 'Please enter a valid SMTP email address.';
        $message_type = 'error';
    } elseif ($phone !== '' && !preg_match('/^09\d{9}$/', $phone)) {
        $message = 'Phone number must start with 09 and be 11 digits.';
        $message_type = 'error';
    } elseif ($gcash_number !== '' && !preg_match('/^09[0-9*]{9}$/', $gcash_number)) {
    $message = 'GCash number must start with 09 and contain 11 characters. Use numbers or * for privacy.';
    $message_type = 'error';
    } else {
        $currentLogo = $settings['logo'] ?? '';
        $currentBanner = $settings['banner_image'] ?? '';
        $currentGcashQr = $paymentSettings['gcash_qr'] ?? '';

        $newLogo = uploadImageFile($_FILES['logo'] ?? null, $currentLogo, 'logo_');
        if ($newLogo === false) {
            $message = 'Invalid logo file. Please upload JPG, JPEG, PNG, or WEBP only.';
            $message_type = 'error';
        } else {
            $newBanner = uploadImageFile($_FILES['banner_image'] ?? null, $currentBanner, 'banner_');

            if ($newBanner === false) {
                $message = 'Invalid banner image file. Please upload JPG, JPEG, PNG, or WEBP only.';
                $message_type = 'error';
            } else {
                $newGcashQr = uploadImageFile($_FILES['gcash_qr'] ?? null, $currentGcashQr, 'gcash_qr_');

                if ($newGcashQr === false) {
                    $message = 'Invalid GCash QR image file. Please upload JPG, JPEG, PNG, or WEBP only.';
                    $message_type = 'error';
                } else {
                    if ($smtp_password === '') {
                        $smtp_password = $settings['smtp_password'] ?? '';
                    }

                    $conn->begin_transaction();

                    try {
                        $stmt = $conn->prepare("
                            UPDATE settings SET
                                shop_name = ?,
                                logo = ?,
                                banner_image = ?,
                                booking_policy = ?,
                                about_us = ?,
                                address = ?,
                                phone = ?,
                                email = ?,
                                facebook = ?,
                                est_year = ?,
                                opening_hours = ?,
                                smtp_email = ?,
                                smtp_password = ?,
                                smtp_from_name = ?
                            WHERE id = ?
                        ");

                        $stmt->bind_param(
                            "ssssssssssssssi",
                            $shop_name,
                            $newLogo,
                            $newBanner,
                            $booking_policy,
                            $about_us,
                            $address,
                            $phone,
                            $email,
                            $facebook,
                            $est_year,
                            $opening_hours,
                            $smtp_email,
                            $smtp_password,
                            $smtp_from_name,
                            $settings_id
                        );
                        $stmt->execute();
                        $stmt->close();

                        $payStmt = $conn->prepare("
                            UPDATE payment_settings SET
                                gcash_number = ?,
                                gcash_qr = ?,
                                gcash_name = ?
                            WHERE id = ?
                        ");
                        $payStmt->bind_param(
                            "sssi",
                            $gcash_number,
                            $newGcashQr,
                            $gcash_name,
                            $payment_settings_id
                        );
                        $payStmt->execute();
                        $payStmt->close();

                        $conn->commit();

                        $message = 'Settings updated successfully.';
                        $message_type = 'success';

                        $settingsQuery = $conn->query("SELECT * FROM settings WHERE id = {$settings_id} LIMIT 1");
                        $settings = $settingsQuery ? $settingsQuery->fetch_assoc() : $settings;

                        $paymentSettingsQuery = $conn->query("SELECT * FROM payment_settings WHERE id = {$payment_settings_id} LIMIT 1");
                        $paymentSettings = $paymentSettingsQuery ? $paymentSettingsQuery->fetch_assoc() : $paymentSettings;
                    } catch (Exception $e) {
                        $conn->rollback();
                        $message = 'Failed to update settings.';
                        $message_type = 'error';
                    }
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings | <?= htmlspecialchars($settings['shop_name'] ?? 'Barbershop') ?></title>
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
        }

        .page-header p {
            margin: 0;
            color: #666;
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

        .settings-grid {
            display: grid;
            grid-template-columns: 1.1fr 0.9fr;
            gap: 20px;
        }

        .panel {
            background: #fff;
            border-radius: 22px;
            padding: 22px;
            box-shadow: 0 10px 30px rgba(15, 23, 42, 0.06);
            border: 1px solid #edf2f7;
            margin-bottom: 20px;
        }

        .panel h2 {
            margin-top: 0;
            margin-bottom: 16px;
            font-size: 22px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
        }

        .form-group {
            margin-bottom: 14px;
        }

        .form-group.full {
            grid-column: 1 / -1;
        }

        .form-group label {
            display: block;
            margin-bottom: 7px;
            font-weight: 600;
            font-size: 13px;
            color: #374151;
        }

        .form-group input,
        .form-group textarea {
            width: 100%;
            padding: 11px 13px;
            border: 1px solid #d1d9e6;
            border-radius: 12px;
            font-size: 14px;
            outline: none;
            background: #fff;
            font-family: inherit;
        }

        .form-group textarea {
            resize: vertical;
            min-height: 110px;
        }

        .form-group input:focus,
        .form-group textarea:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.12);
        }

        .btn {
            border: none;
            border-radius: 12px;
            padding: 12px 16px;
            font-weight: 600;
            cursor: pointer;
            background: #2563eb;
            color: #fff;
            transition: 0.18s ease;
            font-size: 14px;
        }

        .btn:hover {
            transform: translateY(-1px);
            background: #1d4ed8;
        }

        .preview-box {
            background: #fafafa;
            border: 1px solid #e6edf5;
            border-radius: 18px;
            padding: 18px;
        }

        .preview-box h3 {
            margin-top: 0;
            margin-bottom: 14px;
            font-size: 18px;
        }

        .preview-logo,
        .preview-banner,
        .preview-qr {
            width: 100%;
            border-radius: 14px;
            border: 1px solid #ddd;
            background: #fff;
            object-fit: cover;
        }

        .preview-logo {
            max-width: 110px;
            height: 110px;
            display: block;
            margin-bottom: 14px;
        }

        .preview-banner {
            height: 180px;
            margin-bottom: 14px;
        }

        .preview-qr {
            max-width: 220px;
            height: 220px;
            display: block;
            margin-bottom: 14px;
        }

        .preview-meta p {
            margin: 7px 0;
            line-height: 1.6;
            word-break: break-word;
        }

        .helper-text {
            margin-top: 6px;
            font-size: 12px;
            color: #666;
            line-height: 1.5;
        }

        @media (max-width: 1100px) {
            .settings-grid {
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

            .form-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 480px) {
            .admin-main {
                padding: 14px;
            }

            .panel {
                padding: 16px;
                border-radius: 16px;
            }

            .page-header h1 {
                font-size: 24px;
            }

            .preview-banner {
                height: 140px;
            }

            .preview-qr {
                max-width: 170px;
                height: 170px;
            }
        }
    </style>
</head>
<body>

<div class="mobile-topbar">
    <button type="button" class="menu-btn" id="menuBtn">☰</button>
    <strong><?= htmlspecialchars($settings['shop_name'] ?? 'Barbershop') ?></strong>
</div>

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<div class="admin-layout">
    <aside class="admin-sidebar" id="adminSidebar">
        <div class="admin-brand">
            <img src="images/<?= htmlspecialchars($settings['logo'] ?: 'logo.png') ?>" alt="Shop Logo">
            <h2><?= htmlspecialchars($settings['shop_name'] ?? 'Barbershop') ?></h2>
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
            <a href="admin_reports.php">Reports</a>
            <a href="admin_ar_models.php">AR Hair Models</a>
            <a href="admin_settings.php" class="active">Settings</a>
            <a href="admin_logout.php">Logout</a>
        </nav>
    </aside>

    <main class="admin-main">
        <div class="page-header">
            <div>
                <h1>Shop Settings</h1>
                <p>Manage your shop profile, contact details, branding, SMTP, and payment details.</p>
            </div>
        </div>

        <?php if ($message !== ''): ?>
            <div class="alert <?= $message_type === 'success' ? 'alert-success' : 'alert-error' ?>">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data">
            <div class="settings-grid">

                <div>
                    <div class="panel">
                        <h2>Shop Information</h2>

                        <div class="form-grid">
                            <div class="form-group full">
                                <label for="shop_name">Shop Name</label>
                                <input type="text" id="shop_name" name="shop_name" value="<?= htmlspecialchars($settings['shop_name'] ?? '') ?>" required>
                            </div>

                            <div class="form-group">
                                <label for="phone">Phone</label>
                                <input type="text" id="phone" name="phone" value="<?= htmlspecialchars($settings['phone'] ?? '') ?>" placeholder="09XXXXXXXXX">
                            </div>

                            <div class="form-group">
                                <label for="email">Shop Email</label>
                                <input type="email" id="email" name="email" value="<?= htmlspecialchars($settings['email'] ?? '') ?>">
                            </div>

                            <div class="form-group full">
                                <label for="address">Address</label>
                                <input type="text" id="address" name="address" value="<?= htmlspecialchars($settings['address'] ?? '') ?>">
                            </div>

                            <div class="form-group">
                                <label for="facebook">Facebook Link / Page</label>
                                <input type="text" id="facebook" name="facebook" value="<?= htmlspecialchars($settings['facebook'] ?? '') ?>">
                            </div>

                            <div class="form-group">
                                <label for="est_year">Established Year</label>
                                <input type="text" id="est_year" name="est_year" value="<?= htmlspecialchars($settings['est_year'] ?? '') ?>" placeholder="2020">
                            </div>

                            <div class="form-group full">
                                <label for="opening_hours">Opening Hours</label>
                                <input type="text" id="opening_hours" name="opening_hours" value="<?= htmlspecialchars($settings['opening_hours'] ?? '') ?>" placeholder="10:00 AM - 10:00 PM">
                            </div>

                            <div class="form-group full">
                                <label for="about_us">About Us</label>
                                <textarea id="about_us" name="about_us"><?= htmlspecialchars($settings['about_us'] ?? '') ?></textarea>
                            </div>

                            <div class="form-group full">
                                <label for="booking_policy">Booking Policy</label>
                                <textarea id="booking_policy" name="booking_policy"><?= htmlspecialchars($settings['booking_policy'] ?? '') ?></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="panel">
                        <h2>Payment Settings</h2>

                        <div class="form-grid">
                            <div class="form-group full">
                                <label for="gcash_number">GCash Number</label>
                                <input type="text" id="gcash_number" name="gcash_number" value="<?= htmlspecialchars($paymentSettings['gcash_number'] ?? '') ?>" placeholder="09*******12">
                            </div>

                            <div class="form-group full">
                                <label for="gcash_name">GCash Account Name</label>
                                <input type="text" id="gcash_name" name="gcash_name" value="<?= htmlspecialchars($paymentSettings['gcash_name'] ?? '') ?>" placeholder="Example: AN**E B.">
                                <div class="helper-text">This name will appear below the GCash QR code on the payment page.</div>
                            </div>

                            <div class="form-group full">
                                <label for="gcash_qr">GCash QR Image</label>
                                <input type="file" id="gcash_qr" name="gcash_qr" accept="image/*">
                                <div class="helper-text">Upload the QR code image customers will scan during payment.</div>
                            </div>
                        </div>
                    </div>

                    <div class="panel">
                        <h2>Email / SMTP Settings</h2>

                        <div class="form-grid">
                            <div class="form-group full">
                                <label for="smtp_email">SMTP Email</label>
                                <input type="email" id="smtp_email" name="smtp_email" value="<?= htmlspecialchars($settings['smtp_email'] ?? '') ?>">
                            </div>

                            <div class="form-group full">
                                <label for="smtp_password">SMTP Password</label>
                                <input type="password" id="smtp_password" name="smtp_password" value="" placeholder="Leave blank to keep current password">
                                <div class="helper-text">Leave this blank if you do not want to change the current SMTP password.</div>
                            </div>

                            <div class="form-group full">
                                <label for="smtp_from_name">SMTP From Name</label>
                                <input type="text" id="smtp_from_name" name="smtp_from_name" value="<?= htmlspecialchars($settings['smtp_from_name'] ?? '') ?>">
                            </div>
                        </div>
                    </div>

                    <div class="panel">
                        <h2>Branding Images</h2>

                        <div class="form-grid">
                            <div class="form-group full">
                                <label for="logo">Shop Logo</label>
                                <input type="file" id="logo" name="logo" accept="image/*">
                            </div>

                            <div class="form-group full">
                                <label for="banner_image">Banner Image</label>
                                <input type="file" id="banner_image" name="banner_image" accept="image/*">
                            </div>
                        </div>
                    </div>

                    <button type="submit" class="btn">Save Settings</button>
                </div>

                <div>
                    <div class="panel">
                        <h2>Live Preview</h2>

                        <div class="preview-box">
                            <h3>Shop Logo</h3>
                            <img class="preview-logo" src="images/<?= htmlspecialchars($settings['logo'] ?: 'logo.png') ?>" alt="Logo Preview">

                            <h3>Banner Preview</h3>
                            <img class="preview-banner" src="images/<?= htmlspecialchars($settings['banner_image'] ?: 'default-banner.jpg') ?>" alt="Banner Preview">

                            <h3>GCash QR Preview</h3>
                            <img class="preview-qr" src="images/<?= htmlspecialchars($paymentSettings['gcash_qr'] ?: 'default-gcash-qr.png') ?>" alt="GCash QR Preview">

                            <div class="preview-meta">
                                <p><strong>Shop Name:</strong> <?= htmlspecialchars($settings['shop_name'] ?? 'Not set') ?></p>
                                <p><strong>Address:</strong> <?= htmlspecialchars($settings['address'] ?? 'Not set') ?></p>
                                <p><strong>Phone:</strong> <?= htmlspecialchars($settings['phone'] ?? 'Not set') ?></p>
                                <p><strong>Email:</strong> <?= htmlspecialchars($settings['email'] ?? 'Not set') ?></p>
                                <p><strong>Facebook:</strong> <?= htmlspecialchars($settings['facebook'] ?? 'Not set') ?></p>
                                <p><strong>Established:</strong> <?= htmlspecialchars($settings['est_year'] ?? 'Not set') ?></p>
                                <p><strong>Opening Hours:</strong> <?= htmlspecialchars($settings['opening_hours'] ?? 'Not set') ?></p>
                                <p><strong>GCash Number:</strong> <?= htmlspecialchars($paymentSettings['gcash_number'] ?? 'Not set') ?></p>
                                <p><strong>GCash Name:</strong> <?= htmlspecialchars($paymentSettings['gcash_name'] ?? 'Not set') ?></p>
                            </div>
                        </div>
                    </div>

                    <div class="panel">
                        <h2>Notes</h2>
                        <div class="preview-meta">
                            <p><strong>Logo:</strong> Recommended square image for better fit in navbar and admin sidebar.</p>
                            <p><strong>Banner:</strong> Use a wide image for homepage or hero section display.</p>
                            <p><strong>Phone Format:</strong> Must start with <strong>09</strong> and be <strong>11 digits</strong>.</p>
                            <p><strong>GCash Number:</strong> Also follows PH mobile format.</p>
                            <p><strong>GCash Account Name:</strong> This appears below the QR code on the payment page, just like the name shown in GCash QR payments.</p>
                            <p><strong>GCash QR:</strong> This will be displayed on the payment page for customer downpayment scanning.</p>
                            <p><strong>SMTP:</strong> These values are used for password reset and system email sending.</p>
                        </div>
                    </div>
                </div>

            </div>
        </form>
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