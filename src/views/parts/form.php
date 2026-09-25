<?php
/** @var ?array $part */ /** @var array $actor */ /** @var array $boats */ /** @var array $cats */
/** @var array $errors */ /** @var ?int $forcedBoat */ /** @var ?string $warning */
$input = $input ?? [];
$val = function(string $k, $default = '') use ($input, $part) {
    if (array_key_exists($k, $input)) return $input[$k];
    if ($part && array_key_exists($k, $part)) return $part[$k];
    return $default;
};
$editing = $part !== null;
$defaultBoat = $forcedBoat !== null ? $forcedBoat : ($part['boat_id'] ?? '');
?>
<section class="card card-narrow">
    <h1 class="title"><?= $editing ? 'Editar repuesto' : 'Nuevo repuesto' ?></h1>
    <?php if ($errors): ?><div class="alert alert-error" data-testid="form-errors"><?php foreach ($errors as $er) echo '<div>'.e($er).'</div>'; ?></div><?php endif; ?>
    <?php if ($warning): ?><div class="alert alert-warn" data-testid="dup-warning"><?= e($warning) ?></div><?php endif; ?>

    <form method="post" action="<?= e(url($editing ? '/parts/' . $part['id'] . '/edit' : '/parts')) ?>" class="form" autocomplete="off">
        <?= csrf_field() ?>
        <?php if ($editing): ?><input type="hidden" name="updated_at" value="<?= e($part['updated_at']) ?>"><?php endif; ?>
        <div class="row">
            <label>Barco
                <?php $bid = (int)$val('boat_id', (int)$defaultBoat); ?>
                <select name="boat_id" data-testid="part-form-boat" <?= $forcedBoat !== null ? 'disabled' : '' ?>>
                    <?php foreach ($boats as $b): if (!(int)$b['is_active'] && (int)$b['id'] !== $bid) continue; ?>
                        <option value="<?= (int)$b['id'] ?>" <?= $bid === (int)$b['id'] ? 'selected' : '' ?>><?= e($b['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if ($forcedBoat !== null): ?><input type="hidden" name="boat_id" value="<?= (int)$forcedBoat ?>"><?php endif; ?>
            </label>
            <label>Categoría
                <?php $cid = (int)$val('category_id', 0); ?>
                <select name="category_id" data-testid="part-form-cat">
                    <option value="">— seleccionar —</option>
                    <?php foreach ($cats as $c): ?>
                        <option value="<?= (int)$c['id'] ?>" <?= $cid === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
        <label>Nombre
            <input type="text" name="name" required maxlength="160" value="<?= e((string)$val('name')) ?>" data-testid="part-form-name">
        </label>
        <div class="row">
            <label>Referencia (opcional)
                <input type="text" name="reference" maxlength="80" value="<?= e((string)$val('reference')) ?>" data-testid="part-form-ref">
            </label>
            <label>Ubicación
                <input type="text" name="location" required maxlength="120" value="<?= e((string)$val('location')) ?>" data-testid="part-form-loc">
            </label>
        </div>
        <div class="row">
            <label>Cantidad
                <input type="number" min="0" step="1" name="quantity" value="<?= e((string)$val('quantity', 0)) ?>" data-testid="part-form-qty">
            </label>
        </div>
        <label>Notas
            <textarea name="notes" rows="3" maxlength="2000" data-testid="part-form-notes"><?= e((string)$val('notes')) ?></textarea>
        </label>
        <?php if ($warning): ?>
            <label class="checkbox">
                <input type="checkbox" name="confirm_duplicate" value="1" data-testid="part-form-confirm-dup">
                Confirmo guardar aunque existan repuestos similares.
            </label>
        <?php endif; ?>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary" data-testid="part-form-submit">
                <?= $editing ? 'Guardar cambios' : 'Crear repuesto' ?>
            </button>
            <a class="btn btn-ghost" href="<?= e(url($editing ? '/parts/' . $part['id'] : '/parts')) ?>">Cancelar</a>
        </div>
    </form>
</section>
