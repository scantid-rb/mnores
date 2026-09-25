<section class="card card-narrow">
    <h1 class="title">Iniciar sesión</h1>
    <p class="subtitle">Inventario de repuestos para barcos</p>

    <form method="post" action="<?= e(url('/login')) ?>" class="form" autocomplete="off">
        <?= csrf_field() ?>
        <label>
            Usuario
            <input type="text" name="username" required maxlength="40"
                   value="<?= e($_POST['username'] ?? '') ?>"
                   autofocus
                   data-testid="login-username-input">
        </label>
        <label>
            Contraseña
            <input type="password" name="password" required
                   data-testid="login-password-input">
        </label>
        <button type="submit" class="btn btn-primary" data-testid="login-submit-btn">
            Entrar
        </button>
    </form>
</section>
