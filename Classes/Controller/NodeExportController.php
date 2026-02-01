<?php

declare(strict_types=1);

namespace Sandstorm\E2ETestTools\Controller;

use Neos\ContentRepository\Domain\Service\ImportExport\NodeExportService;
use Neos\Eel\FlowQuery\FlowQuery;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\Controller\ActionController;
use Neos\Flow\Mvc\View\JsonView;
use Neos\Neos\Domain\Repository\SiteRepository;
use Neos\Neos\Domain\Service\ContentContextFactory;

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
     * @Flow\Inject
     * @var SiteRepository
     */
    protected $siteRepository;

    /**
     * @Flow\Inject
     * @var ContentContextFactory
     */
    protected $contentContextFactory;

    /**
     * 1. Export Button redirects to route /api/export-node/{identifier} -> button component needs to know node identifier
     * 2. Controller gets node via identifier
     * 3. create yaml content from node
     * 4. send via response
     *
     */
    public function indexAction(string $identifier)
    {
        $this->response->setContentType('application/json');

        $site = $this->siteRepository->findDefault();
        $contentContext = $this->contentContextFactory->create([
            'currentSite' => $site
        ]);
        $siteNode = $contentContext->getCurrentSiteNode();

        $node = (new FlowQuery([$siteNode]))
            ->find('#'.$identifier)
            ->get(0);

        $nodePath = $node->getContextPath();

        // \Neos\Flow\var_dump($nodePath, "1");

        return $this->nodeExportService->export($nodePath);
    }
}
