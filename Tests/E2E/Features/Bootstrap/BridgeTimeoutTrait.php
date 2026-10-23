<?php

declare(strict_types=1);

use Behat\Step\Then;
use PHPUnit\Framework\Assert;

/**
 * Only for testing this package: the timeouts of the playwright-bridge and of the requests to it.
 */
trait BridgeTimeoutTrait
{
    /**
     * Takes 15 seconds: a click on a missing element in a context the script opens itself.
     */
    #[Then('a context a script opens itself should time out after :milliseconds ms')]
    public function aContextAScriptOpensItselfShouldTimeOutAfter(int $milliseconds): void
    {
        $message = $this->playwrightRun(<<<'JS'
            const ownContext = await browser.newContext();
            try {
                const page = await ownContext.newPage();
                await page.setContent('<p>No button here</p>');
                await page.getByRole('button').click();
                return 'clicked';
            } catch (error) {
                return error.message;
            } finally {
                await ownContext.close();
            }
        JS);
        Assert::assertStringContainsString(sprintf('Timeout %dms exceeded', $milliseconds), self::playwrightString($message));
    }

    #[Then('a script waiting :seconds s with a request limit of :limit s should fail with :message')]
    public function aScriptWaitingWithARequestLimitOfShouldFailWith(int $seconds, int $limit, string $message): void
    {
        try {
            $this->playwrightConnector->execute(
                $this->requirePlaywrightContext(),
                sprintf('await new Promise((resolve) => setTimeout(resolve, %d));', $seconds * 1000),
                $limit
            );
        } catch (\RuntimeException $error) {
            Assert::assertStringContainsString($message, $error->getMessage());
            return;
        }
        Assert::fail('The script passed, but should hit the request limit.');
    }
}
