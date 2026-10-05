# Creates a backend user and logs into the Neos backend of the system under test.
# The login step fills the fields by their placeholder - for a non-English backend use the variant
# '... with username placeholder "Benutzername" and password placeholder "Passwort"'.
@playwright @flowEntities
Feature: Backend login

  Scenario: editor can log in
    Given I have a site for Site Node "site" with name "TestSite"
    And I have the following nodes in site "site":
      | NodeAggregateId | Parent | NodeType                               | Properties                                   | DimensionSpacePoint |
      | homepage        |        | Sandstorm.E2ETestTools.TestSite:Document.StartPage | {"uriPathSegment":"site","title":"Homepage"} | {"language":"de"}   |
    And I have a Neos backend user "editor" with password "password-for-e2e" and role "Neos.Neos:Editor"
    When I log into the backend using credentials "editor" "password-for-e2e"
    Then the URI path should be "/neos/content"

  Scenario: editor navigates the backend
    Given I have a site for Site Node "site" with name "TestSite"
    And I have the following nodes in site "site":
      | NodeAggregateId | Parent   | NodeType                                           | Properties                                   | DimensionSpacePoint |
      | homepage        |          | Sandstorm.E2ETestTools.TestSite:Document.StartPage | {"uriPathSegment":"site","title":"Homepage"} | {"language":"de"}   |
      | nested          | homepage | Sandstorm.E2ETestTools.TestSite:Document.Page      | {"uriPathSegment":"nested","title":"Nested"} | {"language":"de"}   |
    And I have a Neos backend user "editor" with password "password-for-e2e" and role "Neos.Neos:Editor"
    When I log into the backend using credentials "editor" "password-for-e2e" with username placeholder "Username" and password placeholder "Password"
    And I click the document tree entry "Nested"
    And I click the main menu item "Management"
    And I click the overview dashboard tile "Media"
    Then the URI path should be "/neos/management/media"
