<?php
    session_start();
    global $cnx;
    include("./config/cnx.php");

    // Verify session prerequisites
    if (!isset($_SESSION['step3_image_id'])) {
        header("Location: index.php");
        exit;
    }

    $parentId = $_SESSION['step3_image_id'];
    $_SESSION['redirect_after_login'] = 'tiling_selection.php';
    $imgFolder = 'users/imgs/';
    $tilingFolder = 'users/tilings/';
    $errors = [];
    $previewImage = null;

    // Retrieve source image filename
    $stmt = $cnx->prepare("SELECT filename FROM Images WHERE id_image = ?");
    $stmt->execute([$parentId]);
    $sourceFile = $stmt->fetchColumn();

    // Process generation request
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        if (!csrf_validate($_POST['csrf'] ?? null)) {
            $errors[] = 'Invalid session.';
        } else {
            // Retrieve form inputs
            $method = $_POST['method'] ?? 'quadtree';
            $threshold = (int)($_POST['threshold'] ?? 2000);

            // Handle atomic update logic
            $existingId = $_SESSION['step4_image_id'] ?? null;
            $isUpdate = false;
            $baseName = null;

            // Check for existing step to update
            if ($existingId) {
                $stmt = $cnx->prepare("SELECT filename FROM Images WHERE id_image = ? AND parent_id = ?");
                $stmt->execute([$existingId, $parentId]);
                $existingRow = $stmt->fetch();
                if ($existingRow) {
                    $isUpdate = true;
                    // Extract base filename
                    $baseName = pathinfo($existingRow['filename'], PATHINFO_FILENAME);
                }
            }

            // Generate unique filename if new
            if (!$baseName) {
                $baseName = bin2hex(random_bytes(16));
            }

            // Define file paths
            $finalPngName = $baseName . '.png';
            $finalTxtName = $baseName . '.txt';

            $inputPath    = __DIR__ . '/' . $imgFolder . $sourceFile;
            $outputPngPath = __DIR__ . '/' . $imgFolder . $finalPngName;
            $outputTxtPath = __DIR__ . '/' . $tilingFolder . $finalTxtName;

            $jarPath      = __DIR__ . '/brain.jar';
            $exePath      = __DIR__ . '/C_tiler.exe';
            $catalogPath  = __DIR__ . '/catalog.txt';

            // Detect Java executable
            $javaCmd = 'java';
            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                $javaCmd = '"C:\\Program Files\\Eclipse Adoptium\\jdk-25.0.1.8-hotspot\\bin\\java.exe"';
                $exePath      = __DIR__ . '/C_tiler_win.exe';
            }
            // Execute Java tiling application
            $cmd = sprintf(
                    '%s -cp %s fr.uge.univ_eiffel.image_processing.TileAndDraw %s %s %s %s %s %s %s 2>&1',
                    $javaCmd,
                    escapeshellarg($jarPath),
                    escapeshellarg($inputPath),     // 0
                    escapeshellarg($outputPngPath), // 1
                    escapeshellarg($outputTxtPath), // 2 (Brick List)
                    escapeshellarg($catalogPath),   // 3
                    escapeshellarg($exePath),       // 4
                    escapeshellarg($method),        // 5 (New!)
                    escapeshellarg($threshold)      // 6
            );

            $output = [];
            $returnCode = 0;
            exec($cmd, $output, $returnCode);

            if ($returnCode === 0) {
                // Persist results to database
                try {
                    if ($isUpdate) {
                        // Update existing image record
                        $stmt = $cnx->prepare("UPDATE Images SET status = 'LEGO', original_name = ? WHERE id_image = ?");
                        $stmt->execute(["Lego (T=$threshold)", $existingId]);
                    } else {
                        // Insert new image record
                        $stmt = $cnx->prepare("INSERT INTO Images (user_id, filename, original_name, status, parent_id) VALUES (?, ?, ?, 'LEGO', ?)");
                        $userId = $_SESSION['userId'] ?? NULL;
                        $stmt->execute([$userId, $finalPngName, "Lego (T=$threshold)", $parentId]);
                        $_SESSION['step4_image_id'] = $cnx->lastInsertId();
                    }

                    // Set preview image path
                    $previewImage = $imgFolder . $finalPngName . '?t=' . time();

                } catch (PDOException $e) {
                    $errors[] = "Database Error";
                }
            } else {
                $errors[] = "Java/C Error :" . $javaCmd;
            }
        }
    } else {
        // Check for existing result on page load
        if (isset($_SESSION['step4_image_id'])) {
            $stmt = $cnx->prepare("SELECT filename FROM Images WHERE id_image = ?");
            $stmt->execute([$_SESSION['step4_image_id']]);
            $existingFile = $stmt->fetchColumn();
            if ($existingFile) {
                $previewImage = $imgFolder . $existingFile . '?t=' . time();
            }
        }
    }
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Step 4: Generate LEGO</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>

        .img-area {
            height: 70vh;
            background: #212529;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            border-radius: 8px;
        }

        .preview-img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            image-rendering: pixelated;
            cursor: zoom-in;
        }

        /* Modal Styles */
        #imgModal { display: none; position: fixed; z-index: 1050; padding-top: 50px; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.9); }
        .modal-content { margin: auto; display: block; width: 80%; max-width: 1000px; image-rendering: pixelated; }
        .close { position: absolute; top: 15px; right: 35px; color: #f1f1f1; font-size: 40px; font-weight: bold; cursor: pointer; }

        /* Custom Button Styling */
        .btn-check:checked + .btn-outline-primary {
            background-color: #0d6efd;
            color: white;
            border-color: #0d6efd;
        }
        .preset-btn { text-align: left; position: relative; margin-bottom: 10px; }
        .preset-btn small { display: block; font-size: 0.75rem; opacity: 0.8; }
        .preset-btn.active { background-color: #6c757d; color: white; border-color: #6c757d; }

        /* Error List Styling */
        .error-list {background-color: #fee;border: 1px solid #fcc;border-radius: 4px;padding: 10px;margin-bottom: 15px;text-align: left;}
        .error-list ul {margin: 5px 0;padding-left: 20px;}
        .error-list li {color: #c00;}
    </style>
</head>
<body>

    <?php include("./includes/navbar.php"); ?>

    <div class="container bg-light py-5">

        <?php if (!empty($errors)): ?>
            <div class="error-list">
                <ul>
                    <?php foreach ($errors as $e) echo "<li>" . htmlspecialchars($e) . "</li>"; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div class="row g-4">
            <div class="col-lg-8">
                <div class="card shadow-sm border-0">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">Step 4: Tiling Optimization</h5>
                    </div>
                    <div class="card-body p-2">
                        <div class="img-area shadow-sm">
                            <?php if ($previewImage): ?>
                                <img src="<?= $previewImage ?>" class="preview-img" onclick="openModal(this.src)" alt="Preview">
                            <?php else: ?>
                                <div class="text-white text-center opacity-75">
                                    <p class="mb-0">Preview will appear here</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card shadow-sm border-0 h-100">
                    <div class="card-body d-flex flex-column">
                        <h3 class="mb-3">Generate Mosaic</h3>

                        <form method="POST" id="tilingForm" class="flex-grow-1 d-flex flex-column">
                            <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_get()) ?>">

                            <h6 class="fw-bold mb-3">1. Select Method</h6>
                            <div class="btn-group w-100 mb-4" role="group">
                                <input type="radio" class="btn-check" name="method" id="methodQuad" value="quadtree" checked onchange="toggleThresholds()">
                                <label class="btn btn-outline-primary py-2" for="methodQuad">
                                    <strong>Quadtree</strong><br>
                                    <small>Smart sizing</small>
                                </label>

                                <input type="radio" class="btn-check" name="method" id="method1x1" value="1x1" onchange="toggleThresholds()">
                                <label class="btn btn-outline-primary py-2" for="method1x1">
                                    <strong>1x1</strong><br>
                                    <small>Pixel Perfect</small>
                                </label>
                            </div>

                            <div id="thresholdSection">
                                <h6 class="fw-bold mb-3">2. Precision / Cost</h6>
                                <input type="hidden" name="threshold" id="thresholdInput" value="2000">

                                <div class="d-grid gap-2 mb-3">
                                    <button type="button" class="btn btn-outline-secondary preset-btn" onclick="setThreshold(1000, this)">
                                        <strong>High Detail</strong>
                                        <small>Threshold: 1,000</small>
                                    </button>

                                    <button type="button" class="btn btn-outline-secondary preset-btn active" onclick="setThreshold(2000, this)">
                                        <strong>Balanced</strong>
                                        <small>Threshold: 2,000 (Recommended)</small>
                                    </button>

                                    <button type="button" class="btn btn-outline-secondary preset-btn" onclick="setThreshold(100000, this)">
                                        <strong>Abstract</strong>
                                        <small>Threshold: 100,000 (Lowest Cost)</small>
                                    </button>

                                    <button type="button" class="btn btn-outline-secondary preset-btn" id="customBtn" onclick="enableCustom()">
                                        <strong>Custom Value</strong>
                                        <small>Enter manually...</small>
                                    </button>
                                </div>

                                <div class="collapse mb-3" id="customInputDiv">
                                    <div class="input-group">
                                        <span class="input-group-text">Value</span>
                                        <input type="number" class="form-control" id="customNumber" placeholder="e.g. 5000">
                                        <button class="btn btn-primary" type="button" onclick="applyCustom()">Set</button>
                                    </div>
                                </div>
                            </div>

                            <button type="submit" class="btn btn-primary w-100 btn-lg mb-3">
                                <?= $previewImage ? 'Regenerate Preview ↻' : 'Generate Preview' ?>
                            </button>

                            <div class="mt-auto pt-3 border-top d-flex justify-content-between align-items-center">
                                <a href="filter_selection.php" class="btn btn-outline-secondary">← Back</a>

                                <?php if ($previewImage): ?>
                                    <a href="order.php" class="btn btn-success fw-bold">Finalize Order ➔</a>
                                <?php else: ?>
                                    <button type="button" class="btn btn-secondary" disabled>Finalize Order ➔</button>
                                <?php endif; ?>
                            </div>
                        </form>
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
        const thresholdSection = document.getElementById('thresholdSection');
        const thresholdInput = document.getElementById('thresholdInput');
        const customDiv = document.getElementById('customInputDiv');
        const customNum = document.getElementById('customNumber');
        const presetBtns = document.querySelectorAll('.preset-btn');

        // Toggle threshold visibility
        function toggleThresholds() {
            const isQuad = document.getElementById('methodQuad').checked;
            if (isQuad) {
                thresholdSection.style.display = 'block';
            } else {
                thresholdSection.style.display = 'none';
            }
        }

        // Handle threshold preset selection
        function setThreshold(val, btn) {
            thresholdInput.value = val;
            customDiv.classList.remove('show');

            // Update button states
            presetBtns.forEach(b => b.classList.remove('active', 'btn-secondary', 'text-white'));
            presetBtns.forEach(b => b.classList.add('btn-outline-secondary'));

            btn.classList.remove('btn-outline-secondary');
            btn.classList.add('active', 'btn-secondary', 'text-white');
        }

        // Enable custom threshold mode
        function enableCustom() {
            presetBtns.forEach(b => b.classList.remove('active', 'btn-secondary', 'text-white'));
            presetBtns.forEach(b => b.classList.add('btn-outline-secondary'));

            document.getElementById('customBtn').classList.remove('btn-outline-secondary');
            document.getElementById('customBtn').classList.add('active', 'btn-secondary', 'text-white');

            customDiv.classList.add('show');
            customNum.focus();
        }

        // Apply custom threshold
        function applyCustom() {
            const val = customNum.value;
            if (val && val > 0) {
                thresholdInput.value = val;
            } else {
                alert("Please enter a valid number");
            }
        }

        function openModal(src) {
            document.getElementById("imgModal").style.display = "block";
            document.getElementById("modalImg").src = src;
        }
        function closeModal() {
            document.getElementById("imgModal").style.display = "none";
        }

        // Synchronize custom input with hidden field
        customNum.addEventListener('input', (e) => {
            thresholdInput.value = e.target.value;
        });

        // Initialize UI state
        toggleThresholds();
    </script>

    <?php include("./includes/footer.php"); ?>
</body>
</html>