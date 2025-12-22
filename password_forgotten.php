<?php
session_start();
global $cnx;
include("./config/cnx.php");

$errors = [];
$viewState = 'form'; // States: 'form', 'success', 'error'
$message = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // Validate session integrity
    if (!csrf_validate($_POST['csrf'] ?? null)) {
        $viewState = 'error';
        $message = 'Invalid form submission.';
    }
    // Verify Captcha (Crucial here to prevent email bombing)
    elseif (!validateTurnstile()['success']) {
        $errors[] = 'Access denied to bots or internal error.';
    }
    else {
        $email = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Invalid email format';
        } else {
            // Enforce rate limiting
            $lastSendTime = $_SESSION['last_password_reset_sent'] ?? 0;
            $currentTime = time();
            $cooldownSeconds = 60; // Increased to 60s for better spam protection

            if (($currentTime - $lastSendTime) < $cooldownSeconds) {
                $remainingTime = $cooldownSeconds - ($currentTime - $lastSendTime);
                $errors[] = "Please wait {$remainingTime} seconds before requesting another link.";
            } else {
                try {
                    // Check email existence
                    $stmt = $cnx->prepare("SELECT * FROM Users WHERE email = ?");
                    $stmt->execute([$email]);
                    $user = $stmt->fetch(PDO::FETCH_ASSOC);

                    // Always show success message to prevent user enumeration
                    $viewState = 'success';

                    if ($user !== false) {
                        // Generate secure token
                        $token = bin2hex(random_bytes(32));

                        // Store token
                        $ins = $cnx->prepare("INSERT INTO Tokens2FA (user_id, token, is_used, created_at) VALUES (?, ?, ?, NOW())");
                        $ins->execute([$user['id_user'], $token, 0]);

                        // Build reset link
                        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
                        $domain = $_SERVER['HTTP_HOST'];
                        $link = $protocol . $domain . dirname($_SERVER['PHP_SELF']) . '/reset_password.php?token=' . $token;

                        // Format email body
                        $emailBody = "
                                <div style='font-family: Arial, sans-serif; padding: 20px; border: 1px solid #e0e0e0; border-radius: 8px; max-width: 600px;'>
                                    <h2 style='color: #0d6efd;'>Password Reset Request 🔐</h2>
                                    <p>We received a request to reset your password. Click the button below to proceed:</p>
                                    <p style='text-align: center;'>
                                        <a href='{$link}' style='display: inline-block; background-color: #0d6efd; color: white; padding: 12px 24px; text-decoration: none; border-radius: 5px; font-weight: bold;'>Reset Password</a>
                                    </p>
                                    <p style='color: #6c757d; font-size: 12px; margin-top: 20px;'>Link expires in 10 minutes.</p>
                                </div>";

                        sendMail($email, 'Password Recovery', $emailBody);

                        // Update rate limit timestamp
                        $_SESSION['last_password_reset_sent'] = $currentTime;
                    }
                    csrf_rotate();

                } catch (PDOException $e) {
                    $viewState = 'error';
                    $message = 'Database error. Please try again later.';
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
    <title>Password Recovery</title>
    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .icon-box { font-size: 3rem; margin-bottom: 1rem; }
        .text-danger-custom { color: #dc3545; }
    </style>
</head>
<body class="bg-light d-flex flex-column min-vh-100">

<?php include("./includes/navbar.php"); ?>

<div class="container flex-grow-1 d-flex align-items-center justify-content-center py-5">
    <div class="row justify-content-center w-100">
        <div class="col-md-6 col-lg-5">

            <?php if ($viewState === 'success'): ?>
                <div class="card shadow-sm border-0 text-center">
                    <div class="card-body p-5">
                        <div class="icon-box text-primary">📧</div>
                        <h2 class="fw-bold mb-3">Check your inbox</h2>
                        <p class="text-muted mb-4">
                            If an account exists for that email, we have sent a password reset link.
                            It may take a few minutes to arrive.
                        </p>
                        <div class="d-grid">
                            <a href="connexion.php" class="btn btn-outline-secondary">Back to Login</a>
                        </div>
                    </div>
                </div>

            <?php elseif ($viewState === 'error'): ?>
                <div class="card shadow-sm border-0 text-center">
                    <div class="card-body p-5">
                        <div class="icon-box text-danger-custom">⚠️</div>
                        <h2 class="fw-bold mb-3">Request Failed</h2>
                        <p class="text-muted mb-4"><?= htmlspecialchars($message) ?></p>
                        <div class="d-grid gap-2">
                            <a href="password_forgotten.php" class="btn btn-primary">Try Again</a>
                            <a href="index.php" class="btn btn-outline-secondary">Go Home</a>
                        </div>
                    </div>
                </div>

            <?php else: ?>
                <div class="card shadow-sm border-0">
                    <div class="card-body p-4">
                        <h2 class="text-center fw-bold mb-4">Password Recovery</h2>
                        <p class="text-muted text-center small mb-4">Enter your email to receive a reset link.</p>

                        <?php if (!empty($errors)): ?>
                            <div class="alert alert-danger">
                                <ul class="mb-0 ps-3">
                                    <?php foreach ($errors as $error): ?>
                                        <li><?= htmlspecialchars($error) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>

                        <form method="POST" action="">
                            <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_get(), ENT_QUOTES, 'UTF-8') ?>">

                            <div class="mb-3">
                                <label for="email" class="form-label">Email Address</label>
                                <input type="email" class="form-control" id="email" name="email"
                                       placeholder="name@example.com" required>
                            </div>

                            <div class="mb-4 d-flex justify-content-center">
                                <div class="cf-turnstile"
                                     data-sitekey="<?php echo $_ENV['CLOUDFLARE_TURNSTILE_PUBLIC']; ?>"
                                     data-theme="light"
                                     data-size="flexible">
                                </div>
                            </div>

                            <div class="d-grid mb-3">
                                <button type="submit" class="btn btn-primary btn-lg">Send Recovery Link</button>
                            </div>

                            <div class="text-center">
                                <a href="connexion.php" class="text-decoration-none">Back to Login</a>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endif; ?>

        </div>
    </div>
</div>

<?php include("./includes/footer.php"); ?>
</body>
</html>