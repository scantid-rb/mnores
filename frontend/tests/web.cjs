const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const assert = require('node:assert/strict');
const ts = require('typescript');
function load(file, modules, globals) {
  const exports = {};
  const source = ts.transpileModule(fs.readFileSync(path.join(__dirname, '../src/', file), 'utf8'), {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
  }).outputText;
  vm.runInNewContext(source, { exports, require: name => { if (!(name in modules)) throw Error(name); return modules[name]; }, ...globals });
  return exports;
}
class Element {
  constructor(tag) { this.tag = tag; this.children = []; this.events = {}; this.style = {}; }
  setAttribute() {}
  append(...nodes) { this.children.push(...nodes); }
  addEventListener(name, fn) { this.events[name] = fn; }
  showModal() { this.open = true; }
  close() { this.open = false; }
  remove() { this.removed = true; }
  click() { this.events.click?.(); this.clicked = true; }
}
const document = { body: new Element('body'), createElement: tag => new Element(tag) };
const flush = async () => { for (let i = 0; i < 8; i++) await Promise.resolve(); };
async function main() {
  const { Alert } = load('utils/alert.web.ts', {}, { document });
  let accepted = 0, cancelled = 0;
  Alert.alert('Delete', 'Confirm?', [{ text: 'Cancel', style: 'cancel', onPress: () => cancelled++ }, { text: 'Delete', style: 'destructive', onPress: () => accepted++ }]);
  let dialog = document.body.children.at(-1);
  const confirm = dialog.children.at(-1).children[1]; confirm.click(); confirm.click(); await flush();
  assert.equal(accepted, 1); assert.equal(cancelled, 0); assert(dialog.removed);
  Alert.alert('Delete', 'Confirm?', [{ text: 'Cancel', style: 'cancel', onPress: () => cancelled++ }, { text: 'Delete', onPress: () => accepted++ }]);
  dialog = document.body.children.at(-1); dialog.events.cancel({ preventDefault() {} }); await flush();
  assert.equal(cancelled, 1); assert.equal(accepted, 1);
  console.log('PASS web confirmation invokes selected action once and Escape cancels');
  Alert.alert('Fail', '', [{ onPress() { throw Error('fixture error'); } }]);
  document.body.children.at(-1).children.at(-1).children[0].click(); await flush();
  assert.match(document.body.children.at(-1).children[1].textContent, /fixture error/);
  console.log('PASS synchronous confirmation callback errors remain visible');
  class ApiError extends Error { constructor(message, status) { super(message); this.status = status; } }
  let status = 200, contentType = 'application/zip', restore, revoked;
  const fetch = async (url, options) => {
    if (url === 'blob:fixture') return new Response(new Blob(['zip']));
    assert.equal(options.headers.Authorization, 'Bearer fixture');
    if (options.method === 'POST') { restore = options.body; return new Response(JSON.stringify({ ok: true, security_backup: 'safe.zip', session_invalidated: true })); }
    return new Response('zip', { status, headers: { 'content-type': contentType } });
  };
  const backups = load('services/backups/files.web.ts', {
    '@/src/services/serverConfig': { getServerUrl: async () => 'https://example.test' },
    '@/src/services/api/client': { ApiError }, '@/src/config': { REQUEST_TIMEOUT_MS: 1000 },
  }, { fetch, AbortController, FormData, Response, document, URL: { createObjectURL: () => 'blob:download', revokeObjectURL: uri => { revoked = uri; } }, setTimeout: fn => { if (fn.toString().includes('revokeObjectURL')) fn(); return 1; }, clearTimeout() {} });
  const uri = await backups.apiDownloadBackup('fixture', 'backup.zip', '');
  await backups.saveBackupDownload(uri, 'backup.zip'); const link = document.body.children.at(-1);
  assert.equal(uri, 'blob:download'); assert.equal(link.download, 'backup.zip'); assert(link.clicked); assert.equal(revoked, uri);
  const result = await backups.apiRestoreBackupUpload('fixture', 'blob:fixture', 'backup.zip');
  assert.equal(restore.get('backup').name, 'backup.zip'); assert.equal(result.session_invalidated, true); assert.equal(result.security_backup, 'safe.zip');
  contentType = 'text/html'; await assert.rejects(backups.apiDownloadBackup('fixture', 'backup.zip', ''), /ZIP/);
  status = 401; await assert.rejects(backups.apiDownloadBackup('fixture', 'backup.zip', ''), error => error.status === 401);
  console.log('PASS browser ZIP download, multipart restore, content validation and auth error');
}
main().catch(error => { console.error(error); process.exitCode = 1; });
