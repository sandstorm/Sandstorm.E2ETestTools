# Changelog

What changed for projects using the package, the newest version first. Versions follow SemVer since 10.0.0 - see
[Versions](README.md#versions). A major version comes with upgrade notes for the steps and the playwright-bridge.

## 11.0.0

### Upgrading the steps to 11.0

1. Require `"sandstorm/e2etesttools": "^11.0"` in your site package's `composer.json`, `composer update`.
2. **Renamed steps** - replace them in your `.feature` files (otherwise Behat reports them as undefined):

   | 10.x | 11.0 |
   |---|---|
   | `I log into the backend using credentials "admin" "password"` | `I log into the backend as "admin" with password "password"` |
   | `I log into the backend using credentials "admin" "password" with username placeholder "..." and password placeholder "..."` | `I log into the backend as "admin" with password "password", with username placeholder "..." and password placeholder "..."` |
   | `the API "weather" should have received "GET" "/forecast" 2 times` | `the API "weather" should have received "GET" "/forecast" exactly 2 times` |
   | `every image should load the fitting srcset image, except:` | `every image should load the fitting srcset image except:` |

   ```bash
   # in your site package - review the diff afterwards
   find Tests -name '*.feature' -exec sed -i.bak -E \
       -e 's/I log into the backend using credentials ("[^"]*"|'"'"'[^'"'"']*'"'"') ("[^"]*"|'"'"'[^'"'"']*'"'"')( with username placeholder)?/I log into the backend as \1 with password \2\3/' \
       -e 's/(should have received "[^"]*" "[^"]*") ([0-9]+) times/\1 exactly \2 times/' \
       -e 's/(["'"'"']) with username placeholder/\1, with username placeholder/' \
       -e 's/srcset image, except:/srcset image except:/' {} \; -exec rm {}.bak \;
   ```
3. **Moved step:** `I load all images of the page` is in `PageHealthTrait` now (was `ResponsiveImageTrait`) - add
   `use PageHealthTrait;` to your `FeatureContext` if you use `ResponsiveImageTrait` without it.
4. **`every image should be loaded` no longer loads lazy images** - a step that checks, doesn't change the page. Put
   `When I load all images of the page` before it, otherwise lazy images outside the viewport fail with "still loading
   after 15 s".
5. **Elements: the copy the visitor sees.** `PageAssertionsTrait` and `FormInteractionTrait` ignore hidden copies (e.g.
   the desktop variant on a mobile viewport) instead of failing in Playwright's strict mode - and no longer check the
   first of several matches:
   - two visible matches fail now (`the button "Delete" should be visible` with a button per row) - scope the step to
     one element (test id) or fix the markup;
   - `should be hidden` fails when any copy is visible;
   - `I focus the :role :name` fails with several visible matches instead of focusing the first.
6. **Mails: the HTML part counts.** `the mail to :recipient should contain :text` checks the HTML part (as visible
   text) as well as the plain text - the part most mail clients show. A mail whose HTML part lacks the text fails now,
   e.g. "Label: value" in the text template but a table without colons in the HTML template: write the labels the same
   way in both templates.
7. **Navigations to other hosts arrive.** With `requests to other hosts are recorded and blocked`, a link, form,
   script or iframe navigating to another host gets an empty page instead of failing with `net::ERR_FAILED` (other
   requests stay blocked). Check where it led with `the URL should start with :url`. A server redirect (302/303) to
   another host isn't covered - see the README. Own steps that read `vars.externalHosts`: the recorded requests are
   full URLs in `vars.externalRequests` now - `vars.externalRequests.map((url) => new URL(url).host)`.
8. `the element with test id :testId should be :state` is one step per state now - the wording stays the same
   (`visible`, `hidden`, `focused`, `enabled`, `disabled`); an unknown state is an undefined step instead of an error.
9. The [new steps](#new-in-110) can have the same wording as steps of your project (e.g. `the mail to :recipient
   should be from :address`, `the radio button :name should be visible`) - Behat reports both as ambiguous. Delete
   yours - the package's steps match names exactly (see [Notes on the steps](README.md#notes-on-the-steps)).

### Upgrading the playwright-bridge to 11.0

Update your copy as described in [Setup step 5](README.md#5-playwright-playwright-bridge), restart it. Contexts and
pages a script opens itself (`browser.newContext()`, `browser.newPage()`) wait up to 15 seconds per action or
navigation, like the scenario's context - with Playwright's 30 s default they hit the 30 s of the request to the bridge
first, and the step failed with a bare cURL timeout. Without the update everything keeps working, except this.

### New in 11.0

- `the URL should start with :url` (NavigationTrait) - e.g. where a link to a payment provider led.
- MailpitTrait: `the mail to :recipient should not contain :text`, `... should be from :address`, `... should have no
  attachments` and `... should have the attachment :fileName of type :contentType`.
- `the :role :name should ...` also takes the words of the form steps: `field` (a form field by its label) and `radio
  button` (`the field "Email" should be visible`, `the radio button "Express" should be enabled`) - they failed with
  an unknown role before.
- Failures say when only hidden matches were found, or when one of several visible matches is hidden by `opacity: 0`,
  off-screen positioning or sr-only (Playwright counts these as visible).
- A request to the bridge that takes too long fails with an explanation (#1761200001) instead of the bare cURL error.
- `playwrightRunOnVisible()` and `playwrightRoleLocator()` for own steps: a script on the visible copy of a locator
  (with the same failure hints), a locator by role with the form-step words.
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
  disabled`) - Behat reports both as ambiguous. Delete yours, or don't use the trait. Mind: the package's steps find
  buttons, links and fields by their **exact** name - texts your steps found as a part (a partial label, a missing
  trailing period) fail now; copy the exact texts from the page.

**Changed behaviour:**

- The assertions of `PageAssertionsTrait`, `FormInteractionTrait`, `BrowserStateTrait` and `the URI path should be`
  wait for the expected state instead of checking once, up to `setPlaywrightTimeout()` (default 5000 ms) - a step that
  was flaky because of a slow page passes now. Their failure messages come from Playwright (expected, received, call
  log) instead of PHPUnit. `$pageAssertions_timeout` still works, but is deprecated.
- `there should be the text ... on the page` checks the rendered text, like its `not` variant: text in hidden elements
  counts as absent, the case matters, and it waits 5 seconds instead of 15. A step that found hidden text before fails
  now - check the element that shows the text, or wait for it with another step first. Rendered text also means:
  `text-transform` applies, line breaks between block and flex items, and form values aren't part of it - see
  [Notes on the steps](README.md#notes-on-the-steps).
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
