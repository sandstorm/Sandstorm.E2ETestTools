<?php

namespace Sandstorm\E2ETestTools\Command;

use Neos\Flow\Cli\CommandController;

class E2ECommandController extends CommandController
{

    public function setupCommand(): void
    {
        $sitePackageKey = readline("Enter your site package key, e.g.: Your.SitePackageKey");
        $this->execute('./flow behat:setup');
        $this->execute('./flow behat:kickstart' . $sitePackageKey . ' http://127.0.0.1:8081');
        $this->execute('cp -n ./Packages/Application/Sandstorm.E2ETestTools/Tests/Behavior/Bootstrap/FeatureContext.php.default ./DistributionPackages/' . $sitePackageKey . '/Tests/Behavior/Bootstrap/FeatureContext.php || echo "FeatureContext.php already exists."');
        $this->execute('sed -i "s/Site.Package.Key.Here/' . $sitePackageKey . '/g" ./DistributionPackages/' . $sitePackageKey . '/Tests/Behavior/Bootstrap/FeatureContext.php');
        $this->execute('cp -n ./Packages/Application/Sandstorm.E2ETestTools/Tests/Behavior/behat.yml ./DistributionPackages/' . $sitePackageKey . '/Tests/Behavior/behat.yml || echo "behat.yml already exists."');
        $this->execute('cd e2e-testrunner && npm install && node index.js');
        $this->execute('./flow e2e:fix');
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
        $this->execute('FLOW_CONTEXT=Testing/Behat ./bin/behat -c ./DistributionPackages/' . $sitePackageKey . '/Tests/Behavior/behat.yml.dist ' . $path . ' -vvv');
    }

    /**
     * Runs commands that have often lead to issues running e2e tests
     */
    public function fixCommand(): void
    {
        $this->execute('cd ./Build/Behat && composer install');
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
