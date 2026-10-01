<?php

declare(strict_types=1);

namespace Sandstorm\E2ETestTools\Command;

use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAddress;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\CommandController;
use Sandstorm\E2ETestTools\Fixture\NodeFixtureGherkin;
use Sandstorm\E2ETestTools\Fixture\NodeFixtureYaml;
use Sandstorm\E2ETestTools\Service\NodeExportService;

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
        $fixture = $this->nodeExportService->exportNodeTree(NodeAddress::create(
            ContentRepositoryId::fromString($contentRepository),
            WorkspaceName::fromString($workspace),
            DimensionSpacePoint::fromJsonString($dimension),
            NodeAggregateId::fromString($node),
        ));

        $this->output($format === 'yaml' ? NodeFixtureYaml::dump($fixture) : NodeFixtureGherkin::steps($fixture, $siteName));
    }
}
