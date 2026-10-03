import { setServerUrl } from "@/src/services/serverConfig";
import { withSessionLock } from "./sessionLifecycle";
// Session repository: the only place that combines secure token storage with
// the local session metadata.

import { storage } from "@/src/utils/storage";
import { TOKEN_KEY } from "@/src/constants/storage";
import { apiGetMe, apiLogin } from "@/src/services/api/endpoints";
import { localStore } from "@/src/database/store";
import { SessionRow, SessionUser } from "@/src/types";

export const sessionRepository = {
  async restore(): Promise<{ token: string | null; session: SessionRow | null }> {
    return withSessionLock(async () => {
      const token = await storage.secureGet(TOKEN_KEY, "");
      const session = await localStore.getSession();
      return { token: token && session ? String(token) : null, session };
    });
  },
  async login(username: string, password: string): Promise<{ token: string; user: SessionUser }> {
    return withSessionLock(async () => {
      const previous = await localStore.getSession();
      const { token, user } = await apiLogin(username, password);
      const switchedOwner = previous == null || previous.id !== user.id;
      const scopeChanged = previous != null && (previous.role !== user.role || previous.boat_id !== user.boat_id);
      // Remove credentials before changing durable metadata. Publish the new
      // credential only after old data is cleared and its owner is committed.
      if (!await storage.secureRemove(TOKEN_KEY)) throw new Error("No se pudo retirar la sesión anterior.");
      try {
        if (switchedOwner || scopeChanged) {
          await localStore.clearUserData();
          await localStore.saveSession(user);
        } else {
          // A username change does not change ownership or reset the cursor.
          await localStore.updateSessionIdentity(user);
        }
        if (!await storage.secureSet(TOKEN_KEY, token)) throw new Error("No se pudo guardar la sesión.");
        return { token, user };
      } catch (error) {
        await storage.secureRemove(TOKEN_KEY);
        throw error;
      }
    });
  },
  async logout(expectedToken?: string): Promise<boolean> {
    return withSessionLock(async () => {
      if (expectedToken !== undefined && await storage.secureGet(TOKEN_KEY, "") !== expectedToken) return false;
      if (!await storage.secureRemove(TOKEN_KEY)) throw new Error("No se pudo retirar la sesión.");
      // Retain non-secret ownership, cache, cursor and queues. restore() only
      // considers this authenticated when a separate credential is present.
      return true;
    });
  },
  async switchServer(url: string): Promise<void> {
    await withSessionLock(async () => {
      if (!await storage.secureRemove(TOKEN_KEY)) throw new Error("No se pudo retirar la sesión.");
      await localStore.clearUserData();
      await localStore.clearSession();
      await setServerUrl(url);
    });
  },
  validate(token: string): Promise<SessionUser> {
    return apiGetMe(token);
  },
};
