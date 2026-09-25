<?php
declare(strict_types=1);

function db(): PDO {
    if (empty($GLOBALS['__pdo'])) {
        if (!is_dir(DATA_DIR)) mkdir(DATA_DIR, 0755, true);
        $pdo = new PDO('sqlite:' . DB_FILE, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT            => 5,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $GLOBALS['__pdo'] = $pdo;
    }
    return $GLOBALS['__pdo'];
}

/** Fuerza a reabrir la conexión (utilizado tras restauración). */
function db_reset(): void {
    $GLOBALS['__pdo'] = null;
}

function db_init_schema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS boats (
        id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, name_norm TEXT NOT NULL UNIQUE,
        registration TEXT NOT NULL, registration_norm TEXT NOT NULL UNIQUE,
        is_active INTEGER NOT NULL DEFAULT 1, created_at TEXT NOT NULL DEFAULT (datetime('now')),
        updated_at TEXT NOT NULL DEFAULT (datetime('now')), deleted_at TEXT)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT NOT NULL, username_norm TEXT NOT NULL UNIQUE,
        first_name TEXT NOT NULL, last_name TEXT NOT NULL, password_hash TEXT NOT NULL,
        role TEXT NOT NULL CHECK (role IN ('admin','inspector','chief_engineer','mechanic')),
        boat_id INTEGER REFERENCES boats(id) ON DELETE RESTRICT,
        is_active INTEGER NOT NULL DEFAULT 1, is_primary_admin INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL DEFAULT (datetime('now')))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS login_attempts (
        id INTEGER PRIMARY KEY AUTOINCREMENT, ip TEXT NOT NULL, username_norm TEXT NOT NULL,
        failed_count INTEGER NOT NULL DEFAULT 0, locked_until INTEGER, updated_at INTEGER NOT NULL,
        UNIQUE (ip, username_norm))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS categories (
        id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, name_norm TEXT NOT NULL UNIQUE,
        is_system INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL DEFAULT (datetime('now')),
        updated_at TEXT NOT NULL DEFAULT (datetime('now')), deleted_at TEXT)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS audit_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT, at_utc TEXT NOT NULL,
        actor_id INTEGER, actor_username TEXT, operation TEXT NOT NULL, object_type TEXT NOT NULL,
        object_id INTEGER, boat_id INTEGER, old_data TEXT, new_data TEXT)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS parts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        boat_id INTEGER NOT NULL REFERENCES boats(id) ON DELETE RESTRICT,
        name TEXT NOT NULL, name_norm TEXT NOT NULL,
        reference TEXT NOT NULL DEFAULT '', reference_norm TEXT NOT NULL DEFAULT '',
        category_id INTEGER NOT NULL REFERENCES categories(id) ON DELETE RESTRICT,
        location TEXT NOT NULL, location_norm TEXT NOT NULL,
        quantity INTEGER NOT NULL DEFAULT 0 CHECK (quantity >= 0),
        notes TEXT NOT NULL DEFAULT '',
        photo_path TEXT,
        updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ','now')))");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_parts_boat ON parts(boat_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_parts_search ON parts(name_norm)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
        key TEXT PRIMARY KEY, value TEXT NOT NULL DEFAULT '')");
    $pdo->exec("INSERT OR IGNORE INTO categories (name, name_norm, is_system) VALUES ('Sin categoría', 'sin categoria', 1)");

    // --- Soporte para la API de la app Android (offline-first) ---
    // Carnets de acceso (tokens) por dispositivo. Ver api_contract_android.md.
    $pdo->exec("CREATE TABLE IF NOT EXISTS api_tokens (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        token_hash TEXT NOT NULL,
        device_label TEXT,
        created_at TEXT NOT NULL,
        last_used_at TEXT,
        revoked_at TEXT)");

    // "boats" y "categories" se marcan solas al crearse/editarse, para que
    // la app móvil sepa qué ha cambiado desde su última sincronización.
    // "parts" NO lleva estos triggers: ya gestiona su propio updated_at
    // a mano (con más precisión, usado también para detectar ediciones
    // simultáneas en parts.php) — añadirle un trigger aquí se lo pisaría.
    foreach (['boats', 'categories'] as $t) {
        $pdo->exec("CREATE TRIGGER IF NOT EXISTS trg_{$t}_stamp_insert
            AFTER INSERT ON $t BEGIN UPDATE $t SET updated_at = datetime('now') WHERE id = NEW.id; END");
        $pdo->exec("CREATE TRIGGER IF NOT EXISTS trg_{$t}_stamp_update
            AFTER UPDATE ON $t BEGIN UPDATE $t SET updated_at = datetime('now') WHERE id = NEW.id; END");
    }
}

function db_migrate(PDO $pdo): void {
    db_init_schema($pdo);
    $cols = $pdo->query("PRAGMA table_info(users)")->fetchAll();
    $has = false; foreach ($cols as $c) if ($c['name'] === 'boat_id') { $has = true; break; }
    if (!$has) $pdo->exec("ALTER TABLE users ADD COLUMN boat_id INTEGER REFERENCES boats(id) ON DELETE RESTRICT");
    // Normalizar name_norm de "Sin categoría" (sin acento) para búsquedas coherentes.
    $pdo->exec("UPDATE categories SET name_norm='sin categoria' WHERE is_system=1");

    // --- Soporte API Android: columnas añadidas después del lanzamiento inicial.
    // db_init_schema() ya las crea en instalaciones nuevas; esto cubre las
    // que ya existían antes de este cambio (como esta misma instalación).
    foreach (['boats', 'categories'] as $t) {
        $tcols = $pdo->query("PRAGMA table_info($t)")->fetchAll();
        $names = array_column($tcols, 'name');
        if (!in_array('updated_at', $names, true)) {
            $pdo->exec("ALTER TABLE $t ADD COLUMN updated_at TEXT");
            $pdo->exec("UPDATE $t SET updated_at = datetime('now') WHERE updated_at IS NULL");
        }
        if (!in_array('deleted_at', $names, true)) {
            $pdo->exec("ALTER TABLE $t ADD COLUMN deleted_at TEXT");
        }
    }
}

function is_installed(): bool { return file_exists(INSTALL_LOCK) && file_exists(DB_FILE); }

/** Retorna id de "Sin categoría" */
function system_category_id(): int {
    return (int)db()->query("SELECT id FROM categories WHERE is_system=1 LIMIT 1")->fetchColumn();
}
