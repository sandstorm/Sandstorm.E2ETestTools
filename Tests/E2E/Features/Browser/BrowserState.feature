# BrowserStateTrait: cookies and web storage - checking what a page stores, and presetting it before the first visit
# (e.g. the consent decision, so the banner doesn't cover every page). Page: TestMarkup.BrowserState of the test site.
@playwright @flowEntities
Feature: Cookies and web storage

  Background:
    Given I have a site for Site Node "site" with name "TestSite"
    And I have the following nodes in site "site":
      | NodeAggregateId | Parent        | NodeType                                           | Properties                                | DimensionSpacePoint |
      | homepage        |               | Sandstorm.E2ETestTools.TestSite:Document.StartPage | {"uriPathSegment":"site","title":"State"} | {"language":"de"}   |
      | section         | homepage/main | Sandstorm.E2ETestTools.TestSite:Content.Section    | {}                                        | {"language":"de"}   |
      | state           | section       | Sandstorm.E2ETestTools.TestSite:Content.TestMarkup | {"markup":"BrowserState"}                 | {"language":"de"}   |

  Scenario: accepting the consent banner sets a cookie
    When I access the URI path "/"
    Then the cookie "consent" should not be set
    And the element with test id "consent-banner" should be visible
    When I click the button "Accept cookies"
    Then the cookie "consent" should be set
    # compared URL-decoded
    And the cookie "consent" should have the value '{"analytics":true}'
    And the element with test id "consent-banner" should be hidden

  Scenario: a preset consent cookie hides the banner
    Given the cookie "consent" has the value "accepted"
    When I access the URI path "/"
    Then the element with test id "consent-banner" should be hidden
    When I delete the cookie "consent"
    Then the cookie "consent" should not be set

  Scenario: preset local storage
    Given the local storage key "theme" has the value "dark"
    When I access the URI path "/"
    Then there should be the text "Theme: dark" in "[data-testid=theme]"
    And the local storage key "theme" should have the value "dark"

  Scenario: storage written by the page
    When I access the URI path "/"
    Then the session storage key "tab" should not be set
    When I click the button "Remember settings"
    Then the session storage key "tab" should have the value "details"
    And the local storage key "theme" should have the value "dark"
    When I remove the session storage key "tab"
    Then the session storage key "tab" should not be set
