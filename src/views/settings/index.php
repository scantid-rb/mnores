<?php /** @var array $values */ /** @var array $errors */ ?>
<section class="card card-narrow" data-testid="settings-page">
    <h1 class="title">Configuración</h1>
    <p class="subtitle">Cambios aplicados inmediatamente. Los valores se guardan en la base de datos.</p>
    <?php if ($errors): ?><div class="alert alert-error"><?php foreach ($errors as $er) echo '<div>'.e($er).'</div>'; ?></div><?php endif; ?>

    <form method="post" action="<?= e(url('/settings')) ?>" class="form" autocomplete="off">
        <?= csrf_field() ?>
        <h2 class="section-title">Identidad</h2>
        <label>Nombre de la aplicación
            <input type="text" name="app_name" required maxlength="80" value="<?= e($values['app_name']) ?>" data-testid="set-app-name">
        </label>
        <label>Título / marca visible
            <input type="text" name="app_title" required maxlength="80" value="<?= e($values['app_title']) ?>" data-testid="set-app-title">
        </label>
        <label>Empresa u organización
            <input type="text" name="company_name" maxlength="120" value="<?= e($values['company_name']) ?>" data-testid="set-company">
        </label>

        <h2 class="section-title">Copias de seguridad</h2>
        <div class="row">
            <label>Intervalo backup automático (días)
                <input type="number" min="1" max="365" name="backup_interval_days" value="<?= e($values['backup_interval_days']) ?>" data-testid="set-bkp-interval">
            </label>
            <label>Retención backups de seguridad (días)
                <input type="number" min="1" max="365" name="backup_retention_days" value="<?= e($values['backup_retention_days']) ?>" data-testid="set-bkp-retention">
            </label>
        </div>

        <h2 class="section-title">Auditoría y espacio</h2>
        <div class="row">
            <label>Conservación auditoría (días)
                <input type="number" min="1" max="3650" name="audit_retention_days" value="<?= e($values['audit_retention_days']) ?>" data-testid="set-audit-retention">
            </label>
            <label>Umbral aviso espacio libre (%)
                <input type="number" min="0" max="90" name="disk_warning_percent" value="<?= e($values['disk_warning_percent']) ?>" data-testid="set-disk-warn">
            </label>
        </div>

        <div class="form-actions">
            <button class="btn btn-primary" data-testid="settings-submit">Guardar</button>
        </div>
    </form>
</section>
