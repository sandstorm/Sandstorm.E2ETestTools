# The fixture export on the command line: ./flow e2efixture:export (see "Fixtures from existing content" in the README).
# These scenarios run ./flow from Behat - only because the command is what they test (see FixtureExportTrait).
@flowEntities
Feature: Fixture export command

  Background:
    Given I have a site for Site Node "site" with name "TestSite"
    And I have the following nodes in site "site":
      | NodeAggregateId | Parent        | NodeType                                           | Properties                                   | DimensionSpacePoint |
      | homepage        |               | Sandstorm.E2ETestTools.TestSite:Document.StartPage | {"uriPathSegment":"site","title":"Homepage"} | {"language":"de"}   |
      | nested          | homepage      | Sandstorm.E2ETestTools.TestSite:Document.Page      | {"uriPathSegment":"nested","title":"Nested"} | {"language":"de"}   |
      | section         | homepage/main | Sandstorm.E2ETestTools.TestSite:Content.Section    | {}                                           | {"language":"de"}   |
      | headline        | section       | Sandstorm.E2ETestTools.TestSite:Content.Headline   | {"title":"<h1>Exported headline<\/h1>"}      | {"language":"de"}   |
    And the following node references:
      | NodeAggregateId | ReferenceName | Targets | DimensionSpacePoint |
      | homepage        | privacyPage   | nested  | {"language":"de"}   |

  Scenario: export by NodeAggregateId - the node's closest document with its ancestors and descendants
    When I run the fixture export with the arguments:
      """
      headline --dimension '{"language":"de"}'
      """
    Then the exported fixture should contain the nodes "homepage, section, headline"

  Scenario: export by URI path
    When I run the fixture export with the arguments:
      """
      --uri-path nested --dimension '{"language":"de"}'
      """
    Then the exported fixture should contain the nodes "homepage, nested"

  Scenario: export as Gherkin steps
    When I run the fixture export with the arguments:
      """
      --uri-path / --dimension '{"language":"de"}' --format gherkin --site-name site
      """
    Then the exported steps should contain 'Given I have the following nodes in site "site":'
    And the exported steps should contain "And the following node references:"
    And the exported steps should contain "| headline"

  @playwright
  Scenario: round trip - the import creates what the export wrote
    When I run the fixture export with the arguments:
      """
      --uri-path / --dimension '{"language":"de"}'
      """
    And I import the exported fixture into an empty content repository in site "site"
    And I access the URI path "/"
    Then there should be the text "Exported headline" on the page
    When I get the node "homepage" in dimension '{"language":"de"}'
    And I render the Fusion object "/testcase" with the current context node:
      """
      testcase = ${q(node).referenceNodes('privacyPage').property('title')}
      """
    Then the Fusion output should equal to "Nested"

  Scenario: neither NodeAggregateId nor URI path
    When I run the fixture export with the arguments:
      """
      --dimension '{"language":"de"}'
      """
    Then the export should fail with "Give either a NodeAggregateId or --uri-path."

  Scenario: both NodeAggregateId and URI path
    When I run the fixture export with the arguments:
      """
      headline --uri-path / --dimension '{"language":"de"}'
      """
    Then the export should fail with "Give either a NodeAggregateId or --uri-path."

  Scenario: unknown format
    When I run the fixture export with the arguments:
      """
      headline --dimension '{"language":"de"}' --format json
      """
    Then the export should fail with 'Unknown format "json"'

  Scenario: unknown node
    When I run the fixture export with the arguments:
      """
      does-not-exist --dimension '{"language":"de"}'
      """
    Then the export should fail with "does-not-exist"
