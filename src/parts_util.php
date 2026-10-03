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

/** Un barco eliminado sigue existiendo para historial, pero no admite escrituras. */
function parts_boat_available(int $boatId): bool {
    $st = db()->prepare('SELECT 1 FROM boats WHERE id=:id AND deleted_at IS NULL');
    $st->execute([':id' => $boatId]);
    return (bool)$st->fetchColumn();
}

function parts_can_create(array $actor, int $boat_id): bool {
    if (!parts_boat_available($boat_id)) return false;
    if (in_array($actor['role'], [ROLE_ADMIN, ROLE_INSPECTOR], true)) return true;
    if ($actor['role'] === ROLE_CHIEF) return (int)($actor['boat_id'] ?? 0) === $boat_id && (int)$boat_id > 0;
    return false;
}

function parts_can_edit_all(array $actor, array $part): bool {
    if (!parts_boat_available((int)$part['boat_id'])) return false;
    // Editar todos los campos (excepto ID).
    if (in_array($actor['role'], [ROLE_ADMIN, ROLE_INSPECTOR], true)) return true;
    if ($actor['role'] === ROLE_CHIEF) return (int)($actor['boat_id'] ?? 0) === (int)$part['boat_id'];
    return false;
}

function parts_can_edit_quantity(array $actor, array $part): bool {
    if (!parts_boat_available((int)$part['boat_id'])) return false;
    if (parts_can_edit_all($actor, $part)) return true;
    if ($actor['role'] === ROLE_MECHANIC) return (int)($actor['boat_id'] ?? 0) === (int)$part['boat_id'];
    return false;
}

function parts_can_manage_photo(array $actor, array $part): bool {
    if (!parts_boat_available((int)$part['boat_id'])) return false;
    // Chief y Mechanic pueden gestionar foto en su barco. Admin/Inspector siempre.
    if (in_array($actor['role'], [ROLE_ADMIN, ROLE_INSPECTOR], true)) return true;
    if (in_array($actor['role'], [ROLE_CHIEF, ROLE_MECHANIC], true))
        return (int)($actor['boat_id'] ?? 0) === (int)$part['boat_id'];
    return false;
}

function parts_can_delete(array $actor, array $part): bool {
    if (!parts_boat_available((int)$part['boat_id'])) return false;
    if (in_array($actor['role'], [ROLE_ADMIN, ROLE_INSPECTOR], true)) return true;
    if ($actor['role'] === ROLE_CHIEF) return (int)($actor['boat_id'] ?? 0) === (int)$part['boat_id'];
    return false;
}
