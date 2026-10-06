# The "Export Node" button in the inspector of the Neos backend (Metadata tab): administrators download the selected
# node's document tree as YAML fixture; everybody else sees the button disabled (see Configuration/Policy.yaml).
@playwright @flowEntities
Feature: Fixture export button

  Background:
    Given I have a site for Site Node "site" with name "TestSite"
    And I have the following nodes in site "site":
      | NodeAggregateId | Parent        | NodeType                                           | Properties                                   | DimensionSpacePoint |
      | homepage        |               | Sandstorm.E2ETestTools.TestSite:Document.StartPage | {"uriPathSegment":"site","title":"Homepage"} | {"language":"de"}   |
      | nested          | homepage      | Sandstorm.E2ETestTools.TestSite:Document.Page      | {"uriPathSegment":"nested","title":"Nested"} | {"language":"de"}   |
      | section         | nested/main   | Sandstorm.E2ETestTools.TestSite:Content.Section    | {}                                           | {"language":"de"}   |
      | headline        | section       | Sandstorm.E2ETestTools.TestSite:Content.Headline   | {"title":"<h1>Exported headline<\/h1>"}      | {"language":"de"}   |

  Scenario: an administrator downloads the selected page - and the import creates it again
    Given I have a Neos backend user "admin" with password "password-for-e2e" and role "Neos.Neos:Administrator"
    When I log into the backend using credentials "admin" "password-for-e2e"
    And I click the document tree entry "Nested"
    And I open the inspector tab "Metadata"
    And I download the file of the button "Export Node"
    Then the exported fixture should contain the nodes "homepage, nested, section, headline"
    When I import the exported fixture into an empty content repository in site "site"
    And I access the URI path "/nested"
    Then there should be the text "Exported headline" on the page

  Scenario: an editor can't export
    Given I have a Neos backend user "editor" with password "password-for-e2e" and role "Neos.Neos:Editor"
    When I log into the backend using credentials "editor" "password-for-e2e"
    And I click the document tree entry "Nested"
    And I open the inspector tab "Metadata"
    Then the button "Export Node" should be disabled
