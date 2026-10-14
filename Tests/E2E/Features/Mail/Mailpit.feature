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
