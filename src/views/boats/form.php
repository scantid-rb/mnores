<?php /** @var ?array $boat */ /** @var array $errors */
$input = $input ?? [];
$val = function(string $k, $default = '') use ($input, $boat) {
    if (array_key_exists($k, $input)) return $input[$k];
    if ($boat && array_key_exists($k, $boat)) return $boat[$k];
    return $default;
};
$editing = $boat !== null;
?>
<section class="card card-narrow">
    <h1 class="title"><?= $editing ? 'Editar barco' : 'Nuevo barco' ?></h1>
    <?php if ($errors): ?><div class="alert alert-error"><?php foreach ($errors as $er) echo '<div>'.e($er).'</div>'; ?></div><?php endif; ?>
    <form method="post" action="<?= e(url($editing ? '/boats/' . $boat['id'] . '/edit' : '/boats')) ?>" class="form" autocomplete="off">
        <?= csrf_field() ?>
        <label>Nombre
            <input type="text" name="name" required maxlength="120" value="<?= e((string)$val('name')) ?>" data-testid="boat-form-name">
        </label>
        <label>Matrícula
            <input type="text" name="registration" required maxlength="60" value="<?= e((string)$val('registration')) ?>" data-testid="boat-form-reg">
        </label>
        <label class="checkbox">
            <input type="checkbox" name="is_active" value="1" <?= ((int)$val('is_active', 1) === 1) ? 'checked' : '' ?> data-testid="boat-form-active">
            Activo
        </label>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary" data-testid="boat-form-submit"><?= $editing ? 'Guardar cambios' : 'Crear barco' ?></button>
            <a class="btn btn-ghost" href="<?= e(url('/boats')) ?>">Cancelar</a>
        </div>
    </form>
</section>
