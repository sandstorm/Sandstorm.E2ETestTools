# Proposed steps (TODO - not implemented)

Steps the [Neos E2E Testing Guide](NEOS_E2E_TESTING_GUIDE.md) uses that this package doesn't ship yet. They're
candidates for common steps: generic enough for most Neos projects, adaptable per project. Until one is shipped,
implement it in your project's `FeatureContext`.

"Where" says how the step gets its data: **Fusion output** (last `I render ...` result, via Symfony DomCrawler like the
existing `in the fusion output ...` steps), **browser** (Playwright via `$this->playwrightConnector->execute()`),
**CR** (content repository commands/subgraph in the Behat process).

## Page and Fusion output assertions

| Step | Purpose | Where | Notes |
|---|---|---|---|
| `there should not be the text :text on the page` | visibility: hidden content is absent | browser | assert after the page loaded (pair with a positive assertion), not with a timeout race |
| `there should not be the text :text in :selector` | absence inside one region (menu, footer) | browser | |
| `the element :selector should be visible` / `... should be hidden` | JS components, consent, accordions | browser | Playwright `toBeVisible()` / `toBeHidden()` |
| `the element :selector should not exist in the fusion output` | content variants on integration level (no link without target) | Fusion output | counterpart for the browser: `the element :selector should not exist` |
| `the element :selector should exist :count times in the fusion output` | lists, teasers, menu items | Fusion output | |
| `the page should have the meta tag :name with content :content` | SEO: description, robots, og:* | browser or `I render the page` output | match `name` and `property` attributes |
| `the canonical URL should be :uriPath` / `the hreflang links should be:` | routing/SEO | browser | table: `hreflang` · `href` |

## Navigation and reachability

| Step | Purpose | Where | Notes |
|---|---|---|---|
| `the menu :selector should contain:` | menu structure: labels, order, level, current item | browser | table `label · level · current`; level from list nesting, current from `aria-current` (recommend that attribute in the menu markup) |
| `all links in :selector should respond with 200` | reachability of menus/footers | browser + HTTP | collect `href`s in the browser, request each against the SUT (same origin only), report all failing URLs at once |
| `the sitemap should contain :uriPath` / `... should not contain ...` | hidden pages not in `sitemap.xml` | HTTP | fetch `/sitemap.xml` from the SUT, compare paths |

## Changing content mid-scenario

| Step | Purpose | Where | Notes |
|---|---|---|---|
| `I set the property :property of node :nodeAggregateId to :value` | cache invalidation (visit, change, visit) | CR | `SetNodeProperties`; value JSON-decoded like overwrite values; dimension parameter variant |
| `I hide the node :nodeAggregateId` / `I show the node ...` | visibility, broken references | CR | `TagSubtree` / `UntagSubtree` with `NeosSubtreeTag::disabled()` (same as the `Hidden` column) |
| `I remove the node :nodeAggregateId` | references to removed nodes, error resilience | CR | `RemoveNodeAggregate` |
| `creating the following nodes in site :siteName should fail:` | NodeType constraints | CR | same table as `I have the following nodes in site ...`; expects an exception (optionally a message part) |

All of them change content in the Behat process: the SUT only sees cache flushes if both contexts share the cache
backend (README Troubleshooting item 4).

## Dimensions and forms

| Step | Purpose | Where | Notes |
|---|---|---|---|
| `I switch to the dimension :dimensionSpacePoint` | default dimension for following steps (`I get the node`, node tables without the column) | CR | removes the repeated `{"language":"de"}` |
| `a mail with subject :subject should have been sent to :address` | form finishers | HTTP (Mailpit API) | Mailpit URL from an env variable; clear the mailbox in a `@BeforeScenario` hook |
