<?php

declare(strict_types=1);

namespace Sandstorm\E2ETestTools\Behat;

use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use Neos\Flow\ObjectManagement\ObjectManagerInterface;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Neos\Domain\Service\UserService;

/**
 * Neos backend: users, login, main menu, dashboard and document tree. Page visits and their status are in
 * {@see NavigationTrait}.
 *
 * Needs {@see PlaywrightTrait}.
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
        $this->playwrightRun(
            // language=JavaScript
            '
            vars.page = await context.newPage();
            await vars.page.goto("BASEURL/neos/");

            await vars.page.getByPlaceholder(%s, {exact: true}).fill(%s);
            await vars.page.getByPlaceholder(%s, {exact: true}).fill(%s);
            await vars.page.getByRole("button", {name: "Login"}).click();
            // a failed login stays on the login page
            await vars.page.waitForURL((url) => !url.pathname.startsWith("/neos/login"), {timeout: 10000});
            ',
            $usernamePlaceholder,
            $username,
            $passwordPlaceholder,
            $password
        );
    }

    #[When('I click the main menu item :menuItem')]
    public function iClickTheMainMenuItem(string $menuItem): void
    {
        $this->playwrightRun(
            // language=JavaScript
            '
            // open the main menu (Neos 9 UI), then follow the module link
            await vars.page.locator("#neos-MenuToggler").click();
            await vars.page.locator(`[class*="drawer"]`).getByRole("link", {name: %s, exact: true}).first().click();
            ',
            $menuItem
        );
    }

    #[When('I click the overview dashboard tile :tileTitle')]
    public function iClickTheDashboardOverviewTile(string $tileTitle): void
    {
        $this->playwrightRun('await vars.page.getByText(%s).first().click();', $tileTitle);
    }

    #[When('I click the document tree entry :documentTitle')]
    public function iClickTheDocumentTreeEntry(string $documentTitle): void
    {
        $this->playwrightRun(
            // language=JavaScript
            '
            await vars.page.locator("body div[class*=leftSideBar__top]").getByRole("button", {name: %s}).click();
            await vars.page.locator("div#neos-Inspector").waitFor();
            // page.frame() does not wait - the frame of the content canvas exists once its iframe element does
            await vars.page.locator("iframe[name=neos-content-main]").waitFor({state: "attached"});
            vars.neosContentFrame = vars.page.frame("neos-content-main");
            ',
            $documentTitle
        );
    }
}
