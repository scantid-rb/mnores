import { Platform } from "react-native";

export function registerPwa(): void {
  if (Platform.OS !== "web" || !('serviceWorker' in navigator)) return;
  const base = process.env.EXPO_BASE_URL || "";
  const scope = `${base.replace(/\/$/, "")}/`;
  // Links must resolve against the deployment root, including on deep routes.
  const manifest = document.querySelector<HTMLLinkElement>('link[rel="manifest"]');
  if (manifest) manifest.href = `${scope}manifest.json`;
  void navigator.serviceWorker.register(`${scope}sw.js`, { scope, updateViaCache: "none" })
    .then((registration) => registration.update())
    .catch((error) => console.error("No se pudo activar el modo offline", error));
}
