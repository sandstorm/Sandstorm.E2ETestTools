<?php

declare(strict_types=1);

namespace Sandstorm\E2ETestTools\Controller;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\Controller\ActionController;
use Sandstorm\E2ETestTools\Service\NodeExportService;
use Symfony\Component\Yaml\Yaml;

/**
 * @Flow\Scope("singleton")
 */
class NodeExportController extends ActionController
{
    /**
     * @var NodeExportService
     * @Flow\Inject
     */
    protected NodeExportService $nodeExportService;

    /**
     */
    public function indexAction(string $identifier)
    {
        $this->view = null;

        $node = $this->nodeExportService->getNeosNodeFromIdentifier($identifier);
        $nodeTree = $this->nodeExportService->getNodeTreeArrayByNode($node);

        $yaml =  Yaml::dump($nodeTree, 9999, 4);
        $fileName = 'node-tree-' . date('Y-m-d_H-i-s') . '.yaml';

        header('Content-Disposition: attachment; filename="' . $fileName . '"');
        echo $yaml;
        // prevent default flow behaviour
        die();
    }
}
