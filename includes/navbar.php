<?php
$isLoggedIn = isset($_SESSION['userId']);
$navUsername = $_SESSION['username'] ?? 'Account';
$currentPage = basename($_SERVER['PHP_SELF']);
?>

<nav class="navbar navbar-expand-lg navbar-light bg-white shadow-sm mb-4">
    <div class="container-fluid px-3">
        <a class="navbar-brand fw-bold" href="index.php">🧱 Img2Brick</a>

        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarContent" aria-controls="navbarContent" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="navbarContent">

            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
            </ul>

            <div class="d-flex flex-column flex-lg-row align-items-lg-center gap-2 mt-3 mt-lg-0">
                <?php if ($isLoggedIn): ?>

                    <a href="my_orders.php" class="btn btn-outline-secondary <?= ($currentPage == 'my_orders.php') ? 'active' : '' ?>">
                        📦 My Orders
                    </a>

                    <a href="my_account.php" class="btn btn-outline-secondary <?= ($currentPage == 'my_account.php') ? 'active' : '' ?>">
                        👤 <?= htmlspecialchars($navUsername) ?>
                    </a>

                    <a href="logout.php" class="btn btn-outline-danger">Log Out</a>

                <?php else: ?>

                    <a href="connexion.php" class="btn btn-outline-primary">Log In</a>
                    <a href="creation.php" class="btn btn-primary">Sign Up</a>

                <?php endif; ?>
            </div>
        </div>
    </div>
</nav>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>