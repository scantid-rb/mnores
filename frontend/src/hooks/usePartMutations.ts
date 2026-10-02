// Local-first mutations. Each writes SQLite immediately (part + queue in a
// transaction), invalidates the affected read queries, then kicks a sync pass
// (which is a no-op offline). The user sees the change instantly regardless of
// connectivity.

import { useMutation, useQueryClient } from "@tanstack/react-query";

import { inventoryRepository } from "@/src/repositories/inventoryRepository";
import { useSync } from "@/src/state/SyncContext";
import { useSession } from "@/src/state/SessionContext";
import { CreatePartInput, EditablePartFields } from "@/src/types";
import { newLocalId } from "@/src/utils/id";
import { canAccessBoat, canCreatePart, canDeletePart, canEditFields, canEditQuantity } from "@/src/utils/permissions";

function useAfterMutation() {
  const qc = useQueryClient();
  const { syncNow, refreshPending } = useSync();
  return (rowUid?: string) => {
    qc.invalidateQueries({ queryKey: ["parts"] });
    qc.invalidateQueries({ queryKey: ["counts"] });
    if (rowUid) qc.invalidateQueries({ queryKey: ["part", rowUid] });
    void refreshPending();
    void syncNow(); // fire-and-forget; guarded + offline-safe
  };
}

export function useCreatePart() {
  const { mode, user } = useSession();
  const after = useAfterMutation();
  return useMutation({
    // This operation writes the local database; sync handles network access.
    networkMode: "always",
    mutationFn: (input: Omit<CreatePartInput, "local_id">) => {
      if (mode === "readonly") throw new Error("El inventario está en modo solo lectura.");
      if (!canCreatePart(user?.role) || !canAccessBoat(user, input.boat_id)) throw new Error("No tienes permiso para crear repuestos en este barco.");
      return inventoryRepository.createPart({ ...input, local_id: newLocalId() });
    },
    onSuccess: (part) => after(part.row_uid),
  });
}

export function useUpdatePart() {
  const { mode, user } = useSession();
  const after = useAfterMutation();
  return useMutation({
    // This operation writes the local database; sync handles network access.
    networkMode: "always",
    mutationFn: async ({ rowUid, fields }: { rowUid: string; fields: EditablePartFields }) => {
      if (mode === "readonly") throw new Error("El inventario está en modo solo lectura.");
      const part = await inventoryRepository.getPart(rowUid);
      if (!part || !canAccessBoat(user, part.boat_id) || !canEditQuantity(user?.role)) throw new Error("No tienes permiso para editar este repuesto.");
      if (!canEditFields(user?.role) && Object.keys(fields).some((key) => key !== "quantity")) throw new Error("Solo puedes modificar la cantidad.");
      return inventoryRepository.updatePart(rowUid, fields);
    },
    onSuccess: (_r, vars) => after(vars.rowUid),
  });
}

export function useDeletePart() {
  const { mode, user } = useSession();
  const after = useAfterMutation();
  return useMutation({
    // This operation writes the local database; sync handles network access.
    networkMode: "always",
    mutationFn: async (rowUid: string) => {
      if (mode === "readonly") throw new Error("El inventario está en modo solo lectura.");
      const part = await inventoryRepository.getPart(rowUid);
      if (!part || !canDeletePart(user?.role) || !canAccessBoat(user, part.boat_id)) throw new Error("No tienes permiso para eliminar este repuesto.");
      return inventoryRepository.deletePart(rowUid);
    },
    onSuccess: (_r, rowUid) => after(rowUid),
  });
}
