<?php
declare(strict_types=1);

$actor = require_role([ROLE_ADMIN, ROLE_INSPECTOR, ROLE_CHIEF, ROLE_MECHANIC]);
$path  = current_path();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

function _load_part(int $id): ?array {
    $s = db()->prepare('SELECT p.*, b.name AS boat_name, c.name AS category_name FROM parts p
                        JOIN boats b ON b.id=p.boat_id LEFT JOIN categories c ON c.id=p.category_id
                        WHERE p.id=:id');
    $s->execute([':id' => $id]); $r = $s->fetch(); return $r ?: null;
}
function _now(): string { return gmdate('Y-m-d\TH:i:s.') . substr(sprintf('%03d', (int)(microtime(true)*1000)%1000), 0, 3) . 'Z'; }

/* ---------------- LISTADO ---------------- */
if ($path === '/parts' && $method === 'GET') {
    $q         = trim((string)($_GET['q'] ?? ''));
    $cat       = trim((string)($_GET['category_id'] ?? ''));
    $sortBy    = (string)($_GET['sort'] ?? 'name');
    $sortDir   = strtolower((string)($_GET['dir'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';
    $page      = max(1, (int)($_GET['page'] ?? 1));
    $perPage   = 50;

    $sortMap = ['name'=>'p.name_norm','reference'=>'p.reference_norm','category'=>'c.name','location'=>'p.location_norm','quantity'=>'p.quantity'];
    $sortCol = $sortMap[$sortBy] ?? 'p.name_norm';

    // Boat selector según rol.
    $forcedBoat = parts_visible_boat_id($actor);
    if ($forcedBoat === null) {
        $bsel = trim((string)($_GET['boat_id'] ?? ''));
        $boat_id = ($bsel === '' ? null : (int)$bsel);
    } else {
        $boat_id = $forcedBoat > 0 ? $forcedBoat : -1; // -1 = no ver nada
    }

    $where = []; $args = [];
    if ($boat_id === -1) {
        $where[] = '0=1';
    } elseif ($boat_id !== null) {
        $where[] = 'p.boat_id = :b'; $args[':b'] = $boat_id;
    }
    if ($q !== '') {
        $qn = search_norm($q);
        $where[] = '(p.name_norm LIKE :q OR p.reference_norm LIKE :q OR p.location_norm LIKE :q OR c.name_norm LIKE :q)';
        $args[':q'] = '%' . $qn . '%';
    }
    if ($cat !== '') { $where[] = 'p.category_id = :c'; $args[':c'] = (int)$cat; }
    $wsql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $totSt = db()->prepare("SELECT COUNT(*) FROM parts p JOIN boats b ON b.id=p.boat_id LEFT JOIN categories c ON c.id=p.category_id $wsql");
    $totSt->execute($args); $total = (int)$totSt->fetchColumn();
    $pages = max(1, (int)ceil($total / $perPage));
    if ($page > $pages) $page = $pages;
    $offset = ($page - 1) * $perPage;

    $st = db()->prepare("SELECT p.*, b.name AS boat_name, c.name AS category_name
                         FROM parts p JOIN boats b ON b.id=p.boat_id LEFT JOIN categories c ON c.id=p.category_id
                         $wsql ORDER BY $sortCol $sortDir, p.id ASC LIMIT $perPage OFFSET $offset");
    foreach ($args as $k=>$v) $st->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
    $st->execute();
    $rows = $st->fetchAll();

    $boats = db()->query('SELECT id, name, is_active FROM boats ORDER BY name')->fetchAll();
    $cats  = db()->query('SELECT id, name FROM categories ORDER BY is_system DESC, name')->fetchAll();

    render('parts/index', compact('actor','rows','boats','cats','q','cat','sortBy','sortDir','page','pages','total','boat_id','forcedBoat') + ['title'=>'Inventario']);
    return;
}

/* ---------------- CREAR ---------------- */
if ($path === '/parts/new' && $method === 'GET') {
    $forcedBoat = parts_visible_boat_id($actor);
    if ($actor['role'] === ROLE_MECHANIC) { http_response_code(403); echo 'No autorizado'; return; }
    $boats = db()->query('SELECT id,name,is_active FROM boats WHERE is_active=1 ORDER BY name')->fetchAll();
    $cats  = db()->query('SELECT id,name FROM categories ORDER BY is_system DESC, name')->fetchAll();
    render('parts/form', ['title'=>'Nuevo repuesto','actor'=>$actor,'part'=>null,'boats'=>$boats,'cats'=>$cats,'errors'=>[],'forcedBoat'=>$forcedBoat,'warning'=>null]);
    return;
}

if ($path === '/parts' && $method === 'POST') {
    csrf_check();
    $d = _read_part_form();
    $errors = _validate_part_form($d);
    if (!$errors && !parts_can_create($actor, $d['boat_id'])) $errors[] = 'No autorizado sobre este barco.';
    // Advertencia de duplicado (no bloquea).
    $warning = null;
    if (!$errors && ($_POST['confirm_duplicate'] ?? '') !== '1') {
        $dup = db()->prepare('SELECT id, name, reference FROM parts
                              WHERE boat_id=:b AND name_norm=:n AND reference_norm=:r AND category_id=:c AND location_norm=:l');
        $dup->execute([':b'=>$d['boat_id'], ':n'=>search_norm($d['name']), ':r'=>search_norm($d['reference']), ':c'=>$d['category_id'], ':l'=>search_norm($d['location'])]);
        $dupRow = $dup->fetch();
        if ($dupRow) $warning = 'Ya existe un repuesto similar (ID ' . (int)$dupRow['id'] . '). Marca la confirmación para guardarlo igualmente.';
    }
    if ($errors || $warning) {
        $boats = db()->query('SELECT id,name,is_active FROM boats WHERE is_active=1 ORDER BY name')->fetchAll();
        $cats  = db()->query('SELECT id,name FROM categories ORDER BY is_system DESC, name')->fetchAll();
        render('parts/form', ['title'=>'Nuevo repuesto','actor'=>$actor,'part'=>null,'boats'=>$boats,'cats'=>$cats,'errors'=>$errors,'forcedBoat'=>parts_visible_boat_id($actor),'input'=>$d,'warning'=>$warning]);
        return;
    }
    $now = _now();
    $st = db()->prepare('INSERT INTO parts (boat_id,name,name_norm,reference,reference_norm,category_id,location,location_norm,quantity,notes,updated_at)
                         VALUES (:b,:n,:nn,:r,:rn,:c,:l,:ln,:q,:no,:t)');
    $st->execute([':b'=>$d['boat_id'], ':n'=>$d['name'], ':nn'=>search_norm($d['name']),
        ':r'=>$d['reference'], ':rn'=>search_norm($d['reference']),
        ':c'=>$d['category_id'], ':l'=>$d['location'], ':ln'=>search_norm($d['location']),
        ':q'=>$d['quantity'], ':no'=>$d['notes'], ':t'=>$now]);
    $newId = (int)db()->lastInsertId();
    audit_log('part.create', 'part', $newId, $d['boat_id'], null, $d);
    $_SESSION['flash_success'] = 'Repuesto creado.';
    redirect('/parts/' . $newId);
}

/* ---------------- FICHA / EDITAR / ELIMINAR / FOTO ---------------- */
if (preg_match('#^/parts/(\d+)(?:/(edit|delete|quantity|photo|photo/delete))?$#', $path, $m)) {
    $id = (int)$m[1]; $sub = $m[2] ?? '';
    $p = _load_part($id);
    if (!$p) { http_response_code(404); echo 'No encontrado'; return; }
    if (!parts_can_view($actor, $p)) { http_response_code(403); echo 'No autorizado'; return; }

    if ($sub === 'photo' && $method === 'GET') {
        // Servir la foto solo a usuarios con acceso.
        $file = photo_path_for($id);
        if (!is_file($file)) { http_response_code(404); return; }
        header('Content-Type: image/jpeg'); header('Cache-Control: private, max-age=60');
        readfile($file); return;
    }

    if ($sub === '' && $method === 'GET') {
        render('parts/show', ['title'=>$p['name'],'actor'=>$actor,'part'=>$p]); return;
    }

    if ($sub === 'edit') {
        if (!parts_can_edit_all($actor, $p)) { http_response_code(403); echo 'No autorizado'; return; }
        if ($method === 'POST') {
            csrf_check();
            $d = _read_part_form();
            $errors = _validate_part_form($d);
            // Concurrencia: comparar updated_at enviado.
            $prev = (string)($_POST['updated_at'] ?? '');
            if ($prev !== '' && $prev !== $p['updated_at']) $errors[] = 'Este repuesto ha sido modificado por otro usuario. Recargue la ficha antes de guardar.';
            // Chief no puede cambiar boat_id fuera del suyo.
            if (!$errors && !parts_can_create($actor, $d['boat_id'])) $errors[] = 'No autorizado sobre ese barco.';
            if ($errors) {
                $boats = db()->query('SELECT id,name,is_active FROM boats ORDER BY name')->fetchAll();
                $cats  = db()->query('SELECT id,name FROM categories ORDER BY is_system DESC, name')->fetchAll();
                render('parts/form', ['title'=>'Editar repuesto','actor'=>$actor,'part'=>$p,'boats'=>$boats,'cats'=>$cats,'errors'=>$errors,'forcedBoat'=>parts_visible_boat_id($actor),'input'=>$d,'warning'=>null]);
                return;
            }
            $old = ['name'=>$p['name'],'reference'=>$p['reference'],'category_id'=>(int)$p['category_id'],'location'=>$p['location'],'quantity'=>(int)$p['quantity'],'notes'=>$p['notes'],'boat_id'=>(int)$p['boat_id']];
            $now = _now();
            $st = db()->prepare('UPDATE parts SET boat_id=:b,name=:n,name_norm=:nn,reference=:r,reference_norm=:rn,
                category_id=:c,location=:l,location_norm=:ln,quantity=:q,notes=:no,updated_at=:t WHERE id=:id');
            $st->execute([':b'=>$d['boat_id'],':n'=>$d['name'],':nn'=>search_norm($d['name']),
                ':r'=>$d['reference'],':rn'=>search_norm($d['reference']),
                ':c'=>$d['category_id'],':l'=>$d['location'],':ln'=>search_norm($d['location']),
                ':q'=>$d['quantity'],':no'=>$d['notes'],':t'=>$now,':id'=>$id]);
            audit_log('part.update','part',$id,$d['boat_id'],$old,$d);
            if ((int)$old['category_id'] !== (int)$d['category_id']) audit_log('part.category_change','part',$id,$d['boat_id'],['category_id'=>$old['category_id']],['category_id'=>$d['category_id']]);
            if ((int)$old['quantity'] !== (int)$d['quantity']) audit_log('part.quantity_change','part',$id,$d['boat_id'],['quantity'=>$old['quantity']],['quantity'=>$d['quantity']]);
            $_SESSION['flash_success'] = 'Repuesto actualizado.';
            redirect('/parts/' . $id);
        }
        $boats = db()->query('SELECT id,name,is_active FROM boats ORDER BY name')->fetchAll();
        $cats  = db()->query('SELECT id,name FROM categories ORDER BY is_system DESC, name')->fetchAll();
        render('parts/form', ['title'=>'Editar repuesto','actor'=>$actor,'part'=>$p,'boats'=>$boats,'cats'=>$cats,'errors'=>[],'forcedBoat'=>parts_visible_boat_id($actor),'warning'=>null]);
        return;
    }

    if ($sub === 'quantity' && $method === 'POST') {
        csrf_check();
        if (!parts_can_edit_quantity($actor, $p)) { http_response_code(403); echo 'No autorizado'; return; }
        $qty = (int)($_POST['quantity'] ?? -1);
        $prev = (string)($_POST['updated_at'] ?? '');
        if ($qty < 0)  { $_SESSION['flash_error'] = 'La cantidad debe ser 0 o mayor.'; redirect('/parts/'.$id); }
        if ($prev !== '' && $prev !== $p['updated_at']) { $_SESSION['flash_error'] = 'Este repuesto ha sido modificado por otro usuario. Recargue la ficha antes de guardar.'; redirect('/parts/'.$id); }
        $now = _now();
        db()->prepare('UPDATE parts SET quantity=:q, updated_at=:t WHERE id=:id')->execute([':q'=>$qty,':t'=>$now,':id'=>$id]);
        audit_log('part.quantity_change','part',$id,(int)$p['boat_id'],['quantity'=>(int)$p['quantity']],['quantity'=>$qty]);
        $_SESSION['flash_success'] = 'Cantidad actualizada.';
        redirect('/parts/'.$id);
    }

    if ($sub === 'delete' && $method === 'POST') {
        csrf_check();
        if (!parts_can_delete($actor, $p)) { http_response_code(403); echo 'No autorizado'; return; }
        photo_delete($id);
        db()->prepare('DELETE FROM parts WHERE id=:id')->execute([':id'=>$id]);
        audit_log('part.delete','part',$id,(int)$p['boat_id'],['name'=>$p['name'],'reference'=>$p['reference']]);
        $_SESSION['flash_success'] = 'Repuesto eliminado.';
        redirect('/parts');
    }

    if ($sub === 'photo' && $method === 'POST') {
        csrf_check();
        if (!parts_can_manage_photo($actor, $p)) { http_response_code(403); echo 'No autorizado'; return; }
        $had = !empty($p['photo_path']) && is_file(photo_path_for($id));
        $res = photo_process_upload($_FILES['photo'] ?? [], $id);
        if (!$res['ok']) { $_SESSION['flash_error'] = $res['error'] ?? 'Error al procesar la imagen.'; redirect('/parts/'.$id); }
        db()->prepare('UPDATE parts SET photo_path=:pp, updated_at=:t WHERE id=:id')->execute([':pp'=>photo_path_for($id), ':t'=>_now(), ':id'=>$id]);
        audit_log($had ? 'part.photo_replace' : 'part.photo_add','part',$id,(int)$p['boat_id']);
        $_SESSION['flash_success'] = $had ? 'Fotografía sustituida.' : 'Fotografía añadida.';
        redirect('/parts/'.$id);
    }

    if ($sub === 'photo/delete' && $method === 'POST') {
        csrf_check();
        if (!parts_can_manage_photo($actor, $p)) { http_response_code(403); echo 'No autorizado'; return; }
        photo_delete($id);
        db()->prepare('UPDATE parts SET photo_path=NULL, updated_at=:t WHERE id=:id')->execute([':t'=>_now(),':id'=>$id]);
        audit_log('part.photo_delete','part',$id,(int)$p['boat_id']);
        $_SESSION['flash_success'] = 'Fotografía eliminada.';
        redirect('/parts/'.$id);
    }
}

http_response_code(404); echo 'No encontrado';

/* ---------- helpers de formulario ---------- */
function _read_part_form(): array {
    return [
        'boat_id'    => (int)($_POST['boat_id'] ?? 0),
        'name'       => trim((string)($_POST['name'] ?? '')),
        'reference'  => trim((string)($_POST['reference'] ?? '')),
        'category_id'=> (int)($_POST['category_id'] ?? 0),
        'location'   => trim((string)($_POST['location'] ?? '')),
        'quantity'   => max(0, (int)($_POST['quantity'] ?? 0)),
        'notes'      => trim((string)($_POST['notes'] ?? '')),
    ];
}
function _validate_part_form(array $d): array {
    $errs = [];
    if ($d['name'] === '' || mb_strlen($d['name']) > 160) $errs[] = 'Nombre obligatorio.';
    if (mb_strlen($d['reference']) > 80) $errs[] = 'Referencia demasiado larga.';
    if ($d['location'] === '' || mb_strlen($d['location']) > 120) $errs[] = 'Ubicación obligatoria.';
    if ($d['category_id'] <= 0) $errs[] = 'Categoría obligatoria.';
    if ($d['quantity'] < 0) $errs[] = 'La cantidad debe ser 0 o mayor.';
    if ($d['boat_id'] <= 0) $errs[] = 'Barco obligatorio.';
    if ($d['category_id'] > 0) {
        $ok = db()->prepare('SELECT 1 FROM categories WHERE id=:id'); $ok->execute([':id'=>$d['category_id']]);
        if (!$ok->fetch()) $errs[] = 'Categoría no válida.';
    }
    if ($d['boat_id'] > 0) {
        $ok = db()->prepare('SELECT is_active FROM boats WHERE id=:id'); $ok->execute([':id'=>$d['boat_id']]);
        $r = $ok->fetch();
        if (!$r) $errs[] = 'Barco no válido.';
    }
    return $errs;
}
