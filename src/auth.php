<?php
declare(strict_types=1);

const ROLE_ADMIN     = 'admin';
const ROLE_INSPECTOR = 'inspector';
const ROLE_CHIEF     = 'chief_engineer';
const ROLE_MECHANIC  = 'mechanic';

function normalize_username(string $u): string {
    return mb_strtolower(trim($u), 'UTF-8');
}

function normalize_name(string $s): string {
    // Colapsar espacios internos y minúsculas para comparar.
    $s = preg_replace('/\s+/u', ' ', trim($s)) ?? '';
    return mb_strtolower($s, 'UTF-8');
}

function current_user(): ?array {
    if (empty($_SESSION['user_id'])) return null;
    static $cached = null;
    if ($cached !== null && $cached['id'] === $_SESSION['user_id']) return $cached;
    $stmt = db()->prepare('SELECT u.*, b.name AS boat_name, b.is_active AS boat_is_active
                           FROM users u
                           LEFT JOIN boats b ON b.id = u.boat_id
                           WHERE u.id = :id AND u.is_active = 1');
    $stmt->execute([':id' => $_SESSION['user_id']]);
    $row = $stmt->fetch();
    if (!$row) { $_SESSION = []; return null; }
    $cached = $row;
    return $row;
}

function require_login(): array {
    $u = current_user();
    if (!$u) redirect('/login');
    return $u;
}

function require_guest(): void {
    if (current_user()) redirect('/home');
}

function require_role(array $roles): array {
    $u = require_login();
    if (!in_array($u['role'], $roles, true)) {
        http_response_code(403);
        echo '<!doctype html><meta charset="utf-8"><title>403</title><p style="font-family:sans-serif;padding:2rem">No autorizado. <a href="' . e(url('/home')) . '">Inicio</a></p>';
        exit;
    }
    return $u;
}

function role_label(string $role): string {
    return match ($role) {
        ROLE_ADMIN     => 'Administrador',
        ROLE_INSPECTOR => 'Inspector',
        ROLE_CHIEF     => 'Jefe de Máquinas',
        ROLE_MECHANIC  => 'Mecánico',
        default        => $role,
    };
}

function all_roles(): array {
    return [ROLE_ADMIN, ROLE_INSPECTOR, ROLE_CHIEF, ROLE_MECHANIC];
}

/* ---------- Autorización sobre gestión de usuarios ---------- */

/** ¿Qué roles puede crear/editar/eliminar el actor? */
function manageable_roles_for(array $actor): array {
    if ($actor['role'] === ROLE_ADMIN)     return all_roles();
    if ($actor['role'] === ROLE_INSPECTOR) return [ROLE_CHIEF, ROLE_MECHANIC];
    if ($actor['role'] === ROLE_CHIEF)     return [ROLE_MECHANIC];
    return [];
}

function can_manage_users(array $actor): bool {
    return in_array($actor['role'], [ROLE_ADMIN, ROLE_INSPECTOR, ROLE_CHIEF], true);
}

function can_manage_user(array $actor, array $target): bool {
    if (!can_manage_users($actor)) return false;
    if (!in_array($target['role'], manageable_roles_for($actor), true)) return false;
    // Chief solo sobre mecánicos de su MISMO barco.
    if ($actor['role'] === ROLE_CHIEF) {
        $ab = (int)($actor['boat_id']  ?? 0);
        $tb = (int)($target['boat_id'] ?? 0);
        if ($ab <= 0 || $ab !== $tb) return false;
    }
    return true;
}

function can_manage_boats(array $actor): bool {
    return in_array($actor['role'], [ROLE_ADMIN, ROLE_INSPECTOR], true);
}

function can_delete_boat(array $actor): bool {
    return $actor['role'] === ROLE_ADMIN;
}

function can_manage_categories(array $actor): bool {
    return in_array($actor['role'], [ROLE_ADMIN, ROLE_INSPECTOR], true);
}

/* ---------- Fuerza bruta ---------- */

function brute_is_locked(string $ip, string $username_norm): int {
    $stmt = db()->prepare('SELECT locked_until FROM login_attempts WHERE ip = :ip AND username_norm = :u');
    $stmt->execute([':ip' => $ip, ':u' => $username_norm]);
    $row = $stmt->fetch();
    if ($row && $row['locked_until'] && $row['locked_until'] > time()) {
        return (int)$row['locked_until'] - time();
    }
    return 0;
}

function brute_register_failure(string $ip, string $username_norm): void {
    $pdo = db();
    $now = time();
    $stmt = $pdo->prepare('SELECT id, failed_count FROM login_attempts WHERE ip = :ip AND username_norm = :u');
    $stmt->execute([':ip' => $ip, ':u' => $username_norm]);
    $row = $stmt->fetch();
    if (!$row) {
        $ins = $pdo->prepare('INSERT INTO login_attempts (ip, username_norm, failed_count, locked_until, updated_at)
                              VALUES (:ip, :u, 1, NULL, :t)');
        $ins->execute([':ip' => $ip, ':u' => $username_norm, ':t' => $now]);
        return;
    }
    $count = (int)$row['failed_count'] + 1;
    $lock  = null;
    if ($count >= BRUTE_MAX_ATTEMPTS) {
        $lock  = $now + BRUTE_LOCK_SECONDS;
        $count = 0;
    }
    $upd = $pdo->prepare('UPDATE login_attempts SET failed_count = :c, locked_until = :l, updated_at = :t WHERE id = :id');
    $upd->execute([':c' => $count, ':l' => $lock, ':t' => $now, ':id' => $row['id']]);
}

function brute_reset(string $ip, string $username_norm): void {
    $stmt = db()->prepare('DELETE FROM login_attempts WHERE ip = :ip AND username_norm = :u');
    $stmt->execute([':ip' => $ip, ':u' => $username_norm]);
}
