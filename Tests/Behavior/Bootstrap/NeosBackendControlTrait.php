<?php

namespace Sandstorm\E2ETestTools\Tests\Behavior\Bootstrap;

use Neos\Flow\ObjectManagement\ObjectManagerInterface;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Neos\Domain\Service\UserService;
use PHPUnit\Framework\Assert;

trait NeosBackendControlTrait
{
    abstract public function getObjectManager(): ObjectManagerInterface;

    /**
     * @Given I have a Neos backend user :username with password :password and role :role
     */
    public function iHaveANeosBackendUserWithPasswordAndRole(string $username, string $password, string $role)
    {
        $this->getObjectManager()->get(UserService::class)->createUser($username, $password, 'Test', 'Test', [$role]);
        $this->getObjectManager()->get(PersistenceManagerInterface::class)->persistAll();
    }

    /**
     * @When I log into the backend using credentials :username :password
     */
    public function iLogIntoTheBackendUsingCredentials(string $username, string $password)
    {
        $this->playwrightConnector->execute($this->playwrightContext, sprintf('
            vars.page = await context.newPage();
            await vars.page.goto("BASEURL/neos/");
            
            await vars.page.fill("[placeholder=\"Username\"]", "%s");
            await vars.page.fill("[placeholder=\"Password\"]", "%s");
            await vars.page.click("button:has-text(\"Login\")");
            await vars.page.waitForNavigation();
        ', $username, $password));
    }

    /**
     * @When I click the main menu item :menuItem
     */
    public function iClickTheMainMenuItem($menuItem)
    {
        $this->playwrightConnector->execute($this->playwrightContext, sprintf('
            // open the main menu
            await vars.page.click(`[aria-label="Menu"]`);
            await vars.page.click(`button[role="button"]:has-text("%s")`);
        ', $menuItem));
    }

    /**
     * @When I click the overview dashboard tile :tileTitle
     */
    public function iClickTheDashboardOverviewTile($tileTitle)
    {
        $this->playwrightConnector->execute($this->playwrightContext, sprintf('
            await vars.page.click(`text=%s`);
        ', $tileTitle));
    }

    /**
     * @Then the URI path should be :uriPath
     */
    public function theUriPathShouldBe($uriPath)
    {
        $actual = $this->playwrightConnector->execute($this->playwrightContext,'
            return vars.page.evaluate(() => window.location.pathname);
        ');
        Assert::assertEquals(rtrim($uriPath, '/'), rtrim($actual, '/'));
    }

    /**
     * @Then there should be the text :expected on the page
     */
    public function thereShouldBeTheTextOnThePage($expected)
    {
        $this->playwrightConnector->execute($this->playwrightContext, sprintf('
            await vars.page.textContent(`text=%s`);
        ', $expected));
    }

    /**
     * @When I access the URI path :uriPath
     */
    public function iAccess($uriPath)
    {
        $this->playwrightConnector->execute($this->playwrightContext, sprintf('
            vars.page = await context.newPage();
            await vars.page.goto("BASEURL%s");
        ', $uriPath));
    }
}
