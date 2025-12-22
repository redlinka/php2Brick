<?php
session_start();
global $cnx;
include("./config/cnx.php");

// Redirect if prerequisite step is missing
if (!isset($_SESSION['step2_image_id'])) {
    header("Location: index.php");
    exit;
}

$parentId = $_SESSION['step2_image_id'];
$_SESSION['redirect_after_login'] = 'filter_selection.php';
$imgDir = 'users/imgs/';
$tilingDir = 'users/tilings/';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Validate session security
    if (!csrf_validate($_POST['csrf'] ?? null)) {
        $errors[] = 'Invalid session.';
    }

    if (empty($errors)) {
        if (empty($_POST['image'])) {
            $errors[] = 'No image data received. Please try again.';
        } else {
            // Decode received image data
            $imageParts = explode(";base64,", $_POST['image']);

            if (count($imageParts) < 2) {
                $errors[] = 'Invalid image format.';
            } else {
                $imageType = explode("image/", $imageParts[0])[1];
                $imageBase64 = base64_decode($imageParts[1]);
                $filterName = $_POST['filterName'] ?? 'Custom Filter';

                $existingId = $_SESSION['step3_image_id'] ?? null;
                $isUpdate = false;
                $targetFilename = null;

                // Check for existing step to update
                if ($existingId) {
                    $stmt = $cnx->prepare("SELECT filename FROM Images WHERE id_image = ? AND parent_id = ?");
                    $stmt->execute([$existingId, $parentId]);
                    $existingRow = $stmt->fetch();

                    if ($existingRow) {
                        $isUpdate = true;
                        $targetFilename = $existingRow['filename']; // Reuse existing filename
                    }
                }
                // Generate unique filename if new
                if (!$targetFilename) {
                    $targetFilename = bin2hex(random_bytes(16)) . '.' . $imageType;
                }
                $targetPath = $imgDir . $targetFilename;

                if (file_put_contents(__DIR__ . '/' . $targetPath, $imageBase64)) {
                    try {
                        if ($isUpdate) {
                            // Update existing image record
                            $stmt = $cnx->prepare("UPDATE Images SET status = 'CUSTOM', original_name = ? WHERE id_image = ?");
                            $stmt->execute(["Filtered ($filterName)", $existingId]);

                            // Delete downstream steps to maintain consistency
                            deleteDescendants($cnx, $existingId, $imgDir, $tilingDir, true);
                            unset($_SESSION['step4_image_id']);

                        } else {
                            // Insert new image record
                            $stmt = $cnx->prepare("INSERT INTO Images (user_id, filename, original_name, status, parent_id) VALUES (?, ?, ?, 'CUSTOM', ?)");
                            $userId = $_SESSION['userId'] ?? NULL;
                            $stmt->execute([$userId, $targetFilename, "Filtered ($filterName)", $parentId]);

                            $_SESSION['step3_image_id'] = $cnx->lastInsertId();
                        }
                        // Redirect to tiling selection step
                        header("Location: tiling_selection.php");
                        exit;

                    } catch (PDOException $e) {
                        if (!$isUpdate && file_exists(__DIR__ . '/' . $targetPath)) {
                            unlink(__DIR__ . '/' . $targetPath);
                        }
                        http_response_code(500);
                        $errors[] = 'Database Error. Try again later.';
                    }
                } else {
                    http_response_code(500);
                    $errors[] = 'Server Error: Could not save file.';
                }
            }
        }
    }
}
try {
    // Retrieve image for preview
    $stmt = $cnx->prepare("SELECT filename FROM Images WHERE id_image = ?");
    $stmt->execute([$parentId]);
    $image = $stmt->fetch();

    if (!$image) die("Image not found");

    // Append timestamp to prevent caching
    $displayPath = $imgDir . $image['filename'] . '?t=' . time();

} catch (PDOException $e) {

    http_response_code(500);
    die("Database Error");
}
// Define available filters
$filters = [
        ['name' => 'Normal', 'css' => 'none'],
        ['name' => 'Black & White', 'css' => 'grayscale(100%)'],
        ['name' => 'Sepia', 'css' => 'sepia(100%)'],
        ['name' => 'Warm Red', 'css' => 'contrast(1.5) sepia(100%) hue-rotate(-50deg) saturate(2)'],
        ['name' => 'Cool Blue', 'css' => 'contrast(1.2) hue-rotate(180deg)'],
        ['name' => 'High Contrast', 'css' => 'contrast(2)'],
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Step 3: Add Filters</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>

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

        .pixelated {
            width: 100%;
            height: 100%;
            object-fit: cover;    /* FILL ALL SPACE */
            image-rendering: pixelated;
            display: block;
        }

        #imgModal { display: none; position: fixed; z-index: 1050; padding-top: 50px; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.9); }
        .modal-content { margin: auto; display: block; width: 80%; max-width: 800px; image-rendering: pixelated; object-fit: contain; }
        .close { position: absolute; top: 15px; right: 35px; color: #f1f1f1; font-size: 40px; font-weight: bold; cursor: pointer; }
    </style>
</head>
<body >

<?php include("./includes/navbar.php"); ?>

<div class="container bg-light py-5">
    <h2 class="text-center mb-4">Step 3: Choose a Tint</h2>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger">
            <ul><?php foreach ($errors as $err): ?>
                    <li><?= htmlspecialchars($err) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="POST" id="filterForm">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_get()) ?>">
        <input type="hidden" name="image" id="hiddenImage">
        <input type="hidden" name="filterName" id="hiddenFilterName">
    </form>

    <img id="sourceImage" src="<?= htmlspecialchars($displayPath) ?>" style="display:none;" crossorigin="anonymous">

    <div class="row g-4">
        <?php foreach ($filters as $f): ?>
            <div class="col-md-4">
                <div class="card h-100 shadow-sm algo-card">
                    <div class="card-header text-center fw-bold text-uppercase">
                        <?= htmlspecialchars($f['name']) ?>
                    </div>
                    <div class="card-body preview-box">
                        <img src="<?= htmlspecialchars($displayPath) ?>"
                             class="pixelated"
                             style="filter: <?= htmlspecialchars($f['css']) ?>;"
                             onclick="event.stopPropagation(); openModal(this.src, '<?= htmlspecialchars($f['css']) ?>')" alt="">
                    </div>
                    <div class="card-footer text-center">
                        <button type="button" class="btn btn-outline-primary w-100"
                                onclick="selectFilter('<?= htmlspecialchars($f['css']) ?>', '<?= htmlspecialchars($f['name']) ?>')">
                            Select This
                        </button>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="text-center mt-5">
        <a href="downscale_selection.php" class="btn btn-outline-secondary">← Back</a>
    </div>
</div>

<div id="imgModal">
    <span class="close" onclick="closeModal()">&times;</span>
    <img class="modal-content" id="modalImg" alt="">
</div>

<canvas id="hiddenCanvas" style="display:none;"></canvas>

<script>
    const sourceImg = document.getElementById('sourceImage');
    const canvas = document.getElementById('hiddenCanvas');
    const form = document.getElementById('filterForm');

    function selectFilter(filterCss, filterName) {
        const ctx = canvas.getContext('2d');
        canvas.width = sourceImg.naturalWidth;
        canvas.height = sourceImg.naturalHeight;
        ctx.filter = filterCss;
        ctx.drawImage(sourceImg, 0, 0, canvas.width, canvas.height);
        document.getElementById('hiddenImage').value = canvas.toDataURL('image/png');
        document.getElementById('hiddenFilterName').value = filterName;
        form.submit();
    }

    function openModal(src, filterCss) {
        document.getElementById("imgModal").style.display = "block";
        const modalImg = document.getElementById("modalImg");
        modalImg.src = src;
        modalImg.style.filter = filterCss;
    }

    function closeModal() {
        document.getElementById("imgModal").style.display = "none";
    }

    window.onclick = function(event) {
        if (event.target === document.getElementById("imgModal")) closeModal();
    }
</script>

<?php include("./includes/footer.php"); ?>
</body>
</html>