<?php
/** @var array $dirs */ /** @var ?array $lastAuto */ /** @var ?float $freePct */
$fmtBytes = function($b) {
    if (!$b) return '—';
    if ($b < 1024) return $b . ' B';
    if ($b < 1024*1024) return round($b/1024, 1) . ' kB';
    if ($b < 1024*1024*1024) return round($b/1024/1024, 2) . ' MB';
    return round($b/1024/1024/1024, 2) . ' GB';
};
$low = $freePct !== null && $freePct < $warn_pct;
?>
<section class="card" data-testid="status-page">
    <h1 class="title">Estado del sistema</h1>

    <?php if ($low): ?>
        <div class="alert alert-warn" data-testid="status-disk-low">
            <strong>Aviso:</strong> queda solo <?= number_format($freePct, 1) ?>% de espacio libre (umbral <?= (int)$warn_pct ?>%). La aplicación sigue operativa pero conviene liberar espacio o retirar backups antiguos.
        </div>
    <?php endif; ?>

    <div class="userinfo">
        <div class="userinfo-row"><span class="label">Versión aplicación</span><span class="value"><?= e($app_ver) ?></span></div>
        <div class="userinfo-row"><span class="label">Versión esquema</span><span class="value"><?= (int)$sch_ver ?></span></div>
        <div class="userinfo-row"><span class="label">SQLite integrity_check</span><span class="value"><?= e($sqliteOk) ?></span></div>
        <div class="userinfo-row"><span class="label">Tamaño BD</span><span class="value"><?= e($fmtBytes($db_size)) ?></span></div>
        <div class="userinfo-row"><span class="label">Espacio libre</span>
            <span class="value" data-testid="status-free">
                <?= e($fmtBytes($free)) ?> de <?= e($fmtBytes($total)) ?>
                <?php if ($freePct !== null): ?>· <?= number_format($freePct, 1) ?>%<?php endif; ?>
            </span>
        </div>
        <div class="userinfo-row"><span class="label">Último auto-backup</span>
            <span class="value">
                <?php if ($lastAuto): ?><code><?= e($lastAuto['name']) ?></code> · <?= e(gmdate('Y-m-d H:i', $lastAuto['mtime'])) ?> UTC<?php else: ?>—<?php endif; ?>
            </span>
        </div>
        <div class="userinfo-row"><span class="label">Estado backup</span>
            <span class="value">
                <?php if ($lastFail): ?><span class="dot dot-ko"></span> Último intento falló: <?= e($lastFail) ?>
                <?php elseif ($lastRun): ?><span class="dot dot-ok"></span> Correcto (<?= e(gmdate('Y-m-d H:i', $lastRun)) ?> UTC)
                <?php else: ?>Sin ejecuciones<?php endif; ?>
            </span>
        </div>
    </div>

    <h2 class="section-title">Directorios</h2>
    <div class="checks">
        <?php foreach ($dirs as $label => $ok): ?>
            <div class="check <?= $ok ? 'ok' : 'ko' ?>">
                <span class="check-dot"></span>
                <span><code><?= e($label) ?></code></span>
                <span class="check-state"><?= $ok ? 'OK' : 'Falta o sin permisos' ?></span>
            </div>
        <?php endforeach; ?>
    </div>
</section>
