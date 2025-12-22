<?php
session_start();
global $cnx;
include("./config/cnx.php");

// Redirect to home if previous step missing
if (!isset($_SESSION['step1_image_id'])) {
    header("Location: index.php");
    exit;
}

$parentId = $_SESSION['step1_image_id'];
$_SESSION['redirect_after_login'] = 'dimensions_selection.php';
$imgDir = 'users/imgs/';
$errors = [];

// Fetch parent image for preview and dimensions
try {
    $stmt = $cnx->prepare("SELECT filename FROM Images WHERE id_image = ?");
    $stmt->execute([$parentId]);
    $image = $stmt->fetch();
    if (!$image) die("Image not found");

    $sourceImg = $imgDir . $image['filename'];
    $fullSourcePath = __DIR__ . '/' . $sourceImg;

    // Get original dimensions for the "Keep Ratio" logic
    list($origW, $origH) = getimagesize($fullSourcePath);

} catch (PDOException $e) {
    die("Database Error");
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Validate CSRF token
    if (!csrf_validate($_POST['csrf'] ?? null)) {
        $errors[] = 'Invalid session (CSRF). Please try again.';
    } else {
        $width = (int)$_POST['width'];
        $height = (int)$_POST['height'];

        // Enforce constraints
        if ($width < 16 || $height < 16) {
            $errors[] = "Dimensions must be at least 16x16.";
        }
        if ($width % 16 !== 0 || $height % 16 !== 0) {
            $errors[] = "Dimensions must be multiples of 16.";
        }
        if ($width > 1024 || $height > 1024) {
            $errors[] = "Maximum dimension is 1024 studs.";
        }

        if (empty($errors)) {
            // Save dimensions and proceed
            $_SESSION['target_width'] = $width;
            $_SESSION['target_height'] = $height;
            csrf_rotate();
            header("Location: downscale_selection.php");
            exit;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Step 2: Dimensions</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .error-list { background: #fee; border: 1px solid #fcc; padding: 10px; margin-bottom: 15px; border-radius: 4px; }
        .error-list li { color: #c00; }

        /* Preview Image */
        .preview-img {
            width: 100%;
            height: 300px;
            object-fit: contain;
            background: #212529;
            border-radius: 8px;
            cursor: zoom-in;
        }

        /* Modal Styles */
        #imgModal { display: none; position: fixed; z-index: 1050; padding-top: 50px; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.9); }
        .modal-content { margin: auto; display: block; width: 80%; max-width: 900px; image-rendering: pixelated; }
        .close { position: absolute; top: 15px; right: 35px; color: #f1f1f1; font-size: 40px; font-weight: bold; cursor: pointer; }

        /* Custom Button Styling */
        .preset-btn { text-align: left; position: relative; margin-bottom: 10px; }
        .preset-btn small { display: block; font-size: 0.75rem; opacity: 0.8; }
        .preset-btn.active { background-color: #6c757d; color: white; border-color: #6c757d; }
    </style>
</head>
<body>

<?php include("./includes/navbar.php"); ?>

<div class="container bg-light py-5">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card shadow border-0">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0">Step 2: Choose Size</h5>
                </div>
                <div class="card-body p-4">

                    <?php if (!empty($errors)): ?>
                        <div class="error-list">
                            <ul>
                                <?php foreach ($errors as $err): ?>
                                    <li><?= htmlspecialchars($err) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <div class="row g-4">
                        <div class="col-md-5 text-center">
                            <img src="<?= htmlspecialchars($sourceImg) ?>"
                                 class="preview-img shadow-sm mb-2"
                                 onclick="openModal(this.src)"
                                 alt="Source">
                            <p class="text-muted small">Original: <?= $origW ?>px x <?= $origH ?>px</p>

                            <div class="alert alert-info py-2">
                                Output: <strong id="displayDims">Calculating...</strong> studs
                            </div>
                        </div>

                        <div class="col-md-7">
                            <form method="POST" id="dimForm">
                                <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_get()) ?>">

                                <input type="hidden" id="wInput" name="width" value="">
                                <input type="hidden" id="hInput" name="height" value="">

                                <h6 class="fw-bold mb-3">Select Mode</h6>

                                <div class="d-grid gap-2">
                                    <button type="button" class="btn btn-outline-secondary preset-btn active" id="btnRatio" onclick="setMode('ratio')">
                                        <strong>Keep Ratio</strong>
                                        <small>Adjust scale, preserves shape (Recommended)</small>
                                    </button>

                                    <div class="btn-group w-100">
                                        <button type="button" class="btn btn-outline-secondary preset-btn" id="btnSmall" onclick="setMode('small')">
                                            <strong>Small</strong>
                                            <small>32 x 32</small>
                                        </button>
                                        <button type="button" class="btn btn-outline-secondary preset-btn" id="btnMedium" onclick="setMode('medium')">
                                            <strong>Medium</strong>
                                            <small>64 x 64</small>
                                        </button>
                                        <button type="button" class="btn btn-outline-secondary preset-btn" id="btnLarge" onclick="setMode('large')">
                                            <strong>Large</strong>
                                            <small>128 x 128</small>
                                        </button>
                                    </div>

                                    <button type="button" class="btn btn-outline-secondary preset-btn" id="btnCustom" onclick="setMode('custom')">
                                        <strong>Custom</strong>
                                        <small>Manual entry (Max 1024)</small>
                                    </button>
                                </div>

                                <div id="ratioControls" class="mt-3 p-3 bg-light rounded border">
                                    <label class="form-label fw-bold d-flex justify-content-between">
                                        <span>Scale Factor</span>
                                        <span id="sliderValDisplay" class="text-primary">Medium</span>
                                    </label>
                                    <input type="range" class="form-range" id="ratioSlider" min="16" max="256" step="16" value="64">
                                    <div class="form-text small">Slide to change size. Dimensions snap to 16.</div>
                                </div>

                                <div id="customControls" class="mt-3 p-3 bg-light rounded border" style="display:none;">
                                    <div class="row g-2">
                                        <div class="col">
                                            <label class="small fw-bold">Width</label>
                                            <input type="number" id="customW" class="form-control" placeholder="64" min="16" max="1024" step="16">
                                        </div>
                                        <div class="col">
                                            <label class="small fw-bold">Height</label>
                                            <input type="number" id="customH" class="form-control" placeholder="64" min="16" max="1024" step="16">
                                        </div>
                                    </div>
                                    <div class="form-text small mt-1">Must be multiples of 16. Max 1024.</div>
                                </div>

                                <div class="d-flex justify-content-between align-items-center mt-4 pt-3 border-top">
                                    <a href="crop_selection.php" class="btn btn-outline-secondary">← Back</a>
                                    <button type="submit" class="btn btn-primary btn-lg">Next Step ➔</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div id="imgModal" onclick="closeModal()">
    <span class="close">&times;</span>
    <img class="modal-content" id="modalImg">
</div>

<script>
    // Original image dimensions from PHP
    const origW = <?= $origW ?>;
    const origH = <?= $origH ?>;
    const aspectRatio = origW / origH;

    // Elements
    const buttons = {
        ratio: document.getElementById('btnRatio'),
        small: document.getElementById('btnSmall'),
        medium: document.getElementById('btnMedium'),
        large: document.getElementById('btnLarge'),
        custom: document.getElementById('btnCustom')
    };

    const ratioControls = document.getElementById('ratioControls');
    const customControls = document.getElementById('customControls');
    const ratioSlider = document.getElementById('ratioSlider');
    const displayDims = document.getElementById('displayDims');

    // Hidden Submission Inputs
    const wInput = document.getElementById('wInput');
    const hInput = document.getElementById('hInput');

    // Custom Inputs
    const customW = document.getElementById('customW');
    const customH = document.getElementById('customH');

    let currentMode = 'ratio';

    function setMode(mode) {
        currentMode = mode;

        // 1. Update Buttons Visuals
        Object.values(buttons).forEach(btn => {
            btn.classList.remove('active', 'btn-secondary', 'text-white');
            btn.classList.add('btn-outline-secondary');
        });
        buttons[mode].classList.remove('btn-outline-secondary');
        buttons[mode].classList.add('active', 'btn-secondary', 'text-white');

        // 2. Toggle Control Panels
        ratioControls.style.display = (mode === 'ratio') ? 'block' : 'none';
        customControls.style.display = (mode === 'custom') ? 'block' : 'none';

        // 3. Calculate Dimensions based on mode
        calculateDimensions();
    }

    function calculateDimensions() {
        let w = 0, h = 0;

        if (currentMode === 'small') { w = 32; h = 32; }
        else if (currentMode === 'medium') { w = 64; h = 64; }
        else if (currentMode === 'large') { w = 128; h = 128; }
        else if (currentMode === 'custom') {
            w = parseInt(customW.value) || 0;
            h = parseInt(customH.value) || 0;
        }
        else if (currentMode === 'ratio') {
            // Slider determines the LONGEST side
            const longSide = parseInt(ratioSlider.value);

            if (origW >= origH) {
                // Landscape or Square
                w = longSide;
                h = Math.round((longSide / aspectRatio) / 16) * 16;
            } else {
                // Portrait
                h = longSide;
                w = Math.round((longSide * aspectRatio) / 16) * 16;
            }

            // Safety clamp to min 16
            if (w < 16) w = 16;
            if (h < 16) h = 16;

            document.getElementById('sliderValDisplay').innerText = longSide + ' studs (Longest Side)';
        }

        // Update UI Text
        displayDims.innerText = `${w} x ${h}`;

        // Update Hidden Inputs
        wInput.value = w;
        hInput.value = h;
    }

    // 1. Ratio Slider
    ratioSlider.addEventListener('input', calculateDimensions);

    // 2. Custom Inputs (Enforce multiple of 16 logic on blur)
    function enforce16(e) {
        let val = parseInt(e.target.value) || 16;
        val = Math.round(val / 16) * 16;
        if (val < 16) val = 16;
        if (val > 1024) val = 1024;
        e.target.value = val;
        calculateDimensions();
    }
    customW.addEventListener('change', enforce16);
    customH.addEventListener('change', enforce16);
    customW.addEventListener('keyup', calculateDimensions);
    customH.addEventListener('keyup', calculateDimensions);

    // Modal Logic
    function openModal(src) {
        document.getElementById("imgModal").style.display = "block";
        document.getElementById("modalImg").src = src;
    }
    function closeModal() {
        document.getElementById("imgModal").style.display = "none";
    }

    // Initialize
    setMode('ratio'); // Default Recommended
</script>

<?php include("./includes/footer.php"); ?>
</body>
</html>