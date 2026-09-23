# Visits a page of the system under test in a real browser (Playwright bridge).
# The screenshot lands in the results directory (see setupPlaywright() / "Debugging" in the README).
@playwright @flowEntities
Feature: Homepage renders

  Background:
    Given I have a site for Site Node "site" with name "YourSiteName"
    And I have the following nodes in site "site":
      | NodeAggregateId | Parent        | NodeType                               | Properties                                   | DimensionSpacePoint |
      | homepage        |               | Your.SitePackageKey:Document.StartPage | {"uriPathSegment":"site","title":"Homepage"} | {"language":"de"}   |
      | section         | homepage/main | Your.SitePackageKey:Content.Section    | {}                                           | {"language":"de"}   |
      | headline        | section       | Your.SitePackageKey:Content.Headline   | {"title":"<h1>It works<\/h1>"}               | {"language":"de"}   |

  Scenario: visiting the homepage shows the headline
    When I access the URI path "/"
    Then the response status code should be "200"
    And there should be the text "It works" on the page
    And I do a screenshot "Homepage.png"
