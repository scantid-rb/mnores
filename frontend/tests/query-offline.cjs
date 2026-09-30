const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const assert = require('node:assert/strict');
const ts = require('typescript');
const { indexedDB } = require('fake-indexeddb');
const { QueryClient, MutationObserver, QueryObserver, onlineManager } = require('@tanstack/react-query');
const root = path.resolve(__dirname, '..');
function load(file, modules) {
  const exports = {};
  const js = ts.transpileModule(fs.readFileSync(path.join(root, file), 'utf8'), {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
  }).outputText;
  vm.runInNewContext(js, { exports, require: name => {
    if (!(name in modules)) throw Error(name);
    return modules[name];
  }, indexedDB, Blob, structuredClone, console: { info() {}, error: console.error }, setTimeout, clearTimeout });
  return exports;
}
let id = 0;
function store() {
  return load('src/database/store.web.ts', { '@/src/utils/id': { newQueueId: () => 'q-' + ++id } }).localStore;
}
const row = id => ({ id, boat_id: 1, name: 'Part ' + id, reference: null, category_id: 1,
  location: null, quantity: 5, notes: null, photo_path: null, updated_at: 'T0', deleted_at: null });
const catalog = parts => ({ parts, boats: [{ id: 1, name: 'Boat' }], categories: [{ id: 1, name: 'Category' }], users: [] });
async function raw(key) {
  const db = await new Promise((resolve, reject) => {
    const request = indexedDB.open('shipinventory-web', 2);
    request.onsuccess = () => resolve(request.result);
    request.onerror = () => reject(request.error);
  });
  try {
    return await new Promise((resolve, reject) => {
      const request = db.transaction('kv').objectStore('kv').get(key);
      request.onsuccess = () => resolve(request.result?.value);
      request.onerror = () => reject(request.error);
    });
  } finally { db.close(); }
}
async function main() {
  const s = store();
  await s.clearUserData(); await s.init();
  await s.reconcileInventory(catalog([row(1), row(2), row(3)]), []);
  await s.saveSession({ id: 1, username: 'chief', role: 'chief_engineer', boat_id: 1 });
  const qc = new QueryClient();
  qc.mount();
  onlineManager.setOnline(false);
  // Reproduce the old default: mutationFn is never reached, IDB stays 3 / 0.
  let called = false;
  const blocked = new MutationObserver(qc, { mutationFn: async () => { called = true; } });
  const pending = blocked.mutate();
  await new Promise(resolve => setTimeout(resolve, 20));
  assert.equal(blocked.getCurrentResult().isPaused, true);
  assert.equal(called, false);
  assert.equal((await raw('db.parts')).length, 3);
  assert.equal(((await raw('db.queue')) ?? []).length, 0);
  onlineManager.setOnline(true);
  await pending;
  onlineManager.setOnline(false);
  const repo = load('src/repositories/inventoryRepository.ts', { '@/src/database/store': { localStore: s } });
  const hooks = load('src/hooks/usePartMutations.ts', {
    '@tanstack/react-query': { useMutation: options => options, useQueryClient: () => qc },
    '@/src/repositories/inventoryRepository': repo,
    '@/src/state/SessionContext': { useSession: () => ({ mode: 'authenticated' }) },
    '@/src/state/SyncContext': { useSync: () => ({ syncNow: async () => {}, refreshPending: async () => {} }) },
    '@/src/utils/id': { newLocalId: () => 'offline-new' },
  });
  async function bounded(promise) {
    let timer;
    try {
      return await Promise.race([promise, new Promise((_, reject) => {
        timer = setTimeout(() => reject(Error('Local operation was paused offline')), 1000);
      })]);
    } finally { clearTimeout(timer); }
  }
  const mutate = (options, input) => bounded(new MutationObserver(qc, options).mutate(input));
  await mutate(hooks.useCreatePart(), { boat_id: 1, name: 'Offline', category_id: 1, quantity: 7 });
  assert.equal((await raw('db.parts')).length, 4);
  assert.equal((await raw('db.queue')).length, 1);
  await mutate(hooks.useUpdatePart(), { rowUid: 'srv-1', fields: { quantity: 42 } });
  assert.equal((await raw('db.queue')).length, 2);
  await mutate(hooks.useDeletePart(), 'srv-2');
  assert.equal((await raw('db.parts')).find(p => p.row_uid === 'srv-1').quantity, 42);
  assert.equal(((await raw('db.queue')) ?? []).length, 3);
  console.log('PASS actual React Query scheduling: old default pauses before IDB; offline create/edit/delete now persist');
  // A new store + QueryClient simulate loss of JS/query caches on offline reload.
  const reloaded = store();
  await reloaded.init();
  const reloadRepo = load('src/repositories/inventoryRepository.ts', { '@/src/database/store': { localStore: reloaded } });
  const reads = load('src/hooks/useInventory.ts', {
    '@tanstack/react-query': { useQuery: options => options },
    '@/src/repositories/inventoryRepository': reloadRepo,
  });
  const fresh = new QueryClient();
  for (const options of [reads.useParts('', null), reads.usePart('srv-1'), reads.useCategories(),
    reads.useBoats(), reads.useUsers(), reads.useCounts()]) {
    const observer = new QueryObserver(fresh, options);
    const result = await bounded(observer.refetch());
    assert.equal(result.status, 'success');
  }
  const parts = await fresh.fetchQuery(reads.useParts('', null));
  assert.equal(parts.length, 3);
  assert.equal(parts.some(p => p.row_uid === 'srv-2'), false);
  assert.equal(parts.find(p => p.row_uid === 'srv-1').quantity, 42);
  assert.equal(parts.find(p => p.row_uid === 'offline-new').quantity, 7);
  assert.equal((await reloaded.getPendingChanges()).length, 3);
  console.log('PASS offline reload: all six local read hooks execute and durable edits/deletion/create survive');
  onlineManager.setOnline(true);
  const server = [row(1), row(2), row(3)];
  const sent = [];
  const engine = load('src/services/sync/syncEngine.ts', {
    '@/src/database/store': { localStore: reloaded },
    '@/src/services/api/client': { ApiError: class extends Error {} },
    '@/src/services/photos/photoService': { photoExists: async () => true },
    '@/src/services/api/endpoints': {
      apiPush: async (_token, changes) => {
        const c = changes[0]; sent.push(c.action);
        const id = c.action === 'create' ? 4 : c.id;
        if (c.action === 'create') server.push({ ...row(id), ...c, id, updated_at: 'T1' });
        if (c.action === 'update') Object.assign(server.find(p => p.id === id), c, { updated_at: 'T1' });
        if (c.action === 'delete') server.splice(server.findIndex(p => p.id === id), 1);
        return { ok: true, results: [{ action: c.action, local_id: c.local_id, id, status: 'ok', updated_at: 'T1' }] };
      },
      apiGetSync: async () => ({ server_time: 'T2', parts: server }),
      apiGetBoats: async () => catalog([]).boats,
      apiGetCategories: async () => catalog([]).categories,
      apiGetUsers: async () => [],
    },
  });
  const result = await engine.runSync('fixture-token');
  assert.equal(result.ok, 3);
  assert.equal(sent.join(','), 'create,update,delete');
  assert.equal(((await raw('db.queue')) ?? []).length, 0);
  assert.equal(server.find(p => p.id === 1).quantity, 42);
  assert.equal(server.some(p => p.id === 2), false);
  assert.equal(server.find(p => p.id === 4).quantity, 7);
  console.log('PASS reconnect: real sync engine sends all three operations, applies ACKs, reconciles and empties IDB queue (fixture API)');
  qc.unmount(); qc.clear(); fresh.clear();
}
main().catch(error => { console.error(error); process.exitCode = 1; }).finally(() => onlineManager.setOnline(true));
