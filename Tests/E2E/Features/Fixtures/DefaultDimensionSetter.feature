# The default dimension space point set in the FeatureContext constructor (setDefaultDimensionSpacePoint(), here
# {"language":"de"} - see DefaultDimensionFeatureContext): tables need no DimensionSpacePoint column at all. The step
# changes the default for one scenario only; rows with their own dimension override it either way.
# Runs in its own Behat suite (tag @defaultDimensionSetter, see behat.yml.dist). Scenario order matters: the scenario
# after the step's checks that the constructor's default is back.
@defaultDimensionSetter @flowEntities @playwright
Feature: Default dimension space point from the FeatureContext

  Background:
    Given I have a site for Site Node "site" with name "TestSite"

  Scenario: no column - the constructor's default
    Given I have the following nodes in site "site":
      | NodeAggregateId | Parent        | NodeType                                           | Properties                                   |
      | homepage        |               | Sandstorm.E2ETestTools.TestSite:Document.StartPage | {"uriPathSegment":"site","title":"Homepage"} |
      | section         | homepage/main | Sandstorm.E2ETestTools.TestSite:Content.Section    | {}                                           |
      | headline        | section       | Sandstorm.E2ETestTools.TestSite:Content.Headline   | {"title":"<h1>German headline<\/h1>"}        |
    When I get the node "headline"
    And I render the Fusion object "/testcase" with the current context node:
      """
      testcase = ${Json.stringify(node.dimensionSpacePoint.coordinates)}
      """
    Then the Fusion output should equal to '{"language":"de"}'
    When I access the URI path "/"
    Then there should be the text "German headline" on the page

  Scenario: the step changes the default for this scenario
    Given the default dimension space point is '{"language":"en"}'
    And I have the following nodes in site "site":
      | NodeAggregateId | Parent        | NodeType                                           | Properties                                   |
      | homepage        |               | Sandstorm.E2ETestTools.TestSite:Document.StartPage | {"uriPathSegment":"site","title":"Homepage"} |
      | section         | homepage/main | Sandstorm.E2ETestTools.TestSite:Content.Section    | {}                                           |
      | headline        | section       | Sandstorm.E2ETestTools.TestSite:Content.Headline   | {"title":"<h1>English headline<\/h1>"}       |
    When I get the node "headline"
    And I render the Fusion object "/testcase" with the current context node:
      """
      testcase = ${Json.stringify(node.dimensionSpacePoint.coordinates)}
      """
    Then the Fusion output should equal to '{"language":"en"}'
    When I access the URI path "/en"
    Then there should be the text "English headline" on the page

  Scenario: after a scenario with the step, the constructor's default is back
    Given I have the following nodes in site "site":
      | NodeAggregateId | Parent        | NodeType                                           | Properties                                   |
      | homepage        |               | Sandstorm.E2ETestTools.TestSite:Document.StartPage | {"uriPathSegment":"site","title":"Homepage"} |
    When I get the node "homepage"
    And I render the Fusion object "/testcase" with the current context node:
      """
      testcase = ${Json.stringify(node.dimensionSpacePoint.coordinates)}
      """
    Then the Fusion output should equal to '{"language":"de"}'

  Scenario: the step plus rows that override it
    Given the default dimension space point is '{"language":"de"}'
    And I have the following nodes in site "site":
      | NodeAggregateId | Parent        | NodeType                                           | Properties                                   | DimensionSpacePoint |
      | homepage        |               | Sandstorm.E2ETestTools.TestSite:Document.StartPage | {"uriPathSegment":"site","title":"Homepage"} |                     |
      | section         | homepage/main | Sandstorm.E2ETestTools.TestSite:Content.Section    | {}                                           |                     |
      | headline        | section       | Sandstorm.E2ETestTools.TestSite:Content.Headline   | {"title":"<h1>German headline<\/h1>"}        |                     |
      | headline-ch     | section       | Sandstorm.E2ETestTools.TestSite:Content.Headline   | {"title":"<h2>Only in Switzerland<\/h2>"}    | {"language":"ch"}   |
    When I access the URI path "/"
    Then there should not be the text "Only in Switzerland" on the page
    When I access the URI path "/ch"
    Then there should be the text "German headline" on the page
    And there should be the text "Only in Switzerland" on the page

  Scenario: rows that override the constructor's default, without the step
    Given I have the following nodes in site "site":
      | NodeAggregateId | Parent        | NodeType                                           | Properties                                   | DimensionSpacePoint |
      | homepage        |               | Sandstorm.E2ETestTools.TestSite:Document.StartPage | {"uriPathSegment":"site","title":"Homepage"} |                     |
      | section         | homepage/main | Sandstorm.E2ETestTools.TestSite:Content.Section    | {}                                           |                     |
      | headline-ch     | section       | Sandstorm.E2ETestTools.TestSite:Content.Headline   | {"title":"<h2>Only in Switzerland<\/h2>"}    | {"language":"ch"}   |
    When I get the node "headline-ch" in dimension '{"language":"ch"}'
    And I render the Fusion object "/testcase" with the current context node:
      """
      testcase = ${Json.stringify(node.dimensionSpacePoint.coordinates)}
      """
    Then the Fusion output should equal to '{"language":"ch"}'
    When I access the URI path "/ch"
    Then there should be the text "Only in Switzerland" on the page
