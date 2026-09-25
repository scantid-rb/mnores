<?php /** @var array $user */ ?>
<section class="card" data-testid="home-page">
    <h1 class="title">Bienvenido, <?= e($user['first_name']) ?></h1>
    <div class="userinfo">
        <div class="userinfo-row"><span class="label">Nombre completo</span><span class="value" data-testid="home-fullname"><?= e($user['first_name'] . ' ' . $user['last_name']) ?></span></div>
        <div class="userinfo-row"><span class="label">Usuario</span><span class="value" data-testid="home-username"><?= e($user['username']) ?></span></div>
        <div class="userinfo-row"><span class="label">Rol</span><span class="value"><span class="pill" data-testid="home-role"><?= e(role_label($user['role'])) ?></span></span></div>
        <div class="userinfo-row"><span class="label">Barco</span><span class="value" data-testid="home-boat"><?= e($user['boat_name'] ?? '—') ?></span></div>
    </div>

    <div class="home-links">
        <a class="home-link" href="<?= e(url('/parts')) ?>" data-testid="home-link-parts"><strong>Inventario</strong><em>Repuestos por barco</em></a>
        <?php if (in_array($user['role'], [ROLE_ADMIN, ROLE_INSPECTOR], true)): ?>
            <a class="home-link" href="<?= e(url('/users')) ?>" data-testid="home-link-users"><strong>Usuarios</strong><em>Gestión de cuentas</em></a>
        <?php endif; ?>
        <a class="home-link" href="<?= e(url('/boats')) ?>" data-testid="home-link-boats"><strong>Barcos</strong><em>Registro de la flota</em></a>
        <a class="home-link" href="<?= e(url('/categories')) ?>" data-testid="home-link-categories"><strong>Categorías</strong><em>Clasificación de repuestos</em></a>
        <a class="home-link" href="<?= e(url('/account')) ?>" data-testid="home-link-account"><strong>Mi cuenta</strong><em>Perfil y contraseña</em></a>
    </div>
</section>
