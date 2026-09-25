<?php
/** @var array $rows */ /** @var string $op */ /** @var string $type */ /** @var string $user */
/** @var int $page */ /** @var int $pages */ /** @var int $total */ /** @var array $types */
$qs = function(array $over = []) use ($op, $type, $user, $page) {
    $p = array_filter(['op'=>$op,'type'=>$type,'user'=>$user,'page'=>$page] + $over, fn($v)=>$v!=='' && $v!==null);
    foreach ($over as $k=>$v) if ($v==='' || $v===null) unset($p[$k]);
    return $p ? '?' . http_build_query($p) : '';
};
$fmtJson = function(?string $s): string {
    if (!$s) return '';
    $d = json_decode($s, true);
    if (!is_array($d)) return $s;
    $parts = [];
    foreach ($d as $k => $v) {
        if (is_scalar($v) || $v === null) $parts[] = $k . '=' . (is_bool($v) ? ($v?'true':'false') : (string)$v);
        else $parts[] = $k . '=' . json_encode($v, JSON_UNESCAPED_UNICODE);
    }
    return implode(' · ', $parts);
};
?>
<section class="card" data-testid="audit-page">
    <div class="card-head">
        <h1 class="title">Auditoría</h1>
        <span class="hint"><?= (int)$total ?> registro(s)</span>
    </div>

    <form method="get" action="<?= e(url('/audit')) ?>" class="form-inline filters">
        <label>Operación
            <input type="text" name="op" value="<?= e($op) ?>" placeholder="ej. user.create" data-testid="audit-filter-op">
        </label>
        <label>Tipo de objeto
            <select name="type" data-testid="audit-filter-type">
                <option value="">— todos —</option>
                <?php foreach ($types as $t): ?>
                    <option value="<?= e($t) ?>" <?= $type===$t?'selected':'' ?>><?= e($t) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Usuario
            <input type="text" name="user" value="<?= e($user) ?>" placeholder="username" data-testid="audit-filter-user">
        </label>
        <button class="btn btn-primary" data-testid="audit-filter-apply">Aplicar</button>
        <?php if ($op||$type||$user): ?><a class="btn btn-ghost" href="<?= e(url('/audit')) ?>">Limpiar</a><?php endif; ?>
    </form>

    <div class="table-wrap">
        <table class="table" data-testid="audit-table">
            <thead><tr>
                <th>Fecha (UTC)</th><th>Usuario</th><th>Operación</th><th>Objeto</th><th>ID</th><th>Barco</th><th>Cambios</th>
            </tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr data-testid="audit-row-<?= (int)$r['id'] ?>">
                    <td><?= e($r['at_utc']) ?></td>
                    <td><?= e($r['actor_username'] ?? '—') ?></td>
                    <td><code><?= e($r['operation']) ?></code></td>
                    <td><?= e($r['object_type']) ?></td>
                    <td><?= $r['object_id'] !== null ? (int)$r['object_id'] : '—' ?></td>
                    <td><?= $r['boat_id']   !== null ? (int)$r['boat_id']   : '—' ?></td>
                    <td class="audit-changes">
                        <?php if ($r['old_data']): ?><div><strong>antes:</strong> <?= e($fmtJson($r['old_data'])) ?></div><?php endif; ?>
                        <?php if ($r['new_data']): ?><div><strong>después:</strong> <?= e($fmtJson($r['new_data'])) ?></div><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?><tr><td colspan="7" class="empty">Sin registros.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($pages > 1): ?>
        <div class="pagination">
            <?php if ($page > 1): ?><a class="btn btn-sm" href="<?= e(url('/audit' . $qs(['page'=>$page-1]))) ?>">← Anterior</a><?php endif; ?>
            <span class="hint">Página <?= (int)$page ?> de <?= (int)$pages ?></span>
            <?php if ($page < $pages): ?><a class="btn btn-sm" href="<?= e(url('/audit' . $qs(['page'=>$page+1]))) ?>">Siguiente →</a><?php endif; ?>
        </div>
    <?php endif; ?>
</section>
