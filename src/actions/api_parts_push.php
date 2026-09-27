<?php
declare(strict_types=1);

/**
 * src/actions/api_parts_push.php
 * ---------------------------------
 * Ruta: POST /api/parts/push
 *
 * Recibe los cambios hechos offline en el móvil (piezas editadas, creadas o
 * eliminadas) y los aplica en la base de datos real.
 *
 * IMPORTANTE: no gestiona fotos todavía (eso es el Paso 5, aparte).
 * Los campos que sí gestiona: name, reference, category_id, location,
 * quantity, notes.
 *
 * Además acepta un update parcial de cantidad con:
 *   action + id + base_updated_at + quantity
 * En ese caso solo se modifica quantity.
 *
 * Esta versión utiliza tombstones para DELETE:
 * - No elimina físicamente la fila de parts.
 * - Conserva la fila con deleted_at y actualiza updated_at.
 * - /api/sync?since= puede así informar al móvil de la eliminación.
 */

header('Content-Type: application/json; charset=utf-8');

$actor = api_require_auth();

$rawBody = file_get_contents('php://input');
$input = json_decode($rawBody, true);
$changes = is_array($input['changes'] ?? null) ? $input['changes'] : [];

$pdo = db();
$results = [];

foreach ($changes as $c) {
    $action = $c['action'] ?? '';

    try {

    // ---------- EDITAR PIEZA EXISTENTE ----------
    if ($action === 'update') {
        $id = (int)($c['id'] ?? 0);

        $current = $pdo->prepare('SELECT * FROM parts WHERE id = :id');
        $current->execute([':id' => $id]);
        $row = $current->fetch();

        if (!$row || !empty($row['deleted_at'])) {
            $results[] = ['action' => 'update', 'id' => $id, 'status' => 'not_found'];
            continue;
        }

        $canEditAll = parts_can_edit_all($actor, $row);
        $canEditQuantity = parts_can_edit_quantity($actor, $row);

        if (!$canEditAll && !$canEditQuantity) {
            $results[] = ['action' => 'update', 'id' => $id, 'status' => 'forbidden'];
            continue;
        }

        // Si alguien más cambió la pieza mientras el móvil estaba offline,
        // lo detectamos aquí, pero igualmente aplicamos el cambio del móvil
        // y lo anotamos como conflicto.
        $baseUpdatedAt = (string)($c['base_updated_at'] ?? '');
        $huboConflicto = $baseUpdatedAt !== '' && $baseUpdatedAt !== $row['updated_at'];

        $hasFullField = array_key_exists('name', $c)
            || array_key_exists('reference', $c)
            || array_key_exists('category_id', $c)
            || array_key_exists('location', $c)
            || array_key_exists('notes', $c);

        $quantityOnly = array_key_exists('quantity', $c) && !$hasFullField;

        $qty = max(0, (int)($c['quantity'] ?? 0));
        $now = api_now();

        if ($quantityOnly) {
            $pdo->prepare(
                'UPDATE parts SET quantity=:q, updated_at=:t, deleted_at=NULL WHERE id=:id'
            )->execute([
                ':q' => $qty,
                ':t' => $now,
                ':id' => $id,
            ]);

            audit_log(
                'part.quantity_change',
                'part',
                $id,
                (int)$row['boat_id'],
                ['quantity' => $row['quantity']],
                ['quantity' => $qty]
            );

            $results[] = [
                'action' => 'update',
                'id' => $id,
                'status' => $huboConflicto ? 'conflict_overwritten' : 'ok',
                'updated_at' => $now,
            ];

            continue;
        }

        if ($canEditAll) {
            $name      = trim((string)($c['name'] ?? ''));
            $reference = trim((string)($c['reference'] ?? ''));
            $location  = trim((string)($c['location'] ?? ''));
            $notes     = trim((string)($c['notes'] ?? ''));
            $catId     = (int)($c['category_id'] ?? 0) ?: null;

            $pdo->prepare(
                'UPDATE parts SET name=:n, name_norm=:nn, reference=:r, reference_norm=:rn,
                    category_id=:c, location=:l, location_norm=:ln, quantity=:q, notes=:no,
                    updated_at=:t, deleted_at=NULL WHERE id=:id'
            )->execute([
                ':n' => $name, ':nn' => search_norm($name),
                ':r' => $reference, ':rn' => search_norm($reference),
                ':c' => $catId,
                ':l' => $location, ':ln' => search_norm($location),
                ':q' => $qty, ':no' => $notes,
                ':t' => $now, ':id' => $id,
            ]);

            audit_log('part.update', 'part', $id, (int)$row['boat_id'], $row, [
                'name' => $name, 'reference' => $reference, 'category_id' => $catId,
                'location' => $location, 'quantity' => $qty, 'notes' => $notes,
            ]);
            if ((int)$row['category_id'] !== (int)$catId) {
                audit_log('part.category_change', 'part', $id, (int)$row['boat_id'], ['category_id' => $row['category_id']], ['category_id' => $catId]);
            }
        } else {
            $pdo->prepare(
                'UPDATE parts SET quantity=:q, updated_at=:t, deleted_at=NULL WHERE id=:id'
            )->execute([
                ':q' => $qty,
                ':t' => $now, ':id' => $id,
            ]);

            audit_log('part.quantity_change', 'part', $id, (int)$row['boat_id'],
                ['quantity' => $row['quantity']],
                ['quantity' => $qty]
            );
        }

        if ($canEditAll && (int)$row['quantity'] !== $qty) {
            audit_log('part.quantity_change', 'part', $id, (int)$row['boat_id'], ['quantity' => $row['quantity']], ['quantity' => $qty]);
        }

        $results[] = [
            'action'     => 'update',
            'id'         => $id,
            'status'     => $huboConflicto ? 'conflict_overwritten' : 'ok',
            'updated_at' => $now,
        ];
        continue;
    }

    // ---------- ELIMINAR PIEZA EXISTENTE ----------
    if ($action === 'delete') {
        $id = (int)($c['id'] ?? 0);

        if ($id <= 0) {
            $results[] = ['action' => 'delete', 'id' => $id, 'status' => 'invalid'];
            continue;
        }

        $current = $pdo->prepare('SELECT * FROM parts WHERE id = :id');
        $current->execute([':id' => $id]);
        $row = $current->fetch();

        if (!$row) {
            $results[] = ['action' => 'delete', 'id' => $id, 'status' => 'not_found'];
            continue;
        }

        // Un reintento de un DELETE ya aplicado debe ser idempotente.
        if (!empty($row['deleted_at'])) {
            $results[] = [
                'action' => 'delete',
                'id' => $id,
                'status' => 'ok',
                'updated_at' => $row['updated_at'],
            ];
            continue;
        }

        if (!parts_can_delete($actor, $row)) {
            $results[] = ['action' => 'delete', 'id' => $id, 'status' => 'forbidden'];
            continue;
        }

        $baseUpdatedAt = (string)($c['base_updated_at'] ?? '');
        $huboConflicto = $baseUpdatedAt !== '' && $baseUpdatedAt !== $row['updated_at'];

        // El archivo de foto se puede eliminar físicamente; la fila de parts
        // se conserva como tombstone para sincronización incremental.
        photo_delete($id);

        $now = api_now();
        $pdo->prepare(
            'UPDATE parts SET deleted_at=:d, updated_at=:t WHERE id=:id'
        )->execute([
            ':d' => $now,
            ':t' => $now,
            ':id' => $id,
        ]);

        audit_log(
            'part.delete',
            'part',
            $id,
            (int)$row['boat_id'],
            ['name' => $row['name'], 'reference' => $row['reference']]
        );

        $results[] = [
            'action' => 'delete',
            'id' => $id,
            'status' => $huboConflicto ? 'conflict_overwritten' : 'ok',
            'updated_at' => $now,
        ];
        continue;
    }

    // ---------- CREAR PIEZA NUEVA ----------
    if ($action === 'create') {
        $localId  = trim((string)($c['local_id'] ?? ''));
        $boatId   = (int)($c['boat_id'] ?? 0);
        $name     = trim((string)($c['name'] ?? ''));
        $reference= trim((string)($c['reference'] ?? ''));
        $location = trim((string)($c['location'] ?? ''));
        $notes    = trim((string)($c['notes'] ?? ''));
        $catId    = (int)($c['category_id'] ?? 0) ?: null;
        $qty      = max(0, (int)($c['quantity'] ?? 0));
        $now      = api_now();

        if ($boatId <= 0 || $name === '' || $localId === '') {
            $results[] = ['action' => 'create', 'local_id' => $localId, 'status' => 'invalid'];
            continue;
        }

        if (!parts_can_create($actor, $boatId)) {
            $results[] = ['action' => 'create', 'local_id' => $localId, 'status' => 'forbidden'];
            continue;
        }

        $existing = $pdo->prepare(
            'SELECT id, updated_at, deleted_at FROM parts WHERE boat_id=:b AND client_local_id=:local LIMIT 1'
        );
        $existing->execute([':b' => $boatId, ':local' => $localId]);
        $existingRow = $existing->fetch();
        if ($existingRow) {
            $results[] = [
                'action'     => 'create',
                'local_id'   => $localId,
                'id'         => (int)$existingRow['id'],
                'status'     => 'ok',
                'updated_at' => $existingRow['updated_at'],
            ];
            continue;
        }

        $pdo->prepare(
            'INSERT INTO parts (boat_id,name,name_norm,reference,reference_norm,category_id,
                location,location_norm,quantity,notes,client_local_id,updated_at,deleted_at)
             VALUES (:b,:n,:nn,:r,:rn,:c,:l,:ln,:q,:no,:local,:t,NULL)'
        )->execute([
            ':b' => $boatId,
            ':n' => $name, ':nn' => search_norm($name),
            ':r' => $reference, ':rn' => search_norm($reference),
            ':c' => $catId,
            ':l' => $location, ':ln' => search_norm($location),
            ':q' => $qty, ':no' => $notes, ':local' => $localId,
            ':t' => $now,
        ]);

        $newId = (int)$pdo->lastInsertId();

        audit_log('part.create', 'part', $newId, $boatId, null, [
            'name' => $name, 'reference' => $reference, 'category_id' => $catId,
            'location' => $location, 'quantity' => $qty, 'notes' => $notes,
        ]);

        $results[] = [
            'action'     => 'create',
            'local_id'   => $localId,
            'id'         => $newId,
            'status'     => 'ok',
            'updated_at' => $now,
        ];
        continue;
    }

    $results[] = ['action' => $action, 'status' => 'unknown_action'];
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode([
            'ok' => false,
            'error' => 'server_error',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

echo json_encode([
    'ok'          => true,
    'server_time' => api_now(),
    'results'     => $results,
]);
