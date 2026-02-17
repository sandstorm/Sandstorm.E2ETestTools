const esbuild = require('esbuild');
const extensibilityMap = require("@neos-project/neos-ui-extensibility/extensibilityMap.json");
const SassPlugin = require('esbuild-sass-plugin').default;
const isWatch = process.argv.includes('--watch');

/** @type {import("esbuild").BuildOptions} */
const options = {
    logLevel: "info",
    bundle: true,
    target: "es2020",
    entryPoints: { "Plugin": "src/index.ts" },
    // add this loader mapping,
    // in case you're "misusing" javascript files as typescript-react files
    // - eg with `@neos` or `@connect` decorators
    loader: { ".js": "tsx" },
    outdir: "../../Public/ExportNodeButton",
    alias: extensibilityMap,
    plugins: [SassPlugin()]
}

if (isWatch) {
    esbuild.context(options).then((ctx) => ctx.watch())
} else {
    esbuild.build(options)
}
