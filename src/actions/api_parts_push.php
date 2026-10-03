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
if (!is_array($input) || !isset($input['changes']) || !is_array($input['changes']) || !array_is_list($input['changes'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'changes debe ser una lista.']);
    return;
}
$changes = $input['changes'];

function push_valid_quantity($value): bool {
    return is_int($value) && $value >= 0;
}
function push_valid_fields(array $c, PDO $pdo): bool {
    foreach (['name', 'reference', 'category_id', 'location', 'quantity', 'notes'] as $field) {
        if (!array_key_exists($field, $c)) return false;
    }
    if (!is_string($c['name']) || trim($c['name']) === '' || mb_strlen($c['name']) > 160) return false;
    foreach (['reference' => 80, 'location' => 120, 'notes' => 65535] as $field => $limit) {
        if ($c[$field] !== null && (!is_string($c[$field]) || mb_strlen($c[$field]) > $limit)) return false;
    }
    if (!push_valid_quantity($c['quantity']) || !is_int($c['category_id']) || $c['category_id'] <= 0) return false;
    $category = $pdo->prepare('SELECT id FROM categories WHERE id=:id AND deleted_at IS NULL');
    $category->execute([':id' => $c['category_id']]);
    return (bool)$category->fetch();
}

$pdo = db();
$results = [];

foreach ($changes as $c) {
    if (!is_array($c)) { $results[] = ['action' => '', 'status' => 'invalid']; continue; }
    $action = $c['action'] ?? '';
    if (!is_string($action)) { $results[] = ['action' => '', 'status' => 'invalid']; continue; }
    if (in_array($action, ['update', 'delete'], true) && (!isset($c['id']) || !is_int($c['id']) || $c['id'] <= 0)) {
        $results[] = ['action' => $action, 'status' => 'invalid'];
        continue;
    }

    try {
    // Acquire the SQLite writer lock before reading a part or touching photos.
    // The mutation and its audit records commit together.
    $pdo->beginTransaction();
    $pdo->exec('UPDATE parts SET id=id WHERE id=-1');

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

        // This endpoint edits the existing boat, never reassigns inventory.
        if (array_key_exists('boat_id', $c) && (!is_int($c['boat_id']) || $c['boat_id'] !== (int)$row['boat_id'])) {
            $results[] = ['action' => 'update', 'id' => $id, 'status' => 'invalid'];
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

        if (($quantityOnly && !push_valid_quantity($c['quantity']))
            || (!$quantityOnly && !push_valid_fields($c, $pdo))) {
            $results[] = ['action' => 'update', 'id' => $id, 'status' => 'invalid'];
            continue;
        }
        if (!$quantityOnly && !$canEditAll) {
            $results[] = ['action' => 'update', 'id' => $id, 'status' => 'forbidden'];
            continue;
        }
        $qty = (int)$c['quantity'];
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
            'UPDATE parts SET photo_path=NULL, deleted_at=:d, updated_at=:t WHERE id=:id'
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
        $localId  = is_string($c['local_id'] ?? null) ? trim($c['local_id']) : '';
        $boatId   = is_int($c['boat_id'] ?? null) ? $c['boat_id'] : 0;
        $now      = api_now();

        if ($boatId <= 0 || $localId === '' || strlen($localId) > 200) {
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

        // An already applied create remains idempotent even if its category
        // was subsequently removed. Validate the catalog only for new rows.
        $boat = $pdo->prepare('SELECT id FROM boats WHERE id=:id AND deleted_at IS NULL');
        $boat->execute([':id' => $boatId]);
        if (!push_valid_fields($c, $pdo) || !$boat->fetch()) {
            $results[] = ['action' => 'create', 'local_id' => $localId, 'status' => 'invalid'];
            continue;
        }
        $name = trim($c['name']);
        $reference = trim($c['reference'] ?? '');
        $location = trim($c['location'] ?? '');
        $notes = trim($c['notes'] ?? '');
        $catId = $c['category_id'];
        $qty = $c['quantity'];

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
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log("parts/push: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'ok' => false,
            'error' => 'server_error',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    } finally {
        if ($pdo->inTransaction()) $pdo->commit();
    }
}

echo json_encode([
    'ok'          => true,
    'server_time' => api_now(),
    'results'     => $results,
]);
