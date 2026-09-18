<?php

declare(strict_types=1);

namespace Sandstorm\E2ETestTools\Service;

use Doctrine\ORM\EntityManagerInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Domain\Repository\SiteRepository;

/**
 * NOTE: This service uses Neos 8 CR APIs and is not functional in Neos 9.
 * ContentContextFactory injection was removed — that class no longer exists in Neos 9.
 */
class NodeExportService
{
    /**
     * @Flow\Inject
     * @var SiteRepository
     */
    protected $siteRepository;

    /**
     * Doctrine's Entity Manager.
     *
     * @Flow\Inject
     * @var EntityManagerInterface
     */
    protected $entityManager;

    /**
     * @Flow\Inject
     * @var NodeToYamlConverter
     */
    protected $nodeToYamlConverter;

    public function getNeosNodeFromIdentifier(?string $identifier = null): mixed
    {
        throw new \RuntimeException('NodeExportService::getNeosNodeFromIdentifier() is not implemented for Neos 9. The Neos 8 ContentContextFactory no longer exists.', 1700000001);
    }

    public function getNodeTreeArrayByNode(mixed $node): array
    {
        throw new \RuntimeException('NodeExportService::getNodeTreeArrayByNode() is not implemented for Neos 9.', 1700000002);
    }
}
