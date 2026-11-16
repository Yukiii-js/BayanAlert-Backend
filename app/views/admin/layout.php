<?php
$e = function ($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
$groups = [];
foreach ($tables as $tableName => $definition) {
    $groups[$definition['group']][$tableName] = $definition;
}
$pageTitle = 'Overview';
if ($view_page === 'table' || $view_page === 'form') {
    $pageTitle = $table_info['label'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/png" href="<?= site_url('images/bayanalert-icon.png'); ?>">
    <title><?= $e($pageTitle); ?> · BayanAlert Admin</title>
    <style>
        :root {
            --navy: #101d35;
            --navy-soft: #192b49;
            --blue: #1769e0;
            --blue-dark: #1056bd;
            --cyan: #21b7c9;
            --ink: #17243a;
            --muted: #78869b;
            --line: #e8edf3;
            --canvas: #f4f7fb;
            --white: #fff;
            --green: #15966c;
            --red: #c44747;
            --shadow: 0 12px 34px rgba(24, 43, 74, .07);
        }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--canvas); color: var(--ink); font-family: Inter, ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif; font-size: 14px; }
        a { color: inherit; text-decoration: none; }
        button, input, select, textarea { font: inherit; }
        .shell { min-height: 100vh; }
        .sidebar { position: fixed; inset: 0 auto 0 0; width: 258px; background: var(--navy); color: #d8e2f2; display: flex; flex-direction: column; z-index: 10; overflow-y: auto; }
        .brand { display: flex; align-items: center; gap: 12px; padding: 22px 20px; border-bottom: 1px solid rgba(255,255,255,.08); }
        .brand-mark { display: block; width: 42px; height: 42px; object-fit: contain; }
        .brand-title { color: white; font-size: 18px; font-weight: 850; letter-spacing: -.4px; }
        .brand-sub { margin-top: 3px; color: #91a4c0; font-size: 10px; letter-spacing: .09em; text-transform: uppercase; }
        .brand-title, .brand-sub, .signed-name, .signed-role { display: block; }
        .side-nav { padding: 15px 12px 24px; }
        .nav-caption { margin: 18px 10px 8px; color: #8193ae; font-size: 9px; font-weight: 800; letter-spacing: .15em; text-transform: uppercase; }
        .nav-link { display: flex; gap: 11px; align-items: center; min-height: 38px; padding: 9px 11px; margin: 2px 0; border-radius: 9px; color: #bac9dd; font-size: 12px; transition: .15s ease; }
        .nav-link:hover { color: white; background: rgba(255,255,255,.07); }
        .nav-link.active { color: white; background: linear-gradient(100deg, rgba(35,133,239,.33), rgba(35,133,239,.12)); box-shadow: inset 2px 0 #28c0d0; }
        .nav-icon { display: grid; place-items: center; width: 20px; color: #8fa9ca; font-size: 12px; font-weight: 800; }
        .nav-link.active .nav-icon { color: #49d4df; }
        .side-bottom { margin-top: auto; padding: 15px 16px; border-top: 1px solid rgba(255,255,255,.08); background: rgba(0,0,0,.1); }
        .signed-in { display: flex; align-items: center; gap: 10px; min-width: 0; }
        .avatar { display: grid; place-items: center; width: 35px; height: 35px; flex: 0 0 35px; border-radius: 50%; color: white; background: #245b99; font-size: 12px; font-weight: 800; }
        .signed-name { color: white; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 12px; font-weight: 700; }
        .signed-role { margin-top: 3px; color: #8fa2bd; font-size: 10px; }
        .logout-form { margin-left: auto; }
        .logout { border: 0; background: none; color: #a9bad1; padding: 7px; cursor: pointer; }
        .logout:hover { color: #ff9898; }
        .main { min-height: 100vh; margin-left: 258px; }
        .topbar { position: sticky; top: 0; height: 66px; padding: 0 32px; display: flex; align-items: center; justify-content: space-between; gap: 18px; background: rgba(255,255,255,.94); border-bottom: 1px solid var(--line); z-index: 5; backdrop-filter: blur(10px); }
        .top-context { color: #65758d; font-size: 11px; font-weight: 700; letter-spacing: .045em; }
        .top-right { display: flex; align-items: center; gap: 14px; color: #64738a; font-size: 11px; }
        .secure-pill { display: inline-flex; align-items: center; gap: 6px; border: 1px solid #d8eee7; background: #f0faf6; color: #218267; padding: 7px 10px; border-radius: 999px; font-weight: 750; }
        .content { width: min(1440px, 100%); margin: 0 auto; padding: 30px 32px 48px; }
        .page-head { display: flex; align-items: flex-end; justify-content: space-between; gap: 20px; margin-bottom: 24px; }
        .eyebrow { margin: 0 0 7px; color: var(--blue); font-size: 10px; font-weight: 850; letter-spacing: .12em; text-transform: uppercase; }
        h1 { margin: 0; color: var(--ink); font-size: clamp(22px, 2.5vw, 29px); letter-spacing: -.75px; line-height: 1.2; }
        .subtitle { margin: 8px 0 0; color: var(--muted); font-size: 12px; line-height: 1.6; max-width: 720px; }
        .btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; min-height: 38px; padding: 9px 14px; border: 1px solid transparent; border-radius: 9px; font-size: 12px; font-weight: 750; cursor: pointer; transition: .15s ease; }
        .btn-primary { background: var(--blue); color: white; box-shadow: 0 5px 12px rgba(23,105,224,.17); }
        .btn-primary:hover { background: var(--blue-dark); transform: translateY(-1px); }
        .btn-light { background: white; color: #53647c; border-color: var(--line); }
        .btn-light:hover { border-color: #cbd7e6; background: #f9fbfd; }
        .btn-danger { color: var(--red); background: #fff; border-color: #f0d9d9; min-height: 31px; padding: 6px 9px; font-size: 11px; }
        .btn-danger:hover { background: #fff4f4; border-color: #e9bcbc; }
        .grid { display: grid; gap: 16px; }
        .stats { grid-template-columns: repeat(4, minmax(0, 1fr)); margin-bottom: 22px; }
        .stat-card, .panel, .table-card, .form-card { background: white; border: 1px solid var(--line); border-radius: 13px; box-shadow: var(--shadow); }
        .stat-card { padding: 19px 20px; position: relative; overflow: hidden; }
        .stat-card::after { content: ""; position: absolute; width: 68px; height: 68px; right: -22px; top: -22px; background: #edf5ff; border-radius: 50%; }
        .stat-label { color: #8290a3; font-size: 10px; font-weight: 800; letter-spacing: .09em; text-transform: uppercase; }
        .stat-value { margin-top: 10px; font-size: 28px; font-weight: 850; letter-spacing: -.8px; }
        .stat-note { margin-top: 5px; color: #8a97a8; font-size: 10px; }
        .dashboard-grid { grid-template-columns: minmax(0, 1.45fr) minmax(290px, .85fr); align-items: start; }
        .panel-head { display: flex; justify-content: space-between; align-items: center; gap: 15px; padding: 17px 19px; border-bottom: 1px solid var(--line); }
        .panel-title { margin: 0; font-size: 13px; font-weight: 800; }
        .panel-subtitle { margin-top: 4px; color: var(--muted); font-size: 10px; }
        .panel-body { padding: 18px 19px; }
        .table-groups { display: grid; gap: 20px; }
        .group-title { display: flex; align-items: center; justify-content: space-between; margin: 0 0 10px; color: #7f8da1; font-size: 9px; letter-spacing: .12em; text-transform: uppercase; }
        .tile-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 9px; }
        .table-tile { display: flex; align-items: center; justify-content: space-between; gap: 12px; min-height: 57px; padding: 11px 12px; border: 1px solid #edf0f5; border-radius: 10px; background: #fff; transition: .15s ease; }
        .table-tile:hover { border-color: #c8dcf7; background: #f8fbff; transform: translateY(-1px); }
        .tile-name { font-size: 11px; font-weight: 750; }
        .tile-count { display: grid; place-items: center; min-width: 30px; height: 25px; padding: 0 7px; border-radius: 7px; background: #f0f5fc; color: #42648f; font-size: 10px; font-weight: 800; }
        .quick-list { display: grid; gap: 9px; }
        .quick-link { display: flex; gap: 12px; align-items: center; padding: 12px; border: 1px solid #edf0f5; border-radius: 10px; }
        .quick-link:hover { border-color: #c8dcf7; background: #f8fbff; }
        .quick-icon { display: grid; place-items: center; width: 34px; height: 34px; flex: 0 0 34px; border-radius: 10px; background: #edf5ff; color: var(--blue); font-weight: 900; }
        .quick-name { font-size: 11px; font-weight: 800; }
        .quick-description { margin-top: 4px; color: var(--muted); font-size: 10px; line-height: 1.4; }
        .table-card { overflow: hidden; }
        .toolbar { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 15px 17px; border-bottom: 1px solid var(--line); }
        .search { display: flex; align-items: center; gap: 8px; width: min(370px, 100%); }
        .control { width: 100%; min-height: 38px; padding: 9px 11px; color: #304158; background: white; border: 1px solid #dfe6ef; border-radius: 8px; outline: none; font-size: 12px; }
        .control:focus { border-color: #73a8f3; box-shadow: 0 0 0 3px rgba(23,105,224,.11); }
        .table-wrap { overflow-x: auto; }
        .data-table { width: 100%; border-collapse: collapse; text-align: left; white-space: nowrap; }
        .data-table th { padding: 12px 14px; background: #f8fafd; border-bottom: 1px solid var(--line); color: #8491a4; font-size: 9px; font-weight: 850; letter-spacing: .09em; text-transform: uppercase; }
        .data-table td { max-width: 280px; overflow: hidden; text-overflow: ellipsis; padding: 12px 14px; border-bottom: 1px solid #f0f3f7; color: #485970; font-size: 11px; }
        .data-table tbody tr:hover { background: #fafcff; }
        .data-table tbody tr:last-child td { border-bottom: 0; }
        .id-cell { color: var(--blue) !important; font-weight: 800; }
        .actions { display: flex; justify-content: flex-end; align-items: center; gap: 7px; }
        .text-link { color: var(--blue); font-weight: 750; }
        .text-link:hover { text-decoration: underline; }
        .table-footer { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 12px 17px; border-top: 1px solid var(--line); color: var(--muted); font-size: 10px; }
        .pager { display: flex; align-items: center; gap: 7px; }
        .pager a, .pager span { display: grid; place-items: center; min-width: 30px; height: 29px; padding: 0 8px; border: 1px solid var(--line); border-radius: 7px; background: white; }
        .pager .current { color: white; border-color: var(--blue); background: var(--blue); }
        .alert { margin-bottom: 16px; padding: 12px 14px; border: 1px solid transparent; border-radius: 9px; font-size: 11px; line-height: 1.5; }
        .alert-success { color: #176b50; border-color: #cfe9dc; background: #f0fbf6; }
        .alert-error { color: #9a3939; border-color: #f0d2d2; background: #fff5f5; }
        .form-card { max-width: 900px; overflow: hidden; }
        .form-body { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 18px; padding: 22px; }
        .field { min-width: 0; }
        .field.full { grid-column: 1 / -1; }
        .field-label { display: block; margin-bottom: 7px; color: #4c5c72; font-size: 10px; font-weight: 800; }
        .field-hint { margin-top: 6px; color: #8a97a8; font-size: 10px; line-height: 1.45; }
        textarea.control { min-height: 95px; resize: vertical; }
        .check-control { display: flex; align-items: center; gap: 9px; min-height: 38px; color: #526279; font-size: 12px; }
        .check-control input { width: 16px; height: 16px; accent-color: var(--blue); }
        .form-actions { display: flex; align-items: center; justify-content: flex-end; gap: 9px; padding: 15px 22px; border-top: 1px solid var(--line); background: #fafbfd; }
        .empty { padding: 42px 18px; text-align: center; color: var(--muted); font-size: 12px; }
        .tag { display: inline-flex; padding: 4px 8px; border-radius: 999px; background: #edf5ff; color: #3167a7; font-size: 9px; font-weight: 800; }
        .menu-toggle { display: none; border: 1px solid var(--line); background: white; color: var(--ink); border-radius: 8px; padding: 7px 10px; }
        @media (max-width: 1100px) { .stats { grid-template-columns: repeat(2, minmax(0, 1fr)); } .dashboard-grid { grid-template-columns: 1fr; } }
        @media (max-width: 760px) {
            .sidebar { transform: translateX(-100%); transition: transform .2s ease; box-shadow: 12px 0 28px rgba(10,25,48,.16); }
            .sidebar.open { transform: translateX(0); }
            .main { margin-left: 0; }
            .topbar { height: 58px; padding: 0 15px; }
            .top-context { display: none; }
            .menu-toggle { display: inline-block; }
            .content { padding: 22px 15px 36px; }
            .page-head { align-items: flex-start; }
            .page-head .btn { white-space: nowrap; }
            .stats { gap: 10px; }
            .stat-card { padding: 15px; }
            .stat-value { font-size: 24px; }
            .tile-grid { grid-template-columns: 1fr; }
            .form-body { grid-template-columns: 1fr; padding: 17px; }
            .field.full { grid-column: auto; }
            .toolbar, .table-footer { align-items: stretch; flex-direction: column; }
        }
    </style>
</head>
<body>
<div class="shell">
    <aside class="sidebar" id="adminSidebar">
        <a class="brand" href="<?= site_url('admin'); ?>">
            <img class="brand-mark" src="<?= site_url('images/bayanalert-logo.png'); ?>" alt="BayanAlert">
            <span><span class="brand-title">BayanAlert</span><span class="brand-sub">Command center</span></span>
        </a>
        <nav class="side-nav">
            <p class="nav-caption">Workspace</p>
            <a class="nav-link <?= $view_page === 'dashboard' ? 'active' : ''; ?>" href="<?= site_url('admin'); ?>">
                <span class="nav-icon">▦</span> Overview
            </a>
            <?php foreach ($groups as $groupName => $groupTables): ?>
                <p class="nav-caption"><?= $e($groupName); ?></p>
                <?php foreach ($groupTables as $tableName => $definition): ?>
                    <a class="nav-link <?= isset($table) && $table === $tableName ? 'active' : ''; ?>" href="<?= site_url('admin/tables/' . $tableName); ?>">
                        <span class="nav-icon">·</span><?= $e($definition['label']); ?>
                    </a>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </nav>
        <div class="side-bottom">
            <div class="signed-in">
                <span class="avatar"><?= $e(strtoupper(substr($admin_name, 0, 1))); ?></span>
                <span style="min-width:0"><span class="signed-name"><?= $e($admin_name); ?></span><span class="signed-role">Administrator</span></span>
                <form class="logout-form" action="<?= site_url('auth/logout'); ?>" method="POST">
                    <?php csrf_field(); ?>
                    <button class="logout" type="submit" title="Sign out" aria-label="Sign out">↪</button>
                </form>
            </div>
        </div>
    </aside>

    <div class="main">
        <header class="topbar">
            <div style="display:flex;align-items:center;gap:12px">
                <button class="menu-toggle" type="button" onclick="document.getElementById('adminSidebar').classList.toggle('open')" aria-label="Toggle navigation">☰</button>
                <span class="top-context">BAYANALERT <span style="color:#c4ceda">/</span> ADMINISTRATION</span>
            </div>
            <div class="top-right"><span class="secure-pill">● Admin session</span><span><?= date('M j, Y'); ?></span></div>
        </header>
        <main class="content">
            <?php if (!empty($message)): ?><div class="alert alert-success"><?= $e($message); ?></div><?php endif; ?>
            <?php if (!empty($error)): ?><div class="alert alert-error"><?= $e($error); ?></div><?php endif; ?>
            <?php include APP_DIR . 'views/admin/' . $view_page . '.php'; ?>
        </main>
    </div>
</div>
</body>
</html>
