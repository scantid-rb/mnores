const { defineConfig } = require("eslint/config");
const expoConfig = require("eslint-config-expo/flat");
module.exports = defineConfig([expoConfig, { files: ["**/*.cjs"], languageOptions: { globals: { __dirname: "readonly", module: "readonly", require: "readonly", process: "readonly", console: "readonly", Buffer: "readonly" } } }, { ignores: ["dist/**", ".expo/**"] }]);
