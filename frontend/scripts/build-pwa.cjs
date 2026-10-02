const fs = require("node:fs");
const path = require("node:path");
const crypto = require("node:crypto");
const { spawnSync } = require("node:child_process");
const root = path.resolve(__dirname, ".."), output = path.resolve(root, "..", "pwa");
const base = (process.env.PWA_BASE_PATH || "").replace(/\/$/, "");
if (base && !/^\/(?:[A-Za-z0-9_-]+\/?)+$/.test(base)) throw new Error("PWA_BASE_PATH debe ser una ruta absoluta sin query ni traversal");
fs.rmSync(output, { recursive: true, force: true });
const result = spawnSync(process.execPath, [require.resolve("expo/bin/cli"), "export", "--platform", "web", "--output-dir", output], {
  cwd: root, env: { ...process.env, PWA_BASE_PATH: base }, stdio: "inherit",
});
if (result.status !== 0) process.exit(result.status || 1);
const walk = (dir) => fs.readdirSync(dir, { withFileTypes: true }).flatMap((entry) => entry.isDirectory() ? walk(path.join(dir, entry.name)) : [path.join(dir, entry.name)]);
// Expo exports dependency images/fonts under assets/node_modules. These are
// static resources, but keep the public tree free of that directory name.
const dependencyAssets = path.join(output, "assets", "node_modules");
if (fs.existsSync(dependencyAssets)) fs.renameSync(dependencyAssets, path.join(output, "assets", "dependencies"));
for (const file of walk(output).filter((file) => /\.(?:js|json|html|css|map)$/.test(file))) {
  const text = fs.readFileSync(file, "utf8");
  fs.writeFileSync(file, text.replaceAll("assets/node_modules/", "assets/dependencies/"));
}
// The bundle changed when asset URLs were rewritten: update its content hash
// and HTML reference before creating the complete service-worker precache.
const htmlFile = path.join(output, "index.html");
let html = fs.readFileSync(htmlFile, "utf8");
for (const file of walk(output).filter((file) => /entry-[a-f0-9]+\.js$/.test(file))) {
  const name = `entry-${crypto.createHash("md5").update(fs.readFileSync(file)).digest("hex")}.js`;
  html = html.replaceAll(path.basename(file), name);
  fs.renameSync(file, path.join(path.dirname(file), name));
}
html = html.replace('href="./manifest.json"', `href="${base}/manifest.json"`).replace('href="./icon.svg"', `href="${base}/icon.svg"`);
fs.writeFileSync(htmlFile, html);
// Apache configuration is deployed with the PWA, but must not be fetched by
// the service worker (Apache deliberately prevents HTTP access to dotfiles).
const files = walk(output).filter((file) => !file.endsWith(`${path.sep}sw.js`) && path.basename(file) !== ".htaccess").sort();
const hash = crypto.createHash("sha256");
for (const file of files) hash.update(path.relative(output, file)).update(fs.readFileSync(file));
const buildId = hash.digest("hex").slice(0, 16);
const assets = ["./", ...files.filter((file) => !file.endsWith(`${path.sep}index.html`)).map((file) => `./${path.relative(output, file).split(path.sep).join("/")}`)];
const swPath = path.join(output, "sw.js");
const sw = fs.readFileSync(swPath, "utf8").replace('const BUILD_ID = "development-v4";', `const BUILD_ID = ${JSON.stringify(buildId)};`).replace('const PRECACHE = ["./", "./manifest.json", "./icon.svg"];', `const PRECACHE = ${JSON.stringify(assets)};`);
fs.writeFileSync(swPath, sw);
const entry = files.find((file) => /entry-.*\.js$/.test(file));
if (!html.includes('rel="manifest"') || !entry || !fs.readFileSync(entry, "utf8").includes('serviceWorker')) throw new Error("Export sin manifiesto o registro de SW");
console.log(`PWA ${buildId}: ${assets.length} recursos, base ${base || "/"}`);
