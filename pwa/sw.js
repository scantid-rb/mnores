// build-pwa.cjs replaces the constants with a build hash and complete asset list.
const BUILD_ID = "303127e1a48d5250";
const PRECACHE = ["./","./_expo/static/js/web/entry-bddb629a4741163dce89893d3d57e549.js","./assets/assets/images/app-image.d70632d8f675cc77fb908c895caecdf1.png","./assets/dependencies/@expo-google-fonts/material-symbols/400Regular/MaterialSymbols_400Regular.2d743919d3b5a055bfd99f0ce0f1469f.ttf","./assets/dependencies/expo-router/assets/arrow_down.017bc6ba3fc25503e5eb5e53826d48a8.png","./assets/dependencies/expo-router/assets/error.d1ea1496f9057eb392d5bbf3732a61b7.png","./assets/dependencies/expo-router/assets/file.19eeb73b9593a38f8e9f418337fc7d10.png","./assets/dependencies/expo-router/assets/forward.d8b800c443b8972542883e0b9de2bdc6.png","./assets/dependencies/expo-router/assets/pkg.ab19f4cbc543357183a20571f68380a3.png","./assets/dependencies/expo-router/assets/react-navigation/elements/back-icon-mask.0a328cd9c1afd0afe8e3b1ec5165b1b4.png","./assets/dependencies/expo-router/assets/react-navigation/elements/back-icon.35ba0eaec5a4f5ed12ca16fabeae451d.png","./assets/dependencies/expo-router/assets/react-navigation/elements/clear-icon.c94f6478e7ae0cdd9f15de1fcb9e5e55.png","./assets/dependencies/expo-router/assets/react-navigation/elements/clear-icon.c94f6478e7ae0cdd9f15de1fcb9e5e55@2x.png","./assets/dependencies/expo-router/assets/react-navigation/elements/clear-icon.c94f6478e7ae0cdd9f15de1fcb9e5e55@3x.png","./assets/dependencies/expo-router/assets/react-navigation/elements/clear-icon.c94f6478e7ae0cdd9f15de1fcb9e5e55@4x.png","./assets/dependencies/expo-router/assets/react-navigation/elements/close-icon.808e1b1b9b53114ec2838071a7e6daa7.png","./assets/dependencies/expo-router/assets/react-navigation/elements/close-icon.808e1b1b9b53114ec2838071a7e6daa7@2x.png","./assets/dependencies/expo-router/assets/react-navigation/elements/close-icon.808e1b1b9b53114ec2838071a7e6daa7@3x.png","./assets/dependencies/expo-router/assets/react-navigation/elements/close-icon.808e1b1b9b53114ec2838071a7e6daa7@4x.png","./assets/dependencies/expo-router/assets/react-navigation/elements/search-icon.286d67d3f74808a60a78d3ebf1a5fb57.png","./assets/dependencies/expo-router/assets/sitemap.412dd9275b6b48ad28f5e3d81bb1f626.png","./assets/dependencies/expo-router/assets/unmatched.20e71bdf79e3a97bf55fd9e164041578.png","./favicon.ico","./icon.svg","./manifest.json","./metadata.json"];
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
  const navigation = request.mode === "navigate" && /^(?:$|index\.html$|(?:inventory|login|sync|profile|part-edit|backups|server-settings|system-status)\/?$|(?:part|admin)(?:\/|$))/.test(relative);
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
