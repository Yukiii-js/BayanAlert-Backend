<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Hazard Categories</title>
    <link rel="icon" type="image/png" href="<?= site_url('images/bayanalert-icon.png'); ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container my-5">
        <div class="d-flex align-items-center gap-3 mb-4">
            <img src="<?= site_url('images/bayanalert-logo.png'); ?>" alt="BayanAlert" style="width: 48px; height: 48px; object-fit: contain;">
            <div>
                <strong class="d-block">BayanAlert</strong>
                <small class="text-secondary">Calapan City Community Safety</small>
            </div>
        </div>
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2>Hazard Categories</h2>
            <div>
                <a href="<?= site_url('category/create'); ?>" class="btn btn-primary">+ Add Category</a>
            </div>
        </div>

        <?php if(!empty($success)): ?>
            <div class="alert alert-success"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        <?php if(!empty($error)): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <div class="card shadow-sm">
            <div class="card-body">
                <table class="table table-bordered table-striped align-middle mb-0">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Category Name</th>
                            <th>Description</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(!empty($categories)): foreach($categories as $cat): ?>
                        <tr>
                            <td><?= $cat['category_id']; ?></td>
                            <td><?= htmlspecialchars($cat['category_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?= htmlspecialchars((string) ($cat['description'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td>
                                <span class="badge bg-<?= $cat['is_active'] ? 'success' : 'secondary'; ?>">
                                    <?= $cat['is_active'] ? 'Active' : 'Inactive'; ?>
                                </span>
                            </td>
                            <td>
                                <a href="<?= site_url('category/edit/' . $cat['category_id']); ?>" class="btn btn-sm btn-warning">Edit</a>
                                <form action="<?= site_url('category/delete/' . $cat['category_id']); ?>" method="POST" class="d-inline" onsubmit="return confirm('Delete this record?')">
                                    <?php csrf_field(); ?>
                                    <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; else: ?>
                        <tr>
                            <td colspan="5" class="text-center">No categories found.</td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <form action="<?= site_url('auth/logout'); ?>" method="POST" class="mt-3 text-end">
            <?php csrf_field(); ?>
            <button type="submit" class="btn btn-outline-danger">Logout</button>
        </form>
    </div>
</body>
</html>