<?php

namespace Sandstorm\E2ETestTools\Tests\Behavior\Bootstrap;

use Behat\Gherkin\Node\TableNode;
use Sandstorm\E2ETestTools\Service\NodeImportService;

/**
 */
trait NodeImportTrait
{
    /**
     * =======================================================================
     * ========================= Utility methods =============================
     * =======================================================================
     */

    /**
     * Get absolute fixture file path from to test file relative path
     *
     * @param string $relativeFilePath relative file path to test file
     * @return string absolute fixture file path
     */
    private function getAbsoluteFixturePathFromFileName(string $relativeFilePath): string
    {
        $pwdParts = explode("/", $this->getCurrentTestFilePath());
        $pwdParts[count($pwdParts) - 1] = $relativeFilePath;
        return implode("/", $pwdParts);
    }

    /**
     * =======================================================================
     * =========================== Test steps ================================
     * =======================================================================
     */

    /**
     * @Given I have the following nodes from file :fileName
     * @When I create the following nodes from file :fileName
     */
    public function iHaveTheFollowingNodesFromFile(string $fileName): void
    {
        $yamlFilePath = $this->getAbsoluteFixturePathFromFileName($fileName);
        $yaml = NodeImportService::parseYamlFile($yamlFilePath);
        $table = NodeImportService::createTableNodeFromYamlArray($yaml);
        $this->iHaveTheFollowingNodes($table);
    }

    /**
     * @When /^I overwrite node properties with following values:$/
     */
    public function iOverwriteNodePropertiesWithFollowingValues(TableNode $table): void
    {
        $rows = $table->getHash();
        foreach ($rows as $row) {
            $identifier = $row['Identifier'] ?? $row['identifier'];
            $property = $row['Property'] ?? $row['property'];
            $value = $row['Value'] ?? $row['value'];

        }
    }

    /**
     * @When /^I overwrite following alias with values:$/
     */
    public function iOverwriteFollowingAliasWithValues($fileName, $table): void
    {
    }

}
