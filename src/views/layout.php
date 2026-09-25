<?php
/** @var string $__view */
$__title = $title ?? setting('app_name');
$__user  = current_user();
$__path  = current_path();
$__brand = setting('app_title');
$__company = setting('company_name');

$__diskWarn = null;
if ($__user && in_array($__user['role'], [ROLE_ADMIN, ROLE_INSPECTOR], true)) {
    $free = @disk_free_space(DATA_DIR);
    $total = @disk_total_space(DATA_DIR);
    if ($free && $total && $total > 0) {
        $pct = ($free / $total) * 100.0;
        $thr = setting_int('disk_warning_percent');
        if ($pct < $thr) $__diskWarn = ['pct'=>$pct, 'thr'=>$thr];
    }
}

$isAdminOrInsp = $__user && in_array($__user['role'], [ROLE_ADMIN, ROLE_INSPECTOR], true);
$activeIn = function(array $paths) use ($__path) {
    foreach ($paths as $p) if ($__path === $p || str_starts_with($__path, $p . '/')) return true;
    return false;
};
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= e($__title) ?> · <?= e($__brand) ?></title>
<link rel="stylesheet" href="<?= e(url('/assets/style.css')) ?>">
</head>
<body>
<header class="topbar">
    <div class="brand">
        <span class="brand-mark">◈</span>
        <span class="brand-name"><?= e($__brand) ?></span>
        <?php if ($__company): ?><span class="brand-company">· <?= e($__company) ?></span><?php endif; ?>
    </div>

    <?php if ($__user): ?>
        <details class="menu-toggle" data-testid="menu-toggle">
            <summary aria-label="Menú">☰</summary>
        </details>

        <nav class="topnav-links" aria-label="principal" data-testid="topnav">
            <a href="<?= e(url('/home')) ?>" class="nav-link<?= $__path==='/home'?' active':'' ?>" data-testid="nav-home">Inicio</a>

            <details class="nav-drop<?= $activeIn(['/parts','/categories','/export'])?' active':'' ?>" data-testid="nav-inventario">
                <summary>Inventario ▾</summary>
                <div class="nav-drop-menu">
                    <a href="<?= e(url('/parts')) ?>" data-testid="nav-parts">Inventario</a>
                    <a href="<?= e(url('/categories')) ?>" data-testid="nav-categories">Categorías</a>
                    <?php if ($isAdminOrInsp): ?><a href="<?= e(url('/backups')) ?>#exportar" data-testid="nav-export">Exportar Excel</a><?php endif; ?>
                </div>
            </details>

            <?php if (can_manage_users($__user)): ?>
            <details class="nav-drop<?= $activeIn(['/users'])?' active':'' ?>" data-testid="nav-usuarios">
                <summary>Usuarios ▾</summary>
                <div class="nav-drop-menu">
                    <a href="<?= e(url('/users')) ?>" data-testid="nav-users">
                        <?= $__user['role']===ROLE_CHIEF ? 'Mecánicos / Mi barco' : 'Usuarios' ?>
                    </a>
                </div>
            </details>
            <?php endif; ?>

            <a href="<?= e(url('/boats')) ?>" class="nav-link<?= $activeIn(['/boats'])?' active':'' ?>" data-testid="nav-boats">Barcos</a>

            <?php if ($isAdminOrInsp): ?>
            <details class="nav-drop<?= $activeIn(['/backups','/audit','/settings','/status'])?' active':'' ?>" data-testid="nav-sistema">
                <summary>Sistema ▾</summary>
                <div class="nav-drop-menu">
                    <a href="<?= e(url('/backups')) ?>" data-testid="nav-backups">Backups</a>
                    <a href="<?= e(url('/audit')) ?>" data-testid="nav-audit">Auditoría</a>
                    <a href="<?= e(url('/settings')) ?>" data-testid="nav-settings">Configuración</a>
                    <a href="<?= e(url('/status')) ?>" data-testid="nav-status">Estado del sistema</a>
                </div>
            </details>
            <?php endif; ?>

            <a href="<?= e(url('/account')) ?>" class="nav-link<?= $__path==='/account'?' active':'' ?>" data-testid="nav-account">Mi cuenta</a>

            <form method="post" action="<?= e(url('/logout')) ?>" class="inline nav-logout">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-ghost btn-sm" data-testid="topbar-logout-btn">Cerrar sesión</button>
            </form>
        </nav>

        <div class="user-badge-wrap">
            <span class="user-badge" data-testid="topbar-user">
                <?= e($__user['first_name'] . ' ' . $__user['last_name']) ?>
                <em><?= e(role_label($__user['role'])) ?></em>
            </span>
        </div>
    <?php endif; ?>
</header>

<main class="container">
    <?php if ($__diskWarn): ?>
        <div class="alert alert-warn" data-testid="disk-warning">
            <strong>Espacio bajo:</strong> queda <?= number_format($__diskWarn['pct'], 1) ?>% libre (umbral <?= (int)$__diskWarn['thr'] ?>%). Considere liberar espacio o retirar backups antiguos.
        </div>
    <?php endif; ?>

    <?php
    $flash_error   = $_SESSION['flash_error']   ?? null;
    $flash_success = $_SESSION['flash_success'] ?? null;
    unset($_SESSION['flash_error'], $_SESSION['flash_success']);
    ?>
    <?php if ($flash_error): ?><div class="alert alert-error" data-testid="flash-error"><?= e($flash_error) ?></div><?php endif; ?>
    <?php if ($flash_success): ?><div class="alert alert-success" data-testid="flash-success"><?= e($flash_success) ?></div><?php endif; ?>
    <?php require __DIR__ . '/' . $__view . '.php'; ?>
</main>

<footer class="footer"><small><?= e($__title) ?> · v<?= e(APP_VERSION) ?><?= $__company ? ' · '.e($__company) : '' ?></small></footer>

<script>
// Cierra los desplegables del menú al hacer clic fuera.
document.addEventListener('click', (e) => {
    document.querySelectorAll('nav .nav-drop[open]').forEach(d => {
        if (!d.contains(e.target)) d.removeAttribute('open');
    });
});
// Cierra el menú móvil al clicar un enlace.
document.querySelectorAll('.topnav-links a').forEach(a => a.addEventListener('click', () => {
    const t = document.querySelector('.menu-toggle');
    if (t) t.removeAttribute('open');
}));
</script>
</body>
</html>
