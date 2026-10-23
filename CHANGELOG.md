# Changelog

What changed for projects using the package, the newest version first. Versions follow SemVer since 10.0.0 - see
[Versions](README.md#versions). A major version comes with upgrade notes for the steps and the playwright-bridge.

## 11.0.0 - unreleased

### Upgrading to 11.0

**Changed behaviour** - a test that passed can fail now:

- `PageAssertionsTrait` and `FormInteractionTrait` use the copy of an element the visitor sees: a hidden copy (e.g. the
  desktop variant on a mobile viewport) is ignored instead of failing in Playwright's strict mode. The element
  assertions no longer check the first match - two visible matches fail now (`the button "Delete" should be visible`
  with a button per row), `should be hidden` fails when any copy is visible, and `I focus the :role :name` no longer
  focuses the first of several. Scope those steps to one element (test id) or fix the markup.
- `requests to other hosts are recorded and blocked`: a navigation to another host (link, form, JavaScript, iframe)
  gets an empty page instead of failing with `net::ERR_FAILED` - other requests stay blocked. A server redirect to
  another host isn't covered (see README). The recorded requests are full URLs now: own steps that read
  `vars.externalHosts` use `vars.externalRequests` (map them with `new URL(url).host`).
- `the mail to :recipient should contain :text` checks the HTML part too (as visible text), not only the plain text -
  the part most mail clients show. A mail whose HTML part lacks the text fails now - e.g. "Label: value" in the text
  part, but a table without colons in the HTML part: write the labels the same way in both templates.

**playwright-bridge** - update your copy: contexts and pages a script opens itself (`browser.newContext()`,
`browser.newPage()`) wait up to 15 seconds per action or navigation, like the scenario's context. With Playwright's
30 s default they hit the 30 s of the request to the bridge first, and the step failed with a bare cURL timeout. An
older copy keeps working, without this.

### New in 11.0

- New step `the page URL should start with :url` (NavigationTrait) - e.g. where a link to a payment provider led.
- New MailpitTrait steps: `the mail to :recipient should not contain :text`, `... should be from :address`,
  `... should have no attachments` and `... should have the attachment :fileName of type :contentType`.
- Failures say when only hidden matches were found, or when one of several visible matches is hidden by `opacity: 0`,
  off-screen positioning or sr-only (Playwright counts these as visible).
- A request to the bridge that takes too long fails with an explanation (#1761200001) instead of the bare cURL error.
- `playwrightRunOnVisible()` for own steps: runs a script on the visible copy of a locator, with the same failure hints.
- `there should not be the text :text in :selector` passes when no element matches visibly (it failed with "element(s)
  not found" before).
- `the focus should be inside :selector` passes when any match contains the focus (nested matches are fine) - it
  checked only the first match before.
- `the :role :name should be focused` reports an element inside `inert` as "hidden from assistive technology" - since
  10.0 it failed with Playwright's plain message.

## 10.1.0 - 2026-10-20

- New `ResponsiveImageTrait` (see README): checks that `sizes` makes the browser load the fitting srcset image, with
  generated test images (php-vips or ext-gd). Images may be expected to load `one larger` (new) or `one smaller` per
  image; `sizes="auto"` fails the check. Copied an `ImageSizeTrait` from the kickstart into your project? Delete it and
  use `ResponsiveImageTrait` - same steps, otherwise Behat reports them as ambiguous.
- `I pause for debugging` no longer needs `PAUSE_FOR_DEBUGGING=true` - the pause request waits without timeout, every
  other request to the bridge keeps its 30 seconds. With a headless bridge the step fails right away (#1728120002).
  `PAUSE_FOR_DEBUGGING` is ignored now and no longer lifts the 30 seconds of the other requests - a step that only
  passed with it (a request to the bridge longer than 30 seconds) fails now. Drop it from your scripts and
  `docker compose exec -e` lists.
- `PlaywrightConnector::execute()` takes an optional timeout in seconds (`0` = none).
- playwright-bridge: `GET /status` answers `{"headless": true|false}` - update your copy to get the headless check
  (an older copy pauses as before, without it).

## 10.0.0 - 2026-10-14

### Upgrading the steps to 10.0

**Split traits:**

- Add `use NavigationTrait;` to your `FeatureContext` - `I access the URI path`, `the URI path should be` and `the
  response status code should be` moved there from `NeosBackendControlTrait`. Without it, Behat reports these steps as
  undefined.
- `there should be the text ... on the page` moved to `PageAssertionsTrait`.
- The [new steps](#new-in-100) can have the same wording as steps of your project (e.g. `the button :caption should be
  disabled`) - Behat reports both as ambiguous. Delete yours, or don't use the trait.

**Changed behaviour:**

- The assertions of `PageAssertionsTrait`, `FormInteractionTrait`, `BrowserStateTrait` and `the URI path should be`
  wait for the expected state instead of checking once, up to `setPlaywrightTimeout()` (default 5000 ms) - a step that
  was flaky because of a slow page passes now. Their failure messages come from Playwright (expected, received, call
  log) instead of PHPUnit. `$pageAssertions_timeout` still works, but is deprecated.
- `there should be the text ... on the page` checks the rendered text, like its `not` variant: text in hidden elements
  counts as absent, the case matters, and it waits 5 seconds instead of 15. A step that found hidden text before fails
  now - check the element that shows the text, or wait for it with another step first.
- `I delete the cookie` keeps the other cookies (before, all were removed and added again).
- `E2E_TRACE` wins over `setPlaywrightTracingMode()`; an unknown value fails the run.

**Renamed steps** - one wording for the same thing (`px`, `delete`, `should not be`, `:method :path`, `should be`):

| Before | Now |
|---|---|
| `I have a site for Site Node ...` | `I have a site for the site node ...` |
| `the Fusion output should equal to :expected` | `the Fusion output should be :expected` |
| `in the fusion output, the inner HTML of CSS selector :selector matches :expected` | `the inner HTML of :selector in the Fusion output should be :expected` |
| `in the fusion output, the attributes of CSS selector :selector are:` | `the attributes of :selector in the Fusion output should be:` |
| `I do a screenshot :filename` | `I take a screenshot :filename` |
| `I debug the playwright script` | `I debug the Playwright script` |
| `the viewport size is :width x :height` | `the viewport is :width x :height px` |
| `I scroll up by :pixels pixels` | `I scroll up by :pixels px` |
| `I remove the local/session storage key :key` | `I delete the local/session storage key :key` |
| `there should be no request to :host` | `there should not be a request to :host` |
| `the API :api path :path on :method serves response :file` | `the API :api serves :file for :method :path` |

Most of them with sed (GNU sed; on macOS `sed -i ''`), from the directory with your features:

```bash
find . -name '*.feature' -exec sed -i -E \
  -e 's/I have a site for Site Node/I have a site for the site node/' \
  -e 's/the Fusion output should equal to/the Fusion output should be/' \
  -e 's/in the fusion output, the inner HTML of CSS selector ("[^"]*") matches/the inner HTML of \1 in the Fusion output should be/' \
  -e 's/in the fusion output, the attributes of CSS selector ("[^"]*") are:/the attributes of \1 in the Fusion output should be:/' \
  -e 's/I do a screenshot/I take a screenshot/' \
  -e 's/I debug the playwright script/I debug the Playwright script/' \
  -e 's/the viewport size is ([^ ]+) x ([^ ]+)/the viewport is \1 x \2 px/' \
  -e 's/I scroll up by ([^ ]+) pixels/I scroll up by \1 px/' \
  -e 's/I remove the ([^ ]+) storage key/I delete the \1 storage key/' \
  -e 's/there should be no request to/there should not be a request to/' \
  -e 's/the API ("[^"]*") path ("[^"]*") on ("[^"]*") serves response ("[^"]*")/the API \1 serves \4 for \3 \2/' \
  {} +
```

**Custom steps:** `$this->playwrightConnector->execute(...)` still works; `$this->playwrightRun('...%s...', $value)` is
shorter. The method of `the API :api serves :file for :method :path` takes its arguments in a new order (`$api, $file,
$method, $path, $status`), and the private helpers of the traits (`formInteraction_expect()`, `pageAssertions_expect()`)
changed their signatures - don't call those from your steps.

### Upgrading the playwright-bridge to 10.0 (Playwright 1.51 to 1.64)

Update your copy as described in [Setup step 5](README.md#5-playwright-playwright-bridge). What changed:

- The steps assert with `expect` - a bridge that doesn't hand it to the scripts fails the first `@playwright` scenario
  with "The playwright-bridge is too old" (#1728000002). The scripts get `(contextName, browser, context, vars, fs,
  expect)` - a custom step that declares one of these names itself (e.g. `const fs = require("fs")`) is a
  `SyntaxError` now; rename the variable.
- `package.json` has `@playwright/test` (for `expect`) next to `playwright`, both 1.64.0; the `Dockerfile` is based on
  `mcr.microsoft.com/playwright:v1.64.0-noble`; Node 20 or newer (`.nvmrc`).
- `chromium.launch()` no longer takes `devtools` (removed in 1.58) - if your copy passes it, drop it (or use
  `args: ['--auto-open-devtools-for-tabs']`). `HEADLESS=false` or `--no-headless` show the browser.
- Since 1.57 the browser is a "Chrome for Testing" build - screenshots can differ by a few pixels. Compare visual
  baselines again, if you have any.

### New in 10.0

- `PageHealthTrait`: JavaScript errors, failed requests and images of a page - a smoke test per page.
- `NavigationTrait` (reload, viewport and screen size, scrolling), `NetworkTrait` (requests to other hosts, links),
  `MailpitTrait` (sent mails).
- Steps by role and accessible name: `I focus the :role :name`, `the :role :name should be
  focused/visible/hidden/enabled/disabled`, `the :role :name should (not) be in the viewport`.
- `the focus should be inside :selector`, `the field :label should be invalid`.
- For your own steps: `playwrightRun()`, the typed `playwrightArray()`/`playwrightStrings()`/`playwrightString()`,
  `CssValue::of()` for values in CSS selectors.
- `E2E_TRACE=on-error|always|off` per run; every Behat step is a group in the trace.
- `StepTimingTrait`: `E2E_TIMINGS=1` writes the duration of every step - a local dev tool, not for the pipeline.
- `all links in ... should respond with 200` names the failing links and their status.

## 9.1.0 - 2026-10-06

### Migrating tests from Neos 8

Start your `FeatureContext` from [`FeatureContext.php.default`](Templates/FeatureContext.php.default) instead of
patching the old one - a `FeatureContext` of an older version fails with missing classes. Also:

- The traits moved from `Sandstorm\E2ETestTools\Tests\Behavior\Bootstrap\` to `Sandstorm\E2ETestTools\Behat\` and are
  autoloaded - drop the `require_once` lines.
- The templates are in `Templates/` (`FeatureContext.php.default`, `playwright-bridge/`) - compare your copy of the
  bridge with the new one.
- `Testing/Behat` needs the SUT's cache storage and `applicationIdentifier` and its persistent resource storage/target
  ([Setup step 2](README.md#2-two-flow-contexts-two-ports)).
- Behat must run with `FLOW_CONTEXT=Testing/Behat` (or a sub context) - Neos.Behat 9.1 refuses other contexts, e.g. a
  container-wide `Development/...` or the SUT context of a CI job.
- Symfony projects are no longer supported - stay on 8.x there.

In the feature files ([Fixtures](README.md#fixtures) shows the result):

| Neos 8 | Neos 9 |
|---|---|
| tag `@fixtures` | `@flowEntities`: Neos.Behat resets the database on this tag now (`FlowEntitiesTrait`; the old `FlowContextTrait` with `@fixtures` is deprecated) |
| `I have/create the following nodes:` | `I have/create the following nodes in site "site":` |
| columns `Identifier \| Path \| Node Type \| Properties \| Language` | `NodeAggregateId \| Parent \| NodeType \| Properties \| DimensionSpacePoint`, optional `Hidden` |
| `Path` (`/sites/site/main/foo`), a `/sites` row | `Parent`: empty = site node, `homepage/main` = tethered child, otherwise the parent's `NodeAggregateId`; no `/sites` row |
| `Language`: `de` | `DimensionSpacePoint`: `{"language":"de"}` |
| `HiddenInIndex` column | `"hiddenInMenu": true` in `Properties` |
| reference properties with node identifiers in `Properties` | `And the following node references:` |
| `I get a node by path "/sites/site" with the following context:` + table | `I get the node "homepage" in dimension '{"language":"de"}'` |
| `I have the following nodes from file "x.yaml" [with overwrites]` | `... from file "x.yaml" in site "site" [with overwrites:]` |
| overwrite columns `identifier \| property \| value` | `nodeAggregateId \| property \| value` |
| YAML exported with the Neos 8 button (nodes keyed by identifier, nested `children`) | rejected - export again ([Exporting existing content](README.md#exporting-existing-content)) |

## 8.x and older

See the [GitHub releases](https://github.com/sandstorm/Sandstorm.E2ETestTools/releases).
