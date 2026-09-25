<?php
declare(strict_types=1);

/**
 * src/actions/api_sync.php
 * ---------------------------
 * Ruta: GET /api/sync
 * Ruta: GET /api/sync?since=2026-09-24T10:00:00.000Z
 *
 * Sin "since": manda TODO (barcos, categorías, piezas). Se usa la
 * primera vez que el móvil se conecta, para tener una copia completa.
 *
 * Con "since": manda solo lo que cambió desde esa fecha (incluidas
 * las piezas/barcos/categorías borrados, para que el móvil también
 * los borre de su copia local).
 *
 * Requiere carnet de acceso válido (igual que /api/me).
 */

header('Content-Type: application/json; charset=utf-8');

$actor = api_require_auth();

$since = trim((string)($_GET['since'] ?? ''));

// Fecha desde la que devolvemos cambios. Si no viene "since", usamos
// una fecha muy antigua para que salga "todo".
$sinceBoats = $since !== '' ? $since : '0000-01-01';
$sinceParts = $since !== '' ? $since : '0000-01-01T00:00:00.000Z';

function fetch_boats(PDO $pdo, string $since): array {
    $st = $pdo->prepare(
        'SELECT id, name, registration, is_active, updated_at, deleted_at
         FROM boats WHERE updated_at > :s ORDER BY id'
    );
    $st->execute([':s' => $since]);
    return $st->fetchAll();
}

function fetch_categories(PDO $pdo, string $since): array {
    $st = $pdo->prepare(
        'SELECT id, name, is_system, updated_at, deleted_at
         FROM categories WHERE updated_at > :s ORDER BY id'
    );
    $st->execute([':s' => $since]);
    return $st->fetchAll();
}

function fetch_parts(PDO $pdo, string $since, ?int $forcedBoat): array {
    if ($forcedBoat === 0) {
        return []; // el actor no tiene barco asignado: no ve ninguna pieza
    }
    if ($forcedBoat !== null) {
        $st = $pdo->prepare(
            'SELECT id, boat_id, name, reference, category_id, location,
                    quantity, notes, photo_path, updated_at
             FROM parts WHERE updated_at > :s AND boat_id = :b ORDER BY id'
        );
        $st->execute([':s' => $since, ':b' => $forcedBoat]);
        return $st->fetchAll();
    }
    $st = $pdo->prepare(
        'SELECT id, boat_id, name, reference, category_id, location,
                quantity, notes, photo_path, updated_at
         FROM parts WHERE updated_at > :s ORDER BY id'
    );
    $st->execute([':s' => $since]);
    return $st->fetchAll();
}

$pdo = db();
$forcedBoat = parts_visible_boat_id($actor); // null = ve todos, 0 = ninguno, N = solo ese barco

$server_time = gmdate('Y-m-d\TH:i:s.') . sprintf('%03d', (int)(microtime(true) * 1000) % 1000) . 'Z';

echo json_encode([
    'ok'          => true,
    'server_time' => $server_time,
    'boats'       => fetch_boats($pdo, $sinceBoats),
    'categories'  => fetch_categories($pdo, $sinceBoats),
    'parts'       => fetch_parts($pdo, $sinceParts, $forcedBoat),
]);
