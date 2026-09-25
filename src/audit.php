<?php
declare(strict_types=1);

function audit_log(string $operation, string $object_type, ?int $object_id, ?int $boat_id = null, ?array $old = null, ?array $new = null): void {
    $actor = current_user();
    $stmt = db()->prepare('INSERT INTO audit_log
        (at_utc, actor_id, actor_username, operation, object_type, object_id, boat_id, old_data, new_data)
        VALUES (:t, :aid, :au, :op, :ot, :oid, :bid, :old, :new)');
    $stmt->execute([
        ':t'   => gmdate('Y-m-d H:i:s'),
        ':aid' => $actor['id'] ?? null,
        ':au'  => $actor['username'] ?? null,
        ':op'  => $operation,
        ':ot'  => $object_type,
        ':oid' => $object_id,
        ':bid' => $boat_id,
        ':old' => $old ? json_encode($old, JSON_UNESCAPED_UNICODE) : null,
        ':new' => $new ? json_encode($new, JSON_UNESCAPED_UNICODE) : null,
    ]);
}

/** Filtra un array de usuario para no incluir el hash en la auditoría. */
function audit_user_snapshot(array $u): array {
    return [
        'username'   => $u['username']   ?? null,
        'first_name' => $u['first_name'] ?? null,
        'last_name'  => $u['last_name']  ?? null,
        'role'       => $u['role']       ?? null,
        'boat_id'    => $u['boat_id']    ?? null,
        'is_active'  => (int)($u['is_active'] ?? 0),
    ];
}
