<?php
session_start();
global $cnx;
include("./config/cnx.php");

$errors = [];
// States: 'checking' (initial), 'form' (valid token), 'success' (updated), 'error' (invalid token)
$viewState = 'checking';
$message = '';

// 1. TOKEN VERIFICATION
if (!isset($_GET['token'])) {
    $viewState = 'error';
    $message = "No token provided.";
} else {
    $token = $_GET['token'];

    try {
        $stmt = $cnx->prepare("SELECT *, TIMESTAMPDIFF(MINUTE, created_at, NOW()) as age_minutes 
                                   FROM Tokens2FA 
                                   WHERE token = ? AND is_used = 0 
                                   LIMIT 1");
        $stmt->execute([$token]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$result) {
            $viewState = 'error';
            $message = "Invalid or expired token.";
        } elseif ((int)$result['age_minutes'] > 10) {
            $viewState = 'error';
            $message = "This link has expired. Please request a new one.";
        } else {
            // Token is valid, show the form
            $viewState = 'form';
            $userId = $result['user_id'];
        }

    } catch (PDOException $e) {
        $viewState = 'error';
        $message = 'Database error. Please try again later.';
    }
}

// 2. FORM SUBMISSION (Only process if token was valid)
if ($viewState === 'form' && $_SERVER["REQUEST_METHOD"] === "POST") {

    if (!csrf_validate($_POST['csrf'] ?? null)) {
        $errors[] = 'Invalid session.';
    } else {
        $password = $_POST['password'];

        // Enforce password complexity
        if (strlen($password) < 12) {
            $errors[] = 'Password must be at least 12 characters long';
        }
        if (!preg_match('/[0-9]/', $password)) {
            $errors[] = 'Password must contain at least one number';
        }
        if (!preg_match('/[A-Z]/', $password)) {
            $errors[] = 'Password must contain at least one uppercase letter';
        }
        if (!preg_match('/[a-z]/', $password)) {
            $errors[] = 'Password must contain at least one lowercase letter';
        }
        if (!preg_match('/[!@#$%^&*(),.?":{}|<>]/', $password)) {
            $errors[] = 'Password must contain at least one special character';
        }

        if (empty($errors)) {
            $newPassword = password_hash($password, $_ENV['ALGO']);

            try {
                $stmt = $cnx->prepare("UPDATE Users SET password = ? WHERE id_user = ?");
                $stmt->execute([$newPassword, $userId]);

                $stmt = $cnx->prepare("UPDATE Tokens2FA SET is_used = 1 WHERE token = ?");
                $stmt->execute([$token]);

                unset($_SESSION['last_password_reset_sent']);

                // Switch to success view
                $viewState = 'success';
                csrf_rotate();

            } catch (Exception $e) {
                $errors[] = 'Database error. Please try again later.';
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
    <title>Reset Password</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .req-item { margin-bottom: 2px; font-size: 0.85rem; }
        .invalid { color: #dc3545; }
        .success { color: #198754; }
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
                        <div class="icon-box text-success">🎉</div>
                        <h2 class="fw-bold mb-3">Password Updated!</h2>
                        <p class="text-muted mb-4">Your password has been securely reset. You can now log in with your new credentials.</p>
                        <div class="d-grid">
                            <a href="connexion.php" class="btn btn-primary btn-lg">Log In Now</a>
                        </div>
                    </div>
                </div>

            <?php elseif ($viewState === 'error'): ?>
                <div class="card shadow-sm border-0 text-center">
                    <div class="card-body p-5">
                        <div class="icon-box text-danger-custom">⚠️</div>
                        <h2 class="fw-bold mb-3">Link Expired or Invalid</h2>
                        <p class="text-muted mb-4"><?= htmlspecialchars($message) ?></p>
                        <div class="d-grid gap-2">
                            <a href="password_forgotten.php" class="btn btn-primary">Request New Link</a>
                            <a href="index.php" class="btn btn-outline-secondary">Go Home</a>
                        </div>
                    </div>
                </div>

            <?php elseif ($viewState === 'form'): ?>
                <div class="card shadow-sm border-0">
                    <div class="card-body p-4">
                        <h2 class="text-center fw-bold mb-4">Reset Password</h2>

                        <?php if (!empty($errors)): ?>
                            <div class="alert alert-danger">
                                <ul class="mb-0 ps-3">
                                    <?php foreach ($errors as $error): ?>
                                        <li><?= htmlspecialchars($error) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>

                        <form action="" method="POST">
                            <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_get(), ENT_QUOTES, 'UTF-8') ?>">

                            <div class="mb-3">
                                <label for="password" class="form-label">New Password</label>
                                <input type="password" class="form-control" name="password" id="password"
                                       placeholder="Enter new strong password" required>
                            </div>

                            <div id="message" class="alert alert-light border small mb-4">
                                <h6 class="fw-bold mb-2">Password must contain:</h6>
                                <div id="letter" class="req-item invalid">❌ Lowercase letter</div>
                                <div id="capital" class="req-item invalid">❌ Uppercase letter</div>
                                <div id="number" class="req-item invalid">❌ Number</div>
                                <div id="special" class="req-item invalid">❌ Special character</div>
                                <div id="length" class="req-item invalid">❌ Min 12 characters</div>
                            </div>

                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-primary btn-lg">Update Password</button>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endif; ?>

        </div>
    </div>
</div>

<?php include("./includes/footer.php"); ?>

<script>
    const myInput = document.getElementById("password");
    // ... (Keep existing JS validation logic) ...
    if (myInput) {
        const letter = document.getElementById("letter");
        const capital = document.getElementById("capital");
        const number = document.getElementById("number");
        const length = document.getElementById("length");
        const special = document.getElementById("special");

        myInput.onkeyup = function() {
            // Validation logic (Same as before)
            var lowerCaseLetters = /[a-z]/g;
            if(myInput.value.match(lowerCaseLetters)) { letter.classList.remove("invalid"); letter.classList.add("success"); letter.innerHTML = "✅ Lowercase letter"; } else { letter.classList.remove("success"); letter.classList.add("invalid"); letter.innerHTML = "❌ Lowercase letter"; }

            var upperCaseLetters = /[A-Z]/g;
            if(myInput.value.match(upperCaseLetters)) { capital.classList.remove("invalid"); capital.classList.add("success"); capital.innerHTML = "✅ Uppercase letter"; } else { capital.classList.remove("success"); capital.classList.add("invalid"); capital.innerHTML = "❌ Uppercase letter"; }

            var numbers = /[0-9]/g;
            if(myInput.value.match(numbers)) { number.classList.remove("invalid"); number.classList.add("success"); number.innerHTML = "✅ Number"; } else { number.classList.remove("success"); number.classList.add("invalid"); number.innerHTML = "❌ Number"; }

            if(myInput.value.length >= 12) { length.classList.remove("invalid"); length.classList.add("success"); length.innerHTML = "✅ Min 12 characters"; } else { length.classList.remove("success"); length.classList.add("invalid"); length.innerHTML = "❌ Min 12 characters"; }

            var specials = /[!@#$%^&*(),.?":{}|<>]/g;
            if(myInput.value.match(specials)) { special.classList.remove("invalid"); special.classList.add("success"); special.innerHTML = "✅ Special character"; } else { special.classList.remove("success"); special.classList.add("invalid"); special.innerHTML = "❌ Special character"; }
        }
    }
</script>
</body>
</html>