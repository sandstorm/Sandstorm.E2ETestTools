<?php

declare(strict_types=1);

namespace Sandstorm\E2ETestTools\StepGenerator;

use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Package\PackageManager;

/**
 * Public builder API to configure and create a NodeTable to use in your step generator command controller.
 */
class NodeTableBuilder
{
    private PackageManager $packageManager;
    private ContentRepositoryRegistry $contentRepositoryRegistry;
    private array $defaultPersistentResourceProperties = [];
    private array $defaultImageProperties = [];
    private ?string $fixtureBasePath = null;

    public function __construct(PackageManager $packageManager, ContentRepositoryRegistry $contentRepositoryRegistry)
    {
        $this->packageManager = $packageManager;
        $this->contentRepositoryRegistry = $contentRepositoryRegistry;
    }

    /**
     * Extra columns for every row of the "I have the following images:" table, e.g. ['Copyright Notice' => '...'].
     */
    public function withDefaultPersistentResourceProperties(array $defaultPersistentResourceProperties): NodeTableBuilder
    {
        $this->defaultPersistentResourceProperties = $defaultPersistentResourceProperties;
        return $this;
    }

    /**
     * Extra columns for every image row, e.g. ['Copyright Notice' => '...'].
     */
    public function withDefaultImageProperties(array $defaultImageProperties): NodeTableBuilder
    {
        $this->defaultImageProperties = $defaultImageProperties;
        return $this;
    }

    /**
     * Where image files used by the collected nodes are stored as fixtures, e.g.
     * ('Your.SitePackageKey', 'Tests/Behavior/Features/Homepage/Resources/'). Required as soon as a node uses an image.
     */
    public function withFixturesBaseDirectory(string $packageKey, string $subPath): NodeTableBuilder
    {
        $package = $this->packageManager->getPackage($packageKey);
        $this->fixtureBasePath = $package->getPackagePath() . $subPath;
        return $this;
    }

    /**
     * @param ContentSubgraphInterface $subgraph workspace + dimension the nodes are read from
     */
    public function build(ContentSubgraphInterface $subgraph): NodeTable
    {
        return new NodeTable(
            $subgraph,
            $this->contentRepositoryRegistry->get($subgraph->getContentRepositoryId())->getNodeTypeManager(),
            $this->fixtureBasePath,
            $this->defaultPersistentResourceProperties,
            $this->defaultImageProperties
        );
    }
}
