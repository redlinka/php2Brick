<?php
session_start();
global $cnx;
include("./config/cnx.php");

// Enforce authentication
if (!isset($_SESSION['userId'])) {
    $_SESSION['redirect_after_login'] = 'order.php';
    header("Location: creation.php");
    exit;
}
// Verify image generation prerequisite
if (!isset($_SESSION['step4_image_id'])) {
    header("Location: index.php");
    exit;
}

$userId = $_SESSION['userId'];
$imageId = $_SESSION['step4_image_id'];
$imgDir = 'users/imgs/';
$errors = [];

// Retrieve image and user details
$stmt = $cnx->prepare("
                SELECT i.filename, i.status, 
                       u.name, u.surname, u.phone, u.default_address 
                FROM Images i
                JOIN Users u ON u.id_user = ?
                WHERE i.id_image = ? AND i.user_id = ?
            ");
$stmt->execute([$userId, $imageId, $userId]);
$data = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$data) die("Error: Image not found.");

$previewPath = $imgDir . $data['filename'];
$algoInfo = $data['status'] ?? 'Custom';

// Initialize form fields
$fillName    = $data['name'] ?? '';
$fillSurname = $data['surname'] ?? '';
$fillPhone   = $data['phone'] ?? '';
$fillAddr    = $data['default_address'] ?? '';

// Process order submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate($_POST['csrf'] ?? null)) {
        $errors[] = 'Session expired.';
    } elseif (!validateTurnstile()['success']) {
        $errors[] = 'Captcha failed.';
    } else {
        // Sanitize input fields
        $fName   = trim($_POST['first_name']);
        $lName   = trim($_POST['last_name']);
        $phone   = trim($_POST['phone']);

        $address = trim($_POST['address']);
        $city    = trim($_POST['city']);
        $zip     = trim($_POST['zip']);
        $country = trim($_POST['country']);

        // Validate payment credentials
        $cardName = trim($_POST['card_name']);
        $cardNum  = str_replace(' ', '', $_POST['card_number']);

        if ($cardNum !== '4242424242424242' || $_POST['card_cvc'] !== '123') {
            $errors[] = "Payment Declined: Invalid Test Card Credentials.";
        }

        if (empty($fName) || empty($lName) || empty($phone) || empty($address)) {
            $errors[] = "Please fill in all contact and shipping fields.";
        }

        // Execute order transaction
        if (empty($errors)) {
            try {
                $cnx->beginTransaction();

                // Insert order record
                $sql = "INSERT INTO Orders (user_id, first_name, last_name, phone, image_id, total_price, status, shipping_address, created_at) 
                                VALUES (?, ?, ?, ?, ?, ?, 'PREPARATION', ?, NOW())";

                $fullAddress = "$address, $zip $city, $country";
                $stmt = $cnx->prepare($sql);

                $stmt->execute([$userId, $fName, $lName, $phone, $imageId, 49.99, $fullAddress]);
                $orderId = $cnx->lastInsertId();

                $cnx->commit();

                // Clear workflow session variables
                unset($_SESSION['step0_image_id']);
                unset($_SESSION['step1_image_id']);
                unset($_SESSION['step2_image_id']);
                unset($_SESSION['step3_image_id']);
                unset($_SESSION['step4_image_id']);

                // Redirect to order confirmation
                $_SESSION['last_order_id'] = $orderId;
                header("Location: order_completed.php");
                exit;

            } catch (Exception $e) {
                $cnx->rollBack();
                $errors[] = "System Error: " . $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Checkout - Img2Brick</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    <style>
        .summary-img { width: 100%; border-radius: 8px; image-rendering: pixelated; }
        .payment-warning { background-color: #fff3cd; color: #856404; border: 1px solid #ffeeba; border-radius: 4px; padding: 10px; font-size: 0.9rem; }
    </style>
</head>
<body>
<?php include("./includes/navbar.php"); ?>

<div class="container bg-light py-5">
    <div class="row g-5">
        <div class="col-md-5 col-lg-4 order-md-last">
            <h4 class="d-flex justify-content-between align-items-center mb-3">
                <span class="text-primary">Your Mosaic</span>
            </h4>
            <ul class="list-group mb-3 shadow-sm">
                <li class="list-group-item p-3 text-center bg-dark">
                    <img src="<?= $previewPath ?>" class="summary-img" alt="Lego Preview">
                </li>
                <li class="list-group-item d-flex justify-content-between lh-sm">
                    <div>
                        <h6 class="my-0">LEGO® Mosaic Kit</h6>
                        <small class="text-muted"><?= htmlspecialchars($algoInfo) ?></small>
                    </div>
                    <span class="text-muted">$49.99</span>
                </li>
                <li class="list-group-item d-flex justify-content-between">
                    <span>Total (USD)</span>
                    <strong>$49.99</strong>
                </li>
            </ul>
        </div>

        <div class="col-md-7 col-lg-8">
            <h4 class="mb-3">Checkout</h4>
            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger"><ul><?php foreach ($errors as $e) echo "<li>$e</li>"; ?></ul></div>
            <?php endif; ?>

            <form method="POST" novalidate>
                <input type="hidden" name="csrf" value="<?= csrf_get() ?>">

                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-white fw-bold">1. Contact Details</div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">First Name</label>
                                <input type="text" class="form-control" name="first_name" value="<?= htmlspecialchars($fillName) ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Last Name</label>
                                <input type="text" class="form-control" name="last_name" value="<?= htmlspecialchars($fillSurname) ?>" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Phone Number</label>
                                <input type="tel" class="form-control" name="phone" value="<?= htmlspecialchars($fillPhone) ?>" placeholder="+33 6 12 34 56 78" required>
                                <div class="form-text">Required for delivery updates.</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-white fw-bold">2. Shipping Address</div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label">Address</label>
                                <input type="text" class="form-control" name="address" value="<?= htmlspecialchars($fillAddr) ?>" placeholder="123 Main St" required>
                            </div>
                            <div class="col-md-5">
                                <label class="form-label">Country</label>
                                <select class="form-select" name="country">
                                    <option value="France">France</option>
                                    <option value="USA">United States</option>
                                    <option value="UK">United Kingdom</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">City</label>
                                <input type="text" class="form-control" name="city" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Zip</label>
                                <input type="text" class="form-control" name="zip" required>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-white fw-bold">3. Payment</div>
                    <div class="card-body">
                        <div class="payment-warning mb-3 text-center">
                            ⚠️ <strong>SIMULATED PAYMENT MODE</strong><br>No real money charged.
                        </div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Name on card</label>
                                <input type="text" class="form-control" name="card_name" value="John Placeholder" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Card number</label>
                                <input type="text" class="form-control" name="card_number" value="4242 4242 4242 4242" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Expiration</label>
                                <input type="text" class="form-control" name="card_exp" value="12/34" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">CVC</label>
                                <input type="text" class="form-control" name="card_cvc" value="123" required>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mb-4 d-flex justify-content-center">
                    <div class="cf-turnstile" data-sitekey="<?= $_ENV['CLOUDFLARE_TURNSTILE_PUBLIC'] ?>"></div>
                </div>

                <div class="d-flex justify-content-between align-items-center mt-4 pt-3 border-top">
                    <a href="tiling_selection.php" class="btn btn-outline-secondary">← Back to Preview</a>
                    <button class="btn btn-primary btn-lg" type="submit">Confirm Order ($49.99)</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include("./includes/footer.php"); ?>

</body>
</html>