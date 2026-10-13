# NavigationTrait: page visits, reloads, response status, screen size and scrolling.
# The page is a Fusion fixture of the test site (TestMarkup.Elements).
@playwright @flowEntities
Feature: Navigation

  Background:
    Given I have a site for Site Node "site"
    And I have the following nodes in site "site":
      | NodeAggregateId | Parent        | NodeType                                           | Properties                                   | DimensionSpacePoint |
      | homepage        |               | Sandstorm.E2ETestTools.TestSite:Document.StartPage | {"uriPathSegment":"site","title":"Elements"} | {"language":"de"}   |
      | section         | homepage/main | Sandstorm.E2ETestTools.TestSite:Content.Section    | {}                                           | {"language":"de"}   |
      | elements        | section       | Sandstorm.E2ETestTools.TestSite:Content.TestMarkup | {"markup":"Elements"}                        | {"language":"de"}   |

  Scenario: page visit and reload
    When I access the URI path "/"
    Then the response status code should be "200"
    And the URI path should be "/"
    When I reload the page
    Then the response status code should be "200"

  Scenario: a page that doesn't exist
    When I access the URI path "/does-not-exist"
    Then the response status code should be "404"

  Scenario: screen size
    When I access the URI path "/"
    And the viewport size is 400 x 800
    Then there should be the text "400 px, ratio 1" in "[data-testid=screen]"
    When the screen is 500 x 800 px with pixel ratio 2
    Then there should be the text "500 px, ratio 2" in "[data-testid=screen]"

  Scenario: scrolling
    When I access the URI path "/"
    And the viewport size is 1280 x 600
    Then the heading "Far below" should not be in the viewport
    When I scroll to the bottom of the page
    Then the heading "Far below" should be in the viewport
    When I scroll up by 50 pixels
    And I click the button "Back to top"
    Then the page should be scrolled to the top
