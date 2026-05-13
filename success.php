<?php
session_start();
include 'db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

if (!isset($_SESSION['payment_success']) || $_SESSION['payment_success'] !== true) {
    header("Location: index.php");
    exit();
}

unset($_SESSION['payment_success']);

$settingsQuery = $conn->query("SELECT * FROM settings LIMIT 1");
$shop = $settingsQuery ? $settingsQuery->fetch_assoc() : [];

$shopName = $shop['shop_name'] ?? 'Fade Street Hair Studio';
$logo = !empty($shop['logo']) ? $shop['logo'] : 'logo.png';
$bannerImage = !empty($shop['banner_image']) ? $shop['banner_image'] : 'banner.jpg';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="refresh" content="8;url=index.php">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Sent | <?= htmlspecialchars($shopName) ?></title>
    <link rel="stylesheet" href="styles.css">

    <style>
        .payment-sent-page {
            min-height: 100vh;
            padding: 150px 24px 70px;
            background:
                radial-gradient(circle at top left, rgba(11, 61, 145, 0.13), transparent 34%),
                linear-gradient(180deg, #ffffff 0%, #f7f9fc 48%, #ffffff 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            box-sizing: border-box;
        }

        .payment-sent-wrapper {
            width: min(780px, 100%);
        }

        .payment-sent-box {
            position: relative;
            overflow: hidden;
            background: #ffffff;
            border: 1px solid #dbe3ef;
            border-radius: 32px;
            padding: 42px;
            text-align: center;
            box-shadow: 0 24px 70px rgba(7, 23, 47, 0.14);
        }

        .payment-sent-box::before {
            content: "";
            position: absolute;
            inset: 0 0 auto;
            height: 8px;
            background: linear-gradient(90deg, #061f4a, #0b3d91);
        }

        .payment-sent-shop {
            display: flex;
            justify-content: center;
            margin-bottom: 18px;
        }

        .payment-sent-logo {
            width: 82px;
            height: 82px;
            object-fit: contain;
            background: #ffffff;
            border: 1px solid #dbe3ef;
            border-radius: 24px;
            padding: 8px;
            box-shadow: 0 14px 30px rgba(7, 23, 47, 0.10);
        }

        .payment-sent-icon-wrap {
            width: 86px;
            height: 86px;
            margin: 0 auto 20px;
            border-radius: 50%;
            display: grid;
            place-items: center;
            background: #ecfdf5;
            color: #047857;
            font-size: 2.5rem;
            font-weight: 900;
            box-shadow: 0 14px 30px rgba(4, 120, 87, 0.16);
        }

        .payment-sent-box h2 {
            margin: 0 0 14px;
            color: #07172f;
            font-size: clamp(2rem, 4vw, 3rem);
            line-height: 1.05;
            letter-spacing: -1.2px;
            font-weight: 900;
        }

        .payment-sent-box p {
            margin: 10px auto;
            max-width: 590px;
            color: #475569;
            line-height: 1.75;
            font-size: 1rem;
            font-weight: 700;
        }

        .payment-sent-status {
            margin: 26px auto 0;
            max-width: 520px;
            background: linear-gradient(135deg, #f8fbff, #ffffff);
            border: 1px solid #dbe8ff;
            border-radius: 22px;
            padding: 20px;
            text-align: left;
        }

        .payment-sent-status p {
            margin: 8px 0;
            color: #475569;
            max-width: none;
        }

        .payment-sent-status strong {
            color: #07172f;
        }

        .payment-status-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            border-radius: 999px;
            background: #fff7ed;
            color: #9a3412;
            font-weight: 900;
            margin-bottom: 8px;
        }

        .redirect-message {
            margin-top: 20px !important;
            color: #64748b !important;
            font-size: 0.95rem !important;
        }

        .redirect-message strong {
            color: #0b3d91;
        }

        .payment-sent-actions {
            margin-top: 26px;
            display: flex;
            justify-content: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .finish-btn,
        .view-appointments-btn {
            min-height: 50px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            border-radius: 999px;
            padding: 0 22px;
            font-weight: 900;
            transition: 0.25s ease;
        }

        .view-appointments-btn {
            background: linear-gradient(135deg, #061f4a, #0b3d91);
            color: #ffffff;
            box-shadow: 0 14px 30px rgba(11, 61, 145, 0.25);
        }

        .finish-btn {
            background: #eaf1ff;
            color: #061f4a;
        }

        .finish-btn:hover,
        .view-appointments-btn:hover {
            transform: translateY(-3px);
        }

        @media (max-width: 768px) {
            .payment-sent-page {
                padding: 125px 16px 50px;
            }

            .payment-sent-box {
                padding: 34px 22px;
                border-radius: 26px;
            }

            .payment-sent-icon-wrap {
                width: 76px;
                height: 76px;
                font-size: 2.1rem;
            }

            .payment-sent-logo {
                width: 72px;
                height: 72px;
            }
        }

        @media (max-width: 480px) {
            .payment-sent-page {
                padding: 110px 12px 38px;
            }

            .payment-sent-box {
                padding: 30px 18px;
                border-radius: 22px;
            }

            .finish-btn,
            .view-appointments-btn {
                width: 100%;
            }

            .payment-sent-status {
                padding: 16px;
                border-radius: 18px;
            }
        }
    </style>
</head>
<body>

<?php include 'navbar.php'; ?>

<main class="payment-sent-page">
    <div class="payment-sent-wrapper">
        <div class="payment-sent-box">

            <div class="payment-sent-shop">
                <img src="images/<?= htmlspecialchars($logo) ?>" alt="<?= htmlspecialchars($shopName) ?>" class="payment-sent-logo">
            </div>

            <div class="payment-sent-icon-wrap">✓</div>

            <h2>Your payment has been sent.</h2>

            <p>
                Please wait for management approval. You can check your booking status in the
                <strong>Appointments</strong> section. Only approved bookings are considered valid.
            </p>

            <div class="payment-sent-status">
                <div class="payment-status-pill">Pending Approval</div>
                <p><strong>Status:</strong> Your appointment is waiting for management approval.</p>
                <p><strong>Note:</strong> Your payment proof is now under review.</p>
            </div>

            <p class="redirect-message">
                Redirecting to homepage in <strong id="countdown">8</strong> seconds...
            </p>

            <div class="payment-sent-actions">
                <a href="appointments.php" class="view-appointments-btn">View Appointments</a>
                <a href="index.php" class="finish-btn">Go to Homepage</a>
            </div>

        </div>
    </div>
</main>

<script>
let seconds = 8;
const countdown = document.getElementById('countdown');

const timer = setInterval(() => {
    seconds--;

    if (countdown) {
        countdown.textContent = seconds;
    }

    if (seconds <= 0) {
        clearInterval(timer);
        window.location.href = 'index.php';
    }
}, 1000);
</script>

<?php include 'footer.php'; ?>

</body>
</html>