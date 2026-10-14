# Image assets as fixtures: "I have the following images" imports files (Path relative to the Packages directory) and
# publishes them; nodes reference them by Image ID. Backslashes in the Properties JSON: see Download.feature.
@flowEntities
Feature: Image fixtures

  Background:
    Given I have a site for the site node "site" with name "TestSite"
    And I have the following images:
      | Image ID                             | Path                                                                                    | Collection | Filename      | Relative Publication Path | Copyright Notice    |
      | 0e4b3bb6-2b8c-4bcf-9d3f-3b4f5c6d7e8f | Application/Sandstorm.E2ETestTools/Tests/E2E/Features/PersistentResources/landscape.png | persistent | landscape.png |                           | © Test photographer |
    And I have the following nodes in site "site":
      | NodeAggregateId | Parent        | NodeType                                           | Properties                                                                                                                                                          | DimensionSpacePoint |
      | homepage        |               | Sandstorm.E2ETestTools.TestSite:Document.StartPage | {"uriPathSegment":"site","title":"Homepage"}                                                                                                                        | {"language":"de"}   |
      | section         | homepage/main | Sandstorm.E2ETestTools.TestSite:Content.Section    | {}                                                                                                                                                                  | {"language":"de"}   |
      | image           | section       | Sandstorm.E2ETestTools.TestSite:Content.Image      | {"alternativeText":"A green landscape","image":{"__flow_object_type":"Neos\\\\Media\\\\Domain\\\\Model\\\\Image","__identifier":"0e4b3bb6-2b8c-4bcf-9d3f-3b4f5c6d7e8f"}} | {"language":"de"}   |

  Scenario: the image node renders the asset
    Given I get the node "image" in dimension '{"language":"de"}'
    When I render the Fusion object "/testcase" with the current context node:
      """
      testcase = Sandstorm.E2ETestTools.TestSite:Content.Image
      """
    Then the inner HTML of "figcaption" in the Fusion output should be "© Test photographer"
    And the attributes of "img" in the Fusion output should be:
      | Key | Value             |
      | alt | A green landscape |

  @playwright
  Scenario: the image shows on the system under test
    When I access the URI path "/"
    Then there should be the text "© Test photographer" on the page
    And the element with test id "image" should be visible
