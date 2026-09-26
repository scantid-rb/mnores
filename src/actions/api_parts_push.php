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
 * Formato esperado del cuerpo (JSON):
 * {
 *   "changes": [
 *     {
 *       "action": "update",
 *       "id": 7,
 *       "base_updated_at": "2026-09-24T19:54:33.136Z",   // el que tenía el móvil antes de editar
 *       "name": "...", "reference": "...", "category_id": 26,
 *       "location": "...", "quantity": 3, "notes": "..."
 *     },
 *     {
 *       "action": "create",
 *       "local_id": "abc-123-generado-por-el-movil",
 *       "boat_id": 1,
 *       "name": "...", "reference": "...", "category_id": 26,
 *       "location": "...", "quantity": 1, "notes": "..."
 *     }
 *   ]
 * }
 *
 * Respuesta:
 * {
 *   "ok": true,
 *   "server_time": "...",
 *   "results": [
 *     { "action": "update", "id": 7, "status": "ok", "updated_at": "..." },
 *     { "action": "update", "id": 9, "status": "conflict_overwritten", "updated_at": "..." },
 *     { "action": "create", "local_id": "abc-123-...", "id": 55, "status": "ok", "updated_at": "..." }
 *   ]
 * }
 */

header('Content-Type: application/json; charset=utf-8');

$actor = api_require_auth();

$input = json_decode(file_get_contents('php://input'), true);
$changes = is_array($input['changes'] ?? null) ? $input['changes'] : [];

$pdo = db();
$results = [];

foreach ($changes as $c) {
    $action = $c['action'] ?? '';

    // ---------- EDITAR PIEZA EXISTENTE ----------
    if ($action === 'update') {
        $id = (int)($c['id'] ?? 0);

        $current = $pdo->prepare('SELECT * FROM parts WHERE id = :id');
        $current->execute([':id' => $id]);
        $row = $current->fetch();

        if (!$row) {
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
        // (equipo pequeño, choques muy raros) y solo lo anotamos.
        $baseUpdatedAt = (string)($c['base_updated_at'] ?? '');
        $huboConflicto = $baseUpdatedAt !== '' && $baseUpdatedAt !== $row['updated_at'];

        $qty = max(0, (int)($c['quantity'] ?? 0));
        $now = api_now();

        if ($canEditAll) {
            $name      = trim((string)($c['name'] ?? ''));
            $reference = trim((string)($c['reference'] ?? ''));
            $location  = trim((string)($c['location'] ?? ''));
            $notes     = trim((string)($c['notes'] ?? ''));
            $catId     = (int)($c['category_id'] ?? 0) ?: null;

            $pdo->prepare(
                'UPDATE parts SET name=:n, name_norm=:nn, reference=:r, reference_norm=:rn,
                    category_id=:c, location=:l, location_norm=:ln, quantity=:q, notes=:no,
                    updated_at=:t WHERE id=:id'
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
            // Mecánico: exactamente la misma restricción que la web.
            // Aunque el cliente envíe otros campos, el servidor los ignora.
            $pdo->prepare(
                'UPDATE parts SET quantity=:q, updated_at=:t WHERE id=:id'
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

        // Si ya no existe, el móvil puede descartar su operación pendiente.
        if (!$row) {
            $results[] = ['action' => 'delete', 'id' => $id, 'status' => 'not_found'];
            continue;
        }

        if (!parts_can_delete($actor, $row)) {
            $results[] = ['action' => 'delete', 'id' => $id, 'status' => 'forbidden'];
            continue;
        }

        // Igual que en update: detectamos si alguien modificó la pieza
        // mientras el móvil estaba offline, pero priorizamos la operación
        // explícita del usuario y permitimos el borrado.
        $baseUpdatedAt = (string)($c['base_updated_at'] ?? '');
        $huboConflicto = $baseUpdatedAt !== '' && $baseUpdatedAt !== $row['updated_at'];

        // El borrado web elimina también la fotografía asociada.
        photo_delete($id);
        $pdo->prepare('DELETE FROM parts WHERE id = :id')->execute([':id' => $id]);

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

        // Idempotencia: si el móvil reintenta un create cuyo primer intento
        // pudo llegar al servidor pero cuya respuesta se perdió, devolvemos
        // la misma pieza en lugar de insertar un duplicado.
        $existing = $pdo->prepare(
            'SELECT id, updated_at FROM parts WHERE boat_id=:b AND client_local_id=:local LIMIT 1'
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
                location,location_norm,quantity,notes,client_local_id,updated_at)
             VALUES (:b,:n,:nn,:r,:rn,:c,:l,:ln,:q,:no,:local,:t)'
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
}

echo json_encode([
    'ok'          => true,
    'server_time' => api_now(),
    'results'     => $results,
]);
