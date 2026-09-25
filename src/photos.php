<?php
declare(strict_types=1);

const PHOTO_DIR      = DATA_DIR . '/photos';
const PHOTO_MAX_BYTES = 8 * 1024 * 1024;
const PHOTO_MAX_W    = 1600;
const PHOTO_MAX_H    = 1200;

function photo_path_for(int $part_id): string {
    return PHOTO_DIR . '/' . $part_id . '.jpg';
}

/**
 * Procesa una imagen subida y la guarda como JPEG normalizado.
 * @return array{ok:bool,error?:string}
 */
function photo_process_upload(array $file, int $part_id): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'Error al subir el archivo (código ' . $file['error'] . ').'];
    }
    if ($file['size'] > PHOTO_MAX_BYTES) {
        return ['ok' => false, 'error' => 'La imagen supera 8 MB.'];
    }
    $tmp = $file['tmp_name'];
    if (!is_uploaded_file($tmp) && !is_file($tmp)) {
        return ['ok' => false, 'error' => 'Archivo no válido.'];
    }
    // Validar contenido real: getimagesize + mime.
    $info = @getimagesize($tmp);
    if (!$info) return ['ok' => false, 'error' => 'El archivo no es una imagen válida.'];
    $mime = $info['mime'] ?? '';
    $srcImg = null;
    switch ($mime) {
        case 'image/jpeg': $srcImg = @imagecreatefromjpeg($tmp); break;
        case 'image/png':  $srcImg = @imagecreatefrompng($tmp);  break;
        case 'image/gif':  $srcImg = @imagecreatefromgif($tmp);  break;
        case 'image/webp': if (function_exists('imagecreatefromwebp')) $srcImg = @imagecreatefromwebp($tmp); break;
        default: return ['ok' => false, 'error' => 'Formato no admitido: ' . $mime];
    }
    if (!$srcImg) return ['ok' => false, 'error' => 'No se ha podido decodificar la imagen.'];

    $w = imagesx($srcImg); $h = imagesy($srcImg);
    $scale = min(PHOTO_MAX_W / $w, PHOTO_MAX_H / $h, 1.0);
    $nw = max(1, (int)round($w * $scale));
    $nh = max(1, (int)round($h * $scale));

    $dst = imagecreatetruecolor($nw, $nh);
    // Fondo blanco para PNG/GIF transparentes.
    $white = imagecolorallocate($dst, 255, 255, 255);
    imagefilledrectangle($dst, 0, 0, $nw, $nh, $white);
    imagecopyresampled($dst, $srcImg, 0, 0, 0, 0, $nw, $nh, $w, $h);

    if (!is_dir(PHOTO_DIR)) mkdir(PHOTO_DIR, 0750, true);
    $target = photo_path_for($part_id);
    $tmpOut = $target . '.new';
    $ok = imagejpeg($dst, $tmpOut, 82);
    imagedestroy($srcImg); imagedestroy($dst);
    if (!$ok) return ['ok' => false, 'error' => 'No se pudo guardar la imagen.'];

    // Sustituir de forma atómica: si algo va mal, dejar la anterior intacta.
    if (!rename($tmpOut, $target)) { @unlink($tmpOut); return ['ok' => false, 'error' => 'No se pudo escribir la imagen final.']; }
    @chmod($target, 0640);
    return ['ok' => true];
}

function photo_delete(int $part_id): bool {
    $p = photo_path_for($part_id);
    if (is_file($p)) return @unlink($p);
    return true;
}
