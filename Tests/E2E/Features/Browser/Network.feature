# NetworkTrait: requests to other hosts, links.
# The page is a Fusion fixture of the test site (TestMarkup.Elements) - it loads an image from example.invalid.
@playwright @flowEntities
Feature: Network

  Background:
    Given I have a site for the site node "site"
    And I have the following nodes in site "site":
      | NodeAggregateId | Parent        | NodeType                                           | Properties                                   | DimensionSpacePoint |
      | homepage        |               | Sandstorm.E2ETestTools.TestSite:Document.StartPage | {"uriPathSegment":"site","title":"Elements"} | {"language":"de"}   |
      | section         | homepage/main | Sandstorm.E2ETestTools.TestSite:Content.Section    | {}                                           | {"language":"de"}   |
      | elements        | section       | Sandstorm.E2ETestTools.TestSite:Content.TestMarkup | {"markup":"Elements"}                        | {"language":"de"}   |

  Scenario: requests to other hosts are recorded
    Given requests to other hosts are recorded and blocked
    When I access the URI path "/"
    Then there should be a request to "example.invalid"
    And there should not be a request to "example.com"
    When I forget the recorded requests
    And I reload the page
    Then there should be a request to "example.invalid"

  Scenario: links
    When I access the URI path "/"
    Then all links in ".links" should respond with 200
