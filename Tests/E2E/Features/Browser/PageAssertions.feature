# PageAssertionsTrait: page title, texts on the page and in elements, focus, element states by role and by test id,
# viewport.
# The page is a Fusion fixture of the test site (TestMarkup.Elements).
@playwright @flowEntities
Feature: Page assertions

  Background:
    Given I have a site for the site node "site"
    And I have the following nodes in site "site":
      | NodeAggregateId | Parent        | NodeType                                           | Properties                                   | DimensionSpacePoint |
      | homepage        |               | Sandstorm.E2ETestTools.TestSite:Document.StartPage | {"uriPathSegment":"site","title":"Elements"} | {"language":"de"}   |
      | section         | homepage/main | Sandstorm.E2ETestTools.TestSite:Content.Section    | {}                                           | {"language":"de"}   |
      | elements        | section       | Sandstorm.E2ETestTools.TestSite:Content.TestMarkup | {"markup":"Elements"}                        | {"language":"de"}   |
    And I access the URI path "/"

  Scenario: title and texts
    Then the page title should be "Elements"
    And there should be the text "Welcome" on the page
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

  Scenario: element states by role and accessible name
    Then the button "Enabled" should be visible
    And the button "Enabled" should be enabled
    And the button "Disabled" should be disabled
    And the link "Unavailable" should be disabled
    And the button "Hidden button" should be hidden

  Scenario: viewport
    Then the element with test id "visible-box" should be in the viewport
    And the element with test id "far-below" should not be in the viewport

  Scenario: focus and viewport by role and accessible name
    Then the textbox "Focused field" should be focused
    And the heading "Far below" should not be in the viewport
    When I focus the button "Enabled"
    Then the button "Enabled" should be focused
    When I focus the link "Start"
    Then the focus should be inside ".links"
