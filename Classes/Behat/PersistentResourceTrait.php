<?php

declare(strict_types=1);

namespace Sandstorm\E2ETestTools\Behat;

use Behat\Gherkin\Node\PyStringNode;
use Behat\Gherkin\Node\TableNode;
use Behat\Step\Given;
use Neos\Flow\ObjectManagement\ObjectManagerInterface;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Flow\ResourceManagement\PersistentResource;
use Neos\Flow\ResourceManagement\ResourceManager;
use Neos\Media\Domain\Model\Document;
use Neos\Media\Domain\Model\Image;
use Neos\Media\Domain\Repository\AssetRepository;
use Neos\Utility\ObjectAccess;

/**
 * File and image assets for node properties - used via {@see FusionRenderingTrait}.
 */
trait PersistentResourceTrait
{
    private AssetRepository $persistentResource_assetRepository;

    private ResourceManager $persistentResource_resourceManager;

    private PersistenceManagerInterface $persistentResource_persistenceManager;

    private ?\Closure $persistentResource_resourcePersistedHook = null;

    public function PersistentResourceTrait_setupServices(ObjectManagerInterface $objectManager): void
    {
        $this->persistentResource_assetRepository = $objectManager->get(AssetRepository::class);
        $this->persistentResource_resourceManager = $objectManager->get(ResourceManager::class);
        $this->persistentResource_persistenceManager = $objectManager->get(PersistenceManagerInterface::class);
    }

    /**
     * Called (bound to the FeatureContext) after the asset steps persisted their resources, e.g. to flush a project
     * cache.
     */
    public function PersistentResourceTrait_registerResourcePersistedHook(\Closure $hook): void
    {
        $this->persistentResource_resourcePersistedHook = $hook;
    }

    /**
     * @throws \Exception failure while storing the resource
     */
    #[Given('I have a textual persistent resource :uuid named :filename with the following content:')]
    public function iHaveATextualPersistentResourceWithTheFollowingContent(string $uuid, string $filename, PyStringNode $content): void
    {
        $resource = $this->persistentResource_resourceManager->importResourceFromContent(
            $content->getRaw(),
            $filename);
        $document = new Document($resource);
        ObjectAccess::setProperty($document, 'Persistence_Object_Identifier', $uuid, true);
        $this->persistentResource_assetRepository->add($document);
        $this->persistentResource_persistenceManager->persistAll();
        $this->publishResource($resource);

        $this->callResourcePersistedHook();
    }

    /**
     * Columns: Image ID, Filename, Path (relative to Packages/), Collection, Relative Publication Path, optional
     * Copyright Notice - as printed by the StepGenerator.
     */
    #[Given('I have the following images:')]
    public function iHaveTheFollowingImages(TableNode $imageTable): void
    {
        $persistentResources = [];
        foreach ($imageTable->getHash() as $row) {
            $path = FLOW_PATH_PACKAGES . $row['Path'];
            $stream = is_file($path) ? fopen($path, 'r') : false;
            if ($stream === false) {
                throw new \RuntimeException(sprintf('Image "%s" not found or not readable: %s', $row['Image ID'], $path));
            }
            try {
                $persistentResource = $this->persistentResource_resourceManager->importResource($stream, $row['Collection']);
            } finally {
                fclose($stream);
            }
            $persistentResource->setFilename($row['Filename']);
            $persistentResource->setRelativePublicationPath($row['Relative Publication Path']);
            $image = new Image($persistentResource);
            $image->refresh();

            if (!empty($row['Copyright Notice'])) {
                $image->setCopyrightNotice($row['Copyright Notice']);
            }

            ObjectAccess::setProperty($image, 'Persistence_Object_Identifier', $row['Image ID'], true);
            $this->persistentResource_assetRepository->add($image);
            $persistentResources[] = $persistentResource;
        }
        $this->persistentResource_persistenceManager->persistAll();
        array_map($this->publishResource(...), $persistentResources);

        $this->callResourcePersistedHook();
    }

    /**
     * Resources are not published on demand, so publish fixture resources right away. This only makes them reachable
     * for the system under test if this Behat context uses the same persistent resource storage and target as the
     * system under test (see "Two Flow Contexts, Two Ports" in the README).
     */
    private function publishResource(PersistentResource $resource): void
    {
        $collection = $this->persistentResource_resourceManager->getCollection($resource->getCollectionName());
        $collection->getTarget()->publishResource($resource, $collection);
    }

    private function callResourcePersistedHook(): void
    {
        if ($this->persistentResource_resourcePersistedHook !== null) {
            $this->persistentResource_resourcePersistedHook->call($this);
        }
    }
}
