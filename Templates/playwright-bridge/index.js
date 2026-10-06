'use strict';

//
// THIS SCRIPT IS PART OF THE Sandstorm.E2ETestTools PACKAGE.
// It is COPIED to the respective project, so it can be adjusted to individual needs. However, the most up to date
// base version can always be found in the Sandstorm.E2ETestTools package.
//

/**
 * # Playwright Browser Orchestrator <-> HTTP bridge
 *
 * This script exposes the Playwright Browser orchestrator through a HTTP server. This is useful
 * for orchestrating a browser through Behat BDD tests in PHP, while still using the modern Playwright
 * tooling, instead of the arcane Selenium.
 *
 *
 * ## Usage from the outside
 *
 * For full documentation how this script is used from inside Behat, see PlaywrightTrait.php.
 *
 *
 * ## Internal Documentation
 *
 * As long as the server is running, a Chrome browser instance is running as well.
 *
 * Different tests should use different [Browser Contexts](https://playwright.dev/docs/core-concepts#browser-contexts) for separation - which
 * we expose through the HTTP API.
 *
 * We assume this all runs in a TRUSTED ENVIRONMENT, as it allows SENDING ARBITRARY JAVASCRIPT to this server
 * which is then evaluated. It listens on all interfaces (port 3000), so it is reachable from containers - never
 * publish that port to a network you don't trust.
 *
 * The server exposes the following API endpoints:
 *
 * - POST /exec/[contextName]
 *   - In case the "contextName" is existing, it is continued to be used. Otherwise, a new Playwright BrowserContext is created.
 *   - the BODY is the JavaScript which should be executed (in plain text). You have access to the following variables:
 *     - `context` is the Playwright [BrowserContext](https://playwright.dev/docs/api/class-browsercontext) object
 *     - `vars` is a writable JS Object (`{}`) which you can use to pass information from one call to `/exec/[contextName]` to the next.
 *       Simple `const myVar` variable declarations are NOT preserved to the next invocation (because I do not know how ;).
 *   - The REPLY is sent as soon as the step has completed; so that means we wait until everything has run - the API behaves SYNCHRONOUSLY.
 *     - In case of an error (also a syntax error in the script), a 500 status code is sent.
 *     - In case of success, a 200 status code is sent.

 *     The response format is always structured as JSON like this:
 *     {
 *         error: null, // string, only filled if an error occured
 *         returnValue: // mixed: the return value of the current command (if any)
 *         js: // string: the full JS executed so far in this playwright context. ready to be pasted into the playwright runner
 *     }
 *
 * - POST /stop/[contextName]
 *   - Removes the context, i.e. the allocated `BrowserContext` and variable bag; effectively closing the browser window associated.
 *
 * EXAMPLE usage:
 *
 * curl http://127.0.0.1:3000/exec/t1 --data-raw 'vars.page = await context.newPage();'
 * curl http://127.0.0.1:3000/exec/t1 --data-raw 'await vars.page.goto("http://spiegel.de");'
 * curl -XPOST http://127.0.0.1:3000/stop/t1
 */

const { chromium } = require('playwright');

const Hapi = require('@hapi/hapi');

const init = async () => {
    // HEADLESS=false node index.js opens a visible browser to watch the tests (or debug them)
    const browser = await chromium.launch({headless: process.env.HEADLESS !== 'false'});

    // the "key" is the context identifier (from the URL)
    // the "value" is an object: {
    //   playwrightContext: the playwright context object
    //   script: array of JS strings; concatenated building up the script which has been executed so far.
    //   vars: {} - context variables between steps
    // }
    const currentlyKnownContexts = {};

    const server = Hapi.server({
        port: 3000,
        host: '0.0.0.0'
    });

    server.route({
        method: 'POST',
        path: '/exec/{context}',
        options: {
            payload: {
                parse: false
            }
        },
        handler: async (request, h) => {
            const contextName = request.params.context;
            if (!currentlyKnownContexts[contextName]) {
                console.log(`Creating ${contextName}`);
                const playwrightContext = await browser.newContext();
                playwrightContext.setDefaultTimeout(15000); // 15 s
                currentlyKnownContexts[contextName] = {
                    playwrightContext,
                    vars: {},
                    script: []
                };
            }

            const payload = request.payload.toString('utf-8');
            console.log(`Executing in ${contextName}: `, payload);

            currentlyKnownContexts[contextName].script.push(payload);

            try {
                // API towards the script - inside the try, so a syntax error is reported like any other error
                const fn = eval("(async (context, vars) => {\n" + payload + "\n});");
                const returnValue = await fn(currentlyKnownContexts[contextName].playwrightContext, currentlyKnownContexts[contextName].vars);
                console.log("Finished execution...");
                return h.response(JSON.stringify({
                    error: null,
                    returnValue: returnValue,
                    js: wrapForDebug(currentlyKnownContexts[contextName].script)
                }));
            } catch (error) {
                console.log(`ERROR in context ${contextName}: ${error}`)
                return h.response(JSON.stringify({
                    error: error.toString(),
                    returnValue: null,
                    js: wrapForDebug(currentlyKnownContexts[contextName].script)
                })).code(500)
            }
        }
    });

    server.route({
        method: 'POST',
        path: '/stop/{context}',
        handler: async (request, h) => {
            const contextName = request.params.context;
            if (!currentlyKnownContexts[contextName]) {
                return h.response("Not found").code(404)
            }

            await currentlyKnownContexts[contextName].playwrightContext.close();
            delete currentlyKnownContexts[contextName];

            return h.response("Deleted").code(200);
        }
    });

    await server.start();
    console.log('Server running on %s', server.info.uri);

    // docker stop: close the browser instead of waiting for the kill
    process.on('SIGTERM', async () => {
        await server.stop();
        await browser.close();
        process.exit(0);
    });
};

process.on('unhandledRejection', (err) => {
    console.log(err);
    process.exit(1);
});

init();

const beginBlock = `
// USAGE: store this script as "playwright-bridge/test.js" (so that it has access to the Playwright runtime)
// and then run it using:     PWDEBUG=1 node test.js

const { chromium } = require('playwright');

(async () => {
const browser = await chromium.launch({headless: false, slowMo: 100});
const context = await browser.newContext();
const vars = {};

`

const endBlock = `
})();
`;
function wrapForDebug(scriptBlocks) {
    return beginBlock + scriptBlocks.map(scriptBlock => {
        if (scriptBlock.includes('return')) {
            return "\n(async () => {\n" + scriptBlock + "\n})();";
        } else {
            return "\n" + scriptBlock;
        }
    }).join("") + endBlock;
}
