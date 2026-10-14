# Renders a single Fusion component - no nodes needed, as long as it doesn't render node links.
Feature: Button component

  Scenario: primary button
    When I render the Fusion object "/testcase":
      """
      testcase = Sandstorm.E2ETestTools.TestSite:Component.Button {
        title = 'Click me'
        type = 'primary'
      }
      """
    Then the inner HTML of "button span" in the Fusion output should be "Click me"
