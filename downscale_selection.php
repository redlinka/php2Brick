<?php
session_start();
global $cnx;
include("./config/cnx.php");

// Check session prerequisites
if (!isset($_SESSION['step1_image_id']) || !isset($_SESSION['target_width'])) {
    header("Location: index.php");
    exit;
}

$parentId = $_SESSION['step1_image_id'];
$_SESSION['redirect_after_login'] = 'downscale_selection.php';
$width = $_SESSION['target_width'];
$height = $_SESSION['target_height'];
$imgDir = 'users/imgs/';
$tilingDir = 'users/tilings/';
$errors = [];
$algos = ['nearest', 'bilinear', 'bicubic'];
$generatedImages = [];

// Retrieve parent image filename
$stmt = $cnx->prepare("SELECT filename FROM Images WHERE id_image = ?");
$stmt->execute([$parentId]);
$sourceFile = $stmt->fetchColumn();

// Generate downscaled variations
$jarPath = __DIR__ . '/brain.jar';
$sourcePath = __DIR__ . '/' . $imgDir . $sourceFile;

// Detect Java executable
$javaCmd = 'java';
if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
    $javaCmd = '"C:\\Program Files\\Eclipse Adoptium\\jdk-25.0.1.8-hotspot\\bin\\java.exe"';
}

foreach ($algos as $algo) {
    // Define temporary output path
    $tempName = 'temp_' . $algo . '_' . $parentId . '.png';
    $destPath = __DIR__ . '/' . $imgDir . $tempName;

    $cmd = sprintf(
            '%s -cp %s fr.uge.univ_eiffel.image_processing.ImageScaler %s %s %d %d %s 2>&1',
            $javaCmd,
            escapeshellarg($jarPath),
            escapeshellarg($sourcePath),
            escapeshellarg($destPath),
            $width,
            $height,
            escapeshellarg($algo)
    );

    $output = [];
    $returnCode = 0;
    exec($cmd, $output, $returnCode);

    if ($returnCode === 0) {
        $generatedImages[$algo] = $tempName . '?t=' . time();
    } else {
        $errors[] = "Failed to generate $algo: " . implode(" ", $output);
    }
}

// Process algorithm selection
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate($_POST['csrf'] ?? null)) {
        $errors[] = 'Invalid session.';
    } elseif (!isset($_POST['selected_algo'])) {
        $errors[] = 'Please select an image.';
    } else {
        $selectedAlgo = $_POST['selected_algo'];
        $selectedFilenameRaw = explode('?', $generatedImages[$selectedAlgo] ?? '')[0];

        if ($selectedFilenameRaw && file_exists(__DIR__ . '/' . $imgDir . $selectedFilenameRaw)) {

            $existingId = $_SESSION['step2_image_id'] ?? null;
            $isUpdate = false;
            $finalName = null;

            if ($existingId) {
                $stmt = $cnx->prepare("SELECT filename FROM Images WHERE id_image = ? AND parent_id = ?");
                $stmt->execute([$existingId, $parentId]);
                $existingRow = $stmt->fetch();
                if ($existingRow) {
                    $isUpdate = true;
                    $finalName = $existingRow['filename'];
                }
            }
            if (!$finalName) {
                $finalName = bin2hex(random_bytes(16)) . '.png';
            }
            $finalPath = __DIR__ . '/' . $imgDir . $finalName;
            $tempPath = __DIR__ . '/' . $imgDir . $selectedFilenameRaw;
            // Persist selection to storage
            if (rename($tempPath, $finalPath)) {
                try {
                    if ($isUpdate) {
                        // Update existing database record
                        $stmt = $cnx->prepare("UPDATE Images SET status='CUSTOM', original_name=? WHERE id_image=?");
                        $stmt->execute(["$selectedAlgo", $existingId]);

                        // Invalidate downstream steps
                        deleteDescendants($cnx, $existingId, $imgDir, $tilingDir, true);
                        unset($_SESSION['step3_image_id']);

                    } else {
                        // Insert new database record
                        $stmt = $cnx->prepare("INSERT INTO Images (user_id, filename, original_name, status, parent_id) VALUES (?, ?, ?, 'CUSTOM', ?)");
                        $userId = $_SESSION['userId'] ?? NULL;
                        $stmt->execute([$userId, $finalName, "$selectedAlgo", $parentId]);
                        $_SESSION['step2_image_id'] = $cnx->lastInsertId();
                    }

                    // Remove unused temporary files
                    foreach ($algos as $algo) {
                        $otherFile = 'temp_' . $algo . '_' . $parentId . '.png';
                        if (file_exists(__DIR__ . '/' . $imgDir . $otherFile)) {
                            unlink(__DIR__ . '/' . $imgDir . $otherFile);
                        }
                    }

                    csrf_rotate();
                    header("Location: filter_selection.php");
                    exit;

                } catch (PDOException $e) {
                    // Rollback file on database error
                    if (!$isUpdate) unlink($finalPath);
                    $errors[] = "Database Error. Operation rolled back.";
                }
            } else {
                $errors[] = "File Error. Could not save selection.";
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Step 2b: Compare</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .pixelated {
            image-rendering: pixelated;
            width: 100%;
            height: 100%;
            object-fit: cover;
            cursor: zoom-in;
        }
        .algo-card {
            transition: transform 0.2s;
            border: 2px solid transparent;
            cursor: pointer;
        }
        .algo-card:hover {
            transform: translateY(-5px);
            border-color: #0d6efd;
        }

        .preview-box {
            background-color: #212529;
            aspect-ratio: 1 / 1;
            width: 100%;
            height: auto;
            padding: 0;           /* NO PADDING */
            overflow: hidden;
            position: relative;
        }

        #imgModal { display: none;
            position: fixed;
            z-index: 1050;
            padding-top: 50px;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0,0,0,0.9);
        }
        .modal-content {
            margin: auto;
            display: block;
            width: 80%;
            max-width: 800px;
            image-rendering: pixelated;
        }
        .close {
            position: absolute;
            top: 15px;
            right: 35px;
            color: #f1f1f1;
            font-size: 40px;
            font-weight: bold;
            cursor: pointer;
        }
    </style>
</head>
<body>

<?php include("./includes/navbar.php"); ?>

<div class="container bg-light py-5">
    <h2 class="text-center mb-4">Choose your favorite Result</h2>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger">
            <ul><?php foreach ($errors as $e) echo "<li>$e</li>"; ?></ul>
        </div>
    <?php endif; ?>

    <form method="POST" id="selectionForm">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_get()) ?>">
        <input type="hidden" name="selected_algo" id="selectedAlgoInput">

        <div class="row g-4">
            <?php foreach ($algos as $algo): ?>
                <?php if (isset($generatedImages[$algo])): ?>
                    <div class="col-md-4">
                        <div class="card h-100 shadow-sm algo-card">
                            <div class="card-header text-center fw-bold text-uppercase">
                                <?= $algo ?>
                            </div>
                            <div class="card-body preview-box">
                                <img src="<?= $imgDir . $generatedImages[$algo] ?>"
                                     class="pixelated"
                                     onclick="event.stopPropagation(); openModal(this.src)" alt="">
                            </div>
                            <div class="card-footer text-center">
                                <button type="button" class="btn btn-outline-primary w-100" onclick="selectAlgo('<?= $algo ?>')">Select This</button>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </form>

    <div class="text-center mt-5">
        <a href="dimensions_selection.php" class="btn btn-outline-secondary">← Back</a>
    </div>
</div>

<div id="imgModal">
    <span class="close" onclick="closeModal()">&times;</span>
    <img class="modal-content" id="modalImg" alt="" src="">
</div>

<script>
    // Submit selected algorithm
    function selectAlgo(algo) {
        document.getElementById('selectedAlgoInput').value = algo;
        document.getElementById('selectionForm').submit();
    }

    // Manage image preview modal
    function openModal(src) {
        const modal = document.getElementById("imgModal");
        const modalImg = document.getElementById("modalImg");
        modal.style.display = "block";
        modalImg.src = src;
    }

    function closeModal() {
        document.getElementById("imgModal").style.display = "none";
    }

    // Close modal on background click
    window.onclick = function(event) {
        if (event.target === document.getElementById("imgModal")) {
            closeModal();
        }
    }
</script>

<?php include("./includes/footer.php"); ?>
</body>
</html>