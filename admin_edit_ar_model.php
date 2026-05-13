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
$error_message = "";
$availableFaceShapes = ['oval', 'round', 'square', 'heart', 'oblong'];
$selectedFaceShapes = [];

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    $error_message = "Invalid hairstyle ID.";
} else {
    $id = intval($_GET['id']);

    $stmt = $conn->prepare("SELECT * FROM hairstyles WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $hair = $result->fetch_assoc();
    $stmt->close();

    if (!$hair) {
        $error_message = "Hairstyle not found.";
    } else {
        $mapStmt = $conn->prepare("SELECT face_shape FROM hairstyle_face_shapes WHERE hairstyle_id = ?");
        $mapStmt->bind_param("i", $id);
        $mapStmt->execute();
        $mapResult = $mapStmt->get_result();

        while ($row = $mapResult->fetch_assoc()) {
            $selectedFaceShapes[] = $row['face_shape'];
        }

        $mapStmt->close();
    }
}

if ($error_message === "" && $_SERVER['REQUEST_METHOD'] === 'POST') {
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

    $image_path = $hair['image_path'];

    if ($name === "") {
        $message = "Hairstyle name is required.";
    } else {
        if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
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
                $new_path = $upload_dir . $new_name;

                if (move_uploaded_file($_FILES['image']['tmp_name'], $new_path)) {
                    if (!empty($hair['image_path']) && file_exists($hair['image_path'])) {
                        unlink($hair['image_path']);
                    }
                    $image_path = $new_path;
                } else {
                    $message = "Failed to upload replacement image.";
                }
            }
        }

        if ($message === "") {
            $update = $conn->prepare("
                UPDATE hairstyles
                SET name = ?, image_path = ?, width_scale = ?, height_scale = ?, offset_x = ?, offset_y = ?, anchor_y = ?, rotation_offset = ?, flip_x = ?, is_active = ?
                WHERE id = ?
            ");
            $update->bind_param(
                "ssddiiddiii",
                $name,
                $image_path,
                $width_scale,
                $height_scale,
                $offset_x,
                $offset_y,
                $anchor_y,
                $rotation_offset,
                $flip_x,
                $is_active,
                $id
            );

            if ($update->execute()) {
                $update->close();

                $deleteMap = $conn->prepare("DELETE FROM hairstyle_face_shapes WHERE hairstyle_id = ?");
                $deleteMap->bind_param("i", $id);
                $deleteMap->execute();
                $deleteMap->close();

                if (!empty($face_shapes)) {
                    $insertMap = $conn->prepare("
                        INSERT INTO hairstyle_face_shapes (hairstyle_id, face_shape)
                        VALUES (?, ?)
                    ");

                    foreach ($face_shapes as $face_shape) {
                        $insertMap->bind_param("is", $id, $face_shape);
                        $insertMap->execute();
                    }

                    $insertMap->close();
                }

                header("Location: admin_ar_models.php");
                exit();
            } else {
                $message = "Failed to update hairstyle.";
            }
            $update->close();
        }
    }

    $selectedFaceShapes = $face_shapes;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit AR Hair Model</title>
    <link rel="stylesheet" href="styles.css">
    <style>
        * { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: 'Poppins', sans-serif;
            background: #f4f4f4;
            color: #222;
        }

        .page {
            max-width: 900px;
            margin: 30px auto;
            padding: 20px;
        }

        .panel {
            background: #fff;
            border-radius: 18px;
            padding: 22px;
            box-shadow: 0 4px 14px rgba(0,0,0,0.08);
        }

        .panel h2 {
            margin-top: 0;
            margin-bottom: 16px;
            font-size: 26px;
            color: #111827;
        }

        .message {
            margin-bottom: 16px;
            padding: 12px 14px;
            border-radius: 12px;
            font-weight: 600;
        }

        .message.error {
            background: #fde2e2;
            color: #991b1b;
        }

        .message.warning {
            background: #fff3cd;
            color: #7a5a00;
        }

        .preview {
            text-align: center;
            margin-bottom: 18px;
        }

        .preview img {
            max-width: 220px;
            max-height: 220px;
            object-fit: contain;
            background: #111;
            border-radius: 12px;
            padding: 10px;
        }

        .form-group {
            margin-bottom: 14px;
        }

        label {
            display: block;
            margin-bottom: 6px;
            font-weight: 600;
            color: #374151;
        }

        .form-group small {
            display: block;
            margin-top: 6px;
            color: #6b7280;
            line-height: 1.5;
            font-size: 12px;
        }

        input[type="text"],
        input[type="number"],
        input[type="file"] {
            width: 100%;
            padding: 12px;
            border-radius: 12px;
            border: 1px solid #ddd;
            font-size: 14px;
            background: #fff;
            outline: none;
        }

        input:focus {
            border-color: #A70202;
            box-shadow: 0 0 0 4px rgba(167, 2, 2, 0.08);
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
            margin-top: 10px;
        }

        .checkboxes label {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 0;
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
            color: #111827;
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

        .actions {
            display: flex;
            gap: 12px;
            margin-top: 18px;
            flex-wrap: wrap;
        }

        .btn {
            display: inline-block;
            text-decoration: none;
            border: none;
            border-radius: 10px;
            padding: 12px 16px;
            font-weight: 600;
            cursor: pointer;
            text-align: center;
        }

        .btn-primary {
            background: #A70202;
            color: #fff;
        }

        .btn-primary:hover {
            background: #850101;
        }

        .btn-secondary {
            background: #111;
            color: #fff;
        }

        @media (max-width: 768px) {
            .row-2,
            .face-shape-grid {
                grid-template-columns: 1fr;
            }

            .actions {
                flex-direction: column;
            }

            .btn {
                width: 100%;
            }

            .page {
                padding: 14px;
            }

            .panel {
                padding: 16px;
            }
        }
    </style>
</head>
<body>
<div class="page">
    <div class="panel">
        <h2>Edit Hairstyle</h2>

        <?php if ($error_message !== ""): ?>
            <div class="message error"><?= htmlspecialchars($error_message) ?></div>
            <div class="actions">
                <a href="admin_ar_models.php" class="btn btn-secondary">Back to AR Hair Models</a>
            </div>
        <?php else: ?>

            <?php if ($message): ?>
                <div class="message warning"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>

            <div class="preview">
                <img src="<?= htmlspecialchars($hair['image_path']) ?>" alt="<?= htmlspecialchars($hair['name']) ?>">
            </div>

            <form method="POST" enctype="multipart/form-data">
                <div class="form-group">
                    <label>Hairstyle Name</label>
                    <input type="text" name="name" value="<?= htmlspecialchars($hair['name']) ?>" required>
                    <small>This is the name shown in the admin list and AR hairstyle choices.</small>
                </div>

                <div class="form-group">
                    <label>Replace Image</label>
                    <input type="file" name="image" accept=".png,.jpg,.jpeg,.webp">
                    <small>Upload a transparent PNG if possible for the cleanest overlay result. Leave blank to keep the current image.</small>
                </div>

                <div class="row-2">
                    <div class="form-group">
                        <label>Width Scale</label>
                        <input type="number" step="0.01" name="width_scale" value="<?= htmlspecialchars($hair['width_scale']) ?>" required>
                        <small>Controls how wide the hairstyle appears on the face.</small>
                    </div>
                    <div class="form-group">
                        <label>Height Scale</label>
                        <input type="number" step="0.01" name="height_scale" value="<?= htmlspecialchars($hair['height_scale']) ?>" required>
                        <small>Controls how tall the hairstyle appears.</small>
                    </div>
                </div>

                <div class="row-2">
                    <div class="form-group">
                        <label>Offset X</label>
                        <input type="number" name="offset_x" value="<?= htmlspecialchars($hair['offset_x']) ?>" required>
                        <small>Moves the hairstyle left or right. Negative = left, positive = right.</small>
                    </div>
                    <div class="form-group">
                        <label>Offset Y</label>
                        <input type="number" name="offset_y" value="<?= htmlspecialchars($hair['offset_y']) ?>" required>
                        <small>Moves the hairstyle up or down. Negative = higher, positive = lower.</small>
                    </div>
                </div>

                <div class="row-2">
                    <div class="form-group">
                        <label>Anchor Y</label>
                        <input type="number" step="0.01" name="anchor_y" value="<?= htmlspecialchars($hair['anchor_y']) ?>" required>
                        <small>Controls the vertical anchor point used to attach the hair to the face mesh.</small>
                    </div>
                    <div class="form-group">
                        <label>Rotation Offset</label>
                        <input type="number" step="0.01" name="rotation_offset" value="<?= htmlspecialchars($hair['rotation_offset']) ?>" required>
                        <small>Adjusts the rotation if the hairstyle looks tilted or not following the head correctly.</small>
                    </div>
                </div>

                <div class="form-group">
                    <label>Recommended Face Shapes</label>
                    <div class="face-shape-grid">
                        <label class="face-shape-option"><input type="checkbox" name="face_shapes[]" value="oval" <?= in_array('oval', $selectedFaceShapes) ? 'checked' : '' ?>> Oval</label>
                        <label class="face-shape-option"><input type="checkbox" name="face_shapes[]" value="round" <?= in_array('round', $selectedFaceShapes) ? 'checked' : '' ?>> Round</label>
                        <label class="face-shape-option"><input type="checkbox" name="face_shapes[]" value="square" <?= in_array('square', $selectedFaceShapes) ? 'checked' : '' ?>> Square</label>
                        <label class="face-shape-option"><input type="checkbox" name="face_shapes[]" value="heart" <?= in_array('heart', $selectedFaceShapes) ? 'checked' : '' ?>> Heart</label>
                        <label class="face-shape-option"><input type="checkbox" name="face_shapes[]" value="oblong" <?= in_array('oblong', $selectedFaceShapes) ? 'checked' : '' ?>> Oblong</label>
                    </div>
                    <small>Select which face shapes this hairstyle should be recommended for.</small>
                </div>

                <div class="checkboxes">
                    <label><input type="checkbox" name="flip_x" <?= $hair['flip_x'] ? 'checked' : '' ?>> Flip X</label>
                    <label><input type="checkbox" name="is_active" <?= $hair['is_active'] ? 'checked' : '' ?>> Active</label>
                </div>

                <div class="notes-box">
                    <h3>Quick Tuning Guide</h3>
                    <p><strong>Width Scale</strong> is for making the hairstyle wider or narrower on the head.</p>
                    <p><strong>Height Scale</strong> is for making the hairstyle taller or shorter.</p>
                    <p><strong>Offset X</strong> is for moving the hairstyle left or right.</p>
                    <p><strong>Offset Y</strong> is for moving the hairstyle higher or lower on the forehead.</p>
                    <p><strong>Anchor Y</strong> is for adjusting where the hairstyle locks vertically to the face.</p>
                    <p><strong>Rotation Offset</strong> is for fixing tilt if the hairstyle rotates incorrectly.</p>
                    <p><strong>Flip X</strong> is for mirroring the hairstyle horizontally when the overlay appears reversed.</p>
                    <p><strong>Recommended Face Shapes</strong> controls which customers see this hairstyle after scanning.</p>
                </div>

                <div class="actions">
                    <button type="submit" class="btn btn-primary">Update Hairstyle</button>
                    <a href="admin_ar_models.php" class="btn btn-secondary">Back</a>
                </div>
            </form>

        <?php endif; ?>
    </div>
</div>
</body>
</html>