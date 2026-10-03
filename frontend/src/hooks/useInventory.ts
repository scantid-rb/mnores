// Read hooks over the LOCAL cache (SQLite). react-query only orchestrates the
// reads and re-renders on invalidation; SQLite is the real persistence.

import { useQuery } from "@tanstack/react-query";

import { inventoryRepository } from "@/src/repositories/inventoryRepository";
import { useSession } from "@/src/state/SessionContext";
import { canAccessBoat } from "@/src/utils/permissions";

export function useParts(query: string, categoryId: number | null, boatId: number | null = null) {
  const { user, mode } = useSession();
  const global = user?.role === "admin" || user?.role === "inspector";
  const effectiveBoatId = mode === "readonly" || global ? boatId : user?.boat_id ?? null;
  return useQuery({
    // IndexedDB/SQLite reads must also run on an offline reload.
    networkMode: "always",
    queryKey: ["parts", query, categoryId, effectiveBoatId, mode, user?.id, user?.role],
    queryFn: () => {
      if (mode !== "readonly" && !global && effectiveBoatId == null) return Promise.resolve([]);
      return inventoryRepository.searchParts(query, categoryId, effectiveBoatId);
    },
  });
}

export function useCategories() {
  return useQuery({
    // IndexedDB/SQLite reads must also run on an offline reload.
    networkMode: "always",
    queryKey: ["categories"],
    queryFn: () => inventoryRepository.getCategories(),
  });
}

export function useBoats() {
  return useQuery({
    // IndexedDB/SQLite reads must also run on an offline reload.
    networkMode: "always",
    queryKey: ["boats"],
    queryFn: () => inventoryRepository.getBoats(),
  });
}

export function usePart(rowUid: string) {
  const { user, mode } = useSession();
  return useQuery({
    // IndexedDB/SQLite reads must also run on an offline reload.
    networkMode: "always",
    queryKey: ["part", rowUid, mode, user?.id, user?.role, user?.boat_id],
    queryFn: async () => {
      const part = await inventoryRepository.getPart(rowUid);
      return part && (mode === "readonly" || canAccessBoat(user, part.boat_id)) ? part : null;
    },
    enabled: !!rowUid,
  });
}

export function useCounts() {
  return useQuery({
    // IndexedDB/SQLite reads must also run on an offline reload.
    networkMode: "always",
    queryKey: ["counts"],
    queryFn: () => inventoryRepository.getCounts(),
  });
}


export function useUsers() {
  return useQuery({
    // IndexedDB/SQLite reads must also run on an offline reload.
    networkMode: "always",
    queryKey: ["users"],
    queryFn: () => inventoryRepository.getUsers(),
  });
}
