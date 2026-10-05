# With content dimensions every fixture row needs a dimension space point. Set a default once - per scenario with this
# step, for all scenarios with setDefaultDimensionSpacePoint() in the FeatureContext - and leave out the
# DimensionSpacePoint column, or its cell on rows of the default. Rows with their own dimension keep it.
# The test site's language "ch" specializes "de": ch shows the de content plus its own nodes.
@flowEntities
Feature: Default dimension space point

  @playwright
  Scenario: tables without the DimensionSpacePoint column
    Given I have a site for Site Node "site" with name "TestSite"
    And the default dimension space point is '{"language":"de"}'
    And I have the following nodes in site "site":
      | NodeAggregateId | Parent        | NodeType                                           | Properties                                   |
      | homepage        |               | Sandstorm.E2ETestTools.TestSite:Document.StartPage | {"uriPathSegment":"site","title":"Homepage"} |
      | nested          | homepage      | Sandstorm.E2ETestTools.TestSite:Document.Page      | {"uriPathSegment":"nested","title":"Nested"} |
      | section         | homepage/main | Sandstorm.E2ETestTools.TestSite:Content.Section    | {}                                           |
      | headline        | section       | Sandstorm.E2ETestTools.TestSite:Content.Headline   | {"title":"<h1>German headline<\/h1>"}        |
    And the following node references:
      | NodeAggregateId | ReferenceName | Targets |
      | homepage        | privacyPage   | nested  |
    # without "in dimension": the default
    When I get the node "homepage"
    And I render the Fusion object "/testcase" with the current context node:
      """
      testcase = ${q(node).referenceNodes('privacyPage').property('title')}
      """
    Then the Fusion output should equal to "Nested"
    When I access the URI path "/"
    Then there should be the text "German headline" on the page

  @playwright
  Scenario: mixed - the default plus rows of another dimension
    Given I have a site for Site Node "site" with name "TestSite"
    And the default dimension space point is '{"language":"de"}'
    And I have the following nodes in site "site":
      | NodeAggregateId | Parent        | NodeType                                           | Properties                                       | DimensionSpacePoint |
      | homepage        |               | Sandstorm.E2ETestTools.TestSite:Document.StartPage | {"uriPathSegment":"site","title":"Homepage"}     |                     |
      | nested          | homepage      | Sandstorm.E2ETestTools.TestSite:Document.Page      | {"uriPathSegment":"nested","title":"Nested"}     |                     |
      | section         | homepage/main | Sandstorm.E2ETestTools.TestSite:Content.Section    | {}                                               |                     |
      | headline        | section       | Sandstorm.E2ETestTools.TestSite:Content.Headline   | {"title":"<h1>German headline<\/h1>"}            |                     |
      | headline-ch     | section       | Sandstorm.E2ETestTools.TestSite:Content.Headline   | {"title":"<h2>Only in Switzerland<\/h2>"}        | {"language":"ch"}   |
      | swiss-page      | homepage      | Sandstorm.E2ETestTools.TestSite:Document.Page      | {"uriPathSegment":"swiss","title":"Swiss page"} | {"language":"ch"}   |
    And the following node references:
      | NodeAggregateId | ReferenceName | Targets | DimensionSpacePoint |
      | homepage        | privacyPage   | nested  |                     |
      | swiss-page      | relatedPage   | nested  | {"language":"ch"}   |
    When I access the URI path "/"
    Then there should be the text "German headline" on the page
    And there should not be the text "Only in Switzerland" on the page
    When I access the URI path "/ch"
    Then there should be the text "German headline" on the page
    And there should be the text "Only in Switzerland" on the page
    When I get the node "swiss-page" in dimension '{"language":"ch"}'
    And I render the Fusion object "/testcase" with the current context node:
      """
      testcase = ${q(node).referenceNodes('relatedPage').property('title')}
      """
    Then the Fusion output should equal to "Nested"

  @playwright
  Scenario: YAML file without dimensionSpacePoint
    Given I have a site for Site Node "site" with name "TestSite"
    And the default dimension space point is '{"language":"de"}'
    And I have the following nodes from file "homepage-without-dimension.yaml" in site "site"
    When I access the URI path "/"
    Then there should be the text "From YAML without dimension" on the page
