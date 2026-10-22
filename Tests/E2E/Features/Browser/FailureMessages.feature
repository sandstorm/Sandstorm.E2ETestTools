# The failure messages of the browser steps - a failing step should tell what went wrong. StepFailureTrait (test-only)
# runs a step with a short timeout and checks its message.
# The page is a Fusion fixture of the test site (TestMarkup.Elements).
@playwright @flowEntities
Feature: Failure messages

  Background:
    Given I have a site for the site node "site"
    And I have the following nodes in site "site":
      | NodeAggregateId | Parent        | NodeType                                           | Properties                                   | DimensionSpacePoint |
      | homepage        |               | Sandstorm.E2ETestTools.TestSite:Document.StartPage | {"uriPathSegment":"site","title":"Elements"} | {"language":"de"}   |
      | section         | homepage/main | Sandstorm.E2ETestTools.TestSite:Content.Section    | {}                                           | {"language":"de"}   |
      | elements        | section       | Sandstorm.E2ETestTools.TestSite:Content.TestMarkup | {"markup":"Elements"}                        | {"language":"de"}   |

  Scenario: before the first page load
    Then the step 'the response status code should be 200' should fail with 'No response of a page load - use "I access the URI path" first.'

  Scenario: focus
    When I access the URI path "/"
    Then the step 'the button "Enabled" should be focused' should fail with 'Focused instead: <input data-testid="focused-field"'
    And the step 'the button "Behind the dialog" should be focused' should fail with "is hidden from assistive technology (inert or aria-hidden), so it can't be focused"
    And the step 'the focus should be inside ".links"' should fail with "Focused instead"

  Scenario: element states
    When I access the URI path "/"
    Then the step 'the button "Enabled" should be hidden' should fail with "Expected: 0"
    And the step 'the button "Hidden button" should be visible' should fail with "toBeVisible"
    And the step 'the button "Disabled" should be enabled' should fail with "toBeEnabled"
    And the step 'the element with test id "visible-box" should be shiny' should fail with 'Unknown state "shiny" - use one of: visible, hidden, focused, enabled, disabled.'

  Scenario: texts
    When I access the URI path "/"
    # the text is in the page, but hidden
    Then the step 'there should be the text "Secret text" on the page' should fail with "toContainText"
    And the step 'there should not be the text "Welcome" on the page' should fail with "toContainText"

  Scenario: network
    When I access the URI path "/"
    Then the step 'there should be a request to "example.invalid"' should fail with 'No requests are recorded - use "requests to other hosts are recorded and blocked" first.'
    And the step 'all links in ".broken-links" should respond with 200' should fail with "/does-not-exist (404)"
