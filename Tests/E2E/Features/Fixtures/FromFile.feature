# Creates nodes from a YAML file next to the feature file. Needs NodeImportTrait in your FeatureContext.
@playwright @flowEntities
Feature: Nodes from a YAML file

  Background:
    Given I have a site for Site Node "site" with name "TestSite"

  Scenario: nodes as in the file
    Given I have the following nodes from file "homepage.yaml" in site "site"
    When I access the URI path "/"
    Then there should be the text "From YAML" on the page

  Scenario: overwrite single properties
    # "I create ..." is an alias of "I have ..."
    Given I create the following nodes from file "homepage.yaml" in site "site" with overwrites:
      | nodeAggregateId | property | value                |
      | headline        | title    | <h1>Overwritten</h1> |
    When I access the URI path "/"
    Then there should be the text "Overwritten" on the page
