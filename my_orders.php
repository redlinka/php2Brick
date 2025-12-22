<?php
session_start();
global $cnx;
include("./config/cnx.php");

// Enforce login security
if (!isset($_SESSION['userId'])) {
    header("Location: connexion.php");
    exit;
}

$userId = $_SESSION['userId'];
$imgFolder = 'users/imgs/';
$tilingFolder = 'users/tilings/';

// Fetch user orders
$sql = "SELECT 
            o.id_order, 
            o.total_price, 
            o.status, 
            o.created_at, 
            o.shipping_address,
            o.first_name, o.last_name, o.phone,
            i.filename, 
            i.status as algo_status
        FROM Orders o
        JOIN Images i ON o.image_id = i.id_image
        WHERE o.user_id = ?
        ORDER BY o.created_at DESC";

try {
    $stmt = $cnx->prepare($sql);
    $stmt->execute([$userId]);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Database Error");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>My Orders</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .order-card { transition: transform 0.2s, box-shadow 0.2s; border: none; }
        .order-card:hover { transform: translateY(-3px); box-shadow: 0 10px 20px rgba(0,0,0,0.1) !important; }

        .mosaic-thumb {
            width: 100%;
            height: 150px;
            object-fit: cover;
            image-rendering: pixelated;
            border-top-left-radius: 6px;
            border-top-right-radius: 6px;
        }

        .status-badge { font-size: 0.8rem; padding: 0.5em 0.8em; border-radius: 20px; text-transform: uppercase; letter-spacing: 0.5px; }

        /* Modal Styles */
        .modal-img { width: 100%; border-radius: 8px; image-rendering: pixelated; border: 2px solid #eee; }
    </style>
</head>
<body class="bg-light d-flex flex-column min-vh-100">

<?php include("./includes/navbar.php"); ?>

<div class="container py-5 flex-grow-1">

    <div class="d-flex flex-column flex-md-row justify-content-between align-items-center mb-5">
        <div>
            <h1 class="fw-bold mb-2">📦 My Orders</h1>
            <p class="text-muted mb-0">
                Find here all the orders you made.
            </p>
        </div>
        <div class="mt-3 mt-md-0">
            <a href="index.php" class="btn btn-primary btn-lg shadow-sm">
                + New Order
            </a>
        </div>
    </div>

    <?php if (empty($orders)): ?>
        <div class="text-center py-5">
            <div class="display-1 text-muted mb-3">🕸️</div>
            <h3 class="text-muted">You didn't make any orders with us.</h3>
            <a href="index.php" class="btn btn-outline-primary mt-3">Create my first mosaic</a>
        </div>
    <?php else: ?>

        <div class="row g-4">
            <?php foreach ($orders as $order):
                // Format order data
                $ref = "CMD-" . date("Y", strtotime($order['created_at'])) . "-" . str_pad($order['id_order'], 5, '0', STR_PAD_LEFT);
                $date = date("d/m/Y", strtotime($order['created_at']));
                $imagePath = $imgFolder . $order['filename'];
                $fullPath = __DIR__ . '/' . $imagePath;

                // Calculate image dimensions
                $dimensions = "Standard";
                if (file_exists($fullPath)) {
                    list($w, $h) = getimagesize($fullPath);
                    if ($w && $h) {
                        // Divide by 10 as requested
                        $dimW = round($w / 10);
                        $dimH = round($h / 10);
                        $dimensions = "{$dimW}x{$dimH}";
                    }
                }

                // Define file paths
                $baseName = pathinfo($order['filename'], PATHINFO_FILENAME);
                $txtPath = $tilingFolder . $baseName . '.txt';

                // Map status colors
                $badges = [
                        'PREPARATION' => 'bg-warning text-dark',
                        'SHIPPED' => 'bg-info text-white',
                        'DELIVERED' => 'bg-success text-white',
                        'CANCELLED' => 'bg-danger text-white'
                ];
                $badgeClass = $badges[$order['status']] ?? 'bg-secondary';
                ?>
                <div class="col-md-6 col-lg-4">
                    <div class="card shadow-sm h-100 order-card">
                        <img src="<?= htmlspecialchars($imagePath) ?>" class="mosaic-thumb bg-dark" alt="">

                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <h5 class="card-title fw-bold text-primary mb-0"><?= $ref ?></h5>
                                <span class="badge <?= $badgeClass ?> status-badge"><?= $order['status'] ?></span>
                            </div>

                            <p class="text-muted small mb-3">
                                📅 <?= $date ?><br>
                                🧱 Size : <strong><?= $dimensions ?></strong><br>
                                💳 <strong><?= $order['total_price'] ?> €</strong>
                            </p>

                            <button type="button" class="btn btn-outline-dark w-100"
                                    data-bs-toggle="modal"
                                    data-bs-target="#orderModal"
                                    data-ref="<?= $ref ?>"
                                    data-date="<?= $date ?>"
                                    data-status="<?= $order['status'] ?>"
                                    data-price="<?= $order['total_price'] ?>"
                                    data-address="<?= htmlspecialchars($order['shipping_address']) ?>"
                                    data-contact="<?= htmlspecialchars($order['first_name'] . ' ' . $order['last_name']) ?>"
                                    data-img="<?= $imagePath ?>"
                                    data-dims="<?= $dimensions ?>"
                                    data-txt="<?= file_exists($txtPath) ? $txtPath : '' ?>">
                                👁️ See More
                            </button>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<div class="modal fade" id="orderModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="modalRef">Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row g-4">
                    <div class="col-md-5 text-center">
                        <img src="" id="modalImg" class="modal-img bg-dark mb-2">
                        <p class="text-muted small mb-0">preview</p>
                    </div>

                    <div class="col-md-7">
                        <h6 class="fw-bold border-bottom pb-2">Informations</h6>
                        <ul class="list-unstyled small mb-4">
                            <li><strong>Date :</strong> <span id="modalDate"></span></li>
                            <li><strong>Status :</strong> <span id="modalStatus" class="badge bg-secondary"></span></li>
                            <li><strong>Size :</strong> <span id="modalDims" class="fw-bold"></span></li>
                            <li><strong>Price :</strong> <span id="modalPrice"></span> €</li>
                        </ul>

                        <h6 class="fw-bold border-bottom pb-2">Shipping</h6>
                        <p class="small mb-4">
                            👤 <strong id="modalContact"></strong><br>
                            📍 <span id="modalAddress" style="white-space: pre-line;"></span>
                        </p>

                        <h6 class="fw-bold border-bottom pb-2">files</h6>
                        <div class="d-grid gap-2 mb-3">
                            <a href="#" id="btnDownloadImg" class="btn btn-sm btn-outline-primary" download>
                                🖼️ Download the preview image (.png)
                            </a>
                            <a href="#" id="btnDownloadTxt" class="btn btn-sm btn-outline-secondary" download>
                                📄 Brick List (.txt)
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include("./includes/footer.php"); ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
    const orderModal = document.getElementById('orderModal');
    if (orderModal) {
        orderModal.addEventListener('show.bs.modal', function (event) {
            // Identify trigger button
            const button = event.relatedTarget;

            // Retrieve data attributes
            const ref = button.getAttribute('data-ref');
            const date = button.getAttribute('data-date');
            const status = button.getAttribute('data-status');
            const price = button.getAttribute('data-price');
            const address = button.getAttribute('data-address');
            const contact = button.getAttribute('data-contact');
            const imgPath = button.getAttribute('data-img');
            const dims = button.getAttribute('data-dims');
            const txtPath = button.getAttribute('data-txt');

            // Inject data into modal
            document.getElementById('modalRef').textContent = ref;
            document.getElementById('modalDate').textContent = date;
            document.getElementById('modalStatus').textContent = status;
            document.getElementById('modalPrice').textContent = price;
            document.getElementById('modalAddress').textContent = address;
            document.getElementById('modalContact').textContent = contact;
            document.getElementById('modalImg').src = imgPath;
            document.getElementById('modalDims').textContent = dims + ' studs';

            // Configure download buttons
            const btnImg = document.getElementById('btnDownloadImg');
            btnImg.href = imgPath;

            const btnTxt = document.getElementById('btnDownloadTxt');
            if (txtPath) {
                btnTxt.href = txtPath;
                btnTxt.classList.remove('disabled', 'btn-light');
                btnTxt.classList.add('btn-outline-secondary');
                btnTxt.innerHTML = '📄 Brick List (.txt)';
            } else {
                btnTxt.href = '#';
                btnTxt.classList.remove('btn-outline-secondary');
                btnTxt.classList.add('disabled', 'btn-light');
                btnTxt.innerHTML = '🚫 List Unavailable';
            }
        });
    }
</script>
</body>
</html>