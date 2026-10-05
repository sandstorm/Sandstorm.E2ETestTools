# Renders a whole page via Fusion (no HTTP request, no browser) - fast checks of pages built from fixture nodes.
@flowEntities
Feature: Homepage via Fusion

  Background:
    Given I have a site for Site Node "site" with name "YourSiteName"
    And I have the following nodes in site "site":
      | NodeAggregateId | Parent        | NodeType                               | Properties                                   | DimensionSpacePoint |
      | homepage        |               | Your.SitePackageKey:Document.StartPage | {"uriPathSegment":"site","title":"Homepage"} | {"language":"de"}   |
      | section         | homepage/main | Your.SitePackageKey:Content.Section    | {}                                           | {"language":"de"}   |
      | headline        | section       | Your.SitePackageKey:Content.Headline   | {"title":"<h1>It works<\/h1>"}               | {"language":"de"}   |

  Scenario: homepage renders its headline
    Given I get the node "homepage" in dimension '{"language":"de"}'
    When I render the page
    Then in the fusion output, the inner HTML of CSS selector "h1" matches "It works"
