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
use Sandstorm\E2ETestTools\Service\NodeNotFoundException;

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
     * those assets in your scenario separately (e.g. "I have the following images:"). Every row keeps its dimension space
     * point, so the export works in any scenario, with or without a default dimension space point.
     *
     * Select the node by NodeAggregateId or by the URI path of its page (one of both):
     *   ./flow e2efixture:export 5cb3a5f7-b501-40b2-b5a8-9de169ef1105 --dimension '{"language":"de"}' > homepage.yaml
     *   ./flow e2efixture:export --uri-path about/team --dimension '{"language":"de"}' > team.yaml
     *   ./flow e2efixture:export --uri-path / --dimension '{"language":"de"}' --format gherkin --site-name site
     *
     * @param string $node NodeAggregateId of the node to export
     * @param string $uriPath instead of a node: URI path of the page, without dimension prefix and suffix ("/" is the homepage)
     * @param string $sourceSite site node name the URI path belongs to - only needed with several sites
     * @param string $workspace workspace to read from
     * @param string $dimension dimension space point as JSON, e.g. {"language":"de"}
     * @param string $format "yaml" (for "I have the following nodes from file ...") or "gherkin" (inline steps)
     * @param string $siteName site node name used in the gherkin steps ("... in site <siteName>")
     * @param string $contentRepository content repository id
     */
    public function exportCommand(
        string $node = '',
        string $uriPath = '',
        string $sourceSite = '',
        string $workspace = 'live',
        string $dimension = '{}',
        string $format = 'yaml',
        string $siteName = 'site',
        string $contentRepository = 'default'
    ): void {
        // $node is optional (for --uri-path), so Flow doesn't map a positional NodeAggregateId to it anymore
        $node = $node !== '' ? $node : (string)($this->request->getExceedingArguments()[0] ?? '');
        if (($node === '') === ($uriPath === '')) {
            $this->outputLine('<error>Give either a NodeAggregateId or --uri-path.</error>');
            $this->quit(1);
        }
        if (!in_array($format, ['yaml', 'gherkin'], true)) {
            $this->outputLine('<error>Unknown format "%s" - use "yaml" or "gherkin".</error>', [$format]);
            $this->quit(1);
        }
        $contentRepositoryId = ContentRepositoryId::fromString($contentRepository);
        $workspaceName = WorkspaceName::fromString($workspace);
        $dimensionSpacePoint = DimensionSpacePoint::fromJsonString($dimension);
        try {
            $nodeAddress = $node !== ''
                ? NodeAddress::create($contentRepositoryId, $workspaceName, $dimensionSpacePoint, NodeAggregateId::fromString($node))
                : $this->nodeExportService->documentAddressByUriPath($contentRepositoryId, $workspaceName, $dimensionSpacePoint, $uriPath, $sourceSite !== '' ? $sourceSite : null);
            $fixture = $this->nodeExportService->exportNodeTree($nodeAddress);
        } catch (NodeNotFoundException $e) {
            $this->outputLine('<error>%s</error>', [$e->getMessage()]);
            $this->quit(1);
        }

        $this->output($format === 'yaml' ? NodeFixtureYaml::dump($fixture) : NodeFixtureGherkin::steps($fixture, $siteName));
    }
}
