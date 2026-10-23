# PageHealthTrait: a smoke test per page - JavaScript errors, failed requests, images.
# The pages are Fusion fixtures of the test site (TestMarkup.Health, TestMarkup.BrokenHealth).
@playwright @flowEntities
Feature: Page health

  Background:
    Given I have a site for the site node "site"
    And I have the following nodes in site "site":
      | NodeAggregateId | Parent             | NodeType                                           | Properties                                   | DimensionSpacePoint |
      | homepage        |                    | Sandstorm.E2ETestTools.TestSite:Document.StartPage | {"uriPathSegment":"site","title":"Healthy"}  | {"language":"de"}   |
      | section         | homepage/main      | Sandstorm.E2ETestTools.TestSite:Content.Section    | {}                                           | {"language":"de"}   |
      | health          | section            | Sandstorm.E2ETestTools.TestSite:Content.TestMarkup | {"markup":"Health"}                          | {"language":"de"}   |
      | broken          | homepage           | Sandstorm.E2ETestTools.TestSite:Document.Page      | {"uriPathSegment":"broken","title":"Broken"} | {"language":"de"}   |
      | broken-section  | broken/main        | Sandstorm.E2ETestTools.TestSite:Content.Section    | {}                                           | {"language":"de"}   |
      | broken-markup   | broken-section     | Sandstorm.E2ETestTools.TestSite:Content.TestMarkup | {"markup":"BrokenHealth"}                    | {"language":"de"}   |

  Scenario: a healthy page
    When I access the URI path "/"
    Then there should be no JavaScript errors
    And there should be no failed requests
    # the lazy image far below doesn't load by itself - the check doesn't load it (takes 15 s)
    And the step 'every image should be loaded' should fail with 'a lazy image? "I load all images of the page" first'
    When I load all images of the page
    Then every image should be loaded
    And every image should have an alt attribute

  Scenario: a broken page
    Given requests to other hosts are recorded and blocked
    When I access the URI path "/broken"
    Then the step 'there should be no JavaScript errors' should fail with "console.error: Logged on purpose"
    And the step 'there should be no JavaScript errors' should fail with "Thrown on purpose"
    And the step 'there should be no failed requests' should fail with "GET /missing-image.png (404)"
    And the step 'there should be no failed requests' should fail with "GET /missing-api (404)"
    And the step 'every image should be loaded' should fail with "/missing-image.png (not loaded)"
    And the step 'every image should have an alt attribute' should fail with "/missing-image.png"
    And there should be no failed requests except:
      | URI path |
      | /missing |
    # blocked, but not the site's fault
    And there should be a request to "example.invalid"

  Scenario: before the first page load
    Then the step 'there should be no JavaScript errors' should fail with 'No page - use "I access the URI path" first.'
