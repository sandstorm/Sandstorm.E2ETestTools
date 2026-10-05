<?php

declare(strict_types=1);

namespace Sandstorm\E2ETestTools\Tests\Behavior\Bootstrap;

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
     * scenario's state meanwhile. Needs a bridge with a visible browser (HEADLESS=false) and Behat started with
     * PAUSE_FOR_DEBUGGING=true; otherwise the request to the bridge times out after 30 seconds.
     */
    #[When('I pause for debugging')]
    public function iPauseForDebugging(): void
    {
        if (getenv('PAUSE_FOR_DEBUGGING') !== 'true') {
            throw new \RuntimeException('"I pause for debugging" needs Behat started with PAUSE_FOR_DEBUGGING=true - otherwise the bridge request times out after 30 seconds.', 1728120002);
        }
        $this->playwrightConnector->execute($this->requirePlaywrightContext(), 'await vars.page.pause();');
    }
}
