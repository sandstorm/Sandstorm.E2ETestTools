# FormInteractionTrait, PageAssertionsTrait: markup that renders a control twice (desktop and mobile, one of them hidden)
# - the steps use the copy the visitor sees, two visible copies are an error.
# The page is a Fusion fixture of the test site (TestMarkup.Duplicates).
@playwright @flowEntities
Feature: Visible copies

  Background:
    Given I have a site for the site node "site"
    And I have the following nodes in site "site":
      | NodeAggregateId | Parent        | NodeType                                           | Properties                                     | DimensionSpacePoint |
      | homepage        |               | Sandstorm.E2ETestTools.TestSite:Document.StartPage | {"uriPathSegment":"site","title":"Duplicates"} | {"language":"de"}   |
      | section         | homepage/main | Sandstorm.E2ETestTools.TestSite:Content.Section    | {}                                             | {"language":"de"}   |
      | duplicates      | section       | Sandstorm.E2ETestTools.TestSite:Content.TestMarkup | {"markup":"Duplicates"}                        | {"language":"de"}   |
    And I access the URI path "/"

  Scenario: acting on the visible copy
    When I fill "Berlin" into the field "City"
    And I check the checkbox "Terms"
    And I click the button "Order"
    Then there should be the text "Ordered: Berlin, terms yes" in "[data-testid=order-result]"
    When I uncheck the checkbox "Terms"
    And I click the element with test id "order"
    Then there should be the text "Ordered: Berlin, terms no" in "[data-testid=order-result]"

  Scenario: radio button and select on the visible copy
    # the hidden copy has "Express" checked and "M" selected
    Then the checkbox "Express" should not be checked
    And the field "Size" should have "S" selected
    When I choose the radio button "Express"
    And I select "M" in the field "Size"
    Then the checkbox "Express" should be checked
    And the field "Size" should have "M" selected
    When I click the button "Order"
    Then there should be the text "express yes, size M" in "[data-testid=order-result]"

  Scenario: validation, focus and viewport of the visible copy
    # the hidden copy holds a valid address
    Then the field "Email" should be invalid
    When I focus the button "Order"
    Then the button "Order" should be focused
    And the element with test id "order" should be focused
    And the button "Order" should be in the viewport
    And the element with test id "order" should be in the viewport

  Scenario: focus inside nested matches
    When I focus the button "Panel button"
    Then the focus should be inside ".panel"

  Scenario: checking the visible copy
    Then the field "City" should have the value "visible copy"
    And the checkbox "Terms" should not be checked
    And the button "Order" should be visible
    And the button "Order" should be enabled
    And the element with test id "order" should be visible
    And the checkbox "Hidden only" should be hidden
    # no visible element - a negative check passes
    And there should not be the text "Ordered" in ".does-not-exist"

  Scenario: an sr-only file input counts as visible
    When I upload the file "hello.txt" to the field "Upload"
    Then there should be the text "Uploaded hello.txt" in "[data-testid=upload-result]"

  Scenario: failures
    Then the step 'the button "Delete" should be visible' should fail with "strict mode violation"
    And the step 'I click the button "Delete"' should fail with "strict mode violation"
    And the step 'the button "Order" should be hidden' should fail with "toHaveCount"
    And the step 'the checkbox "Hidden only" should be checked' should fail with "No visible match - found 1 hidden match."

  Scenario: failure hints for visible matches the visitor doesn't see
    Then the step 'the button "Subscribe" should be visible' should fail with "at least one is hidden by opacity: 0"
    And the step 'the button "Search" should be visible' should fail with "at least one is hidden by opacity: 0, off-screen positioning or sr-only"
    And the step 'the button "Close" should be visible' should fail with "at least one is hidden by opacity: 0, off-screen positioning or sr-only"
    # two buttons the visitor sees - no hint
    And the step 'the button "Delete" should be visible' should fail without "at least one is hidden"
    And the step 'the button "Delete" should be visible' should fail without "No visible match"
