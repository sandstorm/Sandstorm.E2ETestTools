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
    Then in the fusion output, the inner HTML of CSS selector "button span" matches "Click me"
