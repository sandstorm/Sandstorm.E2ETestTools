<?php

namespace Sandstorm\E2ETestTools\Command;

use Neos\Flow\Cli\CommandController;

class E2ECommandController extends CommandController
{
    public function setupCommand(): void
    {
        $sitePackageKey = readline("Enter your site package key, e.g.: Your.SitePackageKey \n");
        $this->execute('./flow e2e:fix');
        $this->execute('./flow behat:setup');
        $this->execute('./flow behat:kickstart ' . $sitePackageKey . ' http://127.0.0.1:8081');
    }

    /**
     * Run via `./flow e2e:test Your.SitePackageKey` to run e2e tests
     *
     * @param string $sitePackageKey e.g. Your.SitePackageKey
     * @param string $path either a test feature file path or empty to run all tests
     *  run with `--path value` to run specific file or scenario
     *  examples:
     *      undefined => run all e2e tests
     *      MyFile.feature => run test file
     *      MyFile.feature:123 => run test scenario
     */
    public function testCommand(string $sitePackageKey, string $path = ""): void
    {
        $this->execute('FLOW_CONTEXT=Production/E2E-SUT ./bin/behat -c ./DistributionPackages/' . $sitePackageKey . '/Tests/Behavior/behat.yml.dist ' . $path . ' -vvv');
    }

    /**
     * Runs commands that have often lead to issues running e2e tests
     */
    public function fixCommand(): void
    {
        $this->execute('cd ./Build/Behat && composer install');
        $this->execute("FLOW_CONTEXT=Production/E2E-SUT ./flow flow:doctrine:compileproxies");
    }

    /**
     * executes given CLI command and print output
     */
    private function execute(string $command): void
    {
        exec($command, $output);
        foreach ($output as $out) {
            print_r($out . "\n");
        }
    }
}
