<?php

namespace Sandstorm\E2ETestTools\Tests\Behavior\Bootstrap;

use Behat\Gherkin\Node\TableNode;
use Sandstorm\E2ETestTools\DTO\NodePropertyOverwriteDto;
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
        $this->createNodesFromYaml($fileName);
    }

    /**
     * @Given I have the following nodes from file :fileName with overwrites
     * @When I create the following nodes from file :fileName with overwrites
     */
    public function iHaveTheFollowingNodesFromFileWithOverwrites(string $fileName, TableNode $overwriteTable): void
    {
        $this->createNodesFromYaml($fileName, $overwriteTable);
    }

    /**
     * @param string $fileName - yaml file with node tree, downloadable from neos backend via export button
     * @param ?TableNode $overwriteTable - optional gherkin table to overwrite node properties
     */
    private function createNodesFromYaml(string $fileName, ?TableNode $overwriteTable = null): void
    {
        $yamlFilePath = $this->getAbsoluteFixturePathFromFileName($fileName);
        $yaml = NodeImportService::parseYamlFile($yamlFilePath);
        $overwrites = [];
        if ($overwriteTable !== null) {
            foreach ($overwriteTable->getHash() as $row) {
                $propertyOverwrite = [$row['property'] => $row['value']];
                $overwrites[$row['identifier']] =
                    isset($overwrites[$row['identifier']])
                        ? array_merge($overwrites[$row['identifier']], $propertyOverwrite)
                        : $propertyOverwrite;
            }
        }
        $table = NodeImportService::createTableNodeFromYamlArray($yaml, $overwrites);
        $this->iHaveTheFollowingNodes($table);
    }
}
