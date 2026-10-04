// build-pwa.cjs replaces the constants with a build hash and complete asset list.
const BUILD_ID = "development-v4";
const PRECACHE = ["./", "./manifest.json", "./icon.svg"];
const CACHE_PREFIX = "shipinventory-shell-" + self.registration.scope + "-";
const CACHE_NAME = CACHE_PREFIX + BUILD_ID;
const base = new URL(self.registration.scope);
const shellUrl = new URL("./", base).href;
self.addEventListener("install", (event) => {
  event.waitUntil((async () => {
    try {
      const cache = await caches.open(CACHE_NAME);
      await cache.addAll(PRECACHE.map((path) => new Request(new URL(path, base), { cache: "reload" })));
      await self.skipWaiting();
    } catch (error) { await caches.delete(CACHE_NAME); throw error; }
  })());
});
self.addEventListener("activate", (event) => {
  event.waitUntil((async () => {
    const own = (await caches.keys()).filter((key) => key.startsWith(CACHE_PREFIX));
    // Keep the previous build for lazy imports requested by existing tabs.
    const previous = own.filter((key) => key !== CACHE_NAME).slice(-1);
    await Promise.all(own.filter((key) => key !== CACHE_NAME && !previous.includes(key)).map((key) => caches.delete(key)));
    await self.clients.claim();
  })());
});
async function ownCached(request) {
  const own = (await caches.keys()).filter((key) => key.startsWith(CACHE_PREFIX)).reverse();
  for (const key of own) { const cached = await (await caches.open(key)).match(request); if (cached) return cached; }
}
self.addEventListener("fetch", (event) => {
  const request = event.request, url = new URL(request.url);
  if (request.method !== "GET" || url.origin !== base.origin || !url.pathname.startsWith(base.pathname)) return;
  const relative = url.pathname.slice(base.pathname.length);
  if (relative.startsWith("api/") || relative === "sw.js") return;
  const navigation = request.mode === "navigate" && /^(?:$|index\.html$|(?:inventory|login|sync|profile|about|part-edit|backups|server-settings|system-status)\/?$|(?:part|admin)(?:\/|$))/.test(relative);
  const asset = relative.startsWith("_expo/") || relative.startsWith("assets/");
  const metadata = relative === "manifest.json" || relative === "icon.svg";
  if (!navigation && !asset && !metadata) return;
  event.respondWith((async () => {
    const cache = await caches.open(CACHE_NAME);
    if (asset) { const cached = await ownCached(request); if (cached) return cached; }
    try {
      const response = await fetch(request);
      if (response.ok && response.type === "basic") await cache.put(navigation ? shellUrl : request, response.clone());
      if (navigation && !response.ok) { const shell = await ownCached(shellUrl); if (shell) return shell; }
      return response;
    } catch (error) { const cached = await ownCached(navigation ? shellUrl : request); if (cached) return cached; throw error; }
  })());
});
