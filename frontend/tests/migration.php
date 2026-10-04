<?php
declare(strict_types=1);
require __DIR__ . '/../../src/db.php';
function check(bool $value, string $message): void {
    if (!$value) throw new RuntimeException($message);
}
foreach (['fresh', 'missing-tombstones', 'missing-timestamps'] as $variant) {
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    if ($variant !== 'fresh') {
        $time = $variant === 'missing-timestamps' ? '' : ', updated_at TEXT';
        $pdo->exec("CREATE TABLE boats(id INTEGER PRIMARY KEY, name TEXT, name_norm TEXT UNIQUE, registration TEXT, registration_norm TEXT UNIQUE, is_active INTEGER DEFAULT 1 $time)");
        $pdo->exec("CREATE TABLE categories(id INTEGER PRIMARY KEY, name TEXT, name_norm TEXT UNIQUE, is_system INTEGER DEFAULT 0 $time)");
        $pdo->exec("CREATE TABLE parts(id INTEGER PRIMARY KEY, boat_id INTEGER, name TEXT, name_norm TEXT, reference TEXT, reference_norm TEXT, category_id INTEGER, location TEXT, location_norm TEXT, quantity INTEGER, notes TEXT, photo_path TEXT $time)");
        $pdo->exec("INSERT INTO boats(id,name,name_norm,registration,registration_norm) VALUES(7,'Old boat','old boat','OLD','old')");
        $pdo->exec("INSERT INTO categories(id,name,name_norm) VALUES(8,'Old category','old category')");
        $pdo->exec("INSERT INTO parts(id,boat_id,name,name_norm,category_id,quantity,notes) VALUES(9,7,'Old part','old part',8,3,'Retained')");
    }
    // Exercise both direct initialization (installer) and repeat migrations.
    db_init_schema($pdo);
    db_migrate($pdo);
    db_migrate($pdo);
    foreach (['boats', 'categories', 'parts'] as $table) {
        $columns = array_column($pdo->query("PRAGMA table_info($table)")->fetchAll(), 'name');
        check(in_array('updated_at', $columns, true), "$variant: $table.updated_at");
        check(in_array('deleted_at', $columns, true), "$variant: $table.deleted_at");
        $indexes = array_column($pdo->query("PRAGMA index_list($table)")->fetchAll(), 'name');
        check(in_array("idx_{$table}_deleted_at", $indexes, true), "$variant: $table tombstone index");
        $pdo->query("SELECT * FROM $table WHERE deleted_at IS NULL")->fetchAll();
    }
    if ($variant !== 'fresh') {
        check($pdo->query('SELECT name FROM boats WHERE id=7')->fetchColumn() === 'Old boat', 'Boat preserved');
        check($pdo->query('SELECT name FROM categories WHERE id=8')->fetchColumn() === 'Old category', 'Category preserved');
        check($pdo->query('SELECT notes FROM parts WHERE id=9')->fetchColumn() === 'Retained', 'Part preserved');
        check((int)$pdo->query('SELECT quantity FROM parts WHERE id=9')->fetchColumn() === 3, 'Quantity preserved');
    }
    $pdo->exec("INSERT INTO boats(name,name_norm,registration,registration_norm) VALUES('New boat','new boat','NEW','new')");
    check(str_contains((string)$pdo->query("SELECT updated_at FROM boats WHERE name_norm='new boat'")->fetchColumn(), 'T'), 'Insert trigger works');
    $pdo->exec("UPDATE boats SET name='Updated' WHERE name_norm='new boat'");
    check(str_contains((string)$pdo->query("SELECT updated_at FROM boats WHERE name_norm='new boat'")->fetchColumn(), 'T'), 'Update trigger works');
    echo "PASS migration $variant\n";
}
