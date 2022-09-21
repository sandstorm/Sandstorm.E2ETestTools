<?php

namespace Sandstorm\E2ETestTools\StepGenerator;

use Neos\Flow\Package\PackageManager;

/**
 * Public builder API to configure and create a NodeTable to use in your step generator command controller.
 */
class NodeTableBuilder
{

    private PackageManager $packageManager;
    private array $defaultNodeProperties = [];
    private array $defaultPersistentResourceProperties = [];
    private array $defaultImageProperties = [];
    private ?string $fixtureBasePath = null;

    public function __construct(PackageManager $packageManager)
    {
        $this->packageManager = $packageManager;
    }

    public function withDefaultNodeProperties(array $defaultNodeProperties): NodeTableBuilder
    {
        $this->defaultNodeProperties = $defaultNodeProperties;
        return $this;
    }

    public function withDefaultPersistentResourceProperties(array $defaultPersistentResourceProperties): NodeTableBuilder
    {
        $this->defaultPersistentResourceProperties = $defaultPersistentResourceProperties;
        return $this;
    }

    public function withDefaultImageProperties(array $defaultNodeProperties): NodeTableBuilder
    {
        $this->defaultNodeProperties = $defaultNodeProperties;
        return $this;
    }

    public function withFixturesBaseDirectory(string $packageKey, string $subPath): NodeTableBuilder
    {
        $package = $this->packageManager->getPackage($packageKey);
        $this->fixtureBasePath = $package->getPackagePath() . $subPath;
        return $this;
    }

    public function build(): NodeTable
    {
        return new NodeTable(
            $this->defaultNodeProperties,
            $this->fixtureBasePath,
            $this->defaultPersistentResourceProperties,
            $this->defaultImageProperties
        );
    }

}
