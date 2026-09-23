# Node references (property type "reference"/"references") are set in a separate step, after the nodes exist.
# Targets: comma-separated NodeAggregateIds.
@flowEntities
Feature: Node references

  Scenario: start page references the privacy page
    Given I have a site for Site Node "site" with name "YourSiteName"
    And I have the following nodes in site "site":
      | NodeAggregateId | Parent   | NodeType                               | Properties                                     | DimensionSpacePoint |
      | homepage        |          | Your.SitePackageKey:Document.StartPage | {"uriPathSegment":"site","title":"Homepage"}   | {"language":"de"}   |
      | privacy         | homepage | Your.SitePackageKey:Document.Page      | {"uriPathSegment":"privacy","title":"Privacy"} | {"language":"de"}   |
    And the following node references:
      | NodeAggregateId | ReferenceName | Targets | DimensionSpacePoint |
      | homepage        | privacyPage   | privacy | {"language":"de"}   |
    And I get the node "homepage" in dimension '{"language":"de"}'
    When I render the Fusion object "/testcase" with the current context node:
      """
      testcase = ${q(node).referenceNodes('privacyPage').property('title')}
      """
    Then the Fusion output should equal to "Privacy"
