import * as ImagePicker from "expo-image-picker";
import * as ImageManipulator from "expo-image-manipulator";
import { Image } from "react-native";
import { REQUEST_TIMEOUT_MS } from "@/src/config";
import { getServerUrl, getServerUrlSync } from "@/src/services/serverConfig";
import { ApiError } from "@/src/services/api/client";
import { deleteWebPhotoBlob, getWebPhotoBlob, hasWebPhotoBlob, saveWebPhotoBlob } from "@/src/database/store.web";

const MAX_WIDTH = 1600, MAX_HEIGHT = 1200, DEFAULT_QUALITY = 0.85, MIN_QUALITY = 0.60, MAX_FILE_SIZE = 8 * 1024 * 1024;
function getImageSize(uri: string): Promise<{ width: number; height: number }> {
  return new Promise((resolve, reject) => Image.getSize(uri, (width, height) => resolve({ width, height }), reject));
}
function targetSize(width: number, height: number) {
  const scale = Math.min(MAX_WIDTH / width, MAX_HEIGHT / height, 1);
  return { width: Math.max(1, Math.round(width * scale)), height: Math.max(1, Math.round(height * scale)) };
}
async function uriToBlob(uri: string): Promise<Blob> {
  const response = await fetch(uri);
  if (!response.ok) throw new Error("No se pudo leer la fotografía seleccionada.");
  return response.blob();
}
async function processPhoto(sourceUri: string): Promise<Blob> {
  const { width, height } = await getImageSize(sourceUri);
  const size = targetSize(width, height);
  const actions = width !== size.width || height !== size.height ? [{ resize: size }] : [];
  let quality = DEFAULT_QUALITY;
  while (true) {
    const result = await ImageManipulator.manipulateAsync(sourceUri, actions, { compress: quality, format: ImageManipulator.SaveFormat.JPEG });
    const blob = await uriToBlob(result.uri);
    if (blob.size <= MAX_FILE_SIZE) return blob;
    if (quality <= MIN_QUALITY) throw new Error("No se pudo reducir la foto por debajo de 8 MB manteniendo una calidad mínima del 60 %.");
    quality = Math.max(MIN_QUALITY, Math.round((quality - 0.05) * 100) / 100);
  }
}
function newPhotoKey() { return "idb-photo:" + Date.now() + "-" + Math.random().toString(36).slice(2); }
export async function pickPartPhoto(source: "camera" | "library"): Promise<string | null> {
  const result = source === "camera"
    ? await ImagePicker.launchCameraAsync({ mediaTypes: ["images"], allowsEditing: false, quality: DEFAULT_QUALITY })
    : await ImagePicker.launchImageLibraryAsync({ mediaTypes: ["images"], allowsEditing: false, quality: DEFAULT_QUALITY });
  if (result.canceled || !result.assets[0]?.uri) return null;
  const key = newPhotoKey();
  await saveWebPhotoBlob(key, await processPhoto(result.assets[0].uri));
  return key;
}
export async function persistPhoto(sourceUri: string): Promise<string> {
  const key = newPhotoKey();
  await saveWebPhotoBlob(key, await processPhoto(sourceUri));
  return key;
}
export async function removeLocalPhoto(localPath: string | null | undefined): Promise<void> { if (localPath) await deleteWebPhotoBlob(localPath); }
export async function photoExists(localPath: string): Promise<boolean> { return hasWebPhotoBlob(localPath); }
export async function resolveLocalPhotoUri(localPath: string | null | undefined): Promise<string | null> {
  if (!localPath) return null;
  const blob = await getWebPhotoBlob(localPath);
  return blob ? URL.createObjectURL(blob) : null;
}
export async function uploadPartPhoto(token: string, partId: number, localPath: string): Promise<{ updated_at: string }> {
  const blob = await getWebPhotoBlob(localPath);
  if (!blob) throw new ApiError("Archivo local de foto no encontrado", 0, "network");
  const form = new FormData(); form.append("photo", blob, "photo.jpg");
  const controller = new AbortController(); const timer = setTimeout(() => controller.abort(), REQUEST_TIMEOUT_MS);
  let response: Response;
  try {
    response = await fetch((await getServerUrl()) + "/api/photos/" + partId, { method: "POST", headers: { Accept: "application/json", Authorization: "Bearer " + token }, body: form, signal: controller.signal });
  } catch (e: unknown) {
    clearTimeout(timer);
    if ((e as { name?: string })?.name === "AbortError") throw new ApiError("Tiempo de espera agotado", 0, "timeout");
    throw new ApiError("No se pudo subir la foto", 0, "network");
  }
  clearTimeout(timer);
  const text = await response.text();
  let json: { ok?: boolean; error?: string; updated_at?: string };
  try { json = text ? JSON.parse(text) : {}; } catch { throw new ApiError("Respuesta de foto no válida", response.status, "parse", text.slice(0, 300)); }
  if (response.status < 200 || response.status >= 300 || json.ok === false) throw new ApiError(json.error || ("Error HTTP " + response.status), response.status, response.status >= 200 && response.status < 300 ? "api" : "http", text.slice(0, 300));
  if (!json.updated_at) throw new ApiError("La respuesta de foto no contiene updated_at", response.status, "parse", text.slice(0, 300));
  return { updated_at: json.updated_at };
}
export function remotePartPhotoUrl(partId: number): string { return getServerUrlSync() + "/api/photos/" + partId; }