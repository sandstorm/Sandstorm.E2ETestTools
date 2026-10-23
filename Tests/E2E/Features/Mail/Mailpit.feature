@mailpit
Feature: Mails in Mailpit

  The test site sends no mails - the scenarios send them through Mailpit's send API (MailFixtureTrait) and check them
  with the steps of MailpitTrait.

  Scenario: a mail with all details
    Given a mail was sent to "visitor@example.com" with:
      | Subject    | Your request                      |
      | Text       | Thank you, we will call you back. |
      | Reply-To   | office@example.com                |
      | Attachment | request.pdf                       |
    Then exactly 1 mail should have been sent to "visitor@example.com"
    And exactly 0 mails should have been sent to "office@example.com"
    And the mail to "visitor@example.com" should have the subject "Your request"
    And the mail to "visitor@example.com" should contain "we will call you back"
    And the mail to "visitor@example.com" should reply to "office@example.com"
    And the mail to "visitor@example.com" should have the attachment "request.pdf"

  Scenario: sender, attachments
    Given a mail was sent to "visitor@example.com" with:
      | From            | shop@example.com |
      | Subject         | Your receipt     |
      | Text            | Receipt attached |
      | Attachment      | receipt.pdf      |
      | Attachment type | application/pdf  |
    And a mail was sent to "other@example.com" with:
      | Subject | No files |
      | Text    | Nothing  |
    Then the mail to "visitor@example.com" should be from "shop@example.com"
    And the mail to "visitor@example.com" should have the attachment "receipt.pdf" of type "application/pdf"
    And the mail to "other@example.com" should have no attachments
    And the step 'the mail to "visitor@example.com" should have no attachments' should fail with "receipt.pdf"
    And the step 'the mail to "visitor@example.com" should have the attachment "receipt.pdf" of type "text/plain"' should fail with 'Unexpected type of the attachment "receipt.pdf"'
    And the step 'the mail to "visitor@example.com" should be from "site@example.com"' should fail with "Unexpected sender"

  Scenario: an empty attachment
    Given a mail was sent to "visitor@example.com" with:
      | Text             | Receipt attached |
      | Empty attachment | receipt.pdf      |
      | Attachment type  | application/pdf  |
    Then the step 'the mail to "visitor@example.com" should have the attachment "receipt.pdf" of type "application/pdf"' should fail with 'The attachment "receipt.pdf" is empty.'

  Scenario: text in the text and the HTML part
    # table cells separate words, inline elements and source line breaks don't matter, entities are decoded
    Given a mail was sent to "visitor@example.com" with:
      | Text | Your order 4711 - Grüße, Bestellnummer: 4711 |
      | HTML | <html><head><style>.secret { color: red }</style></head><body><table><tr><td>Your order</td><td>4711</td></tr></table><p>Gr&uuml;&szlig;e,\n        Bestell<b>nummer</b>:&nbsp;4711</p></body></html> |
    Then the mail to "visitor@example.com" should contain "Your order 4711"
    And the mail to "visitor@example.com" should contain "Grüße, Bestellnummer: 4711"
    And the mail to "visitor@example.com" should not contain "secret"
    And the mail to "visitor@example.com" should not contain "{orderNumber}"
    And the step 'the mail to "visitor@example.com" should contain "Thank you"' should fail with '"Thank you" is missing in the text part and the HTML part'

  Scenario: a text only in one part
    Given a mail was sent to "visitor@example.com" with:
      | Text | Your order 4711, thank you |
      | HTML | <p>Your order 4711</p>     |
    Then the step 'the mail to "visitor@example.com" should contain "thank you"' should fail with '"thank you" is missing in the HTML part'
    And the step 'the mail to "visitor@example.com" should not contain "thank you"' should fail with '"thank you" is in the text part'

  Scenario: several mails to one recipient
    Given a mail was sent to "visitor@example.com" with:
      | Subject | First |
    And a mail was sent to "visitor@example.com" with:
      | Subject | Second |
    Then exactly 2 mails should have been sent to "visitor@example.com"

  # runs after the scenarios above: the inbox is emptied before each scenario
  Scenario: the inbox is empty at the start of a scenario
    Then exactly 0 mails should have been sent to "visitor@example.com"
    And the step 'the mail to "visitor@example.com" should have the subject "Your request"' should fail with 'Expected exactly one mail to "visitor@example.com". All mails: none'
