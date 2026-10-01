# Creates a file asset as fixture and references it from a node property of type Asset.
# The Behat context must use the same persistent resource storage/target as the system under test, otherwise the
# file 404s there - see "Two Flow Contexts, Two Ports" in the README.
# In the Properties JSON, backslashes (PHP class names) must be written as \\\\ : Gherkin unescapes \\ to \ first.
@playwright @flowEntities
Feature: Download of a fixture file

  Scenario: download node links the fixture file
    Given I have a site for Site Node "site" with name "YourSiteName"
    And I have a textual persistent resource "74d819f0-0bf4-44df-ae36-0c7639c1afcc" named "hello.txt" with the following content:
      """
      Hello from a fixture resource
      """
    And I have the following nodes in site "site":
      | NodeAggregateId | Parent        | NodeType                               | Properties                                                                                                                                                         | DimensionSpacePoint |
      | homepage        |               | Your.SitePackageKey:Document.StartPage | {"uriPathSegment":"site","title":"Homepage"}                                                                                                                       | {"language":"de"}   |
      | section         | homepage/main | Your.SitePackageKey:Content.Section    | {}                                                                                                                                                                 | {"language":"de"}   |
      | download        | section       | Your.SitePackageKey:Content.Download   | {"description":"Get the file","file":{"__flow_object_type":"Neos\\\\Media\\\\Domain\\\\Model\\\\Document","__identifier":"74d819f0-0bf4-44df-ae36-0c7639c1afcc"}} | {"language":"de"}   |
    When I access the URI path "/"
    Then there should be the text "Get the file" on the page
