<?php
declare(strict_types=1);

/** Normaliza para búsqueda: minúsculas + sin acentos + colapsar espacios. */
function search_norm(string $s): string {
    $s = trim($s);
    if ($s === '') return '';
    // Quitar acentos con iconv (transliteración a ASCII).
    $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
    if ($t !== false && $t !== '') $s = $t;
    $s = preg_replace('/\s+/', ' ', $s) ?? '';
    return strtolower($s);
}

/** Permisos sobre parts */
function parts_visible_boat_id(array $actor): ?int {
    // Devuelve el boat_id al que está restringido, o null si ve todos.
    if (in_array($actor['role'], [ROLE_ADMIN, ROLE_INSPECTOR], true)) return null;
    return $actor['boat_id'] ? (int)$actor['boat_id'] : 0;
}

function parts_can_view(array $actor, array $part): bool {
    $b = parts_visible_boat_id($actor);
    if ($b === null) return true;
    if ($b === 0) return false;
    return (int)$part['boat_id'] === $b;
}

function parts_can_create(array $actor, int $boat_id): bool {
    if (in_array($actor['role'], [ROLE_ADMIN, ROLE_INSPECTOR], true)) return true;
    if ($actor['role'] === ROLE_CHIEF) return (int)($actor['boat_id'] ?? 0) === $boat_id && (int)$boat_id > 0;
    return false;
}

function parts_can_edit_all(array $actor, array $part): bool {
    // Editar todos los campos (excepto ID).
    if (in_array($actor['role'], [ROLE_ADMIN, ROLE_INSPECTOR], true)) return true;
    if ($actor['role'] === ROLE_CHIEF) return (int)($actor['boat_id'] ?? 0) === (int)$part['boat_id'];
    return false;
}

function parts_can_edit_quantity(array $actor, array $part): bool {
    if (parts_can_edit_all($actor, $part)) return true;
    if ($actor['role'] === ROLE_MECHANIC) return (int)($actor['boat_id'] ?? 0) === (int)$part['boat_id'];
    return false;
}

function parts_can_manage_photo(array $actor, array $part): bool {
    // Chief y Mechanic pueden gestionar foto en su barco. Admin/Inspector siempre.
    if (in_array($actor['role'], [ROLE_ADMIN, ROLE_INSPECTOR], true)) return true;
    if (in_array($actor['role'], [ROLE_CHIEF, ROLE_MECHANIC], true))
        return (int)($actor['boat_id'] ?? 0) === (int)$part['boat_id'];
    return false;
}

function parts_can_delete(array $actor, array $part): bool {
    if (in_array($actor['role'], [ROLE_ADMIN, ROLE_INSPECTOR], true)) return true;
    if ($actor['role'] === ROLE_CHIEF) return (int)($actor['boat_id'] ?? 0) === (int)$part['boat_id'];
    return false;
}
