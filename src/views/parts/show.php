<?php /** @var array $part */ /** @var array $actor */
$canEdit  = parts_can_edit_all($actor, $part);
$canQty   = parts_can_edit_quantity($actor, $part);
$canDel   = parts_can_delete($actor, $part);
$canPhoto = parts_can_manage_photo($actor, $part);
$hasPhoto = !empty($part['photo_path']) && is_file(photo_path_for((int)$part['id']));
?>
<section class="card" data-testid="part-page">
    <div class="card-head">
        <div>
            <h1 class="title" data-testid="part-name"><?= e($part['name']) ?></h1>
            <p class="subtitle"><span class="pill"><?= e($part['category_name'] ?? '—') ?></span> · <?= e($part['boat_name']) ?> · ID <?= (int)$part['id'] ?></p>
        </div>
        <div class="row-actions">
            <a class="btn btn-ghost" href="<?= e(url('/parts')) ?>">← Volver</a>
            <?php if ($canEdit): ?><a class="btn" href="<?= e(url('/parts/'.$part['id'].'/edit')) ?>" data-testid="part-edit-btn">Editar</a><?php endif; ?>
        </div>
    </div>

    <div class="part-layout">
        <div class="part-photo" data-testid="part-photo-box">
            <?php if ($hasPhoto): ?>
                <img src="<?= e(url('/parts/'.$part['id'].'/photo?v='.urlencode($part['updated_at']))) ?>" alt="Fotografía del repuesto" data-testid="part-photo">
            <?php else: ?>
                <div class="photo-empty">Sin fotografía</div>
            <?php endif; ?>
            <?php if ($canPhoto): ?>
                <form method="post" action="<?= e(url('/parts/'.$part['id'].'/photo')) ?>" enctype="multipart/form-data" class="photo-form">
                    <?= csrf_field() ?>
                    <input type="file" name="photo" accept="image/jpeg,image/png,image/gif,image/webp" required data-testid="photo-file">
                    <button class="btn btn-sm btn-primary" data-testid="photo-submit"><?= $hasPhoto ? 'Sustituir' : 'Añadir' ?></button>
                </form>
                <?php if ($hasPhoto): ?>
                    <form method="post" action="<?= e(url('/parts/'.$part['id'].'/photo/delete')) ?>" onsubmit="return confirm('¿Eliminar la fotografía?');">
                        <?= csrf_field() ?>
                        <button class="btn btn-sm btn-danger" data-testid="photo-delete">Eliminar fotografía</button>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <div class="part-body">
            <dl class="kv">
                <dt>Referencia</dt><dd data-testid="part-ref"><?= e($part['reference'] ?: '—') ?></dd>
                <dt>Ubicación</dt><dd data-testid="part-loc"><?= e($part['location']) ?></dd>
                <dt>Cantidad</dt><dd data-testid="part-qty"><strong><?= (int)$part['quantity'] ?></strong></dd>
                <dt>Última modificación</dt><dd><?= e($part['updated_at']) ?></dd>
                <?php if ($part['notes'] !== ''): ?>
                <dt>Notas</dt><dd class="notes" data-testid="part-notes"><?= nl2br(e($part['notes'])) ?></dd>
                <?php endif; ?>
            </dl>

            <?php if ($canQty): ?>
                <hr class="sep">
                <h2 class="section-title">Actualizar cantidad</h2>
                <form method="post" action="<?= e(url('/parts/'.$part['id'].'/quantity')) ?>" class="form form-inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="updated_at" value="<?= e($part['updated_at']) ?>">
                    <label>Nueva cantidad
                        <input type="number" name="quantity" min="0" step="1" value="<?= (int)$part['quantity'] ?>" data-testid="qty-input">
                    </label>
                    <button class="btn btn-primary" data-testid="qty-submit">Actualizar</button>
                </form>
            <?php endif; ?>

            <?php if ($canDel): ?>
                <hr class="sep">
                <form method="post" action="<?= e(url('/parts/'.$part['id'].'/delete')) ?>"
                      onsubmit="return confirm('¿Eliminar el repuesto ID <?= (int)$part['id'] ?> «<?= e(addslashes($part['name'])) ?>» (ref. <?= e(addslashes($part['reference'] ?: '—')) ?>)?');">
                    <?= csrf_field() ?>
                    <button class="btn btn-danger" data-testid="part-delete-btn">Eliminar repuesto</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</section>
