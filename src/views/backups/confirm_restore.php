<?php /** @var string $name */ /** @var array $verify */ /** @var array $actor */ ?>
<section class="card card-narrow">
    <h1 class="title">Confirmar restauración</h1>
    <p class="subtitle">Vas a restaurar la aplicación desde <code data-testid="restore-name"><?= e($name) ?></code>.</p>

    <?php if (!$verify['ok']): ?>
        <div class="alert alert-error" data-testid="restore-error"><strong>Backup no válido.</strong><br><?= e($verify['error']) ?></div>
        <a class="btn btn-ghost" href="<?= e(url('/backups')) ?>">Volver</a>
    <?php else: ?>
        <div class="alert alert-warn"><strong>Atención.</strong> Se reemplazará la base de datos y las fotografías actuales.
            Se creará automáticamente un backup de seguridad del estado actual (<code>backup_seguridad_*.zip</code>).</div>

        <dl class="kv">
            <dt>Versión app</dt><dd><?= e((string)($verify['manifest']['app_version'] ?? '?')) ?></dd>
            <dt>Versión esquema</dt><dd><?= (int)($verify['manifest']['schema_version'] ?? 0) ?></dd>
            <dt>Fecha (UTC)</dt><dd><?= e((string)($verify['manifest']['created_at_utc'] ?? '?')) ?></dd>
            <dt>SHA-256 SQLite</dt><dd><code><?= e(substr((string)($verify['manifest']['sqlite_sha256'] ?? ''), 0, 24)) ?>…</code></dd>
            <dt>Fotos esperadas / incluidas</dt><dd><?= (int)($verify['manifest']['photos_expected'] ?? 0) ?> / <?= (int)($verify['manifest']['photos_included'] ?? 0) ?></dd>
        </dl>
        <?php if (!empty($verify['notes'])): ?>
            <div class="alert alert-warn"><?php foreach ($verify['notes'] as $n) echo '<div>' . e($n) . '</div>'; ?></div>
        <?php endif; ?>

        <?php if ($actor['role'] !== ROLE_ADMIN): ?>
            <div class="alert alert-error">Solo un Administrador puede ejecutar la restauración.</div>
            <a class="btn btn-ghost" href="<?= e(url('/backups')) ?>">Volver</a>
        <?php else: ?>
            <form method="post" action="<?= e(url('/backups/' . rawurlencode($name) . '/restore')) ?>" onsubmit="return confirm('¿Confirmar restauración? Se cerrará tu sesión.');">
                <?= csrf_field() ?>
                <div class="form-actions">
                    <button class="btn btn-danger" data-testid="restore-confirm">Restaurar ahora</button>
                    <a class="btn btn-ghost" href="<?= e(url('/backups')) ?>">Cancelar</a>
                </div>
            </form>
        <?php endif; ?>
    <?php endif; ?>
</section>
