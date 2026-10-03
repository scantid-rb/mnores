// Serialize authentication transitions and sync, including across browser tabs.
// The persisted SessionRow is also the non-secret owner of retained local data;
// it authenticates nobody without the independently stored credential.
import { storage } from "@/src/utils/storage";
import { TOKEN_KEY } from "@/src/constants/storage";
import { localStore } from "@/src/database/store";
import { apiGetMe } from "@/src/services/api/endpoints";

let tail: Promise<unknown> = Promise.resolve();
export function withSessionLock<T>(work: () => Promise<T>): Promise<T> {
  const run = () => {
    if (typeof navigator === "undefined") return work();
    // Fail closed when a browser cannot coordinate account transitions across
    // tabs; silently using a per-tab lock would break cache ownership.
    if (!navigator.locks?.request) throw new Error("Este navegador no permite proteger la sesión entre pestañas. Usa un navegador actualizado y HTTPS.");
    return navigator.locks.request("ShipInventorySession", work);
  };
  const result = tail.then(run, run);
  tail = result.catch(() => undefined);
  return result;
}
export async function isSyncSessionCurrent(token: string): Promise<boolean> {
  const owner = await localStore.getSession();
  const current = await storage.secureGet(TOKEN_KEY, "");
  if (!owner || !current || current !== token) return false;
  // Also reject legacy state left with another account's credential.
  const identity = await apiGetMe(token);
  return identity.id === owner.id;
}

// A stale screen in another tab must not append A's work to B's new cache.
// This check is local, so offline mutations never need a network request.
export function withCacheOwner<T>(ownerId: number, work: () => Promise<T>): Promise<T> {
  return withSessionLock(async () => {
    const owner = await localStore.getSession();
    const credential = await storage.secureGet(TOKEN_KEY, "");
    if (!owner || owner.id !== ownerId || !credential) throw new Error("La sesión local ha cambiado. Vuelve a iniciar sesión.");
    return work();
  });
}
