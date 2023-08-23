<?php

namespace Sandstorm\E2ETestTools\Tests\Behavior\Bootstrap;

use Neos\Flow\ObjectManagement\ObjectManagerInterface;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Neos\Domain\Service\UserService;
use PHPUnit\Framework\Assert;
use function PHPUnit\Framework\assertEquals;

/**
 * This trait is only useful in NEOS applications; not in Symfony projects.
 */
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
        $this->playwrightConnector->execute($this->playwrightContext, sprintf(
            // language=JavaScript
            '
            vars.page = await context.newPage();
            await vars.page.goto("BASEURL/neos/");

            await vars.page.fill(`[placeholder="Username"]`, `%s`);
            await vars.page.fill(`[placeholder="Password"]`, `%s`);
            await vars.page.click(`button:has-text("Login")`);
            await vars.page.waitForNavigation();
        '// language=PHP
            , $username, $password));
    }

    /**
     * @When I click the main menu item :menuItem
     */
    public function iClickTheMainMenuItem($menuItem)
    {
        $this->playwrightConnector->execute($this->playwrightContext, sprintf(
        // language=JavaScript
            '
            // open the main menu
            await vars.page.click(`[aria-label="Menu"]`);
            await vars.page.click(`button[role="button"]:has-text("%s")`);
        '// language=PHP
            , $menuItem));
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
     * @When I click the document tree entry :documentTitle
     */
    public function iClickTheDocumentTreeEntry($documentTitle)
    {
        $this->playwrightConnector->execute($this->playwrightContext, sprintf(
        // language=JavaScript
            '
            await vars.page.locator("body div[class*=leftSideBar__top]").getByRole("button", {name: "%s"}).click();
            await vars.page.waitForSelector(`div#neos-Inspector`);
            vars.neosContentFrame = await vars.page.frame(`neos-content-main`);
        '// language=PHP
            , $documentTitle));
    }


    /**
     * @Then the URI path should be :uriPath
     */
    public function theUriPathShouldBe($uriPath)
    {
        $actual = $this->playwrightConnector->execute($this->playwrightContext, '
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
            vars.response = await vars.page.goto("BASEURL%s");
        ', $uriPath));
    }

    /**
     * @Then the response status code should be :status
     */
    public function theResponseStatusCodeShouldBe($status)
    {
        $actualStatusCode = $this->playwrightConnector->execute($this->playwrightContext, sprintf(
        // language=JavaScript
            '
                return vars.response.status();
        '));// language=PHP
        assertEquals($status, $actualStatusCode, 'HTTP response status code mismatch');
    }

}
