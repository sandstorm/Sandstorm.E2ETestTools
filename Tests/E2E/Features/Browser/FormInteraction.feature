# FormInteractionTrait: fields by their label, buttons and links by their caption, elements by test id.
# The form page is a Fusion fixture of the test site (TestMarkup.Form).
@playwright @flowEntities
Feature: Form interaction

  Background:
    Given I have a site for Site Node "site" with name "TestSite"
    And I create the following nodes in site "site":
      | NodeAggregateId | Parent        | NodeType                                           | Properties                                   | DimensionSpacePoint |
      | homepage        |               | Sandstorm.E2ETestTools.TestSite:Document.StartPage | {"uriPathSegment":"site","title":"Form"}     | {"language":"de"}   |
      | nested          | homepage      | Sandstorm.E2ETestTools.TestSite:Document.Page      | {"uriPathSegment":"nested","title":"Nested"} | {"language":"de"}   |
      | section         | homepage/main | Sandstorm.E2ETestTools.TestSite:Content.Section    | {}                                           | {"language":"de"}   |
      | form            | section       | Sandstorm.E2ETestTools.TestSite:Content.TestMarkup | {"markup":"Form"}                            | {"language":"de"}   |
    And I access the URI path "/"

  Scenario: filling the form and sending it
    When I fill "Jane" into the field "Name"
    And I check the checkbox "Newsletter"
    And I choose the radio button "Delivery"
    And I select "Austria" in the field "Country"
    And I click the button "Send"
    Then there should be the text "Sent: Jane, newsletter yes, delivery, Austria" in "[data-testid=form-result]"

  Scenario: field states
    Then the field "Notes" should have the value "prefilled"
    And the checkbox "Terms accepted" should be checked
    And the checkbox "Newsletter" should not be checked
    And the field "Country" should have "Germany" selected
    When I fill "Jane" into the field "Name"
    And I uncheck the checkbox "Terms accepted"
    And I select "Austria" in the field "Country"
    Then the field "Name" should have the value "Jane"
    And the checkbox "Terms accepted" should not be checked
    And the field "Country" should have "Austria" selected

  Scenario: uploading a file next to the feature file
    When I upload the file "hello.txt" to the field "Attachment"
    Then there should be the text "Uploaded hello.txt: Hello upload" in "[data-testid=upload-result]"

  Scenario: pressing a key in the focused field
    When I fill "Jane" into the field "Name"
    And I press the key "Enter"
    Then there should be the text "Enter pressed" in "[data-testid=key-result]"

  Scenario: clicking an element by test id
    Then the element with test id "details" should be hidden
    When I click the element with test id "details-toggle"
    Then the element with test id "details" should be visible

  Scenario: following a link
    When I click the link "Next page"
    Then the URI path should be "/nested"
