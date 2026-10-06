# PageAssertionsTrait: page title, texts on the page and in elements, element states by test id, viewport.
# The page is a Fusion fixture of the test site (TestMarkup.Elements).
@playwright @flowEntities
Feature: Page assertions

  Background:
    Given I have a site for Site Node "site"
    And I have the following nodes in site "site":
      | NodeAggregateId | Parent        | NodeType                                           | Properties                                   | DimensionSpacePoint |
      | homepage        |               | Sandstorm.E2ETestTools.TestSite:Document.StartPage | {"uriPathSegment":"site","title":"Elements"} | {"language":"de"}   |
      | section         | homepage/main | Sandstorm.E2ETestTools.TestSite:Content.Section    | {}                                           | {"language":"de"}   |
      | elements        | section       | Sandstorm.E2ETestTools.TestSite:Content.TestMarkup | {"markup":"Elements"}                        | {"language":"de"}   |
    And I access the URI path "/"

  Scenario: title and texts
    Then the page title should be "Elements"
    And there should be the text "Welcome" in ".intro"
    And there should not be the text "Welcome" in ".outro"
    # text in hidden elements counts as absent
    And there should not be the text "Secret text" on the page

  Scenario: element states
    Then the element with test id "visible-box" should be visible
    And the element with test id "hidden-box" should be hidden
    And the element with test id "focused-field" should be focused
    And the element with test id "enabled-button" should be enabled
    And the element with test id "disabled-button" should be disabled
    And the element with test id "aria-disabled-link" should be disabled

  Scenario: viewport
    Then the element with test id "visible-box" should be in the viewport
    And the element with test id "far-below" should not be in the viewport
