const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '../public/sw.js'), 'utf8');
const stores = new Map();
let networkFails = false, fetched = 0;
function response(body) { return { ok: true, type: 'basic', body, clone() { return response(body); } }; }
const caches = {
  async keys() { return [...stores.keys()]; },
  async delete(key) { return stores.delete(key); },
  async open(key) {
    if (!stores.has(key)) stores.set(key, new Map());
    const store = stores.get(key), url = request => typeof request === 'string' ? request : request.url;
    return {
      async match(request) { return store.get(url(request)); },
      async put(request, value) { store.set(url(request), value); },
      async addAll(requests) {
        if (networkFails) throw Error('network offline');
        for (const request of requests) store.set(url(request), response(key));
      },
    };
  },
};
function worker(build, scope = 'https://example.test/pwa/') {
  const handlers = {};
  vm.runInNewContext(source.replace('"development-v4"', JSON.stringify(build)), {
    self: { registration: { scope }, addEventListener(name, fn) { handlers[name] = fn; }, async skipWaiting() {}, clients: { async claim() {} } },
    caches, Request, URL, fetch: async () => { fetched++; if (networkFails) throw Error('offline'); return response('fresh'); },
  });
  return {
    async lifecycle(name) { let work; handlers[name]({ waitUntil(p) { work = p; } }); await work; },
    async fetch(relative, mode = 'navigate', method = 'GET') {
      let work; handlers.fetch({ request: { url: new URL(relative, scope).href, mode, method }, respondWith(p) { work = p; } });
      return work ? await work : undefined;
    },
  };
}
async function main() {
  const a = worker('A'); await a.lifecycle('install'); await a.lifecycle('activate');
  networkFails = true;
  const bad = worker('B'); await assert.rejects(bad.lifecycle('install'));
  assert((await a.fetch('inventory')).body.includes('A')); assert.equal(stores.size, 1);
  console.log('PASS incomplete update preserves installed offline build');
  networkFails = false;
  const b = worker('B'); await b.lifecycle('install'); await b.lifecycle('activate');
  assert.equal(stores.size, 2); assert.equal((await b.fetch('part/7')).body, 'fresh');
  networkFails = true; assert.equal((await b.fetch('part/7')).body, 'fresh');
  console.log('PASS complete update keeps previous assets and refreshes offline shell');
  const calls = fetched;
  for (const route of ['api/parts/push', 'sw.js', 'inventory.php', 'login.php', 'inventory-wrong', '../api/ping']) assert.equal(await b.fetch(route), undefined);
  assert.equal(await b.fetch('inventory', 'navigate', 'POST'), undefined); assert.equal(calls, fetched);
  console.log('PASS API, PHP, out-of-scope and mutating requests bypass SW');
  networkFails = false;
  const other = worker('other', 'https://example.test/other/'); await other.lifecycle('install'); await other.lifecycle('activate');
  const c = worker('C'); await c.lifecycle('install'); await c.lifecycle('activate');
  assert.equal(stores.size, 3); assert([...stores.keys()].some(k => k.includes('/other/')));
  networkFails = true; assert((await other.fetch('inventory')).body.includes('other'));
  console.log('PASS cache cleanup is scoped and retains one previous build');
  networkFails = false;
  const asset = '_expo/static/js/web/entry-hash.js'; assert.equal((await c.fetch(asset, 'cors')).body, 'fresh');
  networkFails = true; assert.equal((await c.fetch(asset, 'cors')).body, 'fresh');
  console.log('PASS hashed asset works offline after first fetch');
  networkFails = false;
  const root = worker('root', 'https://example.test/'); await root.lifecycle('install'); await root.lifecycle('activate');
  networkFails = true;
  for (const route of ['profile', 'admin/users', 'part/7']) assert((await root.fetch(route)).body.includes('root'));
  for (const route of ['api/account', 'index.php', 'sw.js']) assert.equal(await root.fetch(route), undefined);
  console.log('PASS root deployment opens profile/admin/part offline and excludes backend API/PHP');
}
main().catch(error => { console.error(error); process.exitCode = 1; });
