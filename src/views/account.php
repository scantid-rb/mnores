<?php /** @var array $user */ /** @var array $errors */ /** @var ?string $error_section */ ?>
<section class="card card-narrow" data-testid="account-page">
    <h1 class="title">Mi cuenta</h1>

    <div class="userinfo">
        <div class="userinfo-row"><span class="label">Rol</span><span class="value"><span class="pill" data-testid="acc-role"><?= e(role_label($user['role'])) ?></span></span></div>
        <div class="userinfo-row"><span class="label">Barco</span><span class="value" data-testid="acc-boat"><?= e($user['boat_name'] ?? '—') ?><?= (isset($user['boat_is_active']) && (int)$user['boat_is_active'] === 0) ? ' <span class="tag">inactivo</span>' : '' ?></span></div>
        <div class="userinfo-row"><span class="label">Estado</span><span class="value" data-testid="acc-state"><?= (int)$user['is_active'] === 1 ? 'Activo' : 'Inactivo' ?></span></div>
    </div>

    <hr class="sep">
    <h2 class="section-title">Datos personales</h2>
    <?php if ($errors && $error_section === 'profile'): ?><div class="alert alert-error"><?php foreach ($errors as $er) echo '<div>'.e($er).'</div>'; ?></div><?php endif; ?>
    <form method="post" action="<?= e(url('/account')) ?>" class="form" autocomplete="off">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update_profile">
        <label>Usuario
            <input type="text" name="username" required maxlength="40" value="<?= e($user['username']) ?>" data-testid="acc-username">
        </label>
        <div class="row">
            <label>Nombre
                <input type="text" name="first_name" required maxlength="80" value="<?= e($user['first_name']) ?>" data-testid="acc-first-name">
            </label>
            <label>Apellidos
                <input type="text" name="last_name" required maxlength="120" value="<?= e($user['last_name']) ?>" data-testid="acc-last-name">
            </label>
        </div>
        <button type="submit" class="btn btn-primary" data-testid="acc-profile-submit">Guardar datos</button>
    </form>

    <hr class="sep">
    <h2 class="section-title">Cambiar contraseña</h2>
    <?php if ($errors && $error_section === 'password'): ?><div class="alert alert-error"><?php foreach ($errors as $er) echo '<div>'.e($er).'</div>'; ?></div><?php endif; ?>
    <form method="post" action="<?= e(url('/account')) ?>" class="form" autocomplete="off">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="change_password">
        <label>Contraseña actual
            <input type="password" name="current_password" required data-testid="acc-current">
        </label>
        <div class="row">
            <label>Nueva contraseña
                <input type="password" name="password" required minlength="8" data-testid="acc-new1">
            </label>
            <label>Repetir contraseña
                <input type="password" name="password2" required minlength="8" data-testid="acc-new2">
            </label>
        </div>
        <button type="submit" class="btn btn-primary" data-testid="acc-submit">Cambiar contraseña</button>
    </form>
</section>
