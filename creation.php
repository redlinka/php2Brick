<?php
session_start();
global $cnx;
include("./config/cnx.php");

$errors = [];

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    // Validate session integrity
    if (!csrf_validate($_POST['csrf'] ?? null)) {
        http_response_code(400);
        die('Invalid form submission.');
    }

    // Verify captcha to block bots
    if (!validateTurnstile()['success']) {
        http_response_code(403);
        $errors[] = ('Internal Error or Access Denied to Bots');
    } else {

        // Sanitize and validate email
        $_SESSION['email'] = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);
        if (!filter_var($_SESSION['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Invalid email format';
        }

        // Validate username length
        $username = trim($_POST['username']);
        if (strlen($username) < 8) {
            $errors[] = 'Username must be at least 8 characters long';
        }

        // Enforce password complexity
        $password = $_POST['password'];
        if (strlen($_POST['password']) < 12) {
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

        // Process registration if validation passes
        if (empty($errors)) {

            $password_hashed = password_hash($password, $_ENV['ALGO']);

            try {
                // Check for existing email
                $stmt = $cnx->prepare("SELECT COUNT(*) FROM Users WHERE email = ?");
                $stmt->execute([$_SESSION['email']]);

                if ($stmt->fetchColumn() === 0) {

                    // Check for existing username
                    $stmt = $cnx->prepare("SELECT COUNT(*) FROM Users WHERE username = ?");
                    $stmt->execute([$username]);

                    if ($stmt->fetchColumn() === 0) {
                        try {
                            // Generate verification token
                            $token = bin2hex(random_bytes(32));
                        } catch (Exception $e) {
                            $errors[] = 'Error creating token';
                        }

                        // Insert new user record
                        $stmt = $cnx->prepare("INSERT INTO Users (username, email, password, is_active, phone, default_address, name, surname, year_of_birth, creation_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                        $stmt->execute([$username, $_SESSION['email'], $password_hashed, 0, null, null, null, null, null]);

                        $_SESSION['tempId'] = $cnx->lastInsertId();

                        // Store verification token
                        $stmt = $cnx->prepare("INSERT INTO Tokens2FA (user_id, token, is_used, created_at) VALUES (?, ?, ?, NOW())");
                        $stmt->execute([$_SESSION['tempId'], $token, 0]);

                        // Construct verification link (creates the link depending on whether i'm testing it on my own machine or on the server)
                        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
                        $domain = $_SERVER['HTTP_HOST'];
                        $link = $protocol . $domain . dirname($_SERVER['PHP_SELF']) . '/verify_account.php?token=' . $token;

                        // Send verification email
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
                        $_SESSION['last_email_sent'] = time();

                        csrf_rotate();
                        header('Location: creation_mail.php');
                        exit;
                    } else {
                        $errors[] = 'Username is already taken.';
                    }

                } else {
                    $errors[] = 'An account with this email already exists.';
                }

            } catch (PDOException $e) {
                http_response_code(500);
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
    <title>Create Account - Img2Brick</title>
    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .valid-req { color: #198754; font-size: 0.85rem; }
        .invalid-req { color: #dc3545; font-size: 0.85rem; }
        .req-item { margin-bottom: 2px; }

        /* Password requirement styling updates via JS */
        .invalid { color: #dc3545; } /* Bootstrap Danger */
        .success { color: #198754; } /* Bootstrap Success */
    </style>
</head>
<body>

<?php include("./includes/navbar.php"); ?>

<div class="container bg-light py-5">
    <div class="row justify-content-center">
        <div class="col-md-6 col-lg-5">

            <div class="card shadow-sm border-0">
                <div class="card-body p-4">
                    <h2 class="text-center fw-bold mb-4">Sign Up</h2>

                    <?php if (!empty($errors)): ?>
                        <div class="alert alert-danger">
                            <ul class="mb-0 ps-3">
                                <?php foreach ($errors as $error): ?>
                                    <li><?= htmlspecialchars($error) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <form action="" id="registration-form" method="post" class="needs-validation">
                        <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_get(), ENT_QUOTES, 'UTF-8') ?>">

                        <div class="mb-3">
                            <label for="email" class="form-label">Email Address</label>
                            <input type="email" class="form-control" name="email" id="email"
                                   placeholder="name@example.com"
                                   value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
                        </div>

                        <div class="mb-3">
                            <label for="username" class="form-label">Username</label>
                            <input type="text" class="form-control" name="username" id="username"
                                   placeholder="Choose a username"
                                   minlength="8"
                                   value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required>
                            <div class="form-text">Minimum 8 characters.</div>
                        </div>

                        <div class="mb-3">
                            <label for="password" class="form-label">Password</label>
                            <input type="password" class="form-control" name="password" id="password"
                                   placeholder="Create a strong password" required>
                        </div>

                        <div class="mb-3">
                            <label for="confirm-password" class="form-label">Confirm Password</label>
                            <input type="password" class="form-control" name="confirm-password" id="confirm-password"
                                   placeholder="Repeat password" required>
                            <div id="passwordError" class="form-text mt-1 fw-bold"></div>
                        </div>

                        <div id="message" class="alert alert-light border small mb-3">
                            <h6 class="fw-bold mb-2">Password must contain:</h6>
                            <div id="letter" class="req-item invalid">❌ Lowercase letter</div>
                            <div id="capital" class="req-item invalid">❌ Uppercase letter</div>
                            <div id="number" class="req-item invalid">❌ Number</div>
                            <div id="special" class="req-item invalid">❌ Special character</div>
                            <div id="length" class="req-item invalid">❌ Min 12 characters</div>
                        </div>

                        <div class="mb-4 d-flex justify-content-center">
                            <div class="cf-turnstile"
                                 data-sitekey="<?php echo $_ENV['CLOUDFLARE_TURNSTILE_PUBLIC']; ?>"
                                 data-theme="light"
                                 data-size="flexible"
                                 data-callback="onSuccess">
                            </div>
                        </div>

                        <div class="d-grid mb-3">
                            <input type="submit" class="btn btn-primary btn-lg" id="submit-button" value="Create Account" disabled>
                        </div>

                        <div class="text-center">
                            <span class="text-muted">Already have an account?</span>
                            <a href="connexion.php" class="text-decoration-none fw-bold">Log in</a>
                        </div>
                    </form>
                </div>
            </div>

        </div>
    </div>
</div>

<?php include("./includes/footer.php"); ?>

<script>
    // Real-time validation logic
    document.getElementById('registration-form').addEventListener('input', function () {
        validateForm();
    });

    // Password Requirement Logic (Visual Feedback)
    const myInput = document.getElementById("password");
    const letter = document.getElementById("letter");
    const capital = document.getElementById("capital");
    const number = document.getElementById("number");
    const length = document.getElementById("length");
    const special = document.getElementById("special");

    myInput.onkeyup = function() {
        // Validate lowercase letters
        var lowerCaseLetters = /[a-z]/g;
        if(myInput.value.match(lowerCaseLetters)) {
            letter.classList.remove("invalid");
            letter.classList.add("success");
            letter.innerHTML = "✅ Lowercase letter";
        } else {
            letter.classList.remove("success");
            letter.classList.add("invalid");
            letter.innerHTML = "❌ Lowercase letter";
        }

        // Validate capital letters
        var upperCaseLetters = /[A-Z]/g;
        if(myInput.value.match(upperCaseLetters)) {
            capital.classList.remove("invalid");
            capital.classList.add("success");
            capital.innerHTML = "✅ Uppercase letter";
        } else {
            capital.classList.remove("success");
            capital.classList.add("invalid");
            capital.innerHTML = "❌ Uppercase letter";
        }

        // Validate numbers
        var numbers = /[0-9]/g;
        if(myInput.value.match(numbers)) {
            number.classList.remove("invalid");
            number.classList.add("success");
            number.innerHTML = "✅ Number";
        } else {
            number.classList.remove("success");
            number.classList.add("invalid");
            number.innerHTML = "❌ Number";
        }

        // Validate length
        if(myInput.value.length >= 12) {
            length.classList.remove("invalid");
            length.classList.add("success");
            length.innerHTML = "✅ Min 12 characters";
        } else {
            length.classList.remove("success");
            length.classList.add("invalid");
            length.innerHTML = "❌ Min 12 characters";
        }

        // Validate special char
        var specials = /[!@#$%^&*(),.?":{}|<>]/g;
        if(myInput.value.match(specials)) {
            special.classList.remove("invalid");
            special.classList.add("success");
            special.innerHTML = "✅ Special character";
        } else {
            special.classList.remove("success");
            special.classList.add("invalid");
            special.innerHTML = "❌ Special character";
        }

        validateForm();
    }

    function validateForm() {
        const username = document.getElementById('username').value;
        const password = document.getElementById('password').value;
        const confirmPassword = document.getElementById('confirm-password').value;
        const submitBtn = document.getElementById('submit-button');
        const errorElement = document.getElementById('passwordError');

        // Basic Requirements Check
        // Note: We blindly trust the user can read the visual indicators for now,
        // but the backend will enforce strict rules.

        let isValid = true;

        // Enforce all fields present + Username Min Length
        if (!username || username.length < 8 || !password || !confirmPassword) {
            isValid = false;
        }

        // Match Check
        if (password && confirmPassword) {
            if (password !== confirmPassword) {
                errorElement.textContent = 'Passwords do not match';
                errorElement.className = 'form-text mt-1 fw-bold text-danger';
                isValid = false;
            } else {
                errorElement.textContent = 'Passwords match';
                errorElement.className = 'form-text mt-1 fw-bold text-success';
            }
        } else {
            errorElement.textContent = '';
        }

        if (isValid) {
            submitBtn.disabled = false;
        } else {
            submitBtn.disabled = true;
        }
    }
</script>
</body>
</html>