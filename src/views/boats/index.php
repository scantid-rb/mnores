<?php /** @var array $rows */ /** @var array $actor */ ?>
<section class="card">
    <div class="card-head">
        <h1 class="title">Barcos</h1>
        <?php if (can_manage_boats($actor)): ?>
            <a class="btn btn-primary" href="<?= e(url('/boats/new')) ?>" data-testid="boats-new-btn">Nuevo barco</a>
        <?php endif; ?>
    </div>
    <div class="table-wrap">
        <table class="table" data-testid="boats-table">
            <thead><tr><th>Nombre</th><th>Matrícula</th><th>Usuarios</th><th>Estado</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr data-testid="boat-row-<?= (int)$r['id'] ?>">
                    <td><?= e($r['name']) ?></td>
                    <td><?= e($r['registration']) ?></td>
                    <td><?= (int)$r['users_count'] ?></td>
                    <td><?= (int)$r['is_active'] === 1 ? '<span class="dot dot-ok"></span> Activo' : '<span class="dot dot-ko"></span> Inactivo' ?></td>
                    <td class="row-actions">
                        <?php if (can_manage_boats($actor)): ?>
                            <a class="btn btn-sm" href="<?= e(url('/boats/' . $r['id'] . '/edit')) ?>" data-testid="boat-edit-<?= (int)$r['id'] ?>">Editar</a>
                            <form method="post" action="<?= e(url('/boats/' . $r['id'] . '/toggle')) ?>" class="inline">
                                <?= csrf_field() ?>
                                <button class="btn btn-sm btn-ghost" data-testid="boat-toggle-<?= (int)$r['id'] ?>">
                                    <?= (int)$r['is_active'] === 1 ? 'Desactivar' : 'Activar' ?>
                                </button>
                            </form>
                        <?php endif; ?>
                        <?php if (can_delete_boat($actor)): ?>
                            <form method="post" action="<?= e(url('/boats/' . $r['id'] . '/delete')) ?>" class="inline"
                                  onsubmit="return confirm('¿Eliminar este barco?');">
                                <?= csrf_field() ?>
                                <button class="btn btn-sm btn-danger" data-testid="boat-delete-<?= (int)$r['id'] ?>">Eliminar</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?>
                <tr><td colspan="5" class="empty">Sin barcos registrados.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
