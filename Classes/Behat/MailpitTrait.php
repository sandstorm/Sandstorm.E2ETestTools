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
     * Checks every part the mail has - the plain text and the HTML part, so the text is there whichever the mail client
     * shows. The HTML part is compared as visible text: without tags, entities decoded. Whitespace doesn't matter
     * (line breaks of long lines, indentation, table cells).
     */
    #[Then('the mail to :recipient should contain :text')]
    public function theMailToShouldContain(string $recipient, string $text): void
    {
        $parts = $this->mailpit_parts($recipient);
        $missing = array_keys(array_filter($parts, static fn (string $part) => !str_contains($part, self::mailpit_normalized($text))));
        Assert::assertSame([], $missing, sprintf(
            '"%s" is missing in the %s of the mail to "%s". %s',
            $text,
            implode(' and the ', $missing),
            $recipient,
            self::mailpit_partsSummary($parts)
        ));
    }

    /**
     * In none of the parts - see "should contain".
     */
    #[Then('the mail to :recipient should not contain :text')]
    public function theMailToShouldNotContain(string $recipient, string $text): void
    {
        $parts = $this->mailpit_parts($recipient);
        $found = array_keys(array_filter($parts, static fn (string $part) => str_contains($part, self::mailpit_normalized($text))));
        Assert::assertSame([], $found, sprintf(
            '"%s" is in the %s of the mail to "%s". %s',
            $text,
            implode(' and the ', $found),
            $recipient,
            self::mailpit_partsSummary($parts)
        ));
    }

    /**
     * The address only, not the display name.
     */
    #[Then('the mail to :recipient should be from :address')]
    public function theMailToShouldBeFrom(string $recipient, string $address): void
    {
        $from = $this->mailpit_mailTo($recipient)['From'] ?? [];
        Assert::assertSame($address, is_array($from) ? $from['Address'] ?? null : null, sprintf('Unexpected sender of the mail to "%s".', $recipient));
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

    #[Then('the mail to :recipient should have no attachments')]
    public function theMailToShouldHaveNoAttachments(string $recipient): void
    {
        $attachments = self::mailpit_values($this->mailpit_mailTo($recipient)['Attachments'] ?? [], 'FileName');
        Assert::assertSame([], $attachments, sprintf('Attachments of the mail to "%s": %s', $recipient, implode(', ', $attachments)));
    }

    /**
     * :contentType is the type the mail declares (e.g. application/pdf) - the file content isn't checked, except that
     * it isn't empty.
     */
    #[Then('the mail to :recipient should have the attachment :fileName of type :contentType')]
    public function theMailToShouldHaveTheAttachmentOfType(string $recipient, string $fileName, string $contentType): void
    {
        $attachments = $this->mailpit_mailTo($recipient)['Attachments'] ?? [];
        $attachment = null;
        foreach (is_array($attachments) ? $attachments : [] as $candidate) {
            if (is_array($candidate) && ($candidate['FileName'] ?? null) === $fileName) {
                $attachment = $candidate;
            }
        }
        $names = self::mailpit_values($attachments, 'FileName');
        Assert::assertNotNull($attachment, sprintf('Attachments of the mail to "%s": %s', $recipient, $names === [] ? 'none' : implode(', ', $names)));
        Assert::assertSame($contentType, $attachment['ContentType'] ?? null, sprintf('Unexpected type of the attachment "%s".', $fileName));
        Assert::assertGreaterThan(0, $attachment['Size'] ?? 0, sprintf('The attachment "%s" is empty.', $fileName));
    }

    /**
     * The parts the mail to the recipient has, as normalized text: "text part" and/or "HTML part" (as visible text).
     *
     * @return array<string, string>
     */
    private function mailpit_parts(string $recipient): array
    {
        $mail = $this->mailpit_mailTo($recipient);
        $parts = [];
        if (is_string($mail['Text'] ?? null) && trim($mail['Text']) !== '') {
            $parts['text part'] = self::mailpit_normalized($mail['Text']);
        }
        if (is_string($mail['HTML'] ?? null) && trim($mail['HTML']) !== '') {
            $parts['HTML part'] = self::mailpit_visibleText($mail['HTML']);
        }
        Assert::assertNotSame([], $parts, sprintf('The mail to "%s" has no text.', $recipient));
        return $parts;
    }

    /**
     * @param array<string, string> $parts
     */
    private static function mailpit_partsSummary(array $parts): string
    {
        return implode(' ', array_map(
            static fn (string $name, string $text) => sprintf('%s: "%s"', ucfirst($name), mb_strimwidth($text, 0, 300, '...')),
            array_keys($parts),
            $parts
        ));
    }

    /**
     * The text of an HTML mail as it's read: without head, styles and scripts; block elements (paragraphs, table cells,
     * line breaks, ...) separate words, inline elements (strong, a, span, ...) don't.
     */
    private static function mailpit_visibleText(string $html): string
    {
        $html = preg_replace('#<(head|style|script)\b.*?</\1\s*>#is', ' ', $html) ?? $html;
        $html = preg_replace('#</?(?:p|div|br|hr|td|th|tr|table|thead|tbody|tfoot|li|ul|ol|dl|dt|dd|h[1-6]|blockquote|section|article|header|footer|center)\b[^>]*>#i', ' ', $html) ?? $html;
        return self::mailpit_normalized(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * Whitespace (also non-breaking spaces) collapsed to single spaces.
     */
    private static function mailpit_normalized(string $text): string
    {
        return trim(preg_replace('/[\s\x{00A0}]+/u', ' ', $text) ?? $text);
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
