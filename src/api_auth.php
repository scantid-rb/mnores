<?php
declare(strict_types=1);

/**
 * api_auth.php
 * -------------
 * Funciones para el "carnet de acceso" (token) que usará la app Android.
 * Este archivo no hace nada por sí solo, solo define herramientas que
 * usaremos en el siguiente paso.
 */

/**
 * Fecha/hora actual en el mismo formato que usa "parts" para su
 * control de ediciones simultáneas (ISO 8601 con milisegundos).
 * Nombre propio a propósito: parts.php ya tiene su función interna
 * "_now()", y como ambos archivos pueden cargarse en la misma
 * petición, usar el mismo nombre daría un error de PHP.
 */
if (!function_exists('api_now')) {
    function api_now(): string {
        return gmdate('Y-m-d\TH:i:s.') . sprintf('%03d', (int)(microtime(true) * 1000) % 1000) . 'Z';
    }
}

/**
 * Genera un carnet de acceso nuevo para un usuario y lo guarda en la BD.
 * Devuelve el carnet en texto plano (esto es lo único que verá el móvil;
 * en la base de datos solo se guarda una versión cifrada, igual que
 * ya se hace con las contraseñas).
 */
function api_token_create(int $userId, string $deviceLabel = ''): string {
    $token = bin2hex(random_bytes(32)); // el "carnet" real, 64 caracteres al azar
    $hash  = hash('sha256', $token);    // lo que se guarda en la BD (no se puede revertir)

    $stmt = db()->prepare(
        'INSERT INTO api_tokens (user_id, token_hash, device_label, created_at)
         VALUES (:u, :h, :d, :t)'
    );
    $stmt->execute([
        ':u' => $userId,
        ':h' => $hash,
        ':d' => $deviceLabel !== '' ? $deviceLabel : null,
        ':t' => gmdate('c'),
    ]);

    return $token;
}

/**
 * Busca la cabecera "Authorization" en los distintos sitios donde
 * puede aparecer según cómo el hosting ejecute PHP.
 */
function api_get_authorization_header(): string {
    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
        return $_SERVER['HTTP_AUTHORIZATION'];
    }
    if (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        return $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    }
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        foreach ($headers as $name => $value) {
            if (strcasecmp($name, 'Authorization') === 0) {
                return $value;
            }
        }
    }
    return '';
}

/**
 * Comprueba si un carnet de acceso es válido.
 * Si lo es, devuelve el usuario correspondiente (igual que current_user()).
 * Si no, devuelve null.
 */
function api_authenticate(): ?array {
    $header = api_get_authorization_header();
    if (!preg_match('/^Bearer\s+(.+)$/i', $header, $m)) return null;

    $token = trim($m[1]);
    if ($token === '') return null;

    $hash = hash('sha256', $token);

    $stmt = db()->prepare(
        'SELECT t.id AS token_id, u.*
         FROM api_tokens t
         JOIN users u ON u.id = t.user_id
         WHERE t.token_hash = :h
           AND t.revoked_at IS NULL
           AND u.is_active = 1
         LIMIT 1'
    );
    $stmt->execute([':h' => $hash]);
    $row = $stmt->fetch();
    if (!$row) return null;

    // Anotar que este carnet se usó ahora (útil para ver actividad).
    $upd = db()->prepare('UPDATE api_tokens SET last_used_at = :t WHERE id = :id');
    $upd->execute([':t' => gmdate('c'), ':id' => $row['token_id']]);

    // Importante: dejamos al usuario "logueado" para esta única petición
    // (no se guarda cookie, es todo dentro de esta misma llamada). Así,
    // funciones que ya existían como current_user(), audit_log() o los
    // permisos por rol/barco (parts_can_edit_all, etc.) funcionan igual
    // de bien llamadas desde la API que desde la web, sin duplicar nada.
    $_SESSION['user_id'] = (int)$row['id'];

    return $row;
}

/**
 * Exige un carnet de acceso válido. Si no lo hay, corta la petición
 * con un error 401 en formato JSON (para que la app Android lo entienda).
 */
function api_require_auth(): array {
    $user = api_authenticate();
    if (!$user) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'No autorizado']);
        exit;
    }
    return $user;
}
