<?php
declare(strict_types=1);

/**
 * src/actions/api_me.php
 * ------------------------
 * Ruta: GET /api/me
 * Endpoint de PRUEBA. Solo sirve para comprobar que el carnet de
 * acceso (token) funciona. Si el carnet es válido, dice quién eres.
 * Si no, rechaza la petición.
 */

header('Content-Type: application/json; charset=utf-8');

$user = api_require_auth(); // si el token no es válido, esto ya corta y responde 401

echo json_encode([
    'ok'   => true,
    'user' => [
        'id'       => (int)$user['id'],
        'username' => $user['username'],
        'role'     => $user['role'],
        'boat_id'  => $user['boat_id'],
    ],
]);
