<?php /** @var array $rows */ /** @var array $actor */ /** @var array $allowed_roles */ ?>
<section class="card">
    <div class="card-head">
        <h1 class="title">Usuarios</h1>
        <a class="btn btn-primary" href="<?= e(url('/users/new')) ?>" data-testid="users-new-btn">Nuevo usuario</a>
    </div>
    <div class="table-wrap">
        <table class="table" data-testid="users-table">
            <thead>
                <tr><th>Usuario</th><th>Nombre</th><th>Rol</th><th>Barco</th><th>Estado</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r): $mine = ((int)$r['id'] === (int)$actor['id']);
                  $canManage = can_manage_user($actor, $r); ?>
                <tr data-testid="user-row-<?= (int)$r['id'] ?>">
                    <td><?= e($r['username']) ?><?= (int)$r['is_primary_admin'] === 1 ? ' <span class="tag">principal</span>' : '' ?></td>
                    <td><?= e($r['first_name'] . ' ' . $r['last_name']) ?></td>
                    <td><span class="pill"><?= e(role_label($r['role'])) ?></span></td>
                    <td><?= e($r['boat_name'] ?? '—') ?></td>
                    <td><?= (int)$r['is_active'] === 1 ? '<span class="dot dot-ok"></span> Activo' : '<span class="dot dot-ko"></span> Inactivo' ?></td>
                    <td class="row-actions">
                        <?php if ($canManage): ?>
                            <a class="btn btn-sm" href="<?= e(url('/users/' . $r['id'] . '/edit')) ?>" data-testid="user-edit-<?= (int)$r['id'] ?>">Editar</a>
                            <?php if ((int)$r['is_primary_admin'] !== 1 && !$mine): ?>
                                <form method="post" action="<?= e(url('/users/' . $r['id'] . '/toggle')) ?>" class="inline">
                                    <?= csrf_field() ?>
                                    <button class="btn btn-sm btn-ghost" data-testid="user-toggle-<?= (int)$r['id'] ?>">
                                        <?= (int)$r['is_active'] === 1 ? 'Desactivar' : 'Activar' ?>
                                    </button>
                                </form>
                                <form method="post" action="<?= e(url('/users/' . $r['id'] . '/delete')) ?>" class="inline"
                                      onsubmit="return confirm('¿Eliminar este usuario?');">
                                    <?= csrf_field() ?>
                                    <button class="btn btn-sm btn-danger" data-testid="user-delete-<?= (int)$r['id'] ?>">Eliminar</button>
                                </form>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
