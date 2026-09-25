<?php
/** @var array $rows */ /** @var array $boats */ /** @var array $cats */
/** @var array $actor */ /** @var ?int $forcedBoat */
/** @var string $q */ /** @var string $cat */ /** @var string $sortBy */ /** @var string $sortDir */
/** @var int $page */ /** @var int $pages */ /** @var int $total */
$qs = function(array $over = []) use ($q, $cat, $sortBy, $sortDir, $page, $boat_id, $forcedBoat) {
    $params = ['q'=>$q, 'category_id'=>$cat, 'sort'=>$sortBy, 'dir'=>$sortDir, 'page'=>$page];
    if ($forcedBoat === null && $boat_id !== null) $params['boat_id'] = (string)$boat_id;
    foreach ($over as $k=>$v) $params[$k] = $v;
    $params = array_filter($params, fn($v) => $v !== '' && $v !== null);
    return $params ? '?' . http_build_query($params) : '';
};
$sortLink = function(string $col, string $label) use ($sortBy, $sortDir, $qs) {
    $dir = ($sortBy === $col && $sortDir === 'ASC') ? 'desc' : 'asc';
    $arrow = $sortBy === $col ? ($sortDir === 'ASC' ? ' ▲' : ' ▼') : '';
    return '<a href="' . e(url('/parts' . $qs(['sort'=>$col,'dir'=>$dir,'page'=>1]))) . '">' . e($label) . $arrow . '</a>';
};
$canCreate = in_array($actor['role'], [ROLE_ADMIN, ROLE_INSPECTOR, ROLE_CHIEF], true);
?>
<section class="card">
    <div class="card-head">
        <h1 class="title">Inventario</h1>
        <?php if ($canCreate): ?>
            <a class="btn btn-primary" href="<?= e(url('/parts/new')) ?>" data-testid="parts-new-btn">Nuevo repuesto</a>
        <?php endif; ?>
    </div>

    <form method="get" action="<?= e(url('/parts')) ?>" class="form-inline filters">
        <label class="grow">Buscar
            <input type="text" name="q" value="<?= e($q) ?>" placeholder="nombre, referencia, categoría o ubicación" data-testid="parts-search">
        </label>
        <label>Categoría
            <select name="category_id" data-testid="parts-filter-cat">
                <option value="">— todas —</option>
                <?php foreach ($cats as $c): ?>
                    <option value="<?= (int)$c['id'] ?>" <?= ((string)$cat === (string)$c['id']) ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <?php if ($forcedBoat === null): ?>
            <label>Barco
                <select name="boat_id" data-testid="parts-filter-boat">
                    <option value="">— todos —</option>
                    <?php foreach ($boats as $b): ?>
                        <option value="<?= (int)$b['id'] ?>" <?= ((int)($boat_id ?? 0) === (int)$b['id']) ? 'selected' : '' ?>>
                            <?= e($b['name']) ?><?= (int)$b['is_active']===0 ? ' (inactivo)':'' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        <?php endif; ?>
        <button class="btn btn-primary" data-testid="parts-filter-apply">Aplicar</button>
        <?php if ($q !== '' || $cat !== '' || ($forcedBoat === null && $boat_id !== null)): ?>
            <a class="btn btn-ghost" href="<?= e(url('/parts')) ?>">Limpiar</a>
        <?php endif; ?>
    </form>

    <p class="hint"><?= (int)$total ?> resultado(s) · página <?= (int)$page ?> / <?= (int)$pages ?></p>

    <div class="table-wrap">
        <table class="table" data-testid="parts-table">
            <thead>
                <tr>
                    <th>Foto</th>
                    <th><?= $sortLink('name','Nombre') ?></th>
                    <th><?= $sortLink('reference','Referencia') ?></th>
                    <th><?= $sortLink('category','Categoría') ?></th>
                    <th><?= $sortLink('location','Ubicación') ?></th>
                    <th>Barco</th>
                    <th><?= $sortLink('quantity','Cantidad') ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr data-testid="part-row-<?= (int)$r['id'] ?>" onclick="window.location.href='<?= e(url('/parts/'.$r['id'])) ?>'" style="cursor:pointer">
                    <td><?php if (!empty($r['photo_path'])): ?>
                        <img class="thumb" src="<?= e(url('/parts/' . $r['id'] . '/photo')) ?>" alt="">
                    <?php else: ?><span class="thumb thumb-empty">—</span><?php endif; ?></td>
                    <td><a href="<?= e(url('/parts/'.$r['id'])) ?>" data-testid="part-name-<?= (int)$r['id'] ?>"><?= e($r['name']) ?></a></td>
                    <td><?= e($r['reference'] ?: '—') ?></td>
                    <td><?= e($r['category_name'] ?? '—') ?></td>
                    <td><?= e($r['location']) ?></td>
                    <td><?= e($r['boat_name']) ?></td>
                    <td data-testid="part-qty-<?= (int)$r['id'] ?>"><?= (int)$r['quantity'] ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?>
                <tr><td colspan="7" class="empty">Sin resultados.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($pages > 1): ?>
        <div class="pagination" data-testid="pagination">
            <?php if ($page > 1): ?><a class="btn btn-sm" href="<?= e(url('/parts' . $qs(['page'=>$page-1]))) ?>">← Anterior</a><?php endif; ?>
            <span class="hint">Página <?= (int)$page ?> de <?= (int)$pages ?></span>
            <?php if ($page < $pages): ?><a class="btn btn-sm" href="<?= e(url('/parts' . $qs(['page'=>$page+1]))) ?>">Siguiente →</a><?php endif; ?>
        </div>
    <?php endif; ?>
</section>
