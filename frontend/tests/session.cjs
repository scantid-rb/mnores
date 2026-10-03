const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const ts = require('typescript');
const { IDBFactory } = require('fake-indexeddb');
function load(file, modules, globals = {}) {
  const exports = {};
  const js = ts.transpileModule(fs.readFileSync(`${__dirname}/../${file}`, 'utf8'), {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
  }).outputText;
  vm.runInNewContext(js, { exports, structuredClone, Blob, URL, setTimeout, clearTimeout,
    console: { info() {}, warn() {}, error() {} },
    require(name) { assert.ok(name in modules, name); return modules[name]; }, ...globals });
  return exports;
}
const A = { id: 1, username: 'A', role: 'chief_engineer', boat_id: 1 };
const B = { id: 2, username: 'B', role: 'chief_engineer', boat_id: 2 };
const input = { local_id: 'A-part', boat_id: 1, name: 'Pump', category_id: 1, quantity: 5 };
async function fixture() {
  const indexedDB = new IDBFactory(); let serial = 0; let token = 'token-A'; let identity = A;
  let failRemove = false; let failWrite = false;
  const boundary = [];
  let abortAfter = null;
  let lockTail = Promise.resolve();
  const navigator = { locks: { request: (_name, run) => {
    const result = lockTail.then(run); lockTail = result.catch(() => undefined); return result;
  } } };
  const openStore = () => load('src/database/store.web.ts', { '@/src/utils/id': { newQueueId: () => `q-${++serial}` } }, { indexedDB });
  const web = openStore(); const store = web.localStore;
  await store.init(); await store.saveSession(A); await store.setLastSyncAt('cursor'); await store.createPartLocal(input);
  await web.saveWebPhotoBlob('photo-A', new Blob(['A'])); await store.setLocalPhoto(input.local_id, 'photo-A');
  const storage = {
    secureGet: async () => token,
    secureRemove: async () => { if (failRemove) return false; token = null; boundary.push(await snapshot()); return true; },
    secureSet: async (_key, next) => { boundary.push(await snapshot()); if (failWrite) return false; token = next; boundary.push(await snapshot()); return true; },
  };
  async function snapshot() { return { token, owner: (await store.getSession())?.id, count: await store.getPendingCount() }; }
  const lifecycleModules = {
    '@/src/utils/storage': { storage }, '@/src/constants/storage': { TOKEN_KEY: 'token' },
    '@/src/database/store': { localStore: store }, '@/src/services/api/endpoints': { apiGetMe: async () => identity },
  };
  const openLifecycle = () => load('src/repositories/sessionLifecycle.ts', lifecycleModules, { navigator });
  const lifecycle = openLifecycle();
  const observedStore = new Proxy(store, { get(target, key) {
    const method = target[key];
    if (!['clearUserData', 'saveSession', 'updateSessionIdentity'].includes(key)) return method;
    return async (...args) => {
      const result = await method(...args); boundary.push(await snapshot());
      if (abortAfter === key) throw Error('Interrupted transition');
      return result;
    };
  } });
  const repository = load('src/repositories/sessionRepository.ts', {
    '@/src/services/serverConfig': { setServerUrl: async () => {} },
    './sessionLifecycle': lifecycle, '@/src/utils/storage': { storage }, '@/src/constants/storage': { TOKEN_KEY: 'token' },
    '@/src/database/store': { localStore: observedStore },
    '@/src/services/api/endpoints': { apiGetMe: async () => identity, apiLogin: async () => ({ token: `token-${identity.username}`, user: identity }) },
  }).sessionRepository;
  return { store, web, repository, lifecycle, openLifecycle, openStore, snapshot, boundary, abortAfter: key => { abortAfter = key; },
    identity: user => { identity = user; }, token: value => { token = value; },
    failRemove: () => { failRemove = true; }, failWrite: () => { failWrite = true; } };
}
test('P1 logout retains A ownership and photos through reload; B clears before credential publication', async () => {
  const f = await fixture(); await f.repository.logout();
  assert.equal((await f.repository.restore()).token, null);
  const reopened = f.openStore().localStore;
  assert.equal((await reopened.getSession()).id, A.id); assert.equal(await reopened.getPendingCount(), 2);
  f.identity(B); await f.repository.login('B', 'password');
  assert.equal((await f.snapshot()).owner, B.id); assert.equal(await f.store.getPendingCount(), 0);
  assert.equal(await f.web.hasWebPhotoBlob('photo-A'), false);
  assert.ok(f.boundary.every(s => s.token !== 'token-B' || (s.owner === B.id && s.count === 0)));
});
test('P1 same user relogin and renamed username retain offline queue, photo and cursor', async () => {
  const f = await fixture(); await f.repository.logout();
  f.identity({ ...A, username: 'renamed' }); await f.repository.login('renamed', 'password');
  assert.equal(await f.store.getPendingCount(), 2);
  assert.equal(await f.web.hasWebPhotoBlob('photo-A'), true);
  assert.equal((await f.store.getSession()).last_sync_at, 'cursor');
  assert.equal((await f.store.getSession()).username, 'renamed');
});
test('P1 missing legacy owner clears all work before new login', async () => {
  const f = await fixture(); await f.store.clearSession();
  f.identity(B); await f.repository.login('B', 'password');
  assert.equal(await f.store.getPendingCount(), 0); assert.equal(await f.web.hasWebPhotoBlob('photo-A'), false);
});
for (const change of [{ role: 'mechanic' }, { boat_id: 2 }]) {
  test(`P1 same ID changed access scope ${JSON.stringify(change)} clears stale scoped cache`, async () => {
    const f = await fixture(); f.identity({ ...A, ...change }); await f.repository.login('A', 'password');
    assert.equal(await f.store.getPendingCount(), 0); assert.equal((await f.store.getSession()).last_sync_at, null);
  });
}
test('P1 failed removal cannot change owner, queues or photos', async () => {
  const f = await fixture(); f.failRemove(); f.identity(B);
  await assert.rejects(f.repository.login('B', 'password'), /retirar/);
  assert.equal((await f.snapshot()).owner, A.id); assert.equal(await f.store.getPendingCount(), 2);
  assert.equal(await f.web.hasWebPhotoBlob('photo-A'), true);
});
test('P1 failed token write leaves unauthenticated durable B owner', async () => {
  const f = await fixture(); f.failWrite(); f.identity(B);
  await assert.rejects(f.repository.login('B', 'password'), /guardar/);
  assert.equal((await f.repository.restore()).token, null); assert.equal((await f.snapshot()).owner, B.id);
  assert.equal(await f.store.getPendingCount(), 0);
});
test('P1 stale credential cannot sync or log out a newly authenticated owner', async () => {
  const f = await fixture(); f.identity(B); await f.repository.login('B', 'password');
  assert.equal(await f.lifecycle.isSyncSessionCurrent('token-A'), false);
  assert.equal(await f.repository.logout('token-A'), false);
  assert.equal((await f.repository.restore()).token, 'token-B');
});
test('P1 legacy mismatched token is rejected before push/photo/pull', async () => {
  const f = await fixture(); f.token('token-B'); f.identity(B);
  const before = await f.store.getPendingCount(); let requests = 0;
  const engine = load('src/services/sync/syncEngine.ts', {
    '@/src/repositories/sessionLifecycle': f.lifecycle, '@/src/database/store': { localStore: f.store },
    '@/src/services/api/client': { ApiError: class ApiError extends Error {} },
    '@/src/services/photos/photoService': { photoExists: async () => true, uploadPartPhoto: async () => { requests++; } },
    '@/src/services/api/endpoints': { apiPush: async () => { requests++; }, apiGetSync: async () => { requests++; } },
  });
  assert.equal((await engine.runSync('token-B')).authError, true);
  assert.equal(requests, 0); assert.equal(await f.store.getPendingCount(), before);
});
test('P1 logout waits for a running sync before changing durable credentials', async () => {
  const f = await fixture(); let release; let started;
  const running = new Promise(resolve => { started = resolve; });
  const sync = f.lifecycle.withSessionLock(async () => { started(); await new Promise(resolve => { release = resolve; }); });
  await running;
  const logout = f.repository.logout();
  assert.equal((await f.snapshot()).token, 'token-A');
  release(); await sync; await logout;
  assert.equal((await f.snapshot()).token, null);
});
test('P1 stale A screen cannot append operations or photos after B login', async () => {
  const f = await fixture(); f.identity(B); await f.repository.login('B', 'password');
  await assert.rejects(f.lifecycle.withCacheOwner(A.id, () => f.store.createPartLocal(input)), /sesión local/);
  await assert.rejects(f.lifecycle.withCacheOwner(A.id, () => f.store.setLocalPhoto(input.local_id, 'photo-A')), /sesión local/);
  assert.equal(await f.store.getPendingCount(), 0);
});
test('P1 signed-out screens cannot append work; same-owner offline mutation still works', async () => {
  const f = await fixture();
  await f.lifecycle.withCacheOwner(A.id, () => f.store.updatePartLocal(input.local_id, { quantity: 8 }));
  assert.equal((await f.store.getPart(input.local_id)).quantity, 8);
  await f.repository.logout();
  await assert.rejects(f.lifecycle.withCacheOwner(A.id, () => f.store.updatePartLocal(input.local_id, { quantity: 9 })), /sesión local/);
  assert.equal((await f.store.getPart(input.local_id)).quantity, 8);
});
for (const phase of ['clearUserData', 'saveSession']) {
  test(`P1 reload after ${phase} has no credential and next login safely completes`, async () => {
    const f = await fixture(); f.identity(B); f.abortAfter(phase);
    await assert.rejects(f.repository.login('B', 'password'), /Interrupted/);
    assert.equal((await f.repository.restore()).token, null);
    assert.equal(await f.openStore().localStore.getPendingCount(), 0);
    f.abortAfter(null); await f.repository.login('B', 'password');
    assert.ok(f.boundary.every(s => !s.token || (s.token === 'token-A' ? s.owner === A.id : s.owner === B.id)));
  });
}
test('P1 independent tab lifecycle waits for the shared Web Lock', async () => {
  const f = await fixture(); const other = f.openLifecycle(); let release; let started;
  const ready = new Promise(resolve => { started = resolve; });
  const sync = other.withSessionLock(async () => { started(); await new Promise(resolve => { release = resolve; }); });
  await ready; const logout = f.repository.logout();
  assert.equal((await f.snapshot()).token, 'token-A');
  release(); await sync; await logout; assert.equal((await f.snapshot()).token, null);
});
test('P1 server switch clears owner, cursor, queues and photos before reusing numeric IDs', async () => {
  const f = await fixture(); await f.repository.switchServer('https://example.test');
  assert.equal((await f.repository.restore()).token, null); assert.equal(await f.store.getSession(), null);
  assert.equal(await f.store.getPendingCount(), 0); assert.equal(await f.web.hasWebPhotoBlob('photo-A'), false);
});
test('P1 browser without Web Locks fails closed before running account-sensitive work', async () => {
  const lifecycle = load('src/repositories/sessionLifecycle.ts', {
    '@/src/utils/storage': {}, '@/src/constants/storage': {}, '@/src/database/store': {}, '@/src/services/api/endpoints': {},
  }, { navigator: {} });
  let called = false;
  await assert.rejects(lifecycle.withSessionLock(async () => { called = true; }), /navegador/);
  assert.equal(called, false);
});
