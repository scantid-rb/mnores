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

$rawBody = file_get_contents('php://input');
$input = json_decode($rawBody, true);
$changes = is_array($input['changes'] ?? null) ? $input['changes'] : [];

/*
 * TEMPORARY DIAGNOSTIC LOGGING
 * - Never logs Authorization, cookies, passwords or the complete request body.
 * - Remove this block after the mobile sync issue has been fully validated.
 */
$debugId = bin2hex(random_bytes(6));
$debugLog = defined('DATA_DIR') ? DATA_DIR . '/debug_parts_push.log' : __DIR__ . '/../../data/debug_parts_push.log';

function debug_parts_push_log(string $debugId, string $event, array $data = []): void {
    global $debugLog;
    $entry = array_merge([
        'time' => gmdate('c'),
        'debug_id' => $debugId,
        'event' => $event,
    ], $data);
    @file_put_contents(
        $debugLog,
        json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
}

$jsonError = json_last_error_msg();
$operationDiagnostics = [];
foreach ($changes as $i => $op) {
    if (!is_array($op)) {
        $operationDiagnostics[] = ['index' => $i, 'not_array' => true];
        continue;
    }
    $operationDiagnostics[] = [
        'index' => $i,
        'action' => $op['action'] ?? null,
        'id' => isset($op['id']) ? (int)$op['id'] : null,
        'local_id' => isset($op['local_id']) ? (string)$op['local_id'] : null,
        'boat_id' => isset($op['boat_id']) ? (int)$op['boat_id'] : null,
        'quantity' => array_key_exists('quantity', $op) ? $op['quantity'] : null,
        'quantity_type' => array_key_exists('quantity', $op) ? gettype($op['quantity']) : null,
        'base_updated_at' => $op['base_updated_at'] ?? null,
        'fields_present' => array_keys($op),
    ];
}
debug_parts_push_log($debugId, 'request_received', [
    'method' => $_SERVER['REQUEST_METHOD'] ?? null,
    'changes_count' => count($changes),
    'json_error' => $jsonError,
    'operations' => $operationDiagnostics,
]);

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

        $hasFullField = array_key_exists('name', $c)
            || array_key_exists('reference', $c)
            || array_key_exists('category_id', $c)
            || array_key_exists('location', $c)
            || array_key_exists('notes', $c);

        $quantityOnly = array_key_exists('quantity', $c) && !$hasFullField;

        $qty = max(0, (int)($c['quantity'] ?? 0));
        $now = api_now();

        /*
         * Mobile quantity controls may intentionally send a partial update:
         * action + id + base_updated_at + quantity.
         * This is valid for both mechanics and chief engineers. In this case
         * update ONLY quantity, preserving all other server-side fields.
         */
        if ($quantityOnly) {
            $pdo->prepare(
                'UPDATE parts SET quantity=:q, updated_at=:t WHERE id=:id'
            )->execute([
                ':q' => $qty,
                ':t' => $now,
                ':id' => $id,
            ]);

            audit_log(
                $canEditAll ? 'part.quantity_change' : 'part.quantity_change',
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

            debug_parts_push_log($debugId, 'quantity_only_update', [
                'id' => $id,
                'quantity' => $qty,
                'status' => $huboConflicto ? 'conflict_overwritten' : 'ok',
            ]);
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
    } catch (Throwable $e) {
        debug_parts_push_log($debugId, 'exception', [
            'class' => get_class($e),
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'results_so_far' => $results,
        ]);

        http_response_code(500);
        echo json_encode([
            'ok' => false,
            'error' => 'server_error',
            'debug_id' => $debugId,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

debug_parts_push_log($debugId, 'request_completed', [
    'results' => $results,
]);

echo json_encode([
    'ok'          => true,
    'server_time' => api_now(),
    'results'     => $results,
    'debug_id'    => $debugId,
]);
