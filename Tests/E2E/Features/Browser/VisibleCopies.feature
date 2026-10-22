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
    And the step 'the button "Subscribe" should be visible' should fail with "at least one is hidden by opacity: 0"
