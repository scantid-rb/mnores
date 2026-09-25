<?php /** @var array $rows */ /** @var array $actor */ /** @var array $errors */ /** @var array $input */ ?>
<section class="card">
    <div class="card-head">
        <h1 class="title">Categorías</h1>
    </div>

    <?php if (can_manage_categories($actor)): ?>
        <?php if ($errors): ?><div class="alert alert-error"><?php foreach ($errors as $er) echo '<div>'.e($er).'</div>'; ?></div><?php endif; ?>
        <form method="post" action="<?= e(url('/categories')) ?>" class="form form-inline">
            <?= csrf_field() ?>
            <label class="grow">Nueva categoría
                <input type="text" name="name" required maxlength="80" value="<?= e((string)($input['name'] ?? '')) ?>" data-testid="cat-new-input">
            </label>
            <button class="btn btn-primary" data-testid="cat-new-submit">Añadir</button>
        </form>
    <?php endif; ?>

    <div class="table-wrap">
        <table class="table" data-testid="categories-table">
            <thead><tr><th>Nombre</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): $sys = (int)$r['is_system'] === 1; ?>
                <tr data-testid="cat-row-<?= (int)$r['id'] ?>">
                    <td>
                        <?= e($r['name']) ?>
                        <?php if ($sys): ?><span class="tag">sistema</span><?php endif; ?>
                    </td>
                    <td class="row-actions">
                        <?php if (!$sys && can_manage_categories($actor)): ?>
                            <form method="post" action="<?= e(url('/categories/' . $r['id'] . '/rename')) ?>" class="form-inline">
                                <?= csrf_field() ?>
                                <input type="text" name="name" required maxlength="80" value="<?= e($r['name']) ?>" data-testid="cat-rename-input-<?= (int)$r['id'] ?>">
                                <button class="btn btn-sm" data-testid="cat-rename-btn-<?= (int)$r['id'] ?>">Renombrar</button>
                            </form>
                            <form method="post" action="<?= e(url('/categories/' . $r['id'] . '/delete')) ?>" class="inline"
                                  onsubmit="return confirm('¿Eliminar esta categoría?');">
                                <?= csrf_field() ?>
                                <button class="btn btn-sm btn-danger" data-testid="cat-delete-btn-<?= (int)$r['id'] ?>">Eliminar</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
