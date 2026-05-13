const video = document.getElementById("video");
const canvas = document.getElementById("canvas");
const ctx = canvas.getContext("2d");
const hairSelect = document.getElementById("hairSelect");

canvas.width = 640;
canvas.height = 480;

// Hardcoded single hair first
const HAIR_SETTINGS = {
  "hair/fade.png": {
  src: "hair/fade.png",
  widthScale: 2.50,
  heightScale: 1.00,
  offsetX: 0,
  offsetY: -60,
  anchorY: 0.24,
  rotationOffset: 0,
  flipX: false
}

};

let currentHair = HAIR_SETTINGS[hairSelect.value];

const hairImg = new Image();
hairImg.src = currentHair.src;

hairSelect.addEventListener("change", () => {
  currentHair = HAIR_SETTINGS[hairSelect.value];
  hairImg.src = currentHair.src;

  smooth.x = null;
  smooth.y = null;
  smooth.width = null;
  smooth.height = null;
  smooth.angle = null;
});

function toPixel(point) {
  return {
    x: point.x * canvas.width,
    y: point.y * canvas.height
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
  minDetectionConfidence: 0.6,
  minTrackingConfidence: 0.6
});

faceMesh.onResults((results) => {
  ctx.clearRect(0, 0, canvas.width, canvas.height);

  if (!results.multiFaceLandmarks || results.multiFaceLandmarks.length === 0) {
    return;
  }

  const landmarks = results.multiFaceLandmarks[0];

  // Core landmarks
  const topHead = toPixel(landmarks[10]);
  const chin = toPixel(landmarks[152]);

  const leftForehead = toPixel(landmarks[109]);
  const rightForehead = toPixel(landmarks[338]);

  const leftSide = toPixel(landmarks[234]);
  const rightSide = toPixel(landmarks[454]);

  const foreheadCenterX = (leftForehead.x + rightForehead.x) / 2;

  const foreheadWidth = distance(leftForehead, rightForehead);
  const sideWidth = distance(leftSide, rightSide);
  const headHeight = distance(topHead, chin);

  const baseWidth = (foreheadWidth * 0.68) + (sideWidth * 0.32);

  const targetWidth = baseWidth * currentHair.widthScale;
  const targetHeight = headHeight * currentHair.heightScale;

  // Use top head as anchor, not forehead center, for this kind of image
  const targetX = foreheadCenterX + currentHair.offsetX;
  const targetY = topHead.y + currentHair.offsetY;

  const targetAngle =
    Math.atan2(
      rightForehead.y - leftForehead.y,
      rightForehead.x - leftForehead.x
    ) + currentHair.rotationOffset;

  smooth.x = smoothValue(smooth.x, targetX, 0.22);
  smooth.y = smoothValue(smooth.y, targetY, 0.22);
  smooth.width = smoothValue(smooth.width, targetWidth, 0.22);
  smooth.height = smoothValue(smooth.height, targetHeight, 0.22);
  smooth.angle = smoothValue(smooth.angle, targetAngle, 0.16);

  if (!hairImg.complete) return;

  ctx.save();
  ctx.translate(smooth.x, smooth.y);
  ctx.rotate(smooth.angle);

  if (currentHair.flipX) {
    ctx.scale(-1, 1);
  }

  ctx.drawImage(
    hairImg,
    -smooth.width / 2,
    -smooth.height * currentHair.anchorY,
    smooth.width,
    smooth.height
  );

  ctx.restore();
});

const camera = new Camera(video, {
  onFrame: async () => {
    await faceMesh.send({ image: video });
  },
  width: 640,
  height: 480
});

camera.start();
