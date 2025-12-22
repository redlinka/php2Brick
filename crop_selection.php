<?php
session_start();
global $cnx;
include("./config/cnx.php");

// Redirect to home if no previous image step exists
if (!isset($_SESSION['step0_image_id'])) {
    header("Location: index.php");
    exit;
}

$parentId = $_SESSION['step0_image_id'];
$_SESSION['redirect_after_login'] = 'crop_selection.php';
$imgDir = 'users/imgs/';
$tilingDir = 'users/tilings/';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!csrf_validate($_POST['csrf'] ?? null)) {
        $errors[] = 'Invalid session (CSRF). Please refresh and try again.';
    }

    if (empty($errors)) {
        if (empty($_POST['image'])) {
            $errors[] = 'No image data received.';
        } else {
            // Decode the Base64 image sent by the form
            $imageParts = explode(";base64,", $_POST['image']);

            if (count($imageParts) < 2) {
                $errors[] = "Invalid image format.";
            } else {
                $imageType = explode("image/", $imageParts[0])[1];
                $imageBase64 = base64_decode($imageParts[1]);

                $existingId = $_SESSION['step1_image_id'] ?? null;
                $isUpdate = false;
                $targetFilename = null;

                // Check if we are updating (Going back/forward)
                if ($existingId) {
                    $stmt = $cnx->prepare("SELECT filename FROM Images WHERE id_image = ? AND parent_id = ?");
                    $stmt->execute([$existingId, $parentId]);
                    $existingRow = $stmt->fetch();

                    if ($existingRow) {
                        $isUpdate = true;
                        $targetFilename = $existingRow['filename']; // Reuse name
                    }
                }
                // If no existing file to update, generate a new random name
                if (!$targetFilename) {
                    $targetFilename = bin2hex(random_bytes(16)) . '.' . $imageType;
                }
                $targetPath = $imgDir . $targetFilename;

                // Save to Disk
                if (file_put_contents(__DIR__ . '/' . $targetPath, $imageBase64)) {
                    try {
                        if ($isUpdate) {
                            // Update existing row
                            $stmt = $cnx->prepare("UPDATE Images SET status = 'CUSTOM' WHERE id_image = ?");
                            $stmt->execute([$existingId]);

                            // Clean up forward history since crop changed
                            deleteDescendants($cnx, $existingId, $imgDir, $tilingDir, true);

                            // Reset forward session vars
                            unset($_SESSION['step2_image_id']);
                            unset($_SESSION['step3_image_id']);
                            unset($_SESSION['step4_image_id']);

                        } else {
                            // Insert new row
                            $stmt = $cnx->prepare("INSERT INTO Images (user_id, filename, original_name, status, parent_id) VALUES (?, ?, ?, 'CUSTOM', ?)");
                            $userId = $_SESSION['userId'] ?? NULL;
                            $stmt->execute([$userId, $targetFilename, "Cropped_Image", $parentId]);

                            $_SESSION['step1_image_id'] = $cnx->lastInsertId();
                        }

                        // Redirect on success
                        header("Location: dimensions_selection.php");
                        exit;

                    } catch (PDOException $e) {
                        // Rollback file if insert failed (only matters for new inserts)
                        if (!$isUpdate && file_exists(__DIR__ . 'crop_selection.php/' . $targetPath)) {
                            unlink(__DIR__ . 'crop_selection.php/' . $targetPath);
                        }
                        http_response_code(500);
                        $errors[] = "Database error: " . $e->getMessage();
                    }
                } else {
                    http_response_code(500);
                    $errors[] = "Server error: could not save cropped image.";
                }
            }
        }
    }
}

// Get request: fetch and display image
try {
    $stmt = $cnx->prepare("SELECT filename FROM Images WHERE id_image = ?");
    $stmt->execute([$parentId]);
    $image = $stmt->fetch();

    if (!$image) {
        http_response_code(404);
        die("Image not found");
    }
    // Add timestamp to prevent caching
    $displayPath = $imgDir . $image['filename'] . '?t=' . time();

} catch (PDOException $e) {
    http_response_code(500);
    die("Database Error");
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Step 1: Crop</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .crop-area {
            height: 70vh;
            background: #212529;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            overflow: hidden;
        }

        img {
            max-width: 100%;
            max-height: 100%;
            display: block;
        }
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
                <?php foreach ($errors as $error): ?>
                    <li><?= htmlspecialchars($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-lg-8">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0">Step 1: Crop</h5>
                </div>
                <div class="card-body p-2">
                    <div class="crop-area">
                        <img id="image" src="<?= htmlspecialchars($displayPath) ?>" crossorigin="anonymous">
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body d-flex flex-column">
                    <h3 class="mb-3">Crop Your Image.</h3>
                    <p class="text-muted">Choose a preset or drag the handles freely.</p>

                    <div class="mb-4">
                        <label class="form-label fw-bold">Aspect Ratio:</label>
                        <div class="d-grid gap-2">
                            <div class="btn-group" role="group">
                                <button type="button" class="btn btn-outline-primary" onclick="setRatio(1)">1:1 (Square)</button>
                                <button type="button" class="btn btn-outline-primary" onclick="setRatio(16/9)">16:9 (Wide)</button>
                                <button type="button" class="btn btn-outline-primary" onclick="setRatio(4/3)">4:3 (Photo)</button>
                            </div>
                            <button type="button" class="btn btn-outline-secondary" onclick="setRatio(NaN)">🔓 Free / Custom</button>
                        </div>
                    </div>

                    <form method="POST" id="cropForm">
                        <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_get()) ?>">
                        <input type="hidden" name="image" id="hiddenImage">
                    </form>

                    <div class="mt-auto pt-3 border-top d-flex justify-content-between align-items-center">
                        <a href="index.php" class="btn btn-outline-secondary">Cancel</a>
                        <button id="btnSave" class="btn btn-primary btn-lg">Next Step ➔</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.js"></script>
<script>
    const image = document.getElementById('image');
    const form = document.getElementById('cropForm');
    const btnSave = document.getElementById('btnSave');
    let cropper;

    // Initialize Cropper
    const startCropper = () => {
        if (cropper) cropper.destroy();
        cropper = new Cropper(image, {
            aspectRatio: NaN,
            viewMode: 1,
            autoCropArea: 0.8,
            background: false,
            responsive: true,
        });
    };

    if (image.complete) {
        startCropper();
    } else {
        image.onload = startCropper;
    }

    // Helper to change ratio
    window.setRatio = (ratio) => {
        if(cropper) cropper.setAspectRatio(ratio);
    };

    // Handle "Next" Button
    btnSave.addEventListener('click', () => {
        if (!cropper) return;

        // Get cropped canvas
        const canvas = cropper.getCroppedCanvas({
            maxWidth: 2048,
            maxHeight: 2048
        });

        if (!canvas) return;

        // Put data into Hidden Input
        const base64 = canvas.toDataURL('image/jpeg', 0.9);
        document.getElementById('hiddenImage').value = base64;

        // Submit Form
        btnSave.disabled = true;
        btnSave.textContent = "Processing...";
        form.submit();
    });
</script>

<?php include("./includes/footer.php"); ?>
</body>
</html>