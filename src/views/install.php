<?php /** @var array $checks */ /** @var array $errors */ ?>
<section class="card card-narrow">
    <h1 class="title">Configuración inicial</h1>
    <p class="subtitle">Instalar por primera vez la aplicación de inventario.</p>

    <div class="checks">
        <?php foreach ($checks as $label => $ok): ?>
            <div class="check <?= $ok ? 'ok' : 'ko' ?>">
                <span class="check-dot"></span>
                <span><?= e($label) ?></span>
                <span class="check-state"><?= $ok ? 'OK' : 'Fallo' ?></span>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-error">
            <?php foreach ($errors as $err): ?>
                <div><?= e($err) ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <h2 class="section-title">Nueva instalación</h2>
    <p class="hint">Crea el primer usuario. Será el <strong>Administrador principal</strong> (no podrá ser eliminado ni desactivado).</p>

    <form method="post" action="<?= e(url('/install')) ?>" class="form" autocomplete="off">
        <?= csrf_field() ?>
        <div class="row">
            <label>
                Nombre
                <input type="text" name="first_name" required maxlength="80"
                       value="<?= e($_POST['first_name'] ?? '') ?>"
                       data-testid="install-firstname-input">
            </label>
            <label>
                Apellidos
                <input type="text" name="last_name" required maxlength="120"
                       value="<?= e($_POST['last_name'] ?? '') ?>"
                       data-testid="install-lastname-input">
            </label>
        </div>
        <label>
            Nombre de usuario
            <input type="text" name="username" required maxlength="40"
                   pattern="[A-Za-z0-9._\-]+"
                   value="<?= e($_POST['username'] ?? '') ?>"
                   data-testid="install-username-input">
            <small class="hint">Letras, números, punto, guion o guion bajo.</small>
        </label>
        <div class="row">
            <label>
                Contraseña
                <input type="password" name="password" required minlength="8"
                       data-testid="install-password-input">
            </label>
            <label>
                Repetir contraseña
                <input type="password" name="password2" required minlength="8"
                       data-testid="install-password2-input">
            </label>
        </div>
        <button type="submit" class="btn btn-primary" data-testid="install-submit-btn">
            Crear administrador e inicializar
        </button>
    </form>
</section>
