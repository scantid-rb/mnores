<?php
declare(strict_types=1);

/**
 * src/actions/api_sync.php
 * ---------------------------
 * GET /api/sync
 * GET /api/sync?since=2026-09-24T10:00:00.000Z
 *
 * Sin "since": sincronización completa.
 * Con "since": devuelve únicamente los registros modificados desde el cursor.
 *
 * Importante:
 * - Se captura un cutoff ANTES de leer los datos.
 * - El cutoff devuelto es el cursor que Android debe guardar para la siguiente
 *   sincronización.
 * - Las filas con deleted_at se devuelven como tombstones para que Android
 *   pueda eliminar su copia local.
 */

header('Content-Type: application/json; charset=utf-8');

$actor = api_require_auth();

$since = trim((string)($_GET['since'] ?? ''));
$isFullSync = ($since === '');

// El cursor se captura ANTES de consultar las tablas. Así, una modificación
// que ocurra mientras se construye esta respuesta no puede quedar "por detrás"
// del server_time que guardará Android.
$cutoff = gmdate('Y-m-d\TH:i:s.') . sprintf('%03d', (int)(microtime(true) * 1000) % 1000) . 'Z';

$sinceBoats = $since !== '' ? $since : '0000-01-01T00:00:00.000Z';
$sinceParts = $since !== '' ? $since : '0000-01-01T00:00:00.000Z';

function fetch_boats(PDO $pdo, string $since, string $cutoff, bool $full): array {
    if ($full) {
        return $pdo->query(
            'SELECT id, name, registration, is_active, updated_at, deleted_at
             FROM boats
             ORDER BY id'
        )->fetchAll();
    }
    $st = $pdo->prepare(
        'SELECT id, name, registration, is_active, updated_at, deleted_at
         FROM boats
         WHERE updated_at > :s AND updated_at <= :cutoff
         ORDER BY id'
    );
    $st->execute([':s' => $since, ':cutoff' => $cutoff]);
    return $st->fetchAll();
}

function fetch_categories(PDO $pdo, string $since, string $cutoff, bool $full): array {
    if ($full) {
        return $pdo->query(
            'SELECT id, name, is_system, updated_at, deleted_at
             FROM categories
             ORDER BY id'
        )->fetchAll();
    }
    $st = $pdo->prepare(
        'SELECT id, name, is_system, updated_at, deleted_at
         FROM categories
         WHERE updated_at > :s AND updated_at <= :cutoff
         ORDER BY id'
    );
    $st->execute([':s' => $since, ':cutoff' => $cutoff]);
    return $st->fetchAll();
}

function fetch_parts(PDO $pdo, string $since, string $cutoff, ?int $forcedBoat, bool $full): array {
    if ($forcedBoat === 0) {
        return [];
    }

    if ($forcedBoat !== null) {
        if ($full) {
            $st = $pdo->prepare(
                'SELECT id, boat_id, name, reference, category_id, location,
                        quantity, notes, photo_path, updated_at, deleted_at,
                        client_local_id
                 FROM parts
                 WHERE boat_id = :b
                 ORDER BY id'
            );
            $st->execute([':b' => $forcedBoat]);
            return $st->fetchAll();
        }
        $st = $pdo->prepare(
            'SELECT id, boat_id, name, reference, category_id, location,
                    quantity, notes, photo_path, updated_at, deleted_at,
                    client_local_id
             FROM parts
             WHERE updated_at > :s
               AND updated_at <= :cutoff
               AND boat_id = :b
             ORDER BY id'
        );
        $st->execute([
            ':s' => $since,
            ':cutoff' => $cutoff,
            ':b' => $forcedBoat,
        ]);
        return $st->fetchAll();
    }

    if ($full) {
        return $pdo->query(
            'SELECT id, boat_id, name, reference, category_id, location,
                    quantity, notes, photo_path, updated_at, deleted_at,
                    client_local_id
             FROM parts
             ORDER BY id'
        )->fetchAll();
    }

    $st = $pdo->prepare(
        'SELECT id, boat_id, name, reference, category_id, location,
                quantity, notes, photo_path, updated_at, deleted_at,
                client_local_id
         FROM parts
         WHERE updated_at > :s
           AND updated_at <= :cutoff
         ORDER BY id'
    );
    $st->execute([
        ':s' => $since,
        ':cutoff' => $cutoff,
    ]);
    return $st->fetchAll();
}

$pdo = db();
$forcedBoat = parts_visible_boat_id($actor);

echo json_encode([
    'ok'          => true,
    'server_time' => $cutoff,
    'boats'       => fetch_boats($pdo, $sinceBoats, $cutoff, $isFullSync),
    'categories'  => fetch_categories($pdo, $sinceBoats, $cutoff, $isFullSync),
    'parts'       => fetch_parts($pdo, $sinceParts, $cutoff, $forcedBoat, $isFullSync),
]);
