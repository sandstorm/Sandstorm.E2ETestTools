# Neos E2E Testing Guide

How to write **meaningful** end-to-end tests and step implementations for Neos 9 projects with this package. It assumes
the package is set up (see [README](README.md)) and doesn't repeat Behat, Gherkin or Playwright basics. What's here:
what to test in a Neos project, on which level, with which fixtures and assertions, and what to avoid.

Written for humans and for coding agents. Rules are one bullet each, with the reason after "-".

Steps marked `(proposed)` are not shipped yet - they are collected in [PROPOSED_STEPS.md](PROPOSED_STEPS.md). Until
they are, implement them in your project's `FeatureContext`.

<!-- TOC -->
- [1. What to test - and on which level](#1-what-to-test---and-on-which-level)
  - [Test your project, not Neos](#test-your-project-not-neos)
  - [Configuration features vs. logic features](#configuration-features-vs-logic-features)
  - [Pick the lowest level that catches the bug](#pick-the-lowest-level-that-catches-the-bug)
- [2. Feature catalogue](#2-feature-catalogue)
- [3. Fixtures](#3-fixtures)
- [4. Assertions](#4-assertions)
- [5. Writing step implementations](#5-writing-step-implementations)
- [6. Deriving tests from the code](#6-deriving-tests-from-the-code)
- [7. Anti-patterns](#7-anti-patterns)
<!-- /TOC -->

## 1. What to test - and on which level

### Test your project, not Neos

- Test what the project configures or implements: its NodeTypes, Fusion, routing, settings, PHP - Neos itself is
  tested upstream.
- No "a Text node renders its text" tests for Neos' own NodeTypes; yes "our Teaser links to its target and renders
  nothing without one".
- Test behaviour that a visitor or editor would notice, not implementation details - Fusion prototypes get renamed,
  the page must still work.

### Configuration features vs. logic features

Most Neos features are **configuration features**: NodeTypes + Fusion + CSS/JS, no PHP. Their bugs are wiring
mistakes (wrong property name, missing `@if`), broken constraints, content edge cases, stale caches and broken
markup. They are found by rendering real nodes - Fusion integration and page tests are the main tool.

**Logic features** contain PHP: Eel helpers, data sources, controllers/plugins, form finishers, route part handlers,
CR catch-up hooks, backend modules. Their logic belongs in unit/functional tests (fast, all branches). E2E tests
cover the happy path and the seams: is the helper called with the right node, does the plugin render in the page,
does the finisher actually send the mail.

| | Configuration feature | Logic feature |
|---|---|---|
| Example | Teaser element, main menu, footer | Search, event filter, form with CRM finisher |
| Main risk | wiring, content edge cases, caching | branches of the logic, integration seams |
| Main test | Fusion integration + page test | unit/functional test |
| E2E | one scenario per content variant that renders differently | happy path + one error path |

### Pick the lowest level that catches the bug

| Level | Steps | Catches | Doesn't catch |
|---|---|---|---|
| Fusion component | `I render the Fusion object :path:` | markup, props handling, conditionals | node wiring, routing, JS, caching |
| NodeType integration | `I get the node ...` + `I render the Fusion object :path with the current context node:` | property → prop wiring, child rendering, references, node links | routing, JS, HTTP caching |
| Page (browser) | `I access the URI path ...` + page assertions | routing, dimension resolution, menus in context, content cache, JS, status codes | backend behaviour |
| Backend (browser) | `I log into the backend ...` + navigation steps | editor experience, permissions, inspector | - (slowest, use sparingly) |

- Component and integration tests run in the Behat process - no web server, no browser, fast. Prefer them for
  everything that is "this content renders as that markup".
- Page tests are needed when the bug lives between request and response: routing, dimensions, caching, JS.
- A feature usually needs both: integration scenarios for the content variants, one page scenario that it works in
  a real request.

## 2. Feature catalogue

Features nearly every Neos project has, with what is worth asserting. Use it as a checklist: for each feature the
project has, decide which rows apply.

### Navigation / menus

- **Can break**: levels, order, labels, current/active state, `hiddenInMenu`, hidden documents shown, shortcuts
  pointing nowhere, menus per dimension.
- **Assert**: the resulting structure (labels in order, nesting, which item is current) - not the markup details.
  Negative cases: a hidden document and a `hiddenInMenu` document are **not** in the menu.
- **Reachability**: every link in the menu answers 200 - catches broken uriPathSegments, shortcuts to removed
  targets, wrong dimension prefixes.
- **Level**: page test (menus depend on the current document and the request).
- **Fixture**: site + 2 levels of documents incl. one hidden, one `hiddenInMenu`, one shortcut. Export the real
  document tree and overwrite single nodes for the edge cases.

```gherkin
Scenario: main menu shows visible pages in order and marks the current one
  Given I have the following nodes from file "page-tree.yaml" in site "site" with overwrites:
    | nodeAggregateId | property     | value |
    | imprint         | hiddenInMenu | true  |
  When I access the URI path "/about"
  Then the menu "nav.main" should contain:                    # (proposed)
    | label   | level | current |
    | Home    | 1     |         |
    | About   | 1     | true    |
    | Team    | 2     |         |
  And there should not be the text "Imprint" in "nav.main"   # (proposed)
  And all links in "nav.main" should respond with 200         # (proposed)
```

### Visibility & access

- **Can break**: hidden (disabled) nodes or subtrees rendered, content of protected pages visible, hidden content
  leaking into teasers, search results, sitemap or meta tags.
- **Assert**: absence - the hidden headline is not on the page, the hidden page is 404 and not in the sitemap.
  Also the positive counterpart in the same scenario, otherwise an empty page passes.
- **Level**: page test - `I get the node` reads with removed nodes excluded only, so in-process rendering includes
  hidden nodes; only a real request applies the frontend's visibility.
- **Fixture**: `Hidden` column / `hidden: true` - descendants inherit it, so hide the parent to test the subtree.
- **Pitfall**: timed visibility (publish/unpublish dates) is not part of Neos 9 core - test it only if the project
  uses a package for it, and control the clock.

### Routing & URLs

- **Can break**: uriPathSegment changes, dimension prefixes and fallbacks, custom route part handlers, the 404 page,
  redirects after renaming, canonical URLs, URL suffix/trailing slash.
- **Assert**: status codes and final URI path (`the response status code should be`, `the URI path should be`),
  canonical/hreflang tags.
- **Level**: page test. Route part handlers with logic additionally functional tests.

### Content elements (every NodeType with a Fusion integration)

- **Can break**: a property not wired to the component, empty required properties rendering broken markup, child
  nodes not rendered, inline editing markup leaking into live, constraints allowing invalid trees.
- **Assert per element** (integration level): *empty* (only required properties) renders nothing broken, *full* (all
  properties) shows every value, *extreme* (long text, umlauts, HTML in a text property) stays intact.
- **Constraints**: NodeTypes that may only live in certain collections - creating them elsewhere must fail. A failing
  fixture step fails the scenario, so this needs a step that expects the failure
  (`creating the following nodes in site :site should fail:` - proposed).
- **Pitfall**: the integration test renders in frontend rendering mode - backend-only output (placeholders for empty
  inline-editable properties) needs a backend test.

```gherkin
Scenario: teaser without link target renders no link
  Given I have the following nodes in site "site":
    | NodeAggregateId | Parent    | NodeType                            | Properties         | DimensionSpacePoint |
    | home            |           | Your.SitePackageKey:Document.Home   | {"title":"Home"}   | {"language":"de"}   |
    | section         | home/main | Your.SitePackageKey:Content.Section | {}                 | {"language":"de"}   |
    | teaser          | section   | Your.SitePackageKey:Content.Teaser  | {"title":"Teaser"} | {"language":"de"}   |
  And I get the node "teaser" in dimension '{"language":"de"}'
  When I render the Fusion object "/testcase" with the current context node:
    """
    testcase = Your.SitePackageKey:Content.Teaser
    """
  Then the element "a" should not exist in the fusion output   # (proposed)
```

### Links & references

- **Can break**: `node://` and `asset://` links not resolved, references to hidden or removed nodes breaking the
  page, reference order lost.
- **Assert**: `href` values (`in the fusion output, the attributes of CSS selector ... are:`), and that the page
  still renders (200) when a reference target is hidden or removed.
- **Fixture**: references via `the following node references:`; hide/remove the target mid-scenario
  (`I hide the node` / `I remove the node` - proposed).

### Assets & media

- **Can break**: image variants/`srcset`, missing `alt`, missing asset (deleted image referenced by a node),
  download links.
- **Assert**: attributes (`src`, `srcset`, `alt`), download link answers 200 with the right file name.
- **Fixture**: `I have the following images:` / `I have a textual persistent resource ...`; the asset id must match
  the id in the node property.
- **Pitfall**: thumbnails can be generated asynchronously - assert attributes, not pixels.

### Multi-dimension (languages, markets)

- **Can break**: fallbacks (content in a specialization missing or wrong), language switcher pointing to untranslated
  pages, mixed-language menus.
- **Assert**: per dimension the right text and URL prefix; the switcher links to the matching variant or a defined
  fallback.
- **Fixture**: always set `DimensionSpacePoint` explicitly; create each variant you assert on.

### SEO & meta

- **Can break**: title/description fallbacks, Open Graph image, hreflang set, robots for hidden or noindex pages,
  `sitemap.xml` listing hidden pages.
- **Assert**: tag values with `the page should have the meta tag ...` (proposed), sitemap content
  (`the sitemap should (not) contain ...` - proposed).
- **Level**: page test; title/meta Fusion can be covered on integration level with `I render the page`.

### Forms

- **Can break**: validation messages, required fields, finisher effects (mail, CRM, database), spam protection
  blocking valid input.
- **Assert**: visible validation errors, success message, the finisher's effect (mail received - proposed step for
  Mailpit; database entry via a project step).
- **Level**: browser page test for the flow; finisher logic in unit/functional tests.

### Caching

- **Can break**: a content change not visible on pages that show it elsewhere (teaser, menu, footer), wrong `@cache`
  entry tags, cached personalised content.
- **Assert**: visit page, change content (`I set the property ...` - proposed), visit again, new value visible.
- **Pitfall**: fixture steps run in the Behat process; cache flushes triggered there reach the system under test only
  if both contexts share the cache backend (see README Troubleshooting item 4). A cache test that passes because
  caching is disabled in the SUT context proves nothing - check the SUT context's cache configuration.

### JavaScript components

- **Can break**: sliders, accordions, tabs, consent banners, lazy loading - and the no-JS/consent-denied state.
- **Assert**: behaviour after interaction (element visible/hidden - proposed), not that a script tag exists.
- **Level**: browser page test only.

### Backend / editor experience

- **Can break**: a NodeType not creatable where editors need it, inspector fields missing, custom backend modules,
  permissions per role.
- **Assert**: what an editor with that role can and cannot do - log in as that role, not as administrator.
- **Level**: backend test - slow; cover the critical editor paths, not every NodeType.

### Error resilience

- **Can break**: empty collections, missing references, removed NodeTypes in old content, the 404 and error pages.
- **Assert**: the page renders (200) and the broken part is left out; the 404 page answers 404 with its content.

## 3. Fixtures

- **One minimal tree per scenario** - only the nodes the assertion needs plus the parents and tethered nodes
  required to create them. Big shared fixtures make failures hard to read and couple scenarios.
- **Readable ids** (`home`, `teaser-without-link`) for hand-written rows - the id is in every failure message.
- **Real content for page tests**: export the page (button or `./flow e2efixture:export --uri-path ...`) and keep it as
  YAML next to the feature - realistic NodeType combinations and texts, valid against the current NodeTypes.
- **Edge cases via overwrites** (`... with overwrites:`) instead of a second copy of the YAML - the scenario shows
  what differs.
- **Respect constraints and tethered paths** - `Parent` is `<owner>/main` for content in a tethered collection, and
  the NodeType must be allowed there. A constraint error in the fixture is a test result, not noise.
- **Explicit dimensions** on every row - an empty `DimensionSpacePoint` in a project with dimensions creates nodes
  nobody can see.
- **Assets via steps** with fixed ids - node properties reference them by id.
- **`@flowEntities` on every scenario that creates nodes** - the content repository is reset per scenario; never rely
  on nodes from an earlier scenario.
- **Never against the dev database** - the E2E context has its own database (README setup step 2).
- **Don't**: prod DB dumps as fixtures (personal data, huge, unreadable), SQL inserts (bypass the content repository),
  fixtures that only pass because of a specific order of scenarios.

## 4. Assertions

- **Structure and content, not whole HTML** - `the Fusion output should equal to` breaks on every whitespace or class
  change; assert the parts that carry meaning (text, `href`, `alt`, number of items).
- **Stable selectors** - roles, ARIA attributes, semantic elements or dedicated `data-test` attributes; not utility or
  design-system classes, which change with styling.
- **Always pair negative with positive** - "the hidden teaser is not there" also passes on an empty page; assert a
  visible sibling in the same scenario.
- **Status codes for reachability** - 200 for every page a visitor can reach, 404 for hidden/removed ones.
- **Screenshots and the style guide document, they don't assert** - use them for review and visual regression
  tooling, not as the test's only check.
- **No sleeps** - Playwright waits for elements; a fixed wait hides timing bugs and slows the suite.
- **One behaviour per scenario** - the scenario name says what broke; 20 assertions in one scenario say nothing.
- Note: `... the inner HTML of CSS selector ... matches ...` compares for equality (after the selector), not as a
  regular expression.

## 5. Writing step implementations

- **Domain language, not clicks** - `When I open the main menu` / `Then the menu ... should contain`, not "click
  `.nav-toggle`, wait, read `li`". Selectors live in the step implementation, so a markup change is one fix.
- **Parameterised and generic** - one `the element :selector should be visible` (proposed) beats five element-specific
  steps; project-specific steps only for project-specific concepts.
- **Where**: project steps in your `FeatureContext` or a project trait it uses; generic ones are candidates for this
  package ([PROPOSED_STEPS.md](PROPOSED_STEPS.md)).
- **Content changes through the content repository API** - commands (`SetNodeProperties`, `TagSubtree`,
  `RemoveNodeAggregate`) and subgraph queries, never SQL - projections and caches depend on the events.
- **Know which process you're in** - steps run in the Behat process (`Testing/Behat`); the browser hits the system
  under test (SUT context). Things the SUT must see happen in the database or a shared cache, or run as
  `executeFlowCommand()` in the SUT context.
- **Browser steps**: locate by role/text (Playwright locators), let Playwright wait, return values to PHP and assert
  there with PHPUnit's `Assert` - the failure message then shows expected and actual value.
- **Failure messages with the actual state** - "menu contains [Home, About], expected Team" instead of "assertion
  failed".
- **No hidden retries** - a step that retries until green masks flaky features.
- **Reset what you change** - static state, settings or clock overrides set by a step are reset in an
  `@AfterScenario` hook.

## 6. Deriving tests from the code

A procedure for finding what to test - for a person planning a test suite, or an agent generating one.

1. **Inventory** the project's features from the code:
   - NodeTypes (`NodeTypes/`, `Configuration/NodeTypes.*.yaml`): document vs. content types, properties (which are
     optional, which reference nodes/assets), `constraints`, `childNodes`, mixins like `hiddenInMenu`.
   - Fusion: integration prototypes per NodeType, page/menu prototypes, `@cache` configuration, `@if` conditions
     (each condition is a content variant).
   - Settings: content dimensions, routing, site configuration.
   - PHP: Eel helpers, data sources, controllers, finishers, route part handlers - logic features.
   - `Policy.yaml`: roles that change what visitors or editors see.
   - JS/CSS: interactive components (sliders, accordions, consent).
2. **Classify** each feature with the [feature catalogue](#2-feature-catalogue) and as configuration or logic feature.
3. **Pick levels** per feature ([table above](#pick-the-lowest-level-that-catches-the-bug)) and list the content
   variants worth a scenario (empty / full / extreme / hidden / other dimension).
4. **Write the scenario list first** (names only), review it, then write fixtures and steps - the list is cheap to
   change, written scenarios aren't.
5. **Find the URLs** for page tests in the real content and export fixtures from there.

Related skills in the Neos-on-Docker context repository complement this, they don't replace feature tests:
`extract-features-with-urls` (sample URLs per feature from the sitemap), `rendering-smoke-test` (every URL renders
without exception), `visual-regression-test` (screenshots against golden references).

## 7. Anti-patterns

- **Testing Neos core** - Neos' NodeTypes, routing internals, the UI itself. Upstream tests cover them.
- **Whole-page HTML equality** - breaks on every change, nobody reads the diff.
- **One scenario per page with many assertions** - the first failure hides the rest, the name says nothing.
- **Shared mega fixture** for the whole suite - every change breaks unrelated scenarios.
- **Prod DB dumps as fixtures** - personal data, unreadable, outdated with the next NodeType change.
- **Order-dependent scenarios** - each scenario creates what it needs.
- **Design-class selectors** (`.mt-4.text-blue-600`) - restyling breaks the tests, not the feature.
- **Sleeps** instead of waiting for state.
- **Asserting that an element exists** where the feature is what it shows (text, link target, state).
