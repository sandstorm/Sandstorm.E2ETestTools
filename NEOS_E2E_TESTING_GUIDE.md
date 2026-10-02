# Neos E2E Testing Guide

This guide helps you write end-to-end tests that are worth having in a Neos 9 project: tests that catch real bugs,
are easy to read and don't break for the wrong reasons. It's written for people and for coding agents. It assumes
the package is already set up (see the [README](README.md)) and doesn't explain Behat, Gherkin or Playwright
basics. Many of the rules come from test suites of production Neos projects - from what worked there and from what
didn't. They are defaults: deviate where your project needs it, and write down why.

**How to read it:** the [principles](#principles) are the short version. When you work on a feature, follow
[from feature to scenarios](#from-feature-to-scenarios) - it tells you when to look into the other sections.

**Terms used throughout:** the *site under test* is the copy of your site that the browser visits during a test run
(its own Flow context, database and port). The *Behat process* is where your steps run - it creates the test data and
talks to the browser. *Rendering states* are the situations in which a feature looks or behaves differently: with or
without image, empty or very long text, optional parts, logged in or not, another language.

**Shipped and project steps:** steps marked "project step" in the examples don't come with this package - you write
them in your project's `FeatureContext`. All other steps are shipped (see the steps overview in the README).

<!-- TOC -->
- [Principles](#principles)
- [From feature to scenarios](#from-feature-to-scenarios)
  - [Covering an existing project](#covering-an-existing-project)
- [Prioritising](#prioritising)
- [Three questions per behaviour](#three-questions-per-behaviour)
  - [Where does the behaviour come from?](#where-does-the-behaviour-come-from)
  - [Where does the test run?](#where-does-the-test-run)
  - [How much interaction does it take to test the feature?](#how-much-interaction-does-it-take-to-test-the-feature)
- [Feature catalogue](#feature-catalogue)
- [Scenario patterns](#scenario-patterns)
- [Test data and environment](#test-data-and-environment)
  - [Content fixtures](#content-fixtures)
  - [External APIs](#external-apis)
  - [Mail catching (Mailpit)](#mail-catching-mailpit)
  - [Caches](#caches)
- [Keeping the suite fast](#keeping-the-suite-fast)
- [Writing feature files](#writing-feature-files)
- [Writing step implementations](#writing-step-implementations)
- [Review checklist](#review-checklist)
<!-- /TOC -->

## Principles

- **Test your project, not Neos.** Test what your project adds: its NodeTypes, Fusion, JavaScript, routing, PHP and
  integrations. Neos itself is tested by the Neos team. So don't test that "a Text node renders its text", but do
  test that "our teaser links to its target and shows no link when there is none".
- **Test what a visitor or editor notices.** Check texts, links, states and whether pages can be reached - not how
  they're built. Fusion prototypes get renamed during refactoring; the test should only fail when the page really
  changed.
- **Prove each feature in the browser, cover its states cheaply.** One scenario visits the page in a real browser,
  just like a visitor - that's what proves the feature works. Further rendering states are rendered directly with
  Fusion, and logic is tested with unit tests - much faster. Use the Neos backend only for what editors experience.
  See [where does the test run](#where-does-the-test-run) and [keeping the suite fast](#keeping-the-suite-fast).
- **One behaviour per scenario.** Then the scenario's name tells you what broke, and one failure doesn't hide the
  checks that come after it. Several independent checks on the same page state are fine; a sequence of actions for
  different behaviours is not.
- **Realistic data, but only as much as needed.** Start from real content exported from the site, keep only what the
  scenario needs, and add the edge cases on top. See [content fixtures](#content-fixtures).
- **Make results reproducible.** The tests use their own database, which is reset before each scenario. External
  systems are replaced by mocks, and steps wait for a state instead of a fixed time.
- **Every scenario stands on its own.** It must not depend on the order of scenarios or on anything an earlier
  scenario left behind.
- **Explain what isn't obvious.** Write down the rule a scenario checks, why the test data looks the way it does, and
  what you deliberately don't check.

## From feature to scenarios

This is the main workflow - use it for the feature you're building or fixing right now.

1. **Describe the feature** in one or two sentences, from the point of view of a visitor or editor, and list its rules
   and rendering states. To find them, read the ticket and the related code: NodeType configuration, rendering
   (Fusion, CSS, JavaScript/TypeScript) and PHP.
2. **Classify it.** Find out [where its behaviour comes from](#where-does-the-behaviour-come-from) and look up its
   [catalogue entry](#feature-catalogue) - the catalogue lists what typically breaks for this kind of feature.
3. **Decide how to test each behaviour:** [where the test runs](#where-does-the-test-run), and
   [how much interaction it takes](#how-much-interaction-does-it-take-to-test-the-feature) to test it.
4. **Write down the list of scenarios first** - names only. Cover the rendering states (empty, full, extreme, logged in
   or not, other language), the interaction paths of journeys, the roles, the edges of the rules, the cases where
   something must *not* happen, and the bug you're fixing.
   Review the list before writing anything else: changing a list is cheap, rewriting scenarios isn't.
5. **Prepare the test data.** Build example content in the backend, or find a page that already shows the feature, and
   export it by its URL. Replace external systems by mocks. See [test data and environment](#test-data-and-environment).
6. **Write the steps.** Use the shipped steps first and add project steps only for concepts of your project. See
   [writing step implementations](#writing-step-implementations).

### Covering an existing project

1. **List the features** you find in the project: NodeTypes (document and content types, their properties, mixins like
   `hiddenInMenu`), Fusion (integration prototypes, page and menu prototypes, lists, tiles and previews, and their
   `@cache` configuration), settings (dimensions, routing, sites), FlowQueries in Fusion, PHP
   application code (services, controllers, plugins, finishers, route part handlers, API clients), JavaScript
   components, and roles in `Policy.yaml` that change what visitors or editors see.
2. **Decide what comes first** - see [prioritising](#prioritising).
3. **Run the workflow above** for one feature after the other.

## Prioritising

Not every feature deserves the same tests. Write the tests that matter for your domain - and decide by risk, not by
what's easy to test.

- **Risk = likelihood of breaking × cost of failing.** A feature breaks more likely when it changes often, is complex,
  has broken before, or editors fill it with many different kinds of content. A failure costs more when it loses
  revenue or leads (checkout, contact forms), creates legal risk, produces support requests or damages the reputation.
  Test what scores high on both first and in depth; what scores low on both maybe not at all.
- **Formal requirements are must-haves, however rarely the feature changes.** Accessibility is a legal obligation in
  many cases - for example for e-commerce and many services in the EU under the European Accessibility Act, and for
  public sector sites. The same goes for consent (no tracking before the visitor agreed), legally required pages
  (imprint, privacy policy) and access restrictions.
- **Spend the expensive tests where they pay off.** A practical split:
  - **must not break** - revenue, legal requirements, access: journeys including their error paths, run on every
    merge request;
  - **content features editors use a lot**: their rendering states, mostly with direct Fusion rendering, plus one
    browser scenario each;
  - **cosmetic or rarely changed**: the style guide and a visual review, no assertions.
- **Count the cost of keeping a test green.** Backend tests and long journeys break more often for reasons that have
  nothing to do with the feature. Use them for the first group only.

## Three questions per behaviour

Before writing a scenario, answer three questions for the behaviour it tests: where it comes from decides where its
logic is tested cheapest, where the test runs decides how fast and realistic it is, and how much interaction it takes
decides how long the scenario gets. What typically breaks is listed per feature in the [catalogue](#feature-catalogue).

### Where does the behaviour come from?

#### Markup and styling

The content of a node is turned into HTML, CSS styles it, and presentation JavaScript (a slider, an accordion) adds
behaviour without real logic. In Neos this is mostly Fusion, sometimes Fluid templates or helpers written in PHP - for
the test it doesn't matter how, only what the visitor gets. Typical bugs: a property that isn't rendered, a missing
condition, markup that breaks for unusual content.

**How to test:** one scenario per rendering state ("teaser without link target renders no link"). Many states of one
element can be rendered [directly with Fusion](#direct-fusion-rendering---for-many-rendering-states); one browser
scenario checks the element in a real page.

#### Querying nodes

Fusion loads other nodes from the content repository with FlowQuery: lists, tiles, menus, "related pages", filters like
"upcoming events". The `@cache` configuration decides when such a result is calculated again. Typical bugs: the query
starts at the wrong node, the filter misses an edge case, the cache doesn't notice new nodes.

**How to test:** test data with the edge cases (a node of a type that must not appear, more entries than the limit),
then check the visible entries and their order. For caching, change something after the first visit
([before/after](#beforeafter-a-change)).

#### Client-side logic

JavaScript decides what happens when the visitor interacts with the page: filters, configurators, price calculators, the
basket, the cookie consent. This is real logic with code branches and state, even if no PHP is involved. Typical bugs:
a code branch nobody tried, state that gets out of sync, an interaction path that behaves differently.

**How to test:**

- the calculation and state logic with JavaScript unit tests - fast, and they can cover every code branch;
- in the browser, one scenario per interaction path that leads to a different result: "changing the size after the
  quantity keeps the quantity and updates the price".

#### Application logic in PHP

PHP code you write for business rules or to connect other systems: services, domain models, controllers and plugins,
form finishers, route part handlers, API clients. Typical bugs: a gap in a business rule, data from another system
mapped wrongly, parts that don't fit together (the plugin isn't on the page, the finisher isn't called).

**How to test:** mainly with PHP unit and functional tests. In the browser only the normal case and one error case, to
check that the parts work together: "a valid voucher reduces the total", "an expired voucher shows an error message".

#### External systems

The behaviour depends on another system: a shop backend, a CRM, a newsletter service, a mail server. Typical bugs: the
agreement between the systems changes, error answers aren't handled, the call isn't made or carries the wrong data.

**How to test:** browser scenarios against mocks of the other system, including its error answers (see
[external APIs](#external-apis) and [mail catching](#mail-catching-mailpit)).

#### Combined kinds

Most features combine several kinds. In a shop, the product list of a category is a FlowQuery, adding and removing
items in the basket is client-side logic, the prices come from the shop backend (an external system), voucher rules
are application logic in PHP, and the mini basket in the header is markup and styling. Test each part where it's
cheapest, and test in the browser whether the parts work together: does the plugin show up on the page, does the list
show the right products, does the form finisher really send the mail.

### Where does the test run?

#### In the browser - one scenario per feature

`I access the URI path ...` sends a real request through the web server, routing and caches, and the page runs its
JavaScript in a real browser - exactly what a visitor gets. Example: "the main menu marks the current page" needs a real
request, because the current page only exists there; "the slider shows the next image" needs JavaScript. A browser
scenario is what proves that a feature works.

#### Direct Fusion rendering - for many rendering states

`I render the Fusion object ...` renders a component - or, after `I get the node ...`, a NodeType's integration with a
real node - directly to HTML, inside the Behat process. There is no HTTP request and no browser. That makes it fast,
and when it fails you know the cause is in Fusion or in how the NodeType is wired. Strictly speaking, this is a
component test written with Behat, not an end-to-end test.

Use it when one element has many rendering states: ten teaser states render in about a second, and one browser
scenario then checks the teaser in a real page. Direct rendering can't see routing, caches, JavaScript, or the
visibility and access rules of the website - `I get the node` only leaves out removed nodes, so hidden nodes are
rendered. A pure component without a node doesn't need the content repository at all - the fastest scenario there is.

#### In the backend - for the editor experience

`I log into the backend ...` and the navigation steps drive the Neos user interface. This is the slowest and most
fragile way to test, so use it only for what editors see and do: "an editor can add a teaser to the main column", "an
editor without the right role can't open the order module".

### How much interaction does it take to test the feature?

#### None - opening the page is enough (the default)

The result depends only on content and state that you can set up as test data: create it, visit the page once, check
the result - a handful of steps. "The button links to the contact page", "the main menu marks the current page".
Anything you can set up as test data belongs there, not into steps that click it together in the browser. Short
scenarios fail precisely and run fast.

#### Journey - when the interaction is the feature

Here the visitor's actions change something that later results depend on: basket and checkout, login and account,
consent, wishlists, forms with several steps. The order of the steps *is* the feature. "A completed checkout shows the
order confirmation and sends the confirmation mail" can only be tested by adding items, going through the checkout and
checking the page and the mail at the end. In shops and app-like sites, such journeys *are* the integration test, and
the suite grows large - that's fine, as long as you follow these rules:

- **One behaviour per journey.** Test one discount type per scenario (or use a `Scenario Outline` with one row per
  type), not one long scenario that walks through product page, search, mini basket and basket for every type.
- **One scenario per interaction path.** A journey usually has several paths: buying as a guest or with an account,
  paying by invoice or by direct debit, going back a step, cancelling halfway. Test each path that leads to a
  different result as its own scenario - bugs often hide where paths meet, for example going back after changing the
  address.
- **Start as late as possible.** Set up everything that isn't the behaviour you're testing as test data: content,
  mocked API answers, a logged-in user if the login isn't what you test, the cookie consent if the banner isn't what
  you test. See [reaching a late start state](#reaching-a-late-start-state).
- **Check at the moments that matter** - after each action that belongs to the behaviour, not after every click.

## Feature catalogue

Features that nearly every Neos project has. Each entry says what typically breaks, what to check, where to test it,
what test data you need, and which [pattern](#scenario-patterns) or pitfall matters.

### Navigation and menus

- **What breaks:** levels, order, labels, the marking of the current page, pages that are missing, shortcuts that
  point nowhere, menus in other languages - and, very often, caching: a newly created or renamed page doesn't appear in
  the menu, because the cached menu isn't updated.
- **What to check:** the resulting structure - labels in the right order, nesting, which item is marked as current -
  rather than markup details. Every link in the menu must answer with status 200 - a cheap check that catches broken
  URL segments and wrong language prefixes. A newly created page must appear in the menu without anyone flushing the
  cache. Neos' menu prototypes leave out hidden pages and pages with `hiddenInMenu` by themselves - check
  that only for menus built with their own query.
- **Where:** in the browser, because menus depend on the current page and the request. Menus are built with
  [FlowQueries](#querying-nodes).
- **Test data:** the exported page tree, with the edge cases added as overwrites (a shortcut, a very long page title).

```gherkin
Scenario: main menu shows the pages in order and marks the current one
  Given I have the following nodes from file "page-tree.yaml" in site "site"
  When I access the URI path "/about"
  Then the menu "nav.main" should contain:                    # project step
    | label   | level | current |
    | Home    | 1     |         |
    | About   | 1     | true    |
    | Team    | 2     |         |
  And all links in "nav.main" should respond with 200         # project step
```

Caching needs its own scenario that follows the [before/after pattern](#beforeafter-a-change) - with no cache flush
in between, because whether the menu updates by itself is exactly what's tested (see [caching](#caching)).

```gherkin
Scenario: a newly created page appears in the main menu without a cache flush
  Given I have the following nodes from file "page-tree.yaml" in site "site"
  When I access the URI path "/about"
  Then there should not be the text "Careers" in "nav.main"
  When I create the following nodes in site "site":
    | NodeAggregateId | Parent | NodeType                          | Properties                                     | DimensionSpacePoint |
    | careers         | home   | Your.SitePackageKey:Document.Page | {"uriPathSegment":"careers","title":"Careers"} | {"language":"de"}   |
  And I access the URI path "/about"
  Then there should be the text "Careers" in "nav.main"
```

### Visibility and access

Neos already keeps hidden nodes and pages with `hiddenInMenu` out of the website - that's Neos' job and doesn't need
your tests. What's worth testing is content meant for certain users only, and places where your project bypasses
Neos. Visible content that a list or query leaves out is covered under [lists](#lists-previews-and-tiles).

- **What breaks:**
  - **content for certain frontend users** (an intranet, a members area) is shown to the wrong visitors - or not to the
    ones who may see it. These pages are not hidden in the tree; who sees them depends on the visitor's login and role;
  - **restricted editing in the backend:** some editors may only see and edit a certain subtree, and see or change more
    or less than their role allows;
  - hidden content leaks where your project bypasses Neos' visibility rules: its own PHP queries, a search index, the
    sitemap, meta tags or exports.
- **What to check** - [one scenario per role](#one-scenario-per-role):
  - for content for certain users: a user with the role sees the page, its menu entry, teasers and search results; a
    user without it and an anonymous visitor see none of these, and opening the page's URL directly leads to the login
    or an access error;
  - for restricted editing: the editor sees and can edit their subtree, and can neither see nor edit the rest -
    neither in the document tree nor by opening a page directly;
  - absence of hidden content only where your project bypasses Neos' visibility rules - and always together with
    something visible (see [assertions](#assertions)).
- **Where:** in the browser for the website, in the backend for restricted editing - only a real request applies the
  website's visibility and access rules.
- **Test data:** users with the roles of your project - backend users with
  `I have a Neos backend user :username with password :password and role :role`, frontend users with a project step.
  The `Hidden` column or `hidden: true` where a hidden node is part of the case; child nodes inherit it, so hide the
  parent to test a whole subtree.

### Routing and URLs

Neos builds the URLs of document nodes from their URL segments, and editors can change those segments in most
projects - so testing a specific page URL usually tests nothing but the test data. What's worth testing is where your
project relies on URLs or adds routes itself.

- **What breaks:**
  - **your own controllers and actions** (plugins, routes in `Routes.yaml`, API endpoints) can't be reached, can be
    reached by the wrong users, or crash on unexpected input;
  - **fixed URLs your project relies on** change: auto-created child documents with a fixed URL segment, URLs that are
    printed, sent in mails or used by apps and other systems;
  - redirects after a page was renamed (when your project uses a redirect package), language prefixes and the 404 page;
  - custom route part handlers resolve the wrong node or build wrong URLs.
- **What to check:**
  - for your own controllers and actions: the route answers with status 200 for the users who may use it, and with
    the login or an access error for everyone else ([one scenario per role](#one-scenario-per-role)). Invalid input
    leads to an error page or message (400, 404), not to a crash (500);
  - for fixed URLs: the URL leads to the right page, with `the response status code should be` and
    `the URI path should be`;
  - after renaming a page, the old URL redirects to the new one;
  - an unknown URL answers with 404 and shows the 404 page.
- **Where:** in the browser. Route part handlers and controllers with their own logic also get PHP functional tests.

### Content elements (every NodeType with a Fusion integration)

- **What breaks:** a property that isn't passed to the component (often after a rename in the NodeType), empty
  properties that produce broken markup, child nodes that aren't rendered, editing markup of the backend that shows up
  on the live site, CSS that cuts off long texts or breaks on mobile, and constraints that allow content in places
  where it doesn't belong.
- **What to check:**
  - **Every building block renders without an error.** Each component and each NodeType integration must render with
    only its required properties filled - one broken element can break the whole page. A `Scenario Outline` over all
    content NodeTypes is a cheap safety net. Fusion sometimes turns an error into an error message in the output
    instead of failing, so also check that the output contains no rendering error (a project step like
    `the fusion output should not contain a rendering error`).
  - **Each element in three rendering states:** *empty* (only required properties) renders nothing broken, *full* (all
    properties) shows every value, *extreme* (long text, umlauts, HTML in a text property) stays intact.
  - **Constraints:** creating a NodeType where it isn't allowed must fail. A failing test data step fails the whole
    scenario, so you need a step that expects the failure (a project step like
    `creating the following nodes in site :site should fail:`).
- **Where:** in the browser; when there are many rendering states, render them directly with Fusion.
- **Pitfall:** direct Fusion rendering shows the live site's output. What only the backend shows (for example
  placeholders for empty editable properties) needs a backend test.

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
  Then the element "a" should not exist in the fusion output   # project step
```

### Links and references

Much of this is Neos' job: a link in a text to a page the editor has hidden is simply not rendered, and references
to hidden or removed nodes are left out. That needs no tests of your own. What is yours is the decision what a
component does when its link is missing or of a certain kind - and that decision differs from component to component.

- **What breaks:**
  - **an empty link target** - the editor selects nothing in the link editor, or the linked page is hidden or removed
    later. What should happen depends on the component: inside a bigger component (a teaser, a tile) the link is left
    out and the rest stays; a button is shown disabled or not at all; a component without its link makes no sense and
    disappears. Without a test, the result is often an `<a>` without `href`, an empty button or a link to the current
    page;
  - **external links** are meant to look and behave differently - an icon, a new tab, `rel="noopener"` - and lose that,
    or internal links get it too;
  - `node://` and `asset://` values of a link property that your Fusion passes on without turning them into real URLs;
  - a component that shows referenced nodes renders an empty frame when all of them are hidden or removed.
- **What to check:**
  - **one scenario per link state the component handles differently**: internal, external, asset, empty - and, where
    it matters, a target that is hidden or removed later;
  - the visible result of each state: the link and its `href` (`in the fusion output, the attributes of CSS selector
    ... are:`), the disabled button, the icon of the external link, or that there is no link at all - always together
    with the rest of the component that must still be there (see [assertions](#assertions)).
- **Where:** link states are rendering states - render them directly with Fusion when there are many of them (like the
  [teaser example above](#content-elements-every-nodetype-with-a-fusion-integration)). A target that is hidden later
  needs a real request (see [direct Fusion rendering](#direct-fusion-rendering---for-many-rendering-states)).
- **Test data:** link properties as values in the `Properties` column, references with
  `the following node references:`. To hide or remove a target later, use the
  [before/after pattern](#beforeafter-a-change).

### Lists, previews and tiles

These are nodes that aggregate data of *other* nodes from somewhere else in the tree: news and event lists, teaser
tiles, "related pages", and previews of a page on an overview page. They collect these nodes with
[FlowQueries](#querying-nodes). Neos leaves out hidden and removed nodes by itself - the typical bug is the opposite:
something that should be listed is missing.

- **What breaks:**
  - **tiles are missing,** because the FlowQuery starts at the wrong node, only looks at direct children, filters by
    the wrong NodeType or property, or misses an edge case (an event list that drops events without an end date);
  - **pagination doesn't work,** so visitors can't reach all available nodes: a page is skipped, the last page is cut
    off, or the "more" link is missing;
  - **the sort order is wrong or was never implemented** - usually it's most recent first (by date) or A to Z, and
    without explicit sorting the list simply follows the order in the tree;
  - **a new document doesn't show up** in the list, because the `@cache` configuration of the list doesn't cover the
    listed nodes;
  - the empty state is missing, and the fields of a preview (title, image, teaser text) are wrong or broken when the
    source page doesn't have them.
- **What to check:**
  - the visible items in the right order - titles and number of items;
  - with pagination: more nodes than fit on one page, and that every node can be reached by paging through;
  - the empty state, and the values a preview takes from its source;
  - that the list follows a new document or a changed title (see [caching](#caching)).
- **Where:** in the browser; many selection and preview states can also be rendered directly with Fusion (render the
  list with its page as context).
- **Test data:** several nodes with clearly different sort values (dates, titles) created in a different order than
  the expected one, more nodes than one page shows, and one of a type that must not appear. For lists that depend on
  today ("upcoming events"), see [dates](#dates-relative-to-today).

### Assets and media

The content of images - the picture, the alt text, the file name - comes from editors or an AI agent and is not a
matter of tests. What a developer can get wrong or forget is the wiring in the rendering.

- **What breaks:**
  - **the alt text isn't wired:** the `alt` attribute is missing, empty although the editor entered a text, or shows
    the text of another property. If your project uses a fallback helper (for example the file name when there's no
    alt text), the fallback isn't applied;
  - **`srcset` is missing** - it should be there for most images, otherwise every visitor loads the same large file -
    or `sizes` is missing, so the browser assumes the image is as wide as the viewport;
  - **an empty image property** produces an `<img>` without `src`, an empty frame or a rendering error instead of
    leaving the image out (or showing the placeholder your design defines);
  - download links point to the wrong file or answer with an error.
- **What to check:**
  - `alt` in each case your rendering handles: with the editor's text, without it (fallback or `alt=""` for decorative
    images);
  - that `srcset` has several candidates and `sizes` is set - modern browsers also accept `sizes="auto"` for lazily
    loaded images;
  - the component without an image;
  - that a download link answers with status 200 and the right file name.

  Use `in the fusion output, the attributes of CSS selector ... are:` or a check on the element's attributes in the
  browser.
- **Don't check which candidate the browser picks** from `srcset`. That depends on viewport size, pixel density, the
  browser's cache and the image itself - such a test is flaky and tests the browser, not your project.
- **Where:** in the browser; the attribute checks can also be done with direct Fusion rendering.
- **Test data:** `I have the following images:` or `I have a textual persistent resource ...`. The asset id in the step
  must match the id in the node property. One image with alt text and one without.

### Languages and other content dimensions

Neos resolves content dimensions and their fallbacks by itself: a page without its own variant in one language shows
the content of the fallback language as configured. That's Neos' job and needs no tests of your own. Test what your
project adds on top.

- **What breaks:**
  - **customized fallbacks:** your own rules how content falls back (for example per market, or only for some
    NodeTypes) show the wrong variant or none;
  - **business logic per dimension:** content, prices, legal texts or features that only exist in one country or
    language show up in the others, or are missing where they belong;
  - **the language switcher** your project renders links to the wrong page, or doesn't handle pages that have no
    variant in a language the way your design defines (leave the language out, link to its home page, ...).
- **What to check:** one scenario per rule of your project - the visible content in the dimension where the rule
  applies, and in one where it doesn't; for the language switcher, its links on a translated and on an untranslated
  page.
- **Where:** in the browser.
- **Test data:** set `DimensionSpacePoint` on every row, and create exactly the variants the rule depends on - a missing
  variant is often the case under test.

### SEO, GEO and meta tags

Title, meta description, canonical and hreflang tags, robots tags and the sitemap come from Neos or an SEO package
like Neos.Seo. Their default logic needs no tests of your own. Test only what your project adds on top - where it
wires its own content into SEO or deviates from the default logic. The same goes for GEO (generative engine
optimisation - being found and quoted by AI search): it's mostly built per NodeType, so it's yours to test.

- **What breaks:**
  - **content wired into SEO:** images of the page are reused as Open Graph or Twitter image, texts like the teaser
    text become the meta description or title - and the wrong property is used, or nothing when it's empty;
  - **texts that deviate from the default:** a title pattern, a suffix or a description rule of your project is not
    applied, or applied on the wrong pages;
  - **structured data your project builds** (JSON-LD per NodeType: an event as `Event`, an FAQ element as `FAQPage`,
    a product as `Product`) has wrong or missing values, or is invalid JSON. An `Event` without `startDate` is worse
    than none;
  - **content that only JavaScript shows** - tabs filled by fetch, client-side filters - doesn't exist for crawlers,
    as most AI crawlers don't run JavaScript;
  - **project rules for indexing:** NodeTypes or areas that must be `noindex`, left out of the sitemap, or - if your
    project builds them itself - handled in `robots.txt` rules for AI crawlers or an `llms.txt`.
- **What to check:**
  - the tag values for each addition of your project, with and without the content it's wired to (project steps like
    `the page should have the meta tag ...` and `the sitemap should (not) contain ...`);
  - the JSON-LD of each NodeType in its rendering states, especially with empty optional properties: valid JSON, the
    right type and the required fields;
  - for content crawlers must see: that it's in the HTML the server sends - check the response body, not the page
    after JavaScript ran (a project step, because Playwright checks the rendered page).
- **Where:** in the browser; many tag and JSON-LD states can also be rendered directly with `I render the page` or
  direct Fusion rendering.

### Forms

- **What breaks:** validation messages, required fields, the effects of the form finishers (mail, CRM, database), spam
  protection that blocks valid input - and forms in cached content: Flow checks a CSRF token for logged-in users, and a
  cached form carries the token of whoever filled the cache.
- **What to check:** the visible validation errors, the success message and what the finisher did: the mail (see
  [mails](#mails)), the call to the external system, or the database entry (a project step). For forms that logged-in
  users send: send the form on a page that was already cached by an earlier visit.
- **Where:** the form flow in the browser; the logic of the finishers with PHP unit or functional tests.
- **Pitfall:** time-based spam protection (a minimum time to fill in the form) rejects the browser's instant input.
  Lower the threshold in the test context and say so in a comment - don't add a wait to the scenario.

### Mails

Many journeys end with a mail: contact and claim forms, orders, registrations, password resets, double opt-in. A form
that says "Thank you" but sends nothing - or sends the mail twice - is broken.

- **What breaks:** no mail, two mails, the wrong recipient, missing values, broken links in the mail, a mail in the
  wrong language.
- **What to check:**
  - the number of mails - exactly one per recipient. A confirmation to the customer *and* a notification to the site
    owner are two mails with different recipients - check both;
  - recipient, subject and reply-to address - a contact form mail to the site owner should reply to the visitor;
  - the values the journey produced (the entered text, order number, date, totals), not the whole template;
  - attachments: how many and their file names (uploaded files, generated PDFs);
  - that no mail is sent when none must be sent (validation failed, honeypot filled in, opt-out chosen);
  - on multilingual sites, that the mail is in the language the form was sent in.
- **Where:** as a browser journey; links in the mail continue it (see the
  [journey ending in a mail](#journey-ending-in-a-mail)).
- **Setup:** see [mail catching](#mail-catching-mailpit). When another system sends the mail, check the call to that
  system instead.
- **Usually not tested here:** the layout of the mail and how mail programs display it.

### Caching

Caching is the most common reason why menus, lists and teasers show outdated content. This entry holds the rules;
menus and lists link here.

- **What breaks:** a content change that doesn't show up on pages that show this content elsewhere (teaser, menu,
  footer, lists), a wrong `@cache` configuration, and personal content that is cached for everybody.
- **What to check:** what the `@cache` configuration promises, using the [before/after pattern](#beforeafter-a-change):
  - `mode: cached` with `entryTags` (for example `Everything`, `NodeType_...`, `DescendantOf_...`, `Node_...`): when a
    node covered by the tags changes or is added, the next request shows it - with no cache flush in between;
  - `mode: uncached` or `dynamic` for parts that differ per visitor (basket, login state, consent): two visitors (or a
    logged-in and an anonymous one) each see their own content, not the content of whoever came first. Two visitors
    need two browser contexts - a project step;
  - `maximumLifetime`: content that refreshes after some time - usually better tested with unit tests than by waiting.
- **Where:** in the browser - direct Fusion rendering doesn't go through the site under test's cache.
- **Setup:** see [caches](#caches) - caching must be switched on in the site under test, and the change must reach its
  cache.

### JavaScript components

- **What breaks:** sliders, accordions, tabs, consent banners, lazy loading, filters, configurators and calculators:
  - a code branch nobody tried - a filter combination that leads to an empty or wrong result;
  - state that gets out of sync - the basket after removing the last item, or after going back in the browser;
  - an interaction path that behaves differently - the price calculator forgets the quantity when the size is changed
    afterwards;
  - the state without JavaScript or without consent.
- **What to check:** the behaviour after an interaction (`the element with test id ... should be visible` or
  `... hidden`, the resulting texts and values) - not that a script tag exists. Test the logic of complex components
  with JavaScript unit tests and the interaction in the browser (see [client-side logic](#client-side-logic)).
- **Where:** in the browser.
- **Cookie consent** gets its own scenarios, which start without a preset decision: the banner is shown, no tracker
  or third-party request happens before consent (a project step that watches the network requests), "accept all",
  "necessary only" and a custom choice each load the right embeds and scripts, the decision persists across pages,
  and the settings can be opened and changed again. All other scenarios preset the decision (see
  [keeping the suite fast](#make-each-scenario-cheaper)).

### Accessibility behaviour

- **What breaks:** the skip link, keyboard operation, the focus after an action (item removed, undo, dialog opened or
  closed, "back to top"), and overlays that trap the focus.
- **What to check:** which element has the focus or is in view after the action (`the element with test id ... should
  be focused`, `... should be in the viewport`, `I press the key :key`). Static checks like contrast and missing
  labels are better done by an accessibility scanner.
- **Where:** in the browser, with a fixed viewport size. Smooth scrolling and sticky headers change what is "in the
  viewport" - the shipped steps wait for the final state; project steps must do the same.

### Backend, editor experience and modules

- **What breaks:** a NodeType that editors can't create where they need it, missing fields in the inspector, custom
  backend modules, and permissions of roles.
- **What to check:** what an editor with a certain role can and can't do - log in with that role, not as administrator.
  For editors restricted to a subtree, see [visibility and access](#visibility-and-access). For custom modules (order
  lists, reports): the number of entries, filters and pagination - and for **exports**, download the CSV or Excel file
  and check its header and rows, not just that a file came back.
- **Where:** in the backend. It's slow, so cover the important editor tasks, not every NodeType.
- **Test data:** create the records with a domain step (`Given I have the following orders:`, or
  `Given I have 26 orders` to test the page boundary of a 25-item list).

## Scenario patterns

### Before/after: a change

Test data alone can't tell "renders correctly" apart from "shows an old, cached result". For caching, lists and
anything that shows data of other nodes, change something *after* the first visit:

1. Create the test data, visit the page and check the initial state.
2. Change or add something - a new node in the list, a changed title, a hidden node (with another node table, or with
   project steps like `I set the property ...`, `I hide the node ...`, `I remove the node ...`).
3. Visit the page again and check the new state: the new item appears in the right place, the old title is gone.

In browser tests, the change happens in the Behat process. It only reaches the site under test if both use the same
cache storage - a setup requirement, see [caches](#caches).

```gherkin
Scenario: a new news article shows up in the news list on the homepage
  Given I have the following nodes from file "homepage-with-news.yaml" in site "site"
  When I access the URI path "/"
  Then the news list should show "Spring fair, Summer camp"                   # project step
  When I create the following nodes in site "site":
    | NodeAggregateId | Parent | NodeType                          | Properties                                  | DimensionSpacePoint |
    | autumn-fest     | news   | Your.SitePackageKey:Document.News | {"title":"Autumn fest","date":"2026-10-01"} | {"language":"de"}   |
  And I access the URI path "/"
  Then the news list should show "Autumn fest, Spring fair, Summer camp"
```

### Visit twice

Some data is loaded in the background or saved during the first request ("voucher applied"). It must be right on the
very first visit, not only after a reload. So check the page on the first visit, then reload it and check again.

### Rules at their boundaries

A rule with a threshold ("free shipping from an order value of 50 €") needs one scenario on each side of each
threshold, with the numbers visible in the scenario - for example as a `Scenario Outline` or with
[mock data from a table](#external-apis). A single "happy" value doesn't prove the rule.

### One scenario per role

When what a user sees or may do depends on their role - members area, intranet, restricted editing, your own
controllers - every role is its own path. Write one scenario per role, plus one for anonymous visitors: what this role
sees, and that it gets the login or an access error for the rest.

### Dates relative to today

Test data with fixed dates breaks silently: the "upcoming" event of today's fixture is a past event in a few months.
When a rule compares with today, use dates far from it (`2099-06-01` for "upcoming", `2001-06-01` for "past"), or a
project step that computes them (`an event starting in 3 days`). Dates that only decide the order can stay fixed.

### Reaching a late start state

In processes with several steps, reach the starting point of a scenario with one combined step
(`Given I completed the checkout steps 1 to 3 as a business customer`). Otherwise every "step 4" scenario repeats the
50 steps of steps 1 to 3.

### Journey ending in a mail

Check the mail - and when it contains a link, follow it in the browser and check the result (subscription confirmed,
new password accepted). A link nobody follows proves nothing.

```gherkin
@mailpit
Scenario: newsletter sign-up is confirmed via the link in the mail
  When I access the URI path "/newsletter"
  And I fill "jane@example.com" into the field "E-mail"
  And I click the button "Subscribe"
  Then exactly 1 mail should have been sent to "jane@example.com"            # project step
  And the mail to "jane@example.com" should have the subject "Please confirm your subscription"   # project step
  When I follow the link "Confirm subscription" in the mail to "jane@example.com"                 # project step
  Then there should be the text "Thank you for subscribing" on the page
```

## Test data and environment

### Content fixtures

- **Create only what the scenario needs:** the nodes the check is about, plus the parent and tethered nodes they need.
- **Use readable ids** (`home`, `teaser-without-link`) in hand-written rows - the id shows up in every error message.
- **Use real content for page tests.** Export the page (with the button or with
  `./flow e2efixture:export --uri-path ...`) and keep the YAML file next to the feature. You get realistic
  combinations of NodeTypes and real texts, and the data matches the current NodeTypes.
- **Add edge cases as overwrites** (`... with overwrites:`) instead of a second copy of the YAML file - then the
  scenario shows what's different.
- **Share node trees as a YAML file** instead of copying the same table into several features - so there's only one
  place to update when a NodeType changes.
- **Respect constraints and tethered nodes.** Content inside a tethered collection gets `<owner>/main` as `Parent`, and
  its NodeType must be allowed there. If the test data fails because of a constraint, that's a finding, not noise.
- **Set the dimension on every row.** In a project with languages, a node created without `DimensionSpacePoint` is
  invisible.
- **Create assets with steps** and fixed ids - node properties refer to them by id.
- **Use domain steps for data that isn't content** - for example products and prices from another system. One step
  imports a named, dated snapshot (`Given I imported the shop "demo-2026-02"`). When the data needs to change, add a
  new snapshot instead of editing the old one, so older scenarios keep working.
- **Tag every scenario that creates nodes with `@flowEntities`** - the content repository is then reset before the
  scenario (all tags: [README](README.md#tags)).
- **Never run against the development database** - the tests have their own database
  ([README](README.md#2-two-flow-contexts-two-ports)).
- **Avoid** copies of the production database (personal data, huge, unreadable, outdated with the next NodeType
  change), inserting data with SQL (that bypasses the content repository) and one big fixture for the whole suite.

### External APIs

End-to-end tests never call the real external systems (shop backend, CRM, search service, newsletter service): their
answers change, rate limits hit, and real data gets written. Instead, the site under test talks to a mock of the
system, without any change to the application - only the API's URL in the test configuration points to the mock. This
package's `WireMockTrait` (see the [README](README.md#mocked-third-party-apis-wiremock)) works with
[WireMock](https://wiremock.org), which runs as a Docker container (`wiremock/wiremock`) locally and in CI. One
WireMock instance can stand in for several APIs under different path prefixes.

- **Each scenario starts with the base answers.** In features tagged `@wireMock`, WireMock is reset before every
  scenario and loads only the `_base` answers (auth token, standard login, and general answers for requests that almost
  every scenario triggers). A scenario adds the answers it's about, and they win over the base answers.
- **Keep answers in files, grouped by feature:**
  `Given the API "shop" path "/order" on "POST" serves response "Voucher/order_with_voucher"`.
- **Or keep one folder of answers per scenario** - all answers one scenario needs
  (`Given I load the stubs "login-locked-customer" of the API "shop"`). Then no scenario depends on the answers of
  another one; for a new scenario, copy a folder and adjust it.
- **Build the answer from a table when its values matter.** If the scenario is about values in the API answer, a
  table in the scenario is easier to read than a JSON file (a project step like
  `Given the basket API returns the following items:` with `| article | quantity | price |`) - the numbers behind the
  rule are visible right there.
- **Change answers explicitly.** When the API answers differently after an action, use `I clear all API stubs` and set
  up the new answers - then the scenario shows the expected interaction.
- **Mock the error answers too** - an error status, an empty or unexpected answer. Most crashes in production come
  from answers nobody tried.
- **Check the outgoing call when it is the feature** (for example: the order was sent with the voucher code):
  `Then the API "shop" should have received "POST" "/order"`. What the page shows doesn't prove that the call happened.
- **While writing a test, let unknown requests through** to the real API (`proxyBaseUrl`) to see which calls happen.
  Never do this in CI.

### Mail catching (Mailpit)

- **Catch all mails with [Mailpit](https://mailpit.axllent.org)** (or a similar tool) as the mail server of the site
  under test. No mail leaves the test environment, and steps can read the inbox through Mailpit's HTTP API.
- **Empty the inbox before each mail scenario** (with a `@BeforeScenario @mailpit` hook). Then "exactly one mail" means
  "this scenario sent one".
- **Wait for the mail instead of sleeping.** Check the inbox repeatedly until the expected mail is there, and when time
  runs out, fail with a list of the mails that *did* arrive. If mails are sent from a queue, process the queue in the
  site under test instead (`executeFlowCommand()`).
- **Mails sent by another system never reach Mailpit.** Check the call to the mocked system instead (recipient,
  template, data).

### Caches

- **The site under test only sees cache flushes that reach its cache.** Test data steps run in the Behat process, so
  both need to use the same cache storage - or you flush the cache in the site under test
  ([README troubleshooting](README.md#troubleshooting), item 4).
- **Caching must be switched on in the site under test.** A cache test that passes because caching is switched off
  proves nothing.
- **Caches per session or user** (user details, basket) leak from one scenario into the next when the login mock gives
  out the same session id every time. Flush them in the site under test before each scenario, and write down why
  next to the flush.

## Keeping the suite fast

A slow suite gets run less often, and then it stops catching bugs early. Most time is lost per scenario, not in the
number of scenarios.

### Choose the cheapest test that catches the bug

- **Unit and functional tests first.** When logic can be tested without a browser - a PHP service, a calculation in
  JavaScript - test its code branches there. E2E only proves that the parts work together: one scenario for the normal
  case and one for an error case per seam (see [where the behaviour comes from](#where-does-the-behaviour-come-from)).
- **Direct Fusion rendering for rendering states.** It checks the HTML - texts, attributes, elements - in
  milliseconds, without a request or a browser. A pure component (`I render the Fusion object` without a node) doesn't
  even need the content repository, so its scenarios need no `@flowEntities`. One browser scenario per feature still
  proves that it works in a real page.
- **Leave to tools what tools do better.** Don't write tests that repeat what static analysis already checks - it's
  much faster and runs before the suite:
  - PHPStan for types and wrong calls, the TypeScript compiler and linters for the frontend code;
  - a project linter for your own Neos conventions (naming, structure, missing `@cache` configuration);
  - an accessibility scanner (axe, pa11y) for contrast, missing labels and alt texts, landmarks. A scanner finds only
    part of the problems - behaviour like focus and keyboard operation stays in
    [E2E tests](#accessibility-behaviour);
  - `./flow configuration:validate` for settings and NodeType configuration.

  Run them before or in parallel with the E2E suite, so a typo fails in a minute, not after twenty.

### Make each scenario cheaper

- **Several checks on one state are fine.** One page with three buttons - internal link, external link, no link -
  visited once and checked three times is cheaper than three scenarios, and nothing can hide a later check: no action
  happens between the checks, and each failure names its button. What must not be combined is a sequence of actions
  for different behaviours - that's a journey, and its first failure hides the rest. (For many rendering states,
  direct Fusion rendering with a `Scenario Outline` is cheaper still.)
- **Start late.** Set up as test data what isn't the behaviour under test (see
  [reaching a late start state](#reaching-a-late-start-state)).
- **Skip the login form** when the login isn't what you test - with a project step that sets up the session
  directly, or Playwright's `storageState` from one recorded login. Logging in through the form in
  every scenario adds several seconds each time.
- **Preset the cookie consent** instead of clicking the banner away in every scenario: set the cookie or storage entry
  of your consent tool before the first visit (`Given the cookie :name has the value :value` or
  `Given the :storage storage key :key has the value :value`), in a `@BeforeScenario` hook for the default decision.
  A tag can select another decision (`@consentNecessaryOnly`). The real consent code still runs, so embeds behave as
  in production. Keep the cookie's name and format in one place - it changes with the consent tool. The banner itself
  gets its own scenarios (see [JavaScript components](#javascript-components)).
- **Avoid expensive work in hooks.** Every `./flow` command a hook starts boots Flow again - per scenario. Flush caches
  in the Behat process when both processes share the cache storage, and warm up caches once per run, not per
  scenario. Import content through the content repository, not by restoring SQL dumps.

### Measure, then run in parallel

- **Measure first.** The JUnit report shows the duration of every scenario - start with the slowest features.
- **Then run in parallel:** split the feature files over several CI jobs, each with its own services, balanced by the
  durations from the last report (see the [README](README.md#7-ci-pipeline-optional)). Running in parallel without
  making scenarios cheaper first only multiplies the waste.
- **A smoke subset only when needed.** A second, faster subset (`@smoke` for merge requests, everything on the main
  branch) adds a tag that drifts out of date. Introduce it only when the parallel suite still takes too long.

## Writing feature files

General Gherkin practice applies - Given for the starting state, When for the action, Then for the result, a short
description per feature, scenarios named after the behaviour (see Cucumber's
[Writing better Gherkin](https://cucumber.io/docs/bdd/better-gherkin/)). The rules below matter most in Neos projects.

### Wording

- **Use one language** for all steps (and ideally the scenario names too). With mixed languages, there are twice as
  many steps and nobody finds the one that already exists.
- **One step per action, one way to refer to a thing.** Two steps that do the same (`I access the URI path` and
  `I navigate to path`) split the vocabulary. Refer to a product always by id *or* always by name.
- **Write generic steps in the visitor's words.** `I fill "..." into the field "Password"` and
  `I click the button "Log in"` read like the page and check its accessibility on the way. Technical identifiers like
  `the field with the id "passwordCurrent"` belong into step implementations.
- **No waiting in step names** (`I sign in ... and wait for "/my-profile"`). The step itself waits until the page is
  ready for the next step.
- **Comment what isn't obvious:** the business rule behind the scenarios, why the test data looks the way it does,
  what you deliberately don't check, and the issue a regression scenario guards against.
- **No cleanup steps at the end of a scenario.** They don't run when an earlier step fails - clean up in
  `@AfterScenario` hooks. Don't keep commented-out scenarios: delete them, or tag them `@skip` with the reason.

### Assertions

- **Check what carries meaning, not the whole HTML.** `the Fusion output should equal to` breaks with every changed
  space or class. Check texts, `href` and `alt` values, the number of items - and when a feature is about what an
  element shows, check that, not just that the element exists.
- **Use stable selectors:** roles, labels, ARIA attributes, semantic elements or `data-testid`. Utility or design
  system classes like `.mt-4.text-blue-600` change with the styling.
- **Pair every "is not there" with an "is there".** "The teaser without link target shows no link" also passes on an
  empty page, so check the teaser's title in the same scenario.
- **Only check what is stable.** When a value depends on something the test doesn't control, check the stable part
  ("a delivery date is shown") and explain in a comment why the exact value isn't checked.
- **Screenshots and the style guide document the result, they don't check it.** Use them for reviews and visual
  regression tools, not as the only check of a test.
- **Note:** `... the inner HTML of CSS selector ... matches ...` compares for equality, not as a regular expression.

## Writing step implementations

- **Look for a shipped step first** (steps overview in the [README](README.md#steps)). Steps for your project's own
  concepts go into your `FeatureContext` or a trait of your project.
- **Domain language in the feature, selectors in the step.** `Then the menu ... should contain` in the feature; "click
  `.nav-toggle`, read the `li` elements" in the step. When the markup changes, you fix one step instead of many
  scenarios.
- **Change content through the content repository API** - with commands like `SetNodeProperties`, `TagSubtree` or
  `RemoveNodeAggregate`, and read it with subgraph queries. Never use SQL: the projections and caches depend on the
  events.
- **Know where your code runs.** Steps run in the Behat process (Flow context `Testing/Behat`); the browser visits the
  site under test. Whatever the site under test needs to see must be in the database or a shared cache - or the step
  runs a command in the site under test with `executeFlowCommand()`.
- **Find elements the way a visitor does:** by role, label, text or test id. Find form fields by their label
  (`getByLabel`) - then a field without a label fails the test. Let Playwright wait for elements, return values to PHP
  and check them there with PHPUnit's `Assert`.
- **Pass step parameters safely into scripts.** Step texts contain quotes and backslashes; pass them into Playwright
  scripts as JSON values (`JsValue::of()`), never by pasting the raw text into the script.
- **Wait for a state, not for a fixed time:** for an element, a URL, or the response the next step depends on
  (`page.waitForResponse()`) - never `sleep` or `waitForTimeout`. If the wait times out, the step must fail with the
  actual state ("the menu contains [Home, About], expected Team"). A wait that only logs the timeout moves the error to
  a later step, where it's confusing.
- **Don't retry silently.** A step that retries until it passes hides features that only work sometimes.
- **Reset what a step changes** - static state, settings - in an `@AfterScenario` hook.

## Review checklist

Before you commit a feature - whether you're a person or an agent:

- [ ] The feature's risk justifies the tests' cost ([prioritising](#prioritising)); nothing a linter or scanner
      already checks.
- [ ] Each scenario tests one behaviour of the project, not of Neos, and its name says which.
- [ ] One browser scenario per feature; further rendering states with direct Fusion rendering, logic in unit tests;
      the backend only for the editor experience ([where does the test run](#where-does-the-test-run)).
- [ ] Journeys only where the interaction is the feature: one scenario per interaction path, starting as late as
      possible.
- [ ] The rendering states, the interaction paths, the roles, the edges of the rules and the fixed bug are covered.
- [ ] Test data is minimal and realistic: readable ids, a dimension on every row, `@flowEntities`, no copied tables, no
      database copies, no dates that expire.
- [ ] External systems are mocked (`@wireMock`), including error answers; mails are caught (`@mailpit`).
- [ ] The checks are about meaning and use stable selectors; every "is not there" has an "is there".
- [ ] No fixed waits, no silent retries, no waiting in step names, no cleanup steps.
- [ ] Rules that aren't obvious, choices in the test data and checks left out on purpose are commented.
