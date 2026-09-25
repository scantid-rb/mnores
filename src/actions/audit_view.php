<?php
declare(strict_types=1);

$actor = require_role([ROLE_ADMIN, ROLE_INSPECTOR]);

$op   = trim((string)($_GET['op'] ?? ''));
$type = trim((string)($_GET['type'] ?? ''));
$user = trim((string)($_GET['user'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$per  = 100;

$where = []; $args = [];
if ($op   !== '') { $where[] = 'operation LIKE :op';     $args[':op']   = '%' . $op . '%'; }
if ($type !== '') { $where[] = 'object_type = :ot';       $args[':ot']   = $type; }
if ($user !== '') { $where[] = 'actor_username LIKE :au'; $args[':au']   = '%' . $user . '%'; }
$wsql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$totSt = db()->prepare("SELECT COUNT(*) FROM audit_log $wsql");
$totSt->execute($args); $total = (int)$totSt->fetchColumn();
$pages = max(1, (int)ceil($total / $per));
if ($page > $pages) $page = $pages;
$offset = ($page - 1) * $per;

$st = db()->prepare("SELECT * FROM audit_log $wsql ORDER BY id DESC LIMIT $per OFFSET $offset");
foreach ($args as $k=>$v) $st->bindValue($k, $v);
$st->execute();
$rows = $st->fetchAll();

// distinct object_types for filter
$types = db()->query('SELECT DISTINCT object_type FROM audit_log ORDER BY object_type')->fetchAll(PDO::FETCH_COLUMN);

render('audit/index', [
    'title' => 'Auditoría',
    'actor' => $actor,
    'rows'  => $rows,
    'op'    => $op,
    'type'  => $type,
    'user'  => $user,
    'page'  => $page,
    'pages' => $pages,
    'total' => $total,
    'types' => $types,
]);
