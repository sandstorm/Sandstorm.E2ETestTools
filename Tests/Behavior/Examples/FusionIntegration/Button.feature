# Tests the wiring between a node and its Fusion rendering: create nodes, pick one as context node and render its
# NodeType integration. Node links work (they resolve against the site of the context node).
@flowEntities
Feature: Button integration

  Background:
    Given I have a site for Site Node "site" with name "YourSiteName"
    And I have the following nodes in site "site":
      | NodeAggregateId | Parent        | NodeType                               | Properties                                               | DimensionSpacePoint |
      | homepage        |               | Your.SitePackageKey:Document.StartPage | {"uriPathSegment":"site","title":"Homepage"}             | {"language":"de"}   |
      | nested          | homepage      | Your.SitePackageKey:Document.Page      | {"uriPathSegment":"nested","title":"Nested"}             | {"language":"de"}   |
      | section         | homepage/main | Your.SitePackageKey:Content.Section    | {}                                                       | {"language":"de"}   |
      | button          | section       | Your.SitePackageKey:Content.Button     | {"title":"Go","type":"secondary","link":"node://nested"} | {"language":"de"}   |

  Scenario: button links to another page
    Given I get the node "button" in dimension '{"language":"de"}'
    When I render the Fusion object "/testcase" with the current context node:
      """
      testcase = Your.SitePackageKey:Content.Button
      """
    Then in the fusion output, the inner HTML of CSS selector "a span" matches "Go"
    And in the fusion output, the attributes of CSS selector "a" are:
      | Key  | Value   |
      | href | /nested |
