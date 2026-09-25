<?php
/** @var ?array $user */ /** @var array $boats */ /** @var array $allowed_roles */ /** @var array $errors */
$input = $input ?? [];
$val = function(string $k, $default = '') use ($input, $user) {
    if (array_key_exists($k, $input)) return $input[$k];
    if ($user && array_key_exists($k, $user)) return $user[$k];
    return $default;
};
$editing = $user !== null;
$isPrimary = $editing && (int)$user['is_primary_admin'] === 1;
?>
<section class="card card-narrow">
    <h1 class="title"><?= $editing ? 'Editar usuario' : 'Nuevo usuario' ?></h1>
    <?php if ($errors): ?>
        <div class="alert alert-error"><?php foreach ($errors as $er) echo '<div>' . e($er) . '</div>'; ?></div>
    <?php endif; ?>

    <form method="post" action="<?= e(url($editing ? '/users/' . $user['id'] . '/edit' : '/users')) ?>" class="form" autocomplete="off">
        <?= csrf_field() ?>
        <label>Usuario
            <input type="text" name="username" required maxlength="40" pattern="[A-Za-z0-9._\-]+"
                   value="<?= e((string)$val('username')) ?>" data-testid="user-form-username"
                   <?= $isPrimary ? 'readonly' : '' ?>>
        </label>
        <div class="row">
            <label>Nombre
                <input type="text" name="first_name" required maxlength="80"
                       value="<?= e((string)$val('first_name')) ?>" data-testid="user-form-firstname">
            </label>
            <label>Apellidos
                <input type="text" name="last_name" required maxlength="120"
                       value="<?= e((string)$val('last_name')) ?>" data-testid="user-form-lastname">
            </label>
        </div>
        <div class="row">
            <label>Rol
                <select name="role" data-testid="user-form-role" <?= $isPrimary ? 'disabled' : '' ?>>
                    <?php foreach ($allowed_roles as $r): ?>
                        <option value="<?= e($r) ?>" <?= ((string)$val('role') === $r) ? 'selected' : '' ?>>
                            <?= e(role_label($r)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if ($isPrimary): ?><input type="hidden" name="role" value="<?= e(ROLE_ADMIN) ?>"><?php endif; ?>
            </label>
            <label>Barco
                <select name="boat_id" data-testid="user-form-boat">
                    <option value="">— sin asignar —</option>
                    <?php foreach ($boats as $b): ?>
                        <option value="<?= (int)$b['id'] ?>" <?= ((int)$val('boat_id', 0) === (int)$b['id']) ? 'selected' : '' ?>>
                            <?= e($b['name']) ?><?= (int)$b['is_active'] === 0 ? ' (inactivo)' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
        <?php if (!$editing): ?>
            <div class="row">
                <label>Contraseña
                    <input type="password" name="password" minlength="8" required data-testid="user-form-password">
                </label>
                <label>Repetir contraseña
                    <input type="password" name="password2" minlength="8" required data-testid="user-form-password2">
                </label>
            </div>
        <?php endif; ?>
        <label class="checkbox">
            <input type="checkbox" name="is_active" value="1"
                   <?= ((int)$val('is_active', 1) === 1) ? 'checked' : '' ?>
                   <?= $isPrimary ? 'disabled' : '' ?>
                   data-testid="user-form-active">
            <?php if ($isPrimary): ?><input type="hidden" name="is_active" value="1"><?php endif; ?>
            Activo
        </label>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary" data-testid="user-form-submit">
                <?= $editing ? 'Guardar cambios' : 'Crear usuario' ?>
            </button>
            <a class="btn btn-ghost" href="<?= e(url('/users')) ?>">Cancelar</a>
        </div>
    </form>

    <?php if ($editing): ?>
        <hr class="sep">
        <h2 class="section-title">Cambiar contraseña</h2>
        <form method="post" action="<?= e(url('/users/' . $user['id'] . '/password')) ?>" class="form" autocomplete="off">
            <?= csrf_field() ?>
            <div class="row">
                <label>Nueva contraseña
                    <input type="password" name="password" minlength="8" required data-testid="user-pw-1">
                </label>
                <label>Repetir contraseña
                    <input type="password" name="password2" minlength="8" required data-testid="user-pw-2">
                </label>
            </div>
            <button type="submit" class="btn btn-primary" data-testid="user-pw-submit">Cambiar contraseña</button>
        </form>
    <?php endif; ?>
</section>
