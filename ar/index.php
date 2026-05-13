<?php
session_start();
include '../db.php';

if (!isset($_SESSION['user_id'])) {
    $redirect = urlencode('ar/index.php');
    header("Location: ../login.php?redirect={$redirect}");
    exit();
}

$hairstyles = [];

$sql = "
    SELECT 
        h.*,
        GROUP_CONCAT(DISTINCT hfs.face_shape ORDER BY hfs.face_shape SEPARATOR ',') AS face_shapes_csv
    FROM hairstyles h
    LEFT JOIN hairstyle_face_shapes hfs ON h.id = hfs.hairstyle_id
    WHERE h.is_active = 1
    GROUP BY h.id
    ORDER BY h.name ASC
";
$result = $conn->query($sql);

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $hairstyles[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>AR Haircut Recommendation</title>
  <link rel="stylesheet" href="style.css" />

  <script src="https://cdn.jsdelivr.net/npm/@mediapipe/face_mesh/face_mesh.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/@mediapipe/camera_utils/camera_utils.js"></script>
</head>
<body>
  <div class="page-shell">
    <div class="page-header">
      <div class="header-badge">Haircut Recommendation Using Augmented Reality</div>
      <h1>AR Haircut Studio</h1>
      <p>
        Scan your face shape, get hairstyle recommendations, preview them in real time,
        and save your preferred look before booking.
      </p>
    </div>

    <div class="ar-layout">
      <section class="preview-panel">
        <div class="panel-head">
          <div>
            <h2>Live Camera Preview</h2>
            <p>Look straight at the camera, scan your face shape, then try your recommended styles.</p>
          </div>
          <span class="live-pill">Live</span>
        </div>

        <div class="camera-card">
          <div class="camera-wrap" id="cameraWrap">
            <video id="video" autoplay playsinline muted></video>
            <canvas id="canvas"></canvas>

            <div class="camera-overlay-top">
              <div class="camera-tip">Center your face and keep your head upright</div>
            </div>

            <div class="camera-guides">
              <div class="guide-oval"></div>
            </div>
          </div>
        </div>

        <div class="scan-result-card">
          <div class="scan-result-head">
            <div>
              <h3>Face Shape Recommendation</h3>
              <p>Scan result is an automated estimate for styling guidance.</p>
            </div>
            <div class="shape-meta">
              <span class="confidence-pill" id="confidencePill">Confidence: --</span>
              <span class="shape-pill" id="detectedShapePill">Not Scanned</span>
            </div>
          </div>

          <div class="metrics-grid">
            <div class="metric-box">
              <span>Face Length Ratio</span>
              <strong id="faceRatioValue">--</strong>
            </div>
            <div class="metric-box">
              <span>Forehead Width</span>
              <strong id="foreheadValue">--</strong>
            </div>
            <div class="metric-box">
              <span>Cheekbone Width</span>
              <strong id="cheekboneValue">--</strong>
            </div>
            <div class="metric-box">
              <span>Jaw Width</span>
              <strong id="jawValue">--</strong>
            </div>
          </div>

          <div class="recommendation-summary" id="recommendationSummary">
            Press <strong>Scan Face Shape</strong> to get hairstyle recommendations.
          </div>

          <div class="recommended-list" id="recommendedList"></div>
        </div>
      </section>

      <aside class="control-panel">
        <div class="control-card">
          <div class="control-card-head">
            <h3>Style Settings</h3>
            <p>Scan first for face-shape based recommendations, or browse all hairstyles manually.</p>
          </div>

          <div class="action-buttons action-buttons-top">
            <button id="scanBtn" class="btn btn-primary" type="button">Scan Face Shape</button>
            <button id="showAllBtn" class="btn btn-secondary" type="button">Show All Styles</button>
          </div>

          <div class="form-group">
            <label for="hairSelect">Choose Hairstyle</label>
            <select id="hairSelect"></select>
          </div>

          <div class="form-group">
            <label for="hairColor">Hair Color</label>
            <input type="color" id="hairColor" value="#111111" />
          </div>

          <div class="color-swatches">
            <button type="button" class="swatch active" data-color="#111111" style="background:#111111;" title="Natural Black"></button>
            <button type="button" class="swatch" data-color="#3b2a1f" style="background:#3b2a1f;" title="Dark Brown"></button>
            <button type="button" class="swatch" data-color="#6b4a2f" style="background:#6b4a2f;" title="Brown"></button>
            <button type="button" class="swatch" data-color="#8a5a3b" style="background:#8a5a3b;" title="Light Brown"></button>
            <button type="button" class="swatch" data-color="#9b7a52" style="background:#9b7a52;" title="Ash Brown"></button>
            <button type="button" class="swatch" data-color="#7a1f1f" style="background:#7a1f1f;" title="Burgundy"></button>
          </div>

          <div class="form-group">
            <label for="opacityRange">Hair Opacity</label>
            <input type="range" id="opacityRange" min="0.4" max="1" step="0.05" value="1" />
            <div class="range-value">
              <span>Soft</span>
              <strong id="opacityValue">100%</strong>
              <span>Full</span>
            </div>
          </div>

          <div class="status-box" id="statusText">
            Camera is starting...
          </div>

          <div class="action-buttons">
            <button id="saveBtn" class="btn btn-primary" type="button">Save Photo</button>
            <button id="resetBtn" class="btn btn-secondary" type="button">Reset</button>
          </div>

          <div class="book-action">
            <a href="../index.php#services" class="btn btn-book">Book Appointment</a>
          </div>
        </div>

        <div class="control-card info-card">
          <div class="control-card-head">
            <h3>Tips for Best Results</h3>
          </div>
          <ul class="tips-list">
            <li>Use a bright area with your full face visible.</li>
            <li>Keep your face straight and avoid strong head tilt while scanning.</li>
            <li>Recommendations are based on estimated face shape and owner-assigned hairstyle mappings.</li>
            <li>Saved photo keeps the same mirrored salon-preview look.</li>
          </ul>
        </div>
      </aside>
    </div>
  </div>

  <script>
    const hairstylesFromDB = <?= json_encode($hairstyles, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  </script>
  <script src="script.js"></script>
</body>
</html>