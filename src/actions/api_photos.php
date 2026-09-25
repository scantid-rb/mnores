<?php
declare(strict_types=1);

/**
 * src/actions/api_photos.php
 * -----------------------------
 * Ruta: GET  /api/photos/{id}   -> descargar la foto de una pieza
 * Ruta: POST /api/photos/{id}   -> subir/reemplazar la foto de una pieza
 *
 * Usa exactamente la misma lógica de imágenes que ya usa la web
 * (photo_process_upload, photo_path_for de src/photos.php), así que
 * el resultado es idéntico se suba desde el navegador o desde el móvil.
 *
 * La subida se hace como formulario normal (multipart/form-data) con
 * un campo llamado "photo" — igual que hace la web. NO es JSON, porque
 * las imágenes no se mandan bien en JSON.
 */

$actor = api_require_auth();

// $api_photo_id lo pone index.php al leer el número de la URL.
$id = $api_photo_id ?? 0;

$stmt = db()->prepare('SELECT id, boat_id, photo_path FROM parts WHERE id = :id');
$stmt->execute([':id' => $id]);
$part = $stmt->fetch();

if (!$part) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Pieza no encontrada']);
    return;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// ---------- DESCARGAR ----------
if ($method === 'GET') {
    if (!parts_can_view($actor, $part)) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'No autorizado']);
        return;
    }
    $file = photo_path_for($id);
    if (!is_file($file)) {
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Esta pieza no tiene foto']);
        return;
    }
    header('Content-Type: image/jpeg');
    header('Cache-Control: private, max-age=60');
    readfile($file);
    return;
}

// ---------- SUBIR / REEMPLAZAR ----------
if ($method === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    if (!parts_can_manage_photo($actor, $part)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'No autorizado']);
        return;
    }

    $res = photo_process_upload($_FILES['photo'] ?? [], $id);
    if (!$res['ok']) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => $res['error']]);
        return;
    }

    $now = api_now();
    db()->prepare('UPDATE parts SET photo_path=:pp, updated_at=:t WHERE id=:id')
        ->execute([':pp' => photo_path_for($id), ':t' => $now, ':id' => $id]);

    audit_log('part.photo_replace', 'part', $id, (int)$part['boat_id']);

    echo json_encode(['ok' => true, 'updated_at' => $now]);
    return;
}

http_response_code(405);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => false, 'error' => 'Método no permitido']);
