<?php

declare(strict_types=1);

namespace Sandstorm\E2ETestTools\Service;

use Neos\Eel\FlowQuery\FlowQuery;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Domain\Exception;
use Neos\Neos\Domain\Repository\SiteRepository;
use Neos\Neos\Domain\Service\ContentContextFactory;

class NodeExportService
{
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
     * @throws Exception
     * @throws \Neos\Eel\Exception
     */
    function getNeosNodeFromIdentifier(string $nodeIdentifier)
    {
        $site = $this->siteRepository->findDefault();
        $contentContext = $this->contentContextFactory->create([
            'currentSite' => $site
        ]);
        $siteNode = $contentContext->getCurrentSiteNode();
        $query = new FlowQuery([$siteNode]);
        return $query->find('[where identifier = '.$nodeIdentifier.']')->get();
    }
}
