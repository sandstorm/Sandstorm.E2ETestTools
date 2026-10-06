<?php

declare(strict_types=1);

namespace Sandstorm\E2ETestTools\Behat;

use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use Neos\Flow\ObjectManagement\ObjectManagerInterface;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Neos\Domain\Service\UserService;
use PHPUnit\Framework\Assert;
use Sandstorm\E2ETestTools\Playwright\JsValue;

/**
 * Neos backend: users, login, main menu, dashboard and document tree - plus page visits and their status.
 */
trait NeosBackendControlTrait
{
    abstract public function getObjectManager(): ObjectManagerInterface;

    #[Given('I have a Neos backend user :username with password :password and role :role')]
    public function iHaveANeosBackendUserWithPasswordAndRole(string $username, string $password, string $role): void
    {
        $this->getObjectManager()->get(UserService::class)->createUser($username, $password, 'Test', 'Test', [$role]);
        $this->getObjectManager()->get(PersistenceManagerInterface::class)->persistAll();
    }

    /**
     * Fills the login form by the placeholders of its fields - pass them for a non-English backend.
     */
    #[When('I log into the backend using credentials :username :password')]
    #[When('I log into the backend using credentials :username :password with username placeholder :usernamePlaceholder and password placeholder :passwordPlaceholder')]
    public function iLogIntoTheBackendUsingCredentials(
        string $username,
        string $password,
        string $usernamePlaceholder = 'Username',
        string $passwordPlaceholder = 'Password'
    ): void {
        $this->playwrightConnector->execute($this->requirePlaywrightContext(), sprintf(
            // language=JavaScript
            '
            vars.page = await context.newPage();
            await vars.page.goto("BASEURL/neos/");

            await vars.page.getByPlaceholder(%s, {exact: true}).fill(%s);
            await vars.page.getByPlaceholder(%s, {exact: true}).fill(%s);
            await vars.page.click(`button:has-text("Login")`);
            // a failed login stays on the login page
            await vars.page.waitForURL((url) => !url.pathname.startsWith("/neos/login"), {timeout: 10000});
            ',
            JsValue::of($usernamePlaceholder),
            JsValue::of($username),
            JsValue::of($passwordPlaceholder),
            JsValue::of($password)
        ));
    }

    #[When('I click the main menu item :menuItem')]
    public function iClickTheMainMenuItem(string $menuItem): void
    {
        $this->playwrightConnector->execute($this->requirePlaywrightContext(), sprintf(
            // language=JavaScript
            '
            // open the main menu (Neos 9 UI), then follow the module link
            await vars.page.click(`#neos-MenuToggler`);
            await vars.page.locator(`[class*="drawer"]`).getByRole("link", {name: %s, exact: true}).first().click();
            ',
            JsValue::of($menuItem)
        ));
    }

    #[When('I click the overview dashboard tile :tileTitle')]
    public function iClickTheDashboardOverviewTile(string $tileTitle): void
    {
        $this->playwrightConnector->execute($this->requirePlaywrightContext(), sprintf(
            'await vars.page.getByText(%s).first().click();',
            JsValue::of($tileTitle)
        ));
    }

    #[When('I click the document tree entry :documentTitle')]
    public function iClickTheDocumentTreeEntry(string $documentTitle): void
    {
        $this->playwrightConnector->execute($this->requirePlaywrightContext(), sprintf(
            // language=JavaScript
            '
            await vars.page.locator("body div[class*=leftSideBar__top]").getByRole("button", {name: %s}).click();
            await vars.page.waitForSelector(`div#neos-Inspector`);
            vars.neosContentFrame = await vars.page.frame(`neos-content-main`);
            ',
            JsValue::of($documentTitle)
        ));
    }

    /**
     * Waits for the URL first - after a click or redirect the navigation may still be running.
     */
    #[Then('the URI path should be :uriPath')]
    public function theUriPathShouldBe(string $uriPath): void
    {
        $actual = $this->playwrightConnector->execute($this->requirePlaywrightContext(), sprintf('
            const expected = %s;
            await vars.page.waitForURL((url) => url.pathname.replace(/\\/$/, "") === expected, {timeout: 5000}).catch(() => {});
            return vars.page.evaluate(() => window.location.pathname);
        ', JsValue::of(rtrim($uriPath, '/'))));
        Assert::assertEquals(rtrim($uriPath, '/'), rtrim($actual, '/'));
    }

    /**
     * Waits until the text is on the page (case-insensitive, part of an element's text).
     */
    #[Then('there should be the text :expected on the page')]
    public function thereShouldBeTheTextOnThePage(string $expected): void
    {
        $this->playwrightConnector->execute($this->requirePlaywrightContext(), sprintf(
            'await vars.page.getByText(%s).first().waitFor({state: "attached"});',
            JsValue::of($expected)
        ));
    }

    #[When('I access the URI path :uriPath')]
    public function iAccess(string $uriPath): void
    {
        $this->playwrightConnector->execute($this->requirePlaywrightContext(), sprintf('
            vars.page = await context.newPage();
            vars.response = await vars.page.goto("BASEURL" + %s);
        ', JsValue::of($uriPath)));
    }

    #[Then('the response status code should be :status')]
    public function theResponseStatusCodeShouldBe(string $status): void
    {
        $actualStatusCode = $this->playwrightConnector->execute(
            $this->requirePlaywrightContext(),
            'return vars.response.status();'
        );
        Assert::assertEquals($status, $actualStatusCode, 'HTTP response status code mismatch');
    }
}
