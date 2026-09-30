import { getServerUrl } from "@/src/services/serverConfig";
import { ApiError } from "@/src/services/api/client";
import { REQUEST_TIMEOUT_MS } from "@/src/config";
async function backupFetch(path: string, token: string, body?: FormData): Promise<Response> {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), REQUEST_TIMEOUT_MS);
  try {
    const response = await fetch(`${await getServerUrl()}${path}`, {
      method: body ? "POST" : "GET", headers: { Authorization: `Bearer ${token}` }, body, signal: controller.signal,
    });
    if (!response.ok) {
      const text = await response.text();
      throw new ApiError(`No se pudo completar la operación (HTTP ${response.status}).`, response.status, "http", text.slice(0, 300));
    }
    // Consume while the abort timer still covers the response body.
    const blob = await response.blob();
    return new Response(blob, { status: response.status, headers: response.headers });
  } finally { clearTimeout(timer); }
}
export async function prepareBackupTarget(name: string): Promise<string> { return name; }
export async function apiDownloadBackup(token: string, name: string, _targetUri: string): Promise<string> {
  const response = await backupFetch(`/api/backups/${encodeURIComponent(name)}/download`, token);
  const blob = await response.blob();
  if (!(response.headers.get("content-type") || "").includes("zip")) throw new Error("El servidor no devolvió un archivo ZIP.");
  return URL.createObjectURL(blob);
}
export async function saveBackupDownload(uri: string, name: string): Promise<void> {
  const link = document.createElement("a"); link.href = uri; link.download = name;
  document.body.append(link); link.click(); link.remove();
  setTimeout(() => URL.revokeObjectURL(uri), 1000);
}
export async function apiRestoreBackupUpload(token: string, fileUri: string, fileName: string): Promise<{ security_backup: string | null; session_invalidated: boolean }> {
  const blob = await (await fetch(fileUri)).blob();
  const form = new FormData(); form.append("backup", blob, fileName);
  const response = await backupFetch("/api/backups/restore-upload", token, form);
  const json = await response.json();
  if (json.ok !== true) throw new Error(json.error || "Respuesta de restauración no válida.");
  return { security_backup: json.security_backup ?? null, session_invalidated: json.session_invalidated === true };
}
