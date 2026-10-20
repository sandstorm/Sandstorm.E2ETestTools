# ResponsiveImageTrait: which srcset image the browser loads compared to the fitting one. Content.ResponsiveImage of the
# test site has the srcset 200w, 400w, 600w, 800w, 1000w - `sizes` and the shown width come from the node, so `sizes`
# is right or wrong on purpose. Pixel ratio 1: the browser loads the smallest srcset image at least as wide as `sizes`.
# Every image has its own generated image - the browser could reuse an already loaded larger one otherwise.
@playwright @flowEntities
Feature: Responsive images

  Background:
    Given I have a site for the site node "site" with name "TestSite"
    And I have 8 generated test images
    And I have the following nodes in site "site":
      | NodeAggregateId | Parent       | NodeType                                                | Properties                                                                                                                                                             | DimensionSpacePoint |
      | homepage        |              | Sandstorm.E2ETestTools.TestSite:Document.StartPage      | {"uriPathSegment":"site","title":"Homepage"}                                                                                                                           | {"language":"de"}   |
      | right           | homepage     | Sandstorm.E2ETestTools.TestSite:Document.Page           | {"uriPathSegment":"right","title":"Right"}                                                                                                                             | {"language":"de"}   |
      | fitting         | right/main   | Sandstorm.E2ETestTools.TestSite:Content.ResponsiveImage | {"alternativeText":"fitting","width":380,"sizes":"380px","image":{"__flow_object_type":"Neos\\\\Media\\\\Domain\\\\Model\\\\Image","__identifier":"image-1"}}          | {"language":"de"}   |
      | capped          | right/main   | Sandstorm.E2ETestTools.TestSite:Content.ResponsiveImage | {"alternativeText":"capped","width":1200,"sizes":"1200px","image":{"__flow_object_type":"Neos\\\\Media\\\\Domain\\\\Model\\\\Image","__identifier":"image-2"}}         | {"language":"de"}   |
      | leaning         | homepage     | Sandstorm.E2ETestTools.TestSite:Document.Page           | {"uriPathSegment":"leaning","title":"Leaning"}                                                                                                                         | {"language":"de"}   |
      | larger          | leaning/main | Sandstorm.E2ETestTools.TestSite:Content.ResponsiveImage | {"alternativeText":"one larger","width":380,"sizes":"500px","image":{"__flow_object_type":"Neos\\\\Media\\\\Domain\\\\Model\\\\Image","__identifier":"image-3"}}       | {"language":"de"}   |
      | smaller         | leaning/main | Sandstorm.E2ETestTools.TestSite:Content.ResponsiveImage | {"alternativeText":"one smaller","width":580,"sizes":"390px","image":{"__flow_object_type":"Neos\\\\Media\\\\Domain\\\\Model\\\\Image","__identifier":"image-4"}}      | {"language":"de"}   |
      | fitting2        | leaning/main | Sandstorm.E2ETestTools.TestSite:Content.ResponsiveImage | {"alternativeText":"fitting","width":380,"sizes":"380px","image":{"__flow_object_type":"Neos\\\\Media\\\\Domain\\\\Model\\\\Image","__identifier":"image-5"}}          | {"language":"de"}   |
      | wrong           | homepage     | Sandstorm.E2ETestTools.TestSite:Document.Page           | {"uriPathSegment":"wrong","title":"Wrong"}                                                                                                                             | {"language":"de"}   |
      | too-large       | wrong/main   | Sandstorm.E2ETestTools.TestSite:Content.ResponsiveImage | {"alternativeText":"too large","width":380,"sizes":"800px","image":{"__flow_object_type":"Neos\\\\Media\\\\Domain\\\\Model\\\\Image","__identifier":"image-6"}}        | {"language":"de"}   |
      | too-small       | wrong/main   | Sandstorm.E2ETestTools.TestSite:Content.ResponsiveImage | {"alternativeText":"too small","width":780,"sizes":"300px","image":{"__flow_object_type":"Neos\\\\Media\\\\Domain\\\\Model\\\\Image","__identifier":"image-7"}}        | {"language":"de"}   |
      | auto            | homepage     | Sandstorm.E2ETestTools.TestSite:Document.Page           | {"uriPathSegment":"auto","title":"Auto"}                                                                                                                               | {"language":"de"}   |
      | auto-sizes      | auto/main    | Sandstorm.E2ETestTools.TestSite:Content.ResponsiveImage | {"alternativeText":"auto sizes","width":380,"sizes":"auto, 380px","image":{"__flow_object_type":"Neos\\\\Media\\\\Domain\\\\Model\\\\Image","__identifier":"image-8"}} | {"language":"de"}   |

  Scenario: right sizes - fitting, and capped above the largest srcset image
    When I access the URI path "/right"
    And the screen is 1400 x 900 px with pixel ratio 1
    And I load all images of the page
    Then every image should load the fitting srcset image

  Scenario: one srcset image smaller or larger - only where the feature says so
    When I access the URI path "/leaning"
    And the screen is 1400 x 900 px with pixel ratio 1
    And I load all images of the page
    Then every image should load the fitting srcset image, except:
      | image       | expected               |
      | one larger  | fitting or one larger  |
      | one smaller | fitting or one smaller |
    And the step 'every image should load the fitting srcset image' should fail with "ERROR · one larger · one larger, not expected here (expected: fitting or capped) · needs 380 px (shown 380 px × ratio 1) · sizes says 500 px -> too wide · loaded 600w, expected 400w -> one size larger (more bytes)"
    And the step 'every image should load the fitting srcset image' should fail with "ERROR · one smaller · one smaller, not expected here (expected: fitting or capped)"

  Scenario: wrong sizes
    When I access the URI path "/wrong"
    And the screen is 1400 x 900 px with pixel ratio 1
    And I load all images of the page
    Then the step 'every image should load the fitting srcset image' should fail with "ERROR · too large · too large, not expected here (expected: fitting or capped) · needs 380 px (shown 380 px × ratio 1) · sizes says 800 px -> too wide · loaded 800w, expected 400w -> too large"
    And the step 'every image should load the fitting srcset image' should fail with "ERROR · too small · too small, not expected here (expected: fitting or capped) · needs 780 px (shown 780 px × ratio 1) · sizes says 300 px -> too narrow · loaded 400w, expected 800w -> too small (blurry)"

  Scenario: sizes="auto" makes the check pointless
    When I access the URI path "/auto"
    And I load all images of the page
    Then the step 'every image should load the fitting srcset image' should fail with 'These images have sizes="auto"'
