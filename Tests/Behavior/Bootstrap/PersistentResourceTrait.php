<?php

declare(strict_types=1);

namespace Sandstorm\E2ETestTools\Tests\Behavior\Bootstrap;

use Behat\Gherkin\Node\PyStringNode;
use Behat\Gherkin\Node\TableNode;
use Neos\Flow\Core\Bootstrap;
use Neos\Flow\ObjectManagement\ObjectManagerInterface;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Flow\ResourceManagement\PersistentResource;
use Neos\Flow\ResourceManagement\ResourceManager;
use Neos\Flow\ResourceManagement\ResourceRepository;
use Neos\Media\Domain\Model\Document;
use Neos\Media\Domain\Model\Image;
use Neos\Media\Domain\Repository\AssetRepository;
use Neos\Utility\ObjectAccess;

trait PersistentResourceTrait
{
    /**
     * @var AssetRepository
     */
    private AssetRepository $PersistentResourceTrait_assetRepository;

    /**
     * @var ResourceManager
     */
    private ResourceManager $PersistentResourceTrait_resourceManager;

    /**
     * @var ResourceRepository
     */
    private ResourceRepository $PersistentResourceTrait_resourceRepository;

    /**
     * @var PersistenceManagerInterface
     */
    private PersistenceManagerInterface $PersistentResourceTrait_persistenceManager;

    private ?\Closure $PersistentResourceTrait_resourcePersistedHook = null;

    public function PersistentResourceTrait_setupServices(ObjectManagerInterface $objectManager): void
    {
        $this->PersistentResourceTrait_assetRepository = $objectManager->get(AssetRepository::class);
        $this->PersistentResourceTrait_resourceManager = $objectManager->get(ResourceManager::class);
        $this->PersistentResourceTrait_persistenceManager = $objectManager->get(PersistenceManagerInterface::class);
    }

    public function PersistentResourceTrait_registerResourcePersistedHook(\Closure $hook): void
    {
        $this->PersistentResourceTrait_resourcePersistedHook = $hook;
    }

    /**
     * @Given I have a textual persistent resource :uuid named :filename with the following content:
     * @throws Exception failure while storing the resource
     */
    public function iHaveATextualPersistentResourceWithTheFollowingContent(string $uuid, string $filename, PyStringNode $content): void
    {
        $resource = $this->PersistentResourceTrait_resourceManager->importResourceFromContent(
            $content->getRaw(),
            $filename);
        $document = new Document($resource);
        ObjectAccess::setProperty($document, 'Persistence_Object_Identifier', $uuid, true);
        $this->PersistentResourceTrait_assetRepository->add($document);
        $this->PersistentResourceTrait_persistenceManager->persistAll();
        $this->publishResource($resource);

        $this->callResourcePersistedHook();
    }

    /**
     * @Given I have the following images:
     * @param TableNode $imageTable
     */
    public function iHaveTheFollowingImages(TableNode $imageTable): void
    {
        foreach ($imageTable->getHash() as $rowNumber => $row) {
            $persistentResource = $this->PersistentResourceTrait_resourceManager->importResource(
                fopen(FLOW_PATH_PACKAGES . $row['Path'], 'r'),
                $row['Collection'],
            );
            $persistentResource->setFilename($row['Filename']);
            $persistentResource->setRelativePublicationPath($row['Relative Publication Path']);
            $image = new Image($persistentResource);
            $image->refresh();

            if (!empty($row['Copyright Notice'])) {
                $image->setCopyrightNotice($row['Copyright Notice']);
            }

            ObjectAccess::setProperty($image, 'Persistence_Object_Identifier', $row['Image ID'], true);
            $this->PersistentResourceTrait_assetRepository->add($image);
            $persistentResources[] = $persistentResource;
        }
        $this->PersistentResourceTrait_persistenceManager->persistAll();
        array_map($this->publishResource(...), $persistentResources ?? []);

        $this->callResourcePersistedHook();
    }

    /**
     * Resources are not published on demand, so publish fixture resources right away. This only makes them reachable
     * for the system under test if this Behat context uses the same persistent resource storage and target as the
     * system under test (see "Two Flow Contexts, Two Ports" in the README).
     */
    private function publishResource(PersistentResource $resource): void
    {
        $collection = $this->PersistentResourceTrait_resourceManager->getCollection($resource->getCollectionName());
        $collection->getTarget()->publishResource($resource, $collection);
    }

    private function callResourcePersistedHook(): void
    {
        if ($this->PersistentResourceTrait_resourcePersistedHook !== null) {
            $this->PersistentResourceTrait_resourcePersistedHook->call($this, []);
        }
    }
}
