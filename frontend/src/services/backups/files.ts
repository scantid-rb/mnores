import * as FileSystem from "expo-file-system/legacy";
import { File, UploadType } from "expo-file-system";
import * as Sharing from "expo-sharing";
import { getServerUrl } from "@/src/services/serverConfig";
import { Alert } from "@/src/utils/alert";
export async function apiDownloadBackup(token: string, name: string, targetUri: string): Promise<string> {
  // The same backup may be downloaded repeatedly. Remove the previous
  // temporary copy first so the download always starts from a clean target.
  try {
    if (await FileSystem.getInfoAsync(targetUri).then((info) => info.exists)) {
      await FileSystem.deleteAsync(targetUri, { idempotent: true });
    }
  } catch {
    // If the temporary file cannot be inspected/removed, let downloadAsync
    // report the actual download error instead of masking it here.
  }

  const result = await FileSystem.downloadAsync(
    `${await getServerUrl()}/api/backups/${encodeURIComponent(name)}/download`,
    targetUri,
    {
      headers: { Accept: "application/zip", Authorization: `Bearer ${token}` },
    },
  );
  if (result.status < 200 || result.status >= 300) {
    throw new Error(`No se pudo descargar el backup (HTTP ${result.status}).`);
  }
  return result.uri;
}

export async function apiRestoreBackupUpload(token: string, fileUri: string, fileName: string): Promise<{ security_backup: string | null; session_invalidated: boolean }> {
  const file = new File(fileUri);
  const result = await file.upload(
    `${await getServerUrl()}/api/backups/restore-upload`,
    {
      httpMethod: "POST",
      uploadType: UploadType.MULTIPART,
      fieldName: "backup",
      mimeType: "application/zip",
      headers: { Accept: "application/json", Authorization: `Bearer ${token}` },
      parameters: { filename: fileName },
    },
  );
  const text = result.body ?? "";
  let json: { ok?: boolean; error?: string; security_backup?: string | null; session_invalidated?: boolean } = {};
  try { json = text ? JSON.parse(text) : {}; } catch { throw new Error("Respuesta de restauración no válida."); }
  if (result.status < 200 || result.status >= 300 || json.ok === false) {
    throw new Error(json.error || `Error HTTP ${result.status}`);
  }
  return { security_backup: json.security_backup ?? null, session_invalidated: json.session_invalidated === true };
}

export async function prepareBackupTarget(name: string): Promise<string> {
  const dir = `${FileSystem.cacheDirectory}backups/`;
  await FileSystem.makeDirectoryAsync(dir, { intermediates: true });
  return dir + name;
}
export async function saveBackupDownload(uri: string, name: string): Promise<void> {
  if (await Sharing.isAvailableAsync()) await Sharing.shareAsync(uri, { mimeType: "application/zip", dialogTitle: `Guardar ${name}`, UTI: "com.pkware.zip-archive" });
  else Alert.alert("Backup descargado", `Archivo guardado temporalmente en: ${uri}`);
}
