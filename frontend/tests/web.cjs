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
async function main() {
  let confirmation = true, accepted = 0, cancelled = 0, notice;
  const window = { alert: text => { notice = text; }, confirm: () => confirmation };
  const { Alert } = load('utils/alert.web.ts', {}, { window });
  const buttons = [{ text: 'Cancel', style: 'cancel', onPress: () => cancelled++ }, { text: 'Delete', style: 'destructive', onPress: () => accepted++ }];
  Alert.alert('Delete', 'Confirm?', buttons);
  assert.equal(accepted, 1); assert.equal(cancelled, 0);
  confirmation = false;
  Alert.alert('Delete', 'Confirm?', buttons);
  assert.equal(accepted, 1); assert.equal(cancelled, 1);
  Alert.alert('Profile', 'Updated');
  assert.equal(notice, 'Profile\n\nUpdated');
  Alert.alert('Notice', '', [{ text: 'Cancel', style: 'cancel' }]);
  assert.equal(notice, 'Notice');
  console.log('PASS browser Alert confirms, cancels and displays informational notices');
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
