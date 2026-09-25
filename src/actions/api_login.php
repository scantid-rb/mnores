<?php
declare(strict_types=1);

/**
 * src/actions/api_login.php
 * ---------------------------
 * Ruta: POST /api/login
 * Recibe usuario y contraseña, y si son correctos, devuelve un
 * "carnet de acceso" (token) para que la app Android lo guarde y
 * lo use en el resto de peticiones.
 *
 * Reutiliza exactamente la misma comprobación de contraseña que
 * ya usa el login normal de la web (misma tabla, mismo hash).
 */

header('Content-Type: application/json; charset=utf-8');

// La app Android mandará los datos en formato JSON.
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) $input = [];

$username_raw  = trim((string)($input['username'] ?? ''));
$password      = (string)($input['password'] ?? '');
$device_label  = trim((string)($input['device_label'] ?? ''));
$username_norm = normalize_username($username_raw);
$ip = client_ip();

if ($username_raw === '' || $password === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Usuario y contraseña requeridos']);
    exit;
}

// Mismo sistema de bloqueo por intentos fallidos que ya existe en la web.
$remaining = brute_is_locked($ip, $username_norm);
if ($remaining > 0) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'Demasiados intentos, inténtalo más tarde']);
    exit;
}

$stmt = db()->prepare('SELECT * FROM users WHERE username_norm = :u LIMIT 1');
$stmt->execute([':u' => $username_norm]);
$user = $stmt->fetch();

$ok = false;
if ($user && (int)$user['is_active'] === 1 && password_verify($password, $user['password_hash'])) {
    $ok = true;
}

if (!$ok) {
    brute_register_failure($ip, $username_norm);
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Usuario o contraseña incorrectos']);
    exit;
}

brute_reset($ip, $username_norm);

// Crear el carnet de acceso para este móvil.
$token = api_token_create((int)$user['id'], $device_label);

echo json_encode([
    'ok'    => true,
    'token' => $token,
    'user'  => [
        'id'       => (int)$user['id'],
        'username' => $user['username'],
        'role'     => $user['role'],
        'boat_id'  => $user['boat_id'],
    ],
]);
