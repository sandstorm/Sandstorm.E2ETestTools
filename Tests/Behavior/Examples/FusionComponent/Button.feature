# Renders a single Fusion component - no nodes needed, as long as it doesn't render node links.
# @playwright is only needed for the style guide steps.
@playwright
Feature: Button component

  Scenario: primary button
    When I render the Fusion object "/testcase":
      """
      testcase = Your.SitePackageKey:Component.Button {
        title = 'Click me'
        type = 'primary'
      }
      """
    Then in the fusion output, the inner HTML of CSS selector "button span" matches "Click me"
    Then I store the Fusion output in the styleguide as "Button_Component_Primary"
    Then I store the Fusion output in the styleguide as "Button_Component_Primary_Mobile" using viewport width "320"
