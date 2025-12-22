<?php
session_start();
global $cnx;
include("./config/cnx.php");

// Validate input Ensure token presence
if (!isset($_GET['token'])) {
    http_response_code(400);
    die("No token provided.");
}

try {
    // Query token Verify validity and expiration
    $stmt = $cnx->prepare("SELECT t.*, u.username, u.email 
                               FROM Tokens2FA t
                               JOIN Users u ON t.user_id = u.id_user
                               WHERE t.token = ? AND t.is_used = 0 
                               LIMIT 1");
    $stmt->execute([$_GET['token']]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$result) {
        http_response_code(400);
        die("Invalid or expired login link.");
    }

    // Check expiration (10 minutes)
    $created = new DateTime($result['created_at']);
    $now = new DateTime();
    $diff = $now->diff($created);

    // 10 minutes = 10 * 60 seconds
    $minutes = ($diff->days * 24 * 60) + ($diff->h * 60) + $diff->i;

    if ($minutes > 10) {
        http_response_code(400);
        die("This login link has expired. Please try logging in again.");
    }

    // --- SUCCESS: LOG THE USER IN ---

    // Mark token as used
    $updateStmt = $cnx->prepare("UPDATE Tokens2FA SET is_used = 1 WHERE token = ?");
    $updateStmt->execute([$_GET['token']]);

    // Regenerate session ID Prevent fixation
    session_regenerate_id(true);
    $_SESSION['userId'] = $result['user_id'];
    $_SESSION['username'] = $result['username'];

    // Link guest images to new session
    // Note: This relies on the user clicking the link in the same browser session
    $guestImages = [
        $_SESSION['step0_image_id'] ?? null,
        $_SESSION['step1_image_id'] ?? null,
        $_SESSION['step2_image_id'] ?? null,
        $_SESSION['step3_image_id'] ?? null,
        $_SESSION['step4_image_id'] ?? null
    ];

    foreach ($guestImages as $imgId) {
        if ($imgId) {
            // Adopt image only if currently orphaned
            $adoptStmt = $cnx->prepare("UPDATE Images SET user_id = ? WHERE id_image = ? AND user_id IS NULL");
            $adoptStmt->execute([$result['user_id'], $imgId]);
        }
    }

    // Rotate CSRF
    csrf_rotate();

    // Redirect to intended destination
    if (isset($_SESSION['redirect_after_login'])) {
        header("Location:" . $_SESSION['redirect_after_login']);
    } else {
        header('Location: index.php');
    }
    exit;

} catch (PDOException $e) {
    http_response_code(500);
    die('Database error. Please try again later.');
}
?>
