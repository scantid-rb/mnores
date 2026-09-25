<?php /** @var array $items */ /** @var array $actor */ /** @var int $last */ /** @var string $fail */ /** @var int $interval_days */
$fmt_size = function(int $b): string {
    if ($b < 1024) return $b . ' B';
    if ($b < 1024*1024) return round($b/1024, 1) . ' kB';
    return round($b/1024/1024, 2) . ' MB';
};
$labels = ['manual'=>'Datos (manual)','auto'=>'Datos (automático)','security'=>'Seguridad','app'=>'Aplicación completa','otros'=>'Otros'];
?>
<section class="card" data-testid="backups-page">
    <h1 class="title">Backups</h1>
    <p class="subtitle">Distingue entre un <strong>backup de datos</strong> (BD + fotos) y un <strong>backup completo de aplicación</strong> (código + datos + docs de despliegue).</p>

    <div class="backup-actions">
        <form method="post" action="<?= e(url('/backups/create')) ?>" class="inline">
            <?= csrf_field() ?>
            <button class="btn btn-primary" data-testid="backup-create-btn">Crear backup de datos</button>
        </form>
        <form method="post" action="<?= e(url('/backups/create-app')) ?>" class="inline">
            <?= csrf_field() ?>
            <button class="btn" data-testid="backup-create-app-btn">Crear backup completo de aplicación</button>
        </form>
    </div>

    <div class="userinfo" style="margin:18px 0">
        <div class="userinfo-row"><span class="label">Auto-backup (datos)</span>
            <span class="value">
                Cada <?= (int)$interval_days ?> días · último: <?= $last ? e(gmdate('Y-m-d H:i', $last)) . ' UTC' : '—' ?>
                <?php if ($fail): ?><span class="tag" style="border-color:rgba(239,107,107,.5);color:#ffb4b4;background:rgba(239,107,107,.1)">último fallo: <?= e($fail) ?></span><?php endif; ?>
            </span>
        </div>
    </div>

    <h2 class="section-title">Backups disponibles en el servidor</h2>
    <p class="hint">Descarga cualquier backup para guardarlo localmente. Elimina del servidor los que ya no necesites para liberar espacio.</p>
    <div class="table-wrap">
        <table class="table" data-testid="backups-table">
            <thead><tr><th>Nombre</th><th>Tipo</th><th>Tamaño</th><th>Fecha (UTC)</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($items as $it): ?>
                <tr data-testid="backup-row-<?= e($it['name']) ?>">
                    <td><code><?= e($it['name']) ?></code></td>
                    <td><span class="pill"><?= e($labels[$it['type']] ?? $it['type']) ?></span></td>
                    <td><?= e($fmt_size($it['size'])) ?></td>
                    <td><?= e(gmdate('Y-m-d H:i:s', $it['mtime'])) ?></td>
                    <td class="row-actions">
                        <a class="btn btn-sm" href="<?= e(url('/backups/' . rawurlencode($it['name']) . '/download')) ?>" data-testid="backup-download">Descargar</a>
                        <?php if ($actor['role'] === ROLE_ADMIN && $it['type'] !== 'app'): ?>
                            <a class="btn btn-sm btn-ghost" href="<?= e(url('/backups/' . rawurlencode($it['name']) . '/restore')) ?>" data-testid="backup-restore">Restaurar</a>
                        <?php endif; ?>
                        <form method="post" action="<?= e(url('/backups/' . rawurlencode($it['name']) . '/delete')) ?>" class="inline" onsubmit="return confirm('¿Eliminar este backup del servidor?');">
                            <?= csrf_field() ?>
                            <button class="btn btn-sm btn-danger" data-testid="backup-delete">Eliminar</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$items): ?><tr><td colspan="5" class="empty">Sin backups todavía.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>

    <hr class="sep">
    <h2 class="section-title">Restaurar desde archivo local</h2>
    <?php if ($actor['role'] === ROLE_ADMIN): ?>
        <p class="hint">Selecciona un <code>.zip</code> descargado previamente (backup de datos o backup completo compatible). Se validará antes de aplicar y se generará automáticamente un <code>backup_seguridad_*.zip</code> del estado actual.</p>
        <form method="post" action="<?= e(url('/backups/restore-upload')) ?>" enctype="multipart/form-data" class="form-inline" onsubmit="return confirm('¿Restaurar desde este archivo? Se sustituirá el estado actual y se cerrará tu sesión.');">
            <?= csrf_field() ?>
            <input type="file" name="backup" accept=".zip,application/zip" required data-testid="restore-upload-file">
            <button class="btn btn-danger" data-testid="restore-upload-btn">Validar y restaurar</button>
        </form>
    <?php else: ?>
        <p class="hint">Solo un Administrador puede restaurar desde archivo local.</p>
    <?php endif; ?>

    <hr class="sep">
    <h2 class="section-title">Exportar inventario a Excel</h2>
    <?php $boats = db()->query('SELECT id, name, is_active FROM boats ORDER BY name')->fetchAll(); ?>
    <div class="row-actions">
        <?php foreach ($boats as $b): ?>
            <a class="btn" href="<?= e(url('/export/parts/' . (int)$b['id'])) ?>" data-testid="export-boat-<?= (int)$b['id'] ?>">
                Exportar «<?= e($b['name']) ?>»<?= (int)$b['is_active']===0 ? ' (inactivo)':'' ?>
            </a>
        <?php endforeach; ?>
    </div>
</section>
