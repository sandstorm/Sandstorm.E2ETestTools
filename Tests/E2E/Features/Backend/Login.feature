# Creates a backend user, logs into the Neos backend of the system under test and navigates it.
# The login step fills the fields by their placeholder - for a non-English backend use the variant
# '... with username placeholder "Benutzername" and password placeholder "Passwort"'.
@playwright @flowEntities
Feature: Backend login

  Background:
    Given the default dimension space point is '{"language":"de"}'

  Scenario: an editor logs in and navigates the backend
    Given I have a site for Site Node "site" with name "TestSite"
    And I have the following nodes in site "site":
      | NodeAggregateId | Parent   | NodeType                                           | Properties                                   |
      | homepage        |          | Sandstorm.E2ETestTools.TestSite:Document.StartPage | {"uriPathSegment":"site","title":"Homepage"} |
      | nested          | homepage | Sandstorm.E2ETestTools.TestSite:Document.Page      | {"uriPathSegment":"nested","title":"Nested"} |
    # the quote, backtick and ${ check that step values are escaped in the Playwright script
    And I have a Neos backend user "editor" with password 'pass"word`${e2e}' and role "Neos.Neos:Editor"
    When I log into the backend using credentials "editor" 'pass"word`${e2e}'
    Then the URI path should be "/neos/content"
    When I click the document tree entry "Nested"
    And I click the main menu item "Management"
    Then the URI path should be "/neos/management"
    When I click the overview dashboard tile "Media"
    Then the URI path should be "/neos/management/media"
