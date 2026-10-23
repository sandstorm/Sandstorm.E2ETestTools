<?php

declare(strict_types=1);

use Behat\Gherkin\Node\TableNode;
use Behat\Step\Given;

/**
 * Only for testing MailpitTrait: the test site sends no mails, so the scenario sends them itself - through Mailpit's
 * send API, which delivers them like mails that arrived via SMTP.
 */
trait MailFixtureTrait
{
    /**
     * Rows: From, Subject, Text, HTML, Reply-To, Attachment (a file name - the file has a short text as content),
     * Attachment type (also of the empty attachment), Empty attachment (a file name - no content); all optional. \n in
     * the HTML becomes a line break.
     */
    #[Given('a mail was sent to :recipient with:')]
    public function aMailWasSentToWith(string $recipient, TableNode $fields): void
    {
        $values = $fields->getRowsHash();
        $mail = [
            'From' => ['Email' => $values['From'] ?? 'site@example.com'],
            'To' => [['Email' => $recipient]],
            'Subject' => $values['Subject'] ?? '',
            'Text' => $values['Text'] ?? '',
        ];
        if (isset($values['HTML'])) {
            $mail['HTML'] = str_replace('\n', "\n", $values['HTML']);
        }
        if (isset($values['Reply-To'])) {
            $mail['ReplyTo'] = [['Email' => $values['Reply-To']]];
        }
        if (isset($values['Attachment'])) {
            $mail['Attachments'][] = ['Filename' => $values['Attachment'], 'Content' => base64_encode('attachment')]
                + (isset($values['Attachment type']) ? ['ContentType' => $values['Attachment type']] : []);
        }
        if (isset($values['Empty attachment'])) {
            $mail['Attachments'][] = ['Filename' => $values['Empty attachment'], 'Content' => '']
                + (isset($values['Attachment type']) ? ['ContentType' => $values['Attachment type']] : []);
        }

        $baseUrl = getenv('MAILPIT_API_URL');
        $url = ($baseUrl !== false && $baseUrl !== '' ? $baseUrl : 'http://mailpit:8025') . '/api/v1/send';
        $response = file_get_contents($url, false, stream_context_create(['http' => [
            'method' => 'POST',
            'header' => 'Content-Type: application/json',
            'content' => json_encode($mail, JSON_THROW_ON_ERROR),
            'timeout' => 5,
            'ignore_errors' => true,
        ]]));
        $status = $http_response_header[0] ?? '';
        if ($response === false || !str_contains($status, ' 200')) {
            throw new \RuntimeException(sprintf('Mailpit did not send the mail (%s): %s', $status, (string)$response));
        }
    }
}
