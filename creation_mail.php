<?php
session_start();
global $cnx;
include("./config/cnx.php");

$message = '';
$status = 'neutral'; // neutral, success, error
$errors = [];

// Redirect if session invalid
if (!$_SESSION['tempId'] || !isset($_SESSION['email'])) {
    header('Location: creation.php');
    exit;
}

// Set initial success message on first load
if (isset($_SESSION['email_sent'])) {
    $status = 'success';
    $message = 'A verification email has been sent. It will be valid for 10 minutes.';
    unset($_SESSION['email_sent']);
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // Validate session integrity
    if (!csrf_validate($_POST['csrf'] ?? null)) {
        http_response_code(400);
        die('Invalid form submission.');
    }

    // Enforce rate limiting
    $lastSendTime = $_SESSION['last_email_sent'] ?? 0;
    $currentTime = time();
    $cooldownSeconds = 10;

    if (($currentTime - $lastSendTime) < $cooldownSeconds) {
        $remainingTime = $cooldownSeconds - ($currentTime - $lastSendTime);
        $status = 'error';
        $errors[] = "Please wait {$remainingTime} seconds before resending.";

    } else {

        try {
            // Generate new token
            $token = bin2hex(random_bytes(32));

            $ins = $cnx->prepare("INSERT INTO Tokens2FA (user_id, token, is_used, created_at) VALUES (?, ?, ?, NOW())");
            $ins->execute([$_SESSION['tempId'], $token, 0]);

            // Construct verification link (creates the link depending on whether i'm testing it on my own machine or on the server)
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
            $domain = $_SERVER['HTTP_HOST'];
            $link = $protocol . $domain . dirname($_SERVER['PHP_SELF']) . '/verify_account.php?token=' . $token;

            // Format email body
            $emailBody = "
                                <div style='font-family: Arial, sans-serif; padding: 20px; border: 1px solid #e0e0e0; border-radius: 8px; max-width: 600px;'>
                                    <h2 style='color: #0d6efd;'>Welcome to Img2Brick! 🧱</h2>
                                    <p>Thanks for joining. To activate your account and start building, please click the button below:</p>
                                    <p style='text-align: center;'>
                                        <a href='{$link}' style='display: inline-block; background-color: #0d6efd; color: white; padding: 12px 24px; text-decoration: none; border-radius: 5px; font-weight: bold;'>Verify My Account</a>
                                    </p>
                                    <p style='color: #6c757d; font-size: 12px; margin-top: 20px;'>If the button doesn't work, copy this link: {$link}</p>
                                </div>";

            sendMail(
                    $_SESSION['email'],
                    'Welcome to Img2Brick - Verify your account',
                    $emailBody
            );

            $_SESSION['last_email_sent'] = $currentTime;
            $status = 'success';
            $message = 'Verification email has been resent.';

            csrf_rotate();

        } catch (PDOException $e) {
            $status = 'error';
            $errors[] = 'Database error. Please try again.';
        } catch (Exception $e) {
            $status = 'error';
            $errors[] = 'Error creating token';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Verification</title>
    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .icon-box { font-size: 3rem; margin-bottom: 1rem; }
    </style>
</head>
<body class="bg-light d-flex flex-column min-vh-100">

<?php include("./includes/navbar.php"); ?>

<div class="container flex-grow-1 d-flex align-items-center justify-content-center py-5">
    <div class="card shadow-sm border-0 text-center" style="max-width: 500px; width: 100%;">
        <div class="card-body p-5">

            <div class="icon-box text-primary">📧</div>
            <h2 class="fw-bold mb-3">Check your inbox</h2>

            <p class="text-muted mb-4">
                We have sent a verification link to<br>
                <strong class="text-dark"><?= htmlspecialchars($_SESSION['email']) ?></strong>
            </p>

            <?php if ($status === 'success'): ?>
                <div class="alert alert-success small mb-4">
                    <?= htmlspecialchars($message) ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger small mb-4">
                    <ul class="mb-0 ps-3 text-start">
                        <?php foreach ($errors as $error): ?>
                            <li><?= htmlspecialchars($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form action="" method="POST" class="mb-3">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_get(), ENT_QUOTES, 'UTF-8') ?>">
                <div class="d-grid">
                    <button type="submit" name="resend" class="btn btn-primary btn-lg">Resend Verification Email</button>
                </div>
            </form>

            <a href="connexion.php" class="btn btn-link text-decoration-none text-muted">Back to Login</a>
        </div>
    </div>
</div>

<?php include("./includes/footer.php"); ?>
</body>
</html>