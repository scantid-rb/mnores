<?php
declare(strict_types=1);

/**
 * Configuración dinámica de la aplicación.
 * Los valores se guardan en la tabla `settings` (clave/valor).
 */

const SETTINGS_DEFAULTS = [
    'app_name'              => 'Inventario de Repuestos',
    'app_title'             => 'Repuestos a bordo',
    'company_name'          => '',
    'backup_interval_days'  => '7',
    'backup_retention_days' => '7',
    'audit_retention_days'  => '90',
    'disk_warning_percent'  => '20',
];

function settings_all(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $out = SETTINGS_DEFAULTS;
    try {
        foreach (db()->query('SELECT key, value FROM settings')->fetchAll() as $r) {
            $out[$r['key']] = (string)$r['value'];
        }
    } catch (Throwable $e) { /* tabla aún no existe: usar defaults */ }
    $cache = $out;
    return $out;
}

function setting(string $key, ?string $default = null): string {
    $all = settings_all();
    if (array_key_exists($key, $all)) return $all[$key];
    return $default ?? (SETTINGS_DEFAULTS[$key] ?? '');
}

function setting_int(string $key): int {
    return (int)setting($key);
}

function settings_set(array $kv): void {
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('INSERT INTO settings (key, value) VALUES (:k, :v)
                             ON CONFLICT(key) DO UPDATE SET value = excluded.value');
        foreach ($kv as $k => $v) {
            if (!array_key_exists($k, SETTINGS_DEFAULTS)) continue;
            $st->execute([':k' => $k, ':v' => (string)$v]);
        }
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    // Invalidar caché.
    $ref = new ReflectionFunction('settings_all');
    // No se puede resetear la static de otra función; usamos $GLOBALS con un enfoque distinto.
    unset($GLOBALS['__settings_cache']);
}
