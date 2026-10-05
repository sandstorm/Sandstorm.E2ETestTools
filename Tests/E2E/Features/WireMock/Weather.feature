# WireMockTrait: the system under test calls a third-party API on the server side (the test site's weather helper,
# WEATHER_API_URL) - WireMock answers instead. Fixtures in weather/: _base/ is loaded before every scenario, rainy.json
# and error.json are response bodies, storm/ is a tape of mapping files. The tag @wireMock resets the server.
@playwright @flowEntities @wireMock
Feature: Mocked third-party API

  Background:
    Given I have a site for Site Node "site" with name "TestSite"
    And I have the following nodes in site "site":
      | NodeAggregateId | Parent        | NodeType                                           | Properties                                  | DimensionSpacePoint |
      | homepage        |               | Sandstorm.E2ETestTools.TestSite:Document.StartPage | {"uriPathSegment":"site","title":"Weather"} | {"language":"de"}   |
      | section         | homepage/main | Sandstorm.E2ETestTools.TestSite:Content.Section    | {}                                          | {"language":"de"}   |
      | weather         | section       | Sandstorm.E2ETestTools.TestSite:Content.TestMarkup | {"markup":"Weather"}                        | {"language":"de"}   |

  Scenario: the base stub answers
    When I access the URI path "/"
    Then there should be the text "Forecast: sunny" in "[data-testid=forecast]"
    And the API "weather" should have received "GET" "/forecast"

  Scenario: a response for this scenario
    Given the API "weather" path "/forecast" on "GET" serves response "rainy"
    When I access the URI path "/"
    Then there should be the text "Forecast: rainy" in "[data-testid=forecast]"

  Scenario: the API fails
    Given the API "weather" path "/forecast" on "GET" serves response "error" with status 500
    When I access the URI path "/"
    Then there should be the text "Weather unavailable" in "[data-testid=forecast]"

  Scenario: a tape of stubs
    Given I load the stubs "storm" of the API "weather"
    When I access the URI path "/"
    Then there should be the text "Forecast: stormy" in "[data-testid=forecast]"

  Scenario: back to the base stubs
    Given the API "weather" path "/forecast" on "GET" serves response "rainy"
    And I clear all API stubs
    When I access the URI path "/"
    Then there should be the text "Forecast: sunny" in "[data-testid=forecast]"

  Scenario: counting the calls
    When I access the URI path "/"
    And I access the URI path "/"
    Then the API "weather" should have received "GET" "/forecast" 2 times
