const video = document.getElementById("video");
const canvas = document.getElementById("canvas");
const ctx = canvas.getContext("2d");

const hairSelect = document.getElementById("hairSelect");
const saveBtn = document.getElementById("saveBtn");
const resetBtn = document.getElementById("resetBtn");
const scanBtn = document.getElementById("scanBtn");
const showAllBtn = document.getElementById("showAllBtn");

const hairColorInput = document.getElementById("hairColor");
const opacityRange = document.getElementById("opacityRange");
const opacityValue = document.getElementById("opacityValue");
const statusText = document.getElementById("statusText");
const swatches = document.querySelectorAll(".swatch");
const cameraWrap = document.getElementById("cameraWrap");

const detectedShapePill = document.getElementById("detectedShapePill");
const confidencePill = document.getElementById("confidencePill");
const recommendationSummary = document.getElementById("recommendationSummary");
const recommendedList = document.getElementById("recommendedList");

const faceRatioValue = document.getElementById("faceRatioValue");
const foreheadValue = document.getElementById("foreheadValue");
const cheekboneValue = document.getElementById("cheekboneValue");
const jawValue = document.getElementById("jawValue");

const FACE_SHAPE_LABELS = {
  oval: "Oval",
  round: "Round",
  square: "Square",
  heart: "Heart",
  oblong: "Oblong"
};

const NO_HAIR_VALUE = "__none__";

function resizeCanvasToDisplaySize() {
  const rect = canvas.getBoundingClientRect();
  const dpr = window.devicePixelRatio || 1;

  canvas.width = Math.round(rect.width * dpr);
  canvas.height = Math.round(rect.height * dpr);

  ctx.setTransform(1, 0, 0, 1, 0, 0);
  ctx.scale(dpr, dpr);
}

resizeCanvasToDisplaySize();
window.addEventListener("resize", resizeCanvasToDisplaySize);

const HAIR_SETTINGS = {};
const HAIR_LIST = [];

hairstylesFromDB.forEach((hair) => {
  const faceShapes = String(hair.face_shapes_csv || "")
    .split(",")
    .map((value) => value.trim().toLowerCase())
    .filter(Boolean);

  HAIR_SETTINGS[hair.image_path] = {
    id: parseInt(hair.id, 10),
    name: hair.name,
    src: "../" + hair.image_path,
    widthScale: parseFloat(hair.width_scale || 1),
    heightScale: parseFloat(hair.height_scale || 1),
    offsetX: parseInt(hair.offset_x || 0, 10),
    offsetY: parseInt(hair.offset_y || 0, 10),
    anchorY: parseFloat(hair.anchor_y || 0.5),
    rotationOffset: parseFloat(hair.rotation_offset || 0),
    flipX: parseInt(hair.flip_x || 0, 10) === 1,
    faceShapes
  };

  HAIR_LIST.push({
    value: hair.image_path,
    label: hair.name,
    faceShapes
  });
});

let filteredHairList = [...HAIR_LIST];
let currentHair = null;
let latestLandmarks = null;
let latestClassifyResult = null;
let lastDetectedFaceShape = null;
let isScanning = false;

const hairImg = new Image();
hairImg.crossOrigin = "anonymous";

function populateHairSelect(items, preserveValue = true, includeNone = true) {
  const previousValue = preserveValue ? hairSelect.value : NO_HAIR_VALUE;

  hairSelect.innerHTML = "";

  if (includeNone) {
    const noneOption = document.createElement("option");
    noneOption.value = NO_HAIR_VALUE;
    noneOption.textContent = "No AR Hair";
    hairSelect.appendChild(noneOption);
  }

  items.forEach((item) => {
    const option = document.createElement("option");
    option.value = item.value;
    option.textContent = item.label;
    hairSelect.appendChild(option);
  });

  const allowedValues = [
    ...(includeNone ? [NO_HAIR_VALUE] : []),
    ...items.map((item) => item.value)
  ];

  hairSelect.value = allowedValues.includes(previousValue)
    ? previousValue
    : (includeNone ? NO_HAIR_VALUE : (items[0]?.value || ""));

  updateCurrentHairFromSelect();
}

function updateCurrentHairFromSelect() {
  if (hairSelect.value === NO_HAIR_VALUE || !hairSelect.value) {
    currentHair = null;
    hairImg.removeAttribute("src");
    return;
  }

  currentHair = HAIR_SETTINGS[hairSelect.value] || null;

  if (currentHair) {
    hairImg.src = currentHair.src;
  }
}

populateHairSelect(filteredHairList, false, true);

let hairColor = hairColorInput.value || "#111111";
let hairOpacity = parseFloat(opacityRange.value || "1");
opacityValue.textContent = `${Math.round(hairOpacity * 100)}%`;

function updateSwatchActive(color) {
  swatches.forEach((swatch) => {
    swatch.classList.toggle(
      "active",
      swatch.dataset.color.toLowerCase() === color.toLowerCase()
    );
  });
}

hairColorInput.addEventListener("input", () => {
  hairColor = hairColorInput.value;
  updateSwatchActive(hairColor);
});

swatches.forEach((swatch) => {
  swatch.addEventListener("click", () => {
    hairColor = swatch.dataset.color;
    hairColorInput.value = hairColor;
    updateSwatchActive(hairColor);
  });
});

opacityRange.addEventListener("input", () => {
  hairOpacity = parseFloat(opacityRange.value);
  opacityValue.textContent = `${Math.round(hairOpacity * 100)}%`;
});

hairSelect.addEventListener("change", () => {
  updateCurrentHairFromSelect();

  if (currentHair) {
    statusText.textContent = `Selected hairstyle: ${currentHair.name}`;
    updateRecommendedActiveChip(currentHair.id);
  } else {
    statusText.textContent = "No AR hair selected. Scan your face shape to get recommendations.";
    updateRecommendedActiveChip(null);
  }

  smooth = {
    x: null,
    y: null,
    width: null,
    height: null,
    angle: null
  };
});

resetBtn.addEventListener("click", () => {
  filteredHairList = [...HAIR_LIST];
  populateHairSelect(filteredHairList, false, true);

  hairColor = "#111111";
  hairOpacity = 1;
  hairColorInput.value = hairColor;
  opacityRange.value = "1";
  opacityValue.textContent = "100%";
  updateSwatchActive(hairColor);

  smooth = {
    x: null,
    y: null,
    width: null,
    height: null,
    angle: null
  };

  latestClassifyResult = null;
  lastDetectedFaceShape = null;
  updateDetectedShapeUI(null, null, null);
  recommendedList.innerHTML = "";
  recommendationSummary.innerHTML = 'Press <strong>Scan Face Shape</strong> to get hairstyle recommendations.';
  statusText.textContent = "Settings reset successfully.";
});

showAllBtn.addEventListener("click", () => {
  filteredHairList = [...HAIR_LIST];
  populateHairSelect(filteredHairList, true, true);
  renderRecommendedStyles([]);

  recommendationSummary.innerHTML = lastDetectedFaceShape
    ? `Showing all active hairstyles. Detected face shape: <strong>${FACE_SHAPE_LABELS[lastDetectedFaceShape]}</strong>.`
    : "Showing all active hairstyles.";

  statusText.textContent = "Showing all active hairstyles.";
});

function toPixel(point, width, height) {
  return {
    x: point.x * width,
    y: point.y * height
  };
}

function distance(a, b) {
  return Math.hypot(b.x - a.x, b.y - a.y);
}

function lerp(start, end, t) {
  return start + (end - start) * t;
}

function smoothValue(current, target, factor = 0.2) {
  if (current === null || current === undefined || Number.isNaN(current)) {
    return target;
  }

  return lerp(current, target, factor);
}

let smooth = {
  x: null,
  y: null,
  width: null,
  height: null,
  angle: null
};

const faceMesh = new FaceMesh({
  locateFile: (file) => `https://cdn.jsdelivr.net/npm/@mediapipe/face_mesh/${file}`
});

faceMesh.setOptions({
  maxNumFaces: 1,
  refineLandmarks: true,
  minDetectionConfidence: 0.72,
  minTrackingConfidence: 0.72
});

function hexToRgb(hex) {
  let cleanHex = hex.replace("#", "").trim();

  if (cleanHex.length === 3) {
    cleanHex = cleanHex.split("").map((c) => c + c).join("");
  }

  const num = parseInt(cleanHex, 16);

  return {
    r: (num >> 16) & 255,
    g: (num >> 8) & 255,
    b: num & 255
  };
}

function removeNearBlackPixels(tempCtx, width, height) {
  const imageData = tempCtx.getImageData(0, 0, width, height);
  const data = imageData.data;

  for (let i = 0; i < data.length; i += 4) {
    const r = data[i];
    const g = data[i + 1];
    const b = data[i + 2];
    const a = data[i + 3];

    if (a > 0 && r < 25 && g < 25 && b < 25) {
      data[i + 3] = 0;
    }
  }

  tempCtx.putImageData(imageData, 0, 0);
}

function createTintedHairCanvas(width, height) {
  const tempCanvas = document.createElement("canvas");
  const tempCtx = tempCanvas.getContext("2d");

  tempCanvas.width = Math.max(1, Math.round(width));
  tempCanvas.height = Math.max(1, Math.round(height));

  tempCtx.clearRect(0, 0, tempCanvas.width, tempCanvas.height);
  tempCtx.drawImage(hairImg, 0, 0, tempCanvas.width, tempCanvas.height);

  removeNearBlackPixels(tempCtx, tempCanvas.width, tempCanvas.height);

  const rgb = hexToRgb(hairColor);

  tempCtx.globalCompositeOperation = "source-atop";
  tempCtx.fillStyle = `rgba(${rgb.r}, ${rgb.g}, ${rgb.b}, 0.78)`;
  tempCtx.fillRect(0, 0, tempCanvas.width, tempCanvas.height);

  tempCtx.globalCompositeOperation = "multiply";
  tempCtx.drawImage(hairImg, 0, 0, tempCanvas.width, tempCanvas.height);

  tempCtx.globalCompositeOperation = "destination-in";
  tempCtx.drawImage(tempCanvas, 0, 0);

  tempCtx.globalCompositeOperation = "source-over";

  return tempCanvas;
}

function getStableTopHead(landmarks, width, height) {
  const foreheadA = toPixel(landmarks[10], width, height);
  const foreheadB = toPixel(landmarks[67], width, height);
  const foreheadC = toPixel(landmarks[297], width, height);

  return {
    x: (foreheadA.x + foreheadB.x + foreheadC.x) / 3,
    y: (foreheadA.y + foreheadB.y + foreheadC.y) / 3
  };
}

function getMobileHairYOffset(headHeight) {
  if (window.innerWidth <= 480) {
    return headHeight * 0.12;
  }

  if (window.innerWidth <= 768) {
    return headHeight * 0.08;
  }

  return 0;
}

function drawHairOverlay(drawCtx, drawWidth, drawHeight, landmarks, useSmoothing = true) {
  if (!currentHair || !hairImg.complete || !landmarks) return;

  const topHead = getStableTopHead(landmarks, drawWidth, drawHeight);
  const chin = toPixel(landmarks[152], drawWidth, drawHeight);
  const leftForehead = toPixel(landmarks[109], drawWidth, drawHeight);
  const rightForehead = toPixel(landmarks[338], drawWidth, drawHeight);
  const leftSide = toPixel(landmarks[234], drawWidth, drawHeight);
  const rightSide = toPixel(landmarks[454], drawWidth, drawHeight);

  const foreheadCenterX = (leftForehead.x + rightForehead.x) / 2;
  const foreheadWidth = distance(leftForehead, rightForehead);
  const sideWidth = distance(leftSide, rightSide);
  const headHeight = distance(topHead, chin);

  const baseWidth = foreheadWidth * 0.68 + sideWidth * 0.32;

  const targetWidth = baseWidth * currentHair.widthScale;
  const targetHeight = headHeight * currentHair.heightScale;
  const targetX = foreheadCenterX + currentHair.offsetX;

  const mobileYOffset = getMobileHairYOffset(headHeight);
  const targetY = topHead.y + currentHair.offsetY + mobileYOffset;

  const targetAngle =
    Math.atan2(
      rightForehead.y - leftForehead.y,
      rightForehead.x - leftForehead.x
    ) + currentHair.rotationOffset;

  let drawX = targetX;
  let drawY = targetY;
  let drawOverlayWidth = targetWidth;
  let drawOverlayHeight = targetHeight;
  let drawAngle = targetAngle;

  if (useSmoothing) {
    smooth.x = smoothValue(smooth.x, targetX, 0.22);
    smooth.y = smoothValue(smooth.y, targetY, 0.22);
    smooth.width = smoothValue(smooth.width, targetWidth, 0.22);
    smooth.height = smoothValue(smooth.height, targetHeight, 0.22);
    smooth.angle = smoothValue(smooth.angle, targetAngle, 0.16);

    drawX = smooth.x;
    drawY = smooth.y;
    drawOverlayWidth = smooth.width;
    drawOverlayHeight = smooth.height;
    drawAngle = smooth.angle;
  }

  const tintedHairCanvas = createTintedHairCanvas(drawOverlayWidth, drawOverlayHeight);

  drawCtx.save();
  drawCtx.translate(drawX, drawY);
  drawCtx.rotate(drawAngle);

  if (currentHair.flipX) {
    drawCtx.scale(-1, 1);
  }

  drawCtx.globalAlpha = hairOpacity;
  drawCtx.drawImage(
    tintedHairCanvas,
    -drawOverlayWidth / 2,
    -drawOverlayHeight * currentHair.anchorY,
    drawOverlayWidth,
    drawOverlayHeight
  );

  drawCtx.restore();

  drawCtx.globalAlpha = 1;
  drawCtx.globalCompositeOperation = "source-over";
}

function getFaceMetrics(landmarks, width, height) {
  const topHead = getStableTopHead(landmarks, width, height);
  const chin = toPixel(landmarks[152], width, height);

  const leftForehead = toPixel(landmarks[54], width, height);
  const rightForehead = toPixel(landmarks[284], width, height);

  const leftCheek = toPixel(landmarks[234], width, height);
  const rightCheek = toPixel(landmarks[454], width, height);

  const leftJaw = toPixel(landmarks[172], width, height);
  const rightJaw = toPixel(landmarks[397], width, height);

  const faceLength = distance(topHead, chin);
  const foreheadWidth = distance(leftForehead, rightForehead);
  const cheekboneWidth = distance(leftCheek, rightCheek);
  const jawWidth = distance(leftJaw, rightJaw);

  const faceRatio = cheekboneWidth > 0 ? faceLength / cheekboneWidth : 0;
  const foreheadToCheek = cheekboneWidth > 0 ? foreheadWidth / cheekboneWidth : 0;
  const jawToCheek = cheekboneWidth > 0 ? jawWidth / cheekboneWidth : 0;
  const foreheadToJaw = jawWidth > 0 ? foreheadWidth / jawWidth : 0;

  return {
    faceLength,
    foreheadWidth,
    cheekboneWidth,
    jawWidth,
    faceRatio,
    foreheadToCheek,
    jawToCheek,
    foreheadToJaw
  };
}

function scoreFaceShape(metrics) {
  const templates = {
    oval: {
      faceRatio: 1.45,
      foreheadToCheek: 0.94,
      jawToCheek: 0.88,
      foreheadToJaw: 1.06
    },
    round: {
      faceRatio: 1.23,
      foreheadToCheek: 0.98,
      jawToCheek: 0.95,
      foreheadToJaw: 1.02
    },
    square: {
      faceRatio: 1.32,
      foreheadToCheek: 0.98,
      jawToCheek: 0.97,
      foreheadToJaw: 1.00
    },
    heart: {
      faceRatio: 1.42,
      foreheadToCheek: 0.95,
      jawToCheek: 0.76,
      foreheadToJaw: 1.18
    },
    oblong: {
      faceRatio: 1.67,
      foreheadToCheek: 0.93,
      jawToCheek: 0.86,
      foreheadToJaw: 1.05
    }
  };

  const weights = {
    faceRatio: 4.0,
    foreheadToCheek: 1.8,
    jawToCheek: 3.0,
    foreheadToJaw: 3.5
  };

  const scores = {};

  Object.entries(templates).forEach(([shape, template]) => {
    let score = 0;

    score += Math.abs(metrics.faceRatio - template.faceRatio) * weights.faceRatio;
    score += Math.abs(metrics.foreheadToCheek - template.foreheadToCheek) * weights.foreheadToCheek;
    score += Math.abs(metrics.jawToCheek - template.jawToCheek) * weights.jawToCheek;
    score += Math.abs(metrics.foreheadToJaw - template.foreheadToJaw) * weights.foreheadToJaw;

    if (shape === "heart") {
      if (metrics.foreheadToJaw < 1.12) score += 0.50;
      if (metrics.faceRatio > 1.56) score += 0.35;
    }

    if (shape === "oblong") {
      if (metrics.faceRatio < 1.54) score += 0.45;
      if (metrics.foreheadToJaw > 1.12) score += 0.20;
    }

    if (shape === "oval") {
      if (metrics.faceRatio > 1.60) score += 0.25;
      if (metrics.foreheadToJaw > 1.16) score += 0.25;
    }

    if (shape === "round" && metrics.faceRatio > 1.40) {
      score += 0.60;
    }

    if (shape === "square" && metrics.jawToCheek < 0.90) {
      score += 0.45;
    }

    scores[shape] = score;
  });

  return scores;
}

function classifyFaceShape(metrics) {
  const scores = scoreFaceShape(metrics);
  const sorted = Object.entries(scores).sort((a, b) => a[1] - b[1]);

  let bestShape = sorted[0][0];
  const bestScore = sorted[0][1];
  const secondBestScore = sorted[1][1];
  const confidenceGap = secondBestScore - bestScore;

  if (
    bestShape === "heart" &&
    metrics.faceRatio >= 1.56 &&
    metrics.foreheadToJaw < 1.20
  ) {
    bestShape = "oblong";
  }

  if (
    bestShape === "heart" &&
    metrics.faceRatio < 1.56 &&
    metrics.foreheadToJaw < 1.18
  ) {
    bestShape = "oval";
  }

  return {
    shape: bestShape,
    score: bestScore,
    confidenceGap,
    scores
  };
}

function getConfidenceLabel(confidenceGap) {
  if (confidenceGap >= 0.22) return "High";
  if (confidenceGap >= 0.12) return "Medium";
  return "Low";
}

function updateDetectedShapeUI(shape, metrics, confidenceGap) {
  if (!shape) {
    detectedShapePill.textContent = "Not Scanned";
    confidencePill.textContent = "Confidence: --";
    faceRatioValue.textContent = "--";
    foreheadValue.textContent = "--";
    cheekboneValue.textContent = "--";
    jawValue.textContent = "--";
    return;
  }

  detectedShapePill.textContent = FACE_SHAPE_LABELS[shape] || shape;
  confidencePill.textContent = `Confidence: ${getConfidenceLabel(confidenceGap ?? 0)}`;

  if (metrics) {
    faceRatioValue.textContent = metrics.faceRatio.toFixed(2);
    foreheadValue.textContent = Math.round(metrics.foreheadWidth);
    cheekboneValue.textContent = Math.round(metrics.cheekboneWidth);
    jawValue.textContent = Math.round(metrics.jawWidth);
  }
}

function renderRecommendedStyles(styles) {
  recommendedList.innerHTML = "";

  if (!styles.length) {
    return;
  }

  styles.forEach((style) => {
    const button = document.createElement("button");
    button.type = "button";
    button.className = "recommended-chip";
    button.textContent = style.label;
    button.dataset.id = String(HAIR_SETTINGS[style.value]?.id || "");

    button.addEventListener("click", () => {
      hairSelect.value = style.value;
      hairSelect.dispatchEvent(new Event("change"));
      updateRecommendedActiveChip(HAIR_SETTINGS[style.value]?.id || null);
    });

    recommendedList.appendChild(button);
  });
}

function updateRecommendedActiveChip(hairId) {
  document.querySelectorAll(".recommended-chip").forEach((chip) => {
    chip.classList.toggle("active", chip.dataset.id === String(hairId));
  });
}

function getRecommendedStyles(faceShape) {
  return HAIR_LIST.filter((item) => item.faceShapes.includes(faceShape));
}

async function scanFaceShape() {
  if (!latestLandmarks || !video.videoWidth || !video.videoHeight) {
    statusText.textContent = "Face not detected yet. Please face the camera first.";
    return;
  }

  if (isScanning) return;

  isScanning = true;
  scanBtn.disabled = true;
  scanBtn.textContent = "Scanning...";
  statusText.textContent = "Scanning face shape. Please keep your head steady.";

  const samples = [];
  const sampleCount = 18;

  for (let i = 0; i < sampleCount; i++) {
    if (latestLandmarks) {
      const metrics = getFaceMetrics(latestLandmarks, canvas.clientWidth, canvas.clientHeight);
      const result = classifyFaceShape(metrics);

      samples.push({
        shape: result.shape,
        confidenceGap: result.confidenceGap,
        metrics
      });
    }

    await new Promise((resolve) => setTimeout(resolve, 100));
  }

  scanBtn.disabled = false;
  scanBtn.textContent = "Scan Face Shape";
  isScanning = false;

  if (!samples.length) {
    statusText.textContent = "Unable to scan face shape. Please try again.";
    return;
  }

  const filteredSamples = samples.filter((sample) => sample.confidenceGap >= 0.08);
  const usableSamples = filteredSamples.length >= 6 ? filteredSamples : samples;

  const votes = {};
  usableSamples.forEach((sample) => {
    votes[sample.shape] = (votes[sample.shape] || 0) + 1;
  });

  let bestShape = null;
  let bestVotes = -1;

  Object.keys(votes).forEach((shape) => {
    if (votes[shape] > bestVotes) {
      bestShape = shape;
      bestVotes = votes[shape];
    }
  });

  const matchingSamples = usableSamples.filter((sample) => sample.shape === bestShape);

  const averagedMetrics = matchingSamples.reduce(
    (acc, sample) => {
      acc.faceRatio += sample.metrics.faceRatio;
      acc.foreheadWidth += sample.metrics.foreheadWidth;
      acc.cheekboneWidth += sample.metrics.cheekboneWidth;
      acc.jawWidth += sample.metrics.jawWidth;
      acc.confidenceGap += sample.confidenceGap;
      return acc;
    },
    {
      faceRatio: 0,
      foreheadWidth: 0,
      cheekboneWidth: 0,
      jawWidth: 0,
      confidenceGap: 0
    }
  );

  const divisor = Math.max(matchingSamples.length, 1);
  averagedMetrics.faceRatio /= divisor;
  averagedMetrics.foreheadWidth /= divisor;
  averagedMetrics.cheekboneWidth /= divisor;
  averagedMetrics.jawWidth /= divisor;
  averagedMetrics.confidenceGap /= divisor;

  lastDetectedFaceShape = bestShape;
  updateDetectedShapeUI(bestShape, averagedMetrics, averagedMetrics.confidenceGap);

  const recommendedStyles = getRecommendedStyles(bestShape);
  filteredHairList = recommendedStyles;
  populateHairSelect(filteredHairList, false, true);
  renderRecommendedStyles(recommendedStyles);

  if (recommendedStyles.length > 0) {
    recommendationSummary.innerHTML =
      `Detected face shape: <strong>${FACE_SHAPE_LABELS[bestShape]}</strong>. ` +
      `These hairstyles are recommended for this face shape based on the owner's AR hairstyle mapping.`;

    hairSelect.value = recommendedStyles[0].value;
    hairSelect.dispatchEvent(new Event("change"));

    statusText.textContent = `Face shape detected: ${FACE_SHAPE_LABELS[bestShape]}. Recommended hairstyles loaded.`;
  } else {
    hairSelect.value = NO_HAIR_VALUE;
    hairSelect.dispatchEvent(new Event("change"));

    recommendationSummary.innerHTML =
      `Detected face shape: <strong>${FACE_SHAPE_LABELS[bestShape]}</strong>. ` +
      `There are no hairstyles assigned yet for this face shape.`;

    statusText.textContent = `Face shape detected: ${FACE_SHAPE_LABELS[bestShape]}, but no hairstyles are mapped to it yet.`;
  }
}

scanBtn.addEventListener("click", scanFaceShape);

faceMesh.onResults((results) => {
  ctx.clearRect(0, 0, canvas.clientWidth, canvas.clientHeight);

  if (!results.multiFaceLandmarks || results.multiFaceLandmarks.length === 0) {
    latestLandmarks = null;
    latestClassifyResult = null;
    statusText.textContent = "Face not detected. Please align your face in the frame.";
    return;
  }

  latestLandmarks = results.multiFaceLandmarks[0];
  latestClassifyResult = classifyFaceShape(
    getFaceMetrics(latestLandmarks, canvas.clientWidth, canvas.clientHeight)
  );

  if (currentHair) {
    drawHairOverlay(ctx, canvas.clientWidth, canvas.clientHeight, latestLandmarks, true);
  } else if (!isScanning) {
    statusText.textContent = "Face detected. Click Scan Face Shape to get recommendations.";
  }
});

function drawVideoAsDisplayed(drawCtx, sourceVideo, outputWidth, outputHeight) {
  const videoWidth = sourceVideo.videoWidth;
  const videoHeight = sourceVideo.videoHeight;

  if (!videoWidth || !videoHeight) return;

  const videoRatio = videoWidth / videoHeight;
  const outputRatio = outputWidth / outputHeight;

  let sx = 0;
  let sy = 0;
  let sWidth = videoWidth;
  let sHeight = videoHeight;

  if (videoRatio > outputRatio) {
    sWidth = videoHeight * outputRatio;
    sx = (videoWidth - sWidth) / 2;
  } else {
    sHeight = videoWidth / outputRatio;
    sy = (videoHeight - sHeight) / 2;
  }

  drawCtx.save();
  drawCtx.scale(-1, 1);
  drawCtx.drawImage(
    sourceVideo,
    sx,
    sy,
    sWidth,
    sHeight,
    -outputWidth,
    0,
    outputWidth,
    outputHeight
  );
  drawCtx.restore();
}

async function capturePhoto() {
  if (!video.videoWidth || !video.videoHeight) {
    statusText.textContent = "Camera is not ready yet.";
    return;
  }

  const rect = cameraWrap.getBoundingClientRect();
  const outputWidth = Math.round(rect.width);
  const outputHeight = Math.round(rect.height);

  const outputCanvas = document.createElement("canvas");
  const outputCtx = outputCanvas.getContext("2d");

  outputCanvas.width = outputWidth;
  outputCanvas.height = outputHeight;

  outputCtx.clearRect(0, 0, outputWidth, outputHeight);
  drawVideoAsDisplayed(outputCtx, video, outputWidth, outputHeight);

  if (latestLandmarks && currentHair && hairImg.complete) {
    drawHairOverlay(outputCtx, outputWidth, outputHeight, latestLandmarks, false);
  }

  const dataURL = outputCanvas.toDataURL("image/png");

  const link = document.createElement("a");
  link.href = dataURL;
  link.download = `ar-hairstyle-${Date.now()}.png`;
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);

  statusText.textContent = "Photo saved successfully.";
}

saveBtn.addEventListener("click", capturePhoto);

const camera = new Camera(video, {
  onFrame: async () => {
    resizeCanvasToDisplaySize();
    await faceMesh.send({ image: video });
  },
  width: 1280,
  height: 720
});

camera.start()
  .then(() => {
    statusText.textContent = "Camera started successfully.";
  })
  .catch((error) => {
    console.error(error);
    statusText.textContent = "Unable to access the camera. Please allow camera permission.";
  });