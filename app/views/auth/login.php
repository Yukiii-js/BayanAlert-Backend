<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Login</title>
    <link rel="icon" type="image/png" href="<?= site_url('images/bayanalert-icon.png'); ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light d-flex align-items-center vh-100">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-4">
                <div class="card shadow-sm">
                    <div class="card-body p-4">
                        <img src="<?= site_url('images/bayanalert-logo.png'); ?>" alt="BayanAlert" class="d-block mx-auto mb-2" style="width: 80px; height: 80px; object-fit: contain;">
                        <p class="text-center text-secondary small mb-3">Calapan City Community Safety</p>
                        <h4 class="text-center mb-4">Admin Login</h4>
                        <?php if(!empty($error)): ?>
                            <div class="alert alert-danger p-2 fs-6"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
                        <?php endif; ?>
                        <form action="<?= site_url('auth/authenticate'); ?>" method="POST">
                            <?php csrf_field(); ?>
                            <div class="mb-3">
                                <label class="form-label">Username or Email</label>
                                <input type="text" name="username" class="form-control" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Password</label>
                                <input type="password" name="password" class="form-control" required>
                            </div>
                            <button type="submit" class="btn btn-primary w-100">Sign In</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>