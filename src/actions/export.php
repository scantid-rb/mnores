<?php
declare(strict_types=1);

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

$actor = require_role([ROLE_ADMIN, ROLE_INSPECTOR]);
$path  = current_path();

if (!preg_match('#^/export/parts/(\d+)$#', $path, $m)) {
    http_response_code(404); echo 'No encontrado'; return;
}

$boat_id = (int)$m[1];
$boat = db()->prepare('SELECT * FROM boats WHERE id=:id');
$boat->execute([':id'=>$boat_id]);
$boat = $boat->fetch();
if (!$boat) { http_response_code(404); echo 'Barco no encontrado'; return; }

if (!class_exists(Spreadsheet::class)) {
    http_response_code(500);
    echo 'PhpSpreadsheet no disponible. Instale las dependencias con composer.';
    return;
}

$rows = db()->prepare('SELECT p.name, p.reference, p.location, p.quantity, p.notes, c.name AS category_name
                       FROM parts p LEFT JOIN categories c ON c.id=p.category_id
                       WHERE p.boat_id=:b
                       ORDER BY c.name ASC, p.name_norm ASC');
$rows->bindValue(':b', $boat_id, PDO::PARAM_INT);
$rows->execute();
$all = $rows->fetchAll();

// Agrupar por categoría, saltar vacías.
$byCat = [];
foreach ($all as $r) {
    $cat = $r['category_name'] ?: 'Sin categoría';
    $byCat[$cat][] = $r;
}

$sheetTitle = function(string $s): string {
    // Excel no permite: : \ / ? * [ ] y máx 31 chars
    $s = preg_replace('/[:\\\\\\/\?\*\[\]]/', '_', $s) ?? '';
    return mb_substr(trim($s), 0, 31, 'UTF-8') ?: 'Hoja';
};

$sheetNames = [];
$spreadsheet = new Spreadsheet();
$spreadsheet->removeSheetByIndex(0);

$idx = 0;
foreach ($byCat as $cat => $items) {
    if (!$items) continue;
    $name = $sheetTitle($cat);
    // Evitar duplicados por truncado.
    $base = $name; $n = 1;
    while (in_array(mb_strtolower($name), $sheetNames, true)) { $name = mb_substr($base, 0, 28) . '_' . (++$n); }
    $sheetNames[] = mb_strtolower($name);

    $ws = $spreadsheet->createSheet($idx++);
    $ws->setTitle($name);
    $ws->fromArray(['Nombre','Ubicación','Cantidad','Referencia','Notas'], null, 'A1');
    $ws->getStyle('A1:E1')->getFont()->setBold(true);
    $r = 2;
    foreach ($items as $it) {
        $ws->setCellValue("A$r", $it['name']);
        $ws->setCellValue("B$r", $it['location']);
        $ws->setCellValue("C$r", (int)$it['quantity']);
        $ws->setCellValue("D$r", $it['reference']);
        $ws->setCellValue("E$r", $it['notes']);
        $r++;
    }
    foreach (['A','B','D','E'] as $c) $ws->getColumnDimension($c)->setAutoSize(true);
    $ws->getColumnDimension('C')->setWidth(12);
    $ws->getStyle("C2:C" . max(2, $r-1))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    $ws->freezePane('A2');
}
if ($idx === 0) {
    // Al menos una hoja vacía informativa (nunca por categoría vacía).
    $ws = $spreadsheet->createSheet(0);
    $ws->setTitle('Inventario');
    $ws->setCellValue('A1', 'Sin repuestos para este barco.');
}
$spreadsheet->setActiveSheetIndex(0);

$safe = preg_replace('/[^A-Za-z0-9._-]+/u', '_', $boat['name']) ?? 'barco';
$safe = trim($safe, '_') ?: 'barco';
$fileName = "inventario_{$safe}.xlsx";

$tmp = tempnam(sys_get_temp_dir(), 'xlsx_') . '.xlsx';
(new Xlsx($spreadsheet))->save($tmp);

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $fileName . '"');
header('Content-Length: ' . filesize($tmp));
header('Cache-Control: no-store');
readfile($tmp);
@unlink($tmp);
audit_log('inventory.export','boat',$boat_id,$boat_id,null,['format'=>'xlsx','sheets'=>$idx]);
