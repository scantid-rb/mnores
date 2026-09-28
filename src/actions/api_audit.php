<?php
declare(strict_types=1);

$actor = require_role([ROLE_ADMIN, ROLE_INSPECTOR]);

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = min(100, max(10, (int)($_GET['per_page'] ?? 50)));

$operation = trim((string)($_GET['operation'] ?? ''));
$objectType = trim((string)($_GET['object_type'] ?? ''));
$actorUsername = trim((string)($_GET['actor_username'] ?? ''));

$where = [];
$args = [];

if ($operation !== '') {
    $where[] = 'operation = :operation';
    $args[':operation'] = $operation;
}
if ($objectType !== '') {
    $where[] = 'object_type = :object_type';
    $args[':object_type'] = $objectType;
}
if ($actorUsername !== '') {
    $where[] = 'actor_username = :actor_username';
    $args[':actor_username'] = $actorUsername;
}

$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$countStmt = db()->prepare("SELECT COUNT(*) FROM audit_log{$whereSql}");
$countStmt->execute($args);
$total = (int)$countStmt->fetchColumn();

$pages = max(1, (int)ceil($total / $perPage));
if ($page > $pages) $page = $pages;
$offset = ($page - 1) * $perPage;

$stmt = db()->prepare(
    "SELECT a.id, a.at_utc, a.actor_id, a.actor_username, a.operation,
            a.object_type, a.object_id, a.boat_id, a.old_data, a.new_data,
            b.name AS boat_name
     FROM audit_log a
     LEFT JOIN boats b ON b.id = a.boat_id
     {$whereSql}
     ORDER BY a.id DESC
     LIMIT {$perPage} OFFSET {$offset}"
);
$stmt->execute($args);

$rows = [];
foreach ($stmt->fetchAll() as $row) {
    $old = null;
    $new = null;

    if ($row['old_data'] !== null && $row['old_data'] !== '') {
        $decoded = json_decode((string)$row['old_data'], true);
        $old = is_array($decoded) ? $decoded : null;
    }
    if ($row['new_data'] !== null && $row['new_data'] !== '') {
        $decoded = json_decode((string)$row['new_data'], true);
        $new = is_array($decoded) ? $decoded : null;
    }

    $rows[] = [
        'id' => (int)$row['id'],
        'at_utc' => $row['at_utc'],
        'actor_id' => $row['actor_id'] !== null ? (int)$row['actor_id'] : null,
        'actor_username' => $row['actor_username'],
        'operation' => $row['operation'],
        'object_type' => $row['object_type'],
        'object_id' => $row['object_id'] !== null ? (int)$row['object_id'] : null,
        'boat_id' => $row['boat_id'] !== null ? (int)$row['boat_id'] : null,
        'boat_name' => $row['boat_name'],
        'old_data' => $old,
        'new_data' => $new,
    ];
}

$operations = db()->query(
    'SELECT DISTINCT operation FROM audit_log ORDER BY operation'
)->fetchAll(PDO::FETCH_COLUMN);

$objectTypes = db()->query(
    'SELECT DISTINCT object_type FROM audit_log ORDER BY object_type'
)->fetchAll(PDO::FETCH_COLUMN);

$actors = db()->query(
    "SELECT DISTINCT actor_username
     FROM audit_log
     WHERE actor_username IS NOT NULL AND actor_username <> ''
     ORDER BY actor_username COLLATE NOCASE"
)->fetchAll(PDO::FETCH_COLUMN);

json_response([
    'ok' => true,
    'rows' => $rows,
    'page' => $page,
    'per_page' => $perPage,
    'total' => $total,
    'pages' => $pages,
    'operations' => $operations,
    'object_types' => $objectTypes,
    'actors' => $actors,
]);
