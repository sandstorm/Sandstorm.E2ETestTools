<?php

declare(strict_types=1);

namespace Sandstorm\E2ETestTools\Behat;

use Behat\Behat\Hook\Scope\BeforeScenarioScope;
use Behat\Hook\BeforeScenario;
use Behat\Step\Then;
use PHPUnit\Framework\Assert;

/**
 * Checks the mails the system under test sent, via the HTTP API of [Mailpit](https://mailpit.axllent.org) - configure
 * Mailpit as the SMTP server of the system under test.
 *
 * The API URL as seen from Behat: environment variable MAILPIT_API_URL, default http://mailpit:8025 (a docker compose
 * service "mailpit").
 *
 * Tag scenarios that send mails with the tag "mailpit": the inbox is emptied before them, so "exactly 1 mail" means
 * "this scenario sent one". (No at sign here - Flow would read the tag in this comment as an annotation.)
 *
 * Opt-in: use it in your FeatureContext; remove project steps with the same wording first, otherwise Behat reports them
 * as ambiguous.
 */
trait MailpitTrait
{
    #[BeforeScenario('@mailpit')]
    public function emptyTheMailpitInbox(BeforeScenarioScope $scope): void
    {
        $this->mailpit_request('DELETE', '/api/v1/messages');
    }

    /**
     * Waits up to 5 seconds for the mails - and fails with the mails that did arrive. "exactly 0 mails" always waits
     * the 5 seconds: a mail might still be on its way.
     */
    #[Then('exactly :count mail(s) should have been sent to :recipient')]
    public function exactlyMailsShouldHaveBeenSentTo(int $count, string $recipient): void
    {
        $mails = [];
        for ($i = 0; $i < 25; $i++) {
            $mails = $this->mailpit_mailsTo($recipient);
            if (count($mails) >= $count && $count > 0) {
                break;
            }
            usleep(200_000);
        }
        Assert::assertCount($count, $mails, sprintf('Unexpected number of mails to "%s". All mails: %s', $recipient, $this->mailpit_summary()));
    }

    #[Then('the mail to :recipient should have the subject :subject')]
    public function theMailToShouldHaveTheSubject(string $recipient, string $subject): void
    {
        Assert::assertSame($subject, $this->mailpit_mailTo($recipient)['Subject'] ?? null);
    }

    /**
     * Checks the plain text part of the mail.
     */
    #[Then('the mail to :recipient should contain :text')]
    public function theMailToShouldContain(string $recipient, string $text): void
    {
        $body = $this->mailpit_mailTo($recipient)['Text'] ?? '';
        Assert::assertStringContainsString($text, is_string($body) ? $body : '');
    }

    #[Then('the mail to :recipient should reply to :address')]
    public function theMailToShouldReplyTo(string $recipient, string $address): void
    {
        $replyTo = self::mailpit_values($this->mailpit_mailTo($recipient)['ReplyTo'] ?? [], 'Address');
        Assert::assertSame([$address], $replyTo, sprintf('Unexpected Reply-To of the mail to "%s".', $recipient));
    }

    #[Then('the mail to :recipient should have the attachment :fileName')]
    public function theMailToShouldHaveTheAttachment(string $recipient, string $fileName): void
    {
        $attachments = self::mailpit_values($this->mailpit_mailTo($recipient)['Attachments'] ?? [], 'FileName');
        Assert::assertContains($fileName, $attachments, sprintf('Attachments of the mail to "%s": %s', $recipient, $attachments === [] ? 'none' : implode(', ', $attachments)));
    }

    /**
     * The single mail to the recipient, with all details (Text, HTML, ReplyTo, Attachments, ...).
     *
     * @return array<mixed>
     */
    private function mailpit_mailTo(string $recipient): array
    {
        $mails = $this->mailpit_mailsTo($recipient);
        Assert::assertCount(1, $mails, sprintf('Expected exactly one mail to "%s". All mails: %s', $recipient, $this->mailpit_summary()));
        $id = $mails[0]['ID'] ?? '';
        return $this->mailpit_request('GET', '/api/v1/message/' . (is_string($id) ? $id : ''));
    }

    /**
     * @return list<array<mixed>>
     */
    private function mailpit_mailsTo(string $recipient): array
    {
        return array_values(array_filter(
            $this->mailpit_messages(),
            static fn (array $message) => in_array($recipient, self::mailpit_values($message['To'] ?? [], 'Address'), true)
        ));
    }

    private function mailpit_summary(): string
    {
        $messages = $this->mailpit_messages();
        if ($messages === []) {
            return 'none';
        }
        return implode('; ', array_map(static fn (array $message) => sprintf(
            '"%s" to %s',
            is_string($message['Subject'] ?? null) ? $message['Subject'] : '',
            implode(', ', self::mailpit_values($message['To'] ?? [], 'Address'))
        ), $messages));
    }

    /**
     * @return list<array<mixed>>
     */
    private function mailpit_messages(): array
    {
        $messages = $this->mailpit_request('GET', '/api/v1/messages')['messages'] ?? [];
        return is_array($messages) ? array_values(array_filter($messages, is_array(...))) : [];
    }

    /**
     * One field of each entry of a Mailpit list, e.g. the addresses of `To` or the file names of `Attachments`.
     *
     * @return list<string>
     */
    private static function mailpit_values(mixed $entries, string $field): array
    {
        if (!is_array($entries)) {
            return [];
        }
        $values = [];
        foreach ($entries as $entry) {
            if (is_array($entry) && is_string($entry[$field] ?? null)) {
                $values[] = $entry[$field];
            }
        }
        return $values;
    }

    /**
     * @return array<mixed>
     */
    private function mailpit_request(string $method, string $path): array
    {
        $baseUrl = getenv('MAILPIT_API_URL');
        $url = ($baseUrl !== false && $baseUrl !== '' ? $baseUrl : 'http://mailpit:8025') . $path;
        $response = file_get_contents($url, false, stream_context_create(['http' => [
            'method' => $method,
            'timeout' => 5,
            'ignore_errors' => true,
        ]]));
        if ($response === false) {
            throw new \RuntimeException(sprintf('Mailpit is not reachable at %s - is the mailpit service running?', $url));
        }
        $data = json_decode($response, true);
        return is_array($data) ? $data : [];
    }
}
