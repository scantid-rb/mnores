<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$user = api_require_auth();

echo json_encode([
    'ok' => true,
    'user' => [
        'id' => (int)$user['id'],
        'username' => $user['username'],
        'first_name' => $user['first_name'],
        'last_name' => $user['last_name'],
        'role' => $user['role'],
        'boat_id' => $user['boat_id'] !== null ? (int)$user['boat_id'] : null,
    ],
]);
