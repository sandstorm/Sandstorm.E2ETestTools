<?php

declare(strict_types=1);

namespace Sandstorm\E2ETestTools\Command;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\CommandController;
use Sandstorm\E2ETestTools\Fixture\NodeFixtureRow;
use Sandstorm\E2ETestTools\Fixture\NodeFixtureYaml;
use Sandstorm\E2ETestTools\Fixture\ReferenceFixtureRow;
use Sandstorm\E2ETestTools\Service\NodeExportService;
use Sandstorm\E2ETestTools\StepGenerator\GherkinTable;

class E2eFixtureCommandController extends CommandController
{
    /**
     * @Flow\Inject
     * @var NodeExportService
     */
    protected $nodeExportService;

    /**
     * Export existing content as node fixture
     *
     * Same as the "Export Node" button in the Neos inspector: exports the node's closest document with all its
     * ancestors and descendants, plus references between them. Asset properties are exported as asset ids - create
     * those assets in your scenario separately (e.g. "I have the following images:").
     *
     * Examples:
     *   ./flow e2efixture:export 5cb3a5f7-b501-40b2-b5a8-9de169ef1105 --dimension '{"language":"de"}' > homepage.yaml
     *   ./flow e2efixture:export 5cb3a5f7-b501-40b2-b5a8-9de169ef1105 --dimension '{"language":"de"}' --format gherkin --site-name site
     *
     * @param string $node NodeAggregateId of the node to export
     * @param string $workspace workspace to read from
     * @param string $dimension dimension space point as JSON, e.g. {"language":"de"}
     * @param string $format "yaml" (for "I have the following nodes from file ...") or "gherkin" (inline steps)
     * @param string $siteName site node name used in the gherkin steps ("... in site <siteName>")
     * @param string $contentRepository content repository id
     */
    public function exportCommand(
        string $node,
        string $workspace = 'live',
        string $dimension = '{}',
        string $format = 'yaml',
        string $siteName = 'site',
        string $contentRepository = 'default'
    ): void {
        if (!in_array($format, ['yaml', 'gherkin'], true)) {
            $this->outputLine('<error>Unknown format "%s" - use "yaml" or "gherkin".</error>', [$format]);
            $this->quit(1);
        }
        $export = $this->nodeExportService->exportNodeTree(
            $this->nodeExportService->nodeAddress($node, $workspace, $dimension, $contentRepository)
        );

        $this->output($format === 'yaml'
            ? NodeFixtureYaml::dump($export['nodes'], $export['references'])
            : $this->gherkin($export['nodes'], $export['references'], $siteName));
    }

    /**
     * @param list<NodeFixtureRow> $nodes
     * @param list<ReferenceFixtureRow> $references
     */
    private function gherkin(array $nodes, array $references, string $siteName): string
    {
        $nodeTable = new GherkinTable(['NodeAggregateId', 'Parent', 'NodeType', 'Properties', 'DimensionSpacePoint']);
        foreach ($nodes as $row) {
            $nodeTable->addRow($row->toTableCells());
        }
        $output = sprintf('Given I have the following nodes in site "%s":', $siteName) . "\n" . $nodeTable->toString();

        if ($references !== []) {
            $referenceTable = new GherkinTable(['NodeAggregateId', 'ReferenceName', 'Targets', 'DimensionSpacePoint']);
            foreach ($references as $row) {
                $referenceTable->addRow($row->toTableCells());
            }
            $output .= "And the following node references:\n" . $referenceTable->toString();
        }
        return $output;
    }
}
