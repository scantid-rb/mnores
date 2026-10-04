// Role-based UI capabilities. The server remains the final authority (a
// forbidden result is still handled); this only decides which controls the
// UI shows. Admin/inspector are read-only on mobile until explicitly enabled.

import { Role, SessionUser } from "@/src/types";

export function canAccessBoat(user: SessionUser | null, boatId: number): boolean {
  return user?.role === "admin" || user?.role === "inspector" ||
    ((user?.role === "chief_engineer" || user?.role === "mechanic") &&
      user.boat_id != null && user.boat_id === boatId);
}

export function canCreatePart(role: Role | undefined): boolean {
  return role === "chief_engineer" || role === "admin" || role === "inspector";
}

export function canDeletePart(role: Role | undefined): boolean {
  return role === "chief_engineer" || role === "admin" || role === "inspector";
}

// Edit all descriptive fields (name, reference, category, location, notes).
export function canEditFields(role: Role | undefined): boolean {
  return role === "chief_engineer" || role === "admin" || role === "inspector";
}

// Change quantity (all roles allowed to edit inventory).
export function canEditQuantity(role: Role | undefined): boolean {
  return role === "chief_engineer" || role === "mechanic" || role === "admin" || role === "inspector";
}
