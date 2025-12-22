<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Check Your Email</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .icon-box { font-size: 3rem; margin-bottom: 1rem; color: #0d6efd; }
    </style>
</head>
<body class="bg-light d-flex flex-column min-vh-100">

<?php include("./includes/navbar.php"); ?>

<div class="container flex-grow-1 d-flex align-items-center justify-content-center py-5">
    <div class="card shadow-sm border-0 text-center" style="max-width: 500px; width: 100%;">
        <div class="card-body p-5">
            <div class="icon-box">✉️</div>
            <h2 class="fw-bold mb-3">Check your inbox</h2>
            <p class="text-muted mb-4">
                We have sent a secure login link to your email address.
                Please click the link to complete your sign-in.
            </p>
        </div>
    </div>
</div>

<?php include("./includes/footer.php"); ?>
</body>
</html>
