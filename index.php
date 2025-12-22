<?php
session_start();
global $cnx;
include("./config/cnx.php");

$imgDir = __DIR__ . '/users/imgs';
$tilingDir = __DIR__ . '/users/tilings';
$_SESSION['redirect_after_login'] = 'index.php';
$errors = [];

if (rand(1, 20) === 1) {
    cleanStorage($cnx, $imgDir, $tilingDir);
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (isset($_SESSION['step0_image_id'])) {
        // Delete existing image tree to prevent orphans
        deleteDescendants($cnx, $_SESSION['step0_image_id'], $imgDir, $tilingDir, false);

        // Reset session variables
        unset($_SESSION['step0_image_id']);
        unset($_SESSION['step1_image_id']);
        unset($_SESSION['step2_image_id']);
        unset($_SESSION['step3_image_id']);
        unset($_SESSION['step4_image_id']);
        unset($_SESSION['target_width']);
        unset($_SESSION['target_height']);
    }

    if (!isset($_FILES["image"])) {
        http_response_code(450);
        $errors[] = "No file received.";
    } else {
        $img = $_FILES["image"];

        if ($img["error"] !== UPLOAD_ERR_OK) {
            http_response_code(400);
            $errors[] = error_message($img['error']);
        }

        if (empty($errors)) {
            // Validate file extension against allowlist
            $allowedExtensions = ['png', 'jpg', 'jpeg', 'gif', 'webp'];
            $fileExtension = strtolower(pathinfo($img['name'], PATHINFO_EXTENSION));
            if (!in_array($fileExtension, $allowedExtensions, true)) {
                http_response_code(400);
                $errors[] = "Invalid file extension.";
            }
        }

        if (empty($errors)) {
            // Enforce maximum file size
            if (!isset($img["size"]) || (int)$img["size"] > 2000000) {
                http_response_code(400);
                $errors[] = "The file size is too big (max 2MB).";
            }
        }

        if (empty($errors)) {
            // Verify image integrity and dimensions
            $size = @getimagesize($img["tmp_name"]);
            if ($size === false) {
                http_response_code(400);
                $errors[] = "Uploaded file is not a valid image.";
            } else {
                $width = (int)$size[0];
                $height = (int)$size[1];

                if ($width < 64 || $width > 4096 || $height < 64 || $height > 4096) {
                    http_response_code(400);
                    $errors[] = "Image dimensions must be between 64 and 4096 pixels.";
                }
            }
        }

        if (empty($errors)) {
            // Validate MIME type against allowlist
            $mimeType = @mime_content_type($img["tmp_name"]);
            $allowedMimes = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];
            if (!is_string($mimeType) || !in_array($mimeType, $allowedMimes, true)) {
                http_response_code(400);
                $errors[] = "Invalid image MIME type.";
            }
        }

        if (empty($errors)) {
            if (!is_dir($imgDir)) {
                @mkdir($imgDir, 0700, true);
            }

            if (!is_dir($imgDir) || !is_writable($imgDir)) {

                http_response_code(500);
                $errors[] = "Server error: preview folder is not writable.";

            } else {

                $safeName = bin2hex(random_bytes(16)) . '.' . $fileExtension;
                $targetPath = $imgDir . '/' . $safeName;

                if (!move_uploaded_file($img["tmp_name"], $targetPath)) {
                    http_response_code(500);
                    $errors[] = "Server error: could not store uploaded file.";
                } else {

                    try {
                        // Assign NULL user ID for guests
                        $userId = $_SESSION['userId'] ?? NULL;

                        $stmt = $cnx->prepare("INSERT INTO Images (user_id, filename, original_name, status) VALUES (?, ?, ?, 'RAW')");
                        $stmt->execute([
                                $userId,
                                $safeName,
                                $img['name'],
                        ]);

                        // Store image ID for next step
                        $_SESSION['step0_image_id'] = $cnx->lastInsertId();

                        // Redirect to crop selection
                        header("Location: crop_selection.php");
                        exit;

                    } catch (PDOException $e) {
                        // Delete uploaded file on database failure
                        if (file_exists($targetPath)) {
                            unlink($targetPath);
                        }
                        $errors[] = "Database error. Please try again.";
                    }
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upload Image - Img2Brick</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        /* Modern Drag & Drop Styling */
        #dropArea {
            border: 2px dashed #adb5bd;
            background-color: #f8f9fa;
            border-radius: 12px;
            padding: 3rem;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-bottom: 1.5rem;
            position: relative;
        }

        #dropArea:hover, #dropArea.highlight {
            border-color: #0d6efd;
            background-color: #e9ecef;
            color: #0d6efd;
        }

        .upload-icon {
            font-size: 3rem;
            margin-bottom: 1rem;
            color: #6c757d;
        }

        #dropArea:hover .upload-icon, #dropArea.highlight .upload-icon {
            color: #0d6efd;
        }

        /* Error List Styling */
        .error-list {
            background-color: #fee;
            border: 1px solid #fcc;
            border-radius: 4px;
            padding: 10px;
            margin-bottom: 15px;
            text-align: left;
        }
        .error-list ul {
            margin: 5px 0;
            padding-left: 20px;
        }
        .error-list li {
            color: #c00;
        }
    </style>
</head>

<body class="bg-light d-flex flex-column min-vh-100">
<?php include("./includes/navbar.php"); ?>

<div class="container flex-grow-1 d-flex align-items-center justify-content-center py-5">
    <div class="col-md-8 col-lg-6">
        <div class="card shadow-sm border-0">
            <div class="card-body p-4 p-md-5 text-center">

                <h2 class="fw-bold mb-3">Upload your Image</h2>
                <p class="text-muted mb-4">Start your mosaic journey by selecting a photo.</p>

                <?php if (!empty($errors)): ?>
                    <div class="error-list">
                        <ul>
                            <?php foreach ($errors as $error): ?>
                                <li><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <form action="" method="post" enctype="multipart/form-data">

                    <div id="dropArea">
                        <div class="upload-icon"></div>
                        <h5 class="fw-bold">Drag & Drop your image here</h5>
                        <p class="text-muted small mb-0">or click to browse files</p>
                    </div>

                    <input type="file" id="imageUpload" name="image" accept="image/*" required style="display:none;">

                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary btn-lg">Upload & Continue ➔</button>
                    </div>

                    <div class="mt-3 text-muted small">
                        Supported: JPG, PNG, WEBP, GIF (Max 2MB)
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include("./includes/footer.php"); ?>

<script>
    const dropArea = document.getElementById("dropArea");
    const fileInput = document.getElementById("imageUpload");

    // Click to open file dialog
    dropArea.addEventListener("click", () => fileInput.click());

    // Handle file selection via Click
    fileInput.addEventListener("change", () => {
        if (fileInput.files[0]) {
            updateDropArea(fileInput.files[0].name);
        }
    });

    // Handle Drag Events
    ["dragenter", "dragover"].forEach(eventName => {
        dropArea.addEventListener(eventName, e => {
            e.preventDefault();
            dropArea.classList.add("highlight");
        });
    });

    ["dragleave", "drop"].forEach(eventName => {
        dropArea.addEventListener(eventName, e => {
            e.preventDefault();
            dropArea.classList.remove("highlight");
        });
    });

    // Handle Drop
    dropArea.addEventListener("drop", e => {
        const file = e.dataTransfer.files[0];
        if (!file) return;

        fileInput.files = e.dataTransfer.files;
        updateDropArea(file.name);
    });

    // Visual feedback helper
    function updateDropArea(filename) {
        dropArea.classList.add('highlight');
        dropArea.innerHTML = `
            <div class="upload-icon text-primary">📄</div>
            <h5 class="fw-bold text-primary">${filename}</h5>
            <p class="text-muted small mb-0">Click to change file</p>
        `;
    }
</script>
</body>
</html>