# Neos E2E Testing Guide

What to test in a Neos 9 project, where to test it, and the pitfalls specific to Neos, Flow and this package. It
assumes you know Behat, Gherkin and Playwright, and that the package is set up (see the [README](README.md)).

The guide's stance: **test your project, not Neos.** Hidden nodes, dimension fallbacks, URL generation and the
default SEO tags work without your tests - test what your project adds: NodeTypes, Fusion, FlowQueries, PHP,
JavaScript and integrations. Prove each feature once in the browser, cover its further rendering states with direct
Fusion rendering, and start from real content exported from the site.

**Terms:** the *site under test* is the copy of your site the browser visits (its own Flow context, database and
port). The *Behat process* runs the steps - it creates the test data and talks to the browser. *Rendering states* are
the situations in which a feature looks or behaves differently: with or without image, empty or very long text,
logged in or not, another language. Steps marked "project step" don't come with this package.

<!-- TOC -->
- [Where to test](#where-to-test)
- [Feature catalogue](#feature-catalogue)
- [Test data and environment](#test-data-and-environment)
- [Writing step implementations](#writing-step-implementations)
- [Keeping the suite fast](#keeping-the-suite-fast)
- [Prioritising](#prioritising)
<!-- /TOC -->

## Where to test

Code branches of PHP and JavaScript logic belong in unit and functional tests; E2E proves the wiring (see
[keeping the suite fast](#choose-the-cheapest-test-that-catches-the-bug)). For the E2E part there are three places:

### In the browser - one scenario per feature

`I access the URI path ...` sends a real request through routing, caches and the visibility and access rules, and
the page runs its JavaScript - exactly what a visitor gets. A browser scenario is what proves that a feature works.
When the interaction is the feature (basket, checkout, login, forms with several steps), the scenario becomes a
journey that checks everything on its way (see [journeys](#one-journey-checks-everything-on-its-way)). Everything that
isn't the behaviour under test is set up as test data rather than clicked together.

### Direct Fusion rendering - for many rendering states

`I render the Fusion object ...` renders a component - or, after `I get the node ...`, a NodeType's integration with a
real node - directly to HTML, inside the Behat process: no request, no browser, milliseconds per state. When it
fails, the cause is in Fusion or in how the NodeType is wired. A pure component without a node doesn't need the
content repository at all. Direct rendering can't see routing, caches, JavaScript, or the visibility and access rules
of the website - `I get the node` only leaves out removed nodes, so hidden nodes are rendered.

### In the backend - for the editor experience

`I log into the backend ...` and the navigation steps drive the Neos user interface. Slowest and most fragile, so
only for what editors see and do: "an editor can add a teaser to the main column", "an editor without the right role
can't open the order module".

## Feature catalogue

Features that nearly every Neos project has: what typically breaks, what to check, where to test it and what test data
you need. To find the features of a project, look at its NodeTypes (document and content types, mixins like
`hiddenInMenu`), Fusion prototypes and their `@cache` configuration, FlowQueries, settings (dimensions, routing,
sites), PHP code (controllers, plugins, finishers, route part handlers, API clients), JavaScript components, and the
roles in `Policy.yaml` - the rendering states of a feature hide in NodeType properties and Fusion `@if` conditions.

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
  FlowQueries, so the [lists](#lists-previews-and-tiles) pitfalls apply too.
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

Caching needs its own scenario: change something after the first visit, with no cache flush in between - whether the
menu updates by itself is exactly what's tested (see [caching](#caching)).

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
- **What to check** - one scenario per role, plus one for anonymous visitors:
  - for content for certain users: a user with the role sees the page, its menu entry, teasers and search results; a
    user without it and an anonymous visitor see none of these, and opening the page's URL directly leads to the login
    or an access error;
  - for restricted editing: the editor sees and can edit their subtree, and can neither see nor edit the rest -
    neither in the document tree nor by opening a page directly;
  - absence of hidden content only where your project bypasses Neos' visibility rules - and always together with
    something visible, otherwise the check also passes on an empty page.
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
    the login or an access error for everyone else - one scenario per role. Invalid input leads to an error page or
    message (400, 404), not to a crash (500);
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
  - **every link state the component handles differently**: internal, external, asset, empty - and, where it
    matters, a target that is hidden or removed later. A `Scenario Outline` with direct Fusion rendering, or several
    buttons on one page checked in one visit;
  - the visible result of each state: the link and its `href` (`in the fusion output, the attributes of CSS selector
    ... are:`), the disabled button, the icon of the external link, or that there is no link at all - always together
    with the rest of the component that must still be there.
- **Where:** link states are rendering states - render them directly with Fusion when there are many of them (like the
  [teaser example above](#content-elements-every-nodetype-with-a-fusion-integration)). A target that is hidden later
  needs a real request (see [direct Fusion rendering](#direct-fusion-rendering---for-many-rendering-states)).
- **Test data:** link properties as values in the `Properties` column, references with
  `the following node references:`. To hide or remove a target later, change it after the first visit (see
  [caching](#caching)).

### Lists, previews and tiles

These are nodes that aggregate data of *other* nodes from somewhere else in the tree: news and event lists, teaser
tiles, "related pages", and previews of a page on an overview page. They collect these nodes with
FlowQueries. Neos leaves out hidden and removed nodes by itself - the typical bug is the opposite:
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
  today ("upcoming events"), use dates far from it (`2099-06-01`, `2001-06-01`) or compute them in a project step - a
  fixed "upcoming" date is a past one a few months later.

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

- **What breaks:** validation messages, required fields, the effects of the form finishers (mail, CRM, database), and
  spam protection that blocks valid input.
- **What to check:** the visible validation errors, the success message and what the finisher did: the mail (see
  [mails](#mails)), the call to the external system, or the database entry (a project step).
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
- **Where:** as a browser journey. When the mail contains a link, follow it and check the result (subscription
  confirmed, new password accepted) - a link nobody follows proves nothing.
- **Setup:** see [mail catching](#mail-catching-mailpit). When another system sends the mail, check the call to that
  system instead.
- **Usually not tested here:** the layout of the mail and how mail programs display it.

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

### Caching

Caching is the most common reason why menus, lists and teasers show outdated content. Test data alone can't tell
"renders correctly" apart from "shows an old, cached result" - so visit the page, change or add something (another
node table, or project steps like `I set the property ...`, `I hide the node ...`), and visit again. The change
happens in the Behat process; it only reaches the site under test if both share the cache storage (see
[caches](#caches)).

- **What breaks:** a content change that doesn't show up on pages that show this content elsewhere (teaser, menu,
  footer, lists), a wrong `@cache` configuration, and personal content that is cached for everybody.
- **What to check:** what the `@cache` configuration promises:
  - `mode: cached` with `entryTags` (for example `Everything`, `NodeType_...`, `DescendantOf_...`, `Node_...`): when a
    node covered by the tags changes or is added, the next request shows it - with no cache flush in between;
  - `mode: uncached` or `dynamic` for parts that differ per visitor (basket, login state, consent): two visitors (or a
    logged-in and an anonymous one) each see their own content, not the content of whoever came first. Two visitors
    need two browser contexts - a project step;
  - `maximumLifetime`: content that refreshes after some time - usually better tested with unit tests than by waiting.
- **Where:** in the browser - direct Fusion rendering doesn't go through the site under test's cache.

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

### JavaScript components

- **What breaks:** sliders, accordions, tabs, consent banners, lazy loading, filters, configurators and calculators:
  - a code branch nobody tried - a filter combination that leads to an empty or wrong result;
  - state that gets out of sync - the basket after removing the last item, or after going back in the browser;
  - an interaction path that behaves differently - the price calculator forgets the quantity when the size is changed
    afterwards;
  - the state without JavaScript or without consent.
- **What to check:** the behaviour after an interaction (`the element with test id ... should be visible` or
  `... hidden`, the resulting texts and values) - not that a script tag exists. Test the logic of complex components
  with JavaScript unit tests - they cover every code branch - and only the interaction paths in the browser.
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

## Test data and environment

### Content fixtures

- **Export real content for page tests** - with the button or `./flow e2efixture:export --uri-path ...` - and keep
  the YAML file next to the feature. You get realistic NodeType combinations and real texts, and the data matches the
  current NodeTypes. Add edge cases as overwrites (`... with overwrites:`) instead of a second copy, and share node
  trees as YAML files instead of copying tables between features.
- **Anonymise production data before it goes into git.** Exported content and domain snapshots end up in the
  repository - and with them every name, e-mail address, phone number and customer record they contain (contact
  persons, intranet pages, orders, user accounts). Review exported YAML before committing, and replace personal data
  with made-up values (`jane.doe@example.com`, reserved domains and phone ranges). Snapshots of other systems get the
  same treatment - ideally by a script, so a new snapshot is anonymised the same way.
- **Tethered nodes and constraints:** content inside a tethered collection gets `<owner>/main` as `Parent`, and its
  NodeType must be allowed there. Test data that fails on a constraint is a finding, not noise.
- **Set `DimensionSpacePoint` on every row.** In a project with dimensions, creating a node without it fails.
- **Create assets with steps** and fixed ids - node properties refer to them by id.
- **Data that isn't content** (products, prices from another system): one domain step imports a named, dated snapshot
  (`Given I imported the shop "demo-2026-02"`). When the data changes, add a new snapshot instead of editing the old
  one.
- **Tag scenarios that create nodes with `@flowEntities`** - the content repository is reset before the scenario
  (all tags: [README](README.md#tags)). No SQL inserts and no production dumps: they bypass the content repository's
  events and go stale with the next NodeType change.

### External APIs

The site under test talks to a mock instead of the real system - only the API's URL in the test configuration
changes. The package's `WireMockTrait` (see the [README](README.md#mocked-third-party-apis-wiremock)) drives
[WireMock](https://wiremock.org); one instance can stand in for several APIs under different path prefixes.

- **Base answers per scenario:** in features tagged `@wireMock`, WireMock is reset before every scenario and loads the
  `_base` answers (auth token, standard login). The scenario adds the answers it's about; they win over the base.
- **Answers in files**, grouped by feature (`Given the API "shop" path "/order" on "POST" serves response
  "Voucher/order_with_voucher"`), or one folder per scenario (`Given I load the stubs "login-locked-customer" of the
  API "shop"`). When the values are the point of the scenario, build the answer from a table in a project step.
- **Mock the error answers too** - an error status, an empty or unexpected answer.
- **Check the outgoing call when it is the feature:** `Then the API "shop" should have received "POST" "/order"` -
  what the page shows doesn't prove that the call happened.
- **While writing a test, let unknown requests through** to the real API (`proxyBaseUrl`) to see which calls happen.
  Never in CI.

### Mail catching (Mailpit)

- [Mailpit](https://mailpit.axllent.org) (or similar) is the mail server of the site under test; project steps read the
  inbox through its HTTP API. Empty it in a `@BeforeScenario` hook for a project tag like `@mailpit`, so "exactly one
  mail" means "this scenario sent one".
- Poll the inbox until the mail is there, and on timeout list the mails that *did* arrive. Mails sent from a queue: let
  the queue run synchronously in the site under test's context (queue configuration), so the mail is sent during the
  request.
- Mails sent by another system never reach Mailpit - check the call to the mocked system instead.

### Caches

- **Give Behat and the site under test the same cache storage.** The Behat process can't switch to the site under
  test's Flow context - its `CacheManager` builds every cache from `Testing/Behat`'s `Caches.yaml`. So configure the
  caches the site under test reads with the same storage there: the same Redis database in both contexts (preferred -
  then tag flushes from test data steps reach the site under test too), or, for a file backend,
  `backendOptions.cacheDirectory` in `Testing/Behat` pointing to the site under test's cache directory
  (`%FLOW_PATH_DATA%Temporary/<Context>/SubContext<SubContext>/Cache/Data/<CacheIdentifier>/`).
- **Flush per scenario in PHP, not with `./flow`.** Which caches need it is project-specific - a full page cache,
  caches per session or user (user details, basket) that leak between scenarios when the login mock hands out the same
  session id every time. Flush them in a hook of your `FeatureContext`, with a comment why:

  ```php
  #[BeforeScenario('@flowEntities')]
  public function flushCachesOfTheSiteUnderTest(): void
  {
      // shared Redis backend in Testing/Behat and the site under test's context (Caches.yaml)
      $this->getObject(CacheManager::class)->getCache('Flowpack_FullPageCache_Entries')->flush();
  }
  ```

  A `./flow` command from a hook or step boots Flow again for every scenario.
- **Caching must be switched on in the site under test**, or every cache test passes for the wrong reason.

## Writing step implementations

- **Look for a shipped step first** (steps overview in the [README](README.md#steps)).
- **Change content through the content repository** - commands like `SetNodeProperties`, `TagSubtree`,
  `RemoveNodeAggregate`; read it with subgraph queries. Never SQL: projections and caches depend on the events.
- **Know where your code runs.** Steps run in the Behat process (Flow context `Testing/Behat`); the browser visits the
  site under test. What the site under test needs to see must be in the database or a cache both share (see
  [caches](#caches)).
- **Debugging:** visible browser, pausing, server logs of failed scenarios and reports for agents - see the
  [README](README.md#debugging).
- **Find elements the way a visitor does:** by role, label, text or test id. Fields by their label (`getByLabel`) -
  then a field without a label fails the test. Let Playwright wait, return values to PHP and assert there with
  PHPUnit's `Assert`.
- **Pass step parameters into scripts with `JsValue::of()`**, never by pasting the raw text - step texts contain quotes
  and backslashes.
- **Wait for what the user sees, not for technical events.** The page or its title changes, the total updates, a
  spinner disappears, an `is-loading` class or `aria-busy` goes away (`waitFor`, `waitForURL`, `waitForFunction`) -
  and fail with the actual state when the wait times out. Avoid `page.waitForResponse()`: when its promise rejects
  after the step has already failed or returned, it's an unhandled rejection in the bridge, the bridge process exits,
  and every following scenario fails. If there is nothing visible to wait for, the user gets no feedback either - add
  a loading state to the page.
- **Know the steps' quirks** - exact matching, escaping in step parameters: see the notes below the steps table in
  the [README](README.md#steps).

## Keeping the suite fast

Most time is lost per scenario, not in the number of scenarios.

### Choose the cheapest test that catches the bug

- **Code branches in unit and functional tests** - PHP services, controllers, finishers, route part handlers, API
  clients, and JavaScript logic. E2E only proves the wiring: the normal case and one error case per seam (the plugin
  is on the page, the finisher is called, the error message shows).
- **Rendering states with direct Fusion rendering;** pure components without a node need no `@flowEntities`.
- **Leave to tools what tools do better:** PHPStan, the TypeScript compiler and linters, a project linter for your
  Neos conventions (naming, structure, missing `@cache`), an accessibility scanner (axe, pa11y) for contrast, labels
  and alt texts, and `./flow configuration:validate`. Run them before or in parallel with the suite. Scanners find
  only part of the accessibility problems - focus and keyboard behaviour stay in
  [E2E tests](#accessibility-behaviour).

### One journey checks everything on its way

"One behaviour per scenario" doesn't survive a real project: the scenarios add up and the suite runs forever. Be
pragmatic - a journey checks everything it passes. In a multi-step registration, validate every field of every step
inside the journey (enter an invalid value, check the message, correct it, continue), and check the mail at the end:
one setup and one walk through the form instead of twenty.

- **Separate scenarios only for different end results** - guest or account, invoice or direct debit, another mail.
  One scenario can reach only one end state.
- **The price is that the first failure stops the journey.** Keep the checks in the order of the form, let the step
  error messages name the field, and name the scenario after what it covers.

```gherkin
@mailpit
Scenario: registration validates every step and sends the welcome mail
  When I access the URI path "/register"
  And I fill "12" into the field "Postal code"
  And I click the button "Next"
  Then there should be the text "Please enter a valid postal code" on the page
  When I fill "12345" into the field "Postal code"
  And I click the button "Next"
  # ... the same for every field of the following steps
  Then exactly 1 mail should have been sent to "jane.doe@example.com"         # project step
```

### Make each scenario cheaper

- **Several checks on one state are fine.** One page with three buttons - internal, external, no link - visited once
  and checked three times beats three scenarios: no action happens between the checks, so none can hide another.
- **Skip the login form** when the login isn't what you test - a project step that sets up the session, or
  Playwright's `storageState` from one recorded login.
- **Preset the cookie consent** instead of clicking the banner away: `Given the cookie :name has the value :value` or
  `Given the :storage storage key :key has the value :value` before the first visit, in a `@BeforeScenario` hook for
  the default decision, a tag for others (`@consentNecessaryOnly`). The real consent code still runs. Keep the
  cookie's name and format in one place - it changes with the consent tool. The banner gets its own scenarios (see
  [JavaScript components](#javascript-components)).
- **No `./flow` commands in hooks or steps** - each one boots Flow again. Flush caches in PHP (see [caches](#caches)),
  warm up caches once per run, import content through the content repository instead of restoring SQL dumps.
- **Run in parallel** once the scenarios are cheap: shards with their own services, balanced by the durations from the
  last JUnit report (see the [README](README.md#7-ci-pipeline-optional)).

## Prioritising

- **Must not break** - revenue (checkout, leads), legal requirements, access restrictions: journeys including their
  error paths, covered most deeply. Accessibility is a legal obligation in many cases (European Accessibility
  Act for e-commerce and services, public sector sites), and so is consent (no tracking before the visitor agreed).
- **Paths nobody tests by hand** come right after: validation and error paths, branches in forms, the different
  mails - and whether a mail is sent at all. Manual testing covers the happy path; these break unnoticed.
- **Content features editors use a lot:** their rendering states, mostly with direct Fusion rendering, plus one
  browser scenario each.
- **Cosmetic or rarely changed:** a visual review, no assertions.

Backend tests and long journeys cost the most to keep green - spend them on the first group. Which tests run on every
merge request and which only on the main branch or nightly is a speed decision per project.
