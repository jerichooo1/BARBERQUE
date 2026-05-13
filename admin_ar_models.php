<?php
session_start();
include 'db.php';

if (!isset($_SESSION['admin_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: admin_login.php");
    exit();
}

$settings = $conn->query("SELECT * FROM settings LIMIT 1");
$shop = $settings ? $settings->fetch_assoc() : null;

$message = "";
$availableFaceShapes = ['oval', 'round', 'square', 'heart', 'oblong'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name']);
    $width_scale = floatval($_POST['width_scale']);
    $height_scale = floatval($_POST['height_scale']);
    $offset_x = intval($_POST['offset_x']);
    $offset_y = intval($_POST['offset_y']);
    $anchor_y = floatval($_POST['anchor_y']);
    $rotation_offset = floatval($_POST['rotation_offset']);
    $flip_x = isset($_POST['flip_x']) ? 1 : 0;
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    $face_shapes = isset($_POST['face_shapes']) && is_array($_POST['face_shapes']) ? $_POST['face_shapes'] : [];

    $face_shapes = array_values(array_intersect($availableFaceShapes, $face_shapes));

    if ($name === "") {
        $message = "Hairstyle name is required.";
    } elseif (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        $message = "Please upload a hairstyle image.";
    } else {
        $allowed = ['png', 'jpg', 'jpeg', 'webp'];
        $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));

        if (!in_array($ext, $allowed)) {
            $message = "Only PNG, JPG, JPEG, and WEBP files are allowed.";
        } else {
            $upload_dir = "uploads/hairstyles/";
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }

            $new_name = uniqid("hair_", true) . "." . $ext;
            $image_path = $upload_dir . $new_name;

            if (move_uploaded_file($_FILES['image']['tmp_name'], $image_path)) {
                $stmt = $conn->prepare("
                    INSERT INTO hairstyles
                    (name, image_path, width_scale, height_scale, offset_x, offset_y, anchor_y, rotation_offset, flip_x, is_active)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->bind_param(
                    "ssddiiddii",
                    $name,
                    $image_path,
                    $width_scale,
                    $height_scale,
                    $offset_x,
                    $offset_y,
                    $anchor_y,
                    $rotation_offset,
                    $flip_x,
                    $is_active
                );

                if ($stmt->execute()) {
                    $hairstyle_id = $stmt->insert_id;
                    $stmt->close();

                    if (!empty($face_shapes)) {
                        $mapStmt = $conn->prepare("
                            INSERT INTO hairstyle_face_shapes (hairstyle_id, face_shape)
                            VALUES (?, ?)
                        ");

                        foreach ($face_shapes as $face_shape) {
                            $mapStmt->bind_param("is", $hairstyle_id, $face_shape);
                            $mapStmt->execute();
                        }

                        $mapStmt->close();
                    }

                    $message = "Hairstyle added successfully.";
                } else {
                    $message = "Database error: " . $stmt->error;
                    $stmt->close();
                }
            } else {
                $message = "Failed to upload image.";
            }
        }
    }
}

$hairstyles = $conn->query("
    SELECT
        h.*,
        GROUP_CONCAT(DISTINCT hfs.face_shape ORDER BY hfs.face_shape SEPARATOR ', ') AS face_shapes
    FROM hairstyles h
    LEFT JOIN hairstyle_face_shapes hfs ON h.id = hfs.hairstyle_id
    GROUP BY h.id
    ORDER BY h.id DESC
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Portal | AR Hair Models</title>
    <link rel="stylesheet" href="styles.css">
    <style>
        * { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: 'Poppins', sans-serif;
            background: #f8fafc;
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

        .admin-topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
            margin-bottom: 24px;
            flex-wrap: wrap;
        }

        .admin-topbar h1 {
            margin: 0;
            font-size: 28px;
        }

        .admin-topbar p {
            margin: 5px 0 0;
            color: #666;
        }

        .admin-user {
            font-weight: 600;
            color: #2563eb;
        }

        .content-grid {
            display: grid;
            grid-template-columns: 380px 1fr;
            gap: 20px;
        }

        .panel {
            background: #fff;
            border-radius: 18px;
            padding: 22px;
            box-shadow: 0 4px 14px rgba(0,0,0,0.08);
            min-width: 0;
        }

        .panel h2 {
            margin-top: 0;
            margin-bottom: 16px;
            font-size: 20px;
        }

        .message {
            margin-bottom: 16px;
            padding: 12px 14px;
            border-radius: 12px;
            background: #fff3cd;
            color: #7a5a00;
            font-weight: 600;
        }

        .form-group {
            margin-bottom: 14px;
        }

        .form-group label {
            display: block;
            margin-bottom: 6px;
            font-size: 14px;
            font-weight: 600;
        }

        .form-group small {
            display: block;
            margin-top: 6px;
            color: #6b7280;
            line-height: 1.5;
            font-size: 12px;
        }

        .form-group input[type="text"],
        .form-group input[type="number"],
        .form-group input[type="file"] {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 12px;
            font-size: 14px;
            background: #fff;
            outline: none;
        }

        .form-group input:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.12);
        }

        .row-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .checkboxes {
            display: flex;
            gap: 18px;
            flex-wrap: wrap;
            margin-top: 6px;
        }

        .checkboxes label {
            font-size: 14px;
            font-weight: 600;
        }

        .face-shape-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
            margin-top: 8px;
        }

        .face-shape-option {
            display: flex;
            align-items: center;
            gap: 8px;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 10px 12px;
            background: #fafafa;
            font-size: 14px;
            font-weight: 600;
        }

        .save-btn,
        .edit-btn,
        .delete-btn,
        .cancel-btn,
        .confirm-delete-btn {
            border: none;
            border-radius: 10px;
            padding: 10px 14px;
            text-decoration: none;
            font-weight: 600;
            cursor: pointer;
            display: inline-block;
            text-align: center;
        }

        .save-btn {
            background: #2563eb;
            color: #fff;
            width: 100%;
            margin-top: 10px;
        }

        .save-btn:hover {
            background: #1d4ed8;
        }

        .edit-btn {
            background: #0f172a;
            color: #fff;
            margin-right: 6px;
        }

        .delete-btn {
            background: #dc2626;
            color: #fff;
        }

        .cancel-btn {
            background: #e5e7eb;
            color: #0f172a;
        }

        .confirm-delete-btn {
            background: #dc2626;
            color: #fff;
        }

        .models-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 16px;
        }

        .model-card {
            border: 1px solid #eee;
            border-radius: 16px;
            overflow: hidden;
            background: #fff;
        }

        .model-preview {
            height: 180px;
            background: #111;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 12px;
        }

        .model-preview img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
        }

        .model-body {
            padding: 14px;
        }

        .model-title {
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 10px;
        }

        .model-meta {
            font-size: 13px;
            color: #666;
            line-height: 1.7;
            margin-bottom: 14px;
        }

        .status-pill {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
            margin-bottom: 10px;
        }

        .status-active {
            background: #dff6dd;
            color: #256029;
        }

        .status-inactive {
            background: #fddede;
            color: #991b1b;
        }

        .tag-list {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 8px;
            margin-bottom: 12px;
        }

        .tag-pill {
            display: inline-flex;
            align-items: center;
            padding: 6px 10px;
            border-radius: 999px;
            background: #dbeafe;
            color: #1d4ed8;
            font-size: 12px;
            font-weight: 700;
        }

        .notes-box {
            margin-top: 18px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 14px;
        }

        .notes-box h3 {
            margin: 0 0 10px;
            font-size: 16px;
            color: #0f172a;
        }

        .notes-box p {
            margin: 0 0 10px;
            font-size: 13px;
            line-height: 1.65;
            color: #475569;
        }

        .notes-box p:last-child {
            margin-bottom: 0;
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
            max-width: 520px;
            background: #fff;
            border-radius: 20px;
            box-shadow: 0 24px 60px rgba(15, 23, 42, 0.22);
            position: relative;
        }

        .modal-header {
            padding: 22px 22px 12px;
            border-bottom: 1px solid #edf2f7;
        }

        .modal-header h3 {
            margin: 0;
            font-size: 22px;
            color: #0f172a;
        }

        .modal-body {
            padding: 18px 22px 22px;
        }

        .modal-body p {
            margin: 0 0 12px;
            line-height: 1.6;
            color: #475569;
        }

        .modal-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 18px;
        }

        @media (max-width: 1100px) {
            .content-grid {
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

            .row-2,
            .face-shape-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 640px) {
            .admin-main {
                padding: 14px;
            }

            .panel {
                padding: 16px;
            }

            .save-btn,
            .edit-btn,
            .delete-btn,
            .cancel-btn,
            .confirm-delete-btn {
                width: 100%;
                text-align: center;
                margin-right: 0;
                margin-bottom: 8px;
            }

            .edit-btn:last-child,
            .delete-btn:last-child,
            .cancel-btn:last-child,
            .confirm-delete-btn:last-child {
                margin-bottom: 0;
            }

            .modal-actions {
                flex-direction: column;
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
            <a href="admin_staff.php">Staff</a>
            <a href="admin_reviews.php">Reviews</a>
            <a href="admin_reports.php">Reports</a>
            <a href="admin_ar_models.php" class="active">AR Hair Models</a>
            <a href="admin_settings.php">Settings</a>
            <a href="admin_logout.php">Logout</a>
        </nav>
    </aside>

    <main class="admin-main">
        <div class="admin-topbar">
            <div>
                <h1>AR Hair Models</h1>
                <p>Upload, tune, and assign recommended face shapes for each hairstyle.</p>
            </div>
            
        </div>

        <?php if ($message): ?>
            <div class="message"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <div class="content-grid">
            <section class="panel">
                <h2>Add Hairstyle</h2>

                <form method="POST" enctype="multipart/form-data">
                    <div class="form-group">
                        <label>Hairstyle Name</label>
                        <input type="text" name="name" required>
                        <small>This is the name shown in the admin list and AR hairstyle choices.</small>
                    </div>

                    <div class="form-group">
                        <label>Image File</label>
                        <input type="file" name="image" accept=".png,.jpg,.jpeg,.webp" required>
                        <small>Use a transparent PNG if possible for the cleanest AR overlay result.</small>
                    </div>

                    <div class="row-2">
                        <div class="form-group">
                            <label>Width Scale</label>
                            <input type="number" step="0.01" name="width_scale" value="2.50" required>
                            <small>Controls how wide the hairstyle appears on the face.</small>
                        </div>
                        <div class="form-group">
                            <label>Height Scale</label>
                            <input type="number" step="0.01" name="height_scale" value="1.00" required>
                            <small>Controls how tall the hairstyle appears.</small>
                        </div>
                    </div>

                    <div class="row-2">
                        <div class="form-group">
                            <label>Offset X</label>
                            <input type="number" name="offset_x" value="0" required>
                            <small>Negative = left, positive = right.</small>
                        </div>
                        <div class="form-group">
                            <label>Offset Y</label>
                            <input type="number" name="offset_y" value="-60" required>
                            <small>Negative = higher, positive = lower.</small>
                        </div>
                    </div>

                    <div class="row-2">
                        <div class="form-group">
                            <label>Anchor Y</label>
                            <input type="number" step="0.01" name="anchor_y" value="0.24" required>
                            <small>Controls where the hairstyle attaches vertically.</small>
                        </div>
                        <div class="form-group">
                            <label>Rotation Offset</label>
                            <input type="number" step="0.01" name="rotation_offset" value="0.00" required>
                            <small>Fixes tilt if the hairstyle rotates incorrectly.</small>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Recommended Face Shapes</label>
                        <div class="face-shape-grid">
                            <label class="face-shape-option"><input type="checkbox" name="face_shapes[]" value="oval"> Oval</label>
                            <label class="face-shape-option"><input type="checkbox" name="face_shapes[]" value="round"> Round</label>
                            <label class="face-shape-option"><input type="checkbox" name="face_shapes[]" value="square"> Square</label>
                            <label class="face-shape-option"><input type="checkbox" name="face_shapes[]" value="heart"> Heart</label>
                            <label class="face-shape-option"><input type="checkbox" name="face_shapes[]" value="oblong"> Oblong</label>
                        </div>
                        <small>Select which face shapes this hairstyle should be recommended for on the customer AR page.</small>
                    </div>

                    <div class="checkboxes">
                        <label><input type="checkbox" name="flip_x"> Flip X</label>
                        <label><input type="checkbox" name="is_active" checked> Active</label>
                    </div>

                    <button type="submit" class="save-btn">Save Hairstyle</button>
                </form>

                <div class="notes-box">
                    <h3>Quick Tuning Guide</h3>
                    <p><strong>Width Scale</strong> makes the hairstyle wider or narrower.</p>
                    <p><strong>Height Scale</strong> makes the hairstyle taller or shorter.</p>
                    <p><strong>Offset X</strong> moves the hairstyle left or right.</p>
                    <p><strong>Offset Y</strong> moves the hairstyle higher or lower.</p>
                    <p><strong>Anchor Y</strong> adjusts where the hairstyle locks vertically.</p>
                    <p><strong>Rotation Offset</strong> fixes tilt if the hairstyle rotates incorrectly.</p>
                    <p><strong>Flip X</strong> mirrors the hairstyle horizontally when needed.</p>
                    <p><strong>Recommended Face Shapes</strong> controls what the customer sees after scanning.</p>
                </div>
            </section>

            <section class="panel">
                <h2>Saved Hairstyles</h2>

                <div class="models-grid">
                    <?php if ($hairstyles && $hairstyles->num_rows > 0): ?>
                        <?php while ($hair = $hairstyles->fetch_assoc()): ?>
                            <div class="model-card">
                                <div class="model-preview">
                                    <img src="<?= htmlspecialchars($hair['image_path']) ?>" alt="<?= htmlspecialchars($hair['name']) ?>">
                                </div>
                                <div class="model-body">
                                    <div class="status-pill <?= $hair['is_active'] ? 'status-active' : 'status-inactive' ?>">
                                        <?= $hair['is_active'] ? 'Active' : 'Inactive' ?>
                                    </div>

                                    <div class="model-title"><?= htmlspecialchars($hair['name']) ?></div>

                                    <?php if (!empty($hair['face_shapes'])): ?>
                                        <div class="tag-list">
                                            <?php foreach (explode(', ', $hair['face_shapes']) as $shapeTag): ?>
                                                <span class="tag-pill"><?= htmlspecialchars(ucfirst($shapeTag)) ?></span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>

                                    <div class="model-meta">
                                        Width: <?= htmlspecialchars($hair['width_scale']) ?><br>
                                        Height: <?= htmlspecialchars($hair['height_scale']) ?><br>
                                        Offset X: <?= htmlspecialchars($hair['offset_x']) ?><br>
                                        Offset Y: <?= htmlspecialchars($hair['offset_y']) ?><br>
                                        Anchor Y: <?= htmlspecialchars($hair['anchor_y']) ?><br>
                                        Rotation Offset: <?= htmlspecialchars($hair['rotation_offset']) ?><br>
                                        Flip X: <?= $hair['flip_x'] ? 'Yes' : 'No' ?>
                                    </div>

                                    <a href="admin_edit_ar_model.php?id=<?= $hair['id'] ?>" class="edit-btn">Edit</a>
                                    <button
                                        type="button"
                                        class="delete-btn open-delete-modal"
                                        data-id="<?= (int)$hair['id'] ?>"
                                        data-name="<?= htmlspecialchars($hair['name'], ENT_QUOTES) ?>"
                                    >
                                        Delete
                                    </button>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <p>No hairstyles found yet.</p>
                    <?php endif; ?>
                </div>
            </section>
        </div>
    </main>
</div>

<div class="modal-backdrop" id="deleteModal">
    <div class="modal">
        <div class="modal-header">
            <h3>Delete Hairstyle</h3>
        </div>
        <div class="modal-body">
            <p>Are you sure you want to delete <strong id="deleteHairName">this hairstyle</strong>?</p>
            <p>This action cannot be undone.</p>

            <div class="modal-actions">
                <button type="button" class="cancel-btn" id="cancelDeleteBtn">Cancel</button>
                <a href="#" class="confirm-delete-btn" id="confirmDeleteBtn">Delete</a>
            </div>
        </div>
    </div>
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

function openDeleteModal(id, name) {
    document.getElementById('deleteHairName').textContent = name;
    document.getElementById('confirmDeleteBtn').href = 'admin_delete_ar_model.php?id=' + encodeURIComponent(id);
    document.getElementById('deleteModal').classList.add('show');
}

function closeDeleteModal() {
    document.getElementById('deleteModal').classList.remove('show');
}

const menuBtn = document.getElementById('menuBtn');
const sidebarOverlay = document.getElementById('sidebarOverlay');
const cancelDeleteBtn = document.getElementById('cancelDeleteBtn');

if (menuBtn) {
    menuBtn.addEventListener('click', openSidebar);
}

if (sidebarOverlay) {
    sidebarOverlay.addEventListener('click', function() {
        closeSidebar();
        closeDeleteModal();
    });
}

if (cancelDeleteBtn) {
    cancelDeleteBtn.addEventListener('click', closeDeleteModal);
}

document.querySelectorAll('.open-delete-modal').forEach(btn => {
    btn.addEventListener('click', function() {
        openDeleteModal(this.dataset.id, this.dataset.name);
    });
});

document.getElementById('deleteModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeDeleteModal();
    }
});

window.addEventListener('resize', function () {
    if (window.innerWidth > 900) {
        closeSidebar();
    }
});
</script>

</body>
</html>