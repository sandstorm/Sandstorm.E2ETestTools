<?php

declare(strict_types=1);

namespace Sandstorm\E2ETestTools\Behat;

use Neos\Flow\ObjectManagement\ObjectManagerInterface;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Neos\Domain\Service\UserService;
use PHPUnit\Framework\Assert;
use Sandstorm\E2ETestTools\Playwright\JsValue;
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
     * @When I log into the backend using credentials :username :password with username placeholder :usernamePlaceholder and password placeholder :passwordPlaceholder
     */
    public function iLogIntoTheBackendUsingCredentials(
        string $username,
        string $password,
        string $usernamePlaceholder = 'Username',
        string $passwordPlaceholder = 'Password'
    ) {
        $this->playwrightConnector->execute($this->playwrightContext, sprintf(
            // language=JavaScript
            '
            vars.page = await context.newPage();
            await vars.page.goto("BASEURL/neos/");

            await vars.page.fill(`[placeholder="%s"]`, `%s`);
            await vars.page.fill(`[placeholder="%s"]`, `%s`);
            await Promise.all([
                vars.page.waitForNavigation(),
                vars.page.click(`button:has-text("Login")`),
            ]);
        '// language=PHP
            , $usernamePlaceholder, $username, $passwordPlaceholder, $password));
    }

    /**
     * @When I click the main menu item :menuItem
     */
    public function iClickTheMainMenuItem($menuItem)
    {
        $this->playwrightConnector->execute($this->playwrightContext, sprintf(
        // language=JavaScript
            '
            // open the main menu (Neos 9 UI), then follow the module link
            await vars.page.click(`#neos-MenuToggler`);
            await vars.page.locator(`[class*="drawer"]`).getByRole("link", {name: %s, exact: true}).first().click();
        '// language=PHP
            , JsValue::of($menuItem)));
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
    public function theUriPathShouldBe(string $uriPath): void
    {
        // waits for the URL first - after a click or redirect the navigation may still be running
        $actual = $this->playwrightConnector->execute($this->playwrightContext, sprintf('
            const expected = %s;
            await vars.page.waitForURL((url) => url.pathname.replace(/\\/$/, "") === expected, {timeout: 5000}).catch(() => {});
            return vars.page.evaluate(() => window.location.pathname);
        ', JsValue::of(rtrim($uriPath, '/'))));
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
