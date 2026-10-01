<?php

declare(strict_types=1);

namespace Sandstorm\E2ETestTools\Controller;

use GuzzleHttp\Psr7\Response;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAddress;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\Controller\ActionController;
use Sandstorm\E2ETestTools\Fixture\NodeFixtureYaml;
use Psr\Http\Message\ResponseInterface;
use Sandstorm\E2ETestTools\Service\NodeExportService;
use Sandstorm\E2ETestTools\Service\NodeNotFoundException;

/**
 * Backend of the "Export Node" inspector button: downloads the node tree as YAML node fixture.
 * Only for backend users, see Configuration/Policy.yaml.
 *
 * @Flow\Scope("singleton")
 */
class NodeExportController extends ActionController
{
    /**
     * @Flow\Inject
     * @var NodeExportService
     */
    protected $nodeExportService;

    /**
     * Listens to route `api/export-node?node=<node address>`
     *
     * @param string $node serialized NodeAddress (the Neos UI's contextPath) - workspace + dimension + node
     */
    public function indexAction(string $node): ResponseInterface
    {
        try {
            $nodeAddress = NodeAddress::fromJsonString($node);
        } catch (\InvalidArgumentException $e) {
            return new Response(400, ['Content-Type' => 'text/plain'], 'Invalid node address: ' . $e->getMessage());
        }
        try {
            $export = $this->nodeExportService->exportNodeTree($nodeAddress);
        } catch (NodeNotFoundException $e) {
            return new Response(404, ['Content-Type' => 'text/plain'], $e->getMessage());
        }

        return new Response(
            200,
            [
                'Content-Type' => 'application/x-yaml',
                'Content-Disposition' => sprintf('attachment; filename="node-tree-%s.yaml"', date('Y-m-d_H-i-s')),
            ],
            NodeFixtureYaml::dump($export['nodes'], $export['references'])
        );
    }
}
