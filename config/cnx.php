<?php
// Load Composer dependencies for PHPMailer
require __DIR__ . '/../vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Load configuration credentials from .env file to avoid hardcoding secrets
$_ENV = parse_ini_file(__DIR__ . '/.env');
$user =  $_ENV["USER"];
$pass = $_ENV["PASS"];
$db = $_ENV["DB"];
$host = $_ENV["HOST"];

// Establish database connection using PDO
try {
    $cnx = new PDO(
        "mysql:host=$host;dbname=$db;",
        $user,
        $pass
    );
} catch (PDOException $e) {
    // Hide internal error details in production for security
    http_response_code(500);
    echo "Internal error. Please try again later.";
    echo $e;
    echo phpinfo();
}

/* Send emails using SMTP via PHPMailer.
 * Input: Recipient email, Subject, HTML Body, Array of file paths for attachments.
 * Output: Returns true on success, or error message string on failure. */
function sendMail($to, $subject, $body, $attachments = []) {
    $mail = new PHPMailer(true);

    try {
        // Configure SMTP settings for Gmail service
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = $_ENV['SMTP_USER'];
        $mail->Password = $_ENV['SMTP_PASS'];
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;

        // Set email headers and content
        $mail->setFrom($_ENV['SMTP_USER'], 'App');
        $mail->addAddress($to);

        $mail->isHTML(true); // Enable HTML rendering
        $mail->Subject = $subject;
        $mail->Body = $body;

        // Attach files if provided in the array
        foreach ($attachments as $filePath) {
            if (file_exists($filePath)) {
                $mail->addAttachment($filePath);
            }
        }

        $mail->send();
        return true;
    } catch (Exception $e) {
        return $mail->ErrorInfo;
    }
}

/* Verify Cloudflare Turnstile captcha token.
 * Logic: Sends a POST request to Cloudflare's verification API with the secret key and user token.
 * Output: Returns associative array with 'success' boolean. */
function validateTurnstile() {

    $secret = $_ENV['CLOUDFLARE_TURNSTILE_SECRET'];
    $token = $_POST['cf-turnstile-response'] ?? '';
    $remoteip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'];

    $url = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
    $data = ['secret' => $secret, 'response' => $token];
    if ($remoteip) {
        $data['remoteip'] = $remoteip;
    }

    // Configure HTTP context for the POST request
    $options = [
        'http' => [
            'header' => "Content-type: application/x-www-form-urlencoded\r\n",
            'method' => 'POST',
            'content' => http_build_query($data),
            'timeout' => 5
        ]
    ];
    $context = stream_context_create($options);
    $response = file_get_contents($url, false, $context);

    if ($response === FALSE) {
        return ['success' => false, 'error-codes' => ['internal-error']];
    }
    return json_decode($response, true);
}

/* Retrieve or generate the current CSRF token.
 * Purpose: Ensures a token exists in the session for form rendering. */
function csrf_get()
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/* Validate a POSTed token against the session token.
 * Method: Uses hash_equals to prevent timing attacks during comparison. */
function csrf_validate($tokenFromPost)
{
    if (!isset($_SESSION['csrf_token']) || !is_string($tokenFromPost)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $tokenFromPost);
}

/* Regenerate the CSRF token.
 * Usage: Call this after successful sensitive actions (login, password change) to prevent replay attacks. */
function csrf_rotate()
{
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/* Translate PHP file upload error codes into human-readable messages. */
function error_message($code) {
    switch ($code) {
        case UPLOAD_ERR_INI_SIZE:
            return 'File too large (server limit).';
        case UPLOAD_ERR_FORM_SIZE:
            return 'File too large (form limit).';
        case UPLOAD_ERR_PARTIAL:
            return 'Upload was interrupted. Please retry.';
        case UPLOAD_ERR_NO_FILE:
            return 'No file uploaded.';
        default:
            return 'Upload failed.';
    }
}

/* Recursively delete an image tree (Children first, then Parent).
 * Input: Database connection, Image ID, Directory paths, Flag to keep the root node.
 * Logic:
 * 1. Find all children of the current image.
 * 2. Recursively call this function on children (Depth-First Search).
 * 3. Delete associated temporary files (thumbnails, precursors).
 * 4. Delete the current image file and text file from disk.
 * 5. Remove the record from the database. */
function deleteDescendants($cnx, $imageId, $imgDir, $tilingDir, $keepSelf = false) {

    // Stop if no ID provided
    if (!$imageId) return;

    // Fetch children IDs to recurse
    $stmt = $cnx->prepare("SELECT id_image, filename FROM Images WHERE parent_id = ?");
    $stmt->execute([$imageId]);
    $children = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($children as $child) {
        // Recurse into children to ensure bottom-up deletion
        deleteDescendants($cnx, $child['id_image'], $imgDir, $tilingDir, false);
    }

    // Clean specific temp files associated with this image ID (e.g., algorithmic variations)
    $tempFiles = glob($imgDir . '/temp_*_' . $imageId . '.png');
    if ($tempFiles) {
        foreach ($tempFiles as $temp) {
            if (file_exists($temp)) unlink($temp);
        }
    }

    // Delete the node itself unless requested otherwise (e.g., when updating crop but keeping the ID)
    if (!$keepSelf) {
        // Fetch filename to delete physical files
        $stmtMe = $cnx->prepare("SELECT filename FROM Images WHERE id_image = ?");
        $stmtMe->execute([$imageId]);
        $myFile = $stmtMe->fetchColumn();

        if ($myFile) {
            // Remove main image file
            $imgPath = $imgDir . '/' . $myFile;
            if (file_exists($imgPath)) {
                unlink($imgPath);
            }

            // Remove associated brick list (text file)
            $baseName = pathinfo($myFile, PATHINFO_FILENAME);
            $tilingPath = $tilingDir . '/' . $baseName . '.txt';

            if (file_exists($tilingPath)) {
                unlink($tilingPath);
            }
        }

        // Delete record from Database
        $cnx->prepare("DELETE FROM Images WHERE id_image = ?")->execute([$imageId]);
    }
}

/* Perform garbage collection on storage directories.
 * Logic:
 * A. Delete temporary processing files older than 30 minutes.
 * B. Delete abandoned guest sessions (Images with no User ID) older than 1 hour. */
function cleanStorage($cnx, $imgDir, $brickDir) {
    $now = time();

    // Clean Images Directory: Remove stale temp images
    $tempImgs = glob($imgDir . '/temp_*');
    if ($tempImgs) {
        foreach ($tempImgs as $file) {
            if (is_file($file) && ($now - filemtime($file) >= 1800)) {
                unlink($file);
            }
        }
    }
    // Clean Abandoned Guest Data
    // Find root images (no parent) belonging to guests (no user_id)
    try {
        $stmt = $cnx->query("SELECT id_image, filename FROM Images WHERE user_id IS NULL AND parent_id IS NULL");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $filePath = $imgDir . '/' . $row['filename'];
            $brickPath = $brickDir . '/' . $row['filename'];

            // If file is older than 1 hour, assume session abandoned
            if (file_exists($filePath) && ($now - filemtime($filePath) > 3600)) {

                // Remove brick file if present
                if (file_exists($brickPath)) {
                    unlink($brickPath);
                }

                // Recursively delete the entire tree (Image + Children + DB Rows)
                deleteDescendants($cnx, $row['id_image'], $imgDir, $brickDir);
            }
        }
    } catch (Exception $e) {}
}