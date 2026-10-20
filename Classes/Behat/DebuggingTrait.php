<?php

declare(strict_types=1);

namespace Sandstorm\E2ETestTools\Behat;

use Behat\Step\When;

/**
 * Steps for debugging a scenario while writing it. The logs and screenshots of failed scenarios come from
 * {@see PlaywrightTrait} without this trait.
 *
 * Needs {@see PlaywrightTrait}. Opt-in: use it in your FeatureContext; remove a project step with the same wording
 * first, otherwise Behat reports it as ambiguous.
 */
trait DebuggingTrait
{
    /**
     * Opens the Playwright inspector and stops until you resume there - the site under test and its database keep the
     * scenario's state meanwhile. Needs a bridge with a visible browser (HEADLESS=false); the request to the bridge
     * waits without timeout until you resume.
     */
    #[When('I pause for debugging')]
    public function iPauseForDebugging(): void
    {
        // a headless browser ignores page.pause() - the step would pass without stopping
        if ($this->playwrightConnector->isBridgeHeadless() === true) {
            throw new \RuntimeException('"I pause for debugging" needs a visible browser - start the playwright-bridge with HEADLESS=false.', 1728120002);
        }
        $this->playwrightConnector->execute($this->requirePlaywrightContext(), 'await vars.page.pause();', 0);
    }
}
